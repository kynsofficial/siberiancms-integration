<?php
/**
 * Full backup functionality for Siberian CMS (files + database).
 * OPTIMIZED VERSION 3.0: Improved performance, proper component delegation,
 * better memory management and large file support
 */
class SwiftSpeed_Siberian_Full_Backup extends SwiftSpeed_Siberian_Base_Backup {
    /**
     * File backup instance.
     * 
     * @var SwiftSpeed_Siberian_File_Backup
     */
    private $file_backup;
    
    /**
     * Database backup instance.
     * 
     * @var SwiftSpeed_Siberian_DB_Backup
     */
    private $db_backup;
    
    /**
     * Current backup phase.
     * 
     * @var string
     */
    private $current_phase;
    
    /**
     * Start time for performance metrics.
     * 
     * @var float
     */
    private $start_time;
    
    /**
     * Maximum steps from user settings (2-25).
     * 
     * @var int
     */
    private $max_steps = 5;

    /**
     * Memory usage tracking
     * 
     * @var array
     */
    private $memory_stats = [
        'peak_usage' => 0,
        'last_gc_time' => 0,
        'gc_interval' => 30, // Run GC every 30 seconds
    ];
    
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();
        
        // Load required backup classes
        if (!class_exists('SwiftSpeed_Siberian_File_Backup')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-file-backup.php';
        }
        
        if (!class_exists('SwiftSpeed_Siberian_DB_Backup')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-db-backup.php';
        }
        
        $this->file_backup = new SwiftSpeed_Siberian_File_Backup();
        $this->db_backup = new SwiftSpeed_Siberian_DB_Backup();
        $this->current_phase = 'init';
        $this->start_time = microtime(true);
        
        // Get max steps from settings
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        // Ensure valid values
        if ($this->max_steps < 1 || $this->max_steps > 25) {
            $this->max_steps = 5; // Default to 5 if invalid
        }
        
