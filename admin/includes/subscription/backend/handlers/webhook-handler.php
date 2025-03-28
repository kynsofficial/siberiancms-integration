<?php
/**
 * Webhook Handler - Updated Version with Database Storage
 *
 * Manages payment gateway webhooks and delivers to appropriate handlers.
 * Enhanced to properly handle multiple payment gateways and prevent duplicate subscriptions.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle webhook events from payment gateways.
 */
class SwiftSpeed_Siberian_Webhook_Handler {

    /**
     * Plugin options.
     */
    private static $options = null;

    /**
     * WordPress subscription DB module instance.
     */
    private static $db_module = null;

    /**
     * SiberianCMS DB module instance.
     */
    private static $siber_db = null;

    /**
     * Initialize the handler.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
        
        // Add CORS headers for API endpoints
        add_action('init', array(__CLASS__, 'add_cors_headers'), 1);
        
        // Register API endpoints for SiberianCMS integration
        add_action('wp_ajax_create_subscription_order', array(__CLASS__, 'process_create_subscription_order'));
        add_action('wp_ajax_nopriv_create_subscription_order', array(__CLASS__, 'process_create_subscription_order'));
        add_action('wp_ajax_swsib_subscription_status_update', array(__CLASS__, 'handle_subscription_status_update'));
        add_action('wp_ajax_nopriv_swsib_subscription_status_update', array(__CLASS__, 'handle_subscription_status_update'));
    }

    /**
     * Central logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'backend', $message);
        }
    }

    /**
     * Get WordPress subscription DB module instance.
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
     * Get SiberianCMS DB module instance.
     */
    private static function get_siber_db() {
        if (self::$siber_db !== null) {
            return self::$siber_db;
        }
        
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/siberiansub-db.php';
        self::$siber_db = new SwiftSpeed_Siberian_SiberianSub_DB();
        return self::$siber_db;
    }

