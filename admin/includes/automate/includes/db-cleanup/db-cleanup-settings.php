<?php
/**
 * Database Cleanup - Settings handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_DB_Settings {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Data handler
     */
    private $data;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }
    
    /**
     * Set data handler
     */
    public function set_data_handler($data) {
        $this->data = $data;
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
     * AJAX handler for saving database cleanup automation settings
     */
    public function ajax_save_db_cleanup_automation() {
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
        
        // Initialize db cleanup options if not exists
        if (!isset($options['automate']['db_cleanup'])) {
            $options['automate']['db_cleanup'] = array();
        }
        
        // Identify which card settings are being updated based on form fields
        $card_type = null;
        
        if (isset($settings['sessions_cleanup_frequency'])) {
            $card_type = 'sessions';
        } elseif (isset($settings['mail_logs_cleanup_frequency'])) {
            $card_type = 'mail_logs';
        } elseif (isset($settings['source_queue_cleanup_frequency'])) {
            $card_type = 'source_queue';
        } elseif (isset($settings['optimize_frequency'])) {
            $card_type = 'optimize';
        } elseif (isset($settings['backoffice_alerts_cleanup_frequency'])) {
            $card_type = 'backoffice_alerts';
        } elseif (isset($settings['cleanup_log_cleanup_frequency'])) {
            $card_type = 'cleanup_log';
        }
        
        if (!$card_type) {
            wp_send_json_error(array('message' => 'Could not identify which card settings to update.'));
            return;
        }
        
        $this->log_message("Updating DB cleanup settings for card: {$card_type}");
        
        // Only update the settings for the specific card type
        switch ($card_type) {
            case 'sessions':
                $options['automate']['db_cleanup']['sessions'] = array(
                    'enabled' => isset($settings['sessions_cleanup_enabled']),
                    'frequency' => sanitize_text_field($settings['sessions_cleanup_frequency']),
                    'custom_value' => isset($settings['sessions_cleanup_custom_value']) ? intval($settings['sessions_cleanup_custom_value']) : 0,
                    'custom_unit' => isset($settings['sessions_cleanup_custom_unit']) ? sanitize_text_field($settings['sessions_cleanup_custom_unit']) : 'days',
                    'older_than' => isset($settings['sessions_cleanup_older_than']) ? intval($settings['sessions_cleanup_older_than']) : 7,
                    'older_than_unit' => isset($settings['sessions_cleanup_older_than_unit']) ? sanitize_text_field($settings['sessions_cleanup_older_than_unit']) : 'days'
                );
                break;
                
            case 'mail_logs':
                $options['automate']['db_cleanup']['mail_logs'] = array(
                    'enabled' => isset($settings['mail_logs_cleanup_enabled']),
                    'frequency' => sanitize_text_field($settings['mail_logs_cleanup_frequency']),
                    'custom_value' => isset($settings['mail_logs_cleanup_custom_value']) ? intval($settings['mail_logs_cleanup_custom_value']) : 0,
                    'custom_unit' => isset($settings['mail_logs_cleanup_custom_unit']) ? sanitize_text_field($settings['mail_logs_cleanup_custom_unit']) : 'days',
                    'older_than' => isset($settings['mail_logs_cleanup_older_than']) ? intval($settings['mail_logs_cleanup_older_than']) : 30,
                    'older_than_unit' => isset($settings['mail_logs_cleanup_older_than_unit']) ? sanitize_text_field($settings['mail_logs_cleanup_older_than_unit']) : 'days'
                );
                break;
                
            case 'source_queue':
                $options['automate']['db_cleanup']['source_queue'] = array(
                    'enabled' => isset($settings['source_queue_cleanup_enabled']),
                    'frequency' => sanitize_text_field($settings['source_queue_cleanup_frequency']),
                    'custom_value' => isset($settings['source_queue_cleanup_custom_value']) ? intval($settings['source_queue_cleanup_custom_value']) : 0,
                    'custom_unit' => isset($settings['source_queue_cleanup_custom_unit']) ? sanitize_text_field($settings['source_queue_cleanup_custom_unit']) : 'days',
                    'older_than' => isset($settings['source_queue_cleanup_older_than']) ? intval($settings['source_queue_cleanup_older_than']) : 30,
                    'older_than_unit' => isset($settings['source_queue_cleanup_older_than_unit']) ? sanitize_text_field($settings['source_queue_cleanup_older_than_unit']) : 'days',
                    'status' => isset($settings['source_queue_cleanup_status']) ? sanitize_text_field($settings['source_queue_cleanup_status']) : 'all'
                );
                break;
                
            case 'optimize':
                $options['automate']['db_cleanup']['optimize'] = array(
                    'enabled' => isset($settings['optimize_enabled']),
                    'frequency' => sanitize_text_field($settings['optimize_frequency']),
                    'custom_value' => isset($settings['optimize_custom_value']) ? intval($settings['optimize_custom_value']) : 0,
                    'custom_unit' => isset($settings['optimize_custom_unit']) ? sanitize_text_field($settings['optimize_custom_unit']) : 'days'
                );
                break;
                
            case 'backoffice_alerts':
                $options['automate']['db_cleanup']['backoffice_alerts'] = array(
                    'enabled' => isset($settings['backoffice_alerts_cleanup_enabled']),
                    'frequency' => sanitize_text_field($settings['backoffice_alerts_cleanup_frequency']),
                    'custom_value' => isset($settings['backoffice_alerts_cleanup_custom_value']) ? intval($settings['backoffice_alerts_cleanup_custom_value']) : 0,
                    'custom_unit' => isset($settings['backoffice_alerts_cleanup_custom_unit']) ? sanitize_text_field($settings['backoffice_alerts_cleanup_custom_unit']) : 'days',
                    'older_than' => isset($settings['backoffice_alerts_cleanup_older_than']) ? intval($settings['backoffice_alerts_cleanup_older_than']) : 30,
                    'older_than_unit' => isset($settings['backoffice_alerts_cleanup_older_than_unit']) ? sanitize_text_field($settings['backoffice_alerts_cleanup_older_than_unit']) : 'days'
                );
                break;
                
            case 'cleanup_log':
                $options['automate']['db_cleanup']['cleanup_log'] = array(
                    'enabled' => isset($settings['cleanup_log_cleanup_enabled']),
                    'frequency' => sanitize_text_field($settings['cleanup_log_cleanup_frequency']),
                    'custom_value' => isset($settings['cleanup_log_cleanup_custom_value']) ? intval($settings['cleanup_log_cleanup_custom_value']) : 0,
                    'custom_unit' => isset($settings['cleanup_log_cleanup_custom_unit']) ? sanitize_text_field($settings['cleanup_log_cleanup_custom_unit']) : 'days',
                    'older_than' => isset($settings['cleanup_log_cleanup_older_than']) ? intval($settings['cleanup_log_cleanup_older_than']) : 90,
                    'older_than_unit' => isset($settings['cleanup_log_cleanup_older_than_unit']) ? sanitize_text_field($settings['cleanup_log_cleanup_older_than_unit']) : 'days'
                );
                break;
                
            default:
                wp_send_json_error(array('message' => 'Unknown card type.'));
                return;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        // Update only the schedule for the card being saved
        $card_settings = array($card_type => $options['automate']['db_cleanup'][$card_type]);
        $this->update_db_cleanup_schedules($card_settings);
        
        wp_send_json_success(array('message' => 'Database cleanup automation settings saved.'));
    }
    
    /**
     * Update database cleanup schedules
     */
    private function update_db_cleanup_schedules($settings) {
        // Load the scheduler
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        
        // Only update schedules for the specified cards
        foreach ($settings as $task_type => $task_settings) {
            // Schedule or unschedule based on enabled status
            if (!empty($task_settings['enabled'])) {
                $interval = $this->get_frequency_in_seconds($task_settings);
                $this->log_message("Scheduling task db_cleanup for {$task_type} with interval {$interval} seconds");
                
                $scheduler->schedule_task(
                    'db_cleanup',
                    array('task' => $task_type),
                    $interval
                );
                
                // Force immediate check to ensure the task is scheduled properly
                $scheduler->schedule_next_check(true);
            } else {
                $scheduler->unschedule_task('db_cleanup', array('task' => $task_type));
                $this->log_message("Unscheduled task db_cleanup for {$task_type}");
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
     * Get settings for database cleanup automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['db_cleanup'])) {
            return array(
                'sessions' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'older_than' => 7,
                    'older_than_unit' => 'days'
                ),
                'mail_logs' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'older_than' => 30,
                    'older_than_unit' => 'days'
                ),
                'source_queue' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'older_than' => 30,
                    'older_than_unit' => 'days',
                    'status' => 'all'
                ),
                'optimize' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days'
                ),
                'backoffice_alerts' => array(
                    'enabled' => false,
                    'frequency' => 'weekly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'older_than' => 30,
                    'older_than_unit' => 'days'
                ),
                'cleanup_log' => array(
                    'enabled' => false,
                    'frequency' => 'monthly',
                    'custom_value' => 0,
                    'custom_unit' => 'days',
                    'older_than' => 90,
                    'older_than_unit' => 'days'
                )
            );
        }
        
        return $options['automate']['db_cleanup'];
    }
    
    /**
     * Process settings for database cleanup automation
     */
    public function process_settings($input) {
        // Settings are processed via AJAX
        return $input;
    }
    
    /**
     * Display database cleanup automation settings
     */
    public function display_settings() {
        // Get settings
        $settings = $this->get_settings();
        
        // Get sessions settings
        $sessions = isset($settings['sessions']) ? $settings['sessions'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'older_than' => 7,
            'older_than_unit' => 'days'
        );
        
        // Get mail logs settings
        $mail_logs = isset($settings['mail_logs']) ? $settings['mail_logs'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'older_than' => 30,
            'older_than_unit' => 'days'
        );
        
        // Get source queue settings
        $source_queue = isset($settings['source_queue']) ? $settings['source_queue'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'older_than' => 30,
            'older_than_unit' => 'days',
            'status' => 'all'
        );
        
        // Get optimize settings
        $optimize = isset($settings['optimize']) ? $settings['optimize'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days'
        );
        
        // Get backoffice alerts settings
        $backoffice_alerts = isset($settings['backoffice_alerts']) ? $settings['backoffice_alerts'] : array(
            'enabled' => false,
            'frequency' => 'weekly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'older_than' => 30,
            'older_than_unit' => 'days'
        );
        
        // Get cleanup log settings
        $cleanup_log = isset($settings['cleanup_log']) ? $settings['cleanup_log'] : array(
            'enabled' => false,
            'frequency' => 'monthly',
            'custom_value' => 0,
            'custom_unit' => 'days',
            'older_than' => 90,
            'older_than_unit' => 'days'
        );
        
        // Get counts
        $sessions_count = $this->data->get_sessions_count();
        $mail_logs_count = $this->data->get_mail_logs_count();
        $source_queue_count = $this->data->get_source_queue_count();
        $backoffice_alerts_count = $this->data->get_backoffice_alerts_count();
        $cleanup_log_count = $this->data->get_cleanup_log_count();
        $tables_info = $this->data->get_optimize_tables_info();
        
        // Function to render frequency dropdown with consistent options
        function render_frequency_dropdown($id, $selected_value, $include_all_options = true) {
            $base_options = array(
                'every_minute' => __('Every Minute', 'swiftspeed-siberian'),
                'every_5_minutes' => __('Every 5 Minutes', 'swiftspeed-siberian'),
                'every_10_minutes' => __('Every 10 Minutes', 'swiftspeed-siberian'),
                'every_15_minutes' => __('Every 15 Minutes', 'swiftspeed-siberian'),
                'every_30_minutes' => __('Every 30 Minutes', 'swiftspeed-siberian'),
                'hourly' => __('Hourly', 'swiftspeed-siberian'),
                'every_2_hours' => __('Every 2 Hours', 'swiftspeed-siberian'),
                'every_5_hours' => __('Every 5 Hours', 'swiftspeed-siberian'),
                'every_12_hours' => __('Every 12 Hours', 'swiftspeed-siberian'),
                'daily' => __('Daily', 'swiftspeed-siberian'),
                'every_3_days' => __('Every 3 Days', 'swiftspeed-siberian'),
                'weekly' => __('Weekly', 'swiftspeed-siberian'),
                'every_2_weeks' => __('Every 2 Weeks', 'swiftspeed-siberian'),
                'monthly' => __('Monthly', 'swiftspeed-siberian'),
                'every_3_months' => __('Every 3 Months', 'swiftspeed-siberian'),
                'every_6_months' => __('Every 6 Months', 'swiftspeed-siberian'),
                'yearly' => __('Yearly', 'swiftspeed-siberian'),
                'custom' => __('Custom', 'swiftspeed-siberian')
            );
            
            // Determine which options to display based on task type
            $options = $include_all_options ? $base_options : array_intersect_key($base_options, array_flip([
                'hourly', 'every_12_hours', 'daily', 'weekly', 'monthly', 'custom'
            ]));
            
            ?>
            <select name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>">
                <?php foreach ($options as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_value, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
        }
        
        // Function to render custom unit dropdown with consistent options
        function render_custom_unit_dropdown($id, $selected_value) {
            $options = array(
                'minutes' => __('Minutes', 'swiftspeed-siberian'),
                'hours' => __('Hours', 'swiftspeed-siberian'),
                'days' => __('Days', 'swiftspeed-siberian'),
                'weeks' => __('Weeks', 'swiftspeed-siberian'),
                'months' => __('Months', 'swiftspeed-siberian')
            );
            
            ?>
            <select name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>">
                <?php foreach ($options as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_value, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
        }
        
        // Function to render age unit dropdown with consistent options
        function render_age_unit_dropdown($id, $selected_value) {
            $options = array(
                'minutes' => __('Minutes', 'swiftspeed-siberian'),
                'hours' => __('Hours', 'swiftspeed-siberian'),
                'days' => __('Days', 'swiftspeed-siberian'),
                'weeks' => __('Weeks', 'swiftspeed-siberian'),
                'months' => __('Months', 'swiftspeed-siberian')
            );
            
            ?>
            <select name="<?php echo esc_attr($id); ?>" style="width: 120px;">
                <?php foreach ($options as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($selected_value, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php
        }
        
        ?>
        <div class="task-section">
            <h3><?php _e('Database Cleanup', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('Automated cleanup of various database tables to improve performance.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="task-grid">
                <!-- Sessions Cleanup -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Sessions Cleanup', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($sessions['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($sessions['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes old session data to improve database performance.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value sessions-count"><?php echo $sessions_count; ?></span>
                            <span><?php _e('Total Sessions', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="sessions">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="sessions">
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
                            <form class="db-cleanup-form" data-card-type="sessions">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="sessions_cleanup_enabled" value="1" <?php checked(!empty($sessions['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="sessions_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('sessions_cleanup_frequency', $sessions['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $sessions['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="sessions_cleanup_custom_value" value="<?php echo esc_attr($sessions['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('sessions_cleanup_custom_unit', $sessions['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label><?php _e('Delete Sessions Older Than', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="sessions_cleanup_older_than" value="<?php echo esc_attr($sessions['older_than']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <?php render_age_unit_dropdown('sessions_cleanup_older_than_unit', $sessions['older_than_unit']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Mail Logs Cleanup -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Mail Logs Cleanup', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($mail_logs['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($mail_logs['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes old mail logs to reduce database size.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value mail-logs-count"><?php echo $mail_logs_count; ?></span>
                            <span><?php _e('Total Mail Logs', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="mail_logs">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="mail_logs">
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
                            <form class="db-cleanup-form" data-card-type="mail_logs">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="mail_logs_cleanup_enabled" value="1" <?php checked(!empty($mail_logs['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="mail_logs_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('mail_logs_cleanup_frequency', $mail_logs['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $mail_logs['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="mail_logs_cleanup_custom_value" value="<?php echo esc_attr($mail_logs['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('mail_logs_cleanup_custom_unit', $mail_logs['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label><?php _e('Delete Mail Logs Older Than', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="mail_logs_cleanup_older_than" value="<?php echo esc_attr($mail_logs['older_than']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <?php render_age_unit_dropdown('mail_logs_cleanup_older_than_unit', $mail_logs['older_than_unit']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Source Queue Cleanup -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Source Queue Cleanup', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($source_queue['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($source_queue['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes old source queue entries to clean up the database.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value source-queue-count"><?php echo $source_queue_count; ?></span>
                            <span><?php _e('Total Queue Items', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="source_queue">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="source_queue">
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
                            <form class="db-cleanup-form" data-card-type="source_queue">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="source_queue_cleanup_enabled" value="1" <?php checked(!empty($source_queue['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="source_queue_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('source_queue_cleanup_frequency', $source_queue['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $source_queue['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="source_queue_cleanup_custom_value" value="<?php echo esc_attr($source_queue['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('source_queue_cleanup_custom_unit', $source_queue['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label><?php _e('Delete Queue Items Older Than', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="source_queue_cleanup_older_than" value="<?php echo esc_attr($source_queue['older_than']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <?php render_age_unit_dropdown('source_queue_cleanup_older_than_unit', $source_queue['older_than_unit']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="task-settings-field">
                                        <label><?php _e('Status', 'swiftspeed-siberian'); ?></label>
                                        <select name="source_queue_cleanup_status">
                                            <option value="all" <?php selected($source_queue['status'], 'all'); ?>><?php _e('All', 'swiftspeed-siberian'); ?></option>
                                            <option value="done" <?php selected($source_queue['status'], 'done'); ?>><?php _e('Done', 'swiftspeed-siberian'); ?></option>
                                            <option value="canceled" <?php selected($source_queue['status'], 'canceled'); ?>><?php _e('Canceled', 'swiftspeed-siberian'); ?></option>
                                            <option value="error" <?php selected($source_queue['status'], 'error'); ?>><?php _e('Error', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Database Optimize -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Database Optimization', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($optimize['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($optimize['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Optimize database tables to improve performance and reduce overhead.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value tables-count"><?php echo $tables_info['count']; ?></span>
                            <span><?php _e('Tables', 'swiftspeed-siberian'); ?></span>
                        </div>
                        <div class="task-card-count">
                            <span class="task-card-count-value tables-size"><?php echo $tables_info['size']; ?></span>
                            <span><?php _e('Total Size', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="optimize">
                            <?php _e('Preview Tables', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="optimize">
                            <?php _e('Run Optimization', 'swiftspeed-siberian'); ?>
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
                            <form class="db-cleanup-form" data-card-type="optimize">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="optimize_enabled" value="1" <?php checked(!empty($optimize['enabled'])); ?>>
                                            <?php _e('Enable automated optimization', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="optimize_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('optimize_frequency', $optimize['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $optimize['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="optimize_custom_value" value="<?php echo esc_attr($optimize['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('optimize_custom_unit', $optimize['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Backoffice Alerts Cleanup -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Backoffice Alerts Cleanup', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($backoffice_alerts['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($backoffice_alerts['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes old alerts from the Siberian CMS backoffice.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value backoffice-alerts-count"><?php echo $backoffice_alerts_count; ?></span>
                            <span><?php _e('Total Alerts', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="backoffice_alerts">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="backoffice_alerts">
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
                            <form class="db-cleanup-form" data-card-type="backoffice_alerts">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="backoffice_alerts_cleanup_enabled" value="1" <?php checked(!empty($backoffice_alerts['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="backoffice_alerts_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('backoffice_alerts_cleanup_frequency', $backoffice_alerts['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $backoffice_alerts['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="backoffice_alerts_cleanup_custom_value" value="<?php echo esc_attr($backoffice_alerts['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('backoffice_alerts_cleanup_custom_unit', $backoffice_alerts['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label><?php _e('Delete Alerts Older Than', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="backoffice_alerts_cleanup_older_than" value="<?php echo esc_attr($backoffice_alerts['older_than']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <?php render_age_unit_dropdown('backoffice_alerts_cleanup_older_than_unit', $backoffice_alerts['older_than_unit']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Cleanup Log Cleanup -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Cleanup Log Maintenance', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($cleanup_log['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($cleanup_log['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Removes old entries from the cleanup log table to prevent it from growing too large.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value cleanup-log-count"><?php echo $cleanup_log_count; ?></span>
                            <span><?php _e('Total Log Entries', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-db-data-button" data-type="cleanup_log">
                            <?php _e('Preview Data', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-db-cleanup" data-task="cleanup_log">
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
                            <form class="db-cleanup-form" data-card-type="cleanup_log">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="cleanup_log_cleanup_enabled" value="1" <?php checked(!empty($cleanup_log['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="cleanup_log_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <?php render_frequency_dropdown('cleanup_log_cleanup_frequency', $cleanup_log['frequency'], true); ?>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $cleanup_log['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="cleanup_log_cleanup_custom_value" value="<?php echo esc_attr($cleanup_log['custom_value']); ?>" min="1" step="1">
                                        <?php render_custom_unit_dropdown('cleanup_log_cleanup_custom_unit', $cleanup_log['custom_unit']); ?>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label><?php _e('Delete Log Entries Older Than', 'swiftspeed-siberian'); ?></label>
                                        <div style="display: flex;">
                                            <input type="number" name="cleanup_log_cleanup_older_than" value="<?php echo esc_attr($cleanup_log['older_than']); ?>" min="1" step="1" style="width: 80px; margin-right: 10px;">
                                            <?php render_age_unit_dropdown('cleanup_log_cleanup_older_than_unit', $cleanup_log['older_than_unit']); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-db-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
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