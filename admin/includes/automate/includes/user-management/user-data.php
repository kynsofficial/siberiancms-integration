<?php
/**
 * User Management - Data Operations (Optimized for Speed with Full Functionality)
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_User_Data {
    
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
    private $chunk_size = 50; // Increased from 10 to 50

    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }
    
    /**
     * Set database name
     * 
     * @param string $db_name The database name
     */
    public function set_db_name($db_name) {
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
     * Safely execute a prepared statement with connection validation
     * 
     * @param string $query The SQL query to execute
     * @param string $types Parameter types string
     * @param array $params Array of parameters
     * @param string $context Context for error logging
     * @return mixed Statement result or false on failure
     */
    private function safe_prepared_query($query, $types, $params, $context = 'query') {
        try {
            // Ensure we have a valid connection
            if (!$this->ensure_valid_connection()) {
                $this->log_message("Cannot execute $context: Invalid database connection");
                return false;
            }
            
            // Prepare the statement
            $stmt = $this->db_connection->prepare($query);
            
            if (!$stmt) {
                $this->log_message("Failed to prepare statement for $context: " . $this->db_connection->error);
                
                // If the connection was lost, try reconnecting and retrying once
                if ($this->db_connection->errno == 2006 || $this->db_connection->errno == 2013) {
                    $this->log_message("Connection lost, attempting to reconnect and retry $context");
                    if ($this->reconnect_to_database()) {
                        $stmt = $this->db_connection->prepare($query);
                        if (!$stmt) {
                            $this->log_message("Failed to prepare statement for $context after reconnection: " . $this->db_connection->error);
                            return false;
                        }
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            }
            
            // Bind parameters if they exist
            if (!empty($params)) {
                if (count($params) === 1) {
                    $stmt->bind_param($types, $params[0]);
                } else if (count($params) === 2) {
                    $stmt->bind_param($types, $params[0], $params[1]);
                } else if (count($params) === 3) {
                    $stmt->bind_param($types, $params[0], $params[1], $params[2]);
                } else if (count($params) === 4) {
                    $stmt->bind_param($types, $params[0], $params[1], $params[2], $params[3]);
                } else {
                    // For more parameters, bind dynamically
                    $bindParams = array($types);
                    for ($i = 0; $i < count($params); $i++) {
                        $bindParams[] = &$params[$i];
                    }
                    call_user_func_array(array($stmt, 'bind_param'), $bindParams);
                }
            }
            
            // Execute the statement
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute statement for $context: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            return $stmt;
        } catch (Exception $e) {
            $this->log_message("Exception in $context: " . $e->getMessage());
            return false;
        }
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
     * AJAX handler for getting inactive users count
     */
    public function ajax_get_inactive_users_count() {
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
        
        // Get inactive users count
        $count = $this->get_inactive_users_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX handler for getting users without apps count
     */
    public function ajax_get_users_without_apps_count() {
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
        
        // Get users without apps count
        $count = $this->get_users_without_apps_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * Get inactive users count
     */
    public function get_inactive_users_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        try {
            // Get inactive users settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['user_management']['inactive']) ? $options['automate']['user_management']['inactive'] : array();
            
            // Check if inactivity period is set
            if (empty($settings['inactivity_period']) || empty($settings['inactivity_unit'])) {
                return 0;
            }
            
            // Calculate inactivity threshold date
            $inactivity_seconds = $this->get_period_in_seconds($settings['inactivity_period'], $settings['inactivity_unit']);
            $threshold_date = date('Y-m-d H:i:s', time() - $inactivity_seconds);
            
            $this->log_message("Getting count of users inactive since: " . $threshold_date);
            
            // Get inactive users count
            $query = "SELECT COUNT(*) as count FROM admin WHERE last_action < ? AND is_active = 1";
            $stmt = $this->safe_prepared_query($query, 's', array($threshold_date), 'get_inactive_users_count');
            
            if (!$stmt) {
                return 0;
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $count = intval($row['count']);
            $stmt->close();
            
            $this->log_message("Found $count inactive users");
            
            return $count;
        } catch (Exception $e) {
            $this->log_message("Error in get_inactive_users_count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get users without apps count
     */
    public function get_users_without_apps_count() {
        if (!$this->ensure_valid_connection()) {
            return 0;
        }
        
        try {
            // Get users without apps settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['user_management']['no_apps']) ? $options['automate']['user_management']['no_apps'] : array();
            
            // Set default values if settings are missing
            if (empty($settings['grace_period'])) {
                $settings['grace_period'] = 30; // Default to 30 days grace period
                $this->log_message("Using default grace period: 30 days for count");
            }
            
            if (empty($settings['grace_unit'])) {
                $settings['grace_unit'] = 'days'; // Default to days
                $this->log_message("Using default grace unit: days for count");
            }
            
            // Calculate grace period threshold date
            $grace_seconds = $this->get_period_in_seconds($settings['grace_period'], $settings['grace_unit']);
            $threshold_date = date('Y-m-d H:i:s', time() - $grace_seconds);
            
            $this->log_message("Getting count of users without apps registered before: " . $threshold_date);
            
            // Add inactivity filter if enabled and period is set
            if (!empty($settings['check_inactivity']) && !empty($settings['inactivity_period']) && !empty($settings['inactivity_unit'])) {
                $inactivity_seconds = $this->get_period_in_seconds($settings['inactivity_period'], $settings['inactivity_unit']);
                $inactivity_threshold_date = date('Y-m-d H:i:s', time() - $inactivity_seconds);
                
                $query = "SELECT COUNT(*) as count 
                        FROM admin a 
                        WHERE NOT EXISTS (
                            SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                        )
                        AND a.created_at < ? 
                        AND a.last_action < ?
                        AND a.is_active = 1";
                
                $stmt = $this->safe_prepared_query($query, 'ss', array($threshold_date, $inactivity_threshold_date), 'get_users_without_apps_count');
                
                $this->log_message("Also filtering users who haven't logged in since: " . $inactivity_threshold_date);
            } else {
                // Build the query without inactivity filter
                $query = "SELECT COUNT(*) as count 
                        FROM admin a 
                        WHERE NOT EXISTS (
                            SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                        )
                        AND a.created_at < ? 
                        AND a.is_active = 1";
                
                $stmt = $this->safe_prepared_query($query, 's', array($threshold_date), 'get_users_without_apps_count');
            }
            
            if (!$stmt) {
                return 0;
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $count = intval($row['count']);
            $stmt->close();
            
            $this->log_message("Found $count users without apps");
            
            return $count;
        } catch (Exception $e) {
            $this->log_message("Error in get_users_without_apps_count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get inactive users for processing (OPTIMIZED with batching)
     */
    public function get_inactive_users($inactive_seconds, $limit = 200, $offset = 0) {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Calculate threshold date
            $threshold_date = date('Y-m-d H:i:s', time() - $inactive_seconds);
            
            $this->log_message("Looking for users inactive since: " . $threshold_date . " (limit: $limit, offset: $offset)");
            
            // Get inactive users with limit and offset for batching
            $query = "SELECT admin_id, email, firstname, lastname, last_action 
                     FROM admin 
                     WHERE last_action < ? AND is_active = 1 
                     ORDER BY last_action ASC
                     LIMIT ? OFFSET ?";
            
            $stmt = $this->safe_prepared_query($query, 'sii', array($threshold_date, $limit, $offset), 'get_inactive_users');
            
            if (!$stmt) {
                return array();
            }
            
            $result = $stmt->get_result();
            
            // Store inactive users for processing
            $inactive_users = array();
            while ($row = $result->fetch_assoc()) {
                $inactive_users[] = $row;
            }
            
            $stmt->close();
            
            return $inactive_users;
        } catch (Exception $e) {
            $this->log_message("Error in get_inactive_users: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Get users without apps for processing (OPTIMIZED with batching)
     */
    public function get_users_without_apps($grace_seconds, $check_inactivity = false, $inactivity_seconds = 0, $limit = 200, $offset = 0) {
        if (!$this->ensure_valid_connection()) {
            return array();
        }
        
        try {
            // Calculate grace period threshold date
            $threshold_date = date('Y-m-d H:i:s', time() - $grace_seconds);
            
            $this->log_message("Looking for users without apps registered before: " . $threshold_date . " (limit: $limit, offset: $offset)");
            
            // Add inactivity filter if enabled and period is set
            if ($check_inactivity && $inactivity_seconds > 0) {
                $inactivity_threshold_date = date('Y-m-d H:i:s', time() - $inactivity_seconds);
                
                $query = "SELECT a.admin_id, a.email, a.firstname, a.lastname, a.created_at, a.last_action 
                        FROM admin a 
                        WHERE NOT EXISTS (
                            SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                        )
                        AND a.created_at < ? 
                        AND a.last_action < ?
                        AND a.is_active = 1
                        ORDER BY a.created_at ASC
                        LIMIT ? OFFSET ?";
                
                $stmt = $this->safe_prepared_query($query, 'ssii', array($threshold_date, $inactivity_threshold_date, $limit, $offset), 'get_users_without_apps');
                
                $this->log_message("Also filtering users who haven't logged in since: " . $inactivity_threshold_date);
            } else {
                // Build the query without inactivity filter
                $query = "SELECT a.admin_id, a.email, a.firstname, a.lastname, a.created_at, a.last_action 
                        FROM admin a 
                        WHERE NOT EXISTS (
                            SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                        )
                        AND a.created_at < ? 
                        AND a.is_active = 1
                        ORDER BY a.created_at ASC
                        LIMIT ? OFFSET ?";
                
                $stmt = $this->safe_prepared_query($query, 'sii', array($threshold_date, $limit, $offset), 'get_users_without_apps');
            }
            
            if (!$stmt) {
                return array();
            }
            
            $result = $stmt->get_result();
            
            // Store users without apps for processing
            $users_without_apps = array();
            while ($row = $result->fetch_assoc()) {
                $users_without_apps[] = $row;
            }
            
            $stmt->close();
            
            return $users_without_apps;
        } catch (Exception $e) {
            $this->log_message("Error in get_users_without_apps: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Preview data for AJAX requests
     */
    public function ajax_preview_user_data() {
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
        if ($data_type === 'inactive') {
            $result = $this->preview_inactive_users_data($page);
        } elseif ($data_type === 'no_apps') {
            $result = $this->preview_users_without_apps_data($page);
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
     * Preview inactive users data
     */
    private function preview_inactive_users_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        try {
            // Get inactive users settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['user_management']['inactive']) ? $options['automate']['user_management']['inactive'] : array();
            
            // Check if inactivity period is set
            if (empty($settings['inactivity_period']) || empty($settings['inactivity_unit'])) {
                return array(
                    'success' => false,
                    'message' => 'Inactivity period not set'
                );
            }
            
            // Calculate inactivity threshold date
            $inactivity_seconds = $this->get_period_in_seconds($settings['inactivity_period'], $settings['inactivity_unit']);
            $threshold_date = date('Y-m-d H:i:s', time() - $inactivity_seconds);
            
            // Calculate offset
            $offset = ($page - 1) * $per_page;
            
            // Get total count
            $count_query = "SELECT COUNT(*) as count FROM admin WHERE last_action < ? AND is_active = 1";
            $stmt = $this->safe_prepared_query($count_query, 's', array($threshold_date), 'count_inactive_users');
            
            if (!$stmt) {
                return array(
                    'success' => false,
                    'message' => 'Failed to count inactive users'
                );
            }
            
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $total = intval($row['count']);
            $stmt->close();
            
            $total_pages = ceil($total / $per_page);
            
            // Get inactive users with pagination
            $query = "SELECT admin_id, email, firstname, lastname, last_action, created_at 
                     FROM admin 
                     WHERE last_action < ? AND is_active = 1 
                     ORDER BY last_action ASC 
                     LIMIT ? OFFSET ?";
            
            $stmt = $this->safe_prepared_query($query, 'sii', array($threshold_date, $per_page, $offset), 'get_inactive_users_preview');
            
            if (!$stmt) {
                return array(
                    'success' => false,
                    'message' => 'Failed to fetch inactive users'
                );
            }
            
            $result = $stmt->get_result();
            
            // Format data for display
            $items = array();
            while ($row = $result->fetch_assoc()) {
                // Get app count for this user using a separate query with connection validation
                $app_query = "SELECT COUNT(*) as app_count FROM application WHERE admin_id = ?";
                $app_stmt = $this->safe_prepared_query($app_query, 'i', array($row['admin_id']), 'get_app_count');
                
                if ($app_stmt) {
                    $app_result = $app_stmt->get_result();
                    $app_row = $app_result->fetch_assoc();
                    $row['app_count'] = $app_row['app_count'];
                    $app_stmt->close();
                } else {
                    $row['app_count'] = 'Error';
                }
                
                $items[] = $row;
            }
            
            $stmt->close();
            
            return array(
                'success' => true,
                'data' => array(
                    'title' => 'Inactive Users',
                    'headers' => array('ID', 'Email', 'First Name', 'Last Name', 'Last Action', 'Created At', 'Apps Count'),
                    'fields' => array('admin_id', 'email', 'firstname', 'lastname', 'last_action', 'created_at', 'app_count'),
                    'items' => $items,
                    'total' => $total,
                    'total_pages' => $total_pages,
                    'current_page' => $page
                )
            );
        } catch (Exception $e) {
            $this->log_message("Error in preview_inactive_users_data: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Error fetching inactive users: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Preview users without apps data
     */
    private function preview_users_without_apps_data($page = 1, $per_page = 10) {
        if (!$this->ensure_valid_connection()) {
            return array(
                'success' => false,
                'message' => 'Database connection not configured'
            );
        }
        
        try {
            // Get users without apps settings
            $options = get_option('swsib_options', array());
            $settings = isset($options['automate']['user_management']['no_apps']) ? $options['automate']['user_management']['no_apps'] : array();
            
            // Set default values if settings are missing
            if (empty($settings['grace_period'])) {
                $settings['grace_period'] = 30; // Default to 30 days grace period
            }
            
            if (empty($settings['grace_unit'])) {
                $settings['grace_unit'] = 'days'; // Default to days
            }
            
            // Calculate grace period threshold date
            $grace_seconds = $this->get_period_in_seconds($settings['grace_period'], $settings['grace_unit']);
            $threshold_date = date('Y-m-d H:i:s', time() - $grace_seconds);
            
            // Calculate offset
            $offset = ($page - 1) * $per_page;
            
            // Add inactivity filter if enabled and period is set
            if (!empty($settings['check_inactivity']) && !empty($settings['inactivity_period']) && !empty($settings['inactivity_unit'])) {
                $inactivity_seconds = $this->get_period_in_seconds($settings['inactivity_period'], $settings['inactivity_unit']);
                $inactivity_threshold_date = date('Y-m-d H:i:s', time() - $inactivity_seconds);
                
                // Get total count
                $count_query = "SELECT COUNT(*) as count 
                               FROM admin a 
                               WHERE NOT EXISTS (
                                   SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                               )
                               AND a.created_at < ? 
                               AND a.last_action < ?
                               AND a.is_active = 1";
                
                $stmt = $this->safe_prepared_query($count_query, 'ss', array($threshold_date, $inactivity_threshold_date), 'count_users_without_apps');
                
                if (!$stmt) {
                    return array(
                        'success' => false,
                        'message' => 'Failed to count users without apps'
                    );
                }
                
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $total = intval($row['count']);
                $stmt->close();
                
                $total_pages = ceil($total / $per_page);
                
                // Get users without apps with pagination
                $query = "SELECT a.admin_id, a.email, a.firstname, a.lastname, a.created_at, a.last_action 
                         FROM admin a 
                         WHERE NOT EXISTS (
                             SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                         )
                         AND a.created_at < ? 
                         AND a.last_action < ?
                         AND a.is_active = 1
                         ORDER BY a.created_at ASC
                         LIMIT ? OFFSET ?";
                
                $stmt = $this->safe_prepared_query($query, 'ssii', array($threshold_date, $inactivity_threshold_date, $per_page, $offset), 'get_users_without_apps_preview');
            } else {
                // Get total count without inactivity filter
                $count_query = "SELECT COUNT(*) as count 
                               FROM admin a 
                               WHERE NOT EXISTS (
                                   SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                               )
                               AND a.created_at < ? 
                               AND a.is_active = 1";
                
                $stmt = $this->safe_prepared_query($count_query, 's', array($threshold_date), 'count_users_without_apps');
                
                if (!$stmt) {
                    return array(
                        'success' => false,
                        'message' => 'Failed to count users without apps'
                    );
                }
                
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $total = intval($row['count']);
                $stmt->close();
                
                $total_pages = ceil($total / $per_page);
                
                // Get users without apps with pagination
                $query = "SELECT a.admin_id, a.email, a.firstname, a.lastname, a.created_at, a.last_action 
                         FROM admin a 
                         WHERE NOT EXISTS (
                             SELECT 1 FROM application app WHERE app.admin_id = a.admin_id
                         )
                         AND a.created_at < ? 
                         AND a.is_active = 1
                         ORDER BY a.created_at ASC
                         LIMIT ? OFFSET ?";
                
                $stmt = $this->safe_prepared_query($query, 'sii', array($threshold_date, $per_page, $offset), 'get_users_without_apps_preview');
            }
            
            if (!$stmt) {
                return array(
                    'success' => false,
                    'message' => 'Failed to fetch users without apps'
                );
            }
            
            $result = $stmt->get_result();
            
            // Format data for display
            $items = array();
            while ($row = $result->fetch_assoc()) {
                $items[] = $row;
            }
            
            $stmt->close();
            
            return array(
                'success' => true,
                'data' => array(
                    'title' => 'Users Without Apps',
                    'headers' => array('ID', 'Email', 'First Name', 'Last Name', 'Created At', 'Last Action'),
                    'fields' => array('admin_id', 'email', 'firstname', 'lastname', 'created_at', 'last_action'),
                    'items' => $items,
                    'total' => $total,
                    'total_pages' => $total_pages,
                    'current_page' => $page
                )
            );
        } catch (Exception $e) {
            $this->log_message("Error in preview_users_without_apps_data: " . $e->getMessage());
            return array(
                'success' => false,
                'message' => 'Error fetching users without apps: ' . $e->getMessage()
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
            case 'years':
                return $value * 31536000; // 365 days
            default:
                return $value * 86400; // Default to days
        }
    }
}