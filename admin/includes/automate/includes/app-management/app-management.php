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
     * Chunk size for batch processing
     */
    private $chunk_size = 50;
    
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
            
            // Return the result
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => 'Task started. First batch processed.',
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
     * AJAX handler for getting app management task progress
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
        
        // Fix progress if not set but we have totals
        if ($progress_data['progress'] === 0 && $progress_data['total'] > 0 && $progress_data['processed'] > 0) {
            $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
        }
        
        $this->log_message("Returning progress data: progress={$progress_data['progress']}, processed={$progress_data['processed']}, total={$progress_data['total']}");
        
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
            'detailed_item_list' => array()
        );
        
        // Save to progress file
        file_put_contents($progress_file, json_encode($progress_data));
        
        $this->log_message("Task $task initialized with $total items, $batch_count batches");
        
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
            
            file_put_contents($progress_file, json_encode($progress_data));
            
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
            
            // Record the cleanup results
            $this->data->record_app_deletion_results($task, $progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
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
     * Process a batch of zero size apps
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
        
        // Process each app in the batch
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            $app_name = $app['name'];
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing app: $app_name (ID: $app_id)",
                'type' => 'info'
            );
            
            try {
                // Start transaction for each app
                $this->db_connection->begin_transaction();
                
                // Delete all related data for this app
                $this->data->delete_app_data($app_id);
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = $app_id";
                if (!$this->db_connection->query($app_query)) {
                    throw new Exception("Failed to delete application $app_id: " . $this->db_connection->error);
                }
                
                // Commit the transaction
                $this->db_connection->commit();
                
                $deleted++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Successfully deleted zero size app: $app_name (ID: $app_id)",
                    'type' => 'success'
                );
                
                // Add to detailed items
                $detailed_items[] = array(
                    'app_id' => $app_id,
                    'name' => $app_name,
                    'action' => 'deleted',
                    'timestamp' => date('Y-m-d H:i:s')
                );
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error deleting app $app_id: " . $e->getMessage(),
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
     * Process a batch of inactive apps
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
        
        // Process each app in the batch
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $email = isset($app['email']) ? $app['email'] : '';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing deleted app: $app_name (ID: $app_id)",
                'type' => 'info'
            );
            
            // Check if warning is required
            if ($send_warning) {
                if (!isset($warned_data[$app_id])) {
                    // Send warning email
                    if ($this->send_inactive_app_warning($app, $settings)) {
                        $warned++;
                        $warned_data[$app_id] = array(
                            'timestamp' => time(),
                            'expires' => time() + ($warning_period * 86400)
                        );
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Warning sent for inactive app $app_id",
                            'type' => 'info'
                        );
                        
                        // Add to detailed items
                        $detailed_items[] = array(
                            'app_id' => $app_id,
                            'name' => $app_name,
                            'email' => $email,
                            'action' => 'warned',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                        
                        continue;
                    } else {
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Failed to send warning for app $app_id",
                            'type' => 'error'
                        );
                        $errors++;
                        continue;
                    }
                } else {
                    // Check if warning period has expired
                    if (isset($warned_data[$app_id]['expires']) && time() < $warned_data[$app_id]['expires']) {
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Warning period not expired for app $app_id",
                            'type' => 'info'
                        );
                        $skipped++;
                        continue;
                    }
                }
            }
            
            // Delete the app
            try {
                // Start transaction for each app
                $this->db_connection->begin_transaction();
                
                // Delete all related data for this app
                $this->data->delete_app_data($app_id);
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = $app_id";
                if (!$this->db_connection->query($app_query)) {
                    throw new Exception("Failed to delete application $app_id: " . $this->db_connection->error);
                }
                
                // Commit the transaction
                $this->db_connection->commit();
                
                $deleted++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Successfully deleted inactive app: $app_name (ID: $app_id)",
                    'type' => 'success'
                );
                
                // Add to detailed items
                $detailed_items[] = array(
                    'app_id' => $app_id,
                    'name' => $app_name,
                    'email' => $email,
                    'action' => 'deleted',
                    'timestamp' => date('Y-m-d H:i:s')
                );
                
                // Remove from warned apps if present
                if (isset($warned_data[$app_id])) {
                    unset($warned_data[$app_id]);
                }
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error deleting app $app_id: " . $e->getMessage(),
                    'type' => 'error'
                );
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
     * Process a batch of size violation apps
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
        
        // Process each app in the batch
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $subscription_id = $app['subscription_id'];
            $email = isset($app['email']) ? $app['email'] : '';
            
            // Calculate sizes in MB for logging
            $size_mb = round($app['size_on_disk'] / (1024 * 1024), 2);
            $size_limit_mb = isset($size_limits[$subscription_id]) ? $size_limits[$subscription_id] : 'Not set';
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing size violation app: $app_name (ID: $app_id) - Size: {$size_mb}MB (Limit: {$size_limit_mb}MB)",
                'type' => 'info'
            );
            
            // Check if deletion should be immediate
            if ($delete_immediately) {
                // Delete immediately
            } else if ($send_warning) {
                // Handle warning flow
                if (!isset($warned_data[$app_id])) {
                    // Send warning email
                    if ($this->send_size_violation_warning($app, $settings, $size_limit_mb)) {
                        $warned++;
                        $warned_data[$app_id] = array(
                            'timestamp' => time(),
                            'expires' => time() + ($warning_period * 86400)
                        );
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Warning sent for size violation app $app_id",
                            'type' => 'info'
                        );
                        
                        // Add to detailed items
                        $detailed_items[] = array(
                            'app_id' => $app_id,
                            'name' => $app_name,
                            'email' => $email,
                            'subscription_id' => $subscription_id,
                            'size_mb' => $size_mb,
                            'size_limit_mb' => $size_limit_mb,
                            'action' => 'warned',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                        
                        continue;
                    } else {
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Failed to send warning for app $app_id",
                            'type' => 'error'
                        );
                        $errors++;
                        continue;
                    }
                } else {
                    // Check if warning period has expired
                    if (isset($warned_data[$app_id]['expires']) && time() < $warned_data[$app_id]['expires']) {
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Warning period not expired for app $app_id",
                            'type' => 'info'
                        );
                        $skipped++;
                        continue;
                    }
                }
            } else {
                // No immediate deletion and no warning configured
                $logs[] = array(
                    'time' => time(),
                    'message' => "Skipping app $app_id - no action configured",
                    'type' => 'info'
                );
                $skipped++;
                continue;
            }
            
            // Delete the app (either immediately or after warning period)
            try {
                // Start transaction for each app
                $this->db_connection->begin_transaction();
                
                // Delete all related data for this app
                $this->data->delete_app_data($app_id);
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = $app_id";
                if (!$this->db_connection->query($app_query)) {
                    throw new Exception("Failed to delete application $app_id: " . $this->db_connection->error);
                }
                
                // Commit the transaction
                $this->db_connection->commit();
                
                $deleted++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Successfully deleted size violation app: $app_name (ID: $app_id) - Size: {$size_mb}MB (Limit: {$size_limit_mb}MB)",
                    'type' => 'success'
                );
                
                // Add to detailed items
                $detailed_items[] = array(
                    'app_id' => $app_id,
                    'name' => $app_name,
                    'email' => $email,
                    'subscription_id' => $subscription_id,
                    'size_mb' => $size_mb,
                    'size_limit_mb' => $size_limit_mb,
                    'action' => 'deleted',
                    'timestamp' => date('Y-m-d H:i:s')
                );
                
                // Remove from warned apps if present
                if (isset($warned_data[$app_id])) {
                    unset($warned_data[$app_id]);
                }
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error deleting app $app_id: " . $e->getMessage(),
                    'type' => 'error'
                );
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
     * Process a batch of apps without users
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
        
        // Process each app in the batch
        foreach ($apps as $app) {
            $app_id = $app['app_id'];
            $app_name = isset($app['name']) ? $app['name'] : 'Unknown';
            $size_mb = isset($app['size_mb']) ? $app['size_mb'] : 0;
            
            $logs[] = array(
                'time' => time(),
                'message' => "Processing app without user: $app_name (ID: $app_id)",
                'type' => 'info'
            );
            
            try {
                // Start transaction for each app
                $this->db_connection->begin_transaction();
                
                // Modified verification to match initial query logic
                // Check if admin exists but with slightly different logic to match our search query
                if ($app['admin_id'] !== null) {
                    $verify_query = "SELECT COUNT(*) as user_count FROM admin WHERE admin_id = " . intval($app['admin_id']);
                    $verify_result = $this->db_connection->query($verify_query);
                    
                    if (!$verify_result) {
                        throw new Exception("Failed to verify user for app $app_id: " . $this->db_connection->error);
                    }
                    
                    $verify_row = $verify_result->fetch_assoc();
                    $verify_result->free_result();
                    
                    if (intval($verify_row['user_count']) > 0) {
                        // App now has a user, skip it
                        $this->db_connection->rollback();
                        $skipped++;
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Skipping app $app_id as it now has a user",
                            'type' => 'info'
                        );
                        
                        // Add to detailed items to track skipped apps
                        $detailed_items[] = array(
                            'app_id' => $app_id,
                            'name' => $app_name,
                            'size_mb' => $size_mb,
                            'action' => 'skipped - user found upon verification',
                            'timestamp' => date('Y-m-d H:i:s')
                        );
                        
                        continue;
                    }
                }
                
                // Delete all related data for this app
                $this->data->delete_app_data($app_id);
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = $app_id";
                if (!$this->db_connection->query($app_query)) {
                    throw new Exception("Failed to delete application $app_id: " . $this->db_connection->error);
                }
                
                // Commit the transaction
                $this->db_connection->commit();
                
                $deleted++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Successfully deleted app without user: $app_name (ID: $app_id)",
                    'type' => 'success'
                );
                
                // Add to detailed items
                $detailed_items[] = array(
                    'app_id' => $app_id,
                    'name' => $app_name,
                    'size_mb' => $size_mb,
                    'action' => 'deleted',
                    'timestamp' => date('Y-m-d H:i:s')
                );
                
            } catch (Exception $e) {
                // Rollback the transaction
                $this->db_connection->rollback();
                
                $errors++;
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error deleting app $app_id: " . $e->getMessage(),
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
        
        // Get current batch index
        $batch_index = isset($progress_data['current_batch']) ? $progress_data['current_batch'] : 0;
        
        $this->log_message("Processing batch $batch_index for task $task_type");
        
        // Process just a single batch in this run
        $batch_result = $this->process_batch($task_type, $batch_index);
        
        // Get updated progress data
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        // Check if the task is now complete
        if (isset($batch_result['completed']) && $batch_result['completed']) {
            $this->log_message("Task $task_type completed in this run");
            
            // Format operation details for the report
            $operation_details = array(
                'task' => $task_type,
                'processed' => $progress_data['processed'],
                'total' => $progress_data['total'],
                'progress_percentage' => 100, // Force 100% for completed tasks
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
            
            // Add detailed items list for reporting
            if (isset($progress_data['detailed_item_list']) && !empty($progress_data['detailed_item_list'])) {
                $operation_details['deleted_apps_list'] = $progress_data['detailed_item_list'];
                
                // Also provide a UI-friendly format for display in action logs
                $formatted_apps = array();
                foreach ($progress_data['detailed_item_list'] as $app) {
                    if (isset($app['action']) && $app['action'] === 'deleted') {
                        $timestamp = isset($app['timestamp']) ? $app['timestamp'] : date('Y-m-d H:i:s');
                        $id = isset($app['app_id']) ? $app['app_id'] : "unknown";
                        $name = isset($app['name']) ? $app['name'] : "unknown";
                        $size_info = isset($app['size_mb']) ? " - Size: {$app['size_mb']} MB" : "";
                        $limit_info = isset($app['size_limit_mb']) ? " - Limit: {$app['size_limit_mb']} MB" : "";
                        $email_info = isset($app['email']) && !empty($app['email']) ? " - Owner: {$app['email']}" : "";
                        
                        $formatted_apps[] = array(
                            'app_id' => $id,
                            'name' => $name,
                            'size_mb' => isset($app['size_mb']) ? $app['size_mb'] : '',
                            'size_limit_mb' => isset($app['size_limit_mb']) ? $app['size_limit_mb'] : '',
                            'email' => isset($app['email']) ? $app['email'] : '',
                            'timestamp' => $timestamp
                        );
                    }
                }
                
                // $operation_details['deleted_apps'] = $formatted_apps;
            }
            
            // Create a summary message with explicit 100% progress
            $summary = sprintf(
                __("Processed %d out of %d items (100%%). Deleted %d, skipped %d, warned %d, with %d errors.", 'swiftspeed-siberian'),
                $progress_data['processed'],
                $progress_data['total'],
                $progress_data['deleted'] ?? 0,
                $progress_data['skipped'] ?? 0,
                $progress_data['warned'] ?? 0,
                $progress_data['errors'] ?? 0
            );
            
            $operation_details['summary'] = $summary;
            
            // Generate a message for the result
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
                'success' => true,
                'message' => $message,
                'operation_details' => $operation_details
            );
            
            return array(
                'success' => true,
                'message' => $message,
                'operation_details' => $operation_details,
                'completed' => true
            );
        } else {
            // Task is not complete yet, we'll continue in the next scheduler run
            $this->log_message("Task $task_type not completed yet, will continue in next run at batch {$batch_result['next_batch']}");
            
            // Format operation details for partial progress reporting
            $operation_details = array(
                'task' => $task_type,
                'processed' => $progress_data['processed'],
                'total' => $progress_data['total'],
                'progress_percentage' => $progress_data['progress'],
                'timestamp' => time(),
                'timestamp_formatted' => date('Y-m-d H:i:s', time()),
                'status' => 'in_progress',
                'batch_index' => $batch_index,
                'next_batch' => $batch_result['next_batch']
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
            
            // Create a summary for progress reporting with explicit progress percentage
            $summary = sprintf(
                __("Processed %d out of %d items (%d%%).", 'swiftspeed-siberian'),
                $progress_data['processed'],
                $progress_data['total'],
                $progress_data['progress']
            );
            
            $operation_details['summary'] = $summary;
            
            // Check if we have any deleted items already to report
            if (isset($progress_data['detailed_item_list']) && !empty($progress_data['detailed_item_list'])) {
                $deleted_apps = array_filter($progress_data['detailed_item_list'], function($app) {
                    return isset($app['action']) && $app['action'] === 'deleted';
                });
                
                if (!empty($deleted_apps)) {
                    $formatted_apps = array();
                    $deleted_apps_slice = array_slice($deleted_apps, -10); // Last 10 for display
                    
                    foreach ($deleted_apps_slice as $app) {
                        $timestamp = isset($app['timestamp']) ? $app['timestamp'] : date('Y-m-d H:i:s');
                        $id = isset($app['app_id']) ? $app['app_id'] : "unknown";
                        $name = isset($app['name']) ? $app['name'] : "unknown";
                        $size_info = isset($app['size_mb']) ? " - Size: {$app['size_mb']} MB" : "";
                        $limit_info = isset($app['size_limit_mb']) ? " - Limit: {$app['size_limit_mb']} MB" : "";
                        $email_info = isset($app['email']) && !empty($app['email']) ? " - Owner: {$app['email']}" : "";
                        
                        $formatted_apps[] = array(
                            'app_id' => $id,
                            'name' => $name,
                            'size_mb' => isset($app['size_mb']) ? $app['size_mb'] : '',
                            'size_limit_mb' => isset($app['size_limit_mb']) ? $app['size_limit_mb'] : '',
                            'email' => isset($app['email']) ? $app['email'] : '',
                            'timestamp' => $timestamp
                        );
                    }
                    
                    $operation_details['deleted_apps'] = $formatted_apps;
                    $operation_details['deleted_apps_count'] = count($deleted_apps);
                }
            }
            
            // Format the message for this run
            $message = sprintf(
                __("Batch %d processed. Will continue next run.", 'swiftspeed-siberian'),
                $batch_index
            );
            
            // For compatibility with the action logs system
            global $swsib_last_task_result;
            $swsib_last_task_result = array(
                'success' => true,
                'message' => $message,
                'operation_details' => $operation_details
            );
            
            return array(
                'success' => true,
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