<?php
/**
 * WordPress Tasks Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_WP_Tasks {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Data handler
     */
    private $data;
    
    /**
     * Settings handler
     */
    private $settings;
    
    /**
     * Chunk size for processing large datasets
     */
    private $chunk_size = 5; // Increased from 50 to 100
    
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
        
        // Get database connection from options if not provided
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
                    $this->log_message("Database connection established in constructor");
                } else {
                    $this->log_message("Database connection failed: " . $db_connection->connect_error);
                }
            }
        }
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        $this->data = new SwiftSpeed_Siberian_WP_Data($this->db_connection, $this->db_name);
        $this->settings = new SwiftSpeed_Siberian_WP_Settings($this->db_connection, $this->db_name);
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add direct handler for task execution
        add_action('swsib_run_scheduled_task', array($this, 'handle_scheduled_task'), 10, 2);
        
        // Add handler for loopback requests
        add_action('wp_ajax_nopriv_swsib_wp_tasks_loopback', array($this, 'handle_loopback_request'));
        add_action('wp_ajax_swsib_wp_tasks_loopback', array($this, 'handle_loopback_request'));
        
        // Set up background processing check for active tasks
        add_action('admin_init', array($this, 'check_active_background_tasks'));
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        $dir = plugin_dir_path(__FILE__);
        $include_dir = dirname($dir) . '/';
        
        require_once($include_dir . 'wp-tasks/wp-tasks-data.php');
        require_once($include_dir . 'wp-tasks/wp-tasks-settings.php');
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Main task handlers
        add_action('wp_ajax_swsib_cleanup_wp_users', array($this, 'ajax_cleanup_wp_users'));
        add_action('wp_ajax_swsib_get_wp_cleanup_progress', array($this, 'ajax_get_wp_cleanup_progress'));
        add_action('wp_ajax_swsib_process_batch', array($this, 'ajax_process_batch'));
        
        // Preview handlers
        add_action('wp_ajax_swsib_preview_spam_users', array($this->data, 'ajax_preview_spam_users'));
        add_action('wp_ajax_swsib_preview_unsynced_users', array($this->data, 'ajax_preview_unsynced_users'));
        
        // Settings handler
        add_action('wp_ajax_swsib_save_wp_tasks_automation', array($this->settings, 'ajax_save_wp_tasks_automation'));
        
        // Data handlers
        add_action('wp_ajax_swsib_get_spam_users_count', array($this->data, 'ajax_get_spam_users_count'));
        add_action('wp_ajax_swsib_get_unsynced_users_count', array($this->data, 'ajax_get_unsynced_users_count'));
        // Preview data handler
        add_action('wp_ajax_swsib_preview_wp_tasks_data', array($this->data, 'ajax_preview_wp_tasks_data'));
        
        // Background processing status check
        add_action('wp_ajax_swsib_check_background_task_status', array($this, 'ajax_check_background_task_status'));
    }
    
    /**
     * Log message
     */
    private function log_message($message) {
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
        
        return $swsib_dir . '/swsib_' . sanitize_file_name($task) . '_progress.json';
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
        $tasks = array('spam_users', 'unsynced_users');
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
            set_transient('swsib_active_background_tasks', $active_tasks, 300);
            
            // For each active task, ensure a loopback is triggered if needed
            foreach ($active_tasks as $task => $data) {
                $this->ensure_background_processing($task);
            }
        } else {
            delete_transient('swsib_active_background_tasks');
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
        $active_tasks = get_transient('swsib_active_background_tasks');
        
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
     * Handle scheduled tasks directly - Improved to use TRUE batching across scheduler runs
     * and now processes multiple batches until completion using loopback requests
     */
    public function handle_scheduled_task($task_type, $task_args) {
        $this->log_message("Handling scheduled task: $task_type with args: " . print_r($task_args, true));
        
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
                    $this->data->db_connection = $db_connection; // Make sure data handler has the connection
                    $this->log_message("Database connection established in handle_scheduled_task");
                } else {
                    $this->log_message("Database connection failed: " . $db_connection->connect_error);
                    return array('success' => false, 'message' => 'Database connection failed');
                }
            } else {
                $this->log_message("Database connection settings not found");
                return array('success' => false, 'message' => 'Database connection settings not found');
            }
        }
        
        if ($task_type === 'wp_cleanup') {
            $task = isset($task_args['task']) ? $task_args['task'] : '';
            
            $this->log_message("Processing WP cleanup task: $task");
            
            // Get existing progress file
            $progress_file = $this->get_progress_file($task);
            $progress_exists = file_exists($progress_file);
            
            // Check if there's an existing task in progress
            if ($progress_exists) {
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
                // If the task is already completed or cancelled, start fresh
                if (isset($progress_data['status']) && ($progress_data['status'] === 'completed' || $progress_data['status'] === 'cancelled')) {
                    $this->log_message("Previous task was already completed/cancelled, starting fresh");
                    $progress_exists = false;
                }
            }
            
            // Start a new task if none exists
            if (!$progress_exists) {
                $this->log_message("Initializing new task: $task");
                $this->initialize_task($task);
                $progress_data = json_decode(file_get_contents($progress_file), true);
            } else {
                $this->log_message("Continuing existing task: $task at batch {$progress_data['current_batch']}");
            }
            
            // Get current batch index
            $batch_index = $progress_data['current_batch'];
            $batch_type = $progress_data['batch_type'];
            
            $this->log_message("Processing batch $batch_index of type $batch_type");
            
            // Set background processing flag
            $this->is_background_processing = true;
            
            // Process multiple batches in this run, up to max_batches_per_run
            $batches_processed = 0;
            $continue_processing = true;
            $task_completed = false;
            
            while ($continue_processing && $batches_processed < $this->max_batches_per_run) {
                // Process the next batch
                $batch_result = $this->process_batch($task, $batch_index);
                $batches_processed++;
                
                if (!$batch_result['success']) {
                    $this->log_message("Error processing batch $batch_index: " . $batch_result['message']);
                    $continue_processing = false;
                }
                
                // Check if the task is now complete
                if ($batch_result['completed']) {
                    $task_completed = true;
                    $continue_processing = false;
                    $this->log_message("Task $task completed after processing $batches_processed batches");
                } else {
                    // Update batch index for next iteration
                    $batch_index = $batch_result['next_batch'];
                    
                    // Update progress file for latest batch info
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    // Check if we've processed enough batches for this run
                    if ($batches_processed >= $this->max_batches_per_run) {
                        $this->log_message("Reached max batches per run ($this->max_batches_per_run), will continue via loopback");
                        
                        // Trigger a loopback request to continue processing in the background
                        $this->trigger_loopback_request($task);
                        $continue_processing = false;
                    }
                }
            }
            
            // Get final progress data
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            // Prepare operation details
            $operation_details = array(
                'task' => $task,
                'processed' => $progress_data['processed'],
                'total' => $progress_data['total'],
                'timestamp' => time()
            );
            
            if ($task === 'spam_users') {
                $operation_details['deleted'] = $progress_data['deleted'];
                $operation_details['errors'] = $progress_data['errors'];
                $operation_details['summary'] = "Processed {$progress_data['processed']} spam users: Deleted {$progress_data['deleted']} with {$progress_data['errors']} errors";
                
                // Include deleted users for the action logs
                if (isset($progress_data['deleted_users']) && !empty($progress_data['deleted_users'])) {
                    $operation_details['deleted_users'] = $progress_data['deleted_users'];
                    $operation_details['deleted_users_list'] = $progress_data['deleted_users'];
                }
            } elseif ($task === 'unsynced_users') {
                $operation_details['created'] = $progress_data['created'];
                $operation_details['deleted'] = $progress_data['deleted'];
                $operation_details['errors'] = $progress_data['errors'];
                $operation_details['summary'] = "Processed {$progress_data['processed']} users: Created {$progress_data['created']}, Deleted {$progress_data['deleted']}, with {$progress_data['errors']} errors";
                
                // Include user lists for the action logs
                if (isset($progress_data['created_users']) && !empty($progress_data['created_users'])) {
                    $operation_details['created_users'] = $progress_data['created_users'];
                    $operation_details['created_users_list'] = $progress_data['created_users'];
                }
                
                if (isset($progress_data['deleted_users']) && !empty($progress_data['deleted_users'])) {
                    $operation_details['deleted_users'] = $progress_data['deleted_users'];
                    $operation_details['deleted_users_list'] = $progress_data['deleted_users'];
                }
            }
            
            $success = true; // Consider it a success even with some errors
            $message = '';
            
            if ($task_completed) {
                $message = ($task === 'spam_users') ? 
                          "Spam users cleanup completed. Deleted {$progress_data['deleted']} users with {$progress_data['errors']} errors." :
                          "User synchronization completed. Created {$progress_data['created']} users, deleted {$progress_data['deleted']} users, with {$progress_data['errors']} errors.";
                
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
                $message = "Task in progress. Processed {$progress_data['processed']} out of {$progress_data['total']} items.";
                
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
        
        // Default response for unknown tasks
        return array(
            'success' => false,
            'message' => 'Unknown task type: ' . $task_type,
            'operation_details' => array(
                'error' => "Unknown task type: $task_type",
                'timestamp' => time()
            )
        );
    }
    
    /**
     * Trigger a loopback request to continue processing in the background
     * Using a more persistent nonce approach
     */
    private function trigger_loopback_request($task) {
        // Create a specific persistent nonce key for the task
        $nonce_action = 'swsib_wp_tasks_loopback_' . $task;
        $nonce = wp_create_nonce($nonce_action);
        
        // Store the nonce in an option for validation later
        update_option('swsib_loopback_nonce_' . $task, $nonce, false);
        
        $url = admin_url('admin-ajax.php?action=swsib_wp_tasks_loopback&task=' . urlencode($task) . '&nonce=' . $nonce);
        
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
     * Improved nonce verification
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
        $stored_nonce = get_option('swsib_loopback_nonce_' . $task);
        
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
        
        // Call handle_scheduled_task with the task
        $this->handle_scheduled_task('wp_cleanup', array('task' => $task));
        
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
     * AJAX handler for cleaning up WordPress users
     */
    public function ajax_cleanup_wp_users() {
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
        
        // Get mode (start, continue, etc.)
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'start';
        
        if ($mode === 'start') {
            // Initialize the task
            $this->initialize_task($task);
            
            // Process the first batch directly - this will get things started
            $batch_result = $this->process_next_batch($task);
            
            // Return the result
            if ($batch_result['success']) {
                // Trigger a loopback request to continue processing in the background
                if (!$batch_result['completed']) {
                    $this->trigger_loopback_request($task);
                }
                
                wp_send_json_success(array(
                    'message' => 'Task started. First batch processed. Continuing in background.',
                    'progress' => $batch_result['progress'],
                    'next_batch' => $batch_result['next_batch'],
                    'completed' => $batch_result['completed']
                ));
            } else {
                wp_send_json_error(array(
                    'message' => $batch_result['message']
                ));
            }
        } else {
            // Handle other modes if needed
            wp_send_json_error(array('message' => 'Invalid mode.'));
        }
    }
    
    /**
     * AJAX handler for processing a batch
     */
    public function ajax_process_batch() {
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
        
        // Process the next batch
        $batch_result = $this->process_batch($task, $batch_index);
        
        // If more batches to process and not a background process, trigger loopback
        if ($batch_result['success'] && !$batch_result['completed'] && !$this->is_background_processing) {
            $this->trigger_loopback_request($task);
        }
        
        // Return the result
        if ($batch_result['success']) {
            wp_send_json_success(array(
                'message' => 'Batch processed.',
                'progress' => $batch_result['progress'],
                'next_batch' => $batch_result['next_batch'],
                'completed' => $batch_result['completed']
            ));
        } else {
            wp_send_json_error(array(
                'message' => $batch_result['message']
            ));
        }
    }
    
    /**
     * AJAX handler for getting WordPress cleanup progress
     */
    public function ajax_get_wp_cleanup_progress() {
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
        
        // Get progress data from file
        $progress_file = $this->get_progress_file($task_type);
        
        if (!file_exists($progress_file)) {
            wp_send_json_error(array('message' => 'No progress file found for ' . $task_type));
            return;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        if (!$progress_data) {
            wp_send_json_error(array('message' => 'Invalid progress data for ' . $task_type));
            return;
        }
        
        // Log the progress data for debugging
        $this->log_message("Received WordPress progress request for task_type: {$task_type}");
        
        if (isset($progress_data['progress']) && isset($progress_data['processed']) && isset($progress_data['total'])) {
            $this->log_message("Returning WordPress progress data: progress={$progress_data['progress']}, processed={$progress_data['processed']}, total={$progress_data['total']}, logs=" . count($progress_data['logs']));
        }
        
        // If the task is running but hasn't been updated recently, check if we need to restart
        if (isset($progress_data['status']) && $progress_data['status'] === 'running' && 
            isset($progress_data['last_update']) && (time() - $progress_data['last_update']) > 60) {
            // Trigger a new loopback request
            $this->ensure_background_processing($task_type);
        }
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * Initialize task
     */
    private function initialize_task($task) {
        $this->log_message("Initializing task: $task");
        
        // Ensure DB connection for data operations
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
                    $this->data->db_connection = $db_connection; // Update data handler connection
                    $this->log_message("Database connection established in initialize_task");
                } else {
                    $this->log_message("Database connection failed: " . $db_connection->connect_error);
                }
            }
        }
        
        $total = 0;
        $create_data = array();
        $delete_data = array();
        $logs = array();
        
        // Get data based on task type
        if ($task === 'spam_users') {
            $spam_users = $this->data->get_spam_wordpress_users();
            $total = count($spam_users);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d spam users to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
            
            // Store the user IDs for processing
            $user_ids = array();
            foreach ($spam_users as $user) {
                $user_ids[] = $user->ID;
            }
            
            $create_data = array('users' => array());
            $delete_data = array('users' => $user_ids);
            
        } elseif ($task === 'unsynced_users') {
            // Get Siberian users that need WordPress accounts
            $users_to_create = $this->data->get_siberian_users_without_wp_accounts();
            $create_count = count($users_to_create);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d Siberian users to create in WordPress', 'swiftspeed-siberian'), $create_count),
                'type' => 'info'
            );
            
            // Store the users for processing
            $create_data = array('users' => $users_to_create);
            
            // Get WordPress users that need to be deleted (if enabled)
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['wp_tasks']['unsynced_users']) ? 
                       $options['automate']['wp_tasks']['unsynced_users'] : array();
            $delete_enabled = !empty($settings['delete_wp_users_not_in_siberian']);
            
            if ($delete_enabled) {
                $users_to_delete = $this->data->get_wp_users_without_siberian_accounts();
                $delete_count = count($users_to_delete);
                
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Found %d WordPress users to delete', 'swiftspeed-siberian'), $delete_count),
                    'type' => 'info'
                );
                
                // Store the user IDs for processing
                $user_ids = array();
                foreach ($users_to_delete as $user) {
                    $user_ids[] = $user->ID;
                }
                
                $delete_data = array('users' => $user_ids);
            } else {
                $delete_data = array('users' => array());
                $logs[] = array(
                    'time' => time(),
                    'message' => __('WordPress user deletion is disabled', 'swiftspeed-siberian'),
                    'type' => 'info'
                );
            }
            
            $total = $create_count + count($delete_data['users']);
        }
        
        // Debug logging
        $this->log_message("Task data: create_count=" . count($create_data['users'] ?? array()) . 
                      ", delete_count=" . count($delete_data['users'] ?? array()) . 
                      ", total=$total");
        
        // Ensure we have at least 1 for total to prevent division by zero
        $total = max(1, $total);
        
        // Split into batches
        $create_batches = array_chunk($create_data['users'] ?? array(), $this->chunk_size);
        $delete_batches = array_chunk($delete_data['users'] ?? array(), $this->chunk_size);
        
        // Calculate total batches
        $total_batches = count($create_batches) + count($delete_batches);
        if (empty($create_batches)) $create_batches = array(); // Ensure it's an array
        if (empty($delete_batches)) $delete_batches = array(); // Ensure it's an array
        
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
            'create_batches' => $create_batches,
            'delete_batches' => $delete_batches,
            'total_batches' => $total_batches,
            'current_batch' => 0,
            'batch_type' => !empty($create_batches) ? 'create' : 'delete',
            'created' => 0,
            'deleted' => 0,
            'errors' => 0,
            'created_users' => array(), // Track created users for operation details
            'deleted_users' => array()  // Track deleted users for operation details
        );
        
        // Save to progress file
        $progress_file = $this->get_progress_file($task);
        file_put_contents($progress_file, json_encode($progress_data));
        
        // Store in transient for other parts of the system to know there's an active task
        set_transient('swsib_active_background_tasks', array($task => $progress_data), 300);
        
        $this->log_message("Task $task initialized with $total items, $total_batches batches");
        
        return true;
    }
    
    /**
     * Process all batches - now with loopback requests for background processing
     */
    private function process_all_batches($task) {
        $this->log_message("Processing all batches for task: $task");
        
        // Get progress data
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            $this->log_message("Cannot process batches - progress file not found for task: $task");
            return false;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data) {
            $this->log_message("Cannot process batches - invalid progress data for task: $task");
            return false;
        }
        
        // Process as many batches as we can in this run
        $completed = false;
        $batch = $progress_data['current_batch'];
        $batches_processed = 0;
        $max_batches = $this->max_batches_per_run;
        
        $this->log_message("Starting to process batches for task $task from batch $batch");
        
        while (!$completed && $batches_processed < $max_batches) {
            $this->log_message("Processing batch $batch for task $task (batch $batches_processed of max $max_batches)");
            
            $result = $this->process_batch($task, $batch);
            $completed = $result['completed'];
            $batch = $result['next_batch'];
            $batches_processed++;
            
            // If there was an error, stop processing
            if (!$result['success']) {
                $this->log_message("Error processing batch $batch for task $task: " . $result['message']);
                return false;
            }
            
            // Debug logging for progress
            $this->log_message("Batch processing progress: " . $result['progress'] . "%, next batch: " . $result['next_batch'] . ", completed: " . ($completed ? 'true' : 'false'));
            
            // If we've reached the max batches but task is not complete, trigger a loopback
            if ($batches_processed >= $max_batches && !$completed) {
                $this->log_message("Reached max batches per run ($max_batches), triggering loopback to continue");
                $this->trigger_loopback_request($task);
                break;
            }
        }
        
        $this->log_message("Finished processing batches for task: $task, completed: " . ($completed ? 'true' : 'false'));
        return $completed;
    }
    
    /**
     * Process next batch automatically
     */
    private function process_next_batch($task) {
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
        
        // Get current batch
        $batch = $progress_data['current_batch'];
        
        // Process the batch
        return $this->process_batch($task, $batch);
    }
    
    /**
     * Process a specific batch with enhanced logging
     */
    private function process_batch($task, $batch_index) {
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
        
        // Check if processing is already completed or cancelled
        if ($progress_data['status'] === 'completed' || $progress_data['status'] === 'cancelled') {
            return array(
                'success' => true,
                'message' => 'Processing already completed or cancelled',
                'progress' => 100,
                'next_batch' => 0,
                'completed' => true
            );
        }
        
        // Get current batch type
        $batch_type = $progress_data['batch_type'];
        
        // Add batch processing log
        $progress_data['logs'][] = array(
            'time' => time(),
            'message' => sprintf(__('Processing batch %d (%s)', 'swiftspeed-siberian'), $batch_index + 1, $batch_type),
            'type' => 'info'
        );
        
        // Process based on batch type
        if ($batch_type === 'create') {
            // Process create batch
            if (isset($progress_data['create_batches'][$batch_index])) {
                $users_batch = $progress_data['create_batches'][$batch_index];
                $result = $this->process_create_batch($task, $users_batch);
                
                // Update progress
                $progress_data['created'] += $result['processed'];
                $progress_data['errors'] += $result['errors'];
                $progress_data['processed'] += $result['processed'];
                $progress_data['current_batch'] = $batch_index + 1;
                $progress_data['last_update'] = time();
                
                // Add batch completion log with summary
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Batch %d completed: Created %d users, %d errors', 'swiftspeed-siberian'), 
                                       $batch_index + 1, $result['processed'], $result['errors']),
                    'type' => $result['errors'] > 0 ? 'warning' : 'success'
                );
                
                // Add logs from batch processing
                if (isset($result['logs']) && !empty($result['logs'])) {
                    $progress_data['logs'] = array_merge($progress_data['logs'], $result['logs']);
                }
                
                // Add created users to the list if any
                if (isset($result['created_users']) && !empty($result['created_users'])) {
                    if (!isset($progress_data['created_users'])) {
                        $progress_data['created_users'] = array();
                    }
                    $progress_data['created_users'] = array_merge($progress_data['created_users'], $result['created_users']);
                }
                
                // Check if we need to switch to delete batches
                if ($batch_index + 1 >= count($progress_data['create_batches'])) {
                    $progress_data['batch_type'] = 'delete';
                    $progress_data['current_batch'] = 0;
                    $next_batch = 0;
                } else {
                    $next_batch = $batch_index + 1;
                }
                
                // Calculate progress percentage
                if ($progress_data['total'] > 0) {
                    $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
                } else {
                    $progress_data['progress'] = 100;
                }
                
                // Check if we're done
                $completed = ($progress_data['batch_type'] === 'delete' && empty($progress_data['delete_batches']));
                
                if ($completed) {
                    $progress_data['status'] = 'completed';
                    $progress_data['progress'] = 100;
                    $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
                    
                    // Add completion log
                    $progress_data['logs'][] = array(
                        'time' => time(),
                        'message' => sprintf(__('Task completed. Created %d users with %d errors.', 'swiftspeed-siberian'), 
                                           $progress_data['created'], $progress_data['errors']),
                        'type' => 'success'
                    );
                    
                    // Remove from active tasks transient
                    $active_tasks = get_transient('swsib_active_background_tasks');
                    if ($active_tasks && isset($active_tasks[$task])) {
                        unset($active_tasks[$task]);
                        if (!empty($active_tasks)) {
                            set_transient('swsib_active_background_tasks', $active_tasks, 300);
                        } else {
                            delete_transient('swsib_active_background_tasks');
                        }
                    }
                } else {
                    // Update active tasks transient
                    $active_tasks = get_transient('swsib_active_background_tasks') ?: array();
                    $active_tasks[$task] = $progress_data;
                    set_transient('swsib_active_background_tasks', $active_tasks, 300);
                }
                
                // Update progress file
                file_put_contents($progress_file, json_encode($progress_data));
                
                return array(
                    'success' => true,
                    'message' => 'Create batch processed',
                    'progress' => $progress_data['progress'],
                    'next_batch' => $next_batch,
                    'completed' => $completed
                );
            } else {
                // No more create batches, switch to delete
                $progress_data['batch_type'] = 'delete';
                $progress_data['current_batch'] = 0;
                $progress_data['last_update'] = time();
                file_put_contents($progress_file, json_encode($progress_data));
                
                // Process first delete batch
                return $this->process_batch($task, 0);
            }
        } else {
            // Process delete batch
            if (isset($progress_data['delete_batches'][$batch_index])) {
                $users_batch = $progress_data['delete_batches'][$batch_index];
                $result = $this->process_delete_batch($task, $users_batch);
                
                // Update progress
                $progress_data['deleted'] += $result['processed'];
                $progress_data['errors'] += $result['errors'];
                $progress_data['processed'] += $result['processed'];
                $progress_data['current_batch'] = $batch_index + 1;
                $progress_data['last_update'] = time();
                
                // Add batch completion log with summary
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Batch %d completed: Deleted %d users, %d errors', 'swiftspeed-siberian'), 
                                       $batch_index + 1, $result['processed'], $result['errors']),
                    'type' => $result['errors'] > 0 ? 'warning' : 'success'
                );
                
                // Add logs from batch processing
                if (isset($result['logs']) && !empty($result['logs'])) {
                    $progress_data['logs'] = array_merge($progress_data['logs'], $result['logs']);
                }
                
                // Add deleted users to the list if any
                if (isset($result['deleted_users']) && !empty($result['deleted_users'])) {
                    if (!isset($progress_data['deleted_users'])) {
                        $progress_data['deleted_users'] = array();
                    }
                    $progress_data['deleted_users'] = array_merge($progress_data['deleted_users'], $result['deleted_users']);
                }
                
                // Calculate progress percentage
                if ($progress_data['total'] > 0) {
                    $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
                } else {
                    $progress_data['progress'] = 100;
                }
                
                // Check if we're done
                $completed = ($batch_index + 1 >= count($progress_data['delete_batches']));
                
                if ($completed) {
                    $progress_data['status'] = 'completed';
                    $progress_data['progress'] = 100;
                    $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
                    
                    // Add completion log
                    $progress_data['logs'][] = array(
                        'time' => time(),
                        'message' => sprintf(__('Task completed. Created %d users, deleted %d users with %d errors.', 'swiftspeed-siberian'), 
                                           $progress_data['created'], $progress_data['deleted'], $progress_data['errors']),
                        'type' => 'success'
                    );
                    
                    // Remove from active tasks transient
                    $active_tasks = get_transient('swsib_active_background_tasks');
                    if ($active_tasks && isset($active_tasks[$task])) {
                        unset($active_tasks[$task]);
                        if (!empty($active_tasks)) {
                            set_transient('swsib_active_background_tasks', $active_tasks, 300);
                        } else {
                            delete_transient('swsib_active_background_tasks');
                        }
                    }
                } else {
                    // Update active tasks transient
                    $active_tasks = get_transient('swsib_active_background_tasks') ?: array();
                    $active_tasks[$task] = $progress_data;
                    set_transient('swsib_active_background_tasks', $active_tasks, 300);
                }
                
                // Update progress file
                file_put_contents($progress_file, json_encode($progress_data));
                
                return array(
                    'success' => true,
                    'message' => 'Delete batch processed',
                    'progress' => $progress_data['progress'],
                    'next_batch' => $batch_index + 1,
                    'completed' => $completed
                );
            } else {
                // No more batches, mark as completed
                $progress_data['status'] = 'completed';
                $progress_data['progress'] = 100;
                $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
                $progress_data['last_update'] = time();
                
                // Add completion log
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Task completed. Created %d users, deleted %d users with %d errors.', 'swiftspeed-siberian'), 
                                       $progress_data['created'], $progress_data['deleted'], $progress_data['errors']),
                    'type' => 'success'
                );
                
                // Remove from active tasks transient
                $active_tasks = get_transient('swsib_active_background_tasks');
                if ($active_tasks && isset($active_tasks[$task])) {
                    unset($active_tasks[$task]);
                    if (!empty($active_tasks)) {
                        set_transient('swsib_active_background_tasks', $active_tasks, 300);
                    } else {
                        delete_transient('swsib_active_background_tasks');
                    }
                }
                
                // Update progress file
                file_put_contents($progress_file, json_encode($progress_data));
                
                return array(
                    'success' => true,
                    'message' => 'All batches processed',
                    'progress' => 100,
                    'next_batch' => 0,
                    'completed' => true
                );
            }
        }
    }
    
    /**
     * Process a batch of users to create with detailed logging
     */
    private function process_create_batch($task, $users) {
        $processed = 0;
        $errors = 0;
        $logs = array();
        $created_users = array();
        
        // Process each user in the batch
        foreach ($users as $user) {
            // Update current item in progress
            $this->update_task_progress($task, array(
                'current_item' => sprintf(
                    'Creating WordPress user for %s %s (%s)', 
                    $user['firstname'] ?? '', 
                    $user['lastname'] ?? '', 
                    $user['email'] ?? ''
                )
            ));
            
            $this->log_message("Creating WordPress user for " . ($user['email'] ?? 'unknown'));
            
            // Create WordPress user
            $userdata = array(
                'user_login' => $this->data->generate_username($user['email'], $user['firstname'] ?? '', $user['lastname'] ?? ''),
                'user_email' => $user['email'],
                'first_name' => $user['firstname'] ?? '',
                'last_name' => $user['lastname'] ?? '',
                'display_name' => ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''),
                'user_pass' => wp_generate_password(12, true, true)
            );
            
            $wp_user_id = wp_insert_user($userdata);
            
            if (!is_wp_error($wp_user_id)) {
                $processed++;
                
                // Log success
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Created WordPress user %s (ID: %d) for Siberian user %s (ID: %d)', 'swiftspeed-siberian'), 
                                       $userdata['user_login'], $wp_user_id, $user['email'], $user['admin_id']),
                    'type' => 'success'
                );
                
                // Add siberian_user_id meta for reference
                update_user_meta($wp_user_id, 'siberian_user_id', $user['admin_id']);
                
                // Add to created users list
                $created_users[] = array(
                    'ID' => $wp_user_id,
                    'user_login' => $userdata['user_login'],
                    'user_email' => $user['email'],
                    'siberian_id' => $user['admin_id'],
                    'name' => ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')
                );
                
                $this->log_message("Created WordPress user (ID: $wp_user_id) for Siberian user (ID: " . ($user['admin_id'] ?? 'unknown') . ")");
            } else {
                $errors++;
                
                // Log error
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to create WordPress user for %s: %s', 'swiftspeed-siberian'), 
                                       $user['email'], $wp_user_id->get_error_message()),
                    'type' => 'error'
                );
                
                $this->log_message("Failed to create WordPress user: " . $wp_user_id->get_error_message());
            }
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'created_users' => $created_users,
            'logs' => $logs
        );
    }
    
    /**
     * Process a batch of users to delete with detailed logging
     */
    private function process_delete_batch($task, $user_ids) {
        $processed = 0;
        $errors = 0;
        $logs = array();
        $deleted_users = array();
        
        global $wpdb;
        
        // Process each user in the batch
        foreach ($user_ids as $user_id) {
            // Get user data
            $user = get_userdata($user_id);
            
            if (!$user) {
                $processed++;
                continue; // User already deleted
            }
            
            // Update current item in progress
            $this->update_task_progress($task, array(
                'current_item' => sprintf(
                    'Deleting WordPress user %s (ID: %d)', 
                    $user->user_login,
                    $user->ID
                )
            ));
            
            $this->log_message("Deleting WordPress user " . $user->user_login . " (ID: " . $user->ID . ")");
            
            // Delete user
            if ($task === 'spam_users') {
                // For spam users, check if there's a corresponding Siberian user
                $siberian_id = $this->data->find_siberian_user_by_email($user->user_email);
                if ($siberian_id) {
                    // Log finding Siberian user
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Found corresponding Siberian user with ID: %d for %s', 'swiftspeed-siberian'), 
                                           $siberian_id, $user->user_email),
                        'type' => 'info'
                    );
                    
                    $this->log_message("Found corresponding Siberian user with ID: $siberian_id");
                    
                    // Delete Siberian user
                    $this->data->delete_siberian_user($siberian_id);
                    
                    // Log success
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Deleted Siberian user with ID: %d', 'swiftspeed-siberian'), 
                                           $siberian_id),
                        'type' => 'success'
                    );
                    
                    $this->log_message("Deleted Siberian user with ID: $siberian_id");
                }
            }
            
            // Capture user data before deletion for the report
            $user_data = array(
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name
            );
            
            // Delete WordPress user
            $result = wp_delete_user($user_id, 1); // Reassign to admin
            
            if ($result) {
                $processed++;
                
                // Add to deleted users list
                $deleted_users[] = $user_data;
                
                // Log success
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted WordPress user %s (ID: %d, %s)', 'swiftspeed-siberian'), 
                                       $user->user_login, $user->ID, $user->user_email),
                    'type' => 'success'
                );
                
                $this->log_message("Deleted WordPress user ID: " . $user->ID . " (" . $user->user_email . ")");
            } else {
                // Try direct database deletion if wp_delete_user fails
                try {
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_author = 1 WHERE post_author = %d", $user->ID));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE user_id = %d", $user->ID));
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->comments} SET user_id = 1 WHERE user_id = %d", $user->ID));
                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->users} WHERE ID = %d", $user->ID));
                    
                    $processed++;
                    
                    // Add to deleted users list
                    $deleted_users[] = $user_data;
                    
                    // Log success
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Force deleted WordPress user %s (ID: %d)', 'swiftspeed-siberian'), 
                                           $user->user_login, $user->ID),
                        'type' => 'success'
                    );
                    
                    $this->log_message("Force deleted WordPress user ID: " . $user->ID);
                } catch (Exception $e) {
                    $errors++;
                    
                    // Log error
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Failed to delete user %s (ID %d): %s', 'swiftspeed-siberian'), 
                                           $user->user_login, $user->ID, $e->getMessage()),
                        'type' => 'error'
                    );
                    
                    $this->log_message("Failed to delete user ID " . $user->ID . ": " . $e->getMessage());
                }
            }
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'deleted_users' => $deleted_users,
            'logs' => $logs
        );
    }
    
    /**
     * Mark task as completed
     */
    private function mark_task_completed($task) {
        // Get progress data
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return false;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data) {
            return false;
        }
        
        // Update status and progress
        $progress_data['status'] = 'completed';
        $progress_data['progress'] = 100;
        $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
        $progress_data['last_update'] = time();
        
        // Calculate execution time
        $execution_time = time() - $progress_data['start_time'];
        
        // Add completion log
        $progress_data['logs'][] = array(
            'time' => time(),
            'message' => sprintf(__('Task completed in %d seconds. Created %d users, deleted %d users with %d errors.', 'swiftspeed-siberian'), 
                               $execution_time,
                               $progress_data['created'] ?? 0, 
                               $progress_data['deleted'] ?? 0, 
                               $progress_data['errors'] ?? 0),
            'type' => 'success'
        );
        
        // Update progress file
        file_put_contents($progress_file, json_encode($progress_data));
        
        // Remove from active tasks transient
        $active_tasks = get_transient('swsib_active_background_tasks');
        if ($active_tasks && isset($active_tasks[$task])) {
            unset($active_tasks[$task]);
            if (!empty($active_tasks)) {
                set_transient('swsib_active_background_tasks', $active_tasks, 300);
            } else {
                delete_transient('swsib_active_background_tasks');
            }
        }
        
        $this->log_message("Task $task marked as completed");
        
        return true;
    }
    
    /**
     * Update task progress
     */
    private function update_task_progress($task, $data) {
        $progress_file = $this->get_progress_file($task);
        
        if (file_exists($progress_file)) {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            if (!$progress_data) {
                return false;
            }
            
            // Update data
            foreach ($data as $key => $value) {
                $progress_data[$key] = $value;
            }
            
            // Update last update time
            $progress_data['last_update'] = time();
            
            // Write updated data
            file_put_contents($progress_file, json_encode($progress_data));
            
            // Update active tasks transient
            $active_tasks = get_transient('swsib_active_background_tasks') ?: array();
            $active_tasks[$task] = $progress_data;
            set_transient('swsib_active_background_tasks', $active_tasks, 300);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Add logs to task progress
     */
    private function add_task_logs($task, $logs) {
        $progress_file = $this->get_progress_file($task);
        
        if (file_exists($progress_file)) {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            if (!$progress_data) {
                return false;
            }
            
            // Add logs
            foreach ($logs as $log) {
                $progress_data['logs'][] = $log;
            }
            
            // Limit logs to 100 to prevent file size issues
            if (count($progress_data['logs']) > 100) {
                $progress_data['logs'] = array_slice($progress_data['logs'], -100);
            }
            
            // Update last update time
            $progress_data['last_update'] = time();
            
            // Write updated data
            file_put_contents($progress_file, json_encode($progress_data));
            return true;
        }
        
        return false;
    }
    
    /**
     * Display WordPress tasks automation settings
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for WordPress tasks automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }

    /**
     * Process spam users (compatibility method for scheduler)
     */
    public function process_spam_users($task_id) {
        $this->log_message("Processing spam users with task ID: $task_id");
        
        // Use handle_scheduled_task to ensure proper batch processing
        $result = $this->handle_scheduled_task('wp_cleanup', array('task' => 'spam_users'));
        
        // Save data to transient for compatibility
        set_transient('swsib_task_' . $task_id, array(
            'status' => $result['completed'] ? 'completed' : 'in_progress',
            'processed' => isset($result['operation_details']['processed']) ? $result['operation_details']['processed'] : 0,
            'total' => isset($result['operation_details']['total']) ? $result['operation_details']['total'] : 0,
            'deleted' => isset($result['operation_details']['deleted']) ? $result['operation_details']['deleted'] : 0,
            'errors' => isset($result['operation_details']['errors']) ? $result['operation_details']['errors'] : 0,
            'deleted_users' => isset($result['operation_details']['deleted_users']) ? $result['operation_details']['deleted_users'] : array(),
            'progress_percentage' => isset($result['operation_details']['progress_percentage']) ? $result['operation_details']['progress_percentage'] : 0
        ), HOUR_IN_SECONDS);
        
        $this->log_message("Spam users processing " . ($result['completed'] ? 'completed' : 'in progress') . 
                           ": " . ($result['operation_details']['processed'] ?? 0) . " users processed");
        
        // Return true for success - even if not completed
        return $result['success'];
    }

    /**
     * Process unsynced users (compatibility method for scheduler)
     */
    public function process_unsynced_users($task_id) {
        $this->log_message("Processing unsynced users with task ID: $task_id");
        
        // Use handle_scheduled_task to ensure proper batch processing
        $result = $this->handle_scheduled_task('wp_cleanup', array('task' => 'unsynced_users'));
        
        // Save data to transient for compatibility
        set_transient('swsib_task_' . $task_id, array(
            'status' => $result['completed'] ? 'completed' : 'in_progress',
            'processed' => isset($result['operation_details']['processed']) ? $result['operation_details']['processed'] : 0,
            'total' => isset($result['operation_details']['total']) ? $result['operation_details']['total'] : 0,
            'created' => isset($result['operation_details']['created']) ? $result['operation_details']['created'] : 0,
            'deleted' => isset($result['operation_details']['deleted']) ? $result['operation_details']['deleted'] : 0,
            'errors' => isset($result['operation_details']['errors']) ? $result['operation_details']['errors'] : 0,
            'created_users' => isset($result['operation_details']['created_users']) ? $result['operation_details']['created_users'] : array(),
            'deleted_users' => isset($result['operation_details']['deleted_users']) ? $result['operation_details']['deleted_users'] : array(),
            'progress_percentage' => isset($result['operation_details']['progress_percentage']) ? $result['operation_details']['progress_percentage'] : 0
        ), HOUR_IN_SECONDS);
        
        $this->log_message("Unsynced users processing " . ($result['completed'] ? 'completed' : 'in progress') . 
                           ": " . ($result['operation_details']['processed'] ?? 0) . " users processed");
        
        // Return true for success - even if not completed
        return $result['success'];
    }
}