        // Initialize memory tracking
        $this->init_memory_tracking();
    }

    /**
     * Initialize memory tracking
     */
    private function init_memory_tracking() {
        $this->memory_stats['last_gc_time'] = time();
        $this->memory_stats['peak_usage'] = memory_get_peak_usage(true);
        
        // Adjust GC interval based on max_steps
        $factor = ($this->max_steps - 2) / 23; // 0-1 range
        $this->memory_stats['gc_interval'] = round(60 - ($factor * 30)); // 60s to 30s range
    }

    /**
     * Log memory usage
     * 
     * @param string $checkpoint Checkpoint identifier
     */
    private function log_memory_usage($checkpoint) {
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $formatted_usage = size_format($memory_usage, 2);
        $formatted_peak = size_format($memory_peak, 2);
        $this->log_message("Memory usage at {$checkpoint}: {$formatted_usage} (peak: {$formatted_peak})");
        
        // Update peak usage
        $this->memory_stats['peak_usage'] = max($this->memory_stats['peak_usage'], $memory_peak);
    }

    /**
     * Log a message with enhanced details for full backup.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
    
    /**
     * Start the full backup process.
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
            'include_db' => true,
            'include_files' => true,
            'allow_background' => false,
            'max_steps' => $this->max_steps,
            'scheduled' => false,
            'auto_lock' => false,
        ];
        
        $params = wp_parse_args($params, $default_params);
        
        // Update max_steps if provided
        if (isset($params['max_steps']) && $params['max_steps'] > 0) {
            $this->max_steps = min(25, max(2, (int)$params['max_steps']));
        }
        
        $this->log_message('Starting backup with params: ' . json_encode([
            'storage' => $params['storage'],
            'storage_providers' => $params['storage_providers'],
            'include_db' => $params['include_db'] ? 'yes' : 'no',
            'include_files' => $params['include_files'] ? 'yes' : 'no',
            'scheduled' => $params['scheduled'] ? 'yes' : 'no',
            'auto_lock' => $params['auto_lock'] ? 'yes' : 'no',
            'max_steps' => $this->max_steps,
            'memory_limit' => ini_get('memory_limit'),
        ]));
        
        // Verify that at least one type of backup is enabled
        if (!$params['include_db'] && !$params['include_files']) {
            $this->log_message('Error: Neither database nor files are selected for backup');
            return new WP_Error('backup_error', __('Neither database nor files are selected for backup', 'swiftspeed-siberian'));
        }
        
        // Determine backup type based on parameters
        $backup_type = 'full';
        if ($params['include_db'] && !$params['include_files']) {
            $backup_type = 'db';
            $this->log_message('Backup type determined as DB-only');
        } else if (!$params['include_db'] && $params['include_files']) {
            $backup_type = 'file';
            $this->log_message('Backup type determined as Files-only');
        } else {
            $this->log_message('Backup type determined as Full (DB + Files)');
        }
        
        // Create backup ID and temporary directory
        $backup_id = 'siberian-backup-' . $backup_type . '-' . date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 8);
        $temp_dir = $this->temp_dir . $backup_id . '/';
        
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                $this->log_message('Failed to create temporary directory: ' . $temp_dir);
                return new WP_Error('backup_error', __('Failed to create temporary directory', 'swiftspeed-siberian'));
            }
        }
        
        // Initialize backup status
        $status = [
            'id' => $backup_id,
            'temp_dir' => $temp_dir,
            'backup_type' => $backup_type,
            'params' => $params,
            'started' => time(),
            'start_time' => microtime(true),
            'status' => 'phase_db',
            'message' => __('Starting database backup...', 'swiftspeed-siberian'),
            'progress' => 0,
            'db_status' => null,
            'file_status' => null,
            'current_phase' => 'db',
            'errors' => [],
            'bytes_per_second' => 0,
            'total_size' => 0,
            'db_size' => 0,
            'files_size' => 0,
            'time_elapsed' => 0,
            'allow_background' => $params['allow_background'],
            'memory_usage' => [],
            'critical_errors' => [],
            'max_steps' => $this->max_steps,
            'memory_stats' => $this->memory_stats,
        ];
        
        // Skip DB phase if not included
        if (!$params['include_db']) {
            $status['status'] = 'phase_files';
            $status['message'] = __('Starting file backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'files';
        }
        
        // Skip files phase if not included
        if (!$params['include_files'] && $params['include_db']) {
            $status['status'] = 'phase_db';
            $status['message'] = __('Starting database backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'db';
        }
        
        $this->update_status($status);
        $this->log_message('Backup started: ' . $backup_id . ' (Type: ' . $backup_type . ')');
        $this->log_memory_usage('Backup initialization');
        
        // Process the first phase
        return $this->process_next($status);
    }
    
    /**
     * Process the next phase or step of the full backup.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    public function process_next($status) {
        if (empty($status)) {
            return new WP_Error('process_error', __('Invalid backup status', 'swiftspeed-siberian'));
        }
        
        // Increase memory limit and execution time for backup process
        @ini_set('memory_limit', '2048M');
        @set_time_limit(600);
        
        // Update time elapsed
        $status['time_elapsed'] = microtime(true) - $status['start_time'];
        
        // Track memory usage
        $status['memory_usage'][] = [
            'phase' => $status['status'],
            'memory' => memory_get_usage(true),
            'time' => microtime(true)
        ];
        
        // Process based on current phase
        switch ($status['status']) {
            case 'phase_db':
                $this->log_message('Processing DB phase');
                return $this->process_db_phase($status);
                
            case 'phase_files':
                $this->log_message('Processing Files phase');
                return $this->process_files_phase($status);
                
            case 'phase_finalize':
                $this->log_message('Finalizing backup');
                return $this->finalize_backup($status);
                
            case 'completed':
            case 'error':
                return $status;
                
            default:
                $this->log_message('Unknown backup phase: ' . $status['status']);
                $status['status'] = 'error';
                $status['message'] = __('Unknown backup phase', 'swiftspeed-siberian');
                $this->update_status($status);
                return $status;
        }
    }
    
    /**
     * Process the database backup phase.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    private function process_db_phase($status) {
        // Skip DB phase if not included
        if (!$status['params']['include_db']) {
            $status['status'] = 'phase_files';
            $status['message'] = __('Starting file backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'files';
            $this->update_status($status);
            return $this->process_next($status);
        }
        
        // Create DB directory in temp dir if not exists
        $db_dir = $status['temp_dir'] . 'database/';
        if (!file_exists($db_dir)) {
            if (!wp_mkdir_p($db_dir)) {
                $this->log_message('Failed to create database directory: ' . $db_dir);
                $status['status'] = 'error';
                $status['message'] = __('Failed to create database directory', 'swiftspeed-siberian');
                $this->update_status($status);
                return $status;
            }
        }
        
        // If DB backup not started yet, start it
        if (empty($status['db_status'])) {
            // Delete any existing current_backup option to avoid conflicts
            delete_option('swsib_current_backup');
            
            // Start DB backup
            $db_params = [
                'temp_dir' => $db_dir,
                'full_backup' => true,
                'id' => $status['id'] . '-db',
                'allow_background' => $status['allow_background'],
                'max_steps' => $this->max_steps,
            ];
            
            $this->log_message('Starting DB backup step');
            $this->log_memory_usage('Before DB backup start');
            
            // Start the DB backup using the DB backup class
            $db_status = $this->db_backup->start_backup($db_params);
            
            if (is_wp_error($db_status)) {
                $this->log_message('Failed to start DB backup: ' . $db_status->get_error_message());
                $status['status'] = 'error';
                $status['message'] = $db_status->get_error_message();
                $this->update_status($status);
                return $status;
            }
            
            $status['db_status'] = $db_status;
            $status['message'] = __('Processing database backup...', 'swiftspeed-siberian');
            $status['progress'] = $db_status['progress'] * 0.5;
            $this->update_status($status);
            
            // If DB backup completed in one go
            if ($db_status['status'] === 'completed') {
                $this->log_message('DB backup completed in one step');
                return $this->handle_db_phase_completed($status);
            }
            
            return $status;
        }
        
        // Otherwise, process next DB backup step
        $db_status = $status['db_status'];
        
        // Process next table
        $this->log_message('Processing next DB table');
        $db_status = $this->db_backup->process_next($db_status);
        
        if (is_wp_error($db_status)) {
            $this->log_message('Error processing DB backup: ' . $db_status->get_error_message());
            $status['status'] = 'error';
            $status['message'] = $db_status->get_error_message();
            $this->update_status($status);
            return $status;
        }
        
        // Update status with new DB status
        $status['db_status'] = $db_status;
        $status['message'] = $db_status['message'];
        $status['progress'] = $db_status['progress'] * 0.5;
        
        // Update DB size tracking
        if (isset($db_status['db_size'])) {
            $status['db_size'] = $db_status['db_size'];
            $status['total_size'] = $status['db_size'] + (isset($status['files_size']) ? $status['files_size'] : 0);
        }
        
        // Update speed metrics if available
        if (isset($db_status['bytes_per_second'])) {
            $status['bytes_per_second'] = $db_status['bytes_per_second'];
            
            // Add speed to message
            if ($status['bytes_per_second'] > 0) {
                $speed_text = size_format($status['bytes_per_second'], 2) . '/s';
                $size_text = isset($status['db_size']) ? size_format($status['db_size'], 2) : '';
                
                if (!empty($size_text)) {
                    $status['message'] .= ' (' . $size_text . ' at ' . $speed_text . ')';
                }
            }
        }
        
        $this->update_status($status);
        
        // Check if DB backup is completed
        if ($db_status['status'] === 'completed') {
            $this->log_message('DB backup phase completed');
            return $this->handle_db_phase_completed($status);
        }
        
        return $status;
    }
    
    /**
     * Handle completion of the DB backup phase.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function handle_db_phase_completed($status) {
        // Copy DB fields to main status
        if (!empty($status['db_status'])) {
            $status['total_tables'] = $status['db_status']['total_tables'];
            $status['total_rows'] = $status['db_status']['total_rows'];
            $status['db_size'] = isset($status['db_status']['db_size']) ? $status['db_status']['db_size'] : 0;
            $status['total_size'] = $status['db_size'] + (isset($status['files_size']) ? $status['files_size'] : 0);
            
            // Copy any errors
            if (!empty($status['db_status']['errors'])) {
                $status['errors'] = array_merge($status['errors'], $status['db_status']['errors']);
            }
            
            // Copy any critical errors
            if (!empty($status['db_status']['critical_errors'])) {
                $status['critical_errors'] = array_merge(
                    isset($status['critical_errors']) ? $status['critical_errors'] : [], 
                    $status['db_status']['critical_errors']
                );
            }
        }
        
        // Clean up memory
        $status['db_status'] = null;
        $this->memory_cleanup();
        
        // Log memory usage
        $this->log_memory_usage('After DB backup completion');
        
        // Move to files phase or finalize
        if ($status['params']['include_files']) {
            $this->log_message('Moving to files phase after DB completion');
            $status['status'] = 'phase_files';
            $status['message'] = __('Starting file backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'files';
        } else {
            $this->log_message('Moving to finalize phase (DB-only backup)');
            $status['status'] = 'phase_finalize';
            $status['message'] = __('Finalizing backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'finalize';
        }
        
        $this->update_status($status);
        return $this->process_next($status);
    }
    
    /**
     * Process the files backup phase.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    private function process_files_phase($status) {
        // Skip files phase if not included
        if (!$status['params']['include_files']) {
            $status['status'] = 'phase_finalize';
            $status['message'] = __('Finalizing backup...', 'swiftspeed-siberian');
            $status['current_phase'] = 'finalize';
            $this->update_status($status);
            return $this->process_next($status);
        }
        
        // If file backup not started yet, start it
        if (empty($status['file_status'])) {
            // Delete any existing current_backup option to avoid conflicts
            delete_option('swsib_current_backup');
            
            // Create files directory
            $files_dir = $status['temp_dir'] . 'files/';
            if (!file_exists($files_dir)) {
                if (!wp_mkdir_p($files_dir)) {
                    $this->log_message('Failed to create files directory: ' . $files_dir);
                    $status['status'] = 'error';
                    $status['message'] = __('Failed to create files directory', 'swiftspeed-siberian');
                    $this->update_status($status);
                    return $status;
                }
            }
            
            $this->log_memory_usage('Before file backup start');
            $this->log_message('Starting file backup step');
            
            // Start file backup with improved parameters
            $file_params = [
                'storage' => 'none',
                'storage_providers' => $status['params']['storage_providers'],
                'include_paths' => $status['params']['include_paths'],
                'exclude_paths' => $status['params']['exclude_paths'],
                'temp_dir' => $files_dir,
                'full_backup' => true,
                'id' => $status['id'] . '-files',
                'allow_background' => $status['allow_background'],
                'max_steps' => $this->max_steps, // Pass through max_steps to file backup
            ];
            
            $file_status = $this->file_backup->start_backup($file_params);
            
            if (is_wp_error($file_status)) {
                $this->log_message('Failed to start file backup: ' . $file_status->get_error_message());
                $status['status'] = 'error';
                $status['message'] = $file_status->get_error_message();
                $this->update_status($status);
                return $status;
            }
            
            $status['file_status'] = $file_status;
            $status['message'] = __('Processing file backup...', 'swiftspeed-siberian');
            
            // Calculate progress: DB was 50%, files start at 50%
            $file_progress = $status['params']['include_db'] ? 50 : 0;
            $file_progress += $file_status['progress'] * ($status['params']['include_db'] ? 0.5 : 1);
            // FIX: Explicit cast to integer using round()
            $status['progress'] = round($file_progress);
            
            // Update file size tracking
            if (isset($file_status['total_size'])) {
                $status['files_size'] = $file_status['total_size'];
                $status['total_size'] = (isset($status['db_size']) ? $status['db_size'] : 0) + $status['files_size'];
            }
            
            // Check for large file processing
            if (isset($file_status['is_processing_large_file']) && $file_status['is_processing_large_file']) {
                $status['is_processing_large_file'] = true;
                $this->log_message('Processing large file in file backup');
            }
            
            $this->update_status($status);
            
            // If file backup completed in one go
            if ($file_status['status'] === 'completed') {
                $this->log_message('File backup completed in one step');
                return $this->handle_files_phase_completed($status);
            }
            
            return $status;
        }
        
        // Otherwise, process next files backup step
        $file_status = $status['file_status'];
        
        $this->log_memory_usage('Before processing file batch');
        
        // Process next batch of files
        $this->log_message('Processing next file batch');
        $file_status = $this->file_backup->process_next($file_status);
        
        if (is_wp_error($file_status)) {
            $this->log_message('Error processing file backup: ' . $file_status->get_error_message());
            $status['status'] = 'error';
            $status['message'] = $file_status->get_error_message();
            $this->update_status($status);
            return $status;
        }
        
        // Update status with new file status
        $status['file_status'] = $file_status;
        $status['message'] = $file_status['message'];
        
        // Calculate progress: DB was 50%, files are another 50%
        $file_progress = $status['params']['include_db'] ? 50 : 0;
        $file_progress += $file_status['progress'] * ($status['params']['include_db'] ? 0.5 : 1);
        // FIX: Explicit cast to integer using round()
        $status['progress'] = round($file_progress);
        
        // Update current file info
        if (!empty($file_status['current_file'])) {
            $status['current_file'] = $file_status['current_file'];
        }
        
        // Pass through large file processing flag
        if (isset($file_status['is_processing_large_file'])) {
            $status['is_processing_large_file'] = $file_status['is_processing_large_file'];
            
            if ($status['is_processing_large_file']) {
                $this->log_message('Processing large file: ' . (isset($file_status['current_file']) ? $file_status['current_file'] : 'unknown'));
            }
        }
        
        // Update file size tracking
        if (isset($file_status['total_size'])) {
            $status['files_size'] = $file_status['total_size'];
            $status['total_size'] = (isset($status['db_size']) ? $status['db_size'] : 0) + $status['files_size'];
        }
        
        // Update speed metrics
        if (isset($file_status['bytes_per_second'])) {
            $status['bytes_per_second'] = $file_status['bytes_per_second'];
        }
        
        $this->update_status($status);
        
        // Check if file backup is completed
        if ($file_status['status'] === 'completed') {
            $this->log_message('File backup phase completed');
            return $this->handle_files_phase_completed($status);
        }
        
        return $status;
    }
    
    /**
     * Handle completion of the files backup phase.
     *
     * @param array $status Current backup status.
     * @return array Updated backup status.
     */
    private function handle_files_phase_completed($status) {
        // Copy file fields to main status
        if (!empty($status['file_status'])) {
            $status['processed_files'] = $status['file_status']['processed_files'];
            $status['files_size'] = isset($status['file_status']['total_size']) ? $status['file_status']['total_size'] : 0;
            $status['total_size'] = (isset($status['db_size']) ? $status['db_size'] : 0) + $status['files_size'];
            
            // Copy any errors
            if (!empty($status['file_status']['errors'])) {
                $status['errors'] = array_merge(
                    isset($status['errors']) ? $status['errors'] : [],
                    $status['file_status']['errors']
                );
            }
            
            // Copy any critical errors
            if (!empty($status['file_status']['critical_errors'])) {
                $status['critical_errors'] = array_merge(
                    isset($status['critical_errors']) ? $status['critical_errors'] : [], 
                    $status['file_status']['critical_errors']
                );
            }
            
            // Copy any other important metrics
            if (isset($status['file_status']['files_backed_up'])) {
                $status['files_backed_up'] = $status['file_status']['files_backed_up'];
            }
            
            if (isset($status['file_status']['dirs_backed_up'])) {
                $status['dirs_backed_up'] = $status['file_status']['dirs_backed_up'];
            }
        }
        
        // Clean up memory
        $status['file_status'] = null;
        $this->memory_cleanup();
        
        // Log memory usage
        $this->log_memory_usage('After file backup completion');
        
        // Move to finalize phase
        $status['status'] = 'phase_finalize';
        $status['message'] = __('Finalizing backup...', 'swiftspeed-siberian');
        $status['current_phase'] = 'finalize';
        
        $this->update_status($status);
        return $this->process_next($status);
    }
    
    /**
     * Clean up memory after processing
     * 
     * @return void
     */
    protected function memory_cleanup() {
        // Check if it's time for garbage collection
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
     * Finalize the backup by creating the archive.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    private function finalize_backup($status) {
        $this->log_message('Creating final archive');
        $this->log_memory_usage('Before creating archive');
        
        // Check for critical errors that would make the backup unusable
        if (!empty($status['critical_errors'])) {
            $this->log_message('Backup contains critical errors - failing the backup');
            $error_messages = array_map(function($error) {
                return $error['message'];
            }, $status['critical_errors']);
            
            $status['status'] = 'error';
            $status['message'] = __('Backup failed due to critical errors: ', 'swiftspeed-siberian') . implode('; ', $error_messages);
            $this->update_status($status);
            return $status;
        }
        
        // Validate that we have something to backup
        $has_files = !empty($status['processed_files']);
        $has_db = !empty($status['total_tables']);
        
        if ((!$has_files && $status['params']['include_files']) && 
            (!$has_db && $status['params']['include_db'])) {
            $this->log_message('No files or database tables were processed for backup');
            $status['status'] = 'error';
            $status['message'] = __('No files or database tables were processed for backup', 'swiftspeed-siberian');
            $this->update_status($status);
            return $status;
        }
        
        // Add backup performance stats
        $status['bytes_per_second_avg'] = 0;
        if (isset($status['total_size']) && $status['total_size'] > 0 && $status['time_elapsed'] > 0) {
            $status['bytes_per_second_avg'] = $status['total_size'] / $status['time_elapsed'];
        }
        
        // Use parent class's create_final_archive method to ensure consistent backup history handling
        return parent::create_final_archive($status);
    }
    
    /**
     * Create a README file for the backup with enhanced information.
     *
     * @param array $status Current backup status.
     * @return string README contents.
     */
    protected function create_backup_readme($status) {
        $site_url = site_url();
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        
        $readme = "Siberian CMS Backup\n";
        $readme .= "==============================\n\n";
        $readme .= "Backup created on: {$date}\n";
        $readme .= "Site URL: {$site_url}\n";
        $readme .= "Backup type: " . ucfirst($status['backup_type']) . "\n";
        
        // Performance metrics
        if (isset($status['time_elapsed']) && $status['time_elapsed'] > 0) {
            $minutes = floor($status['time_elapsed'] / 60);
            $seconds = round($status['time_elapsed'] % 60);
            $readme .= "Backup duration: {$minutes}m {$seconds}s\n";
            
            if (isset($status['bytes_per_second_avg']) && $status['bytes_per_second_avg'] > 0) {
                $speed = size_format($status['bytes_per_second_avg'], 2);
                $readme .= "Average speed: {$speed}/s\n";
            }
        }
        
        if ($status['backup_type'] === 'file') {
            $readme .= "Backed up files: " . (isset($status['files_backed_up']) ? $status['files_backed_up'] : count($status['processed_files'])) . "\n";
            $readme .= "Total size: " . size_format($status['total_size'], 2) . "\n";
        } elseif ($status['backup_type'] === 'db') {
            $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
            $readme .= "Database: " . (isset($db_options['database']) ? $db_options['database'] : 'Unknown') . "\n";
            $readme .= "Tables: " . $status['total_tables'] . "\n";
            $readme .= "Total rows: " . $status['total_rows'] . "\n";
            $readme .= "Database size: " . size_format($status['db_size'], 2) . "\n";
        } elseif ($status['backup_type'] === 'full') {
            $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
            $readme .= "Database: " . (isset($db_options['database']) ? $db_options['database'] : 'Unknown') . "\n";
            $readme .= "Tables: " . (isset($status['total_tables']) ? $status['total_tables'] : '0') . "\n";
            $readme .= "Total rows: " . (isset($status['total_rows']) ? $status['total_rows'] : '0') . "\n";
            $readme .= "Database size: " . size_format(isset($status['db_size']) ? $status['db_size'] : 0, 2) . "\n";
            $readme .= "Backed up files: " . (isset($status['files_backed_up']) ? $status['files_backed_up'] : (isset($status['processed_files']) ? count($status['processed_files']) : '0')) . "\n";
            $readme .= "Total file size: " . size_format(isset($status['files_size']) ? $status['files_size'] : 0, 2) . "\n";
            $readme .= "Total backup size: " . size_format($status['total_size'], 2) . "\n";
        }
        
        // CRITICAL: Add error information prominently
        if (!empty($status['critical_errors'])) {
            $readme .= "\n**CRITICAL ERRORS OCCURRED**\n";
            $readme .= "WARNING: " . count($status['critical_errors']) . " critical errors occurred during backup.\n";
            $readme .= "This backup may be incomplete or corrupted.\n";
            foreach ($status['critical_errors'] as $error) {
                $readme .= "- " . $error['message'] . "\n";
            }
            $readme .= "\n";
        }
        
        if (!empty($status['errors'])) {
            $readme .= "\nWarning: " . count($status['errors']) . " errors occurred during backup.\n";
        }
        
        if (!empty($status['failed_files'])) {
            $readme .= "Warning: " . count($status['failed_files']) . " files could not be backed up.\n";
        }
        
        // Add user speed settings info
        $readme .= "\nBackup settings:\n";
        $readme .= "User speed setting: " . $this->max_steps . " (scale: 2-25)\n";
        
        // Add memory usage info
        if (isset($this->memory_stats['peak_usage']) && $this->memory_stats['peak_usage'] > 0) {
            $readme .= "Peak memory usage: " . size_format($this->memory_stats['peak_usage'], 2) . "\n";
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
     * Recursively delete a directory and its contents.
     *
     * @param string $dir Directory to delete.
     */
    private function recursive_delete($dir) {
        if (!file_exists($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->recursive_delete($path);
            } else {
                @unlink($path);
            }
        }
        
        @rmdir($dir);
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
        
        // Clean up temp directory
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        return true;
    }
    
    /**
     * Cleanup old backups based on backup type.
     *
     * @param string $backup_type Type of backup (db, file, full).
     * @return void
     */
    protected function cleanup_old_backups($backup_type) {
        $this->log_message('Running cleanup for old backups of type: ' . $backup_type);
        
        // Get backup history
        $history = get_option('swsib_backup_history', array());
        
        if (empty($history)) {
            return;
        }
        
        // Get backup settings
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        
        // Get maximum number of backups to keep
        $max_backups = 10; // Default
        
        if ($backup_type === 'db') {
            $max_backups = isset($backup_settings['max_backups_db']) ? intval($backup_settings['max_backups_db']) : 10;
        } elseif ($backup_type === 'file') {
            $max_backups = isset($backup_settings['max_backups_file']) ? intval($backup_settings['max_backups_file']) : 5;
        } elseif ($backup_type === 'full') {
            $max_backups = isset($backup_settings['max_backups_full']) ? intval($backup_settings['max_backups_full']) : 3;
        }
        
        $this->log_message('Maximum ' . $backup_type . ' backups to keep: ' . $max_backups);
        
        // Get backups of this type
        $backups = array();
        
        foreach ($history as $id => $backup) {
            if ($backup['backup_type'] === $backup_type) {
                $backups[$id] = $backup;
            }
        }
        
        // If we don't have more than the maximum, return
        if (count($backups) <= $max_backups) {
            return;
        }
        
        // Sort backups by creation date (oldest first)
        uasort($backups, function($a, $b) {
            return $a['created'] - $b['created'];
        });
        
        // Get the number to delete
        $to_delete = count($backups) - $max_backups;
        
        $this->log_message('Need to delete ' . $to_delete . ' old ' . $backup_type . ' backups');
        
        // Delete the oldest backups
        $deleted = 0;
        
        foreach ($backups as $id => $backup) {
            if ($deleted >= $to_delete) {
                break;
            }
            
            // Skip locked backups
            if (!empty($backup['locked'])) {
                $this->log_message('Skipping locked backup: ' . $id);
                continue;
            }
            
            $this->log_message('Deleting old backup: ' . $id);
            
            // Delete the backup file
            if (isset($backup['path']) && file_exists($backup['path'])) {
                @unlink($backup['path']);
                $this->log_message('Deleted local file: ' . $backup['path']);
            }
            
            // Delete from external storage if applicable
            if (isset($backup['storage']) && $backup['storage'] !== 'local' && !empty($backup['storage_info'])) {
                $storage_type = $backup['storage'];
                $provider = $this->get_storage_provider($storage_type);
                
                if ($provider) {
                    $provider->initialize();
                    
                    // Determine file ID or path to delete
                    $file_id = '';
                    
                    if (isset($backup['storage_info']['file_id'])) {
                        $file_id = $backup['storage_info']['file_id'];
                    } elseif (isset($backup['storage_info']['file'])) {
                        $file_id = $backup['storage_info']['file'];
                    } elseif (isset($backup['file'])) {
                        $file_id = $backup['file'];
                    }
                    
                    if (!empty($file_id)) {
                        $provider->delete_file($file_id);
                        $this->log_message('Deleted from ' . $storage_type . ': ' . $file_id);
                    }
                }
            }
            
            // Remove from history
            unset($history[$id]);
            $deleted++;
        }
        
        if ($deleted > 0) {
            update_option('swsib_backup_history', $history);
            $this->log_message('Updated backup history after deleting ' . $deleted . ' old backups');
        }
    }
}