    /**
     * Add CORS headers for webhook endpoints.
     * Fixed to handle SiberianCMS origins properly.
     */
    public static function add_cors_headers() {
        // Get options
        $options = get_option('swsib_options', array());
        
        // Verify integration is enabled
        $integration_enabled = isset($options['subscription']['integration_enabled']) && 
                              filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        if (!$integration_enabled) {
            return;
        }
        
        $allowed_origins = array();
        
        // Get allowed origins from options
        if (isset($options['subscription']['allowed_origins_list'])) {
            foreach ($options['subscription']['allowed_origins_list'] as $entry) {
                if (!empty($entry['url'])) {
                    $allowed_origins[] = rtrim($entry['url'], '/');
                }
            }
        }
        
        // Add current site to allowed origins
        $current_home = rtrim(home_url(), '/');
        if (!in_array($current_home, $allowed_origins, true)) {
            $allowed_origins[] = $current_home;
        }
        
        // Add root domain as well
        $parsed = parse_url(home_url());
        if (isset($parsed['scheme'], $parsed['host'])) {
            $root_domain = $parsed['scheme'] . '://' . $parsed['host'];
            if (!in_array($root_domain, $allowed_origins, true)) {
                $allowed_origins[] = $root_domain;
            }
        }
        
        // Set CORS headers if origin is allowed
        if (!empty($allowed_origins) && isset($_SERVER['HTTP_ORIGIN'])) {
            $request_origin = rtrim($_SERVER['HTTP_ORIGIN'], '/');
            
            if (in_array($request_origin, $allowed_origins, true)) {
                header("Access-Control-Allow-Origin: {$request_origin}");
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
                header("Access-Control-Allow-Credentials: true");
                header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
                
                if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                    header("HTTP/1.1 200 OK");
                    exit;
                }
            }
        }
    }
    
    /**
     * Process incoming SiberianCMS create_subscription_order requests.
     * Fixed to correctly handle CSRF tokens from SiberianCMS.
     */
    public static function process_create_subscription_order() {
        self::log_message("=== START: Processing Subscription Order Request ===");
        
        // Check integration type
        $integration_status = self::check_integration_type();
        self::log_message("Integration status check - WooCommerce: {$integration_status['woo']}, PE Subscription: {$integration_status['pe']}");
        
        // Use PE Subscription if enabled, otherwise fall back to WooCommerce if available
        $use_pe = $integration_status['pe'] === 'enabled';
        
        if ($use_pe) {
            self::log_message("Using PE Subscription integration handler");
            self::process_pe_subscription_order();
        } else {
            self::log_message("PE Subscription not enabled");
            wp_send_json_error(array('message' => 'PE Subscription integration not enabled'));
        }
    }
    
    /**
     * Check which integration types are enabled
     */
    private static function check_integration_type() {
        $options = get_option('swsib_options', array());
        
        // Check PE Subscription integration
        $pe_enabled = isset($options['subscription']['integration_enabled']) && 
                    filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        // Check WooCommerce integration
        $woo_enabled = isset($options['woocommerce']['enabled']) && 
                    filter_var($options['woocommerce']['enabled'], FILTER_VALIDATE_BOOLEAN);
        
        return array(
            'pe' => $pe_enabled ? 'enabled' : 'disabled',
            'woo' => $woo_enabled ? 'enabled' : 'disabled'
        );
    }
    
    /**
     * Process PE Subscription order.
     * Fixed to handle SiberianCMS security token properly and improve application tracking.
     */
    private static function process_pe_subscription_order() {
        self::log_message("=== START: Processing Subscription Order Request ===");
        
        // Get raw POST data
        $raw_payload = file_get_contents('php://input');
        if (!empty($raw_payload)) {
            self::log_message("RAW JSON PAYLOAD: " . $raw_payload);
            $json_data = json_decode($raw_payload, true);
            if (is_array($json_data)) {
                $_POST = array_merge($_POST, $json_data);
                self::log_message("Merged JSON payload with \$_POST");
            }
        }
        
        // Log what we've received for debugging
        self::log_message("POST data: " . print_r($_POST, true));
        
        // Get required data
        $admin_id = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : 0;
        $app_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
        $admin_email = isset($_POST['admin_email']) ? sanitize_email($_POST['admin_email']) : '';
        $sub_id = isset($_POST['selected_subscription_id']) ? sanitize_text_field($_POST['selected_subscription_id']) : '';
        
        // For SiberianCMS, we accept its CSRF token without verification since it comes from the external system
        $csrf_token = isset($_POST['csrf_token']) ? sanitize_text_field($_POST['csrf_token']) : '';
        
        self::log_message("Data received - admin_id: $admin_id, app_id: $app_id, email: $admin_email, sub_id: $sub_id");
        self::log_message("SiberianCMS CSRF token: $csrf_token");
        
        // Validate required fields
        if (empty($admin_email) || empty($sub_id) || empty($app_id)) {
            self::log_message("ERROR: Missing required fields");
            wp_send_json_error(array('message' => 'Missing required fields.'));
            return;
        }
        
        // We don't validate the csrf_token here, as it comes from SiberianCMS and we just need to make sure it exists
        if (empty($csrf_token)) {
            self::log_message("WARNING: Missing CSRF token from SiberianCMS");
            // Continue anyway since some versions might not send it
        }
        
        // Include checkout handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/checkout-handler.php';
        
        // Create checkout URL
        $checkout_url = SwiftSpeed_Siberian_Checkout_Handler::create_subscription_order_url(
            $admin_id,
            $app_id,
            $admin_email,
            $sub_id
        );
        
        if (!$checkout_url) {
            self::log_message("ERROR: Failed to create checkout URL");
            wp_send_json_error(array('message' => 'Failed to create checkout URL'));
            return;
        }
        
        self::log_message("Returning checkout URL: $checkout_url");
        self::log_message("=== END: Processing Subscription Order Request ===");
        
        wp_send_json_success(array('checkout_url' => $checkout_url));
    }
    
    /**
     * Handle subscription status update requests.
     * Updated to require payment_method when looking up a subscription by payment_id.
     */
    public static function handle_subscription_status_update() {
        self::log_message("=== START: Processing Subscription Status Update ===");
        
        // Verify request
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_webhook_nonce')) {
            self::log_message("ERROR: Invalid nonce");
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Get required data
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $payment_id = isset($_POST['payment_id']) ? sanitize_text_field($_POST['payment_id']) : '';
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : '';
        
        if (empty($subscription_id) && (empty($payment_id) || empty($payment_method))) {
            self::log_message("ERROR: No subscription ID or payment ID with method provided");
            wp_send_json_error(array('message' => 'No subscription ID or valid payment information provided'));
            return;
        }
        
        if (empty($status)) {
            self::log_message("ERROR: No status provided");
            wp_send_json_error(array('message' => 'No status provided'));
            return;
        }
        
        // Valid status values
        $valid_statuses = array('active', 'pending-cancellation', 'cancelled', 'expired', 'failed');
        if (!in_array($status, $valid_statuses)) {
            self::log_message("ERROR: Invalid status: $status");
            wp_send_json_error(array('message' => 'Invalid status provided'));
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription
        $subscription = null;
        if (!empty($subscription_id)) {
            $subscription = $db->get_subscription($subscription_id);
        } else if (!empty($payment_id) && !empty($payment_method)) {
            // Using both payment_id AND payment_method to avoid conflicts
            $subscription = $db->get_subscription_by_payment_id($payment_id, $payment_method);
        }
        
        if (!$subscription) {
            self::log_message("ERROR: Subscription not found");
            wp_send_json_error(array('message' => 'Subscription not found'));
            return;
        }
        
        // Load subscription handler for status update functions
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        // Get current status
        $current_status = $subscription['status'];
        $additional_data = array();
        
        // Log the status change attempt
        self::log_message("Status change requested: $current_status -> $status for subscription {$subscription['id']}");
        
        // Only update if status is different
        if ($current_status === $status) {
            self::log_message("=== END: No status change needed, already $status ===");
            wp_send_json_success(array(
                'message' => 'No status change needed, already ' . $status,
                'subscription_id' => $subscription['id'],
                'status' => $status
            ));
            return;
        }
        
        // Special handling for PayPal vs Stripe cancellation
        if ($status === 'pending-cancellation') {
            self::log_message("Processing pending cancellation for {$subscription['payment_method']} subscription");
            
            // Different handling based on payment gateway
            if ($subscription['payment_method'] === 'stripe') {
                // For Stripe, we set cancel_at_period_end flag in the Stripe API
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                SwiftSpeed_Siberian_Stripe_Sub_Handler::set_pending_cancellation($subscription);
            } 
            else if ($subscription['payment_method'] === 'paypal') {
                // For PayPal, we don't cancel in PayPal yet - we'll cancel when the billing period ends
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                SwiftSpeed_Siberian_PayPal_Sub_Handler::set_pending_cancellation($subscription);
            }
        }
        
        // Handle different status transitions
        switch ($status) {
            case 'active':
                // If subscription was expired, it needs special handling for reactivation
                if ($current_status === 'expired') {
                    $result = SwiftSpeed_Siberian_Subscription_Handler::reactivate_expired_subscription($subscription['id']);
                    
                    if ($result) {
                        self::log_message("Successfully reactivated expired subscription {$subscription['id']}");
                        
                        wp_send_json_success(array(
                            'message' => 'Subscription reactivated successfully',
                            'subscription_id' => $subscription['id'],
                            'old_status' => $current_status,
                            'new_status' => 'active'
                        ));
                    } else {
                        self::log_message("Failed to reactivate subscription {$subscription['id']}");
                        wp_send_json_error(array('message' => 'Failed to reactivate subscription'));
                    }
                    return;
                }
                
                // For other statuses just update normally
                $additional_data = array(
                    'payment_status' => 'paid',
                    'last_payment_date' => current_time('mysql'),
                    'retry_count' => 0,
                    'retry_period_end' => null,
                    'grace_period_end' => null
                );
                break;
                
            case 'cancelled':
                // Handle immediate cancellation based on payment gateway
                if ($subscription['payment_method'] === 'stripe') {
                    if (isset($subscription['payment_id']) && strpos($subscription['payment_id'], 'sub_') === 0) {
                        // Cancel in Stripe
                        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                        SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_stripe_subscription_immediately($subscription['payment_id']);
                    }
                } 
                else if ($subscription['payment_method'] === 'paypal') {
                    if (isset($subscription['payment_id'])) {
                        // Cancel in PayPal
                        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                        SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_paypal_subscription($subscription['payment_id']);
                    }
                }
                // No additional data needed
                break;
                
            case 'pending-cancellation':
                // Handled in the gateway-specific code above before the switch statement
                break;
                
            case 'expired':
                // Set grace period
                $grace_end = new DateTime();
                $grace_end->add(new DateInterval('P7D')); // 7 days grace period
                $additional_data['grace_period_end'] = $grace_end->format('Y-m-d H:i:s');
                break;
                
            case 'failed':
                // Set payment status to failed, but keep subscription active for now
                $additional_data['payment_status'] = 'failed';
                $additional_data['last_payment_error'] = current_time('mysql');
                
                // Set retry period
                $retry_end = new DateTime();
                $retry_end->add(new DateInterval('P3D')); // 3 days retry period
                $additional_data['retry_period_end'] = $retry_end->format('Y-m-d H:i:s');
                
                // Increment retry count
                $retry_count = isset($subscription['retry_count']) ? intval($subscription['retry_count']) : 0;
                $additional_data['retry_count'] = $retry_count + 1;
                break;
        }
        
        // Update the subscription status
        $result = $db->update_subscription($subscription['id'], array_merge(
            array('status' => $status),
            $additional_data
        ));
        
        if (!$result) {
            self::log_message("Failed to update subscription status");
            wp_send_json_error(array('message' => 'Failed to update subscription status'));
            return;
        }
        
        // Handle SiberianCMS database actions
        if ($status === 'active' && $current_status !== 'active') {
            // Activate in SiberianCMS
            SwiftSpeed_Siberian_Subscription_Handler::activate_siberian_subscription(
                array(
                    'admin_id' => $subscription['admin_id'],
                    'admin_email' => $subscription['admin_email'],
                    'application_id' => $subscription['application_id'],
                    'siberian_sub_id' => $subscription['siberian_plan_id']
                ),
                null,
                $subscription['id']
            );
        }
        else if ($status === 'cancelled' && $current_status !== 'cancelled') {
            // Deactivate in SiberianCMS
            SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
                $subscription['admin_id'],
                $subscription['admin_email'],
                $subscription['application_id'],
                $subscription['siberian_plan_id'],
                'cancel'
            );
        }
        
        // Fire hooks for status change
        do_action('swsib_subscription_status_changed', $subscription['id'], $current_status, $status);
        
        self::log_message("=== END: Successfully processed subscription status update ===");
        
        wp_send_json_success(array(
            'message' => 'Subscription status updated successfully',
            'subscription_id' => $subscription['id'],
            'old_status' => $current_status,
            'new_status' => $status
        ));
    }
}