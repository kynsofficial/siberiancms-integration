<?php
/**
 * User Management - Settings Handler
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_User_Settings {
    
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
     * Set database connection
     * 
     * @param mysqli $db_connection The database connection
     */
    public function set_db_connection($db_connection) {
        $this->db_connection = $db_connection;
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
     * Log message
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * AJAX handler for saving user management automation settings
     */
    public function ajax_save_user_management_automation() {
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
        
        // Parse settings from form data
        parse_str($_POST['settings'], $settings);
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize automate options if not exists
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        
        // Initialize user management options if not exists
        if (!isset($options['automate']['user_management'])) {
            $options['automate']['user_management'] = array();
        }
        
        // Identify which card settings are being updated based on form fields
        $card_type = null;
        
        if (isset($settings['inactive_users_frequency'])) {
            $card_type = 'inactive';
        } elseif (isset($settings['users_no_apps_frequency'])) {
            $card_type = 'no_apps';
        }
        
        if (!$card_type) {
            wp_send_json_error(array('message' => 'Could not identify which card settings to update.'));
            return;
        }
        
        $this->log_message("Updating user management settings for card: {$card_type}");
        
        // Only update the settings for the specific card type
        switch ($card_type) {
            case 'inactive':
                // Make sure we preserve newlines in the message
                $warning_message = isset($settings['inactive_users_warning_message']) ? 
                    $this->prepare_email_content($settings['inactive_users_warning_message']) : '';
                
                $options['automate']['user_management']['inactive'] = array(
                    'enabled' => isset($settings['inactive_users_enabled']),
                    'frequency' => sanitize_text_field($settings['inactive_users_frequency']),
                    'custom_value' => isset($settings['inactive_users_custom_value']) ? intval($settings['inactive_users_custom_value']) : 0,
                    'custom_unit' => isset($settings['inactive_users_custom_unit']) ? sanitize_text_field($settings['inactive_users_custom_unit']) : 'days',
                    'inactivity_period' => isset($settings['inactive_users_inactivity_period']) ? intval($settings['inactive_users_inactivity_period']) : 365,
                    'inactivity_unit' => isset($settings['inactive_users_inactivity_unit']) ? sanitize_text_field($settings['inactive_users_inactivity_unit']) : 'days',
                    'action' => 'warn',  // Default to warn first
                    'send_warning' => isset($settings['inactive_users_send_warning']),
                    'warning_period' => isset($settings['inactive_users_warning_period']) ? intval($settings['inactive_users_warning_period']) : 7,
                    'warning_subject' => isset($settings['inactive_users_warning_subject']) ? sanitize_text_field($settings['inactive_users_warning_subject']) : '',
                    'warning_message' => $warning_message
                );
                break;
                
            case 'no_apps':
                // Make sure we preserve newlines in the message
                $warning_message = isset($settings['users_no_apps_warning_message']) ? 
                    $this->prepare_email_content($settings['users_no_apps_warning_message']) : '';
                
                $options['automate']['user_management']['no_apps'] = array(
                    'enabled' => isset($settings['users_no_apps_enabled']),
                    'frequency' => sanitize_text_field($settings['users_no_apps_frequency']),
                    'custom_value' => isset($settings['users_no_apps_custom_value']) ? intval($settings['users_no_apps_custom_value']) : 0,
                    'custom_unit' => isset($settings['users_no_apps_custom_unit']) ? sanitize_text_field($settings['users_no_apps_custom_unit']) : 'days',
                    'grace_period' => isset($settings['users_no_apps_grace_period']) ? intval($settings['users_no_apps_grace_period']) : 30,
                    'grace_unit' => isset($settings['users_no_apps_grace_unit']) ? sanitize_text_field($settings['users_no_apps_grace_unit']) : 'days',
                    'check_inactivity' => isset($settings['users_no_apps_check_inactivity']),
                    'inactivity_period' => isset($settings['users_no_apps_inactivity_period']) ? intval($settings['users_no_apps_inactivity_period']) : 90,
                    'inactivity_unit' => isset($settings['users_no_apps_inactivity_unit']) ? sanitize_text_field($settings['users_no_apps_inactivity_unit']) : 'days',
                    'action' => 'warn',  // Default to warn first
                    'send_warning' => isset($settings['users_no_apps_send_warning']),
                    'warning_period' => isset($settings['users_no_apps_warning_period']) ? intval($settings['users_no_apps_warning_period']) : 7,
                    'warning_subject' => isset($settings['users_no_apps_warning_subject']) ? sanitize_text_field($settings['users_no_apps_warning_subject']) : '',
                    'warning_message' => $warning_message
                );
                break;
                
            default:
                wp_send_json_error(array('message' => 'Unknown card type.'));
                return;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        // Update only the schedule for the card being saved
        $card_settings = array($card_type => $options['automate']['user_management'][$card_type]);
        $this->update_user_management_schedules($card_settings);
        
        wp_send_json_success(array('message' => 'User management settings saved successfully.'));
    }
    
    /**
     * Prepare email content by handling newlines properly
     * 
     * @param string $content Raw content from textarea
     * @return string Properly formatted content
     */
    private function prepare_email_content($content) {
        // Strip slashes that may have been added by the form
        $content = stripslashes($content);
        
        // Convert Windows-style line endings to Unix style
        $content = str_replace("\r\n", "\n", $content);
        
        // Apply basic sanitization but preserve line breaks
        $content = sanitize_textarea_field($content);
        
        return $content;
    }
    
    /**
     * Update user management schedules
     */
    private function update_user_management_schedules($settings) {
        // Load the scheduler
        if (class_exists('SwiftSpeed_Siberian_Scheduler')) {
            $scheduler = new SwiftSpeed_Siberian_Scheduler();
            
            // Only update schedules for the specified cards
            foreach ($settings as $task_type => $task_settings) {
                // Schedule or unschedule based on enabled status
                if (!empty($task_settings['enabled'])) {
                    $interval = $this->get_frequency_in_seconds($task_settings);
                    $this->log_message("Scheduling task user_management for {$task_type} with interval {$interval} seconds");
                    
                    $scheduler->schedule_task(
                        'user_management',
                        array('task' => $task_type),
                        $interval
                    );
                    
                    // Force immediate check to ensure the task is scheduled properly
                    $scheduler->schedule_next_check(true);
                } else {
                    $scheduler->unschedule_task('user_management', array('task' => $task_type));
                    $this->log_message("Unscheduled task user_management for {$task_type}");
                }
            }
        } else {
            $this->log_message("Scheduler class not found. Could not update schedules.");
        }
    }
    
    /**
     * Convert frequency settings to seconds
     */
    private function get_frequency_in_seconds($settings) {
        if ($settings['frequency'] === 'custom' && !empty($settings['custom_value']) && !empty($settings['custom_unit'])) {
            $value = intval($settings['custom_value']);
            $unit = $settings['custom_unit'];
            
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
                    return 86400; // Default to daily
            }
        }
        
        switch ($settings['frequency']) {
            case 'every_5_minutes':
                return 300;
            case 'every_10_minutes':
                return 600;
            case 'every_15_minutes':
                return 900;
            case 'every_30_minutes':
                return 1800;
            case 'hourly':
                return 3600;
            case 'every_2_hours':
                return 7200;
            case 'every_5_hours':
                return 18000;
            case 'every_12_hours':
                return 43200;
            case 'daily':
                return 86400;
            case 'every_3_days':
                return 259200;
            case 'weekly':
                return 604800;
            case 'every_2_weeks':
                return 1209600;
            case 'monthly':
                return 2592000; // 30 days
            case 'every_3_months':
                return 7776000; // 90 days
            case 'every_6_months':
                return 15552000; // 180 days
            case 'every_9_months':
                return 23328000; // 270 days
            case 'yearly':
                return 31536000; // 365 days
            default:
                return 86400; // Default to daily
            }
    }
    
    /**
     * Get settings for user management automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['user_management'])) {
            return array(
                'inactive' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'inactivity_period' => 365,
                    'inactivity_unit' => 'days',
                    'action' => 'warn',
                    'send_warning' => true,
                    'warning_period' => 7,
                    'warning_subject' => __('Your account will be removed due to inactivity', 'swiftspeed-siberian'),
                    'warning_message' => "Dear {user_name},\n\nYour account ({user_email}) has been inactive for a long time. If you don't log in within the next {days_remaining} days, your account and all your applications will be deleted.\n\nYour apps:\n{app_list}\n\nLast login: {last_login}\n\nTo prevent this, please log in to your account at {login_url}.\n\nThank you,\n{site_name} Team"
                ),
                'no_apps' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'grace_period' => 30,
                    'grace_unit' => 'days',
                    'check_inactivity' => false,
                    'inactivity_period' => 90,
                    'inactivity_unit' => 'days',
                    'action' => 'warn',
                    'send_warning' => true,
                    'warning_period' => 7,
                    'warning_subject' => __('Your account will be removed due to no applications', 'swiftspeed-siberian'),
                    'warning_message' => "Dear {user_name},\n\nYour account ({user_email}) was created on {registration_date} but you haven't created any applications yet. If you don't create an application within the next {days_remaining} days, your account will be deleted.\n\nPlease log in and create an application to keep your account active.\n\nThank you,\n{site_name} Team"
                )
            );
        }
        
        return $options['automate']['user_management'];
    }
    
    /**
     * Process settings for user management automation
     */
    public function process_settings($input) {
        // Settings are processed via AJAX
        return $input;
    }
    
    /**
     * Display user management automation settings
     */
    public function display_settings() {
        // Get settings
        $settings = $this->get_settings();
        
        // Get inactive users settings
        $inactive = isset($settings['inactive']) ? $settings['inactive'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'inactivity_period' => 365,
            'inactivity_unit' => 'days',
            'action' => 'warn',
            'send_warning' => true,
            'warning_period' => 7,
            'warning_subject' => __('Your account will be removed due to inactivity', 'swiftspeed-siberian'),
            'warning_message' => "Dear {user_name},\n\nYour account ({user_email}) has been inactive for a long time. If you don't log in within the next {days_remaining} days, your account and all your applications will be deleted.\n\nYour apps:\n{app_list}\n\nLast login: {last_login}\n\nTo prevent this, please log in to your account at {login_url}.\n\nThank you,\n{site_name} Team"
        );
        
        // Get users without apps settings
        $no_apps = isset($settings['no_apps']) ? $settings['no_apps'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'grace_period' => 30,
            'grace_unit' => 'days',
            'check_inactivity' => false,
            'inactivity_period' => 90,
            'inactivity_unit' => 'days',
            'action' => 'warn',
            'send_warning' => true,
            'warning_period' => 7,
            'warning_subject' => __('Your account will be removed due to no applications', 'swiftspeed-siberian'),
            'warning_message' => "Dear {user_name},\n\nYour account ({user_email}) was created on {registration_date} but you haven't created any applications yet. If you don't create an application within the next {days_remaining} days, your account will be deleted.\n\nPlease log in and create an application to keep your account active.\n\nThank you,\n{site_name} Team"
        );
        
        // Create a new data instance to get counts
        $user_data = new SwiftSpeed_Siberian_User_Data($this->db_connection, $this->db_name);
        
        // Get count of inactive users
        $inactive_count = $user_data->get_inactive_users_count();
        
        // Get count of users without apps
        $no_apps_count = $user_data->get_users_without_apps_count();
        
        ?>
        <div class="task-section">
            <h3><?php _e('User Management', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('Automated cleanup of inactive users and users without applications.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="task-grid">
                <!-- Inactive Users -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Inactive Users', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($inactive['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($inactive['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes users who have not logged in for a specified period of time.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value inactive-users-count"><?php echo $inactive_count; ?></span>
                            <span><?php _e('Inactive Users', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-user-data-button" data-type="inactive">
                            <?php _e('Preview Users', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-user-management" data-task="inactive">
                            <?php _e('Run Cleanup', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                    
                    <div class="task-settings">
                        <div class="task-settings-header">
                            <h4 class="task-settings-title"><?php _e('Automation Settings', 'swiftspeed-siberian'); ?></h4>
                            <span class="task-settings-toggle">
                                <span class="dashicons dashicons-arrow-down"></span>
                            </span>
                        </div>
                        
                        <div class="task-settings-fields">
                            <form>
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="inactive_users_enabled" value="1" <?php checked(!empty($inactive['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="inactive_users_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="inactive_users_frequency" id="inactive_users_frequency">
                                            <option value="every_5_minutes" <?php selected($inactive['frequency'], 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_10_minutes" <?php selected($inactive['frequency'], 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_15_minutes" <?php selected($inactive['frequency'], 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_30_minutes" <?php selected($inactive['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($inactive['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($inactive['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($inactive['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($inactive['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($inactive['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($inactive['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($inactive['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($inactive['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $inactive['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="inactive_users_custom_value" value="<?php echo esc_attr($inactive['custom_value']); ?>" min="1" step="1">
                                        <select name="inactive_users_custom_unit">
                                            <option value="minutes" <?php selected($inactive['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($inactive['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($inactive['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($inactive['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($inactive['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="inactive_users_inactivity_period"><?php _e('Inactivity Period', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="inactive_users_inactivity_period" id="inactive_users_inactivity_period" value="<?php echo esc_attr($inactive['inactivity_period']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <select name="inactive_users_inactivity_unit" style="width: 120px;">
                                                <option value="days" <?php selected($inactive['inactivity_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                                <option value="weeks" <?php selected($inactive['inactivity_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                                <option value="months" <?php selected($inactive['inactivity_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                                <option value="years" <?php selected($inactive['inactivity_unit'], 'years'); ?>><?php _e('Years', 'swiftspeed-siberian'); ?></option>
                                            </select>
                                        </div>
                                        <p class="description"><?php _e('Users who have not logged in for this period will be considered inactive.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="inactive_users_send_warning" value="1" <?php checked(!empty($inactive['send_warning'])); ?>>
                                            <?php _e('Send warning email before deletion', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label for="inactive_users_warning_period"><?php _e('Warning Period (Days)', 'swiftspeed-siberian'); ?></label>
                                        <input type="number" name="inactive_users_warning_period" id="inactive_users_warning_period" value="<?php echo esc_attr($inactive['warning_period']); ?>" min="1" step="1">
                                        <p class="description"><?php _e('Number of days before inactive users are deleted after warning.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="email-template-header">
                                    <h4><?php _e('Warning Email Template', 'swiftspeed-siberian'); ?></h4>
                                    <span class="email-template-toggle">
                                        <span class="dashicons dashicons-arrow-down"></span>
                                    </span>
                                </div>
                                
                                <div class="email-template-content" style="display: none;">
                                    <div class="email-template-field">
                                        <label for="inactive_users_warning_subject"><?php _e('Subject', 'swiftspeed-siberian'); ?></label>
                                        <input type="text" name="inactive_users_warning_subject" id="inactive_users_warning_subject" value="<?php echo esc_attr($inactive['warning_subject']); ?>" class="widefat">
                                    </div>
                                    
                                    <div class="email-template-field">
                                        <label for="inactive_users_warning_message"><?php _e('Message', 'swiftspeed-siberian'); ?></label>
                                        <textarea name="inactive_users_warning_message" id="inactive_users_warning_message" rows="10" class="widefat"><?php echo esc_textarea($inactive['warning_message']); ?></textarea>
                                    </div>
                                    
                                    <div class="email-placeholder-help">
                                        <p><?php _e('Available placeholders:', 'swiftspeed-siberian'); ?></p>
                                        <ul>
                                            <li><code>{user_name}</code> - <?php _e('User\'s full name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{user_email}</code> - <?php _e('User\'s email address', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{days_remaining}</code> - <?php _e('Warning period in days', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{last_login}</code> - <?php _e('User\'s last login date', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{app_list}</code> - <?php _e('List of user\'s applications', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{site_name}</code> - <?php _e('Your site name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{login_url}</code> - <?php _e('Login page URL', 'swiftspeed-siberian'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-user-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Users Without Apps -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Users Without Apps', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($no_apps['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($no_apps['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes users who registered but never created any applications.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value users-without-apps-count"><?php echo $no_apps_count; ?></span>
                            <span><?php _e('Users Without Apps', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-user-data-button" data-type="no_apps">
                            <?php _e('Preview Users', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-user-management" data-task="no_apps">
                            <?php _e('Run Cleanup', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                    
                    <div class="task-settings">
                        <div class="task-settings-header">
                            <h4 class="task-settings-title"><?php _e('Automation Settings', 'swiftspeed-siberian'); ?></h4>
                            <span class="task-settings-toggle">
                                <span class="dashicons dashicons-arrow-down"></span>
                            </span>
                        </div>
                        
                        <div class="task-settings-fields">
                            <form>
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="users_no_apps_enabled" value="1" <?php checked(!empty($no_apps['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="users_no_apps_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="users_no_apps_frequency" id="users_no_apps_frequency">
                                            <option value="every_5_minutes" <?php selected($no_apps['frequency'], 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_10_minutes" <?php selected($no_apps['frequency'], 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_15_minutes" <?php selected($no_apps['frequency'], 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_30_minutes" <?php selected($no_apps['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($no_apps['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($no_apps['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($no_apps['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($no_apps['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($no_apps['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($no_apps['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($no_apps['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($no_apps['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $no_apps['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="users_no_apps_custom_value" value="<?php echo esc_attr($no_apps['custom_value']); ?>" min="1" step="1">
                                        <select name="users_no_apps_custom_unit">
                                            <option value="minutes" <?php selected($no_apps['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($no_apps['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($no_apps['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($no_apps['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($no_apps['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="users_no_apps_grace_period"><?php _e('Grace Period', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="users_no_apps_grace_period" id="users_no_apps_grace_period" value="<?php echo esc_attr($no_apps['grace_period']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <select name="users_no_apps_grace_unit" style="width: 120px;">
                                                <option value="days" <?php selected($no_apps['grace_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                                <option value="weeks" <?php selected($no_apps['grace_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                                <option value="months" <?php selected($no_apps['grace_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                            </select>
                                        </div>
                                        <p class="description"><?php _e('How long after registration users have to create their first app.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <!-- Inactivity Filter for Users Without Apps -->
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="users_no_apps_check_inactivity" value="1" <?php checked(!empty($no_apps['check_inactivity'])); ?>>
                                            <?php _e('Also check for inactivity', 'swiftspeed-siberian'); ?>
                                        </label>
                                        <p class="description"><?php _e('Additionally filter users who haven\'t logged in for a period of time.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                    
                                    <div class="task-settings-field" style="margin-top: 10px;">
                                        <label for="users_no_apps_inactivity_period"><?php _e('Inactivity Period', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="users_no_apps_inactivity_period" id="users_no_apps_inactivity_period" value="<?php echo esc_attr($no_apps['inactivity_period']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <select name="users_no_apps_inactivity_unit" style="width: 120px;">
                                                <option value="days" <?php selected($no_apps['inactivity_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                                <option value="weeks" <?php selected($no_apps['inactivity_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                                <option value="months" <?php selected($no_apps['inactivity_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                                <option value="years" <?php selected($no_apps['inactivity_unit'], 'years'); ?>><?php _e('Years', 'swiftspeed-siberian'); ?></option>
                                            </select>
                                        </div>
                                        <p class="description"><?php _e('Only include users who haven\'t logged in for this period.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="users_no_apps_send_warning" value="1" <?php checked(!empty($no_apps['send_warning'])); ?>>
                                            <?php _e('Send warning email before deletion', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label for="users_no_apps_warning_period"><?php _e('Warning Period (Days)', 'swiftspeed-siberian'); ?></label>
                                        <input type="number" name="users_no_apps_warning_period" id="users_no_apps_warning_period" value="<?php echo esc_attr($no_apps['warning_period']); ?>" min="1" step="1">
                                        <p class="description"><?php _e('Number of days before users without apps are deleted after warning.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="email-template-header">
                                    <h4><?php _e('Warning Email Template', 'swiftspeed-siberian'); ?></h4>
                                    <span class="email-template-toggle">
                                        <span class="dashicons dashicons-arrow-down"></span>
                                    </span>
                                </div>
                                
                                <div class="email-template-content" style="display: none;">
                                    <div class="email-template-field">
                                        <label for="users_no_apps_warning_subject"><?php _e('Subject', 'swiftspeed-siberian'); ?></label>
                                        <input type="text" name="users_no_apps_warning_subject" id="users_no_apps_warning_subject" value="<?php echo esc_attr($no_apps['warning_subject']); ?>" class="widefat">
                                    </div>
                                    
                                    <div class="email-template-field">
                                        <label for="users_no_apps_warning_message"><?php _e('Message', 'swiftspeed-siberian'); ?></label>
                                        <textarea name="users_no_apps_warning_message" id="users_no_apps_warning_message" rows="10" class="widefat"><?php echo esc_textarea($no_apps['warning_message']); ?></textarea>
                                    </div>
                                    
                                    <div class="email-placeholder-help">
                                        <p><?php _e('Available placeholders:', 'swiftspeed-siberian'); ?></p>
                                        <ul>
                                            <li><code>{user_name}</code> - <?php _e('User\'s full name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{user_email}</code> - <?php _e('User\'s email address', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{days_remaining}</code> - <?php _e('Warning period in days', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{registration_date}</code> - <?php _e('User\'s registration date', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{last_login}</code> - <?php _e('User\'s last login date', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{site_name}</code> - <?php _e('Your site name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{login_url}</code> - <?php _e('Login page URL', 'swiftspeed-siberian'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-user-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

      
        <?php
    }
}