<?php
/**
 * PE Subscription - Email Notifications Tab Content
 *
 * Configure email notifications for subscription status changes.
 *
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Get options
$options = get_option('swsib_options', array());
$email_options = isset($options['subscription']['email']) ? $options['subscription']['email'] : array();

// Default status templates
$status_templates = array(
    'active' => array(
        'subject' => __('Your subscription is now active', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nYour subscription for {app_name} has been activated successfully.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\nBilling frequency: {billing_frequency}\nNext billing date: {next_billing_date}\n\nThank you for your business.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
    'pending-cancellation' => array(
        'subject' => __('Your subscription is pending cancellation', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nYour subscription for {app_name} has been set to pending cancellation. It will continue until the end of the current billing period.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\nEnd date: {end_date}\n\nIf you did not request this cancellation, please contact us immediately.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
    'cancelled' => array(
        'subject' => __('Your subscription has been cancelled', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nYour subscription for {app_name} has been cancelled.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\n\nWe're sorry to see you go. If you have any feedback on how we could improve our service, please let us know.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
    'expired' => array(
        'subject' => __('Your subscription has expired', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nYour subscription for {app_name} has expired due to a payment issue.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\n\nTo reactivate your subscription, please update your payment information or contact us for assistance.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
    'renewed' => array(
        'subject' => __('Your subscription has been renewed', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nYour subscription for {app_name} has been renewed successfully.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\nBilling frequency: {billing_frequency}\nNext billing date: {next_billing_date}\n\nThank you for your continued business.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
    'payment_failed' => array(
        'subject' => __('Subscription payment failed', 'swiftspeed-siberian'),
        'content' => __("Dear {customer_name},\n\nWe were unable to process the payment for your subscription to {app_name}.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nApps allowed: {app_quantity}\n\nPlease update your payment information to avoid service interruption.\n\nRegards,\n{site_name}", 'swiftspeed-siberian'),
    ),
);

// Get email settings with defaults
$email_enabled = isset($email_options['enabled']) ? filter_var($email_options['enabled'], FILTER_VALIDATE_BOOLEAN) : false;
$email_sender_name = isset($email_options['sender_name']) ? $email_options['sender_name'] : get_bloginfo('name');
$email_sender_email = isset($email_options['sender_email']) ? $email_options['sender_email'] : get_bloginfo('admin_email');
$email_logo_url = isset($email_options['logo_url']) ? $email_options['logo_url'] : '';
$email_footer_text = isset($email_options['footer_text']) ? $email_options['footer_text'] : __('© ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.', 'swiftspeed-siberian');
$email_use_smtp = isset($email_options['use_smtp']) ? filter_var($email_options['use_smtp'], FILTER_VALIDATE_BOOLEAN) : false;
$email_smtp_host = isset($email_options['smtp_host']) ? $email_options['smtp_host'] : '';
$email_smtp_port = isset($email_options['smtp_port']) ? $email_options['smtp_port'] : '587';
$email_smtp_encryption = isset($email_options['smtp_encryption']) ? $email_options['smtp_encryption'] : 'tls';
$email_smtp_auth = isset($email_options['smtp_auth']) ? filter_var($email_options['smtp_auth'], FILTER_VALIDATE_BOOLEAN) : true;
$email_smtp_username = isset($email_options['smtp_username']) ? $email_options['smtp_username'] : '';
$email_smtp_password = isset($email_options['smtp_password']) ? $email_options['smtp_password'] : '';

// Get status notification settings
$status_notifications = isset($email_options['status_notifications']) ? $email_options['status_notifications'] : array();

// Merge with defaults
foreach ($status_templates as $status => $template) {
    if (!isset($status_notifications[$status])) {
        $status_notifications[$status] = array(
            'enabled' => ($status === 'active' || $status === 'cancelled'),
            'subject' => $template['subject'],
            'content' => $template['content']
        );
    }
}

// Get available placeholders
$available_placeholders = array(
    '{customer_name}' => __('Customer\'s name', 'swiftspeed-siberian'),
    '{customer_email}' => __('Customer\'s email', 'swiftspeed-siberian'),
    '{plan_name}' => __('Subscription plan name', 'swiftspeed-siberian'),
    '{amount}' => __('Subscription amount', 'swiftspeed-siberian'),
    '{currency}' => __('Subscription currency', 'swiftspeed-siberian'),
    '{billing_frequency}' => __('Billing frequency', 'swiftspeed-siberian'),
    '{start_date}' => __('Subscription start date', 'swiftspeed-siberian'),
    '{end_date}' => __('Subscription end date', 'swiftspeed-siberian'),
    '{next_billing_date}' => __('Next billing date', 'swiftspeed-siberian'),
    '{site_name}' => __('Website name', 'swiftspeed-siberian'),
    '{site_url}' => __('Website URL', 'swiftspeed-siberian'),
    '{subscription_id}' => __('Subscription ID', 'swiftspeed-siberian'),
    '{application_id}' => __('Application ID', 'swiftspeed-siberian'),
    '{app_name}' => __('Application name', 'swiftspeed-siberian'),
    '{app_quantity}' => __('Number of apps allowed by plan', 'swiftspeed-siberian'),
);
?>

<div class="swsib-notice info">
    <p>
        <strong><?php _e('Email Notifications:', 'swiftspeed-siberian'); ?></strong>
        <?php _e('Configure email notifications for subscription status changes. You can customize email templates for different subscription statuses and configure SMTP settings for better email deliverability.', 'swiftspeed-siberian'); ?>
    </p>
</div>

<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="subscription_email_form">
    <input type="hidden" name="action" value="swsib_save_subscription_emails">
    <?php wp_nonce_field('swsib_subscription_emails_nonce', '_wpnonce_swsib_subscription_emails'); ?>

    <div class="swsib-section">
        <h3><?php _e('Email Settings', 'swiftspeed-siberian'); ?></h3>
        
        <div class="swsib-field">
            <label class="swsib-toggle-label">
                <input type="checkbox" 
                       name="swsib_options[subscription][email][enabled]" 
                       id="email_enabled" 
                       <?php checked($email_enabled); ?>>
                <span class="swsib-toggle-switch"></span>
                <span class="swsib-toggle-text">
                    <?php echo $email_enabled ? 
                        __('Email Notifications Enabled', 'swiftspeed-siberian') : 
                        __('Email Notifications Disabled', 'swiftspeed-siberian'); ?>
                </span>
            </label>
            <p class="swsib-field-note">
                <?php _e('Enable or disable all subscription email notifications.', 'swiftspeed-siberian'); ?>
            </p>
        </div>
        
        <div id="email_settings_container" <?php echo !$email_enabled ? 'style="display:none;"' : ''; ?>>
            <div class="swsib-field">
                <label for="email_sender_name"><?php _e('Sender Name', 'swiftspeed-siberian'); ?></label>
                <input type="text" name="swsib_options[subscription][email][sender_name]" id="email_sender_name" value="<?php echo esc_attr($email_sender_name); ?>" class="regular-text">
                <p class="swsib-field-note"><?php _e('Name that will appear as the email sender.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <div class="swsib-field">
                <label for="email_sender_email"><?php _e('Sender Email', 'swiftspeed-siberian'); ?></label>
                <input type="email" name="swsib_options[subscription][email][sender_email]" id="email_sender_email" value="<?php echo esc_attr($email_sender_email); ?>" class="regular-text">
                <p class="swsib-field-note"><?php _e('Email address that will be used to send notifications.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <div class="swsib-field">
                <label for="email_logo_url"><?php _e('Email Logo URL', 'swiftspeed-siberian'); ?></label>
                <div class="swsib-media-field">
                    <input type="text" name="swsib_options[subscription][email][logo_url]" id="email_logo_url" value="<?php echo esc_attr($email_logo_url); ?>" class="regular-text">
                    <button type="button" class="button button-secondary" id="email_logo_upload_button"><?php _e('Select Image', 'swiftspeed-siberian'); ?></button>
                </div>
                <p class="swsib-field-note"><?php _e('Logo to display in the email header. Recommended size: 200x60px.', 'swiftspeed-siberian'); ?></p>
                <?php if (!empty($email_logo_url)): ?>
                <div class="swsib-logo-preview">
                    <img src="<?php echo esc_url($email_logo_url); ?>" alt="Logo Preview" style="max-height: 60px; max-width: 200px;">
                </div>
                <?php endif; ?>
            </div>
            
            <div class="swsib-field">
                <label for="email_footer_text"><?php _e('Footer Text', 'swiftspeed-siberian'); ?></label>
                <textarea name="swsib_options[subscription][email][footer_text]" id="email_footer_text" rows="3" class="large-text"><?php echo esc_textarea($email_footer_text); ?></textarea>
                <p class="swsib-field-note"><?php _e('Text to display in the email footer.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <div class="swsib-field">
                <h4><?php _e('Email Delivery Method', 'swiftspeed-siberian'); ?></h4>
                <label class="swsib-toggle-label">
                    <input type="checkbox" 
                           name="swsib_options[subscription][email][use_smtp]" 
                           id="email_use_smtp" 
                           <?php checked($email_use_smtp); ?>>
                    <span class="swsib-toggle-switch"></span>
                    <span class="swsib-toggle-text">
                        <?php echo $email_use_smtp ? 
                            __('Using SMTP', 'swiftspeed-siberian') : 
                            __('Using WordPress Default', 'swiftspeed-siberian'); ?>
                    </span>
                </label>
                <p class="swsib-field-note">
                    <?php _e('Use SMTP for better email deliverability. If disabled, WordPress default mail function will be used.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            
            <div id="smtp_settings_container" <?php echo !$email_use_smtp ? 'style="display:none;"' : ''; ?>>
                <div class="swsib-notice info">
                    <p><?php _e('Configure your SMTP settings below. These settings will be used to send all subscription notification emails.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="email_smtp_host"><?php _e('SMTP Host', 'swiftspeed-siberian'); ?></label>
                    <input type="text" name="swsib_options[subscription][email][smtp_host]" id="email_smtp_host" value="<?php echo esc_attr($email_smtp_host); ?>" class="regular-text">
                    <p class="swsib-field-note"><?php _e('Your SMTP server address (e.g. smtp.gmail.com).', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="email_smtp_port"><?php _e('SMTP Port', 'swiftspeed-siberian'); ?></label>
                    <input type="text" name="swsib_options[subscription][email][smtp_port]" id="email_smtp_port" value="<?php echo esc_attr($email_smtp_port); ?>" class="small-text">
                    <p class="swsib-field-note"><?php _e('SMTP port (usually 587 for TLS, 465 for SSL).', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="email_smtp_encryption"><?php _e('Encryption', 'swiftspeed-siberian'); ?></label>
                    <select name="swsib_options[subscription][email][smtp_encryption]" id="email_smtp_encryption">
                        <option value="none" <?php selected($email_smtp_encryption, 'none'); ?>><?php _e('None', 'swiftspeed-siberian'); ?></option>
                        <option value="tls" <?php selected($email_smtp_encryption, 'tls'); ?>><?php _e('TLS', 'swiftspeed-siberian'); ?></option>
                        <option value="ssl" <?php selected($email_smtp_encryption, 'ssl'); ?>><?php _e('SSL', 'swiftspeed-siberian'); ?></option>
                    </select>
                    <p class="swsib-field-note"><?php _e('Encryption type for SMTP connection.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label class="swsib-toggle-label">
                        <input type="checkbox" 
                               name="swsib_options[subscription][email][smtp_auth]" 
                               id="email_smtp_auth" 
                               <?php checked($email_smtp_auth); ?>>
                        <span class="swsib-toggle-switch"></span>
                        <span class="swsib-toggle-text">
                            <?php echo $email_smtp_auth ? 
                                __('SMTP Authentication Enabled', 'swiftspeed-siberian') : 
                                __('SMTP Authentication Disabled', 'swiftspeed-siberian'); ?>
                        </span>
                    </label>
                    <p class="swsib-field-note">
                        <?php _e('Enable if your SMTP server requires authentication.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <div id="smtp_auth_container" <?php echo !$email_smtp_auth ? 'style="display:none;"' : ''; ?>>
                    <div class="swsib-field">
                        <label for="email_smtp_username"><?php _e('SMTP Username', 'swiftspeed-siberian'); ?></label>
                        <input type="text" name="swsib_options[subscription][email][smtp_username]" id="email_smtp_username" value="<?php echo esc_attr($email_smtp_username); ?>" class="regular-text">
                        <p class="swsib-field-note"><?php _e('Your SMTP username.', 'swiftspeed-siberian'); ?></p>
                    </div>
                    
                    <div class="swsib-field">
                        <label for="email_smtp_password"><?php _e('SMTP Password', 'swiftspeed-siberian'); ?></label>
                        <input type="password" name="swsib_options[subscription][email][smtp_password]" id="email_smtp_password" value="<?php echo esc_attr($email_smtp_password); ?>" class="regular-text">
                        <p class="swsib-field-note"><?php _e('Your SMTP password. For Gmail, use an app password.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div class="swsib-field">
                    <button type="button" class="button button-secondary" id="test_smtp_connection">
                        <?php _e('Test SMTP Connection', 'swiftspeed-siberian'); ?>
                    </button>
                    <div id="smtp_test_result" class="swsib-test-result"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="swsib-section email-notifications-section" <?php echo !$email_enabled ? 'style="display:none;"' : ''; ?>>
        <h3><?php _e('Status Notification Templates', 'swiftspeed-siberian'); ?></h3>
        
        <div class="swsib-notice info">
            <p><?php _e('Configure email templates for different subscription statuses. You can use placeholders to include dynamic content in your emails.', 'swiftspeed-siberian'); ?></p>
            <p><strong><?php _e('Available Placeholders:', 'swiftspeed-siberian'); ?></strong></p>
            <div class="swsib-placeholders-container">
                <?php foreach ($available_placeholders as $placeholder => $description): ?>
                <span class="swsib-placeholder-item" data-placeholder="<?php echo esc_attr($placeholder); ?>" title="<?php echo esc_attr($description); ?>">
                    <?php echo esc_html($placeholder); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="swsib-email-templates-container">
            <div class="swsib-template-tabs">
                <?php foreach ($status_templates as $status => $template): ?>
                <button type="button" class="swsib-template-tab" data-status="<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(ucfirst(str_replace('-', ' ', $status))); ?>
                </button>
                <?php endforeach; ?>
            </div>
            
            <?php foreach ($status_templates as $status => $template): 
                $status_enabled = isset($status_notifications[$status]['enabled']) 
                    ? $status_notifications[$status]['enabled'] 
                    : ($status === 'active' || $status === 'cancelled');
                $status_subject = isset($status_notifications[$status]['subject']) 
                    ? $status_notifications[$status]['subject'] 
                    : $template['subject'];
                $status_content = isset($status_notifications[$status]['content']) 
                    ? $status_notifications[$status]['content'] 
                    : $template['content'];
            ?>
            <div class="swsib-template-content" data-status="<?php echo esc_attr($status); ?>">
                <div class="swsib-field">
                    <label class="swsib-toggle-label">
                        <input type="checkbox" 
                               name="swsib_options[subscription][email][status_notifications][<?php echo esc_attr($status); ?>][enabled]" 
                               id="email_status_<?php echo esc_attr($status); ?>_enabled" 
                               <?php checked($status_enabled); ?>>
                        <span class="swsib-toggle-switch"></span>
                        <span class="swsib-toggle-text">
                            <?php echo $status_enabled ? 
                                __('Notification Enabled', 'swiftspeed-siberian') : 
                                __('Notification Disabled', 'swiftspeed-siberian'); ?>
                        </span>
                    </label>
                    <p class="swsib-field-note">
                        <?php printf(__('Enable or disable email notifications for %s status.', 'swiftspeed-siberian'), '<strong>' . ucfirst(str_replace('-', ' ', $status)) . '</strong>'); ?>
                    </p>
                </div>
                
                <div class="swsib-field">
                    <label for="email_status_<?php echo esc_attr($status); ?>_subject"><?php _e('Email Subject', 'swiftspeed-siberian'); ?></label>
                    <input type="text" 
                           name="swsib_options[subscription][email][status_notifications][<?php echo esc_attr($status); ?>][subject]" 
                           id="email_status_<?php echo esc_attr($status); ?>_subject" 
                           value="<?php echo esc_attr($status_subject); ?>" 
                           class="large-text">
                </div>
                
                <div class="swsib-field">
                    <label for="email_status_<?php echo esc_attr($status); ?>_content"><?php _e('Email Content', 'swiftspeed-siberian'); ?></label>
                    <textarea name="swsib_options[subscription][email][status_notifications][<?php echo esc_attr($status); ?>][content]" 
                              id="email_status_<?php echo esc_attr($status); ?>_content" 
                              rows="10" 
                              class="large-text"><?php echo esc_textarea($status_content); ?></textarea>
                </div>
                
                <div class="swsib-field">
                    <button type="button" class="button preview-email-template" data-status="<?php echo esc_attr($status); ?>">
                        <?php _e('Preview Template', 'swiftspeed-siberian'); ?>
                    </button>
                    <button type="button" class="button reset-email-template" data-status="<?php echo esc_attr($status); ?>">
                        <?php _e('Reset to Default', 'swiftspeed-siberian'); ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="swsib-form-actions">
        <button type="submit" class="button button-primary" id="save_email_settings">
            <?php _e('Save Email Settings', 'swiftspeed-siberian'); ?>
        </button>
    </div>
</form>

<div id="email_preview_modal" class="swsib-modal">
    <div class="swsib-modal-content">
        <span class="swsib-modal-close">&times;</span>
        <h3><?php _e('Email Preview', 'swiftspeed-siberian'); ?></h3>
        <div class="swsib-email-preview-container">
            <div class="swsib-email-preview-header">
                <div class="swsib-email-preview-subject"></div>
                <div class="swsib-email-preview-meta">
                    <div><strong><?php _e('From:', 'swiftspeed-siberian'); ?></strong> <span class="swsib-email-preview-sender"></span></div>
                    <div><strong><?php _e('To:', 'swiftspeed-siberian'); ?></strong> <span class="swsib-email-preview-recipient"></span></div>
                </div>
            </div>
            <div class="swsib-email-preview-body">
                <div class="swsib-email-preview-content"></div>
            </div>
            <div class="swsib-email-preview-footer"></div>
        </div>
    </div>
</div>

<style>
/* Email Tab Styles */
.swsib-field {
    margin-bottom: 20px;
}

