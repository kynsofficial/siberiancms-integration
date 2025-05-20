<?php
/**
 * Application Management - Data handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_App_Data {
    
    /**
     * Database connection
     */
    public $db_connection;
    
    /**
     * Database name
     */
    public $db_name;
    
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
     * AJAX handler for getting zero size apps count
     */
    public function ajax_get_zero_size_apps_count() {
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
        
        // Get zero size apps count
        $count = $this->get_zero_size_apps_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX handler for getting inactive apps count
     */
    public function ajax_get_inactive_apps_count() {
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
        
        // Get inactive apps count
        $count = $this->get_inactive_apps_count();
        
        wp_send_json_success(array('count' => $count));
    }
    /**
 * Ensure database connection is valid
 * @return boolean True if connection is valid
 */
private function ensure_valid_connection() {
    // Check if connection exists
    if (!$this->db_connection) {
        $this->log_message("No database connection available");
        return false;
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
    
    $this->log_message("Database connection lost");
    return false;
}
    /**
     * AJAX handler for getting size violation apps count
     */
    public function ajax_get_size_violation_apps_count() {
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
        
        // Get size violation apps count
        $count = $this->get_size_violation_apps_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX handler for getting apps without users count
     */
    public function ajax_get_apps_without_users_count() {
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
        
        // Get apps without users count
        $count = $this->get_apps_without_users_count();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * Get zero size apps count
     */
    public function get_zero_size_apps_count() {
        if (!$this->db_connection) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count FROM application WHERE size_on_disk = 0 OR size_on_disk IS NULL";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get zero size apps count: " . $this->db_connection->error);
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    /**
     * Get inactive apps count
     */
    public function get_inactive_apps_count() {
        if (!$this->db_connection) {
            return 0;
        }
        
        // Get settings to check if warnings are enabled
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['app_management']['inactive']) ? 
                    $options['automate']['app_management']['inactive'] : array();
        $send_warning = !empty($settings['send_warning']);
        
        // Base query
        $query = "SELECT COUNT(*) as count FROM application WHERE is_active = 0";
        
        // If warnings are enabled, adjust the count
        if ($send_warning) {
            $warned_transient = get_transient('swsib_warned_inactive_apps');
            $warned_data = $warned_transient ? $warned_transient : array();
            
            if (!empty($warned_data)) {
                $warned_ids = implode(',', array_keys($warned_data));
                $query = "SELECT COUNT(*) as count FROM application WHERE is_active = 0 AND app_id IN ($warned_ids)";
            }
        }
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get inactive apps count: " . $this->db_connection->error);
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    /**
     * Get size violation apps count - Updated to handle warnings correctly
     */
    public function get_size_violation_apps_count() {
        if (!$this->db_connection) {
            return 0;
        }
        
        // Get size limit settings
        $options = get_option('swsib_options', array());
        $size_limits = isset($options['automate']['subscription_size_limits']) ? $options['automate']['subscription_size_limits'] : array();
        
        if (empty($size_limits)) {
            return 0;
        }
        
        // Get size violation apps settings
        $settings = isset($options['automate']['app_management']['size_violation']) ? 
                    $options['automate']['app_management']['size_violation'] : array();
        $delete_immediately = !empty($settings['delete_immediately']);
        $send_warning = !empty($settings['send_warning']) && !$delete_immediately;
        
        // Build query to find apps that exceed their subscription size limit
        $conditions = array();
        
        foreach ($size_limits as $subscription_id => $size_limit) {
            if ($size_limit > 0) {
                // Size limit is in MB, convert to bytes (size_on_disk is stored in bytes)
                $size_limit_bytes = $size_limit * 1024 * 1024;
                $conditions[] = "(sa.subscription_id = $subscription_id AND app.size_on_disk > $size_limit_bytes)";
            }
        }
        
        if (empty($conditions)) {
            return 0;
        }
        
        // Base query
        $query = "SELECT COUNT(*) AS count
                FROM application app 
                JOIN subscription_application sa ON app.app_id = sa.app_id 
                WHERE app.is_active = 1 
                AND (" . implode(' OR ', $conditions) . ")";
                
        // If warnings are enabled, show count for warned apps that are ready for deletion
        if ($send_warning) {
            $warned_transient = get_transient('swsib_warned_size_violation_apps');
            $warned_data = $warned_transient ? $warned_transient : array();
            
            // Filter for warned apps where warning period has expired
            $ready_for_deletion = array();
            $current_time = time();
            
            foreach ($warned_data as $app_id => $warning_info) {
                if (isset($warning_info['expires']) && $current_time >= $warning_info['expires']) {
                    $ready_for_deletion[] = $app_id;
                }
            }
            
            if (!empty($ready_for_deletion)) {
                $warned_ids = implode(',', $ready_for_deletion);
                $query = "SELECT COUNT(*) AS count
                        FROM application app 
                        JOIN subscription_application sa ON app.app_id = sa.app_id 
                        WHERE app.is_active = 1 
                        AND (" . implode(' OR ', $conditions) . ")
                        AND app.app_id IN ($warned_ids)";
            } else {
                // No apps ready for deletion
                return 0;
            }
        }
                
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get size violation apps count: " . $this->db_connection->error);
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    /**
     * Get apps without users count
     */
    public function get_apps_without_users_count() {
        if (!$this->db_connection) {
            return 0;
        }
        
        $query = "SELECT COUNT(*) as count 
                FROM application app 
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id 
                WHERE adm.admin_id IS NULL OR app.admin_id IS NULL";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get apps without users count: " . $this->db_connection->error);
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    
    /**
     * Get zero size apps
     */
    public function get_zero_size_apps() {
        if (!$this->db_connection) {
            return array();
        }
        
        $query = "SELECT app.app_id, app.name, app.created_at, app.admin_id, 
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as owner_email
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.size_on_disk = 0 OR app.size_on_disk IS NULL
                ORDER BY app.app_id ASC";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get zero size apps: " . $this->db_connection->error);
            return array();
        }
        
        $apps = array();
        while ($row = $result->fetch_assoc()) {
            $apps[] = $row;
        }
        
        return $apps;
    }
    
    /**
     * Get inactive apps
     */
    public function get_inactive_apps() {
        if (!$this->db_connection) {
            return array();
        }
        
        // Get all inactive apps regardless of warning status
        $query = "SELECT app.app_id, app.name, app.created_at, app.updated_at, app.admin_id, 
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as email
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.is_active = 0
                ORDER BY app.app_id ASC";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get inactive apps: " . $this->db_connection->error);
            return array();
        }
        
        $apps = array();
        while ($row = $result->fetch_assoc()) {
            $apps[] = $row;
        }
        
        return $apps;
    }
    
    /**
     * Get size violation apps - Updated to only return apps ready for deletion
     */
    public function get_size_violation_apps() {
        if (!$this->db_connection) {
            return array();
        }
        
        // Get size limit settings
        $options = get_option('swsib_options', array());
        $size_limits = isset($options['automate']['subscription_size_limits']) ? $options['automate']['subscription_size_limits'] : array();
        
        if (empty($size_limits)) {
            return array();
        }
        
        // Get size violation apps settings
        $settings = isset($options['automate']['app_management']['size_violation']) ? 
                  $options['automate']['app_management']['size_violation'] : array();
        $delete_immediately = !empty($settings['delete_immediately']);
        $send_warning = !empty($settings['send_warning']) && !$delete_immediately;
        
        // Build query to find apps that exceed their subscription size limit
        $conditions = array();
        
        foreach ($size_limits as $subscription_id => $size_limit) {
            if ($size_limit > 0) {
                // Size limit is in MB, convert to bytes (size_on_disk is stored in bytes)
                $size_limit_bytes = $size_limit * 1024 * 1024;
                $conditions[] = "(sa.subscription_id = $subscription_id AND app.size_on_disk > $size_limit_bytes)";
            }
        }
        
        if (empty($conditions)) {
            return array();
        }
        
        // Base query
        $query = "SELECT app.app_id, app.name, app.size_on_disk, sa.subscription_id, app.admin_id,
                ROUND(app.size_on_disk / 1048576, 2) as size_mb,
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as email
                FROM application app
                JOIN subscription_application sa ON app.app_id = sa.app_id
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.is_active = 1
                AND (" . implode(' OR ', $conditions) . ")";
        
        // If warnings are enabled, filter to only get apps ready for deletion
        if ($send_warning) {
            $warned_transient = get_transient('swsib_warned_size_violation_apps');
            $warned_data = $warned_transient ? $warned_transient : array();
            
            // Filter for warned apps where warning period has expired
            $ready_for_deletion = array();
            $current_time = time();
            
            foreach ($warned_data as $app_id => $warning_info) {
                if (isset($warning_info['expires']) && $current_time >= $warning_info['expires']) {
                    $ready_for_deletion[] = $app_id;
                }
            }
            
            if (!empty($ready_for_deletion)) {
                $warned_ids = implode(',', $ready_for_deletion);
                $query .= " AND app.app_id IN ($warned_ids)";
            } else {
                // No apps ready for deletion
                return array();
            }
        }
        
        $query .= " ORDER BY app.size_on_disk DESC";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get size violation apps: " . $this->db_connection->error);
            return array();
        }
        
        $apps = array();
        while ($row = $result->fetch_assoc()) {
            $apps[] = $row;
        }
        
        return $apps;
    }
    
    /**
     * Get size violation apps for processing - New method to get all violating apps
     * This is used for processing (sending warnings, etc.), not just for display
     */
    public function get_size_violation_apps_for_processing() {
        if (!$this->db_connection) {
            return array();
        }
        
        // Get size limit settings
        $options = get_option('swsib_options', array());
        $size_limits = isset($options['automate']['subscription_size_limits']) ? $options['automate']['subscription_size_limits'] : array();
        
        if (empty($size_limits)) {
            return array();
        }
        
        // Build query to find ALL apps that exceed their subscription size limit
        $conditions = array();
        
        foreach ($size_limits as $subscription_id => $size_limit) {
            if ($size_limit > 0) {
                // Size limit is in MB, convert to bytes (size_on_disk is stored in bytes)
                $size_limit_bytes = $size_limit * 1024 * 1024;
                $conditions[] = "(sa.subscription_id = $subscription_id AND app.size_on_disk > $size_limit_bytes)";
            }
        }
        
        if (empty($conditions)) {
            return array();
        }
        
        // Get ALL apps that violate size limits, regardless of warning status
        $query = "SELECT app.app_id, app.name, app.size_on_disk, sa.subscription_id, app.admin_id,
                ROUND(app.size_on_disk / 1048576, 2) as size_mb,
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as email
                FROM application app
                JOIN subscription_application sa ON app.app_id = sa.app_id
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.is_active = 1
                AND (" . implode(' OR ', $conditions) . ")
                ORDER BY app.size_on_disk DESC";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get size violation apps for processing: " . $this->db_connection->error);
            return array();
        }
        
        $apps = array();
        while ($row = $result->fetch_assoc()) {
            $apps[] = $row;
        }
        
        return $apps;
    }
    
    /**
     * Get apps without users
     */
    public function get_apps_without_users() {
        if (!$this->db_connection) {
            return array();
        }
        
        $query = "SELECT app.app_id, app.name, app.created_at, app.admin_id,
                ROUND(IFNULL(app.size_on_disk, 0) / 1048576, 2) as size_mb
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE adm.admin_id IS NULL OR app.admin_id IS NULL
                ORDER BY app.app_id ASC";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get apps without users: " . $this->db_connection->error);
            return array();
        }
        
        $apps = array();
        while ($row = $result->fetch_assoc()) {
            $apps[] = $row;
        }
        
        return $apps;
    }
    
    /**
     * Get subscription plans
     */
    public function get_subscription_plans() {
        if (!$this->db_connection) {
            return array();
        }
        
        $query = "SELECT subscription_id, name, regular_payment FROM subscription WHERE is_active = 1 ORDER BY regular_payment ASC";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get subscription plans: " . $this->db_connection->error);
            return array();
        }
        
        $subscriptions = array();
        while ($row = $result->fetch_assoc()) {
            $subscriptions[] = $row;
        }
        
        return $subscriptions;
    }
    
    /**
     * Record app deletion results in the database
     */
    public function record_app_deletion_results($type, $deleted, $errors, $skipped = 0) {
        if (!$this->db_connection) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $type = $this->db_connection->real_escape_string($type);
        
        // Check if the cleanup log table exists
        $table_check = $this->db_connection->query("SHOW TABLES LIKE 'swsib_cleanup_log'");
        
        if ($table_check->num_rows === 0) {
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
            
            $this->db_connection->query($create_table);
        }
        
        // Check if the items_skipped column exists
        $column_check = $this->db_connection->query("SHOW COLUMNS FROM swsib_cleanup_log LIKE 'items_skipped'");
        
        if ($column_check->num_rows === 0) {
            // Add the column if it doesn't exist
            $add_column = "ALTER TABLE swsib_cleanup_log ADD COLUMN items_skipped INT(11) DEFAULT 0";
            $this->db_connection->query($add_column);
        }
        
        // Insert cleanup results
        $query = "INSERT INTO swsib_cleanup_log (task_type, items_deleted, errors, executed_at, items_skipped)
                 VALUES ('app_management_$type', $deleted, $errors, '$timestamp', $skipped)";
        
        $this->db_connection->query($query);
    }
    
    /**
     * Get subscription size limits
     */
    public function get_subscription_size_limits() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['subscription_size_limits'])) {
            return array();
        }
        
        return $options['automate']['subscription_size_limits'];
    }
    
    /**
     * Delete all related data for an app
     */
    public function delete_app_data($app_id) {
        if (!$this->db_connection) {
            throw new Exception("Database connection not available");
        }
        
        // Find tables with app_id column
        $tables_query = "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE COLUMN_NAME = 'app_id' 
                      AND TABLE_SCHEMA = '{$this->db_name}'";
        
        $tables_result = $this->db_connection->query($tables_query);
        
        if (!$tables_result) {
            throw new Exception("Failed to get tables with app_id column: " . $this->db_connection->error);
        }
        
        // Delete from each table that has an app_id column
        while ($table_row = $tables_result->fetch_assoc()) {
            $table_name = $table_row['TABLE_NAME'];
            
            // Skip application table as we'll delete from it separately
            if ($table_name === 'application') {
                continue;
            }
            
            $delete_query = "DELETE FROM {$table_name} WHERE app_id = $app_id";
            $this->db_connection->query($delete_query);
        }
    }
    
    /**
     * AJAX handler for previewing app data
     */
    public function ajax_preview_app_data() {
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
        if ($data_type === 'zero_size') {
            $result = $this->preview_zero_size_apps($page);
        } elseif ($data_type === 'inactive') {
            $result = $this->preview_inactive_apps($page);
        } elseif ($data_type === 'size_violation') {
            $result = $this->preview_size_violation_apps($page);
        } elseif ($data_type === 'no_users') {
            $result = $this->preview_apps_without_users($page);
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
     * Preview zero size apps
     */
    public function preview_zero_size_apps($page = 1, $per_page = 10) {
        if (!$this->db_connection) {
            return array(
                'success' => false,
                'message' => 'Database connection not available'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count FROM application WHERE size_on_disk = 0 OR size_on_disk IS NULL";
        $result = $this->db_connection->query($count_query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count zero size apps: ' . $this->db_connection->error
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get zero size apps with pagination
        $query = "SELECT app.app_id, app.name, app.created_at, app.admin_id, 
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as owner_email
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.size_on_disk = 0 OR app.size_on_disk IS NULL
                ORDER BY app.app_id ASC
                LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch zero size apps: ' . $this->db_connection->error
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
                'title' => 'Zero Size Applications',
                'headers' => array('App ID', 'Name', 'Created At', 'Admin ID', 'Owner Name', 'Owner Email'),
                'fields' => array('app_id', 'name', 'created_at', 'admin_id', 'owner_name', 'owner_email'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    /**
     * Preview inactive apps
     */
    public function preview_inactive_apps($page = 1, $per_page = 10) {
        if (!$this->db_connection) {
            return array(
                'success' => false,
                'message' => 'Database connection not available'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count - show all inactive apps regardless of warning status
        $count_query = "SELECT COUNT(*) as count FROM application WHERE is_active = 0";
        $result = $this->db_connection->query($count_query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count inactive apps: ' . $this->db_connection->error
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get inactive apps with pagination - show all
        $query = "SELECT app.app_id, app.name, app.created_at, app.updated_at, app.admin_id, 
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as owner_email
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.is_active = 0
                ORDER BY app.app_id ASC
                LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch inactive apps: ' . $this->db_connection->error
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
                'title' => 'Deleted Applications',
                'headers' => array('App ID', 'Name', 'Created At', 'Updated At', 'Admin ID', 'Owner Name', 'Owner Email'),
                'fields' => array('app_id', 'name', 'created_at', 'updated_at', 'admin_id', 'owner_name', 'owner_email'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    /**
     * Preview size violation apps - Updated to show all violating apps
     */
    public function preview_size_violation_apps($page = 1, $per_page = 10) {
        if (!$this->db_connection) {
            return array(
                'success' => false,
                'message' => 'Database connection not available'
            );
        }
        
        // Get size limit settings
        $options = get_option('swsib_options', array());
        $size_limits = isset($options['automate']['subscription_size_limits']) ? $options['automate']['subscription_size_limits'] : array();
        
        if (empty($size_limits)) {
            return array(
                'success' => true,
                'data' => array(
                    'title' => 'Size Violation Applications',
                    'headers' => array('App ID', 'Name', 'Size (MB)', 'Size Limit (MB)', 'Subscription ID', 'Owner Name', 'Owner Email'),
                    'fields' => array('app_id', 'name', 'size_mb', 'size_limit_mb', 'subscription_id', 'owner_name', 'owner_email'),
                    'items' => array(),
                    'total' => 0,
                    'total_pages' => 1,
                    'current_page' => 1
                )
            );
        }
        
        // Build query to find ALL apps that exceed their subscription size limit
        $conditions = array();
        
        foreach ($size_limits as $subscription_id => $size_limit) {
            if ($size_limit > 0) {
                // Size limit is in MB, convert to bytes (size_on_disk is stored in bytes)
                $size_limit_bytes = $size_limit * 1024 * 1024;
                $conditions[] = "(sa.subscription_id = $subscription_id AND app.size_on_disk > $size_limit_bytes)";
            }
        }
        
        if (empty($conditions)) {
            return array(
                'success' => true,
                'data' => array(
                    'title' => 'Size Violation Applications',
                    'headers' => array('App ID', 'Name', 'Size (MB)', 'Size Limit (MB)', 'Subscription ID', 'Owner Name', 'Owner Email'),
                    'fields' => array('app_id', 'name', 'size_mb', 'size_limit_mb', 'subscription_id', 'owner_name', 'owner_email'),
                    'items' => array(),
                    'total' => 0,
                    'total_pages' => 1,
                    'current_page' => 1
                )
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count - show ALL violating apps
        $count_query = "SELECT COUNT(*) AS count
                        FROM application app 
                        JOIN subscription_application sa ON app.app_id = sa.app_id 
                        WHERE app.is_active = 1 
                        AND (" . implode(' OR ', $conditions) . ")";
        
        $result = $this->db_connection->query($count_query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count size violation apps: ' . $this->db_connection->error
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get ALL size violation apps with pagination
        $query = "SELECT app.app_id, app.name, app.size_on_disk, sa.subscription_id, app.admin_id,
                ROUND(app.size_on_disk / 1048576, 2) as size_mb,
                IFNULL(CONCAT(adm.firstname, ' ', adm.lastname), 'No Owner') as owner_name,
                IFNULL(adm.email, 'unknown') as owner_email
                FROM application app
                JOIN subscription_application sa ON app.app_id = sa.app_id
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE app.is_active = 1
                AND (" . implode(' OR ', $conditions) . ")
                ORDER BY app.size_on_disk DESC
                LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch size violation apps: ' . $this->db_connection->error
            );
        }
        
        // Format data for display
        $items = array();
        while ($row = $result->fetch_assoc()) {
            // Add size limit to each row
            $subscription_id = $row['subscription_id'];
            $row['size_limit_mb'] = isset($size_limits[$subscription_id]) ? $size_limits[$subscription_id] : 'Not set';
            
            $items[] = $row;
        }
        
        return array(
            'success' => true,
            'data' => array(
                'title' => 'Size Violation Applications',
                'headers' => array('App ID', 'Name', 'Size (MB)', 'Size Limit (MB)', 'Subscription ID', 'Owner Name', 'Owner Email'),
                'fields' => array('app_id', 'name', 'size_mb', 'size_limit_mb', 'subscription_id', 'owner_name', 'owner_email'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
    
    /**
     * Preview apps without users
     */
    public function preview_apps_without_users($page = 1, $per_page = 10) {
        if (!$this->db_connection) {
            return array(
                'success' => false,
                'message' => 'Database connection not available'
            );
        }
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get total count
        $count_query = "SELECT COUNT(*) as count 
                        FROM application app 
                        LEFT JOIN admin adm ON app.admin_id = adm.admin_id 
                        WHERE adm.admin_id IS NULL OR app.admin_id IS NULL";
        
        $result = $this->db_connection->query($count_query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to count apps without users: ' . $this->db_connection->error
            );
        }
        
        $row = $result->fetch_assoc();
        $total = intval($row['count']);
        $total_pages = ceil($total / $per_page);
        
        // Get apps without users with pagination
        $query = "SELECT app.app_id, app.name, app.created_at, app.admin_id,
                ROUND(IFNULL(app.size_on_disk, 0) / 1048576, 2) as size_mb
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id
                WHERE adm.admin_id IS NULL OR app.admin_id IS NULL
                ORDER BY app.app_id ASC
                LIMIT {$per_page} OFFSET {$offset}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            return array(
                'success' => false,
                'message' => 'Failed to fetch apps without users: ' . $this->db_connection->error
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
                'title' => 'Applications Without Users',
                'headers' => array('App ID', 'Name', 'Created At', 'Admin ID', 'Size (MB)'),
                'fields' => array('app_id', 'name', 'created_at', 'admin_id', 'size_mb'),
                'items' => $items,
                'total' => $total,
                'total_pages' => $total_pages,
                'current_page' => $page
            )
        );
    }
}