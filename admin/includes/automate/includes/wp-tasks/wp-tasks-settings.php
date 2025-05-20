<?php
/**
 * WordPress Tasks - Settings handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_WP_Settings {
    
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
     * AJAX handler for saving WordPress tasks automation settings
     */
    public function ajax_save_wp_tasks_automation() {
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
        
        // Log the received settings for debugging
        $this->log_message("Received settings for WP tasks: " . print_r($settings, true));
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize automate options if not exists
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        
        // Initialize wp_tasks options if not exists
        if (!isset($options['automate']['wp_tasks'])) {
            $options['automate']['wp_tasks'] = array();
        }
        
        // Save spam users settings
        $options['automate']['wp_tasks']['spam_users'] = array(
            'enabled' => isset($settings['spam_users_enabled']),
            'frequency' => sanitize_text_field($settings['spam_users_frequency']),
            'custom_value' => isset($settings['spam_users_custom_value']) ? intval($settings['spam_users_custom_value']) : 0,
            'custom_unit' => isset($settings['spam_users_custom_unit']) ? sanitize_text_field($settings['spam_users_custom_unit']) : 'hours',
            'aggressive_mode' => isset($settings['spam_users_aggressive_mode'])
        );
        
        // Save unsynced users settings with the new role exclusion and meta exclusion options
        $options['automate']['wp_tasks']['unsynced_users'] = array(
            'enabled' => isset($settings['unsynced_users_enabled']),
            'frequency' => sanitize_text_field($settings['unsynced_users_frequency']),
            'custom_value' => isset($settings['unsynced_users_custom_value']) ? intval($settings['unsynced_users_custom_value']) : 0,
            'custom_unit' => isset($settings['unsynced_users_custom_unit']) ? sanitize_text_field($settings['unsynced_users_custom_unit']) : 'hours',
            'delete_wp_users_not_in_siberian' => isset($settings['unsynced_users_delete_wp_users_not_in_siberian']),
        );
        
        // Handle excluded roles (sanitize array values)
        if (isset($settings['unsynced_users_excluded_roles']) && is_array($settings['unsynced_users_excluded_roles'])) {
            $excluded_roles = array_map('sanitize_text_field', $settings['unsynced_users_excluded_roles']);
            $options['automate']['wp_tasks']['unsynced_users']['excluded_roles'] = $excluded_roles;
        } else {
            $options['automate']['wp_tasks']['unsynced_users']['excluded_roles'] = array();
        }
        
        // Handle excluded meta keys and values
        $excluded_meta_keys = array();
        $excluded_meta_values = array();
        
        if (isset($settings['unsynced_users_excluded_meta_keys']) && is_array($settings['unsynced_users_excluded_meta_keys'])) {
            foreach ($settings['unsynced_users_excluded_meta_keys'] as $i => $key) {
                if (!empty($key)) {
                    $sanitized_key = sanitize_text_field($key);
                    $excluded_meta_keys[] = $sanitized_key;
                    
                    // Also store the corresponding value if it exists
                    if (isset($settings['unsynced_users_excluded_meta_values'][$i])) {
                        $excluded_meta_values[] = sanitize_text_field($settings['unsynced_users_excluded_meta_values'][$i]);
                    } else {
                        $excluded_meta_values[] = '';
                    }
                }
            }
        }
        
        $options['automate']['wp_tasks']['unsynced_users']['excluded_meta_keys'] = $excluded_meta_keys;
        $options['automate']['wp_tasks']['unsynced_users']['excluded_meta_values'] = $excluded_meta_values;
        
        // Log the user exclusion settings for debugging
        $this->log_message("User exclusion settings - excluded_roles: " . 
            print_r($options['automate']['wp_tasks']['unsynced_users']['excluded_roles'], true));
        $this->log_message("User exclusion settings - excluded_meta_keys: " . 
            print_r($options['automate']['wp_tasks']['unsynced_users']['excluded_meta_keys'], true));
        $this->log_message("User exclusion settings - excluded_meta_values: " . 
            print_r($options['automate']['wp_tasks']['unsynced_users']['excluded_meta_values'], true));
        
        // Save security settings - explicitly check if the setting is set
        $prevent_link_names = isset($settings['prevent_link_names']);
        $options['automate']['wp_tasks']['security'] = array(
            'prevent_link_names' => $prevent_link_names
        );
        
        // Log the security settings for debugging
        $this->log_message("Security settings - prevent_link_names: " . ($prevent_link_names ? 'true' : 'false'));
        
        // Log the user sync settings for debugging
        $this->log_message("User sync settings - delete_wp_users_not_in_siberian: " . 
            (isset($settings['unsynced_users_delete_wp_users_not_in_siberian']) ? 'true' : 'false'));
        
        // Update options
        update_option('swsib_options', $options);
        
        // Update schedules
        $this->update_wp_tasks_schedules($options['automate']['wp_tasks']);
        
        // Update security filters immediately
        $this->update_security_filters();
        
        wp_send_json_success(array('message' => 'WordPress tasks automation settings saved.'));
    }
    
    /**
     * Update WordPress tasks schedules
     */
    private function update_wp_tasks_schedules($settings) {
        // Load the scheduler
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        
        // Schedule spam users task
        if (!empty($settings['spam_users']['enabled'])) {
            $interval = $this->get_frequency_in_seconds($settings['spam_users']);
            $this->log_message("Scheduling spam users task with interval: {$interval} seconds");
            $scheduler->schedule_task(
                'wp_cleanup',
                array('task' => 'spam_users'),
                $interval
            );
            
            // Force immediate check to ensure the task is scheduled properly
            $scheduler->schedule_next_check(true);
        } else {
            $scheduler->unschedule_task('wp_cleanup', array('task' => 'spam_users'));
        }
        
        // Schedule unsynced users task
        if (!empty($settings['unsynced_users']['enabled'])) {
            $interval = $this->get_frequency_in_seconds($settings['unsynced_users']);
            $this->log_message("Scheduling unsynced users task with interval: {$interval} seconds");
            $scheduler->schedule_task(
                'wp_cleanup',
                array('task' => 'unsynced_users'),
                $interval
            );
            
            // Force immediate check to ensure the task is scheduled properly
            $scheduler->schedule_next_check(true);
        } else {
            $scheduler->unschedule_task('wp_cleanup', array('task' => 'unsynced_users'));
        }
    }
    
    /**
     * Update security filters based on settings
     */
    private function update_security_filters() {
        $options = get_option('swsib_options', array());
        $settings = isset($options['automate']['wp_tasks']['security']) ? $options['automate']['wp_tasks']['security'] : array();
        
        $prevent_link_names = !empty($settings['prevent_link_names']);
        $this->log_message("Updating security filters - prevent_link_names: " . ($prevent_link_names ? 'true' : 'false'));
        
        if ($prevent_link_names) {
            // Add filters to prevent links in usernames
            if (!has_filter('sanitize_user', array($this, 'prevent_link_names'))) {
                add_filter('sanitize_user', array($this, 'prevent_link_names'), 10, 3);
            }
            
            if (!has_filter('wpmu_validate_user_signup', array($this, 'validate_user_signup'))) {
                add_filter('wpmu_validate_user_signup', array($this, 'validate_user_signup'));
            }
        } else {
            // Remove filters
            remove_filter('sanitize_user', array($this, 'prevent_link_names'));
            remove_filter('wpmu_validate_user_signup', array($this, 'validate_user_signup'));
        }
    }
    
    /**
     * Filter to prevent links in usernames
     */
    public function prevent_link_names($username, $raw_username, $strict) {
        // Pattern to detect URLs, HTML tags, and spam patterns
        $patterns = array(
            '/http[s]?:\/\//',     // http:// or https://
            '/[w]{3}\.[a-z0-9-]+\.[a-z]+/', // www.something.com
            '/\[url=/',            // [url=
            '/\<a/',               // <a
            '/?????/',             // Russian link text
            '/??????/',            // Russian spam word
            '/???????/',           // Russian gift word
            '/????????/',          // Russian won word
            '/pozdravlyaem/'       // Russian congratulations word
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $raw_username)) {
                // Replace with generic username
                return 'user_' . substr(md5(time() . rand()), 0, 10);
            }
        }
        
        return $username;
    }
    
    /**
     * Validate user signup to prevent spam usernames
     */
    public function validate_user_signup($result) {
        $user_name = $result['user_name'];
        $user_email = $result['user_email'];
        
        // Check for spam patterns in the username
        $patterns = array(
            '/http[s]?:\/\//',     // http:// or https://
            '/[w]{3}\.[a-z0-9-]+\.[a-z]+/', // www.something.com
            '/\[url=/',            // [url=
            '/\<a/',               // <a
            '/?????/',             // Russian link text
            '/??????/',            // Russian spam word
            '/???????/',           // Russian gift word
            '/????????/',          // Russian won word
            '/pozdravlyaem/'       // Russian congratulations word
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $user_name)) {
                $result['errors']->add('user_name', __('Username contains disallowed content.'));
                break;
            }
        }
        
        return $result;
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
            case 'hourly':
                return 3600;
            case 'every_30_minutes':
                return 1800;
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
     * Get settings for WordPress tasks automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['wp_tasks'])) {
            return array(
                'spam_users' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours',
                    'aggressive_mode' => false
                ),
                'unsynced_users' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours',
                    'delete_wp_users_not_in_siberian' => false,
                    'excluded_roles' => array('administrator'),
                    'excluded_meta_keys' => array(),
                    'excluded_meta_values' => array()
                ),
                'security' => array(
                    'prevent_link_names' => true
                )
            );
        }
        
        return $options['automate']['wp_tasks'];
    }
    
    /**
     * Process settings for WordPress tasks automation
     */
    public function process_settings($input) {
        // Settings are processed via AJAX
        return $input;
    }
    
   /**
 * Display WordPress tasks automation settings
 */
