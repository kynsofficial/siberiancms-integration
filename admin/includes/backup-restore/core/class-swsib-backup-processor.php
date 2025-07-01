<?php
/**
 * Backup Processor - Resilient deletion with retry logic
 */
class SwiftSpeed_Siberian_Backup_Processor {
    
    private $options;
    private $storage_manager;
    private $file_backup;
    private $full_backup;
    private $backup_dir;
    private $temp_dir;
    private $max_steps = 5;
    private $step_time_limit = 60;
    private $stall_timeout = 300;
    private $large_file_timeout = 600;
    private $deletion_config = [
        'max_retries' => 3,
        'base_delay' => 2,
        'max_delay' => 30,
        'timeout' => 60,
    ];
    
    public function __construct() {
        $this->options = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->temp_dir = $this->backup_dir . 'temp/';
        $this->ensure_directories();
        $this->init_components();
        $this->register_ajax_handlers();
        
        add_action('swsib_process_background_backup', array($this, 'process_background_backup'), 5);
        
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        if ($this->max_steps < 1 || $this->max_steps > 25) {
            $this->max_steps = 5;
        }
        
        if ($this->max_steps > 10) {
            $this->step_time_limit = 30;
        }
        
        add_action('admin_init', array($this, 'admin_init_check_backup'), 20);
        add_action('wp_loaded', array($this, 'front_end_check_backup'), 20);
    }
    
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
        
