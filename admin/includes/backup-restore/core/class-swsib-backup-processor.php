<?php
/**
 * Backup Processor component.
 * Handles backup-related AJAX endpoints and processing.
 * Enhanced with improved reliability and background processing.
 * Version 3.0: Better large file handling and stall detection
 * 
 * @since 2.3.0
 */
class SwiftSpeed_Siberian_Backup_Processor {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Storage manager instance.
     * 
     * @var SwiftSpeed_Siberian_Storage_Manager
     */
    private $storage_manager;
    
    /**
     * File backup instance.
     * 
     * @var SwiftSpeed_Siberian_File_Backup
     */
    private $file_backup;
    
    /**
     * Full backup instance.
     * 
     * @var SwiftSpeed_Siberian_Full_Backup
     */
    private $full_backup;
    
    /**
     * Backup directory.
     * 
     * @var string
     */
    private $backup_dir;
    
    /**
     * Temporary directory for backup operations.
     * 
     * @var string
     */
    private $temp_dir;
    
    /**
     * Maximum steps to process in one background run
     * 
     * @var int
     */
    private $max_steps = 5;
    
    /**
     * Time limit for each step in seconds
     * 
     * @var int
     */
    private $step_time_limit = 60;

    /**
     * Time to wait (in seconds) to consider a backup as stalled
     * 
     * @var int
     */
    private $stall_timeout = 300; // 5 minutes
    
    /**
     * Longer timeout (in seconds) for large file processing
     * 
     * @var int
     */
    private $large_file_timeout = 600; // 10 minutes
    
    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->temp_dir = $this->backup_dir . 'temp/';
        $this->ensure_directories();
        
        // Initialize components
        $this->init_components();
        
        // Register AJAX handlers for backup
        $this->register_ajax_handlers();
        
        // Register background process hook with high priority
        add_action('swsib_process_background_backup', array($this, 'process_background_backup'), 5);
        
        // Set max steps from options
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        // Ensure valid values for max_steps
        if ($this->max_steps < 1 || $this->max_steps > 25) {
            $this->max_steps = 5; // Default to 5 if invalid
        }
        
        // Adjust individual step time limit based on max_steps
        if ($this->max_steps > 10) {
            // For high settings, reduce the individual step time limit
            $this->step_time_limit = 30;
        }
        
