<?php
/**
 * Subscription Email Handler
 *
 * Handles email settings form submissions.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle subscription email settings.
 */
class SwiftSpeed_Siberian_Email_Handler {

    /**
     * Initialize the class.
     */
    public static function init() {
        // Add action handler for saving email settings
        add_action('admin_post_swsib_save_subscription_emails', array(__CLASS__, 'process_email_settings_submission'));
    }

    /**
     * Process email settings form submission.
     */
    public static function process_email_settings_submission() {
        if (
            !isset($_POST['_wpnonce_swsib_subscription_emails']) || 
            !wp_verify_nonce($_POST['_wpnonce_swsib_subscription_emails'], 'swsib_subscription_emails_nonce')
        ) {
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=emails&error=nonce_failed'));
            exit;
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }

        // Initialize email section if not exists
        if (!isset($options['subscription']['email'])) {
            $options['subscription']['email'] = array();
        }

        // Process email enabled status
        $options['subscription']['email']['enabled'] = isset($_POST['swsib_options']['subscription']['email']['enabled']);

        // Process sender details
        if (isset($_POST['swsib_options']['subscription']['email']['sender_name'])) {
            $options['subscription']['email']['sender_name'] = sanitize_text_field($_POST['swsib_options']['subscription']['email']['sender_name']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['sender_email'])) {
            $options['subscription']['email']['sender_email'] = sanitize_email($_POST['swsib_options']['subscription']['email']['sender_email']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['logo_url'])) {
            $options['subscription']['email']['logo_url'] = esc_url_raw($_POST['swsib_options']['subscription']['email']['logo_url']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['footer_text'])) {
            $options['subscription']['email']['footer_text'] = sanitize_textarea_field($_POST['swsib_options']['subscription']['email']['footer_text']);
        }

        // Process SMTP settings
        $options['subscription']['email']['use_smtp'] = isset($_POST['swsib_options']['subscription']['email']['use_smtp']);
        
        if (isset($_POST['swsib_options']['subscription']['email']['smtp_host'])) {
            $options['subscription']['email']['smtp_host'] = sanitize_text_field($_POST['swsib_options']['subscription']['email']['smtp_host']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['smtp_port'])) {
            $options['subscription']['email']['smtp_port'] = intval($_POST['swsib_options']['subscription']['email']['smtp_port']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['smtp_encryption'])) {
            $options['subscription']['email']['smtp_encryption'] = sanitize_text_field($_POST['swsib_options']['subscription']['email']['smtp_encryption']);
        }
        
        $options['subscription']['email']['smtp_auth'] = isset($_POST['swsib_options']['subscription']['email']['smtp_auth']);
        
        if (isset($_POST['swsib_options']['subscription']['email']['smtp_username'])) {
            $options['subscription']['email']['smtp_username'] = sanitize_text_field($_POST['swsib_options']['subscription']['email']['smtp_username']);
        }
        
        if (isset($_POST['swsib_options']['subscription']['email']['smtp_password'])) {
            $options['subscription']['email']['smtp_password'] = sanitize_text_field($_POST['swsib_options']['subscription']['email']['smtp_password']);
        }

        // Process status notification templates
        if (isset($_POST['swsib_options']['subscription']['email']['status_notifications'])) {
            // Initialize status notifications array if not exists
            if (!isset($options['subscription']['email']['status_notifications'])) {
                $options['subscription']['email']['status_notifications'] = array();
            }
            
            $status_notifications = $_POST['swsib_options']['subscription']['email']['status_notifications'];
            
            foreach ($status_notifications as $status => $settings) {
                // Initialize status array if not exists
                if (!isset($options['subscription']['email']['status_notifications'][$status])) {
                    $options['subscription']['email']['status_notifications'][$status] = array();
                }
                
                // Save enabled status
                $options['subscription']['email']['status_notifications'][$status]['enabled'] = 
                    isset($settings['enabled']);
                
                // Save subject and content
                if (isset($settings['subject'])) {
                    $options['subscription']['email']['status_notifications'][$status]['subject'] = 
                        sanitize_text_field($settings['subject']);
                }
                
                if (isset($settings['content'])) {
                    $options['subscription']['email']['status_notifications'][$status]['content'] = 
                        sanitize_textarea_field($settings['content']);
                }
            }
        }

        // Save the options
        update_option('swsib_options', $options);
        
        // Redirect back to the email settings tab with success message
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=emails&updated=true'));
        exit;
    }

    /**
     * Central logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'email', $message);
        }
    }
}

// Initialize the class
SwiftSpeed_Siberian_Email_Handler::init();