<?php
/**
 * Connection handling for file backup operations.
 * OPTIMIZED VERSION: Improved streaming for large files, better memory management,
 * and enhanced error handling for SFTP/FTP connections
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
    private $max_connections = 1; // Reduced from 2 to 1 for better memory management
    
    /**
     * Time after which a connection should be considered stale and recreated
     * 
     * @var int
     */
    private $connection_ttl = 120; // 2 minutes (reduced from 3)
    
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
    ];
    
    /**
     * Download chunk size for memory efficiency
     * 
     * @var int
     */
    private $download_chunk_size = 262144; // 256KB chunks

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize connection pools
        $this->ftp_connections = [];
        $this->sftp_connections = [];
        $this->connection_times = [];
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
        
        $this->log_message('Initialized FTP connection pool with ' . count($this->ftp_connections) . ' connections');
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
        
        $this->log_message('Initialized SFTP connection pool with ' . count($this->sftp_connections) . ' connections');
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
        
        // Connect to FTP server with shorter timeout
        $conn = @ftp_connect($host, $port, 10);
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
        @ftp_set_option($conn, FTP_TIMEOUT_SEC, 30);
        
        $this->connection_stats['connections_created']++;
        return $conn;
    }
    
    /**
     * Create a single SFTP connection
     * Uses SSH2 extension if available, otherwise falls back to phpseclib
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
            // Use SSH2 extension if available
            $conn = @ssh2_connect($host, $port, array('disconnect' => 'disconnect_callback'));
            if (!$conn) {
                $this->log_message('Failed to create SFTP connection to ' . $host . ':' . $port);
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            // Try to authenticate
            if (!@ssh2_auth_password($conn, $username, $password)) {
                $this->log_message('Failed to login to SFTP with user: ' . $username);
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            // Initialize SFTP subsystem
            $sftp = @ssh2_sftp($conn);
            if (!$sftp) {
                $this->log_message('Failed to initialize SFTP subsystem');
                $this->connection_stats['connections_failed']++;
                return false;
            }
            
            $this->connection_stats['connections_created']++;
            return [
                'type' => 'ssh2',
                'connection' => $conn,
                'sftp' => $sftp
            ];
        }
        elseif (class_exists('\phpseclib3\Net\SFTP')) {
            // Use phpseclib if SSH2 extension is not available
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port);
                $sftp->setTimeout(30);
                
                if (!$sftp->login($username, $password)) {
                    $this->log_message('Failed to login to SFTP with user: ' . $username);
                    $this->connection_stats['connections_failed']++;
                    return false;
                }
                
                $this->connection_stats['connections_created']++;
                return [
                    'type' => 'phpseclib',
                    'connection' => $sftp
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
     * Get an FTP connection from the pool or create a new one
     * 
     * @param array $config FTP connection configuration
     * @return resource|false FTP connection or false on failure
     */
    private function get_ftp_connection($config) {
        if (empty($this->ftp_connections)) {
            $conn = $this->create_ftp_connection($config);
            if ($conn) {
                $this->connection_times[] = time();
            }
            return $conn;
        }
        
        // Get a connection from the pool
        $conn = array_pop($this->ftp_connections);
        $conn_time = array_pop($this->connection_times);
        
        // Check if connection is stale
        $is_stale = (time() - $conn_time) > $this->connection_ttl;
        
        // Test if the connection is still valid
        if ($is_stale || @ftp_pwd($conn) === false) {
            // Connection is no longer valid or stale, create a new one
            @ftp_close($conn);
            $this->connection_stats['connections_closed']++;
            $this->log_message('Replacing stale or invalid FTP connection');
            $conn = $this->create_ftp_connection($config);
            if ($conn) {
                $conn_time = time();
            }
        } else {
            $this->connection_stats['connections_reused']++;
        }
        
        if ($conn) {
            // Update the connection time
            $this->connection_times[] = $conn_time;
        }
        
        return $conn;
    }
    
    /**
     * Get an SFTP connection from the pool or create a new one
     * 
     * @param array $config SFTP connection configuration
     * @return mixed|false SFTP connection or false on failure
     */
    private function get_sftp_connection($config) {
        if (empty($this->sftp_connections)) {
            $conn = $this->create_sftp_connection($config);
            if ($conn) {
                $this->connection_times[] = time();
            }
            return $conn;
        }
        
        // Get a connection from the pool
        $conn = array_pop($this->sftp_connections);
        $conn_time = array_pop($this->connection_times);
        
        // Check if connection is stale
        $is_stale = (time() - $conn_time) > $this->connection_ttl;
        
        // Test if the connection is still valid
        $is_valid = false;
        
        if ($conn['type'] === 'ssh2') {
            // For SSH2 extension
            $is_valid = @ssh2_sftp_stat($conn['sftp'], '/') !== false;
        }
        else {
            // For phpseclib - use is_dir to check connection validity
            try {
                $is_valid = $conn['connection']->is_dir('/');
            }
            catch (\Exception $e) {
                $is_valid = false;
            }
        }
        
        if ($is_stale || !$is_valid) {
            // Connection is no longer valid or stale, create a new one
            if ($conn['type'] === 'ssh2') {
                @ssh2_disconnect($conn['connection']);
            }
            
            $this->connection_stats['connections_closed']++;
            $this->log_message('Replacing stale or invalid SFTP connection');
            $conn = $this->create_sftp_connection($config);
            if ($conn) {
                $conn_time = time();
            }
        } else {
            $this->connection_stats['connections_reused']++;
        }
        
        if ($conn) {
            // Update the connection time
            $this->connection_times[] = $conn_time;
        }
        
        return $conn;
    }
    
    /**
     * Return an FTP connection to the pool
     * 
     * @param resource $conn FTP connection
     * @param int $creation_time When the connection was created/last used
     * @return void
     */
    private function return_ftp_connection($conn, $creation_time = null) {
        if (!$conn) return;
        
        if (count($this->ftp_connections) < $this->max_connections) {
            // Add back to the pool
            $this->ftp_connections[] = $conn;
            $this->connection_times[] = $creation_time ?: time();
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
     * @param int $creation_time When the connection was created/last used
     * @return void
     */
    private function return_sftp_connection($conn, $creation_time = null) {
        if (!$conn) return;
        
        if (count($this->sftp_connections) < $this->max_connections) {
            // Add back to the pool
            $this->sftp_connections[] = $conn;
            $this->connection_times[] = $creation_time ?: time();
        } else {
            // Pool is full, close the connection
            if ($conn['type'] === 'ssh2') {
                @ssh2_disconnect($conn['connection']);
            }
            $this->connection_stats['connections_closed']++;
        }
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
        $this->connection_times = [];
        
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
                @ssh2_disconnect($conn['connection']);
            }
            $this->connection_stats['connections_closed']++;
        }
        $this->sftp_connections = [];
        // Reset connection times only if necessary (both pools share the same array)
        if (empty($this->ftp_connections)) {
            $this->connection_times = [];
        }
        
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
        
        // Log connection statistics
        $this->log_message('Connection statistics: ' . json_encode($this->connection_stats));
    }
    
    /**
     * Test FTP connection with improved error handling.
     *
     * @param array $config FTP connection configuration.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function test_ftp_connection($config) {
        // Check if FTP functions are available
        if (!function_exists('ftp_connect')) {
            $this->log_message('FTP functions are not available on this server');
            return new WP_Error('ftp_functions', __('FTP functions are not available on this server', 'swiftspeed-siberian'));
        }
        
        // Connect to FTP server with timeout
        $ftp_conn = @ftp_connect($config['host'], intval($config['port'] ?: 21), 10);
        if (!$ftp_conn) {
            $this->log_message('Could not connect to FTP server: ' . $config['host']);
            return new WP_Error('ftp_connect', __('Could not connect to FTP server', 'swiftspeed-siberian'));
        }
        
        // Login
        if (!@ftp_login($ftp_conn, $config['username'], $config['password'])) {
            ftp_close($ftp_conn);
            $this->log_message('FTP login failed for user: ' . $config['username']);
            return new WP_Error('ftp_login', __('FTP login failed', 'swiftspeed-siberian'));
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Test directory access
        $path = !empty($config['path']) ? $config['path'] : '/';
        $path = rtrim($path, '/');
        
        // Try different approaches to access the directory
        $chdir_success = false;
        
        // First try the exact path
        if (!empty($path)) {
            $this->log_message("Trying to access directory: {$path}");
            $chdir_success = @ftp_chdir($ftp_conn, $path);
        }
        
        // If that fails, try for root or default directory
        if (!$chdir_success) {
            if ($path == '/' || empty($path)) {
                $this->log_message("Trying default directory (.)");
                $chdir_success = @ftp_chdir($ftp_conn, '.');
                
                if ($chdir_success) {
                    $current_dir = @ftp_pwd($ftp_conn);
                    $this->log_message("Successfully accessed default directory: {$current_dir}");
                }
            } else {
                // Try without leading slash
                if (substr($path, 0, 1) === '/') {
                    $alt_path = substr($path, 1);
                    $this->log_message("Trying path without leading slash: {$alt_path}");
                    $chdir_success = @ftp_chdir($ftp_conn, $alt_path);
                }
            }
        }
        
        if (!$chdir_success) {
            ftp_close($ftp_conn);
            $this->log_message("Could not access directory: {$path}");
            return new WP_Error('ftp_chdir', __('Could not access the specified directory', 'swiftspeed-siberian'));
        }
        
        $current_dir = @ftp_pwd($ftp_conn);
        $this->log_message("Successfully accessed directory: {$current_dir}");
        
        // Close the connection
        ftp_close($ftp_conn);
        
        return true;
    }
    
    /**
     * Test SFTP connection with improved error handling.
     *
     * @param array $config SFTP connection configuration.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function test_sftp_connection($config) {
        // Check if SFTP capabilities are available
        if (!extension_loaded('ssh2') && !class_exists('\phpseclib3\Net\SFTP')) {
            $this->log_message('No SFTP capability available - neither SSH2 extension nor phpseclib found');
            return new WP_Error('sftp_functions', __('No SFTP capability available. Please install the SSH2 PHP extension or phpseclib.', 'swiftspeed-siberian'));
        }
        
        $conn = $this->create_sftp_connection($config);
        if (!$conn) {
            return new WP_Error('sftp_connect', __('Could not connect to SFTP server', 'swiftspeed-siberian'));
        }
        
        // Get the path to test
        $path = !empty($config['path']) ? $config['path'] : '/';
        $path = rtrim($path, '/');
        
        // Test directory access
        $can_access = false;
        
        if ($conn['type'] === 'ssh2') {
            // For SSH2 extension
            $can_access = @is_dir("ssh2.sftp://{$conn['sftp']}{$path}");
            if (!$can_access && substr($path, 0, 1) === '/') {
                // Try without leading slash
                $alt_path = substr($path, 1);
                $can_access = @is_dir("ssh2.sftp://{$conn['sftp']}{$alt_path}");
            }
        }
        else {
            // For phpseclib
            try {
                $can_access = $conn['connection']->is_dir($path);
                if (!$can_access && substr($path, 0, 1) === '/') {
                    // Try without leading slash
                    $alt_path = substr($path, 1);
                    $can_access = $conn['connection']->is_dir($alt_path);
                }
            }
            catch (\Exception $e) {
                $this->log_message('SFTP error checking directory: ' . $e->getMessage());
                $can_access = false;
            }
        }
        
        if (!$can_access) {
            if ($conn['type'] === 'ssh2') {
                @ssh2_disconnect($conn['connection']);
            }
            
            $this->log_message("Could not access directory: {$path}");
            return new WP_Error('sftp_access', __('Could not access the specified directory', 'swiftspeed-siberian'));
        }
        
        $this->log_message("Successfully accessed SFTP directory: {$path}");
        
        // Close the connection
        if ($conn['type'] === 'ssh2') {
            @ssh2_disconnect($conn['connection']);
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
        // Get a connection from the pool
        $ftp_conn = $this->get_ftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not get FTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Change to directory
        if (!@ftp_chdir($ftp_conn, $directory)) {
            $this->return_ftp_connection($ftp_conn, $conn_time);
            return new WP_Error('ftp_chdir', sprintf(__('Could not change to directory: %s', 'swiftspeed-siberian'), $directory));
        }
        
        // Get raw listing
        $raw_list = @ftp_rawlist($ftp_conn, '.');
        
        // Return the connection to the pool
        $this->return_ftp_connection($ftp_conn, $conn_time);
        
        if ($raw_list === false) {
            return new WP_Error('ftp_rawlist', sprintf(__('Failed to get directory listing for: %s', 'swiftspeed-siberian'), $directory));
        }
        
        // Parse the raw listing
        $items = [];
        
        foreach ($raw_list as $item) {
            $parsedItem = $this->parse_ftp_rawlist_item($item);
            if ($parsedItem) {
                $items[] = $parsedItem;
            }
        }
        
        return $items;
    }
    
    /**
     * Get SFTP directory contents using connection pooling.
     *
     * @param array $config SFTP connection configuration.
     * @param string $directory Directory path to list.
     * @return array|WP_Error Array of directory contents or error.
     */
    public function get_sftp_directory_contents_pooled($config, $directory) {
        // Get a connection from the pool
        $sftp_conn = $this->get_sftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$sftp_conn) {
            return new WP_Error('sftp_connect', __('Could not get SFTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Get directory contents based on connection type
        $items = [];
        $error = false;
        
        if ($sftp_conn['type'] === 'ssh2') {
            // For SSH2 extension
            $sftp = $sftp_conn['sftp'];
            
            // Open the directory for reading
            $handle = @opendir("ssh2.sftp://$sftp$directory");
            
            if (!$handle) {
                $this->return_sftp_connection($sftp_conn, $conn_time);
                return new WP_Error('sftp_opendir', sprintf(__('Could not open directory: %s', 'swiftspeed-siberian'), $directory));
            }
            
            while (($file = readdir($handle)) !== false) {
                // Skip . and .. entries
                if ($file === '.' || $file === '..') {
                    $items[] = [
                        'name' => $file,
                        'type' => 'dir',
                        'size' => 0
                    ];
                    continue;
                }
                
                $full_path = rtrim($directory, '/') . '/' . $file;
                
                // Check if it's a directory or file
                $is_dir = @is_dir("ssh2.sftp://$sftp$full_path");
                
                if ($is_dir) {
                    $items[] = [
                        'name' => $file,
                        'type' => 'dir',
                        'size' => 0
                    ];
                } else {
                    // Get file stats
                    $stats = @ssh2_sftp_stat($sftp, $full_path);
                    $file_size = isset($stats['size']) ? $stats['size'] : 0;
                    
                    $items[] = [
                        'name' => $file,
                        'type' => 'file',
                        'size' => $file_size
                    ];
                }
            }
            
            closedir($handle);
        }
        else {
            // For phpseclib
            try {
                $sftp = $sftp_conn['connection'];
                
                // List directory contents
                $list = $sftp->nlist($directory);
                
                if ($list === false) {
                    $error = true;
                } else {
                    foreach ($list as $file) {
                        // Skip . and .. entries if not included
                        if ($file === '.' || $file === '..') {
                            $items[] = [
                                'name' => $file,
                                'type' => 'dir',
                                'size' => 0
                            ];
                            continue;
                        }
                        
                        $full_path = rtrim($directory, '/') . '/' . $file;
                        
                        // Check if it's a directory or file
                        $is_dir = $sftp->is_dir($full_path);
                        
                        if ($is_dir) {
                            $items[] = [
                                'name' => $file,
                                'type' => 'dir',
                                'size' => 0
                            ];
                        } else {
                            // Get file size - phpseclib v3 uses stat() instead of size()
                            $stat = $sftp->stat($full_path);
                            $file_size = isset($stat['size']) ? $stat['size'] : 0;
                            
                            $items[] = [
                                'name' => $file,
                                'type' => 'file',
                                'size' => $file_size
                            ];
                        }
                    }
                }
            }
            catch (\Exception $e) {
                $this->log_message('SFTP error listing directory: ' . $e->getMessage());
                $error = true;
            }
        }
        
        // Return the connection to the pool
        $this->return_sftp_connection($sftp_conn, $conn_time);
        
        if ($error) {
            return new WP_Error('sftp_list', sprintf(__('Failed to get directory listing for: %s', 'swiftspeed-siberian'), $directory));
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
        // Different FTP servers format their listings differently
        // Try Unix-style first (most common)
        if (preg_match('/^([dwrx\-]{10})\s+(\d+)\s+(\w+)\s+(\w+)\s+(\d+)\s+(\w{3}\s+\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s+(.+)$/', $raw_item, $matches)) {
            return [
                'name' => $matches[8],
                'type' => $matches[1][0] === 'd' ? 'dir' : 'file',
                'size' => (int)$matches[5],
                'permissions' => $matches[1]
            ];
        }
        // Try Windows-style FTP format
        else if (preg_match('/^(\d{2}-\d{2}-\d{2}\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|[0-9]+)\s+(.+)$/', $raw_item, $matches)) {
            return [
                'name' => $matches[3],
                'type' => $matches[2] === '<DIR>' ? 'dir' : 'file',
                'size' => $matches[2] === '<DIR>' ? 0 : (int)$matches[2],
                'permissions' => ''
            ];
        }
        // Try alternative formats or implementations
        else {
            // Split by whitespace and try to extract the filename and type
            $parts = preg_split('/\s+/', $raw_item);
            if (count($parts) >= 9) {
                $name = $parts[8];
                $is_dir = strpos($parts[0], 'd') === 0;
                $size = (int)$parts[4];
                
                return [
                    'name' => $name,
                    'type' => $is_dir ? 'dir' : 'file',
                    'size' => $size,
                    'permissions' => $parts[0]
                ];
            }
        }
        
        return false;
    }
    
    /**
     * Check the size of a file on FTP server using connection pooling.
     *
     * @param array $config FTP connection configuration.
     * @param string $file_path File path on FTP server.
     * @return int|WP_Error File size or error.
     */
    public function check_ftp_file_size_pooled($config, $file_path) {
        // Get a connection from the pool
        $ftp_conn = $this->get_ftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not get FTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Get file size
        $size = @ftp_size($ftp_conn, $file_path);
        
        // Return the connection to the pool
        $this->return_ftp_connection($ftp_conn, $conn_time);
        
        if ($size < 0) {
            // Some FTP servers return -1 for directories or errors
            return 0;
        }
        
        return $size;
    }
    
    /**
     * Check the size of a file on SFTP server using connection pooling.
     *
     * @param array $config SFTP connection configuration.
     * @param string $file_path File path on SFTP server.
     * @return int|WP_Error File size or error.
     */
    public function check_sftp_file_size_pooled($config, $file_path) {
        // Get a connection from the pool
        $sftp_conn = $this->get_sftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$sftp_conn) {
            return new WP_Error('sftp_connect', __('Could not get SFTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Get file size based on connection type
        $size = 0;
        $error = false;
        
        if ($sftp_conn['type'] === 'ssh2') {
            // For SSH2 extension
            $sftp = $sftp_conn['sftp'];
            
            $stats = @ssh2_sftp_stat($sftp, $file_path);
            if ($stats === false) {
                $error = true;
            } else {
                $size = isset($stats['size']) ? $stats['size'] : 0;
            }
        }
        else {
            // For phpseclib - using stat() instead of size()
            try {
                $sftp = $sftp_conn['connection'];
                $stat = $sftp->stat($file_path);
                
                if ($stat === false) {
                    $error = true;
                } else {
                    $size = isset($stat['size']) ? $stat['size'] : 0;
                }
            }
            catch (\Exception $e) {
                $this->log_message('SFTP error checking file size: ' . $e->getMessage());
                $error = true;
            }
        }
        
        // Return the connection to the pool
        $this->return_sftp_connection($sftp_conn, $conn_time);
        
        if ($error) {
            return new WP_Error('sftp_size', sprintf(__('Failed to check file size: %s', 'swiftspeed-siberian'), $file_path));
        }
        
        return $size;
    }
    
    /**
     * Download a file from FTP using connection pooling and chunked transfer.
     *
     * @param array $config FTP connection configuration.
     * @param string $remote_path Remote file path.
     * @param string $local_path Local destination path.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function download_file_ftp_pooled($config, $remote_path, $local_path) {
        // Get a connection from the pool
        $ftp_conn = $this->get_ftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$ftp_conn) {
            return new WP_Error('ftp_connect', __('Could not get FTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Ensure directory exists
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                $this->return_ftp_connection($ftp_conn, $conn_time);
                return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Open local file for writing
        $local = @fopen($local_path, 'wb');
        if (!$local) {
            $this->return_ftp_connection($ftp_conn, $conn_time);
            return new WP_Error('local_open', __('Failed to create local file', 'swiftspeed-siberian'));
        }
        
        // Try to get file size first
        $file_size = @ftp_size($ftp_conn, $remote_path);
        
        // For larger files (>5MB), use nb_get for better memory efficiency
        if ($file_size > 5242880) {
            $ret = @ftp_nb_get($ftp_conn, $local_path, $remote_path, FTP_BINARY, FTP_AUTORESUME);
            
            while ($ret == FTP_MOREDATA) {
                // Continue downloading
                $ret = @ftp_nb_continue($ftp_conn);
                
                // Allow other processes to run
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            @fclose($local);
            
            // Return the connection to the pool
            $this->return_ftp_connection($ftp_conn, $conn_time);
            
            if ($ret != FTP_FINISHED) {
                @unlink($local_path);
                return new WP_Error('ftp_get', __('Failed to download file from FTP', 'swiftspeed-siberian'));
            }
        } else {
            // For smaller files, use regular get
            @fclose($local);
            
            $result = @ftp_get($ftp_conn, $local_path, $remote_path, FTP_BINARY);
            
            // Return the connection to the pool
            $this->return_ftp_connection($ftp_conn, $conn_time);
            
            if (!$result) {
                @unlink($local_path);
                return new WP_Error('ftp_get', __('Failed to download file from FTP', 'swiftspeed-siberian'));
            }
        }
        
        return true;
    }
    
    /**
     * Download a file from SFTP using connection pooling with memory-efficient streaming.
     *
     * @param array $config SFTP connection configuration.
     * @param string $remote_path Remote file path.
     * @param string $local_path Local destination path.
     * @return bool|WP_Error True on success or error on failure.
     */
    public function download_file_sftp_pooled($config, $remote_path, $local_path) {
        // Get a connection from the pool
        $sftp_conn = $this->get_sftp_connection($config);
        $conn_time = !empty($this->connection_times) ? end($this->connection_times) : time();
        
        if (!$sftp_conn) {
            return new WP_Error('sftp_connect', __('Could not get SFTP connection from pool', 'swiftspeed-siberian'));
        }
        
        // Ensure directory exists
        $dir = dirname($local_path);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                $this->return_sftp_connection($sftp_conn, $conn_time);
                return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Download file based on connection type
        $result = false;
        $error_message = '';
        
        if ($sftp_conn['type'] === 'ssh2') {
            // For SSH2 extension - use chunked streaming
            $sftp = $sftp_conn['sftp'];
            
            // Stream copy from SFTP to local file
            $stream = @fopen("ssh2.sftp://$sftp$remote_path", 'rb');
            
            if (!$stream) {
                $this->return_sftp_connection($sftp_conn, $conn_time);
                return new WP_Error('sftp_open', __('Failed to open remote file', 'swiftspeed-siberian'));
            }
            
            $local_stream = @fopen($local_path, 'wb');
            
            if (!$local_stream) {
                @fclose($stream);
                $this->return_sftp_connection($sftp_conn, $conn_time);
                return new WP_Error('local_open', __('Failed to create local file', 'swiftspeed-siberian'));
            }
            
            // Copy in chunks to avoid memory issues
            $bytes_copied = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, $this->download_chunk_size);
                if ($chunk === false) {
                    @fclose($stream);
                    @fclose($local_stream);
                    @unlink($local_path);
                    $this->return_sftp_connection($sftp_conn, $conn_time);
                    return new WP_Error('sftp_read', __('Failed to read from remote file', 'swiftspeed-siberian'));
                }
                
                $written = fwrite($local_stream, $chunk);
                if ($written === false || $written != strlen($chunk)) {
                    @fclose($stream);
                    @fclose($local_stream);
                    @unlink($local_path);
                    $this->return_sftp_connection($sftp_conn, $conn_time);
                    return new WP_Error('local_write', __('Failed to write to local file', 'swiftspeed-siberian'));
                }
                
                $bytes_copied += $written;
                unset($chunk); // Free memory
            }
            
            @fclose($stream);
            @fclose($local_stream);
            
            $result = ($bytes_copied > 0);
        }
        else {
            // For phpseclib - use chunked download if available
            try {
                $sftp = $sftp_conn['connection'];
                
                // Get file size first
                $stat = $sftp->stat($remote_path);
                $file_size = isset($stat['size']) ? $stat['size'] : 0;
                
                // For larger files, use chunked download if available
                if ($file_size > 5242880) { // 5MB
                    // Open local file for writing
                    $local = @fopen($local_path, 'wb');
                    if (!$local) {
                        $this->return_sftp_connection($sftp_conn, $conn_time);
                        return new WP_Error('local_open', __('Failed to create local file', 'swiftspeed-siberian'));
                    }
                    
                    // Read file in chunks
                    $offset = 0;
                    while ($offset < $file_size) {
                        $length = min($this->download_chunk_size, $file_size - $offset);
                        $chunk = $sftp->get($remote_path, false, $offset, $length);
                        
                        if ($chunk === false) {
                            @fclose($local);
                            @unlink($local_path);
                            $error_message = 'Failed to get file chunk';
                            break;
                        }
                        
                        fwrite($local, $chunk);
                        $offset += strlen($chunk);
                        unset($chunk); // Free memory
                    }
                    
                    @fclose($local);
                    $result = ($offset >= $file_size);
                } else {
                    // For smaller files, use regular download
                    $result = $sftp->get($remote_path, $local_path);
                    
                    if ($result === false) {
                        $error_message = 'Failed to get file';
                    }
                }
            }
            catch (\Exception $e) {
                $this->log_message('SFTP error downloading file: ' . $e->getMessage());
                $error_message = $e->getMessage();
            }
        }
        
        // Return the connection to the pool
        $this->return_sftp_connection($sftp_conn, $conn_time);
        
        if (!$result) {
            @unlink($local_path);
            return new WP_Error('sftp_download', __('Failed to download file from SFTP', 'swiftspeed-siberian') . 
                               ($error_message ? ': ' . $error_message : ''));
        }
        
        return true;
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