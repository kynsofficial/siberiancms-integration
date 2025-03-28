<?php
/**
 * Payment Loader - Manages all payment gateways integration
 * Updated to use database storage for subscriptions
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle payment methods and their integration.
 */
class SwiftSpeed_Siberian_Payment_Loader {

    /**
     * Registered payment gateways.
     */
    private static $payment_gateways = array();

    /**
     * Plugin options.
     */
    private static $options = null;
    
    /**
     * DB module instance.
     */
    private static $db_module = null;

    /**
     * Initialize payment methods.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
        
        // Register available payment gateways
        self::register_payment_gateways();
        
        // Register AJAX handlers
        add_action('wp_ajax_swsib_process_payment', array(__CLASS__, 'handle_process_payment'));
        add_action('wp_ajax_nopriv_swsib_process_payment', array(__CLASS__, 'handle_process_payment'));
        
        // Renewal handling
        add_action('wp_ajax_swsib_renew_subscription', array(__CLASS__, 'handle_renew_subscription'));
        
        // Portal access (Stripe only)
        add_action('wp_ajax_swsib_get_stripe_portal', array(__CLASS__, 'handle_get_portal'));
        
        // Ensure gateway-specific hooks are registered
        add_action('wp_ajax_swsib_get_paypal_portal', array(__CLASS__, 'handle_get_paypal_portal'));
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
     * Register available payment gateways.
     */
    private static function register_payment_gateways() {
        // Load the base and interface files
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/payment-gateway-interface.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/payment-gateway-base.php';
        
        // Load Stripe handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-handler.php';
        
        // Load PayPal handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-handler.php';
        
        // Register Stripe gateway
        self::register_gateway('stripe', 'SwiftSpeed_Siberian_Stripe_Handler');
        
        // Register PayPal gateway
        self::register_gateway('paypal', 'SwiftSpeed_Siberian_PayPal_Handler');
        
        // Allow other plugins to register payment gateways
        do_action('swsib_register_payment_gateways');
    }

    /**
     * Register a payment gateway.
     */
    public static function register_gateway($gateway_id, $handler_class) {
        if (class_exists($handler_class)) {
            self::$payment_gateways[$gateway_id] = array(
                'id' => $gateway_id,
                'handler' => $handler_class
            );
            
            // Initialize the gateway if it has an init method
            if (method_exists($handler_class, 'init')) {
                call_user_func(array($handler_class, 'init'));
            }
        }
    }

    /**
     * Get all registered payment gateways.
     */
    public static function get_payment_gateways() {
        return self::$payment_gateways;
    }

    /**
     * Get a specific payment gateway handler.
     */
    public static function get_gateway($gateway_id) {
        if (isset(self::$payment_gateways[$gateway_id])) {
            return self::$payment_gateways[$gateway_id]['handler'];
        }
        return false;
    }

