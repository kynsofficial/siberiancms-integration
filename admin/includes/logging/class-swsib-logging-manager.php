<?php
/**
 * Logging management functionality for the plugin.
 */
class SwiftSpeed_Siberian_Logging_Manager {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Log directory
     */
    private $log_dir;
    
    /**
     * Available loggers
     */
    private $available_loggers = array(
        'auto_login' => array(
            'backend' => 'Auto Login Backend',
            'frontend' => 'Auto Login Frontend'
        ),
        'autologin_advanced' => array(
            'backend' => 'Auto-Login Advanced Backend',
            'frontend' => 'Auto Login Advanced Frontend'
        ),
        'compatibility' => array(
            'backend' => 'Compatibility Backend',
            'frontend' => 'Compatibility Frontend'
        ),
        'License' => array(
            'backend' => 'License Backend',
            'frontend' => 'License Frontend'
        ),
        'dbconnect' => array(
            'backend' => 'DB Connect Backend',
        ),
        'fileconnect' => array(
            'backend' => 'File Connect Backend',
        ),
        'woocommerce' => array(
            'backend' => 'WooCommerce Integration Backend',
            'frontend' => 'WooCommerce Integration Frontend'
        ),
       'subscription' => array(
            'backend' => 'Subscription Integration Backend',
            'database' => 'Subscription Integration Database',
            'frontend' => 'Subscription Integration Frontend'


        ),
        'clean' => array(
            'backend' => 'Clean Backend'
        ),
        'automate' => array(
            'backend' => 'Automate Backend'          
        ),

        'backup' => array(
            'backup' => 'Backup Files',
            'restore' => 'Restore Files',
            'storage' => 'Backup Storage'          
        ),


        'admin' => array(
            'backend' => 'Admin Backend'
        ),
    
        'system' => array(
            'error' => 'System Errors',
            'info' => 'System Info'
        )
    );
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = get_option('swsib_options', array());
        
        // Set log directory
        $this->log_dir = SWSIB_PLUGIN_DIR . 'log/';
        
        // Create log directory if it doesn't exist
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            
            // Create .htaccess to protect logs
            file_put_contents($this->log_dir . '.htaccess', "Order deny,allow\nDeny from all");
            
