<?php
/**
 * Subscription Integration - Refactored Version
 *
 * Main entry point for subscription integration with better database handling.
 * Enhanced to handle multiple payment gateways with dedicated handlers.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle all subscription integration functionality.
 */
class SwiftSpeed_Siberian_Subscription_Integration {

    /**
     * Plugin options.
     */
    private static $options = null;

    /**
     * Integration status.
     */
    private static $is_enabled = false;

    /**
     * Initialize the integration.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
        self::$is_enabled = isset(self::$options['subscription']['integration_enabled']) && 
                           filter_var(self::$options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        // Initialize database
        self::initialize_database();
        
        // Initialize payment-specific handlers
        self::initialize_payment_handlers();
        
        // Toggle integration AJAX handler (available regardless of enabled state)
        add_action('wp_ajax_swsib_toggle_subscription_integration', array(__CLASS__, 'ajax_toggle_integration'));
        
        // Handle subscription order creation (needs to be registered regardless of enabled state)
        add_action('wp_ajax_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));
        add_action('wp_ajax_nopriv_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));

        // Check if integration is enabled before loading other functionality
        if (!self::$is_enabled) {
            return;
        }

        // Add CORS headers
        add_action('init', array(__CLASS__, 'add_cors_headers'), 1);
        
        // Load required handlers
        self::load_handlers();
        
        // Register event listeners for payment gateway events
        self::register_payment_event_listeners();
    }
    
    /**
     * Initialize payment-specific handlers.
     */
    private static function initialize_payment_handlers() {
        // Initialize Stripe subscription handler
        if (file_exists(SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
            if (class_exists('SwiftSpeed_Siberian_Stripe_Sub_Handler')) {
                SwiftSpeed_Siberian_Stripe_Sub_Handler::init();
            }
        }
        
        // Initialize PayPal subscription handler
        if (file_exists(SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php')) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
            if (class_exists('SwiftSpeed_Siberian_PayPal_Sub_Handler')) {
                SwiftSpeed_Siberian_PayPal_Sub_Handler::init();
            }
        }
    }
    
    /**
     * Initialize the database.
     */
    private static function initialize_database() {
        // Load database manager
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/database-manager.php';
        
        // Database manager init will create tables if needed
    }

    /**
     * Load all required handlers.
     */
    private static function load_handlers() {
        // Include all necessary handler files
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/checkout-handler.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/tax-handler.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/webhook-handler.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/payment-loader.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/public/templates/frontend-shortcode.php';
        
        // Initialize handlers
        SwiftSpeed_Siberian_Checkout_Handler::init();
        SwiftSpeed_Siberian_Subscription_Handler::init();
        SwiftSpeed_Siberian_Tax_Handler::init();
        SwiftSpeed_Siberian_Webhook_Handler::init();
        SwiftSpeed_Siberian_Payment_Loader::init();
        SwiftSpeed_Siberian_Subscription_Frontend::init();
    }

    /**
     * Register event listeners for subscription events from payment gateways.
     */
    private static function register_payment_event_listeners() {
        // Listen for events from Stripe
        add_action('swsib_stripe_subscription_cancelled', array(__CLASS__, 'handle_stripe_subscription_cancelled'), 10, 2);
        add_action('swsib_stripe_subscription_created', array(__CLASS__, 'handle_stripe_subscription_created'), 10, 2);
        add_action('swsib_stripe_subscription_renewed', array(__CLASS__, 'handle_stripe_subscription_renewed'), 10, 2);
        add_action('swsib_stripe_subscription_updated', array(__CLASS__, 'handle_stripe_subscription_updated'), 10, 2);
        
        // Listen for events from PayPal
        add_action('swsib_paypal_subscription_activated', array(__CLASS__, 'handle_paypal_subscription_activated'), 10, 2);
        add_action('swsib_paypal_subscription_cancelled', array(__CLASS__, 'handle_paypal_subscription_cancelled'), 10, 2);
        add_action('swsib_paypal_subscription_suspended', array(__CLASS__, 'handle_paypal_subscription_suspended'), 10, 2);
        add_action('swsib_paypal_subscription_updated', array(__CLASS__, 'handle_paypal_subscription_updated'), 10, 2);
        add_action('swsib_paypal_payment_failed', array(__CLASS__, 'handle_paypal_payment_failed'), 10, 2);
        add_action('swsib_paypal_payment_completed', array(__CLASS__, 'handle_paypal_payment_completed'), 10, 2);
        
        // Generic status change listener
        add_action('swsib_subscription_status_changed', array(__CLASS__, 'handle_subscription_status_changed'), 10, 3);
        
        // Listen for checkout completion
        add_action('swsib_stripe_checkout_completed', array(__CLASS__, 'handle_stripe_checkout_completed'), 10, 2);
        add_action('swsib_paypal_checkout_completed', array(__CLASS__, 'handle_paypal_checkout_completed'), 10, 2);
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
     * Check if integration is enabled.
     */
    public static function is_integration_enabled() {
        return self::$is_enabled;
    }

    /**
     * Add CORS headers based on allowed origins.
     */
    public static function add_cors_headers() {
        // Get options
        $options = get_option('swsib_options', array());
        
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
     * AJAX handler for toggling subscription integration.
     */
    public static function ajax_toggle_integration() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        $enable = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
        
        $options = get_option('swsib_options', array());
        
        // Check if WooCommerce integration is enabled
        $woo_enabled = isset($options['woocommerce']['integration_enabled']) && 
                      filter_var($options['woocommerce']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        if ($enable && $woo_enabled) {
            self::log_message("Toggle integration failed - WooCommerce integration is active");
            wp_send_json_error(array(
                'message' => __('WooCommerce integration is currently active. Please disable it first.', 'swiftspeed-siberian'),
                'conflict' => true
            ));
            return;
        }
        
        // Update PE Subscription integration status
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        $options['subscription']['integration_enabled'] = $enable;
        update_option('swsib_options', $options);
        self::$options = $options;
        self::$is_enabled = $enable;
        
        // If enabling, make sure database tables are created
        if ($enable) {
            self::initialize_database();
        }
        
        self::log_message("PE Subscription integration " . ($enable ? "enabled" : "disabled"));
        
        wp_send_json_success(array(
            'message' => $enable ? 
                __('PE Subscription integration enabled successfully', 'swiftspeed-siberian') : 
                __('PE Subscription integration disabled successfully', 'swiftspeed-siberian'),
            'status' => $enable
        ));
    }

    /**
     * Event handler for Stripe subscription creation.
     */
    public static function handle_stripe_subscription_created($stripe_subscription_id, $subscription_data) {
        self::log_message("Event: Stripe subscription created: {$stripe_subscription_id}");
        
        // The core processing is now handled in the Stripe handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for Stripe subscription cancellation.
     */
    public static function handle_stripe_subscription_cancelled($stripe_subscription_id, $subscription_data) {
        self::log_message("Event: Stripe subscription cancelled: {$stripe_subscription_id}");
        
        // The core processing is now handled in the Stripe handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for Stripe subscription updates.
     */
    public static function handle_stripe_subscription_updated($stripe_subscription_id, $subscription_data) {
        self::log_message("Event: Stripe subscription updated: {$stripe_subscription_id}");
        
        // The core processing is now handled in the Stripe handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for Stripe subscription renewal.
     */
    public static function handle_stripe_subscription_renewed($stripe_subscription_id, $subscription_data) {
        self::log_message("Event: Stripe subscription renewed: {$stripe_subscription_id}");
        
        // The core processing is now handled in the Stripe handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal subscription activation.
     */
    public static function handle_paypal_subscription_activated($paypal_subscription_id, $subscription_data) {
        self::log_message("Event: PayPal subscription activated: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal subscription cancellation.
     */
    public static function handle_paypal_subscription_cancelled($paypal_subscription_id, $subscription_data) {
        self::log_message("Event: PayPal subscription cancelled: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal subscription suspension.
     */
    public static function handle_paypal_subscription_suspended($paypal_subscription_id, $subscription_data) {
        self::log_message("Event: PayPal subscription suspended: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal subscription updates.
     */
    public static function handle_paypal_subscription_updated($paypal_subscription_id, $subscription_data) {
        self::log_message("Event: PayPal subscription updated: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal payment failures.
     */
    public static function handle_paypal_payment_failed($paypal_subscription_id, $payment_data) {
        self::log_message("Event: PayPal payment failed for subscription: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal payment completions.
     */
    public static function handle_paypal_payment_completed($paypal_subscription_id, $payment_data) {
        self::log_message("Event: PayPal payment completed for subscription: {$paypal_subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for Stripe checkout completion.
     */
    public static function handle_stripe_checkout_completed($subscription_id, $session) {
        self::log_message("Event: Stripe checkout completed for subscription: {$subscription_id}");
        
        // The core processing is now handled in the Stripe handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Event handler for PayPal checkout completion.
     */
    public static function handle_paypal_checkout_completed($subscription_id, $data) {
        self::log_message("Event: PayPal checkout completed for subscription: {$subscription_id}");
        
        // The core processing is handled in the PayPal handler
        // This hook is mainly for any additional integration needs
    }

    /**
     * Handle generic subscription status change events
     */
    public static function handle_subscription_status_changed($subscription_id, $old_status, $new_status) {
        self::log_message("Event: Subscription {$subscription_id} status changed from {$old_status} to {$new_status}");
        
        // This is a central place to respond to any subscription status changes
        // regardless of which payment gateway triggered them

    }

    /**
     * Handle create_subscription_order for both WooCommerce and PE Subscription.
     * This is the handler that SiberianCMS is calling.
     */
    public static function handle_create_subscription_order() {
        self::log_message("=== START: Handling create_subscription_order ===");

        // Check which integration is enabled and handle accordingly
        $options = get_option('swsib_options', array());
        
        $woo_enabled = isset($options['woocommerce']['integration_enabled']) && 
                      filter_var($options['woocommerce']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
                      
        $pe_enabled = isset($options['subscription']['integration_enabled']) && 
                     filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        self::log_message("Integration status check - WooCommerce: " . ($woo_enabled ? "enabled" : "disabled") . 
                         ", PE Subscription: " . ($pe_enabled ? "enabled" : "disabled"));
        
        // If both are enabled (should not happen, but just in case), prioritize WooCommerce
        if ($woo_enabled) {
            self::log_message("Delegating to WooCommerce integration handler");
            
            // Check if WooCommerce hook loader class is available and has the handler method
            if (class_exists('SwiftSpeed_Siberian_Woocommerce_Hook_Loader')) {
                // Call the WooCommerce handler
                SwiftSpeed_Siberian_Woocommerce_Hook_Loader::handle_create_subscription_order();
                return;
            } else {
                self::log_message("ERROR: WooCommerce hook loader class not found");
                wp_send_json_error(array('message' => 'WooCommerce integration configuration error.'));
                return;
            }
        } 
        
        // If PE Subscription is enabled, handle it here
        if ($pe_enabled) {
            self::log_message("Using PE Subscription integration handler");
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/webhook-handler.php';
            SwiftSpeed_Siberian_Webhook_Handler::process_create_subscription_order();
            return;
        }
        
        // If neither integration is enabled, return an error
        self::log_message("ERROR: No integration is enabled");
        wp_send_json_error(array('message' => 'No subscription integration is enabled.'));
    }
}