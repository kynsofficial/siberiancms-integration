<?php
/**
 * SiberianCMS Database Clean-up Tools
 * 
 * Provides tools to clean and maintain SiberianCMS database
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Clean {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Database connection instance
     */
    private $db_connection = null;
    
    /**
     * Connected database name
     */
    private $connected_db = null;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Initialize the database connection
        $this->init_db_connection();
        
        // Add AJAX handlers for the cleaning operations
        $this->register_ajax_handlers();
        
        // Load admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Write to log using the central logging manager.
     * 
     * @param string $message The log message
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('clean', 'backend', $message);
        }
    }
    
    /**
     * Initialize database connection.
     */
    private function init_db_connection() {
        if (swsib()->is_db_configured()) {
            $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
            
            if (!empty($db_options['host']) && !empty($db_options['database']) && 
                !empty($db_options['username']) && !empty($db_options['password'])) {
                
                try {
                    $this->db_connection = new mysqli(
                        $db_options['host'],
                        $db_options['username'],
                        $db_options['password'],
                        $db_options['database'],
                        isset($db_options['port']) ? intval($db_options['port']) : 3306
                    );
                    
                    if ($this->db_connection->connect_error) {
                        $this->log_message("Database connection failed: " . $this->db_connection->connect_error);
                        $this->db_connection = null;
                    } else {
                        $this->connected_db = $db_options['database'];
                    }
                } catch (Exception $e) {
                    $this->log_message("Database connection exception: " . $e->getMessage());
                    $this->db_connection = null;
                }
            }
        }
    }
    
    /**
     * Register AJAX handlers for the cleaning operations
     */
    private function register_ajax_handlers() {
        // User management AJAX handlers
        add_action('wp_ajax_swsib_get_admins', array($this, 'ajax_get_admins'));
        add_action('wp_ajax_swsib_delete_admins', array($this, 'ajax_delete_admins'));
        add_action('wp_ajax_swsib_deactivate_admins', array($this, 'ajax_deactivate_admins'));
        add_action('wp_ajax_swsib_activate_admins', array($this, 'ajax_activate_admins')); // New handler for activation
        
        // Application management AJAX handlers
        add_action('wp_ajax_swsib_get_applications', array($this, 'ajax_get_applications'));
        add_action('wp_ajax_swsib_delete_applications', array($this, 'ajax_delete_applications'));
        add_action('wp_ajax_swsib_lock_applications', array($this, 'ajax_lock_applications'));
        add_action('wp_ajax_swsib_unlock_applications', array($this, 'ajax_unlock_applications'));
        
        // Mail log AJAX handlers
        add_action('wp_ajax_swsib_get_mail_logs', array($this, 'ajax_get_mail_logs'));
        add_action('wp_ajax_swsib_delete_mail_logs', array($this, 'ajax_delete_mail_logs'));
        add_action('wp_ajax_swsib_clear_all_mail_logs', array($this, 'ajax_clear_all_mail_logs'));
        
        // Session AJAX handlers
        add_action('wp_ajax_swsib_get_sessions', array($this, 'ajax_get_sessions'));
        add_action('wp_ajax_swsib_delete_sessions', array($this, 'ajax_delete_sessions'));
        add_action('wp_ajax_swsib_clear_all_sessions', array($this, 'ajax_clear_all_sessions'));
        
        // Source Queue AJAX handlers
        add_action('wp_ajax_swsib_get_source_queue', array($this, 'ajax_get_source_queue'));
        add_action('wp_ajax_swsib_delete_source_queue', array($this, 'ajax_delete_source_queue'));
        add_action('wp_ajax_swsib_clear_all_source_queue', array($this, 'ajax_clear_all_source_queue'));

        // Add progress monitor for long operations
        add_action('wp_ajax_swsib_get_deletion_progress', array($this, 'ajax_get_deletion_progress'));
    }
    
    /**
     * Enqueue scripts and styles for the clean tab.
     * 
     * @param string $hook The current admin page hook
     */
    public function enqueue_scripts($hook) {
        // Only load on plugin admin page
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        
        wp_enqueue_style(
            'swsib-clean-css', 
            SWSIB_PLUGIN_URL . 'admin/includes/clean/includes/clean.css',
            array(),
            SWSIB_VERSION
        );
        
        wp_enqueue_script(
            'swsib-clean-js',
            SWSIB_PLUGIN_URL . 'admin/includes/clean/includes/clean.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );
        
        wp_localize_script(
            'swsib-clean-js',
            'swsib_clean',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('swsib-clean-nonce'),
                'confirm_delete_admins' => __('Are you sure you want to delete these admins? This will also delete all their applications and related data!', 'swiftspeed-siberian'),
                'confirm_deactivate_admins' => __('Are you sure you want to deactivate these admins?', 'swiftspeed-siberian'),
                'confirm_activate_admins' => __('Are you sure you want to activate these admins?', 'swiftspeed-siberian'),
                'confirm_delete_apps' => __('Are you sure you want to delete these applications? This cannot be undone!', 'swiftspeed-siberian'),
                'confirm_lock_apps' => __('Are you sure you want to lock these applications?', 'swiftspeed-siberian'),
                'confirm_unlock_apps' => __('Are you sure you want to unlock these applications?', 'swiftspeed-siberian'),
                'confirm_delete_mail_logs' => __('Are you sure you want to delete these mail logs?', 'swiftspeed-siberian'),
                'confirm_clear_all_mail_logs' => __('Are you sure you want to clear ALL mail logs? This cannot be undone!', 'swiftspeed-siberian'),
                'confirm_delete_sessions' => __('Are you sure you want to delete these sessions?', 'swiftspeed-siberian'),
                'confirm_clear_all_sessions' => __('Are you sure you want to clear ALL sessions? This will log out all users!', 'swiftspeed-siberian'),
                'confirm_delete_source_queue' => __('Are you sure you want to delete these source queue items?', 'swiftspeed-siberian'),
                'confirm_clear_all_source_queue' => __('Are you sure you want to clear ALL source queue items? This cannot be undone!', 'swiftspeed-siberian'),
                'error_no_selection' => __('Please select at least one item first.', 'swiftspeed-siberian'),
                'deleting' => __('Deleting...', 'swiftspeed-siberian'),
                'processing' => __('Processing...', 'swiftspeed-siberian'),
                'success' => __('Operation completed successfully.', 'swiftspeed-siberian'),
                'error' => __('An error occurred. Please try again.', 'swiftspeed-siberian'),
                'db_error' => __('Database error: ', 'swiftspeed-siberian')
            )
        );
    }
    
    /**
     * Display the Clean settings and tabs.
     */
    public function display_settings() {
        // First check if the database connection is active
        if (!$this->db_connection) {
            echo '<div class="swsib-notice error">';
            echo '<p>' . __('Database connection failed or not configured. Please check your DB Connect settings.', 'swiftspeed-siberian') . '</p>';
            echo '</div>';
            return;
        }
        
        // Display tabs menu and content
        ?>
        <h2><?php _e('SiberianCMS Database Clean-up Tools', 'swiftspeed-siberian'); ?></h2>
        
    <!-- Backup reminder banner -->
    <div class="backup-first-container">
        <div class="backup-first-icon">&#9888;</div>
        <div class="backup-first-message">
            <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
            <?php _e('It is strongly recommended to backup your database before performing any deletion operations.', 'swiftspeed-siberian'); ?>
        </div>
        <button type="button" class="backup-first-button">
            <?php _e('Backup First', 'swiftspeed-siberian'); ?>
        </button>
    </div>
        
        <div class="swsib-clean-container">
            <!-- Tabs navigation -->
            <div class="swsib-clean-tabs">
                <ul>
                    <li><a href="#users-tab" class="active"><?php _e('Users', 'swiftspeed-siberian'); ?></a></li>
                    <li><a href="#applications-tab"><?php _e('Applications', 'swiftspeed-siberian'); ?></a></li>
                    <li><a href="#mail-log-tab"><?php _e('Mail Logs', 'swiftspeed-siberian'); ?></a></li>
                    <li><a href="#sessions-tab"><?php _e('Sessions', 'swiftspeed-siberian'); ?></a></li>
                    <li><a href="#source-queue-tab"><?php _e('Source Queue', 'swiftspeed-siberian'); ?></a></li>
                    <li><a href="#folder-cleanup-tab"><?php _e('Folder Cleanup', 'swiftspeed-siberian'); ?></a></li>
                </ul>
            </div>
            
            <!-- Tab content -->
            <div class="swsib-clean-tab-content">
                <!-- Users tab -->
                <div id="users-tab" class="swsib-clean-tab-pane active">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/users.php'); ?>
                </div>
                
                <!-- Applications tab -->
                <div id="applications-tab" class="swsib-clean-tab-pane">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/applications.php'); ?>
                </div>
                
                <!-- Mail Log tab -->
                <div id="mail-log-tab" class="swsib-clean-tab-pane">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/mail-log.php'); ?>
                </div>
                
                <!-- Sessions tab -->
                <div id="sessions-tab" class="swsib-clean-tab-pane">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/sessions.php'); ?>
                </div>
                
                <!-- Source Queue tab -->
                <div id="source-queue-tab" class="swsib-clean-tab-pane">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/source-queue.php'); ?>
                </div>
                
                <!-- Folder Cleanup tab -->
                <div id="folder-cleanup-tab" class="swsib-clean-tab-pane">
                    <?php require_once(SWSIB_PLUGIN_DIR . 'admin/includes/clean/includes/folder-cleanup.php'); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for getting deletion progress
     */
    public function ajax_get_deletion_progress() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $operation_type = isset($_POST['operation_type']) ? sanitize_text_field($_POST['operation_type']) : '';
        
        // This would normally fetch progress information from a persistent storage
        // For demonstration, we'll just return a dummy response
        wp_send_json_success(array(
            'operation_type' => $operation_type,
            'progress' => 50, // percentage
            'logs' => array(
                array('type' => 'info', 'message' => 'Processing operation: ' . $operation_type),
                array('type' => 'success', 'message' => 'Processed batches successfully')
            )
        ));
    }
    
    /**
     * AJAX handler for getting admin users
     */
    public function ajax_get_admins() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'admin_id';
        $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'DESC';
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build search condition
        $search_condition = '';
        if (!empty($search)) {
            $search_escaped = $this->db_connection->real_escape_string($search);
            $search_condition = " WHERE admin_id LIKE '%{$search_escaped}%' 
                              OR email LIKE '%{$search_escaped}%' 
                              OR firstname LIKE '%{$search_escaped}%' 
                              OR lastname LIKE '%{$search_escaped}%'";
        }
        
        // Validate sort column
        $valid_columns = array('admin_id', 'parent_id', 'role_id', 'email', 'firstname', 'lastname', 'last_action', 'is_active', 'created_at', 'updated_at');
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = 'admin_id';
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM admin" . $search_condition;
        $count_result = $this->db_connection->query($count_query);
        
        if (!$count_result) {
            $this->log_message("Failed to get admin count: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get admin count: ' . $this->db_connection->error));
            return;
        }
        
        $count_row = $count_result->fetch_assoc();
        $total = $count_row['total'];
        
        // Get data with pagination and sorting
        $query = "SELECT admin_id, parent_id, role_id, email, firstname, lastname, 
                       last_action, is_active, created_at, updated_at 
                FROM admin" . $search_condition . " 
                ORDER BY {$sort_column} {$sort_direction} 
                LIMIT {$offset}, {$per_page}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get admins: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get admins: ' . $this->db_connection->error));
            return;
        }
        
        $admins = array();
        
        while ($row = $result->fetch_assoc()) {
            // Calculate if user is inactive (not logged in for a year)
            $last_action = strtotime($row['last_action']);
            $one_year_ago = strtotime('-1 year');
            $is_inactive = ($last_action < $one_year_ago);
            
            $admins[] = array(
                'admin_id' => $row['admin_id'],
                'parent_id' => $row['parent_id'],
                'role_id' => $row['role_id'],
                'email' => $row['email'],
                'firstname' => $row['firstname'],
                'lastname' => $row['lastname'],
                'last_action' => $row['last_action'],
                'is_active' => $row['is_active'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'is_inactive' => $is_inactive
            );
        }
        
        $result->free();
        
        // Return data
        wp_send_json_success(array(
            'admins' => $admins,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * AJAX handler for deleting admin users
     */
    public function ajax_delete_admins() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get admin IDs to delete
        $admin_ids = isset($_POST['admin_ids']) ? $_POST['admin_ids'] : array();
        
        if (empty($admin_ids) || !is_array($admin_ids)) {
            wp_send_json_error(array('message' => 'No admin IDs provided'));
            return;
        }
        
        // Start transaction
        $this->db_connection->begin_transaction();
        
        try {
            $deleted_count = 0;
            $errors = array();
            
            foreach ($admin_ids as $admin_id) {
                $admin_id = intval($admin_id);
                
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
                $app_result->free();
                
                // Delete from application_admin
                $app_admin_query = "DELETE FROM application_admin WHERE admin_id = {$admin_id}";
                if (!$this->db_connection->query($app_admin_query)) {
                    throw new Exception("Failed to delete from application_admin for admin {$admin_id}: " . $this->db_connection->error);
                }
                
                // For each app, delete related data
                foreach ($app_ids as $app_id) {
                    // Find tables with app_id column
                    $tables_query = "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE COLUMN_NAME = 'app_id' 
                                    AND TABLE_SCHEMA = '{$this->connected_db}'";
                    
                    $tables_result = $this->db_connection->query($tables_query);
                    
                    if (!$tables_result) {
                        throw new Exception("Failed to get tables with app_id column: " . $this->db_connection->error);
                    }
                    
                    // Delete from each table that has an app_id column
                    while ($table_row = $tables_result->fetch_assoc()) {
                        $table_name = $table_row['TABLE_NAME'];
                        
                        // Skip application table as we'll delete from it last
                        if ($table_name === 'application') {
                            continue;
                        }
                        
                        $delete_query = "DELETE FROM {$table_name} WHERE app_id = {$app_id}";
                        if (!$this->db_connection->query($delete_query)) {
                            $this->log_message("Failed to delete from {$table_name} for app {$app_id}: " . $this->db_connection->error);
                            // Continue with next table even if this one fails
                        }
                    }
                    $tables_result->free();
                    
                    // Delete the application itself
                    $app_query = "DELETE FROM application WHERE app_id = {$app_id}";
                    if (!$this->db_connection->query($app_query)) {
                        throw new Exception("Failed to delete application {$app_id}: " . $this->db_connection->error);
                    }
                }
                
                // Finally, delete the admin
                $admin_query = "DELETE FROM admin WHERE admin_id = {$admin_id}";
                if (!$this->db_connection->query($admin_query)) {
                    throw new Exception("Failed to delete admin {$admin_id}: " . $this->db_connection->error);
                }
                
                $deleted_count++;
                $this->log_message("Deleted admin ID {$admin_id} and all related data");
            }
            
            // Commit the transaction
            $this->db_connection->commit();
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d admin(s)', 'swiftspeed-siberian'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
            
        } catch (Exception $e) {
            // Rollback the transaction
            $this->db_connection->rollback();
            
            $this->log_message("Admin deletion error: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for deactivating admin users
     */
    public function ajax_deactivate_admins() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get admin IDs to deactivate
        $admin_ids = isset($_POST['admin_ids']) ? $_POST['admin_ids'] : array();
        
        if (empty($admin_ids) || !is_array($admin_ids)) {
            wp_send_json_error(array('message' => 'No admin IDs provided'));
            return;
        }
        
        $deactivated_count = 0;
        $errors = array();
        
        foreach ($admin_ids as $admin_id) {
            $admin_id = intval($admin_id);
            
            $query = "UPDATE admin SET is_active = 0 WHERE admin_id = {$admin_id}";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $deactivated_count++;
                $this->log_message("Deactivated admin ID {$admin_id}");
            } else {
                $errors[] = "Failed to deactivate admin ID {$admin_id}: " . $this->db_connection->error;
                $this->log_message("Failed to deactivate admin ID {$admin_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deactivated %d admin(s)', 'swiftspeed-siberian'), $deactivated_count),
                'deactivated_count' => $deactivated_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during deactivation', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for activating admin users
     */
    public function ajax_activate_admins() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get admin IDs to activate
        $admin_ids = isset($_POST['admin_ids']) ? $_POST['admin_ids'] : array();
        
        if (empty($admin_ids) || !is_array($admin_ids)) {
            wp_send_json_error(array('message' => 'No admin IDs provided'));
            return;
        }
        
        $activated_count = 0;
        $errors = array();
        
        foreach ($admin_ids as $admin_id) {
            $admin_id = intval($admin_id);
            
            $query = "UPDATE admin SET is_active = 1 WHERE admin_id = {$admin_id}";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $activated_count++;
                $this->log_message("Activated admin ID {$admin_id}");
            } else {
                $errors[] = "Failed to activate admin ID {$admin_id}: " . $this->db_connection->error;
                $this->log_message("Failed to activate admin ID {$admin_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully activated %d admin(s)', 'swiftspeed-siberian'), $activated_count),
                'activated_count' => $activated_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during activation', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for getting applications
     */
    public function ajax_get_applications() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'app_id';
        $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'DESC';
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build search condition
        $search_condition = '';
        if (!empty($search)) {
            $search_escaped = $this->db_connection->real_escape_string($search);
            $search_condition = " WHERE app.app_id LIKE '%{$search_escaped}%' 
                              OR app.name LIKE '%{$search_escaped}%' 
                              OR adm.email LIKE '%{$search_escaped}%'
                              OR app.description LIKE '%{$search_escaped}%'
                              OR app.keywords LIKE '%{$search_escaped}%'";
        }
        
        // Validate sort column
        $valid_columns = array('app_id', 'name', 'admin_id', 'is_locked', 'created_at', 'updated_at', 'description', 'keywords', 'admin_email', 'size_on_disk');
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = 'app_id';
        }
        
        // Handle sorting by admin_email which is a joined column
        $order_by = $sort_column;
        if ($sort_column === 'admin_email') {
            $order_by = 'adm.email';
        } elseif ($sort_column !== 'size_on_disk') {
            $order_by = 'app.' . $sort_column;
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total 
                      FROM application app
                      LEFT JOIN admin adm ON app.admin_id = adm.admin_id" . $search_condition;
        $count_result = $this->db_connection->query($count_query);
        
        if (!$count_result) {
            $this->log_message("Failed to get application count: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get application count: ' . $this->db_connection->error));
            return;
        }
        
        $count_row = $count_result->fetch_assoc();
        $total = $count_row['total'];
        
        // Get data with pagination and sorting
        $query = "SELECT app.app_id, app.name, app.admin_id, app.is_locked, app.created_at, 
                       app.updated_at, app.description, app.keywords, app.size_on_disk, adm.email as admin_email
                FROM application app
                LEFT JOIN admin adm ON app.admin_id = adm.admin_id" . $search_condition . "
                ORDER BY " . $order_by . " " . $sort_direction . " 
                LIMIT {$offset}, {$per_page}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get applications: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get applications: ' . $this->db_connection->error));
            return;
        }
        
        $applications = array();
        
        while ($row = $result->fetch_assoc()) {
            $applications[] = array(
                'app_id' => $row['app_id'],
                'name' => $row['name'],
                'admin_id' => $row['admin_id'],
                'admin_email' => $row['admin_email'],
                'is_locked' => $row['is_locked'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'size_on_disk' => $row['size_on_disk'],
                'description' => $row['description'],
                'keywords' => $row['keywords']
            );
        }
        
        $result->free();
        
        // Return data
        wp_send_json_success(array(
            'applications' => $applications,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * AJAX handler for deleting applications
     */
    public function ajax_delete_applications() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get application IDs to delete
        $app_ids = isset($_POST['app_ids']) ? $_POST['app_ids'] : array();
        
        if (empty($app_ids) || !is_array($app_ids)) {
            wp_send_json_error(array('message' => 'No application IDs provided'));
            return;
        }
        
        // Start transaction
        $this->db_connection->begin_transaction();
        
        try {
            $deleted_count = 0;
            $errors = array();
            
            foreach ($app_ids as $app_id) {
                $app_id = intval($app_id);
                
                // Find tables with app_id column
                $tables_query = "SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                WHERE COLUMN_NAME = 'app_id' 
                                AND TABLE_SCHEMA = '{$this->connected_db}'";
                
                $tables_result = $this->db_connection->query($tables_query);
                
                if (!$tables_result) {
                    throw new Exception("Failed to get tables with app_id column: " . $this->db_connection->error);
                }
                
                // Delete from each table that has an app_id column
                while ($table_row = $tables_result->fetch_assoc()) {
                    $table_name = $table_row['TABLE_NAME'];
                    
                    // Skip application table as we'll delete from it last
                    if ($table_name === 'application') {
                        continue;
                    }
                    
                    $delete_query = "DELETE FROM {$table_name} WHERE app_id = {$app_id}";
                    if (!$this->db_connection->query($delete_query)) {
                        $this->log_message("Failed to delete from {$table_name} for app {$app_id}: " . $this->db_connection->error);
                        // Continue with next table even if this one fails
                    }
                }
                $tables_result->free();
                
                // Delete from application_admin for this app
                $app_admin_query = "DELETE FROM application_admin WHERE app_id = {$app_id}";
                if (!$this->db_connection->query($app_admin_query)) {
                    throw new Exception("Failed to delete from application_admin for app {$app_id}: " . $this->db_connection->error);
                }
                
                // Delete the application itself
                $app_query = "DELETE FROM application WHERE app_id = {$app_id}";
                if (!$this->db_connection->query($app_query)) {
                    throw new Exception("Failed to delete application {$app_id}: " . $this->db_connection->error);
                }
                
                $deleted_count++;
                $this->log_message("Deleted application ID {$app_id} and all related data");
            }
            
            // Commit the transaction
            $this->db_connection->commit();
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d application(s)', 'swiftspeed-siberian'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
            
        } catch (Exception $e) {
            // Rollback the transaction
            $this->db_connection->rollback();
            
            $this->log_message("Application deletion error: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX handler for locking applications
     */
    public function ajax_lock_applications() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get application IDs to lock
        $app_ids = isset($_POST['app_ids']) ? $_POST['app_ids'] : array();
        
        if (empty($app_ids) || !is_array($app_ids)) {
            wp_send_json_error(array('message' => 'No application IDs provided'));
            return;
        }
        
        $locked_count = 0;
        $errors = array();
        
        foreach ($app_ids as $app_id) {
            $app_id = intval($app_id);
            
            $query = "UPDATE application SET is_locked = 1 WHERE app_id = {$app_id}";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $locked_count++;
                $this->log_message("Locked application ID {$app_id}");
            } else {
                $errors[] = "Failed to lock application ID {$app_id}: " . $this->db_connection->error;
                $this->log_message("Failed to lock application ID {$app_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully locked %d application(s)', 'swiftspeed-siberian'), $locked_count),
                'locked_count' => $locked_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during locking applications', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for unlocking applications
     */
    public function ajax_unlock_applications() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get application IDs to unlock
        $app_ids = isset($_POST['app_ids']) ? $_POST['app_ids'] : array();
        
        if (empty($app_ids) || !is_array($app_ids)) {
            wp_send_json_error(array('message' => 'No application IDs provided'));
            return;
        }
        
        $unlocked_count = 0;
        $errors = array();
        
        foreach ($app_ids as $app_id) {
            $app_id = intval($app_id);
            
            $query = "UPDATE application SET is_locked = 0 WHERE app_id = {$app_id}";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $unlocked_count++;
                $this->log_message("Unlocked application ID {$app_id}");
            } else {
                $errors[] = "Failed to unlock application ID {$app_id}: " . $this->db_connection->error;
                $this->log_message("Failed to unlock application ID {$app_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully unlocked %d application(s)', 'swiftspeed-siberian'), $unlocked_count),
                'unlocked_count' => $unlocked_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during unlocking applications', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for getting mail logs
     */
    public function ajax_get_mail_logs() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'log_id';
        $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'DESC';
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build search condition
        $search_condition = '';
        if (!empty($search)) {
            $search_escaped = $this->db_connection->real_escape_string($search);
            $search_condition = " WHERE title LIKE '%{$search_escaped}%' 
                              OR `from` LIKE '%{$search_escaped}%' 
                              OR recipients LIKE '%{$search_escaped}%'";
        }
        
        // Validate sort column
        $valid_columns = array('log_id', 'title', 'from', 'recipients', 'created_at', 'updated_at', 'app_id', 'is_application');
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = 'log_id';
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM mail_log" . $search_condition;
        $count_result = $this->db_connection->query($count_query);
        
        if (!$count_result) {
            $this->log_message("Failed to get mail log count: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get mail log count: ' . $this->db_connection->error));
            return;
        }
        
        $count_row = $count_result->fetch_assoc();
        $total = $count_row['total'];
        
        // Get data with pagination and sorting
        $query = "SELECT log_id, title, `from`, recipients, created_at, updated_at, app_id, is_application, text_error 
                FROM mail_log" . $search_condition . " 
                ORDER BY {$sort_column} {$sort_direction} 
                LIMIT {$offset}, {$per_page}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get mail logs: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get mail logs: ' . $this->db_connection->error));
            return;
        }
        
        $mail_logs = array();
        
        while ($row = $result->fetch_assoc()) {
            $mail_logs[] = array(
                'log_id' => $row['log_id'],
                'title' => $row['title'],
                'from' => $row['from'],
                'recipients' => $row['recipients'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'app_id' => $row['app_id'],
                'is_application' => $row['is_application'],
                'text_error' => $row['text_error']
            );
        }
        
        $result->free();
        
        // Return data
        wp_send_json_success(array(
            'mail_logs' => $mail_logs,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * AJAX handler for deleting mail logs
     */
    public function ajax_delete_mail_logs() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get log IDs to delete
        $log_ids = isset($_POST['log_ids']) ? $_POST['log_ids'] : array();
        
        if (empty($log_ids) || !is_array($log_ids)) {
            wp_send_json_error(array('message' => 'No log IDs provided'));
            return;
        }
        
        $deleted_count = 0;
        $errors = array();
        
        foreach ($log_ids as $log_id) {
            $log_id = intval($log_id);
            
            $query = "DELETE FROM mail_log WHERE log_id = {$log_id}";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $deleted_count++;
                $this->log_message("Deleted mail log ID {$log_id}");
            } else {
                $errors[] = "Failed to delete mail log ID {$log_id}: " . $this->db_connection->error;
                $this->log_message("Failed to delete mail log ID {$log_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d mail log(s)', 'swiftspeed-siberian'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during deleting mail logs', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for clearing all mail logs
     */
    public function ajax_clear_all_mail_logs() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $query = "DELETE FROM mail_log";
        $result = $this->db_connection->query($query);
        
        if ($result) {
            $affected_rows = $this->db_connection->affected_rows;
            $this->log_message("Cleared all mail logs ({$affected_rows} rows affected)");
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully cleared all mail logs (%d rows affected)', 'swiftspeed-siberian'), $affected_rows),
                'affected_rows' => $affected_rows
            ));
        } else {
            $this->log_message("Failed to clear all mail logs: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to clear all mail logs: ' . $this->db_connection->error));
        }
    }
    
    /**
     * AJAX handler for getting sessions
     */
    public function ajax_get_sessions() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'modified';
        $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'DESC';
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build search condition
        $search_condition = '';
        if (!empty($search)) {
            $search_escaped = $this->db_connection->real_escape_string($search);
            $search_condition = " WHERE session_id LIKE '%{$search_escaped}%'";
        }
        
        // Validate sort column
        $valid_columns = array('session_id', 'modified', 'lifetime', 'source');
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = 'modified';
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM session" . $search_condition;
        $count_result = $this->db_connection->query($count_query);
        
        if (!$count_result) {
            $this->log_message("Failed to get session count: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get session count: ' . $this->db_connection->error));
            return;
        }
        
        $count_row = $count_result->fetch_assoc();
        $total = $count_row['total'];
        
        // Get data with pagination and sorting
        $query = "SELECT session_id, modified, lifetime, source 
                FROM session" . $search_condition . " 
                ORDER BY {$sort_column} {$sort_direction} 
                LIMIT {$offset}, {$per_page}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get sessions: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get sessions: ' . $this->db_connection->error));
            return;
        }
        
        $sessions = array();
        
        while ($row = $result->fetch_assoc()) {
            // Convert modified timestamp to date
            $sessions[] = array(
                'session_id' => $row['session_id'],
                'modified' => $row['modified'],
                'lifetime' => $row['lifetime'],
                'source' => $row['source']
            );
        }
        
        $result->free();
        
        // Return data
        wp_send_json_success(array(
            'sessions' => $sessions,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * AJAX handler for deleting sessions
     */
    public function ajax_delete_sessions() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get session IDs to delete
        $session_ids = isset($_POST['session_ids']) ? $_POST['session_ids'] : array();
        
        if (empty($session_ids) || !is_array($session_ids)) {
            wp_send_json_error(array('message' => 'No session IDs provided'));
            return;
        }
        
        $deleted_count = 0;
        $errors = array();
        
        foreach ($session_ids as $session_id) {
            // Escape the session ID as it's a string
            $session_id = $this->db_connection->real_escape_string($session_id);
            
            $query = "DELETE FROM session WHERE session_id = '{$session_id}'";
            $result = $this->db_connection->query($query);
            
            if ($result) {
                $deleted_count++;
                $this->log_message("Deleted session ID {$session_id}");
            } else {
                $errors[] = "Failed to delete session ID {$session_id}: " . $this->db_connection->error;
                $this->log_message("Failed to delete session ID {$session_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d session(s)', 'swiftspeed-siberian'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during deleting sessions', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for clearing all sessions
     */
    public function ajax_clear_all_sessions() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $query = "DELETE FROM session";
        $result = $this->db_connection->query($query);
        
        if ($result) {
            $affected_rows = $this->db_connection->affected_rows;
            $this->log_message("Cleared all sessions ({$affected_rows} rows affected)");
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully cleared all sessions (%d rows affected)', 'swiftspeed-siberian'), $affected_rows),
                'affected_rows' => $affected_rows
            ));
        } else {
            $this->log_message("Failed to clear all sessions: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to clear all sessions: ' . $this->db_connection->error));
        }
    }
    
    /**
     * AJAX handler for getting source queue items
     */
    public function ajax_get_source_queue() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_column = isset($_POST['sort_column']) ? sanitize_text_field($_POST['sort_column']) : 'source_queue_id';
        $sort_direction = isset($_POST['sort_direction']) ? sanitize_text_field($_POST['sort_direction']) : 'DESC';
        
        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Build search condition
        $search_condition = '';
        if (!empty($search)) {
            $search_escaped = $this->db_connection->real_escape_string($search);
            $search_condition = " WHERE name LIKE '%{$search_escaped}%' 
                              OR url LIKE '%{$search_escaped}%' 
                              OR status LIKE '%{$search_escaped}%'
                              OR host LIKE '%{$search_escaped}%'";
        }
        
        // Validate sort column
        $valid_columns = array('source_queue_id', 'name', 'url', 'path', 'app_id', 'protocol', 'host', 'type', 'status', 'is_apk_service', 'created_at', 'updated_at');
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = 'source_queue_id';
        }
        
        // Validate sort direction
        $sort_direction = strtoupper($sort_direction) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $count_query = "SELECT COUNT(*) as total FROM source_queue" . $search_condition;
        $count_result = $this->db_connection->query($count_query);
        
        if (!$count_result) {
            $this->log_message("Failed to get source queue count: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get source queue count: ' . $this->db_connection->error));
            return;
        }
        
        $count_row = $count_result->fetch_assoc();
        $total = $count_row['total'];
        
        // Get data with pagination and sorting
        $query = "SELECT source_queue_id, name, url, path, app_id, protocol, host, type, design_code, 
                       user_id, user_type, status, is_apk_service, aab_path, apk_path, apk_message, 
                       apk_status, build_time, build_start_time, is_autopublish, is_refresh_pem, 
                       created_at, updated_at 
                FROM source_queue" . $search_condition . " 
                ORDER BY {$sort_column} {$sort_direction} 
                LIMIT {$offset}, {$per_page}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get source queue items: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to get source queue items: ' . $this->db_connection->error));
            return;
        }
        
        $source_queue_items = array();
        
        while ($row = $result->fetch_assoc()) {
            // Format dates for display
            $build_time = !empty($row['build_time']) ? date('Y-m-d H:i:s', intval($row['build_time'])) : '';
            $build_start_time = !empty($row['build_start_time']) ? date('Y-m-d H:i:s', intval($row['build_start_time'])) : '';
            
            $source_queue_items[] = array(
                'source_queue_id' => $row['source_queue_id'],
                'name' => $row['name'],
                'url' => $row['url'],
                'app_id' => $row['app_id'],
                'host' => $row['host'],
                'type' => $row['type'],
                'status' => $row['status'],
                'is_apk_service' => $row['is_apk_service'],
                'apk_status' => $row['apk_status'],
                'build_time' => $build_time,
                'build_start_time' => $build_start_time,
                'is_autopublish' => $row['is_autopublish'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            );
        }
        
        $result->free();
        
        // Return data
        wp_send_json_success(array(
            'source_queue_items' => $source_queue_items,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'current_page' => $page
        ));
    }
    
    /**
     * AJAX handler for deleting source queue items
     */
    public function ajax_delete_source_queue() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get source queue IDs to delete
        $source_queue_ids = isset($_POST['source_queue_ids']) ? $_POST['source_queue_ids'] : array();
        
        if (empty($source_queue_ids) || !is_array($source_queue_ids)) {
            wp_send_json_error(array('message' => 'No source queue IDs provided'));
            return;
        }
        
        $deleted_count = 0;
        $errors = array();
        
        foreach ($source_queue_ids as $source_queue_id) {
            $source_queue_id = intval($source_queue_id);
            
            $query = "DELETE FROM source_queue WHERE source_queue_id = {$source_queue_id}";

            $result = $this->db_connection->query($query);
            
            if ($result) {
                $deleted_count++;
                $this->log_message("Deleted source queue ID {$source_queue_id}");
            } else {
                $errors[] = "Failed to delete source queue ID {$source_queue_id}: " . $this->db_connection->error;
                $this->log_message("Failed to delete source queue ID {$source_queue_id}: " . $this->db_connection->error);
            }
        }
        
        if (empty($errors)) {
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully deleted %d source queue item(s)', 'swiftspeed-siberian'), $deleted_count),
                'deleted_count' => $deleted_count
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Errors occurred during deleting source queue items', 'swiftspeed-siberian'),
                'errors' => $errors
            ));
        }
    }
    
    /**
     * AJAX handler for clearing all source queue items
     */
    public function ajax_clear_all_source_queue() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $query = "DELETE FROM source_queue";
        $result = $this->db_connection->query($query);
        
        if ($result) {
            $affected_rows = $this->db_connection->affected_rows;
            $this->log_message("Cleared all source queue items ({$affected_rows} rows affected)");
            
            wp_send_json_success(array(
                'message' => sprintf(__('Successfully cleared all source queue items (%d rows affected)', 'swiftspeed-siberian'), $affected_rows),
                'affected_rows' => $affected_rows
            ));
        } else {
            $this->log_message("Failed to clear all source queue items: " . $this->db_connection->error);
            wp_send_json_error(array('message' => 'Failed to clear all source queue items: ' . $this->db_connection->error));
        }
    }
}