public function display_settings() {
    // Get settings
    $settings = $this->get_settings();
    
    // Get spam users settings
    $spam_users = isset($settings['spam_users']) ? $settings['spam_users'] : array(
        'enabled' => false,
        'frequency' => 'daily',
        'custom_value' => 0,
        'custom_unit' => 'hours',
        'aggressive_mode' => false
    );
    
    // Get unsynced users settings
    $unsynced_users = isset($settings['unsynced_users']) ? $settings['unsynced_users'] : array(
        'enabled' => false,
        'frequency' => 'daily',
        'custom_value' => 0,
        'custom_unit' => 'hours',
        'delete_wp_users_not_in_siberian' => false,
        'excluded_roles' => array('administrator'),
        'excluded_meta_keys' => array(),
        'excluded_meta_values' => array()
    );
    
    // Get security settings (with default)
    $security = isset($settings['security']) ? $settings['security'] : array(
        'prevent_link_names' => true
    );
    
    // Get all WordPress roles
    $roles = wp_roles();
    
    ?>
    <div class="task-section">
        <h3><?php _e('WordPress User Management', 'swiftspeed-siberian'); ?></h3>
        
        <div class="info-text">
            <?php _e('Clean up spam users and synchronize WordPress users with Siberian CMS.', 'swiftspeed-siberian'); ?>
        </div>
        
        <div class="task-grid">
            <!-- Spam Users Cleanup -->
            <div class="task-card">
                <div class="task-card-header">
                    <h4 class="task-card-title"><?php _e('Spam Users Cleanup', 'swiftspeed-siberian'); ?></h4>
                    <span class="task-card-badge <?php echo !empty($spam_users['enabled']) ? 'active' : ''; ?>">
                        <?php echo !empty($spam_users['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                    </span>
                </div>
                
                <div class="task-card-description">
                    <?php _e('Detect and remove users with spam indicators in their usernames or display names.', 'swiftspeed-siberian'); ?>
                </div>
                
                <div class="task-card-counts">
                    <div class="task-card-count">
                        <span><?php _e('Spam Users', 'swiftspeed-siberian'); ?></span>
                        <span class="task-card-count-value spam-users-count">
                            <span class="loading-spinner"></span>
                        </span>
                    </div>
                </div>
                
                <div class="task-card-actions">
                    <button type="button" class="button button-secondary preview-data-button" data-type="spam_users" <?php echo !$this->db_connection ? 'disabled' : ''; ?>>
                        <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                    </button>
                    <button type="button" class="button button-primary run-wp-cleanup" data-task="spam_users">
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
                                        <input type="checkbox" name="spam_users_enabled" value="1" <?php checked(!empty($spam_users['enabled'])); ?>>
                                        <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                    </label>
                                </div>
                                
                                <div class="task-settings-field">
                                    <label>
                                        <input type="checkbox" name="spam_users_aggressive_mode" value="1" <?php checked(!empty($spam_users['aggressive_mode'])); ?>>
                                        <?php _e('Use aggressive detection (more patterns, may have false positives)', 'swiftspeed-siberian'); ?>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="task-settings-field-group">
                                <div class="task-settings-field">
                                    <label for="spam_users_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                    <select name="spam_users_frequency" id="spam_users_frequency">
                                        <option value="every_30_minutes" <?php selected($spam_users['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                        <option value="hourly" <?php selected($spam_users['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_2_hours" <?php selected($spam_users['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_5_hours" <?php selected($spam_users['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_12_hours" <?php selected($spam_users['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="daily" <?php selected($spam_users['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                        <option value="weekly" <?php selected($spam_users['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                        <option value="custom" <?php selected($spam_users['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                    </select>
                                </div>
                                
                                <div class="custom-frequency-container" style="<?php echo $spam_users['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                    <input type="number" name="spam_users_custom_value" value="<?php echo esc_attr($spam_users['custom_value']); ?>" min="1" step="1">
                                    <select name="spam_users_custom_unit">
                                        <option value="minutes" <?php selected($spam_users['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                        <option value="hours" <?php selected($spam_users['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="days" <?php selected($spam_users['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                        <option value="weeks" <?php selected($spam_users['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                        <option value="months" <?php selected($spam_users['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- User Synchronization (Renamed from Unsynced Users Cleanup) -->
            <div class="task-card">
                <div class="task-card-header">
                    <h4 class="task-card-title"><?php _e('User Synchronization', 'swiftspeed-siberian'); ?></h4>
                    <span class="task-card-badge <?php echo !empty($unsynced_users['enabled']) ? 'active' : ''; ?>">
                        <?php echo !empty($unsynced_users['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                    </span>
                </div>
                
                <div class="task-card-description">
                    <?php _e('Synchronize users between Siberian CMS and WordPress. Creates WordPress accounts for Siberian users who don\'t have them, and optionally removes WordPress users not present in Siberian CMS.', 'swiftspeed-siberian'); ?>
                </div>
                
                <div class="task-card-counts">
                    <div class="task-card-count">
                        <span><?php _e('Unsynced Users', 'swiftspeed-siberian'); ?></span>
                        <span class="task-card-count-value unsynced-users-count">
                            <span class="loading-spinner"></span>
                        </span>
                    </div>
                </div>
                
                <div class="task-card-actions" style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <button type="button" class="button button-secondary preview-data-button" data-type="siberian_users_without_wp" <?php echo !$this->db_connection ? 'disabled' : ''; ?>>
                        <?php _e('Preview Users To Create', 'swiftspeed-siberian'); ?>
                    </button>
                    <?php if (!empty($unsynced_users['delete_wp_users_not_in_siberian'])): ?>
                    <button type="button" class="button button-secondary preview-data-button" data-type="wp_users_without_siberian" <?php echo !$this->db_connection ? 'disabled' : ''; ?>>
                        <?php _e('Preview Users To Delete', 'swiftspeed-siberian'); ?>
                    </button>
                    <?php endif; ?>
                    <div style="flex-basis: 100%;"></div>
                     <button type="button" class="button button-primary run-wp-cleanup" data-task="unsynced_users" style="margin: 0 auto;">
                        <?php _e('Run Synchronization', 'swiftspeed-siberian'); ?>
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
                                        <input type="checkbox" name="unsynced_users_enabled" value="1" <?php checked(!empty($unsynced_users['enabled'])); ?>>
                                        <?php _e('Enable automated synchronization', 'swiftspeed-siberian'); ?>
                                    </label>
                                </div>
                                
                                <!-- New deletion option -->
                                <div class="task-settings-field">
                                    <label>
                                        <input type="checkbox" name="unsynced_users_delete_wp_users_not_in_siberian" value="1" <?php checked(!empty($unsynced_users['delete_wp_users_not_in_siberian'])); ?>>
                                        <?php _e('Delete WordPress users not present in Siberian CMS', 'swiftspeed-siberian'); ?>
                                    </label>
                                    <p class="field-description" style="color: #d63638; font-style: italic; margin-top: 5px; font-size: 12px;">
                                        <?php _e('Warning: This will permanently delete WordPress users that don\'t exist in Siberian CMS.', 'swiftspeed-siberian'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- New Excluded Roles Section -->
                            <div class="task-settings-field-group excluded-roles-container" style="<?php echo !empty($unsynced_users['delete_wp_users_not_in_siberian']) ? '' : 'display: none;'; ?>">
                                <div class="task-settings-field">
                                    <label><?php _e('Exclude Users with These Roles', 'swiftspeed-siberian'); ?></label>
                                    <div class="role-checkboxes">
                                        <?php foreach ($roles->role_names as $role_key => $role_name) : ?>
                                            <div class="role-checkbox">
                                                <label>
                                                    <input type="checkbox" 
                                                           name="unsynced_users_excluded_roles[]" 
                                                           value="<?php echo esc_attr($role_key); ?>" 
                                                           <?php checked(in_array($role_key, isset($unsynced_users['excluded_roles']) ? $unsynced_users['excluded_roles'] : array())); ?>>
                                                    <span><?php echo esc_html($role_name); ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="field-description" style="margin-top: 5px; font-size: 12px;">
                                        <?php _e('Users with selected roles will NOT be deleted, even if they don\'t exist in Siberian CMS.', 'swiftspeed-siberian'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <!-- New Excluded Meta Section -->
                            <div class="task-settings-field-group excluded-meta-container" style="<?php echo !empty($unsynced_users['delete_wp_users_not_in_siberian']) ? '' : 'display: none;'; ?>">
                                <div class="task-settings-field">
                                    <label><?php _e('Exclude Users by Meta Data', 'swiftspeed-siberian'); ?></label>
                                    
                                    <div class="meta-filters">
                                        <div class="meta-filter-row">
                                            <input type="text" name="unsynced_users_excluded_meta_keys[]" placeholder="Meta Key" 
                                                   value="<?php echo isset($unsynced_users['excluded_meta_keys'][0]) ? esc_attr($unsynced_users['excluded_meta_keys'][0]) : ''; ?>">
                                            <input type="text" name="unsynced_users_excluded_meta_values[]" placeholder="Meta Value" 
                                                   value="<?php echo isset($unsynced_users['excluded_meta_values'][0]) ? esc_attr($unsynced_users['excluded_meta_values'][0]) : ''; ?>">
                                        </div>
                                        <?php 
                                        // If there are additional meta key/value pairs, display them
                                        if (!empty($unsynced_users['excluded_meta_keys']) && count($unsynced_users['excluded_meta_keys']) > 1) {
                                            for ($i = 1; $i < count($unsynced_users['excluded_meta_keys']); $i++) {
                                                echo '<div class="meta-filter-row">';
                                                echo '<input type="text" name="unsynced_users_excluded_meta_keys[]" placeholder="Meta Key" value="' . esc_attr($unsynced_users['excluded_meta_keys'][$i]) . '">';
                                                echo '<input type="text" name="unsynced_users_excluded_meta_values[]" placeholder="Meta Value" value="' . esc_attr(isset($unsynced_users['excluded_meta_values'][$i]) ? $unsynced_users['excluded_meta_values'][$i] : '') . '">';
                                                echo '<button type="button" class="button remove-meta-filter">-</button>';
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <button type="button" class="button add-meta-filter"><?php _e('Add Another Meta Filter', 'swiftspeed-siberian'); ?></button>
                                    <p class="field-description" style="margin-top: 5px; font-size: 12px;">
                                        <?php _e('Users with matching meta key/value pairs will NOT be deleted.', 'swiftspeed-siberian'); ?>
                                    </p>
                                    <p class="field-description" style="margin-top: 5px; font-size: 12px;">
                                        <?php _e('Example: Use "signup_type" as key and "api_app_user" as value to exclude users created by the API.', 'swiftspeed-siberian'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="task-settings-field-group">
                                <div class="task-settings-field">
                                    <label for="unsynced_users_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                    <select name="unsynced_users_frequency" id="unsynced_users_frequency">
                                        <option value="hourly" <?php selected($unsynced_users['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_2_hours" <?php selected($unsynced_users['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_5_hours" <?php selected($unsynced_users['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="every_12_hours" <?php selected($unsynced_users['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="daily" <?php selected($unsynced_users['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                        <option value="weekly" <?php selected($unsynced_users['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                        <option value="custom" <?php selected($unsynced_users['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                    </select>
                                </div>
                                
                                <div class="custom-frequency-container" style="<?php echo $unsynced_users['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                    <input type="number" name="unsynced_users_custom_value" value="<?php echo esc_attr($unsynced_users['custom_value']); ?>" min="1" step="1">
                                    <select name="unsynced_users_custom_unit">
                                        <option value="minutes" <?php selected($unsynced_users['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                        <option value="hours" <?php selected($unsynced_users['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                        <option value="days" <?php selected($unsynced_users['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                        <option value="weeks" <?php selected($unsynced_users['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                        <option value="months" <?php selected($unsynced_users['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Security Settings -->
            <div class="task-card">
                <div class="task-card-header">
                    <h4 class="task-card-title"><?php _e('Security Settings', 'swiftspeed-siberian'); ?></h4>
                </div>
                
                <div class="task-card-description">
                    <?php _e('Configure security settings to prevent spam user registration and other security issues.', 'swiftspeed-siberian'); ?>
                </div>
                
                <div class="task-settings">
                    <form>
                        <div class="task-settings-field-group">
                            <div class="task-settings-field">
                                <label>
                                    <input type="checkbox" name="prevent_link_names" value="1" <?php checked(!empty($security['prevent_link_names'])); ?>>
                                    <?php _e('Prevent links in usernames (blocks spam registrations)', 'swiftspeed-siberian'); ?>
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="task-settings-actions">
            <button type="button" class="button button-primary save-wp-tasks-automation"><?php _e('Save All Settings', 'swiftspeed-siberian'); ?></button>
        </div>
    </div>
    <?php
}

}