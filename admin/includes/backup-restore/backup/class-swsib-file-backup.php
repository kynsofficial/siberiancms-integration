<?php
/**
 * File backup functionality for Siberian CMS.
 * OPTIMIZED STREAMING VERSION 4.1: Proper max_steps scaling with aggressive batching,
 * restored progress display, optimized for both speed and memory efficiency
 */
class SwiftSpeed_Siberian_File_Backup extends SwiftSpeed_Siberian_Base_Backup {
    /**
     * File Connect instance
     * 
     * @var SwiftSpeed_Siberian_File_Connect
     */
    private $file_connect;
    
    /**
     * Connection handler instance
     * 
     * @var SwiftSpeed_Siberian_File_Backup_Connections
     */
    private $connection_handler;
    
    /**
     * Maximum file size to include in backup (1GB by default)
     * 
     * @var int
     */
    private $max_file_size = 1073741824; // 1GB
    
    /**
     * Large file threshold (100MB) - process individually
     * 
     * @var int
     */
    private $large_file_threshold = 104857600; // 100MB
    
    /**
     * User speed setting (2-25)
     * 
     * @var int
     */
    private $max_steps = 5;
    
    /**
     * Dynamic batch limits based on max_steps
     * 
     * @var array
     */
    private $batch_limits = [];
    
    /**
     * Memory management (adjusted for aggressive batching)
     * 
     * @var array
     */
    private $memory_limits = [
        'critical_threshold' => 0.85,   // 85% of available memory
        'cleanup_interval' => 50,       // GC every 50 processed items (more aggressive batches need less frequent cleanup)
    ];

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();
        
        // Load required classes
        if (!class_exists('SwiftSpeed_Siberian_File_Connect')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/connect/includes/fileconnect.php';
        }
        
        if (!class_exists('SwiftSpeed_Siberian_File_Backup_Connections')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-file-backup-connections.php';
        }
        
        $this->file_connect = new SwiftSpeed_Siberian_File_Connect();
        $this->connection_handler = new SwiftSpeed_Siberian_File_Backup_Connections();
        
        // Get user speed settings and calculate aggressive batch limits
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        $this->max_steps = max(2, min(25, $this->max_steps));
        
        // Calculate aggressive batch limits based on max_steps
        $this->calculate_batch_limits();
        
        // Allow filtering max file size
        $this->max_file_size = apply_filters('swsib_max_backup_file_size', $this->max_file_size);
        
