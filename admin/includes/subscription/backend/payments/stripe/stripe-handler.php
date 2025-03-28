<?php
/**
 * Stripe Payment Handler - Simplified Version with Database Storage
 *
 * Manages all Stripe-specific payment functionality with improved database handling
 * and proper application_id tracking for multiple subscriptions.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Load the base gateway class
require_once dirname(__FILE__) . '/../payment-gateway-base.php';

/**
 * Class to handle Stripe payment gateway functionality.
 */
class SwiftSpeed_Siberian_Stripe_Handler extends SwiftSpeed_Siberian_Payment_Gateway_Base {

    /**
     * Gateway ID.
     * 
     * @var string
     */
    protected static $gateway_id = 'stripe';
    
    /**
     * Gateway name.
     * 
     * @var string
     */
    protected static $gateway_name = 'Stripe';
    
    /**
     * WordPress subscription DB module instance.
     * 
     * @var SwiftSpeed_Siberian_Subscriptions_DB
     */
    private static $db_module = null;

    /**
     * SiberianCMS DB module instance.
     * 
     * @var SwiftSpeed_Siberian_SiberianSub_DB
     */
    private static $siber_db = null;

    /**
     * Initialize the handler.
     */
    public static function init() {
        parent::init();
        
        // Register webhook handler
        add_action('init', array(__CLASS__, 'register_webhook_endpoint'));
    }

    /**
     * Register the webhook endpoint.
     */
    public static function register_webhook_endpoint() {
        // Only register if initialized through the normal flow
        if (isset($_GET['swsib_stripe_webhook']) && $_GET['swsib_stripe_webhook'] === '1') {
            self::handle_webhook();
            exit;
        }
    }

