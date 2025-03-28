<?php
/**
 * Frontend Shortcode Handler for Subscriptions
 *
 * Handles the rendering of subscription management UI on the frontend.
 * Updated to use database storage instead of options.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle subscription frontend rendering.
 */
class SwiftSpeed_Siberian_Subscription_Frontend {

    /**
     * DB module instance.
     */
    private static $db_module = null;

    /**
     * Initialize the handler.
     */
    public static function init() {
        // Register subscription shortcode
        add_shortcode('swsib_subscriptions', array(__CLASS__, 'subscriptions_shortcode'));
    }
    
    /**
     * Central logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'frontend', $message);
        }
    }
    
    /**
     * Get DB module instance.
     */
    private static function get_db_module() {
        if (self::$db_module !== null) {
            return self::$db_module;
        }
        
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
        self::$db_module = new SwiftSpeed_Siberian_Subscriptions_DB();
        return self::$db_module;
    }
    
    /**
     * Sync Stripe subscription status with database
     * Added to ensure Stripe changes made outside the system are reflected in the frontend
     */
    private static function sync_stripe_subscriptions($user_id) {
        if (!$user_id) {
            return;
        }
        
        // Get user's Stripe customer ID
        $customer_id = get_user_meta($user_id, 'swsib_stripe_customer_id', true);
        
        if (empty($customer_id)) {
            return;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        
        // Get Stripe API key
        $stripe_settings = isset($options['subscription']['payment_gateways']['stripe']) ? 
                         $options['subscription']['payment_gateways']['stripe'] : array();
                         
        if (!isset($stripe_settings['enabled']) || !$stripe_settings['enabled']) {
            return;
        }
        
        $is_test_mode = isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'];
        $secret_key = $is_test_mode ? 
                    $stripe_settings['test_secret_key'] : 
                    $stripe_settings['live_secret_key'];
        
        if (empty($secret_key)) {
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get all user's subscriptions
        $user_subscriptions = $db->get_user_subscriptions($user_id);
        $stripe_subscriptions = array();
        
        // Filter to Stripe subscriptions
        foreach ($user_subscriptions as $subscription) {
            if ($subscription['payment_method'] === 'stripe' && 
                !empty($subscription['payment_id']) && 
                strpos($subscription['payment_id'], 'sub_') === 0) {
                $stripe_subscriptions[$subscription['payment_id']] = $subscription;
            }
        }
        
        // If no Stripe subscriptions, nothing to sync
        if (empty($stripe_subscriptions)) {
            return;
        }
        
        // Get all customer's subscriptions from Stripe
        $response = wp_remote_get(
            "https://api.stripe.com/v1/customers/{$customer_id}/subscriptions?limit=100",
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key
                ),
                'timeout' => 30
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error getting Stripe subscriptions: " . $response->get_error_message());
            return;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            self::log_message("Non-200 response from Stripe API: " . $response_code);
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body);
        
        if (!isset($result->data) || !is_array($result->data)) {
            self::log_message("Invalid response from Stripe API");
            return;
        }
        
        $stripe_subscriptions_data = $result->data;
        
        foreach ($stripe_subscriptions_data as $stripe_sub) {
            $stripe_sub_id = $stripe_sub->id;
            
            // Check if we have this subscription in our database
            if (!isset($stripe_subscriptions[$stripe_sub_id])) {
                continue;
            }
            
            $our_subscription = $stripe_subscriptions[$stripe_sub_id];
            $current_status = $our_subscription['status'];
            $new_status = $current_status; // Default to no change
            
            // Determine new status based on Stripe status
            if ($stripe_sub->status === 'active') {
                if ($current_status === 'cancelled' || $current_status === 'expired') {
                    $new_status = 'active';
                }
            } else if ($stripe_sub->status === 'canceled') {
                if ($current_status === 'active' || $current_status === 'pending-cancellation') {
                    $new_status = 'cancelled';
                }
            } else if ($stripe_sub->status === 'past_due') {
                // Keep status but update payment status
                $db->update_subscription($our_subscription['id'], array(
                    'payment_status' => 'failed'
                ));
            } else if ($stripe_sub->status === 'unpaid') {
                $new_status = 'expired';
            }
            
            // Check for pending cancellation
            if ($stripe_sub->cancel_at_period_end && $current_status === 'active') {
                $new_status = 'pending-cancellation';
            }
            
            // Update status if changed
            if ($new_status !== $current_status) {
                self::log_message("Updating subscription {$our_subscription['id']} status from {$current_status} to {$new_status}");
                $db->update_subscription_status($our_subscription['id'], $new_status);
            }
        }
    }

  /**
 * Subscriptions shortcode handler - handles user subscription management display.
 * Updated to handle cancellation_source for conditional resume button display.
 */
public static function subscriptions_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<div class="swsib-notice info"><p>' . __('Please log in to view your subscriptions.', 'swiftspeed-siberian') . '</p></div>';
    }
    
