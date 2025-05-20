<?php
/**
 * Image Cleanup - Data handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Image_Data {
    
    /**
     * Database connection
     */
    public $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Chunk size for processing large datasets
     */
    private $chunk_size = 10;
    
    /**
     * Cached application IDs with timestamp
     * Only cached for 10 seconds to prevent stale data during deletion
     */
    private $app_ids_cache = null;
    private $app_ids_cache_time = 0;
    private $cache_duration = 5; // Reduced from 60 to 5 seconds for more frequent refreshes
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * Get progress file path
     */
    public function get_progress_file($task) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $swsib_dir = $base_dir . '/swsib';
        
        // Create directory if it doesn't exist
        if (!file_exists($swsib_dir)) {
            wp_mkdir_p($swsib_dir);
        }
        
        return $swsib_dir . '/swsib_image_' . sanitize_file_name($task) . '_progress.json';
    }
    
/**
 * Ensure database connection is alive and healthy
 */
private function ensure_database_connection() {
    // Check if connection exists
    if (!$this->db_connection) {
        $this->log_message("No database connection available, attempting to connect...");
        return false;
    }
    
    // Test connection with a simple query instead of using ping()
    try {
        // Execute a minimal query to check connection
        $result = @$this->db_connection->query("SELECT 1");
        if ($result) {
            $result->free();
            return true;
        }
    } catch (Exception $e) {
        $this->log_message("Connection test failed: " . $e->getMessage());
    }
    
    $this->log_message("Database connection lost, attempting to reconnect...");
    
    // Try to reconnect
    $options = get_option('swsib_options', array());
    $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
    
    if (empty($db_options['host']) || empty($db_options['database']) || 
        empty($db_options['username']) || empty($db_options['password'])) {
        $this->log_message("Missing database credentials, cannot reconnect");
        return false;
    }
    
    try {
        $this->db_connection = new mysqli(
            $db_options['host'],
            $db_options['username'],
            $db_options['password'],
            $db_options['database'],
            isset($db_options['port']) ? intval($db_options['port']) : 3306
        );
        
        if ($this->db_connection->connect_error) {
            $this->log_message("Database reconnection failed: " . $this->db_connection->connect_error);
            return false;
        }
        
        $this->log_message("Database reconnection successful");
        return true;
        
    } catch (Exception $e) {
        $this->log_message("Database reconnection error: " . $e->getMessage());
        return false;
    }
}
    
    /**
     * Get application IDs from the database (with caching and health check)
     */
    public function get_application_ids($force_refresh = false) {
        // Return cached data if available and not expired
        if (!$force_refresh && $this->app_ids_cache !== null && 
            (time() - $this->app_ids_cache_time) < $this->cache_duration) {
            return $this->app_ids_cache;
        }
        
        // Ensure database connection is healthy
        if (!$this->ensure_database_connection()) {
            $this->log_message("Cannot connect to database to fetch application IDs");
            return false;
        }
        
        $query = "SELECT app_id FROM application";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Database query failed: " . $this->db_connection->error);
            return false;
        }
        
        $app_ids = array();
        
        while ($row = $result->fetch_assoc()) {
            // Convert to string to match folder names
            $app_ids[] = (string)$row['app_id'];
        }
        
        // Cache the results
        $this->app_ids_cache = $app_ids;
        $this->app_ids_cache_time = time();
        
        $this->log_message("Fetched " . count($app_ids) . " application IDs from database");
        
        // Free result
        $result->free();
        
        return $app_ids;
    }
    
    /**
     * AJAX handler for getting orphaned image folders count
     */
    public function ajax_get_orphaned_images_count() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get count of orphaned image folders
        $count = $this->get_orphaned_images_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * Get count of orphaned image folders
     */
    public function get_orphaned_images_count() {
        
        // Get application IDs from the database for comparison
        $app_ids = $this->get_application_ids();
        
        if ($app_ids === false) {
            $this->log_message("Failed to get application IDs from database");
            return 0;
        }
        
        // Get installation path
        $installation_path = $this->get_installation_path();
        
        if (empty($installation_path)) {
            $this->log_message("Installation path not configured");
            return 0;
        }
        
        // Get all folders in the image application directory
        $image_folders = $this->get_image_folders($installation_path);
        
        if ($image_folders === false) {
            $this->log_message("Failed to access image folders");
            return 0;
        }
        
        // Find orphaned folders (numeric folders that don't correspond to existing app IDs)
        $orphaned_folders = array();
        $non_app_folders = array();
        
        foreach ($image_folders as $folder) {
            // Only consider numeric folder names as they correspond to app IDs
            if (is_numeric($folder) && !in_array((string)$folder, $app_ids, true)) {
                $orphaned_folders[] = $folder;
            } else if (!is_numeric($folder)) {
                $non_app_folders[] = $folder;
            }
        }
        
        $this->log_message("Found " . count($orphaned_folders) . " orphaned image folders out of " . count($image_folders) . " total folders");
        
        return count($orphaned_folders);
    }
    
    /**
     * Get image folders from the Siberian installation
     */
    public function get_image_folders($installation_path) {
        $options = get_option('swsib_options', array());
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $connection_method = isset($installation['connection_method']) ? $installation['connection_method'] : 'ftp';
        
        // Check connection method
        switch ($connection_method) {
            case 'ftp':
                return $this->get_image_folders_ftp($installation_path, $options);
            case 'sftp':
                return $this->get_image_folders_sftp($installation_path, $options);
            case 'local':
            default:
                return $this->get_image_folders_local($installation_path);
        }
    }
    
    /**
     * Get image folders using FTP
     */
    private function get_image_folders_ftp($installation_path, $options) {
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $ftp_options = isset($installation['ftp']) ? $installation['ftp'] : array();
        
        if (empty($ftp_options['host']) || empty($ftp_options['username']) || empty($ftp_options['password'])) {
            $this->log_message("FTP connection details not configured");
            return false;
        }
        
        $host = $ftp_options['host'];
        $username = $ftp_options['username'];
        $password = $ftp_options['password'];
        $port = isset($ftp_options['port']) ? intval($ftp_options['port']) : 21;
        
        // Connect to FTP server
        $conn = @ftp_connect($host, $port);
        if (!$conn) {
            $this->log_message("Failed to connect to FTP server: $host:$port");
            return false;
        }
        
        // Login to FTP server
        $login = @ftp_login($conn, $username, $password);
        if (!$login) {
            $this->log_message("Failed to login to FTP server as $username");
            ftp_close($conn);
            return false;
        }
        
        // Set passive mode
        ftp_pasv($conn, true);
        
        // Change to the image application directory
        $image_app_path = rtrim($installation_path, '/') . '/images/application';
        
        if (!@ftp_chdir($conn, $image_app_path)) {
            $this->log_message("Failed to change to image application directory: $image_app_path");
            ftp_close($conn);
            return false;
        }
        
        // Get list of directories
        $folders = @ftp_nlist($conn, '.');
        
        // Close FTP connection
        ftp_close($conn);
        
        if (!$folders) {
            // $this->log_message("No folders found in image application directory or error listing files");
            return array();
        }
        
        // Filter out . and .. entries
        $folders = array_filter($folders, function($folder) {
            return $folder !== '.' && $folder !== '..';
        });
                
        return $folders;
    }
    
    /**
     * Get image folders using SFTP
     */
    private function get_image_folders_sftp($installation_path, $options) {
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $sftp_options = isset($installation['sftp']) ? $installation['sftp'] : array();
        
        if (empty($sftp_options['host']) || empty($sftp_options['username']) || empty($sftp_options['password'])) {
            $this->log_message("SFTP connection details not configured");
            return false;
        }
        
        $host = $sftp_options['host'];
        $username = $sftp_options['username'];
        $password = $sftp_options['password'];
        $port = isset($sftp_options['port']) ? intval($sftp_options['port']) : 22;
        
        // Determine SFTP method available (ssh2 extension or phpseclib)
        $sftp_method = $this->is_sftp_available();
        
        if (!$sftp_method) {
            $this->log_message("No SFTP capability available (SSH2 extension not loaded and phpseclib not found)");
            return false;
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->get_image_folders_sftp_ssh2($installation_path, $host, $username, $password, $port);
        } 
        // Fallback to phpseclib
        else {
            return $this->get_image_folders_sftp_phpseclib($installation_path, $host, $username, $password, $port);
        }
    }
    
    /**
     * Check if SFTP is available (either via SSH2 extension or phpseclib)
     */
    private function is_sftp_available() {
        if (extension_loaded('ssh2')) {
            $this->log_message('Using native SSH2 extension for SFTP connections');
            return 'ssh2';
        } elseif (class_exists('\phpseclib3\Net\SFTP')) {
            $this->log_message('Native SSH2 extension not available, using phpseclib fallback for SFTP connections');
            return 'phpseclib';
        }
        $this->log_message('No SFTP capability available - SSH2 extension not loaded and phpseclib not found');
        return false;
    }
    
    /**
     * Get image folders using SSH2 extension for SFTP
     */
    private function get_image_folders_sftp_ssh2($installation_path, $host, $username, $password, $port) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            $this->log_message("Could not connect to SFTP server $host:$port");
            return false;
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            $this->log_message("SFTP authentication failed for user $username");
            return false;
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            $this->log_message("Could not initialize SFTP subsystem");
            return false;
        }
        
        // Set up the image application directory path
        $image_app_path = rtrim($installation_path, '/') . '/images/application';
        
        // Try to open the directory for reading
        $sftp_dir = @opendir("ssh2.sftp://$sftp" . $image_app_path);
        
        if (!$sftp_dir) {
            $this->log_message("Could not open directory: $image_app_path");
            return false;
        }
        
        // Read the directory contents
        $folders = array();
        
        while (($file = readdir($sftp_dir)) !== false) {
            if ($file !== '.' && $file !== '..') {
                $folders[] = $file;
            }
        }
        
        closedir($sftp_dir);
        
        return $folders;
    }
    
    /**
     * Get image folders using phpseclib for SFTP
     */
    private function get_image_folders_sftp_phpseclib($installation_path, $host, $username, $password, $port) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                $this->log_message("SFTP authentication failed for user $username");
                return false;
            }
            
            // Set up the image application directory path
            $image_app_path = rtrim($installation_path, '/') . '/images/application';
            
            // List directory contents
            $list = $sftp->nlist($image_app_path);
            
            if ($list === false) {
                $this->log_message("Could not list directory contents of $image_app_path");
                return false;
            }
            
            // Filter out . and .. entries
            $folders = array_filter($list, function($item) {
                return $item !== '.' && $item !== '..';
            });
            
            // Remove the full path information, just get the folder names
            $folders = array_map(function($path) {
                return basename($path);
            }, $folders);
            
            return $folders;
        } catch (\Exception $e) {
            $this->log_message('SFTP error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get image folders locally
     */
    private function get_image_folders_local($installation_path) {
        $image_app_path = rtrim($installation_path, '/') . '/images/application';
        $this->log_message("Checking local directory: $image_app_path");
        
        if (!is_dir($image_app_path)) {
            $this->log_message("Image application directory does not exist: $image_app_path");
            return false;
        }
        
        if (!is_readable($image_app_path)) {
            $this->log_message("Image application directory is not readable: $image_app_path");
            return false;
        }
        
        $folders = array_diff(scandir($image_app_path), array('.', '..'));
        
        if (empty($folders)) {
            $this->log_message("No folders found in image application directory");
            return array();
        }
        
        $this->log_message("Found " . count($folders) . " folders in image application directory");
        
        return $folders;
    }
    
    /**
     * Get installation path from options
     */
    public function get_installation_path() {
        $options = get_option('swsib_options', array());
        $installation = isset($options['installation']) ? $options['installation'] : array();
        
        if (empty($installation['enabled']) || empty($installation['is_configured'])) {
            return '';
        }
        
        $connection_method = isset($installation['connection_method']) ? $installation['connection_method'] : 'ftp';
        $connection_details = isset($installation[$connection_method]) ? $installation[$connection_method] : array();
        
        return isset($connection_details['path']) ? $connection_details['path'] : '';
    }
    
    /**
     * Record cleanup results in the database
     */
    public function record_cleanup_results($deleted, $errors, $skipped = 0) {
        if (!$this->ensure_database_connection()) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        
        // Check if the cleanup log table exists
        $table_check = $this->db_connection->query("SHOW TABLES LIKE 'swsib_cleanup_log'");
        
        if ($table_check->num_rows === 0) {
            // Create the table if it doesn't exist
            $create_table = "CREATE TABLE swsib_cleanup_log (
                id INT(11) NOT NULL AUTO_INCREMENT,
                task_type VARCHAR(50) NOT NULL,
                items_deleted INT(11) NOT NULL,
                errors INT(11) NOT NULL,
                items_skipped INT(11) DEFAULT 0,
                executed_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            )";
            
            $this->db_connection->query($create_table);
        } else {
            // Check if the items_skipped column exists
            $column_check = $this->db_connection->query("SHOW COLUMNS FROM swsib_cleanup_log LIKE 'items_skipped'");
            
            // Add the column if it doesn't exist
            if ($column_check->num_rows === 0) {
                $this->db_connection->query("ALTER TABLE swsib_cleanup_log ADD COLUMN items_skipped INT(11) DEFAULT 0");
            }
            
            $column_check->free();
        }
        
        // Insert cleanup results
        $query = "INSERT INTO swsib_cleanup_log (task_type, items_deleted, errors, items_skipped, executed_at)
                 VALUES ('image_cleanup', $deleted, $errors, $skipped, '$timestamp')";
        
        $this->db_connection->query($query);
        
        $table_check->free();
    }
    
    /**
     * Initialize batch task
     */
    public function initialize_task($task = 'cleanup') {
        $this->log_message("Initializing image cleanup task: $task");
        
        // Get orphaned folders
        $app_ids = $this->get_application_ids(true); // Force refresh to get latest IDs
        if ($app_ids === false) {
            return false;
        }
        
        $installation_path = $this->get_installation_path();
        if (empty($installation_path)) {
            return false;
        }
        
        $image_folders = $this->get_image_folders($installation_path);
        if ($image_folders === false) {
            return false;
        }
        
        // Find orphaned folders
        $orphaned_folders = array();
        $non_app_folders = array();
        
        foreach ($image_folders as $folder) {
            if (is_numeric($folder) && !in_array((string)$folder, $app_ids, true)) {
                $orphaned_folders[] = $folder;
                $this->log_message("Identified orphaned folder: $folder");
            } else if (!is_numeric($folder)) {
                $non_app_folders[] = $folder;
                $this->log_message("Non-application folder (will skip): $folder");
            } else {
                $this->log_message("Active application folder (will skip): $folder");
            }
        }
        
        $total = count($orphaned_folders);
        $logs = array(
            array(
                'time' => time(),
                'message' => sprintf(__('Found %d orphaned image folders to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            ),
            array(
                'time' => time(),
                'message' => sprintf(__('Found %d non-application folders to skip', 'swiftspeed-siberian'), count($non_app_folders)),
                'type' => 'info'
            )
        );
        
        // Ensure we have at least 1 for total to prevent division by zero
        $total = max(1, $total);
        
        // Calculate batch count
        $batch_count = ceil($total / $this->chunk_size);
        
        // Initialize progress data
        $progress_data = array(
            'status' => 'running',
            'progress' => 0,
            'processed' => 0,
            'total' => $total,
            'current_item' => __('Task initialized', 'swiftspeed-siberian'),
            'logs' => array_merge([
                array(
                    'time' => time(),
                    'message' => __('Image cleanup task initialized', 'swiftspeed-siberian'),
                    'type' => 'info'
                )
            ], $logs),
            'start_time' => time(),
            'last_update' => time(),
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'deleted' => 0,
            'errors' => 0,
            'skipped' => 0,
            'orphaned_folders' => $orphaned_folders,
            'installation_path' => $installation_path,
            'deleted_folders' => array() // Track deleted folders
        );
        
        // Save to progress file
        $progress_file = $this->get_progress_file($task);
        file_put_contents($progress_file, json_encode($progress_data));
        
        $this->log_message("Task $task initialized with $total folders, $batch_count batches");
        
        return true;
    }
    
    /**
     * Process batch for image cleanup
     */
    public function process_batch($task = 'cleanup', $batch_index = 0) {
        // Get progress data
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return array(
                'success' => false,
                'message' => 'Progress file not found'
            );
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data) {
            return array(
                'success' => false,
                'message' => 'Invalid progress data'
            );
        }
        
        // Check if processing is already completed
        if ($progress_data['status'] === 'completed') {
            return array(
                'success' => true,
                'message' => 'Processing already completed',
                'progress' => 100,
                'next_batch' => 0,
                'completed' => true
            );
        }
        
        // Calculate batch bounds
        $start_index = $batch_index * $this->chunk_size;
        $orphaned_folders = $progress_data['orphaned_folders'];
        $installation_path = $progress_data['installation_path'];
        
        // Get folders for this batch
        $batch_folders = array_slice($orphaned_folders, $start_index, $this->chunk_size);
        
        if (empty($batch_folders)) {
            // No more folders to process
            $progress_data['status'] = 'completed';
            $progress_data['progress'] = 100;
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => __('All orphaned folders processed successfully.', 'swiftspeed-siberian'),
                'type' => 'success'
            );
            file_put_contents($progress_file, json_encode($progress_data));
            
            return array(
                'success' => true,
                'message' => 'All folders processed',
                'progress' => 100,
                'next_batch' => $batch_index + 1,
                'completed' => true
            );
        }
        
        // Process folders in this batch
        $result = $this->process_folder_batch($batch_folders, $installation_path);
        
        // Update progress
        $progress_data['deleted'] += $result['deleted'];
        $progress_data['errors'] += $result['errors'];
        $progress_data['skipped'] += $result['skipped'];
        $progress_data['processed'] += count($batch_folders);
        $progress_data['current_batch'] = $batch_index + 1;
        
        // Add deleted folders to the tracking list
        if (!isset($progress_data['deleted_folders'])) {
            $progress_data['deleted_folders'] = array();
        }
        
        if (!empty($result['deleted_folders'])) {
            $progress_data['deleted_folders'] = array_merge($progress_data['deleted_folders'], $result['deleted_folders']);
        }
        
        // Calculate progress percentage
        if ($progress_data['total'] > 0) {
            $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
        } else {
            $progress_data['progress'] = 100;
        }
        
        // Add batch logs to progress
        if (!empty($result['logs'])) {
            foreach ($result['logs'] as $log) {
                $progress_data['logs'][] = $log;
            }
        }
        
        // Check if we're done
        $completed = ($progress_data['processed'] >= $progress_data['total']);
        
        if ($completed) {
            $progress_data['status'] = 'completed';
            $progress_data['progress'] = 100;
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            
            // Add completion log
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => sprintf(__('Image cleanup completed. Processed %d folders, deleted %d, skipped %d, with %d errors.', 'swiftspeed-siberian'), 
                                   $progress_data['processed'], $progress_data['deleted'], $progress_data['skipped'], $progress_data['errors']),
                'type' => 'success'
            );
            
            // Record the cleanup results
            $this->record_cleanup_results($progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
        }
        
        // Update progress file
        file_put_contents($progress_file, json_encode($progress_data));
        
        return array(
            'success' => true,
            'message' => 'Batch processed',
            'progress' => $progress_data['progress'],
            'next_batch' => $batch_index + 1,
            'completed' => $completed
        );
    }
    
    /**
     * Process a batch of folders
     */
    private function process_folder_batch($folders, $installation_path) {
        $options = get_option('swsib_options', array());
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $connection_method = isset($installation['connection_method']) ? $installation['connection_method'] : 'ftp';
        
        $logs = [];
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $deleted_folders = array(); // Track deleted folders
        
        // Base image application path
        $image_app_path = rtrim($installation_path, '/') . '/images/application';
        $this->log_message("Processing batch of " . count($folders) . " folders in: $image_app_path");
        
        foreach ($folders as $folder) {
            $folder_path = $image_app_path . '/' . $folder;
            $logs[] = array(
                'time' => time(),
                'message' => "Processing folder: $folder",
                'type' => 'info'
            );
            
            // Critical double-check before deletion: verify the folder is still orphaned
            $current_app_ids = $this->get_application_ids(true); // Force refresh to get latest IDs
            
            if ($current_app_ids === false) {
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Failed to get current application IDs - SKIPPING deletion for safety: $folder",
                    'type' => 'error'
                );
                $this->log_message("CRITICAL: Skipping deletion of folder $folder due to database error");
                continue;
            }
            
            // Check if folder is still orphaned
            if (in_array((string)$folder, $current_app_ids, true)) {
                $skipped++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "SAFETY: Folder $folder is NO LONGER orphaned - a new application was created. Skipping deletion.",
                    'type' => 'warning'
                );
                $this->log_message("SAFETY: Skipping deletion of folder $folder - it now has an active application");
                continue;
            }
            
            // Proceed with deletion only after verification
            switch ($connection_method) {
                case 'ftp':
                    $result = $this->delete_folder_ftp($folder_path, $options, $folder);
                    break;
                case 'sftp':
                    $result = $this->delete_folder_sftp($folder_path, $options, $folder);
                    break;
                case 'local':
                default:
                    $result = $this->delete_folder_local($folder_path, $folder);
                    break;
            }
            
            if ($result['success']) {
                $deleted++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Successfully deleted folder: $folder",
                    'type' => 'success'
                );
                
                // Add to deleted folders list
                $deleted_folders[] = array(
                    'folder_id' => $folder,
                    'folder_path' => $folder_path,
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                );
            } else {
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Failed to delete folder: $folder - " . $result['message'],
                    'type' => 'error'
                );
            }
        }
        
        return array(
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped,
            'logs' => $logs,
            'deleted_folders' => $deleted_folders
        );
    }
    
    /**
     * Delete folder using FTP
     */
    private function delete_folder_ftp($folder_path, $options, $folder_id) {
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $ftp_options = isset($installation['ftp']) ? $installation['ftp'] : array();
        
        $this->log_message("Attempting to delete folder via FTP: $folder_path");
        
        if (empty($ftp_options['host']) || empty($ftp_options['username']) || empty($ftp_options['password'])) {
            $this->log_message("FTP connection details not configured");
            return array(
                'success' => false,
                'message' => 'FTP connection details not configured'
            );
        }
        
        $host = $ftp_options['host'];
        $username = $ftp_options['username'];
        $password = $ftp_options['password'];
        $port = isset($ftp_options['port']) ? intval($ftp_options['port']) : 21;
        
        // Connect to FTP server
        $conn = @ftp_connect($host, $port);
        if (!$conn) {
            $this->log_message("Failed to connect to FTP server: $host:$port");
            return array(
                'success' => false,
                'message' => "Failed to connect to FTP server: $host:$port"
            );
        }
        
        // Login to FTP server
        $login = @ftp_login($conn, $username, $password);
        if (!$login) {
            $this->log_message("Failed to login to FTP server as $username");
            ftp_close($conn);
            return array(
                'success' => false,
                'message' => "Failed to login to FTP server as $username"
            );
        }
        
        // Set passive mode
        ftp_pasv($conn, true);
        
        // Check if directory exists
        if (!@ftp_chdir($conn, $folder_path)) {
            $this->log_message("Directory doesn't exist or can't be accessed: $folder_path");
            ftp_close($conn);
            return array(
                'success' => false,
                'message' => "Directory doesn't exist or can't be accessed: $folder_path"
            );
        }
        
        // Go back to parent directory so we can delete the target
        @ftp_cdup($conn);
        
        // Get folder name from path
        $folder_name = basename($folder_path);
        
        $this->log_message("Deleting FTP directory: $folder_name");
        
        // Recursive delete helper function
        $success = $this->ftp_delete_dir_recursive($conn, $folder_name);
        
        // Close FTP connection
        ftp_close($conn);
        
        if ($success) {
            $this->log_message("Successfully deleted folder via FTP: $folder_path");
            return array(
                'success' => true,
                'message' => 'Folder deleted successfully'
            );
        } else {
            $this->log_message("Failed to delete folder via FTP: $folder_path");
            return array(
                'success' => false,
                'message' => 'Failed to delete folder'
            );
        }
    }
    
    /**
     * Delete folder using SFTP
     */
    private function delete_folder_sftp($folder_path, $options, $folder_id) {
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $sftp_options = isset($installation['sftp']) ? $installation['sftp'] : array();
        
        $this->log_message("Attempting to delete folder via SFTP: $folder_path");
        
        if (empty($sftp_options['host']) || empty($sftp_options['username']) || empty($sftp_options['password'])) {
            $this->log_message("SFTP connection details not configured");
            return array(
                'success' => false,
                'message' => 'SFTP connection details not configured'
            );
        }
        
        $host = $sftp_options['host'];
        $username = $sftp_options['username'];
        $password = $sftp_options['password'];
        $port = isset($sftp_options['port']) ? intval($sftp_options['port']) : 22;
        
        // Determine SFTP method available (ssh2 extension or phpseclib)
        $sftp_method = $this->is_sftp_available();
        
        if (!$sftp_method) {
            $this->log_message("No SFTP capability available");
            return array(
                'success' => false,
                'message' => 'SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.'
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->delete_folder_sftp_ssh2($folder_path, $host, $username, $password, $port, $folder_id);
        } 
        // Fallback to phpseclib
        else {
            return $this->delete_folder_sftp_phpseclib($folder_path, $host, $username, $password, $port, $folder_id);
        }
    }
    
    /**
     * Delete folder using SSH2 extension for SFTP
     */
    private function delete_folder_sftp_ssh2($folder_path, $host, $username, $password, $port, $folder_id) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            $this->log_message("Could not connect to SFTP server: $host:$port");
            return array(
                'success' => false,
                'message' => "Could not connect to SFTP server: $host:$port"
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            $this->log_message("SFTP authentication failed for user: $username");
            return array(
                'success' => false,
                'message' => "SFTP authentication failed for user: $username"
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            $this->log_message("Could not initialize SFTP subsystem");
            return array(
                'success' => false,
                'message' => "Could not initialize SFTP subsystem"
            );
        }
        
        // Check if directory exists
        if (!is_dir("ssh2.sftp://$sftp" . $folder_path)) {
            $this->log_message("Directory doesn't exist or can't be accessed: $folder_path");
            return array(
                'success' => false,
                'message' => "Directory doesn't exist or can't be accessed: $folder_path"
            );
        }
        
        // Delete the directory recursively
        $success = $this->sftp_delete_dir_recursive_ssh2($sftp, $folder_path);
        
        if ($success) {
            $this->log_message("Successfully deleted folder via SFTP (SSH2): $folder_path");
            return array(
                'success' => true,
                'message' => 'Folder deleted successfully'
            );
        } else {
            $this->log_message("Failed to delete folder via SFTP (SSH2): $folder_path");
            return array(
                'success' => false,
                'message' => 'Failed to delete folder'
            );
        }
    }
    
    /**
     * Delete folder using phpseclib for SFTP
     */
    private function delete_folder_sftp_phpseclib($folder_path, $host, $username, $password, $port, $folder_id) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                $this->log_message("SFTP authentication failed for user: $username");
                return array(
                    'success' => false,
                    'message' => "SFTP authentication failed for user: $username"
                );
            }
            
            // Check if directory exists
            if (!$sftp->is_dir($folder_path)) {
                $this->log_message("Directory doesn't exist: $folder_path");
                return array(
                    'success' => false,
                    'message' => "Directory doesn't exist: $folder_path"
                );
            }
            
            // Delete the directory recursively
            $success = $this->sftp_delete_dir_recursive_phpseclib($sftp, $folder_path);
            
            if ($success) {
                $this->log_message("Successfully deleted folder via SFTP (phpseclib): $folder_path");
                return array(
                    'success' => true,
                    'message' => 'Folder deleted successfully'
                );
            } else {
                $this->log_message("Failed to delete folder via SFTP (phpseclib): $folder_path");
                return array(
                    'success' => false,
                    'message' => 'Failed to delete folder'
                );
            }
        } catch (\Exception $e) {
            $this->log_message("SFTP error: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => "SFTP error: " . $e->getMessage()
            );
        }
    }
    
    /**
     * Recursively delete a directory using SSH2 SFTP
     */
    private function sftp_delete_dir_recursive_ssh2($sftp, $directory) {
        // Get directory listing
        $dir = @opendir("ssh2.sftp://$sftp" . $directory);
        
        if (!$dir) {
            $this->log_message("Failed to open directory: $directory");
            return false;
        }
        
        $success = true;
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $directory . '/' . $file;
            
            if (is_dir("ssh2.sftp://$sftp" . $full_path)) {
                // Recursively delete subdirectory
                if (!$this->sftp_delete_dir_recursive_ssh2($sftp, $full_path)) {
                    $this->log_message("Failed to delete subdirectory: $full_path");
                    $success = false;
                    continue; // Continue with other files/dirs even if this one fails
                }
            } else {
                // Delete file
                if (!@ssh2_sftp_unlink($sftp, $full_path)) {
                    $this->log_message("Failed to delete file: $full_path");
                    $success = false;
                    continue; // Continue with other files even if this one fails
                }
            }
        }
        
        closedir($dir);
        
        // Try to delete the directory itself if all contents were successfully deleted
        if ($success && !@ssh2_sftp_rmdir($sftp, $directory)) {
            $this->log_message("Failed to remove directory: $directory");
            $success = false;
        }
        
        return $success;
    }
    
    /**
     * Recursively delete a directory using phpseclib SFTP
     */
    private function sftp_delete_dir_recursive_phpseclib($sftp, $directory) {
        // Get directory listing
        $files = $sftp->nlist($directory);
        
        if ($files === false) {
            $this->log_message("Failed to list directory contents: $directory");
            return false;
        }
        
        $success = true;
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $directory . '/' . $file;
            
            if ($sftp->is_dir($full_path)) {
                // Recursively delete subdirectory
                if (!$this->sftp_delete_dir_recursive_phpseclib($sftp, $full_path)) {
                    $this->log_message("Failed to delete subdirectory: $full_path");
                    $success = false;
                    continue; // Continue with other files/dirs even if this one fails
                }
            } else {
                // Delete file
                if (!$sftp->delete($full_path)) {
                    $this->log_message("Failed to delete file: $full_path");
                    $success = false;
                    continue; // Continue with other files even if this one fails
                }
            }
        }
        
        // Try to delete the directory itself if all contents were successfully deleted
        if ($success && !$sftp->rmdir($directory)) {
            $this->log_message("Failed to remove directory: $directory");
            $success = false;
        }
        
        return $success;
    }
    
    /**
     * Recursively delete a directory via FTP
     */
    private function ftp_delete_dir_recursive($conn, $directory) {
        // Try to change to the directory
        if (@ftp_chdir($conn, $directory)) {
            // Get list of files in the directory
            $files = @ftp_nlist($conn, ".");
            
            if ($files === false) {
                $this->log_message("Failed to list contents of directory: $directory");
                return false;
            }
            
            // Remove . and .. entries
            $files = array_filter($files, function($file) {
                return $file != '.' && $file != '..';
            });
            
            $this->log_message("Found " . count($files) . " items in directory: $directory");
            
            // Track success status to continue even if some operations fail
            $success = true;
            
            foreach ($files as $file) {
                // Try to change into file to see if it's a directory
                if (@ftp_chdir($conn, $file)) {
                    // It's a directory, change back and delete recursively
                    @ftp_cdup($conn);
                    if (!$this->ftp_delete_dir_recursive($conn, $file)) {
                        $this->log_message("Failed to delete subdirectory: $file in $directory, but continuing with others");
                        $success = false;
                        continue; // Continue with other files/dirs even if this one fails
                    }
                } else {
                    // It's a file, delete it
                    if (!@ftp_delete($conn, $file)) {
                        $this->log_message("Failed to delete file: $file in $directory, but continuing with others");
                        $success = false;
                        continue; // Continue with other files even if this one fails
                    }
                }
            }
            
            // Change back to parent directory
            @ftp_cdup($conn);
            
            // Delete the directory itself if all contents were successfully deleted
            if ($success && !@ftp_rmdir($conn, $directory)) {
                $this->log_message("Failed to remove directory: $directory");
                $success = false;
            }
            
            return $success;
        }
        
        return false;
    }
    
    /**
     * Delete folder locally
     */
    private function delete_folder_local($folder_path, $folder_id) {
        $this->log_message("Attempting to delete folder locally: $folder_path");
        
        if (!file_exists($folder_path)) {
            $this->log_message("Folder does not exist: $folder_path");
            return array(
                'success' => false,
                'message' => 'Folder does not exist'
            );
        }
        
        if (!is_dir($folder_path)) {
            $this->log_message("Path is not a directory: $folder_path");
            return array(
                'success' => false,
                'message' => 'Path is not a directory'
            );
        }
        
        $result = $this->recursive_rmdir($folder_path);
        
        if ($result) {
            $this->log_message("Successfully deleted folder locally: $folder_path");
            return array(
                'success' => true,
                'message' => 'Folder deleted successfully'
            );
        } else {
            $this->log_message("Failed to delete folder locally: $folder_path");
            return array(
                'success' => false,
                'message' => 'Failed to delete folder'
            );
        }
    }
    
    /**
     * Recursively delete a directory locally
     */
    private function recursive_rmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $success = true;
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                // Recursively delete subdirectory
                if (!$this->recursive_rmdir($path)) {
                    $this->log_message("Failed to delete subdirectory: $path");
                    $success = false;
                }
            } else {
                // Delete file
                if (!@unlink($path)) {
                    $this->log_message("Failed to delete file: $path");
                    $success = false;
                }
            }
        }
        
        // Try to delete the directory itself
        if ($success && !@rmdir($dir)) {
            $this->log_message("Failed to remove directory: $dir");
            $success = false;
        }
        
        return $success;
    }
    
    /**
     * Get progress data for a task
     */
    public function get_progress_data($task = 'cleanup') {
        $progress_file = $this->get_progress_file($task);
        
        if (!file_exists($progress_file)) {
            return array(
                'status' => 'not_started',
                'progress' => 0,
                'processed' => 0,
                'total' => 0,
                'current_item' => '',
                'logs' => []
            );
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        if (!$progress_data) {
            return array(
                'status' => 'error',
                'progress' => 0,
                'processed' => 0,
                'total' => 0,
                'current_item' => '',
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Invalid progress data',
                        'type' => 'error'
                    )
                ]
            );
        }
        
        return $progress_data;
    }
    
    /**
     * Get orphaned folders for preview
     */
    public function get_orphaned_folders_preview($page = 1, $per_page = 10) {
        // Get application IDs from the database for comparison
        $app_ids = $this->get_application_ids();
        
        if ($app_ids === false) {
            return array(
                'success' => false,
                'message' => 'Failed to get application IDs from database'
            );
        }
        
        // Get installation path
        $installation_path = $this->get_installation_path();
        
        if (empty($installation_path)) {
            return array(
                'success' => false,
                'message' => 'Installation path not configured'
            );
        }
        
        // Get all folders in the image application directory
        $image_folders = $this->get_image_folders($installation_path);
        
        if ($image_folders === false) {
            return array(
                'success' => false,
                'message' => 'Failed to access image folders'
            );
        }
        
        // Filter and prepare the data
        $orphaned_folders = array();
        $non_app_folders = array();
        
        foreach ($image_folders as $folder) {
            if (is_numeric($folder) && !in_array((string)$folder, $app_ids, true)) {
                $orphaned_folders[] = array(
                    'folder_id' => $folder,
                    'folder_path' => rtrim($installation_path, '/') . '/images/application/' . $folder,
                    'status' => 'Orphaned'
                );
            } else if (!is_numeric($folder)) {
                $non_app_folders[] = array(
                    'folder_id' => $folder,
                    'folder_path' => rtrim($installation_path, '/') . '/images/application/' . $folder,
                    'status' => 'Non-Application (Will be skipped)'
                );
            }
        }
        
        // Sort folders by ID for better readability
        usort($orphaned_folders, function($a, $b) {
            return $a['folder_id'] <=> $b['folder_id'];
        });
        
        // Combine both types, with orphaned folders first
        $all_folders = array_merge($orphaned_folders, $non_app_folders);
        
        // Calculate pagination
        $total = count($all_folders);
        $total_pages = ceil($total / $per_page);
        
        // Get requested page of data
        $offset = ($page - 1) * $per_page;
        $folders_page = array_slice($all_folders, $offset, $per_page);
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Orphaned Image Folders',
                'headers' => array('Folder ID', 'Folder Path', 'Status'),
                'fields' => array('folder_id', 'folder_path', 'status'),
                'items' => $folders_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page,
                'orphaned_count' => count($orphaned_folders),
                'non_app_count' => count($non_app_folders)
            )
        );
    }
    
   /**
    * AJAX handler for getting orphaned folders preview
    */
    public function ajax_preview_orphaned_folders() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get page number
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        
        // Get folders preview
        $result = $this->get_orphaned_folders_preview($page, $per_page);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}