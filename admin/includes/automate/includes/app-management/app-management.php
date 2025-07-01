<?php
/**
 * Application Management - Core class
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_App_Management {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Email manager instance
     */
    private $email_manager;
    
    /**
     * Tasks handler
     */
    private $data;
    
    /**
     * Settings handler
     */
    private $settings;
    
    /**
     * Chunk size for batch processing - SIGNIFICANTLY INCREASED
     */
    private $chunk_size = 20;
    
    /**
     * Maximum batches to process in a single run
     */
    private $max_batches_per_run = 10;
    
    /**
     * Flag to indicate if we're processing in the background
     */
    private $is_background_processing = false;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
        $this->email_manager = new SwiftSpeed_Siberian_Email_Manager();
        
        // Load module files
        $this->include_files();
        
        // Initialize components
        $this->data = new SwiftSpeed_Siberian_App_Data($this->db_connection, $this->db_name);
        $this->settings = new SwiftSpeed_Siberian_App_Settings($this->db_connection, $this->db_name);
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add action for task execution
        add_action('swsib_process_app_management', array($this, 'process_app_management'), 10, 2);
        
        // Add handler for loopback requests
        add_action('wp_ajax_nopriv_swsib_app_management_loopback', array($this, 'handle_loopback_request'));
        add_action('wp_ajax_swsib_app_management_loopback', array($this, 'handle_loopback_request'));
        
        // Set up background processing check for active tasks
        add_action('admin_init', array($this, 'check_active_background_tasks'));
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        $dir = plugin_dir_path(__FILE__);
        
        require_once($dir . 'app-data.php');
        require_once($dir . 'app-settings.php');
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Main app management AJAX handlers
        add_action('wp_ajax_swsib_manage_apps', array($this, 'ajax_manage_apps'));
        add_action('wp_ajax_swsib_get_app_management_progress', array($this, 'ajax_get_app_management_progress'));
        add_action('wp_ajax_swsib_process_app_batch', array($this, 'ajax_process_app_batch'));
        
        // Preview app data
        add_action('wp_ajax_swsib_preview_app_data', array($this->data, 'ajax_preview_app_data'));
        
        // Count AJAX handlers
        add_action('wp_ajax_swsib_get_zero_size_apps_count', array($this->data, 'ajax_get_zero_size_apps_count'));
        add_action('wp_ajax_swsib_get_inactive_apps_count', array($this->data, 'ajax_get_inactive_apps_count'));
        add_action('wp_ajax_swsib_get_size_violation_apps_count', array($this->data, 'ajax_get_size_violation_apps_count'));
        add_action('wp_ajax_swsib_get_apps_without_users_count', array($this->data, 'ajax_get_apps_without_users_count'));
        
        // Settings AJAX handlers
        add_action('wp_ajax_swsib_save_app_management_automation', array($this->settings, 'ajax_save_app_management_automation'));
        add_action('wp_ajax_swsib_save_subscription_size_limits', array($this->settings, 'ajax_save_subscription_size_limits'));
        
        // Background processing status check
        add_action('wp_ajax_swsib_check_app_background_task_status', array($this, 'ajax_check_background_task_status'));
    }
    
    /**
     * Log message
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * Get progress file path
     */
    private function get_progress_file($task) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $swsib_dir = $base_dir . '/swsib';
        
        // Create directory if it doesn't exist
        if (!file_exists($swsib_dir)) {
            wp_mkdir_p($swsib_dir);
        }
        
        return $swsib_dir . '/swsib_app_' . sanitize_file_name($task) . '_progress.json';
    }
    
    /**
     * Check if a task exists/is initialized
     */
    public function task_exists($task) {
        $progress_file = $this->get_progress_file($task);
        return file_exists($progress_file);
    }
    
    /**
     * Check for active background tasks on admin page load
     */
    public function check_active_background_tasks() {
        // Only check in admin area
        if (!is_admin()) {
            return;
        }
        
        // Check for running tasks
        $tasks = array('zero_size', 'inactive', 'size_violation', 'no_users');
        $active_tasks = array();
        
        foreach ($tasks as $task) {
            $progress_file = $this->get_progress_file($task);
            if (file_exists($progress_file)) {
                $progress_data = json_decode(file_get_contents($progress_file), true);
                if ($progress_data && isset($progress_data['status']) && $progress_data['status'] === 'running') {
                    // Check if task has been updated recently (within 5 minutes)
                    if (isset($progress_data['last_update']) && (time() - $progress_data['last_update']) < 300) {
                        $active_tasks[$task] = $progress_data;
                    }
                }
            }
        }
        
        if (!empty($active_tasks)) {
            // Store active tasks in transient for JS to pick up
            set_transient('swsib_active_app_background_tasks', $active_tasks, 300);
            
            // For each active task, ensure a loopback is triggered if needed
            foreach ($active_tasks as $task => $data) {
                $this->ensure_background_processing($task);
            }
        } else {
            delete_transient('swsib_active_app_background_tasks');
        }
    }
    
    /**
     * AJAX handler to check background task status
     */
    public function ajax_check_background_task_status() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Get active tasks
        $active_tasks = get_transient('swsib_active_app_background_tasks');
        
        if ($active_tasks) {
            wp_send_json_success(array(
                'active_tasks' => $active_tasks
            ));
        } else {
            wp_send_json_error(array('message' => 'No active background tasks.'));
        }
    }
    
    /**
     * Ensure background processing is continuing for a task
     */
    private function ensure_background_processing($task) {
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return false;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data || !isset($progress_data['status']) || $progress_data['status'] !== 'running') {
            return false;
        }
        
        // Check if the last update was less than 1 minute ago - if so, assume it's still running
        if (isset($progress_data['last_update']) && (time() - $progress_data['last_update']) < 60) {
            return true;
        }
        
        // If it's been more than 1 minute, trigger a new loopback request
        $this->log_message("Restarting background processing for task: $task");
        $this->trigger_loopback_request($task);
        
        return true;
    }
    
    /**
     * AJAX handler for app management tasks
     */
    public function ajax_manage_apps() {
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
        
        // Get task type
        $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';
        
        if (empty($task)) {
            wp_send_json_error(array('message' => 'Task not specified.'));
            return;
        }
        
        // Initialize the task with batch processing
        if ($this->initialize_task($task)) {
            // Process the first batch - this will get things started
            $batch_result = $this->process_batch($task, 0);
            
            // If there are more batches to process, trigger a loopback request
            if ($batch_result['success'] && !$batch_result['completed']) {
                $this->trigger_loopback_request($task);
            }
            
            // Return the result
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => 'Task started. First batch processed. Continuing in background.',
                    'progress' => $batch_result['progress'],
                    'next_batch' => $batch_result['next_batch'],
                    'completed' => $batch_result['completed'],
                    'batch_count' => $batch_result['batch_count'] ?? 1,
                    'background_enabled' => true
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $batch_result['message']
                ));
            }
        } else {
            wp_send_json_error(array('message' => 'Failed to initialize task.'));
        }
    }
    
    /**
     * AJAX handler for processing a batch
     */
    public function ajax_process_app_batch() {
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
        
        // Get task type
        $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';
        
        if (empty($task)) {
            wp_send_json_error(array('message' => 'Task not specified.'));
            return;
        }
        
        // Get batch index
        $batch_index = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
        
        // Process the batch
        $batch_result = $this->process_batch($task, $batch_index);
        
        // If there are more batches to process and not a background process, trigger a loopback request
        if ($batch_result['success'] && !$batch_result['completed'] && !$this->is_background_processing) {
            $this->trigger_loopback_request($task);
        }
        
        // Return the result
        if ($batch_result['success']) {
            wp_send_json_success(array(
                'message' => 'Batch processed.',
                'progress' => $batch_result['progress'],
                'next_batch' => $batch_result['next_batch'],
                'completed' => $batch_result['completed'],
                'batch_count' => $batch_result['batch_count'] ?? 1
            ));
        } else {
            wp_send_json_error(array(
                'message' => $batch_result['message']
            ));
        }
    }
    
    /**
     * AJAX handler for getting app management task progress - FIXED
     */
    public function ajax_get_app_management_progress() {
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
        
        // Get task type from the request
        $task_type = isset($_POST['task_type']) ? sanitize_text_field($_POST['task_type']) : '';
        
        if (empty($task_type)) {
            wp_send_json_error(array('message' => 'Task type not specified.'));
            return;
        }
        
        // Get progress data
        $progress_data = $this->get_progress_data($task_type);
        
        // Set proper is_running and background_enabled flags based on actual status
        if (isset($progress_data['status'])) {
            if ($progress_data['status'] === 'completed' || $progress_data['status'] === 'cancelled') {
                // Task is done - not running
                $progress_data['is_running'] = false;
                $progress_data['background_enabled'] = false;
                $progress_data['heartbeat_age'] = 0;
            } else if ($progress_data['status'] === 'running') {
                // Task is running - check if it needs restart
                if (isset($progress_data['last_update']) && (time() - $progress_data['last_update']) > 60) {
                    // Trigger a new loopback request
                    $this->ensure_background_processing($task_type);
                    $progress_data['is_running'] = true;
                    $progress_data['background_enabled'] = true;
                    $progress_data['heartbeat_age'] = time() - $progress_data['last_update'];
                } else {
                    // Task is running normally
                    $progress_data['is_running'] = true;
                    $progress_data['background_enabled'] = true;
                    $progress_data['heartbeat_age'] = isset($progress_data['last_update']) ? time() - $progress_data['last_update'] : 0;
                }
            } else {
                // Unknown status - assume not running
                $progress_data['is_running'] = false;
                $progress_data['background_enabled'] = false;
                $progress_data['heartbeat_age'] = 0;
            }
        } else {
            // No status - assume not running
            $progress_data['is_running'] = false;
            $progress_data['background_enabled'] = false;
            $progress_data['heartbeat_age'] = 0;
        }
        
        // Fix progress if not set but we have totals
        if (isset($progress_data['progress']) && $progress_data['progress'] === 0 && 
            isset($progress_data['total']) && $progress_data['total'] > 0 && 
            isset($progress_data['processed']) && $progress_data['processed'] > 0) {
            $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
        }
        
        $this->log_message("Returning progress data: status=" . ($progress_data['status'] ?? 'unknown') .
                           ", is_running=" . ($progress_data['is_running'] ? 'true' : 'false') .
                           ", progress=" . ($progress_data['progress'] ?? 0) . 
                           ", processed=" . ($progress_data['processed'] ?? 0) . 
                           ", total=" . ($progress_data['total'] ?? 0));
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * Get progress data for a task
     */
    public function get_progress_data($task_type) {
        $progress_file = $this->get_progress_file($task_type);
        
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
     * Trigger a loopback request to continue processing in the background
     */
    private function trigger_loopback_request($task) {
        // Create a specific persistent nonce key for the task
        $nonce_action = 'swsib_app_management_loopback_' . $task;
        $nonce = wp_create_nonce($nonce_action);
        
        // Store the nonce in an option for validation later
        update_option('swsib_app_loopback_nonce_' . $task, $nonce, false);
        
        $url = admin_url('admin-ajax.php?action=swsib_app_management_loopback&task=' . urlencode($task) . '&nonce=' . $nonce);
        
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array('Cache-Control' => 'no-cache'),
        );
        
        $this->log_message("Triggering loopback request for task: $task");
        wp_remote_get($url, $args);
    }
    
    /**
     * Handle loopback request to continue processing batches
     */
    public function handle_loopback_request() {
        // Get task
        $task = isset($_GET['task']) ? sanitize_text_field($_GET['task']) : '';
        
        if (empty($task)) {
            $this->log_message("No task specified in loopback request");
            wp_die('No task specified.', 'Error', array('response' => 400));
        }
        
        // Get and verify nonce
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
        $stored_nonce = get_option('swsib_app_loopback_nonce_' . $task);
        
        if (empty($nonce) || $nonce !== $stored_nonce) {
            $this->log_message("Invalid nonce in loopback request");
            // Don't die here - check if the task is valid and continue anyway
            if (!$this->is_valid_running_task($task)) {
                wp_die('Security check failed.', 'Security Error', array('response' => 403));
            }
        }
        
        $this->log_message("Handling loopback request for task: $task");
        
        // Set background processing flag
        $this->is_background_processing = true;
        
        // Process batches for this task
        $this->process_app_management('app_management_' . $task, $task);
        
        // Always die to prevent output
        wp_die();
    }
    
    /**
     * Check if a task is valid and running
     */
    private function is_valid_running_task($task) {
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return false;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data || !isset($progress_data['status']) || $progress_data['status'] !== 'running') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Initialize task for batch processing
     */
    public function initialize_task($task) {
        $this->log_message("Initializing app management task: $task");
        
        // Check if there's an existing task - if so, delete it to start fresh
        $progress_file = $this->get_progress_file($task);
        if (file_exists($progress_file)) {
            $this->log_message("Removing existing progress file for task $task to start fresh");
            @unlink($progress_file);
        }
        
        $total = 0;
        $logs = array();
        $data_items = array();
        
        // Get data based on task type
        if ($task === 'zero_size') {
            $data_items = $this->data->get_zero_size_apps();
            $total = count($data_items);
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d zero size apps to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'inactive') {
            $data_items = $this->data->get_inactive_apps();
            $total = count($data_items);
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d deleted apps to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'size_violation') {
            // For size violation, we need to handle warnings differently
            // Get all apps that violate size limits, not just the warned ones
            $data_items = $this->data->get_size_violation_apps_for_processing();
            $total = count($data_items);
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d apps exceeding size limits to process', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'no_users') {
            $data_items = $this->data->get_apps_without_users();
            $total = count($data_items);
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d apps without users to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        }
        
        // Ensure we have at least 1 for total to prevent division by zero
        $total = max(1, $total);
        
        // Split items into batches
        $batches = array_chunk($data_items, $this->chunk_size);
        
        // Calculate batch count
        $batch_count = count($batches);
        if ($batch_count == 0) $batch_count = 1; // Ensure at least 1 batch
        
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
                    'message' => __('Task initialized', 'swiftspeed-siberian'),
                    'type' => 'info'
                )
            ], $logs),
            'start_time' => time(),
            'last_update' => time(),
            'batches' => $batches,
            'batch_count' => $batch_count,
            'current_batch' => 0,
            'deleted' => 0,
            'errors' => 0,
            'skipped' => 0,
            'warned' => 0,
            'is_running' => true,
            'background_enabled' => true,
            'detailed_item_list' => array()
        );
        
        // Save to progress file
        file_put_contents($progress_file, json_encode($progress_data));
        
        // Store in transient for other parts of the system to know there's an active task
        $active_tasks = get_transient('swsib_active_app_background_tasks') ?: array();
        $active_tasks[$task] = $progress_data;
        set_transient('swsib_active_app_background_tasks', $active_tasks, 300);
        
        $this->log_message("Task $task initialized with $total items, $batch_count batches (chunk size: {$this->chunk_size})");
        
        return true;
    }
    
    /**
     * Process batch for specific task
     */
    public function process_batch($task, $batch_index) {
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
                'completed' => true,
                'batch_count' => $progress_data['batch_count']
            );
        }
        
        // Get batch of items to process
        if (!isset($progress_data['batches'][$batch_index])) {
            // No more batches, mark as completed
            $progress_data['status'] = 'completed';
            $progress_data['progress'] = 100;
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => sprintf(__('Task completed. Processed %d items, deleted %d, skipped %d, warned %d, with %d errors.', 'swiftspeed-siberian'), 
                                   $progress_data['processed'], 
                                   $progress_data['deleted'], 
                                   $progress_data['skipped'], 
                                   $progress_data['warned'] ?? 0,
                                   $progress_data['errors']),
                'type' => 'success'
            );
            
            // Remove from active tasks transient
            $active_tasks = get_transient('swsib_active_app_background_tasks');
            if ($active_tasks && isset($active_tasks[$task])) {
                unset($active_tasks[$task]);
                if (!empty($active_tasks)) {
                    set_transient('swsib_active_app_background_tasks', $active_tasks, 300);
                } else {
                    delete_transient('swsib_active_app_background_tasks');
                }
            }
            
            file_put_contents($progress_file, json_encode($progress_data));
            
            // Record the cleanup results
            $this->data->record_app_deletion_results($task, $progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
            
            return array(
                'success' => true,
                'message' => 'All batches processed',
                'progress' => 100,
                'next_batch' => 0,
                'completed' => true,
                'batch_count' => $progress_data['batch_count']
            );
        }
        
        $batch_items = $progress_data['batches'][$batch_index];
        
        // Add batch processing log
        $progress_data['logs'][] = array(
            'time' => time(),
            'message' => sprintf(__('Processing batch %d of %d', 'swiftspeed-siberian'), $batch_index + 1, $progress_data['batch_count']),
            'type' => 'info'
        );
        
        // Process batch based on task type
        $result = array(
            'deleted' => 0,
            'errors' => 0,
            'skipped' => 0,
            'warned' => 0,
            'logs' => [],
            'detailed_items' => []
        );
        
        if ($task === 'zero_size') {
            $result = $this->process_zero_size_apps_batch($batch_items);
        } elseif ($task === 'inactive') {
            $result = $this->process_inactive_apps_batch($batch_items);
        } elseif ($task === 'size_violation') {
            $result = $this->process_size_violation_apps_batch($batch_items);
        } elseif ($task === 'no_users') {
            $result = $this->process_apps_without_users_batch($batch_items);
        }
        
        // Update progress
        $progress_data['deleted'] += $result['deleted'];
        $progress_data['errors'] += $result['errors'];
        $progress_data['skipped'] += $result['skipped'];
        
        // Add warned count if present
        if (isset($result['warned'])) {
            if (!isset($progress_data['warned'])) {
                $progress_data['warned'] = 0;
            }
            $progress_data['warned'] += $result['warned'];
        }
        
        // Update processed count (includes all items processed - deleted, error, skipped, warned)
        $progress_data['processed'] += count($batch_items);
        $progress_data['current_batch'] = $batch_index + 1;
        $progress_data['last_update'] = time();
        
        // Add detailed items to the list (for reporting in action logs)
        if (!empty($result['detailed_items'])) {
            if (!isset($progress_data['detailed_item_list'])) {
                $progress_data['detailed_item_list'] = array();
            }
            $progress_data['detailed_item_list'] = array_merge($progress_data['detailed_item_list'], $result['detailed_items']);
        }
        
        // Calculate progress percentage based on processed vs total
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
        
        // Add batch completion log with summary
        $progress_data['logs'][] = array(
            'time' => time(),
            'message' => sprintf(__('Batch %d completed: Deleted %d items, skipped %d, warned %d, with %d errors', 'swiftspeed-siberian'), 
                               $batch_index + 1, $result['deleted'], $result['skipped'], $result['warned'] ?? 0, $result['errors']),
            'type' => $result['errors'] > 0 ? 'warning' : 'success'
        );
        
        // Check if we're done
        $completed = ($batch_index + 1 >= $progress_data['batch_count']);
        
        if ($completed) {
            $progress_data['status'] = 'completed';
            $progress_data['progress'] = 100;
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            
            // Add completion log
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => sprintf(__('Task completed. Processed %d items, deleted %d, skipped %d, warned %d, with %d errors.', 'swiftspeed-siberian'), 
                                   $progress_data['processed'], 
                                   $progress_data['deleted'], 
                                   $progress_data['skipped'], 
                                   $progress_data['warned'] ?? 0,
                                   $progress_data['errors']),
                'type' => 'success'
            );
            
            // Remove from active tasks transient
            $active_tasks = get_transient('swsib_active_app_background_tasks');
            if ($active_tasks && isset($active_tasks[$task])) {
                unset($active_tasks[$task]);
                if (!empty($active_tasks)) {
                    set_transient('swsib_active_app_background_tasks', $active_tasks, 300);
                } else {
                    delete_transient('swsib_active_app_background_tasks');
                }
            }
            
            // Record the cleanup results
            $this->data->record_app_deletion_results($task, $progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
        } else {
            // Update active tasks transient
            $active_tasks = get_transient('swsib_active_app_background_tasks') ?: array();
            $active_tasks[$task] = $progress_data;
            set_transient('swsib_active_app_background_tasks', $active_tasks, 300);
        }
        
        // Update progress file
        file_put_contents($progress_file, json_encode($progress_data));
        
        return array(
            'success' => true,
            'message' => 'Batch processed',
            'progress' => $progress_data['progress'],
            'next_batch' => $batch_index + 1,
            'completed' => $completed,
            'batch_count' => $progress_data['batch_count']
        );
    }
    
    /**
     * Send warning email for a size violation app
     */
    private function send_size_violation_warning($app, $email_settings, $size_limit) {
        if (!$app || !$email_settings || empty($app['email'])) {
            $this->log_message("Invalid data for sending size violation warning");
            return false;
        }
        
        $placeholders = array(
            'name' => $app['owner_name'] ?? 'User',
            'email' => $app['email'],
            'app_name' => $app['name'],
            'app_id' => $app['app_id'],
            'days' => $email_settings['warning_period'],
            'current_size' => $app['size_mb'],
            'size_limit' => $size_limit,
            'subscription_id' => $app['subscription_id']
        );
        
        $subject = $email_settings['warning_subject'];
        $message = $email_settings['warning_message'];
        
        $email_sent = $this->email_manager->send_email(
            $app['email'],
            $subject,
            $message,
            $placeholders
        );
        
        if ($email_sent) {
            $this->log_message("Size violation warning sent to {$app['email']} for app {$app['name']} (ID: {$app['app_id']})");
        } else {
            $this->log_message("Failed to send size violation warning to {$app['email']} for app {$app['name']} (ID: {$app['app_id']})");
        }
        
        return $email_sent;
    }
    
    /**
     * Send warning email for an inactive app
     */
    private function send_inactive_app_warning($app, $email_settings) {
        if (!$app || !$email_settings || empty($app['email'])) {
            $this->log_message("Invalid data for sending inactive app warning");
            return false;
        }
        
        $placeholders = array(
            'name' => $app['owner_name'] ?? 'User',
            'email' => $app['email'],
            'app_name' => $app['name'],
            'app_id' => $app['app_id'],
            'days' => $email_settings['warning_period']
        );
        
        $subject = $email_settings['warning_subject'];
        $message = $email_settings['warning_message'];
        
        $email_sent = $this->email_manager->send_email(
            $app['email'],
            $subject,
            $message,
            $placeholders
        );
        
        if ($email_sent) {
            $this->log_message("Inactive app warning sent to {$app['email']} for app {$app['name']} (ID: {$app['app_id']})");
        } else {
            $this->log_message("Failed to send inactive app warning to {$app['email']} for app {$app['name']} (ID: {$app['app_id']})");
        }
        
        return $email_sent;
    }
    
    /**
     * Process a batch of zero size apps - OPTIMIZED FOR BULK OPERATIONS WITH INDIVIDUAL REPORTING
     */
    private function process_zero_size_apps_batch($apps) {
        if (!$this->db_connection) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Database connection not available',
                        'type' => 'error'
                    )
                ],
                'detailed_items' => []
            );
        }
        
        $logs = [];
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $detailed_items = [];
        
        // Extract app IDs for bulk operations
        $app_ids = array_column($apps, 'app_id');
        $batch_size = count($app_ids);
        
        // Add individual processing logs for UI
        foreach ($apps as $app) {
            $logs[] = array(
                'time' => time(),
                'message' => "Processing app: {$app['name']} (ID: {$app['app_id']})",
                'type' => 'info'
            );
        }
        
        try {
            // Start single transaction for entire batch
            $this->db_connection->begin_transaction();
            
            // Bulk delete all related data for these apps
            $this->data->bulk_delete_app_data($app_ids);
            
            // Bulk delete the applications themselves
            if ($this->data->bulk_delete_applications($app_ids)) {
                // Commit the transaction
                $this->db_connection->commit();
                
                $deleted = $batch_size;
                
                // Add individual success logs for UI (like the original)
                foreach ($apps as $app) {
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Successfully deleted zero size app: {$app['name']} (ID: {$app['app_id']})",
                        'type' => 'success'
                    );
                    
                    $detailed_items[] = array(
                        'app_id' => $app['app_id'],
                        'name' => $app['name'],
                        'action' => 'deleted',
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
                
            } else {
                throw new Exception("Failed to bulk delete applications");
            }
            
        } catch (Exception $e) {
            // Rollback the transaction
            $this->db_connection->rollback();
            
            $errors = $batch_size;
            
            // Add individual error logs for UI
            foreach ($apps as $app) {
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error deleting app {$app['app_id']}: " . $e->getMessage(),
                    'type' => 'error'
                );
            }
        }
        
        return array(
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped,
            'logs' => $logs,
            'detailed_items' => $detailed_items
        );
    }
    
    /**
     * Process a batch of inactive apps - OPTIMIZED FOR BULK OPERATIONS WITH INDIVIDUAL REPORTING
     */
    private function process_inactive_apps_batch($apps) {
        if (!$this->db_connection) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'warned' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Database connection not available',
                        'type' => 'error'
                    )
                ],
                'detailed_items' => []
            );
        }
        
        $logs = [];
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $warned = 0;
        $detailed_items = [];
        
        // Get settings
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['app_management']['inactive']) ? $options['automate']['app_management']['inactive'] : array();
        $send_warning = !empty($settings['send_warning']);
        $warning_period = isset($settings['warning_period']) ? intval($settings['warning_period']) : 7;
        
        // Check warned apps
        $warned_transient = get_transient('swsib_warned_inactive_apps');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        $batch_size = count($apps);
        
        // Separate apps into different categories for bulk processing
        $apps_to_delete = array();
        $apps_to_warn = array();
        $apps_to_skip = array();
        
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            
            if ($send_warning) {
                if (!isset($warned_data[$app_id])) {
                    // Needs warning
                    $apps_to_warn[] = $app;
                } else {
                    // Check if warning period has expired
                    if (isset($warned_data[$app_id]['expires']) && time() < $warned_data[$app_id]['expires']) {
                        $apps_to_skip[] = $app;
                    } else {
                        $apps_to_delete[] = $app;
                    }
                }
            } else {
                // No warning required, delete directly
                $apps_to_delete[] = $app;
            }
        }
        
        // Process warnings (these need individual handling for email)
        foreach ($apps_to_warn as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing deleted app: $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
            
            if ($this->send_inactive_app_warning($app, $settings)) {
                $warned++;
                $warned_data[$app['app_id']] = array(
                    'timestamp' => time(),
                    'expires' => time() + ($warning_period * 86400)
                );
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Warning sent for inactive app $app_name (ID: {$app['app_id']})",
                    'type' => 'info'
                );
                
                $detailed_items[] = array(
                    'app_id' => $app['app_id'],
                    'name' => $app_name,
                    'email' => $app['email'] ?? '',
                    'action' => 'warned',
                    'timestamp' => date('Y-m-d H:i:s')
                );
            } else {
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Failed to send warning for app {$app['app_id']}",
                    'type' => 'error'
                );
            }
        }
        
        // Process skipped apps
        foreach ($apps_to_skip as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing deleted app: $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
            
            $logs[] = array(
                'time' => time(),
                'message' => "Warning period not expired for app $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
            
            $skipped++;
            $detailed_items[] = array(
                'app_id' => $app['app_id'],
                'name' => $app_name,
                'email' => $app['email'] ?? '',
                'action' => 'skipped - warning period not expired',
                'timestamp' => date('Y-m-d H:i:s')
            );
        }
        
        // Process apps for deletion with individual logging
        foreach ($apps_to_delete as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing deleted app: $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
        }
        
        // Bulk delete apps that are ready for deletion
        if (!empty($apps_to_delete)) {
            $app_ids_to_delete = array_column($apps_to_delete, 'app_id');
            
            try {
                // Start transaction for bulk deletion
                $this->db_connection->begin_transaction();
                
                // Bulk delete all related data
                $this->data->bulk_delete_app_data($app_ids_to_delete);
                
                // Bulk delete the applications themselves
                if ($this->data->bulk_delete_applications($app_ids_to_delete)) {
                    // Commit the transaction
                    $this->db_connection->commit();
                    
                    $deleted = count($apps_to_delete);
                    
                    // Add individual success logs for UI (like the original)
                    foreach ($apps_to_delete as $app) {
                        $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Successfully deleted inactive app: $app_name (ID: {$app['app_id']})",
                            'type' => 'success'
                        );
                        
                        $detailed_items[] = array(
                            'app_id' => $app['app_id'],
                            'name' => $app_name,
                            'email' => $app['email'] ?? '',
                            'action' => 'deleted',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                        
                        // Remove from warned apps if present
                        if (isset($warned_data[$app['app_id']])) {
                            unset($warned_data[$app['app_id']]);
                        }
                    }
                    
                } else {
                    throw new Exception("Failed to bulk delete inactive applications");
                }
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors += count($apps_to_delete);
                
                // Add individual error logs for UI
                foreach ($apps_to_delete as $app) {
                    $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Error deleting app {$app['app_id']}: " . $e->getMessage(),
                        'type' => 'error'
                    );
                }
            }
        }
        
        // Update warned apps transient
        set_transient('swsib_warned_inactive_apps', $warned_data, 30 * DAY_IN_SECONDS);
        
        return array(
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped,
            'warned' => $warned,
            'logs' => $logs,
            'detailed_items' => $detailed_items
        );
    }
    
    /**
     * Process a batch of size violation apps - OPTIMIZED FOR BULK OPERATIONS WITH INDIVIDUAL REPORTING
     */
    private function process_size_violation_apps_batch($apps) {
        if (!$this->db_connection) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'warned' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Database connection not available',
                        'type' => 'error'
                    )
                ],
                'detailed_items' => []
            );
        }
        
        $logs = [];
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $warned = 0;
        $detailed_items = [];
        
        // Get size violation apps settings
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['app_management']['size_violation']) ? 
                  $options['automate']['app_management']['size_violation'] : array();
        
        $delete_immediately = !empty($settings['delete_immediately']);
        $send_warning = !empty($settings['send_warning']) && !$delete_immediately;
        $warning_period = isset($settings['warning_period']) ? intval($settings['warning_period']) : 7;
        
        // Get size limits
        $size_limits = $this->data->get_subscription_size_limits();
        
        // Check warned apps
        $warned_transient = get_transient('swsib_warned_size_violation_apps');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        $batch_size = count($apps);
        
        // Separate apps into different categories for bulk processing
        $apps_to_delete = array();
        $apps_to_warn = array();
        $apps_to_skip = array();
        
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            $subscription_id = $app['subscription_id'];
            $size_limit_mb = isset($size_limits[$subscription_id]) ? $size_limits[$subscription_id] : 'Not set';
            
            // Add size limit info to app data
            $app['size_limit_mb'] = $size_limit_mb;
            
            if ($delete_immediately) {
                $apps_to_delete[] = $app;
            } else if ($send_warning) {
                if (!isset($warned_data[$app_id])) {
                    $apps_to_warn[] = $app;
                } else {
                    // Check if warning period has expired
                    if (isset($warned_data[$app_id]['expires']) && time() < $warned_data[$app_id]['expires']) {
                        $apps_to_skip[] = $app;
                    } else {
                        $apps_to_delete[] = $app;
                    }
                }
            } else {
                // No action configured
                $apps_to_skip[] = $app;
            }
        }
        
        // Process warnings (these need individual handling for email)
        foreach ($apps_to_warn as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $size_mb = round($app['size_on_disk'] / (1024 * 1024), 2);
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing size violation app: $app_name (ID: {$app['app_id']}) - Size: {$size_mb}MB (Limit: {$app['size_limit_mb']}MB)",
                'type' => 'info'
            );
            
            if ($this->send_size_violation_warning($app, $settings, $app['size_limit_mb'])) {
                $warned++;
                $warned_data[$app['app_id']] = array(
                    'timestamp' => time(),
                    'expires' => time() + ($warning_period * 86400)
                );
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Warning sent for size violation app $app_name (ID: {$app['app_id']})",
                    'type' => 'info'
                );
                
                $detailed_items[] = array(
                    'app_id' => $app['app_id'],
                    'name' => $app_name,
                    'email' => $app['email'] ?? '',
                    'subscription_id' => $app['subscription_id'],
                    'size_mb' => $size_mb,
                    'size_limit_mb' => $app['size_limit_mb'],
                    'action' => 'warned',
                    'timestamp' => date('Y-m-d H:i:s')
                );
            } else {
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Failed to send warning for app {$app['app_id']}",
                    'type' => 'error'
                );
            }
        }
        
        // Process skipped apps
        foreach ($apps_to_skip as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $size_mb = round($app['size_on_disk'] / (1024 * 1024), 2);
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing size violation app: $app_name (ID: {$app['app_id']}) - Size: {$size_mb}MB (Limit: {$app['size_limit_mb']}MB)",
                'type' => 'info'
            );
            
            $reason = !$delete_immediately && !$send_warning ? 'no action configured' : 'warning period not expired';
            $logs[] = array(
                'time' => time(),
                'message' => "Skipping app {$app['app_id']} - {$reason}",
                'type' => 'info'
            );
            
            $skipped++;
            $detailed_items[] = array(
                'app_id' => $app['app_id'],
                'name' => $app_name,
                'email' => $app['email'] ?? '',
                'subscription_id' => $app['subscription_id'],
                'size_mb' => $size_mb,
                'size_limit_mb' => $app['size_limit_mb'],
                'action' => "skipped - {$reason}",
                'timestamp' => date('Y-m-d H:i:s')
            );
        }
        
        // Process apps for deletion with individual logging
        foreach ($apps_to_delete as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $size_mb = round($app['size_on_disk'] / (1024 * 1024), 2);
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing size violation app: $app_name (ID: {$app['app_id']}) - Size: {$size_mb}MB (Limit: {$app['size_limit_mb']}MB)",
                'type' => 'info'
            );
        }
        
        // Bulk delete apps that are ready for deletion
        if (!empty($apps_to_delete)) {
            $app_ids_to_delete = array_column($apps_to_delete, 'app_id');
            
            try {
                // Start transaction for bulk deletion
                $this->db_connection->begin_transaction();
                
                // Bulk delete all related data
                $this->data->bulk_delete_app_data($app_ids_to_delete);
                
                // Bulk delete the applications themselves
                if ($this->data->bulk_delete_applications($app_ids_to_delete)) {
                    // Commit the transaction
                    $this->db_connection->commit();
                    
                    $deleted = count($apps_to_delete);
                    
                    // Add individual success logs for UI (like the original)
                    foreach ($apps_to_delete as $app) {
                        $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                        $size_mb = round($app['size_on_disk'] / (1024 * 1024), 2);
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Successfully deleted size violation app: $app_name (ID: {$app['app_id']}) - Size: {$size_mb}MB (Limit: {$app['size_limit_mb']}MB)",
                            'type' => 'success'
                        );
                        
                        $detailed_items[] = array(
                            'app_id' => $app['app_id'],
                            'name' => $app_name,
                            'email' => $app['email'] ?? '',
                            'subscription_id' => $app['subscription_id'],
                            'size_mb' => $size_mb,
                            'size_limit_mb' => $app['size_limit_mb'],
                            'action' => 'deleted',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                        
                        // Remove from warned apps if present
                        if (isset($warned_data[$app['app_id']])) {
                            unset($warned_data[$app['app_id']]);
                        }
                    }
                    
                } else {
                    throw new Exception("Failed to bulk delete size violation applications");
                }
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors += count($apps_to_delete);
                
                // Add individual error logs for UI
                foreach ($apps_to_delete as $app) {
                    $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Error deleting app {$app['app_id']}: " . $e->getMessage(),
                        'type' => 'error'
                    );
                }
            }
        }
        
        // Update warned apps transient
        set_transient('swsib_warned_size_violation_apps', $warned_data, 30 * DAY_IN_SECONDS);
        
        return array(
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped,
            'warned' => $warned,
            'logs' => $logs,
            'detailed_items' => $detailed_items
        );
    }
    
    /**
     * Process a batch of apps without users - OPTIMIZED FOR BULK OPERATIONS WITH INDIVIDUAL REPORTING
     */
    private function process_apps_without_users_batch($apps) {
        if (!$this->db_connection) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Database connection not available',
                        'type' => 'error'
                    )
                ],
                'detailed_items' => []
            );
        }
        
        $logs = [];
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $detailed_items = [];
        
        $batch_size = count($apps);
        
        // Verify which apps still don't have users (in case they were recently assigned)
        $apps_to_delete = array();
        $apps_to_skip = array();
        
        $app_ids_str = implode(',', array_map('intval', array_column($apps, 'app_id')));
        if (!empty($app_ids_str)) {
            $verify_query = "SELECT app.app_id 
                           FROM application app 
                           LEFT JOIN admin adm ON app.admin_id = adm.admin_id 
                           WHERE app.app_id IN ({$app_ids_str}) 
                           AND (adm.admin_id IS NULL OR app.admin_id IS NULL)";
            
            $verify_result = $this->db_connection->query($verify_query);
            
            if ($verify_result) {
                $verified_app_ids = array();
                while ($row = $verify_result->fetch_assoc()) {
                    $verified_app_ids[] = $row['app_id'];
                }
                
                // Separate apps based on verification
                foreach ($apps as $app) {
                    if (in_array($app['app_id'], $verified_app_ids)) {
                        $apps_to_delete[] = $app;
                    } else {
                        $apps_to_skip[] = $app;
                    }
                }
            } else {
                // If verification fails, delete all (as per original logic)
                $apps_to_delete = $apps;
            }
        }
        
        // Process skipped apps with individual logging
        foreach ($apps_to_skip as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing app without user: $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
            
            $logs[] = array(
                'time' => time(),
                'message' => "Skipping app {$app['app_id']} as it now has a user",
                'type' => 'info'
            );
            
            $skipped++;
            $detailed_items[] = array(
                'app_id' => $app['app_id'],
                'name' => $app_name,
                'size_mb' => $app['size_mb'] ?? 0,
                'action' => 'skipped - user found upon verification',
                'timestamp' => date('Y-m-d H:i:s')
            );
        }
        
        // Process apps for deletion with individual logging
        foreach ($apps_to_delete as $app) {
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing app without user: $app_name (ID: {$app['app_id']})",
                'type' => 'info'
            );
        }
        
        // Bulk delete apps that still don't have users
        if (!empty($apps_to_delete)) {
            $app_ids_to_delete = array_column($apps_to_delete, 'app_id');
            
            try {
                // Start transaction for bulk deletion
                $this->db_connection->begin_transaction();
                
                // Bulk delete all related data
                $this->data->bulk_delete_app_data($app_ids_to_delete);
                
                // Bulk delete the applications themselves
                if ($this->data->bulk_delete_applications($app_ids_to_delete)) {
                    // Commit the transaction
                    $this->db_connection->commit();
                    
                    $deleted = count($apps_to_delete);
                    
                    // Add individual success logs for UI (like the original)
                    foreach ($apps_to_delete as $app) {
                        $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Successfully deleted app without user: $app_name (ID: {$app['app_id']})",
                            'type' => 'success'
                        );
                        
                        $detailed_items[] = array(
                            'app_id' => $app['app_id'],
                            'name' => $app_name,
                            'size_mb' => $app['size_mb'] ?? 0,
                            'action' => 'deleted',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                    }
                    
                } else {
                    throw new Exception("Failed to bulk delete applications without users");
                }
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors += count($apps_to_delete);
                
                // Add individual error logs for UI
                foreach ($apps_to_delete as $app) {
                    $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Error deleting app {$app['app_id']}: " . $e->getMessage(),
                        'type' => 'error'
                    );
                }
            }
        }
        
        return array(
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped,
            'logs' => $logs,
            'detailed_items' => $detailed_items
        );
    }
    
    /**
     * Handle scheduled task - Main handler for automated tasks
     * 
     * @param string $task_id The unique task ID
     * @param array $task_args The arguments for the task
     * @return array Result of task execution
     */
    public function handle_scheduled_task($task_id, $task_args) {
        $task_type = '';
        
        // Extract task type from args if provided
        if (is_array($task_args) && isset($task_args['task'])) {
            $task_type = $task_args['task'];
        } else if (is_string($task_args)) {
            $task_type = $task_args;
        }
        
        $this->log_message("Starting app management task: $task_type for task ID: $task_id");
        
        // Ensure we have a database connection
        if (!$this->db_connection) {
            $options = get_option('swsib_options', array());
            $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
            
            if (!empty($db_options['host']) && !empty($db_options['database']) && 
                !empty($db_options['username']) && !empty($db_options['password'])) {
                
                $db_connection = new mysqli(
                    $db_options['host'],
                    $db_options['username'],
                    $db_options['password'],
                    $db_options['database'],
                    isset($db_options['port']) ? intval($db_options['port']) : 3306
                );
                
                if (!$db_connection->connect_error) {
                    $this->db_connection = $db_connection;
                    $this->db_name = $db_options['database'];
                    $this->data->db_connection = $db_connection; // Also update data handler connection
                    $this->log_message("Database connection established in handle_scheduled_task");
                } else {
                    $this->log_message("Database connection failed: " . $db_connection->connect_error);
                    return array(
                        'success' => false,
                        'message' => 'Database connection failed: ' . $db_connection->connect_error,
                        'operation_details' => array(
                            'error' => 'Database connection failed',
                            'timestamp' => time(),
                            'timestamp_formatted' => date('Y-m-d H:i:s', time())
                        )
                    );
                }
            } else {
                $this->log_message("Database connection settings not found");
                return array(
                    'success' => false,
                    'message' => 'Database connection settings not found',
                    'operation_details' => array(
                        'error' => 'Database connection settings not found',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
        }
        
        // Check if there is an existing task in progress
        $progress_file = $this->get_progress_file($task_type);
        $progress_exists = file_exists($progress_file);
        
        // Get current progress data
        if ($progress_exists) {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            // If the task is already completed, start fresh
            if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                $this->log_message("Previous task was already completed, starting fresh");
                $progress_exists = false;
            }
        }
        
        // Initialize the task if needed
        if (!$progress_exists) {
            $this->log_message("Initializing new task: $task_type");
            if (!$this->initialize_task($task_type)) {
                $this->log_message("Failed to initialize task: $task_type");
                return array(
                    'success' => false,
                    'message' => 'Failed to initialize task',
                    'operation_details' => array(
                        'error' => 'Failed to initialize task',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            // Load the newly created progress data
            $progress_data = json_decode(file_get_contents($progress_file), true);
        }
        
        // Set background processing flag
        $this->is_background_processing = true;
        
        // Process multiple batches in this run, up to max_batches_per_run
        $batches_processed = 0;
        $continue_processing = true;
        $task_completed = false;
        $batch_index = isset($progress_data['current_batch']) ? $progress_data['current_batch'] : 0;
        
        $this->log_message("Starting to process batches for task $task_type from batch $batch_index");
        
        while ($continue_processing && $batches_processed < $this->max_batches_per_run) {
            // Process the next batch
            $batch_result = $this->process_batch($task_type, $batch_index);
            $batches_processed++;
            
            if (!$batch_result['success']) {
                $this->log_message("Error processing batch $batch_index: " . $batch_result['message']);
                $continue_processing = false;
            }
            
            // Check if the task is now complete
            if ($batch_result['completed']) {
                $task_completed = true;
                $continue_processing = false;
                $this->log_message("Task $task_type completed after processing $batches_processed batches");
            } else {
                // Update batch index for next iteration
                $batch_index = $batch_result['next_batch'];
                
                // Check if we've processed enough batches for this run
                if ($batches_processed >= $this->max_batches_per_run) {
                    $this->log_message("Reached max batches per run ($this->max_batches_per_run), will continue via loopback");
                    
                    // Trigger a loopback request to continue processing in the background
                    $this->trigger_loopback_request($task_type);
                    $continue_processing = false;
                }
            }
        }
        
        // Get final progress data
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        // Prepare operation details
        $operation_details = array(
            'task' => $task_type,
            'processed' => $progress_data['processed'],
            'total' => $progress_data['total'],
            'progress_percentage' => $progress_data['progress'],
            'timestamp' => time(),
            'timestamp_formatted' => date('Y-m-d H:i:s', time())
        );
        
        if (isset($progress_data['deleted'])) {
            $operation_details['deleted'] = $progress_data['deleted'];
        }
        
        if (isset($progress_data['errors'])) {
            $operation_details['errors'] = $progress_data['errors'];
        }
        
        if (isset($progress_data['skipped'])) {
            $operation_details['skipped'] = $progress_data['skipped'];
        }
        
        if (isset($progress_data['warned'])) {
            $operation_details['warned'] = $progress_data['warned'];
        }
        
        // Add detailed item list for reporting in action logs
        if (isset($progress_data['detailed_item_list']) && !empty($progress_data['detailed_item_list'])) {
            $operation_details['deleted_apps_list'] = $progress_data['detailed_item_list'];
            
            // Filter just the deleted items for a summary
            $deleted_apps = array_filter($progress_data['detailed_item_list'], function($app) {
                return isset($app['action']) && $app['action'] === 'deleted';
            });
            
            if (!empty($deleted_apps)) {
                $operation_details['deleted_apps'] = array_slice($deleted_apps, -10); // Last 10 for display
                $operation_details['deleted_apps_count'] = count($deleted_apps);
            }
        }
        
        // Create a summary message
        $summary = sprintf(
            __("Processed %d out of %d items (%d%%). Deleted %d, skipped %d, warned %d, with %d errors.", 'swiftspeed-siberian'),
            $progress_data['processed'],
            $progress_data['total'],
            $progress_data['progress'],
            $progress_data['deleted'] ?? 0,
            $progress_data['skipped'] ?? 0,
            $progress_data['warned'] ?? 0,
            $progress_data['errors'] ?? 0
        );
        
        $operation_details['summary'] = $summary;
        
        $success = true; // Consider it a success even with some errors
        $message = '';
        
        if ($task_completed) {
            $message = sprintf(
                __("Task %s completed. Processed %d items: Deleted %d, Skipped %d, Warned %d, with %d errors.", 'swiftspeed-siberian'),
                $task_type,
                $progress_data['processed'],
                $progress_data['deleted'] ?? 0,
                $progress_data['skipped'] ?? 0,
                $progress_data['warned'] ?? 0,
                $progress_data['errors'] ?? 0
            );
            
            // For compatibility with the action logs system
            global $swsib_last_task_result;
            $swsib_last_task_result = array(
                'success' => $success,
                'message' => $message,
                'operation_details' => $operation_details,
                'completed' => true
            );
            
            return array(
                'success' => $success,
                'message' => $message,
                'operation_details' => $operation_details,
                'completed' => true
            );
        } else {
            // Task is continuing via loopback, return current progress
            $message = sprintf(
                __("Task in progress. Processed %d out of %d items (%d%%).", 'swiftspeed-siberian'),
                $progress_data['processed'],
                $progress_data['total'],
                $progress_data['progress']
            );
            
            // For compatibility with the action logs system
            global $swsib_last_task_result;
            $swsib_last_task_result = array(
                'success' => $success,
                'message' => $message,
                'operation_details' => $operation_details,
                'completed' => false
            );
            
            return array(
                'success' => $success,
                'message' => $message,
                'operation_details' => $operation_details,
                'completed' => false
            );
        }
    }
    
    /**
     * Process app management task - Main handler for scheduled tasks
     * Improved to use the same batching approach as WP tasks
     */
    public function process_app_management($task_id, $task_type) {
        // For backward compatibility, use the new handle_scheduled_task method
        return $this->handle_scheduled_task($task_id, $task_type);
    }
    
    /**
     * Display app management settings and UI
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for app management automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }
}