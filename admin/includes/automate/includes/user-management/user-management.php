<?php
/**
 * User Management Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_User_Management {
    
    /**
     * Database connection
     */
    private $db_connection = null;
    
    /**
     * Database name
     */
    private $db_name = null;
    
    /**
     * Email manager instance
     */
    private $email_manager;
    
    /**
     * User data handler
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
     * Flag to track if we created a new connection that needs to be closed
     */
    private $connection_needs_closing = false;
    
    /**
     * Maximum number of connection attempts
     */
    private $max_connection_attempts = 3;
    
    /**
     * Delay between connection attempts (microseconds)
     */
    private $connection_retry_delay = 500000; // 0.5 seconds
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        // Store provided connection if available and valid
        if ($db_connection && !$db_connection->connect_error) {
            $this->db_connection = $db_connection;
            $this->db_name = $db_name;
            $this->connection_needs_closing = false;
        } else {
            // Initialize connection as null - we'll connect lazily when needed
            $this->db_connection = null;
            $this->db_name = null;
            // We won't establish connection in the constructor anymore
        }
        
        if (class_exists('SwiftSpeed_Siberian_Email_Manager')) {
            $this->email_manager = new SwiftSpeed_Siberian_Email_Manager();
        }
        
        // Include required files
        $this->include_files();
        
        // Initialize components with null connections - they'll get valid connections when needed
        $this->data = new SwiftSpeed_Siberian_User_Data(null, null);
        $this->settings = new SwiftSpeed_Siberian_User_Settings(null, null);
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add direct handler for task execution
        add_action('swsib_run_scheduled_task', array($this, 'handle_scheduled_task'), 10, 2);
    }
    
    /**
     * Destructor - ensure connection is closed if we created it
     */
    public function __destruct() {
        $this->close_connection();
    }
    
    /**
     * Close the database connection if we created it
     */
    public function close_connection() {
        if ($this->connection_needs_closing && $this->db_connection !== null) {
            try {
                $this->db_connection->close();
                $this->db_connection = null;
                $this->connection_needs_closing = false;
                $this->log_message("Closed database connection");
            } catch (Exception $e) {
                $this->log_message("Error closing database connection: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Get database connection - lazy initialization
     * Will attempt to establish a connection if one doesn't exist
     * 
     * @return mysqli|null Database connection or null on failure
     */
    public function get_db_connection() {
        // If we already have a valid connection, return it
        if ($this->db_connection !== null && !$this->db_connection->connect_error && $this->db_connection->ping()) {
            return $this->db_connection;
        }
        
        // If connection is invalid and we need to close it
        if ($this->db_connection !== null && $this->connection_needs_closing) {
            $this->close_connection();
        }
        
        // Get database connection options
        $options = get_option('swsib_options', array());
        $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
        
        if (empty($db_options['host']) || empty($db_options['database']) || 
            empty($db_options['username']) || empty($db_options['password'])) {
            $this->log_message("Database connection options not properly configured");
            return null;
        }
        
        // Attempt to connect with retry logic
        $attempts = 0;
        $connected = false;
        
        while (!$connected && $attempts < $this->max_connection_attempts) {
            $attempts++;
            
            try {
                // Set mysqli to throw exceptions
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                
                // Create new connection
                $db_connection = new mysqli(
                    $db_options['host'],
                    $db_options['username'],
                    $db_options['password'],
                    $db_options['database'],
                    isset($db_options['port']) ? intval($db_options['port']) : 3306
                );
                
                // Reset mysqli report mode
                mysqli_report(MYSQLI_REPORT_OFF);
                
                // Check connection
                if (!$db_connection->connect_error) {
                    $this->db_connection = $db_connection;
                    $this->db_name = $db_options['database'];
                    $this->connection_needs_closing = true;
                    $connected = true;
                    
                    $this->log_message("Database connection established (attempt {$attempts})");
                    
                    // Update the connection in the child components
                    $this->update_component_connections();
                    
                    return $this->db_connection;
                }
            } catch (Exception $e) {
                $this->log_message("Database connection failed (attempt {$attempts}): " . $e->getMessage());
                
                // Wait before retry
                if ($attempts < $this->max_connection_attempts) {
                    usleep($this->connection_retry_delay);
                }
            }
        }
        
        $this->log_message("All database connection attempts failed");
        return null;
    }
    

/**
 * Update database connection in child components
 */
private function update_component_connections() {
    if ($this->data !== null) {
        $this->data->db_connection = $this->db_connection;
        // Change this line:
        // $this->data->db_name = $this->db_name; 
        // To use a setter method or make the property public:
        if (method_exists($this->data, 'set_db_name')) {
            $this->data->set_db_name($this->db_name);
        } else {
            // Fallback if setter doesn't exist - add setter to User_Data class
            $this->log_message("Warning: set_db_name method missing in data component");
        }
    }
    
    if ($this->settings !== null) {
        // Use the setter methods instead of direct property access
        $this->settings->set_db_connection($this->db_connection);
        $this->settings->set_db_name($this->db_name);
    }
}
    
    /**
     * Include required files
     */
    private function include_files() {
        $dir = plugin_dir_path(__FILE__);
        
        if (file_exists($dir . 'user-data.php')) {
            require_once($dir . 'user-data.php');
        }
        
        if (file_exists($dir . 'user-settings.php')) {
            require_once($dir . 'user-settings.php');
        }
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Main user management handlers
        add_action('wp_ajax_swsib_manage_users', array($this, 'ajax_manage_users'));
        add_action('wp_ajax_swsib_get_user_progress', array($this, 'ajax_get_user_progress'));
        add_action('wp_ajax_swsib_process_user_batch', array($this, 'ajax_process_user_batch'));
        
        // Count AJAX handlers
        add_action('wp_ajax_swsib_get_inactive_users_count', array($this->data, 'ajax_get_inactive_users_count'));
        add_action('wp_ajax_swsib_get_users_without_apps_count', array($this->data, 'ajax_get_users_without_apps_count'));
        
        // Preview data handler
        add_action('wp_ajax_swsib_preview_user_data', array($this->data, 'ajax_preview_user_data'));
        
        // Settings AJAX handlers
        add_action('wp_ajax_swsib_save_user_management_automation', array($this->settings, 'ajax_save_user_management_automation'));
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
        
        return $swsib_dir . '/swsib_user_' . sanitize_file_name($task) . '_progress.json';
    }
    
    /**
     * Handle scheduled task - Using batch processing
     * Improved with proper database connection handling
     */
    public function handle_scheduled_task($task_type, $task_args) {
        $this->log_message("Handling scheduled user management task: $task_type with args: " . print_r($task_args, true));
        
        try {
            // Ensure we have a database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                $this->log_message("Failed to establish database connection for scheduled task");
                return array(
                    'success' => false,
                    'message' => "Database connection failed",
                    'operation_details' => array(
                        'error' => 'Database connection failed',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            if ($task_type === 'user_management') {
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                
                $this->log_message("Processing user management task: $task");
                
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
                $batch_index = isset($progress_data['current_batch']) ? $progress_data['current_batch'] : 0;
                
                $this->log_message("Processing batch $batch_index for task $task");
                
                // Process just a single batch in this run
                $batch_result = $this->process_batch($task, $batch_index);
                
                // Check if the task is now complete
                if (isset($batch_result['completed']) && $batch_result['completed']) {
                    $this->log_message("Task $task completed in this run");
                    
                    // Get final progress data for rich operation details
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    // Create detailed operation details with the correct progress percentage
                    $operation_details = array(
                        'task' => $task,
                        'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                        'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                        'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
                        'warned' => isset($progress_data['warned']) ? $progress_data['warned'] : 0,
                        'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
                        'skipped' => isset($progress_data['skipped']) ? $progress_data['skipped'] : 0,
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time()),
                        'progress_percentage' => 100, // Always set explicitly to 100 for completed tasks
                        'summary' => "Processed {$progress_data['processed']} out of {$progress_data['total']} users (100%)."
                    );
                    
                    // Include processed users if available (limited to prevent excessive size)
                    if (isset($progress_data['deleted_users']) && !empty($progress_data['deleted_users'])) {
                        $operation_details['deleted_users'] = array_slice($progress_data['deleted_users'], 0, 100);
                        
                        // Format deleted users for display
                        $formatted_users = array();
                        foreach ($operation_details['deleted_users'] as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
                        }
                        
                        $operation_details['deleted_users_list'] = $operation_details['deleted_users'];
                        $operation_details['deleted_users_count'] = count($operation_details['deleted_users']);
                    }
                    
                    if (isset($progress_data['warned_users']) && !empty($progress_data['warned_users'])) {
                        $operation_details['warned_users'] = array_slice($progress_data['warned_users'], 0, 100);
                        
                        // Format warned users for display
                        $formatted_users = array();
                        foreach ($operation_details['warned_users'] as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
                        }
                        
                        $operation_details['warned_users_formatted'] = $formatted_users;
                        $operation_details['warned_users_list'] = $operation_details['warned_users'];
                        $operation_details['warned_users_count'] = count($operation_details['warned_users']);
                    }
                    
                    // Include skipped users if available
                    if (isset($progress_data['skipped_users']) && !empty($progress_data['skipped_users'])) {
                        $operation_details['skipped_users'] = array_slice($progress_data['skipped_users'], 0, 100);
                        
                        // Format skipped users for display
                        $formatted_users = array();
                        foreach ($operation_details['skipped_users'] as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            $reason = isset($user['reason']) ? $user['reason'] : "No reason specified";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name - Reason: $reason";
                        }
                        
                        $operation_details['skipped_users_formatted'] = $formatted_users;
                        $operation_details['skipped_users_list'] = $operation_details['skipped_users'];
                        $operation_details['skipped_users_count'] = count($operation_details['skipped_users']);
                    }
                    
                    // Close connection before returning result
                    $this->close_connection();
                    
                    $success = true;
                    $message = "User management task completed. Processed {$progress_data['processed']} users: Warned {$progress_data['warned']}, Deleted {$progress_data['deleted']}, with {$progress_data['errors']} errors.";
                    
                    return array(
                        'success' => $success,
                        'message' => $message,
                        'operation_details' => $operation_details
                    );
                } else {
                    // Task is not complete yet, we'll continue in the next scheduler run
                    $this->log_message("Task $task not completed yet, will continue in next run at batch " . 
                                    (isset($batch_result['next_batch']) ? $batch_result['next_batch'] : 'unknown'));
                    
                    // Return partial progress for logging purposes
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    $operation_details = array(
                        'task' => $task,
                        'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                        'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                        'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
                        'warned' => isset($progress_data['warned']) ? $progress_data['warned'] : 0,
                        'skipped' => isset($progress_data['skipped']) ? $progress_data['skipped'] : 0,
                        'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time()),
                        'status' => 'in_progress',
                        'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
                        'batch_index' => $batch_index,
                        'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : 0,
                        'summary' => "Processed {$progress_data['processed']} out of {$progress_data['total']} users ({$progress_data['progress']}%)."
                    );
                    
                    // Include some sample of processed users if available
                    if (isset($progress_data['deleted_users']) && !empty($progress_data['deleted_users'])) {
                        // Get the last 10 deleted users for display
                        $recent_deleted = array_slice($progress_data['deleted_users'], -10);
                        
                        // Format deleted users for display
                        $formatted_users = array();
                        foreach ($recent_deleted as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
                        }
                        
                        $operation_details['deleted_users'] = $recent_deleted;
                        $operation_details['deleted_users_formatted'] = $formatted_users;
                        $operation_details['deleted_users_count'] = count($progress_data['deleted_users']);
                        $operation_details['deleted_users_detail'] = "Deleted {$operation_details['deleted_users_count']} users in this batch";
                    }
                    
                    if (isset($progress_data['warned_users']) && !empty($progress_data['warned_users'])) {
                        // Get the last 10 warned users for display
                        $recent_warned = array_slice($progress_data['warned_users'], -10);
                        
                        // Format warned users for display
                        $formatted_users = array();
                        foreach ($recent_warned as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
                        }
                        
                        $operation_details['warned_users'] = $recent_warned;
                        $operation_details['warned_users_formatted'] = $formatted_users;
                        $operation_details['warned_users_count'] = count($progress_data['warned_users']);
                        $operation_details['warned_users_detail'] = "Warned {$operation_details['warned_users_count']} users in this batch";
                    }
                    
                    // Include skipped users if available
                    if (isset($progress_data['skipped_users']) && !empty($progress_data['skipped_users'])) {
                        // Get the last 10 skipped users for display
                        $recent_skipped = array_slice($progress_data['skipped_users'], -10);
                        
                        // Format skipped users for display
                        $formatted_users = array();
                        foreach ($recent_skipped as $user) {
                            $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                            $id = isset($user['id']) ? $user['id'] : "unknown";
                            $email = isset($user['email']) ? $user['email'] : "no-email";
                            $name = isset($user['name']) ? $user['name'] : "";
                            $reason = isset($user['reason']) ? $user['reason'] : "No reason specified";
                            
                            $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name - Reason: $reason";
                        }
                        
                        $operation_details['skipped_users'] = $recent_skipped;
                        $operation_details['skipped_users_formatted'] = $formatted_users;
                        $operation_details['skipped_users_count'] = count($progress_data['skipped_users']);
                        $operation_details['skipped_users_detail'] = "Skipped {$operation_details['skipped_users_count']} users in this batch";
                    }
                    
                    // Close connection before returning result
                    $this->close_connection();
                    
                    // Create the message for the progress report
                    $message = sprintf(__('Batch %d processed. Processing will continue in the next run.', 'swiftspeed-siberian'), $batch_index);
                    
                    return array(
                        'success' => true,
                        'message' => $message,
                        'operation_details' => $operation_details,
                        'completed' => false
                    );
                }
            }
            
            // Default response for unknown tasks
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => 'Unknown task type: ' . $task_type,
                'operation_details' => array(
                    'error' => "Unknown task type: $task_type",
                    'timestamp' => time()
                )
            );
        } catch (Exception $e) {
            $this->log_message("Exception in user management task: " . $e->getMessage());
            
            // Ensure connection is closed if we had an exception
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => "Error in user management task: " . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
    
    /**
     * AJAX handler for user management
     * Improved with better connection handling
     */
    public function ajax_manage_users() {
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
        
        // Get mode (start, continue, status)
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'start';
        
        try {
            // Ensure database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                wp_send_json_error(array('message' => 'Database connection failed. Please check your connection settings.'));
                return;
            }
            
            // For starting a new task
            if ($mode === 'start') {
                // Initialize the task with batch processing
                $initialized = $this->initialize_task($task);
                
                if (!$initialized) {
                    $this->close_connection();
                    wp_send_json_error(array('message' => 'Failed to initialize task.'));
                    return;
                }
                
                // Process the first batch - this will get things started
                $batch_result = $this->process_batch($task, 0);
                
                // Close connection before sending response
                $this->close_connection();
                
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
                
                return;
            }
            
            // Close connection before sending response
            $this->close_connection();
            wp_send_json_error(array('message' => 'Invalid mode'));
            
        } catch (Exception $e) {
            $this->log_message("Exception in ajax_manage_users: " . $e->getMessage());
            $this->close_connection();
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for processing a batch
     * Improved with better connection handling
     */
    public function ajax_process_user_batch() {
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
        
        try {
            // Ensure database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                wp_send_json_error(array('message' => 'Database connection failed. Please check your connection settings.'));
                return;
            }
            
            // Get batch index
            $batch_index = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
            
            // Process the batch
            $batch_result = $this->process_batch($task, $batch_index);
            
            // Close connection before sending response
            $this->close_connection();
            
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
            
        } catch (Exception $e) {
            $this->log_message("Exception in ajax_process_user_batch: " . $e->getMessage());
            $this->close_connection();
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for getting user progress
     */
    public function ajax_get_user_progress() {
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
        $task_type = isset($_POST['task_type']) ? sanitize_text_field($_POST['task_type']) : '';
        
        if (empty($task_type)) {
            wp_send_json_error(array('message' => 'Task type not specified.'));
            return;
        }
        
        // Get progress file
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
        
        // Log the progress data
        $this->log_message("Received user progress request for task_type: {$task_type}");
        
        if (isset($progress_data['progress']) && isset($progress_data['processed']) && isset($progress_data['total'])) {
            $this->log_message("Returning progress data: progress={$progress_data['progress']}, processed={$progress_data['processed']}, total={$progress_data['total']}");
        }
        
        // If status is completed, ensure progress is 100%
        if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
            $progress_data['progress'] = 100;
            $progress_data['progress_percentage'] = 100;
            $progress_data['summary'] = sprintf(__('Processed %d out of %d users (100%%).', 'swiftspeed-siberian'), 
                                              $progress_data['processed'], $progress_data['total']);
        }
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * Initialize task for batch processing
     * Improved with better connection handling
     */
    private function initialize_task($task) {
        $this->log_message("Initializing user management task: $task");
        
        // Ensure DB connection
        $connection = $this->get_db_connection();
        if (!$connection) {
            $this->log_message("Database connection not available for task: $task");
            return false;
        }
        
        // Update data handler connection
        $this->data->db_connection = $connection;
        
        $total = 0;
        $logs = array();
        $users = array();
        
        // Get settings for task
        $options = get_option('swsib_options', array());
        
        // Get data based on task type
        if ($task === 'inactive') {
            // Get settings
            $settings = isset($options['automate']['user_management']['inactive']) ? 
                     $options['automate']['user_management']['inactive'] : array();
            
            // Get inactive period
            $inactive_period = isset($settings['inactivity_period']) ? intval($settings['inactivity_period']) : 90;
            $inactive_unit = isset($settings['inactivity_unit']) ? $settings['inactivity_unit'] : 'days';
            
            // Convert to seconds
            $inactive_seconds = $this->get_period_in_seconds($inactive_period, $inactive_unit);
            
            // Get inactive users
            $users = $this->data->get_inactive_users($inactive_seconds);
            $total = count($users);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d inactive users to process', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
            
            $this->log_message("Found $total inactive users to process");
            
        } elseif ($task === 'no_apps') {
            // Get settings
            $settings = isset($options['automate']['user_management']['no_apps']) ? 
                     $options['automate']['user_management']['no_apps'] : array();
            
            // Get minimum age period
            $minimum_age = isset($settings['grace_period']) ? intval($settings['grace_period']) : 30;
            $age_unit = isset($settings['grace_unit']) ? $settings['grace_unit'] : 'days';
            
            // Convert to seconds
            $age_seconds = $this->get_period_in_seconds($minimum_age, $age_unit);
            
            // Check if inactivity filter is enabled
            $check_inactivity = !empty($settings['check_inactivity']);
            $inactivity_seconds = 0;
            
            if ($check_inactivity) {
                $inactivity_period = isset($settings['inactivity_period']) ? intval($settings['inactivity_period']) : 90;
                $inactivity_unit = isset($settings['inactivity_unit']) ? $settings['inactivity_unit'] : 'days';
                $inactivity_seconds = $this->get_period_in_seconds($inactivity_period, $inactivity_unit);
            }
            
            // Get users without apps
            $users = $this->data->get_users_without_apps($age_seconds, $check_inactivity, $inactivity_seconds);
            $total = count($users);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d users without apps to process', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
            
            $this->log_message("Found $total users without apps to process");
        }
        
        // Ensure we have at least 1 for total to prevent division by zero
        $total = max(1, $total);
        
        // Split into batches
        $batches = array_chunk($users, $this->chunk_size);
        
        // Calculate batch count
        $batch_count = count($batches);
        
        // Initialize progress data
        $progress_data = array(
            'status' => 'running',
            'progress' => 0,
            'progress_percentage' => 0,
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
            'warned' => 0,
            'skipped' => 0,
            'errors' => 0,
            'deleted_users' => array(),
            'warned_users' => array(),
            'skipped_users' => array(),
            'settings' => $settings
        );
        
        // Save to progress file
        $progress_file = $this->get_progress_file($task);
        file_put_contents($progress_file, json_encode($progress_data));
        
        $this->log_message("Task $task initialized with $total users, $batch_count batches");
        
        return true;
    }
    
    /**
     * Process batch for user management task
     * Improved with better connection handling
     */
    public function process_batch($task, $batch_index) {
        $this->log_message("Processing batch $batch_index for task $task");
        
        // Ensure we have a valid connection
        $connection = $this->get_db_connection();
        if (!$connection) {
            $this->log_message("Database connection not available for processing batch");
            return array(
                'success' => false,
                'message' => 'Database connection failed',
                'completed' => false
            );
        }
        
        // Update data handler connection
        $this->data->db_connection = $connection;
        
        // Get progress data
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            $this->log_message("Progress file not found for task: $task");
            return array(
                'success' => false,
                'message' => 'Progress file not found',
                'completed' => false
            );
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data) {
            $this->log_message("Invalid progress data for task: $task");
            return array(
                'success' => false,
                'message' => 'Invalid progress data',
                'completed' => false
            );
        }
        
        // Check if processing is already completed
        if ($progress_data['status'] === 'completed') {
            $this->log_message("Task $task already completed");
            return array(
                'success' => true,
                'message' => 'Processing already completed',
                'progress' => 100,
                'next_batch' => 0,
                'completed' => true
            );
        }
        
        // Check if the batch index is valid
        if (!isset($progress_data['batches'][$batch_index])) {
            if ($batch_index >= count($progress_data['batches'])) {
                // We've processed all batches, mark as completed with correct progress percentage
                $progress_data['status'] = 'completed';
                $progress_data['progress'] = 100;
                $progress_data['progress_percentage'] = 100; // Add explicit progress_percentage field
                $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
                
                // Add completion log
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Task completed. Processed %d users, warned %d, deleted %d with %d errors.', 'swiftspeed-siberian'), 
                                      $progress_data['processed'], $progress_data['warned'], $progress_data['deleted'], $progress_data['errors']),
                    'type' => 'success'
                );
                
                // Create summary with 100% progress
                $progress_data['summary'] = sprintf(__('Processed %d out of %d users (100%%).', 'swiftspeed-siberian'), 
                                                 $progress_data['processed'], $progress_data['total']);
                
                // Save progress data
                file_put_contents($progress_file, json_encode($progress_data));
                
                $this->log_message("All batches processed for task $task. Task completed.");
                
                return array(
                    'success' => true,
                    'message' => 'All batches processed',
                    'progress' => 100,
                    'next_batch' => $batch_index, // Keep the same batch for clarity
                    'completed' => true
                );
            } else {
                $this->log_message("Invalid batch index $batch_index for task $task");
                return array(
                    'success' => false,
                    'message' => 'Invalid batch index',
                    'completed' => false
                );
            }
        }
        
        // Get the batch to process
        $batch_users = $progress_data['batches'][$batch_index];
        
        // Process the batch
        $result = array(
            'warned' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
            'logs' => array(),
            'deleted_users' => array(),
            'warned_users' => array(),
            'skipped_users' => array()
        );
        
        // Get the action to take (warn or delete)
        $action = isset($progress_data['settings']['action']) ? $progress_data['settings']['action'] : 'warn';
        
        // Process based on task type
        if ($task === 'inactive') {
            $result = $this->process_inactive_users_batch($batch_users, $progress_data['settings']);
        } elseif ($task === 'no_apps') {
            $result = $this->process_users_without_apps_batch($batch_users, $progress_data['settings']);
        }
        
        // Update progress
        $progress_data['warned'] += $result['warned'];
        $progress_data['deleted'] += $result['deleted'];
        $progress_data['skipped'] += $result['skipped'];
        $progress_data['errors'] += $result['errors'];
        $progress_data['processed'] += count($batch_users);
        $progress_data['current_batch'] = $batch_index + 1;
        $progress_data['last_update'] = time();
        
        // Add processed users to the list
        if (!empty($result['deleted_users'])) {
            if (!isset($progress_data['deleted_users'])) {
                $progress_data['deleted_users'] = array();
            }
            $progress_data['deleted_users'] = array_merge($progress_data['deleted_users'], $result['deleted_users']);
            
            // Limit deleted users to prevent file size issues
            if (count($progress_data['deleted_users']) > 100) {
                $progress_data['deleted_users'] = array_slice($progress_data['deleted_users'], -100);
            }
        }
        
        if (!empty($result['warned_users'])) {
            if (!isset($progress_data['warned_users'])) {
                $progress_data['warned_users'] = array();
            }
            $progress_data['warned_users'] = array_merge($progress_data['warned_users'], $result['warned_users']);
            
            // Limit warned users to prevent file size issues
            if (count($progress_data['warned_users']) > 100) {
                $progress_data['warned_users'] = array_slice($progress_data['warned_users'], -100);
            }
        }
        
        // Add skipped users to the list
        if (!empty($result['skipped_users'])) {
            if (!isset($progress_data['skipped_users'])) {
                $progress_data['skipped_users'] = array();
            }
            $progress_data['skipped_users'] = array_merge($progress_data['skipped_users'], $result['skipped_users']);
            
            // Limit skipped users to prevent file size issues
            if (count($progress_data['skipped_users']) > 100) {
                $progress_data['skipped_users'] = array_slice($progress_data['skipped_users'], -100);
            }
        }
        
        // Calculate progress percentage
        if ($progress_data['total'] > 0) {
            $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
            $progress_data['progress_percentage'] = $progress_data['progress']; // Add explicit progress_percentage field
        } else {
            $progress_data['progress'] = 100;
            $progress_data['progress_percentage'] = 100;
        }
        
        // Add batch logs to progress
        if (!empty($result['logs'])) {
            foreach ($result['logs'] as $log) {
                $progress_data['logs'][] = $log;
            }
        }
        
        // Limit logs to prevent file size issues
        if (count($progress_data['logs']) > 100) {
            $progress_data['logs'] = array_slice($progress_data['logs'], -100);
        }
        
        // Check if this is the last batch
        $completed = ($batch_index + 1 >= count($progress_data['batches']));
        
        if ($completed) {
            $progress_data['status'] = 'completed';
            $progress_data['progress'] = 100;
            $progress_data['progress_percentage'] = 100; // Add explicit progress_percentage field
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            
            // Add completion log
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => sprintf(__('Task completed. Processed %d users, warned %d, deleted %d with %d errors, skipped %d.', 'swiftspeed-siberian'), 
                                   $progress_data['processed'], $progress_data['warned'], $progress_data['deleted'], 
                                   $progress_data['errors'], $progress_data['skipped']),
                'type' => 'success'
            );
            
            // Create summary with 100% progress
            $progress_data['summary'] = sprintf(__('Processed %d out of %d users (100%%).', 'swiftspeed-siberian'), 
                                              $progress_data['processed'], $progress_data['total']);
        } else {
            // Create progress summary for in-progress tasks
            $progress_data['summary'] = sprintf(__('Processed %d out of %d users (%d%%).', 'swiftspeed-siberian'), 
                                              $progress_data['processed'], $progress_data['total'], 
                                              $progress_data['progress']);
        }
        
        // Save updated progress data
        file_put_contents($progress_file, json_encode($progress_data));
        
        // Build a more informative message for the response
        $message = sprintf(__('Batch %d processed. Will continue next run.', 'swiftspeed-siberian'), $batch_index);
        
        if ($completed) {
            $message = sprintf(__('All batches processed. Task completed.', 'swiftspeed-siberian'));
        }
        
        $this->log_message("Completed processing batch $batch_index for task $task. Progress: {$progress_data['progress']}%");
        
        return array(
            'success' => true,
            'message' => $message,
            'progress' => $progress_data['progress'],
            'next_batch' => $batch_index + 1,
            'completed' => $completed
        );
    }
    
    /**
     * Process inactive users batch
     */
    private function process_inactive_users_batch($users, $settings) {
        $warned = 0;
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = array();
        $deleted_users = array();
        $warned_users = array();
        $skipped_users = array();
        
        if (empty($users)) {
            $logs[] = array(
                'time' => time(),
                'message' => 'No users to process in this batch',
                'type' => 'info'
            );
            
            return array(
                'warned' => $warned,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'errors' => $errors,
                'logs' => $logs,
                'deleted_users' => $deleted_users,
                'warned_users' => $warned_users,
                'skipped_users' => $skipped_users
            );
        }
        
        $this->log_message("Processing batch of " . count($users) . " inactive users");
        
        // Get action type from settings or default to warn
        $action = isset($settings['action']) ? $settings['action'] : 'warn';
        $send_warning = isset($settings['send_warning']) ? $settings['send_warning'] : true;
        
        // Get warned users transient (to avoid warning again)
        $warned_transient = get_transient('swsib_warned_inactive_users');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        // Get warning threshold (users warned this many seconds ago can be warned again)
        $warning_threshold = time() - (86400 * 7); // 7 days
        
        foreach ($users as $user) {
            $admin_id = $user['admin_id'];
            $email = $user['email'];
            $name = trim($user['firstname'] . ' ' . $user['lastname']);
            
            // Create a processed user record
            $user_record = array(
                'id' => $admin_id,
                'email' => $email,
                'name' => $name,
                'last_login' => $user['last_action'],
                'action' => 'none',
                'timestamp' => date('Y-m-d H:i:s') // Add timestamp for reporting
            );
            
            $this->log_message("Processing inactive user: $email (ID: $admin_id)");
            
            // If action is warn and user was already warned recently, skip
            if ($action === 'warn' && isset($warned_data[$admin_id]) && $warned_data[$admin_id] > $warning_threshold) {
                $user_record['action'] = 'skipped';
                $user_record['reason'] = 'Already warned recently';
                $skipped++;
                $skipped_users[] = $user_record;
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Skipped user $email (already warned recently)",
                    'type' => 'info'
                );
                
                continue;
            }
            
            if ($action === 'warn' && $send_warning) {
                // Get user's apps
                $apps_query = "SELECT app.app_id, app.name 
                              FROM application app 
                              WHERE app.admin_id = ?";
                
                $stmt = $this->db_connection->prepare($apps_query);
                
                if (!$stmt) {
                    $errors++;
                    $user_record['action'] = 'error';
                    $user_record['reason'] = 'Failed to prepare statement: ' . $this->db_connection->error;
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Error getting apps for user $admin_id: " . $this->db_connection->error,
                        'type' => 'error'
                    );
                    continue;
                }
                
                $stmt->bind_param('i', $admin_id);
                $stmt->execute();
                $apps_result = $stmt->get_result();
                $stmt->close();
                
                $apps = array();
                while ($app = $apps_result->fetch_assoc()) {
                    $apps[] = $app;
                }
                $apps_result->free_result();
                
                if ($this->email_manager) {
                    // Send warning email
                    $subject = $settings['warning_subject'];
                    $message = $settings['warning_message'];
                    
                    // Replace placeholders
                    $subject = str_replace('{name}', $name, $subject);
                    $subject = str_replace('{email}', $email, $subject);
                    $subject = str_replace('{days}', $settings['warning_period'], $subject);
                    
                    $message = str_replace('{name}', $name, $message);
                    $message = str_replace('{email}', $email, $message);
                    $message = str_replace('{days}', $settings['warning_period'], $message);
                    $message = str_replace('{last_login}', $user['last_action'], $message);
                    
                    // Add apps list
                    $apps_list = '';
                    foreach ($apps as $app) {
                        $apps_list .= "- {$app['name']} (ID: {$app['app_id']})\n";
                    }
                    
                    $message = str_replace('{apps_list}', $apps_list, $message);
                    
                    // Send email
                    $sent = $this->email_manager->send_email($email, $subject, $message);
                    
                    if ($sent) {
                        // Record warning
                        $warned_data[$admin_id] = time();
                        $warned++;
                        
                        $user_record['action'] = 'warned';
                        $user_record['apps'] = $apps;
                        $warned_users[] = $user_record;
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Sent warning email to inactive user: $email (ID: $admin_id)",
                            'type' => 'success'
                        );
                        
                        $this->log_message("Sent warning email to inactive user: $email (ID: $admin_id)");
                    } else {
                        $errors++;
                        $user_record['action'] = 'error';
                        $user_record['reason'] = 'Failed to send warning email';
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Failed to send warning email to inactive user: $email (ID: $admin_id)",
                            'type' => 'error'
                        );
                        
                        $this->log_message("Failed to send warning email to inactive user: $email (ID: $admin_id)");
                    }
                } else {
                    // No email manager available
                    $errors++;
                    $user_record['action'] = 'error';
                    $user_record['reason'] = 'Email manager not available';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Cannot send warning to $email - Email manager not available",
                        'type' => 'error'
                    );
                }
            } else if ($action === 'delete' || !$send_warning) {
                // Delete the user
                $result = $this->delete_siberian_user($admin_id, $email);
                
                if ($result) {
                    $deleted++;
                    $user_record['action'] = 'deleted';
                    $deleted_users[] = $user_record;
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Successfully deleted inactive user: $email (ID: $admin_id)",
                        'type' => 'success'
                    );
                    
                    $this->log_message("Deleted inactive user: $email (ID: $admin_id)");
                    
                    // Remove from warned users if present
                    if (isset($warned_data[$admin_id])) {
                        unset($warned_data[$admin_id]);
                    }
                } else {
                    $errors++;
                    $user_record['action'] = 'error';
                    $user_record['reason'] = 'Failed to delete user';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Failed to delete inactive user: $email (ID: $admin_id)",
                        'type' => 'error'
                    );
                    
                    $this->log_message("Failed to delete inactive user: $email (ID: $admin_id)");
                }
            }
        }
        
        // Update warned users transient if we sent warnings
        if ($warned > 0) {
            set_transient('swsib_warned_inactive_users', $warned_data, 30 * DAY_IN_SECONDS);
        }
        
        return array(
            'warned' => $warned,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
            'logs' => $logs,
            'deleted_users' => $deleted_users,
            'warned_users' => $warned_users,
            'skipped_users' => $skipped_users
        );
    }
    
    /**
     * Process users without apps batch
     */
    private function process_users_without_apps_batch($users, $settings) {
        $warned = 0;
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = array();
        $deleted_users = array();
        $warned_users = array();
        $skipped_users = array();
        
        if (empty($users)) {
            $logs[] = array(
                'time' => time(),
                'message' => 'No users to process in this batch',
                'type' => 'info'
            );
            
            return array(
                'warned' => $warned,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'errors' => $errors,
                'logs' => $logs,
                'deleted_users' => $deleted_users,
                'warned_users' => $warned_users,
                'skipped_users' => $skipped_users
            );
        }
        
        $this->log_message("Processing batch of " . count($users) . " users without apps");
        
        // Get action type from settings or default to warn
        $action = isset($settings['action']) ? $settings['action'] : 'warn';
        $send_warning = isset($settings['send_warning']) ? $settings['send_warning'] : true;
        
        // Get warned users transient (to avoid warning again)
        $warned_transient = get_transient('swsib_warned_users_without_apps');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        // Get warning threshold (users warned this many seconds ago can be warned again)
        $warning_threshold = time() - (86400 * 7); // 7 days
        
        foreach ($users as $user) {
            $admin_id = $user['admin_id'];
            $email = $user['email'];
            $name = trim($user['firstname'] . ' ' . $user['lastname']);
            
            // Create a processed user record
            $user_record = array(
                'id' => $admin_id,
                'email' => $email,
                'name' => $name,
                'created_at' => $user['created_at'],
                'last_login' => $user['last_action'],
                'action' => 'none',
                'timestamp' => date('Y-m-d H:i:s') // Add timestamp for reporting
            );
            
            $this->log_message("Processing user without apps: $email (ID: $admin_id)");
            
            // Verify this user still has no apps (double-check)
            $verify_query = "SELECT COUNT(*) as app_count FROM application WHERE admin_id = ?";
            
            $stmt = $this->db_connection->prepare($verify_query);
            
            if (!$stmt) {
                $errors++;
                $user_record['action'] = 'error';
                $user_record['reason'] = 'Failed to prepare statement: ' . $this->db_connection->error;
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Error verifying apps for user $admin_id: " . $this->db_connection->error,
                    'type' => 'error'
                );
                continue;
            }
            
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $stmt->bind_result($app_count);
            $stmt->fetch();
            $stmt->close();
            
            if ($app_count > 0) {
                // User now has apps, skip
                $skipped++;
                $user_record['action'] = 'skipped';
                $user_record['reason'] = 'User now has apps';
                $skipped_users[] = $user_record;
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Skipping user $email (now has $app_count apps)",
                    'type' => 'info'
                );
                
                continue;
            }
            
            // If action is warn and user was already warned recently, skip
            if ($action === 'warn' && isset($warned_data[$admin_id]) && $warned_data[$admin_id] > $warning_threshold) {
                $skipped++;
                $user_record['action'] = 'skipped';
                $user_record['reason'] = 'Already warned recently';
                $skipped_users[] = $user_record;
                
                $logs[] = array(
                    'time' => time(),
                    'message' => "Skipped user $email (already warned recently)",
                    'type' => 'info'
                );
                
                continue;
            }
            
            if ($action === 'warn' && $send_warning) {
                if ($this->email_manager) {
                    // Send warning email
                    $subject = $settings['warning_subject'];
                    $message = $settings['warning_message'];
                    
                    // Replace placeholders
                    $subject = str_replace('{name}', $name, $subject);
                    $subject = str_replace('{email}', $email, $subject);
                    $subject = str_replace('{days}', $settings['warning_period'], $subject);
                    
                    $message = str_replace('{name}', $name, $message);
                    $message = str_replace('{email}', $email, $message);
                    $message = str_replace('{days}', $settings['warning_period'], $message);
                    $message = str_replace('{registration_date}', $user['created_at'], $message);
                    $message = str_replace('{last_login}', $user['last_action'], $message);
                    
                    // Send email
                    $sent = $this->email_manager->send_email($email, $subject, $message);
                    
                    if ($sent) {
                        // Record warning
                        $warned_data[$admin_id] = time();
                        $warned++;
                        
                        $user_record['action'] = 'warned';
                        $warned_users[] = $user_record;
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Sent warning email to user without apps: $email (ID: $admin_id)",
                            'type' => 'success'
                        );
                        
                        $this->log_message("Sent warning email to user without apps: $email (ID: $admin_id)");
                    } else {
                        $errors++;
                        $user_record['action'] = 'error';
                        $user_record['reason'] = 'Failed to send warning email';
                        
                        $logs[] = array(
                            'time' => time(),
                            'message' => "Failed to send warning email to user without apps: $email (ID: $admin_id)",
                            'type' => 'error'
                        );
                        
                        $this->log_message("Failed to send warning email to user without apps: $email (ID: $admin_id)");
                    }
                } else {
                    // No email manager available
                    $errors++;
                    $user_record['action'] = 'error';
                    $user_record['reason'] = 'Email manager not available';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Cannot send warning to $email - Email manager not available",
                        'type' => 'error'
                    );
                }
            } else if ($action === 'delete' || !$send_warning) {
                // Delete the user
                $result = $this->delete_siberian_user($admin_id, $email);
                
                if ($result) {
                    $deleted++;
                    $user_record['action'] = 'deleted';
                    $deleted_users[] = $user_record;
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Successfully deleted user without apps: $email (ID: $admin_id)",
                        'type' => 'success'
                    );
                    
                    $this->log_message("Deleted user without apps: $email (ID: $admin_id)");
                    
                    // Remove from warned users if present
                    if (isset($warned_data[$admin_id])) {
                        unset($warned_data[$admin_id]);
                    }
                } else {
                    $errors++;
                    $user_record['action'] = 'error';
                    $user_record['reason'] = 'Failed to delete user';
                    
                    $logs[] = array(
                        'time' => time(),
                        'message' => "Failed to delete user without apps: $email (ID: $admin_id)",
                        'type' => 'error'
                    );
                    
                    $this->log_message("Failed to delete user without apps: $email (ID: $admin_id)");
                }
            }
        }
        
        // Update warned users transient if we sent warnings
        if ($warned > 0) {
            set_transient('swsib_warned_users_without_apps', $warned_data, 30 * DAY_IN_SECONDS);
        }
        
        return array(
            'warned' => $warned,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'errors' => $errors,
            'logs' => $logs,
            'deleted_users' => $deleted_users,
            'warned_users' => $warned_users,
            'skipped_users' => $skipped_users
        );
    }
    
    /**
     * Delete a Siberian user and related data
     */
    private function delete_siberian_user($admin_id, $email) {
        if (!$this->db_connection) {
            $this->log_message("Cannot delete user - no database connection");
            return false;
        }
        
        // Start transaction
        $this->db_connection->begin_transaction();
        
        try {
            // Get all apps owned by this admin
            $app_query = "SELECT app_id FROM application WHERE admin_id = ?";
            $stmt = $this->db_connection->prepare($app_query);
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statement for getting apps: " . $this->db_connection->error);
            }
            
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $app_result = $stmt->get_result();
            $stmt->close();
            
            $app_ids = array();
            while ($app_row = $app_result->fetch_assoc()) {
                $app_ids[] = $app_row['app_id'];
            }
            $app_result->free_result(); // Free the result
            
            $this->log_message("Found " . count($app_ids) . " apps for admin $admin_id");
            
            // Delete from application_admin
            $app_admin_query = "DELETE FROM application_admin WHERE admin_id = ?";
            $stmt = $this->db_connection->prepare($app_admin_query);
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statement for deleting application_admin: " . $this->db_connection->error);
            }
            
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $stmt->close();
            
            // For each app, delete related data
            foreach ($app_ids as $app_id) {
                // Find tables with app_id column
                $tables_query = "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                               WHERE COLUMN_NAME = 'app_id' 
                               AND TABLE_SCHEMA = ?";
                
                $stmt = $this->db_connection->prepare($tables_query);
                
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement for finding tables: " . $this->db_connection->error);
                }
                
                $stmt->bind_param('s', $this->db_name);
                $stmt->execute();
                $tables_result = $stmt->get_result();
                $stmt->close();
                
                // Delete from each table that has an app_id column
                while ($table_row = $tables_result->fetch_assoc()) {
                    $table_name = $table_row['TABLE_NAME'];
                    
                    // Skip application table as we'll delete from it last
                    if ($table_name === 'application') {
                        continue;
                    }
                    
                    $delete_query = "DELETE FROM {$table_name} WHERE app_id = ?";
                    
                    // Add safeguard with try/catch for each table deletion
                    try {
                        $stmt = $this->db_connection->prepare($delete_query);
                        
                        if (!$stmt) {
                            $this->log_message("Failed to prepare statement for deleting from {$table_name}: " . $this->db_connection->error);
                            continue;
                        }
                        
                        $stmt->bind_param('i', $app_id);
                        $stmt->execute();
                        $stmt->close();
                    } catch (Exception $e) {
                        $this->log_message("Error deleting from {$table_name} for app {$app_id}: " . $e->getMessage());
                        // Continue with next table, don't throw
                    }
                }
                $tables_result->free_result(); // Free the result
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = ?";
                $stmt = $this->db_connection->prepare($app_query);
                
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement for deleting application: " . $this->db_connection->error);
                }
                
                $stmt->bind_param('i', $app_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Finally, delete the admin
            $admin_query = "DELETE FROM admin WHERE admin_id = ?";
            $stmt = $this->db_connection->prepare($admin_query);
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statement for deleting admin: " . $this->db_connection->error);
            }
            
            $stmt->bind_param('i', $admin_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete corresponding WordPress user
            $wp_user = get_user_by('email', $email);
            if ($wp_user) {
                wp_delete_user($wp_user->ID);
                $this->log_message("Deleted corresponding WordPress user: $email");
            }
            
            // Commit the transaction
            $this->db_connection->commit();
            
            return true;
            
        } catch (Exception $e) {
            // Rollback the transaction
            $this->db_connection->rollback();
            $this->log_message("Error deleting user $admin_id: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Convert period settings to seconds
     */
    private function get_period_in_seconds($value, $unit) {
        switch ($unit) {
            case 'minutes':
                return $value * 60;
            case 'hours':
                return $value * 3600;
            case 'days':
                return $value * 86400;
            case 'weeks':
                return $value * 604800;
            case 'months':
                return $value * 2592000; // 30 days
            case 'years':
                return $value * 31536000; // 365 days
            default:
                return $value * 86400; // Default to days
        }
    }
    
    /**
     * Display user management settings
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for user management automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }
}