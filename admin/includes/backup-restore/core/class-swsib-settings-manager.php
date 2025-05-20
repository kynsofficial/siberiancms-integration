<?php
/**
 * Backup & Restore Settings Manager.
 * Extracted from the core class to handle settings management separately.
 * 
 * @since 2.3.0
 */
class SwiftSpeed_Siberian_Settings_Manager {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Storage manager instance.
     * 
     * @var SwiftSpeed_Siberian_Storage_Manager
     */
    private $storage_manager;
    
    /**
     * Maximum steps to process in one background run
     * 
     * @var int
     */
    private $max_steps;
    
    /**
     * Cron manager instance
     * 
     * @var SwiftSpeed_Siberian_Cron_Manager
     */
    private $cron_manager = null;
    
    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Register settings hooks
        add_action('admin_init', array($this, 'register_settings'));
        
        // Set max steps from options or default
        $this->set_max_steps();
        
        // Add support for keeping tab selection on save
        add_action('admin_head', array($this, 'preserve_tab_on_submit'));
        
        // Add filter to redirect back to same tab after settings save
        add_filter('wp_redirect', array($this, 'redirect_to_active_tab'), 10, 2);
    }
    
    /**
     * Set the storage manager instance.
     *
     * @param SwiftSpeed_Siberian_Storage_Manager $storage_manager Storage manager instance.
     * @return void
     */
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    /**
     * Set the cron manager instance.
     *
     * @param SwiftSpeed_Siberian_Cron_Manager $cron_manager Cron manager instance.
     * @return void
     */
    public function set_cron_manager($cron_manager) {
        $this->cron_manager = $cron_manager;
    }
    
    /**
     * Set max steps from options
     */
    private function set_max_steps() {
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $this->max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        // Ensure valid values
        if ($this->max_steps < 2 || $this->max_steps > 25) {
            $this->max_steps = 5; // Default to 5 if invalid
        }
    }
    
    /**
     * Get the maximum processing steps.
     *
     * @return int Maximum processing steps.
     */
    public function get_max_steps() {
        return $this->max_steps;
    }

    /**
     * Filter the redirect URL to maintain the active tab
     * 
     * @param string $location The redirect location
     * @param int $status The redirect status
     * @return string Modified location with active tab
     */
    public function redirect_to_active_tab($location, $status) {
        // Only modify our own plugin redirects
        if (strpos($location, 'page=swsib-integration') !== false) {
            // Check if an active tab was set
            if (isset($_POST['active_tab']) && !empty($_POST['active_tab'])) {
                $active_tab = sanitize_key($_POST['active_tab']);
                
                // Handle case if location already has a hash
                if (strpos($location, '#') !== false) {
                    $location = preg_replace('/#.*$/', '', $location);
                }
                
                // Append the active tab as a hash
                $location .= '#' . $active_tab;
            }
        }
        
        return $location;
    }
    
   /**
 * Add script to preserve tab selection on form submission and
 * automatically check Google Drive connection status
 */