.swsib-field label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.swsib-media-field {
    display: flex;
    align-items: center;
    gap: 10px;
}

.swsib-logo-preview {
    margin-top: 10px;
    padding: 10px;
    background: #f7f7f7;
    border-radius: 4px;
    display: inline-block;
}

.swsib-placeholders-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.swsib-placeholder-item {
    background: #f1f1f1;
    border: 1px solid #ddd;
    padding: 5px 10px;
    border-radius: 3px;
    font-family: monospace;
    cursor: pointer;
    transition: all 0.2s;
}

.swsib-placeholder-item:hover {
    background: #e0e0e0;
}

.swsib-template-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    margin-bottom: 15px;
    border-bottom: 1px solid #ddd;
}

.swsib-template-tab {
    padding: 10px 15px;
    cursor: pointer;
    background: #f1f1f1;
    border: 1px solid #ddd;
    border-bottom: none;
    border-radius: 4px 4px 0 0;
    margin-right: 2px;
    font-weight: 500;
}

.swsib-template-tab.active {
    background: #fff;
    border-bottom: 1px solid #fff;
    margin-bottom: -1px;
}

.swsib-template-content {
    display: none;
    padding: 20px;
    background: #fff;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 4px 4px;
}

.swsib-template-content.active {
    display: block;
}

