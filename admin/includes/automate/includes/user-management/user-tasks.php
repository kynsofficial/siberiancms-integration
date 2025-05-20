<?php
/**
 * User Management - Task Execution
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_User_Tasks {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Email manager instance
     */
    private $email_manager;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null, $email_manager = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
        $this->email_manager = $email_manager;
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
     * Process inactive users
     * 
     * @param string $task_id The task ID
     * @param array $task_data The task data
     * @param bool $is_manual Whether this is a manual run (not from cron)
     */
    public function process_inactive_users($task_id, $task_data, $is_manual = false) {
        $this->log_message("Starting inactive users task with batch processing");
        
        // Initialize the task
        $data_handler = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
        $data_handler->initialize_task('inactive');
        
        // Process all batches
        $this->process_all_batches('inactive');
        
        // For compatibility with old code, update the transient
        $progress_file = $this->get_progress_file('inactive');
        if (file_exists($progress_file)) {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            if ($progress_data) {
                $task_data = array_merge($task_data, $progress_data);
                $task_data['status'] = $progress_data['status'];
                set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
                set_transient('swsib_inactive_users_progress', $task_data, DAY_IN_SECONDS);
            }
        }
        
        $this->log_message("Inactive users task completed");
    }
    
    /**
     * Process all batches for a task
     */
    private function process_all_batches($task) {
        $data_handler = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
        $batch = 0;
        $completed = false;
        
        while (!$completed) {
            $result = $data_handler->process_batch($task, $batch);
            if (!$result['success']) {
                $this->log_message("Error processing batch $batch for task $task: " . $result['message']);
                return false;
            }
            
            $batch = $result['next_batch'];
            $completed = $result['completed'];
            
            // Small delay to prevent server overload
            usleep(100000); // 0.1 second
        }
        
        return true;
    }
    
    /**
     * Process users without apps
     * 
     * @param string $task_id The task ID
     * @param array $task_data The task data
     * @param bool $is_manual Whether this is a manual run (not from cron)
     */
    public function process_users_without_apps($task_id, $task_data, $is_manual = false) {
        $this->log_message("Starting users without apps task with batch processing");
        
        // Initialize the task
        $data_handler = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
        $data_handler->initialize_task('no_apps');
        
        // Process all batches
        $this->process_all_batches('no_apps');
        
        // For compatibility with old code, update the transient
        $progress_file = $this->get_progress_file('no_apps');
        if (file_exists($progress_file)) {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            
            if ($progress_data) {
                $task_data = array_merge($task_data, $progress_data);
                $task_data['status'] = $progress_data['status'];
                set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
                set_transient('swsib_no_apps_users_progress', $task_data, DAY_IN_SECONDS);
            }
        }
        
        $this->log_message("Users without apps task completed");
    }
    
    /**
     * Process inactive users warnings
     * 
     * @param string $task_id The task ID
     * @param array $task_data The task data
     * @param string $progress_key The progress key
     */
    public function process_inactive_users_warnings($task_id, $task_data, $inactive_users, $warning_threshold, $settings, $progress_key) {
        $warned_users = 0;
        $already_warned = 0;
        $errors = 0;
        
        // Get users who have already been warned
        $warned_transient = get_transient('swsib_warned_inactive_users');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        foreach ($inactive_users as $user) {
            $admin_id = $user['admin_id'];
            $email = $user['email'];
            
            // Skip if already warned recently
            if (isset($warned_data[$admin_id]) && $warned_data[$admin_id] > $warning_threshold) {
                $already_warned++;
                continue;
            }
            
            // Get user's apps - IMPROVED QUERY
            $apps_query = "SELECT app.app_id, app.name 
                           FROM application app 
                           WHERE app.admin_id = {$admin_id}";
            $apps_result = $this->db_connection->query($apps_query);
            
            if (!$apps_result) {
                $this->log_message("Error getting apps for user {$admin_id}: " . $this->db_connection->error);
                continue;
            }
            
            $apps = array();
            if ($apps_result && $apps_result->num_rows > 0) {
                while ($app = $apps_result->fetch_assoc()) {
                    $apps[] = $app;
                }
            }
            $apps_result->free_result(); // Free the result
            
            // Send warning email
            $subject = $settings['warning_subject'];
            $message = $settings['warning_message'];
            
            // Replace placeholders
            $subject = str_replace('{name}', $user['firstname'] . ' ' . $user['lastname'], $subject);
            $subject = str_replace('{email}', $email, $subject);
            $subject = str_replace('{days}', $settings['warning_period'], $subject);
            
            $message = str_replace('{name}', $user['firstname'] . ' ' . $user['lastname'], $message);
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
                $warned_users++;
                
                $this->log_message("Sent warning email to inactive user: $email (ID: $admin_id)");
            } else {
                $errors++;
                $this->log_message("Failed to send warning email to inactive user: $email (ID: $admin_id)");
            }
        }
        
        // Update warned users transient
        set_transient('swsib_warned_inactive_users', $warned_data, 30 * DAY_IN_SECONDS);
        
        // Update task information
        $task_data['processed'] = count($inactive_users);
        $task_data['warned'] = $warned_users;
        $task_data['errors'] = $errors;
        $task_data['logs'][] = array(
            'time' => time(),
            'message' => "Sent $warned_users warning emails, skipped $already_warned already warned users, with $errors errors",
            'type' => 'info'
        );
        $task_data['status'] = 'completed';
        
        set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
        set_transient($progress_key, $task_data, DAY_IN_SECONDS);
        
        // Schedule deletion after warning period
        wp_schedule_single_event(
            time() + $this->get_period_in_seconds($settings['warning_period'], 'days'),
            'swsib_process_user_management',
            array($task_id . '_deletion', 'inactive_deletion')
        );
        
        // Store task connection for later
        set_transient('swsib_inactive_deletion_task_' . $task_id . '_deletion', $task_id, 31 * DAY_IN_SECONDS);
    }
    
    /**
     * Process inactive users deletion for scheduled tasks
     */
    public function process_inactive_users_deletion($task_id, $task_data, $progress_key) {
        // Check if this is a follow-up to a warning task
        $original_task_id = '';
        if (strpos($task_id, '_deletion') !== false) {
            $original_task_id = get_transient('swsib_inactive_deletion_task_' . $task_id);
            
            if ($original_task_id) {
                // For scheduled tasks that were warned, use batch processing
                $data_handler = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
                $data_handler->initialize_task('inactive');
                
                // Process all batches
                $this->process_all_batches('inactive');
                
                // Update task data
                $progress_file = $this->get_progress_file('inactive');
                if (file_exists($progress_file)) {
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    if ($progress_data) {
                        $task_data = array_merge($task_data, $progress_data);
                        $task_data['status'] = $progress_data['status'];
                        set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
                        set_transient($progress_key, $task_data, DAY_IN_SECONDS);
                    }
                }
                
                // Delete the transients
                delete_transient('swsib_inactive_deletion_task_' . $task_id);
                delete_transient('swsib_inactive_users_' . $original_task_id);
            }
        }
    }
    
    /**
     * Process users without apps warnings
     */
    public function process_users_without_apps_warnings($task_id, $task_data, $users_without_apps, $warning_threshold, $settings, $progress_key) {
        $warned_users = 0;
        $already_warned = 0;
        $errors = 0;
        
        // Get users who have already been warned
        $warned_transient = get_transient('swsib_warned_users_without_apps');
        $warned_data = $warned_transient ? $warned_transient : array();
        
        foreach ($users_without_apps as $user) {
            $admin_id = $user['admin_id'];
            $email = $user['email'];
            
            // Skip if already warned recently
            if (isset($warned_data[$admin_id]) && $warned_data[$admin_id] > $warning_threshold) {
                $already_warned++;
                continue;
            }
            
            // Send warning email
            $subject = $settings['warning_subject'];
            $message = $settings['warning_message'];
            
            // Replace placeholders
            $subject = str_replace('{name}', $user['firstname'] . ' ' . $user['lastname'], $subject);
            $subject = str_replace('{email}', $email, $subject);
            $subject = str_replace('{days}', $settings['warning_period'], $subject);
            
            $message = str_replace('{name}', $user['firstname'] . ' ' . $user['lastname'], $message);
            $message = str_replace('{email}', $email, $message);
            $message = str_replace('{days}', $settings['warning_period'], $message);
            $message = str_replace('{registration_date}', $user['created_at'], $message);
            
            // Send email
            $sent = $this->email_manager->send_email($email, $subject, $message);
            
            if ($sent) {
                // Record warning
                $warned_data[$admin_id] = time();
                $warned_users++;
                
                $this->log_message("Sent warning email to user without apps: $email (ID: $admin_id)");
            } else {
                $errors++;
                $this->log_message("Failed to send warning email to user without apps: $email (ID: $admin_id)");
            }
        }
        
        // Update warned users transient
        set_transient('swsib_warned_users_without_apps', $warned_data, 30 * DAY_IN_SECONDS);
        
        // Update task information
        $task_data['processed'] = count($users_without_apps);
        $task_data['warned'] = $warned_users;
        $task_data['errors'] = $errors;
        $task_data['logs'][] = array(
            'time' => time(),
            'message' => "Sent $warned_users warning emails, skipped $already_warned already warned users, with $errors errors",
            'type' => 'info'
        );
        $task_data['status'] = 'completed';
        
        set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
        set_transient($progress_key, $task_data, DAY_IN_SECONDS);
        
        // Schedule deletion after warning period
        wp_schedule_single_event(
            time() + $this->get_period_in_seconds($settings['warning_period'], 'days'),
            'swsib_process_user_management',
            array($task_id . '_deletion', 'no_apps_deletion')
        );
        
        // Store task connection for later
        set_transient('swsib_no_apps_deletion_task_' . $task_id . '_deletion', $task_id, 31 * DAY_IN_SECONDS);
    }
    
    /**
     * Process users without apps deletion for scheduled tasks
     */
    public function process_users_without_apps_deletion($task_id, $task_data, $progress_key) {
        // Check if this is a follow-up to a warning task
        $original_task_id = '';
        if (strpos($task_id, '_deletion') !== false) {
            $original_task_id = get_transient('swsib_no_apps_deletion_task_' . $task_id);
            
            if ($original_task_id) {
                // For scheduled tasks that were warned, use batch processing
                $data_handler = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
                $data_handler->initialize_task('no_apps');
                
                // Process all batches
                $this->process_all_batches('no_apps');
                
                // Update task data
                $progress_file = $this->get_progress_file('no_apps');
                if (file_exists($progress_file)) {
                    $progress_data = json_decode(file_get_contents($progress_file), true);
                    
                    if ($progress_data) {
                        $task_data = array_merge($task_data, $progress_data);
                        $task_data['status'] = $progress_data['status'];
                        set_transient('swsib_task_' . $task_id, $task_data, DAY_IN_SECONDS);
                        set_transient($progress_key, $task_data, DAY_IN_SECONDS);
                    }
                }
                
                // Delete the transients
                delete_transient('swsib_no_apps_deletion_task_' . $task_id);
                delete_transient('swsib_users_without_apps_' . $original_task_id);
            }
        }
    }
    
    /**
     * Convert period settings to seconds
     */
    private function get_period_in_seconds($value, $unit) {
        switch ($unit) {
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