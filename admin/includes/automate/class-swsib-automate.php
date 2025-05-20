<?php
/**
 * Automate functionality for the plugin.
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Automate {
    
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
     * Current active tab
     */
    private $active_tab = 'siberian';
    
    /**
     * Task modules
     */
    public $siberian_api;
    public $image_cleanup;
    public $user_management;
    public $app_management;
    public $db_cleanup;
    public $wp_tasks;
    public $email_manager;
    public $scheduler;
    public $actions;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Initialize the database connection
        $this->init_db_connection();
        
        // Include required files
        $this->include_files();
        
        // Initialize task modules
        $this->init_task_modules();
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Register custom cron schedules - MOVED EARLIER in init to ensure schedules are registered before use
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        
        // Set up cron hooks for automation - AFTER schedules are registered
        $this->setup_cron_hooks();
        
        // Register admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'), 100);
        
        // Set active tab from URL parameter
        if (isset($_GET['automate_tab']) && in_array($_GET['automate_tab'], array('siberian', 'wordpress', 'actions'))) {
            $this->active_tab = sanitize_key($_GET['automate_tab']);
        }
        
        // IMPROVED: Add additional triggers for task checking
        add_action('admin_init', array($this, 'admin_init_check_tasks'));
        add_action('wp_loaded', array($this, 'front_end_check_tasks'));
    }
    
    /**
     * Log message
     */
    private function log_message($message, $force = false) {
        static $last_log_time = 0;
        $current_time = time();
        
        // Only log if it's been more than 5 minutes since the last log or if forced
        if ($force || ($current_time - $last_log_time) > 300) {
            if (function_exists('swsib') && swsib()->logging) {
                swsib()->logging->write_to_log('automate', 'backend', $message);
            }
            $last_log_time = $current_time;
        }
    }
    
    /**
     * Admin init task check - additional trigger for admin pages
     */
    public function admin_init_check_tasks() {
        // Get the last admin check time
        $last_admin_check = get_option('swsib_last_admin_check', 0);
        $current_time = time();
        
        // Only check every 5 minutes to prevent excessive checks
        if (($current_time - $last_admin_check) > 300) {
            $this->log_message("Admin page visit triggering task check", true);
            if ($this->scheduler) {
                $this->scheduler->force_check_tasks();
            }
            update_option('swsib_last_admin_check', $current_time);
        }
    }
    
    /**
     * Front end task check - additional trigger for frontend visits
     */
    public function front_end_check_tasks() {
        // Only run on non-admin pages and limit frequency
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Get the last front end check time
        $last_frontend_check = get_option('swsib_last_frontend_check', 0);
        $current_time = time();
        
        // Only check every 15 minutes to prevent excessive checks on high-traffic sites
        if (($current_time - $last_frontend_check) > 900) {
            $this->log_message("Frontend page visit triggering task check", true);
            if ($this->scheduler) {
                $this->scheduler->force_check_tasks();
            }
            update_option('swsib_last_frontend_check', $current_time);
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
                        $this->log_message("Database connection failed: " . $this->db_connection->connect_error, true);
                        $this->db_connection = null;
                    } else {
                        $this->connected_db = $db_options['database'];
                    }
                } catch (Exception $e) {
                    $this->log_message("Database connection exception: " . $e->getMessage(), true);
                    $this->db_connection = null;
                }
            }
        }
    }
    
    /**
     * Include required files.
     */
    private function include_files() {
        // Create includes directory if it doesn't exist
        $includes_dir = SWSIB_PLUGIN_DIR . 'admin/includes/automate/includes/';
        if (!file_exists($includes_dir)) {
            wp_mkdir_p($includes_dir);
        }
        
        // Include task modules
        require_once($includes_dir . '/siberian-api/siberian-api.php');
        require_once($includes_dir . '/image-cleanup/image-cleanup.php');
        require_once($includes_dir . '/user-management/user-management.php');
        require_once($includes_dir . '/app-management/app-management.php');
        require_once($includes_dir . '/db-cleanup/db-cleanup.php');
        require_once($includes_dir . '/wp-tasks/wp-tasks.php');
        
        // Include helper modules
        require_once($includes_dir . '/email-manager/email-manager.php');
        require_once($includes_dir . '/task-runner/scheduler.php');
        require_once($includes_dir . '/task-runner/task-runner.php');
        require_once($includes_dir . '/task-runner/actions.php');
    }
    
    /**
     * Initialize task modules.
     */
    private function init_task_modules() {
        // Initialize task modules
        $this->siberian_api = new SwiftSpeed_Siberian_API($this->db_connection, $this->connected_db);
        $this->image_cleanup = new SwiftSpeed_Siberian_Image_Cleanup($this->db_connection, $this->connected_db);
        $this->user_management = new SwiftSpeed_Siberian_User_Management($this->db_connection, $this->connected_db);
        $this->app_management = new SwiftSpeed_Siberian_App_Management($this->db_connection, $this->connected_db);
        $this->db_cleanup = new SwiftSpeed_Siberian_DB_Cleanup($this->db_connection, $this->connected_db);
        $this->wp_tasks = new SwiftSpeed_Siberian_WP_Tasks($this->db_connection, $this->connected_db);
        
        // Initialize helper modules
        $this->email_manager = new SwiftSpeed_Siberian_Email_Manager();
        $this->scheduler = new SwiftSpeed_Siberian_Scheduler();
        $this->actions = new SwiftSpeed_Siberian_Actions();
    }
    
    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // API automation AJAX handlers
        add_action('wp_ajax_swsib_run_api_command', array($this->siberian_api, 'ajax_run_api_command'));
        add_action('wp_ajax_swsib_save_api_automation', array($this->siberian_api, 'ajax_save_api_automation'));
        
        // Image cleanup AJAX handlers
        add_action('wp_ajax_swsib_cleanup_images', array($this->image_cleanup, 'ajax_cleanup_images'));
        add_action('wp_ajax_swsib_save_image_cleanup_automation', array($this->image_cleanup, 'ajax_save_image_cleanup_automation'));
        add_action('wp_ajax_swsib_get_cleanup_progress', array($this->image_cleanup, 'ajax_get_cleanup_progress'));
        add_action('wp_ajax_swsib_get_orphaned_images_count', array($this->image_cleanup, 'ajax_get_orphaned_images_count'));
        
        // User management AJAX handlers
        add_action('wp_ajax_swsib_manage_users', array($this->user_management, 'ajax_manage_users'));
        add_action('wp_ajax_swsib_save_user_management_automation', array($this->user_management, 'ajax_save_user_management_automation'));
        add_action('wp_ajax_swsib_get_inactive_users_count', array($this->user_management, 'ajax_get_inactive_users_count'));
        add_action('wp_ajax_swsib_get_users_without_apps_count', array($this->user_management, 'ajax_get_users_without_apps_count'));
        add_action('wp_ajax_swsib_get_user_management_progress', array($this->user_management, 'ajax_get_user_management_progress'));
        
        // Database cleanup AJAX handlers
        add_action('wp_ajax_swsib_cleanup_database', array($this->db_cleanup, 'ajax_cleanup_database'));
        add_action('wp_ajax_swsib_save_db_cleanup_automation', array($this->db_cleanup, 'ajax_save_db_cleanup_automation'));
        
        // WordPress tasks AJAX handlers
        add_action('wp_ajax_swsib_cleanup_wp_users', array($this->wp_tasks, 'ajax_cleanup_wp_users'));
        add_action('wp_ajax_swsib_save_wp_tasks_automation', array($this->wp_tasks, 'ajax_save_wp_tasks_automation'));
        add_action('wp_ajax_swsib_get_spam_users_count', array($this->wp_tasks, 'ajax_get_spam_users_count'));
        add_action('wp_ajax_swsib_get_unsynced_users_count', array($this->wp_tasks, 'ajax_get_unsynced_users_count'));
        add_action('wp_ajax_swsib_get_wp_cleanup_progress', array($this->wp_tasks, 'ajax_get_wp_cleanup_progress'));
        
        // Scheduler AJAX handlers
        add_action('wp_ajax_swsib_get_task_schedule', array($this->scheduler, 'ajax_get_task_schedule'));
        add_action('wp_ajax_swsib_get_task_history', array($this->scheduler, 'ajax_get_task_history'));
        add_action('wp_ajax_swsib_run_task_now', array($this->scheduler, 'ajax_run_task_now'));
        add_action('wp_ajax_swsib_reset_task', array($this->scheduler, 'ajax_reset_task'));
        
        // Force check AJAX handler - for external cron access
        add_action('wp_ajax_swsib_force_check_tasks', array($this, 'ajax_force_check_tasks'));
        add_action('wp_ajax_nopriv_swsib_force_check_tasks', array($this, 'ajax_force_check_tasks'));
        
        // Task progress AJAX handlers
        add_action('wp_ajax_swsib_get_task_progress', array('SwiftSpeed_Siberian_Task_Runner', 'ajax_get_task_progress'));
        
        // IMPROVED: Add AJAX handler for manually running all due tasks
        add_action('wp_ajax_swsib_run_all_due_tasks', array($this, 'ajax_run_all_due_tasks'));
        
        // Add SMTP settings AJAX handlers
        add_action('wp_ajax_swsib_save_smtp_settings', array($this->email_manager, 'ajax_save_smtp_settings'));
        add_action('wp_ajax_swsib_test_smtp_settings', array($this->email_manager, 'ajax_test_smtp_settings'));
    }

    /**
     * AJAX handler for force checking tasks
     */
    public function ajax_force_check_tasks() {
        // Basic security check - require a key parameter
        $expected_key = md5('swsib_force_check_tasks');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            if ($this->scheduler) {
                $this->scheduler->force_check_tasks();
            }
            wp_die('Tasks checked');
        } else {
            wp_die('Invalid security key');
        }
    }
    
    /**
     * AJAX handler for manually running all due tasks
     */
    public function ajax_run_all_due_tasks() {
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
        
        // Force run all due tasks
        if ($this->scheduler) {
            $this->scheduler->force_check_tasks();
        }
        
        wp_send_json_success(array('message' => 'All due tasks have been checked and executed.'));
    }
    
