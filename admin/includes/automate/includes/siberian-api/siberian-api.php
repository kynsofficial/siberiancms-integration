<?php
/**
 * Siberian API Command Automation - Main Class
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_API {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * API settings handler
     */
    private $settings;
    
    /**
     * API data handler
     */
    private $data;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        $this->data = new SwiftSpeed_Siberian_API_Data($this->db_connection, $this->db_name);
        $this->settings = new SwiftSpeed_Siberian_API_Settings($this->db_connection, $this->db_name);
        
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
        
        require_once($include_dir . 'siberian-api/siberian-api-data.php');
        require_once($include_dir . 'siberian-api/siberian-api-settings.php');
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // API command handlers
        add_action('wp_ajax_swsib_run_api_command', array($this, 'ajax_run_api_command'));
        
        // Settings handler
        add_action('wp_ajax_swsib_save_api_automation', array($this, 'ajax_save_api_automation'));
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
     * Handle scheduled tasks directly
     */
    public function handle_scheduled_task($task_type, $task_args) {
        if ($task_type === 'api_command') {
            $command = isset($task_args['command']) ? $task_args['command'] : '';
            
            $this->log_message("Handling scheduled API command: $command");
            
            // Get Siberian API settings
            $options = get_option('swsib_options', array());
            $auto_login = isset($options['auto_login']) ? $options['auto_login'] : array();
            
            $siberian_url = isset($auto_login['siberian_url']) ? $auto_login['siberian_url'] : '';
            $api_user = isset($auto_login['api_user']) ? $auto_login['api_user'] : '';
            $api_password = isset($auto_login['api_password']) ? $auto_login['api_password'] : '';
            
            if (empty($siberian_url) || empty($api_user) || empty($api_password)) {
                $this->log_message("API credentials not configured, cannot execute command");
                return array('success' => false, 'message' => 'API credentials not configured');
            }
            
            // Execute the command
            $result = $this->data->execute_command($command, $siberian_url, $api_user, $api_password);
            
            // Mark the task as completed so it will be rescheduled
            $scheduler = new SwiftSpeed_Siberian_Scheduler();
            $scheduler->mark_task_completed(
                $task_type, 
                $task_args, 
                $result['success'], 
                $result['message']
            );
            
            return $result;
        }
        
        return array('success' => false, 'message' => 'Unknown task type');
    }
    
    /**
     * AJAX handler for running API commands
     */
    public function ajax_run_api_command() {
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
        
        // Get command
        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
        
        if (empty($command)) {
            wp_send_json_error(array('message' => 'Command not specified.'));
            return;
        }
        
        // Get Siberian API settings
        $options = get_option('swsib_options', array());
        $auto_login = isset($options['auto_login']) ? $options['auto_login'] : array();
        
        $siberian_url = isset($auto_login['siberian_url']) ? $auto_login['siberian_url'] : '';
        $api_user = isset($auto_login['api_user']) ? $auto_login['api_user'] : '';
        $api_password = isset($auto_login['api_password']) ? $auto_login['api_password'] : '';
        
        if (empty($siberian_url) || empty($api_user) || empty($api_password)) {
            wp_send_json_error(array('message' => 'Siberian API credentials not configured. Please check Auto Login settings.'));
            return;
        }
        
        // Execute the command
        $result = $this->data->execute_command($command, $siberian_url, $api_user, $api_password);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'output' => $result['output']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX handler for saving API automation settings
     */
    public function ajax_save_api_automation() {
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
        
        // Get command type
        $command = isset($_POST['command']) ? sanitize_text_field($_POST['command']) : '';
        
        if (empty($command) || !in_array($command, array('manifest', 'clearcache', 'cleartmp', 'clearlogs'))) {
            wp_send_json_error(array('message' => 'Invalid command type.'));
            return;
        }
        
        // Parse settings from form data
        parse_str($_POST['settings'], $settings);
        
        // Save settings
        $result = $this->settings->save_api_automation($command, $settings);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * Display API automation settings
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for API automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }
}