<?php
/**
 * Enhanced file restore handler for Siberian CMS backups.
 * Version 2.0 - Comprehensive restart-resistant implementation with advanced SFTP support
 */
class SwiftSpeed_Siberian_File_Restore {
    /**
     * Connection resource
     * 
     * @var mixed
     */
    private $connection = null;
    
    /**
     * Current batch size for file operations (dynamic).
     * 
     * @var int
     */
    private $batch_size = 200;
    
    /**
     * Maximum number of retries for failed operations.
     * 
     * @var int
     */
    private $max_retries = 1;
    
    /**
     * Base path for operations.
     * 
     * @var string
     */
    private $base_path = '';
    
    /**
     * Connection type (ftp, sftp, local)
     * 
     * @var string
     */
    private $connection_type = '';
    
    /**
     * Connection handler (for better organization)
     * 
     * @var object
     */
    private $connection_handler = null;
    
    /**
     * SFTP connection instance for phpseclib
     * 
     * @var object
     */
    private $sftp_connection = null;
    
    /**
     * SSH2 connection instance
     * 
     * @var resource
     */
    private $ssh2_connection = null;
    
    /**
     * SSH2 SFTP resource
     * 
     * @var resource
     */
    private $ssh2_sftp = null;
    
    /**
     * Connection configuration
     * 
     * @var array
     */
    private $config = [];
    
    /**
     * Track created directories to avoid redundant operations
     * 
     * @var array
     */
    private $created_directories = [];
    
    /**
     * Root directory flag - used to track if we're at root or not
     * 
     * @var bool
     */
    private $at_root_dir = false;

    /**
     * Current working directory for FTP
     * 
     * @var string
     */
    private $current_ftp_dir = '';
    
    /**
     * Process files restore with progressive approach
     * 
     * @param array $status Current restore status
     * @return array|WP_Error Updated status or error
     */
    public function process($status) {
        if (!$status['has_files']) {
            // No files to restore, move to cleanup phase
            $status['phase'] = 'cleanup';
            $status['status'] = 'processing';
            $status['message'] = __('Finalizing restore...', 'swiftspeed-siberian');
            
            return $status;
        }
        
        // Check if files haven't been initialized yet
        if (!isset($status['files_initialized'])) {
            return $this->initialize_files_restore($status);
        }
        
        // Process the next batch of files
        return $this->restore_files_batch($status);
    }
    
    /**
     * Initialize files restore with installation settings
     * 
     * @param array $status Current restore status
     * @return array|WP_Error Updated status or error
     */
    private function initialize_files_restore($status) {
        // Check if files directory exists
        $files_dir = $status['extract_dir'] . 'files/';
        
        if (!file_exists($files_dir)) {
            $this->log_message('Files directory not found: ' . $files_dir);
            $status['phase'] = 'cleanup';
            $status['message'] = __('Finalizing restore...', 'swiftspeed-siberian');
            
            return $status;
        }
        
        // Get installation connection settings (already verified by user)
        $options = swsib()->get_options();
        $installation_options = isset($options['installation']) ? $options['installation'] : [];
        
        if (empty($installation_options['is_configured'])) {
            $this->log_message('Installation connection is not configured');
            return new WP_Error('restore_error', __('Installation connection is not configured', 'swiftspeed-siberian'));
        }
        
        $connection_method = $installation_options['connection_method'];
        $connection_config = isset($installation_options[$connection_method]) ? $installation_options[$connection_method] : [];
        
        // For SFTP connections, ensure the config has the right structure
        if ($connection_method === 'sftp' && !isset($connection_config['host_sftp']) && isset($connection_config['host'])) {
            $connection_config['host_sftp'] = $connection_config['host'];
            $connection_config['username_sftp'] = $connection_config['username'];
            $connection_config['password_sftp'] = $connection_config['password'];
            $connection_config['port_sftp'] = isset($connection_config['port']) ? $connection_config['port'] : 22;
            $connection_config['path_sftp'] = isset($connection_config['path']) ? $connection_config['path'] : '';
        }
        
        // Initialize the appropriate connection handler
        $result = $this->initialize_connection($connection_method, $connection_config);
        if (is_wp_error($result)) {
            $this->log_message('CRITICAL: Failed to establish connection: ' . $result->get_error_message());
            return $result;
        }
        
        // Scan directory structure first to organize directories and files with retry logic
        $scan_attempts = 0;
        $scan_result = false;
        
        while ($scan_attempts < 3 && $scan_result === false) {
            $scan_result = $this->scan_backup_structure($files_dir);
            if (is_wp_error($scan_result)) {
                $this->log_message('Error scanning backup structure (attempt ' . ($scan_attempts + 1) . '): ' . $scan_result->get_error_message());
                $scan_attempts++;
                if ($scan_attempts >= 3) {
                    return $scan_result;
                }
                sleep(1); // Wait 1 second before retrying
            }
        }
        
        // Store scan results
        $directories = $scan_result['directories'];
        $files = $scan_result['files'];
        $total_files_size = $scan_result['total_size'];
        
        // Respect user's configured batch size
        $this->batch_size = isset($status['batch_size']) ? (int)$status['batch_size'] : 25;
        
        // Create balanced batching to show progress quickly
        // Allocate 70% of batch operations to directories initially
        $dir_batch_size = ceil(min($this->batch_size * 0.7, count($directories)));
        $file_batch_size = max(1, min($this->batch_size - $dir_batch_size, count($files)));
        
        $this->log_message('Scan complete: Found ' . count($directories) . ' directories and ' . count($files) . ' files to restore');
        $this->log_message('Using user-configured batch size: ' . $this->batch_size . ' (dirs: ' . $dir_batch_size . ', files: ' . $file_batch_size . ')');
        
        // Create queues for directories and files
        $dir_queue = new SplQueue();
        foreach ($directories as $dir) {
            $dir_queue->enqueue($dir);
        }
        
        $file_queue = new SplQueue();
        foreach ($files as $file) {
            $file_queue->enqueue($file);
        }
        
        // Update status
        $status['files_initialized'] = true;
        $status['dir_queue'] = serialize($dir_queue);
        $status['file_queue'] = serialize($file_queue);
        $status['files_total'] = count($directories) + count($files);
        $status['files_processed'] = 0;
        $status['files_processed_size'] = 0;
        $status['files_size'] = $total_files_size;
        $status['connection_method'] = $connection_method;
        $status['connection_config'] = $connection_config;
        $status['message'] = sprintf(
            __('Restoring files... (%d files, %s)', 'swiftspeed-siberian'),
            count($directories) + count($files),
            size_format($total_files_size)
        );
        $status['dir_batch_size'] = $dir_batch_size;
        $status['file_batch_size'] = $file_batch_size;
        $status['dirs_total'] = count($directories);
        $status['files_count'] = count($files);
        $status['dirs_processed'] = 0;
        $status['actual_files_processed'] = 0;
        $status['connection_attempts'] = 0;  // Track connection attempts for more robustness
        $status['last_connection_status'] = true; // Track connection status
        $status['path_cache'] = []; // Cache of already created paths
        $status['created_directories'] = []; // Track successfully created directories
        
        return $status;
    }
    
    /**
     * Scan backup structure to organize directories and files
     * 
     * @param string $files_dir Files directory path
     * @return array|WP_Error Scanned structure or error
     */
    private function scan_backup_structure($files_dir) {
        if (!file_exists($files_dir)) {
            return new WP_Error('scan_error', __('Backup files directory not found', 'swiftspeed-siberian'));
        }
        
        $directories = [];
        $files = [];
        $total_size = 0;
        $files_dir = rtrim($files_dir, '/') . '/';
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($files_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            // Sort directories first by path depth (parent before children)
            $all_items = [];
            
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                $relative_path = substr($path, strlen($files_dir));
                
                // Skip backup metadata files
                if (basename($relative_path) === 'README.txt' || basename($relative_path) === '.test') {
                    continue;
                }
                
                $item = [
                    'path' => $path,
                    'relative_path' => $relative_path,
                    'type' => $file->isDir() ? 'dir' : 'file',
                    'depth' => substr_count($relative_path, '/'),
                    'size' => $file->isFile() ? $file->getSize() : 0
                ];
                
                $all_items[] = $item;
            }
            
            // Sort by type (directories first) and then by depth
            usort($all_items, function($a, $b) {
                if ($a['type'] === 'dir' && $b['type'] !== 'dir') {
                    return -1;
                }
                if ($a['type'] !== 'dir' && $b['type'] === 'dir') {
                    return 1;
                }
                // For directories, sort by depth (shallowest first)
                if ($a['type'] === 'dir' && $b['type'] === 'dir') {
                    if ($a['depth'] !== $b['depth']) {
                        return $a['depth'] - $b['depth'];
                    }
                    // For same depth, sort alphabetically
                    return strcmp($a['relative_path'], $b['relative_path']);
                }
                // For files, we don't need any specific ordering for processing
                return 0;
            });
            
            // Separate into directories and files
            foreach ($all_items as $item) {
                if ($item['type'] === 'dir') {
                    $directories[] = $item;
                } else {
                    $files[] = $item;
                    $total_size += $item['size'];
                }
            }
        } catch (Exception $e) {
            return new WP_Error('scan_error', sprintf(__('Failed to scan backup: %s', 'swiftspeed-siberian'), $e->getMessage()));
        }
        
