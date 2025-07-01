<?php
/**
 * Connection handling for file backup operations.
 * FIXED VERSION 3.0: Improved SFTP stability, better error handling,
 * enhanced retry logic, and connection reliability matching FTP performance
 */
class SwiftSpeed_Siberian_File_Backup_Connections {
    /**
     * FTP connection pool
     * 
     * @var array
     */
    private $ftp_connections = [];
    
    /**
     * SFTP connection pool
     * 
     * @var array
     */
    private $sftp_connections = [];
    
    /**
     * Maximum number of connections to maintain in the pool
     * 
     * @var int
     */
    private $max_connections = 1; // Single connection for memory efficiency
    
    /**
     * Connection timeout settings
     * 
     * @var array
     */
    private $timeouts = [
        'connection_ttl' => 300,    // 5 minutes (increased for SFTP)
        'operation_timeout' => 90,  // 90 seconds for operations (increased)
        'large_file_timeout' => 600, // 10 minutes for large files
        'sftp_timeout' => 120,      // 2 minutes for SFTP operations
    ];
    
    /**
     * Track when connections were created
     * 
     * @var array
     */
    private $connection_times = [];
    
    /**
     * Connection statistics for monitoring
     * 
     * @var array
     */
    private $connection_stats = [
        'connections_created' => 0,
        'connections_reused' => 0,
        'connections_failed' => 0,
        'connections_closed' => 0,
        'bytes_transferred' => 0,
        'files_transferred' => 0,
        'retry_attempts' => 0,
        'retry_successes' => 0,
    ];
    
    /**
     * Streaming settings (optimized for reliability)
     * 
     * @var array
     */
    private $streaming_config = [
        'chunk_size' => 65536,      // 64KB chunks for better reliability
        'buffer_size' => 262144,    // 256KB buffer
        'large_file_threshold' => 104857600, // 100MB
        'max_retries' => 5,         // Increased retry attempts
        'retry_delay' => 2,         // 2 seconds between retries
    ];

    /**
     * SFTP specific settings for better reliability
     * 
     * @var array
     */
    private $sftp_config = [
        'keep_alive_interval' => 30, // Send keep-alive every 30 seconds
        'max_packet_size' => 32768,  // 32KB max packet size for compatibility
        'compression' => false,      // Disable compression for better compatibility
        'cipher' => 'aes128-ctr',    // Use reliable cipher
    ];

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize connection pools
        $this->ftp_connections = [];
        $this->sftp_connections = [];
        $this->connection_times = [];
        
