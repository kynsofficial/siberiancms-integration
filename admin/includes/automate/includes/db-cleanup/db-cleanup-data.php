<?php
/**
 * Database Cleanup - Data handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_DB_Data {
    
    /**
     * Database connection
     */
    public $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Chunk size for processing large datasets
     */
    private $chunk_size = 5;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }
    

    /**
 * Check if database connection is valid and reconnect if needed
 * @return boolean True if connection is valid
 */
private function ensure_valid_connection() {
    // Check if connection exists and is valid
    if (!$this->db_connection || $this->db_connection->connect_errno) {
        $this->log_message("Database connection is invalid or not established. Attempting to reconnect...");
        return $this->reconnect_to_database();
    }
    
    // Test connection with a simple query instead of using ping()
    try {
        $result = @$this->db_connection->query("SELECT 1");
        if ($result) {
            $result->free();
            return true;
        }
    } catch (Exception $e) {
        $this->log_message("Connection test failed: " . $e->getMessage());
    }
    
    $this->log_message("Database connection is no longer active. Attempting to reconnect...");
    return $this->reconnect_to_database();
}
    
    /**
     * Reconnect to the database
     * @return boolean True if reconnection was successful
     */
    private function reconnect_to_database() {
        // Get database connection details from options
        $options = get_option('swsib_options', array());
        $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
        
        if (!empty($db_options['host']) && !empty($db_options['database']) && 
            !empty($db_options['username']) && !empty($db_options['password'])) {
            
            // Close existing connection if it exists
            if ($this->db_connection) {
                @$this->db_connection->close();
            }
            
            // Create new connection
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
                $this->log_message("Successfully reconnected to database");
                return true;
            } else {
                $this->log_message("Failed to reconnect to database: " . $db_connection->connect_error);
                return false;
            }
        }
        
        $this->log_message("Database connection settings not found");
        return false;
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
     * Safely execute a database query with connection validation
     * 
     * @param string $query The SQL query to execute
     * @param string $context Context for error logging
     * @return mixed Query result or false on failure
     */
    private function safe_query($query, $context = 'query') {
        try {
            // Ensure we have a valid connection
            if (!$this->ensure_valid_connection()) {
                $this->log_message("Cannot execute $context: Invalid database connection");
                return false;
            }
            
            // Execute the query
            $result = $this->db_connection->query($query);
            
            if (!$result) {
                $this->log_message("Failed to execute $context: " . $this->db_connection->error);
                
                // If the connection was lost, try reconnecting and retrying once
                if ($this->db_connection->errno == 2006 || $this->db_connection->errno == 2013) {
                    $this->log_message("Connection lost, attempting to reconnect and retry $context");
                    if ($this->reconnect_to_database()) {
                        $result = $this->db_connection->query($query);
                        if (!$result) {
                            $this->log_message("Failed to execute $context after reconnection: " . $this->db_connection->error);
                        }
                    }
                }
            }
            
            return $result;
        } catch (Exception $e) {
            $this->log_message("Exception in $context: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Count and data retrieval methods
     */
    public function get_sessions_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM session";
        $result = $this->safe_query($query, 'get_sessions_count');
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    public function get_mail_logs_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM mail_log";
        $result = $this->safe_query($query, 'get_mail_logs_count');
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    public function get_source_queue_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM source_queue";
        $result = $this->safe_query($query, 'get_source_queue_count');
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    public function get_backoffice_alerts_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM backoffice_notification WHERE type='alert'";
        $result = $this->safe_query($query, 'get_backoffice_alerts_count');
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    public function get_cleanup_log_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        // Check if the cleanup log table exists
        $table_check = $this->safe_query("SHOW TABLES LIKE 'swsib_cleanup_log'", 'check_cleanup_log_table');
        
        if (!$table_check || $table_check->num_rows === 0) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM swsib_cleanup_log";
        $result = $this->safe_query($query, 'get_cleanup_log_count');
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    public function get_optimize_tables_info() {
        if (!$this->ensure_valid_connection()) {
            return [
                'count' => 0,
                'size' => '0 MB'
            ];
        }
        
        // Get all tables in the database
        $query = "SELECT TABLE_NAME, 
                 ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = '{$this->db_name}'";
        
        $result = $this->safe_query($query, 'get_tables_info');
        
        if (!$result) {
            return [
                'count' => 0,
                'size' => '0 MB'
            ];
        }
        
        $total_size = 0;
        $table_count = $result->num_rows;
        
        while ($row = $result->fetch_assoc()) {
            $total_size += floatval($row['size_mb']);
        }
        
        return [
            'count' => $table_count,
            'size' => number_format($total_size, 2) . ' MB'
        ];
    }
    
    public function get_tables_for_optimize() {
        if (!$this->ensure_valid_connection()) {
            return [];
        }
        
        // Get all tables in the database
        $query = "SELECT TABLE_NAME, 
                 ROUND(((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024), 2) AS size_mb,
                 DATA_FREE AS free_space
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = '{$this->db_name}'
                 ORDER BY size_mb DESC";
        
        $result = $this->safe_query($query, 'get_tables_for_optimize');
        
        if (!$result) {
            return [];
        }
        
        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row;
        }
        
        return $tables;
    }
    
    /**
     * AJAX handlers for data counts
     */
    public function ajax_get_sessions_count() {
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
        
        $count = $this->get_sessions_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_get_mail_logs_count() {
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
        
        $count = $this->get_mail_logs_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_get_source_queue_count() {
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
        
        $count = $this->get_source_queue_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_get_backoffice_alerts_count() {
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
        
        $count = $this->get_backoffice_alerts_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_get_cleanup_log_count() {
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
        
        $count = $this->get_cleanup_log_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    public function ajax_get_optimize_tables_info() {
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
        
        $info = $this->get_optimize_tables_info();
        
        wp_send_json_success($info);
    }
    
    /**
     * Initialize task for batch processing
     */
    public function initialize_task($task) {
        $this->log_message("Initializing DB cleanup task: $task");
        
        // Ensure DB connection
        if (!$this->ensure_valid_connection()) {
            $this->log_message("Database connection not available for task: $task");
            return false;
        }
        
        $total = 0;
        $logs = array();
        $items_to_process = array();
        
        // Get data based on task type
        if ($task === 'sessions') {
            $items_to_process = $this->get_sessions_to_cleanup();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d sessions to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'mail_logs') {
            $items_to_process = $this->get_mail_logs_to_cleanup();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d mail logs to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'source_queue') {
            $items_to_process = $this->get_source_queue_to_cleanup();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d source queue items to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'optimize') {
            $items_to_process = $this->get_tables_for_optimize();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d tables to optimize', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'backoffice_alerts') {
            $items_to_process = $this->get_backoffice_alerts_to_cleanup();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d backoffice alerts to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        } elseif ($task === 'cleanup_log') {
            $items_to_process = $this->get_cleanup_log_entries_to_cleanup();
            $total = count($items_to_process);
            
            $logs[] = array(
                'time' => time(),
                'message' => sprintf(__('Found %d cleanup log entries to clean up', 'swiftspeed-siberian'), $total),
                'type' => 'info'
            );
        }
        
        // Ensure we have at least 1 for total to prevent division by zero
        $total = max(1, $total);
        
        // Split items into batches
        $batches = array_chunk($items_to_process, $this->chunk_size);
        if (empty($batches) && $total > 0) {
            $batches = array(array()); // Add an empty batch to process
        }
        
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
            'batch_count' => count($batches),
            'current_batch' => 0,
            'deleted' => 0,
            'errors' => 0,
            'skipped' => 0,
            'deleted_items' => array(), // Track deleted items for operation details
            'optimized_tables' => array() // Track optimized tables for operation details
        );
        
        // Save to progress file
        $progress_file = $this->get_progress_file($task);
        file_put_contents($progress_file, json_encode($progress_data));
        
        $this->log_message("Task $task initialized with $total items, " . count($batches) . " batches");
        
        return true;
    }
    
    /**
     * Process a batch of items for a specific task
     */
    public function process_batch($task, $batch_index) {
        $this->log_message("Processing batch $batch_index for task $task");
        
        // Ensure DB connection
        if (!$this->ensure_valid_connection()) {
            $this->log_message("Database connection not available for processing batch $batch_index of task $task");
            return array(
                'success' => false,
                'message' => 'Database connection not available',
                'completed' => false
            );
        }
        
        // Get progress data
        $progress_file = $this->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return array(
                'success' => false,
                'message' => 'Progress file not found',
                'completed' => false
            );
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data) {
            return array(
                'success' => false,
                'message' => 'Invalid progress data',
                'completed' => false
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
        
        // Check if the batch index is valid
        if (!isset($progress_data['batches'][$batch_index])) {
            if ($batch_index >= count($progress_data['batches'])) {
                // We've processed all batches, mark as completed
                $progress_data['status'] = 'completed';
                $progress_data['progress'] = 100;
                $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
                
                // Add completion log
                $progress_data['logs'][] = array(
                    'time' => time(),
                    'message' => sprintf(__('Task completed. Processed %d items, deleted %d, with %d errors.', 'swiftspeed-siberian'), 
                                      $progress_data['processed'], $progress_data['deleted'], $progress_data['errors']),
                    'type' => 'success'
                );
                
                // Create summary for operation details
                $progress_data['summary'] = sprintf(__('Processed %d out of %d items (100%%).', 'swiftspeed-siberian'), 
                                                 $progress_data['processed'], $progress_data['total']);
                
                // Record the cleanup results in the database
                $this->record_cleanup_results($task, $progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
                
                // Save progress data
                file_put_contents($progress_file, json_encode($progress_data));
                
                return array(
                    'success' => true,
                    'message' => 'All batches processed',
                    'progress' => 100,
                    'next_batch' => $batch_index, // Keep the same batch for clarity
                    'completed' => true
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Invalid batch index',
                    'completed' => false
                );
            }
        }
        
        // Get the batch to process
        $batch_items = $progress_data['batches'][$batch_index];
        
        // Process the batch based on task type
        $result = array(
            'deleted' => 0,
            'errors' => 0,
            'skipped' => 0,
            'logs' => [],
            'deleted_items' => array()
        );
        
        if ($task === 'sessions') {
            $result = $this->process_sessions_batch($batch_items);
        } elseif ($task === 'mail_logs') {
            $result = $this->process_mail_logs_batch($batch_items);
        } elseif ($task === 'source_queue') {
            $result = $this->process_source_queue_batch($batch_items);
        } elseif ($task === 'optimize') {
            $result = $this->process_optimize_batch($batch_items);
        } elseif ($task === 'backoffice_alerts') {
            $result = $this->process_backoffice_alerts_batch($batch_items);
        } elseif ($task === 'cleanup_log') {
            $result = $this->process_cleanup_log_batch($batch_items);
        }
        
        // Update progress
        $progress_data['deleted'] += $result['deleted'];
        $progress_data['errors'] += $result['errors'];
        $progress_data['skipped'] += $result['skipped'];
        $progress_data['processed'] += count($batch_items);
        $progress_data['current_batch'] = $batch_index + 1;
        $progress_data['last_update'] = time();
        
        // Add detailed operation tracking
        if (isset($result['deleted_items']) && !empty($result['deleted_items'])) {
            if (!isset($progress_data['deleted_items'])) {
                $progress_data['deleted_items'] = array();
            }
            $progress_data['deleted_items'] = array_merge($progress_data['deleted_items'], $result['deleted_items']);
            
            // Limit to 100 items to prevent file size issues
            if (count($progress_data['deleted_items']) > 100) {
                $progress_data['deleted_items'] = array_slice($progress_data['deleted_items'], -100);
            }
        }
        
        // Special handling for optimized tables
        if ($task === 'optimize' && isset($result['optimized_tables']) && !empty($result['optimized_tables'])) {
            if (!isset($progress_data['optimized_tables'])) {
                $progress_data['optimized_tables'] = array();
            }
            $progress_data['optimized_tables'] = array_merge($progress_data['optimized_tables'], $result['optimized_tables']);
            
            // Limit to 100 items to prevent file size issues
            if (count($progress_data['optimized_tables']) > 100) {
                $progress_data['optimized_tables'] = array_slice($progress_data['optimized_tables'], -100);
            }
        }
        
        // Calculate progress percentage
        if ($progress_data['total'] > 0) {
            $progress_data['progress'] = min(100, round(($progress_data['processed'] / $progress_data['total']) * 100));
        } else {
            $progress_data['progress'] = 100;
        }
        
        // Create summary for operation details - improved format to match image cleanup
        $progress_data['summary'] = sprintf(__('Processed %d out of %d items (%d%%).', 'swiftspeed-siberian'), 
                                         $progress_data['processed'], $progress_data['total'], $progress_data['progress']);
        
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
            $progress_data['current_item'] = __('Task completed', 'swiftspeed-siberian');
            
            // Add completion log
            $progress_data['logs'][] = array(
                'time' => time(),
                'message' => sprintf(__('Task completed. Processed %d items, deleted %d, with %d errors.', 'swiftspeed-siberian'), 
                                   $progress_data['processed'], $progress_data['deleted'], $progress_data['errors']),
                'type' => 'success'
            );
            
            // Create summary for operation details
            $progress_data['summary'] = sprintf(__('Processed %d out of %d items (100%%).', 'swiftspeed-siberian'), 
                                            $progress_data['processed'], $progress_data['total']);
            
            // Record the cleanup results in the database
            $this->record_cleanup_results($task, $progress_data['deleted'], $progress_data['errors'], $progress_data['skipped']);
        }
        
        // Save updated progress data
        file_put_contents($progress_file, json_encode($progress_data));
        
        // Build a more informative message for the response
        $message = sprintf(__('Batch %d processed. Will continue next run.', 'swiftspeed-siberian'), $batch_index);
        
        if ($completed) {
            $message = sprintf(__('All batches processed. Task completed.', 'swiftspeed-siberian'));
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'progress' => $progress_data['progress'],
            'next_batch' => $batch_index + 1,
            'completed' => $completed
        );
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
     * Preview data methods
     */
    public function ajax_preview_db_data() {
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
        
        // Get data type and page
        $data_type = isset($_POST['data_type']) ? sanitize_text_field($_POST['data_type']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        
        if (empty($data_type)) {
            wp_send_json_error(array('message' => 'Data type not specified.'));
            return;
        }
        
        // Preview data based on type
        if ($data_type === 'sessions') {
            $result = $this->preview_sessions_data($page);
        } elseif ($data_type === 'mail_logs') {
            $result = $this->preview_mail_logs_data($page);
        } elseif ($data_type === 'source_queue') {
            $result = $this->preview_source_queue_data($page);
        } elseif ($data_type === 'optimize') {
            $result = $this->preview_optimize_tables_data($page);
        } elseif ($data_type === 'backoffice_alerts') {
            $result = $this->preview_backoffice_alerts_data($page);
        } elseif ($data_type === 'cleanup_log') {
            $result = $this->preview_cleanup_log_data($page);
        } else {
            wp_send_json_error(array('message' => 'Invalid data type.'));
            return;
        }
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Preview methods implementation
     */
    public function preview_sessions_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM session";
        $result = $this->safe_query($count_query, 'count_sessions');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count sessions'
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get sessions with pagination 
        $query = "SELECT session_id, modified 
                 FROM session 
                 ORDER BY modified DESC 
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->safe_query($query, 'fetch_sessions');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch sessions'
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Sessions',
                'headers' => array('Session ID', 'Modified'),
                'fields' => array('session_id', 'modified'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    public function preview_mail_logs_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM mail_log";
        $result = $this->safe_query($count_query, 'count_mail_logs');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count mail logs'
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get mail logs with pagination
        $query = "SELECT log_id, title, `from`, recipients, 
                 CASE WHEN text_error IS NULL THEN 'Success' ELSE 'Failed' END AS status,
                 created_at 
                 FROM mail_log 
                 ORDER BY created_at DESC 
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->safe_query($query, 'fetch_mail_logs');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch mail logs'
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Mail Logs',
                'headers' => array('ID', 'Title', 'From', 'Recipients', 'Status', 'Created At'),
                'fields' => array('log_id', 'title', 'from', 'recipients', 'status', 'created_at'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    public function preview_source_queue_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM source_queue";
        $result = $this->safe_query($count_query, 'count_source_queue');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count source queue'
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get source queue with pagination
        $query = "SELECT source_queue_id, name, status, created_at, updated_at 
                 FROM source_queue 
                 ORDER BY created_at DESC 
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->safe_query($query, 'fetch_source_queue');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch source queue'
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Source Queue',
                'headers' => array('ID', 'Name', 'Status', 'Created At', 'Updated At'),
                'fields' => array('source_queue_id', 'name', 'status', 'created_at', 'updated_at'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    public function preview_optimize_tables_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Get tables info
        $tables = $this->get_tables_for_optimize();
        $total = count($tables);
        $total_pages = ceil($total / $per_page);
        
        // Apply pagination manually
        $offset = ($page - 1) * $per_page;
        $tables = array_slice($tables, $offset, $per_page);
        
        // Format data for display
        $items = array();
        foreach ($tables as $table) {
            $items[] = array(
                'table_name' => $table['TABLE_NAME'],
                'size_mb' => $table['size_mb'],
                'free_space' => $table['free_space'],
                'status' => 'Not optimized'
            );
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Database Tables',
                'headers' => array('Table Name', 'Size (MB)', 'Free Space (Bytes)', 'Status'),
                'fields' => array('table_name', 'size_mb', 'free_space', 'status'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    public function preview_backoffice_alerts_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM backoffice_notification WHERE type='alert'";
        $result = $this->safe_query($count_query, 'count_backoffice_alerts');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count backoffice alerts'
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get backoffice alerts with pagination
        $query = "SELECT notification_id, title, description, created_at 
                 FROM backoffice_notification 
                 WHERE type='alert' 
                 ORDER BY created_at DESC 
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->safe_query($query, 'fetch_backoffice_alerts');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch backoffice alerts'
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Backoffice Alerts',
                'headers' => array('ID', 'Title', 'Description', 'Created At'),
                'fields' => array('notification_id', 'title', 'description', 'created_at'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    public function preview_cleanup_log_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        // Check if the cleanup log table exists
        $table_check = $this->safe_query("SHOW TABLES LIKE 'swsib_cleanup_log'", 'check_cleanup_log_table');
        
        if (!$table_check || $table_check->num_rows === 0) {
            // Create the table if it doesn't exist
            $create_table = "CREATE TABLE swsib_cleanup_log (
                id INT(11) NOT NULL AUTO_INCREMENT,
                task_type VARCHAR(50) NOT NULL,
                items_deleted INT(11) NOT NULL,
                errors INT(11) NOT NULL,
                executed_at DATETIME NOT NULL,
                items_skipped INT(11) DEFAULT 0,
                PRIMARY KEY (id)
            )";
            
            $this->safe_query($create_table, 'create_cleanup_log_table');
            
            return array(
                'success' => true,
                'data' => array(
                    'title' => 'Cleanup Log',
                    'headers' => array('ID', 'Task Type', 'Items Deleted', 'Errors', 'Executed At', 'Items Skipped'),
                    'fields' => array('id', 'task_type', 'items_deleted', 'errors', 'executed_at', 'items_skipped'),
                    'items' => array(),
                    'total' => 0,
                    'total_pages' => 1,
                    'current_page' => 1
                )
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM swsib_cleanup_log";
        $result = $this->safe_query($count_query, 'count_cleanup_log');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count cleanup log'
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get cleanup log with pagination
        $query = "SELECT id, task_type, items_deleted, errors, executed_at, 
                 IFNULL(items_skipped, 0) as items_skipped 
                 FROM swsib_cleanup_log 
                 ORDER BY executed_at DESC 
                 LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->safe_query($query, 'fetch_cleanup_log');
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch cleanup log'
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Cleanup Log',
                'headers' => array('ID', 'Task Type', 'Items Deleted', 'Errors', 'Executed At', 'Items Skipped'),
                'fields' => array('id', 'task_type', 'items_deleted', 'errors', 'executed_at', 'items_skipped'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    /**
     * Get data for cleanup methods
     */
    private function get_sessions_to_cleanup() {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Get settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['db_cleanup']['sessions']) ? $options['automate']['db_cleanup']['sessions'] : array();
            
            // Calculate threshold date if 'older_than' is set
            $where_clause = '';
            if (!empty($settings['older_than']) && !empty($settings['older_than_unit'])) {
                $threshold_seconds = $this->get_period_in_seconds($settings['older_than'], $settings['older_than_unit']);
                $threshold_date = date('Y-m-d H:i:s', time() - $threshold_seconds);
                $where_clause = " WHERE modified < '$threshold_date'";
            }
            
            // Get all sessions that need to be cleaned up
            $query = "SELECT session_id, modified FROM session" . $where_clause;
            $result = $this->safe_query($query, 'get_sessions_to_cleanup');
            
            if (!$result) {
                return array();
            }
            
            $sessions = array();
            while ($row = $result->fetch_assoc()) {
                $sessions[] = $row;
            }
            
            return $sessions;
        } catch (Exception $e) {
            $this->log_message("Exception in get_sessions_to_cleanup: " . $e->getMessage());
            return array();
        }
    }
    
    private function get_mail_logs_to_cleanup() {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Get settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['db_cleanup']['mail_logs']) ? $options['automate']['db_cleanup']['mail_logs'] : array();
            
            // Calculate threshold date if 'older_than' is set
            $where_clause = '';
            if (!empty($settings['older_than']) && !empty($settings['older_than_unit'])) {
                $threshold_seconds = $this->get_period_in_seconds($settings['older_than'], $settings['older_than_unit']);
                $threshold_date = date('Y-m-d H:i:s', time() - $threshold_seconds);
                $where_clause = " WHERE created_at < '$threshold_date'";
            }
            
            // Get all mail logs that need to be cleaned up
            $query = "SELECT log_id, title, `from`, created_at FROM mail_log" . $where_clause;
            $result = $this->safe_query($query, 'get_mail_logs_to_cleanup');
            
            if (!$result) {
                return array();
            }
            
            $logs = array();
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
            
            return $logs;
        } catch (Exception $e) {
            $this->log_message("Exception in get_mail_logs_to_cleanup: " . $e->getMessage());
            return array();
        }
    }
    
    private function get_source_queue_to_cleanup() {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Get settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['db_cleanup']['source_queue']) ? $options['automate']['db_cleanup']['source_queue'] : array();
            
            // Build where clause
            $where_conditions = array();
            
            // Add age condition if set
            if (!empty($settings['older_than']) && !empty($settings['older_than_unit'])) {
                $threshold_seconds = $this->get_period_in_seconds($settings['older_than'], $settings['older_than_unit']);
                $threshold_date = date('Y-m-d H:i:s', time() - $threshold_seconds);
                $where_conditions[] = "created_at < '$threshold_date'";
            }
            
            // Add status condition if set
            if (!empty($settings['status']) && $settings['status'] !== 'all') {
                $status = $this->db_connection->real_escape_string($settings['status']);
                $where_conditions[] = "status = '$status'";
            }
            
            // Combine conditions
            $where_clause = '';
            if (!empty($where_conditions)) {
                $where_clause = " WHERE " . implode(' AND ', $where_conditions);
            }
            
            // Get all source queue items that need to be cleaned up
            $query = "SELECT source_queue_id, name, status, created_at FROM source_queue" . $where_clause;
            $result = $this->safe_query($query, 'get_source_queue_to_cleanup');
            
            if (!$result) {
                return array();
            }
            
            $items = array();
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            
            return $items;
        } catch (Exception $e) {
            $this->log_message("Exception in get_source_queue_to_cleanup: " . $e->getMessage());
            return array();
        }
    }
    
    private function get_backoffice_alerts_to_cleanup() {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Get settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['db_cleanup']['backoffice_alerts']) ? $options['automate']['db_cleanup']['backoffice_alerts'] : array();
            
            // Calculate threshold date if 'older_than' is set
            $where_clause = " WHERE type='alert'";
            if (!empty($settings['older_than']) && !empty($settings['older_than_unit'])) {
                $threshold_seconds = $this->get_period_in_seconds($settings['older_than'], $settings['older_than_unit']);
                $threshold_date = date('Y-m-d H:i:s', time() - $threshold_seconds);
                $where_clause .= " AND created_at < '$threshold_date'";
            }
            
            // Get all backoffice alerts that need to be cleaned up
            $query = "SELECT notification_id, title, created_at FROM backoffice_notification" . $where_clause;
            $result = $this->safe_query($query, 'get_backoffice_alerts_to_cleanup');
            
            if (!$result) {
                return array();
            }
            
            $alerts = array();
            while ($row = $result->fetch_assoc()) {
                $alerts[] = $row;
            }
            
            return $alerts;
        } catch (Exception $e) {
            $this->log_message("Exception in get_backoffice_alerts_to_cleanup: " . $e->getMessage());
            return array();
        }
    }
    
    private function get_cleanup_log_entries_to_cleanup() {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Check if the cleanup log table exists
            $table_check = $this->safe_query("SHOW TABLES LIKE 'swsib_cleanup_log'", 'check_cleanup_log_table');
            
            if (!$table_check || $table_check->num_rows === 0) {
                return array();
            }
            
            // Get settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['db_cleanup']['cleanup_log']) ? $options['automate']['db_cleanup']['cleanup_log'] : array();
            
            // Calculate threshold date if 'older_than' is set
            $where_clause = '';
            if (!empty($settings['older_than']) && !empty($settings['older_than_unit'])) {
                $threshold_seconds = $this->get_period_in_seconds($settings['older_than'], $settings['older_than_unit']);
                $threshold_date = date('Y-m-d H:i:s', time() - $threshold_seconds);
                $where_clause = " WHERE executed_at < '$threshold_date'";
            }
            
            // Get all cleanup log entries that need to be cleaned up
            $query = "SELECT id, task_type, executed_at FROM swsib_cleanup_log" . $where_clause;
            $result = $this->safe_query($query, 'get_cleanup_log_entries_to_cleanup');
            
            if (!$result) {
                return array();
            }
            
            $entries = array();
            while ($row = $result->fetch_assoc()) {
                $entries[] = $row;
            }
            
            return $entries;
        } catch (Exception $e) {
            $this->log_message("Exception in get_cleanup_log_entries_to_cleanup: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Batch processing methods
     */
    private function process_sessions_batch($sessions) {
        if (!$this->ensure_valid_connection() || empty($sessions)) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No sessions to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'deleted_items' => array()
            );
        }
        
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];
        $deleted_items = array();
        
        try {
            // Extract session IDs
            $session_ids = array_column($sessions, 'session_id');
            
            if (empty($session_ids)) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'No valid session IDs found',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            // Create placeholders and escape session IDs
            $placeholders = array();
            foreach ($session_ids as $id) {
                $placeholders[] = "'" . $this->db_connection->real_escape_string($id) . "'";
            }
            
            // Build IN clause
            $ids_clause = implode(',', $placeholders);
            
            // Delete sessions in a single query
            $query = "DELETE FROM session WHERE session_id IN ({$ids_clause})";
            $result = $this->safe_query($query, 'delete_sessions_batch');
            
            if ($result) {
                $deleted = $this->db_connection->affected_rows;
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted %d sessions', 'swiftspeed-siberian'), $deleted),
                    'type' => 'success'
                );
                
                // Add details about deleted sessions
                foreach ($sessions as $session) {
                    $deleted_items[] = array(
                        'id' => $session['session_id'],
                        'modified' => $session['modified'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
            } else {
                $errors = count($session_ids);
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to delete sessions', 'swiftspeed-siberian')),
                    'type' => 'error'
                );
            }
            
            return array(
                'deleted' => $deleted,
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'deleted_items' => $deleted_items
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_sessions_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($sessions),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'deleted_items' => array()
            );
        }
    }
    
    private function process_mail_logs_batch($mail_logs) {
        if (!$this->ensure_valid_connection() || empty($mail_logs)) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No mail logs to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'deleted_items' => array()
            );
        }
        
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];
        $deleted_items = array();
        
        try {
            // Extract log IDs
            $log_ids = array_column($mail_logs, 'log_id');
            
            if (empty($log_ids)) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'No valid mail log IDs found',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            // Create placeholders and clean log IDs
            $placeholders = array();
            foreach ($log_ids as $id) {
                $placeholders[] = intval($id);
            }
            
            // Build IN clause
            $ids_clause = implode(',', $placeholders);
            
            // Delete mail logs in a single query
            $query = "DELETE FROM mail_log WHERE log_id IN ({$ids_clause})";
            $result = $this->safe_query($query, 'delete_mail_logs_batch');
            
            if ($result) {
                $deleted = $this->db_connection->affected_rows;
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted %d mail logs', 'swiftspeed-siberian'), $deleted),
                    'type' => 'success'
                );
                
                // Add details about deleted mail logs
                foreach ($mail_logs as $mail_log) {
                    $deleted_items[] = array(
                        'id' => $mail_log['log_id'],
                        'title' => $mail_log['title'],
                        'from' => $mail_log['from'],
                        'created_at' => $mail_log['created_at'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
            } else {
                $errors = count($log_ids);
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to delete mail logs', 'swiftspeed-siberian')),
                    'type' => 'error'
                );
            }
            
            return array(
                'deleted' => $deleted,
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'deleted_items' => $deleted_items
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_mail_logs_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($mail_logs),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'deleted_items' => array()
            );
        }
    }
    
    private function process_source_queue_batch($source_queue_items) {
        if (!$this->ensure_valid_connection() || empty($source_queue_items)) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No source queue items to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'deleted_items' => array()
            );
        }
        
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];
        $deleted_items = array();
        
        try {
            // Extract item IDs
            $item_ids = array_column($source_queue_items, 'source_queue_id');
            
            if (empty($item_ids)) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'No valid source queue IDs found',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            // Create placeholders and clean item IDs
            $placeholders = array();
            foreach ($item_ids as $id) {
                $placeholders[] = intval($id);
            }
            
            // Build IN clause
            $ids_clause = implode(',', $placeholders);
            
            // Delete source queue items in a single query
            $query = "DELETE FROM source_queue WHERE source_queue_id IN ({$ids_clause})";
            $result = $this->safe_query($query, 'delete_source_queue_batch');
            
            if ($result) {
                $deleted = $this->db_connection->affected_rows;
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted %d source queue items', 'swiftspeed-siberian'), $deleted),
                    'type' => 'success'
                );
                
                // Add details about deleted source queue items
                foreach ($source_queue_items as $item) {
                    $deleted_items[] = array(
                        'id' => $item['source_queue_id'],
                        'name' => $item['name'],
                        'status' => $item['status'],
                        'created_at' => $item['created_at'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
            } else {
                $errors = count($item_ids);
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to delete source queue items', 'swiftspeed-siberian')),
                    'type' => 'error'
                );
            }
            
            return array(
                'deleted' => $deleted,
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'deleted_items' => $deleted_items
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_source_queue_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($source_queue_items),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'deleted_items' => array()
            );
        }
    }
    
    private function process_optimize_batch($tables) {
        if (!$this->ensure_valid_connection() || empty($tables)) {
            return array(
                'deleted' => 0, // 'deleted' used as 'optimized' count
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No tables to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'optimized_tables' => array()
            );
        }
        
        $optimized = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];
        $optimized_tables = array();
        
        try {
            // Process each table
            foreach ($tables as $table) {
                $table_name = $table['TABLE_NAME'];
                
                // Add log about current table
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Optimizing table: %s', 'swiftspeed-siberian'), $table_name),
                    'type' => 'info'
                );
                
                // Run optimize
                $query = "OPTIMIZE TABLE `{$table_name}`";
                $result = $this->safe_query($query, "optimize_table_{$table_name}");
                
                if ($result) {
                    $optimized++;
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Successfully optimized table: %s', 'swiftspeed-siberian'), $table_name),
                        'type' => 'success'
                    );
                    
                    // Add details about optimized table
                    $optimized_tables[] = array(
                        'table_name' => $table_name,
                        'size_mb' => $table['size_mb'],
                        'free_space' => $table['free_space'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                } else {
                    $errors++;
                    $logs[] = array(
                        'time' => time(),
                        'message' => sprintf(__('Failed to optimize table %s', 'swiftspeed-siberian'), $table_name),
                        'type' => 'error'
                    );
                }
            }
            
            return array(
                'deleted' => $optimized, // Using 'deleted' for consistency with other methods
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'optimized_tables' => $optimized_tables
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_optimize_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($tables),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'optimized_tables' => array()
            );
        }
    }
    
    private function process_backoffice_alerts_batch($alerts) {
        if (!$this->ensure_valid_connection() || empty($alerts)) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No backoffice alerts to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'deleted_items' => array()
            );
        }
        
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];
        $deleted_items = array();
        
        try {
            // Extract alert IDs
            $alert_ids = array_column($alerts, 'notification_id');
            
            if (empty($alert_ids)) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'No valid backoffice alert IDs found',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            // Create placeholders and clean alert IDs
            $placeholders = array();
            foreach ($alert_ids as $id) {
                $placeholders[] = intval($id);
            }
            
            // Build IN clause
            $ids_clause = implode(',', $placeholders);
            
            // Delete backoffice alerts in a single query
            $query = "DELETE FROM backoffice_notification WHERE notification_id IN ({$ids_clause})";
            $result = $this->safe_query($query, 'delete_backoffice_alerts_batch');
            
            if ($result) {
                $deleted = $this->db_connection->affected_rows;
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted %d backoffice alerts', 'swiftspeed-siberian'), $deleted),
                    'type' => 'success'
                );
                
                // Add details about deleted alerts
                foreach ($alerts as $alert) {
                    $deleted_items[] = array(
                        'id' => $alert['notification_id'],
                        'title' => $alert['title'],
                        'created_at' => $alert['created_at'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
            } else {
                $errors = count($alert_ids);
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to delete backoffice alerts', 'swiftspeed-siberian')),
                    'type' => 'error'
                );
            }
            
            return array(
                'deleted' => $deleted,
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'deleted_items' => $deleted_items
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_backoffice_alerts_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($alerts),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'deleted_items' => array()
            );
        }
    }
    
    private function process_cleanup_log_batch($entries) {
        if (!$this->ensure_valid_connection() || empty($entries)) {
            return array(
                'deleted' => 0,
                'errors' => 0,
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'No cleanup log entries to process or database connection not available',
                        'type' => 'info'
                    )
                ],
                'deleted_items' => array()
            );
        }
        
        try {
            // Check if the cleanup log table exists
            $table_check = $this->safe_query("SHOW TABLES LIKE 'swsib_cleanup_log'", 'check_cleanup_log_table');
            
            if (!$table_check || $table_check->num_rows === 0) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'Cleanup log table does not exist',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            $deleted = 0;
            $errors = 0;
            $skipped = 0;
            $logs = [];
            $deleted_items = array();
            
            // Extract entry IDs
            $entry_ids = array_column($entries, 'id');
            
            if (empty($entry_ids)) {
                return array(
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'logs' => [
                        array(
                            'time' => time(),
                            'message' => 'No valid cleanup log entry IDs found',
                            'type' => 'info'
                        )
                    ],
                    'deleted_items' => array()
                );
            }
            
            // Create placeholders and clean entry IDs
            $placeholders = array();
            foreach ($entry_ids as $id) {
                $placeholders[] = intval($id);
            }
            
            // Build IN clause
            $ids_clause = implode(',', $placeholders);
            
            // Delete cleanup log entries in a single query
            $query = "DELETE FROM swsib_cleanup_log WHERE id IN ({$ids_clause})";
            $result = $this->safe_query($query, 'delete_cleanup_log_batch');
            
            if ($result) {
                $deleted = $this->db_connection->affected_rows;
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Deleted %d cleanup log entries', 'swiftspeed-siberian'), $deleted),
                    'type' => 'success'
                );
                
                // Add details about deleted entries
                foreach ($entries as $entry) {
                    $deleted_items[] = array(
                        'id' => $entry['id'],
                        'task_type' => $entry['task_type'],
                        'executed_at' => $entry['executed_at'],
                        'timestamp' => date('Y-m-d H:i:s')
                    );
                }
            } else {
                $errors = count($entry_ids);
                $logs[] = array(
                    'time' => time(),
                    'message' => sprintf(__('Failed to delete cleanup log entries', 'swiftspeed-siberian')),
                    'type' => 'error'
                );
            }
            
            return array(
                'deleted' => $deleted,
                'errors' => $errors,
                'skipped' => $skipped,
                'logs' => $logs,
                'deleted_items' => $deleted_items
            );
        } catch (Exception $e) {
            $this->log_message("Exception in process_cleanup_log_batch: " . $e->getMessage());
            return array(
                'deleted' => 0,
                'errors' => count($entries),
                'skipped' => 0,
                'logs' => [
                    array(
                        'time' => time(),
                        'message' => 'Error: ' . $e->getMessage(),
                        'type' => 'error'
                    )
                ],
                'deleted_items' => array()
            );
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
            default:
                return $value * 86400; // Default to days
        }
    }
    
    /**
     * Record cleanup results in the database
     */
    private function record_cleanup_results($type, $deleted, $errors, $skipped = 0) {
        if (!$this->ensure_valid_connection()) {
            return false;
        }
        
        try {
            $timestamp = date('Y-m-d H:i:s');
            $type = $this->db_connection->real_escape_string('db_cleanup_' . $type);
            
            // Check if the cleanup log table exists
            $table_check = $this->safe_query("SHOW TABLES LIKE 'swsib_cleanup_log'", 'check_cleanup_log_table');
            
            if (!$table_check || $table_check->num_rows === 0) {
                // Create the table if it doesn't exist
                $create_table = "CREATE TABLE swsib_cleanup_log (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    task_type VARCHAR(50) NOT NULL,
                    items_deleted INT(11) NOT NULL,
                    errors INT(11) NOT NULL,
                    executed_at DATETIME NOT NULL,
                    items_skipped INT(11) DEFAULT 0,
                    PRIMARY KEY (id)
                )";
                
                $this->safe_query($create_table, 'create_cleanup_log_table');
            }
            
            // Check if the items_skipped column exists
            $column_check = $this->safe_query("SHOW COLUMNS FROM swsib_cleanup_log LIKE 'items_skipped'", 'check_items_skipped_column');
            
            if (!$column_check || $column_check->num_rows === 0) {
                // Add the column if it doesn't exist
                $add_column = "ALTER TABLE swsib_cleanup_log ADD COLUMN items_skipped INT(11) DEFAULT 0";
                $this->safe_query($add_column, 'add_items_skipped_column');
            }
            
            // Insert cleanup results
            $query = "INSERT INTO swsib_cleanup_log (task_type, items_deleted, errors, executed_at, items_skipped)
                     VALUES ('{$type}', {$deleted}, {$errors}, '{$timestamp}', {$skipped})";
            
            $result = $this->safe_query($query, 'insert_cleanup_results');
            
            if ($result) {
                $this->log_message("Recorded cleanup results for $type: deleted=$deleted, errors=$errors, skipped=$skipped");
                return true;
            } else {
                $this->log_message("Failed to record cleanup results for $type");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in record_cleanup_results: " . $e->getMessage());
            return false;
        }
    }
}