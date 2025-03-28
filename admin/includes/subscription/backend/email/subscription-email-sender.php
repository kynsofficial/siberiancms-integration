<?php
/**
 * Subscription Email Sender
 *
 * Handles sending emails when subscription status changes.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle subscription email notifications.
 */
class SwiftSpeed_Siberian_Email_Sender {

    /**
     * Initialize the class.
     */
    public static function init() {
        // Hook into subscription status changes
        add_action('swsib_subscription_status_changed', array(__CLASS__, 'send_status_notification'), 10, 3);
        
        // Add action handler for SMTP test
        add_action('wp_ajax_swsib_test_smtp_connection', array(__CLASS__, 'ajax_test_smtp_connection'));
    }

   /**
 * Send notification when subscription status changes.
 *
 * @param string $subscription_id The subscription ID.
 * @param string $old_status The old subscription status.
 * @param string $new_status The new subscription status.
 */
public static function send_status_notification($subscription_id, $old_status, $new_status) {
    // Get options
    $options = get_option('swsib_options', array());
    $email_options = isset($options['subscription']['email']) ? $options['subscription']['email'] : array();
    
    // Check if email notifications are enabled
    if (!isset($email_options['enabled']) || !$email_options['enabled']) {
        self::log_message("Email notifications are disabled. Skipping email for status change to {$new_status}");
        return;
    }
    
    // Check if notification for this status is enabled
    if (!isset($email_options['status_notifications'][$new_status]['enabled']) || 
        !$email_options['status_notifications'][$new_status]['enabled']) {
        self::log_message("Email notifications for status '{$new_status}' are disabled");
        return;
    }
    
    // Get subscription data
    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
    $db = new SwiftSpeed_Siberian_Subscriptions_DB();
    $subscription = $db->get_subscription($subscription_id);
    
    if (!$subscription) {
        self::log_message("Cannot send notification - subscription not found: $subscription_id");
        return;
    }
    
    // Get user data
    $user = get_userdata($subscription['user_id']);
    if (!$user) {
        self::log_message("Cannot send notification - user not found: {$subscription['user_id']}");
        return;
    }
    
    // Get plan data
    $plan_data = self::get_plan_data($subscription['plan_id']);
    
    // Get billing frequency display text
    $billing_frequency_display = ucfirst($subscription['billing_frequency']);
    switch($subscription['billing_frequency']) {
        case 'quarterly':
            $billing_frequency_display = __('Quarterly (3 months)', 'swiftspeed-siberian');
            break;
        case 'biannually':
            $billing_frequency_display = __('Bi-annually (6 months)', 'swiftspeed-siberian');
            break;
        case 'annually':
            $billing_frequency_display = __('Annually (1 year)', 'swiftspeed-siberian');
            break;
    }
    
    // Calculate next billing date if available
    $next_billing_date = '';
    if (isset($subscription['end_date']) && !empty($subscription['end_date'])) {
        $next_billing_date = date('Y-m-d', strtotime($subscription['end_date']));
    }
    
    // Get app details from SiberianCMS
    $app_details = array('app_name' => '', 'app_quantity' => 1);
    if (isset($subscription['application_id']) && isset($subscription['siberian_plan_id'])) {
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/siberiansub-db.php';
        $siber_db = new SwiftSpeed_Siberian_SiberianSub_DB();
        $app_details = $siber_db->get_app_details_for_subscription(
            $subscription['application_id'], 
            $subscription['siberian_plan_id']
        );
    }
    
    // Prepare email data
    $to = $user->user_email;
    $subject = $email_options['status_notifications'][$new_status]['subject'];
    $message = $email_options['status_notifications'][$new_status]['content'];
    
    // Replace placeholders
    $placeholders = array(
        '{customer_name}' => $user->display_name,
        '{customer_email}' => $user->user_email,
        '{plan_name}' => isset($plan_data['name']) ? $plan_data['name'] : $subscription['plan_id'],
        '{amount}' => isset($subscription['amount']) ? $subscription['amount'] : '0.00',
        '{currency}' => isset($subscription['currency']) ? $subscription['currency'] : 'USD',
        '{billing_frequency}' => $billing_frequency_display,
        '{start_date}' => isset($subscription['start_date']) ? date('Y-m-d', strtotime($subscription['start_date'])) : '',
        '{end_date}' => isset($subscription['end_date']) ? date('Y-m-d', strtotime($subscription['end_date'])) : '',
        '{next_billing_date}' => $next_billing_date,
        '{site_name}' => get_bloginfo('name'),
        '{site_url}' => get_bloginfo('url'),
        '{subscription_id}' => $subscription_id,
        '{application_id}' => isset($subscription['application_id']) ? $subscription['application_id'] : '',
        '{app_name}' => $app_details['app_name'],
        '{app_quantity}' => $app_details['app_quantity'],
    );
    
    foreach ($placeholders as $placeholder => $value) {
        $subject = str_replace($placeholder, $value, $subject);
        $message = str_replace($placeholder, $value, $message);
    }
    
    self::log_message("Sending {$new_status} notification email to {$to}");
    
    // Send email
    $sent = self::send_email($to, $subject, $message);
    
    if ($sent) {
        self::log_message("Successfully sent {$new_status} notification email to {$to}");
    } else {
        self::log_message("Failed to send {$new_status} notification email to {$to}");
    }
}
    
