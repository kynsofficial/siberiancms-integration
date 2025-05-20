<?php
/**
 * Siberian API Settings
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_API_Settings {
    
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
     * Save API automation settings
     */
    public function save_api_automation($command, $settings) {
        if (empty($command) || !in_array($command, array('manifest', 'clearcache', 'cleartmp', 'clearlogs'))) {
            return array(
                'success' => false,
                'message' => 'Invalid command type.'
            );
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize automate options if not exists
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        
        // Initialize API options if not exists
        if (!isset($options['automate']['api'])) {
            $options['automate']['api'] = array();
        }
        
        // Determine field prefix based on command
        $prefix = 'api_' . $command . '_';
        
        // Save only the specified command's settings
        $options['automate']['api'][$command] = array(
            'enabled' => isset($settings[$prefix . 'enabled']),
            'frequency' => sanitize_text_field($settings[$prefix . 'frequency']),
            'custom_value' => isset($settings[$prefix . 'custom_value']) ? intval($settings[$prefix . 'custom_value']) : 0,
            'custom_unit' => isset($settings[$prefix . 'custom_unit']) ? sanitize_text_field($settings[$prefix . 'custom_unit']) : 'hours'
        );
        
        // Save options
        update_option('swsib_options', $options);
        
        // Update only this command's schedule
        $this->update_command_schedule($command, $options['automate']['api'][$command]);
        
        return array(
            'success' => true,
            'message' => 'API automation settings saved for ' . $command
        );
    }
    
    /**
     * Update a single command's schedule
     * 
     * @param string $command The command to update
     * @param array $settings The settings for this command
     */
    private function update_command_schedule($command, $settings) {
        // Load the scheduler
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        
        // Schedule task if enabled
        if (!empty($settings['enabled'])) {
            $interval = $this->get_frequency_in_seconds($settings);
            $this->log_message("Scheduling $command task with interval: {$interval} seconds");
            
            $scheduler->schedule_task(
                'api_command',
                array(
                    'command' => $command,
                    'type' => $command
                ),
                $interval
            );
            
            // Force immediate check to ensure the task is scheduled properly
            $scheduler->schedule_next_check(true);
        } else {
            $scheduler->unschedule_task('api_command', array('type' => $command));
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
            case 'twice_daily':
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
     * Get settings for API automation
     */
    public function get_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['api'])) {
            return array(
                'manifest' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours'
                ),
                'clearcache' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours'
                ),
                'cleartmp' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours'
                ),
                'clearlogs' => array(
                    'enabled' => false,
                    'frequency' => 'daily',
                    'custom_value' => 0,
                    'custom_unit' => 'hours'
                )
            );
        }
        
        return $options['automate']['api'];
    }
    
    /**
     * Process settings for API automation
     */
    public function process_settings($input) {
        if (!isset($input['automate'])) {
            $input['automate'] = array();
        }
        
        // API settings are stored via AJAX
        
        return $input;
    }
    
    /**
     * Display API automation settings
     */
    public function display_settings() {
        // Get settings
        $settings = $this->get_settings();
        
        // Get manifest settings
        $manifest = isset($settings['manifest']) ? $settings['manifest'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'hours'
        );
        
        // Get clearcache settings
        $clearcache = isset($settings['clearcache']) ? $settings['clearcache'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'hours'
        );
        
        // Get cleartmp settings
        $cleartmp = isset($settings['cleartmp']) ? $settings['cleartmp'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'hours'
        );
        
        // Get clearlogs settings
        $clearlogs = isset($settings['clearlogs']) ? $settings['clearlogs'] : array(
            'enabled' => false,
            'frequency' => 'daily',
            'custom_value' => 0,
            'custom_unit' => 'hours'
        );
        
        // Get API settings from auto_login
        $options = get_option('swsib_options', array());
        $auto_login = isset($options['auto_login']) ? $options['auto_login'] : array();
        
        $siberian_url = isset($auto_login['siberian_url']) ? $auto_login['siberian_url'] : '';
        $api_configured = !empty($siberian_url) && !empty($auto_login['api_user']) && !empty($auto_login['api_password']);
        
        ?>
        <div class="task-section">
            <h3><?php _e('Siberian API Commands', 'swiftspeed-siberian'); ?></h3>
            
            <div class="info-text">
                <?php _e('These API commands help maintain your Siberian CMS installation by rebuilding caches and manifests.', 'swiftspeed-siberian'); ?>
            </div>
            
            <?php if (!$api_configured): ?>
            <div class="swsib-notice warning">
                <p><?php _e('Siberian API is not configured. Please configure the API credentials in the Auto Login tab.', 'swiftspeed-siberian'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=auto_login'); ?>" class="button button-primary"><?php _e('Configure API Credentials', 'swiftspeed-siberian'); ?></a></p>
            </div>
            <?php endif; ?>
            
            <div class="task-grid">
                <!-- Manifest Rebuild Command -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Manifest Rebuild', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($manifest['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($manifest['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Rebuilds application manifests for proper app functioning.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-primary run-api-command" data-command="manifest" <?php echo !$api_configured ? 'disabled' : ''; ?>>
                            <?php _e('Run Now', 'swiftspeed-siberian'); ?>
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
                            <form data-command="manifest">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="api_manifest_enabled" value="1" <?php checked(!empty($manifest['enabled'])); ?>>
                                            <?php _e('Enable automated rebuilding', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="api_manifest_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="api_manifest_frequency" id="api_manifest_frequency">
                                            <option value="every_30_minutes" <?php selected($manifest['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($manifest['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($manifest['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($manifest['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($manifest['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($manifest['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_days" <?php selected($manifest['frequency'], 'every_3_days'); ?>><?php _e('Every 3 Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($manifest['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_weeks" <?php selected($manifest['frequency'], 'every_2_weeks'); ?>><?php _e('Every 2 Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="monthly" <?php selected($manifest['frequency'], 'monthly'); ?>><?php _e('Monthly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($manifest['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $manifest['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="api_manifest_custom_value" value="<?php echo esc_attr($manifest['custom_value']); ?>" min="1" step="1">
                                        <select name="api_manifest_custom_unit">
                                            <option value="minutes" <?php selected($manifest['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($manifest['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($manifest['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($manifest['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($manifest['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-api-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Clear Cache Command -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Clear Cache', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($clearcache['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($clearcache['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Clears all Siberian CMS caches (var/cache) to ensure fresh content delivery.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-primary run-api-command" data-command="clearcache" <?php echo !$api_configured ? 'disabled' : ''; ?>>
                            <?php _e('Run Now', 'swiftspeed-siberian'); ?>
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
                            <form data-command="clearcache">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="api_clearcache_enabled" value="1" <?php checked(!empty($clearcache['enabled'])); ?>>
                                            <?php _e('Enable automated clearing', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="api_clearcache_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="api_clearcache_frequency" id="api_clearcache_frequency">
                                            <option value="every_30_minutes" <?php selected($clearcache['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($clearcache['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($clearcache['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($clearcache['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($clearcache['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($clearcache['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_days" <?php selected($clearcache['frequency'], 'every_3_days'); ?>><?php _e('Every 3 Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($clearcache['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($clearcache['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $clearcache['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="api_clearcache_custom_value" value="<?php echo esc_attr($clearcache['custom_value']); ?>" min="1" step="1">
                                        <select name="api_clearcache_custom_unit">
                                            <option value="minutes" <?php selected($clearcache['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($clearcache['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($clearcache['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($clearcache['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($clearcache['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-api-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Clear Tmp Command -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Clear Tmp', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($cleartmp['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($cleartmp['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Clears temporary files (var/tmp) from the Siberian CMS installation.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-primary run-api-command" data-command="cleartmp" <?php echo !$api_configured ? 'disabled' : ''; ?>>
                            <?php _e('Run Now', 'swiftspeed-siberian'); ?>
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
                            <form data-command="cleartmp">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="api_cleartmp_enabled" value="1" <?php checked(!empty($cleartmp['enabled'])); ?>>
                                            <?php _e('Enable automated clearing', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="api_cleartmp_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="api_cleartmp_frequency" id="api_cleartmp_frequency">
                                            <option value="every_30_minutes" <?php selected($cleartmp['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($cleartmp['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($cleartmp['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($cleartmp['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($cleartmp['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($cleartmp['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_days" <?php selected($cleartmp['frequency'], 'every_3_days'); ?>><?php _e('Every 3 Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($cleartmp['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($cleartmp['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $cleartmp['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="api_cleartmp_custom_value" value="<?php echo esc_attr($cleartmp['custom_value']); ?>" min="1" step="1">
                                        <select name="api_cleartmp_custom_unit">
                                            <option value="minutes" <?php selected($cleartmp['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($cleartmp['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($cleartmp['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($cleartmp['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($cleartmp['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-api-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Clear Logs Command -->
                <div class="task-card">
                    <div class="task-card-header">
                        <h4 class="task-card-title"><?php _e('Clear Logs', 'swiftspeed-siberian'); ?></h4>
                        <span class="task-card-badge <?php echo !empty($clearlogs['enabled']) ? 'active' : ''; ?>">
                            <?php echo !empty($clearlogs['enabled']) ? __('Automated', 'swiftspeed-siberian') : __('Manual', 'swiftspeed-siberian'); ?>
                        </span>
                    </div>
                    
                    <div class="task-card-description">
                        <?php _e('Clears log files (var/logs) from the Siberian CMS installation.', 'swiftspeed-siberian'); ?>
                    </div>
                    
                    <div class="task-card-actions">
                        <button type="button" class="button button-primary run-api-command" data-command="clearlogs" <?php echo !$api_configured ? 'disabled' : ''; ?>>
                            <?php _e('Run Now', 'swiftspeed-siberian'); ?>
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
                            <form data-command="clearlogs">
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label>
                                            <input type="checkbox" name="api_clearlogs_enabled" value="1" <?php checked(!empty($clearlogs['enabled'])); ?>>
                                            <?php _e('Enable automated clearing', 'swiftspeed-siberian'); ?>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="task-settings-field-group">
                                    <div class="task-settings-field">
                                        <label for="api_clearlogs_frequency"><?php _e('Frequency', 'swiftspeed-siberian'); ?></label>
                                        <select name="api_clearlogs_frequency" id="api_clearlogs_frequency">
                                            <option value="every_30_minutes" <?php selected($clearlogs['frequency'], 'every_30_minutes'); ?>><?php _e('Every 30 Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hourly" <?php selected($clearlogs['frequency'], 'hourly'); ?>><?php _e('Hourly', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_2_hours" <?php selected($clearlogs['frequency'], 'every_2_hours'); ?>><?php _e('Every 2 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_5_hours" <?php selected($clearlogs['frequency'], 'every_5_hours'); ?>><?php _e('Every 5 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_12_hours" <?php selected($clearlogs['frequency'], 'every_12_hours'); ?>><?php _e('Every 12 Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="daily" <?php selected($clearlogs['frequency'], 'daily'); ?>><?php _e('Daily', 'swiftspeed-siberian'); ?></option>
                                            <option value="every_3_days" <?php selected($clearlogs['frequency'], 'every_3_days'); ?>><?php _e('Every 3 Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weekly" <?php selected($clearlogs['frequency'], 'weekly'); ?>><?php _e('Weekly', 'swiftspeed-siberian'); ?></option>
                                            <option value="custom" <?php selected($clearlogs['frequency'], 'custom'); ?>><?php _e('Custom', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                    
                                    <div class="custom-frequency-container" style="<?php echo $clearlogs['frequency'] === 'custom' ? '' : 'display: none;'; ?>">
                                        <input type="number" name="api_clearlogs_custom_value" value="<?php echo esc_attr($clearlogs['custom_value']); ?>" min="1" step="1">
                                        <select name="api_clearlogs_custom_unit">
                                            <option value="minutes" <?php selected($clearlogs['custom_unit'], 'minutes'); ?>><?php _e('Minutes', 'swiftspeed-siberian'); ?></option>
                                            <option value="hours" <?php selected($clearlogs['custom_unit'], 'hours'); ?>><?php _e('Hours', 'swiftspeed-siberian'); ?></option>
                                            <option value="days" <?php selected($clearlogs['custom_unit'], 'days'); ?>><?php _e('Days', 'swiftspeed-siberian'); ?></option>
                                            <option value="weeks" <?php selected($clearlogs['custom_unit'], 'weeks'); ?>><?php _e('Weeks', 'swiftspeed-siberian'); ?></option>
                                            <option value="months" <?php selected($clearlogs['custom_unit'], 'months'); ?>><?php _e('Months', 'swiftspeed-siberian'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="task-settings-actions">
                                    <button type="button" class="button button-primary save-api-automation"><?php _e('Save Settings', 'swiftspeed-siberian'); ?></button>
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