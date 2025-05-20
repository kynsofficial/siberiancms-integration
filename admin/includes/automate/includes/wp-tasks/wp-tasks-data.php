<?php
/**
 * WordPress Tasks - Data handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_WP_Data {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
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
     * AJAX handler for getting spam users count
     */
    public function ajax_get_spam_users_count() {
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
        
        $count = $this->count_spam_users();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * AJAX handler for getting unsynced users count
     */
    public function ajax_get_unsynced_users_count() {
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
        
        $count = $this->count_unsynced_users();
        
        wp_send_json_success(array('count' => $count));
    }
    
    /**
     * Count spam users
     */
    public function count_spam_users() {
        global $wpdb;
        
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['wp_tasks']['spam_users']) ? $options['automate']['wp_tasks']['spam_users'] : array();
        $aggressive_mode = !empty($settings['aggressive_mode']);
        
        if ($aggressive_mode) {
            // More extensive pattern matching in aggressive mode
            $query = "
                SELECT COUNT(ID) FROM {$wpdb->users}
                WHERE display_name REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
                OR user_nicename REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
                OR user_login REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
            ";
        } else {
            // Basic pattern matching in normal mode
            $query = "
                SELECT COUNT(ID) FROM {$wpdb->users}
                WHERE display_name LIKE '%http%'
                OR display_name LIKE '%www.%'
                OR display_name LIKE '%[url=%'
                OR display_name LIKE '%<a%'
                OR user_nicename LIKE '%http%'
                OR user_login LIKE '%http%'
            ";
        }
        
        $count = $wpdb->get_var($query);
        
        return intval($count);
    }
    
    /**
     * Count unsynced users - users in Siberian not in WordPress
     */
    public function count_unsynced_users() {
        if (!$this->db_connection) {
            return 0;
        }
        
        global $wpdb;
        
        // Get all WordPress user emails
        $wp_emails = $wpdb->get_col("SELECT user_email FROM {$wpdb->users}");
        
        if (empty($wp_emails)) {
            return 0;
        }
        
        // Escape emails for SQL
        $wp_emails_escaped = array_map(array($this->db_connection, 'real_escape_string'), $wp_emails);
        $wp_emails_list = "'" . implode("','", $wp_emails_escaped) . "'";
        
        // Get count of Siberian users that don't exist in WordPress
        $query = "SELECT COUNT(*) FROM admin WHERE email NOT IN ({$wp_emails_list})";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Database query failed: " . $this->db_connection->error);
            return 0;
        }
        
        $row = $result->fetch_row();
        return intval($row[0]);
    }
    
    /**
     * Count WordPress users not in Siberian
     */
    public function count_wp_users_not_in_siberian() {
        if (!$this->db_connection) {
            return 0;
        }
        
        global $wpdb;
        
        // Get all Siberian user emails
        $query = "SELECT email FROM admin";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Database query failed: " . $this->db_connection->error);
            return 0;
        }
        
        $siberian_emails = array();
        while ($row = $result->fetch_assoc()) {
            $siberian_emails[] = strtolower($row['email']);
        }
        
        if (empty($siberian_emails)) {
            return 0;
        }
        
        // Get all WordPress users except admin (ID 1)
        $wp_users = $wpdb->get_results("SELECT ID, user_email FROM {$wpdb->users} WHERE ID > 1");
        
        if (empty($wp_users)) {
            return 0;
        }
        
        // Get settings
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['wp_tasks']['unsynced_users']) ? 
                  $options['automate']['wp_tasks']['unsynced_users'] : array();
        $excluded_roles = isset($settings['excluded_roles']) ? $settings['excluded_roles'] : array();
        $excluded_meta_keys = isset($settings['excluded_meta_keys']) ? $settings['excluded_meta_keys'] : array();
        $excluded_meta_values = isset($settings['excluded_meta_values']) ? $settings['excluded_meta_values'] : array();
        
        // Count users not in Siberian, respecting exclusions
        $count = 0;
        foreach ($wp_users as $user) {
            if (!in_array(strtolower($user->user_email), $siberian_emails)) {
                $wp_user = new WP_User($user->ID);
                
                // Skip if user has an excluded role
                $has_excluded_role = false;
                foreach ($excluded_roles as $role) {
                    if (in_array($role, (array)$wp_user->roles)) {
                        $has_excluded_role = true;
                        break;
                    }
                }
                
                if ($has_excluded_role) {
                    continue;
                }
                
                // Skip if user has excluded meta
                $has_excluded_meta = false;
                for ($i = 0; $i < count($excluded_meta_keys); $i++) {
                    $meta_key = $excluded_meta_keys[$i];
                    $meta_value = isset($excluded_meta_values[$i]) ? $excluded_meta_values[$i] : '';
                    
                    if (empty($meta_key)) {
                        continue;
                    }
                    
                    $user_meta_value = get_user_meta($user->ID, $meta_key, true);
                    
                    if ($meta_value === '' && !empty($user_meta_value)) {
                        $has_excluded_meta = true;
                        break;
                    } else if ($meta_value !== '' && $user_meta_value === $meta_value) {
                        $has_excluded_meta = true;
                        break;
                    }
                }
                
                if (!$has_excluded_meta) {
                    $count++;
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Get all Siberian users that don't have WordPress accounts
     */
    public function get_siberian_users_without_wp_accounts() {
        if (!$this->db_connection) {
            return array();
        }
        
        global $wpdb;
        
        // Get all WordPress user emails
        $wp_emails = $wpdb->get_col("SELECT LOWER(user_email) FROM {$wpdb->users}");
        
        if (empty($wp_emails)) {
            $wp_emails = array('admin@example.com'); // Dummy email to prevent empty IN clause
        }
        
        // Escape emails for SQL
        $wp_emails_escaped = array_map(array($this->db_connection, 'real_escape_string'), $wp_emails);
        $wp_emails_list = "'" . implode("','", $wp_emails_escaped) . "'";
        
        // Get Siberian users that don't exist in WordPress
        $query = "SELECT admin_id, email, firstname, lastname FROM admin WHERE LOWER(email) NOT IN ({$wp_emails_list})";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Database query failed: " . $this->db_connection->error);
            return array();
        }
        
        $users = array();
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        
        return $users;
    }
    
    /**
     * Get all WordPress users that don't have Siberian accounts
     */
    public function get_wp_users_without_siberian_accounts() {
        if (!$this->db_connection) {
            return array();
        }
        
        global $wpdb;
        
        // Get all Siberian user emails
        $query = "SELECT LOWER(email) AS email FROM admin";
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Database query failed: " . $this->db_connection->error);
            return array();
        }
        
        $siberian_emails = array();
        while ($row = $result->fetch_assoc()) {
            $siberian_emails[] = $row['email'];
        }
        
        if (empty($siberian_emails)) {
            return array();
        }
        
        // Get all WordPress users except admin (ID 1)
        $wp_users = $wpdb->get_results("SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE ID > 1");
        
        if (empty($wp_users)) {
            return array();
        }
        
        // Get users not in Siberian, respecting exclusions
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['wp_tasks']['unsynced_users']) ? 
                  $options['automate']['wp_tasks']['unsynced_users'] : array();
        $excluded_roles = isset($settings['excluded_roles']) ? $settings['excluded_roles'] : array();
        $excluded_meta_keys = isset($settings['excluded_meta_keys']) ? $settings['excluded_meta_keys'] : array();
        $excluded_meta_values = isset($settings['excluded_meta_values']) ? $settings['excluded_meta_values'] : array();
        
        $users_to_delete = array();
        foreach ($wp_users as $user) {
            if (!in_array(strtolower($user->user_email), $siberian_emails)) {
                $wp_user = new WP_User($user->ID);
                
                // Skip if user has an excluded role
                $has_excluded_role = false;
                foreach ($excluded_roles as $role) {
                    if (in_array($role, (array)$wp_user->roles)) {
                        $has_excluded_role = true;
                        break;
                    }
                }
                
                if ($has_excluded_role) {
                    continue;
                }
                
                // Skip if user has excluded meta
                $has_excluded_meta = false;
                for ($i = 0; $i < count($excluded_meta_keys); $i++) {
                    $meta_key = $excluded_meta_keys[$i];
                    $meta_value = isset($excluded_meta_values[$i]) ? $excluded_meta_values[$i] : '';
                    
                    if (empty($meta_key)) {
                        continue;
                    }
                    
                    $user_meta_value = get_user_meta($user->ID, $meta_key, true);
                    
                    if ($meta_value === '' && !empty($user_meta_value)) {
                        $has_excluded_meta = true;
                        break;
                    } else if ($meta_value !== '' && $user_meta_value === $meta_value) {
                        $has_excluded_meta = true;
                        break;
                    }
                }
                
                if (!$has_excluded_meta) {
                    $users_to_delete[] = $user;
                }
            }
        }
        
        return $users_to_delete;
    }