    /**
     * Send subscription email.
     *
     * @param string $to Recipient email.
     * @param string $subject Email subject.
     * @param string $message Email message.
     * @return bool Whether the email was sent successfully.
     */
    public static function send_email($to, $subject, $message) {
        // Get options
        $options = get_option('swsib_options', array());
        $email_options = isset($options['subscription']['email']) ? $options['subscription']['email'] : array();
        
        // Get sender info
        $sender_name = isset($email_options['sender_name']) ? $email_options['sender_name'] : get_bloginfo('name');
        $sender_email = isset($email_options['sender_email']) ? $email_options['sender_email'] : get_bloginfo('admin_email');
        $use_smtp = isset($email_options['use_smtp']) ? filter_var($email_options['use_smtp'], FILTER_VALIDATE_BOOLEAN) : false;
        
        // Set up headers
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: $sender_name <$sender_email>"
        );
        
        // Build HTML email
        $logo_url = isset($email_options['logo_url']) ? $email_options['logo_url'] : '';
        $footer_text = isset($email_options['footer_text']) ? $email_options['footer_text'] : '';
        
        $html_message = self::build_html_email($message, $logo_url, $footer_text);
        
        // If using SMTP, send via SMTP
        if ($use_smtp) {
            return self::send_via_smtp($to, $subject, $html_message, $headers, $sender_name, $sender_email);
        }
        