/**
 * Set up cron hooks for automation.
 */
private function setup_cron_hooks() {
    // Register cron event hooks
    add_action('swsib_run_scheduled_task', array('SwiftSpeed_Siberian_Task_Runner', 'run_scheduled_task'), 10, 2);
    
    // Add action for recurrent task check
    add_action('swsib_recurrent_task_check', array($this->scheduler, 'recurrent_task_check'));
    
    // Add action for immediate task checking
    add_action('swsib_check_scheduled_tasks', array($this->scheduler, 'check_scheduled_tasks'));
    
    // Ensure custom schedule exists before using it
    $schedules = wp_get_schedules();
    $minute_schedule = isset($schedules['swsib_every_minute']) ? 'swsib_every_minute' : 'hourly';
    
    // Schedule the recurrent check if not already scheduled
    if (!wp_next_scheduled('swsib_recurrent_task_check')) {
        wp_schedule_event(time() + 30, $minute_schedule, 'swsib_recurrent_task_check');
    }
    
    // Remove old hourly check if it exists (migrating from old system)
    if (wp_next_scheduled('swsib_hourly_tasks_check')) {
        wp_clear_scheduled_hook('swsib_hourly_tasks_check');
    }
    
    // Add additional single event to run shortly if not already scheduled
    if (!wp_next_scheduled('swsib_check_scheduled_tasks')) {
        wp_schedule_single_event(time() + 30, 'swsib_check_scheduled_tasks');
    }
    
    // Schedule backend helper events that run less frequently but more reliably
    if (!wp_next_scheduled('swsib_hourly_task_check_backup')) {
        wp_schedule_event(time() + 600, 'hourly', 'swsib_hourly_task_check_backup');
    }
    add_action('swsib_hourly_task_check_backup', array($this->scheduler, 'force_check_tasks'));
    
    // Log cron URL with more detailed information
    $this->log_cron_url();
    
    // Check if external cron is configured and provide user feedback
    if (!$this->is_external_cron_configured()) {
        // Schedule immediate check to ensure we catch any missed tasks
        if (!wp_next_scheduled('swsib_check_scheduled_tasks')) {
            wp_schedule_single_event(time() + 10, 'swsib_check_scheduled_tasks');
        }
    }
}
    
    /**
     * Log cron job URL with more detailed information
     */
    private function log_cron_url() {
        $last_cron_url_log = get_option('swsib_last_cron_url_log', 0);
        $cron_url = admin_url('admin-ajax.php?action=swsib_force_check_tasks&key=' . md5('swsib_force_check_tasks'));
        
        if (time() - $last_cron_url_log > DAY_IN_SECONDS) { // Only log once per day
            // Check if wp-cron is disabled in the site configuration
            $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            
            $message = "For reliable task execution, set up a server cron job to hit this URL: $cron_url\n";
            
            if ($wp_cron_disabled) {
                $message .= "NOTICE: WP-Cron is disabled on this site (DISABLE_WP_CRON is set to true). External cron setup is REQUIRED for automation to work properly.\n";
            } else {
                $message .= "RECOMMENDATION: For high-traffic sites, consider setting DISABLE_WP_CRON to true in wp-config.php and using a server cron job for better reliability.\n";
            }
            
            $message .= "Example cron job command (run every 5 minutes):\n";
            $message .= "*/5 * * * * wget -q -O /dev/null '$cron_url' >/dev/null 2>&1";
            
            $this->log_message($message, true);
            update_option('swsib_last_cron_url_log', time());
        }
    }
    
    /**
     * Check if external cron is configured
     */
    private function is_external_cron_configured() {
        // Get option to check if external cron is configured
        $options = get_option('swsib_options', array());
        return !empty($options['automate']['use_external_cron']);
    }
    

