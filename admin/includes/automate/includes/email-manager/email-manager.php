<?php
/**
 * Email Manager for Automation Tasks - Simplified Version
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Email_Manager {
    
    /**
     * Email settings
     */
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = $this->get_email_settings();
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
     * Get email settings
     */
    private function get_email_settings() {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate']) || !isset($options['automate']['smtp'])) {
            return array(
                'from_name' => get_bloginfo('name'),
                'from_email' => get_bloginfo('admin_email'),
                'use_smtp' => false,
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_auth' => true,
                'smtp_username' => '',
                'smtp_password' => ''
            );
        }
        
        return $options['automate']['smtp'];
    }
    
    /**
     * Send email to user
     */
    public function send_email($to, $subject, $message, $placeholders = array()) {
        // Replace placeholders in subject and message
        $subject = $this->replace_placeholders($subject, $placeholders);
        $message = $this->replace_placeholders($message, $placeholders);
        
        $this->log_message("Sending email to: $to");
        
        // Set up email headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->settings['from_name'] . ' <' . $this->settings['from_email'] . '>'
        );
        
        // Convert message to HTML if needed
        if (strpos($message, '<html') === false) {
            $message = nl2br($message);
            $message = '<html><body>' . $message . '</body></html>';
        }
        
        // Check if SMTP is enabled
        if (!empty($this->settings['use_smtp'])) {
            return $this->send_smtp_email($to, $subject, $message, $headers);
        } else {
            return wp_mail($to, $subject, $message, $headers);
        }
    }
    
    /**
     * Send email using SMTP
     */
    private function send_smtp_email($to, $subject, $message, $headers) {
        // Add WordPress SMTP plugin filter if available
        if (function_exists('phpmailer_init_smtp')) {
            // Most SMTP plugins hook into phpmailer_init, so we can use that
            add_action('phpmailer_init', array($this, 'configure_smtp'));
            
            $result = wp_mail($to, $subject, $message, $headers);
            
            remove_action('phpmailer_init', array($this, 'configure_smtp'));
            
            return $result;
        } else {
            // Fallback to using built-in wp_mail which may use default SMTP settings
            $this->log_message("No SMTP plugin detected, using default wp_mail");
            return wp_mail($to, $subject, $message, $headers);
        }
    }
    
    /**
     * Configure SMTP for PHPMailer
     */
    public function configure_smtp($phpmailer) {
        $phpmailer->isSMTP();
        $phpmailer->Host = $this->settings['smtp_host'];
        $phpmailer->Port = $this->settings['smtp_port'];
        
        if (!empty($this->settings['smtp_encryption'])) {
            $phpmailer->SMTPSecure = $this->settings['smtp_encryption'];
        }
        
        if (!empty($this->settings['smtp_auth'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $this->settings['smtp_username'];
            $phpmailer->Password = $this->settings['smtp_password'];
        } else {
            $phpmailer->SMTPAuth = false;
        }
    }
    
    /**
     * Replace placeholders in template
     */
    private function replace_placeholders($content, $placeholders) {
        foreach ($placeholders as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }
        
        // Standard placeholders
        $site_name = get_bloginfo('name');
        $login_url = wp_login_url();
        
        $content = str_replace('{site_name}', $site_name, $content);
        $content = str_replace('{login_url}', $login_url, $content);
        
        return $content;
    }
    
    /**
     * Save email settings - Simplified to only handle SMTP
     */
    public function save_smtp_settings($settings) {
        $options = get_option('swsib_options', array());
        
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        
        // Only save SMTP settings now
        $options['automate']['smtp'] = $settings;
        
        update_option('swsib_options', $options);
        
        return true;
    }
    
    /**
     * AJAX handler for saving SMTP settings
     */
    public function ajax_save_smtp_settings() {
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
        parse_str($_POST['settings'], $form_data);
        
        $smtp_settings = array(
            'from_name' => sanitize_text_field($form_data['smtp_from_name']),
            'from_email' => sanitize_email($form_data['smtp_from_email']),
            'use_smtp' => !empty($form_data['use_smtp']),
            'smtp_host' => sanitize_text_field($form_data['smtp_host']),
            'smtp_port' => intval($form_data['smtp_port']),
            'smtp_encryption' => sanitize_text_field($form_data['smtp_encryption']),
            'smtp_auth' => !empty($form_data['smtp_auth']),
            'smtp_username' => sanitize_text_field($form_data['smtp_username']),
            'smtp_password' => $form_data['smtp_password'] // Don't sanitize password to preserve special chars
        );
        
        $saved = $this->save_smtp_settings($smtp_settings);
        
        if ($saved) {
            wp_send_json_success(array('message' => 'SMTP settings saved successfully.'));
        } else {
            wp_send_json_error(array('message' => 'Failed to save SMTP settings.'));
        }
    }
    
    /**
     * AJAX handler for testing SMTP settings
     */
    public function ajax_test_smtp_settings() {
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
        
        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        
        if (empty($test_email)) {
            $test_email = get_option('admin_email');
        }
        
        // Send a test email
        $subject = __('SMTP Test Email', 'swiftspeed-siberian');
        $message = __('This is a test email sent from your WordPress site using the SMTP settings configured in SwiftSpeed Siberian plugin. If you received this email, your SMTP settings are working correctly.', 'swiftspeed-siberian');
        
        $result = $this->send_email($test_email, $subject, $message);
        
        if ($result) {
            wp_send_json_success(array('message' => sprintf(__('Test email sent successfully to %s.', 'swiftspeed-siberian'), $test_email)));
        } else {
            wp_send_json_error(array('message' => __('Failed to send test email. Please check your SMTP settings.', 'swiftspeed-siberian')));
        }
    }
    
    /**
     * Display SMTP settings
     */
    public function display_smtp_settings() {
        $settings = $this->settings;
        ?>
        <div class="task-section" id="smtp-settings-section">
            <h3><?php _e('Email Delivery Settings (SMTP)', 'swiftspeed-siberian'); ?></h3>
            
            <div class="swsib-notice info">
                <p><?php _e('If WordPress is unable to send emails reliably, configure SMTP settings below to ensure all notification emails are delivered successfully.', 'swiftspeed-siberian'); ?></p>
                <p><?php _e('These settings affect all automated emails sent by the plugin, including user and application management notifications.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <form id="smtp-settings-form">
                <div class="email-sender-settings">
                    <h4><?php _e('Email Sender Settings', 'swiftspeed-siberian'); ?></h4>
                    
                    <div class="task-settings-field">
                        <label for="smtp_from_name"><?php _e('From Name', 'swiftspeed-siberian'); ?></label>
                        <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?php echo esc_attr($settings['from_name']); ?>">
                        <p class="description"><?php _e('The name that will appear in the From field of emails.', 'swiftspeed-siberian'); ?></p>
                    </div>
                    
                    <div class="task-settings-field">
                        <label for="smtp_from_email"><?php _e('From Email', 'swiftspeed-siberian'); ?></label>
                        <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?php echo esc_attr($settings['from_email']); ?>">
                        <p class="description"><?php _e('The email address that will be used as the sender.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div class="smtp-config">
                    <div class="task-settings-field">
                        <label>
                            <input type="checkbox" name="use_smtp" value="1" <?php checked(!empty($settings['use_smtp'])); ?>>
                            <?php _e('Use SMTP for sending emails', 'swiftspeed-siberian'); ?>
                        </label>
                        <p class="description"><?php _e('Enable this to use an external SMTP server for sending emails instead of the default WordPress mail function.', 'swiftspeed-siberian'); ?></p>
                    </div>
                    
                    <div id="smtp-settings-fields" style="<?php echo !empty($settings['use_smtp']) ? '' : 'display: none;'; ?>">
                        <div class="task-settings-field">
                            <label for="smtp_host"><?php _e('SMTP Host', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr($settings['smtp_host']); ?>">
                            <p class="description"><?php _e('The hostname of your SMTP server (e.g., smtp.gmail.com).', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="task-settings-field">
                            <label for="smtp_port"><?php _e('SMTP Port', 'swiftspeed-siberian'); ?></label>
                            <input type="number" id="smtp_port" name="smtp_port" value="<?php echo esc_attr($settings['smtp_port']); ?>">
                            <p class="description"><?php _e('The port of your SMTP server (common ports: 25, 465, 587).', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="task-settings-field">
                            <label for="smtp_encryption"><?php _e('Encryption', 'swiftspeed-siberian'); ?></label>
                            <select id="smtp_encryption" name="smtp_encryption">
                                <option value="" <?php selected($settings['smtp_encryption'], ''); ?>><?php _e('None', 'swiftspeed-siberian'); ?></option>
                                <option value="ssl" <?php selected($settings['smtp_encryption'], 'ssl'); ?>><?php _e('SSL', 'swiftspeed-siberian'); ?></option>
                                <option value="tls" <?php selected($settings['smtp_encryption'], 'tls'); ?>><?php _e('TLS', 'swiftspeed-siberian'); ?></option>
                            </select>
                            <p class="description"><?php _e('The encryption method used by your SMTP server.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="task-settings-field">
                            <label>
                                <input type="checkbox" name="smtp_auth" value="1" <?php checked(!empty($settings['smtp_auth'])); ?>>
                                <?php _e('Authentication Required', 'swiftspeed-siberian'); ?>
                            </label>
                            <p class="description"><?php _e('Enable this if your SMTP server requires authentication.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div id="smtp-auth-fields" style="<?php echo !empty($settings['smtp_auth']) ? '' : 'display: none;'; ?>">
                            <div class="task-settings-field">
                                <label for="smtp_username"><?php _e('SMTP Username', 'swiftspeed-siberian'); ?></label>
                                <input type="text" id="smtp_username" name="smtp_username" value="<?php echo esc_attr($settings['smtp_username']); ?>">
                                <p class="description"><?php _e('Your SMTP username (usually your email address).', 'swiftspeed-siberian'); ?></p>
                            </div>
                            
                            <div class="task-settings-field">
                                <label for="smtp_password"><?php _e('SMTP Password', 'swiftspeed-siberian'); ?></label>
                                <input type="password" id="smtp_password" name="smtp_password" value="<?php echo esc_attr($settings['smtp_password']); ?>">
                                <p class="description"><?php _e('Your SMTP password or app password for services with 2FA.', 'swiftspeed-siberian'); ?></p>
                            </div>
                        </div>
                        
                        <div class="task-settings-field smtp-test-container">
                            <label for="test_email"><?php _e('Test Email Address', 'swiftspeed-siberian'); ?></label>
                            <div class="smtp-test-row">
                                <input type="email" id="test_email" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                                <button type="button" class="button smtp-test-button"><?php _e('Send Test Email', 'swiftspeed-siberian'); ?></button>
                            </div>
                            <p class="description"><?php _e('Enter an email address to send a test message using the settings above.', 'swiftspeed-siberian'); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="task-settings-actions">
                    <button type="button" class="button button-primary save-smtp-settings"><?php _e('Save SMTP Settings', 'swiftspeed-siberian'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }
}