    // Load the frontend CSS/JS
    wp_enqueue_style(
        'swsib-subscription-public-css',
        SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/public.css',
        array(),
        SWSIB_VERSION
    );
    
    wp_enqueue_script(
        'swsib-subscription-public-js',
        SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/public.js',
        array('jquery'),
        SWSIB_VERSION,
        true
    );
    
    // Localize script
    wp_localize_script('swsib-subscription-public-js', 'swsib_subscription_public', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('swsib_subscription_frontend_nonce'),
        'translations' => array(
            'cancelConfirmText' => __('Are you sure you want to cancel this subscription? Your subscription will continue until the end of the current billing period.', 'swiftspeed-siberian'),
            'pendingCancellationText' => __('Pending Cancellation', 'swiftspeed-siberian'),
            'pendingMessage' => __('Your subscription will be cancelled at the end of the current billing period.', 'swiftspeed-siberian'),
            'resumeSubscriptionText' => __('Resume Subscription', 'swiftspeed-siberian'),
            'cancelSuccessMessage' => __('Your subscription has been set to cancel at the end of the current billing period.', 'swiftspeed-siberian'),
            'resumeConfirmText' => __('Are you sure you want to resume this subscription? This will prevent it from being cancelled at the end of the current billing period.', 'swiftspeed-siberian'),
            'renewConfirmText' => __('Are you sure you want to renew this subscription? You will be redirected to payment.', 'swiftspeed-siberian')
        )
    ));
    
    // Get current user's subscriptions
    $user_id = get_current_user_id();
    
    // First, sync with Stripe to ensure data is up to date
    self::sync_stripe_subscriptions($user_id);
    
    // Get subscriptions using DB module
    $db = self::get_db_module();
    $subscriptions = $db->get_user_subscriptions($user_id);
    
    // Extract attributes
    $atts = shortcode_atts(array(
        'title' => __('Manage Subscriptions', 'swiftspeed-siberian')
    ), $atts);
    
    // Get plans for reference
    $options = get_option('swsib_options', array());
    $plans = isset($options['subscription']['plans']) ? $options['subscription']['plans'] : array();
    $popup_action = isset($options['subscription']['purchase_popup_action']) ? 
                   $options['subscription']['purchase_popup_action'] : '';
    
    // Get the current user's info
    $current_user = wp_get_current_user();
    $user_name = $current_user->display_name;
    $user_email = $current_user->user_email;
    $user_initials = substr($current_user->first_name, 0, 1) . substr($current_user->last_name, 0, 1);
    if (empty(trim($user_initials))) {
        $user_initials = strtoupper(substr($user_name, 0, 1));
    }
    
    // Format subscriptions for display
    $formatted_subscriptions = array();
    $active_count = 0;
    $cancelled_count = 0;
    $pending_count = 0;
    $expired_count = 0;
    
    // Debug log
    self::log_message("Starting to format subscriptions for display. Found " . count($subscriptions) . " subscriptions.");
    
    foreach ($subscriptions as $subscription) {
        $plan_name = '';
        $plan_price = '';
        $plan_currency = '';
        $plan_description = '';
        $plan_app_quantity = 1;
        
        // Find plan details
        foreach ($plans as $plan) {
            if ($plan['id'] === $subscription['plan_id']) {
                $plan_name = $plan['name'];
                $plan_price = $plan['price'];
                $plan_currency = $plan['currency'];
                $plan_description = $plan['description'];
                $plan_app_quantity = isset($plan['app_quantity']) ? $plan['app_quantity'] : 1;
                break;
            }
        }
        
        // Use app_quantity from subscription if available, otherwise from plan
        $app_quantity = isset($subscription['app_quantity']) ? $subscription['app_quantity'] : $plan_app_quantity;
        
        // Format dates
        $start_date = new DateTime($subscription['start_date']);
        $end_date = new DateTime($subscription['end_date']);
        $now = new DateTime();
        
        // Get status - use existing status, don't auto-compute expired
        $status = $subscription['status'];
        
        // Calculate grace period information for expired subscriptions
        $grace_period_message = '';
        $days_left_in_grace = 0;
        
        if ($status === 'expired' && isset($subscription['grace_period_end']) && !empty($subscription['grace_period_end'])) {
            $grace_end = new DateTime($subscription['grace_period_end']);
            if ($now <= $grace_end) {
                $interval = $now->diff($grace_end);
                $days_left_in_grace = $interval->days;
                $grace_period_message = sprintf(
                    __('Your subscription has expired. You have %d days left to renew before losing access.', 'swiftspeed-siberian'),
                    $days_left_in_grace
                );
            }
        }
        
        // Count by status
        if ($status === 'active') {
            $active_count++;
        } elseif ($status === 'pending-cancellation') {
            $pending_count++;
        } elseif ($status === 'cancelled') {
            $cancelled_count++;
        } elseif ($status === 'expired') {
            $expired_count++;
        }
        
        $billing_frequency_label = '';
        switch ($subscription['billing_frequency']) {
            case 'weekly':
                $billing_frequency_label = __('Weekly', 'swiftspeed-siberian');
                break;
            case 'monthly':
                $billing_frequency_label = __('Monthly', 'swiftspeed-siberian');
                break;
            case 'quarterly':
                $billing_frequency_label = __('Quarterly', 'swiftspeed-siberian');
                break;
            case 'biannually':
                $billing_frequency_label = __('Bi-annually', 'swiftspeed-siberian');
                break;
            case 'annually':
                $billing_frequency_label = __('Annually', 'swiftspeed-siberian');
                break;
            default:
                $billing_frequency_label = ucfirst($subscription['billing_frequency']);
        }
        
        // Check payment method - this is the key check for showing the Stripe button
        $payment_method = isset($subscription['payment_method']) ? $subscription['payment_method'] : '';
        // Only enable Stripe portal for active and pending-cancellation subscriptions
        $can_use_stripe_portal = ($payment_method === 'stripe' && $status !== 'cancelled');
        
        // Check cancellation source
        $cancellation_source = isset($subscription['cancellation_source']) ? $subscription['cancellation_source'] : '';
        
        // Determine if subscription can be resumed (only if from frontend for PayPal or any Stripe)
        $can_resume = false;
        if ($status === 'pending-cancellation') {
            if ($payment_method === 'stripe') {
                $can_resume = true; // Stripe can always be resumed
            } else if ($payment_method === 'paypal' && $cancellation_source === 'frontend') {
                $can_resume = true; // PayPal can only be resumed if canceled from frontend
            }
        }
        
        // Get stripe customer ID
        $stripe_customer_id = '';
        if ($can_use_stripe_portal) {
            // Get from subscription
            if (!empty($subscription['stripe_customer_id'])) {
                $stripe_customer_id = $subscription['stripe_customer_id'];
                self::log_message("Found Stripe customer ID in subscription: " . $stripe_customer_id);
            } else {
                // Try to get from user meta as fallback
                $meta_customer_id = get_user_meta($user_id, 'swsib_stripe_customer_id', true);
                if (!empty($meta_customer_id)) {
                    $stripe_customer_id = $meta_customer_id;
                    self::log_message("Found Stripe customer ID in user meta: " . $stripe_customer_id);
                    
                    // Save back to subscription for future
                    $db->update_subscription($subscription['id'], [
                        'stripe_customer_id' => $meta_customer_id
                    ]);
                }
            }
        }
        
        // Determine if the subscription is in grace period
        $is_in_grace_period = false;
        if ($status === 'expired' && !empty($days_left_in_grace)) {
            $is_in_grace_period = true;
        }
        
        self::log_message("Subscription ID: {$subscription['id']}, Payment Method: {$payment_method}, Can use Stripe: " . ($can_use_stripe_portal ? 'Yes' : 'No'));
        
        $formatted_subscriptions[] = array(
            'id' => $subscription['id'],
            'plan_name' => $plan_name,
            'plan_description' => $plan_description,
            'amount' => isset($subscription['total_amount']) ? $subscription['total_amount'] : $plan_price,
            'currency' => $plan_currency,
            'status' => $status,
            'start_date' => $start_date->format('F j, Y'),
            'end_date' => $end_date->format('F j, Y'),
            'billing_frequency' => $billing_frequency_label,
            'payment_method' => isset($subscription['payment_method']) ? ucfirst($subscription['payment_method']) : __('Manual', 'swiftspeed-siberian'),
            'payment_id' => isset($subscription['payment_id']) ? $subscription['payment_id'] : '',
            'app_quantity' => $app_quantity,
            'popup_action' => $popup_action,
            'grace_period_message' => $grace_period_message,
            'days_left_in_grace' => $days_left_in_grace,
            'is_in_grace_period' => $is_in_grace_period,
            'can_use_stripe_portal' => $can_use_stripe_portal,
            'can_resume' => $can_resume,
            'cancellation_source' => $cancellation_source,
            'stripe_customer_id' => $stripe_customer_id
        );
    }
    
    // Start building the output
    ob_start();
    
    // Use the premium dashboard template
    ?>
    <div class="swsib-frontend-container">
        <!-- Header Section -->
        <div class="swsib-frontend-header">
            <h2><?php echo esc_html($atts['title']); ?></h2>
            <p><?php _e('Manage your subscription plans and track your subscription history.', 'swiftspeed-siberian'); ?></p>
        </div>
        
        <!-- Dashboard Layout -->
        <div class="swsib-frontend-dashboard">
            <!-- Sidebar with user info and summary -->
            <div class="swsib-frontend-sidebar">
                <div class="swsib-frontend-card">
                    <div class="swsib-user-info">
                        <div class="swsib-user-avatar">
                            <?php echo esc_html($user_initials); ?>
                        </div>
                        <div class="swsib-user-details">
                            <h4><?php echo esc_html($user_name); ?></h4>
                            <p><?php echo esc_html($user_email); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="swsib-frontend-card">
                    <h3><?php _e('Subscription Summary', 'swiftspeed-siberian'); ?></h3>
                    <div class="swsib-subscription-summary">
                        <div class="swsib-summary-item clickable <?php echo ($active_count == 0 ? ' empty' : ''); ?>" data-filter="active">
                            <div class="swsib-summary-label"><?php _e('Active Subscriptions', 'swiftspeed-siberian'); ?></div>
                            <div class="swsib-summary-value"><?php echo esc_html($active_count); ?></div>
                        </div>
                        <div class="swsib-summary-item clickable <?php echo ($pending_count == 0 ? ' empty' : ''); ?>" data-filter="pending-cancellation">
                            <div class="swsib-summary-label"><?php _e('Pending Cancellation', 'swiftspeed-siberian'); ?></div>
                            <div class="swsib-summary-value"><?php echo esc_html($pending_count); ?></div>
                        </div>
                        <div class="swsib-summary-item clickable <?php echo ($cancelled_count == 0 ? ' empty' : ''); ?>" data-filter="cancelled">
                            <div class="swsib-summary-label"><?php _e('Cancelled', 'swiftspeed-siberian'); ?></div>
                            <div class="swsib-summary-value"><?php echo esc_html($cancelled_count); ?></div>
                        </div>
                        <div class="swsib-summary-item clickable <?php echo ($expired_count == 0 ? ' empty' : ''); ?>" data-filter="expired">
                            <div class="swsib-summary-label"><?php _e('Expired', 'swiftspeed-siberian'); ?></div>
                            <div class="swsib-summary-value"><?php echo esc_html($expired_count); ?></div>
                        </div>
                        <div class="swsib-summary-item clickable" data-filter="all">
                            <div class="swsib-summary-label"><?php _e('Show All', 'swiftspeed-siberian'); ?></div>
                            <div class="swsib-summary-value"><?php echo esc_html(count($formatted_subscriptions)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main content with subscriptions list -->
            <div class="swsib-frontend-main">
                <?php if (empty($formatted_subscriptions)): ?>
                    <div class="swsib-frontend-card">
                        <div class="swsib-empty-subscriptions">
                            <p><?php _e('You don\'t have any subscriptions yet.', 'swiftspeed-siberian'); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="swsib-message-container" style="display: none;"></div>
                    
                    <div class="swsib-subscription-filter-container">
                        <select id="subscription-status-filter" class="swsib-status-filter">
                            <option value="all"><?php _e('All Subscriptions', 'swiftspeed-siberian'); ?></option>
                            <option value="active"><?php _e('Active Only', 'swiftspeed-siberian'); ?></option>
                            <option value="pending-cancellation"><?php _e('Pending Cancellation', 'swiftspeed-siberian'); ?></option>
                            <option value="cancelled"><?php _e('Cancelled', 'swiftspeed-siberian'); ?></option>
                            <option value="expired"><?php _e('Expired', 'swiftspeed-siberian'); ?></option>
                        </select>
                    </div>
                    
                    <ul class="swsib-subscription-list">
                        <?php foreach ($formatted_subscriptions as $subscription): ?>
                            <li class="swsib-subscription-item" data-status="<?php echo esc_attr($subscription['status']); ?>">
                                <div class="swsib-subscription-header">
                                    <div class="swsib-subscription-title">
                                        <?php echo esc_html($subscription['plan_name']); ?>
                                    </div>
                                    <div class="swsib-subscription-price">
                                        <?php echo esc_html($subscription['amount']); ?> <?php echo esc_html($subscription['currency']); ?>
                                        <span class="swsib-plan-frequency"><?php echo esc_html($subscription['billing_frequency']); ?></span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($subscription['plan_description'])): ?>
                                <div class="swsib-subscription-description">
                                    <?php echo esc_html($subscription['plan_description']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="swsib-subscription-details">
                                    <div class="swsib-status-badge swsib-status-<?php echo esc_attr($subscription['status']); ?>">
                                        <?php 
                                        switch ($subscription['status']) {
                                            case 'active':
                                                _e('Active', 'swiftspeed-siberian');
                                                break;
                                            case 'pending-cancellation':
                                                _e('Pending Cancellation', 'swiftspeed-siberian');
                                                break;
                                            case 'cancelled':
                                                _e('Cancelled', 'swiftspeed-siberian');
                                                break;
                                            case 'expired':
                                                _e('Expired', 'swiftspeed-siberian');
                                                break;
                                            default:
                                                echo esc_html(ucfirst($subscription['status']));
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($subscription['grace_period_message'])): ?>
                                <div class="swsib-grace-period-message">
                                    <?php echo esc_html($subscription['grace_period_message']); ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="swsib-subscription-dates">
                                    <div class="swsib-start-date">
                                        <strong><?php _e('Started:', 'swiftspeed-siberian'); ?></strong> <?php echo esc_html($subscription['start_date']); ?>
                                    </div>
                                    <div class="swsib-end-date">
                                        <strong><?php _e('Ends:', 'swiftspeed-siberian'); ?></strong> <?php echo esc_html($subscription['end_date']); ?>
                                    </div>
                                </div>
                                
                                <div class="swsib-subscription-meta">
                                    <div class="swsib-payment-method">
                                        <strong><?php _e('Payment Method:', 'swiftspeed-siberian'); ?></strong>
                                        <span class="swsib-payment-method-icon swsib-payment-method-<?php echo strtolower($subscription['payment_method']); ?>"></span>
                                        <?php echo esc_html($subscription['payment_method']); ?>
                                    </div>
                                    <div class="swsib-app-quantity">
                                        <strong><?php _e('App Quantity:', 'swiftspeed-siberian'); ?></strong> 
                                        <?php echo esc_html($subscription['app_quantity']); ?>
                                    </div>
                                </div>
                                
                                <div class="swsib-subscription-actions">
                                    <?php if ($subscription['status'] === 'cancelled'): ?>
                                        <!-- No action buttons for cancelled subscriptions -->
                                        <div class="swsib-cancelled-message">
                                            <?php _e('This subscription has been cancelled.', 'swiftspeed-siberian'); ?>
                                        </div>
                                    <?php else: ?>
                                        <?php 
                                        // Show popup action for active, pending cancellation, and expired subscriptions in grace period
                                        $show_popup_action = !empty($subscription['popup_action']) && 
                                                           ($subscription['status'] === 'active' || 
                                                            $subscription['status'] === 'pending-cancellation' || 
                                                           ($subscription['status'] === 'expired' && $subscription['is_in_grace_period']));
                                                           
                                        if ($show_popup_action):
                                        ?>
                                            <?php if (strpos(trim($subscription['popup_action']), '[') === 0): ?>
                                                <?php echo do_shortcode($subscription['popup_action']); ?>
                                            <?php else: ?>
                                                <a href="<?php echo esc_url($subscription['popup_action']); ?>" class="swsib-button">
                                                    <?php _e('Go to Your App', 'swiftspeed-siberian'); ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($subscription['status'] === 'active'): ?>
                                            <?php if ($subscription['can_use_stripe_portal']): ?>
                                                <button type="button" class="swsib-button swsib-stripe-portal-btn" 
                                                        data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                                        data-customer-id="<?php echo esc_attr($subscription['stripe_customer_id']); ?>">
                                                    <?php _e('Manage in Stripe', 'swiftspeed-siberian'); ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button type="button" class="swsib-button swsib-cancel-btn" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                                                <?php _e('Cancel Subscription', 'swiftspeed-siberian'); ?>
                                            </button>
                                            
                                        <?php elseif ($subscription['status'] === 'pending-cancellation'): ?>
                                            <div class="swsib-pending-message">
                                                <?php _e('Your subscription will be cancelled at the end of the current billing period.', 'swiftspeed-siberian'); ?>
                                                
                                                <?php if ($subscription['cancellation_source'] === 'paypal'): ?>
                                                    <p class="swsib-paypal-note">
                                                        <?php _e('This cancellation was initiated from your PayPal account.', 'swiftspeed-siberian'); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($subscription['can_use_stripe_portal']): ?>
                                                <button type="button" class="swsib-button swsib-stripe-portal-btn" 
                                                        data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                                        data-customer-id="<?php echo esc_attr($subscription['stripe_customer_id']); ?>">
                                                    <?php _e('Manage in Stripe', 'swiftspeed-siberian'); ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($subscription['can_resume']): ?>
                                                <button type="button" class="swsib-button swsib-uncancel-btn" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                                                    <?php _e('Resume Subscription', 'swiftspeed-siberian'); ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                        <?php elseif ($subscription['status'] === 'expired'): ?>
                                            <button type="button" class="swsib-button swsib-renew-btn" 
                                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                                                <?php _e('Renew Subscription', 'swiftspeed-siberian'); ?>
                                            </button>
                                            
                                            <?php if ($subscription['can_use_stripe_portal']): ?>
                                                <button type="button" class="swsib-button swsib-stripe-portal-btn" 
                                                        data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                                        data-customer-id="<?php echo esc_attr($subscription['stripe_customer_id']); ?>">
                                                    <?php _e('Manage in Stripe', 'swiftspeed-siberian'); ?>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Loading overlay for Stripe portal redirects -->
    <div id="swsib-loading-overlay" style="display: none;">
        <div class="swsib-loading-spinner"></div>
        <div class="swsib-loading-message"><?php _e('Processing...', 'swiftspeed-siberian'); ?></div>
    </div>
    
    <?php
    
    return ob_get_clean();
     }
}