public function preserve_tab_on_submit() {
    // Only add on plugin admin page
    $screen = get_current_screen();
    if (!$screen || strpos($screen->base, 'swsib-integration') === false) {
        return;
    }
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Store the current tab in a hidden field when form is submitted
        $('#siberian-backup-settings-form').on('submit', function() {
            const currentTab = $('.siberian-tabs-content').attr('data-active-tab') || 'settings';
            $('input[name="active_tab"]').val(currentTab);
            
            // Also update the URL hash - this helps with browser refreshes
            window.location.hash = '#' + currentTab;
            
            // Return true to allow the form to submit
            return true;
        });
        
        // Check Google Drive connection status when the Google Drive tab is selected
        $('.siberian-storage-tabs a[href="#siberian-storage-gdrive"]').on('click', function() {
            checkGDriveConnectionStatus();
        });
        
        // Also check status when the page loads if Google Drive tab is active
        if ($('#siberian-storage-gdrive').hasClass('active')) {
            checkGDriveConnectionStatus();
        }
        
        // Additionally, check status on initial page load regardless of active tab
        // to ensure connection status is always up-to-date
        setTimeout(function() {
            checkGDriveConnectionStatus();
        }, 500);
        
        // Function to check Google Drive connection status
        function checkGDriveConnectionStatus() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_check_storage_connection',
                    nonce: swsib_backup_restore.nonce,
                    provider: 'gdrive'
                },
                success: function(response) {
                    if (response.success && response.data.connected) {
                        // Update the Google Drive connection status
                        updateGDriveConnectionStatus(true, response.data);
                    }
                }
            });
        }
        
        // Helper function to update Google Drive connection status
        function updateGDriveConnectionStatus(isConnected, data) {
            // Find the auth button
            const authButton = $('.siberian-auth-provider-button[data-provider="gdrive"]');
            const authContainer = authButton.closest('.siberian-field');
            
            // Update the display based on connection status
            if (isConnected) {
                // Remove existing status to avoid duplicates
                authContainer.find('.gdrive-connected-status').remove();
                
                // Add connected indicator
                authContainer.append('<div class="gdrive-connected-status" style="margin-top: 10px; color: green;"><span class="dashicons dashicons-yes-alt"></span> Connected to Google Drive' + 
                    (data.email ? ' (' + data.email + ')' : '') + 
                    '</div>');
                
                // Update button text to "Reconnect to Google Drive"
                authButton.html('Reconnect to Google Drive');
                
                // Update storage checkboxes in backup form
                const storageCheckboxes = $('.siberian-storage-checkbox');
                storageCheckboxes.each(function() {
                    const checkbox = $(this).find('input[type="checkbox"]');
                    if (checkbox.val() === 'gdrive') {
                        // Add connected indicator next to checkbox if not already there
                        if ($(this).find('.storage-connected').length === 0) {
                            $(this).append(' <span class="dashicons dashicons-yes-alt storage-connected" title="Connected"></span>');
                        }
                    }
                });
            }
        }
        
        // Toggle external cron info when checkbox changes
        $('#swsib_options_backup_restore_use_external_cron').on('change', function() {
            if ($(this).is(':checked')) {
                $('.external-cron-info').show();
            } else {
                $('.external-cron-info').hide();
            }
        });
        
        // Copy cron URL to clipboard
        $('.copy-cron-url').on('click', function() {
            var urlInput = $('.siberian-cron-url');
            urlInput.select();
            document.execCommand('copy');
            
            // Show feedback
            var $button = $(this);
            var originalText = $button.html();
            $button.html('<span class="dashicons dashicons-yes"></span> <?php _e('Copied!', 'swiftspeed-siberian'); ?>');
            
            setTimeout(function() {
                $button.html(originalText);
            }, 2000);
        });
        
        // Update cron example when interval settings change
        $('#swsib_options_backup_restore_scheduled_interval_value, #swsib_options_backup_restore_scheduled_interval_unit').on('change', function() {
            updateCronExample();
        });
        
        // Function to update cron example based on current settings
        function updateCronExample() {
            var value = $('#swsib_options_backup_restore_scheduled_interval_value').val();
            var unit = $('#swsib_options_backup_restore_scheduled_interval_unit').val();
            var cronTiming = '';
            
            switch(unit) {
                case 'minutes':
                    cronTiming = '*/' + value + ' * * * *';
                    break;
                case 'hours':
                    cronTiming = '0 */' + value + ' * * *';
                    break;
                case 'days':
                    cronTiming = '0 0 */' + value + ' * *';
                    break;
                case 'weeks':
                    cronTiming = '0 0 * * ' + (value == 1 ? '0' : '*/' + value);
                    break;
                case 'months':
                    cronTiming = '0 0 1 */' + value + ' *';
                    break;
                default:
                    cronTiming = '0 0 * * *'; // Daily at midnight by default
            }
            
            // Get cron URL
            var cronUrl = $('.siberian-cron-url').val();
            
            // Update example
            $('.siberian-cron-example').text(cronTiming + ' wget -q -O /dev/null \'' + cronUrl + '\' >/dev/null 2>&1');
        }
    });
    </script>
    <?php
}
    
    /**
     * Register settings for Backup & Restore.
     *
     * @return void
     */
    public function register_settings() {
        // Use the existing swsib_options option
        register_setting(
            'swsib_options',
            'swsib_options',
            array($this, 'sanitize_options')
        );
    }
    
    /**
     * Sanitize and process options
     */
    public function sanitize_options($input) {
        // Get the complete existing options
        $existing_options = get_option('swsib_options', array());
        
        // Only process backup/restore settings if that's what's being updated
        if (isset($input['backup_restore'])) {
            // Process backup/restore specific settings
            $input['backup_restore'] = $this->process_settings($input['backup_restore']);
            
            // Merge the processed input with existing options
            return array_merge($existing_options, $input);
        }
        
        // If other settings are being updated, simply return the input
        // This ensures we don't interfere with other parts of the plugin
        return $input;
    }
    
    /**
     * Process settings for Backup & Restore.
     *
     * @param array $backup_restore_settings Backup & Restore settings.
     * @return array Processed settings.
     */
    public function process_settings($backup_restore_settings) {
        if (empty($backup_restore_settings)) {
            return $backup_restore_settings;
        }
        
        // Process storage provider settings if present
        if (isset($backup_restore_settings['storage']) && $this->storage_manager) {
            $backup_restore_settings = $this->storage_manager->process_settings($backup_restore_settings);
        }
        
        // Process scheduled backup settings
        if (isset($backup_restore_settings['scheduled_enabled'])) {
            $backup_restore_settings = $this->process_scheduled_backup_settings($backup_restore_settings);
        } else {
            // Clear schedule if disabled
            if ($this->cron_manager) {
                $this->cron_manager->clear_scheduled_events();
            } else {
                wp_clear_scheduled_hook('swsib_process_scheduled_backup');
            }
        }
        
        // Process max steps setting
        if (isset($backup_restore_settings['max_steps'])) {
            $max_steps = intval($backup_restore_settings['max_steps']);
            
            // Ensure max_steps is within valid range
            $backup_restore_settings['max_steps'] = max(2, min(25, $max_steps));
            
            // Update the property value
            $this->max_steps = $backup_restore_settings['max_steps'];
        }
        
        return $backup_restore_settings;
    }
    
    /**
     * Process scheduled backup settings with improved interval support.
     *
     * @param array $backup_restore_settings Backup & Restore settings.
     * @return array Processed settings.
     */
    private function process_scheduled_backup_settings($backup_restore_settings) {
        // Get old settings for comparison
        $old_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        
        // Process interval settings
        $interval_value = isset($backup_restore_settings['scheduled_interval_value']) ? 
                         intval($backup_restore_settings['scheduled_interval_value']) : 1;
        $interval_unit = isset($backup_restore_settings['scheduled_interval_unit']) ? 
                        sanitize_key($backup_restore_settings['scheduled_interval_unit']) : 'days';
        
        // Ensure valid values
        $backup_restore_settings['scheduled_interval_value'] = max(1, min(100, $interval_value));
        
        if (!in_array($interval_unit, array('minutes', 'hours', 'days', 'weeks', 'months'))) {
            $backup_restore_settings['scheduled_interval_unit'] = 'days';
        }
        
        // Check if the settings have changed enough to warrant rescheduling
        $need_reset = false;
        
        if (!isset($old_settings['scheduled_interval_value']) || 
            !isset($old_settings['scheduled_interval_unit']) ||
            $old_settings['scheduled_interval_value'] != $backup_restore_settings['scheduled_interval_value'] ||
            $old_settings['scheduled_interval_unit'] != $backup_restore_settings['scheduled_interval_unit']) {
            $need_reset = true;
        }
        
        // Reset schedule if needed
        if ($need_reset) {
            if ($this->cron_manager) {
                $this->cron_manager->reset_schedule();
            } else {
                // Fallback to direct WP cron handling if cron manager not available
                $this->reset_cron_schedule($backup_restore_settings);
            }
        }
        
        return $backup_restore_settings;
    }
    
    /**
     * Fallback method to reset cron schedule directly.
     * Used only if cron_manager is not available.
     * 
     * @param array $backup_restore_settings Backup settings
     */
    private function reset_cron_schedule($backup_restore_settings) {
        // Clear existing events
        wp_clear_scheduled_hook('swsib_process_scheduled_backup');
        
        // Get interval settings
        $interval_value = isset($backup_restore_settings['scheduled_interval_value']) ? 
                         intval($backup_restore_settings['scheduled_interval_value']) : 1;
        $interval_unit = isset($backup_restore_settings['scheduled_interval_unit']) ? 
                        $backup_restore_settings['scheduled_interval_unit'] : 'days';
        
        // Create custom schedule if needed
        $schedule_name = 'swsib_every_' . $interval_value . '_' . $interval_unit;
        $schedule_interval = $this->convert_to_seconds($interval_value, $interval_unit);
        
        // Register the custom schedule
        add_filter('cron_schedules', function($schedules) use ($schedule_name, $schedule_interval, $interval_value, $interval_unit) {
            $schedules[$schedule_name] = array(
                'interval' => $schedule_interval,
                'display' => sprintf(__('Every %d %s', 'swiftspeed-siberian'), $interval_value, $interval_unit)
            );
            return $schedules;
        });
        
        // Schedule the event
        if (!wp_next_scheduled('swsib_process_scheduled_backup')) {
            wp_schedule_event(time() + 300, $schedule_name, 'swsib_process_scheduled_backup');
        }
    }
    
    /**
     * Convert interval to seconds
     *
     * @param int $value Interval value
     * @param string $unit Interval unit (minutes, hours, days, weeks, months)
     * @return int Seconds
     */
    private function convert_to_seconds($value, $unit) {
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
    
    /**
     * Test storage connection.
     *
     * @param string $provider_id Storage provider ID.
     * @param array $provider_settings Provider settings.
     * @return true|WP_Error True on success or error object.
     */
    public function test_storage_connection($provider_id, $provider_settings) {
        if (!$this->storage_manager) {
            return new WP_Error('no_storage_manager', __('Storage manager not initialized', 'swiftspeed-siberian'));
        }
        
        // Get provider
        $provider = $this->storage_manager->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Provider not found', 'swiftspeed-siberian'));
        }
        
        // Create a temporary instance with the new settings
        $temp_provider_class = get_class($provider);
        $temp_provider = new $temp_provider_class($provider_settings);
        
        // Test connection
        return $temp_provider->test_connection();
    }
    
    /**
     * Get the display name of a storage provider.
     * 
     * @param string $storage_type Storage provider type.
     * @return string Display name.
     */
    public function get_storage_display_name($storage_type) {
        $display_names = [
            'local' => __('Local', 'swiftspeed-siberian'),
            'gdrive' => __('Google Drive', 'swiftspeed-siberian'),
            'gcs' => __('Google Cloud Storage', 'swiftspeed-siberian'),
            's3' => __('Amazon S3', 'swiftspeed-siberian'),
        ];
        
        return isset($display_names[$storage_type]) ? $display_names[$storage_type] : $storage_type;
    }
    
    /**
     * Log a message for debugging.
     * 
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
}