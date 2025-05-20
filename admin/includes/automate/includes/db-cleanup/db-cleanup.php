<?php
/**
 * Database Cleanup Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_DB_Cleanup {
    
    /**
     * Database connection
     */
    private $db_connection = null;
    
    /**
     * Database name
     */
    private $db_name = null;
    
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
    private $chunk_size = 2;
    
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
        
        // Include required files
        $this->include_files();
        
        // Initialize components with null connections - they'll get valid connections when needed
        $this->data = new SwiftSpeed_Siberian_DB_Data(null, null);
        $this->settings = new SwiftSpeed_Siberian_DB_Settings(null, null);
        $this->settings->set_data_handler($this->data);
        
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
        }
        
        if ($this->settings !== null && property_exists($this->settings, 'db_connection')) {
            $this->settings->db_connection = $this->db_connection;
        }
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        $dir = plugin_dir_path(__FILE__);
        
        require_once($dir . 'db-cleanup-data.php');
        require_once($dir . 'db-cleanup-settings.php');
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Main task handlers
        add_action('wp_ajax_swsib_cleanup_database', array($this, 'ajax_cleanup_database'));
        add_action('wp_ajax_swsib_get_db_cleanup_progress', array($this, 'ajax_get_db_cleanup_progress'));
        add_action('wp_ajax_swsib_process_db_batch', array($this, 'ajax_process_db_batch'));
        
        // Preview data handler
        add_action('wp_ajax_swsib_preview_db_data', array($this->data, 'ajax_preview_db_data'));
        
        // Settings handler
        add_action('wp_ajax_swsib_save_db_cleanup_automation', array($this->settings, 'ajax_save_db_cleanup_automation'));
        
        // Data count handlers
        add_action('wp_ajax_swsib_get_sessions_count', array($this->data, 'ajax_get_sessions_count'));
        add_action('wp_ajax_swsib_get_mail_logs_count', array($this->data, 'ajax_get_mail_logs_count'));
        add_action('wp_ajax_swsib_get_source_queue_count', array($this->data, 'ajax_get_source_queue_count'));
        add_action('wp_ajax_swsib_get_backoffice_alerts_count', array($this->data, 'ajax_get_backoffice_alerts_count'));
        add_action('wp_ajax_swsib_get_cleanup_log_count', array($this->data, 'ajax_get_cleanup_log_count'));
        add_action('wp_ajax_swsib_get_optimize_tables_info', array($this->data, 'ajax_get_optimize_tables_info'));
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
        
        return $swsib_dir . '/swsib_db_cleanup_' . sanitize_file_name($task) . '_progress.json';
    }
    
    /**
     * Handle scheduled task - Using batch processing
     * Improved with proper database connection handling
     */
    public function handle_scheduled_task($task_type, $task_args) {
        $this->log_message("Handling scheduled db cleanup task: $task_type with args: " . print_r($task_args, true));
        
        try {
            // Ensure we have a database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                $this->log_message("Failed to establish database connection for scheduled task");
                return array(
                    'success' => false,
                    'message' => "Database connection failed",
                    'operation_details' => array(
                        'error' => "Database connection failed",
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            if ($task_type === 'db_cleanup') {
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                
                $this->log_message("Processing DB cleanup task: $task");
                
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
                    $this->data->initialize_task($task);
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                } else {
                    $this->log_message("Continuing existing task: $task at batch {$progress_data['current_batch']}");
                }
                
                // Get current batch index
                $batch_index = isset($progress_data['current_batch']) ? $progress_data['current_batch'] : 0;
                
                $this->log_message("Processing batch $batch_index for task $task");
                
                // Process just a single batch in this run
                $batch_result = $this->data->process_batch($task, $batch_index);
                
                // Check if the task is now complete
                if (isset($batch_result['completed']) && $batch_result['completed']) {
                    $this->log_message("Task $task completed in this run");
                    
                    // Get final progress data for rich operation details
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    $operation_details = array(
                        'task' => $task,
                        'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                        'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    );
                    
                    if ($task === 'sessions' || $task === 'mail_logs' || $task === 'source_queue' || $task === 'backoffice_alerts' || $task === 'cleanup_log') {
                        $operation_details['deleted'] = $progress_data['deleted'];
                        $operation_details['errors'] = $progress_data['errors'];
                        $operation_details['skipped'] = isset($progress_data['skipped']) ? $progress_data['skipped'] : 0;
                        $operation_details['summary'] = "Processed {$progress_data['processed']} out of {$progress_data['total']} items (100%).";
                        
                        // Include detailed information about deleted items if available
                        if (isset($progress_data['deleted_items']) && !empty($progress_data['deleted_items'])) {
                            $operation_details['deleted_items'] = $progress_data['deleted_items'];
                            $operation_details['deleted_items_list'] = $progress_data['deleted_items'];
                            
                            // Format deleted items for display
                            $formatted_items = array();
                            foreach ($progress_data['deleted_items'] as $item) {
                                $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                                $id = isset($item['id']) ? $item['id'] : "unknown";
                                $type_info = '';
                                
                                if (isset($item['title'])) {
                                    $type_info = ' - ' . $item['title'];
                                } elseif (isset($item['name'])) {
                                    $type_info = ' - ' . $item['name'];
                                } elseif (isset($item['modified'])) {
                                    $type_info = ' - Modified: ' . $item['modified'];
                                }
                                
                                $formatted_items[] = "$timestamp - <strong>$id</strong>$type_info";
                            }
                            
                            $operation_details['deleted_items_formatted'] = $formatted_items;
                        }
                    } elseif ($task === 'optimize') {
                        $operation_details['optimized'] = $progress_data['deleted']; // For optimize, 'deleted' counts as 'optimized'
                        $operation_details['errors'] = $progress_data['errors'];
                        $operation_details['skipped'] = isset($progress_data['skipped']) ? $progress_data['skipped'] : 0;
                        $operation_details['summary'] = "Processed {$progress_data['processed']} out of {$progress_data['total']} tables (100%).";
                        
                        // Include detailed information about optimized tables if available
                        if (isset($progress_data['optimized_tables']) && !empty($progress_data['optimized_tables'])) {
                            $operation_details['optimized_tables'] = $progress_data['optimized_tables'];
                            $operation_details['optimized_tables_list'] = $progress_data['optimized_tables'];
                            
                            // Format optimized tables for display
                            $formatted_tables = array();
                            foreach ($progress_data['optimized_tables'] as $table) {
                                $timestamp = isset($table['timestamp']) ? $table['timestamp'] : date('Y-m-d H:i:s');
                                $table_name = isset($table['table_name']) ? $table['table_name'] : "unknown";
                                $size_info = isset($table['size_mb']) ? " - Size: {$table['size_mb']} MB" : "";
                                
                                $formatted_tables[] = "$timestamp - <strong>$table_name</strong>$size_info";
                            }
                            
                            $operation_details['optimized_tables_formatted'] = $formatted_tables;
                        }
                    }
                    
                    // Close connection before returning result
                    $this->close_connection();
                    
                    $success = true; // Consider it a success even with some errors
                    $message = "All batches processed. Task completed.";
                    
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
                    $this->log_message("Task $task not completed yet, will continue in next run at batch " . 
                                      (isset($batch_result['next_batch']) ? $batch_result['next_batch'] : 'unknown'));
                    
                    // Return partial progress for logging purposes
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    $operation_details = array(
                        'task' => $task,
                        'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                        'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time()),
                        'status' => 'in_progress',
                        'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
                        'batch_index' => $batch_index,
                        'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : 0,
                        'summary' => "Processed {$progress_data['processed']} out of {$progress_data['total']} items ({$progress_data['progress']}%)."
                    );
                    
                    if ($task === 'sessions' || $task === 'mail_logs' || $task === 'source_queue' || $task === 'backoffice_alerts' || $task === 'cleanup_log') {
                        $operation_details['deleted'] = isset($progress_data['deleted']) ? $progress_data['deleted'] : 0;
                        $operation_details['errors'] = isset($progress_data['errors']) ? $progress_data['errors'] : 0;
                        $operation_details['skipped'] = isset($progress_data['skipped']) ? $progress_data['skipped'] : 0;
                        
                        // Include details about deleted items if available
                        if (isset($progress_data['deleted_items']) && !empty($progress_data['deleted_items'])) {
                            // Format deleted items for display (similar to image cleanup)
                            $formatted_items = array();
                            $items_to_format = array_slice($progress_data['deleted_items'], -10); // Last 10 for display
                            
                            foreach ($items_to_format as $item) {
                                $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                                $id = isset($item['id']) ? $item['id'] : "unknown";
                                $type_info = '';
                                
                                if (isset($item['title'])) {
                                    $type_info = ' - ' . $item['title'];
                                } elseif (isset($item['name'])) {
                                    $type_info = ' - ' . $item['name'];
                                } elseif (isset($item['modified'])) {
                                    $type_info = ' - Modified: ' . $item['modified'];
                                }
                                
                                $formatted_items[] = "$timestamp - <strong>$id</strong>$type_info";
                            }
                            
                            $operation_details['deleted_items_count'] = count($progress_data['deleted_items']);
                            $operation_details['deleted_items_detail'] = "Deleted {$operation_details['deleted_items_count']} items in this batch";
                            $operation_details['deleted_items'] = implode("<br>", $formatted_items);
                        }
                    } elseif ($task === 'optimize') {
                        $operation_details['optimized'] = isset($progress_data['deleted']) ? $progress_data['deleted'] : 0; // For optimize, 'deleted' counts as 'optimized'
                        $operation_details['errors'] = isset($progress_data['errors']) ? $progress_data['errors'] : 0;
                        $operation_details['skipped'] = isset($progress_data['skipped']) ? $progress_data['skipped'] : 0;
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
            $this->log_message("Exception in db cleanup task: " . $e->getMessage());
            
            // Ensure connection is closed if we had an exception
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => "Error in db cleanup task: " . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
    
    /**
     * AJAX handler for cleaning up database
     * Improved with better connection handling
     */
    public function ajax_cleanup_database() {
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
            
            // Initialize the task for batch processing
            $this->data->initialize_task($task);
            
            // Process the first batch directly - this will get things started
            $batch_result = $this->data->process_batch($task, 0);
            
            // Close connection before sending response
            $this->close_connection();
            
            // Return the result
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => $batch_result['message'],
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
            $this->log_message("Exception in ajax_cleanup_database: " . $e->getMessage());
            $this->close_connection();
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for processing a batch
     * Improved with better connection handling
     */
    public function ajax_process_db_batch() {
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
            $batch_result = $this->data->process_batch($task, $batch_index);
            
            // Close connection before sending response
            $this->close_connection();
            
            // Return the result
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => $batch_result['message'],
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
            $this->log_message("Exception in ajax_process_db_batch: " . $e->getMessage());
            $this->close_connection();
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for getting database cleanup progress
     */
    public function ajax_get_db_cleanup_progress() {
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
        $this->log_message("Received DB cleanup progress request for task_type: {$task_type}");
        
        if (isset($progress_data['progress']) && isset($progress_data['processed']) && isset($progress_data['total'])) {
            $this->log_message("Returning DB cleanup progress data: progress={$progress_data['progress']}, processed={$progress_data['processed']}, total={$progress_data['total']}, logs=" . count($progress_data['logs']));
        }
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * Display database cleanup automation settings
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for database cleanup automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }
    
    /**
     * Clear sessions method for direct task execution
     * Improved with better connection handling
     */
    public function clear_sessions() {
        try {
            // Ensure database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                return array(
                    'success' => false,
                    'message' => 'Database connection failed. Please check your connection settings.',
                    'operation_details' => array(
                        'error' => 'Database connection failed',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            // Check if there is an existing task that's completed
            $progress_file = $this->get_progress_file('sessions');
            $progress_exists = file_exists($progress_file);
            
            if ($progress_exists) {
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
                // If the task is already completed, start fresh
                if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                    $this->log_message("Previous sessions cleanup was already completed, starting fresh");
                    $progress_exists = false;
                }
            }
            
            // Initialize the task 
            if (!$progress_exists) {
                $this->data->initialize_task('sessions');
            }
            
            // Create a unique task ID
            $task_id = uniqid('sessions_');
            
            // Get current batch index
            if ($progress_exists && isset($progress_data['current_batch'])) {
                $batch_index = $progress_data['current_batch'];
            } else {
                $batch_index = 0;
            }
            
            // Process the batch
            $batch_result = $this->data->process_batch('sessions', $batch_index);
            $progress_data = json_decode(file_get_contents($this->get_progress_file('sessions')), true);
            
            // Create a proper message based on the batch result
            $message = $batch_result['message'];
            
            // Format the result
            $result = array(
                'success' => $batch_result['success'],
                'message' => $message,
                'operation_details' => array(
                    'task' => 'sessions',
                    'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
                    'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
                    'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                    'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                    'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
                    'batch_index' => $batch_index,
                    'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : ($batch_index + 1),
                    'summary' => isset($progress_data['processed']) && isset($progress_data['total']) && isset($progress_data['progress']) ? 
                        "Processed {$progress_data['processed']} out of {$progress_data['total']} sessions ({$progress_data['progress']}%)." : 
                        "Processing sessions...",
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
            
            // Add detailed information about deleted items if available
            if (isset($progress_data['deleted_items']) && !empty($progress_data['deleted_items'])) {
                // Format deleted items for display
                $formatted_items = array();
                foreach ($progress_data['deleted_items'] as $item) {
                    $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                    $id = isset($item['id']) ? $item['id'] : "unknown";
                    $modified = isset($item['modified']) ? " - Modified: {$item['modified']}" : "";
                    
                    $formatted_items[] = "$timestamp - <strong>$id</strong>$modified";
                }
                
                $result['operation_details']['deleted_items_count'] = count($progress_data['deleted_items']);
                $result['operation_details']['deleted_items_detail'] = "Deleted {$result['operation_details']['deleted_items_count']} sessions in this batch";
                $result['operation_details']['deleted_items'] = implode("<br>", $formatted_items);
            }
            
            // Close connection before returning
            $this->close_connection();
            
            return $result;
        } catch (Exception $e) {
            $this->log_message("Exception in clear_sessions: " . $e->getMessage());
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
    
    /**
     * Clear mail logs method for direct task execution
     * Improved with better connection handling
     */
    public function clear_mail_logs() {
        try {
            // Ensure database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                return array(
                    'success' => false,
                    'message' => 'Database connection failed. Please check your connection settings.',
                    'operation_details' => array(
                        'error' => 'Database connection failed',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            // Check if there is an existing task that's completed
            $progress_file = $this->get_progress_file('mail_logs');
            $progress_exists = file_exists($progress_file);
            
            if ($progress_exists) {
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
                // If the task is already completed, start fresh
                if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                    $this->log_message("Previous mail logs cleanup was already completed, starting fresh");
                    $progress_exists = false;
                }
            }
            
            // Initialize the task 
            if (!$progress_exists) {
                $this->data->initialize_task('mail_logs');
            }
            
            // Get current batch index
            if ($progress_exists && isset($progress_data['current_batch'])) {
                $batch_index = $progress_data['current_batch'];
            } else {
                $batch_index = 0;
            }
            
            // Process the batch
            $batch_result = $this->data->process_batch('mail_logs', $batch_index);
            $progress_data = json_decode(file_get_contents($this->get_progress_file('mail_logs')), true);
            
            // Create a proper message based on the batch result
            $message = $batch_result['message'];
            
            // Format the result
            $result = array(
                'success' => $batch_result['success'],
                'message' => $message,
                'operation_details' => array(
                    'task' => 'mail_logs',
                    'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
                    'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
                    'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                    'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                    'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
                    'batch_index' => $batch_index,
                    'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : ($batch_index + 1),
                    'summary' => isset($progress_data['processed']) && isset($progress_data['total']) && isset($progress_data['progress']) ? 
                        "Processed {$progress_data['processed']} out of {$progress_data['total']} mail logs ({$progress_data['progress']}%)." : 
                        "Processing mail logs...",
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
            
            // Add detailed information about deleted items if available
            if (isset($progress_data['deleted_items']) && !empty($progress_data['deleted_items'])) {
                // Format deleted items for display
                $formatted_items = array();
                foreach ($progress_data['deleted_items'] as $item) {
                    $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                    $id = isset($item['id']) ? $item['id'] : "unknown";
                    $title = isset($item['title']) ? " - {$item['title']}" : "";
                    $from = isset($item['from']) ? " - From: {$item['from']}" : "";
                    
                    $formatted_items[] = "$timestamp - <strong>$id</strong>$title$from";
                }
                
                $result['operation_details']['deleted_items_count'] = count($progress_data['deleted_items']);
                $result['operation_details']['deleted_items_detail'] = "Deleted {$result['operation_details']['deleted_items_count']} mail logs in this batch";
                $result['operation_details']['deleted_items'] = implode("<br>", $formatted_items);
            }
            
            // Close connection before returning
            $this->close_connection();
            
            return $result;
        } catch (Exception $e) {
            $this->log_message("Exception in clear_mail_logs: " . $e->getMessage());
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
    
    /**
     * Clear source queue method for direct task execution
     * Improved with better connection handling
     */
    public function clear_source_queue() {
        try {
            // Ensure database connection
            $connection = $this->get_db_connection();
            
            if (!$connection) {
                return array(
                    'success' => false,
                    'message' => 'Database connection failed. Please check your connection settings.',
                    'operation_details' => array(
                        'error' => 'Database connection failed',
                        'timestamp' => time(),
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            // Check if there is an existing task that's completed
            $progress_file = $this->get_progress_file('source_queue');
            $progress_exists = file_exists($progress_file);
            
            if ($progress_exists) {
                $progress_data = json_decode(file_get_contents($progress_file), true);
                
                // If the task is already completed, start fresh
                if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                    $this->log_message("Previous source queue cleanup was already completed, starting fresh");
                    $progress_exists = false;
                }
            }
            
            // Initialize the task 
            if (!$progress_exists) {
                $this->data->initialize_task('source_queue');
            }
            
            // Get current batch index
            if ($progress_exists && isset($progress_data['current_batch'])) {
                $batch_index = $progress_data['current_batch'];
            } else {
                $batch_index = 0;
            }
            
            // Process the batch
            $batch_result = $this->data->process_batch('source_queue', $batch_index);
            $progress_data = json_decode(file_get_contents($this->get_progress_file('source_queue')), true);
            
            // Create a proper message based on the batch result
            $message = $batch_result['message'];
            
            // Format the result
            $result = array(
                'success' => $batch_result['success'],
                'message' => $message,
                'operation_details' => array(
                    'task' => 'source_queue',
                    'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
                    'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
                    'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
                    'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
                    'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
                    'batch_index' => $batch_index,
                    'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : ($batch_index + 1),
                    'summary' => isset($progress_data['processed']) && isset($progress_data['total']) && isset($progress_data['progress']) ? 
                        "Processed {$progress_data['processed']} out of {$progress_data['total']} source queue items ({$progress_data['progress']}%)." : 
                        "Processing source queue items...",
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
            
            // Add detailed information about deleted items if available
            if (isset($progress_data['deleted_items']) && !empty($progress_data['deleted_items'])) {
                // Format deleted items for display
                $formatted_items = array();
                foreach ($progress_data['deleted_items'] as $item) {
                    $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                    $id = isset($item['id']) ? $item['id'] : "unknown";
                    $name = isset($item['name']) ? " - {$item['name']}" : "";
                    $status = isset($item['status']) ? " - Status: {$item['status']}" : "";
                    
                    $formatted_items[] = "$timestamp - <strong>$id</strong>$name$status";
                }
                
                $result['operation_details']['deleted_items_count'] = count($progress_data['deleted_items']);
                $result['operation_details']['deleted_items_detail'] = "Deleted {$result['operation_details']['deleted_items_count']} source queue items in this batch";
                $result['operation_details']['deleted_items'] = implode("<br>", $formatted_items);
            }
            
            // Close connection before returning
            $this->close_connection();
            
            return $result;
        } catch (Exception $e) {
            $this->log_message("Exception in clear_source_queue: " . $e->getMessage());
            $this->close_connection();
            
            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
}