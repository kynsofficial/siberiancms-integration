<?php
/**
 * Image Cleanup - Settings handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Image_Settings {
    
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
    public function __construct($db_connection = null, $db_name = null, $data = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
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
     * AJAX handler for saving image cleanup automation settings
     */
    public function ajax_save_image_cleanup_automation() {
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
        
        // Save image cleanup settings
        $options['automate']['image_cleanup'] = array(
            'enabled' => isset($settings['image_cleanup_enabled']),
            'frequency' => sanitize_text_field($settings['image_cleanup_frequency']),
            'custom_value' => isset($settings['image_cleanup_custom_value']) ? intval($settings['image_cleanup_custom_value']) : 0,
            'custom_unit' => isset($settings['image_cleanup_custom_unit']) ? sanitize_text_field($settings['image_cleanup_custom_unit']) : 'hours'
        );
        
        // Save options
        update_option('swsib_options', $options);
        
        // Update schedule
        $this->update_image_cleanup_schedule($options['automate']['image_cleanup']);
        
        wp_send_json_success(array('message' => 'Image cleanup automation settings saved.'));
    }
    
    /**
     * Update image cleanup schedule
     */
    private function update_image_cleanup_schedule($settings) {
        // Load the scheduler
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        
        // Schedule image cleanup task
        if (!empty($settings['enabled'])) {
            $scheduler->schedule_task(
                'image_cleanup',
                array(),
                $this->get_frequency_in_seconds($settings)
            );
        } else {
            $scheduler->unschedule_task('image_cleanup', array());
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
                return 604800; // Default to weekly
            }
    }
    
    /**
     * Get settings for image cleanup automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['image_cleanup'])) {
            return array(
                'enabled' => false,
                'frequency' => 'weekly',
                'custom_value' => 0,
                'custom_unit' => 'hours'
            );
        }
        
        return $options['automate']['image_cleanup'];
    }
    
    /**
     * Process settings for image cleanup automation
     */
    public function process_settings($input) {
        // Settings are processed via AJAX
        return $input;
    }
    
    /**
     * Display image cleanup automation settings
     */
    public function display_settings() {
        // Get settings
        $settings = $this->get_settings();
        
        // Get orphaned folders count
        $orphaned_count = $this->data->get_orphaned_images_count();
        
        ?>
        <div class="task-section">
            <h3><?php _e('Image Folder Cleanup', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('Cleans up orphaned image folders from applications that have been deleted from the database.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="task-grid">
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Orphaned Image Folders', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($settings['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($settings['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('When applications are deleted, sometimes their image folders remain. This task cleans up these orphaned folders.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-counts">
                        <div class="task-card-count">
                            <span class="task-card-count-value orphaned-images-count"><?php echo $orphaned_count; ?></span>
                            <span><?php _e('Orphaned Folders', 'swiftspeed-siberian'); ?></span>
                        </div>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-secondary preview-orphaned-folders-button">
                            <?php _e('Preview Folders', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" class="button button-primary run-image-cleanup">
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
                                            <input type="checkbox" name="image_cleanup_enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                            <?php _e('Enable automated cleanup', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="image_cleanup_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="image_cleanup_frequency" id="image_cleanup_frequency">
                                            <option value="every_30_minutes" <?php selected($settings['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($settings['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($settings['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($settings['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($settings['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($settings['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_days" <?php selected($settings['frequency'], 'every_3_days'); ?>><?php _e('Every 3 Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($settings['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_weeks" <?php selected($settings['frequency'], 'every_2_weeks'); ?>><?php _e('Every 2 Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($settings['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_months" <?php selected($settings['frequency'], 'every_3_months'); ?>><?php _e('Every 3 Months', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_6_months" <?php selected($settings['frequency'], 'every_6_months'); ?>><?php _e('Every 6 Months', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_9_months" <?php selected($settings['frequency'], 'every_9_months'); ?>><?php _e('Every 9 Months', 'swiftspeed-siberian'); ?></option>
                                            <option value="yearly" <?php selected($settings['frequency'], 'yearly'); ?>><?php _e('Yearly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($settings['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $settings['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="image_cleanup_custom_value" value="<?php echo esc_attr($settings['custom_value']); ?>" min="1" step="1">
                                        <select name="image_cleanup_custom_unit">
                                            <option value="minutes" <?php selected($settings['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($settings['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($settings['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($settings['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($settings['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-image-cleanup-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
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