/**
 * Preview spam WordPress users for the UI

 */
public function preview_spam_wordpress_users($page = 1, $per_page = 10) {
    global $wpdb;
    
    $options = get_option('swsib_options', array());
    $settings = isset($options['automate']['wp_tasks']['spam_users']) ? $options['automate']['wp_tasks']['spam_users'] : array();
    $aggressive_mode = !empty($settings['aggressive_mode']);
    
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    if ($aggressive_mode) {
        // More extensive pattern matching in aggressive mode
        $count_query = "
            SELECT COUNT(ID) FROM {$wpdb->users}
            WHERE display_name REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
            OR user_nicename REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
            OR user_login REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
        ";
        
        $query = "
            SELECT ID, user_login, user_email, display_name, user_url, user_registered FROM {$wpdb->users}
            WHERE display_name REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
            OR user_nicename REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
            OR user_login REGEXP '(http|https|www\\.|\\[url=|\\<a|\\???|???|?????|????|pozdravlyaem|????)'
            ORDER BY user_registered DESC
            LIMIT %d OFFSET %d
        ";
    } else {
        // Basic pattern matching in normal mode
        $count_query = "
            SELECT COUNT(ID) FROM {$wpdb->users}
            WHERE display_name LIKE '%http%'
            OR display_name LIKE '%www.%'
            OR display_name LIKE '%[url=%'
            OR display_name LIKE '%<a%'
            OR user_nicename LIKE '%http%'
            OR user_login LIKE '%http%'
        ";
        
        $query = "
            SELECT ID, user_login, user_email, display_name, user_url, user_registered FROM {$wpdb->users}
            WHERE display_name LIKE '%http%'
            OR display_name LIKE '%www.%'
            OR display_name LIKE '%[url=%'
            OR display_name LIKE '%<a%'
            OR user_nicename LIKE '%http%'
            OR user_login LIKE '%http%'
            ORDER BY user_registered DESC
            LIMIT %d OFFSET %d
        ";
    }
    
    // Get total count
    $total = intval($wpdb->get_var($count_query));
    $total_pages = ceil($total / $per_page);
    
    // Get paginated results
    $items = $wpdb->get_results($wpdb->prepare($query, $per_page, $offset));
    
    if ($items === false) {
        return array(
            'success' => false,
            'message' => 'Database query failed: ' . $wpdb->last_error
        );
    }
    
    // Format data for display
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'id' => $item->ID,
            'username' => $item->user_login,
            'email' => $item->user_email,
            'display_name' => $item->display_name,
            'url' => $item->user_url,
            'registered' => $item->user_registered
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'title' => 'Spam WordPress Users',
            'headers' => array('ID', 'Username', 'Email', 'Display Name', 'URL', 'Registered'),
            'fields' => array('id', 'username', 'email', 'display_name', 'url', 'registered'),
            'items' => $formatted_items,
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page
        )
    );
}