        $htaccess_file = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, "Order deny,allow\nDeny from all");
        }
        
        $index_file = $this->backup_dir . 'index.php';
        if (!file_exists($index_file)) {
            @file_put_contents($index_file, "<?php\n// Silence is golden.");
        }
        
        return true;
    }
    
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    public function set_max_steps($max_steps) {
        $this->max_steps = intval($max_steps);
        if ($this->max_steps < 1 || $this->max_steps > 25) {
            $this->max_steps = 5;
        }
    }
    
    public function admin_init_check_backup() {
        $last_admin_check = get_option('swsib_last_admin_backup_check', 0);
        $current_time = time();
        
        if (($current_time - $last_admin_check) > 120) {
            $this->check_background_backup();
            update_option('swsib_last_admin_backup_check', $current_time);
        }
    }
    
    public function front_end_check_backup() {
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        $last_frontend_check = get_option('swsib_last_frontend_backup_check', 0);
        $current_time = time();
        
        if (($current_time - $last_frontend_check) > 600) {
            $this->check_background_backup();
            update_option('swsib_last_frontend_backup_check', $current_time);
        }
    }
    
    private function check_background_backup() {
        $current_backup = get_option('swsib_current_backup', array());
        $background_flag = $this->get_background_flag();
        
        if (!empty($current_backup) && 
            in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading')) && 
            $background_flag) {
            
            $heartbeat = get_option('swsib_backup_heartbeat', 0);
            $current_time = time();
            
            $timeout_threshold = $this->stall_timeout;
            
            if (isset($current_backup['is_processing_large_file']) && $current_backup['is_processing_large_file']) {
                $timeout_threshold = $this->large_file_timeout;
                $this->log_message('Using extended timeout threshold for large file processing');
            }
            
            $stalled = ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold);
            
            if (!$stalled && isset($current_backup['started'])) {
                $duration = $current_time - $current_backup['started'];
                
                if ($duration > 7200) {
                    $this->log_message('Backup has been running for more than 2 hours, checking for progress');
                    
                    $last_update = get_option('swsib_last_backup_update', 0);
                    if ($last_update > 0 && ($current_time - $last_update) > 600) {
                        $stalled = true;
                        $this->log_message('No progress in last 10 minutes, marking as stalled');
                    }
                }
            }
            
            if ($stalled) {
                $this->log_message('Found stalled backup (no heartbeat for ' . $timeout_threshold . '+ seconds), attempting to resume');
                
                $checkpoint_file = $this->backup_dir . 'temp/' . $current_backup['id'] . '/checkpoint.json';
                if (file_exists($checkpoint_file)) {
                    $this->log_message('Checkpoint found, attempting to resume backup from checkpoint...');
                    
                    $checkpoint_data = json_decode(file_get_contents($checkpoint_file), true);
                    if (!empty($checkpoint_data)) {
                        $current_backup['processed_files'] = $checkpoint_data['processed_files'];
                        $current_backup['processed_dirs'] = $checkpoint_data['processed_dirs'];
                        $current_backup['total_size'] = $checkpoint_data['total_size'];
                        $current_backup['pending_files'] = $checkpoint_data['pending_files'];
                        $current_backup['large_files_queue'] = $checkpoint_data['large_files_queue'];
                        $current_backup['retry_files'] = $checkpoint_data['retry_files'];
                        $current_backup['status'] = 'processing';
                        $current_backup['message'] = __('Resuming backup from checkpoint...', 'swiftspeed-siberian');
                        
                        update_option('swsib_current_backup', $current_backup);
                        $this->log_message('Successfully resumed backup from checkpoint');
                    }
                }
                
                delete_option('swsib_backup_process_lock');
            } else {
                $this->log_message('Found active backup, continuing in background');
            }
            
            do_action('swsib_process_background_backup');
            
            if (!wp_next_scheduled('swsib_process_background_backup')) {
                wp_schedule_single_event(time() + 30, 'swsib_process_background_backup');
            }
        }
    }
    
    private function init_components() {
        $this->file_backup = new SwiftSpeed_Siberian_File_Backup();
        $this->full_backup = new SwiftSpeed_Siberian_Full_Backup();
    }
    
    private function register_ajax_handlers() {
        add_action('wp_ajax_swsib_start_backup', array($this, 'ajax_start_backup'));
        add_action('wp_ajax_swsib_backup_progress', array($this, 'ajax_backup_progress'));
        add_action('wp_ajax_swsib_process_next_backup_step', array($this, 'ajax_process_next_backup_step'));
        add_action('wp_ajax_swsib_cancel_backup', array($this, 'ajax_cancel_backup'));
        add_action('wp_ajax_swsib_get_backup_history', array($this, 'ajax_get_backup_history'));
        add_action('wp_ajax_swsib_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_swsib_lock_backup', array($this, 'ajax_lock_backup'));
        add_action('wp_ajax_swsib_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_swsib_ping_background', array($this, 'ajax_ping_background'));
        add_action('wp_ajax_swsib_force_check_backup', array($this, 'ajax_force_check_backup'));
        add_action('wp_ajax_nopriv_swsib_force_check_backup', array($this, 'ajax_force_check_backup'));
        add_action('wp_ajax_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_nopriv_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
    }
    
    public function ajax_start_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            $this->log_message('Backup failed: Security check failed');
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            $this->log_message('Backup failed: Permission denied');
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $backup_type = isset($_POST['backup_type']) ? sanitize_key($_POST['backup_type']) : 'full';
        
        $storage_providers = [];
        if (isset($_POST['storage_providers']) && is_array($_POST['storage_providers'])) {
            $storage_providers = array_map('sanitize_key', $_POST['storage_providers']);
        } elseif (isset($_POST['storage_providers'])) {
            $providers_string = sanitize_text_field($_POST['storage_providers']);
            $storage_providers = array_map('trim', explode(',', $providers_string));
            $storage_providers = array_map('sanitize_key', $storage_providers);
        }
        
        if (empty($storage_providers)) {
            $storage_providers = ['local'];
        }
        
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        $storage_providers = array_unique(array_filter($storage_providers));
        
        $include_all_files = isset($_POST['include_all_files']) && $_POST['include_all_files'] === '1';
        $auto_lock = isset($_POST['lock_backup']) && $_POST['lock_backup'] === '1';
        
        $this->log_message('Starting backup of type: ' . $backup_type . 
                       ', storage providers: ' . implode(',', $storage_providers) . 
                       ', include_all_files: ' . ($include_all_files ? 'yes' : 'no') . 
                       ', auto_lock: ' . ($auto_lock ? 'yes' : 'no'));
        
        foreach ($storage_providers as $provider) {
            if ($provider !== 'local') {
                $storage_provider_obj = $this->get_storage_provider($provider);
                if (is_wp_error($storage_provider_obj)) {
                    wp_send_json_error(array('message' => sprintf(__('Storage provider %s is not available: %s', 'swiftspeed-siberian'), $provider, $storage_provider_obj->get_error_message())));
                }
            }
        }
        
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
        
        delete_option('swsib_backup_process_lock');
        delete_option('swsib_backup_heartbeat');
        
        $params = array(
            'storage' => $storage_providers[0],
            'storage_providers' => $storage_providers,
            'include_paths' => $include_paths,
            'exclude_paths' => $exclude_paths,
            'allow_background' => true,
            'auto_lock' => $auto_lock,
            'scheduled' => false,
            'max_steps' => $this->max_steps,
        );
        
        if ($backup_type === 'full') {
            $params['include_db'] = true;
            $params['include_files'] = true;
            $this->log_message('Starting full backup with both DB and files');
            $result = $this->full_backup->start_backup($params);
        } elseif ($backup_type === 'files') {
            $params['include_db'] = false;
            $params['include_files'] = true;
            $this->log_message('Starting files-only backup');
            $result = $this->full_backup->start_backup($params);
        } else if ($backup_type === 'db') {
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
        
        $this->update_background_flag(true);
        $this->update_backup_heartbeat();
        $this->process_background_backup();
        
        wp_send_json_success($result);
    }
    
    public function ajax_backup_progress() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup found', 'swiftspeed-siberian')));
        }
        
        $timeout_threshold = $this->stall_timeout;
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $timeout_threshold = $this->large_file_timeout;
        }
        
        $heartbeat = get_option('swsib_backup_heartbeat', 0);
        $current_time = time();
        
        if ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold) {
            $this->log_message('Backup appears stalled - last heartbeat: ' . date('Y-m-d H:i:s', $heartbeat));
            
            if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                if (($current_time - $heartbeat) < $this->large_file_timeout) {
                    $this->log_message('Processing large file, extending timeout');
                    
                    $status['message'] = sprintf(
                        __('Processing large file, please wait... (%s)', 'swiftspeed-siberian'),
                        isset($status['current_file']) ? basename($status['current_file']) : ''
                    );
                } else {
                    if ($status['status'] !== 'error') {
                        $status['status'] = 'error';
                        $status['message'] = __('Backup process appears to be stalled while processing large file. Please try again.', 'swiftspeed-siberian');
                        update_option('swsib_current_backup', $status);
                    }
                }
            } else {
                if ($status['status'] !== 'error') {
                    $status['status'] = 'error';
                    $status['message'] = __('Backup process appears to be stalled. Please try again.', 'swiftspeed-siberian');
                    update_option('swsib_current_backup', $status);
                }
            }
        }
        
        if (isset($status['started'])) {
            $status['elapsed_time'] = time() - $status['started'];
        }
        
        if (isset($status['start_time'])) {
            $status['time_elapsed'] = microtime(true) - $status['start_time'];
        }
        
        $this->enhance_backup_progress_data($status);
        
        if ($status['status'] !== 'completed' && $status['status'] !== 'error') {
            $this->update_backup_heartbeat();
        }
        
        if ($status['status'] !== 'completed' && $status['status'] !== 'error') {
            do_action('swsib_process_background_backup');
        }
        
        wp_send_json_success($status);
    }
    
    private function enhance_backup_progress_data(&$status) {
        if ($status['backup_type'] === 'file') {
            if (isset($status['current_file_index']) && isset($status['total_files']) && $status['total_files'] > 0) {
                $status['progress'] = min(95, ($status['current_file_index'] / $status['total_files']) * 100);
                
                if (empty($status['current_file']) && !empty($status['processed_files'])) {
                    $last_file = end($status['processed_files']);
                    if (isset($last_file['path'])) {
                        $status['current_file'] = $last_file['path'];
                    }
                }
                
                if (!isset($status['message']) || empty($status['message'])) {
                    $status['message'] = sprintf(
                        __('Processing files... %d of %d (%.2f%%)', 'swiftspeed-siberian'),
                        $status['current_file_index'],
                        $status['total_files'],
                        $status['progress']
                    );
                }
                
                if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                    $status['message'] .= ' - ' . __('Processing large file', 'swiftspeed-siberian');
                    if (isset($status['current_file_progress'])) {
                        $status['message'] .= ' (' . round($status['current_file_progress']) . '%)';
                    }
                }
            }
        }
        
        if ($status['backup_type'] === 'full') {
            if (isset($status['current_phase'])) {
                if ($status['current_phase'] === 'db' && isset($status['total_tables'])) {
                    if (isset($status['db_status']) && !isset($status['processed_tables'])) {
                        $status['processed_tables'] = isset($status['db_status']['processed_tables']) ? 
                            $status['db_status']['processed_tables'] : 0;
                        $status['total_tables'] = isset($status['db_status']['total_tables']) ? 
                            $status['db_status']['total_tables'] : 0;
                        $status['current_table'] = isset($status['db_status']['current_table']) ? 
                            $status['db_status']['current_table'] : '';
                        
                        if (isset($status['db_status']['db_size'])) {
                            $status['db_size'] = $status['db_status']['db_size'];
                        }
                    }
                } elseif ($status['current_phase'] === 'files' && isset($status['file_status'])) {
                    if (!isset($status['current_file']) && isset($status['file_status']['current_file'])) {
                        $status['current_file'] = $status['file_status']['current_file'];
                        $status['current_file_index'] = isset($status['file_status']['current_file_index']) ? 
                            $status['file_status']['current_file_index'] : 0;
                        $status['total_files'] = isset($status['file_status']['total_files']) ? 
                            $status['file_status']['total_files'] : 0;
                        
                        if (isset($status['file_status']['total_size'])) {
                            $status['files_size'] = $status['file_status']['total_size'];
                        }
                    }
                }
            }
            
            if (!isset($status['total_size']) && (isset($status['db_size']) || isset($status['files_size']))) {
                $status['total_size'] = (isset($status['db_size']) ? $status['db_size'] : 0) + 
                                       (isset($status['files_size']) ? $status['files_size'] : 0);
            }
        }
        
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
            
            if (isset($status['db_size']) && $status['db_size'] > 0) {
                $size_text = size_format($status['db_size'], 2);
                if (!strpos($status['message'], $size_text)) {
                    $status['message'] .= ' (' . $size_text . ')';
                }
            }
        }
        
        if (function_exists('memory_get_usage') && !strpos($status['message'], 'Memory:')) {
            $memory_usage = memory_get_usage(true);
            $memory_limit = $this->get_memory_limit();
            $memory_percentage = round(($memory_usage / $memory_limit) * 100);
            
            if ($memory_percentage > 50) {
                $status['message'] .= sprintf(' (Memory: %s%%)', $memory_percentage);
            }
        }
    }
    
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        $unit = strtoupper(substr($memory_limit, -1));
        $value = intval(substr($memory_limit, 0, -1));
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }
        
        if ($value <= 0) {
            return 2147483648;
        }
        
        return $value;
    }
    
    public function ajax_process_next_backup_step() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup found', 'swiftspeed-siberian')));
        }
        
        $this->update_background_flag(false);
        
        $result = $this->full_backup->process_next($status);
        
        if (is_wp_error($result)) {
            $this->log_message('Error processing backup step: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        if (isset($result['started'])) {
            $result['elapsed_time'] = time() - $result['started'];
        }
        
        if ($result['status'] !== 'completed' && $result['status'] !== 'error') {
            $this->update_background_flag(true);
        } else if ($result['status'] === 'completed' && isset($result['id'])) {
            $this->ensure_scheduled_flag_in_history($result['id'], !empty($result['params']['scheduled']));
        }
        
        $this->update_backup_heartbeat();
        
        wp_send_json_success($result);
    }
    
    private function ensure_scheduled_flag_in_history($backup_id, $is_scheduled) {
        $history = get_option('swsib_backup_history', array());
        
        if (isset($history[$backup_id])) {
            if ((!isset($history[$backup_id]['scheduled']) && $is_scheduled) || 
                (isset($history[$backup_id]['scheduled']) && $history[$backup_id]['scheduled'] !== $is_scheduled)) {
                
                $this->log_message('Updating scheduled flag in history for backup: ' . $backup_id . ' to ' . ($is_scheduled ? 'true' : 'false'));
                $history[$backup_id]['scheduled'] = $is_scheduled;
                update_option('swsib_backup_history', $history);
            }
            
            if (isset($history[$backup_id]['storage_providers']) && is_array($history[$backup_id]['storage_providers'])) {
                if (!isset($history[$backup_id]['uploaded_to']) || !is_array($history[$backup_id]['uploaded_to'])) {
                    $history[$backup_id]['uploaded_to'] = $history[$backup_id]['storage_providers'];
                    update_option('swsib_backup_history', $history);
                }
            }
        }
    }
    
    public function process_background_backup() {
        @set_time_limit($this->step_time_limit);
        @ini_set('memory_limit', '2048M');
        
        if (!$this->get_background_flag()) {
            $this->log_message('Background processing flag not set, exiting');
            return;
        }
        
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status) || $status['status'] === 'completed' || $status['status'] === 'error') {
            $this->update_background_flag(false);
            $this->log_message('No active backup or backup already completed/failed, disabling background processing');
            return;
        }
        
        $lock = get_option('swsib_backup_process_lock', 0);
        $current_time = time();
        
        if ($lock > 0 && ($current_time - $lock) < 30) {
            $this->log_message('Another backup process appears to be running (lock time: ' . date('Y-m-d H:i:s', $lock) . '), skipping this run');
            return;
        }
        
        update_option('swsib_backup_process_lock', $current_time);
        
        $timeout_threshold = $this->stall_timeout;
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $timeout_threshold = $this->large_file_timeout;
        }
        
        $heartbeat = get_option('swsib_backup_heartbeat', 0);
        
        if ($heartbeat > 0 && ($current_time - $heartbeat) > $timeout_threshold) {
            if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
                if (($current_time - $heartbeat) < $this->large_file_timeout) {
                    $this->log_message('Processing large file, extending timeout');
                } else {
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
                $this->log_message('Background processing: Backup appears stalled - last heartbeat: ' . date('Y-m-d H:i:s', $heartbeat));
                
                if ($status['status'] !== 'error') {
                    $status['status'] = 'error';
                    $status['message'] = __('Backup process appears to be stalled. Please try again.', 'swiftspeed-siberian');
                    update_option('swsib_current_backup', $status);
                }
                
                $this->update_background_flag(false);
                delete_option('swsib_backup_process_lock');
                return;
            }
        }
        
        $original_progress = isset($status['progress']) ? $status['progress'] : 0;
        $original_files_backed_up = isset($status['files_backed_up']) ? $status['files_backed_up'] : 0;
        $original_tables_processed = isset($status['processed_tables']) ? $status['processed_tables'] : 0;
        
        $max_steps = $this->max_steps;
        
        if (isset($status['is_processing_large_file']) && $status['is_processing_large_file']) {
            $max_steps = 1;
            $this->log_message('Processing large file, limiting to 1 step');
        } else if (isset($status['large_files_count']) && $status['large_files_count'] > 0) {
            $max_steps = min(2, $max_steps);
            $this->log_message('Large files present, limiting to max 2 steps');
        }
        
        $step_count = 0;
        $continue_processing = true;
        $last_result = $status;
        
        $this->log_message('Running background processing batch (max ' . $max_steps . ' steps)');
        
        while ($continue_processing && $step_count < $max_steps) {
            $result = null;
            
            try {
                $result = $this->full_backup->process_next($last_result);
            } catch (Exception $e) {
                $this->log_message('Exception in background processing: ' . $e->getMessage());
                $result = new WP_Error('background_exception', $e->getMessage());
            }
            
            if (is_wp_error($result)) {
                $this->log_message('Error in background processing: ' . $result->get_error_message());
                
                $status['status'] = 'error';
                $status['message'] = $result->get_error_message();
                update_option('swsib_current_backup', $status);
                
                $this->update_background_flag(false);
                delete_option('swsib_backup_process_lock');
                return;
            }
            
            update_option('swsib_current_backup', $result);
            $last_result = $result;
            
            if ($result['status'] === 'completed' || $result['status'] === 'error') {
                $this->log_message('Background processing complete with status: ' . $result['status']);
                $this->update_background_flag(false);
                $continue_processing = false;

                if ($result['status'] === 'completed' && isset($result['id'])) {
                    $this->ensure_scheduled_flag_in_history($result['id'], !empty($result['params']['scheduled']));
                }
            }
            
            $this->update_backup_heartbeat();
            $step_count++;
            
            if ($step_count % 2 === 0) {
                $memory_usage = memory_get_usage(true);
                $memory_limit = $this->get_memory_limit();
                
                if ($memory_usage > ($memory_limit * 0.75)) {
                    $this->log_message('Memory usage high (' . size_format($memory_usage, 2) . ' of ' . 
                        size_format($memory_limit, 2) . '), stopping batch after ' . $step_count . ' steps');
                    $continue_processing = false;
                }
            }
            
            if (isset($result['is_processing_large_file']) && $result['is_processing_large_file']) {
                $this->log_message('Large file processing in progress, breaking batch');
                $continue_processing = false;
            }
        }
        
        $new_progress = isset($last_result['progress']) ? $last_result['progress'] : 0;
        $new_files_backed_up = isset($last_result['files_backed_up']) ? $last_result['files_backed_up'] : 0;
        $new_tables_processed = isset($last_result['processed_tables']) ? $last_result['processed_tables'] : 0;
        
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
        
        $needs_more_processing = $continue_processing && $this->get_background_flag();
        
        delete_option('swsib_backup_process_lock');
        
        if ($needs_more_processing) {
            wp_schedule_single_event(time() + 5, 'swsib_process_background_backup');
            
            if (!isset($last_result['is_processing_large_file']) || !$last_result['is_processing_large_file']) {
                $this->trigger_loopback_request();
            }
            
            $this->log_message('Scheduled next background processing run');
        }
    }
    
    private function trigger_loopback_request() {
        $nonce = wp_create_nonce('swsib_loopback_' . time());
        $url = site_url('wp-cron.php?doing_wp_cron=' . microtime(true) . '&swsib_nonce=' . $nonce);
        
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array(
                'Cache-Control' => 'no-cache',
            ),
        );
        
        $this->log_message('Triggering loopback request to continue processing');
        wp_remote_get($url, $args);
    }
    
    public function ajax_ping_background() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_ping_background')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }
        
        $is_active = $this->get_background_flag();
        
        if ($is_active) {
            do_action('swsib_process_background_backup');
        }
        
        wp_send_json_success(array(
            'active' => $is_active,
            'time' => current_time('mysql')
        ));
    }
    
    public function ajax_force_check_backup() {
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
    
    public function ajax_trigger_scheduled_backup() {
        $expected_key = md5('swsib_trigger_scheduled_backup');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            $this->log_message('External cron request to run scheduled backup');
            
            $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
            
            if (empty($backup_settings['scheduled_enabled'])) {
                wp_die('Scheduled backups are disabled');
            }
            
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
    
    public function ajax_cancel_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $status = get_option('swsib_current_backup', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup to cancel', 'swiftspeed-siberian')));
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        $this->update_background_flag(false);
        delete_option('swsib_backup_heartbeat');
        delete_option('swsib_backup_process_lock');
        
        $result = $this->full_backup->cancel_backup($status);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        delete_option('swsib_current_backup');
        
        wp_send_json_success(array('message' => __('Backup canceled successfully', 'swiftspeed-siberian')));
    }
    
    public function ajax_get_backup_history() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $history = get_option('swsib_backup_history', array());
        $remote_backups = $this->storage_manager->get_all_backups();
        $formatted_backups = array();
        
        foreach ($history as $id => $backup) {
            $storage_name = '';
            
            if (isset($backup['uploaded_to']) && is_array($backup['uploaded_to']) && count($backup['uploaded_to']) > 1) {
                $storage_names = array();
                foreach ($backup['uploaded_to'] as $provider_id) {
                    $storage_names[] = $this->get_storage_display_name($provider_id);
                }
                $storage_name = implode(', ', $storage_names);
            } else {
                $storage_name = $this->get_storage_display_name($backup['storage']);
            }
            
            if (!isset($backup['scheduled'])) {
                $backup['scheduled'] = false;
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
                'scheduled' => !empty($backup['scheduled']),
            );
        }
        
        wp_send_json_success(array(
            'history' => $formatted_backups,
            'remote_backups' => $remote_backups,
        ));
    }
    
    public function ajax_delete_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $provider_id = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'all';
        
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => __('No backup ID provided', 'swiftspeed-siberian')));
        }
        
        $history = get_option('swsib_backup_history', []);
        $all_backups = $this->storage_manager->get_all_backups();
        
        if (!isset($all_backups[$backup_id])) {
            wp_send_json_error(array('message' => __('Backup not found', 'swiftspeed-siberian')));
        }
        
        $backup = $all_backups[$backup_id];
        
        if (!empty($backup['locked'])) {
            wp_send_json_error(array('message' => __('Cannot delete locked backup', 'swiftspeed-siberian')));
        }
        
        $this->log_message('Starting resilient deletion of backup: ' . $backup_id . ' (' . $backup['file'] . ')');
        $filename = $backup['file'];
        
        $storages_to_delete = $provider_id === 'all' ? $backup['storages'] : [$provider_id];
        $delete_results = [];
        $overall_success = true;
        
        foreach ($storages_to_delete as $storage) {
            $this->log_message('Attempting resilient deletion from storage: ' . $storage);
            $result = $this->delete_backup_resilient($backup, $storage, $filename);
            $delete_results[$storage] = $result;
            
            if (!$result) {
                $overall_success = false;
            }
        }
        
        $this->update_backup_history_after_deletion($history, $filename, $provider_id, $delete_results, $backup);
        
        if ($overall_success) {
            $this->log_message('Backup deleted successfully from all storages');
            wp_send_json_success(array('message' => __('Backup deleted successfully', 'swiftspeed-siberian')));
        } else {
            $any_successful = in_array(true, $delete_results);
            
            if ($any_successful) {
                $successful_storages = [];
                $failed_storages = [];
                
                foreach ($delete_results as $storage => $success) {
                    if ($success) {
                        $successful_storages[] = $storage;
                    } else {
                        $failed_storages[] = $storage;
                    }
                }
                
                $message = sprintf(
                    __('Backup partially deleted. Successfully removed from: %s. Failed to remove from: %s.', 'swiftspeed-siberian'),
                    implode(', ', $successful_storages),
                    implode(', ', $failed_storages)
                );
                
                $this->log_message('Partial deletion: ' . $message);
                
                wp_send_json_success(array(
                    'message' => $message,
                    'partial' => true,
                    'results' => $delete_results
                ));
            } else {
                $this->log_message('Failed to delete backup from all storages');
                wp_send_json_error(array(
                    'message' => __('Failed to delete backup from all storage locations. Please check the logs and try again.', 'swiftspeed-siberian'),
                    'results' => $delete_results
                ));
            }
        }
    }
    
    private function delete_backup_resilient($backup, $storage, $filename) {
        $max_retries = $this->deletion_config['max_retries'];
        $base_delay = $this->deletion_config['base_delay'];
        $max_delay = $this->deletion_config['max_delay'];
        $timeout = $this->deletion_config['timeout'];
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $this->log_message("Deletion attempt {$attempt}/{$max_retries} for {$storage}: {$filename}");
            
            try {
                @set_time_limit($timeout);
                
                $result = $this->delete_from_single_storage($backup, $storage, $filename);
                
                if ($result === true) {
                    $this->log_message("Successfully deleted from {$storage} on attempt {$attempt}");
                    return true;
                }
                
                $error_msg = is_string($result) ? $result : 'Unknown deletion error';
                $this->log_message("Deletion failed on attempt {$attempt} for {$storage}: {$error_msg}");
                
            } catch (Exception $e) {
                $this->log_message("Exception on deletion attempt {$attempt} for {$storage}: " . $e->getMessage());
            }
            
            if ($attempt < $max_retries) {
                $delay = min($base_delay * pow(2, $attempt - 1), $max_delay);
                $this->log_message("Waiting {$delay} seconds before retry " . ($attempt + 1));
                sleep($delay);
            }
        }
        
        $this->log_message("Failed to delete from {$storage} after {$max_retries} attempts");
        return false;
    }
    
    private function delete_from_single_storage($backup, $storage, $filename) {
        if ($storage === 'local') {
            return $this->delete_from_local_storage($backup, $filename);
        } else {
            return $this->delete_from_cloud_storage($backup, $storage, $filename);
        }
    }
    
    private function delete_from_local_storage($backup, $filename) {
        $possible_paths = [];
        
        if (isset($backup['providers']['local']['path']) && !empty($backup['providers']['local']['path'])) {
            $possible_paths[] = $backup['providers']['local']['path'];
        }
        
        $possible_paths[] = $this->backup_dir . $filename;
        
        if (isset($backup['path']) && !empty($backup['path'])) {
            $possible_paths[] = $backup['path'];
        }
        
        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_file($path)) {
                if (@unlink($path)) {
                    $this->log_message('Successfully deleted local file: ' . $path);
                    return true;
                } else {
                    $error = error_get_last();
                    $error_msg = 'Failed to delete local file: ' . $path;
                    if ($error && isset($error['message'])) {
                        $error_msg .= ' - ' . $error['message'];
                    }
                    $this->log_message($error_msg);
                    return $error_msg;
                }
            }
        }
        
        $this->log_message('Local file not found in any expected location: ' . implode(', ', $possible_paths));
        return 'File not found in expected locations';
    }
    
    private function delete_from_cloud_storage($backup, $storage, $filename) {
        try {
            $provider = $this->storage_manager->get_provider($storage);
            
            if (!$provider) {
                return "Storage provider '{$storage}' not available";
            }
            
            if (!$provider->is_configured()) {
                return "Storage provider '{$storage}' not configured";
            }
            
            $init_result = $provider->initialize();
            if (is_wp_error($init_result)) {
                return "Failed to initialize {$storage}: " . $init_result->get_error_message();
            }
            
            $file_path = $this->resolve_cloud_file_path($backup, $storage, $filename);
            
            if (empty($file_path)) {
                return "Could not determine file path for {$storage}";
            }
            
            $this->log_message("Attempting to delete from {$storage} with path: {$file_path}");
            
            $result = $provider->delete_file($file_path);
            
            if (is_wp_error($result)) {
                $error_msg = $result->get_error_message();
                
                if (strpos($error_msg, 'auth') !== false || strpos($error_msg, 'token') !== false) {
                    $this->log_message("Authentication error detected for {$storage}, attempting to refresh");
                    
                    if (method_exists($provider, 'refresh_auth')) {
                        $refresh_result = $provider->refresh_auth();
                        if (!is_wp_error($refresh_result)) {
                            $result = $provider->delete_file($file_path);
                            if (!is_wp_error($result)) {
                                $this->log_message("Successfully deleted from {$storage} after auth refresh");
                                return true;
                            }
                        }
                    }
                }
                
                return "Delete failed for {$storage}: " . $error_msg;
            }
            
            $this->log_message("Successfully deleted from {$storage}");
            return true;
            
        } catch (Exception $e) {
            $error_msg = "Exception deleting from {$storage}: " . $e->getMessage();
            $this->log_message($error_msg);
            return $error_msg;
        }
    }
    
    private function resolve_cloud_file_path($backup, $storage, $filename) {
        if (isset($backup['all_storage_info'][$storage])) {
            $storage_info = $backup['all_storage_info'][$storage];
            
            if (!empty($storage_info['file_id'])) {
                return $storage_info['file_id'];
            }
            
            if (!empty($storage_info['path'])) {
                return $storage_info['path'];
            }
            
            if (!empty($storage_info['file'])) {
                return $storage_info['file'];
            }
        }
        
        if (isset($backup['providers'][$storage])) {
            $provider_info = $backup['providers'][$storage];
            
            if (!empty($provider_info['file_id'])) {
                return $provider_info['file_id'];
            }
            
            if (!empty($provider_info['path'])) {
                return $provider_info['path'];
            }
        }
        
        if ($backup['storage'] === $storage && isset($backup['storage_info'])) {
            $storage_info = $backup['storage_info'];
            
            if (!empty($storage_info['file_id'])) {
                return $storage_info['file_id'];
            }
            
            if (!empty($storage_info['path'])) {
                return $storage_info['path'];
            }
            
            if (!empty($storage_info['file'])) {
                return $storage_info['file'];
            }
        }
        
        return $filename;
    }
    
    private function update_backup_history_after_deletion($history, $filename, $provider_id, $delete_results, $backup) {
        $history_updated = false;
        
        foreach ($history as $history_id => $history_item) {
            if ($history_item['file'] === $filename) {
                if ($provider_id === 'all') {
                    $any_successful = in_array(true, $delete_results);
                    
                    if ($any_successful) {
                        unset($history[$history_id]);
                        $this->log_message('Removed backup from history: ' . $history_id);
                        $history_updated = true;
                    }
                } else {
                    if (isset($delete_results[$provider_id]) && $delete_results[$provider_id]) {
                        if (isset($history_item['uploaded_to']) && is_array($history_item['uploaded_to'])) {
                            $history_item['uploaded_to'] = array_diff($history_item['uploaded_to'], [$provider_id]);
                            
                            if (!empty($history_item['uploaded_to'])) {
                                $history[$history_id] = $history_item;
                                $this->log_message('Updated storage providers for backup in history: ' . $history_id);
                            } else {
                                unset($history[$history_id]);
                                $this->log_message('Removed backup from history after removing last storage provider: ' . $history_id);
                            }
                            $history_updated = true;
                        }
                    }
                }
                break;
            }
        }
        
        if ($history_updated) {
            update_option('swsib_backup_history', $history);
        }
    }
    
    public function ajax_lock_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $locked = isset($_POST['locked']) ? (bool) $_POST['locked'] : false;
        
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => __('No backup ID provided', 'swiftspeed-siberian')));
        }
        
        $history = get_option('swsib_backup_history', []);
        $all_backups = $this->storage_manager->get_all_backups();
        
        if (!isset($all_backups[$backup_id])) {
            wp_send_json_error(array('message' => __('Backup not found', 'swiftspeed-siberian')));
        }
        
        $backup = $all_backups[$backup_id];
        $filename = $backup['file'];
        $updated = false;
        
        foreach ($history as $id => $history_item) {
            if ($history_item['file'] === $filename) {
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
    
    public function ajax_download_backup() {
        if (
            !isset($_GET['backup_download_nonce']) || 
            !wp_verify_nonce($_GET['backup_download_nonce'], 'swsib_backup_nonce')
        ) {
            wp_die(__('Security check failed', 'swiftspeed-siberian'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'swiftspeed-siberian'));
        }
        
        $backup_id = isset($_GET['backup_id']) ? sanitize_text_field($_GET['backup_id']) : '';
        
        if (empty($backup_id)) {
            wp_die(__('No backup ID provided', 'swiftspeed-siberian'));
        }
        
        $history = get_option('swsib_backup_history', array());
        
        if (!isset($history[$backup_id])) {
            wp_die(__('Backup not found in history', 'swiftspeed-siberian'));
        }
        
        $backup = $history[$backup_id];
        $this->log_message('Downloading backup: ' . $backup_id . ' (' . $backup['file'] . ')');
        
        $provider = isset($_GET['provider']) ? sanitize_text_field($_GET['provider']) : '';
        if (!empty($provider) && $provider !== 'local' && $provider !== $backup['storage']) {
            $backup['storage'] = $provider;
            $this->log_message('Using requested provider: ' . $provider . ' instead of ' . $backup['storage']);
        }
        
        if ($backup['storage'] === 'local') {
            if (empty($backup['path']) || !file_exists($backup['path'])) {
                $this->log_message('Backup file not found at path: ' . $backup['path']);
                
                $alt_path = $this->backup_dir . $backup['file'];
                if (file_exists($alt_path)) {
                    $this->log_message('Found backup at alternate path: ' . $alt_path);
                    $backup['path'] = $alt_path;
                    
                    $history[$backup_id]['path'] = $alt_path;
                    update_option('swsib_backup_history', $history);
                } else {
                    wp_die(__('Backup file not found', 'swiftspeed-siberian'));
                }
            }
            
            $file_path = $backup['path'];
            $file_name = basename($file_path);
            
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
            
            $this->readfile_chunked($file_path);
            exit;
        } else {
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
            
            $this->readfile_chunked($temp_file);
            
            @unlink($temp_file);
            exit;
        }
    }
    
    private function readfile_chunked($filename, $chunk_size = 1048576) {
        $handle = @fopen($filename, 'rb');
        if ($handle === false) {
            return false;
        }
        
        while (!feof($handle)) {
            $buffer = fread($handle, $chunk_size);
            echo $buffer;
            
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            
            $buffer = null;
            
            static $chunks_processed = 0;
            $chunks_processed++;
            if ($chunks_processed % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        fclose($handle);
        return true;
    }
    
    public function run_scheduled_backup($backup_settings = null) {
        if (!is_array($backup_settings)) {
            $backup_settings = array();
        }
        
        $this->log_message('Running scheduled backup with settings: ' . json_encode($backup_settings));
        
        $backup_type = isset($backup_settings['backup_type']) ? $backup_settings['backup_type'] : 
                   (isset($backup_settings['scheduled_type']) ? $backup_settings['scheduled_type'] : 'full');
        
        $storage_providers = isset($backup_settings['storage_providers']) ? (array)$backup_settings['storage_providers'] : 
                         (isset($backup_settings['scheduled_storages']) ? (array)$backup_settings['scheduled_storages'] : array('local'));
        
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        $storage_providers = array_map('sanitize_key', $storage_providers);
        
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
        
        $params = array(
            'storage' => $storage_providers[0],
            'storage_providers' => $storage_providers,
            'include_paths' => array(),
            'exclude_paths' => array(),
            'scheduled' => true,
            'allow_background' => true,
            'max_steps' => $this->max_steps,
            'schedule_id' => isset($backup_settings['schedule_id']) ? $backup_settings['schedule_id'] : null,
            'schedule_name' => isset($backup_settings['schedule_name']) ? $backup_settings['schedule_name'] : null,
            'auto_lock' => !empty($backup_settings['auto_lock']),
        );
        
        $this->log_message("Starting scheduled {$backup_type} backup to " . implode(', ', $storage_providers) . " storage" . 
                      (!empty($params['auto_lock']) ? " (with auto-lock enabled)" : ""));
        
        if ($backup_type === 'full') {
            $params['include_db'] = true;
            $params['include_files'] = true;
            $this->log_message('Starting full backup with both DB and files');
            $result = $this->full_backup->start_backup($params);
        } elseif ($backup_type === 'files') {
            $params['include_db'] = false;
            $params['include_files'] = true;
            $this->log_message('Starting files-only backup');
            $result = $this->full_backup->start_backup($params);
        } else if ($backup_type === 'db') {
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
        
        $this->update_background_flag(true);
        $this->update_backup_heartbeat();
        $this->process_background_backup();
        
        $this->log_message('Scheduled backup started successfully');
        return $result;
    }

    public function cleanup_old_backups() {
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $history = get_option('swsib_backup_history', array());
        
        if (empty($history)) {
            return;
        }
        
        $this->log_message('Running scheduled cleanup of old backups');
        
        $max_db = isset($backup_settings['max_backups_db']) ? intval($backup_settings['max_backups_db']) : 10;
        $max_file = isset($backup_settings['max_backups_file']) ? intval($backup_settings['max_backups_file']) : 5;
        $max_full = isset($backup_settings['max_backups_full']) ? intval($backup_settings['max_backups_full']) : 3;
        
        $db_backups = array();
        $file_backups = array();
        $full_backups = array();
        
        foreach ($history as $id => $backup) {
            if (!empty($backup['locked'])) {
                continue;
            }
            
            if ($backup['backup_type'] === 'db') {
                $db_backups[$id] = $backup;
            } elseif ($backup['backup_type'] === 'file') {
                $file_backups[$id] = $backup;
            } elseif ($backup['backup_type'] === 'full') {
                $full_backups[$id] = $backup;
            }
        }
        
        $sort_fn = function($a, $b) {
            return $a['created'] - $b['created'];
        };
        
        uasort($db_backups, $sort_fn);
        uasort($file_backups, $sort_fn);
        uasort($full_backups, $sort_fn);
        
        $db_count = count($db_backups);
        $file_count = count($file_backups);
        $full_count = count($full_backups);
        
        $this->log_message("Backup counts before cleanup - DB: $db_count, File: $file_count, Full: $full_count");
        $this->log_message("Max limits - DB: $max_db, File: $max_file, Full: $max_full");
        
        $this->remove_excess_backups($db_backups, $db_count, $max_db);
        $this->remove_excess_backups($file_backups, $file_count, $max_file);
        $this->remove_excess_backups($full_backups, $full_count, $max_full);
    }
    
    private function remove_excess_backups($backups, $count, $max) {
        if ($count <= $max) {
            return;
        }
        
        $to_remove = array_slice($backups, 0, $count - $max, true);
        $history = get_option('swsib_backup_history', array());
        
        foreach ($to_remove as $id => $backup) {
            $this->log_message("Removing excess backup: {$id} ({$backup['backup_type']})");
            
            if (isset($backup['uploaded_to']) && is_array($backup['uploaded_to'])) {
                foreach ($backup['uploaded_to'] as $storage) {
                    if ($storage === 'local') {
                        if (!empty($backup['path']) && file_exists($backup['path'])) {
                            @unlink($backup['path']);
                        }
                    } else {
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
                if ($backup['storage'] === 'local') {
                    if (!empty($backup['path']) && file_exists($backup['path'])) {
                        @unlink($backup['path']);
                    }
                } else {
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
            
            unset($history[$id]);
        }
        
        update_option('swsib_backup_history', $history);
    }
    
    protected function get_storage_provider($provider_type) {
        $providers = [
            'local' => 'SwiftSpeed_Siberian_Storage_Local',
            'gdrive' => 'SwiftSpeed_Siberian_Storage_GDrive',
            'gcs' => 'SwiftSpeed_Siberian_Storage_GCS',
            's3' => 'SwiftSpeed_Siberian_Storage_S3',
        ];
        
        if (!isset($providers[$provider_type])) {
            return new WP_Error(
                'invalid_provider',
                sprintf(__('Invalid storage provider: %s', 'swiftspeed-siberian'), $provider_type)
            );
        }
        
        $provider_class = $providers[$provider_type];
        $file_path = SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-' . $provider_type . '.php';
        
        if (!file_exists($file_path)) {
            return new WP_Error(
                'provider_not_found',
                sprintf(__('Storage provider file not found: %s', 'swiftspeed-siberian'), $file_path)
            );
        }
        
        if (!class_exists($provider_class)) {
            require_once $file_path;
        }
        
        $config = [];
        if (isset($this->options['backup_restore']['storage'][$provider_type])) {
            $config = $this->options['backup_restore']['storage'][$provider_type];
        }
        
        $provider = new $provider_class($config);
        
        return $provider;
    }
    
    private function update_backup_heartbeat() {
        update_option('swsib_backup_heartbeat', time());
    }
    
    private function update_background_flag($enabled) {
        $current_state = get_option('swsib_background_processing', false);
        
        if ($current_state !== $enabled) {
            $this->log_message('Background processing flag changed: ' . ($enabled ? 'enabled' : 'disabled'));
        }
        
        update_option('swsib_background_processing', $enabled);
        
        if ($enabled) {
            if (!wp_next_scheduled('swsib_process_background_backup')) {
                wp_schedule_single_event(time() + 5, 'swsib_process_background_backup');
            }
            $this->trigger_loopback_request();
        }
        
        if (!$enabled) {
            wp_clear_scheduled_hook('swsib_process_background_backup');
        }
    }
    
    private function get_background_flag() {
        return (bool) get_option('swsib_background_processing', false);
    }
    
    private function get_storage_display_name($storage_type) {
        $display_names = [
            'local' => __('Local', 'swiftspeed-siberian'),
            'gdrive' => __('Google Drive', 'swiftspeed-siberian'),
            'gcs' => __('Google Cloud Storage', 'swiftspeed-siberian'),
            's3' => __('Amazon S3', 'swiftspeed-siberian'),
        ];
        
        return isset($display_names[$storage_type]) ? $display_names[$storage_type] : $storage_type;
    }
    
    public function log_message($message, $force = false) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
}