    /**
     * Handle payment processing via AJAX.
     * Fixed to properly handle nonce verification without bypassing security.
     */
    public static function handle_process_payment() {
        // Debug log the incoming request
        self::log_message("Payment processing request received: " . print_r($_POST, true));
        
        // Check for nonce and verify with multiple possible actions for better compatibility
        $nonce_verified = false;
        
        if (isset($_POST['nonce'])) {
            $nonce = sanitize_text_field($_POST['nonce']);
            self::log_message("Checking nonce: " . $nonce);
            
            // Check against multiple possible nonce actions
            if (wp_verify_nonce($nonce, 'swsib_subscription_checkout_nonce')) {
                $nonce_verified = true;
                self::log_message("Nonce verified with checkout_nonce action");
            } 
            elseif (wp_verify_nonce($nonce, 'swsib_subscription_frontend_nonce')) {
                $nonce_verified = true;
                self::log_message("Nonce verified with frontend_nonce action");
            } 
            elseif (wp_verify_nonce($nonce, 'swsib_subscription_public_nonce')) {
                $nonce_verified = true;
                self::log_message("Nonce verified with public_nonce action");
            }
            else {
                self::log_message("Nonce failed verification for all actions");
            }
        } else {
            self::log_message("No nonce provided in request");
        }
        
        // Enforce nonce verification for security
        if (!$nonce_verified) {
            self::log_message("Security check failed in payment processing");
            wp_send_json_error(array(
                'message' => __('Security verification failed. Please refresh the page and try again.', 'swiftspeed-siberian')
            ));
            return;
        }
        
        // Get payment method
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
        if (empty($payment_method)) {
            self::log_message("Error: No payment method specified");
            wp_send_json_error(array('message' => __('No payment method specified', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("Processing payment with method: {$payment_method}");
        
        // Get handler for the payment method
        $handler = self::get_gateway($payment_method);
        if (!$handler) {
            self::log_message("Error: Unsupported payment method: {$payment_method}");
            wp_send_json_error(array('message' => sprintf(__('Unsupported payment method: %s', 'swiftspeed-siberian'), $payment_method)));
            return;
        }
        
        // Get payment data
        $payment_data = isset($_POST['payment_data']) ? $_POST['payment_data'] : array();
        
        // Initialize payment_data as an empty array if it's not passed or is invalid
        if (!is_array($payment_data)) {
            $payment_data = array();
        }
        
        // Get checkout data
        $checkout_data = isset($_POST['checkout_data']) ? $_POST['checkout_data'] : array();
        if (empty($checkout_data)) {
            self::log_message("Error: No checkout data provided");
            wp_send_json_error(array('message' => __('Checkout data not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Log checkout data for debugging
        self::log_message("Checkout data: " . print_r($checkout_data, true));
        
        // Get customer data
        $customer_data = isset($_POST['customer_data']) ? $_POST['customer_data'] : array();
        
        // Log customer data for debugging
        self::log_message("Customer data: " . print_r($customer_data, true));
        
        // Store current user ID in user meta as a temporary record 
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, 'swsib_last_payment_method', $payment_method);
            
            // Add user ID to checkout data if not already present
            if (empty($checkout_data['user_id'])) {
                $checkout_data['user_id'] = $user_id;
                self::log_message("Added user ID {$user_id} to checkout data");
            }
        }
        
        // Process payment using the handler
        self::log_message("Delegating to payment handler: {$handler}");
        call_user_func(array($handler, 'process_payment'), $payment_data, $checkout_data, $customer_data);
    }

    /**
     * Handle subscription renewal via AJAX.
     */
    public static function handle_renew_subscription() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to perform this action', 'swiftspeed-siberian')));
            return;
        }
        
        // Get subscription ID
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("Processing renewal for subscription ID: {$subscription_id}");
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription from database
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Verify ownership
        if ($subscription['user_id'] !== get_current_user_id()) {
            wp_send_json_error(array('message' => __('Subscription does not belong to current user', 'swiftspeed-siberian')));
            return;
        }
        
        // Verify that this subscription is eligible for renewal (must be expired or in grace period, not cancelled)
        if ($subscription['status'] !== 'expired') {
            wp_send_json_error(array('message' => __('This subscription is not eligible for renewal', 'swiftspeed-siberian')));
            return;
        }
        
        // Determine payment method
        $payment_method = isset($subscription['payment_method']) ? $subscription['payment_method'] : 
                          (isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'stripe');
        
        // Get options for plans
        $options = get_option('swsib_options', array());
        
        // Find plan
        $plan = null;
        if (isset($options['subscription']['plans'])) {
            foreach ($options['subscription']['plans'] as $p) {
                if ($p['id'] === $subscription['plan_id']) {
                    $plan = $p;
                    break;
                }
            }
        }
        
        if (!$plan) {
            wp_send_json_error(array('message' => __('Subscription plan not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Get handler for the payment method
        $handler = self::get_gateway($payment_method);
        if (!$handler) {
            self::log_message("Error: Unsupported payment method for renewal: {$payment_method}");
            wp_send_json_error(array('message' => sprintf(__('Unsupported payment method: %s', 'swiftspeed-siberian'), $payment_method)));
            return;
        }
        
        // Create renewal checkout data
        $checkout_data = array(
            'plan_id' => $plan['id'],
            'user_id' => get_current_user_id(),
            'admin_id' => $subscription['admin_id'],
            'admin_email' => $subscription['admin_email'],
            'application_id' => $subscription['application_id'],
            'siberian_sub_id' => $subscription['siberian_plan_id'],
            'is_renewal' => true,
            'original_subscription_id' => $subscription_id
        );
        
        // Get customer data from the subscription
        $customer_data = isset($subscription['customer_data']) ? $subscription['customer_data'] : array();
        
        // Process renewal using the handler
        if (method_exists($handler, 'process_renewal')) {
            self::log_message("Delegating renewal to payment handler: {$handler}");
            call_user_func(array($handler, 'process_renewal'), $subscription_id, array(), $customer_data);
        } else {
            self::log_message("Error: Renewal not supported for: {$payment_method}");
            wp_send_json_error(array('message' => sprintf(__('Renewal not supported for: %s', 'swiftspeed-siberian'), $payment_method)));
        }
    }

    /**
     * Handle getting payment portal access via AJAX.
     * This is for Stripe only, PayPal doesn't provide a customer portal.
     */
    public static function handle_get_portal() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to perform this action', 'swiftspeed-siberian')));
            return;
        }
        
        // Get subscription ID
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("Getting payment portal for subscription ID: {$subscription_id}");
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription from database
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            self::log_message("Error: Subscription not found");
            wp_send_json_error(array(
                'message' => __('Subscription not found', 'swiftspeed-siberian')
            ));
            return;
        }
        
        // Verify ownership by checking if current user is admin OR the subscription owner
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        
        // Convert both to integers for proper comparison
        if (!$is_admin && (int)$subscription['user_id'] !== (int)$current_user_id) {
            self::log_message("Error: Subscription does not belong to current user (User ID: {$current_user_id}, Subscription User ID: {$subscription['user_id']})");
            wp_send_json_error(array(
                'message' => __('Subscription not found or does not belong to you', 'swiftspeed-siberian')
            ));
            return;
        }
        
        // Get the payment method
        $payment_method = isset($subscription['payment_method']) ? $subscription['payment_method'] : '';
        
        // Check if this is a Stripe subscription - PayPal has no customer portal
        if ($payment_method !== 'stripe') {
            self::log_message("Error: Payment method is {$payment_method}, not Stripe. No customer portal available.");
            wp_send_json_error(array(
                'message' => __('Customer portal is only available for Stripe subscriptions.', 'swiftspeed-siberian'),
                'use_fallback' => true
            ));
            return;
        }
        
        // Determine which handler to use
        $handler = self::get_gateway($payment_method);
        if (!$handler) {
            self::log_message("Error: No payment handler found for method: {$payment_method}");
            wp_send_json_error(array(
                'message' => __('Payment method not supported', 'swiftspeed-siberian'),
                'use_fallback' => true
            ));
            return;
        }
        
        // Check if the subscription can be managed by this gateway
        if (!call_user_func(array($handler, 'can_manage_subscription'), $subscription)) {
            self::log_message("Error: Subscription cannot be managed through {$payment_method}");
            wp_send_json_error(array(
                'message' => sprintf(__('This subscription cannot be managed through %s', 'swiftspeed-siberian'), ucfirst($payment_method)),
                'use_fallback' => true
            ));
            return;
        }
        
        // Get return URL if provided
        $return_url = isset($_POST['return_url']) ? esc_url_raw($_POST['return_url']) : '';
        
        // Call the handler to get the portal URL
        try {
            // Pass the return URL as a parameter to the handler if available
            if (!empty($return_url)) {
                $portal_url = call_user_func(array($handler, 'get_payment_portal'), $subscription_id, $subscription, $return_url);
            } else {
                $portal_url = call_user_func(array($handler, 'get_payment_portal'), $subscription_id, $subscription);
            }
            
            if ($portal_url === false) {
                wp_send_json_error(array(
                    'message' => __('Unable to generate portal URL. Please try again later or contact support.', 'swiftspeed-siberian'),
                    'use_fallback' => true
                ));
                return;
            }
            
            wp_send_json_success(array(
                'portal_url' => $portal_url,
                'message' => sprintf(__('Redirecting to %s portal...', 'swiftspeed-siberian'), ucfirst($payment_method))
            ));
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $response = array(
                'message' => sprintf(__('Error accessing %s portal: ', 'swiftspeed-siberian'), ucfirst($payment_method)) . $error_message,
                'use_fallback' => true
            );
            
            // Special handling for certain errors
            if ($error_message === 'stripe_portal_not_configured') {
                $response = array(
                    'message' => __('The Stripe Customer Portal has not been configured. Please contact the site administrator to set up the Stripe Customer Portal in the Stripe Dashboard.', 'swiftspeed-siberian'),
                    'portal_not_configured' => true,
                    'admin_message' => __('To configure the Stripe Customer Portal, go to the Stripe Dashboard > Settings > Billing > Customer Portal and set up your portal configuration.', 'swiftspeed-siberian')
                );
            }
            
            wp_send_json_error($response);
        }
    }

    /**
     * Handle getting PayPal portal access via AJAX.
     * This is a placeholder function to handle the AJAX call - PayPal doesn't have a customer portal like Stripe.
     */
    public static function handle_get_paypal_portal() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in to perform this action', 'swiftspeed-siberian')));
            return;
        }
        
        // Get subscription ID
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("PayPal portal access requested for subscription ID: {$subscription_id}");
        
        // PayPal doesn't have a customer portal, so return an informative error
        wp_send_json_error(array(
            'message' => __('PayPal doesn\'t provide a customer portal for subscription management. Please manage your subscription through this site or your PayPal account.', 'swiftspeed-siberian'),
            'use_fallback' => true
        ));
    }

    /**
     * Central logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'backend', $message);
        }
    }
}