    /**
     * Get WordPress subscription DB module instance.
     */
    protected static function get_db_module() {
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
    protected static function get_siber_db() {
        if (self::$siber_db !== null) {
            return self::$siber_db;
        }
        
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/siberiansub-db.php';
        self::$siber_db = new SwiftSpeed_Siberian_SiberianSub_DB();
        return self::$siber_db;
    }

    /**
     * Central logging method.
     */
    protected static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'backend', $message);
        }
    }

    /**
     * Get Stripe API keys based on mode.
     */
    protected static function get_api_keys() {
        $settings = self::get_settings();
        $is_test_mode = isset($settings['test_mode']) && $settings['test_mode'];
        
        return array(
            'publishable_key' => $is_test_mode ? 
                (isset($settings['test_publishable_key']) ? $settings['test_publishable_key'] : '') : 
                (isset($settings['live_publishable_key']) ? $settings['live_publishable_key'] : ''),
            'secret_key' => $is_test_mode ? 
                (isset($settings['test_secret_key']) ? $settings['test_secret_key'] : '') : 
                (isset($settings['live_secret_key']) ? $settings['live_secret_key'] : ''),
            'webhook_secret' => isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '',
            'is_test_mode' => $is_test_mode
        );
    }

    /**
     * Process payment with Stripe.
     * Simplified to create a clear, direct path to Stripe checkout.
     */
    public static function process_payment($payment_data, $checkout_data, $customer_data) {
        // Check if Stripe is enabled
        if (!self::is_enabled()) {
            wp_send_json_error(array('message' => __('Stripe payment gateway is not enabled', 'swiftspeed-siberian')));
            return;
        }
        
        // Get API keys
        $api_keys = self::get_api_keys();
        $secret_key = $api_keys['secret_key'];
        
        if (empty($secret_key)) {
            wp_send_json_error(array('message' => __('Stripe secret key not configured', 'swiftspeed-siberian')));
            return;
        }
        
        // Get plan details
        $plan_id = isset($checkout_data['plan_id']) ? sanitize_text_field($checkout_data['plan_id']) : '';
        if (empty($plan_id)) {
            wp_send_json_error(array('message' => __('Plan ID not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        
        // Find plan
        $plan = null;
        if (isset($options['subscription']['plans'])) {
            foreach ($options['subscription']['plans'] as $p) {
                if ($p['id'] === $plan_id) {
                    $plan = $p;
                    break;
                }
            }
        }
        
        if (!$plan) {
            wp_send_json_error(array('message' => __('Subscription plan not found', 'swiftspeed-siberian')));
            return;
        }
        
        try {
            // Log the operation
            self::log_message("Processing Stripe payment for plan ID: {$plan_id}");
            
            // Calculate total price including tax if applicable
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/tax-handler.php';
            $tax_amount = SwiftSpeed_Siberian_Tax_Handler::calculate_tax($plan, $customer_data);
            $total_amount = $plan['price'] + $tax_amount;
            
            // Get user ID
            if (isset($checkout_data['user_id'])) {
                $user_id = intval($checkout_data['user_id']);
            } elseif (is_user_logged_in()) {
                $user_id = get_current_user_id();
            } else {
                $user_id = 0;
            }
            
            // Create a unique session key for reference
            $session_key = uniqid('session_');
            
            // Store the complete checkout data in a transient for retrieval after payment
            $complete_checkout_data = array_merge($checkout_data, array(
                'user_id' => $user_id,
                'customer_data' => $customer_data,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'timestamp' => time()
            ));
            
            set_transient('swsib_stripe_checkout_' . $session_key, $complete_checkout_data, 3600); // 1 hour expiration
            self::log_message("Stored checkout data in transient with key: $session_key");
            
            // If user is logged in, also store in user meta as backup
            if ($user_id > 0) {
                update_user_meta($user_id, 'swsib_stripe_checkout_data', $complete_checkout_data);
                self::log_message("Stored checkout data in user meta for user ID: $user_id");
            }
            
            // Get success handler for URLs
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/checkout-handler.php';
            
            // Create success and cancel URLs
            $success_url = add_query_arg(
                array(
                    'swsib_checkout_success' => '1',
                    'session_key' => $session_key
                ),
                SwiftSpeed_Siberian_Checkout_Handler::get_success_redirect_url()
            );
            
            $cancel_url = add_query_arg(
                array(
                    'swsib_checkout_cancel' => '1',
                    'session_key' => $session_key
                ),
                isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url()
            );
            
            // Create proper line items array for subscription
            $line_items = array(
                array(
                    'price_data' => array(
                        'unit_amount' => round($plan['price'] * 100), // Amount in cents
                        'currency' => strtolower($plan['currency']),
                        'product_data' => array(
                            'name' => $plan['name'],
                            'description' => !empty($plan['description']) ? $plan['description'] : 'This is a starter plan for app creators'
                        ),
                        'recurring' => array(
                            'interval' => self::get_stripe_interval($plan['billing_frequency']),
                            'interval_count' => self::get_stripe_interval_count($plan['billing_frequency'])
                        )
                    ),
                    'quantity' => 1,
                )
            );
            
            // Add tax line item if applicable
            if ($tax_amount > 0) {
                $line_items[] = array(
                    'price_data' => array(
                        'unit_amount' => round($tax_amount * 100), // Tax amount in cents
                        'currency' => strtolower($plan['currency']),
                        'product_data' => array(
                            'name' => 'Tax for ' . $plan['name'],
                            'description' => 'Applicable tax'
                        ),
                        'recurring' => array(
                            'interval' => self::get_stripe_interval($plan['billing_frequency']),
                            'interval_count' => self::get_stripe_interval_count($plan['billing_frequency'])
                        )
                    ),
                    'quantity' => 1,
                );
            }
            
            // Clear and specific metadata to identify the subscription properly
            $metadata = array(
                'plan_id' => $plan['id'],
                'user_id' => $user_id,
                'admin_id' => isset($checkout_data['admin_id']) ? $checkout_data['admin_id'] : 0,
                'application_id' => isset($checkout_data['application_id']) ? $checkout_data['application_id'] : 0,
                'siberian_sub_id' => isset($checkout_data['siberian_sub_id']) ? $checkout_data['siberian_sub_id'] : 0,
                'session_key' => $session_key
            );
            
            // Customer email for Stripe
            $customer_email = isset($customer_data['email']) ? $customer_data['email'] : '';
            
            $session_params = array(
                'payment_method_types' => array('card'),
                'billing_address_collection' => 'required',
                'line_items' => $line_items,
                'mode' => 'subscription',
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'metadata' => $metadata,
                'subscription_data' => array(
                    'metadata' => $metadata
                )
            );
            
            // Add customer email if available
            if (!empty($customer_email)) {
                $session_params['customer_email'] = $customer_email;
            }
            
            self::log_message("Creating Stripe checkout session with parameters: " . json_encode($session_params));
            
            // Make request to Stripe API
            $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => self::build_stripe_params($session_params)
            ));
            
            if (is_wp_error($response)) {
                self::log_message('Stripe API error: ' . $response->get_error_message());
                wp_send_json_error(array('message' => $response->get_error_message()));
                return;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
            
            if (isset($data->error)) {
                self::log_message('Stripe API error: ' . $data->error->message);
                wp_send_json_error(array('message' => $data->error->message));
                return;
            }
            
            if (!isset($data->url) || !isset($data->id)) {
                self::log_message('Unexpected Stripe API response: ' . $body);
                wp_send_json_error(array('message' => 'Unexpected response from Stripe'));
                return;
            }
            
            self::log_message("Stripe checkout session created successfully: " . $data->id);
            
            // Update transient with Stripe session ID
            $checkout_data_transient = get_transient('swsib_stripe_checkout_' . $session_key);
            if ($checkout_data_transient) {
                $checkout_data_transient['stripe_session_id'] = $data->id;
                set_transient('swsib_stripe_checkout_' . $session_key, $checkout_data_transient, 3600);
            }
            
            // If user is logged in, store session ID in user meta
            if ($user_id > 0) {
                update_user_meta($user_id, 'swsib_stripe_session_id', $data->id);
                
                // Store customer ID if available
                if (isset($data->customer) && !empty($data->customer)) {
                    update_user_meta($user_id, 'swsib_stripe_customer_id', $data->customer);
                    self::log_message("Stored Stripe customer ID in user meta: " . $data->customer);
                }
            }
            
            // Return checkout session ID and URL
            wp_send_json_success(array(
                'checkout_url' => $data->url,
                'session_id' => $data->id
            ));
            
        } catch (Exception $e) {
            self::log_message('Stripe payment error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Convert billing frequency to Stripe interval
     */
    private static function get_stripe_interval($billing_frequency) {
        switch ($billing_frequency) {
            case 'weekly':
                return 'week';
            case 'monthly':
                return 'month';
            case 'quarterly':
                return 'month';
            case 'biannually':
                return 'month';
            case 'annually':
                return 'year';
            default:
                return 'month';
        }
    }
    
    /**
     * Get interval count for Stripe based on billing frequency
     */
    private static function get_stripe_interval_count($billing_frequency) {
        switch ($billing_frequency) {
            case 'weekly':
                return 1;
            case 'monthly':
                return 1;
            case 'quarterly':
                return 3;
            case 'biannually':
                return 6;
            case 'annually':
                return 1;
            default:
                return 1;
        }
    }

    /**
     * Process subscription renewal with Stripe.
     */
    public static function process_renewal($subscription_id, $payment_data, $customer_data) {
        // Check if Stripe is enabled
        if (!self::is_enabled()) {
            wp_send_json_error(array('message' => __('Stripe payment gateway is not enabled', 'swiftspeed-siberian')));
            return;
        }
        
        // Get API keys
        $api_keys = self::get_api_keys();
        $secret_key = $api_keys['secret_key'];
        
        if (empty($secret_key)) {
            wp_send_json_error(array('message' => __('Stripe secret key not configured', 'swiftspeed-siberian')));
            return;
        }
        
        try {
            self::log_message("Processing renewal for subscription ID: {$subscription_id}");
            
            // Get DB module
            $db = self::get_db_module();
            
            // Get subscription by ID
            $subscription = $db->get_subscription($subscription_id);
            
            if (!$subscription) {
                wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
                return;
            }
            
            // Verify ownership if user is logged in
            if ($subscription['user_id'] !== get_current_user_id()) {
                wp_send_json_error(array('message' => __('Subscription does not belong to current user', 'swiftspeed-siberian')));
                return;
            }
            
            // Get options for plans
            $options = get_option('swsib_options', array());
            
            // Get plan details
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
            
            // Calculate tax amount
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/tax-handler.php';
            $tax_amount = SwiftSpeed_Siberian_Tax_Handler::calculate_tax($plan, $customer_data);
            $total_amount = $plan['price'] + $tax_amount;
            
            // Create a unique key for the session
            $session_key = md5(wp_json_encode($subscription) . time());
            
            // Store renewal data for retrieval after payment
            $renewal_data = array(
                'subscription_id' => $subscription_id,
                'plan' => $plan,
                'customer_data' => $customer_data,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'user_id' => get_current_user_id(),
                'admin_id' => $subscription['admin_id'],
                'admin_email' => $subscription['admin_email'],
                'application_id' => $subscription['application_id'],
                'siberian_sub_id' => $subscription['siberian_plan_id']
            );
            
            set_transient('swsib_stripe_renewal_' . $session_key, $renewal_data, 3600); // 1 hour expiration
            update_user_meta(get_current_user_id(), 'swsib_stripe_renewal_data', $renewal_data);
            
            // Get success and cancel URLs
            $success_url = add_query_arg(
                array(
                    'swsib_stripe_renewal_success' => '1',
                    'session_key' => $session_key
                ),
                home_url('/my-account/subscriptions/')
            );
            
            $cancel_url = add_query_arg(
                array(
                    'swsib_stripe_renewal_cancel' => '1',
                    'session_key' => $session_key
                ),
                home_url('/my-account/subscriptions/')
            );
            
            // Create line items
            $line_items = array();
            
            // Add main subscription item
            $line_items[] = array(
                'price_data' => array(
                    'currency' => strtolower($plan['currency']),
                    'product_data' => array(
                        'name' => __('Renewal: ', 'swiftspeed-siberian') . $plan['name'],
                        'description' => !empty($plan['description']) ? $plan['description'] : 
                                        sprintf(__('Subscription renewal with %d app(s)', 'swiftspeed-siberian'), $plan['app_quantity'])
                    ),
                    'unit_amount' => round($plan['price'] * 100), // Convert to cents
                    'recurring' => array(
                        'interval' => self::get_stripe_interval($plan['billing_frequency']),
                        'interval_count' => self::get_stripe_interval_count($plan['billing_frequency'])
                    )
                ),
                'quantity' => 1
            );
            
            // Add tax as a separate line item if applicable
            if ($tax_amount > 0) {
                $line_items[] = array(
                    'price_data' => array(
                        'currency' => strtolower($plan['currency']),
                        'product_data' => array(
                            'name' => __('Tax', 'swiftspeed-siberian'),
                            'description' => __('Applicable tax', 'swiftspeed-siberian')
                        ),
                        'unit_amount' => round($tax_amount * 100), // Convert to cents
                        'recurring' => array(
                            'interval' => self::get_stripe_interval($plan['billing_frequency']),
                            'interval_count' => self::get_stripe_interval_count($plan['billing_frequency'])
                        )
                    ),
                    'quantity' => 1
                );
            }
            
            // Prepare customer data
            $customer_email = isset($customer_data['email']) ? $customer_data['email'] : '';
            if (empty($customer_email) && isset($subscription['admin_email'])) {
                $customer_email = $subscription['admin_email'];
            }

            // Get any existing Stripe customer ID from user meta
            $stripe_customer_id = get_user_meta(get_current_user_id(), 'swsib_stripe_customer_id', true);
            
            // Create checkout session parameters
            $session_params = array(
                'payment_method_types' => array('card'),
                'line_items' => $line_items,
                'mode' => 'subscription',
                'success_url' => $success_url,
                'cancel_url' => $cancel_url,
                'metadata' => array(
                    'session_key' => $session_key,
                    'renewal' => 'true',
                    'subscription_id' => $subscription_id,
                    'plan_id' => $plan['id'],
                    'user_id' => get_current_user_id(),
                    'admin_id' => $subscription['admin_id'],
                    'application_id' => $subscription['application_id'],
                    'siberian_plan_id' => $subscription['siberian_plan_id']
                ),
                'subscription_data' => array(
                    'metadata' => array(
                        'plan_id' => $plan['id'],
                        'user_id' => get_current_user_id(),
                        'subscription_id' => $subscription_id,
                        'renewal' => 'true',
                        'application_id' => $subscription['application_id'],
                        'siberian_plan_id' => $subscription['siberian_plan_id']
                    )
                )
            );

            // If we have a Stripe customer ID, use it
            if (!empty($stripe_customer_id)) {
                $session_params['customer'] = $stripe_customer_id;
            } 
            // Otherwise use the email
            else if (!empty($customer_email)) {
                $session_params['customer_email'] = $customer_email;
            }
            
            self::log_message("Creating Stripe renewal checkout session with params: " . json_encode($session_params));
            
            // Make request to Stripe API
            $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => self::build_stripe_params($session_params)
            ));
            
            if (is_wp_error($response)) {
                self::log_message('Stripe API error during renewal: ' . $response->get_error_message());
                wp_send_json_error(array('message' => $response->get_error_message()));
                return;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body);
            
            if (isset($data->error)) {
                self::log_message('Stripe API error during renewal: ' . $data->error->message);
                wp_send_json_error(array('message' => $data->error->message));
                return;
            }
            
            if (!isset($data->url) || !isset($data->id)) {
                self::log_message('Unexpected Stripe API response during renewal: ' . $body);
                wp_send_json_error(array('message' => 'Unexpected response from Stripe'));
                return;
            }
            
            self::log_message("Stripe renewal checkout session created successfully: " . $data->id);
            
            // Save customer ID if present and not already saved
            if (isset($data->customer) && empty($stripe_customer_id)) {
                update_user_meta(get_current_user_id(), 'swsib_stripe_customer_id', $data->customer);
            }
            
            // Return checkout session ID and URL
            wp_send_json_success(array(
                'checkout_url' => $data->url,
                'session_id' => $data->id
            ));
            
        } catch (Exception $e) {
            self::log_message('Stripe renewal error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Handle Stripe webhook.
     * Simplified to focus on the most important events and avoid duplicate processing.
     */
    public static function handle_webhook() {
        // Check if Stripe is enabled
        if (!self::is_enabled()) {
            status_header(403);
            echo json_encode(array('error' => 'Stripe gateway not enabled'));
            exit;
        }
        
        // Get API keys
        $api_keys = self::get_api_keys();
        $webhook_secret = $api_keys['webhook_secret'];
        
        // Get input
        $input = file_get_contents('php://input');
        self::log_message("Received webhook data: " . $input);
        
        try {
            // Parse JSON directly
            $event_data = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
            }
            
            // Verify signature if webhook secret is available
            if (!empty($webhook_secret) && isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
                $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
                
                // Proper signature verification
                if (!self::verify_webhook_signature($input, $sig_header, $webhook_secret)) {
                    throw new Exception('Signature verification failed');
                }
                
                self::log_message("Verified webhook signature: " . $sig_header);
            }
            
            // Create standard event object
            $event = (object) array(
                'type' => isset($event_data['type']) ? $event_data['type'] : '',
                'data' => (object) array(
                    'object' => (object) $event_data['data']['object']
                )
            );
            
            // Handle specific events
            self::log_message('Processing webhook event: ' . $event->type);
            
            switch ($event->type) {
                case 'checkout.session.completed':
                    self::log_message('Stripe webhook received: checkout.session.completed');
                    self::handle_checkout_session_completed($event->data->object);
                    break;
                    
                case 'customer.subscription.updated':
                    self::log_message('Stripe webhook received: customer.subscription.updated');
                    self::handle_subscription_updated($event->data->object);
                    break;
                    
                case 'customer.subscription.deleted':
                    self::log_message('Stripe webhook received: customer.subscription.deleted');
                    self::handle_subscription_deleted($event->data->object);
                    break;
                    
                case 'invoice.payment_succeeded':
                    self::log_message('Stripe webhook received: invoice.payment_succeeded');
                    self::handle_invoice_payment_succeeded($event->data->object);
                    break;
                    
                case 'invoice.payment_failed':
                    self::log_message('Stripe webhook received: invoice.payment_failed');
                    self::handle_invoice_payment_failed($event->data->object);
                    break;
                
                case 'charge.refunded':
                    self::log_message('Stripe webhook received: charge.refunded');
                    self::handle_charge_refunded($event->data->object);
                    break;
            }
            
            status_header(200);
            echo json_encode(array('success' => true));
            exit;
            
        } catch (Exception $e) {
            self::log_message('Stripe webhook error: ' . $e->getMessage());
            status_header(400);
            echo json_encode(array('error' => $e->getMessage()));
            exit;
        }
    }

    /**
     * Handle charge refund webhook events
     */
    private static function handle_charge_refunded($charge) {
        self::log_message("Processing charge refund: " . json_encode($charge));
        
        // Find subscription by invoice
        if (!isset($charge->invoice)) {
            self::log_message("No invoice ID in charge, can't determine subscription");
            return;
        }
        
        // Get invoice details to find the subscription
        $api_keys = self::get_api_keys();
        $secret_key = $api_keys['secret_key'];
        
        $response = wp_remote_get(
            'https://api.stripe.com/v1/invoices/' . $charge->invoice,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key
                )
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error retrieving invoice: " . $response->get_error_message());
            return;
        }
        
        $invoice = json_decode(wp_remote_retrieve_body($response));
        
        if (!isset($invoice->subscription)) {
            self::log_message("No subscription attached to invoice");
            return;
        }
        
        $stripe_sub_id = $invoice->subscription;
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription with matching payment_id
        $subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        
        if ($subscription) {
            // If it's a refund, immediately cancel the subscription
            $db->update_subscription_status($subscription['id'], 'cancelled');
            
            self::log_message("Subscription {$subscription['id']} cancelled due to refund");
            
            // Also deactivate in SiberianCMS
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
            
            SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
                $subscription['admin_id'],
                $subscription['admin_email'],
                $subscription['application_id'],
                $subscription['siberian_plan_id'],
                'cancel'
            );
        } else {
            self::log_message("No matching subscription found for Stripe subscription: {$stripe_sub_id}");
        }
    }

    /**
     * Handle Stripe subscription updates - ONLY handle status changes
     * No longer handles payment details or updates end dates
     */
    private static function handle_subscription_updated($subscription_object) {
        self::log_message("Processing Stripe subscription update: " . json_encode($subscription_object));
        
        // Get necessary data
        $stripe_sub_id = $subscription_object->id;
        $status = $subscription_object->status;
        $cancel_at_period_end = isset($subscription_object->cancel_at_period_end) ? $subscription_object->cancel_at_period_end : false;
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription by payment ID
        $subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        
        if (!$subscription) {
            self::log_message("No matching subscription found for Stripe subscription ID: {$stripe_sub_id}");
            return;
        }
        
        self::log_message("Found matching subscription ID: {$subscription['id']} with status: {$subscription['status']}");
        
        // Get current status
        $current_status = $subscription['status'];
        
        // Map Stripe status to our status - FOCUS ONLY ON STATUS CHANGES
        $new_status = $current_status; // Default to keeping current status
        $status_change = false;
        
        // Handle cancel_at_period_end first (since it's a special case)
        if ($cancel_at_period_end && $current_status === 'active') {
            $new_status = 'pending-cancellation';
            $status_change = true;
            self::log_message("Setting to pending-cancellation due to cancel_at_period_end=true");
        }
        // Then handle different Stripe status values
        else if ($status === 'canceled' && !$cancel_at_period_end && $current_status !== 'cancelled') {
            // If canceled immediately (not at period end)
            $new_status = 'cancelled';
            $status_change = true;
            self::log_message("Setting to cancelled due to immediate cancellation");
        }
        else if ($status === 'incomplete_expired' && $current_status !== 'expired') {
            $new_status = 'expired';
            $status_change = true;
            self::log_message("Marking subscription as expired due to incomplete setup");
        }
        
        // Only update if status needs to change
        if ($status_change && $new_status !== $current_status) {
            // Update status only, no payment details or dates
            $db->update_subscription_status($subscription['id'], $new_status);
            
            self::log_message("Updated subscription status from {$current_status} to {$new_status}");
            
            // Handle SiberianCMS integration for status changes
            if ($new_status === 'cancelled' && $current_status !== 'cancelled') {
                // Deactivate in SiberianCMS
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
                SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
                    $subscription['admin_id'],
                    $subscription['admin_email'],
                    $subscription['application_id'],
                    $subscription['siberian_plan_id'],
                    'cancel'
                );
            }
            
            // Fire action for integrations
            do_action('swsib_stripe_subscription_updated', $stripe_sub_id, $subscription_object);
        } else {
            self::log_message("No status update needed for subscription {$subscription['id']}");
        }
    }

    /**
     * Handle Stripe subscription deletion
     */
    private static function handle_subscription_deleted($subscription_object) {
        self::log_message("Processing Stripe subscription deletion: " . json_encode($subscription_object));
        
        // Get necessary data
        $stripe_sub_id = $subscription_object->id;
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription by payment ID
        $subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        
        if ($subscription) {
            self::log_message("Found matching subscription ID: {$subscription['id']} with status: {$subscription['status']}");
            
            // Only cancel if not already cancelled
            if ($subscription['status'] !== 'cancelled') {
                // Update status to cancelled
                $db->update_subscription_status($subscription['id'], 'cancelled');
                
                self::log_message("Marked subscription {$subscription['id']} as cancelled due to Stripe deletion");
                
                // Deactivate in SiberianCMS
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
                SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
                    $subscription['admin_id'],
                    $subscription['admin_email'],
                    $subscription['application_id'],
                    $subscription['siberian_plan_id'],
                    'cancel'
                );
                
                // Fire action for integrations
                do_action('swsib_stripe_subscription_cancelled', $stripe_sub_id, $subscription_object);
            } else {
                self::log_message("Subscription {$subscription['id']} already cancelled, no action needed");
            }
        } else {
            self::log_message("No matching subscription found for deleted Stripe subscription ID: {$stripe_sub_id}");
        }
    }

    /**
     * Handle invoice payment succeeded event
     * ONLY handles payment records and renewals, not subscription status changes
     */
    private static function handle_invoice_payment_succeeded($invoice) {
        self::log_message("Processing invoice payment success: " . json_encode($invoice));
        
        if (!isset($invoice->subscription)) {
            self::log_message("No subscription ID in invoice - not a subscription payment");
            return;
        }
        
        $stripe_sub_id = $invoice->subscription;
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription by payment ID
        $subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        
        if (!$subscription) {
            self::log_message("No matching subscription found for Stripe invoice payment: {$invoice->id}");
            return;
        }
        
        self::log_message("Found matching subscription: {$subscription['id']}");
        
        // Prepare payment data to update
        $payment_data = array(
            'payment_status' => 'paid',
            'last_payment_date' => current_time('mysql'),
            'retry_count' => 0,
            'last_payment_error' => null,
            'retry_period_end' => null
        );
        
        // Calculate new end date ONLY if the subscription is not pending cancellation
        if ($subscription['status'] !== 'pending-cancellation') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
            $new_end_date = SwiftSpeed_Siberian_Subscription_Handler::calculate_end_date($subscription['billing_frequency']);
            $payment_data['end_date'] = $new_end_date;
            
            self::log_message("Updated end date to {$new_end_date} for subscription {$subscription['id']}");
        } else {
            self::log_message("Subscription is pending cancellation, not updating end date");
        }
        
        // If subscription was expired, reactivate it (status change)
        if ($subscription['status'] === 'expired') {
            // Reactivate the subscription
            $payment_data['status'] = 'active';
            $db->update_subscription($subscription['id'], $payment_data);
            
            self::log_message("Reactivated expired subscription {$subscription['id']} after successful payment");
            
            // Activate in SiberianCMS ONLY for expired -> active transitions
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
            
            // Fire webhook event
            do_action('swsib_stripe_subscription_renewed', $stripe_sub_id, $invoice);
        } 
        // For active subscriptions, just update the payment data
        else {
            // Update the subscription data - DON'T change status
            $db->update_subscription($subscription['id'], $payment_data);
            
            self::log_message("Updated payment data for subscription {$subscription['id']}, status remains {$subscription['status']}");
        }
    }

    /**
     * Handle invoice payment failed event
     */
    private static function handle_invoice_payment_failed($invoice) {
        self::log_message("Processing invoice payment failure: " . json_encode($invoice));
        
        if (!isset($invoice->subscription)) {
            self::log_message("No subscription ID in invoice - not a subscription payment");
            return;
        }
        
        $stripe_sub_id = $invoice->subscription;
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription by payment ID
        $subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        
        if (!$subscription) {
            self::log_message("No matching subscription found for Stripe invoice payment failure: {$invoice->id}");
            return;
        }
        
        self::log_message("Found matching subscription: {$subscription['id']}");
        
        // Update payment data only
        $update_data = array(
            'payment_status' => 'failed',
            'last_payment_error' => current_time('mysql')
        );
        
        // Set retry period
        $retry_end = new DateTime();
        $retry_end->add(new DateInterval('P3D')); // 3 days retry period
        $update_data['retry_period_end'] = $retry_end->format('Y-m-d H:i:s');
        
        // Increment retry count
        $retry_count = isset($subscription['retry_count']) ? intval($subscription['retry_count']) : 0;
        $retry_count++;
        $update_data['retry_count'] = $retry_count;
        
        // Check if we've exceeded retry attempts
        if ($retry_count >= 3) {
            // Set to expired
            $update_data['status'] = 'expired';
            
            // Add grace period
            $grace_end = new DateTime();
            $grace_end->add(new DateInterval('P7D')); // 7 days grace period
            $update_data['grace_period_end'] = $grace_end->format('Y-m-d H:i:s');
            
            self::log_message("Marked subscription {$subscription['id']} as expired after {$retry_count} failed payment attempts");
        }
        
        // Update the subscription
        $db->update_subscription($subscription['id'], $update_data);
        
        self::log_message("Updated payment status to failed for subscription {$subscription['id']}, retry count: {$retry_count}");
    }

    /**
     * Handle completed Stripe Checkout session.
     * ONLY handles initial subscription creation with better duplicate detection.
     */
    private static function handle_checkout_session_completed($session) {
        self::log_message("Processing completed checkout session: " . json_encode($session));
        
        // Essential data from session
        $stripe_sub_id = isset($session->subscription) ? $session->subscription : null;
        $customer_id = isset($session->customer) ? $session->customer : null;
        $session_key = isset($session->metadata) && isset($session->metadata->session_key) ? $session->metadata->session_key : null;
        
        // If no subscription ID, we can't continue
        if (!$stripe_sub_id) {
            self::log_message("No subscription ID found in checkout session, cannot process");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // ENHANCED DUPLICATE DETECTION
        // Check if we already have a subscription with this payment ID
        $existing = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
        if ($existing) {
            self::log_message("Subscription already exists with this Stripe subscription ID: {$stripe_sub_id}. No action needed.");
            return;
        }
        
        // Check by application ID if available
        $application_id = isset($session->metadata) && isset($session->metadata->application_id) ? $session->metadata->application_id : 0;
        $siberian_sub_id = isset($session->metadata) && isset($session->metadata->siberian_sub_id) ? $session->metadata->siberian_sub_id : 0;
        
        if ($application_id && $siberian_sub_id) {
            // Check for any active subscriptions with the same application_id and siberian_sub_id
            $existing_subscriptions = $db->get_all_subscriptions(array(
                'application_id' => $application_id,
                'siberian_plan_id' => $siberian_sub_id,
                'status' => 'active'
            ));
            
            if (!empty($existing_subscriptions)) {
                self::log_message("Active subscription already exists for application ID: {$application_id} and siberian plan ID: {$siberian_sub_id}. Not creating duplicate.");
                
                // Update existing with new Stripe subscription ID
                $existing_subscription = $existing_subscriptions[0];
                $db->update_subscription($existing_subscription['id'], array(
                    'payment_id' => $stripe_sub_id,
                    'payment_method' => 'stripe',
                    'stripe_customer_id' => $customer_id
                ));
                
                self::log_message("Updated existing subscription {$existing_subscription['id']} with new Stripe subscription ID");
                return;
            }
        }
        
        // Get metadata from the session
        $metadata = isset($session->metadata) ? (array)$session->metadata : array();
        
        // Minimum required metadata fields
        $required_fields = array('user_id', 'plan_id', 'application_id', 'siberian_sub_id');
        $missing_fields = array();
        
        foreach ($required_fields as $field) {
            if (!isset($metadata[$field]) || empty($metadata[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            self::log_message("Missing required metadata fields: " . implode(', ', $missing_fields));
            
            // Try to get from transient if we have session_key
            if ($session_key) {
                $checkout_data = get_transient('swsib_stripe_checkout_' . $session_key);
                if ($checkout_data) {
                    self::log_message("Retrieved checkout data from transient with key: {$session_key}");
                    
                    // Fill in missing metadata from checkout data
                    foreach ($required_fields as $field) {
                        if (isset($checkout_data[$field]) && !isset($metadata[$field])) {
                            $metadata[$field] = $checkout_data[$field];
                        }
                    }
                } else {
                    self::log_message("No checkout data found in transient for key: {$session_key}");
                }
            }
            
            // Check again after trying to fill from transient
            $missing_fields = array();
            foreach ($required_fields as $field) {
                if (!isset($metadata[$field]) || empty($metadata[$field])) {
                    $missing_fields[] = $field;
                }
            }
            
            if (!empty($missing_fields)) {
                self::log_message("Still missing required metadata fields after checking transient: " . implode(', ', $missing_fields));
                return;
            }
        }
        
        // Extract key data
        $user_id = (int)$metadata['user_id'];
        $application_id = (int)$metadata['application_id'];
        $siberian_sub_id = $metadata['siberian_sub_id'];
        $plan_id = $metadata['plan_id'];
        $admin_id = isset($metadata['admin_id']) ? (int)$metadata['admin_id'] : 0;
        
        // Get customer information
        $customer_data = array();
        if (isset($session->customer_details)) {
            $details = $session->customer_details;
            
            if (isset($details->email)) {
                $customer_data['email'] = $details->email;
            }
            
            if (isset($details->name)) {
                $name_parts = explode(' ', $details->name, 2);
                $customer_data['first_name'] = $name_parts[0];
                $customer_data['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
            }
            
            if (isset($details->address)) {
                $address = $details->address;
                
                if (isset($address->country)) {
                    $customer_data['country'] = $address->country;
                }
                
                if (isset($address->postal_code)) {
                    $customer_data['zip'] = $address->postal_code;
                }
                
                if (isset($address->state)) {
                    $customer_data['state'] = $address->state;
                }
                
                if (isset($address->city)) {
                    $customer_data['city'] = $address->city;
                }
                
                if (isset($address->line1)) {
                    $customer_data['address'] = $address->line1;
                }
            }
        }
        
        // Get email from user data if not in customer_data
        if (!isset($customer_data['email']) && $user_id > 0) {
            $user = get_user_by('ID', $user_id);
            if ($user) {
                $customer_data['email'] = $user->user_email;
            }
        }
        
        // Get plan details
        $options = get_option('swsib_options', array());
        $plan = null;
        
        if (isset($options['subscription']['plans'])) {
            foreach ($options['subscription']['plans'] as $p) {
                if ($p['id'] === $plan_id) {
                    $plan = $p;
                    break;
                }
            }
        }
        
        if (!$plan) {
            self::log_message("Plan not found for ID: {$plan_id}, cannot create subscription");
            return;
        }
        
        // Create checkout data from session metadata
        $checkout_data = array(
            'user_id' => $user_id,
            'admin_id' => $admin_id,
            'application_id' => $application_id,
            'siberian_sub_id' => $siberian_sub_id,
            'plan_id' => $plan_id,
            'admin_email' => isset($customer_data['email']) ? $customer_data['email'] : ''
        );
        
        // Store Stripe customer ID in user meta for future use
        if ($customer_id && $user_id > 0) {
            update_user_meta($user_id, 'swsib_stripe_customer_id', $customer_id);
        }
        
        // Create subscription record via Subscription Handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        $subscription_id = SwiftSpeed_Siberian_Subscription_Handler::create_subscription(
            $plan, 
            $checkout_data, 
            $stripe_sub_id,  // This is the Stripe subscription ID
            $customer_data,
            'stripe'  // Payment method
        );
        
        if (!$subscription_id) {
            self::log_message("Failed to create subscription record");
            return;
        }
        
        self::log_message("Successfully created subscription record with ID: {$subscription_id}");
        
        // Update subscription with Stripe customer ID
        if ($customer_id) {
            $db->update_subscription($subscription_id, array(
                'stripe_customer_id' => $customer_id
            ));
            self::log_message("Added Stripe customer ID to subscription: {$customer_id}");
        }
        
        // Activate in SiberianCMS
        $activation_result = SwiftSpeed_Siberian_Subscription_Handler::activate_siberian_subscription(
            $checkout_data,
            $plan,
            $subscription_id
        );
        
        if (!$activation_result) {
            self::log_message("Failed to activate subscription in SiberianCMS, but subscription was created");
        } else {
            self::log_message("Successfully activated subscription in SiberianCMS");
        }
        
        // Clean up transients
        if ($session_key) {
            delete_transient('swsib_stripe_checkout_' . $session_key);
        }
        
        // Store success data for redirect
        if ($user_id > 0) {
            update_user_meta($user_id, 'swsib_checkout_success_data', array(
                'subscription_id' => $subscription_id,
                'plan_name' => $plan['name'],
                'timestamp' => time()
            ));
        }
        
        self::log_message("Subscription processing completed successfully");
    }

    /**
     * Generate a payment management portal URL.
     */
    public static function get_payment_portal($subscription_id, $subscription) {
        if (!self::can_manage_subscription($subscription)) {
            return false;
        }
        
        // Get Stripe customer ID
        $stripe_customer_id = '';
        
        // Try to get from subscription first
        if (isset($subscription['stripe_customer_id']) && !empty($subscription['stripe_customer_id'])) {
            $stripe_customer_id = $subscription['stripe_customer_id'];
        } 
        // If not in subscription, check user meta
        else if (isset($subscription['user_id'])) {
            $stripe_customer_id = get_user_meta($subscription['user_id'], 'swsib_stripe_customer_id', true);
        }
        
        // If still no customer ID but we have a subscription ID in Stripe format, try to get from API
        if (empty($stripe_customer_id) && isset($subscription['payment_id']) && strpos($subscription['payment_id'], 'sub_') === 0) {
            self::log_message("Looking up customer ID from Stripe subscription: " . $subscription['payment_id']);
            
            // Get API keys
            $api_keys = self::get_api_keys();
            $secret_key = $api_keys['secret_key'];
            
            $response = wp_remote_get(
                'https://api.stripe.com/v1/subscriptions/' . $subscription['payment_id'],
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $secret_key,
                    )
                )
            );
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                
                if ($response_code === 200) {
                    $subscription_data = json_decode($body);
                    if (!empty($subscription_data->customer)) {
                        $stripe_customer_id = $subscription_data->customer;
                        self::log_message("Found customer ID in Stripe API: " . $stripe_customer_id);
                        
                        // Store for future use
                        $db = self::get_db_module();
                        $db->update_subscription($subscription_id, array(
                            'stripe_customer_id' => $stripe_customer_id
                        ));
                        
                        if (isset($subscription['user_id'])) {
                            update_user_meta($subscription['user_id'], 'swsib_stripe_customer_id', $stripe_customer_id);
                        }
                    }
                }
            }
        }
        
        if (empty($stripe_customer_id)) {
            self::log_message("No Stripe customer ID found for subscription: " . $subscription_id);
            return false;
        }
        
        // Now generate the portal URL
        try {
            $api_keys = self::get_api_keys();
            $secret_key = $api_keys['secret_key'];
            
            if (empty($secret_key)) {
                self::log_message("Stripe secret key not configured");
                return false;
            }
            
            self::log_message("Creating portal session for customer ID: " . $stripe_customer_id);
            
            // Find the page with the subscriptions shortcode
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/checkout-handler.php';
            $subscriptions_page_url = SwiftSpeed_Siberian_Checkout_Handler::get_success_redirect_url();
            
            // Create return URL
            $return_url = add_query_arg(
                array('swsib_stripe_portal_return' => '1'),
                $subscriptions_page_url
            );
            
            // Create portal session
            $response = wp_remote_post(
                'https://api.stripe.com/v1/billing_portal/sessions',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $secret_key,
                        'Content-Type' => 'application/x-www-form-urlencoded'
                    ),
                    'body' => array(
                        'customer' => $stripe_customer_id,
                        'return_url' => $return_url
                    )
                )
            );
            
            if (is_wp_error($response)) {
                self::log_message("Error creating portal session: " . $response->get_error_message());
                return false;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                // Check for specific error about portal configuration
                $error_data = json_decode($body, true);
                if (isset($error_data['error']['message']) && 
                    strpos($error_data['error']['message'], 'No configuration provided') !== false) {
                    // This is the error about portal configuration not being set up
                    throw new Exception('stripe_portal_not_configured');
                }
                
                self::log_message("Error response from Stripe API: " . $body);
                return false;
            }
            
            $portal_data = json_decode($body);
            
            if (empty($portal_data->url)) {
                self::log_message("No URL in Stripe portal response");
                return false;
            }
            
            self::log_message("Successfully created Stripe customer portal URL: " . $portal_data->url);
            return $portal_data->url;
            
        } catch (Exception $e) {
            if ($e->getMessage() === 'stripe_portal_not_configured') {
                self::log_message('Stripe customer portal not configured in Stripe Dashboard');
                // Pass this specific error up
                throw $e;
            } else {
                self::log_message('Stripe portal error: ' . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Improved webhook signature verification for Stripe.
     */
    private static function verify_webhook_signature($payload, $sig_header, $secret) {
        // Get timestamp and signatures from header
        $timestamp = null;
        $signatures = [];
        
        $items = explode(',', $sig_header);
        foreach($items as $item) {
            $item = trim($item);
            if (strpos($item, 't=') === 0) {
                $timestamp = substr($item, 2);
            } elseif (strpos($item, 'v1=') === 0) {
                $signatures[] = substr($item, 3);
            }
        }
        
        if (!$timestamp || empty($signatures)) {
            self::log_message("Invalid signature format: missing timestamp or signatures");
            return false;
        }
        
        // Verify the timestamp (optional) - 5 minute tolerance
        $now = time();
        if ($now - intval($timestamp) > 300) {
            self::log_message("Webhook timestamp too old: " . $timestamp);
            return false;
        }
        
        // Compute expected signature
        $signed_payload = $timestamp . '.' . $payload;
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);
        
        // Check if any signature matches
        foreach ($signatures as $signature) {
            if (hash_equals($expected_signature, $signature)) {
                return true;
            }
        }
        
        self::log_message("Signature verification failed - expected: " . $expected_signature);
        return false;
    }

    /**
     * Helper method to build Stripe API parameters in the correct format.
     */
    private static function build_stripe_params($params, $parent_key = '') {
        $result = array();
        
        foreach ($params as $key => $value) {
            $key_name = $parent_key ? $parent_key . '[' . $key . ']' : $key;
            
            if (is_array($value)) {
                if (isset($value[0]) && is_array($value[0])) { 
                    // This is a numeric indexed array of arrays
                    foreach ($value as $i => $item) {
                        $result = array_merge($result, self::build_stripe_params($item, $key_name . '[' . $i . ']'));
                    }
                } elseif (isset($value[0])) {
                    // This is a numeric indexed array of scalar values
                    foreach ($value as $i => $item) {
                        $result[$key_name . '[' . $i . ']'] = $item;
                    }
                } else {
                    // This is an associative array
                    $result = array_merge($result, self::build_stripe_params($value, $key_name));
                }
            } else {
                $result[$key_name] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a subscription can be managed via this gateway.
     */
    public static function can_manage_subscription($subscription) {
        if (!self::is_enabled()) {
            return false;
        }
        
        if (!isset($subscription['payment_method']) || $subscription['payment_method'] !== 'stripe') {
            return false;
        }
        
        return true;
    }
}