/**
 * Preview users in Siberian but not in WordPress
 * 
 */
public function preview_siberian_users_without_wp($page = 1, $per_page = 10) {
    if (!$this->db_connection) {
        return array(
            'success' => false,
            'message' => 'Database connection not configured'
        );
    }
    
    // Get all Siberian users that don't have WordPress accounts
    $all_users = $this->get_siberian_users_without_wp_accounts();
    
    // Get total count
    $total = count($all_users);
    $total_pages = ceil($total / $per_page);
    
    // Get paginated results
    $offset = ($page - 1) * $per_page;
    $items = array_slice($all_users, $offset, $per_page);
    
    // Format data for display
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'id' => $item['admin_id'],
            'email' => $item['email'],
            'firstname' => $item['firstname'] ?? '',
            'lastname' => $item['lastname'] ?? '',
            'full_name' => trim(($item['firstname'] ?? '') . ' ' . ($item['lastname'] ?? '')),
            'action' => 'Will create WordPress account'
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'title' => 'Siberian Users Without WordPress Accounts',
            'headers' => array('ID', 'Email', 'First Name', 'Last Name', 'Full Name', 'Action'),
            'fields' => array('id', 'email', 'firstname', 'lastname', 'full_name', 'action'),
            'items' => $formatted_items,
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page
        )
    );
}