        // Otherwise, use WordPress mail
        return wp_mail($to, $subject, $html_message, $headers);
    }
    
    /**
     * Build HTML email.
     *
     * @param string $message Email message.
     * @param string $logo_url Logo URL.
     * @param string $footer_text Footer text.
     * @return string HTML email.
     */
    private static function build_html_email($message, $logo_url, $footer_text) {
        $logo_html = '';
        if (!empty($logo_url)) {
            $logo_html = '<div style="text-align: center; margin-bottom: 20px;">
                <img src="' . esc_url($logo_url) . '" alt="Logo" style="max-width: 200px; max-height: 60px;">
            </div>';
        }
        
        $content = nl2br($message);
        
        return '<!DOCTYPE html>
        <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
            <title>Subscription Notification</title>
        </head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif, \'Apple Color Emoji\', \'Segoe UI Emoji\', \'Segoe UI Symbol\'; margin: 0; padding: 0; width: 100%; background-color: #f5f5f5;">
            <table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
                <tr>
                    <td style="padding: 20px 0;">
                        <table align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <tr>
                                <td style="padding: 30px;">
                                    ' . $logo_html . '
                                    <div style="color: #333333; font-size: 16px; line-height: 1.5;">
                                        ' . $content . '
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 20px; background-color: #f8f8f8; border-top: 1px solid #eeeeee; text-align: center; color: #666666; font-size: 12px;">
                                    ' . $footer_text . '
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
    }
    
    /**
     * Send email via SMTP.
     *
     * @param string $to Recipient email.
     * @param string $subject Email subject.
     * @param string $message Email message.
     * @param array $headers Email headers.
     * @param string $sender_name Sender name.
     * @param string $sender_email Sender email.
     * @return bool Whether the email was sent successfully.
     */
    private static function send_via_smtp($to, $subject, $message, $headers, $sender_name, $sender_email) {
        // Get options
        $options = get_option('swsib_options', array());
        $email_options = isset($options['subscription']['email']) ? $options['subscription']['email'] : array();
        
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        // Create a new PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Set mailer to use SMTP
            $mail->isSMTP();
            
            // SMTP settings
            $mail->Host = $email_options['smtp_host'];
            $mail->Port = $email_options['smtp_port'];
            
            // Set encryption type
            if ($email_options['smtp_encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($email_options['smtp_encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            
            // Set authentication
            if (isset($email_options['smtp_auth']) && $email_options['smtp_auth']) {
                $mail->SMTPAuth = true;
                $mail->Username = $email_options['smtp_username'];
                $mail->Password = $email_options['smtp_password'];
            } else {
                $mail->SMTPAuth = false;
            }
            
            // Set sender
            $mail->setFrom($sender_email, $sender_name);
            
            // Set recipient
            $mail->addAddress($to);
            
            // Set email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = wp_strip_all_tags($message);
            
            // Send email
            return $mail->send();
        } catch (Exception $e) {
            self::log_message('Error sending email via SMTP: ' . $mail->ErrorInfo);
            return false;
        }
    }
    
    /**
     * Get plan data by ID.
     *
     * @param string $plan_id Plan ID.
     * @return array|null Plan data or null if not found.
     */
    private static function get_plan_data($plan_id) {
        $options = get_option('swsib_options', array());
        $plans = isset($options['subscription']['plans']) ? $options['subscription']['plans'] : array();
        
        foreach ($plans as $plan) {
            if ($plan['id'] === $plan_id) {
                return $plan;
            }
        }
        
        return null;
    }
    
    /**
     * Test SMTP connection via AJAX.
     */
    public static function ajax_test_smtp_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_emails_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get SMTP settings
        $host = isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '';
        $port = isset($_POST['port']) ? intval($_POST['port']) : 587;
        $encryption = isset($_POST['encryption']) ? sanitize_text_field($_POST['encryption']) : 'tls';
        $auth = isset($_POST['auth']) ? filter_var($_POST['auth'], FILTER_VALIDATE_BOOLEAN) : true;
        $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
        $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
        $from_name = isset($_POST['from_name']) ? sanitize_text_field($_POST['from_name']) : get_bloginfo('name');
        $from_email = isset($_POST['from_email']) ? sanitize_email($_POST['from_email']) : get_bloginfo('admin_email');
        
        // Check if PHPMailer is available
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
        }
        
        // Create a new PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Enable debug mode
            $mail->SMTPDebug = 3;
            $mail->Debugoutput = function($str, $level) { 
                // Store debug info in transient
                $debug_output = get_transient('swsib_smtp_debug');
                if ($debug_output === false) {
                    $debug_output = '';
                }
                $debug_output .= $str . "\n";
                set_transient('swsib_smtp_debug', $debug_output, 600); // Store for 10 minutes
            };
            
            // Set mailer to use SMTP
            $mail->isSMTP();
            
            // SMTP settings
            $mail->Host = $host;
            $mail->Port = $port;
            
            // Set encryption type
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            
            // Set authentication
            if ($auth) {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $password;
            } else {
                $mail->SMTPAuth = false;
            }
            
            // Set sender
            $mail->setFrom($from_email, $from_name);
            
            // Set recipient (send to self for testing)
            $mail->addAddress($from_email);
            
            // Set email content
            $mail->isHTML(true);
            $mail->Subject = 'SMTP Test Email';
            $mail->Body = 'This is a test email to verify SMTP settings.';
            $mail->AltBody = 'This is a test email to verify SMTP settings.';
            
            // Connect only - don't send actual email
            $mail->SMTPConnect();
            $mail->smtpClose();
            
            wp_send_json_success(array('message' => __('SMTP connection successful', 'swiftspeed-siberian')));
        } catch (Exception $e) {
            $debug_output = get_transient('swsib_smtp_debug');
            delete_transient('swsib_smtp_debug');
            
            wp_send_json_error(array(
                'message' => $mail->ErrorInfo,
                'debug' => $debug_output
            ));
        }
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
SwiftSpeed_Siberian_Email_Sender::init();