            // Create index.php to prevent directory listing
            file_put_contents($this->log_dir . 'index.php', "<?php\n// Silence is golden.");
        }
        
        // Initialize logging settings if they don't exist
        if (!isset($this->options['logging'])) {
            $this->options['logging'] = array(
                'loggers' => array()
            );
            update_option('swsib_options', $this->options);
        }
        
        // Register AJAX handlers for log viewing
        add_action('wp_ajax_swsib_get_log_content', array($this, 'ajax_get_log_content'));
        add_action('wp_ajax_swsib_clear_log', array($this, 'ajax_clear_log'));
        add_action('wp_ajax_swsib_delete_all_logs', array($this, 'ajax_delete_all_logs'));
        
        // Register action for form submission
        add_action('admin_post_swsib_save_logging_settings', array($this, 'process_form_submission'));
        
        // Add filter to prevent overwriting other settings
        add_filter('pre_update_option_swsib_options', array($this, 'filter_update_options'), 10, 2);
        
        // Clean up inconsistent log files
        $this->clean_inconsistent_log_files();
    }
    
    /**
     * Filter to properly handle saving options without overwriting other tabs
     */
    public function filter_update_options($new_value, $old_value) {
        // Only apply this filter when saving logging settings
        if (isset($_POST['option_page']) && $_POST['option_page'] === 'swsib_logging_options') {
            if (is_array($old_value) && is_array($new_value)) {
                // Extract only the logging settings from the new value
                $logging_settings = isset($new_value['logging']) ? $new_value['logging'] : array();
                
                // Keep all other settings from the old value
                foreach ($old_value as $key => $value) {
                    if ($key !== 'logging') {
                        $new_value[$key] = $value;
                    }
                }
                
                // Update only the logging settings
                $new_value['logging'] = $logging_settings;
            }
        }
        
        return $new_value;
    }
    
    /**
     * Clean up inconsistent log files - remove files with hybrid naming pattern
     */
    private function clean_inconsistent_log_files() {
        // Check if log directory exists
        if (!file_exists($this->log_dir)) {
            return;
        }
        
        // Get all files in log directory
        $files = scandir($this->log_dir);
        
        foreach ($files as $file) {
            // Skip directories and non-log files
            if (is_dir($this->log_dir . $file) || !preg_match('/\.log$/', $file)) {
                continue;
            }
            
            // Check for inconsistent naming pattern (mixed hyphen and underscore)
            if (strpos($file, '-') !== false && strpos($file, '_') !== false) {
                // Delete inconsistently named file
                unlink($this->log_dir . $file);
            }
        }
    }
    
    /**
     * Process form submission
     */
    public function process_form_submission() {
        // Check nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'swsib_logging_options-options')) {
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=logging&error=nonce_failed'));
            exit;
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Get previous loggers to compare
        $previous_loggers = isset($options['logging']['loggers']) ? $options['logging']['loggers'] : array();
        
        // Initialize logging array if not exists
        if (!isset($options['logging'])) {
            $options['logging'] = array(
                'loggers' => array()
            );
        }
        
        // Reset all loggers
        $new_loggers = array();
        
        // Process enabled loggers
        if (isset($_POST['swsib_options']['logging']['loggers']) && is_array($_POST['swsib_options']['logging']['loggers'])) {
            foreach ($_POST['swsib_options']['logging']['loggers'] as $logger_id => $enabled) {
                $new_loggers[$logger_id] = true;
                
                // Create log file if it doesn't exist
                list($logger_group, $logger_key) = explode('_', $logger_id, 2);
                $log_file = $this->get_log_filename($logger_group, $logger_key);
                
                if (!file_exists($log_file)) {
                    $this->write_log_message($logger_group, $logger_key, 'Log initialized on ' . date('Y-m-d H:i:s'));
                }
            }
        }
        
        // Find loggers that have been disabled and delete their logs
        foreach ($previous_loggers as $logger_id => $enabled) {
            if ($enabled && !isset($new_loggers[$logger_id])) {
                // This logger has been disabled - delete its log file
                list($logger_group, $logger_key) = explode('_', $logger_id, 2);
                $log_file = $this->get_log_filename($logger_group, $logger_key);
                
                if (file_exists($log_file)) {
                    unlink($log_file);
                }
            }
        }
        
        // Update the loggers in options (only the logging part)
        $options['logging']['loggers'] = $new_loggers;
        
        // Save options
        update_option('swsib_options', $options);
        
        // Redirect back to the tab with a success parameter (consistent with other tabs)
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=logging&logging_updated=true'));
        exit;
    }
    
    /**
     * Display logging settings
     */
    public function display_settings() {
        $logging_options = isset($this->options['logging']) ? $this->options['logging'] : array('loggers' => array());
        $enabled_loggers = isset($logging_options['loggers']) ? $logging_options['loggers'] : array();
        
        // Ensure log directory exists
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        // Check for existing log files
        $existing_logs = array();
        foreach ($this->available_loggers as $logger_group => $loggers) {
            foreach ($loggers as $logger_key => $logger_name) {
                $log_file = $this->get_log_filename($logger_group, $logger_key);
                if (file_exists($log_file)) {
                    $logger_id = $logger_group . '_' . $logger_key;
                    $existing_logs[$logger_id] = array(
                        'name' => $logger_name,
                        'size' => filesize($log_file)
                    );
                }
            }
        }
        
        // Check for tab-specific success message
        if (isset($_GET['logging_updated']) && $_GET['logging_updated'] == 'true') {
            echo '<div class="swsib-notice success"><p>' . __('Logging settings saved successfully.', 'swiftspeed-siberian') . '</p></div>';
        }
        
        // Check for nonce error
        if (isset($_GET['error']) && $_GET['error'] == 'nonce_failed') {
            echo '<div class="swsib-notice error"><p>' . __('Security check failed. Please try again.', 'swiftspeed-siberian') . '</p></div>';
        }
        
        ?>
        <h2><?php _e('Logging Management', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Configure detailed logging for troubleshooting and development purposes.', 'swiftspeed-siberian'); ?>
        </p>
        
        <div class="swsib-notice warning">
            <p><strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
            <?php _e('Enabling logging will create log files that may impact performance. Only enable specific loggers when necessary for troubleshooting or development.', 'swiftspeed-siberian'); ?></p>
            <p><?php _e('Log files will be automatically deleted when a logger is disabled.', 'swiftspeed-siberian'); ?></p>
        </div>
        
        <!-- Direct form submission to admin-post.php -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="swsib-logging-form" class="swsib-settings-form">
            <?php wp_nonce_field('swsib_logging_options-options'); ?>
            <input type="hidden" name="action" value="swsib_save_logging_settings">
            <input type="hidden" name="tab_id" id="logging-tab-id-field-unique" value="logging" />
            <input type="hidden" name="option_page" value="swsib_logging_options" />
            
            <h3><?php _e('Available Loggers', 'swiftspeed-siberian'); ?></h3>
            <p><?php _e('Select which components to log. Each enabled logger will create a separate log file.', 'swiftspeed-siberian'); ?></p>
            
            <div class="swsib-loggers-grid">
                <?php foreach ($this->available_loggers as $logger_group => $loggers): ?>
                    <div class="swsib-logger-group">
                        <h4><?php echo ucfirst(str_replace('_', ' ', $logger_group)); ?></h4>
                        <?php foreach ($loggers as $logger_key => $logger_name): ?>
                            <?php
                            $logger_id = $logger_group . '_' . $logger_key;
                            $logger_enabled = isset($enabled_loggers[$logger_id]) && $enabled_loggers[$logger_id];
                            $log_file = $this->get_log_filename($logger_group, $logger_key);
                            $log_exists = file_exists($log_file);
                            $log_size = $log_exists ? size_format(filesize($log_file)) : '0 KB';
                            $unique_field_id = 'swsib_options_logging_loggers_' . $logger_id . '_' . uniqid();
                            ?>
                            <div class="swsib-logger-item">
                                <div class="swsib-field switch-field compact">
                                    <label for="<?php echo esc_attr($unique_field_id); ?>">
                                        <?php echo esc_html($logger_name); ?>
                                        <?php if ($log_exists): ?>
                                            <span class="swsib-log-size">(<?php echo $log_size; ?>)</span>
                                        <?php endif; ?>
                                    </label>
                                    <div class="toggle-container">
                                        <label class="switch small">
                                            <input type="checkbox" 
                                                id="<?php echo esc_attr($unique_field_id); ?>" 
                                                name="swsib_options[logging][loggers][<?php echo $logger_id; ?>]" 
                                                value="1" 
                                                class="logger-toggle"
                                                data-logger="<?php echo $logger_id; ?>"
                                                <?php checked($logger_enabled); ?> />
                                            <span class="slider round"></span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($existing_logs)): ?>
            <div class="swsib-log-viewer">
                <h3><?php _e('Log Viewer', 'swiftspeed-siberian'); ?></h3>
                
                <div class="swsib-log-controls">
                    <select id="swsib-log-selector" class="swsib-log-selector">
                        <option value=""><?php _e('Select a log to view', 'swiftspeed-siberian'); ?></option>
                        <?php
                        // Loop through available loggers
                        foreach ($existing_logs as $logger_id => $log_info) {
                            echo '<option value="' . esc_attr($logger_id) . '">' . esc_html($log_info['name']) . ' (' . size_format($log_info['size']) . ')</option>';
                        }
                        ?>
                    </select>
                    
                    <div class="swsib-log-buttons">
                        <button type="button" id="swsib-refresh-log" class="button swsib-refresh-log" disabled>
                            <span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" id="swsib-copy-log" class="button swsib-copy-log" disabled>
                            <span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" id="swsib-clear-log" class="button swsib-clear-log" disabled>
                            <span class="dashicons dashicons-trash"></span> <?php _e('Clear', 'swiftspeed-siberian'); ?>
                        </button>
                        
                        <button type="button" id="swsib-delete-all-logs" class="button button-warning swsib-delete-all-logs">
                            <span class="dashicons dashicons-trash"></span> <?php _e('Delete All Logs', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                </div>
                
                <div id="swsib-log-content-wrapper" class="swsib-log-content-wrapper" style="display: none;">
                    <pre id="swsib-log-content" class="swsib-log-content"></pre>
                </div>
                
                <div id="swsib-log-placeholder" class="swsib-log-placeholder">
                    <p><?php _e('Select a log file from the dropdown to view its contents.', 'swiftspeed-siberian'); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="swsib-actions" id="logging-save-button-container">
                <input type="submit" name="submit" id="logging-save-button" class="button button-primary" value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>">
            </div>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Refresh log content when log is selected
            $('#swsib-log-selector').on('change', function() {
                var loggerId = $(this).val();
                if (loggerId) {
                    $('#swsib-log-placeholder').hide();
                    $('#swsib-log-content-wrapper').show();
                    loadLogContent(loggerId);
                    $('#swsib-refresh-log, #swsib-copy-log, #swsib-clear-log').prop('disabled', false);
                } else {
                    $('#swsib-log-content').html('');
                    $('#swsib-log-content-wrapper').hide();
                    $('#swsib-log-placeholder').show();
                    $('#swsib-refresh-log, #swsib-copy-log, #swsib-clear-log').prop('disabled', true);
                }
            });
            
            // Refresh button
            $('#swsib-refresh-log').on('click', function() {
                var loggerId = $('#swsib-log-selector').val();
                if (loggerId) {
                    loadLogContent(loggerId);
                }
            });
            
            // Copy button
            $('#swsib-copy-log').on('click', function() {
                var logContent = $('#swsib-log-content').text();
                
                // Create temporary textarea to copy from
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(logContent).select();
                document.execCommand('copy');
                $temp.remove();
                
                // Show copied message
                var $button = $(this);
                var originalText = $button.html();
                $button.html('<span class="dashicons dashicons-yes"></span> <?php _e('Copied!', 'swiftspeed-siberian'); ?>');
                
                setTimeout(function() {
                    $button.html(originalText);
                }, 2000);
            });
            
            // Clear button
            $('#swsib-clear-log').on('click', function() {
                var loggerId = $('#swsib-log-selector').val();
                
                if (loggerId && confirm('<?php _e('Are you sure you want to clear this log file? This action cannot be undone.', 'swiftspeed-siberian'); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'swsib_clear_log',
                            logger_id: loggerId,
                            nonce: '<?php echo wp_create_nonce('swsib-logging-nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#swsib-log-content').html('');
                                loadLogContent(loggerId);
                                
                                // Update the size in the dropdown
                                var $option = $('#swsib-log-selector option:selected');
                                var logName = $option.text().split('(')[0];
                                $option.text(logName + ' (0 B)');
                                
                                // Show success message
                                var $notice = $('<div class="swsib-notice success"><?php _e('Log cleared successfully.', 'swiftspeed-siberian'); ?></div>');
                                $('.swsib-header').after($notice);
                                
                                setTimeout(function() {
                                    $notice.fadeOut(function() {
                                        $notice.remove();
                                    });
                                }, 3000);
                            } else {
                                // Show error message
                                var $notice = $('<div class="swsib-notice error"><?php _e('Error clearing log.', 'swiftspeed-siberian'); ?></div>');
                                $('.swsib-header').after($notice);
                                
                                setTimeout(function() {
                                    $notice.fadeOut(function() {
                                        $notice.remove();
                                    });
                                }, 3000);
                            }
                        }
                    });
                }
            });
            
            // Delete All Logs button
            $('#swsib-delete-all-logs').on('click', function() {
                if (confirm('<?php _e('Are you sure you want to delete ALL log files? This action cannot be undone.', 'swiftspeed-siberian'); ?>')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'swsib_delete_all_logs',
                            nonce: '<?php echo wp_create_nonce('swsib-logging-nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Clear log content
                                $('#swsib-log-content').html('');
                                $('#swsib-log-content-wrapper').hide();
                                $('#swsib-log-placeholder').show();
                                
                                // Disable buttons
                                $('#swsib-refresh-log, #swsib-copy-log, #swsib-clear-log').prop('disabled', true);
                                
                                // Reset log selector
                                $('#swsib-log-selector').html('<option value=""><?php _e('Select a log to view', 'swiftspeed-siberian'); ?></option>');
                                
                                // Hide delete all logs button if no logs remain
                                $('#swsib-delete-all-logs').hide();
                                
                                // Show success message
                                var $notice = $('<div class="swsib-notice success"><?php _e('All log files have been deleted successfully.', 'swiftspeed-siberian'); ?></div>');
                                $('.swsib-header').after($notice);
                                
                                setTimeout(function() {
                                    $notice.fadeOut(function() {
                                        $notice.remove();
                                    });
                                }, 3000);
                                
                                // Reload the page after a short delay to refresh the UI
                                setTimeout(function() {
                                    window.location.reload();
                                }, 3000);
                            } else {
                                // Show error message
                                var $notice = $('<div class="swsib-notice error"><?php _e('Error deleting log files.', 'swiftspeed-siberian'); ?></div>');
                                $('.swsib-header').after($notice);
                                
                                setTimeout(function() {
                                    $notice.fadeOut(function() {
                                        $notice.remove();
                                    });
                                }, 3000);
                            }
                        }
                    });
                }
            });
            
            // Function to load log content
            function loadLogContent(loggerId) {
                $('#swsib-log-content').html('<?php _e('Loading...', 'swiftspeed-siberian'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'swsib_get_log_content',
                        logger_id: loggerId,
                        nonce: '<?php echo wp_create_nonce('swsib-logging-nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            if (response.data.content) {
                                $('#swsib-log-content').html(response.data.content);
                            } else {
                                $('#swsib-log-content').html('<?php _e('Log file is empty.', 'swiftspeed-siberian'); ?>');
                            }
                        } else {
                            $('#swsib-log-content').html('<?php _e('Error loading log.', 'swiftspeed-siberian'); ?>');
                        }
                    },
                    error: function() {
                        $('#swsib-log-content').html('<?php _e('Error loading log.', 'swiftspeed-siberian'); ?>');
                    }
                });
            }
        });
        </script>
        
        <style>
        .swsib-loggers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .swsib-logger-group {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 15px;
        }
        
        .swsib-logger-group h4 {
            margin-top: 0;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .swsib-logger-item {
            margin-bottom: 8px;
        }
        
        .swsib-field.compact {
            margin-bottom: 5px;
        }
        
        .swsib-field.compact label {
            font-weight: normal;
        }
        
        .swsib-log-size {
            font-size: 0.85em;
            color: #666;
            margin-left: 5px;
        }
        
        .swsib-log-viewer {
            margin-top: 30px;
        }
        
        .swsib-log-controls {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .swsib-log-selector {
            min-width: 250px;
        }
        
        .swsib-log-buttons {
            display: flex;
            gap: 10px;
        }
        
        .swsib-log-content-wrapper {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 400px;
            overflow: auto;
        }
        
        .swsib-log-content {
            padding: 15px;
            margin: 0;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.4;
            word-break: break-all; /* Force break long words */
            overflow-wrap: break-word;
            max-width: 100%;
        }
        
        .swsib-log-placeholder {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
        }
        
        .switch.small {
            width: 36px;
            height: 20px;
        }
        
        .switch.small .slider:before {
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
        }
        
        .switch.small input:checked + .slider:before {
            transform: translateX(16px);
        }
        
        .button-warning {
            background-color: #d63638 !important;
            color: white !important;
            border-color: #d63638 !important;
        }
        
        .button-warning:hover {
            background-color: #b32d2e !important;
            border-color: #b32d2e !important;
        }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for getting log content
     */
    public function ajax_get_log_content() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-logging-nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get logger ID
        $logger_id = isset($_POST['logger_id']) ? sanitize_text_field($_POST['logger_id']) : '';
        
        if (empty($logger_id)) {
            wp_send_json_error('Invalid logger ID');
        }
        
        // Get log parts
        list($logger_group, $logger_key) = explode('_', $logger_id, 2);
        $log_file = $this->get_log_filename($logger_group, $logger_key);
        
        if (!file_exists($log_file)) {
            wp_send_json_error('Log file does not exist');
        }
        
        // Read log file content
        $content = file_get_contents($log_file);
        
        // Escape HTML in content
        $content = esc_html($content);
        
        wp_send_json_success(array(
            'content' => $content
        ));
    }
    
    /**
     * AJAX handler for clearing log
     */
    public function ajax_clear_log() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-logging-nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get logger ID
        $logger_id = isset($_POST['logger_id']) ? sanitize_text_field($_POST['logger_id']) : '';
        
        if (empty($logger_id)) {
            wp_send_json_error('Invalid logger ID');
        }
        
        // Get log parts
        list($logger_group, $logger_key) = explode('_', $logger_id, 2);
        $log_file = $this->get_log_filename($logger_group, $logger_key);
        
        if (!file_exists($log_file)) {
            wp_send_json_error('Log file does not exist');
        }
        
        // Clear log file
        file_put_contents($log_file, '');
        
        // Write initialization message
        $this->write_log_message($logger_group, $logger_key, 'Log cleared on ' . date('Y-m-d H:i:s'));
        
        wp_send_json_success();
    }
    
    /**
     * AJAX handler for deleting all logs
     */
    public function ajax_delete_all_logs() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-logging-nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Delete all logs
        $result = $this->delete_all_logs();
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to delete some log files');
        }
    }
    
    /**
     * Delete all log files
     */
    public function delete_all_logs() {
        // Check if log directory exists
        if (!file_exists($this->log_dir)) {
            return true; // No logs to delete
        }
        
        // Get all files in log directory
        $files = glob($this->log_dir . '*.log');
        $success = true;
        
        // Delete each log file
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Get log filename for a specific logger
     */
    public function get_log_filename($module, $type) {
        return $this->log_dir . "{$module}_{$type}.log";
    }
    
    /**
     * Write directly to a log file (for internal use)
     */
    private function write_log_message($module, $type, $message) {
        // Ensure log directory exists
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }
        
        // Get log file path
        $log_file = $this->get_log_filename($module, $type);
        
        // Format message with timestamp
        $formatted_message = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        
        // Append to log file
        file_put_contents($log_file, $formatted_message, FILE_APPEND);
    }
    
    /**
     * Write to log - public method for other classes to use
     */
    public function write_to_log($module, $type, $message) {
        // Check if this specific logger is enabled
        $logger_id = $module . '_' . $type;
        $enabled_loggers = isset($this->options['logging']['loggers']) ? $this->options['logging']['loggers'] : array();
        
        if (!isset($enabled_loggers[$logger_id]) || !$enabled_loggers[$logger_id]) {
            return;
        }
        
        // Write the message to the log
        $this->write_log_message($module, $type, $message);
    }
    
    /**
     * Log a message (static method for easy access)
     */
    public static function log($module, $type, $message) {
        $instance = swsib()->logging;
        
        if ($instance) {
            $instance->write_to_log($module, $type, $message);
        }
    }
}