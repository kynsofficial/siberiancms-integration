<?php
/**
 * File backup functionality for Siberian CMS.
 * OPTIMIZED VERSION 3.0: Enhanced memory management, smart batch processing,
 * and better handling of large folders with many medium-sized files
 * WITH MAX_STEPS SUPPORT
 */
class SwiftSpeed_Siberian_File_Backup extends SwiftSpeed_Siberian_Base_Backup {
    /**
     * File Connect instance
     * 
     * @var SwiftSpeed_Siberian_File_Connect
     */
    private $file_connect;
    
    /**
     * Default batch size for file processing
     * 
     * @var int
     */
    private $batch_size = 5; // Start with very conservative batch size
    
    /**
     * Maximum file size to include in backup (500mb by default)
     * 
     * @var int
     */
     private $max_file_size = 524288000; // 500MB
    
    /**
     * Backup start time for speed calculation
     * 
     * @var float
     */
    private $start_time;
    
    /**
     * Maximum number of file processing retries
     * 
     * @var int
     */
    private $max_retries = 3;
    
    /**
     * Adaptive batch size management
     * 
     * @var array
     */
    private $batch_metrics = [
        'last_batch_time' => 0,
        'last_batch_size' => 0,
        'last_batch_files' => 0,
        'optimal_time_per_batch' => 10, // Target 10 seconds per batch
        'consecutive_errors' => 0,
        'last_memory_usage' => 0,
        'memory_high_water_mark' => 0,
        'large_files_in_batch' => 0
    ];
    
    /**
     * Connection handler instance
     * 
     * @var SwiftSpeed_Siberian_File_Backup_Connections
     */
    private $connection_handler;
    
    /**
     * Memory usage tracking
     * 
     * @var array
     */
    private $memory_stats = [
        'peak_usage' => 0,
        'last_gc_time' => 0,
        'gc_interval' => 30, // Run GC every 30 seconds
        'critical_threshold' => 0.75, // 75% of available memory
        'warning_threshold' => 0.6,   // 60% of available memory
    ];
    
    /**
     * Large file threshold (50MB)
     * 
     * @var int
     */
    private $large_file_threshold = 52428800; // 50MB
    
    /**
     * Medium file threshold (5MB)
     * 
     * @var int
     */
    private $medium_file_threshold = 5242880; // 5MB
    
    /**
     * Minimum batch size
     * 
     * @var int
     */
    private $min_batch_size = 1;
    
    /**
     * Maximum batch size (dynamically calculated based on max_steps)
     * 
     * @var int
     */
    private $max_batch_size = 50;
    
    /**
     * Maximum steps from user settings (2-25)
     * 
     * @var int
     */
    private $max_steps = 5;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();
        
        // Load the File Connect class
        if (!class_exists('SwiftSpeed_Siberian_File_Connect')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/connect/includes/fileconnect.php';
        }
        
        // Load the connections handler class
        if (!class_exists('SwiftSpeed_Siberian_File_Backup_Connections')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-file-backup-connections.php';
        }
        
        $this->file_connect = new SwiftSpeed_Siberian_File_Connect();
        $this->connection_handler = new SwiftSpeed_Siberian_File_Backup_Connections();
        
        // Allow filtering max file size
        $this->max_file_size = apply_filters('swsib_max_backup_file_size', $this->max_file_size);
        
        // Record start time for speed calculations
        $this->start_time = microtime(true);
        
        // Get max steps from settings and calculate batch sizes
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        // Calculate batch sizes based on max_steps
        $this->calculate_batch_sizes_from_max_steps();
        
