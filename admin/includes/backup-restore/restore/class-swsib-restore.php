<?php
/**
 * Core restore functionality for Siberian CMS backups.
 * Enhanced version with improved connection recovery and robust error handling
 */
class SwiftSpeed_Siberian_Restore {
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Base backup directory.
     * 
     * @var string
     */
    private $backup_dir;
    
    /**
     * Temporary directory.
     * 
     * @var string
     */
    private $temp_dir;
    
    /**
     * File restore handler.
     * 
     * @var SwiftSpeed_Siberian_File_Restore
     */
    private $file_restore;
    
    /**
     * Database restore handler.
     * 
     * @var SwiftSpeed_Siberian_Database_Restore
     */
    private $database_restore;
    
    /**
     * Start time for performance tracking
     * 
     * @var float
     */
    private $start_time;
    
    /**
     * Maximum number of processing steps from user settings
     * 
     * @var int
     */
    private $max_steps = 5;
    
    /**
     * Constructor.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->temp_dir = $this->backup_dir . 'temp/';
        $this->start_time = microtime(true);
        
        // Get max steps from settings (controls speed - 2-25)
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        $this->max_steps = max(2, min(25, $this->max_steps)); // Ensure it's within valid range
        
        // Ensure directories exist
        $this->ensure_directories();
        
        // Load component classes
        if (!class_exists('SwiftSpeed_Siberian_File_Restore')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/restore/class-swsib-file-restore.php';
        }
        
        if (!class_exists('SwiftSpeed_Siberian_Database_Restore')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/restore/class-swsib-database-restore.php';
        }
        
        $this->file_restore = new SwiftSpeed_Siberian_File_Restore();
        $this->database_restore = new SwiftSpeed_Siberian_Database_Restore();
    }
    
    /**
     * Write to log using the central logging manager.
     * 
     * @param string $message The message to log.
     * @return void
     */
     public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'restore', $message);
        }
    }
    
    /**
     * Ensure necessary directories exist.
     * 
     * @return void
     */
    private function ensure_directories() {
        $directories = [$this->backup_dir, $this->temp_dir];
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    $this->log_message('Failed to create directory: ' . $dir);
                }
            }
        }
    }
    
    /**
     * Start the restore process with integrity verification.
     * 
     * @param array $backup Backup information.
     * @return array|WP_Error Restore status or error.
     */
    public function start_restore($backup) {
        // Increase memory and execution time limits to match backup capabilities
        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);
        
        $this->log_message('Starting restore for backup: ' . $backup['id'] . ' | Type: ' . 
            (isset($backup['backup_type']) ? $backup['backup_type'] : 'unknown'));
        
        // Validate installation path and permissions before starting
        $installation_options = isset($this->options['installation']) ? $this->options['installation'] : [];
        
        if (empty($installation_options['is_configured'])) {
            $this->log_message('Installation connection is not configured');
            return new WP_Error('restore_error', __('Installation connection is not configured', 'swiftspeed-siberian'));
        }
        
        $connection_method = isset($installation_options['connection_method']) ? $installation_options['connection_method'] : 'ftp';
        $connection_config = isset($installation_options[$connection_method]) ? $installation_options[$connection_method] : [];
        
        // Test installation path accessibility
        $path_test = $this->test_installation_path($connection_method, $connection_config);
        if (is_wp_error($path_test)) {
            $this->log_message('Installation path test failed: ' . $path_test->get_error_message());
            return $path_test;
        }
        
        // Create restore ID
        $restore_id = 'restore-' . date('Y-m-d-H-i-s') . '-' . wp_generate_password(8, false);
        
        // Create a temporary directory for the restore
        $temp_dir = $this->temp_dir . $restore_id . '/';
        
        if (!file_exists($temp_dir) && !wp_mkdir_p($temp_dir)) {
            $this->log_message('Failed to create temporary directory: ' . $temp_dir);
            return new WP_Error('restore_error', __('Failed to create temporary directory', 'swiftspeed-siberian'));
        }
        
        // Check if we need to download from external storage
        $storage_type = isset($backup['storage']) ? $backup['storage'] : 'local';
        $backup_file = '';
        
        if ($storage_type === 'local') {
            $backup_file = isset($backup['path']) ? $backup['path'] : '';
            
            if (empty($backup_file) || !file_exists($backup_file)) {
                $this->log_message('Backup file not found: ' . $backup_file);
                return new WP_Error('restore_error', __('Backup file not found', 'swiftspeed-siberian'));
            }
        } else {
            // External storage, need to download
            $backup_file = $temp_dir . $backup['file'];
            $download_result = $this->download_from_storage($storage_type, $backup, $backup_file);
            
            if (is_wp_error($download_result)) {
                $this->log_message('Failed to download backup file: ' . $download_result->get_error_message());
                $this->cleanup_temp_files($temp_dir); // Clean up temp directory
                return $download_result;
            }
        }
        
        // Log backup file information
        $backup_size = file_exists($backup_file) ? filesize($backup_file) : 0;
        $this->log_message('Backup file ready: ' . $backup_file . ' (' . size_format($backup_size, 2) . ')');
        
        // Extract the backup to temp dir with progress reporting
        $extract_dir = $temp_dir . 'extracted/';
        
        if (!file_exists($extract_dir) && !wp_mkdir_p($extract_dir)) {
            $this->log_message('Failed to create extraction directory: ' . $extract_dir);
            $this->cleanup_temp_files($temp_dir);
            return new WP_Error('restore_error', __('Failed to create extraction directory', 'swiftspeed-siberian'));
        }
        
        // Create intermediate status for extraction reporting
        $extract_status = [
            'id' => $restore_id,
            'status' => 'extracting',
            'message' => __('Extracting backup files...', 'swiftspeed-siberian'),
            'progress' => 5,
        ];
        update_option('swsib_current_restore', $extract_status);
        
        // Extract the backup
        $extract_result = $this->extract_backup($backup_file, $extract_dir);
        
        if (is_wp_error($extract_result)) {
            $this->log_message('Failed to extract backup: ' . $extract_result->get_error_message());
            $this->cleanup_temp_files($temp_dir);
            
            $extract_status['status'] = 'error';
            $extract_status['message'] = $extract_result->get_error_message();
            update_option('swsib_current_restore', $extract_status);
            
            return $extract_result;
        }
        
        // Determine what's in the backup
        $has_db = file_exists($extract_dir . 'backup.sql') || file_exists($extract_dir . 'database/');
        $has_files = file_exists($extract_dir . 'files/');
        
        if (!$has_db && !$has_files) {
            $this->log_message('Backup contains neither database nor files');
            $this->cleanup_temp_files($temp_dir);
            return new WP_Error('restore_error', __('Backup contains neither database nor files', 'swiftspeed-siberian'));
        }
        
        // Get estimated sizes for progress tracking
        $db_size = 0;
        $files_size = 0;
        
        if ($has_db) {
            $db_source = file_exists($extract_dir . 'database/') ? 'database/' : '';
            $db_size = $this->get_directory_size($extract_dir . $db_source);
            $this->log_message('Database found: ' . size_format($db_size, 2));
        }
        
        if ($has_files) {
            $files_size = $this->get_directory_size($extract_dir . 'files/');
            $this->log_message('Files found: ' . size_format($files_size, 2));
        }
        
        // Load backup manifest for verification
        $manifest = $this->load_backup_manifest($extract_dir);
        
        if (is_wp_error($manifest)) {
            $this->log_message('Failed to load backup manifest: ' . $manifest->get_error_message());
            // Continue anyway, manifest is optional
        } elseif (is_array($manifest)) {
            if (isset($manifest['critical_errors']) && $manifest['critical_errors'] > 0) {
                $this->log_message('Backup contains critical errors: ' . $manifest['critical_errors']);
                $this->cleanup_temp_files($temp_dir);
                return new WP_Error('critical_errors', __('Backup contains critical errors and cannot be restored', 'swiftspeed-siberian'));
            }
        }
        
        // Calculate batch sizes based on user settings (like backup does)
        $file_batch_size = $this->calculate_file_batch_size();
        $db_batch_size = $this->calculate_db_batch_size();
        
        $this->log_message('Using batch sizes - Files: ' . $file_batch_size . ', DB: ' . $db_batch_size . 
            ' (from user setting: ' . $this->max_steps . ')');
        
        // Initialize restore status
        $status = [
            'id' => $restore_id,
            'backup_id' => $backup['id'],
            'temp_dir' => $temp_dir,
            'extract_dir' => $extract_dir,
            'backup_file' => $backup_file,
            'has_db' => $has_db,
            'has_files' => $has_files,
            'started' => time(),
            'start_time' => microtime(true),
            'status' => 'processing',
            'phase' => $has_db ? 'database' : ($has_files ? 'files' : 'cleanup'),
            'message' => $has_db ? __('Preparing to restore database...', 'swiftspeed-siberian') : 
                        ($has_files ? __('Preparing to restore files...', 'swiftspeed-siberian') : 
                        __('Finalizing restore...', 'swiftspeed-siberian')),
            'progress' => 10,
            'files_total' => 0,
            'files_processed' => 0,
            'files_processed_size' => 0,
            'current_file' => '',
            'tables_total' => 0,
            'tables_processed' => 0,
            'db_processed_size' => 0,
            'current_table' => '',
            'total_size' => $db_size + $files_size,
            'processed_size' => 0,
            'bytes_per_second' => 0,
            'time_elapsed' => 0,
            'batch_metrics' => [
                'last_batch_time' => 0,
                'last_batch_size' => 0,
                'last_batch_files' => 0,
                'optimal_time_per_batch' => 15, // Target 15 seconds per batch
                'consecutive_errors' => 0,
                'last_memory_usage' => 0
            ],
            'retry_files' => [],
            'failed_files' => [],
            'batch_size' => $file_batch_size,
            'db_batch_size' => $db_batch_size,
            'speed_history' => [],
            'errors' => [],
            'critical_errors' => [],
            'table_sizes' => [],
            'db_queue' => null,
            'file_queue' => null,
            'manifest' => $manifest,
            'integrity_check' => [],
            'estimated_total' => $db_size + $files_size,
            'db_rows_processed' => 0,
            'db_rows_total' => 0,
            'db_speed' => 0,
            'file_speed' => 0,
            'overwritten_files' => [], // Track files that were overwritten
            'new_files' => [], // Track new files created
            'max_steps' => $this->max_steps, // Pass user setting to handlers
            'connection_attempts' => 0, // Track connection attempts
            'last_processing_time' => microtime(true), // Track last successful processing time
            'recovery_mode' => false, // Flag for recovery mode
        ];
        
        // Save status
        update_option('swsib_current_restore', $status);
        
        $this->log_message('Restore initialized with ID: ' . $restore_id);
        $this->log_message('Total size to restore: ' . size_format($status['total_size']));
        
        return $status;
    }
    
    /**
     * Test installation path accessibility and permissions.
     * 
     * @param string $connection_method Connection method.
     * @param array $connection_config Connection configuration.
     * @return bool|WP_Error True on success or error.
     */
    private function test_installation_path($connection_method, $connection_config) {
        $base_path = isset($connection_config['path']) ? $connection_config['path'] : '';
        
        // For SFTP, normalize the configuration
        if ($connection_method === 'sftp') {
            if (isset($connection_config['host']) && !isset($connection_config['host_sftp'])) {
                $connection_config['host_sftp'] = $connection_config['host'];
                $connection_config['username_sftp'] = $connection_config['username'];
                $connection_config['password_sftp'] = $connection_config['password'];
                $connection_config['port_sftp'] = isset($connection_config['port']) ? $connection_config['port'] : 22;
                $connection_config['path_sftp'] = $base_path;
            } else if (isset($connection_config['host_sftp'])) {
                $base_path = isset($connection_config['path_sftp']) ? $connection_config['path_sftp'] : $base_path;
            }
        }
        
        if (empty($base_path)) {
            return new WP_Error('path_error', __('Installation path is not configured', 'swiftspeed-siberian'));
        }
        
        try {
            if ($connection_method === 'ftp') {
                return $this->test_ftp_connection($connection_config);
            } else if ($connection_method === 'sftp') {
                return $this->test_sftp_connection($connection_config);
            } else if ($connection_method === 'local') {
                return $this->test_local_path($connection_config);
            } else {
                return new WP_Error('invalid_method', __('Invalid connection method', 'swiftspeed-siberian'));
            }
        } catch (Exception $e) {
            return new WP_Error('connection_test', $e->getMessage());
        }
    }
    
    /**
     * Test FTP connection and permissions
     * 
     * @param array $config FTP connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function test_ftp_connection($config) {
        $base_path = isset($config['path']) ? $config['path'] : '';
        
        if (empty($base_path)) {
            return new WP_Error('path_error', __('FTP path is not configured', 'swiftspeed-siberian'));
        }
        
        // Check for FTP functions
        if (!function_exists('ftp_connect')) {
            return new WP_Error('ftp_error', __('FTP functions not available', 'swiftspeed-siberian'));
        }
        
        // Connect to FTP server
        $conn = @ftp_connect($config['host'], isset($config['port']) ? intval($config['port']) : 21, 30);
        if (!$conn) {
            return new WP_Error('ftp_connect', __('Cannot connect to FTP server', 'swiftspeed-siberian'));
        }
        
        // Login
        if (!@ftp_login($conn, $config['username'], $config['password'])) {
            @ftp_close($conn);
            return new WP_Error('ftp_login', __('FTP login failed', 'swiftspeed-siberian'));
        }
        
        // Set passive mode - essential for many firewalls and NATs
        @ftp_pasv($conn, true);
        
        // Test base path accessibility
        $path_exists = @ftp_chdir($conn, $base_path);
        
        // If path doesn't exist, try to create it
        if (!$path_exists) {
            $this->log_message('FTP path does not exist, will be created during restore: ' . $base_path);
            
            // Try creating a directory in the parent path to test write permissions
            $parent_path = dirname($base_path);
            $test_dir = basename($base_path) . '_test_' . time();
            
            if (!@ftp_chdir($conn, $parent_path)) {
                @ftp_close($conn);
                return new WP_Error('ftp_parent', __('Cannot access parent directory', 'swiftspeed-siberian'));
            }
            
            if (!@ftp_mkdir($conn, $test_dir)) {
                @ftp_close($conn);
                return new WP_Error('ftp_permission', __('No write permission in parent directory', 'swiftspeed-siberian'));
            }
            
            // Clean up test directory
            @ftp_rmdir($conn, $test_dir);
        } else {
            // Path exists, test write permission with a test file
            $test_file = '.' . uniqid() . '.test';
            $temp_file = tempnam(sys_get_temp_dir(), 'ftp');
            file_put_contents($temp_file, 'test');
            
            if (!@ftp_put($conn, $test_file, $temp_file, FTP_BINARY)) {
                @unlink($temp_file);
                @ftp_close($conn);
                return new WP_Error('ftp_permission', __('No write permission in installation directory', 'swiftspeed-siberian'));
            }
            
            // Clean up
            @ftp_delete($conn, $test_file);
            @unlink($temp_file);
        }
        
        // Close connection
        @ftp_close($conn);
        
        $this->log_message('FTP connection test successful for: ' . $config['host']);
        return true;
    }
    
    /**
     * Test SFTP connection and permissions
     * 
     * @param array $config SFTP connection configuration
     * @return bool|WP_Error True on success or error
     */
    private function test_sftp_connection($config) {
        $host = isset($config['host_sftp']) ? $config['host_sftp'] : (isset($config['host']) ? $config['host'] : '');
        $username = isset($config['username_sftp']) ? $config['username_sftp'] : (isset($config['username']) ? $config['username'] : '');
        $password = isset($config['password_sftp']) ? $config['password_sftp'] : (isset($config['password']) ? $config['password'] : '');
        $port = isset($config['port_sftp']) ? intval($config['port_sftp']) : (isset($config['port']) ? intval($config['port']) : 22);
        $base_path = isset($config['path_sftp']) ? $config['path_sftp'] : (isset($config['path']) ? $config['path'] : '');
        
        if (empty($base_path)) {
            return new WP_Error('path_error', __('SFTP path is not configured', 'swiftspeed-siberian'));
        }
        
        // Try using SSH2 extension first
        if (extension_loaded('ssh2')) {
            try {
                // Connect with timeout
                $conn = @ssh2_connect($host, $port, [], ['timeout' => 30]);
                if (!$conn) {
                    throw new Exception('Could not connect to SFTP server');
                }
                
                // Authenticate
                if (!@ssh2_auth_password($conn, $username, $password)) {
                    throw new Exception('Authentication failed');
                }
                
                // Initialize SFTP subsystem
                $sftp = @ssh2_sftp($conn);
                if (!$sftp) {
                    throw new Exception('Failed to initialize SFTP subsystem');
                }
                
                // Test if path exists
                $path_exists = @is_dir("ssh2.sftp://{$sftp}{$base_path}");
                
                if (!$path_exists) {
                    $this->log_message('SFTP path does not exist, will be created during restore: ' . $base_path);
                    
                    // Try creating a test directory to verify permissions
                    $parent_path = dirname($base_path);
                    $test_dir = basename($base_path) . '_test_' . time();
                    $parent_exists = @is_dir("ssh2.sftp://{$sftp}{$parent_path}");
                    
                    if (!$parent_exists) {
                        throw new Exception('Parent directory does not exist');
                    }
                    
                    if (!@ssh2_sftp_mkdir($sftp, $parent_path . '/' . $test_dir, 0755)) {
                        throw new Exception('Cannot create directories in parent path - no write permissions');
                    }
                    
                    // Clean up
                    @ssh2_sftp_rmdir($sftp, $parent_path . '/' . $test_dir);
                } else {
                    // Path exists, test write permissions
                    $test_file = $base_path . '/.' . uniqid() . '.test';
                    $stream = @fopen("ssh2.sftp://{$sftp}{$test_file}", 'w');
                    
                    if (!$stream) {
                        throw new Exception('Cannot write to installation directory - no write permissions');
                    }
                    
                    @fwrite($stream, 'test');
                    @fclose($stream);
                    
                    // Clean up
                    @ssh2_sftp_unlink($sftp, $test_file);
                }
                
                // Close connection
                @ssh2_disconnect($conn);
                $this->log_message('SFTP connection test successful (SSH2 extension)');
                return true;
                
            } catch (Exception $e) {
                $this->log_message('SSH2 test failed: ' . $e->getMessage() . ' - Will try phpseclib');
                // Continue to try phpseclib
            }
        }
        
        // Try phpseclib3 if available
        if (class_exists('\phpseclib3\Net\SFTP')) {
            try {
                $sftp = new \phpseclib3\Net\SFTP($host, $port, 30);
                
                if (!$sftp->login($username, $password)) {
                    throw new Exception('Authentication failed');
                }
                
                // Test if path exists
                $path_exists = $sftp->is_dir($base_path);
                
                if (!$path_exists) {
                    $this->log_message('SFTP path does not exist, will be created during restore: ' . $base_path);
                    
                    // Try creating a test directory to verify permissions
                    $parent_path = dirname($base_path);
                    $test_dir = basename($base_path) . '_test_' . time();
                    $parent_exists = $sftp->is_dir($parent_path);
                    
                    if (!$parent_exists) {
                        throw new Exception('Parent directory does not exist');
                    }
                    
                    if (!$sftp->mkdir($parent_path . '/' . $test_dir)) {
                        throw new Exception('Cannot create directories in parent path - no write permissions');
                    }
                    
                    // Clean up
                    $sftp->rmdir($parent_path . '/' . $test_dir);
                } else {
                    // Path exists, test write permissions
                    $test_file = $base_path . '/.' . uniqid() . '.test';
                    
                    if (!$sftp->put($test_file, 'test')) {
                        throw new Exception('Cannot write to installation directory - no write permissions');
                    }
                    
                    // Clean up
                    $sftp->delete($test_file);
                }
                
                $this->log_message('SFTP connection test successful (phpseclib3)');
                return true;
                
            } catch (Exception $e) {
                return new WP_Error('sftp_error', $e->getMessage());
            }
        }
        
        return new WP_Error('sftp_missing', __('No SFTP capability available (neither SSH2 extension nor phpseclib3)', 'swiftspeed-siberian'));
    }
    
    /**
     * Test local path accessibility and permissions
     * 
     * @param array $config Local path configuration
     * @return bool|WP_Error True on success or error
     */
    private function test_local_path($config) {
        $base_path = isset($config['path']) ? $config['path'] : '';
        
        if (empty($base_path)) {
            return new WP_Error('path_error', __('Local path is not configured', 'swiftspeed-siberian'));
        }
        
        // Check if directory exists
        if (!file_exists($base_path)) {
            $this->log_message('Local path does not exist, will create it: ' . $base_path);
            
            // Try to create the path
            if (!wp_mkdir_p($base_path)) {
                return new WP_Error('local_mkdir', __('Could not create local directory', 'swiftspeed-siberian'));
            }
        }
        
        // Check if we have write permissions
        if (!is_writable($base_path)) {
            return new WP_Error('local_write', __('No write permission to local directory', 'swiftspeed-siberian'));
        }
        
        // Test creating a file
        $test_file = rtrim($base_path, '/') . '/.' . uniqid() . '.test';
        if (@file_put_contents($test_file, 'test') === false) {
            return new WP_Error('local_write', __('Cannot write to local directory', 'swiftspeed-siberian'));
        }
        
        // Clean up
        @unlink($test_file);
        
        $this->log_message('Local path test successful: ' . $base_path);
        return true;
    }
    
    /**
     * Download a backup file from external storage.
     * 
     * @param string $storage_type Storage type.
     * @param array $backup Backup information.
     * @param string $destination_path Destination file path.
     * @return bool|WP_Error True on success or error.
     */
    private function download_from_storage($storage_type, $backup, $destination_path) {
        // Load the storage provider
        $storage_class = 'SwiftSpeed_Siberian_Storage_' . ucfirst($storage_type);
        $provider_file = SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-' . $storage_type . '.php';
        
        if (!file_exists($provider_file)) {
            return new WP_Error('storage_provider', sprintf(__('Storage provider file not found: %s', 'swiftspeed-siberian'), $provider_file));
        }
        
        // Load the file if not already loaded
        if (!class_exists($storage_class)) {
            require_once $provider_file;
        }
        
        // Get provider config
        $config = [];
        if (isset($this->options['backup_restore']['storage'][$storage_type])) {
            $config = $this->options['backup_restore']['storage'][$storage_type];
        }
        
        // Create provider instance
        $provider = new $storage_class($config);
        
        // Initialize provider
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            return $init_result;
        }
        
        // Get source path
        $source_path = '';
        
        // Different providers use different field names for the file identifier
        if (!empty($backup['storage_info']['file_id'])) {
            $source_path = $storage_type . ':' . $backup['storage_info']['file_id'];
        } elseif (!empty($backup['storage_info']['s3_key'])) {
            $source_path = $backup['storage_info']['s3_key'];
        } elseif (!empty($backup['storage_info']['object_name'])) {
            $source_path = $backup['storage_info']['object_name'];
        } elseif (!empty($backup['storage_info']['file'])) {
            $source_path = $backup['storage_info']['file'];
        } else {
            $source_path = $backup['file'];
        }
        
        $this->log_message('Downloading from ' . $storage_type . ' storage: ' . $source_path);
        
        // Create destination directory if it doesn't exist
        $dest_dir = dirname($destination_path);
        if (!file_exists($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Download the file in chunks for better memory usage
        return $this->download_file_chunked($provider, $source_path, $destination_path);
    }
    
    /**
     * Download a file in chunks to reduce memory usage.
     * 
     * @param object $provider Storage provider.
     * @param string $source_path Source file path.
     * @param string $destination_path Destination file path.
     * @return bool|WP_Error True on success or error.
     */
    private function download_file_chunked($provider, $source_path, $destination_path) {
        // For providers that support chunked downloads
        if (method_exists($provider, 'download_file_chunked')) {
            $this->log_message('Using chunked download method');
            $download_result = $provider->download_file_chunked($source_path, $destination_path);
            
            if (is_wp_error($download_result)) {
                return $download_result;
            }
            
            // Verify downloaded file exists and has size
            if (!file_exists($destination_path) || filesize($destination_path) == 0) {
                return new WP_Error('download_verify', __('Downloaded file is empty or missing', 'swiftspeed-siberian'));
            }
            
            return true;
        }
        
        // Fallback to regular download
        $this->log_message('Using standard download method');
        $download_result = $provider->download_file($source_path, $destination_path);
        
        if (is_wp_error($download_result)) {
            return $download_result;
        }
        
        // Verify downloaded file
        if (!file_exists($destination_path) || filesize($destination_path) == 0) {
            return new WP_Error('download_verify', __('Downloaded file is empty or missing', 'swiftspeed-siberian'));
        }
        
        return true;
    }
    
    /**
     * Extract a backup archive with progress reporting.
     * 
     * @param string $file_path Backup file path.
     * @param string $extract_dir Extraction directory.
     * @return bool|WP_Error True on success or error.
     */
    private function extract_backup($file_path, $extract_dir) {
        if (!file_exists($file_path)) {
            return new WP_Error('extract_error', __('Backup file not found', 'swiftspeed-siberian'));
        }
        
        if (!file_exists($extract_dir) && !wp_mkdir_p($extract_dir)) {
            return new WP_Error('extract_error', __('Failed to create extraction directory', 'swiftspeed-siberian'));
        }
        
        $zip = new ZipArchive();
        $open_result = $zip->open($file_path);
        
        if ($open_result !== true) {
            return new WP_Error('extract_error', sprintf(__('Failed to open backup file (code: %d)', 'swiftspeed-siberian'), $open_result));
        }
        
        // For large ZIP files, extract in batches with progress reporting
        $total_files = $zip->numFiles;
        $this->log_message('Extracting ' . $total_files . ' files from backup');
        
        // Use batch extraction for large archives
        if ($total_files > 1000) {
            $result = $this->extract_zip_in_batches($zip, $extract_dir, $total_files);
        } else {
            // Standard extraction for smaller archives
            $result = $zip->extractTo($extract_dir);
        }
        
        $zip->close();
        
        if (!$result) {
            return new WP_Error('extract_error', __('Failed to extract backup file', 'swiftspeed-siberian'));
        }
        
        // Verify extraction results
        if (!$this->verify_extraction($extract_dir)) {
            return new WP_Error('extract_verify', __('Extraction verification failed - incomplete or corrupt backup', 'swiftspeed-siberian'));
        }
        
        return true;
    }
    
    /**
     * Extract a ZIP file in batches to reduce memory usage with progress updates.
     * 
     * @param ZipArchive $zip ZIP archive.
     * @param string $extract_dir Extraction directory.
     * @param int $total_files Total number of files.
     * @return bool True on success, false on failure.
     */
    private function extract_zip_in_batches($zip, $extract_dir, $total_files) {
        $batch_size = 500; // Files per batch
        $batches = ceil($total_files / $batch_size);
        
        $this->log_message('Extracting in ' . $batches . ' batches of ' . $batch_size . ' files');
        
        // Initialize extraction status
        $extract_status = get_option('swsib_current_restore', []);
        
        for ($batch = 0; $batch < $batches; $batch++) {
            $start = $batch * $batch_size;
            $end = min(($batch + 1) * $batch_size, $total_files);
            
            // Extract only files in this batch
            for ($i = $start; $i < $end; $i++) {
                $file = $zip->getNameIndex($i);
                if ($file !== false) {
                    if (!$zip->extractTo($extract_dir, $file)) {
                        $this->log_message('Failed to extract file: ' . $file);
                        return false;
                    }
                }
            }
            
            // Update progress
            $progress = 5 + (($batch + 1) / $batches * 5); // 5-10% progress range for extraction
            if (is_array($extract_status) && !empty($extract_status)) {
                $extract_status['progress'] = $progress;
                $extract_status['message'] = sprintf(
                    __('Extracting backup: %d of %d files (%.1f%%)', 'swiftspeed-siberian'),
                    $end,
                    $total_files,
                    ($end / $total_files) * 100
                );
                update_option('swsib_current_restore', $extract_status);
            }
            
            $this->log_message('Extracted batch ' . ($batch + 1) . ' of ' . $batches . 
                ' (' . $end . '/' . $total_files . ' files)');
            
            // Give the server a moment to breathe between large batches
            if ($batch % 5 == 4) {
                usleep(100000); // 100ms pause
            }
        }
        
        return true;
    }
    
    /**
     * Verify extraction results.
     * 
     * @param string $extract_dir Extraction directory.
     * @return bool True if extraction looks valid.
     */
    private function verify_extraction($extract_dir) {
        // Check for essential files/folders that indicate a valid backup
        $has_readme = file_exists($extract_dir . 'README.txt');
        $has_db = file_exists($extract_dir . 'backup.sql') || file_exists($extract_dir . 'database/');
        $has_files = file_exists($extract_dir . 'files/');
        
        // A valid backup should have at least README and one of files or database
        return $has_readme && ($has_db || $has_files);
    }
    
    /**
     * Get the size of a directory recursively.
     * 
     * @param string $dir Directory path.
     * @return int Directory size in bytes.
     */
    private function get_directory_size($dir) {
        if (!file_exists($dir)) {
            return 0;
        }
        
        $size = 0;
        
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }
    
    /**
     * Calculate file batch size based on user settings (matches backup logic).
     * 
     * @return int File batch size.
     */
    private function calculate_file_batch_size() {
        // Base batch size on user setting (2-25)
        // Lower values are more conservative, higher values are for faster processing
        $base_size = 20; // Base for minimum setting (2)
        $max_size = 300; // Maximum at setting 25
        
        // Normalize to 0-1 range
        $factor = ($this->max_steps - 2) / 23;
        
        // Scale batch size based on user preference (20-300 files)
        $batch_size = round($base_size + ($factor * ($max_size - $base_size)));
        
        return max(20, min($max_size, $batch_size));
    }
    
    /**
     * Calculate DB batch size based on user settings (matches backup logic).
     * 
     * @return int Database batch size.
     */
    private function calculate_db_batch_size() {
        // Base batch size on user setting (2-25)
        // Lower values are more conservative, higher values are for faster processing
        $base_size = 500; // Base for minimum setting (2)
        $max_size = 10000; // Maximum at setting 25
        
        // Normalize to 0-1 range
        $factor = ($this->max_steps - 2) / 23;
        
        // Scale batch size based on user preference (500-10000 rows)
        $batch_size = round($base_size + ($factor * ($max_size - $base_size)));
        
        return max(500, min($max_size, $batch_size));
    }
    
    /**
     * Process the next step in the restore process with failsafe mechanisms.
     * 
     * @param array $status Current restore status.
     * @return array|WP_Error Updated status or error.
     */
    public function process_next_step($status) {
        if (empty($status)) {
            return new WP_Error('restore_error', __('Invalid restore status', 'swiftspeed-siberian'));
        }
        
        if ($status['status'] === 'completed' || $status['status'] === 'error') {
            return $status; // Already completed or has error
        }
        
        // Increase resources for processing
        @ini_set('memory_limit', '2048M');
        @set_time_limit(600); // 10 minutes
        
        // Check for abnormal timeouts
        $last_processing_time = isset($status['last_processing_time']) ? $status['last_processing_time'] : 0;
        $current_time = microtime(true);
        $time_since_last_update = $current_time - $last_processing_time;
        
        // If it's been more than 5 minutes since the last update, something might have gone wrong
        if ($time_since_last_update > 300 && !isset($status['recovery_mode'])) {
            $this->log_message('Detected possible timeout, enabling recovery mode. Time since last update: ' . 
                round($time_since_last_update) . ' seconds');
            
            // Set recovery mode
            $status['recovery_mode'] = true;
            $status['recovery_attempts'] = isset($status['recovery_attempts']) ? $status['recovery_attempts'] + 1 : 1;
            
            // If we've tried recovery too many times, fail the restore
            if ($status['recovery_attempts'] > 3) {
                $this->log_message('Too many recovery attempts, aborting restore');
                $status['status'] = 'error';
                $status['message'] = __('Restore process timed out repeatedly, aborting', 'swiftspeed-siberian');
                update_option('swsib_current_restore', $status);
                return $status;
            }
            
            // Update status before attempting recovery
            update_option('swsib_current_restore', $status);
        }
        
        // Update time elapsed
        $status['time_elapsed'] = microtime(true) - $status['start_time'];
        $status['last_processing_time'] = microtime(true);
        
        // Process multiple steps in one call based on user setting
        $step_count = 0;
        $continue_processing = true;
        $last_result = $status;
        
        while ($continue_processing && $step_count < $status['max_steps']) {
            $step_start_time = microtime(true);
            
            switch ($last_result['phase']) {
                case 'database':
                    $result = $this->database_restore->process($last_result);
                    break;
                    
                case 'files':
                    $result = $this->file_restore->process($last_result);
                    break;
                    
                case 'cleanup':
                    $result = $this->process_cleanup($last_result);
                    break;
                    
                case 'completed':
                case 'error':
                    return $last_result;
                    
                default:
                    $this->log_message('Unknown restore phase: ' . $last_result['phase']);
                    $status['status'] = 'error';
                    $status['phase'] = 'error';
                    $status['message'] = __('Unknown restore phase', 'swiftspeed-siberian');
                    update_option('swsib_current_restore', $status);
                    return $status;
            }
            
            if (is_wp_error($result)) {
                $this->log_message('Error in restore step: ' . $result->get_error_message());
                
                // Handle specific retryable errors
                if ($result->get_error_code() === 'retriable') {
                    $this->log_message('Retryable error detected, will retry');
                    continue;
                }
                
                // For critical errors, fail the restore
                $status['status'] = 'error';
                $status['phase'] = 'error';
                $status['message'] = $result->get_error_message();
                update_option('swsib_current_restore', $status);
                return $status;
            }
            
            // Clear recovery mode if processing was successful
            if (isset($result['recovery_mode']) && $result['recovery_mode']) {
                $result['recovery_mode'] = false;
                $this->log_message('Recovery mode cleared after successful processing');
            }
            
            $last_result = $result;
            
            // If completed or error, stop processing
            if ($result['status'] === 'completed' || $result['status'] === 'error') {
                $continue_processing = false;
            }
            
            $step_count++;
            
            // Update last_processing_time after each successful step
            $last_result['last_processing_time'] = microtime(true);
            update_option('swsib_current_restore', $last_result);
            
            // Step safety checks
            $step_duration = microtime(true) - $step_start_time;
            
            // If a single step takes too long, break to avoid timeouts
            if ($step_duration > 30) { // 30 seconds max per step
                $this->log_message('Step taking too long (' . round($step_duration, 2) . 's), breaking batch');
                $continue_processing = false;
            }
            
            // Check memory usage
            if ($step_count % 5 === 0) {
                $memory_usage = memory_get_usage(true);
                $memory_limit = $this->get_memory_limit();
                
                // If using more than 80% of available memory, stop this batch
                if ($memory_usage > ($memory_limit * 0.8)) {
                    $this->log_message('Memory usage high (' . size_format($memory_usage, 2) . ' of ' . 
                        size_format($memory_limit, 2) . '), stopping batch after ' . $step_count . ' steps');
                    $continue_processing = false;
                }
            }
        }
        
        // Update status
        update_option('swsib_current_restore', $last_result);
        
        return $last_result;
    }
    
    /**
     * Process cleanup phase with comprehensive reporting.
     * 
     * @param array $status Current restore status.
     * @return array Updated status.
     */
    private function process_cleanup($status) {
        // Remove temporary files
        $this->cleanup_temp_files($status['temp_dir']);
        
        // Calculate performance metrics for completion message
        $time_elapsed = microtime(true) - $status['start_time'];
        $total_size = $status['processed_size'];
        $bytes_per_second = ($time_elapsed > 0 && $total_size > 0) ? $total_size / $time_elapsed : 0;
        
        // Format metrics for display
        $elapsed_min = floor($time_elapsed / 60);
        $elapsed_sec = round($time_elapsed % 60);
        $elapsed_formatted = sprintf('%d:%02d', $elapsed_min, $elapsed_sec);
        $size_formatted = size_format($total_size, 2);
        $speed_formatted = size_format($bytes_per_second, 2) . '/s';
        
        // Generate detailed summary
        $summary = [];
        
        if (isset($status['dirs_processed']) && $status['dirs_processed'] > 0) {
            $summary[] = sprintf(__('%d directories created', 'swiftspeed-siberian'), $status['dirs_processed']);
        }
        
        if (isset($status['actual_files_processed']) && $status['actual_files_processed'] > 0) {
            $summary[] = sprintf(__('%d files restored', 'swiftspeed-siberian'), $status['actual_files_processed']);
        }
        
        if (isset($status['tables_processed']) && $status['tables_processed'] > 0) {
            $summary[] = sprintf(__('%d database tables restored', 'swiftspeed-siberian'), $status['tables_processed']);
        }
        
        if (!empty($status['failed_files'])) {
            $summary[] = sprintf(__('%d files failed', 'swiftspeed-siberian'), count($status['failed_files']));
        }
        
        // Determine final status
        $final_status = 'completed';
        $has_errors = !empty($status['failed_files']) || !empty($status['errors']);
        if ($has_errors) {
            $final_status = 'partial';
        }
        
        // Build completion message
        $message = sprintf(
            __('Restore completed! (%s in %s, avg %s)', 'swiftspeed-siberian'),
            $size_formatted,
            $elapsed_formatted,
            $speed_formatted
        );
        
        if (!empty($summary)) {
            $message .= ' - ' . implode(', ', $summary);
        }
        
        if ($has_errors) {
            $message .= ' - ' . __('Some files or tables failed to restore. See admin logs for details.', 'swiftspeed-siberian');
        }
        
        // Mark as completed
        $status['phase'] = 'completed';
        $status['status'] = $final_status;
        $status['message'] = $message;
        $status['progress'] = 100;
        $status['completed'] = time();
        $status['time_elapsed'] = $time_elapsed;
        $status['bytes_per_second_avg'] = $bytes_per_second;
        
        // Add to restore history
        $this->add_to_restore_history($status);
        
        $this->log_message('Restore completed ' . ($has_errors ? 'with errors' : 'successfully') . ': ' . 
            $status['id'] . ' in ' . $elapsed_formatted . ', avg ' . $speed_formatted);
            
        if ($has_errors) {
            $this->log_message('Restore completed with errors: ' . count($status['failed_files']) . ' files failed');
        }
        
        update_option('swsib_current_restore', $status);
        
        return $status;
    }
    
    /**
     * Add completed restore to restore history.
     * 
     * @param array $status Restore status.
     * @return void
     */
    private function add_to_restore_history($status) {
        if (empty($status)) {
            return;
        }
        
        $history = get_option('swsib_restore_history', []);
        
        // Create history entry
        $entry = [
            'id' => $status['id'],
            'backup_id' => $status['backup_id'],
            'has_db' => $status['has_db'],
            'has_files' => $status['has_files'],
            'started' => $status['started'],
            'completed' => $status['completed'],
            'duration' => $status['completed'] - $status['started'],
            'total_size' => $status['processed_size'],
            'speed' => isset($status['bytes_per_second_avg']) ? $status['bytes_per_second_avg'] : 0,
            'status' => $status['status'],
            'files_processed' => isset($status['actual_files_processed']) ? $status['actual_files_processed'] : 0,
            'tables_processed' => isset($status['tables_processed']) ? $status['tables_processed'] : 0,
            'error_count' => count($status['errors'] ?? []) + count($status['failed_files'] ?? []),
            'message' => $status['message'] ?? '',
        ];
        
        $history[$status['id']] = $entry;
        
        // Sort by completion date, newest first
        uasort($history, function($a, $b) {
            return $b['completed'] - $a['completed'];
        });
        
        // Limit history size to 50 entries
        if (count($history) > 50) {
            $history = array_slice($history, 0, 50, true);
        }
        
        update_option('swsib_restore_history', $history);
    }
    
    /**
     * Cancel an in-progress restore.
     * 
     * @param array $status Current restore status.
     * @return bool True on success, false on failure.
     */
    public function cancel_restore($status) {
        if (empty($status)) {
            return false;
        }
        
        $this->log_message('Canceling restore: ' . $status['id']);
        
        // Cleanup temp directory
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        // Add to history as canceled
        $status['status'] = 'canceled';
        $status['phase'] = 'canceled';
        $status['message'] = __('Restore canceled by user', 'swiftspeed-siberian');
        $status['completed'] = time();
        $status['time_elapsed'] = microtime(true) - $status['start_time'];
        
        $this->add_to_restore_history($status);
        
        // Remove status
        delete_option('swsib_current_restore');
        
        return true;
    }
    
    /**
     * Cleanup temporary files.
     * 
     * @param string $temp_dir Temporary directory.
     * @return void
     */
    private function cleanup_temp_files($temp_dir) {
        if (empty($temp_dir) || !file_exists($temp_dir)) {
            return;
        }
        
        $this->log_message('Cleaning up temporary files: ' . $temp_dir);
        
        try {
            // Use RecursiveIteratorIterator with RecursiveDirectoryIterator to get all files
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            
            @rmdir($temp_dir);
        } catch (Exception $e) {
            $this->log_message('Error cleaning up temp files: ' . $e->getMessage());
        }
    }
    
    /**
     * Load backup manifest for integrity verification.
     * 
     * @param string $extract_dir Extraction directory.
     * @return array|WP_Error Manifest information or error.
     */
    private function load_backup_manifest($extract_dir) {
        // Check if README.txt exists and contains manifest info
        $readme_file = $extract_dir . 'README.txt';
        
        if (!file_exists($readme_file)) {
            return [
                'type' => 'unknown',
                'created' => null,
                'tables' => 0,
                'files' => 0,
                'critical_errors' => 0
            ];
        }
        
        $content = file_get_contents($readme_file);
        
        // Parse manifest from README
        $manifest = [
            'type' => 'unknown',
            'created' => null,
            'tables' => 0,
            'files' => 0,
            'critical_errors' => 0
        ];
        
        // Extract backup type
        if (preg_match('/Backup type: (.*)/i', $content, $matches)) {
            $manifest['type'] = strtolower(trim($matches[1]));
        }
        
        // Extract created date
        if (preg_match('/Backup created on: (.*)/i', $content, $matches)) {
            $manifest['created'] = trim($matches[1]);
        }
        
        // Extract critical errors count
        if (preg_match('/\*\*CRITICAL ERRORS OCCURRED\*\*.*?(\d+)/is', $content, $matches)) {
            $manifest['critical_errors'] = intval($matches[1]);
        }
        
        // Extract number of tables
        if (preg_match('/Tables: (\d+)/i', $content, $matches)) {
            $manifest['tables'] = intval($matches[1]);
        }
        
        // Extract number of files
        if (preg_match('/Files backed up: (\d+)/i', $content, $matches)) {
            $manifest['files'] = intval($matches[1]);
        }
        
        return $manifest;
    }
    
    /**
     * Get the available memory limit in bytes.
     * 
     * @return int Memory limit in bytes.
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
}