/* Modal Styles */
.swsib-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.swsib-modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 80%;
    max-width: 800px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-radius: 4px;
    position: relative;
}

.swsib-modal-close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    position: absolute;
    right: 15px;
    top: 10px;
}

.swsib-modal-close:hover,
.swsib-modal-close:focus {
    color: black;
    text-decoration: none;
}

.swsib-email-preview-container {
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 15px;
}

.swsib-email-preview-header {
    background: #f7f7f7;
    padding: 15px;
    border-bottom: 1px solid #ddd;
}

.swsib-email-preview-subject {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 10px;
}

.swsib-email-preview-meta {
    font-size: 14px;
    color: #666;
}

.swsib-email-preview-body {
    padding: 20px;
    background: #fff;
    min-height: 200px;
}

.swsib-email-preview-content {
    white-space: pre-wrap;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    line-height: 1.5;
}

.swsib-email-preview-footer {
    padding: 15px;
    background: #f7f7f7;
    border-top: 1px solid #ddd;
    font-size: 12px;
    color: #666;
    text-align: center;
}

.swsib-test-result {
    margin-top: 10px;
    padding: 10px;
    display: none;
}

.swsib-test-result.success {
    background: #dff0d8;
    border: 1px solid #d6e9c6;
    color: #3c763d;
}