/**
     * Register custom cron schedules.
     * FIXED: Consolidated with scheduler.php to prevent conflicts
     */
    public function register_cron_schedules($schedules) {
        // Add a minute schedule for more responsive task execution
        $schedules['swsib_every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'swiftspeed-siberian')
        );
        
        // Add more minute-based schedules
        $schedules['swsib_every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_10_minutes'] = array(
            'interval' => 600,
            'display' => __('Every 10 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_15_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'swiftspeed-siberian')
        );
        
        // Add standard schedules
        $schedules['every_30_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['every_2_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display' => __('Every 2 Hours', 'swiftspeed-siberian')
        );
        
        $schedules['every_5_hours'] = array(
            'interval' => 5 * HOUR_IN_SECONDS,
            'display' => __('Every 5 Hours', 'swiftspeed-siberian')
        );
        
        $schedules['every_12_hours'] = array(
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Every 12 Hours', 'swiftspeed-siberian')
        );
        
        $schedules['every_3_days'] = array(
            'interval' => 3 * DAY_IN_SECONDS,
            'display' => __('Every 3 Days', 'swiftspeed-siberian')
        );
        
        $schedules['every_2_weeks'] = array(
            'interval' => 14 * DAY_IN_SECONDS,
            'display' => __('Every 2 Weeks', 'swiftspeed-siberian')
        );
        
        $schedules['every_month'] = array(
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Every Month', 'swiftspeed-siberian')
        );
        
        $schedules['every_3_months'] = array(
            'interval' => 90 * DAY_IN_SECONDS,
            'display' => __('Every 3 Months', 'swiftspeed-siberian')
        );
        
        $schedules['every_6_months'] = array(
            'interval' => 180 * DAY_IN_SECONDS,
            'display' => __('Every 6 Months', 'swiftspeed-siberian')
        );
        
        $schedules['every_9_months'] = array(
            'interval' => 270 * DAY_IN_SECONDS,
            'display' => __('Every 9 Months', 'swiftspeed-siberian')
        );
        
        $schedules['every_year'] = array(
            'interval' => 365 * DAY_IN_SECONDS,
            'display' => __('Every Year', 'swiftspeed-siberian')
        );
        
        return $schedules;
    }
/**
 * Enqueue scripts and styles for the Automate tab.
 */
public function enqueue_scripts($hook) {
    // Only load on plugin admin page
    if (strpos($hook, 'swsib-integration') === false) {
        return;
    }
    
    // To ensure compatibility with existing clean tab, grab localized script data
    global $wp_scripts;
    $hasCleanScript = false;
    
    // Check if swsib-clean-js is already registered
    if (isset($wp_scripts->registered['swsib-clean-js'])) {
        $hasCleanScript = true;
    }
    
    // Enqueue our automate CSS
    wp_enqueue_style(
        'swsib-automate-css', 
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/automate.css',
        array(),
        SWSIB_VERSION
    );
    
    // Enqueue our automate JS
    wp_enqueue_script(
        'swsib-automate-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/automate.js',
        array('jquery'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue app management JS
    wp_enqueue_script(
        'swsib-app-management-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/app-management/app-management.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue DB Cleanup JS
    wp_enqueue_script(
        'swsib-db-cleanup-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/db-cleanup/db-cleanup.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );

    // Enqueue WordPress tasks JS
    wp_enqueue_script(
        'swsib-wp-tasks-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/wp-tasks/wp-tasks.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue Image Cleanup JS
    wp_enqueue_script(
        'swsib-image-cleanup-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/image-cleanup/image-cleanup.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue user management JS
    wp_enqueue_script(
        'swsib-user-management-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/user-management/user-management.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue Siberian API JS
    wp_enqueue_script(
        'swsib-siberian-api-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/siberian-api/siberian-api.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue Actions JS
    wp_enqueue_script(
        'swsib-actions-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/task-runner/actions.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Enqueue Email Manager JS
    wp_enqueue_script(
        'swsib-email-manager-js',
        SWSIB_PLUGIN_URL . 'admin/includes/automate/includes/email-manager/email-manager.js',
        array('jquery', 'swsib-automate-js'),
        SWSIB_VERSION,
        true
    );
    
    // Add our localization data
    wp_localize_script(
        'swsib-automate-js',
        'swsib_automate',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('swsib-automate-nonce'),
            'is_db_configured' => swsib()->is_db_configured(),
            'is_api_configured' => $this->is_api_configured(),
            'confirm_run_task' => __('Are you sure you want to run this task now?', 'swiftspeed-siberian'),
            'confirm_delete_users' => __('Are you sure you want to run the inactive users cleanup task? This will remove users based on your settings.', 'swiftspeed-siberian'),
            'confirm_delete_apps' => __('Are you sure you want to run the application cleanup task? This will remove applications based on your settings.', 'swiftspeed-siberian'),
            'confirm_clean_images' => __('Are you sure you want to clean up orphaned image folders? This cannot be undone.', 'swiftspeed-siberian'),
            'task_running' => __('Task is running...', 'swiftspeed-siberian'),
            'task_complete' => __('Task completed successfully.', 'swiftspeed-siberian'),
            'task_failed' => __('Task failed. Please check the logs.', 'swiftspeed-siberian'),
            'settings_saved' => __('Automation settings saved successfully.', 'swiftspeed-siberian'),
            'settings_failed' => __('Failed to save automation settings.', 'swiftspeed-siberian')
        )
    );
    
    // If clean script wasn't already registered, provide a dummy object to prevent errors
    if (!$hasCleanScript) {
        wp_add_inline_script('swsib-automate-js', 'var swsib_clean = { 
            confirm_delete_admins: "Are you sure?", 
            confirm_deactivate_admins: "Are you sure?", 
            confirm_activate_admins: "Are you sure?", 
            confirm_delete_apps: "Are you sure?", 
            confirm_lock_apps: "Are you sure?", 
            confirm_unlock_apps: "Are you sure?", 
            confirm_delete_mail_logs: "Are you sure?", 
            confirm_clear_all_mail_logs: "Are you sure?", 
            confirm_delete_sessions: "Are you sure?", 
            confirm_clear_all_sessions: "Are you sure?", 
            confirm_delete_source_queue: "Are you sure?", 
            confirm_clear_all_source_queue: "Are you sure?", 
            error_no_selection: "Please select items", 
            deleting: "Deleting...", 
            processing: "Processing...", 
            success: "Success", 
            error: "Error", 
            db_error: "DB Error" 
        };', 'before');
    }
}

    /**
     * Check if API connection is configured.
     */
    private function is_api_configured() {
        $auto_login = isset($this->options['auto_login']) ? $this->options['auto_login'] : array();
        return !empty($auto_login['siberian_url']) && !empty($auto_login['api_user']) && !empty($auto_login['api_password']);
    }
    
    /**
     * Display the Automate settings and tabs.
     */
    public function display_settings() {
        // First check if the database connection is active
        if (!$this->db_connection) {
            echo '<div class="swsib-notice error">';
            echo '<p>' . __('Database connection failed or not configured. Please check your DB Connect settings.', 'swiftspeed-siberian') . '</p>';
            echo '</div>';
            return;
        }
        
        // Get the current tab
        $current_tab = $this->active_tab;
        
     // Display tabs and content
?>
<h2><?php _e('Automation System', 'swiftspeed-siberian'); ?></h2>

<div class="swsib-notice info">
    <p><?php _e('The Automation System allows you to schedule tasks to clean up and maintain your Siberian CMS and WordPress installations.', 'swiftspeed-siberian'); ?></p>
    <p><?php _e('You can run tasks manually or set up automated schedules to run them at specific intervals.', 'swiftspeed-siberian'); ?></p>
    <p><strong><?php _e('Recommended setup:', 'swiftspeed-siberian'); ?></strong> <?php _e('For reliable operation, set up a server cron job to call the URL shown in your system logs.', 'swiftspeed-siberian'); ?></p>
</div>

<div id="task-progress-container" class="task-progress-container" style="display: none;">
    <div class="task-progress-header">
        <h3 class="task-title"><?php _e('Task in Progress', 'swiftspeed-siberian'); ?></h3>
        <span class="close-progress dashicons dashicons-no-alt"></span>
    </div>
    <div class="task-progress-bar-container">
        <div class="task-progress-bar"></div>
        <div class="task-progress-percentage">0%</div>
    </div>
    <div class="task-progress-details">
        <div class="task-stats">
            <span class="task-processed">0</span> / <span class="task-total">0</span>
            <span class="task-time-elapsed">00:00:00</span>
        </div>
        <div class="task-current-item"></div>
    </div>
    <div class="task-progress-log"></div>
</div>

<!-- Tabs navigation -->
<div class="swsib-automate-tabs">
    <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=automate&automate_tab=siberian'); ?>" 
       class="<?php echo $current_tab == 'siberian' ? 'active' : ''; ?>">
        <?php _e('Siberian CMS Tasks', 'swiftspeed-siberian'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=automate&automate_tab=wordpress'); ?>" 
       class="<?php echo $current_tab == 'wordpress' ? 'active' : ''; ?>">
        <?php _e('WordPress Tasks', 'swiftspeed-siberian'); ?>
    </a>
    <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=automate&automate_tab=actions'); ?>" 
       class="<?php echo $current_tab == 'actions' ? 'active' : ''; ?>">
        <?php _e('Automated Actions', 'swiftspeed-siberian'); ?>
    </a>
</div>

<!-- Tab content -->
<div class="swsib-automate-tab-content">
    <div id="automate-tab-siberian" style="<?php echo $current_tab == 'siberian' ? '' : 'display: none;'; ?>">
        <?php $this->render_siberian_tab(); ?>
    </div>
    <div id="automate-tab-wordpress" style="<?php echo $current_tab == 'wordpress' ? '' : 'display: none;'; ?>">
        <?php $this->render_wordpress_tab(); ?>
    </div>
    <div id="automate-tab-actions" style="<?php echo $current_tab == 'actions' ? '' : 'display: none;'; ?>">
        <?php $this->render_actions_tab(); ?>
    </div>
</div>
<?php
    }
    
    /**
     * Render the Siberian CMS tasks tab
     */
    private function render_siberian_tab() {
        // Check if all modules are initialized properly
        if (!isset($this->siberian_api) || 
            !isset($this->image_cleanup) || 
            !isset($this->user_management) || 
            !isset($this->app_management) || 
            !isset($this->db_cleanup)) {
            
            // Display placeholder content if modules aren't available
            echo '<div class="task-section">';
            echo '<h3>' . __('Siberian CMS Automation Tasks', 'swiftspeed-siberian') . '</h3>';
            echo '<div class="info-text">' . __('Automation features are currently loading. Please refresh the page or check back later.', 'swiftspeed-siberian') . '</div>';
            echo '</div>';
            return;
        }
        
        // API Commands Section
        $this->siberian_api->display_settings();
        
        // Image Cleanup Section
        $this->image_cleanup->display_settings();
        
        // User Management Section
        $this->user_management->display_settings();
        
        // App Management Section
        $this->app_management->display_settings();
        
        // Database Cleanup Section
        $this->db_cleanup->display_settings();
        
        // SMTP Settings Section - Moved from WordPress tab to Siberian tab
        $this->email_manager->display_smtp_settings();
    }
    
    /**
     * Render the WordPress tasks tab
     */
    private function render_wordpress_tab() {
        // Check if all modules are initialized properly
        if (!isset($this->wp_tasks)) {
            // Display placeholder content if modules aren't available
            echo '<div class="task-section">';
            echo '<h3>' . __('WordPress Automation Tasks', 'swiftspeed-siberian') . '</h3>';
            echo '<div class="info-text">' . __('Automation features are currently loading. Please refresh the page or check back later.', 'swiftspeed-siberian') . '</div>';
            echo '</div>';
            return;
        }
        
        // WordPress User Management Section
        $this->wp_tasks->display_settings();
    }
    
    /**
     * Render the Automated Actions tab
     */
    private function render_actions_tab() {
        // Check if actions module is initialized properly
        if (!isset($this->actions)) {
            // Display placeholder content if module isn't available
            echo '<div class="task-section">';
            echo '<h3>' . __('Automated Actions Log', 'swiftspeed-siberian') . '</h3>';
            echo '<div class="info-text">' . __('Automated Actions log is currently loading. Please refresh the page or check back later.', 'swiftspeed-siberian') . '</div>';
            echo '</div>';
            return;
        }
        
        // Display the actions log
        $this->actions->display_settings();
    }
    
    /**
     * Process settings form submission for Automate.
     */
    public function process_settings($input) {
        // Process settings for each task module
        $input = $this->siberian_api->process_settings($input);
        $input = $this->image_cleanup->process_settings($input);
        $input = $this->user_management->process_settings($input);
        $input = $this->app_management->process_settings($input);
        $input = $this->db_cleanup->process_settings($input);
        $input = $this->wp_tasks->process_settings($input);
        
        return $input;
    }
}