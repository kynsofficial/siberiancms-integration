<?php
/**
 * Application Management - Settings handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_App_Settings {
    
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
     * AJAX handler for saving app management automation settings
     * Fixed to only update settings for the specific card being saved
     */
    public function ajax_save_app_management_automation() {
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
        
        // Initialize app management options if not exists
        if (!isset($options['automate']['app_management'])) {
            $options['automate']['app_management'] = array();
        }
        
        // Identify which card settings are being updated based on form fields
        $card_type = null;
        
        if (isset($settings['zero_size_apps_frequency'])) {
            $card_type = 'zero_size';
        } elseif (isset($settings['inactive_apps_frequency'])) {
            $card_type = 'inactive';
        } elseif (isset($settings['size_violation_apps_frequency'])) {
            $card_type = 'size_violation';
        } elseif (isset($settings['apps_no_users_frequency'])) {
            $card_type = 'no_users';
        }
        
        if (!$card_type) {
            wp_send_json_error(array('message' => 'Could not identify which card settings to update.'));
            return;
        }
        
        $this->log_message("Updating app management settings for card: {$card_type}");
        
        // Only update the settings for the specific card type
        switch ($card_type) {
            case 'zero_size':
                $options['automate']['app_management']['zero_size'] = array(
                    'enabled' => isset($settings['zero_size_apps_enabled']),
                    'frequency' => sanitize_text_field($settings['zero_size_apps_frequency']),
                    'custom_value' => isset($settings['zero_size_apps_custom_value']) ? intval($settings['zero_size_apps_custom_value']) : 0,
                    'custom_unit' => isset($settings['zero_size_apps_custom_unit']) ? sanitize_text_field($settings['zero_size_apps_custom_unit']) : 'days'
                );
                break;
                
            case 'inactive':
                // Make sure we preserve newlines in the message
                $warning_message = isset($settings['inactive_apps_warning_message']) ? 
                    $this->prepare_email_content($settings['inactive_apps_warning_message']) : '';
                
                $options['automate']['app_management']['inactive'] = array(
                    'enabled' => isset($settings['inactive_apps_enabled']),
                    'frequency' => sanitize_text_field($settings['inactive_apps_frequency']),
                    'custom_value' => isset($settings['inactive_apps_custom_value']) ? intval($settings['inactive_apps_custom_value']) : 0,
                    'custom_unit' => isset($settings['inactive_apps_custom_unit']) ? sanitize_text_field($settings['inactive_apps_custom_unit']) : 'days',
                    'send_warning' => isset($settings['inactive_apps_send_warning']),
                    'warning_period' => intval($settings['inactive_apps_warning_period']),
                    'warning_subject' => sanitize_text_field($settings['inactive_apps_warning_subject']),
                    'warning_message' => $warning_message
                );
                break;
                
            case 'size_violation':
                // Make sure we preserve newlines in the message
                $warning_message = isset($settings['size_violation_apps_warning_message']) ? 
                    $this->prepare_email_content($settings['size_violation_apps_warning_message']) : '';
                
                $options['automate']['app_management']['size_violation'] = array(
                    'enabled' => isset($settings['size_violation_apps_enabled']),
                    'frequency' => sanitize_text_field($settings['size_violation_apps_frequency']),
                    'custom_value' => isset($settings['size_violation_apps_custom_value']) ? intval($settings['size_violation_apps_custom_value']) : 0,
                    'custom_unit' => isset($settings['size_violation_apps_custom_unit']) ? sanitize_text_field($settings['size_violation_apps_custom_unit']) : 'days',
                    'delete_immediately' => isset($settings['size_violation_apps_delete_immediately']),
                    'send_warning' => isset($settings['size_violation_apps_send_warning']),
                    'warning_period' => intval($settings['size_violation_apps_warning_period']),
                    'warning_subject' => sanitize_text_field($settings['size_violation_apps_warning_subject']),
                    'warning_message' => $warning_message
                );
                break;
                
            case 'no_users':
                // Make sure we preserve newlines in the message
                $warning_message = isset($settings['apps_no_users_warning_message']) ? 
                    $this->prepare_email_content($settings['apps_no_users_warning_message']) : '';
                
                $options['automate']['app_management']['no_users'] = array(
                    'enabled' => isset($settings['apps_no_users_enabled']),
                    'frequency' => sanitize_text_field($settings['apps_no_users_frequency']),
                    'custom_value' => isset($settings['apps_no_users_custom_value']) ? intval($settings['apps_no_users_custom_value']) : 0,
                    'custom_unit' => isset($settings['apps_no_users_custom_unit']) ? sanitize_text_field($settings['apps_no_users_custom_unit']) : 'days',
                    'send_warning' => isset($settings['apps_no_users_send_warning']),
                    'warning_period' => intval($settings['apps_no_users_warning_period']),
                    'warning_subject' => sanitize_text_field($settings['apps_no_users_warning_subject']),
                    'warning_message' => $warning_message
                );
                break;
                
            default:
                wp_send_json_error(array('message' => 'Unknown card type.'));
                return;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        // Log the updated settings for the specific card
        $this->log_message("Updated app management settings for {$card_type}. Enabled: " . 
                         ($options['automate']['app_management'][$card_type]['enabled'] ? 'yes' : 'no'));
        
        // Update schedules - only for the specific card that was changed
        $card_settings = array($card_type => $options['automate']['app_management'][$card_type]);
        $this->update_app_management_schedules($card_settings);
        
        wp_send_json_success(array('message' => 'App management automation settings saved.'));
    }
    
    /**
     * AJAX handler for saving subscription size limits
     */
    public function ajax_save_subscription_size_limits() {
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
        
        // Get size limits
        $limits = isset($_POST['limits']) ? $_POST['limits'] : array();
        
        if (empty($limits) || !is_array($limits)) {
            wp_send_json_error(array('message' => 'No subscription size limits provided.'));
            return;
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize automate options if not exists
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        
        // Initialize subscription size limits if not exists
        if (!isset($options['automate']['subscription_size_limits'])) {
            $options['automate']['subscription_size_limits'] = array();
        }
        
        // Clear existing limits
        $options['automate']['subscription_size_limits'] = array();
        
        // Save new limits
        foreach ($limits as $limit) {
            $subscription_id = intval($limit['subscription_id']);
            $size_limit = intval($limit['size_limit']);
            
            if ($subscription_id > 0 && $size_limit >= 0) {
                $options['automate']['subscription_size_limits'][$subscription_id] = $size_limit;
            }
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        wp_send_json_success(array('message' => 'Subscription size limits saved.'));
    }
    
    /**
     * Update app management schedules
     * Modified to only update schedules for specific cards
     */
    private function update_app_management_schedules($settings) {
        // Load the scheduler
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        
        // Only update schedules for the provided settings (specific cards)
        foreach ($settings as $task => $task_settings) {
            if (!empty($task_settings['enabled'])) {
                $scheduler->schedule_task(
                    'app_management',
                    array('task' => $task),
                    $this->get_frequency_in_seconds($task_settings)
                );
                $this->log_message("Scheduled task app_management for {$task}");
            } else {
                $scheduler->unschedule_task('app_management', array('task' => $task));
                $this->log_message("Unscheduled task app_management for {$task}");
            }
        }
    }
    
    /**
     * Convert frequency settings to seconds
     */
    private function get_frequency_in_seconds($settings) {
        if ($settings['frequency'] === 'custom' && !empty($settings['custom_value']) && !empty($settings['custom_unit'])) {
            $value = intval($settings['custom_value']);
            $unit = $settings['custom_unit'];
            
            $this->log_message("Converting custom frequency to seconds: {$value} {$unit}");
            
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
            case 'every_minute':
                return 60;
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
     * Get settings for app management automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['app_management'])) {
            return array(
                'zero_size' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days'
                ),
                'inactive' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'send_warning' => true,
                    'warning_period' => 7,
                    'warning_subject' => __('Your application will be removed', 'swiftspeed-siberian'),
                    'warning_message' => "Dear {name},\n\nYour application '{app_name}' (ID: {app_id}) has been marked as inactive. If you don't reactivate it within the next {days} days, it will be permanently deleted.\n\nPlease log in to reactivate your application.\n\nRegards,\nThe Admin Team"
                ),
                'size_violation' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'delete_immediately' => false,
                    'send_warning' => true,
                    'warning_period' => 7,
                    'warning_subject' => __('Your application exceeds size limit', 'swiftspeed-siberian'),
                    'warning_message' => "Dear {name},\n\nYour application '{app_name}' (ID: {app_id}) exceeds the size limit for your subscription plan.\n\nCurrent size: {current_size} MB\nSize limit: {size_limit} MB\n\nPlease reduce the size of your application or upgrade your subscription plan within the next {days} days to avoid deletion.\n\nRegards,\nThe Admin Team"
                ),
                'no_users' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'send_warning' => false,
                    'warning_period' => 7,
                    'warning_subject' => __('Orphaned application will be removed', 'swiftspeed-siberian'),
                    'warning_message' => "Hello Administrator,\n\nThe application '{app_name}' (ID: {app_id}) has no associated user account. It will be deleted in {days} days.\n\nThis is an automated notification.\n\nRegards,\nThe Admin Team"
                )
            );
        }
        
        return $options['automate']['app_management'];
    }
    
    /**
     * Process settings for app management automation
     */
    public function process_settings($input) {
        // Settings are processed via AJAX
        return $input;
    }
    
    /**
     * Display app management settings
     * Default versions of templates are now formatted properly
     */
    public function display_settings() {
        // Get settings
        $settings = $this->get_settings();
        
        // Get zero size apps settings
        $zero_size = isset($settings['zero_size']) ? $settings['zero_size'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days'
        );
        
        // Get inactive apps settings
        $inactive = isset($settings['inactive']) ? $settings['inactive'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'send_warning' => true,
            'warning_period' => 7,
            'warning_subject' => __('Your application will be removed', 'swiftspeed-siberian'),
            'warning_message' => "Dear {name},\n\nYour application '{app_name}' (ID: {app_id}) has been marked as inactive. If you don't reactivate it within the next {days} days, it will be permanently deleted.\n\nPlease log in to reactivate your application.\n\nRegards,\nThe Admin Team"
        );
        
        // Get size violation apps settings
        $size_violation = isset($settings['size_violation']) ? $settings['size_violation'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'delete_immediately' => false,
            'send_warning' => true,
            'warning_period' => 7,
            'warning_subject' => __('Your application exceeds size limit', 'swiftspeed-siberian'),
            'warning_message' => "Dear {name},\n\nYour application '{app_name}' (ID: {app_id}) exceeds the size limit for your subscription plan.\n\nCurrent size: {current_size} MB\nSize limit: {size_limit} MB\n\nPlease reduce the size of your application or upgrade your subscription plan within the next {days} days to avoid deletion.\n\nRegards,\nThe Admin Team"
        );
        
        // Get apps without users settings
        $no_users = isset($settings['no_users']) ? $settings['no_users'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'send_warning' => false,
            'warning_period' => 7,
            'warning_subject' => __('Orphaned application will be removed', 'swiftspeed-siberian'),
            'warning_message' => "Hello Administrator,\n\nThe application '{app_name}' (ID: {app_id}) has no associated user account. It will be deleted in {days} days.\n\nThis is an automated notification.\n\nRegards,\nThe Admin Team"
        );
        
        // Get subscription plans
        $data = new SwiftSpeed_Siberian_App_Data($this->db_connection, $this->db_name);
        $subscriptions = $data->get_subscription_plans();
        
        // Get subscription size limits
        $size_limits = $data->get_subscription_size_limits();
        
        // Get counts
        $zero_size_count = $data->get_zero_size_apps_count();
        $inactive_count = $data->get_inactive_apps_count();
        $size_violation_count = $data->get_size_violation_apps_count();
        $no_users_count = $data->get_apps_without_users_count();
        
        ?>
        <div class="task-section">
            <h3><?php _e('Application Management', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('Automated cleanup of applications based on various criteria.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="task-grid">
                <!-- Zero Size Apps -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Zero Size Apps', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($zero_size['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($zero_size['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes applications that have no data (0 bytes size on disk).', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value zero-size-apps-count"><?php echo $zero_size_count; ?></span>
                            <span><?php _e('Zero Size Apps', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-app-data-button" data-type="zero_size">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-app-management" data-task="zero_size">
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
                                            <input type="checkbox" name="zero_size_apps_enabled" value="1" <?php checked(!empty($zero_size['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="zero_size_apps_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="zero_size_apps_frequency" id="zero_size_apps_frequency">
                                            <option value="every_minute" <?php selected($zero_size['frequency'], 'every_minute'); ?>><?php _e('Every Minute', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_minutes" <?php selected($zero_size['frequency'], 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_10_minutes" <?php selected($zero_size['frequency'], 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_15_minutes" <?php selected($zero_size['frequency'], 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_30_minutes" <?php selected($zero_size['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($zero_size['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($zero_size['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($zero_size['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($zero_size['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($zero_size['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($zero_size['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($zero_size['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($zero_size['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $zero_size['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="zero_size_apps_custom_value" value="<?php echo esc_attr($zero_size['custom_value']); ?>" min="1" step="1">
                                        <select name="zero_size_apps_custom_unit">
                                            <option value="minutes" <?php selected($zero_size['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($zero_size['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($zero_size['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($zero_size['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($zero_size['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-app-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Inactive Apps (renamed to Deleted Apps) -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Deleted Apps', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($inactive['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($inactive['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes applications that have been deleted by the user from their editor.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value inactive-apps-count"><?php echo $inactive_count; ?></span>
                            <span><?php _e('Deleted Apps', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-app-data-button" data-type="inactive">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-app-management" data-task="inactive">
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
                                            <input type="checkbox" name="inactive_apps_enabled" value="1" <?php checked(!empty($inactive['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="inactive_apps_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="inactive_apps_frequency" id="inactive_apps_frequency">
                                            <option value="every_minute" <?php selected($inactive['frequency'], 'every_minute'); ?>><?php _e('Every Minute', 'swiftspeed-siberian'); ?></option>
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
                                        <input type="number" name="inactive_apps_custom_value" value="<?php echo esc_attr($inactive['custom_value']); ?>" min="1" step="1">
                                        <select name="inactive_apps_custom_unit">
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
                                        <label>
                                            <input type="checkbox" name="inactive_apps_send_warning" value="1" <?php checked(!empty($inactive['send_warning'])); ?>>
                                            <?php _e('Send warning email before deletion', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label for="inactive_apps_warning_period"><?php _e('Warning Period (Days)', 'swiftspeed-siberian'); ?></label>
                                        <input type="number" name="inactive_apps_warning_period" id="inactive_apps_warning_period" value="<?php echo esc_attr($inactive['warning_period']); ?>" min="1" step="1">
                                        <p class="description"><?php _e('Number of days before deleted apps are permanently removed after warning.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="email-template-header">
                                    <h4><?php _e('Warning Email Template', 'swiftspeed-siberian'); ?></h4>
                                    <span class="email-template-toggle">
                                        <span class="dashicons dashicons-arrow-down"></span>
                                    </span>
                                </div>
                                
                                <div class="email-template-content">
                                    <div class="email-template-field">
                                        <label for="inactive_apps_warning_subject"><?php _e('Subject', 'swiftspeed-siberian'); ?></label>
                                        <input type="text" name="inactive_apps_warning_subject" id="inactive_apps_warning_subject" value="<?php echo esc_attr($inactive['warning_subject']); ?>">
                                    </div>
                                    
                                    <div class="email-template-field">
                                        <label for="inactive_apps_warning_message"><?php _e('Message', 'swiftspeed-siberian'); ?></label>
                                        <textarea name="inactive_apps_warning_message" id="inactive_apps_warning_message" rows="10"><?php echo esc_textarea($inactive['warning_message']); ?></textarea>
                                    </div>
                                    
                                    <div class="email-placeholder-help">
                                        <p><?php _e('Available placeholders:', 'swiftspeed-siberian'); ?></p>
                                        <ul>
                                            <li><code>{name}</code> - <?php _e('User\'s full name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{email}</code> - <?php _e('User\'s email address', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{app_name}</code> - <?php _e('Application name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{app_id}</code> - <?php _e('Application ID', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{days}</code> - <?php _e('Warning period in days', 'swiftspeed-siberian'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-app-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Size Violation Apps -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Size Violation Apps', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($size_violation['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($size_violation['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes applications that exceed their subscription plan\'s size limit.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value size-violation-apps-count"><?php echo $size_violation_count; ?></span>
                            <span><?php _e('Size Violation Apps', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-app-data-button" data-type="size_violation">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-app-management" data-task="size_violation">
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
                                            <input type="checkbox" name="size_violation_apps_enabled" value="1" <?php checked(!empty($size_violation['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="size_violation_apps_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="size_violation_apps_frequency" id="size_violation_apps_frequency">
                                            <option value="every_minute" <?php selected($size_violation['frequency'], 'every_minute'); ?>><?php _e('Every Minute', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_minutes" <?php selected($size_violation['frequency'], 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_10_minutes" <?php selected($size_violation['frequency'], 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_15_minutes" <?php selected($size_violation['frequency'], 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_30_minutes" <?php selected($size_violation['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($size_violation['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($size_violation['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($size_violation['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($size_violation['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($size_violation['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($size_violation['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($size_violation['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($size_violation['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $size_violation['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="size_violation_apps_custom_value" value="<?php echo esc_attr($size_violation['custom_value']); ?>" min="1" step="1">
                                        <select name="size_violation_apps_custom_unit">
                                            <option value="minutes" <?php selected($size_violation['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($size_violation['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($size_violation['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($size_violation['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($size_violation['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="size_violation_apps_delete_immediately" value="1" <?php checked(!empty($size_violation['delete_immediately'])); ?>>
                                            <?php _e('Delete immediately without warning', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="size_violation_apps_send_warning" value="1" <?php checked(!empty($size_violation['send_warning'])); ?>>
                                            <?php _e('Send warning email before deletion', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label for="size_violation_apps_warning_period"><?php _e('Warning Period (Days)', 'swiftspeed-siberian'); ?></label>
                                        <input type="number" name="size_violation_apps_warning_period" id="size_violation_apps_warning_period" value="<?php echo esc_attr($size_violation['warning_period']); ?>" min="1" step="1">
                                        <p class="description"><?php _e('Number of days before size-violating apps are deleted after warning.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                </div>
                                
                                <div class="email-template-header">
                                    <h4><?php _e('Warning Email Template', 'swiftspeed-siberian'); ?></h4>
                                    <span class="email-template-toggle">
                                        <span class="dashicons dashicons-arrow-down"></span>
                                    </span>
                                </div>
                                
                                <div class="email-template-content">
                                    <div class="email-template-field">
                                        <label for="size_violation_apps_warning_subject"><?php _e('Subject', 'swiftspeed-siberian'); ?></label>
                                        <input type="text" name="size_violation_apps_warning_subject" id="size_violation_apps_warning_subject" value="<?php echo esc_attr($size_violation['warning_subject']); ?>">
                                    </div>
                                    
                                    <div class="email-template-field">
                                        <label for="size_violation_apps_warning_message"><?php _e('Message', 'swiftspeed-siberian'); ?></label>
                                        <textarea name="size_violation_apps_warning_message" id="size_violation_apps_warning_message" rows="10"><?php echo esc_textarea($size_violation['warning_message']); ?></textarea>
                                    </div>
                                    
                                    <div class="email-placeholder-help">
                                        <p><?php _e('Available placeholders:', 'swiftspeed-siberian'); ?></p>
                                        <ul>
                                            <li><code>{name}</code> - <?php _e('User\'s full name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{email}</code> - <?php _e('User\'s email address', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{app_name}</code> - <?php _e('Application name', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{app_id}</code> - <?php _e('Application ID', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{days}</code> - <?php _e('Warning period in days', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{size_limit}</code> - <?php _e('Subscription size limit in MB', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{current_size}</code> - <?php _e('Current app size in MB', 'swiftspeed-siberian'); ?></li>
                                            <li><code>{subscription_id}</code> - <?php _e('Subscription ID', 'swiftspeed-siberian'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-app-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Apps Without Users -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Apps Without Users', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($no_users['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($no_users['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes applications whose owners have been deleted.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value apps-without-users-count"><?php echo $no_users_count; ?></span>
                            <span><?php _e('Orphaned Apps', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-app-data-button" data-type="no_users">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-app-management" data-task="no_users">
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
                                            <input type="checkbox" name="apps_no_users_enabled" value="1" <?php checked(!empty($no_users['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="apps_no_users_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="apps_no_users_frequency" id="apps_no_users_frequency">
                                            <option value="every_minute" <?php selected($no_users['frequency'], 'every_minute'); ?>><?php _e('Every Minute', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_minutes" <?php selected($no_users['frequency'], 'every_5_minutes'); ?>><?php _e('Every 5 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_10_minutes" <?php selected($no_users['frequency'], 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_15_minutes" <?php selected($no_users['frequency'], 'every_15_minutes'); ?>><?php _e('Every 15 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_30_minutes" <?php selected($no_users['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($no_users['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($no_users['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($no_users['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($no_users['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($no_users['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($no_users['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($no_users['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($no_users['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $no_users['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="apps_no_users_custom_value" value="<?php echo esc_attr($no_users['custom_value']); ?>" min="1" step="1">
                                        <select name="apps_no_users_custom_unit">
                                            <option value="minutes" <?php selected($no_users['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($no_users['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($no_users['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($no_users['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($no_users['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-app-management-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Subscription Size Limits -->
        <div class="task-section">
            <h3><?php _e('Subscription Size Limits', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('Set maximum size limits for each subscription plan. Applications exceeding these limits can be automatically flagged for cleanup.', 'swiftspeed-siberian'); ?>
            </div>
            
            <?php if (empty($subscriptions)): ?>
                <div class="swsib-notice warning">
                    <?php _e('No subscription plans found. Please check your Siberian CMS database connection.', 'swiftspeed-siberian'); ?>
                </div>
            <?php else: ?>
                <form>
                    <table class="subscription-limits-table">
                        <thead>
                            <tr>
                                <th><?php _e('Subscription ID', 'swiftspeed-siberian'); ?></th>
                                <th><?php _e('Plan Name', 'swiftspeed-siberian'); ?></th>
                                <th><?php _e('Price', 'swiftspeed-siberian'); ?></th>
                                <th><?php _e('Size Limit (MB)', 'swiftspeed-siberian'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptions as $subscription): ?>
                                <tr class="subscription-row" data-subscription-id="<?php echo $subscription['subscription_id']; ?>">
                                    <td><?php echo $subscription['subscription_id']; ?></td>
                                    <td><?php echo esc_html($subscription['name']); ?></td>
                                    <td><?php echo esc_html($subscription['regular_payment']); ?></td>
                                    <td>
                                        <input type="number" name="subscription_limit[<?php echo $subscription['subscription_id']; ?>]" 
                                               value="<?php echo isset($size_limits[$subscription['subscription_id']]) ? intval($size_limits[$subscription['subscription_id']]) : 0; ?>" 
                                               min="0" step="1">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="task-settings-actions">
                        <button type="button" class="button button-primary save-subscription-size-limits"><?php _e('Save Size Limits', 'swiftspeed-siberian'); ?></button>
                    </div>
                </form>
                
                <div class="swsib-notice info" style="margin-top: 15px;">
                    <p><strong><?php _e('Note:', 'swiftspeed-siberian'); ?></strong> <?php _e('Set a limit of 0 MB to disable size checking for a subscription plan.', 'swiftspeed-siberian'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}