        // Add additional triggers for backup checking
        add_action('admin_init', array($this, 'admin_init_check_backup'), 20);
        add_action('wp_loaded', array($this, 'front_end_check_backup'), 20);
    }
    
    /**
     * Ensure necessary directories exist.
     *
     * @return bool Whether directories were created successfully
     */
    private function ensure_directories() {
        $directories = array($this->backup_dir, $this->temp_dir);
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    $this->log_message('Failed to create directory: ' . $dir);
                    return false;
                }
            }
        }
        
        // Create .htaccess to prevent direct access
        $htaccess_file = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, "Order deny,allow\nDeny from all");
        }
        
        // Create index.php for security
        $index_file = $this->backup_dir . 'index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n// Silence is golden.");
        }
        
        return true;
    }
    
    /**
     * Set the storage manager instance.
     *
     * @param SwiftSpeed_Siberian_Storage_Manager $storage_manager Storage manager instance.
     * @return void
     */
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    /**
     * Set the maximum number of steps to process.
     */
    public function set_max_steps($max_steps) {
        $this->max_steps = intval($max_steps);
        
        // Ensure valid values
        if ($this->max_steps < 1 || $this->max_steps > 25) {
            $this->max_steps = 5; // Default to 5 if invalid
        }
    }
    
    /**
     * Admin init backup check - additional trigger for admin pages
     */
    public function admin_init_check_backup() {
        // Get the last admin check time
        $last_admin_check = get_option('swsib_last_admin_backup_check', 0);
        $current_time = time();
        
        // Only check every 2 minutes to prevent excessive checks
        if (($current_time - $last_admin_check) > 120) {
            $this->check_background_backup();
            update_option('swsib_last_admin_backup_check', $current_time);
        }
    }
    
    /**
     * Front end task check - additional trigger for frontend visits
     */
    public function front_end_check_backup() {
        // Only run on non-admin pages and limit frequency
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Get the last front end check time
        $last_frontend_check = get_option('swsib_last_frontend_backup_check', 0);
        $current_time = time();
        
        // Only check every 10 minutes to prevent excessive checks on high-traffic sites
        if (($current_time - $last_frontend_check) > 600) {
            $this->check_background_backup();
            update_option('swsib_last_frontend_backup_check', $current_time);
        }
    }
    
    /**
     * Check if there's a background backup that needs processing
     */
    private function check_background_backup() {
        // Check if a backup is in progress
        $current_backup = get_option('swsib_current_backup', array());
        $background_flag = $this->get_background_flag();
        
        if (!empty($current_backup) && 
            in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading')) && 
            $background_flag) {
            
            // Check for stalled backup
            $heartbeat = get_option('swsib_backup_heartbeat', 0);
            $current_time = time();
            
            // Determine the appropriate timeout threshold
            $timeout_threshold = $this->stall_timeout;
            
            // Use longer timeout for large file processing
            if (isset($current_backup['is_processing_large_file']) && $current_backup['is_processing_large_file']) {
                $timeout_threshold = $this->large_file_timeout;
                $this->log_message('Using extended timeout threshold for large file processing');
            }
            
            // Consider stalled if no heartbeat for the timeout threshold
            $stalled = ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold);
            
            // Also check if the backup has been running for too long overall
            if (!$stalled && isset($current_backup['started'])) {
                $duration = $current_time - $current_backup['started'];
                
                // If backup has been running for more than 2 hours, check for progress
                if ($duration > 7200) {
                    $this->log_message('Backup has been running for more than 2 hours, checking for progress');
                    
                    // Check if progress has been made recently
                    $last_update = get_option('swsib_last_backup_update', 0);
                    if ($last_update > 0 && ($current_time - $last_update) > 600) {
                        $stalled = true;
                        $this->log_message('No progress in last 10 minutes, marking as stalled');
                    }
                }
            }
            
            if ($stalled) {
                $this->log_message('Found stalled backup (no heartbeat for ' . $timeout_threshold . '+ seconds), attempting to resume');
                
                // Check for checkpoint file
                $checkpoint_file = $this->backup_dir . 'temp/' . $current_backup['id'] . '/checkpoint.json';
                if (file_exists($checkpoint_file)) {
                    $this->log_message('Checkpoint found, attempting to resume backup from checkpoint...');
                    
                    // Load checkpoint data
                    $checkpoint_data = json_decode(file_get_contents($checkpoint_file), true);
                    if (!empty($checkpoint_data)) {
                        // Update the stalled backup status with checkpoint data
                        $current_backup['processed_files'] = $checkpoint_data['processed_files'];
                        $current_backup['processed_dirs'] = $checkpoint_data['processed_dirs'];
                        $current_backup['total_size'] = $checkpoint_data['total_size'];
                        $current_backup['pending_files'] = $checkpoint_data['pending_files'];
                        $current_backup['large_files_queue'] = $checkpoint_data['large_files_queue'];
                        $current_backup['retry_files'] = $checkpoint_data['retry_files'];
                        $current_backup['status'] = 'processing'; // Reset to processing state
                        $current_backup['message'] = __('Resuming backup from checkpoint...', 'swiftspeed-siberian');
                        
                        // Save the updated status
                        update_option('swsib_current_backup', $current_backup);
                        $this->log_message('Successfully resumed backup from checkpoint');
                    }
                }
                
                // Clear any process locks
                delete_option('swsib_backup_process_lock');
            } else {
                $this->log_message('Found active backup, continuing in background');
            }
            
            // Trigger background processing
            do_action('swsib_process_background_backup');
            
            // Also schedule a single event for extra reliability
            if (!wp_next_scheduled('swsib_process_background_backup')) {
                wp_schedule_single_event(time() + 30, 'swsib_process_background_backup');
            }
        }
    }
    
    /**
     * Initialize components.
     *
     * @return void
     */
    private function init_components() {
        $this->file_backup = new SwiftSpeed_Siberian_File_Backup();
        $this->full_backup = new SwiftSpeed_Siberian_Full_Backup();
    }
    
    /**
     * Register AJAX handlers.
     *
     * @return void
     */
    private function register_ajax_handlers() {
        // Backup handlers
        add_action('wp_ajax_swsib_start_backup', array($this, 'ajax_start_backup'));
        add_action('wp_ajax_swsib_backup_progress', array($this, 'ajax_backup_progress'));
        add_action('wp_ajax_swsib_process_next_backup_step', array($this, 'ajax_process_next_backup_step'));
        add_action('wp_ajax_swsib_cancel_backup', array($this, 'ajax_cancel_backup'));
        
        // Backup history handlers
        add_action('wp_ajax_swsib_get_backup_history', array($this, 'ajax_get_backup_history'));
        add_action('wp_ajax_swsib_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_swsib_lock_backup', array($this, 'ajax_lock_backup'));
        add_action('wp_ajax_swsib_download_backup', array($this, 'ajax_download_backup'));
        
        // Background processing handlers
        add_action('wp_ajax_swsib_ping_background', array($this, 'ajax_ping_background'));
        add_action('wp_ajax_swsib_force_check_backup', array($this, 'ajax_force_check_backup'));
        add_action('wp_ajax_nopriv_swsib_force_check_backup', array($this, 'ajax_force_check_backup'));
        
        // External trigger for scheduled backups
        add_action('wp_ajax_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_nopriv_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
    }
    
    /**
     * AJAX handler for starting a backup.
     */
    public function ajax_start_backup() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            $this->log_message('Backup failed: Security check failed');
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            $this->log_message('Backup failed: Permission denied');
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get parameters
        $backup_type = isset($_POST['backup_type']) ? sanitize_key($_POST['backup_type']) : 'full';
        
        // Get storage providers - handle multiple selections or single value
        $storage_providers = isset($_POST['storage_providers']) ? (array)$_POST['storage_providers'] : array('local');
        
        // Ensure local storage is always included
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        // Sanitize storage providers
        $storage_providers = array_map('sanitize_key', $storage_providers);
        
        // Use the first storage provider as the primary
        $primary_storage = $storage_providers[0];
        
        $include_all_files = isset($_POST['include_all_files']) && $_POST['include_all_files'] === '1';
        
        // Check if backup should be locked (new feature)
        $auto_lock = isset($_POST['lock_backup']) && $_POST['lock_backup'] === '1';
        
        $this->log_message('Starting backup of type: ' . $backup_type . ', primary storage: ' . $primary_storage . 
                       ', additional storages: ' . implode(',', array_slice($storage_providers, 1)) . 
                       ', include_all_files: ' . ($include_all_files ? 'yes' : 'no') . 
                       ', auto_lock: ' . ($auto_lock ? 'yes' : 'no'));
        
        // Get paths
        $include_paths = array();
        $exclude_paths = array();
        
        if (!$include_all_files) {
            if (isset($_POST['include_paths']) && !empty($_POST['include_paths'])) {
                $paths = explode("\n", sanitize_textarea_field($_POST['include_paths']));
                foreach ($paths as $path) {
                    $path = trim($path);
                    if (!empty($path)) {
                        $include_paths[] = $path;
                    }
                }
            }
            
            if (isset($_POST['exclude_paths']) && !empty($_POST['exclude_paths'])) {
                $paths = explode("\n", sanitize_textarea_field($_POST['exclude_paths']));
                foreach ($paths as $path) {
                    $path = trim($path);
                    if (!empty($path)) {
                        $exclude_paths[] = $path;
                    }
                }
            }
        }
        
        // Clear any existing stalled backups
        delete_option('swsib_backup_process_lock');
        delete_option('swsib_backup_heartbeat');
        
        // Start the backup based on type
        $params = array(
            'storage' => $primary_storage,
            'storage_providers' => $storage_providers,
            'include_paths' => $include_paths,
            'exclude_paths' => $exclude_paths,
            'allow_background' => true, // Enable background processing
            'auto_lock' => $auto_lock, // Add auto-lock parameter
            'scheduled' => false, // Explicitly mark as manual backup
            'max_steps' => $this->max_steps, // Pass max_steps to backup component
        );
        
        if ($backup_type === 'full') {
            // For full backup, we need both DB and files
            $params['include_db'] = true;
            $params['include_files'] = true;
            $this->log_message('Starting full backup with both DB and files');
            $result = $this->full_backup->start_backup($params);
        } elseif ($backup_type === 'files') {
            // For files-only backup
            $this->log_message('Starting files-only backup');
            $result = $this->file_backup->start_backup($params);
        } else if ($backup_type === 'db') {
            // For DB-only backup, use full backup class with db-only flag
            $params['include_db'] = true;
            $params['include_files'] = false;
            $this->log_message('Starting DB-only backup');
            $result = $this->full_backup->start_backup($params);
        } else {
            $this->log_message('Invalid backup type: ' . $backup_type);
            wp_send_json_error(array('message' => __('Invalid backup type', 'swiftspeed-siberian')));
            return;
        }
        
        if (is_wp_error($result)) {
            $this->log_message('Backup failed: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Set the background flag and update heartbeat
        $this->update_background_flag(true);
        $this->update_backup_heartbeat();
        
        // Process a batch immediately in the current request
        $this->process_background_backup();
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for checking backup progress with enhanced details.
     */
    public function ajax_backup_progress() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current backup status
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup found', 'swiftspeed-siberian')));
        }
        
        // Determine appropriate timeout threshold
        $timeout_threshold = $this->stall_timeout;
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $timeout_threshold = $this->large_file_timeout;
        }
        
        // Check for stalled backup
        $heartbeat = get_option('swsib_backup_heartbeat', 0);
        $current_time = time();
        
        if ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold) {
            // Backup appears stalled
            $this->log_message('Backup appears stalled - last heartbeat: ' . date('Y-m-d H:i:s', $heartbeat));
            
            // Check if processing a large file
            if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                // Give more time for large files
                if (($current_time - $heartbeat) < $this->large_file_timeout) {
                    $this->log_message('Processing large file, extending timeout');
                    
                    // Update status message to inform user about large file
                    $status['message'] = sprintf(
                        __('Processing large file, please wait... (%s)', 'swiftspeed-siberian'),
                        isset($status['current_file']) ? basename($status['current_file']) : ''
                    );
                } else {
                    // Even large files shouldn't take more than the extended timeout
                    if ($status['status'] !== 'error') {
                        $status['status'] = 'error';
                        $status['message'] = __('Backup process appears to be stalled while processing large file. Please try again.', 'swiftspeed-siberian');
                        update_option('swsib_current_backup', $status);
                    }
                }
            } else {
                if ($status['status'] !== 'error') {
                    // Update status to error
                    $status['status'] = 'error';
                    $status['message'] = __('Backup process appears to be stalled. Please try again.', 'swiftspeed-siberian');
                    update_option('swsib_current_backup', $status);
                }
            }
        }
        
        // Add elapsed time
        if (isset($status['started'])) {
            $status['elapsed_time'] = time() - $status['started'];
        }
        
        // Add time elapsed since high-precision start time if available
        if (isset($status['start_time'])) {
            $status['time_elapsed'] = microtime(true) - $status['start_time'];
        }
        
        // Enhance progress display for each backup type
        $this->enhance_backup_progress_data($status);
        
        // Update heartbeat if backup is active
        if ($status['status'] !== 'completed' && $status['status'] !== 'error') {
            $this->update_backup_heartbeat();
        }
        
        // Trigger a background process if not completed
        if ($status['status'] !== 'completed' && $status['status'] !== 'error') {
            do_action('swsib_process_background_backup');
        }
        
        wp_send_json_success($status);
    }
    
    /**
     * Enhance backup progress data for better UI display.
     */
    private function enhance_backup_progress_data(&$status) {
        // For file backups, ensure the correct progress calculation
        if ($status['backup_type'] === 'file') {
            if (isset($status['current_file_index']) && isset($status['total_files']) && $status['total_files'] > 0) {
                // Override progress to ensure it's calculated correctly
                $status['progress'] = min(95, ($status['current_file_index'] / $status['total_files']) * 100);
                
                // Ensure there's a current file listed (take the last one from processed_files if needed)
                if (empty($status['current_file']) && !empty($status['processed_files'])) {
                    $last_file = end($status['processed_files']);
                    if (isset($last_file['path'])) {
                        $status['current_file'] = $last_file['path'];
                    }
                }
                
                // Make sure the message includes file progress information
                if (!isset($status['message']) || empty($status['message'])) {
                    $status['message'] = sprintf(
                        __('Processing files... %d of %d (%.2f%%)', 'swiftspeed-siberian'),
                        $status['current_file_index'],
                        $status['total_files'],
                        $status['progress']
                    );
                }
                
                // Add large file processing status
                if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                    $status['message'] .= ' - ' . __('Processing large file', 'swiftspeed-siberian');
                    if (isset($status['current_file_progress'])) {
                        $status['message'] .= ' (' . round($status['current_file_progress']) . '%)';
                    }
                }
            }
        }
        
        // For full backups, ensure proper phase reporting
        if ($status['backup_type'] === 'full') {
            // Handle phases for full backup
            if (isset($status['current_phase'])) {
                if ($status['current_phase'] === 'db' && isset($status['total_tables'])) {
                    // Make sure db stats are showing
                    if (isset($status['db_status']) && !isset($status['processed_tables'])) {
                        $status['processed_tables'] = isset($status['db_status']['processed_tables']) ? 
                            $status['db_status']['processed_tables'] : 0;
                        $status['total_tables'] = isset($status['db_status']['total_tables']) ? 
                            $status['db_status']['total_tables'] : 0;
                        $status['current_table'] = isset($status['db_status']['current_table']) ? 
                            $status['db_status']['current_table'] : '';
                        
                        // Add DB size information
                        if (isset($status['db_status']['db_size'])) {
                            $status['db_size'] = $status['db_status']['db_size'];
                        }
                    }
                } elseif ($status['current_phase'] === 'files' && isset($status['file_status'])) {
                    // Make sure file stats are showing 
                    if (!isset($status['current_file']) && isset($status['file_status']['current_file'])) {
                        $status['current_file'] = $status['file_status']['current_file'];
                        $status['current_file_index'] = isset($status['file_status']['current_file_index']) ? 
                            $status['file_status']['current_file_index'] : 0;
                        $status['total_files'] = isset($status['file_status']['total_files']) ? 
                            $status['file_status']['total_files'] : 0;
                        
                        // Add file size information
                        if (isset($status['file_status']['total_size'])) {
                            $status['files_size'] = $status['file_status']['total_size'];
                        }
                    }
                }
            }
            
            // Calculate total size from components
            if (!isset($status['total_size']) && (isset($status['db_size']) || isset($status['files_size']))) {
                $status['total_size'] = (isset($status['db_size']) ? $status['db_size'] : 0) + 
                                       (isset($status['files_size']) ? $status['files_size'] : 0);
            }
        }
        
        // For DB backups, ensure table information is shown
        if ($status['backup_type'] === 'db') {
            if (isset($status['current_table']) && !empty($status['current_table'])) {
                if (!isset($status['message']) || empty($status['message'])) {
                    $table_progress = isset($status['processed_tables']) && isset($status['total_tables']) ? 
                        " (" . $status['processed_tables'] . " of " . $status['total_tables'] . ")" : "";
                    
                    $status['message'] = sprintf(
                        __('Processing table: %s%s', 'swiftspeed-siberian'),
                        $status['current_table'],
                        $table_progress
                    );
                }
            }
            
            // Add DB size to the message if available
            if (isset($status['db_size']) && $status['db_size'] > 0) {
                $size_text = size_format($status['db_size'], 2);
                if (!strpos($status['message'], $size_text)) {
                    $status['message'] .= ' (' . $size_text . ')';
                }
            }
        }
        
        // Add memory usage info for all backup types
        if (function_exists('memory_get_usage') && !strpos($status['message'], 'Memory:')) {
            $memory_usage = memory_get_usage(true);
            $memory_limit = $this->get_memory_limit();
            $memory_percentage = round(($memory_usage / $memory_limit) * 100);
            
            if ($memory_percentage > 50) {
                $status['message'] .= sprintf(' (Memory: %s%%)', $memory_percentage);
            }
        }
    }
    
    /**
     * Get memory limit in bytes
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
        }
        
        if ($value <= 0) { // No limit
            return 2147483648; // Return 2GB as a reasonable assumption
        }
        
        return $value;
    }
    
    /**
     * AJAX handler for processing the next backup step.
     */
    public function ajax_process_next_backup_step() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current backup status
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup found', 'swiftspeed-siberian')));
        }
        
        // Disable background processing during manual steps
        $this->update_background_flag(false);
        
        // Process next step based on backup type
        if ($status['backup_type'] === 'full') {
            $result = $this->full_backup->process_next($status);
        } elseif ($status['backup_type'] === 'file') {
            $result = $this->file_backup->process_next($status);
        } else {
            // DB backup - use full backup with db-only flag
            $result = $this->full_backup->process_next($status);
        }
        
        if (is_wp_error($result)) {
            $this->log_message('Error processing backup step: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Add elapsed time
        if (isset($result['started'])) {
            $result['elapsed_time'] = time() - $result['started'];
        }
        
        // If the backup is complete or has an error, don't enable background processing
        if ($result['status'] !== 'completed' && $result['status'] !== 'error') {
            // Re-enable background processing after processing manually
            $this->update_background_flag(true);
        } else if ($result['status'] === 'completed' && isset($result['id'])) {
            // Make sure scheduled flag is preserved for manually processed backups that complete
            $this->ensure_scheduled_flag_in_history($result['id'], !empty($result['params']['scheduled']));
        }
        
        // Update heartbeat
        $this->update_backup_heartbeat();
        
        wp_send_json_success($result);
    }
    
    /**
     * Ensure the scheduled flag is correctly set in backup history
     * 
     * @param string $backup_id Backup ID
     * @param bool $is_scheduled Whether the backup was scheduled
     */
    private function ensure_scheduled_flag_in_history($backup_id, $is_scheduled) {
        $history = get_option('swsib_backup_history', array());
        
        if (isset($history[$backup_id])) {
            // Check if the scheduled flag needs updating
            if ((!isset($history[$backup_id]['scheduled']) && $is_scheduled) || 
                (isset($history[$backup_id]['scheduled']) && $history[$backup_id]['scheduled'] !== $is_scheduled)) {
                
                $this->log_message('Updating scheduled flag in history for backup: ' . $backup_id . ' to ' . ($is_scheduled ? 'true' : 'false'));
                $history[$backup_id]['scheduled'] = $is_scheduled;
                update_option('swsib_backup_history', $history);
            }
            
            // Also ensure storage providers are correctly set
            if (isset($history[$backup_id]['storage_providers']) && is_array($history[$backup_id]['storage_providers'])) {
                // Make sure uploaded_to field is correctly set for UI display
                if (!isset($history[$backup_id]['uploaded_to']) || !is_array($history[$backup_id]['uploaded_to'])) {
                    $history[$backup_id]['uploaded_to'] = $history[$backup_id]['storage_providers'];
                    update_option('swsib_backup_history', $history);
                }
            }
        }
    }
    
    /**
     * Process backup in background via WP Cron with enhanced reliability.
     */
    public function process_background_backup() {
        // Set a longer timeout and increase memory limit
        @set_time_limit($this->step_time_limit);
        @ini_set('memory_limit', '2048M');
        
        // Check if background processing is enabled
        if (!$this->get_background_flag()) {
            $this->log_message('Background processing flag not set, exiting');
            return;
        }
        
        // Get current backup status
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status) || $status['status'] === 'completed' || $status['status'] === 'error') {
            // No active backup or backup already completed/failed
            $this->update_background_flag(false);
            $this->log_message('No active backup or backup already completed/failed, disabling background processing');
            return;
        }
        
        // Check if another backup process might be running (lock mechanism)
        $lock = get_option('swsib_backup_process_lock', 0);
        $current_time = time();
        
        if ($lock > 0 && ($current_time - $lock) < 30) {
            // Another process might be running (locked less than 30 seconds ago)
            $this->log_message('Another backup process appears to be running (lock time: ' . date('Y-m-d H:i:s', $lock) . '), skipping this run');
            return;
        }
        
        // Set the lock
        update_option('swsib_backup_process_lock', $current_time);
        
        // Determine appropriate timeout threshold
        $timeout_threshold = $this->stall_timeout;
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $timeout_threshold = $this->large_file_timeout;
        }
        
        // Check if the backup has stalled
        $heartbeat = get_option('swsib_backup_heartbeat', 0);
        
        if ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold) {
            // Check if processing a large file
            if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                // Give more time for large files
                if (($current_time - $heartbeat) < $this->large_file_timeout) {
                    $this->log_message('Processing large file, extending timeout');
                } else {
                    // Even large files shouldn't take more than the extended timeout
                    $this->log_message('Background processing: Backup stalled while processing large file');
                    
                    if ($status['status'] !== 'error') {
                        $status['status'] = 'error';
                        $status['message'] = __('Backup process stalled while processing large file. Please try again.', 'swiftspeed-siberian');
                        update_option('swsib_current_backup', $status);
                    }
                    
                    $this->update_background_flag(false);
                    delete_option('swsib_backup_process_lock');
                    return;
                }
            } else {
                // Backup appears stalled
                $this->log_message('Background processing: Backup appears stalled - last heartbeat: ' . date('Y-m-d H:i:s', $heartbeat));
                
                if ($status['status'] !== 'error') {
                    // Update status to error
                    $status['status'] = 'error';
                    $status['message'] = __('Backup process appears to be stalled. Please try again.', 'swiftspeed-siberian');
                    update_option('swsib_current_backup', $status);
                }
                
                $this->update_background_flag(false);
                delete_option('swsib_backup_process_lock');
                return;
            }
        }
        
        // Store the original status to check for progress later
        $original_progress = isset($status['progress']) ? $status['progress'] : 0;
        $original_files_backed_up = isset($status['files_backed_up']) ? $status['files_backed_up'] : 0;
        $original_tables_processed = isset($status['processed_tables']) ? $status['processed_tables'] : 0;
        
        // For large file sites, process fewer steps at once
        $max_steps = $this->max_steps;
        
        // Check if we're processing large files and adjust accordingly
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $max_steps = 1; // Only 1 step for large files
            $this->log_message('Processing large file, limiting to 1 step');
        } else if (isset($status['large_files_count']) && $status['large_files_count'] > 0) {
            $max_steps = min(2, $max_steps); // Limit to 2 steps if there are large files
            $this->log_message('Large files present, limiting to max 2 steps');
        }
        
        // Process multiple steps in a single cron run to make significant progress
        $step_count = 0;
        $continue_processing = true;
        $last_result = $status;
        
        $this->log_message('Running background processing batch (max ' . $max_steps . ' steps)');
        
        while ($continue_processing && $step_count < $max_steps) {
            // Process next step based on backup type
            $result = null;
            
            try {
                if ($last_result['backup_type'] === 'full') {
                    $result = $this->full_backup->process_next($last_result);
                } elseif ($last_result['backup_type'] === 'file') {
                    $result = $this->file_backup->process_next($last_result);
                } else {
                    // DB backup - use full backup with db-only flag
                    $result = $this->full_backup->process_next($last_result);
                }
            } catch (Exception $e) {
                $this->log_message('Exception in background processing: ' . $e->getMessage());
                $result = new WP_Error('background_exception', $e->getMessage());
            }
            
            if (is_wp_error($result)) {
                $this->log_message('Error in background processing: ' . $result->get_error_message());
                
                // Update status to show error
                $status['status'] = 'error';
                $status['message'] = $result->get_error_message();
                update_option('swsib_current_backup', $status);
                
                // Disable background processing
                $this->update_background_flag(false);
                delete_option('swsib_backup_process_lock');
                return;
            }
            
            // Update the backup status
            update_option('swsib_current_backup', $result);
            $last_result = $result;
            
            // If completed or error, stop processing
            if ($result['status'] === 'completed' || $result['status'] === 'error') {
                $this->log_message('Background processing complete with status: ' . $result['status']);
                $this->update_background_flag(false);
                $continue_processing = false;

                // Make sure scheduled flag is preserved in history for completed backups
                if ($result['status'] === 'completed' && isset($result['id'])) {
                    $this->ensure_scheduled_flag_in_history($result['id'], !empty($result['params']['scheduled']));
                }
            }
            
            // Update the heartbeat
            $this->update_backup_heartbeat();
            
            // Increment the step counter
            $step_count++;
            
            // Check if we're running low on resources
            if ($step_count % 2 === 0) {
                $memory_usage = memory_get_usage(true);
                $memory_limit = $this->get_memory_limit();
                
                // If using more than 75% of available memory, stop this batch
                if ($memory_usage > ($memory_limit * 0.75)) {
                    $this->log_message('Memory usage high (' . size_format($memory_usage, 2) . ' of ' . 
                        size_format($memory_limit, 2) . '), stopping batch after ' . $step_count . ' steps');
                    $continue_processing = false;
                }
            }
            
            // If processing large file, always break after one step
            if (isset($result['is_processing_large_file']) && $result['is_processing_large_file']) {
                $this->log_message('Large file processing in progress, breaking batch');
                $continue_processing = false;
            }
        }
        
        // Check if we made progress
        $new_progress = isset($last_result['progress']) ? $last_result['progress'] : 0;
        $new_files_backed_up = isset($last_result['files_backed_up']) ? $last_result['files_backed_up'] : 0;
        $new_tables_processed = isset($last_result['processed_tables']) ? $last_result['processed_tables'] : 0;
        
        // Track progress for various types of backups
        $progress_details = [];
        
        if ($new_progress != $original_progress) {
            $progress_details[] = sprintf('Progress: %.1f%% -> %.1f%%', $original_progress, $new_progress);
        }
        
        if ($new_files_backed_up != $original_files_backed_up) {
            $progress_details[] = sprintf('Files: %d -> %d', $original_files_backed_up, $new_files_backed_up);
        }
        
        if ($new_tables_processed != $original_tables_processed) {
            $progress_details[] = sprintf('Tables: %d -> %d', $original_tables_processed, $new_tables_processed);
        }
        
        if (!empty($progress_details)) {
            $this->log_message('Background progress after ' . $step_count . ' steps: ' . implode(', ', $progress_details));
        } else {
            $this->log_message('Background processing ran ' . $step_count . ' steps but made no apparent progress');
        }
        
        // Need to continue processing?
        $needs_more_processing = $continue_processing && $this->get_background_flag();
        
        // Clean up
        delete_option('swsib_backup_process_lock');
        
        // If we need to continue, trigger a loopback request to keep processing
        if ($needs_more_processing) {
            // Schedule the next run
            wp_schedule_single_event(time() + 5, 'swsib_process_background_backup');
            
            // Also trigger a loopback request for immediate continuation (disabled for large files)
            if (!isset($last_result['is_processing_large_file']) || !$last_result['is_processing_large_file']) {
                $this->trigger_loopback_request();
            }
            
            $this->log_message('Scheduled next background processing run');
        }
    }
    
    /**
     * Trigger a loopback request to continue processing.
     */
    private function trigger_loopback_request() {
        // Create a unique nonce for security
        $nonce = wp_create_nonce('swsib_loopback_' . time());
        
        // Create the loopback URL with the core wp-cron.php
        $url = site_url('wp-cron.php?doing_wp_cron=' . microtime(true) . '&swsib_nonce=' . $nonce);
        
        // Send a non-blocking request to the server
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array(
                'Cache-Control' => 'no-cache',
            ),
        );
        
        // Log the attempt
        $this->log_message('Triggering loopback request to continue processing');
        
        // Send the request
        wp_remote_get($url, $args);
    }
    
    /**
     * AJAX handler for background pings.
     * Helps keep the background process running.
     * 
     * @return void
     */
    public function ajax_ping_background() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_ping_background')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        // Check if background processing is active
        $is_active = $this->get_background_flag();
        
        if ($is_active) {
            // Trigger the background process
            do_action('swsib_process_background_backup');
        }
        
        wp_send_json_success(array(
            'active' => $is_active,
            'time' => current_time('mysql')
        ));
    }
    
    /**
     * AJAX handler for force checking backup progress.
     * Used by external cron jobs.
     */
    public function ajax_force_check_backup() {
        // Basic security check - require a key parameter
        $expected_key = md5('swsib_force_check_backup');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            $this->log_message('Force check backup request received');
            $this->check_background_backup();
            wp_die('Backup check completed');
        } else {
            wp_die('Invalid security key');
        }
    }
    
    /**
     * AJAX handler for triggering scheduled backups.
     * Used by external cron jobs.
     */
    public function ajax_trigger_scheduled_backup() {
        // Basic security check - require a key parameter
        $expected_key = md5('swsib_trigger_scheduled_backup');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            $this->log_message('External cron request to run scheduled backup');
            
            // Check if scheduled backups are enabled
            $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
            
            if (empty($backup_settings['scheduled_enabled'])) {
                wp_die('Scheduled backups are disabled');
            }
            
            // Run the scheduled backup
            $result = $this->run_scheduled_backup();
            
            if (is_wp_error($result)) {
                wp_die('Error starting scheduled backup: ' . $result->get_error_message());
            } else {
                wp_die('Scheduled backup triggered successfully');
            }
        } else {
            wp_die('Invalid security key');
        }
    }
    
    /**
     * AJAX handler for canceling a backup.
     */
    public function ajax_cancel_backup() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current backup status
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup to cancel', 'swiftspeed-siberian')));
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        // Disable background processing
        $this->update_background_flag(false);
        
        // Clear heartbeat
        delete_option('swsib_backup_heartbeat');
        
        // Clear process lock
        delete_option('swsib_backup_process_lock');
        
        // Cancel backup based on type
        if ($status['backup_type'] === 'full') {
            $result = $this->full_backup->cancel_backup($status);
        } elseif ($status['backup_type'] === 'file') {
            $result = $this->file_backup->cancel_backup($status);
        } else {
            // DB backup - use one of the above
            $result = $this->full_backup->cancel_backup($status);
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        // Remove the current backup status
        delete_option('swsib_current_backup');
        
        wp_send_json_success(array('message' => __('Backup canceled successfully', 'swiftspeed-siberian')));
    }
    
    /**
     * AJAX handler for getting backup history.
     */
    public function ajax_get_backup_history() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $history = get_option('swsib_backup_history', array());
        
        // Get remote backups too
        $remote_backups = $this->storage_manager->get_all_backups();
        
        // Format into usable data
        $formatted_backups = array();
        
        foreach ($history as $id => $backup) {
            // Format storage names - handle both old and new format
            $storage_name = '';
            
            if (isset($backup['uploaded_to']) && is_array($backup['uploaded_to']) && count($backup['uploaded_to']) > 1) {
                // New format with multiple storage providers
                $storage_names = array();
                foreach ($backup['uploaded_to'] as $provider_id) {
                    $storage_names[] = $this->get_storage_display_name($provider_id);
                }
                $storage_name = implode(', ', $storage_names);
            } else {
                // Old format or single storage
                $storage_name = $this->get_storage_display_name($backup['storage']);
            }
            
            // Ensure scheduled flag is always set
            if (!isset($backup['scheduled'])) {
                $backup['scheduled'] = false;
                // Update the history with this flag
                $history[$id]['scheduled'] = false;
                update_option('swsib_backup_history', $history);
            }
            
            $formatted_backups[$id] = array(
                'id' => $id,
                'file' => $backup['file'],
                'backup_type' => $backup['backup_type'],
                'storage' => $backup['storage'],
                'storage_info' => isset($backup['storage_info']) ? $backup['storage_info'] : array(),
                'size' => $backup['size'],
                'created' => $backup['created'],
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $backup['created']),
                'locked' => !empty($backup['locked']),
                'storage_name' => $storage_name,
                'uploaded_to' => isset($backup['uploaded_to']) ? $backup['uploaded_to'] : array($backup['storage']),
                'all_storage_info' => isset($backup['all_storage_info']) ? $backup['all_storage_info'] : array(),
                'scheduled' => !empty($backup['scheduled']), // Ensure boolean value
            );
        }
        
        wp_send_json_success(array(
            'history' => $formatted_backups,
            'remote_backups' => $remote_backups,
        ));
    }
    
    /**
     * AJAX handler for deleting a backup.
     */
    public function ajax_delete_backup() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get backup ID
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $provider_id = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'all';
        
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => __('No backup ID provided', 'swiftspeed-siberian')));
        }
        
        // Get backup history
        $history = get_option('swsib_backup_history', []);
        
        // Get the latest backup list from all storage providers
        $all_backups = $this->storage_manager->get_all_backups();
        
        // Check if backup exists in the aggregated backups list
        if (!isset($all_backups[$backup_id])) {
            wp_send_json_error(array('message' => __('Backup not found', 'swiftspeed-siberian')));
        }
        
        $backup = $all_backups[$backup_id];
        
        // Check if backup is locked
        if (!empty($backup['locked'])) {
            wp_send_json_error(array('message' => __('Cannot delete locked backup', 'swiftspeed-siberian')));
        }
        
        $this->log_message('Deleting backup: ' . $backup_id . ' (' . $backup['file'] . ')');
        $filename = $backup['file'];
        
        // Delete from all storage providers or just the specified one
        $storages_to_delete = $provider_id === 'all' ? $backup['storages'] : [$provider_id];
        $delete_results = [];
        
        foreach ($storages_to_delete as $storage) {
            $this->log_message('Attempting to delete from storage: ' . $storage);
            
            if ($storage === 'local') {
                // Delete from local filesystem
                $path = isset($backup['providers']['local']['path']) ? $backup['providers']['local']['path'] : '';
                
                if (!empty($path) && file_exists($path)) {
                    if (unlink($path)) {
                        $this->log_message('Successfully deleted local file: ' . $path);
                        $delete_results[$storage] = true;
                    } else {
                        $this->log_message('Failed to delete local file: ' . $path);
                        $delete_results[$storage] = false;
                    }
                } else {
                    $this->log_message('Local file not found: ' . $path);
                    $delete_results[$storage] = false;
                }
            } else {
                // Delete from external storage
                $provider = $this->storage_manager->get_provider($storage);
                
                if ($provider && $provider->is_configured()) {
                    $provider->initialize();
                    
                    $file_path = '';
                    if (isset($backup['providers'][$storage]['file_id'])) {
                        $file_path = $backup['providers'][$storage]['file_id'];
                    } elseif (isset($backup['providers'][$storage]['path'])) {
                        $file_path = $backup['providers'][$storage]['path'];
                    } else {
                        $file_path = $filename;
                    }
                    
                    if (!empty($file_path)) {
                        $result = $provider->delete_file($file_path);
                        
                        if (is_wp_error($result)) {
                            $this->log_message('Failed to delete from ' . $storage . ': ' . $result->get_error_message());
                            $delete_results[$storage] = false;
                        } else {
                            $this->log_message('Successfully deleted from ' . $storage);
                            $delete_results[$storage] = true;
                        }
                    }
                } else {
                    $this->log_message('Provider not available or not configured: ' . $storage);
                    $delete_results[$storage] = false;
                }
            }
        }
        
        // Update history if needed
        if ($provider_id === 'all' || count($delete_results) === count($backup['storages'])) {
            // Remove from history completely
            $found_in_history = false;
            
            foreach ($history as $history_id => $history_item) {
                if ($history_item['file'] === $filename) {
                    unset($history[$history_id]);
                    $found_in_history = true;
                    $this->log_message('Removed backup from history: ' . $history_id);
                }
            }
            
            if ($found_in_history) {
                update_option('swsib_backup_history', $history);
            }
        } else {
            // Just update the history to remove the specific storage provider
            foreach ($history as $history_id => $history_item) {
                if ($history_item['file'] === $filename) {
                    if (isset($history_item['uploaded_to']) && is_array($history_item['uploaded_to'])) {
                        $history_item['uploaded_to'] = array_diff($history_item['uploaded_to'], [$provider_id]);
                        
                        if (!empty($history_item['uploaded_to'])) {
                            $history[$history_id] = $history_item;
                            $this->log_message('Updated storage providers for backup in history: ' . $history_id);
                        } else {
                            unset($history[$history_id]);
                            $this->log_message('Removed backup from history after removing last storage provider: ' . $history_id);
                        }
                    }
                }
            }
            
            update_option('swsib_backup_history', $history);
        }
        
        // Check if all deletions were successful
        $all_successful = !in_array(false, $delete_results);
        
        if ($all_successful) {
            wp_send_json_success(array('message' => __('Backup deleted successfully', 'swiftspeed-siberian')));
        } else {
            wp_send_json_success(array(
                'message' => __('Backup partially deleted. Some storage locations could not be deleted.', 'swiftspeed-siberian'),
                'partial' => true,
                'results' => $delete_results
            ));
        }
    }
    
    /**
     * AJAX handler for locking/unlocking a backup.
     */
    public function ajax_lock_backup() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get backup ID and locked state
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $locked = isset($_POST['locked']) ? (bool) $_POST['locked'] : false;
        
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => __('No backup ID provided', 'swiftspeed-siberian')));
        }
        
        // Get backup history
        $history = get_option('swsib_backup_history', []);
        $all_backups = $this->storage_manager->get_all_backups();
        
        if (!isset($all_backups[$backup_id])) {
            wp_send_json_error(array('message' => __('Backup not found', 'swiftspeed-siberian')));
        }
        
        $backup = $all_backups[$backup_id];
        $filename = $backup['file'];
        $updated = false;
        
        // Find and update all entries in history with matching filename
        foreach ($history as $id => $history_item) {
            if ($history_item['file'] === $filename) {
                // Toggle locked state
                $history[$id]['locked'] = $locked;
                $updated = true;
                $this->log_message('Updated lock status to ' . ($locked ? 'locked' : 'unlocked') . ' for backup: ' . $id);
            }
        }
        
        if ($updated) {
            update_option('swsib_backup_history', $history);
            
            wp_send_json_success(array(
                'message' => $locked 
                    ? __('Backup locked successfully', 'swiftspeed-siberian') 
                    : __('Backup unlocked successfully', 'swiftspeed-siberian'),
                'locked' => $locked
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to update backup lock status', 'swiftspeed-siberian')));
        }
    }
    
/**
 * AJAX handler for downloading a backup.
 */
public function ajax_download_backup() {
    // Check nonce
    if (
        !isset($_GET['backup_download_nonce']) || 
        !wp_verify_nonce($_GET['backup_download_nonce'], 'swsib_backup_nonce')
    ) {
        wp_die(__('Security check failed', 'swiftspeed-siberian'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied', 'swiftspeed-siberian'));
    }
    
    // Get backup ID
    $backup_id = isset($_GET['backup_id']) ? sanitize_text_field($_GET['backup_id']) : '';
    
    if (empty($backup_id)) {
        wp_die(__('No backup ID provided', 'swiftspeed-siberian'));
    }
    
    // Get backup history
    $history = get_option('swsib_backup_history', array());
    
    if (!isset($history[$backup_id])) {
        wp_die(__('Backup not found in history', 'swiftspeed-siberian'));
    }
    
    $backup = $history[$backup_id];
    $this->log_message('Downloading backup: ' . $backup_id . ' (' . $backup['file'] . ')');
    
    // Override provider from request
    $provider = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';
    if (!empty($provider) && $provider !== 'local' && $provider !== $backup['storage']) {
        $backup['storage'] = $provider;
        $this->log_message('Using requested provider: ' . $provider . ' instead of ' . $backup['storage']);
    }
    
    // Handle download based on storage type
    if ($backup['storage'] === 'local') {
        // Check if path exists and is valid
        if (empty($backup['path']) || !file_exists($backup['path'])) {
            $this->log_message('Backup file not found at path: ' . $backup['path']);
            
            // Try to find the file in the backup directory
            $alt_path = $this->backup_dir . $backup['file'];
            if (file_exists($alt_path)) {
                $this->log_message('Found backup at alternate path: ' . $alt_path);
                $backup['path'] = $alt_path;
                
                // Update the history with the correct path
                $history[$backup_id]['path'] = $alt_path;
                update_option('swsib_backup_history', $history);
            } else {
                wp_die(__('Backup file not found', 'swiftspeed-siberian'));
            }
        }
        
        $file_path = $backup['path'];
        $file_name = basename($file_path);
        
        // Set headers and stream the file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Use readfile_chunked for better memory usage with large files
        $this->readfile_chunked($file_path);
        exit;
    } else {
        // External storage - first download to temp file
        $temp_file = $this->temp_dir . $backup['file'];
        $temp_dir = dirname($temp_file);
        
        if (!file_exists($temp_dir) && !wp_mkdir_p($temp_dir)) {
            $this->log_message('Failed to create temporary directory: ' . $temp_dir);
            wp_die(__('Failed to create temporary directory', 'swiftspeed-siberian'));
        }
        
        $provider = $this->storage_manager->get_provider($backup['storage']);
        
        if (!$provider || !$provider->is_configured()) {
            $this->log_message('Storage provider not configured: ' . $backup['storage']);
            wp_die(__('Storage provider not configured', 'swiftspeed-siberian'));
        }
        
        $provider->initialize();
        
        $file_path = !empty($backup['storage_info']['file']) 
            ? $backup['storage_info']['file'] 
            : $backup['file'];
            
        $result = $provider->download_file($file_path, $temp_file);
        
        if (is_wp_error($result)) {
            $this->log_message('Failed to download from storage: ' . $result->get_error_message());
            wp_die($result->get_error_message());
        }
        
        // Stream the temp file
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($temp_file) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($temp_file));
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Use chunked reading for better memory usage
        $this->readfile_chunked($temp_file);
        
        // Clean up
        @unlink($temp_file);
        exit;
    }
}
    
    /**
     * Read a file and output it in chunks to reduce memory usage.
     * 
     * @param string $filename The file to read
     * @param int $chunk_size Size of each chunk (default: 1MB)
     * @return bool Whether the file was successfully read
     */
    private function readfile_chunked($filename, $chunk_size = 1048576) {
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        
        while (!feof($handle)) {
            $buffer = fread($handle, $chunk_size);
            echo $buffer;
            
            // Force flush after each chunk for large files
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            
            // Free up memory
            $buffer = null;
            
            // Force garbage collection occasionally
            static $chunks_processed = 0;
            $chunks_processed++;
            if ($chunks_processed % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        fclose($handle);
        return true;
    }
    
 
    /**
     * Run a scheduled backup now.
     * Enhanced with improved reliability, support for multiple storage providers,
     * and automatic locking.
     * 
     * @param array $backup_settings The backup settings array
     * @return bool|WP_Error True on success or error object
     */
    public function run_scheduled_backup($backup_settings = null) {
        if (!is_array($backup_settings)) {
            $backup_settings = array();
        }
        
        $this->log_message('Running scheduled backup with settings: ' . json_encode($backup_settings));
        
        // Get backup type
        $backup_type = isset($backup_settings['backup_type']) ? $backup_settings['backup_type'] : 
                   (isset($backup_settings['scheduled_type']) ? $backup_settings['scheduled_type'] : 'full');
        
        // Get storage providers - handle array of storage locations
        $storage_providers = isset($backup_settings['storage_providers']) ? (array)$backup_settings['storage_providers'] : 
                         (isset($backup_settings['scheduled_storages']) ? (array)$backup_settings['scheduled_storages'] : array('local'));
        
        // Ensure local storage is always included
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        // Sanitize storage providers
        $storage_providers = array_map('sanitize_key', $storage_providers);
        
        // Use the first storage provider as the primary
        $primary_storage = $storage_providers[0];
        
        // Ensure all storage providers are configured
        $all_providers_configured = true;
        foreach ($storage_providers as $provider_id) {
            if ($provider_id !== 'local') {
                $provider = $this->storage_manager->get_provider($provider_id);
                if (!$provider || !$provider->is_configured()) {
                    $this->log_message('Storage provider not configured: ' . $provider_id);
                    $all_providers_configured = false;
                    break;
                }
            }
        }
        
        if (!$all_providers_configured) {
            return new WP_Error('storage_not_configured', __('One or more storage providers are not configured', 'swiftspeed-siberian'));
        }
        
        // Set up backup parameters
        $params = array(
            'storage' => $primary_storage,
            'storage_providers' => $storage_providers,
            'include_paths' => array(),
            'exclude_paths' => array(),
            'scheduled' => true, // Flag to indicate this is a scheduled backup
            'allow_background' => true, // Enable background processing
            'max_steps' => $this->max_steps, // Pass max_steps to backup component
            // Add schedule identifier if available
            'schedule_id' => isset($backup_settings['schedule_id']) ? $backup_settings['schedule_id'] : null,
            'schedule_name' => isset($backup_settings['schedule_name']) ? $backup_settings['schedule_name'] : null,
            // Add auto-lock setting
            'auto_lock' => !empty($backup_settings['auto_lock']),
        );
        
        $this->log_message("Starting scheduled {$backup_type} backup to " . implode(', ', $storage_providers) . " storage" . 
                      (!empty($params['auto_lock']) ? " (with auto-lock enabled)" : ""));
        
        // Start the backup based on type
        if ($backup_type === 'full') {
            // For full backup, we need both DB and files
            $params['include_db'] = true;
            $params['include_files'] = true;
            $this->log_message('Starting full backup with both DB and files');
            $result = $this->full_backup->start_backup($params);
        } elseif ($backup_type === 'files') {
            // For files-only backup
            $this->log_message('Starting files-only backup');
            $result = $this->file_backup->start_backup($params);
        } else if ($backup_type === 'db') {
            // For DB-only backup, use full backup class with db-only flag
            $params['include_db'] = true;
            $params['include_files'] = false;
            $this->log_message('Starting DB-only backup');
            $result = $this->full_backup->start_backup($params);
        } else {
            $this->log_message('Invalid backup type: ' . $backup_type);
            return new WP_Error('invalid_backup_type', __('Invalid backup type', 'swiftspeed-siberian'));
        }
        
        if (is_wp_error($result)) {
            $this->log_message('Scheduled backup failed: ' . $result->get_error_message());
            return $result;
        }
        
        // Set the background flag and update heartbeat
        $this->update_background_flag(true);
        $this->update_backup_heartbeat();
        
        // Process a batch immediately in the current request
        $this->process_background_backup();
        
        $this->log_message('Scheduled backup started successfully');
        return $result;
    }

    /**
     * Cleanup old backups based on configured limits.
     *
     * @return void
     */
    public function cleanup_old_backups() {
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $history = get_option('swsib_backup_history', array());
        
        if (empty($history)) {
            return;
        }
        
        $this->log_message('Running scheduled cleanup of old backups');
        
        // Get max backup limits
        $max_db = isset($backup_settings['max_backups_db']) ? intval($backup_settings['max_backups_db']) : 10;
        $max_file = isset($backup_settings['max_backups_file']) ? intval($backup_settings['max_backups_file']) : 5;
        $max_full = isset($backup_settings['max_backups_full']) ? intval($backup_settings['max_backups_full']) : 3;
        
        // Group backups by type
        $db_backups = array();
        $file_backups = array();
        $full_backups = array();
        
        foreach ($history as $id => $backup) {
            if (!empty($backup['locked'])) {
                continue; // Skip locked backups
            }
            
            if ($backup['backup_type'] === 'db') {
                $db_backups[$id] = $backup;
            } elseif ($backup['backup_type'] === 'file') {
                $file_backups[$id] = $backup;
            } elseif ($backup['backup_type'] === 'full') {
                $full_backups[$id] = $backup;
            }
        }
        
        // Sort each group by creation date, oldest first
        $sort_fn = function($a, $b) {
            return $a['created'] - $b['created'];
        };
        
        uasort($db_backups, $sort_fn);
        uasort($file_backups, $sort_fn);
        uasort($full_backups, $sort_fn);
        
        // Remove excess backups
        $db_count = count($db_backups);
        $file_count = count($file_backups);
        $full_count = count($full_backups);
        
        $this->log_message("Backup counts before cleanup - DB: $db_count, File: $file_count, Full: $full_count");
        $this->log_message("Max limits - DB: $max_db, File: $max_file, Full: $max_full");
        
        $this->remove_excess_backups($db_backups, $db_count, $max_db);
        $this->remove_excess_backups($file_backups, $file_count, $max_file);
        $this->remove_excess_backups($full_backups, $full_count, $max_full);
    }
    
    /**
     * Remove excess backups of a specific type.
     * 
     * @param array $backups Array of backups
     * @param int $count Current count
     * @param int $max Maximum to keep
     */
    private function remove_excess_backups($backups, $count, $max) {
        if ($count <= $max) {
            return;
        }
        
        $to_remove = array_slice($backups, 0, $count - $max, true);
        $history = get_option('swsib_backup_history', array());
        
        foreach ($to_remove as $id => $backup) {
            $this->log_message("Removing excess backup: {$id} ({$backup['backup_type']})");
            
            // Delete the actual file(s)
            if (isset($backup['uploaded_to']) && is_array($backup['uploaded_to'])) {
                // Multi-storage backup - delete from all locations
                foreach ($backup['uploaded_to'] as $storage) {
                    if ($storage === 'local') {
                        if (!empty($backup['path']) && file_exists($backup['path'])) {
                            @unlink($backup['path']);
                        }
                    } else {
                        // Delete from external storage
                        $provider = $this->storage_manager->get_provider($storage);
                        
                        if ($provider && $provider->is_configured()) {
                            $provider->initialize();
                            
                            $file_path = '';
                            if (isset($backup['all_storage_info'][$storage]['file_id'])) {
                                $file_path = $backup['all_storage_info'][$storage]['file_id'];
                            } elseif (isset($backup['all_storage_info'][$storage]['path'])) {
                                $file_path = $backup['all_storage_info'][$storage]['path'];
                            } else {
                                $file_path = $backup['file'];
                            }
                            
                            $provider->delete_file($file_path);
                        }
                    }
                }
            } else {
                // Legacy single-storage backup
                if ($backup['storage'] === 'local') {
                    if (!empty($backup['path']) && file_exists($backup['path'])) {
                        @unlink($backup['path']);
                    }
                } else {
                    // Delete from external storage
                    $provider = $this->storage_manager->get_provider($backup['storage']);
                    
                    if ($provider && $provider->is_configured()) {
                        $provider->initialize();
                        
                        $file_path = !empty($backup['storage_info']['file']) 
                            ? $backup['storage_info']['file'] 
                            : $backup['file'];
                            
                        $provider->delete_file($file_path);
                    }
                }
            }
            
            // Remove from history
            unset($history[$id]);
        }
        
        update_option('swsib_backup_history', $history);
    }
    
    /**
     * Update the backup heartbeat timestamp.
     * 
     * @return void
     */
    private function update_backup_heartbeat() {
        update_option('swsib_backup_heartbeat', time());
    }
    
    /**
     * Update background processing flag.
     * 
     * @param bool $enabled Whether to enable background processing
     * @return void
     */
    private function update_background_flag($enabled) {
        $current_state = get_option('swsib_background_processing', false);
        
        // Only log if we're changing state
        if ($current_state !== $enabled) {
            $this->log_message('Background processing flag changed: ' . ($enabled ? 'enabled' : 'disabled'));
        }
        
        update_option('swsib_background_processing', $enabled);
        
        // If enabling background processing, schedule an immediate event and trigger loopback
        if ($enabled) {
            if (!wp_next_scheduled('swsib_process_background_backup')) {
                wp_schedule_single_event(time() + 5, 'swsib_process_background_backup');
            }
            $this->trigger_loopback_request();
        }
        
        // If disabling, clear any scheduled events
        if (!$enabled) {
            wp_clear_scheduled_hook('swsib_process_background_backup');
        }
    }
    
    /**
     * Get background processing flag.
     *
     * @return bool Whether background processing is enabled.
     */
    private function get_background_flag() {
        return (bool) get_option('swsib_background_processing', false);
    }
    
    /**
     * Get the display name of a storage provider.
     * 
     * @param string $storage_type Storage provider type
     * @return string Display name
     */
    private function get_storage_display_name($storage_type) {
        $display_names = [
            'local' => __('Local', 'swiftspeed-siberian'),
            'gdrive' => __('Google Drive', 'swiftspeed-siberian'),
            'gcs' => __('Google Cloud Storage', 'swiftspeed-siberian'),
            's3' => __('Amazon S3', 'swiftspeed-siberian'),
        ];
        
        return isset($display_names[$storage_type]) ? $display_names[$storage_type] : $storage_type;
    }
    
    /**
     * Log a message for debugging.
     *
     * @param string $message Message to log
     * @param bool $force Force logging even if not in debug mode
     */
    public function log_message($message, $force = false) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
}