.swsib-test-result.error {
    background: #f2dede;
    border: 1px solid #ebccd1;
    color: #a94442;
}

.swsib-form-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle Email Settings
    $('#email_enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#email_settings_container, .email-notifications-section').slideDown(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('Email Notifications Enabled');
        } else {
            $('#email_settings_container, .email-notifications-section').slideUp(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('Email Notifications Disabled');
        }
    });
    
    // Toggle SMTP Settings
    $('#email_use_smtp').on('change', function() {
        if ($(this).is(':checked')) {
            $('#smtp_settings_container').slideDown(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('Using SMTP');
        } else {
            $('#smtp_settings_container').slideUp(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('Using WordPress Default');
        }
    });
    
    // Toggle SMTP Authentication
    $('#email_smtp_auth').on('change', function() {
        if ($(this).is(':checked')) {
            $('#smtp_auth_container').slideDown(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('SMTP Authentication Enabled');
        } else {
            $('#smtp_auth_container').slideUp(300);
            $('.swsib-toggle-text', $(this).closest('label')).text('SMTP Authentication Disabled');
        }
    });
    
    // Handle Status Notification toggles
    $('[id^="email_status_"][id$="_enabled"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('.swsib-toggle-text', $(this).closest('label')).text('Notification Enabled');
        } else {
            $('.swsib-toggle-text', $(this).closest('label')).text('Notification Disabled');
        }
    });
    
    // Email logo upload
    $('#email_logo_upload_button').on('click', function(e) {
        e.preventDefault();
        
        var custom_uploader = wp.media({
            title: 'Select Logo',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });
        
        custom_uploader.on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#email_logo_url').val(attachment.url);
            
            // Update preview
            if ($('.swsib-logo-preview').length) {
                $('.swsib-logo-preview img').attr('src', attachment.url);
            } else {
                $('<div class="swsib-logo-preview"><img src="' + attachment.url + '" alt="Logo Preview" style="max-height: 60px; max-width: 200px;"></div>')
                    .insertAfter('#email_logo_url').closest('.swsib-field').find('.swsib-field-note');
            }
        });
        
        custom_uploader.open();
    });
    
    // Template Tabs
    $('.swsib-template-tab').on('click', function() {
        var status = $(this).data('status');
        
        // Update active tab
        $('.swsib-template-tab').removeClass('active');
        $(this).addClass('active');
        
        // Show corresponding content
        $('.swsib-template-content').removeClass('active').hide();
        $('.swsib-template-content[data-status="' + status + '"]').addClass('active').show();
    });
    
    // Show first tab by default
    $('.swsib-template-tab:first').click();
    
    // Placeholder Insertion
    $('.swsib-placeholder-item').on('click', function() {
        var placeholder = $(this).data('placeholder');
        var activeStatus = $('.swsib-template-content.active').data('status');
        
        if (activeStatus) {
            var subjectField = $('#email_status_' + activeStatus + '_subject');
            var contentField = $('#email_status_' + activeStatus + '_content');
            
            // If focus is in subject field
            if (subjectField.is(':focus')) {
                insertAtCursor(subjectField[0], placeholder);
            } 
            // Default to content field
            else {
                insertAtCursor(contentField[0], placeholder);
                contentField.focus();
            }
        }
    });
    
    // Helper function to insert text at cursor position
    function insertAtCursor(field, text) {
        if (document.selection) {
            // IE
            field.focus();
            var sel = document.selection.createRange();
            sel.text = text;
            field.focus();
        } else if (field.selectionStart || field.selectionStart === 0) {
            // Firefox/Chrome
            var startPos = field.selectionStart;
            var endPos = field.selectionEnd;
            field.value = field.value.substring(0, startPos) + text + field.value.substring(endPos, field.value.length);
            field.selectionStart = startPos + text.length;
            field.selectionEnd = startPos + text.length;
            field.focus();
        } else {
            field.value += text;
            field.focus();
        }
    }
    
    // Preview Template
    $('.preview-email-template').on('click', function() {
        var status = $(this).data('status');
        var subject = $('#email_status_' + status + '_subject').val();
        var content = $('#email_status_' + status + '_content').val();
        var senderName = $('#email_sender_name').val();
        var senderEmail = $('#email_sender_email').val();
        var footerText = $('#email_footer_text').val();
        
        // Replace placeholders with sample data
        var sampleData = {
            '{customer_name}': 'John Doe',
            '{customer_email}': 'john.doe@example.com',
            '{plan_name}': 'Premium Plan',
            '{amount}': '99.00',
            '{currency}': 'USD',
            '{billing_frequency}': 'Monthly',
            '{start_date}': '2023-01-01',
            '{end_date}': '2024-01-01',
            '{next_billing_date}': '2023-02-01',
            '{site_name}': $('#email_sender_name').val() || 'Your Website',
            '{site_url}': window.location.origin,
            '{subscription_id}': 'sub_123456789',
            '{application_id}': 'app_987654321'
        };
        
        // Replace placeholders in subject and content
        for (var placeholder in sampleData) {
            subject = subject.split(placeholder).join(sampleData[placeholder]);
            content = content.split(placeholder).join(sampleData[placeholder]);
        }
        
        // Populate preview modal
        $('.swsib-email-preview-subject').text(subject);
        $('.swsib-email-preview-sender').text(senderName + ' <' + senderEmail + '>');
        $('.swsib-email-preview-recipient').text(sampleData['{customer_name}'] + ' <' + sampleData['{customer_email}'] + '>');
        $('.swsib-email-preview-content').html(content.replace(/\n/g, '<br>'));
        $('.swsib-email-preview-footer').text(footerText);
        
        // Show modal
        $('#email_preview_modal').css('display', 'block');
    });
    
    // Reset Template to Default
    $('.reset-email-template').on('click', function() {
        if (!confirm('Are you sure you want to reset this template to default?')) {
            return;
        }
        
        var status = $(this).data('status');
        var defaultTemplates = {
            'active': {
                subject: 'Your subscription is now active',
                content: "Dear {customer_name},\n\nYour subscription has been activated successfully.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nBilling frequency: {billing_frequency}\nNext billing date: {next_billing_date}\n\nThank you for your business.\n\nRegards,\n{site_name}"
            },
            'pending-cancellation': {
                subject: 'Your subscription is pending cancellation',
                content: "Dear {customer_name},\n\nYour subscription has been set to pending cancellation. It will continue until the end of the current billing period.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nEnd date: {end_date}\n\nIf you did not request this cancellation, please contact us immediately.\n\nRegards,\n{site_name}"
            },
            'cancelled': {
                subject: 'Your subscription has been cancelled',
                content: "Dear {customer_name},\n\nYour subscription has been cancelled.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\n\nWe're sorry to see you go. If you have any feedback on how we could improve our service, please let us know.\n\nRegards,\n{site_name}"
            },
            'expired': {
                subject: 'Your subscription has expired',
                content: "Dear {customer_name},\n\nYour subscription has expired due to a payment issue.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\n\nTo reactivate your subscription, please update your payment information or contact us for assistance.\n\nRegards,\n{site_name}"
            },
            'renewed': {
                subject: 'Your subscription has been renewed',
                content: "Dear {customer_name},\n\nYour subscription has been renewed successfully.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\nBilling frequency: {billing_frequency}\nNext billing date: {next_billing_date}\n\nThank you for your continued business.\n\nRegards,\n{site_name}"
            },
            'payment_failed': {
                subject: 'Subscription payment failed',
                content: "Dear {customer_name},\n\nWe were unable to process the payment for your subscription.\n\nSubscription details:\nPlan: {plan_name}\nAmount: {amount} {currency}\n\nPlease update your payment information to avoid service interruption.\n\nRegards,\n{site_name}"
            }
        };
        
        if (defaultTemplates[status]) {
            $('#email_status_' + status + '_subject').val(defaultTemplates[status].subject);
            $('#email_status_' + status + '_content').val(defaultTemplates[status].content);
        }
    });
    
    // Close Modal
    $('.swsib-modal-close').on('click', function() {
        $('#email_preview_modal').css('display', 'none');
    });
    
    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if ($(event.target).is('#email_preview_modal')) {
            $('#email_preview_modal').css('display', 'none');
        }
    });
    
    // Test SMTP Connection
    $('#test_smtp_connection').on('click', function() {
        var $button = $(this);
        var $result = $('#smtp_test_result');
        
        var data = {
            action: 'swsib_test_smtp_connection',
            nonce: $('#_wpnonce_swsib_subscription_emails').val(),
            host: $('#email_smtp_host').val(),
            port: $('#email_smtp_port').val(),
            encryption: $('#email_smtp_encryption').val(),
            auth: $('#email_smtp_auth').is(':checked'),
            username: $('#email_smtp_username').val(),
            password: $('#email_smtp_password').val(),
            from_name: $('#email_sender_name').val(),
            from_email: $('#email_sender_email').val()
        };
        
        if (!data.host) {
            $result.removeClass('success').addClass('error').html('SMTP host is required').show();
            return;
        }
        
        if (data.auth && (!data.username || !data.password)) {
            $result.removeClass('success').addClass('error').html('SMTP username and password are required when authentication is enabled').show();
            return;
        }
        
        $button.prop('disabled', true).text('Testing...');
        $result.removeClass('success error').html('').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').html('Connection successful! ' + response.data.message).show();
                } else {
                    $result.removeClass('success').addClass('error').html('Connection failed: ' + response.data.message).show();
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').html('An unexpected error occurred').show();
            },
            complete: function() {
                $button.prop('disabled', false).text('Test SMTP Connection');
            }
        });
    });
});
</script>