        return [
            'directories' => $directories,
            'files' => $files,
            'total_size' => $total_size
        ];
    }
    
    /**
     * Initialize the appropriate connection handler
     * 
     * @param string $connection_method Connection method
     * @param array $config Connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function initialize_connection($connection_method, $config) {
        $this->connection_type = $connection_method;
        $this->config = $config;
        
        switch ($connection_method) {
            case 'ftp':
                return $this->initialize_ftp_connection($config);
                
            case 'sftp':
                return $this->initialize_sftp_connection($config);
                
            case 'local':
                return $this->initialize_local_connection($config);
                
            default:
                return new WP_Error('connection_error', __('Unknown connection method', 'swiftspeed-siberian'));
        }
    }
    
    /**
     * Initialize FTP connection with enhanced reliability
     * 
     * @param array $config FTP connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function initialize_ftp_connection($config) {
        // Check if FTP functions are available
        if (!function_exists('ftp_connect')) {
            return new WP_Error('ftp_error', __('FTP functions not available on this server', 'swiftspeed-siberian'));
        }
        
        $host = $config['host'];
        $username = $config['username'];
        $password = $config['password'];
        $port = isset($config['port']) ? intval($config['port']) : 21;
        $this->base_path = isset($config['path']) ? rtrim($config['path'], '/') : '';
        
        // Reset directory tracking
        $this->created_directories = [];
        $this->at_root_dir = false;
        $this->current_ftp_dir = '';
        
        // Create connection with timeout
        $this->log_message('Connecting to FTP server: ' . $host . ':' . $port);
        $this->connection = @ftp_connect($host, $port, 30);
        if (!$this->connection) {
            return new WP_Error('ftp_connect', __('Could not connect to FTP server', 'swiftspeed-siberian'));
        }
        
        // Login with retry
        $login_attempts = 0;
        $login_success = false;
        
        while ($login_attempts < 3 && !$login_success) {
            $this->log_message('Attempting FTP login as: ' . $username);
            $login_success = @ftp_login($this->connection, $username, $password);
            
            if (!$login_success) {
                $login_attempts++;
                if ($login_attempts >= 3) {
                    @ftp_close($this->connection);
                    $this->connection = null;
                    return new WP_Error('ftp_login', __('FTP login failed after multiple attempts', 'swiftspeed-siberian'));
                }
                sleep(1); // Wait before retrying
            }
        }
        
        // Set passive mode - essential for many firewalls and NATs
        $this->log_message('Setting FTP passive mode');
        @ftp_pasv($this->connection, true);
        
        // Set longer timeouts for reliability
        @ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 300); // Increase timeout to 5 minutes
        
        // Get current directory for reference
        $this->current_ftp_dir = @ftp_pwd($this->connection);
        $this->log_message('Initial FTP directory: ' . $this->current_ftp_dir);
        
        // Always start from root and verify we can get there
        $root_success = @ftp_chdir($this->connection, '/');
        if (!$root_success) {
            $this->log_message('Warning: Could not change to root directory, trying to continue anyway');
        } else {
            $this->at_root_dir = true;
            $this->current_ftp_dir = '/';
        }
        
        // Test or create base directory
        if (!empty($this->base_path)) {
            $base_dir_exists = $this->ensure_base_directory_exists();
            if (!$base_dir_exists) {
                $this->log_message('Warning: Base path does not exist and could not be created. Will attempt to create during restore operations.');
            } else {
                $this->log_message('Base path successfully verified or created: ' . $this->base_path);
                
                // Add base path to created directories
                $this->created_directories[$this->base_path] = true;
            }
            
            // Try to get current directory
            $pwd = @ftp_pwd($this->connection);
            if ($pwd !== false) {
                $this->current_ftp_dir = $pwd;
                $this->log_message('Current FTP directory after initialization: ' . $this->current_ftp_dir);
            }
        }
        
        $this->log_message('FTP connected to: ' . $host . ':' . $port . ' | Base path: ' . $this->base_path . ' | Current directory: ' . $this->current_ftp_dir);
        return true;
    }
    
    /**
     * Ensure the base directory exists, creating it if necessary with enhanced robustness
     * 
     * @return bool True if directory exists or was successfully created
     */
    private function ensure_base_directory_exists() {
        if (empty($this->base_path)) {
            return true; // No base path specified, nothing to create
        }
        
        if (!$this->connection) {
            return false;
        }
        
        // First try to directly change to the directory
        $this->log_message('Checking if base path exists: ' . $this->base_path);
        
        // Ensure we're at root first
        $root_success = @ftp_chdir($this->connection, '/');
        if (!$root_success) {
            $this->log_message('Warning: Could not change to root directory');
            // Try with a relative path instead
            $base_exists = @ftp_chdir($this->connection, $this->base_path);
        } else {
            $this->at_root_dir = true;
            $this->current_ftp_dir = '/';
            $base_exists = @ftp_chdir($this->connection, $this->base_path);
        }
        
        if ($base_exists) {
            $this->log_message('Base path exists: ' . $this->base_path);
            // Get current directory for reference
            $pwd = @ftp_pwd($this->connection);
            if ($pwd !== false) {
                $this->current_ftp_dir = $pwd;
            }
            return true;
        }
        
        // If base path doesn't exist, create it component by component
        $this->log_message('Base path does not exist, creating: ' . $this->base_path);
        
        // Make sure we're at root
        if (!$this->at_root_dir) {
            $root_success = @ftp_chdir($this->connection, '/');
            if ($root_success) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
            }
        }
        
        // Split the path and create each component
        $path_components = array_filter(explode('/', $this->base_path));
        $current_path = '';
        
        foreach ($path_components as $component) {
            if (empty($component)) continue;
            
            // Build current path incrementally
            $current_path .= '/' . $component;
            
            // Try to change to this directory to see if it exists
            $dir_exists = @ftp_chdir($this->connection, $current_path);
            
            if (!$dir_exists) {
                // Directory doesn't exist, try to create it
                $this->log_message('Creating directory: ' . $current_path);
                
                // Try different approaches to create the directory
                $dir_created = false;
                
                // Approach 1: Create from current position
                if ($this->at_root_dir) {
                    $dir_created = @ftp_mkdir($this->connection, $current_path);
                }
                
                // Approach 2: Go to parent and create
                if (!$dir_created) {
                    $parent_path = dirname($current_path);
                    if ($parent_path == '/') {
                        // We're trying to create a top-level directory
                        $go_to_parent = @ftp_chdir($this->connection, '/');
                    } else {
                        $go_to_parent = @ftp_chdir($this->connection, $parent_path);
                    }
                    
                    if ($go_to_parent) {
                        $dir_created = @ftp_mkdir($this->connection, $component);
                        $this->current_ftp_dir = $parent_path;
                    }
                }
                
                // Approach 3: Try with absolute path
                if (!$dir_created) {
                    // Go back to root
                    $root_success = @ftp_chdir($this->connection, '/');
                    if ($root_success) {
                        $this->at_root_dir = true;
                        $this->current_ftp_dir = '/';
                        // Try with absolute path
                        $dir_created = @ftp_mkdir($this->connection, $current_path);
                    }
                }
                
                if (!$dir_created) {
                    $this->log_message('Failed to create directory: ' . $current_path);
                    return false;
                }
            }
            
            // Finally, ensure we're in the directory
            $chdir_success = @ftp_chdir($this->connection, $current_path);
            if (!$chdir_success) {
                $this->log_message('Failed to change to directory after creation: ' . $current_path);
                return false;
            }
            
            $this->current_ftp_dir = $current_path;
            $this->at_root_dir = false;
        }
        
        // Verify the final path
        if (@ftp_pwd($this->connection) === $this->base_path) {
            $this->log_message('Successfully created and verified base path: ' . $this->base_path);
            return true;
        }
        
        return false;
    }
    
    /**
     * Initialize SFTP connection with advanced handling
     * 
     * @param array $config SFTP connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function initialize_sftp_connection($config) {
        // Normalize the config fields - support both direct and UI-based parameter naming
        $host = isset($config['host_sftp']) ? $config['host_sftp'] : (isset($config['host']) ? $config['host'] : '');
        $username = isset($config['username_sftp']) ? $config['username_sftp'] : (isset($config['username']) ? $config['username'] : '');
        $password = isset($config['password_sftp']) ? $config['password_sftp'] : (isset($config['password']) ? $config['password'] : '');
        $port = isset($config['port_sftp']) ? intval($config['port_sftp']) : (isset($config['port']) ? intval($config['port']) : 22);
        $this->base_path = isset($config['path_sftp']) ? rtrim($config['path_sftp'], '/') : (isset($config['path']) ? rtrim($config['path'], '/') : '');
        
        // Store normalized values in config
        $this->config = [
            'host_sftp' => $host,
            'username_sftp' => $username,
            'password_sftp' => $password,
            'port_sftp' => $port,
            'path_sftp' => $this->base_path
        ];
        
        // Log connection details for debugging (redact password)
        $debug_config = $this->config;
        $debug_config['password_sftp'] = '********';
        $this->log_message('SFTP connection parameters: ' . print_r($debug_config, true));
        
        // Try direct SFTP connection methods first
        if (extension_loaded('ssh2')) {
            try {
                $this->log_message('Using SSH2 extension for SFTP');
                
                // Connect with timeout option
                $this->ssh2_connection = @ssh2_connect($host, $port, [], ['timeout' => 30]);
                
                if (!$this->ssh2_connection) {
                    $this->log_message('SSH2 connection failed, will try alternative methods');
                    throw new Exception('SSH2 connection failed');
                }
                
                // Authenticate with basic password authentication
                if (!@ssh2_auth_password($this->ssh2_connection, $username, $password)) {
                    $this->log_message('SSH2 authentication failed, will try alternative methods');
                    throw new Exception('SSH2 authentication failed');
                }
                
                // Initialize SFTP subsystem
                $this->ssh2_sftp = @ssh2_sftp($this->ssh2_connection);
                if (!$this->ssh2_sftp) {
                    $this->log_message('Failed to initialize SFTP subsystem, will try alternative methods');
                    throw new Exception('Failed to initialize SFTP subsystem');
                }
                
                // Test directory access
                $path_exists = @is_dir("ssh2.sftp://{$this->ssh2_sftp}{$this->base_path}");
                if (!$path_exists) {
                    // Try to create the base path
                    $this->log_message('Base path does not exist, will create during restore: ' . $this->base_path);
                }
                
                $this->log_message('SSH2 SFTP connection established successfully');
                return true;
                
            } catch (Exception $e) {
                $this->log_message('SSH2 exception: ' . $e->getMessage() . ' - Will try phpseclib');
                // Continue to phpseclib if SSH2 fails
            }
        }
        
        // Try phpseclib3 if available
        if (class_exists('\phpseclib3\Net\SFTP')) {
            try {
                $this->log_message('Using phpseclib3 for SFTP connection');
                
                // Create new SFTP instance
                $this->sftp_connection = new \phpseclib3\Net\SFTP($host, $port, 30);
                
                // Login
                if (!$this->sftp_connection->login($username, $password)) {
                    $this->log_message('Phpseclib3 SFTP authentication failed');
                    return new WP_Error('sftp_auth', __('Could not authenticate with SFTP server', 'swiftspeed-siberian'));
                }
                
                // Test directory access
                if (!$this->sftp_connection->is_dir($this->base_path)) {
                    $this->log_message('Base path does not exist, will create during restore: ' . $this->base_path);
                }
                
                $this->log_message('Phpseclib3 SFTP connection established successfully');
                return true;
                
            } catch (Exception $e) {
                $this->log_message('Phpseclib3 exception: ' . $e->getMessage());
                return new WP_Error('sftp_error', $e->getMessage());
            }
        }
        
        // If we reach here, no SFTP methods available
        return new WP_Error('sftp_missing', __('No SFTP capability available (neither SSH2 extension nor phpseclib3)', 'swiftspeed-siberian'));
    }
    
    /**
     * Initialize Local filesystem connection
     * 
     * @param array $config Local connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function initialize_local_connection($config) {
        $this->base_path = isset($config['path']) ? rtrim($config['path'], '/') : '';
        
        if (empty($this->base_path)) {
            return new WP_Error('local_path', __('Local path is not configured', 'swiftspeed-siberian'));
        }
        
        // Check if directory exists
        if (!file_exists($this->base_path)) {
            $this->log_message('Local path does not exist, will create it: ' . $this->base_path);
            
            // Try to create the path
            if (!wp_mkdir_p($this->base_path)) {
                return new WP_Error('local_mkdir', __('Could not create local directory', 'swiftspeed-siberian'));
            }
        }
        
        // Check if we have write permissions
        if (!is_writable($this->base_path)) {
            return new WP_Error('local_write', __('No write permission to local directory', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Local filesystem connection initialized with base path: ' . $this->base_path);
        return true;
    }
    
    /**
     * Restore files in batches - Progressive approach
     * 
     * @param array $status Current restore status
     * @return array|WP_Error Updated status or error
     */
    private function restore_files_batch($status) {
        // Check connection health and reconnect if needed
        if (!$this->check_connection_health($status)) {
            $reconnect_result = $this->reconnect($status);
            if (is_wp_error($reconnect_result)) {
                $this->log_message('Failed to reconnect: ' . $reconnect_result->get_error_message());
                
                // If we've had too many connection failures, fail the restore
                if ($status['connection_attempts'] > 5) {
                    $this->log_message('Too many connection failures, aborting restore');
                    $status['status'] = 'error';
                    $status['message'] = __('Too many connection failures, restore aborted', 'swiftspeed-siberian');
                    return $status;
                }
                
                // Otherwise, return the status with connection_attempts incremented
                // Next time we'll try to reconnect again
                return $status;
            }
        }
        
        $dir_queue = unserialize($status['dir_queue']);
        $file_queue = unserialize($status['file_queue']);
        $retry_items = isset($status['retry_items']) ? $status['retry_items'] : [];
        
        // Use created directories from status if available
        if (isset($status['created_directories']) && is_array($status['created_directories'])) {
            $this->created_directories = $status['created_directories'];
        }
        
        // Respect user's configured batch size
        $this->batch_size = isset($status['batch_size']) ? (int)$status['batch_size'] : 25;
        
        // Keep original batch sizes, but ensure we don't exceed total batch size
        $dir_batch_size = min($status['dir_batch_size'], $this->batch_size);
        $file_batch_size = min($status['file_batch_size'], $this->batch_size - $dir_batch_size);
        
        // As directories get processed, allocate more batch capacity to files
        if ($dir_queue->count() < $status['dirs_total'] / 2) {
            // We've processed more than half the directories, allocate more to files
            $dir_batch_size = ceil($this->batch_size * 0.3);
            $file_batch_size = $this->batch_size - $dir_batch_size;
        }
        
        // Dynamic batch size based on completion
        if ($dir_queue->count() === 0) {
            // All directories processed, focus entirely on files
            $dir_batch_size = 0;
            $file_batch_size = $this->batch_size;
        } elseif ($file_queue->count() === 0) {
            // All files processed, focus entirely on directories
            $dir_batch_size = $this->batch_size;
            $file_batch_size = 0;
        }
        
        $batch_count = 0;
        $batch_start_time = microtime(true);
        $batch_size_processed = 0;
        $total_batch_operations = 0;
        
        // Cache of already verified parent directories to avoid redundant checks
        $verified_parents = isset($status['path_cache']) ? $status['path_cache'] : [];
        
        // Balance processing between directories and files to show progress quickly
        $dir_ops_count = 0;
        $file_ops_count = 0;
        $retry_ops_count = 0;
        
        // Process retry items first
        if (!empty($retry_items) && $total_batch_operations < $this->batch_size) {
            $retry_batch_size = min(count($retry_items), ceil($this->batch_size * 0.3));
            $this->log_message('Processing ' . $retry_batch_size . ' retry items');
            
            $remaining_retries = [];
            $processed_retries = 0;
            
            foreach ($retry_items as $retry_item) {
                if ($processed_retries >= $retry_batch_size || $total_batch_operations >= $this->batch_size) {
                    $remaining_retries[] = $retry_item;
                    continue;
                }
                
                // Increment retry count if not already set
                if (!isset($retry_item['retry_count'])) {
                    $retry_item['retry_count'] = 1;
                } else {
                    $retry_item['retry_count']++;
                }
                
                // If exceeded max retries, log failure and skip
                if ($retry_item['retry_count'] > $this->max_retries) {
                    $this->log_message('Max retries exceeded for: ' . $retry_item['path']);
                    $status['failed_files'][] = [
                        'path' => $retry_item['path'],
                        'type' => $retry_item['type'],
                        'message' => __('Max retries exceeded', 'swiftspeed-siberian')
                    ];
                    $processed_retries++;
                    $retry_ops_count++;
                    $total_batch_operations++;
                    continue;
                }
                
                $status['current_item'] = $retry_item['path'];
                
                // Process based on item type
                if ($retry_item['type'] === 'dir') {
                    // Ensure parent directories exist before creating this one
                    $parent_dir = dirname($retry_item['path']);
                    if ($parent_dir !== '.' && $parent_dir !== '/' && !isset($verified_parents[$parent_dir])) {
                        // Need to create parent directory first
                        $parent_result = $this->create_directory($parent_dir);
                        if (!is_wp_error($parent_result)) {
                            $verified_parents[$parent_dir] = true;
                            $this->created_directories[$parent_dir] = true;
                        }
                    }
                    
                    // Try to create the directory
                    $result = $this->create_directory($retry_item['path']);
                    
                    if (is_wp_error($result)) {
                        $this->log_message('Retry failed for directory: ' . $retry_item['path'] . ' - ' . $result->get_error_message());
                        $remaining_retries[] = $retry_item;
                    } else {
                        $status['dirs_processed']++;
                        $verified_parents[$retry_item['path']] = true;
                        $this->created_directories[$retry_item['path']] = true;
                    }
                } else {
                    // Ensure parent directory exists before copying file
                    $parent_dir = dirname($retry_item['path']);
                    if ($parent_dir !== '.' && $parent_dir !== '/' && !isset($verified_parents[$parent_dir])) {
                        // Need to create parent directory first
                        $parent_result = $this->create_directory($parent_dir);
                        if (!is_wp_error($parent_result)) {
                            $verified_parents[$parent_dir] = true;
                            $this->created_directories[$parent_dir] = true;
                        }
                    }
                    
                    // Try to copy the file
                    $result = $this->copy_file($retry_item['item']['path'], $retry_item['path']);
                    
                    if (is_wp_error($result)) {
                        $this->log_message('Retry failed for file: ' . $retry_item['path'] . ' - ' . $result->get_error_message());
                        $remaining_retries[] = $retry_item;
                    } else {
                        $batch_size_processed += isset($retry_item['item']['size']) ? $retry_item['item']['size'] : 0;
                        $status['files_processed_size'] += isset($retry_item['item']['size']) ? $retry_item['item']['size'] : 0;
                        $status['actual_files_processed']++;
                    }
                }
                
                $processed_retries++;
                $retry_ops_count++;
                $total_batch_operations++;
                
                // Check connection health periodically during operations
                if ($retry_ops_count % 10 === 0) {
                    if (!$this->check_connection_health($status)) {
                        $this->log_message('Connection lost during retry operations, reconnecting...');
                        $reconnect_result = $this->reconnect($status);
                        if (is_wp_error($reconnect_result)) {
                            // If reconnection fails, store remaining items and return
                            $status['retry_items'] = array_merge($remaining_retries, array_slice($retry_items, $processed_retries));
                            $status['dir_queue'] = serialize($dir_queue);
                            $status['file_queue'] = serialize($file_queue);
                            $status['path_cache'] = $verified_parents;
                            $status['created_directories'] = $this->created_directories;
                            $status['connection_attempts']++;
                            return $status;
                        }
                    }
                }
            }
            
            // Update retry items
            $retry_items = $remaining_retries;
        }
        
        // Check if we still have capacity for more operations
        if ($total_batch_operations >= $this->batch_size) {
            // Update status and return
            $status['retry_items'] = $retry_items;
            $status['dir_queue'] = serialize($dir_queue);
            $status['file_queue'] = serialize($file_queue);
            $status['path_cache'] = $verified_parents;
            $status['created_directories'] = $this->created_directories;
            
            // Update progress
            $this->update_status_progress($status, $batch_size_processed, $batch_start_time);
            return $status;
        }
        
        // Process both directories and files in each batch for visible progress
        // First process directories up to dir_batch_size
        while (!$dir_queue->isEmpty() && $dir_ops_count < $dir_batch_size && $total_batch_operations < $this->batch_size) {
            $dir = $dir_queue->dequeue();
            $status['current_item'] = $dir['relative_path'];
            
            // Ensure parent directories exist before creating this one
            $parent_dir = dirname($dir['relative_path']);
            if ($parent_dir !== '.' && $parent_dir !== '/' && !isset($verified_parents[$parent_dir]) && !isset($this->created_directories[$parent_dir])) {
                // Add this directory back to the queue and process its parent first
                $dir_queue->enqueue($dir);
                
                // Create parent directory
                $parent_result = $this->create_directory($parent_dir);
                if (!is_wp_error($parent_result)) {
                    $verified_parents[$parent_dir] = true;
                    $this->created_directories[$parent_dir] = true;
                    $status['dirs_processed']++;
                } else {
                    // Failed to create parent, add to retry queue
                    $retry_items[] = [
                        'path' => $parent_dir,
                        'type' => 'dir',
                        'retry_count' => 1,
                        'item' => ['relative_path' => $parent_dir]
                    ];
                }
                
                $dir_ops_count++;
                $total_batch_operations++;
                continue;
            }
            
            // Check if directory is already created
            if (isset($this->created_directories[$dir['relative_path']])) {
                $this->log_message('Directory already created, skipping: ' . $dir['relative_path']);
                $status['dirs_processed']++;
                $status['files_processed']++;
                $dir_ops_count++;
                $total_batch_operations++;
                continue;
            }
            
            // Create directory
            $result = $this->create_directory($dir['relative_path']);
            
            if (is_wp_error($result)) {
                $this->log_message('Failed to create directory: ' . $dir['relative_path'] . ' - ' . $result->get_error_message());
                
                // Add to retry list
                $retry_items[] = [
                    'path' => $dir['relative_path'],
                    'type' => 'dir',
                    'retry_count' => 1,
                    'item' => $dir
                ];
            } else {
                $status['dirs_processed']++;
                $verified_parents[$dir['relative_path']] = true;
                $this->created_directories[$dir['relative_path']] = true;
            }
            
            $status['files_processed']++;
            $dir_ops_count++;
            $total_batch_operations++;
            
            // Check connection health periodically
            if ($dir_ops_count % 10 === 0) {
                if (!$this->check_connection_health($status)) {
                    $this->log_message('Connection lost during directory operations, reconnecting...');
                    $reconnect_result = $this->reconnect($status);
                    if (is_wp_error($reconnect_result)) {
                        // If reconnection fails, store current state and return
                        $status['retry_items'] = $retry_items;
                        $status['dir_queue'] = serialize($dir_queue);
                        $status['file_queue'] = serialize($file_queue);
                        $status['path_cache'] = $verified_parents;
                        $status['created_directories'] = $this->created_directories;
                        $status['connection_attempts']++;
                        return $status;
                    }
                }
            }
        }
        
        // Then process files up to file_batch_size
        while (!$file_queue->isEmpty() && $file_ops_count < $file_batch_size && $total_batch_operations < $this->batch_size) {
            $file = $file_queue->dequeue();
            $status['current_item'] = $file['relative_path'];
            
            // Ensure parent directory exists before copying file
            $parent_dir = dirname($file['relative_path']);
            if ($parent_dir !== '.' && $parent_dir !== '/' && !isset($verified_parents[$parent_dir]) && !isset($this->created_directories[$parent_dir])) {
                // Add this file back to the queue and process its parent first
                $file_queue->enqueue($file);
                
                // Create parent directory
                $parent_result = $this->create_directory($parent_dir);
                if (!is_wp_error($parent_result)) {
                    $verified_parents[$parent_dir] = true;
                    $this->created_directories[$parent_dir] = true;
                    $status['dirs_processed']++;
                } else {
                    // Failed to create parent, add to retry queue
                    $retry_items[] = [
                        'path' => $parent_dir,
                        'type' => 'dir',
                        'retry_count' => 1,
                        'item' => ['relative_path' => $parent_dir]
                    ];
                }
                
                $file_ops_count++;
                $total_batch_operations++;
                continue;
            }
            
            // Copy file
            $result = $this->copy_file($file['path'], $file['relative_path']);
            
            if (is_wp_error($result)) {
                $this->log_message('Failed to copy file: ' . $file['relative_path'] . ' - ' . $result->get_error_message());
                
                // Add to retry list
                $retry_items[] = [
                    'path' => $file['relative_path'],
                    'type' => 'file',
                    'retry_count' => 1,
                    'item' => $file
                ];
            } else {
                $batch_size_processed += $file['size'];
                $status['files_processed_size'] += $file['size'];
                $status['actual_files_processed']++;
            }
            
            $status['files_processed']++;
            $file_ops_count++;
            $total_batch_operations++;
            
            // Check connection health periodically
            if ($file_ops_count % 10 === 0) {
                if (!$this->check_connection_health($status)) {
                    $this->log_message('Connection lost during file operations, reconnecting...');
                    $reconnect_result = $this->reconnect($status);
                    if (is_wp_error($reconnect_result)) {
                        // If reconnection fails, store current state and return
                        $status['retry_items'] = $retry_items;
                        $status['dir_queue'] = serialize($dir_queue);
                        $status['file_queue'] = serialize($file_queue);
                        $status['path_cache'] = $verified_parents;
                        $status['created_directories'] = $this->created_directories;
                        $status['connection_attempts']++;
                        return $status;
                    }
                }
            }
            
            // Check memory usage periodically and break if getting high
            if ($file_ops_count % 10 === 0) {
                $memory_usage = memory_get_usage(true);
                $memory_limit = $this->get_memory_limit();
                
                // If we're using more than 80% of available memory, stop this batch
                if ($memory_usage > ($memory_limit * 0.8)) {
                    $this->log_message('Memory usage high (' . size_format($memory_usage, 2) . ' of ' . 
                        size_format($memory_limit, 2) . '), breaking batch');
                    break;
                }
            }
        }
        
        // Update status
        $status['retry_items'] = $retry_items;
        $status['dir_queue'] = serialize($dir_queue);
        $status['file_queue'] = serialize($file_queue);
        $status['path_cache'] = $verified_parents;
        $status['created_directories'] = $this->created_directories;
        $status['processed_size'] = $status['db_processed_size'] + $status['files_processed_size'];
        
        // Update progress
        $this->update_status_progress($status, $batch_size_processed, $batch_start_time);
        
        // Check if all files are processed
        if ($dir_queue->isEmpty() && $file_queue->isEmpty() && empty($retry_items)) {
            $status['phase'] = 'cleanup';
            $status['message'] = __('Finalizing restore...', 'swiftspeed-siberian');
            
            // Close connection
            $this->close_connection();
        }
        
        return $status;
    }
    
    /**
     * Update status progress and metrics
     * 
     * @param array &$status Current restore status (by reference)
     * @param int $batch_size_processed Size of processed data in this batch
     * @param float $batch_start_time Start time of this batch
     * @return void
     */
    private function update_status_progress(&$status, $batch_size_processed, $batch_start_time) {
        // Calculate progress
        if ($status['files_size'] > 0 && $status['files_total'] > 0) {
            $size_progress = ($status['files_processed_size'] / $status['files_size']) * 100;
            $count_progress = ($status['files_processed'] / $status['files_total']) * 100;
            $file_progress = max($size_progress, $count_progress);
            
            if ($status['has_db']) {
                $db_progress = ($status['tables_processed'] / max(1, $status['tables_total'])) * 50;
                $status['progress'] = $db_progress + ($file_progress * 0.5);
            } else {
                $status['progress'] = $file_progress;
            }
        }
        
        // Update performance metrics
        $batch_duration = microtime(true) - $batch_start_time;
        if ($batch_duration > 0 && $batch_size_processed > 0) {
            $current_speed = $batch_size_processed / $batch_duration;
            
            // Use weighted average for speed
            if (isset($status['bytes_per_second']) && $status['bytes_per_second'] > 0) {
                $status['bytes_per_second'] = ($status['bytes_per_second'] * 0.7) + ($current_speed * 0.3);
            } else {
                $status['bytes_per_second'] = $current_speed;
            }
            
            // Update file specific speed
            $status['file_speed'] = $current_speed;
        }
        
        // Update batch metrics
        $status['batch_metrics']['last_batch_time'] = $batch_duration;
        $status['batch_metrics']['last_batch_size'] = $batch_size_processed;
        $status['batch_metrics']['last_memory_usage'] = memory_get_usage(true);
        
        // Update status message with more detailed progress
        $message = sprintf(
            __('Restoring: %d of %d total items | %d of %d dirs | %d of %d files | %.1f%% complete', 'swiftspeed-siberian'),
            $status['files_processed'],
            $status['files_total'],
            $status['dirs_processed'],
            $status['dirs_total'],
            $status['actual_files_processed'],
            $status['files_count'],
            $status['progress']
        );
        
        if (isset($status['bytes_per_second']) && $status['bytes_per_second'] > 0) {
            $message .= ' | ' . size_format($status['bytes_per_second'], 2) . '/s';
        }
        
        $status['message'] = $message;
    }
    
    /**
     * Check if the current connection is still healthy
     * 
     * @param array $status Current restore status
     * @return bool True if connection is healthy
     */
    private function check_connection_health($status) {
        switch ($this->connection_type) {
            case 'ftp':
                // For FTP, check if connection is still open and working
                if (!$this->connection) {
                    return false;
                }
                
                // Try a basic command to test the connection
                $pwd = @ftp_pwd($this->connection);
                if ($pwd === false) {
                    $this->log_message('FTP connection lost - pwd failed');
                    return false;
                }
                
                // Connection still working
                return true;
                
            case 'sftp':
                // For SSH2 SFTP
                if ($this->ssh2_connection && $this->ssh2_sftp) {
                    try {
                        // Try to get directory listing of '/' as a health check
                        $result = @ssh2_sftp_stat($this->ssh2_sftp, '/');
                        return ($result !== false);
                    } catch (Exception $e) {
                        return false;
                    }
                }
                // For phpseclib SFTP
                elseif ($this->sftp_connection) {
                    try {
                        // Use pwd() as a health check
                        return ($this->sftp_connection->pwd() !== false);
                    } catch (Exception $e) {
                        return false;
                    }
                }
                return false;
                
            case 'local':
                // Local connections are always healthy
                return true;
                
            default:
                return false;
        }
    }
    
    /**
     * Reconnect to the server if connection is lost
     * 
     * @param array $status Current restore status
     * @return bool|WP_Error True on success or error
     */
    private function reconnect($status) {
        $this->log_message('Attempting to reconnect...');
        
        // Close any existing connections
        $this->close_connection();
        
        // Increment connection attempts
        $status['connection_attempts'] = isset($status['connection_attempts']) ? $status['connection_attempts'] + 1 : 1;
        
        // If we've tried too many times, fail
        if ($status['connection_attempts'] > 5) {
            return new WP_Error('connection_error', __('Too many connection attempts, giving up', 'swiftspeed-siberian'));
        }
        
        // Reinitialize connection with the same parameters
        $connection_method = $status['connection_method'];
        $connection_config = $status['connection_config'];
        
        // Wait before reconnecting to avoid hammering the server
        sleep(2);
        
        $result = $this->initialize_connection($connection_method, $connection_config);
        if (is_wp_error($result)) {
            $this->log_message('Reconnection failed: ' . $result->get_error_message());
            return $result;
        }
        
        // Reset connection attempts if successful
        $status['last_connection_status'] = true;
        $this->log_message('Reconnection successful');
        return true;
    }
    
    /**
     * Create a directory using the appropriate connection method
     * 
     * @param string $directory Relative directory path
     * @return bool|WP_Error True on success or error
     */
    private function create_directory($directory) {
        // Normalize path
        $directory = trim($directory, '/');
        
        if (empty($directory)) {
            return true; // Root directory
        }
        
        // Check if already created
        if (isset($this->created_directories[$directory]) && $this->created_directories[$directory]) {
            return true;
        }
        
        switch ($this->connection_type) {
            case 'ftp':
                return $this->create_directory_ftp($directory);
                
            case 'sftp':
                return $this->create_directory_sftp($directory);
                
            case 'local':
                return $this->create_directory_local($directory);
                
            default:
                return new WP_Error('unknown_connection', __('Unknown connection type', 'swiftspeed-siberian'));
        }
    }
    
    /**
     * Create directory on FTP server with enhanced robustness
     * 
     * @param string $directory Relative directory path
     * @return bool|WP_Error True on success or error
     */
    private function create_directory_ftp($directory) {
        if (!$this->connection) {
            return new WP_Error('ftp_error', __('FTP connection not established', 'swiftspeed-siberian'));
        }
        
        // Normalize directory path
        $directory = trim($directory, '/');
        
        if (empty($directory)) {
            return true; // Root directory
        }
        
        // Check if directory already exists in our tracking
        if (isset($this->created_directories[$directory]) && $this->created_directories[$directory]) {
            return true;
        }
        
        // Save current directory so we can return to it
        $original_dir = @ftp_pwd($this->connection);
        if ($original_dir === false) {
            // Try to reconnect if we can't get the current directory
            $this->log_message('FTP connection issue - cannot get current directory, attempting to fix');
            if (!$this->at_root_dir) {
                if (@ftp_chdir($this->connection, '/')) {
                    $this->at_root_dir = true;
                    $this->current_ftp_dir = '/';
                    $original_dir = '/';
                }
            }
        } else {
            $this->current_ftp_dir = $original_dir;
            $this->at_root_dir = ($original_dir === '/');
        }
        
        // Get absolute target path
        $target_path = ltrim($this->base_path . '/' . $directory, '/');
        
        // First try direct check if directory exists by attempting to change to it
        $full_path = '/' . $target_path;
        
        $dir_exists = false;
        
        // Approach 1: Check if directory exists by trying to change to it
        if (@ftp_chdir($this->connection, $full_path)) {
            $dir_exists = true;
            
            // Return to original directory
            if ($original_dir !== false) {
                @ftp_chdir($this->connection, $original_dir);
            } else {
                // Try to go back to root at least
                @ftp_chdir($this->connection, '/');
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
            }
            
            // Directory already exists, mark it as created
            $this->created_directories[$directory] = true;
            return true;
        }
        
        // Directory doesn't exist, need to create it
        $this->log_message('Creating FTP directory: ' . $target_path);
        
        // Multiple directory creation strategies
        $success = false;
        $errors = [];
        
        // Approach 1: Create from root with absolute path
        if (!$success) {
            // First go to root
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                // Try to create the full path
                if (@ftp_mkdir($this->connection, $target_path)) {
                    $success = true;
                } else {
                    $errors[] = 'Failed to create directory from root';
                }
            }
        }
        
        // Approach 2: Try to create directory incrementally
        if (!$success) {
            // Go to base path first if it exists
            $base_exists = false;
            
            // First go to root
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                // Try to change to base path
                if (!empty($this->base_path)) {
                    if (@ftp_chdir($this->connection, $this->base_path)) {
                        $base_exists = true;
                        $this->at_root_dir = false;
                        $this->current_ftp_dir = '/' . ltrim($this->base_path, '/');
                    }
                } else {
                    $base_exists = true; // No base path means we're already at the right place
                }
            }
            
            if ($base_exists) {
                // Create directory components one by one
                $path_parts = explode('/', $directory);
                $current_path = '';
                
                foreach ($path_parts as $part) {
                    if (empty($part)) continue;
                    
                    $current_path = $current_path ? $current_path . '/' . $part : $part;
                    
                    // Check if this part already exists
                    if (@ftp_chdir($this->connection, $part)) {
                        // Directory exists, continue to next component
                        $this->current_ftp_dir .= '/' . $part;
                        continue;
                    }
                    
                    // Try to create this directory component
                    if (@ftp_mkdir($this->connection, $part)) {
                        // Success - change into the new directory
                        if (@ftp_chdir($this->connection, $part)) {
                            $this->current_ftp_dir .= '/' . $part;
                        } else {
                            $errors[] = 'Created directory but could not enter it: ' . $part;
                            // Try to restore original position
                            if ($original_dir !== false) {
                                @ftp_chdir($this->connection, $original_dir);
                            } else {
                                @ftp_chdir($this->connection, '/');
                                $this->at_root_dir = true;
                                $this->current_ftp_dir = '/';
                            }
                            return new WP_Error('ftp_mkdir', 'Failed to enter created directory: ' . $part);
                        }
                    } else {
                        $errors[] = 'Failed to create directory component: ' . $part;
                        // Try to restore original position
                        if ($original_dir !== false) {
                            @ftp_chdir($this->connection, $original_dir);
                        } else {
                            @ftp_chdir($this->connection, '/');
                            $this->at_root_dir = true;
                            $this->current_ftp_dir = '/';
                        }
                        return new WP_Error('ftp_mkdir', 'Failed to create directory component: ' . $part);
                    }
                }
                
                // If we get here, we've successfully created all directory components
                $success = true;
            }
        }
        
        // Approach 3: Try using mlsd if available to get more reliable directory info
        if (!$success && function_exists('ftp_mlsd')) {
            // First go to root
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                // Get all the parent directories
                $path_parts = explode('/', $target_path);
                $current_path = '';
                
                foreach ($path_parts as $index => $part) {
                    if (empty($part)) continue;
                    
                    $current_path .= '/' . $part;
                    
                    // Check if this is the final component (the one we want to create)
                    if ($index === count($path_parts) - 1) {
                        // Last component - create it directly
                        if (@ftp_mkdir($this->connection, $current_path)) {
                            $success = true;
                            break;
                        } else {
                            $errors[] = 'Failed to create final directory';
                        }
                    } else {
                        // This is a parent directory - make sure it exists
                        if (!@ftp_chdir($this->connection, $current_path)) {
                            // Try to create this parent
                            if (@ftp_mkdir($this->connection, $current_path)) {
                                $this->log_message('Created parent directory: ' . $current_path);
                            } else {
                                $errors[] = 'Failed to create parent: ' . $current_path;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        // Return to original directory
        if ($original_dir !== false) {
            @ftp_chdir($this->connection, $original_dir);
        } else {
            @ftp_chdir($this->connection, '/');
            $this->at_root_dir = true;
            $this->current_ftp_dir = '/';
        }
        
        // Final check - see if the directory now exists
        if (!$success) {
            // Go to root
            if (@ftp_chdir($this->connection, '/')) {
                // Now try to change to the target directory
                if (@ftp_chdir($this->connection, $target_path)) {
                    $success = true;
                    
                    // Return to original directory
                    if ($original_dir !== false) {
                        @ftp_chdir($this->connection, $original_dir);
                    } else {
                        @ftp_chdir($this->connection, '/');
                        $this->at_root_dir = true;
                        $this->current_ftp_dir = '/';
                    }
                }
            }
        }
        
        if ($success) {
            // Update our tracking of created directories
            $this->created_directories[$directory] = true;
            return true;
        }
        
        return new WP_Error('ftp_mkdir', 'Failed to create directory: ' . $directory . ' - ' . implode(', ', $errors));
    }
    
    /**
     * Create directory using SFTP with comprehensive approach
     * 
     * @param string $directory Relative directory path
     * @return bool|WP_Error True on success or error
     */
    private function create_directory_sftp($directory) {
        // Normalize directory path
        $directory = trim($directory, '/');
        
        if (empty($directory)) {
            return true; // Root directory
        }
        
        // Make full path
        $full_path = rtrim($this->base_path, '/') . '/' . $directory;
        
        // Try different approaches to create the directory
        $created = false;
        $errors = [];
        
        // Approach 1: SSH2 extension
        if ($this->ssh2_connection && $this->ssh2_sftp) {
            try {
                // Check if directory already exists
                if (@is_dir("ssh2.sftp://{$this->ssh2_sftp}{$full_path}")) {
                    return true;
                }
                
                // Try to create directory recursively
                $result = $this->mkdir_recursive_ssh2($this->ssh2_sftp, $full_path);
                if ($result) {
                    $created = true;
                } else {
                    $errors[] = 'SSH2 mkdir_recursive failed';
                    
                    // Try direct mkdir as fallback
                    if (@ssh2_sftp_mkdir($this->ssh2_sftp, $full_path, 0755, true)) {
                        $created = true;
                    } else {
                        $errors[] = 'SSH2 direct mkdir failed';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'SSH2 exception: ' . $e->getMessage();
            }
        }
        
        // Approach 2: phpseclib
        if (!$created && $this->sftp_connection) {
            try {
                // Check if directory already exists
                if ($this->sftp_connection->is_dir($full_path)) {
                    return true;
                }
                
                // Try to create directory with recursive option
                if ($this->sftp_connection->mkdir($full_path, 0755, true)) {
                    $created = true;
                } else {
                    $errors[] = 'phpseclib mkdir failed';
                    
                    // Try creating parent directories one by one
                    $parts = explode('/', trim($full_path, '/'));
                    $path = '';
                    
                    foreach ($parts as $part) {
                        $path .= '/' . $part;
                        
                        if (!$this->sftp_connection->is_dir($path)) {
                            $this->sftp_connection->mkdir($path);
                        }
                    }
                    
                    // Check if directory exists now
                    if ($this->sftp_connection->is_dir($full_path)) {
                        $created = true;
                    } else {
                        $errors[] = 'phpseclib incremental mkdir failed';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'phpseclib exception: ' . $e->getMessage();
            }
        }
        
        // Approach 3: Execute shell mkdir command if available
        if (!$created && $this->ssh2_connection) {
            try {
                $cmd = 'mkdir -p ' . escapeshellarg($full_path);
                $stream = @ssh2_exec($this->ssh2_connection, $cmd);
                if ($stream) {
                    stream_set_blocking($stream, true);
                    $output = stream_get_contents($stream);
                    fclose($stream);
                    
                    // Check if the directory exists after command
                    if ($this->ssh2_sftp && @is_dir("ssh2.sftp://{$this->ssh2_sftp}{$full_path}")) {
                        $created = true;
                    } else {
                        $errors[] = 'SSH2 exec mkdir failed';
                    }
                } else {
                    $errors[] = 'SSH2 exec failed';
                }
            } catch (Exception $e) {
                $errors[] = 'SSH2 exec exception: ' . $e->getMessage();
            }
        }
        
        if ($created) {
            // Update tracking of created directories
            $this->created_directories[$directory] = true;
            return true;
        }
        
        // All attempts failed
        return new WP_Error(
            'sftp_mkdir',
            sprintf(__('Failed to create SFTP directory: %s - %s', 'swiftspeed-siberian'), $directory, implode(', ', $errors))
        );
    }
    
    /**
     * Recursive mkdir for SSH2 SFTP
     * 
     * @param resource $sftp SSH2 SFTP resource
     * @param string $dir Directory path
     * @return bool True on success, false on failure
     */
    private function mkdir_recursive_ssh2($sftp, $dir) {
        $parts = explode('/', $dir);
        $path = '';
        
        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }
            
            $path .= '/' . $part;
            
            if (!@is_dir("ssh2.sftp://$sftp" . $path)) {
                if (!@ssh2_sftp_mkdir($sftp, $path, 0755)) {
                    // Try with 0777 as fallback
                    if (!@ssh2_sftp_mkdir($sftp, $path, 0777)) {
                        return false;
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Create directory on local filesystem
     * 
     * @param string $directory Relative directory path
     * @return bool|WP_Error True on success or error
     */
    private function create_directory_local($directory) {
        // Normalize path
        $directory = trim($directory, '/');
        
        if (empty($directory)) {
            return true; // Root directory
        }
        
        // Make absolute path
        $full_path = rtrim($this->base_path, '/') . '/' . $directory;
        
        if (file_exists($full_path) && is_dir($full_path)) {
            // Directory already exists
            $this->created_directories[$directory] = true;
            return true;
        }
        
        if (wp_mkdir_p($full_path)) {
            // Mark as created
            $this->created_directories[$directory] = true;
            return true;
        }
        
        return new WP_Error('local_mkdir', sprintf(__('Failed to create local directory: %s', 'swiftspeed-siberian'), $full_path));
    }
    
    /**
     * Copy file using the appropriate connection method
     * 
     * @param string $source_path Source file path
     * @param string $target_path Target relative path
     * @return bool|WP_Error True on success or error
     */
    private function copy_file($source_path, $target_path) {
        // Ensure source file exists
        if (!file_exists($source_path)) {
            return new WP_Error('source_missing', __('Source file does not exist', 'swiftspeed-siberian'));
        }
        
        switch ($this->connection_type) {
            case 'ftp':
                return $this->copy_file_ftp($source_path, $target_path);
                
            case 'sftp':
                return $this->copy_file_sftp($source_path, $target_path);
                
            case 'local':
                return $this->copy_file_local($source_path, $target_path);
                
            default:
                return new WP_Error('unknown_connection', __('Unknown connection type', 'swiftspeed-siberian'));
        }
    }
    
    /**
     * Copy file to FTP server with enhanced reliability
     * 
     * @param string $source_path Source file path
     * @param string $target_path Target relative path
     * @return bool|WP_Error True on success or error
     */
    private function copy_file_ftp($source_path, $target_path) {
        if (!$this->connection) {
            return new WP_Error('ftp_error', __('FTP connection not established', 'swiftspeed-siberian'));
        }
        
        // Make full target path
        $full_target_path = ltrim($this->base_path . '/' . $target_path, '/');
        
        // Save current directory so we can return to it
        $original_dir = @ftp_pwd($this->connection);
        if ($original_dir === false) {
            // Try to reconnect if we can't get the current directory
            $this->log_message('FTP connection issue - cannot get current directory before file upload');
            if (!$this->at_root_dir) {
                if (@ftp_chdir($this->connection, '/')) {
                    $this->at_root_dir = true;
                    $this->current_ftp_dir = '/';
                    $original_dir = '/';
                }
            }
        } else {
            $this->current_ftp_dir = $original_dir;
            $this->at_root_dir = ($original_dir === '/');
        }
        
        // Ensure parent directory exists
        $parent_dir = dirname($target_path);
        if ($parent_dir !== '.' && $parent_dir !== '/' && !isset($this->created_directories[$parent_dir])) {
            $parent_result = $this->create_directory($parent_dir);
            if (is_wp_error($parent_result)) {
                return $parent_result;
            }
            $this->created_directories[$parent_dir] = true;
        }
        
        // Get file size for different upload strategies
        $file_size = filesize($source_path);
        
        // Prepare for upload
        $upload_success = false;
        $errors = [];
        
        // First, try to ensure we're in the right directory for upload
        $parent_path = rtrim($this->base_path, '/') . '/' . ltrim(dirname($target_path), '/');
        $filename = basename($target_path);
        
        // Try different upload approaches
        
        // Approach 1: Change to parent directory and upload with relative path
        if (!$upload_success) {
            // Try to go to parent directory first
            $parent_cd_success = false;
            
            // First go to root
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                // Then try parent dir
                if ($parent_path === '/' || @ftp_chdir($this->connection, $parent_path)) {
                    $parent_cd_success = true;
                    $this->current_ftp_dir = $parent_path;
                    $this->at_root_dir = false;
                    
                    // Upload file with just the filename
                    $this->log_message('Uploading file to ' . $parent_path . '/' . $filename);
                    
                    if ($file_size < 1024 * 1024) { // Less than 1MB
                        // Use standard upload for small files
                        $upload_success = @ftp_put($this->connection, $filename, $source_path, FTP_BINARY);
                    } else {
                        // Use chunked upload for larger files
                        $chunked_result = $this->chunked_ftp_upload($source_path, $filename);
                        $upload_success = !is_wp_error($chunked_result);
                    }
                    
                    if (!$upload_success) {
                        $errors[] = 'Failed to upload after changing to parent dir';
                    }
                } else {
                    $errors[] = 'Failed to change to parent directory: ' . $parent_path;
                }
            }
        }
        
        // Approach 2: Use absolute path for upload
        if (!$upload_success) {
            // Go back to root directory
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                $this->log_message('Trying upload with absolute path: ' . $full_target_path);
                
                if ($file_size < 1024 * 1024) { // Less than 1MB
                    // Use standard upload for small files
                    $upload_success = @ftp_put($this->connection, $full_target_path, $source_path, FTP_BINARY);
                } else {
                    // Use chunked upload for larger files
                    $chunked_result = $this->chunked_ftp_upload($source_path, $full_target_path);
                    $upload_success = !is_wp_error($chunked_result);
                }
                
                if (!$upload_success) {
                    $errors[] = 'Failed to upload with absolute path';
                }
            }
        }
        
        // Approach 3: Try alternative FTP command sequence
        if (!$upload_success) {
            // Go back to root directory
            if (@ftp_chdir($this->connection, '/')) {
                $this->at_root_dir = true;
                $this->current_ftp_dir = '/';
                
                // Use direct FTP commands
                $this->log_message('Trying alternative FTP command sequence for upload');
                
                // Create a temporary file handle
                $temp_handle = fopen('php://temp', 'r+');
                
                if ($temp_handle) {
                    // Try to upload an empty file first to create the file
                    $empty_upload = @ftp_fput($this->connection, $full_target_path, $temp_handle, FTP_BINARY);
                    fclose($temp_handle);
                    
                    if ($empty_upload) {
                        // Now try to append content
                        $source_handle = fopen($source_path, 'r');
                        if ($source_handle) {
                            // Use STOR command directly
                            $upload_success = @ftp_put($this->connection, $full_target_path, $source_path, FTP_BINARY);
                            fclose($source_handle);
                            
                            if (!$upload_success) {
                                $errors[] = 'Failed to upload after creating empty file';
                            }
                        } else {
                            $errors[] = 'Could not open source file for reading';
                        }
                    } else {
                        $errors[] = 'Failed to create empty file';
                    }
                } else {
                    $errors[] = 'Could not create temporary file handle';
                }
            }
        }
        
        // Return to original directory if possible
        if ($original_dir !== false) {
            @ftp_chdir($this->connection, $original_dir);
            $this->current_ftp_dir = $original_dir;
            $this->at_root_dir = ($original_dir === '/');
        } else {
            // Go back to root
            @ftp_chdir($this->connection, '/');
            $this->at_root_dir = true;
            $this->current_ftp_dir = '/';
        }
        
        if ($upload_success) {
            return true;
        }
        
        return new WP_Error('ftp_upload', sprintf(
            __('Failed to upload file: %s - Errors: %s', 'swiftspeed-siberian'),
            $target_path,
            implode(', ', $errors)
        ));
    }
    
    /**
     * Upload a file to FTP server in chunks to avoid memory issues
     *
     * @param string $source_path Source file path
     * @param string $target_path Target relative path
     * @return bool|WP_Error True on success or error
     */
    private function chunked_ftp_upload($source_path, $target_path) {
        // Open the source file
        $fp = @fopen($source_path, 'rb');
        if (!$fp) {
            return new WP_Error('file_open', __('Could not open source file for reading', 'swiftspeed-siberian'));
        }
        
        // Create a temporary file for the chunk
        $temp_file = tempnam(sys_get_temp_dir(), 'ftp_chunk');
        $chunk_size = 512 * 1024; // 512KB chunks
        $chunk_num = 0;
        $error = null;
        
        // Try different approaches for chunked upload
        
        // Approach 1: Create empty file and use REST for position
        try {
            // Start with an empty file
            $empty_temp = fopen('php://temp', 'r');
            @ftp_fput($this->connection, $target_path, $empty_temp, FTP_BINARY);
            fclose($empty_temp);
            
            // Get initial position
            $position = 0;
            
            while (!feof($fp)) {
                // Read a chunk
                $chunk = fread($fp, $chunk_size);
                $chunk_len = strlen($chunk);
                
                // Write chunk to temporary file
                file_put_contents($temp_file, $chunk);
                
                // Upload the chunk using FTP REST command
                $handle = fopen($temp_file, 'rb');
                
                // Set the REST position for resuming
                $rest_result = @ftp_raw($this->connection, "REST $position");
                if (!$rest_result || substr($rest_result[0], 0, 3) !== '350') {
                    $error = new WP_Error('ftp_rest', 'Failed to set REST position for chunked upload');
                    break;
                }
                
                // Store the chunk
                $store_result = @ftp_fput($this->connection, $target_path, $handle, FTP_BINARY);
                fclose($handle);
                
                if (!$store_result) {
                    $error = new WP_Error('ftp_store', 'Failed to store chunk ' . $chunk_num);
                    break;
                }
                
                // Update position
                $position += $chunk_len;
                $chunk_num++;
            }
        } catch (Exception $e) {
            $error = new WP_Error('ftp_chunk', 'Exception during chunked upload: ' . $e->getMessage());
        }
        
        // Clean up
        fclose($fp);
        @unlink($temp_file);
        
        if ($error) {
            // If approach 1 failed, try approach 2: Standard put
            $this->log_message('Chunked upload failed, trying standard upload');
            
            if (@ftp_put($this->connection, $target_path, $source_path, FTP_BINARY)) {
                return true;
            }
            
            return $error;
        }
        
        return true;
    }
    
    /**
     * Copy file to SFTP server with comprehensive approach
     * 
     * @param string $source_path Source file path
     * @param string $target_path Target relative path
     * @return bool|WP_Error True on success or error
     */
    private function copy_file_sftp($source_path, $target_path) {
        // Make full path
        $full_path = rtrim($this->base_path, '/') . '/' . $target_path;
        
        // Try different approaches to upload the file
        $uploaded = false;
        $errors = [];
        
        // Approach 1: SSH2 extension
        if ($this->ssh2_connection && $this->ssh2_sftp && !$uploaded) {
            try {
                // Ensure target directory exists
                $target_dir = dirname($full_path);
                $this->mkdir_recursive_ssh2($this->ssh2_sftp, $target_dir);
                
                // Try streaming approach first for better memory usage
                $stream = @fopen("ssh2.sftp://{$this->ssh2_sftp}{$full_path}", 'w');
                
                if ($stream) {
                    $source_stream = @fopen($source_path, 'r');
                    if ($source_stream) {
                        // Stream in chunks
                        $bytes_copied = stream_copy_to_stream($source_stream, $stream);
                        fclose($source_stream);
                        fclose($stream);
                        
                        if ($bytes_copied > 0) {
                            $uploaded = true;
                        } else {
                            $errors[] = 'SSH2 stream copy returned 0 bytes';
                        }
                    } else {
                        fclose($stream);
                        $errors[] = 'Failed to open source file for streaming';
                    }
                } else {
                    $errors[] = 'Failed to open SFTP stream for writing';
                    
                    // Try alternative approach with file_get_contents
                    $data = @file_get_contents($source_path);
                    
                    if ($data !== false) {
                        // Use SSH2 SFTP file_put_contents wrapper
                        $result = @file_put_contents("ssh2.sftp://{$this->ssh2_sftp}{$full_path}", $data);
                        
                        if ($result !== false) {
                            $uploaded = true;
                        } else {
                            $errors[] = 'SSH2 file_put_contents failed';
                        }
                    } else {
                        $errors[] = 'Failed to read source file';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'SSH2 exception: ' . $e->getMessage();
            }
        }
        
        // Approach 2: phpseclib
        if ($this->sftp_connection && !$uploaded) {
            try {
                // Ensure target directory exists
                $target_dir = dirname($full_path);
                $this->sftp_connection->mkdir($target_dir, 0755, true);
                
                // Try to upload file with phpseclib
                $file_size = filesize($source_path);
                
                // Use different approaches based on file size
                if ($file_size > 5 * 1024 * 1024) { // Greater than 5MB
                    // For large files, use chunked upload to reduce memory usage
                    $result = $this->chunked_sftp_upload($source_path, $full_path);
                    
                    if ($result === true) {
                        $uploaded = true;
                    } else {
                        $errors[] = 'phpseclib chunked upload failed: ' . $result;
                    }
                } else {
                    // For smaller files, use standard upload
                    if ($this->sftp_connection->put($full_path, $source_path, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
                        $uploaded = true;
                    } else {
                        $errors[] = 'phpseclib standard upload failed';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'phpseclib exception: ' . $e->getMessage();
            }
        }
        
        if ($uploaded) {
            return true;
        }
        
        // All attempts failed
        return new WP_Error(
            'sftp_upload',
            sprintf(__('Failed to upload file: %s - %s', 'swiftspeed-siberian'), $target_path, implode(', ', $errors))
        );
    }
    
    /**
     * Upload a file to SFTP server in chunks
     *
     * @param string $source_path Source file path
     * @param string $remote_path Remote file path
     * @return bool|string True on success or error message
     */
    private function chunked_sftp_upload($source_path, $remote_path) {
        $fp = @fopen($source_path, 'rb');
        if (!$fp) {
            return 'Could not open source file';
        }
        
        $chunk_size = 1024 * 1024; // 1MB chunks
        $offset = 0;
        $error = null;
        
        try {
            // Initialize with empty file
            $this->sftp_connection->put($remote_path, '');
            
            // Upload in chunks
            while (!feof($fp)) {
                $data = fread($fp, $chunk_size);
                
                if ($data === false) {
                    $error = 'Failed to read chunk from source file';
                    break;
                }
                
                // Append chunk to remote file
                if (!$this->sftp_connection->put($remote_path, $data, \phpseclib3\Net\SFTP::RESUME)) {
                    $error = 'Failed to write chunk to remote file';
                    break;
                }
                
                $offset += strlen($data);
            }
        } catch (Exception $e) {
            $error = 'Exception during chunked upload: ' . $e->getMessage();
        }
        
        fclose($fp);
        
        if ($error) {
            return $error;
        }
        
        return true;
    }
    
    /**
     * Copy file to local filesystem
     * 
     * @param string $source_path Source file path
     * @param string $target_path Target relative path
     * @return bool|WP_Error True on success or error
     */
    private function copy_file_local($source_path, $target_path) {
        // Make absolute path
        $full_path = rtrim($this->base_path, '/') . '/' . $target_path;
        
        // Ensure directory exists
        $dir = dirname($full_path);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return new WP_Error('local_mkdir', sprintf(__('Failed to create directory: %s', 'swiftspeed-siberian'), $dir));
            }
        }
        
        // Copy file
        if (copy($source_path, $full_path)) {
            return true;
        }
        
        // If standard copy fails, try streaming copy
        $source = @fopen($source_path, 'rb');
        $dest = @fopen($full_path, 'wb');
        
        if (!$source || !$dest) {
            if ($source) @fclose($source);
            if ($dest) @fclose($dest);
            return new WP_Error('local_open', __('Failed to open files for streaming copy', 'swiftspeed-siberian'));
        }
        
        // Copy in chunks
        $bytes_copied = stream_copy_to_stream($source, $dest);
        
        @fclose($source);
        @fclose($dest);
        
        if ($bytes_copied) {
            return true;
        }
        
        return new WP_Error('local_copy', sprintf(__('Failed to copy file: %s', 'swiftspeed-siberian'), $full_path));
    }
    
    /**
     * Get the available memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        // Convert to bytes
        $unit = strtoupper(substr($memory_limit, -1));
        $value = intval(substr($memory_limit, 0, -1));
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
                // Fall through
            case 'M':
                $value *= 1024;
                // Fall through
            case 'K':
                $value *= 1024;
                break;
        }
        
        return $value;
    }
    
    /**
     * Close connection
     * 
     * @return void
     */
    private function close_connection() {
        switch ($this->connection_type) {
            case 'ftp':
                if ($this->connection) {
                    @ftp_close($this->connection);
                    $this->connection = null;
                }
                break;
                
            case 'sftp':
                // Close SSH2 connection
                if ($this->ssh2_connection) {
                    @ssh2_disconnect($this->ssh2_connection);
                    $this->ssh2_connection = null;
                    $this->ssh2_sftp = null;
                }
                
                // phpseclib connections are automatically closed
                $this->sftp_connection = null;
                break;
                
            case 'local':
                // Local connections don't need closing
                break;
        }
    }
    
    /**
     * Write to log.
     * 
     * @param string $message Message to log
     * @return void
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'restore', $message);
        }
    }
    
    /**
     * Destructor to ensure connection is closed.
     */
    public function __destruct() {
        $this->close_connection();
    }
}