        // Adjust settings based on available memory
        $memory_limit = $this->get_memory_limit();
        if ($memory_limit < 536870912) { // Less than 512MB
            $this->streaming_config['chunk_size'] = 32768; // 32KB
            $this->streaming_config['buffer_size'] = 131072; // 128KB
        } elseif ($memory_limit > 1073741824) { // More than 1GB
            $this->streaming_config['chunk_size'] = 131072; // 128KB
            $this->streaming_config['buffer_size'] = 524288; // 512KB
        }
    }

    /**
     * Log a message with enhanced details for connections.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'connections', $message);
        }
    }
    
    /**
     * Initialize a pool of FTP connections for faster operations
     * 
     * @param array $config FTP connection configuration
     * @return void
     */
    public function initialize_connection_pool($config) {
        // Close any existing connections first
        $this->close_connection_pool();
        
        // Create initial connection
        $connection = $this->create_ftp_connection($config);
        if ($connection) {
            $this->ftp_connections[] = $connection;
            $this->connection_times[] = time();
        }
        
        $this->log_message('Initialized FTP connection pool');
    }
    
    /**
     * Initialize a pool of SFTP connections for faster operations
     * 
     * @param array $config SFTP connection configuration
     * @return void
     */
    public function initialize_sftp_connection_pool($config) {
        // Close any existing SFTP connections first
        $this->close_sftp_connection_pool();
        
        // Create initial connection
        $connection = $this->create_sftp_connection($config);
        if ($connection) {
            $this->sftp_connections[] = $connection;
            $this->connection_times[] = time();
        }
        
        $this->log_message('Initialized SFTP connection pool');
    }
    
    /**
     * Create a single FTP connection
     * 
     * @param array $config FTP connection configuration
     * @return resource|false FTP connection or false on failure
     */
    private function create_ftp_connection($config) {
        $host = $config['host'];
        $username = $config['username'];
        $password = $config['password'];
        $port = isset($config['port']) ? intval($config['port']) : 21;
        
        // Connect to FTP server with timeout
        $conn = @ftp_connect($host, $port, 15);
        if (!$conn) {
            $this->log_message('Failed to create FTP connection to ' . $host . ':' . $port);
            $this->connection_stats['connections_failed']++;
            return false;
        }
        
        // Login
        if (!@ftp_login($conn, $username, $password)) {
            @ftp_close($conn);
            $this->log_message('Failed to login to FTP with user: ' . $username);
            $this->connection_stats['connections_failed']++;
            return false;
        }
        
        // Set passive mode
        @ftp_pasv($conn, true);
        
        // Set timeout
        @ftp_set_option($conn, FTP_TIMEOUT_SEC, $this->timeouts['operation_timeout']);
        
        $this->connection_stats['connections_created']++;
        $this->log_message('Created FTP connection successfully');
        return $conn;
    }
    
    /**
     * Create a single SFTP connection with enhanced reliability
     * 
     * @param array $config SFTP connection configuration
     * @return mixed|false SFTP connection or false on failure
     */
    private function create_sftp_connection($config) {
        $host = $config['host'];
        $username = $config['username'];
        $password = $config['password'];
        $port = isset($config['port']) ? intval($config['port']) : 22;
        
        if (extension_loaded('ssh2')) {
            // Use SSH2 extension with enhanced settings
            $methods = [
                'kex' => 'diffie-hellman-group14-sha256,diffie-hellman-group-exchange-sha256',
                'hostkey' => 'rsa-sha2-512,rsa-sha2-256,ssh-rsa',
                'client_to_server' => [
                    'crypt' => 'aes128-ctr,aes192-ctr,aes256-ctr',
                    'mac' => 'hmac-sha2-256,hmac-sha1',
                    'comp' => 'none'
                ],
                'server_to_client' => [
                    'crypt' => 'aes128-ctr,aes192-ctr,aes256-ctr',
                    'mac' => 'hmac-sha2-256,hmac-sha1',
                    'comp' => 'none'
                ]
            ];
            
            $callbacks = [
                'disconnect' => [$this, 'sftp_disconnect_callback']
            ];
            
            $conn = @ssh2_connect($host, $port, $methods, $callbacks);
            if (!$conn) {
                $this->log_message('Failed to create SFTP connection to ' . $host . ':' . $port);
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            // Try to authenticate with better error handling
            $auth_result = @ssh2_auth_password($conn, $username, $password);
            if (!$auth_result) {
                // Try to get more specific error information
                $this->log_message('Failed to login to SFTP with user: ' . $username . ' (authentication failed)');
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            // Initialize SFTP subsystem with retry
            $sftp = false;
            $retry_count = 0;
            $max_retries = 3;
            
            while ($retry_count < $max_retries && !$sftp) {
                $sftp = @ssh2_sftp($conn);
                if (!$sftp) {
                    $retry_count++;
                    if ($retry_count < $max_retries) {
                        $this->log_message('SFTP subsystem initialization failed, retrying... (attempt ' . $retry_count . ')');
                        sleep(1); // Wait 1 second before retry
                    }
                }
            }
            
            if (!$sftp) {
                $this->log_message('Failed to initialize SFTP subsystem after ' . $max_retries . ' attempts');
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            // Test the connection with a simple stat operation
            $test_result = @ssh2_sftp_stat($sftp, '/');
            if ($test_result === false) {
                $this->log_message('SFTP connection test failed - cannot access root directory');
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            $this->connection_stats['connections_created']++;
            $this->log_message('Created SFTP connection successfully (SSH2)');
            return [
                'type' => 'ssh2',
                'connection' => $conn,
                'sftp' => $sftp,
                'created_at' => time(),
                'last_activity' => time(),
            ];
        }
        elseif (class_exists('\phpseclib3\Net\SFTP')) {
            // Use phpseclib with enhanced settings
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port);
                
                // Set various options for better reliability
                $sftp->setTimeout($this->timeouts['sftp_timeout']);
                $sftp->setKeepAlive($this->sftp_config['keep_alive_interval']);
                
                // Try to login with retry mechanism
                $login_success = false;
                $retry_count = 0;
                $max_retries = 3;
                
                while ($retry_count < $max_retries && !$login_success) {
                    try {
                        $login_success = $sftp->login($username, $password);
                        if (!$login_success) {
                            $retry_count++;
                            if ($retry_count < $max_retries) {
                                $this->log_message('SFTP login failed, retrying... (attempt ' . $retry_count . ')');
                                sleep(2); // Wait 2 seconds before retry
                            }
                        }
                    } catch (\Exception $e) {
                        $retry_count++;
                        if ($retry_count < $max_retries) {
                            $this->log_message('SFTP login exception, retrying... (attempt ' . $retry_count . '): ' . $e->getMessage());
                            sleep(2);
                        } else {
                            throw $e;
                        }
                    }
                }
                
                if (!$login_success) {
                    $this->log_message('Failed to login to SFTP with user: ' . $username . ' after ' . $max_retries . ' attempts');
                    $this->connection_stats['connections_failed']++;
                    return false;
                }
                
                // Test the connection
                $test_result = $sftp->is_dir('/');
                if (!$test_result) {
                    $this->log_message('SFTP connection test failed - cannot access root directory');
                    $this->connection_stats['connections_failed']++;
                    return false;
                }
                
                $this->connection_stats['connections_created']++;
                $this->log_message('Created SFTP connection successfully (phpseclib)');
                return [
                    'type' => 'phpseclib',
                    'connection' => $sftp,
                    'created_at' => time(),
                    'last_activity' => time(),
                ];
            }
            catch (\Exception $e) {
                $this->log_message('SFTP error: ' . $e->getMessage());
                $this->connection_stats['connections_failed']++;
                return false;
            }
        }
        else {
            $this->log_message('No SFTP capability available - neither SSH2 extension nor phpseclib found');
            return false;
        }
    }
    
    /**
     * SFTP disconnect callback for SSH2
     * 
     * @param int $reason Disconnect reason
     * @param string $message Disconnect message
     * @param string $language Language
     */
    public function sftp_disconnect_callback($reason, $message, $language) {
        $this->log_message("SFTP disconnected: reason=$reason, message=$message");
    }
    
    /**
     * Get an FTP connection from the pool or create a new one
     * 
     * @param array $config FTP connection configuration
     * @return resource|false FTP connection or false on failure
     */
    private function get_ftp_connection($config) {
        if (empty($this->ftp_connections)) {
            return $this->create_ftp_connection($config);
        }
        
        // Get a connection from the pool
        $conn = array_pop($this->ftp_connections);
        $conn_time = array_pop($this->connection_times);
        
        // Check if connection is stale or invalid
        $is_stale = (time() - $conn_time) > $this->timeouts['connection_ttl'];
        
        if ($is_stale || @ftp_pwd($conn) === false) {
            // Connection is no longer valid or stale, create a new one
            @ftp_close($conn);
            $this->connection_stats['connections_closed']++;
            $this->log_message('Replacing stale FTP connection');
            return $this->create_ftp_connection($config);
        } else {
            $this->connection_stats['connections_reused']++;
            return $conn;
        }
    }
    
    /**
     * Get an SFTP connection from the pool or create a new one
     * 
     * @param array $config SFTP connection configuration
     * @return mixed|false SFTP connection or false on failure
     */
    private function get_sftp_connection($config) {
        if (empty($this->sftp_connections)) {
            return $this->create_sftp_connection($config);
        }
        
        // Get a connection from the pool
        $conn = array_pop($this->sftp_connections);
        $conn_time = array_pop($this->connection_times);
        
        // Check if connection is stale or invalid
        $is_stale = (time() - $conn['created_at']) > $this->timeouts['connection_ttl'];
        $is_valid = false;
        
        if ($conn['type'] === 'ssh2') {
            $is_valid = @ssh2_sftp_stat($conn['sftp'], '/') !== false;
        } else {
            try {
                $is_valid = $conn['connection']->is_dir('/');
            } catch (\Exception $e) {
                $is_valid = false;
            }
        }
        
        if ($is_stale || !$is_valid) {
            // Connection is no longer valid or stale, create a new one
            if ($conn['type'] === 'ssh2') {
                // No explicit disconnect for SSH2, just let it go out of scope
            }
            $this->connection_stats['connections_closed']++;
            $this->log_message('Replacing stale SFTP connection');
            return $this->create_sftp_connection($config);
        } else {
            // Update last activity time
            $conn['last_activity'] = time();
            $this->connection_stats['connections_reused']++;
            return $conn;
        }
    }
    
    /**
     * Return an FTP connection to the pool
     * 
     * @param resource $conn FTP connection
     * @return void
     */
    private function return_ftp_connection($conn) {
        if (!$conn) return;
        
        if (count($this->ftp_connections) < $this->max_connections) {
            // Add back to the pool
            $this->ftp_connections[] = $conn;
            $this->connection_times[] = time();
        } else {
            // Pool is full, close the connection
            @ftp_close($conn);
            $this->connection_stats['connections_closed']++;
        }
    }
    
    /**
     * Return an SFTP connection to the pool
     * 
     * @param mixed $conn SFTP connection
     * @return void
     */
    private function return_sftp_connection($conn) {
        if (!$conn) return;
        
        if (count($this->sftp_connections) < $this->max_connections) {
            // Update last activity and add back to the pool
            $conn['last_activity'] = time();
            $this->sftp_connections[] = $conn;
            $this->connection_times[] = time();
        } else {
            // Pool is full, close the connection
            if ($conn['type'] === 'ssh2') {
                // No explicit disconnect for SSH2, just let it go out of scope
            }
            $this->connection_stats['connections_closed']++;
        }
    }
    
    /**
     * Test FTP connection with enhanced error handling.
     *
     * @param array $config FTP connection configuration.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function test_ftp_connection($config) {
        if (!function_exists('ftp_connect')) {
            return new WP_Error('ftp_functions', __('FTP functions are not available on this server', 'swiftspeed-siberian'));
        }
        
        $ftp_conn = @ftp_connect($config['host'], intval($config['port'] ?: 21), 15);
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not connect to FTP server', 'swiftspeed-siberian'));
        }
        
        if (!@ftp_login($ftp_conn, $config['username'], $config['password'])) {
            ftp_close($ftp_conn);
            return new WP_Error('ftp_login', __('FTP login failed', 'swiftspeed-siberian'));
        }
        
        ftp_pasv($ftp_conn, true);
        
        $path = !empty($config['path']) ? $config['path'] : '/';
        if (!@ftp_chdir($ftp_conn, $path)) {
            if ($path !== '/' && !@ftp_chdir($ftp_conn, '.')) {
                ftp_close($ftp_conn);
                return new WP_Error('ftp_chdir', __('Could not access the specified directory', 'swiftspeed-siberian'));
            }
        }
        
        ftp_close($ftp_conn);
        return true;
    }
    
    /**
     * Test SFTP connection with enhanced error handling and reliability.
     *
     * @param array $config SFTP connection configuration.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function test_sftp_connection($config) {
        if (!extension_loaded('ssh2') && !class_exists('\phpseclib3\Net\SFTP')) {
            return new WP_Error('sftp_functions', __('No SFTP capability available. Please install the SSH2 PHP extension or phpseclib.', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Testing SFTP connection to ' . $config['host'] . ':' . ($config['port'] ?: 22));
        
        $conn = $this->create_sftp_connection($config);
        if (!$conn) {
            return new WP_Error('sftp_connect', __('Could not connect to SFTP server. Please check your credentials and server settings.', 'swiftspeed-siberian'));
        }
        
        $path = !empty($config['path']) ? $config['path'] : '/';
        $can_access = false;
        
        try {
            if ($conn['type'] === 'ssh2') {
                $can_access = @ssh2_sftp_stat($conn['sftp'], $path) !== false;
                if (!$can_access && $path !== '/') {
                    // Try to create the directory if it doesn't exist
                    $can_access = @ssh2_sftp_mkdir($conn['sftp'], $path, 0755, true);
                }
            } else {
                $can_access = $conn['connection']->is_dir($path);
                if (!$can_access && $path !== '/') {
                    // Try to create the directory if it doesn't exist
                    $can_access = $conn['connection']->mkdir($path, -1, true);
                }
            }
        } catch (\Exception $e) {
            $this->log_message('SFTP test access error: ' . $e->getMessage());
            $can_access = false;
        }
        
        if (!$can_access) {
            if ($conn['type'] === 'ssh2') {
                // No explicit disconnect needed
            }
            return new WP_Error('sftp_access', sprintf(__('Could not access the specified directory: %s. Please check permissions.', 'swiftspeed-siberian'), $path));
        }
        
        $this->log_message('SFTP connection test successful');
        
        if ($conn['type'] === 'ssh2') {
            // No explicit disconnect needed
        }
        
        return true;
    }
    
    /**
     * Get FTP directory contents using connection pooling.
     *
     * @param array $config FTP connection configuration.
     * @param string $directory Directory path to list.
     * @return array|WP_Error Array of directory contents or error.
     */
    public function get_ftp_directory_contents_pooled($config, $directory) {
        $ftp_conn = $this->get_ftp_connection($config);
        
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not get FTP connection from pool', 'swiftspeed-siberian'));
        }
        
        if (!@ftp_chdir($ftp_conn, $directory)) {
            $this->return_ftp_connection($ftp_conn);
            return new WP_Error('ftp_chdir', sprintf(__('Could not change to directory: %s', 'swiftspeed-siberian'), $directory));
        }
        
        $raw_list = @ftp_rawlist($ftp_conn, '.');
        $this->return_ftp_connection($ftp_conn);
        
        if ($raw_list === false) {
            return new WP_Error('ftp_rawlist', sprintf(__('Failed to get directory listing for: %s', 'swiftspeed-siberian'), $directory));
        }
        
        $items = [];
        foreach ($raw_list as $item) {
            $parsed = $this->parse_ftp_rawlist_item($item);
            if ($parsed) {
                $items[] = $parsed;
            }
        }
        
        return $items;
    }
    
    /**
     * Get SFTP directory contents using connection pooling with enhanced reliability.
     *
     * @param array $config SFTP connection configuration.
     * @param string $directory Directory path to list.
     * @return array|WP_Error Array of directory contents or error.
     */
    public function get_sftp_directory_contents_pooled($config, $directory) {
        $sftp_conn = $this->get_sftp_connection($config);
        
        if (!$sftp_conn) {
            return new WP_Error('sftp_connect', __('Could not get SFTP connection from pool', 'swiftspeed-siberian'));
        }
        
        $items = [];
        $error = false;
        $retry_count = 0;
        $max_retries = $this->streaming_config['max_retries'];
        
        while ($retry_count < $max_retries && !$items && !$error) {
            try {
                if ($sftp_conn['type'] === 'ssh2') {
                    $sftp = $sftp_conn['sftp'];
                    $handle = @opendir("ssh2.sftp://$sftp$directory");
                    
                    if (!$handle) {
                        if ($retry_count < $max_retries - 1) {
                            $retry_count++;
                            $this->log_message("SFTP opendir failed for $directory, retrying... (attempt $retry_count)");
                            sleep($this->streaming_config['retry_delay']);
                            continue;
                        } else {
                            $this->return_sftp_connection($sftp_conn);
                            return new WP_Error('sftp_opendir', sprintf(__('Could not open directory: %s', 'swiftspeed-siberian'), $directory));
                        }
                    }
                    
                    while (($file = readdir($handle)) !== false) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        
                        $full_path = rtrim($directory, '/') . '/' . $file;
                        
                        // Use stat with retry for better reliability
                        $stats = null;
                        $stat_retry = 0;
                        while ($stat_retry < 3 && !$stats) {
                            $stats = @ssh2_sftp_stat($sftp, $full_path);
                            if (!$stats) {
                                $stat_retry++;
                                if ($stat_retry < 3) {
                                    usleep(100000); // 100ms delay
                                }
                            }
                        }
                        
                        if ($stats && isset($stats['mode'])) {
                            $is_dir = ($stats['mode'] & 0170000) === 0040000; // S_IFDIR
                            $file_size = isset($stats['size']) ? $stats['size'] : 0;
                            
                            $items[] = [
                                'name' => $file,
                                'type' => $is_dir ? 'dir' : 'file',
                                'size' => $file_size
                            ];
                        } else {
                            // Fallback: assume it's a file if we can't get stats
                            $items[] = [
                                'name' => $file,
                                'type' => 'file',
                                'size' => 0
                            ];
                        }
                    }
                    
                    closedir($handle);
                    break; // Success, exit retry loop
                    
                } else {
                    $sftp = $sftp_conn['connection'];
                    $list = $sftp->nlist($directory);
                    
                    if ($list === false) {
                        if ($retry_count < $max_retries - 1) {
                            $retry_count++;
                            $this->log_message("SFTP nlist failed for $directory, retrying... (attempt $retry_count)");
                            sleep($this->streaming_config['retry_delay']);
                            continue;
                        } else {
                            $error = true;
                            break;
                        }
                    }
                    
                    foreach ($list as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        
                        $full_path = rtrim($directory, '/') . '/' . $file;
                        
                        // Use retry mechanism for stat operations
                        $is_dir = false;
                        $file_size = 0;
                        $stat_retry = 0;
                        
                        while ($stat_retry < 3) {
                            try {
                                $is_dir = $sftp->is_dir($full_path);
                                if (!$is_dir) {
                                    $stat = $sftp->stat($full_path);
                                    $file_size = isset($stat['size']) ? $stat['size'] : 0;
                                }
                                break; // Success
                            } catch (\Exception $e) {
                                $stat_retry++;
                                if ($stat_retry < 3) {
                                    usleep(100000); // 100ms delay
                                } else {
                                    $this->log_message("SFTP stat failed for $full_path after retries: " . $e->getMessage());
                                }
                            }
                        }
                        
                        $items[] = [
                            'name' => $file,
                            'type' => $is_dir ? 'dir' : 'file',
                            'size' => $file_size
                        ];
                    }
                    break; // Success, exit retry loop
                }
            } catch (\Exception $e) {
                $retry_count++;
                if ($retry_count < $max_retries) {
                    $this->log_message("SFTP directory listing exception, retrying... (attempt $retry_count): " . $e->getMessage());
                    sleep($this->streaming_config['retry_delay']);
                } else {
                    $this->log_message('SFTP error listing directory after retries: ' . $e->getMessage());
                    $error = true;
                }
            }
        }
        
        if ($retry_count > 0) {
            if (!empty($items)) {
                $this->connection_stats['retry_successes']++;
                $this->log_message("SFTP directory listing succeeded after $retry_count retries");
            }
            $this->connection_stats['retry_attempts'] += $retry_count;
        }
        
        $this->return_sftp_connection($sftp_conn);
        
        if ($error || (empty($items) && $retry_count >= $max_retries)) {
            return new WP_Error('sftp_list', sprintf(__('Failed to get directory listing for: %s after %d retries', 'swiftspeed-siberian'), $directory, $max_retries));
        }
        
        return $items;
    }
    
    /**
     * Parse a raw FTP directory listing item.
     *
     * @param string $raw_item Raw FTP directory listing item.
     * @return array|bool Parsed item or false on failure.
     */
    private function parse_ftp_rawlist_item($raw_item) {
        // Unix-style format
        if (preg_match('/^([dwrx\-]{10})\s+(\d+)\s+(\w+)\s+(\w+)\s+(\d+)\s+(\w{3}\s+\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s+(.+)$/', $raw_item, $matches)) {
            return [
                'name' => $matches[8],
                'type' => $matches[1][0] === 'd' ? 'dir' : 'file',
                'size' => (int)$matches[5],
            ];
        }
        // Windows-style format
        elseif (preg_match('/^(\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|[0-9]+)\s+(.+)$/', $raw_item, $matches)) {
            return [
                'name' => $matches[3],
                'type' => $matches[2] === '<DIR>' ? 'dir' : 'file',
                'size' => $matches[2] === '<DIR>' ? 0 : (int)$matches[2],
            ];
        }
        
        return false;
    }
    
    /**
     * Download a file from FTP using optimized streaming.
     *
     * @param array $config FTP connection configuration.
     * @param string $remote_path Remote file path.
     * @param string $local_path Local destination path.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function download_file_ftp_pooled($config, $remote_path, $local_path) {
        $ftp_conn = $this->get_ftp_connection($config);
        
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not get FTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Ensure directory exists
        $dir = dirname($local_path);
        if (!file_exists($dir) && !wp_mkdir_p($dir)) {
            $this->return_ftp_connection($ftp_conn);
            return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
        }
        
        // Get file size for large file detection
        $file_size = @ftp_size($ftp_conn, $remote_path);
        $is_large_file = $file_size > $this->streaming_config['large_file_threshold'];
        
        // Set appropriate timeout
        $timeout = $is_large_file ? $this->timeouts['large_file_timeout'] : $this->timeouts['operation_timeout'];
        @ftp_set_option($ftp_conn, FTP_TIMEOUT_SEC, $timeout);
        
        $result = false;
        
        if ($is_large_file && $file_size > 0) {
            // Use non-blocking download for large files
            $this->log_message("Downloading large file via FTP: {$remote_path} (" . size_format($file_size) . ')');
            
            $ret = @ftp_nb_get($ftp_conn, $local_path, $remote_path, FTP_BINARY, FTP_AUTORESUME);
            
            while ($ret == FTP_MOREDATA) {
                $ret = @ftp_nb_continue($ftp_conn);
                
                // Allow other processes and memory cleanup
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            $result = ($ret == FTP_FINISHED);
        } else {
            // Use regular download for smaller files
            $result = @ftp_get($ftp_conn, $local_path, $remote_path, FTP_BINARY);
        }
        
        $this->return_ftp_connection($ftp_conn);
        
        if (!$result) {
            @unlink($local_path);
            return new WP_Error('ftp_get', __('Failed to download file from FTP', 'swiftspeed-siberian'));
        }
        
        // Update statistics
        $actual_size = file_exists($local_path) ? filesize($local_path) : 0;
        $this->connection_stats['bytes_transferred'] += $actual_size;
        $this->connection_stats['files_transferred']++;
        
        return true;
    }
    
    /**
     * Download a file from SFTP using optimized streaming with enhanced reliability.
     *
     * @param array $config SFTP connection configuration.
     * @param string $remote_path Remote file path.
     * @param string $local_path Local destination path.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function download_file_sftp_pooled($config, $remote_path, $local_path) {
        $sftp_conn = $this->get_sftp_connection($config);
        
        if (!$sftp_conn) {
            return new WP_Error('sftp_connect', __('Could not get SFTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Ensure directory exists
        $dir = dirname($local_path);
        if (!file_exists($dir) && !wp_mkdir_p($dir)) {
            $this->return_sftp_connection($sftp_conn);
            return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
        }
        
        $result = false;
        $error_message = '';
        $retry_count = 0;
        $max_retries = $this->streaming_config['max_retries'];
        
        while ($retry_count < $max_retries && !$result) {
            try {
                if ($sftp_conn['type'] === 'ssh2') {
                    // SSH2 extension - use optimized streaming with retry
                    $sftp = $sftp_conn['sftp'];
                    
                    $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'rb');
                    if (!$stream) {
                        if ($retry_count < $max_retries - 1) {
                            $retry_count++;
                            $this->log_message("Failed to open remote file $remote_path, retrying... (attempt $retry_count)");
                            sleep($this->streaming_config['retry_delay']);
                            continue;
                        } else {
                            $this->return_sftp_connection($sftp_conn);
                            return new WP_Error('sftp_open', __('Failed to open remote file after retries', 'swiftspeed-siberian'));
                        }
                    }
                    
                    $local_stream = @fopen($local_path, 'wb');
                    if (!$local_stream) {
                        @fclose($stream);
                        $this->return_sftp_connection($sftp_conn);
                        return new WP_Error('local_open', __('Failed to create local file', 'swiftspeed-siberian'));
                    }
                    
                    // Stream copy with optimized chunk size and error handling
                    $bytes_copied = 0;
                    $last_activity = time();
                    
                    while (!feof($stream)) {
                        $chunk = fread($stream, $this->streaming_config['chunk_size']);
                        if ($chunk === false) {
                            $error_message = 'Failed to read from remote file';
                            break;
                        }
                        
                        if (fwrite($local_stream, $chunk) === false) {
                            $error_message = 'Failed to write to local file';
                            break;
                        }
                        
                        $bytes_copied += strlen($chunk);
                        $last_activity = time();
                        
                        // Memory cleanup for large files
                        if ($bytes_copied % ($this->streaming_config['chunk_size'] * 16) === 0) {
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                        }
                        
                        // Check for timeout
                        if ((time() - $last_activity) > $this->timeouts['sftp_timeout']) {
                            $error_message = 'SFTP operation timed out';
                            break;
                        }
                    }
                    
                    @fclose($stream);
                    @fclose($local_stream);
                    
                    $result = ($bytes_copied > 0 && empty($error_message));
                    
                    if (!$result && !empty($error_message)) {
                        if ($retry_count < $max_retries - 1) {
                            $retry_count++;
                            $this->log_message("SFTP download error: $error_message, retrying... (attempt $retry_count)");
                            @unlink($local_path); // Clean up partial file
                            sleep($this->streaming_config['retry_delay']);
                            continue;
                        }
                    }
                    
                } else {
                    // phpseclib - use optimized chunked download with retry
                    $sftp = $sftp_conn['connection'];
                    
                    // Get file size with retry
                    $stat = null;
                    $stat_retry = 0;
                    while ($stat_retry < 3 && !$stat) {
                        try {
                            $stat = $sftp->stat($remote_path);
                        } catch (\Exception $e) {
                            $stat_retry++;
                            if ($stat_retry < 3) {
                                usleep(500000); // 500ms delay
                            }
                        }
                    }
                    
                    $file_size = isset($stat['size']) ? $stat['size'] : 0;
                    $is_large_file = $file_size > $this->streaming_config['large_file_threshold'];
                    
                    if ($is_large_file && $file_size > 0) {
                        // Chunked download for large files
                        $this->log_message("Downloading large file via SFTP: {$remote_path} (" . size_format($file_size) . ')');
                        
                        $local = @fopen($local_path, 'wb');
                        if (!$local) {
                            $error_message = 'Failed to create local file';
                        } else {
                            $offset = 0;
                            $chunk_failures = 0;
                            $max_chunk_failures = 5;
                            
                            while ($offset < $file_size && $chunk_failures < $max_chunk_failures) {
                                $length = min($this->streaming_config['chunk_size'], $file_size - $offset);
                                
                                try {
                                    $chunk = $sftp->get($remote_path, false, $offset, $length);
                                    
                                    if ($chunk === false) {
                                        $chunk_failures++;
                                        $this->log_message("Failed to get chunk at offset $offset, failure count: $chunk_failures");
                                        if ($chunk_failures < $max_chunk_failures) {
                                            sleep(1); // Wait before retry
                                            continue;
                                        } else {
                                            $error_message = 'Too many chunk failures';
                                            break;
                                        }
                                    }
                                    
                                    fwrite($local, $chunk);
                                    $offset += strlen($chunk);
                                    $chunk_failures = 0; // Reset failure count on success
                                    
                                    // Memory cleanup
                                    if ($offset % ($this->streaming_config['chunk_size'] * 10) === 0) {
                                        if (function_exists('gc_collect_cycles')) {
                                            gc_collect_cycles();
                                        }
                                    }
                                } catch (\Exception $e) {
                                    $chunk_failures++;
                                    $this->log_message("Exception getting chunk at offset $offset: " . $e->getMessage());
                                    if ($chunk_failures < $max_chunk_failures) {
                                        sleep(1); // Wait before retry
                                        continue;
                                    } else {
                                        $error_message = 'Exception: ' . $e->getMessage();
                                        break;
                                    }
                                }
                            }
                            
                            @fclose($local);
                            $result = ($offset >= $file_size && empty($error_message));
                        }
                    } else {
                        // Regular download for smaller files
                        try {
                            $result = $sftp->get($remote_path, $local_path);
                        } catch (\Exception $e) {
                            $error_message = $e->getMessage();
                            $result = false;
                        }
                    }
                    
                    if (!$result && !empty($error_message)) {
                        if ($retry_count < $max_retries - 1) {
                            $retry_count++;
                            $this->log_message("SFTP download error: $error_message, retrying... (attempt $retry_count)");
                            @unlink($local_path); // Clean up partial file
                            sleep($this->streaming_config['retry_delay']);
                            continue;
                        }
                    }
                }
                
                // If we get here and result is true, we succeeded
                if ($result) {
                    break;
                }
                
            } catch (\Exception $e) {
                $retry_count++;
                $error_message = $e->getMessage();
                if ($retry_count < $max_retries) {
                    $this->log_message("SFTP download exception, retrying... (attempt $retry_count): $error_message");
                    @unlink($local_path); // Clean up partial file
                    sleep($this->streaming_config['retry_delay']);
                } else {
                    $this->log_message('SFTP error downloading file after retries: ' . $error_message);
                }
            }
        }
        
        if ($retry_count > 0) {
            if ($result) {
                $this->connection_stats['retry_successes']++;
                $this->log_message("SFTP download succeeded after $retry_count retries");
            }
            $this->connection_stats['retry_attempts'] += $retry_count;
        }
        
        $this->return_sftp_connection($sftp_conn);
        
        if (!$result) {
            @unlink($local_path);
            $final_error = !empty($error_message) ? $error_message : 'Unknown error';
            return new WP_Error('sftp_download', sprintf(__('Failed to download file from SFTP after %d retries: %s', 'swiftspeed-siberian'), $max_retries, $final_error));
        }
        
        // Update statistics
        $actual_size = file_exists($local_path) ? filesize($local_path) : 0;
        $this->connection_stats['bytes_transferred'] += $actual_size;
        $this->connection_stats['files_transferred']++;
        
        return true;
    }
    
    /**
     * Close all FTP connections in the pool
     * 
     * @return void
     */
    public function close_connection_pool() {
        foreach ($this->ftp_connections as $conn) {
            @ftp_close($conn);
            $this->connection_stats['connections_closed']++;
        }
        $this->ftp_connections = [];
        $this->log_message('Closed FTP connection pool');
    }
    
    /**
     * Close all SFTP connections in the pool
     * 
     * @return void
     */
    public function close_sftp_connection_pool() {
        foreach ($this->sftp_connections as $conn) {
            if ($conn['type'] === 'ssh2') {
                // No explicit disconnect for SSH2, just let it go out of scope
            }
            $this->connection_stats['connections_closed']++;
        }
        $this->sftp_connections = [];
        $this->log_message('Closed SFTP connection pool');
    }

    /**
     * Close all connections (both FTP and SFTP)
     * 
     * @return void
     */
    public function close_all_connections() {
        $this->close_connection_pool();
        $this->close_sftp_connection_pool();
        $this->connection_times = [];
        
        // Log final statistics
        $this->log_message('Connection session stats: ' . json_encode($this->connection_stats));
        
        if ($this->connection_stats['retry_attempts'] > 0) {
            $success_rate = ($this->connection_stats['retry_successes'] / $this->connection_stats['retry_attempts']) * 100;
            $this->log_message(sprintf('Retry success rate: %.1f%% (%d successes out of %d attempts)', 
                $success_rate, $this->connection_stats['retry_successes'], $this->connection_stats['retry_attempts']));
        }
    }
    
    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit == -1) {
            return 1073741824; // 1GB default
        }
        
        $unit = strtoupper(substr($memory_limit, -1));
        $value = intval(substr($memory_limit, 0, -1));
        
        switch ($unit) {
            case 'G': $value *= 1024;
            case 'M': $value *= 1024;
            case 'K': $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get connection statistics
     * 
     * @return array Connection statistics
     */
    public function get_connection_stats() {
        return $this->connection_stats;
    }
}