        // Initialize memory tracking
        $this->initialize_memory_tracking();
    }
    
    /**
     * Calculate batch sizes based on max_steps setting
     * 
     * @return void
     */
    private function calculate_batch_sizes_from_max_steps() {
        // Normalize max_steps to 2-25 range
        $this->max_steps = max(2, min(25, $this->max_steps));
        
        // Map max_steps to batch sizes
        // 2 = most conservative (minimum speed)
        // 25 = maximum speed
        
        // Calculate min batch size (1-3)
        $this->min_batch_size = ($this->max_steps <= 10) ? 1 : 2;
        
        // Calculate max batch size (10-200)
        $factor = ($this->max_steps - 2) / 23; // Normalize to 0-1 range
        $this->max_batch_size = round(10 + ($factor * 190)); // 10 to 200 range
        
        // Calculate initial batch size
        $this->batch_size = round($this->min_batch_size + ($factor * ($this->max_batch_size / 10)));
        
        // Adjust optimal time per batch based on max_steps
        $this->batch_metrics['optimal_time_per_batch'] = round(20 - ($factor * 15)); // 20s to 5s range
        
        $this->log_message("Batch sizes calculated from max_steps ({$this->max_steps}): min={$this->min_batch_size}, max={$this->max_batch_size}, initial={$this->batch_size}, target_time={$this->batch_metrics['optimal_time_per_batch']}s");
    }

    /**
     * Initialize memory tracking
     * 
     * @return void
     */
    private function initialize_memory_tracking() {
        $memory_limit = $this->get_memory_limit();
        $this->memory_stats['critical_threshold'] = intval($memory_limit * 0.75);
        $this->memory_stats['warning_threshold'] = intval($memory_limit * 0.6);
        $this->memory_stats['last_gc_time'] = time();
        
        // Adjust GC interval based on max_steps
        $factor = ($this->max_steps - 2) / 23;
        $this->memory_stats['gc_interval'] = round(60 - ($factor * 30)); // 60s to 30s range
    }

    /**
     * Log a message with enhanced details for file backup.
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
     * Start the file backup process.
     *
     * @param array $params Backup parameters.
     * @return array|WP_Error Backup status or error.
     */
    public function start_backup($params = []) {
        // Increase memory limit and execution time for backup process
        @ini_set('memory_limit', '2048M');
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
            'resume_from' => null,
        ];
        
        $params = wp_parse_args($params, $default_params);
        
        // Auto-exclude system directories that don't need to be backed up
        $auto_exclude_paths = [
            '/phpmyadmin',
            '/var/tmp',
            '/var/session',
            '/var/cache',
            '/var/cache_images',
            '/var/log',
            '/.usermin'
        ];
        
        // Add auto-excluded paths to user-defined exclude paths
        $params['exclude_paths'] = array_merge($params['exclude_paths'], $auto_exclude_paths);
        $this->log_message('Automatically excluding system directories: ' . implode(', ', $auto_exclude_paths));
        
        $this->log_memory_usage('Starting file backup');
        $this->log_message('Starting file backup with params: ' . json_encode([
            'storage' => $params['storage'],
            'storage_providers' => $params['storage_providers'],
            'full_backup' => $params['full_backup'] ? 'yes' : 'no',
            'scheduled' => $params['scheduled'] ? 'yes' : 'no',
            'auto_lock' => $params['auto_lock'] ? 'yes' : 'no',
            'include_paths_count' => count($params['include_paths']),
            'exclude_paths_count' => count($params['exclude_paths']),
            'memory_limit' => ini_get('memory_limit'),
            'max_steps' => $this->max_steps,
        ]));
        
        // Check if installation connection is configured
        $installation_options = isset($this->options['installation']) ? $this->options['installation'] : [];
        if (empty($installation_options['is_configured'])) {
            $this->log_message('Installation connection is not configured');
            return new WP_Error('backup_error', __('Installation connection is not configured', 'swiftspeed-siberian'));
        }
        
        // Create or use provided backup ID and temporary directory
        $backup_id = !empty($params['id']) ? $params['id'] : 'siberian-backup-file-' . date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 8);
        $temp_dir = !empty($params['temp_dir']) ? $params['temp_dir'] : $this->temp_dir . $backup_id . '/';
        
        $this->log_message('Using backup ID: ' . $backup_id);
        $this->log_message('Using temp dir: ' . $temp_dir);
        
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                $this->log_message('Failed to create temporary directory: ' . $temp_dir);
                return new WP_Error('backup_error', __('Failed to create temporary directory', 'swiftspeed-siberian'));
            }
        }
        
        // Create files directory in the temp dir
        $files_dir = $params['full_backup'] ? $temp_dir : $temp_dir . 'files/';
        if (!file_exists($files_dir)) {
            if (!wp_mkdir_p($files_dir)) {
                $this->log_message('Failed to create files directory: ' . $files_dir);
                return new WP_Error('backup_error', __('Failed to create files directory', 'swiftspeed-siberian'));
            }
        }
        
        // Get connection method and configuration
        $connection_method = isset($installation_options['connection_method']) ? $installation_options['connection_method'] : 'ftp';
        $connection_config = isset($installation_options[$connection_method]) ? $installation_options[$connection_method] : [];
        
        $this->log_message('Using connection method: ' . $connection_method);
        
        // Validate connection configuration
        if ($connection_method === 'ftp') {
            if (empty($connection_config['host']) || empty($connection_config['username']) || 
                empty($connection_config['password']) || empty($connection_config['path'])) {
                $this->log_message('FTP connection configuration is incomplete');
                return new WP_Error('backup_error', __('FTP connection configuration is incomplete', 'swiftspeed-siberian'));
            }
            
            $this->log_message('Testing FTP connection...');
            $result = $this->connection_handler->test_ftp_connection($connection_config);
            if (is_wp_error($result)) {
                $this->log_message('FTP connection test failed: ' . $result->get_error_message());
                return $result;
            }
            
            $this->log_message('FTP connection test successful');
            
            // Initialize connection pool with reduced pool size
            $this->connection_handler->initialize_connection_pool($connection_config);
        } 
        elseif ($connection_method === 'sftp') {
            if (empty($connection_config['host']) || empty($connection_config['username']) || 
                empty($connection_config['password']) || empty($connection_config['path'])) {
                $this->log_message('SFTP connection configuration is incomplete');
                return new WP_Error('backup_error', __('SFTP connection configuration is incomplete', 'swiftspeed-siberian'));
            }
            
            $this->log_message('Testing SFTP connection...');
            $result = $this->connection_handler->test_sftp_connection($connection_config);
            if (is_wp_error($result)) {
                $this->log_message('SFTP connection test failed: ' . $result->get_error_message());
                return $result;
            }
            
            $this->log_message('SFTP connection test successful');
            
            // Initialize SFTP connection pool with reduced pool size
            $this->connection_handler->initialize_sftp_connection_pool($connection_config);
        }
        else {
            // Local connection
            if (empty($connection_config['path'])) {
                $this->log_message('Local path is not configured');
                return new WP_Error('backup_error', __('Local path is not configured', 'swiftspeed-siberian'));
            }
            
            if (!file_exists($connection_config['path'])) {
                $this->log_message('Local path does not exist: ' . $connection_config['path']);
                return new WP_Error('backup_error', __('Local path does not exist', 'swiftspeed-siberian'));
            }
            
            $this->log_message('Local path validation successful');
        }
        
        // If no include_paths specified, use the installation root path
        if (empty($params['include_paths'])) {
            $params['include_paths'] = isset($connection_config['path']) ? [$connection_config['path']] : [];
            $this->log_message('No include paths specified, using installation path: ' . implode(', ', $params['include_paths']));
        }
        
        // Create initial directory queue from include paths
        $queue = new SplQueue();
        
        // Add root directories to the queue
        foreach ($params['include_paths'] as $path) {
            $this->log_message('Adding root path to queue: ' . $path);
            $queue->enqueue([
                'path' => $path,
                'type' => 'dir',
                'depth' => 0,
                'relative_path' => '',
                'size_estimate' => 0
            ]);
            
            // Also add to processed list so we don't lose the root directory
            $relative_path = $this->get_relative_path($connection_config['path'], $path);
            
            // Create the root directory in the backup
            $local_dir = $files_dir . $relative_path;
            if (!file_exists($local_dir) && !wp_mkdir_p($local_dir)) {
                $this->log_message('Failed to create root directory in backup: ' . $local_dir);
            }
        }
        
        // Check for resume data
        $processed_files = [];
        $processed_dirs = [];
        if (!empty($params['resume_from']) && isset($params['resume_data'])) {
            $this->log_message('Attempting to resume backup from: ' . $params['resume_from']);
            $processed_files = isset($params['resume_data']['processed_files']) ? $params['resume_data']['processed_files'] : [];
            $processed_dirs = isset($params['resume_data']['processed_dirs']) ? $params['resume_data']['processed_dirs'] : [];
            
            $this->log_message('Resuming with ' . count($processed_files) . ' processed files and ' . count($processed_dirs) . ' processed directories');
        }
        
        // Initialize backup status
        $status = [
            'id' => $backup_id,
            'temp_dir' => $temp_dir,
            'files_dir' => $files_dir,
            'backup_type' => 'file',
            'connection_method' => $connection_method,
            'connection_config' => $connection_config,
            'params' => $params,
            'file_queue' => serialize($queue),
            'processed_dirs' => $processed_dirs,
            'processed_files' => $processed_files,
            'current_file_index' => count($processed_files),
            'files_backed_up' => count($processed_files),
            'dirs_backed_up' => count($processed_dirs),
            'dirs_scanned' => 0,
            'total_size' => 0,
            'started' => time(),
            'start_time' => microtime(true),
            'status' => 'processing',
            'message' => __('Starting file backup...', 'swiftspeed-siberian'),
            'progress' => 0,
            'failed_files' => [],
            'retry_files' => [],
            'full_backup' => $params['full_backup'],
            'errors' => [],
            'allow_background' => $params['allow_background'],
            'exclude_paths' => $params['exclude_paths'],
            'estimated_total' => 100,
            'bytes_per_second' => 0,
            'time_elapsed' => 0,
            'current_batch_size' => 0,
            'last_batch_time' => 0,
            'last_batch_success' => true,
            'batch_size' => $this->batch_size,
            'excluded_file_count' => 0,
            'excluded_size' => 0,
            'batch_metrics' => $this->batch_metrics,
            'phase' => 'scanning',
            'remaining_size_estimate' => 0,
            'subdir_count' => 0,
            // New fields for better memory management
            'pending_files' => [],
            'current_batch_files' => [],
            'large_files_queue' => [],
            'memory_stats' => $this->memory_stats,
            'last_checkpoint' => time(),
            'checkpoint_interval' => 60, // Checkpoint every 60 seconds
            'max_steps' => $this->max_steps, // Include max_steps in status
        ];
        
        // Calculate total size of processed files if resuming
        if (!empty($processed_files)) {
            foreach ($processed_files as $file) {
                if (isset($file['size'])) {
                    $status['total_size'] += $file['size'];
                }
            }
            $this->log_message('Resumed backup has ' . size_format($status['total_size'], 2) . ' of already processed files');
        }
        
        $this->update_status($status);
        $this->log_message('File backup started: ' . $backup_id);
        
        // Process the first batch immediately for a responsive UI
        return $this->process_next($status);
    }
    
    /**
     * Log current memory usage
     * 
     * @param string $checkpoint Checkpoint identifier
     * @return void
     */
    private function log_memory_usage($checkpoint) {
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $formatted_usage = size_format($memory_usage, 2);
        $formatted_peak = size_format($memory_peak, 2);
        $this->log_message("Memory usage at {$checkpoint}: {$formatted_usage} (peak: {$formatted_peak})");
        
        // Update memory stats
        $this->memory_stats['peak_usage'] = max($this->memory_stats['peak_usage'], $memory_peak);
    }
    
    /**
     * Process the next step in the file backup.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    public function process_next($status) {
        if (empty($status)) {
            return new WP_Error('process_error', __('Invalid backup status', 'swiftspeed-siberian'));
        }
        
        if ($status['status'] !== 'processing') {
            return $status; // Already completed or has error
        }
        
        // Increase memory limit and execution time for backup process
        @ini_set('memory_limit', '2048M');
        @set_time_limit(300); // 5 minutes
        
        // Check memory before processing
        $memory_check = $this->check_memory_usage($status);
        if (!$memory_check) {
            $this->log_message('Memory usage critical, attempting emergency garbage collection');
            $this->emergency_memory_cleanup();
            
            // Check again after cleanup
            if (!$this->check_memory_usage($status)) {
                $status['status'] = 'error';
                $status['message'] = __('Out of memory. Please increase PHP memory limit.', 'swiftspeed-siberian');
                $this->update_status($status);
                return $status;
            }
        }
        
        // Record batch start time for performance metrics
        $batch_start_time = microtime(true);
        $batch_start_size = isset($status['total_size']) ? $status['total_size'] : 0;
        
        // Adjust batch size based on memory and performance
        $this->adjust_batch_size($status);
        
        // Get the current batch size from status
        $this->batch_size = isset($status['batch_size']) ? $status['batch_size'] : $this->batch_size;
        
        // Process next batch of directories and files
        $queue = unserialize($status['file_queue']);
        $retry_files = isset($status['retry_files']) ? $status['retry_files'] : [];
        $pending_files = isset($status['pending_files']) ? $status['pending_files'] : [];
        $large_files_queue = isset($status['large_files_queue']) ? $status['large_files_queue'] : [];
        
        $batch_count = 0;
        $batch_size_bytes = 0;
        $connection_method = $status['connection_method'];
        $connection_config = $status['connection_config'];
        $files_dir = $status['files_dir'];
        $exclude_paths = $status['exclude_paths'];
        
        $status['phase'] = empty($queue) && empty($retry_files) && empty($pending_files) && empty($large_files_queue) ? 
                          'finalizing' : 'processing';
        
        // Process files with smart batching based on size
        $files_to_process = [];
        
        // First, try to process retry files (if any)
        if (!empty($retry_files) && $status['last_batch_success']) {
            $this->log_message('Processing ' . count($retry_files) . ' retry files');
            foreach ($retry_files as $key => $retry_item) {
                if ($batch_count >= $this->batch_size || $batch_size_bytes > 50 * 1024 * 1024) { // 50MB limit per batch
                    break;
                }
                $files_to_process[] = $retry_item;
                $batch_size_bytes += isset($retry_item['size_estimate']) ? $retry_item['size_estimate'] : 0;
                unset($retry_files[$key]);
                $batch_count++;
            }
            $status['retry_files'] = array_values($retry_files);
        }
        
        // Then process pending files
        if ($batch_count < $this->batch_size && !empty($pending_files)) {
            foreach ($pending_files as $key => $file_item) {
                if ($batch_count >= $this->batch_size || $batch_size_bytes > 50 * 1024 * 1024) {
                    break;
                }
                $files_to_process[] = $file_item;
                $batch_size_bytes += isset($file_item['size_estimate']) ? $file_item['size_estimate'] : 0;
                unset($pending_files[$key]);
                $batch_count++;
            }
            $status['pending_files'] = array_values($pending_files);
        }
        
        // If we still have room, scan directories for more files
        if ($batch_count < $this->batch_size && !$queue->isEmpty()) {
            // Dynamically adjust number of directories to scan based on max_steps
            $factor = ($this->max_steps - 2) / 23;
            $dirs_to_scan = min(round(1 + ($factor * 9)), $this->batch_size - $batch_count); // 1 to 10 directories
            $scanned_dirs = 0;
            
            while (!$queue->isEmpty() && $scanned_dirs < $dirs_to_scan) {
                $item = $queue->dequeue();
                $path = $item['path'];
                $depth = $item['depth'];
                $type = $item['type'];
                
                // Skip if exceeds max depth
                if ($depth > 15) {
                    $this->log_message("Skipping due to max depth: " . $path);
                    continue;
                }
                
                // Check if path should be excluded
                $exclude = false;
                foreach ($exclude_paths as $exclude_path) {
                    if (!empty($exclude_path) && (strpos($path, $exclude_path) === 0 || $path === $exclude_path)) {
                        $exclude = true;
                        $this->log_message("Excluding path: {$path}");
                        $status['excluded_file_count']++;
                        break;
                    }
                }
                
                if ($exclude) {
                    continue;
                }
                
                // Get relative path
                $relative_path = $this->get_relative_path($connection_config['path'], $path);
                
                if ($type === 'dir') {
                    // Process directory
                    $local_dir = $files_dir . $relative_path;
                    
                    // Create directory in backup if it doesn't exist
                    if (!file_exists($local_dir)) {
                        if (!wp_mkdir_p($local_dir)) {
                            $this->log_message("Failed to create directory in backup: {$local_dir}");
                            $status['errors'][] = [
                                'path' => $path,
                                'message' => 'Failed to create directory in backup'
                            ];
                            continue;
                        }
                    }
                    
                    // Add to processed directories
                    $status['processed_dirs'][] = [
                        'path' => $path,
                        'relative_path' => $relative_path,
                        'type' => 'dir',
                        'size' => 0
                    ];
                    
                    $status['dirs_backed_up']++;
                    
                    // Get directory contents
                    $dir_contents = null;
                    switch ($connection_method) {
                        case 'ftp':
                            $dir_contents = $this->connection_handler->get_ftp_directory_contents_pooled($connection_config, $path);
                            break;
                        case 'sftp':
                            $dir_contents = $this->connection_handler->get_sftp_directory_contents_pooled($connection_config, $path);
                            break;
                        case 'local':
                        default:
                            $dir_contents = $this->get_local_directory_contents($path);
                            break;
                    }
                    
                    if (is_wp_error($dir_contents)) {
                        $this->log_message("Error listing directory {$path}: " . $dir_contents->get_error_message());
                        $status['errors'][] = [
                            'path' => $path,
                            'message' => $dir_contents->get_error_message()
                        ];
                        continue;
                    }
                    
                    $status['dirs_scanned']++;
                    $scanned_dirs++;
                    
                    // Process directory contents with smart sorting
                    $subdirs = [];
                    $small_files = [];
                    $medium_files = [];
                    $large_files = [];
                    
                    foreach ($dir_contents as $content_item) {
                        if ($content_item['name'] === '.' || $content_item['name'] === '..') {
                            continue;
                        }
                        
                        $item_path = rtrim($path, '/') . '/' . $content_item['name'];
                        
                        // Check if path should be excluded
                        $exclude = false;
                        foreach ($exclude_paths as $exclude_path) {
                            if (!empty($exclude_path) && (strpos($item_path, $exclude_path) === 0 || $item_path === $exclude_path)) {
                                $exclude = true;
                                $status['excluded_file_count']++;
                                if ($content_item['type'] === 'file' && isset($content_item['size'])) {
                                    $status['excluded_size'] += $content_item['size'];
                                }
                                break;
                            }
                        }
                        
                        if ($exclude) {
                            continue;
                        }
                        
                        $queue_item = [
                            'path' => $item_path,
                            'type' => $content_item['type'],
                            'depth' => $depth + 1,
                            'relative_path' => $this->get_relative_path($connection_config['path'], $item_path),
                            'size_estimate' => isset($content_item['size']) ? $content_item['size'] : 0
                        ];
                        
                        if ($content_item['type'] === 'dir') {
                            $subdirs[] = $queue_item;
                        } else {
                            // Skip files that exceed max size
                            if (isset($content_item['size']) && $content_item['size'] > $this->max_file_size) {
                                $this->log_message("Skipping file {$item_path} due to size: " . size_format($content_item['size']));
                                $status['failed_files'][] = [
                                    'path' => $item_path,
                                    'message' => sprintf(__('File exceeds maximum size limit (%s)', 'swiftspeed-siberian'), size_format($this->max_file_size))
                                ];
                                $status['excluded_file_count']++;
                                $status['excluded_size'] += $content_item['size'];
                                continue;
                            }
                            
                            // Categorize files by size
                            $file_size = isset($content_item['size']) ? $content_item['size'] : 0;
                            
                            if ($file_size > $this->large_file_threshold) {
                                $large_files[] = $queue_item;
                            } elseif ($file_size > $this->medium_file_threshold) {
                                $medium_files[] = $queue_item;
                            } else {
                                $small_files[] = $queue_item;
                            }
                        }
                    }
                    
                    // Process files strategically:
                    // 1. Small files first (quick wins)
                    // 2. Medium files next
                    // 3. Large files in separate queue
                    
                    // Add small files to current batch if there's room
                    foreach ($small_files as $file) {
                        if ($batch_count < $this->batch_size && $batch_size_bytes < 50 * 1024 * 1024) {
                            $files_to_process[] = $file;
                            $batch_size_bytes += $file['size_estimate'];
                            $batch_count++;
                        } else {
                            $pending_files[] = $file;
                        }
                    }
                    
                    // Add medium files to pending
                    foreach ($medium_files as $file) {
                        $pending_files[] = $file;
                    }
                    
                    // Add large files to special queue
                    foreach ($large_files as $file) {
                        $large_files_queue[] = $file;
                    }
                    
                    // Add subdirectories back to queue
                    foreach ($subdirs as $subdir) {
                        $queue->enqueue($subdir);
                    }
                    
                    $status['pending_files'] = $pending_files;
                    $status['large_files_queue'] = $large_files_queue;
                }
            }
        }
        
        // Process large files one at a time if no other files to process
        if (empty($files_to_process) && !empty($large_files_queue)) {
            $large_file = array_shift($large_files_queue);
            $files_to_process[] = $large_file;
            $status['large_files_queue'] = $large_files_queue;
            $this->log_message('Processing large file: ' . $large_file['path'] . ' (' . size_format($large_file['size_estimate']) . ')');
        }
        
        // Process the collected files
        $processed_count = 0;
        foreach ($files_to_process as $file_item) {
            $path = $file_item['path'];
            $relative_path = $file_item['relative_path'];
            $local_path = $files_dir . $relative_path;
            
            // Create parent directory if needed
            $parent_dir = dirname($local_path);
            if (!file_exists($parent_dir)) {
                if (!wp_mkdir_p($parent_dir)) {
                    $this->log_message("Failed to create parent directory: {$parent_dir}");
                    $status['errors'][] = [
                        'path' => $path,
                        'message' => 'Failed to create parent directory'
                    ];
                    continue;
                }
            }
            
            // Check memory before downloading
            if (!$this->check_memory_usage($status, true)) {
                $this->log_message('Memory usage too high, deferring file: ' . $path);
                // Move file back to pending queue
                $status['pending_files'][] = $file_item;
                break; // Exit the loop to prevent memory issues
            }
            
            // Download/copy the file
            $result = false;
            switch ($connection_method) {
                case 'ftp':
                    $result = $this->connection_handler->download_file_ftp_pooled($connection_config, $path, $local_path);
                    break;
                case 'sftp':
                    $result = $this->connection_handler->download_file_sftp_pooled($connection_config, $path, $local_path);
                    break;
                case 'local':
                default:
                    $result = $this->download_file_local($path, $local_path);
                    break;
            }
            
            if (is_wp_error($result)) {
                $this->log_message('Failed to download file: ' . $path . ' - ' . $result->get_error_message());
                
                // Add to retry list
                $retry_count = isset($file_item['retry_count']) ? $file_item['retry_count'] : 0;
                if ($retry_count < $this->max_retries) {
                    $file_item['retry_count'] = $retry_count + 1;
                    $status['retry_files'][] = $file_item;
                } else {
                    $status['failed_files'][] = [
                        'path' => $path,
                        'message' => $result->get_error_message()
                    ];
                }
            } else {
                // Get file size
                if (file_exists($local_path)) {
                    $file_size = filesize($local_path);
                    $status['total_size'] += $file_size;
                    
                    // Add to processed files
                    $status['processed_files'][] = [
                        'path' => $path,
                        'relative_path' => $relative_path,
                        'type' => 'file',
                        'size' => $file_size
                    ];
                    
                    $status['files_backed_up']++;
                    $status['current_file_index']++;
                    $processed_count++;
                    
                    // Update current file in status
                    $status['current_file'] = $path;
                    
                    // Log progress for larger files
                    if ($file_size > $this->medium_file_threshold) {
                        $this->log_message("Processed file: " . $path . " (" . size_format($file_size) . ")");
                    }
                } else {
                    $this->log_message("Warning: Downloaded file doesn't exist: {$local_path}");
                    $status['failed_files'][] = [
                        'path' => $path,
                        'message' => __('Failed to verify downloaded file', 'swiftspeed-siberian')
                    ];
                }
            }
            
            // Periodic memory cleanup based on max_steps
            $cleanup_interval = max(3, 10 - round(($this->max_steps - 2) / 5)); // 3 to 10 files
            if ($processed_count % $cleanup_interval === 0) {
                $this->memory_cleanup();
            }
        }
        
        // Update the queue
        $status['file_queue'] = serialize($queue);
        
        // Calculate progress
        $processed_count = $status['files_backed_up'] + $status['dirs_backed_up'];
        $pending_count = count($pending_files) + count($large_files_queue) + count($retry_files);
        $total_estimate = max($status['estimated_total'], $processed_count + $pending_count + 1);
        
        if ($status['phase'] === 'finalizing') {
            $progress = 95;
        } else {
            $progress = min(90, ($processed_count / $total_estimate) * 100);
        }
        
        $status['progress'] = $progress;
        $status['total_files'] = $total_estimate;
        
        // Calculate speed metrics
        $batch_end_time = microtime(true);
        $batch_duration = $batch_end_time - $batch_start_time;
        $batch_size = $status['total_size'] - $batch_start_size;
        
        // Update batch metrics
        $status['batch_metrics']['last_batch_time'] = $batch_duration;
        $status['batch_metrics']['last_batch_size'] = $batch_size;
        $status['batch_metrics']['last_batch_files'] = $batch_count;
        $status['batch_metrics']['last_memory_usage'] = memory_get_usage(true);
        $status['batch_metrics']['memory_high_water_mark'] = memory_get_peak_usage(true);
        
        // Calculate speed
        if ($batch_duration > 0 && $batch_size > 0) {
            $current_speed = $batch_size / $batch_duration;
            
            if ($status['bytes_per_second'] > 0) {
                $status['bytes_per_second'] = ($status['bytes_per_second'] * 0.7) + ($current_speed * 0.3);
            } else {
                $status['bytes_per_second'] = $current_speed;
            }
        }
        
        // Update status message
        $status['time_elapsed'] = microtime(true) - $status['start_time'];
        $status['message'] = $this->generate_status_message($status);
        
        // Check if we need to checkpoint
        if (time() - $status['last_checkpoint'] > $status['checkpoint_interval']) {
            $this->create_checkpoint($status);
            $status['last_checkpoint'] = time();
        }
        
        // Check if completed
        if ($queue->isEmpty() && empty($status['retry_files']) && empty($status['pending_files']) && empty($status['large_files_queue'])) {
            // Close connections
            $this->connection_handler->close_all_connections();
            
            // Move to finalize phase
            $status['message'] = __('All files processed, finalizing backup...', 'swiftspeed-siberian');
            
            if (empty($status['full_backup'])) {
                return $this->create_final_archive($status);
            } else {
                $status['status'] = 'completed';
                $status['message'] = __('File backup completed successfully!', 'swiftspeed-siberian');
                $status['progress'] = 100;
                $this->update_status($status);
                return $status;
            }
        }
        
        // Final memory cleanup
        $this->memory_cleanup();
        
        $this->update_status($status);
        return $status;
    }
    
    /**
     * Check memory usage and determine if we should continue
     * 
     * @param array $status Current backup status
     * @param bool $strict Use stricter thresholds
     * @return bool True if memory is OK, false if critical
     */
    private function check_memory_usage(&$status, $strict = false) {
        $current_memory = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();
        $memory_percentage = ($current_memory / $memory_limit) * 100;
        
        // Update memory stats
        $status['memory_stats']['current_usage'] = $current_memory;
        $status['memory_stats']['usage_percentage'] = $memory_percentage;
        
        // Adjust threshold based on max_steps
        $factor = ($this->max_steps - 2) / 23;
        $base_threshold = $strict ? 60 : 75;
        $threshold = $base_threshold - ($factor * 10); // More aggressive with higher max_steps
        
        if ($memory_percentage > $threshold) {
            $this->log_message(sprintf(
                'Memory usage high: %s of %s (%.1f%%)',
                size_format($current_memory),
                size_format($memory_limit),
                $memory_percentage
            ));
            
            // Perform garbage collection
            $this->memory_cleanup();
            
            // Check again
            $current_memory = memory_get_usage(true);
            $memory_percentage = ($current_memory / $memory_limit) * 100;
            
            return $memory_percentage < $threshold;
        }
        
        return true;
    }
    
    /**
     * Emergency memory cleanup
     * 
     * @return void
     */
    private function emergency_memory_cleanup() {
        // Clear any internal buffers
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Force garbage collection multiple times
        for ($i = 0; $i < 3; $i++) {
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        // Clear any cached data
        wp_cache_flush();
        
        $this->log_message('Emergency memory cleanup performed');
    }
    
    /**
     * Create a checkpoint for resume capability
     * 
     * @param array $status Current backup status
     * @return void
     */
    private function create_checkpoint($status) {
        $checkpoint_data = [
            'timestamp' => time(),
            'processed_files' => $status['processed_files'],
            'processed_dirs' => $status['processed_dirs'],
            'total_size' => $status['total_size'],
            'pending_files' => isset($status['pending_files']) ? $status['pending_files'] : [],
            'large_files_queue' => isset($status['large_files_queue']) ? $status['large_files_queue'] : [],
            'retry_files' => isset($status['retry_files']) ? $status['retry_files'] : [],
        ];
        
        $checkpoint_file = $status['temp_dir'] . 'checkpoint.json';
        file_put_contents($checkpoint_file, json_encode($checkpoint_data));
        
        $this->log_message('Created checkpoint at ' . date('Y-m-d H:i:s'));
    }
    
    /**
     * Adjust batch size based on processing performance, memory usage, and max_steps
     * 
     * @param array $status Current backup status
     * @return void
     */
    private function adjust_batch_size(&$status) {
        $metrics = isset($status['batch_metrics']) ? $status['batch_metrics'] : $this->batch_metrics;
        $current_batch_size = isset($status['batch_size']) ? $status['batch_size'] : $this->batch_size;
        
        // Get memory usage percentage
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();
        $memory_percentage = ($memory_usage / $memory_limit) * 100;
        
        // Determine adjustment based on memory and performance
        $adjustment_factor = 1.0;
        
        // Memory-based adjustment
        if ($memory_percentage > 70) {
            $adjustment_factor = 0.5; // Halve batch size if memory is high
        } elseif ($memory_percentage > 60) {
            $adjustment_factor = 0.7;
        } elseif ($memory_percentage > 50) {
            $adjustment_factor = 0.9;
        } elseif ($memory_percentage < 30) {
            $adjustment_factor = 1.5; // Increase if memory usage is low
        }
        
        // Performance-based adjustment
        if ($metrics['last_batch_time'] > 0) {
            $time_factor = $metrics['optimal_time_per_batch'] / max(1, $metrics['last_batch_time']);
            $adjustment_factor *= $time_factor;
        }
        
        // Check if last batch had errors
        if (isset($metrics['consecutive_errors']) && $metrics['consecutive_errors'] > 0) {
            $adjustment_factor *= 0.8; // Reduce on errors
        }
        
        // Apply adjustment
        $new_batch_size = round($current_batch_size * $adjustment_factor);
        
        // Enforce limits based on max_steps
        $new_batch_size = max($this->min_batch_size, min($this->max_batch_size, $new_batch_size));
        
        // Only log significant changes
        if (abs($new_batch_size - $current_batch_size) > 2) {
            $this->log_message(sprintf(
                'Adjusting batch size from %d to %d (memory: %.1f%%, time: %.1fs, max_steps: %d)',
                $current_batch_size,
                $new_batch_size,
                $memory_percentage,
                $metrics['last_batch_time'],
                $this->max_steps
            ));
        }
        
        $status['batch_size'] = $new_batch_size;
    }
    
    /**
     * Get the available memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    protected function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit == -1) {
            // No limit
            return 2147483648; // 2GB as a reasonable assumption
        }
        
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
            default:
                // No unit specified, value is already in bytes
                break;
        }
        
        return $value;
    }
    
    /**
     * Clean up memory after processing
     * 
     * @return void
     */
    protected function memory_cleanup() {
        // Check if it's time for GC
        $current_time = time();
        if (($current_time - $this->memory_stats['last_gc_time']) >= $this->memory_stats['gc_interval']) {
            // Force garbage collection if available
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            $this->memory_stats['last_gc_time'] = $current_time;
        }
    }
    
    /**
     * Generate a detailed status message based on current backup state
     * 
     * @param array $status Current backup status
     * @return string Formatted status message
     */
    private function generate_status_message($status) {
        $phase = isset($status['phase']) ? $status['phase'] : 'processing';
        $files_backed_up = isset($status['files_backed_up']) ? $status['files_backed_up'] : 0;
        $dirs_backed_up = isset($status['dirs_backed_up']) ? $status['dirs_backed_up'] : 0;
        $progress = isset($status['progress']) ? $status['progress'] : 0;
        $total_size = isset($status['total_size']) ? $status['total_size'] : 0;
        $bytes_per_second = isset($status['bytes_per_second']) ? $status['bytes_per_second'] : 0;
        $retry_files = isset($status['retry_files']) ? count($status['retry_files']) : 0;
        $pending_files = isset($status['pending_files']) ? count($status['pending_files']) : 0;
        $large_files = isset($status['large_files_queue']) ? count($status['large_files_queue']) : 0;
        
        // Format size and speed
        $size_text = size_format($total_size, 2);
        $speed_text = '';
        
        if ($bytes_per_second > 0) {
            $speed_text = ' at ' . size_format($bytes_per_second, 2) . '/s';
        }
        
        // Add memory usage info
        $memory_info = '';
        if (isset($status['memory_stats']['usage_percentage'])) {
            $memory_info = sprintf(' (Memory: %.1f%%)', $status['memory_stats']['usage_percentage']);
        }
        
        // Base message on phase
        switch ($phase) {
            case 'scanning':
                $message = sprintf(
                    __('Scanning and processing: %d files, %d directories (%.1f%%, %s%s)%s', 'swiftspeed-siberian'),
                    $files_backed_up,
                    $dirs_backed_up,
                    $progress,
                    $size_text,
                    $speed_text,
                    $memory_info
                );
                break;
                
            case 'processing':
                $message = sprintf(
                    __('Processing: %d files, %d directories (%.1f%%, %s%s)%s', 'swiftspeed-siberian'),
                    $files_backed_up,
                    $dirs_backed_up,
                    $progress,
                    $size_text,
                    $speed_text,
                    $memory_info
                );
                
                // Add pending files info
                if ($pending_files > 0 || $large_files > 0) {
                    $pending_info = [];
                    if ($pending_files > 0) {
                        $pending_info[] = sprintf(__('%d pending', 'swiftspeed-siberian'), $pending_files);
                    }
                    if ($large_files > 0) {
                        $pending_info[] = sprintf(__('%d large files', 'swiftspeed-siberian'), $large_files);
                    }
                    $message .= ' (' . implode(', ', $pending_info) . ')';
                }
                
                // Add retry information if applicable
                if ($retry_files > 0) {
                    $message .= sprintf(__(', retrying %d files', 'swiftspeed-siberian'), $retry_files);
                }
                break;
                
            case 'finalizing':
                $message = sprintf(
                    __('Finalizing backup: %d files, %d directories (%s)', 'swiftspeed-siberian'),
                    $files_backed_up,
                    $dirs_backed_up,
                    $size_text
                );
                break;
                
            default:
                $message = sprintf(
                    __('Processed %d files and %d directories (%.1f%%, %s%s)%s', 'swiftspeed-siberian'),
                    $files_backed_up,
                    $dirs_backed_up,
                    $progress,
                    $size_text,
                    $speed_text,
                    $memory_info
                );
        }
        
        return $message;
    }
    
    /**
     * Get local directory contents.
     *
     * @param string $directory Directory path to list.
     * @return array|WP_Error Array of directory contents or error.
     */
    private function get_local_directory_contents($directory) {
        if (!file_exists($directory) || !is_dir($directory)) {
            return new WP_Error('dir_not_exist', sprintf(__('Directory does not exist: %s', 'swiftspeed-siberian'), $directory));
        }
        
        if (!is_readable($directory)) {
            return new WP_Error('dir_not_readable', sprintf(__('Directory is not readable: %s', 'swiftspeed-siberian'), $directory));
        }
        
        $items = [];
        
        try {
            $dir = new DirectoryIterator($directory);
            
            foreach ($dir as $file_info) {
                if ($file_info->isDot()) {
                    continue;
                }
                
                $items[] = [
                    'name' => $file_info->getFilename(),
                    'type' => $file_info->isDir() ? 'dir' : 'file',
                    'size' => $file_info->isFile() ? $file_info->getSize() : 0,
                    'permissions' => substr(sprintf('%o', $file_info->getPerms()), -4)
                ];
            }
        } catch (Exception $e) {
            return new WP_Error('dir_read_error', $e->getMessage());
        }
        
        return $items;
    }
    
    /**
     * Copy a file from local filesystem with improved error handling.
     *
     * @param string $source_path Source file path.
     * @param string $destination_path Destination path.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function download_file_local($source_path, $destination_path) {
        if (!file_exists($source_path)) {
            return new WP_Error('source_not_exist', __('Source file does not exist', 'swiftspeed-siberian'));
        }
        
        if (!is_readable($source_path)) {
            return new WP_Error('source_not_readable', __('Source file is not readable', 'swiftspeed-siberian'));
        }
        
        // Check if file is too large
        $file_size = filesize($source_path);
        if ($file_size > $this->max_file_size) {
            return new WP_Error('file_too_large', sprintf(__('File is too large to backup: %s', 'swiftspeed-siberian'), size_format($file_size)));
        }
        
        // Ensure parent directory exists
        $parent_dir = dirname($destination_path);
        if (!file_exists($parent_dir)) {
            if (!wp_mkdir_p($parent_dir)) {
                return new WP_Error('mkdir_failed', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Use streams for all files for better reliability
        $source = @fopen($source_path, 'rb');
        $dest = @fopen($destination_path, 'wb');
        
        if (!$source || !$dest) {
            if ($source) @fclose($source);
            if ($dest) @fclose($dest);
            return new WP_Error('open_failed', __('Failed to open files for streaming copy', 'swiftspeed-siberian'));
        }
        
        // Copy in smaller chunks for better memory usage (256KB for better memory efficiency)
        $bytes_copied = 0;
        $chunk_size = 256 * 1024; // 256KB chunks
        
        while (!feof($source)) {
            $buffer = fread($source, $chunk_size);
            if ($buffer === false) {
                @fclose($source);
                @fclose($dest);
                return new WP_Error('read_failed', __('Failed to read from source file', 'swiftspeed-siberian'));
            }
            
            $write_result = fwrite($dest, $buffer);
            if ($write_result === false || $write_result != strlen($buffer)) {
                @fclose($source);
                @fclose($dest);
                return new WP_Error('write_failed', __('Failed to write to destination file', 'swiftspeed-siberian'));
            }
            
            $bytes_copied += $write_result;
            
            // Clear the buffer variable to free memory
            unset($buffer);
        }
        
        @fclose($source);
        @fclose($dest);
        
        if ($bytes_copied < $file_size) {
            return new WP_Error('copy_incomplete', __('Failed to copy complete file', 'swiftspeed-siberian'));
        }
        
        return true;
    }
    
    /**
     * Get the path relative to a base path.
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
        
        // If the base path is just /, return without leading slash
        if ($base_path === '/') {
            return ltrim($path, '/');
        }
        
        return $path;
    }
    
    /**
     * Create the final backup archive with optimized ZIP creation.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    public function create_final_archive($status) {
        if (empty($status['processed_files'])) {
            $this->log_message('No files were processed for backup');
            $status['status'] = 'error';
            $status['message'] = __('No files were processed for backup', 'swiftspeed-siberian');
            $this->update_status($status);
            return $status;
        }
        
        // Increase limits
        @ini_set('memory_limit', '2048M');
        @set_time_limit(600); // 10 minutes
        
        $this->log_memory_usage('Before creating archive');
        
        // Use parent method for consistent handling of backup history
        return parent::create_final_archive($status);
    }
    
    /**
     * Create a README file for the backup with improved information.
     *
     * @param array $status Current backup status.
     * @return string README contents.
     */
    protected function create_backup_readme($status) {
        $site_url = site_url();
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        
        $readme = "Siberian CMS File Backup\n";
        $readme .= "==============================\n\n";
        $readme .= "Backup created on: {$date}\n";
        $readme .= "Site URL: {$site_url}\n";
        $readme .= "Backup type: Files Only\n";
        $readme .= "Files backed up: {$status['files_backed_up']}\n";
        $readme .= "Directories backed up: {$status['dirs_backed_up']}\n";
        $readme .= "Total size: " . size_format($status['total_size'], 2) . "\n";
        
        // Performance metrics
        if (isset($status['time_elapsed']) && $status['time_elapsed'] > 0) {
            $minutes = floor($status['time_elapsed'] / 60);
            $seconds = round($status['time_elapsed'] % 60);
            $readme .= "Backup duration: {$minutes}m {$seconds}s\n";
            
            if (isset($status['bytes_per_second']) && $status['bytes_per_second'] > 0) {
                $speed = size_format($status['bytes_per_second'], 2);
                $readme .= "Average speed: {$speed}/s\n";
            }
        }
        
        // Memory stats
        if (!empty($status['memory_stats'])) {
            $peak_memory = isset($status['memory_stats']['peak_usage']) ? $status['memory_stats']['peak_usage'] : 0;
            if ($peak_memory > 0) {
                $readme .= "Peak memory usage: " . size_format($peak_memory, 2) . "\n";
            }
        }
        
        // User settings
        $readme .= "\nBackup settings:\n";
        $readme .= "User speed setting: " . $this->max_steps . " (scale: 2-25)\n";
        
        if (!empty($status['failed_files'])) {
            $readme .= "\nWarning: " . count($status['failed_files']) . " files could not be backed up.\n";
        }
        
        if (isset($status['excluded_file_count']) && $status['excluded_file_count'] > 0) {
            $readme .= "Files excluded: {$status['excluded_file_count']}\n";
            if (isset($status['excluded_size']) && $status['excluded_size'] > 0) {
                $readme .= "Excluded size: " . size_format($status['excluded_size'], 2) . "\n";
            }
        }
        
        $readme .= "\nCreated by SwiftSpeed Siberian Integration Plugin\n";
        return $readme;
    }
    
    /**
     * Clean up temporary files.
     *
     * @param string $dir Directory to clean up.
     * @return void
     */
    protected function cleanup_temp_files($dir) {
        if (empty($dir) || !file_exists($dir)) {
            return;
        }
        
        $this->log_message('Cleaning up temporary files in: ' . $dir);
        
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            
            @rmdir($dir);
        } catch (Exception $e) {
            $this->log_message('Error cleaning up temp files: ' . $e->getMessage());
        }
    }
    
    /**
     * Cancel an in-progress backup.
     *
     * @param array $status Current backup status.
     * @return bool True on success, false on failure.
     */
    public function cancel_backup($status) {
        if (empty($status)) {
            return false;
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        // Close all connections
        $this->connection_handler->close_all_connections();
        
        // Clean up temp directory
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        return true;
    }
    
    /**
     * Destructor to ensure connections are closed.
     */
    public function __destruct() {
        if ($this->connection_handler) {
            $this->connection_handler->close_all_connections();
        }
    }
}