        $this->log_message("File backup initialized with max_steps {$this->max_steps}, batch limits: " . json_encode($this->batch_limits));
    }

    /**
     * Calculate aggressive batch limits based on max_steps
     * 
     * @return void
     */
    private function calculate_batch_limits() {
        // Scale factor from 0 (slowest) to 1 (fastest)
        $scale = ($this->max_steps - 2) / 23;
        
        // Aggressive scaling for max_steps
        $this->batch_limits = [
            // Files per batch: 20 to 500 files
            'max_files_per_batch' => round(20 + ($scale * 480)),
            
            // Size per batch: 50MB to 500MB
            'max_size_per_batch' => round(52428800 + ($scale * 471859200)),
            
            // Directories per scan: 10 to 100 directories
            'max_dirs_per_scan' => round(10 + ($scale * 90)),
            
            // Checkpoint interval: 60s to 15s
            'checkpoint_interval' => round(60 - ($scale * 45)),
            
            // Files per progress update: 10 to 100
            'progress_update_interval' => round(10 + ($scale * 90)),
        ];
        
        // Ensure minimums for stability
        $this->batch_limits['max_files_per_batch'] = max(10, $this->batch_limits['max_files_per_batch']);
        $this->batch_limits['max_dirs_per_scan'] = max(5, $this->batch_limits['max_dirs_per_scan']);
        $this->batch_limits['checkpoint_interval'] = max(15, $this->batch_limits['checkpoint_interval']);
    }

    /**
     * Log a message.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'file_backup', $message);
        }
    }
    
    /**
     * Start the file backup process with streaming architecture.
     *
     * @param array $params Backup parameters.
     * @return array|WP_Error Backup status or error.
     */
    public function start_backup($params = []) {
        // Set memory limit based on max_steps (higher steps = more memory allowed)
        $memory_mb = round(256 + ($this->max_steps * 20)); // 256MB to 756MB
        @ini_set('memory_limit', $memory_mb . 'M');
        @set_time_limit(0);
        
        // Default parameters
        $default_params = [
            'storage' => 'local',
            'storage_providers' => ['local'],
            'include_paths' => [],
            'exclude_paths' => [],
            'temp_dir' => null,
            'full_backup' => false,
            'id' => null,
            'allow_background' => false,
            'scheduled' => false,
            'auto_lock' => false,
            'schedule_id' => null,
            'schedule_name' => null,
        ];
        
        $params = wp_parse_args($params, $default_params);
        
        // Auto-exclude system directories
        $auto_exclude_paths = [
            '/phpmyadmin', '/var/tmp', '/var/session', '/var/cache',
            '/var/cache_images', '/var/log', '/.usermin', '/logs'
        ];
        $params['exclude_paths'] = array_merge($params['exclude_paths'], $auto_exclude_paths);
        
        $this->log_message('Starting optimized file backup: ' . json_encode([
            'max_steps' => $this->max_steps,
            'batch_files' => $this->batch_limits['max_files_per_batch'],
            'batch_dirs' => $this->batch_limits['max_dirs_per_scan'],
            'memory_limit' => $memory_mb . 'M',
            'storage_providers' => $params['storage_providers'],
            'full_backup' => $params['full_backup'] ? 'yes' : 'no',
            'scheduled' => $params['scheduled'] ? 'yes' : 'no',
        ]));
        
        // Validate installation connection
        $installation_options = isset($this->options['installation']) ? $this->options['installation'] : [];
        if (empty($installation_options['is_configured'])) {
            return new WP_Error('backup_error', __('Installation connection is not configured', 'swiftspeed-siberian'));
        }
        
        // Setup backup directories
        $backup_id = !empty($params['id']) ? $params['id'] : 'siberian-backup-file-' . date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 8);
        $temp_dir = !empty($params['temp_dir']) ? $params['temp_dir'] : $this->temp_dir . $backup_id . '/';
        
        if (!file_exists($temp_dir) && !wp_mkdir_p($temp_dir)) {
            return new WP_Error('backup_error', __('Failed to create temporary directory', 'swiftspeed-siberian'));
        }
        
        $files_dir = $params['full_backup'] ? $temp_dir : $temp_dir . 'files/';
        if (!file_exists($files_dir) && !wp_mkdir_p($files_dir)) {
            return new WP_Error('backup_error', __('Failed to create files directory', 'swiftspeed-siberian'));
        }
        
        // Setup checkpoint directory for state persistence
        $checkpoint_dir = $temp_dir . 'checkpoints/';
        if (!file_exists($checkpoint_dir) && !wp_mkdir_p($checkpoint_dir)) {
            return new WP_Error('backup_error', __('Failed to create checkpoint directory', 'swiftspeed-siberian'));
        }
        
        // Get and validate connection configuration
        $connection_method = isset($installation_options['connection_method']) ? $installation_options['connection_method'] : 'ftp';
        $connection_config = isset($installation_options[$connection_method]) ? $installation_options[$connection_method] : [];
        
        $connection_test = $this->test_and_init_connection($connection_method, $connection_config);
        if (is_wp_error($connection_test)) {
            return $connection_test;
        }
        
        // Initialize directory scanning queue (file-based, not memory-based)
        $scan_queue_file = $checkpoint_dir . 'scan_queue.txt';
        $this->init_scan_queue($scan_queue_file, $params['include_paths'], $connection_config['path']);
        
        // Initialize backup status with proper progress tracking
        $status = [
            'id' => $backup_id,
            'temp_dir' => $temp_dir,
            'files_dir' => $files_dir,
            'checkpoint_dir' => $checkpoint_dir,
            'scan_queue_file' => $scan_queue_file,
            'backup_type' => 'file',
            'connection_method' => $connection_method,
            'connection_config' => $connection_config,
            'params' => $params,
            'started' => time(),
            'start_time' => microtime(true),
            'status' => 'processing',
            'phase' => 'directory_scan',
            'message' => __('Starting file discovery...', 'swiftspeed-siberian'),
            'progress' => 1,
            'current_dir' => '',
            'current_file' => '',
            'current_file_index' => 0,
            'allow_background' => $params['allow_background'],
            'exclude_paths' => $params['exclude_paths'],
            'last_checkpoint' => time(),
            'errors' => [],
            'bytes_per_second' => 0,
            'time_elapsed' => 0,
            
            // Progress tracking for UI compatibility
            'total_files' => 100,           // Estimated, updated during scan
            'files_backed_up' => 0,         // Files actually processed
            'dirs_backed_up' => 0,          // Directories processed  
            'total_size' => 0,              // Total bytes processed
            'failed_files' => [],           // Failed file list (lightweight)
            'processed_files' => [],        // Processed file list (lightweight)
            'processed_dirs' => [],         // Processed dirs list (lightweight)
            
            // Internal counters
            'dirs_scanned' => 0,
            'files_discovered' => 0,
            'excluded_count' => 0,
            'scan_completed' => false,
            'processing_completed' => false,
            'last_progress_update' => 0,
            
            // Batch tracking
            'max_steps' => $this->max_steps,
            'batch_limits' => $this->batch_limits,
        ];
        
        $this->update_status($status);
        $this->log_message('File backup initialized: ' . $backup_id);
        
        // Start processing immediately
        return $this->process_next($status);
    }
    
    /**
     * Test and initialize connection.
     *
     * @param string $method Connection method.
     * @param array $config Connection configuration.
     * @return bool|WP_Error True on success, error on failure.
     */
    private function test_and_init_connection($method, $config) {
        $this->log_message('Testing connection method: ' . $method);
        
        switch ($method) {
            case 'ftp':
                if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
                    return new WP_Error('backup_error', __('FTP connection configuration is incomplete', 'swiftspeed-siberian'));
                }
                
                $result = $this->connection_handler->test_ftp_connection($config);
                if (is_wp_error($result)) {
                    return $result;
                }
                
                $this->connection_handler->initialize_connection_pool($config);
                break;
                
            case 'sftp':
                if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
                    return new WP_Error('backup_error', __('SFTP connection configuration is incomplete', 'swiftspeed-siberian'));
                }
                
                $result = $this->connection_handler->test_sftp_connection($config);
                if (is_wp_error($result)) {
                    return $result;
                }
                
                $this->connection_handler->initialize_sftp_connection_pool($config);
                break;
                
            case 'local':
            default:
                if (empty($config['path']) || !file_exists($config['path'])) {
                    return new WP_Error('backup_error', __('Local path is not configured or does not exist', 'swiftspeed-siberian'));
                }
                break;
        }
        
        $this->log_message('Connection test successful');
        return true;
    }
    
    /**
     * Initialize scanning queue (file-based for memory efficiency).
     *
     * @param string $queue_file Queue file path.
     * @param array $include_paths Paths to include.
     * @param string $root_path Root installation path.
     * @return void
     */
    private function init_scan_queue($queue_file, $include_paths, $root_path) {
        $queue_data = [];
        
        // Use installation root if no include paths specified
        if (empty($include_paths)) {
            $include_paths = [$root_path];
        }
        
        foreach ($include_paths as $path) {
            $queue_data[] = [
                'path' => $path,
                'type' => 'dir',
                'depth' => 0,
                'relative_path' => $this->get_relative_path($root_path, $path)
            ];
        }
        
        file_put_contents($queue_file, json_encode($queue_data));
        $this->log_message('Initialized scan queue with ' . count($queue_data) . ' root directories');
    }
    
    /**
     * Process the next step in the file backup with optimized streaming.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    public function process_next($status) {
        if (empty($status) || $status['status'] === 'completed' || $status['status'] === 'error') {
            return $status;
        }
        
        // Set memory limit based on max_steps
        $memory_mb = round(256 + ($this->max_steps * 20));
        @ini_set('memory_limit', $memory_mb . 'M');
        @set_time_limit(300); // 5 minutes per step
        
        $start_time = microtime(true);
        $status['time_elapsed'] = $start_time - $status['start_time'];
        
        // Memory check
        if (!$this->check_memory_safety()) {
            $this->emergency_cleanup();
            if (!$this->check_memory_safety()) {
                $status['status'] = 'error';
                $status['message'] = __('Memory usage critical. Backup cannot continue safely.', 'swiftspeed-siberian');
                $this->update_status($status);
                return $status;
            }
        }
        
        // Process based on current phase
        switch ($status['phase']) {
            case 'directory_scan':
                return $this->process_directory_scanning($status);
                
            case 'file_processing':
                return $this->process_file_copying($status);
                
            case 'finalization':
                return $this->finalize_backup($status);
                
            default:
                $status['status'] = 'error';
                $status['message'] = __('Unknown backup phase', 'swiftspeed-siberian');
                $this->update_status($status);
                return $status;
        }
    }
    
    /**
     * Process directory scanning phase with aggressive batching.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function process_directory_scanning($status) {
        $scan_queue_file = $status['scan_queue_file'];
        $processed_queue_file = $status['checkpoint_dir'] . 'processed_queue.txt';
        $connection_config = $status['connection_config'];
        $connection_method = $status['connection_method'];
        
        // Load current scan queue
        if (!file_exists($scan_queue_file) || filesize($scan_queue_file) === 0) {
            // Scanning completed, move to file processing
            return $this->transition_to_file_processing($status);
        }
        
        $queue_data = json_decode(file_get_contents($scan_queue_file), true);
        if (empty($queue_data)) {
            return $this->transition_to_file_processing($status);
        }
        
        $new_queue_data = [];
        $files_found = [];
        $dirs_processed = 0;
        $max_dirs = $this->batch_limits['max_dirs_per_scan'];
        
        $this->log_message("Processing directory scan batch: max {$max_dirs} directories");
        
        // Process aggressive number of directories per step
        while (!empty($queue_data) && $dirs_processed < $max_dirs) {
            $item = array_shift($queue_data);
            $path = $item['path'];
            $depth = $item['depth'];
            $relative_path = $item['relative_path'];
            
            // Skip deep directories
            if ($depth > 12) {
                $this->log_message("Skipping deep directory: {$path}");
                continue;
            }
            
            // Check exclusions
            if ($this->is_path_excluded($path, $status['exclude_paths'])) {
                $status['excluded_count']++;
                continue;
            }
            
            $status['current_dir'] = $path;
            
            // Create directory in backup
            $local_dir = $status['files_dir'] . $relative_path;
            if (!file_exists($local_dir) && !wp_mkdir_p($local_dir)) {
                $status['errors'][] = "Failed to create directory: {$relative_path}";
                continue;
            }
            
            // Add to processed dirs list (lightweight)
            $status['processed_dirs'][] = [
                'path' => $path,
                'relative_path' => $relative_path,
                'type' => 'dir'
            ];
            
            // Get directory contents
            $contents = $this->get_directory_contents($connection_method, $connection_config, $path);
            if (is_wp_error($contents)) {
                $status['errors'][] = "Failed to read directory {$path}: " . $contents->get_error_message();
                continue;
            }
            
            // Process contents aggressively
            foreach ($contents as $content) {
                if ($content['name'] === '.' || $content['name'] === '..') {
                    continue;
                }
                
                $item_path = rtrim($path, '/') . '/' . $content['name'];
                $item_relative = $relative_path . '/' . $content['name'];
                
                if ($this->is_path_excluded($item_path, $status['exclude_paths'])) {
                    $status['excluded_count']++;
                    continue;
                }
                
                if ($content['type'] === 'dir') {
                    // Add subdirectory to queue
                    $new_queue_data[] = [
                        'path' => $item_path,
                        'type' => 'dir',
                        'depth' => $depth + 1,
                        'relative_path' => $item_relative
                    ];
                } else {
                    // Add file to processing queue
                    $file_size = isset($content['size']) ? $content['size'] : 0;
                    
                    // Skip oversized files
                    if ($file_size > $this->max_file_size) {
                        $status['excluded_count']++;
                        $status['errors'][] = "File too large, skipped: {$item_relative} (" . size_format($file_size) . ')';
                        continue;
                    }
                    
                    $files_found[] = [
                        'path' => $item_path,
                        'relative_path' => $item_relative,
                        'size' => $file_size,
                        'type' => 'file'
                    ];
                    
                    $status['total_size'] += $file_size;
                    $status['files_discovered']++;
                }
            }
            
            $status['dirs_backed_up']++;
            $status['dirs_scanned']++;
            $dirs_processed++;
            
            // Memory cleanup every batch of directories
            if ($dirs_processed % max(1, $max_dirs / 4) === 0) {
                $this->memory_cleanup();
            }
        }
        
        // Append discovered files to processed queue (batch write for efficiency)
        if (!empty($files_found)) {
            $existing_files = [];
            if (file_exists($processed_queue_file)) {
                $existing_content = file_get_contents($processed_queue_file);
                if (!empty($existing_content)) {
                    $existing_files = json_decode($existing_content, true) ?: [];
                }
            }
            
            $all_files = array_merge($existing_files, $files_found);
            file_put_contents($processed_queue_file, json_encode($all_files));
            
            $status['total_files'] = count($all_files);
            $this->log_message("Discovered " . count($files_found) . " files, total estimate: " . $status['total_files']);
        }
        
        // Update scan queue
        $combined_queue = array_merge($new_queue_data, $queue_data);
        if (empty($combined_queue)) {
            // Scanning completed
            @unlink($scan_queue_file);
            $status['scan_completed'] = true;
            $this->log_message("Directory scanning completed. Total files: " . $status['total_files'] . ", size: " . size_format($status['total_size']));
        } else {
            file_put_contents($scan_queue_file, json_encode($combined_queue));
        }
        
        // Calculate progress (scanning is 20% of total work)
        $remaining_dirs = count($combined_queue);
        $total_dir_work = $status['dirs_scanned'] + $remaining_dirs;
        $scan_progress = $total_dir_work > 0 ? ($status['dirs_scanned'] / $total_dir_work) * 20 : 20;
        $status['progress'] = min(20, $scan_progress);
        
        $status['message'] = sprintf(__('Scanning directories: %d processed, %d files found (%s)', 'swiftspeed-siberian'), 
                                   $status['dirs_scanned'], 
                                   $status['total_files'], 
                                   size_format($status['total_size']));
        
        // Checkpoint periodically
        if (time() - $status['last_checkpoint'] > $this->batch_limits['checkpoint_interval']) {
            $this->create_checkpoint($status);
            $status['last_checkpoint'] = time();
        }
        
        $this->update_status($status);
        return $status;
    }
    
    /**
     * Transition to file processing phase.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function transition_to_file_processing($status) {
        $status['phase'] = 'file_processing';
        $status['message'] = __('Starting file backup...', 'swiftspeed-siberian');
        $status['scan_completed'] = true;
        $status['progress'] = 20; // Scanning phase complete
        
        $this->log_message('Transitioning to file processing phase');
        $this->update_status($status);
        
        return $this->process_next($status);
    }
    
    /**
     * Process file copying phase with aggressive streaming.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function process_file_copying($status) {
        $processed_queue_file = $status['checkpoint_dir'] . 'processed_queue.txt';
        $connection_config = $status['connection_config'];
        $connection_method = $status['connection_method'];
        
        // Load files to process
        if (!file_exists($processed_queue_file)) {
            return $this->transition_to_finalization($status);
        }
        
        $all_files = json_decode(file_get_contents($processed_queue_file), true);
        if (empty($all_files)) {
            return $this->transition_to_finalization($status);
        }
        
        // Process files in aggressive batches
        $batch_count = 0;
        $batch_size = 0;
        $max_files = $this->batch_limits['max_files_per_batch'];
        $max_size = $this->batch_limits['max_size_per_batch'];
        
        $this->log_message("Processing file batch: max {$max_files} files or " . size_format($max_size));
        
        while (!empty($all_files) && 
               $batch_count < $max_files && 
               $batch_size < $max_size) {
            
            $file = array_shift($all_files);
            $remote_path = $file['path'];
            $relative_path = $file['relative_path'];
            $file_size = $file['size'];
            $local_path = $status['files_dir'] . $relative_path;
            
            $status['current_file'] = $remote_path;
            $status['current_file_index'] = $status['files_backed_up'] + 1;
            
            // Ensure parent directory exists
            $parent_dir = dirname($local_path);
            if (!file_exists($parent_dir) && !wp_mkdir_p($parent_dir)) {
                $status['errors'][] = "Failed to create parent directory for: {$relative_path}";
                $status['failed_files'][] = [
                    'path' => $remote_path,
                    'message' => 'Failed to create parent directory'
                ];
                continue;
            }
            
            // Download file with streaming
            $download_result = $this->download_file_streaming($connection_method, $connection_config, $remote_path, $local_path);
            
            if (is_wp_error($download_result)) {
                $error_msg = "Failed to download {$relative_path}: " . $download_result->get_error_message();
                $status['errors'][] = $error_msg;
                $status['failed_files'][] = [
                    'path' => $remote_path,
                    'message' => $download_result->get_error_message()
                ];
            } else {
                $actual_size = file_exists($local_path) ? filesize($local_path) : 0;
                
                // Add to processed files list (lightweight - only keep recent files for UI)
                $processed_file = [
                    'path' => $remote_path,
                    'relative_path' => $relative_path,
                    'type' => 'file',
                    'size' => $actual_size
                ];
                
                $status['processed_files'][] = $processed_file;
                
                // Keep only last 100 files in memory for UI display
                if (count($status['processed_files']) > 100) {
                    array_shift($status['processed_files']);
                }
                
                $batch_size += $actual_size;
                $status['files_backed_up']++;
                
                // Log progress for large files
                if ($file_size > $this->large_file_threshold) {
                    $this->log_message("Processed large file: {$relative_path} (" . size_format($actual_size) . ')');
                }
            }
            
            $batch_count++;
            
            // Memory cleanup based on batch progress
            if ($batch_count % $this->memory_limits['cleanup_interval'] === 0) {
                $this->memory_cleanup();
            }
            
            // Update progress periodically for UI responsiveness (based on batch size)
            $progress_interval = max(10, min(50, $this->batch_limits['progress_update_interval']));
            if ($batch_count % $progress_interval === 0) {
                $this->update_progress_and_speed($status, $batch_size);
            }
        }
        
        // Final progress and speed update for this batch
        $this->update_progress_and_speed($status, $batch_size);
        
        // Update UI compatibility fields
        $status['current_file_index'] = $status['files_backed_up'];
        if (isset($all_files[0])) {
            $status['current_file'] = $all_files[0]['path'];
        }
        
        // Update remaining files
        if (!empty($all_files)) {
            file_put_contents($processed_queue_file, json_encode($all_files));
        } else {
            @unlink($processed_queue_file);
            $status['processing_completed'] = true;
        }
        
        // Checkpoint
        if (time() - $status['last_checkpoint'] > $this->batch_limits['checkpoint_interval']) {
            $this->create_checkpoint($status);
            $status['last_checkpoint'] = time();
        }
        
        $this->update_status($status);
        
        // Check if completed
        if ($status['processing_completed']) {
            return $this->transition_to_finalization($status);
        }
        
        return $status;
    }
    
    /**
     * Update progress and speed calculations.
     *
     * @param array $status Current status.
     * @param int $batch_size Bytes processed in this batch.
     * @return void
     */
    private function update_progress_and_speed(&$status, $batch_size) {
        // Calculate progress (file processing is 70% of total work, from 20% to 90%)
        $files_total = max(1, $status['total_files']);
        $progress_ratio = $status['files_backed_up'] / $files_total;
        $status['progress'] = min(90, 20 + ($progress_ratio * 70));
        
        // Calculate speed
        $elapsed = microtime(true) - $status['start_time'];
        if ($elapsed > 0 && $batch_size > 0) {
            $current_speed = $batch_size / $elapsed;
            $status['bytes_per_second'] = $status['bytes_per_second'] > 0 
                ? ($status['bytes_per_second'] * 0.7) + ($current_speed * 0.3)
                : $current_speed;
        }
        
        // Update UI compatibility fields
        $status['current_file_index'] = $status['files_backed_up'];
        
        // Update status message
        $failed_count = count($status['failed_files']);
        $speed_text = $status['bytes_per_second'] > 0 ? ' at ' . size_format($status['bytes_per_second']) . '/s' : '';
        $status['message'] = sprintf(__('Copying files: %d of %d (%d failed)%s', 'swiftspeed-siberian'), 
                                   $status['files_backed_up'], 
                                   $files_total,
                                   $failed_count,
                                   $speed_text);
    }
    
    /**
     * Download a file with streaming (memory efficient).
     *
     * @param string $method Connection method.
     * @param array $config Connection configuration.
     * @param string $remote_path Remote file path.
     * @param string $local_path Local file path.
     * @return bool|WP_Error True on success, error on failure.
     */
    private function download_file_streaming($method, $config, $remote_path, $local_path) {
        switch ($method) {
            case 'ftp':
                return $this->connection_handler->download_file_ftp_pooled($config, $remote_path, $local_path);
                
            case 'sftp':
                return $this->connection_handler->download_file_sftp_pooled($config, $remote_path, $local_path);
                
            case 'local':
            default:
                return $this->copy_file_local_streaming($remote_path, $local_path);
        }
    }
    
    /**
     * Copy local file with streaming.
     *
     * @param string $source Source file path.
     * @param string $destination Destination file path.
     * @return bool|WP_Error True on success, error on failure.
     */
    private function copy_file_local_streaming($source, $destination) {
        if (!file_exists($source) || !is_readable($source)) {
            return new WP_Error('file_error', 'Source file not accessible');
        }
        
        $file_size = filesize($source);
        if ($file_size > $this->max_file_size) {
            return new WP_Error('file_too_large', 'File exceeds size limit');
        }
        
        // Use stream copy for memory efficiency
        $src = fopen($source, 'rb');
        $dest = fopen($destination, 'wb');
        
        if (!$src || !$dest) {
            if ($src) fclose($src);
            if ($dest) fclose($dest);
            return new WP_Error('file_error', 'Cannot open files for streaming');
        }
        
        // Copy in optimized chunks (varies by max_steps setting)
        $chunk_size = 65536; // Base 64KB
        if ($this->max_steps > 15) {
            $chunk_size = 262144; // 256KB for high speed
        } elseif ($this->max_steps > 10) {
            $chunk_size = 131072; // 128KB for medium speed
        }
        
        while (!feof($src)) {
            $chunk = fread($src, $chunk_size);
            if ($chunk === false) {
                fclose($src);
                fclose($dest);
                @unlink($destination);
                return new WP_Error('read_error', 'Error reading source file');
            }
            
            if (fwrite($dest, $chunk) === false) {
                fclose($src);
                fclose($dest);
                @unlink($destination);
                return new WP_Error('write_error', 'Error writing destination file');
            }
        }
        
        fclose($src);
        fclose($dest);
        
        return true;
    }
    
    /**
     * Transition to finalization phase.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function transition_to_finalization($status) {
        $status['phase'] = 'finalization';
        $status['progress'] = 90;
        $status['message'] = __('Creating backup archive...', 'swiftspeed-siberian');
        
        $this->log_message('Transitioning to finalization phase');
        $this->update_status($status);
        
        return $this->process_next($status);
    }
    
    /**
     * Finalize backup by creating archive.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function finalize_backup($status) {
        // Clean up checkpoint files
        $this->cleanup_checkpoint_files($status['checkpoint_dir']);
        
        // Create final archive if not part of full backup
        if (empty($status['full_backup'])) {
            return $this->create_final_archive($status);
        }
        
        // For full backups, just mark as completed
        $status['status'] = 'completed';
        $status['progress'] = 100;
        $status['message'] = __('File backup completed successfully', 'swiftspeed-siberian');
        
        $this->log_message("File backup completed: {$status['files_backed_up']} files, " . size_format($status['total_size']));
        $this->update_status($status);
        
        return $status;
    }
    
    /**
     * Get directory contents efficiently.
     *
     * @param string $method Connection method.
     * @param array $config Connection configuration.
     * @param string $path Directory path.
     * @return array|WP_Error Directory contents or error.
     */
    private function get_directory_contents($method, $config, $path) {
        switch ($method) {
            case 'ftp':
                return $this->connection_handler->get_ftp_directory_contents_pooled($config, $path);
                
            case 'sftp':
                return $this->connection_handler->get_sftp_directory_contents_pooled($config, $path);
                
            case 'local':
            default:
                return $this->get_local_directory_contents($path);
        }
    }
    
    /**
     * Get local directory contents.
     *
     * @param string $directory Directory path.
     * @return array|WP_Error Directory contents or error.
     */
    private function get_local_directory_contents($directory) {
        if (!is_dir($directory) || !is_readable($directory)) {
            return new WP_Error('dir_error', 'Directory not accessible');
        }
        
        $items = [];
        try {
            $iterator = new DirectoryIterator($directory);
            foreach ($iterator as $file_info) {
                if ($file_info->isDot()) continue;
                
                $items[] = [
                    'name' => $file_info->getFilename(),
                    'type' => $file_info->isDir() ? 'dir' : 'file',
                    'size' => $file_info->isFile() ? $file_info->getSize() : 0,
                ];
            }
        } catch (Exception $e) {
            return new WP_Error('dir_read_error', $e->getMessage());
        }
        
        return $items;
    }
    
    /**
     * Check if path should be excluded.
     *
     * @param string $path Path to check.
     * @param array $exclude_paths Exclude patterns.
     * @return bool True if should be excluded.
     */
    private function is_path_excluded($path, $exclude_paths) {
        foreach ($exclude_paths as $exclude_path) {
            if (empty($exclude_path)) continue;
            
            if (strpos($path, $exclude_path) === 0 || $path === $exclude_path) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get relative path.
     *
     * @param string $base_path Base path.
     * @param string $path Full path.
     * @return string Relative path.
     */
    private function get_relative_path($base_path, $path) {
        $base_path = rtrim($base_path, '/') . '/';
        
        if (strpos($path, $base_path) === 0) {
            return substr($path, strlen($base_path));
        }
        
        if ($base_path === '/') {
            return ltrim($path, '/');
        }
        
        return $path;
    }
    
    /**
     * Create checkpoint for recovery.
     *
     * @param array $status Current status.
     * @return void
     */
    private function create_checkpoint($status) {
        $checkpoint_data = [
            'timestamp' => time(),
            'phase' => $status['phase'],
            'files_backed_up' => $status['files_backed_up'],
            'dirs_backed_up' => $status['dirs_backed_up'],
            'total_size' => $status['total_size'],
            'failed_count' => count($status['failed_files']),
            'excluded_count' => $status['excluded_count'],
            'progress' => $status['progress'],
        ];
        
        $checkpoint_file = $status['checkpoint_dir'] . 'main_checkpoint.json';
        file_put_contents($checkpoint_file, json_encode($checkpoint_data));
    }
    
    /**
     * Clean up checkpoint files.
     *
     * @param string $checkpoint_dir Checkpoint directory.
     * @return void
     */
    private function cleanup_checkpoint_files($checkpoint_dir) {
        if (!is_dir($checkpoint_dir)) return;
        
        $files = glob($checkpoint_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($checkpoint_dir);
    }
    
    /**
     * Check memory safety.
     *
     * @return bool True if memory usage is safe.
     */
    private function check_memory_safety() {
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();
        $usage_ratio = $memory_usage / $memory_limit;
        
        return $usage_ratio < $this->memory_limits['critical_threshold'];
    }
    
    /**
     * Get memory limit in bytes.
     *
     * @return int Memory limit in bytes.
     */
    protected function get_memory_limit() {
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
     * Emergency memory cleanup.
     *
     * @return void
     */
    private function emergency_cleanup() {
        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }
        }
        
        // Clear WordPress caches
        wp_cache_flush();
        
        $this->log_message('Emergency memory cleanup performed');
    }
    
    /**
     * Regular memory cleanup.
     *
     * @return void
     */
    protected function memory_cleanup() {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Create final archive with minimal memory usage.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    public function create_final_archive($status) {
        if ($status['files_backed_up'] === 0) {
            $status['status'] = 'error';
            $status['message'] = __('No files were processed for backup', 'swiftspeed-siberian');
            $this->update_status($status);
            return $status;
        }
        
        $this->log_message("Creating final archive for {$status['files_backed_up']} files");
        
        // Use parent method for archive creation
        return parent::create_final_archive($status);
    }
    
    /**
     * Cancel backup and cleanup.
     *
     * @param array $status Current backup status.
     * @return bool Success status.
     */
    public function cancel_backup($status) {
        if (empty($status)) {
            return false;
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        // Close connections
        $this->connection_handler->close_all_connections();
        
        // Clean up temp files
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        return true;
    }
    
    /**
     * Destructor to ensure cleanup.
     */
    public function __destruct() {
        if ($this->connection_handler) {
            $this->connection_handler->close_all_connections();
        }
    }
}