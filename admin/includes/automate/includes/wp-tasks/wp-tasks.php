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
    private $chunk_size = 50;
    
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
     * Handle scheduled tasks directly - Improved to use TRUE batching across scheduler runs
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
                
                // If the task is already completed, start fresh
                if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                    $this->log_message("Previous task was already completed, starting fresh");
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
            
            // Process just a single batch in this run
            $batch_result = $this->process_batch($task, $batch_index);
            
            // Check if the task is now complete
            if ($batch_result['completed']) {
                $this->log_message("Task $task completed in this run");
                
                // Get final progress data for rich operation details
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
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
                $message = ($task === 'spam_users') ? 
                          "Spam users cleanup completed. Deleted {$progress_data['deleted']} users with {$progress_data['errors']} errors." :
                          "User synchronization completed. Created {$progress_data['created']} users, deleted {$progress_data['deleted']} users, with {$progress_data['errors']} errors.";
                
                // For compatibility with the action logs system
                global $swsib_last_task_result;
                $swsib_last_task_result = array(
                    'success' => $success,
                    'message' => $message,
                    'operation_details' => $operation_details
                );
                
                return array(
                    'success' => $success,
                    'message' => $message,
                    'operation_details' => $operation_details
                );
            } else {
                // Task is not complete yet, we'll continue in the next scheduler run
                $this->log_message("Task $task not completed yet, will continue in next run at batch {$batch_result['next_batch']}");
                
                // Return partial progress for logging purposes
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
                $operation_details = array(
                    'task' => $task,
                    'processed' => $progress_data['processed'],
                    'total' => $progress_data['total'],
                    'timestamp' => time(),
                    'status' => 'in_progress',
                    'progress_percentage' => $progress_data['progress'],
                    'batch' => $batch_result['next_batch'],
                    'summary' => "Task in progress: Processed {$progress_data['processed']} out of {$progress_data['total']} items (" . $progress_data['progress'] . "%)"
                );
                
                if ($task === 'spam_users') {
                    $operation_details['deleted'] = $progress_data['deleted'];
                    $operation_details['errors'] = $progress_data['errors'];
                } elseif ($task === 'unsynced_users') {
                    $operation_details['created'] = $progress_data['created'];
                    $operation_details['deleted'] = $progress_data['deleted'];
                    $operation_details['errors'] = $progress_data['errors'];
                }
                
                return array(
                    'success' => true,
                    'message' => "Task in progress. Processed {$progress_data['processed']} out of {$progress_data['total']} items.",
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
        
        // Initialize the task
        $this->initialize_task($task);
        
        // Process the first batch directly - this will get things started
        $batch_result = $this->process_next_batch($task);
        
        // Return the result
        if ($batch_result['success']) {
            wp_send_json_success(array(
                'message' => 'Task started. First batch processed.',
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
        
        $this->log_message("Task $task initialized with $total items, $total_batches batches");
        
        return true;
    }
    
    /**
     * Process all batches - only used for manual UI tasks, not for automated tasks
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
        
        // Process all remaining batches
        $completed = false;
        $batch = 0;
        $max_iterations = 100; // Safety limit
        $iterations = 0;
        
        while (!$completed && $iterations < $max_iterations) {
            $iterations++;
            $this->log_message("Processing batch $batch for task $task (iteration $iterations)");
            
            $result = $this->process_batch($task, $batch);
            $completed = $result['completed'];
            $batch = $result['next_batch'];
            
            // Sleep briefly to prevent server overload
            usleep(100000); // 0.1 second
            
            // If there was an error, stop processing
            if (!$result['success']) {
                $this->log_message("Error processing batch $batch for task $task: " . $result['message']);
                return false;
            }
            
            // Debug logging for progress
            $this->log_message("Batch processing progress: " . $result['progress'] . "%, next batch: " . $result['next_batch'] . ", completed: " . ($completed ? 'true' : 'false'));
        }
        
        // Check if we hit the safety limit
        if ($iterations >= $max_iterations && !$completed) {
            $this->log_message("Warning: Hit safety limit of $max_iterations iterations without completing task");
        }
        
        $this->log_message("Finished processing all batches for task: $task");
        return true;
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
     * Process a specific batch
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
        
        // Get current batch type
        $batch_type = $progress_data['batch_type'];
        
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
                
                // Add completion log
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Task completed. Created %d users, deleted %d users with %d errors.', 'swiftspeed-siberian'), 
                                       $progress_data['created'], $progress_data['deleted'], $progress_data['errors']),
                    'type' => 'success'
                );
                
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
     * Process a batch of users to create
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
                    'message' => sprintf(__('Created WordPress user (ID: %d) for Siberian user (ID: %d)', 'swiftspeed-siberian'), 
                                       $wp_user_id, $user['admin_id']),
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
                    'message' => sprintf(__('Failed to create WordPress user: %s', 'swiftspeed-siberian'), 
                                       $wp_user_id->get_error_message()),
                    'type' => 'error'
                );
                
                $this->log_message("Failed to create WordPress user: " . $wp_user_id->get_error_message());
            }
        }
        
        // Add logs to progress
        $this->add_task_logs($task, $logs);
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'created_users' => $created_users
        );
    }
    
    /**
     * Process a batch of users to delete
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
                        'message' => sprintf(__('Found corresponding Siberian user with ID: %d', 'swiftspeed-siberian'), 
                                           $siberian_id),
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
                    'message' => sprintf(__('Deleted WordPress user ID: %d (%s)', 'swiftspeed-siberian'), 
                                       $user->ID, $user->user_email),
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
                        'message' => sprintf(__('Force deleted WordPress user ID: %d', 'swiftspeed-siberian'), 
                                           $user->ID),
                        'type' => 'success'
                    );
                    
                    $this->log_message("Force deleted WordPress user ID: " . $user->ID);
                } catch (Exception $e) {
                    $errors++;
                    
                    // Log error
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Failed to delete user ID %d: %s', 'swiftspeed-siberian'), 
                                           $user->ID, $e->getMessage()),
                        'type' => 'error'
                    );
                    
                    $this->log_message("Failed to delete user ID " . $user->ID . ": " . $e->getMessage());
                }
            }
        }
        
        // Add logs to progress
        $this->add_task_logs($task, $logs);
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'deleted_users' => $deleted_users
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