/**
 * Preview WordPress users not in Siberian
 * 
 * @param int $page Page number
 * @param int $per_page Items per page
 * @return array Result array with success flag and data
 */
public function preview_wp_users_without_siberian($page = 1, $per_page = 10) {
    if (!$this->db_connection) {
        return array(
            'success' => false,
            'message' => 'Database connection not configured'
        );
    }
    
    // Get all WordPress users that don't have Siberian accounts
    $all_users = $this->get_wp_users_without_siberian_accounts();
    
    // Get total count
    $total = count($all_users);
    $total_pages = ceil($total / $per_page);
    
    // Get paginated results
    $offset = ($page - 1) * $per_page;
    $items = array_slice($all_users, $offset, $per_page);
    
    // Format data for display
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'id' => $item->ID,
            'username' => $item->user_login,
            'email' => $item->user_email,
            'action' => 'Will be deleted if deletion is enabled'
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'title' => 'WordPress Users Without Siberian Accounts',
            'headers' => array('ID', 'Username', 'Email', 'Action'),
            'fields' => array('id', 'username', 'email', 'action'),
            'items' => $formatted_items,
            'total' => $total,
            'total_pages' => $total_pages,
            'current_page' => $page
        )
    );
}

/**
 * AJAX handler for previewing WordPress tasks data
 */
public function ajax_preview_wp_tasks_data() {
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
    if ($data_type === 'spam_users') {
        $result = $this->preview_spam_wordpress_users($page);
    } elseif ($data_type === 'siberian_users_without_wp') {
        $result = $this->preview_siberian_users_without_wp($page);
    } elseif ($data_type === 'wp_users_without_siberian') {
        $result = $this->preview_wp_users_without_siberian($page);
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
     * Get a list of spam WordPress users
     */
    public function get_spam_wordpress_users() {
        global $wpdb;
        
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['wp_tasks']['spam_users']) ? $options['automate']['wp_tasks']['spam_users'] : array();
        $aggressive_mode = !empty($settings['aggressive_mode']);
        
        if ($aggressive_mode) {
            // More extensive pattern matching in aggressive mode
            $query = "
                SELECT ID, user_login, user_email, display_name FROM {$wpdb->users}
                WHERE display_name REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
                OR user_nicename REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
                OR user_login REGEXP '(http|https|www\\.|\\[url=|\\<a|\\??????|??????|???????? ???????|pozdravlyaem|????????)'
            ";
        } else {
            // Basic pattern matching in normal mode
            $query = "
                SELECT ID, user_login, user_email, display_name FROM {$wpdb->users}
                WHERE display_name LIKE '%http%'
                OR display_name LIKE '%www.%'
                OR display_name LIKE '%[url=%'
                OR display_name LIKE '%<a%'
                OR user_nicename LIKE '%http%'
                OR user_login LIKE '%http%'
            ";
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Find corresponding Siberian user for a WordPress user
     */
    public function find_siberian_user_by_email($email) {
        if (!$this->db_connection) {
            return null;
        }
        
        $email = $this->db_connection->real_escape_string($email);
        $query = "SELECT admin_id FROM admin WHERE email = '{$email}'";
        $result = $this->db_connection->query($query);
        
        if (!$result || $result->num_rows === 0) {
            return null;
        }
        
        $row = $result->fetch_assoc();
        return $row['admin_id'];
    }
    
    /**
     * Delete a Siberian user and all associated data
     */
    public function delete_siberian_user($admin_id) {
        if (!$this->db_connection) {
            $this->log_message("Cannot delete Siberian user: Database connection not configured");
            return false;
        }
        
        $admin_id = intval($admin_id);
        $this->log_message("Forcefully deleting Siberian user ID {$admin_id} and all associated data");
        
        // Start transaction
        $this->db_connection->begin_transaction();
        
        try {
            // Get all apps owned by this admin
            $app_query = "SELECT app_id FROM application WHERE admin_id = {$admin_id}";
            $app_result = $this->db_connection->query($app_query);
            
            if (!$app_result) {
                throw new Exception("Failed to get apps for admin {$admin_id}: " . $this->db_connection->error);
            }
            
            $app_ids = array();
            while ($app_row = $app_result->fetch_assoc()) {
                $app_ids[] = $app_row['app_id'];
            }
            
            $this->log_message("Found " . count($app_ids) . " apps for Siberian user ID {$admin_id}");
            
            // Direct deletion of all application-related tables for this admin
            $delete_queries = [
                // Delete from admin user tables
                "DELETE FROM application_admin WHERE admin_id = {$admin_id}",
                "DELETE FROM admin_tokens WHERE admin_id = {$admin_id}",
                "DELETE FROM admin_log WHERE admin_id = {$admin_id}",
                "DELETE FROM admin_device WHERE admin_id = {$admin_id}",
            ];
            
            // Execute direct admin table deletions
            foreach ($delete_queries as $query) {
                $this->db_connection->query($query);
            }
            
            // For each app, delete related data
            if (!empty($app_ids)) {
                $app_ids_string = implode(',', $app_ids);
                
                // First get all tables with app_id column
                $tables_query = "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                WHERE COLUMN_NAME = 'app_id' 
                                AND TABLE_SCHEMA = '{$this->db_name}'";
                
                $tables_result = $this->db_connection->query($tables_query);
                
                if (!$tables_result) {
                    throw new Exception("Failed to get tables with app_id column: " . $this->db_connection->error);
                }
                
                $app_tables = [];
                while ($table_row = $tables_result->fetch_assoc()) {
                    $app_tables[] = $table_row['TABLE_NAME'];
                }
                
                // Delete from each app-related table (except 'application' which we'll do last)
                foreach ($app_tables as $table) {
                    if ($table === 'application') continue;
                    
                    $delete_query = "DELETE FROM {$table} WHERE app_id IN ({$app_ids_string})";
                    $this->db_connection->query($delete_query);
                    
                    // Log any errors
                    if ($this->db_connection->error) {
                        $this->log_message("Error deleting from {$table}: " . $this->db_connection->error);
                    }
                }
                
                // Finally delete from application table
                $delete_app_query = "DELETE FROM application WHERE app_id IN ({$app_ids_string})";
                $result = $this->db_connection->query($delete_app_query);
                
                if (!$result) {
                    throw new Exception("Failed to delete applications: " . $this->db_connection->error);
                }
            }
            
            // Finally, delete the admin record itself
            $admin_query = "DELETE FROM admin WHERE admin_id = {$admin_id}";
            if (!$this->db_connection->query($admin_query)) {
                throw new Exception("Failed to delete admin {$admin_id}: " . $this->db_connection->error);
            }
            
            // Commit the transaction
            $this->db_connection->commit();
            
            $this->log_message("Successfully deleted Siberian user ID {$admin_id} and all related data");
            
            return true;
        } catch (Exception $e) {
            // Rollback the transaction
            $this->db_connection->rollback();
            
            $this->log_message("Failed to delete Siberian user ID {$admin_id}: " . $e->getMessage());
            
            // Even if transaction failed, try one more direct delete
            try {
                $this->db_connection->query("DELETE FROM admin WHERE admin_id = {$admin_id}");
                $this->log_message("Forced direct delete of admin ID {$admin_id} after transaction failure");
            } catch (Exception $inner_e) {
                $this->log_message("Even forced direct deletion failed: " . $inner_e->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * Generate a username based on email, first name, and last name
     */
    public function generate_username($email, $firstname, $lastname) {
        // Try email username part first
        $username = strstr($email, '@', true);
        
        if (username_exists($username)) {
            // Try first name + last name
            $username = sanitize_user(strtolower($firstname . $lastname), true);
            
            if (username_exists($username)) {
                // Try first name + last initial
                $username = sanitize_user(strtolower($firstname . substr($lastname, 0, 1)), true);
                
                if (username_exists($username)) {
                    // Add random number suffix until we find an available username
                    do {
                        $username = sanitize_user(strtolower($firstname . substr($lastname, 0, 1)) . rand(1, 999), true);
                    } while (username_exists($username));
                }
            }
        }
        
        return $username;
    }
}