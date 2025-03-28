<?php
/**
 * PayPal Payment Handler
 *
 * Manages all PayPal-specific payment functionality with database storage.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Load the base gateway class
require_once dirname(__FILE__) . '/../payment-gateway-base.php';

/**
 * Class to handle PayPal payment gateway functionality.
 */
class SwiftSpeed_Siberian_PayPal_Handler extends SwiftSpeed_Siberian_Payment_Gateway_Base {

    /**
     * Gateway ID.
     * 
     * @var string
     */
    protected static $gateway_id = 'paypal';
    
    /**
     * Gateway name.
     * 
     * @var string
     */
    protected static $gateway_name = 'PayPal';
    
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
        if (isset($_GET['swsib_paypal_webhook']) && $_GET['swsib_paypal_webhook'] === '1') {
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
     * Get PayPal API credentials based on mode.
     */
    protected static function get_api_credentials() {
        $settings = self::get_settings();
        $is_sandbox_mode = isset($settings['sandbox_mode']) && $settings['sandbox_mode'];
        
        return array(
            'client_id' => $is_sandbox_mode ? 
                (isset($settings['sandbox_client_id']) ? $settings['sandbox_client_id'] : '') : 
                (isset($settings['live_client_id']) ? $settings['live_client_id'] : ''),
            'client_secret' => $is_sandbox_mode ? 
                (isset($settings['sandbox_client_secret']) ? $settings['sandbox_client_secret'] : '') : 
                (isset($settings['live_client_secret']) ? $settings['live_client_secret'] : ''),
            'webhook_id' => isset($settings['webhook_id']) ? $settings['webhook_id'] : '',
            'is_sandbox_mode' => $is_sandbox_mode
        );
    }

    /**
     * Get PayPal API base URL based on mode.
     */
    protected static function get_api_base_url() {
        $settings = self::get_settings();
        $is_sandbox_mode = isset($settings['sandbox_mode']) && $settings['sandbox_mode'];
        
        return $is_sandbox_mode ? 
            'https://api-m.sandbox.paypal.com' : 
            'https://api-m.paypal.com';
    }

    /**
     * Get PayPal access token.
     */
    protected static function get_access_token() {
        $credentials = self::get_api_credentials();
        $client_id = $credentials['client_id'];
        $client_secret = $credentials['client_secret'];
        
        if (empty($client_id) || empty($client_secret)) {
            self::log_message("Missing PayPal API credentials");
            return false;
        }
        
        $api_url = self::get_api_base_url() . '/v1/oauth2/token';
        
        $response = wp_remote_post(
            $api_url,
            array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.1',
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => 'grant_type=client_credentials',
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error getting PayPal access token: " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (!isset($data->access_token)) {
            self::log_message("Invalid response from PayPal OAuth API: " . $body);
            return false;
        }
        
        return $data->access_token;
    }

    /**
     * Process payment with PayPal.
     * Simplified to prevent duplicate creation and only create subscription after authorization.
     */
    public static function process_payment($payment_data, $checkout_data, $customer_data) {
        // Check if PayPal is enabled
        if (!self::is_enabled()) {
            wp_send_json_error(array('message' => __('PayPal payment gateway is not enabled', 'swiftspeed-siberian')));
            return;
        }
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            wp_send_json_error(array('message' => __('Unable to connect to PayPal. Please try again later.', 'swiftspeed-siberian')));
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
            self::log_message("Processing PayPal payment for plan ID: {$plan_id}");
            
            // Calculate total price including tax if applicable
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/tax-handler.php';
            $tax_amount = SwiftSpeed_Siberian_Tax_Handler::calculate_tax($plan, $customer_data);
            $total_amount = $plan['price'] + $tax_amount;
            
            // Get user ID
            $user_id = isset($checkout_data['user_id']) ? intval($checkout_data['user_id']) : 
                        (is_user_logged_in() ? get_current_user_id() : 0);
            
            // Create a unique session key for reference
            $session_key = uniqid('pp_session_');
            
            // Store the complete checkout data in a transient for retrieval after redirect
            $complete_checkout_data = array_merge($checkout_data, array(
                'user_id' => $user_id,
                'customer_data' => $customer_data,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'timestamp' => time(),
                'session_key' => $session_key
            ));
            
            set_transient('swsib_paypal_checkout_' . $session_key, $complete_checkout_data, 3600); // 1 hour expiration
            self::log_message("Stored checkout data in transient with key: $session_key");
            
            // If user is logged in, also store in user meta as backup
            if ($user_id > 0) {
                update_user_meta($user_id, 'swsib_paypal_checkout_data', $complete_checkout_data);
                self::log_message("Stored checkout data in user meta for user ID: $user_id");
            }
            
            // Get success handler
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/checkout-handler.php';
            
            // Create success and cancel URLs
            $success_url = add_query_arg(
                array(
                    'swsib_checkout_success' => '1',
                    'session_key' => $session_key,
                    'gateway' => 'paypal'
                ),
                SwiftSpeed_Siberian_Checkout_Handler::get_success_redirect_url()
            );
            
            $cancel_url = add_query_arg(
                array(
                    'swsib_checkout_cancel' => '1',
                    'session_key' => $session_key,
                    'gateway' => 'paypal'
                ),
                isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : home_url()
            );
            
            // Get API base URL
            $api_base_url = self::get_api_base_url();
            
            // Convert billing frequency to PayPal interval
            $frequency_details = self::get_paypal_billing_frequency($plan['billing_frequency']);
            
            // Format amount with 2 decimal places
            $formatted_amount = number_format($plan['price'], 2, '.', '');
            $formatted_tax = number_format($tax_amount, 2, '.', '');
            $formatted_total = number_format($total_amount, 2, '.', '');
            
            // Ensure currency is uppercase
            $currency = strtoupper($plan['currency']);
            
            // Create PayPal product first
            $product_data = array(
                'name' => $plan['name'],
                'description' => !empty($plan['description']) ? $plan['description'] : 'Subscription plan with ' . $plan['app_quantity'] . ' app(s)',
                'type' => 'SERVICE',
                'category' => 'SOFTWARE'
            );
            
            $product_response = wp_remote_post(
                $api_base_url . '/v1/catalogs/products',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode($product_data)
                )
            );
            
            if (is_wp_error($product_response)) {
                self::log_message("Error creating PayPal product: " . $product_response->get_error_message());
                wp_send_json_error(array('message' => $product_response->get_error_message()));
                return;
            }
            
            $product_body = wp_remote_retrieve_body($product_response);
            $product_data = json_decode($product_body);
            
            if (!isset($product_data->id)) {
                self::log_message("Invalid response from PayPal Product API: " . $product_body);
                wp_send_json_error(array('message' => __('Unable to create product in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            $product_id = $product_data->id;
            self::log_message("Created PayPal product with ID: " . $product_id);
            
            // Create a billing plan
            $plan_data = array(
                'product_id' => $product_id,
                'name' => $plan['name'],
                'description' => !empty($plan['description']) ? $plan['description'] : 'Subscription plan with ' . $plan['app_quantity'] . ' app(s)',
                'billing_cycles' => array(
                    array(
                        'frequency' => array(
                            'interval_unit' => $frequency_details['interval_unit'],
                            'interval_count' => $frequency_details['interval_count']
                        ),
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0, // Unlimited
                        'pricing_scheme' => array(
                            'fixed_price' => array(
                                'value' => $formatted_total,
                                'currency_code' => $currency
                            )
                        )
                    )
                ),
                'payment_preferences' => array(
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 3 // Max 3 failures before cancellation
                )
            );
            
            $plan_response = wp_remote_post(
                $api_base_url . '/v1/billing/plans',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json',
                        'Prefer' => 'return=representation'
                    ),
                    'body' => json_encode($plan_data)
                )
            );
            
            if (is_wp_error($plan_response)) {
                self::log_message("Error creating PayPal billing plan: " . $plan_response->get_error_message());
                wp_send_json_error(array('message' => $plan_response->get_error_message()));
                return;
            }
            
            $plan_body = wp_remote_retrieve_body($plan_response);
            $paypal_plan = json_decode($plan_body);
            
            if (!isset($paypal_plan->id)) {
                self::log_message("Invalid response from PayPal Plan API: " . $plan_body);
                wp_send_json_error(array('message' => __('Unable to create billing plan in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            $paypal_plan_id = $paypal_plan->id;
            self::log_message("Created PayPal billing plan with ID: " . $paypal_plan_id);
            
            // Create subscription for the plan
            $subscription_data = array(
                'plan_id' => $paypal_plan_id,
                'application_context' => array(
                    'brand_name' => get_bloginfo('name'),
                    'locale' => 'en-US',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => $success_url,
                    'cancel_url' => $cancel_url
                ),
                'custom_id' => $session_key,
                'subscriber' => array(
                    'name' => array(
                        'given_name' => isset($customer_data['first_name']) ? $customer_data['first_name'] : '',
                        'surname' => isset($customer_data['last_name']) ? $customer_data['last_name'] : ''
                    ),
                    'email_address' => isset($customer_data['email']) ? $customer_data['email'] : ''
                )
            );
            
            self::log_message("Creating PayPal subscription with data: " . json_encode($subscription_data));
            
            $subscription_response = wp_remote_post(
                $api_base_url . '/v1/billing/subscriptions',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json',
                        'Prefer' => 'return=representation'
                    ),
                    'body' => json_encode($subscription_data)
                )
            );
            
            if (is_wp_error($subscription_response)) {
                self::log_message("Error creating PayPal subscription: " . $subscription_response->get_error_message());
                wp_send_json_error(array('message' => $subscription_response->get_error_message()));
                return;
            }
            
            $subscription_body = wp_remote_retrieve_body($subscription_response);
            $subscription_result = json_decode($subscription_body);
            
            if (!isset($subscription_result->id) || !isset($subscription_result->links)) {
                self::log_message("Invalid response from PayPal Subscription API: " . $subscription_body);
                wp_send_json_error(array('message' => __('Unable to create subscription in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            // Find the approval URL for the user to confirm the subscription
            $approval_url = '';
            foreach ($subscription_result->links as $link) {
                if ($link->rel === 'approve') {
                    $approval_url = $link->href;
                    break;
                }
            }
            
            if (empty($approval_url)) {
                self::log_message("No approval URL found in PayPal response: " . $subscription_body);
                wp_send_json_error(array('message' => __('Unable to create payment link. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            // Store the PayPal subscription ID in the transient
            $checkout_data_transient = get_transient('swsib_paypal_checkout_' . $session_key);
            if ($checkout_data_transient) {
                $checkout_data_transient['paypal_subscription_id'] = $subscription_result->id;
                set_transient('swsib_paypal_checkout_' . $session_key, $checkout_data_transient, 3600);
            }
            
            // Store PayPal subscription ID in user meta if user is logged in
            if ($user_id > 0) {
                // Store only the subscription ID, not creating an actual subscription yet
                update_user_meta($user_id, 'swsib_paypal_subscription_id', $subscription_result->id);
                self::log_message("Stored PayPal subscription ID in user meta: " . $subscription_result->id);
            }
            
            self::log_message("PayPal subscription created successfully with ID: " . $subscription_result->id);
            self::log_message("Redirecting user to PayPal approval URL: " . $approval_url);
            
            // Return checkout URL (approval URL)
            wp_send_json_success(array(
                'checkout_url' => $approval_url,
                'subscription_id' => $subscription_result->id
            ));
            
        } catch (Exception $e) {
            self::log_message('PayPal payment error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Convert billing frequency to PayPal interval
     */
    private static function get_paypal_billing_frequency($billing_frequency) {
        switch ($billing_frequency) {
            case 'weekly':
                return array(
                    'interval_unit' => 'WEEK',
                    'interval_count' => 1
                );
            case 'monthly':
                return array(
                    'interval_unit' => 'MONTH',
                    'interval_count' => 1
                );
            case 'quarterly':
                return array(
                    'interval_unit' => 'MONTH',
                    'interval_count' => 3
                );
            case 'biannually':
                return array(
                    'interval_unit' => 'MONTH',
                    'interval_count' => 6
                );
            case 'annually':
                return array(
                    'interval_unit' => 'YEAR',
                    'interval_count' => 1
                );
            default:
                return array(
                    'interval_unit' => 'MONTH',
                    'interval_count' => 1
                );
        }
    }

    /**
     * Process subscription renewal with PayPal.
     */
    public static function process_renewal($subscription_id, $payment_data, $customer_data) {
        // Check if PayPal is enabled
        if (!self::is_enabled()) {
            wp_send_json_error(array('message' => __('PayPal payment gateway is not enabled', 'swiftspeed-siberian')));
            return;
        }
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            wp_send_json_error(array('message' => __('Unable to connect to PayPal. Please try again later.', 'swiftspeed-siberian')));
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
            
            // Store renewal data
            $renewal_data = array(
                'subscription_id' => $subscription_id,
                'plan' => $plan,
                'customer_data' => $customer_data,
                'tax_amount' => $tax_amount,
                'total_amount' => $total_amount,
                'user_id' => get_current_user_id()
            );
            
            // Store in user meta
            update_user_meta(get_current_user_id(), 'swsib_paypal_renewal_data', $renewal_data);
            
            // Create a unique key for the session
            $session_key = md5(wp_json_encode($renewal_data) . time());
            set_transient('swsib_paypal_renewal_' . $session_key, $renewal_data, 3600); // 1 hour expiration
            
            // Get success and cancel URLs
            $success_url = add_query_arg(
                array(
                    'swsib_paypal_renewal_success' => '1',
                    'session_key' => $session_key,
                    'gateway' => 'paypal'
                ),
                home_url('/')
            );
            
            $cancel_url = add_query_arg(
                array(
                    'swsib_paypal_renewal_cancel' => '1',
                    'session_key' => $session_key,
                    'gateway' => 'paypal'
                ),
                home_url('/')
            );
            
            // Get API base URL
            $api_base_url = self::get_api_base_url();
            
            // Format amount with 2 decimal places
            $formatted_total = number_format($total_amount, 2, '.', '');
            
            // Ensure currency is uppercase
            $currency = strtoupper($plan['currency']);
            
            // Create PayPal product for the renewal
            $product_data = array(
                'name' => 'Renewal: ' . $plan['name'],
                'description' => !empty($plan['description']) ? $plan['description'] : 'Subscription renewal with ' . $plan['app_quantity'] . ' app(s)',
                'type' => 'SERVICE',
                'category' => 'SOFTWARE'
            );
            
            $product_response = wp_remote_post(
                $api_base_url . '/v1/catalogs/products',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json'
                    ),
                    'body' => json_encode($product_data)
                )
            );
            
            if (is_wp_error($product_response)) {
                self::log_message("Error creating PayPal product for renewal: " . $product_response->get_error_message());
                wp_send_json_error(array('message' => $product_response->get_error_message()));
                return;
            }
            
            $product_body = wp_remote_retrieve_body($product_response);
            $product_data = json_decode($product_body);
            
            if (!isset($product_data->id)) {
                self::log_message("Invalid response from PayPal Product API for renewal: " . $product_body);
                wp_send_json_error(array('message' => __('Unable to create product in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            $product_id = $product_data->id;
            self::log_message("Created PayPal product for renewal with ID: " . $product_id);
            
            // Get frequency details
            $frequency_details = self::get_paypal_billing_frequency($subscription['billing_frequency']);
            
            // Create a billing plan for the renewal
            $plan_data = array(
                'product_id' => $product_id,
                'name' => 'Renewal: ' . $plan['name'],
                'description' => !empty($plan['description']) ? $plan['description'] : 'Subscription renewal with ' . $plan['app_quantity'] . ' app(s)',
                'billing_cycles' => array(
                    array(
                        'frequency' => array(
                            'interval_unit' => $frequency_details['interval_unit'],
                            'interval_count' => $frequency_details['interval_count']
                        ),
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0, // Unlimited
                        'pricing_scheme' => array(
                            'fixed_price' => array(
                                'value' => $formatted_total,
                                'currency_code' => $currency
                            )
                        )
                    )
                ),
                'payment_preferences' => array(
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 3 // Max 3 failures before cancellation
                )
            );
            
            $plan_response = wp_remote_post(
                $api_base_url . '/v1/billing/plans',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json',
                        'Prefer' => 'return=representation'
                    ),
                    'body' => json_encode($plan_data)
                )
            );
            
            if (is_wp_error($plan_response)) {
                self::log_message("Error creating PayPal billing plan for renewal: " . $plan_response->get_error_message());
                wp_send_json_error(array('message' => $plan_response->get_error_message()));
                return;
            }
            
            $plan_body = wp_remote_retrieve_body($plan_response);
            $paypal_plan = json_decode($plan_body);
            
            if (!isset($paypal_plan->id)) {
                self::log_message("Invalid response from PayPal Plan API for renewal: " . $plan_body);
                wp_send_json_error(array('message' => __('Unable to create billing plan in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            $paypal_plan_id = $paypal_plan->id;
            self::log_message("Created PayPal billing plan for renewal with ID: " . $paypal_plan_id);
            
            // Create subscription for the renewed plan
            $subscription_data = array(
                'plan_id' => $paypal_plan_id,
                'application_context' => array(
                    'brand_name' => get_bloginfo('name'),
                    'locale' => 'en-US',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => $success_url,
                    'cancel_url' => $cancel_url
                ),
                'custom_id' => $session_key,
                'subscriber' => array(
                    'name' => array(
                        'given_name' => isset($customer_data['first_name']) ? $customer_data['first_name'] : '',
                        'surname' => isset($customer_data['last_name']) ? $customer_data['last_name'] : ''
                    ),
                    'email_address' => isset($customer_data['email']) ? $customer_data['email'] : ''
                )
            );
            
            self::log_message("Creating PayPal subscription for renewal with data: " . json_encode($subscription_data));
            
            $subscription_response = wp_remote_post(
                $api_base_url . '/v1/billing/subscriptions',
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type' => 'application/json',
                        'Prefer' => 'return=representation'
                    ),
                    'body' => json_encode($subscription_data)
                )
            );
            
            if (is_wp_error($subscription_response)) {
                self::log_message("Error creating PayPal subscription for renewal: " . $subscription_response->get_error_message());
                wp_send_json_error(array('message' => $subscription_response->get_error_message()));
                return;
            }
            
            $subscription_body = wp_remote_retrieve_body($subscription_response);
            $subscription_result = json_decode($subscription_body);
            
            if (!isset($subscription_result->id) || !isset($subscription_result->links)) {
                self::log_message("Invalid response from PayPal Subscription API for renewal: " . $subscription_body);
                wp_send_json_error(array('message' => __('Unable to create subscription in PayPal. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            // Find the approval URL for the user to confirm the subscription
            $approval_url = '';
            foreach ($subscription_result->links as $link) {
                if ($link->rel === 'approve') {
                    $approval_url = $link->href;
                    break;
                }
            }
            
            if (empty($approval_url)) {
                self::log_message("No approval URL found in PayPal response for renewal: " . $subscription_body);
                wp_send_json_error(array('message' => __('Unable to create payment link. Please try again.', 'swiftspeed-siberian')));
                return;
            }
            
            // Update the renewal data in the transient with the PayPal subscription ID
            $renewal_data_transient = get_transient('swsib_paypal_renewal_' . $session_key);
            if ($renewal_data_transient) {
                $renewal_data_transient['paypal_subscription_id'] = $subscription_result->id;
                set_transient('swsib_paypal_renewal_' . $session_key, $renewal_data_transient, 3600);
            }
            
            self::log_message("PayPal renewal subscription created successfully with ID: " . $subscription_result->id);
            self::log_message("Redirecting user to PayPal approval URL for renewal: " . $approval_url);
            
            // Return checkout URL (approval URL)
            wp_send_json_success(array(
                'checkout_url' => $approval_url,
                'subscription_id' => $subscription_result->id
            ));
            
        } catch (Exception $e) {
            self::log_message('PayPal renewal error: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

 /**
 * Handle PayPal webhook.
 */
public static function handle_webhook() {
    // Check if PayPal is enabled
    if (!self::is_enabled()) {
        status_header(403);
        echo json_encode(array('error' => 'PayPal gateway not enabled'));
        exit;
    }
    
    // Get webhook ID
    $credentials = self::get_api_credentials();
    $webhook_id = $credentials['webhook_id'];
    
    // Get input
    $input = file_get_contents('php://input');
    self::log_message("Received PayPal webhook data: " . $input);
    
    try {
        // Parse JSON
        $event_data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
        }
        
        // Get event type
        $event_type = isset($event_data['event_type']) ? $event_data['event_type'] : '';
        
        if (empty($event_type)) {
            throw new Exception('Missing event type in webhook data');
        }
        
        // Get resource type and data
        $resource_type = isset($event_data['resource_type']) ? $event_data['resource_type'] : '';
        $resource = isset($event_data['resource']) ? $event_data['resource'] : array();
        
        if (empty($resource)) {
            throw new Exception('Missing resource data in webhook event');
        }
        
        self::log_message("Processing PayPal webhook event: {$event_type} for resource type: {$resource_type}");
        
        // Handle different event types
        switch ($event_type) {
            case 'BILLING.SUBSCRIPTION.CREATED':
                self::handle_subscription_created($resource);
                break;
                
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
                self::handle_subscription_activated($resource);
                break;
                
            case 'BILLING.SUBSCRIPTION.UPDATED':
                self::handle_subscription_updated($resource);
                break;
            
            case 'BILLING.SUBSCRIPTION.CANCELLED':
                self::handle_subscription_cancelled($resource);
                break;
                
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
                self::handle_subscription_suspended($resource);
                break;
                
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                self::handle_payment_failed($resource);
                break;
                
            case 'PAYMENT.SALE.COMPLETED':
                self::handle_payment_completed($resource);
                break;
                
            case 'PAYMENT.SALE.REFUNDED':
                self::handle_payment_refunded($resource);
                break;
                
            case 'PAYMENT.SALE.DENIED':
                self::handle_payment_denied($resource);
                break;
                
            default:
                self::log_message("Unhandled PayPal webhook event type: {$event_type}");
                break;
        }
        
        status_header(200);
        echo json_encode(array('success' => true));
        exit;
        
    } catch (Exception $e) {
        self::log_message('PayPal webhook error: ' . $e->getMessage());
        status_header(400);
        echo json_encode(array('error' => $e->getMessage()));
        exit;
    }
}

    /**
     * Handle PayPal subscription creation.
     * Modified to ONLY STORE data for later activation, NOT creating the subscription yet.
     */
    private static function handle_subscription_created($subscription_data) {
        self::log_message("Processing PayPal subscription creation: " . json_encode($subscription_data));
        
        // Get subscription ID
        $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
        
        if (empty($paypal_subscription_id)) {
            self::log_message("Missing PayPal subscription ID in webhook data");
            return;
        }
        
        // Get custom ID (session key) from the subscription if available
        $custom_id = isset($subscription_data['custom_id']) ? $subscription_data['custom_id'] : '';
        
        // Get subscriber info
        $subscriber = isset($subscription_data['subscriber']) ? $subscription_data['subscriber'] : array();
        $email = isset($subscriber['email_address']) ? $subscriber['email_address'] : '';
        
        // Find user by email if available
        $user_id = 0;
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = $user->ID;
                self::log_message("Found user by email: {$email}, user ID: {$user_id}");
            }
        }
        
        // Note: We DO NOT create a subscription here
        // We'll only store necessary data and wait for the ACTIVATED event
        self::log_message("Subscription creation recorded, waiting for activation event");
    }

    /**
     * Handle PayPal subscription activation.
     * This is the key event where we actually create the subscription.
     */
    private static function handle_subscription_activated($subscription_data) {
        self::log_message("Processing PayPal subscription activation: " . json_encode($subscription_data));
        
        // Get subscription ID
        $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
        
        if (empty($paypal_subscription_id)) {
            self::log_message("Missing PayPal subscription ID in webhook data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Check if subscription already exists to prevent duplicates
        $existing_subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
        
        if ($existing_subscription) {
            self::log_message("Subscription already exists with PayPal ID: {$paypal_subscription_id}, ensuring it's active");
            
            // Update the status to active if not already
            if ($existing_subscription['status'] !== 'active') {
                $db->update_subscription_status($existing_subscription['id'], 'active');
                self::log_message("Updated subscription {$existing_subscription['id']} status to active");
            }
            
            // Ensure SiberianCMS is activated
            self::activate_siberian_subscription($existing_subscription['id']);
            return;
        }
        
        // Get custom ID (session key) from the subscription if available
        $custom_id = isset($subscription_data['custom_id']) ? $subscription_data['custom_id'] : '';
        
        // Get subscriber info
        $subscriber = isset($subscription_data['subscriber']) ? $subscription_data['subscriber'] : array();
        $email = isset($subscriber['email_address']) ? $subscriber['email_address'] : '';
        
        // Find user by email if available
        $user_id = 0;
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $user_id = $user->ID;
                self::log_message("Found user by email: {$email}, user ID: {$user_id}");
            }
        }
        
        // If we have a custom ID (session key), get the checkout data from the transient
        if (!empty($custom_id)) {
            $checkout_data = get_transient('swsib_paypal_checkout_' . $custom_id);
            
            if ($checkout_data) {
                self::log_message("Found checkout data in transient with key: {$custom_id}");
                
                // Now that payment is authorized, create the subscription
                self::create_subscription_from_checkout_data($checkout_data, $paypal_subscription_id);
            } else {
                self::log_message("No checkout data found with key: {$custom_id}");
                
                // Try to find in user meta if user ID is available
                if ($user_id > 0) {
                    $checkout_data = get_user_meta($user_id, 'swsib_paypal_checkout_data', true);
                    
                    if ($checkout_data) {
                        self::log_message("Found checkout data in user meta for user ID: {$user_id}");
                        self::create_subscription_from_checkout_data($checkout_data, $paypal_subscription_id);
                    } else {
                        self::log_message("No checkout data found in user meta for user ID: {$user_id}");
                    }
                }
            }
        } else {
            self::log_message("No custom ID found in subscription data");
        }
    }
    
    /**
     * Create subscription from checkout data after PayPal authorization
     */
    private static function create_subscription_from_checkout_data($checkout_data, $paypal_subscription_id) {
        // Get plan details
        $plan_id = isset($checkout_data['plan_id']) ? $checkout_data['plan_id'] : '';
        
        if (empty($plan_id)) {
            self::log_message("No plan ID found in checkout data");
            return false;
        }
        
        // Get plan
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
            self::log_message("Plan not found for ID: {$plan_id}");
            return false;
        }
        
        // Get customer data
        $customer_data = isset($checkout_data['customer_data']) ? $checkout_data['customer_data'] : array();
        
        // Create subscription record
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        // Create with active status since PayPal has activated it
        $subscription_id = SwiftSpeed_Siberian_Subscription_Handler::create_subscription(
            $plan, 
            $checkout_data, 
            $paypal_subscription_id, 
            $customer_data,
            'paypal'
        );
        
        if ($subscription_id) {
            self::log_message("Created subscription with ID: {$subscription_id} for PayPal subscription: {$paypal_subscription_id}");
            
            // Activate in SiberianCMS
            self::activate_siberian_subscription($subscription_id);
            
            // Store success data
            $user_id = isset($checkout_data['user_id']) ? intval($checkout_data['user_id']) : 0;
            if ($user_id > 0) {
                update_user_meta($user_id, 'swsib_checkout_success_data', array(
                    'subscription_id' => $subscription_id,
                    'timestamp' => time()
                ));
            }
            
            return true;
        } else {
            self::log_message("Failed to create subscription record for PayPal subscription: {$paypal_subscription_id}");
            return false;
        }
    }

    /**
     * Activate subscription in SiberianCMS
     * This is a critical function to ensure SiberianCMS integration works properly
     */
    public static function activate_siberian_subscription($subscription_id) {
        self::log_message("Activating subscription {$subscription_id} in SiberianCMS");
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        if (!$subscription) {
            self::log_message("ERROR: Subscription not found: {$subscription_id}");
            return false;
        }
        
        // Load the subscription handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        // Prepare checkout data for activation
        $checkout_data = array(
            'admin_id' => $subscription['admin_id'],
            'admin_email' => $subscription['admin_email'],
            'application_id' => $subscription['application_id'],
            'siberian_sub_id' => $subscription['siberian_plan_id'],
            'user_id' => $subscription['user_id']
        );
        
        // Call the activation method
        $result = SwiftSpeed_Siberian_Subscription_Handler::activate_siberian_subscription(
            $checkout_data,
            null,
            $subscription_id
        );
        
        if ($result) {
            self::log_message("Successfully activated subscription {$subscription_id} in SiberianCMS");
            return true;
        } else {
            self::log_message("ERROR: Failed to activate subscription {$subscription_id} in SiberianCMS");
            return false;
        }
    }

  /**
 * Handle PayPal subscription update.
 * Modified to handle status changes more accurately.
 */
private static function handle_subscription_updated($subscription_data) {
    self::log_message("Processing PayPal subscription update: " . json_encode($subscription_data));
    
    // Get subscription ID
    $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
    
    if (empty($paypal_subscription_id)) {
        self::log_message("Missing PayPal subscription ID in webhook data");
        return;
    }
    
    // Get subscription status
    $status = isset($subscription_data['status']) ? $subscription_data['status'] : '';
    
    if (empty($status)) {
        self::log_message("Missing status in PayPal subscription data");
        return;
    }
    
    // Get DB module
    $db = self::get_db_module();
    
    // Find subscription in our system
    $subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
    
    if (!$subscription) {
        self::log_message("No subscription found with PayPal ID: {$paypal_subscription_id}");
        return;
    }
    
    // Get current status
    $current_status = $subscription['status'];
    $new_status = $current_status; // Default to no change
    
    // Map PayPal status to our status
    switch ($status) {
        case 'ACTIVE':
            if ($current_status !== 'active') {
                $new_status = 'active';
            }
            break;
            
        case 'SUSPENDED':
            // Mark as expired if currently active
            if ($current_status === 'active') {
                $new_status = 'expired';
            }
            break;
            
        case 'CANCELLED':
            // IMPORTANT: For cancellations, set to pending-cancellation and track source
            // Check if we have billing_info with next_billing_time
            $billing_info = isset($subscription_data['billing_info']) ? $subscription_data['billing_info'] : array();
            $next_billing_time = isset($billing_info['next_billing_time']) ? $billing_info['next_billing_time'] : '';
            
            if ($current_status !== 'pending-cancellation' && $current_status !== 'cancelled') {
                $new_status = 'pending-cancellation';
                
                // Update with cancellation source and next billing date
                $update_data = array(
                    'status' => 'pending-cancellation',
                    'cancellation_source' => 'paypal'
                );
                
                if (!empty($next_billing_time)) {
                    $update_data['next_billing_date'] = $next_billing_time;
                }
                
                $db->update_subscription($subscription['id'], $update_data);
                
                self::log_message("Set subscription {$subscription['id']} to pending-cancellation with source: paypal");
                return; // Exit early as we've already updated
            }
            break;
            
        case 'EXPIRED':
            $new_status = 'expired';
            break;
            
        default:
            self::log_message("Unhandled PayPal subscription status: {$status}");
            break;
    }
    
    // Update status if changed
    if ($new_status !== $current_status) {
        self::log_message("Updating subscription {$subscription['id']} status from {$current_status} to {$new_status}");
        
        $db->update_subscription_status($subscription['id'], $new_status);
        
        // Handle SiberianCMS integration
        if ($new_status === 'active' && $current_status !== 'active') {
            // Activate in SiberianCMS
            self::activate_siberian_subscription($subscription['id']);
        } elseif ($new_status === 'cancelled' && $current_status !== 'cancelled') {
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
        
        // Trigger event for other components
        do_action('swsib_paypal_subscription_updated', $paypal_subscription_id, $subscription_data);
    } else {
        self::log_message("No status change needed for subscription {$subscription['id']}");
    }
}


/**
 * Handle PayPal subscription cancellation.
 * Fixed to correctly set status to pending-cancellation with proper cancellation_source.
 */
private static function handle_subscription_cancelled($subscription_data) {
    self::log_message("Processing PayPal subscription cancellation: " . json_encode($subscription_data));
    
    // Get subscription ID
    $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
    
    if (empty($paypal_subscription_id)) {
        self::log_message("Missing PayPal subscription ID in webhook data");
        return;
    }
    
    // Get DB module
    $db = self::get_db_module();
    
    // Find subscription in our system
    $subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
    
    if (!$subscription) {
        self::log_message("No subscription found with PayPal ID: {$paypal_subscription_id}");
        return;
    }
    
    // Get current subscription status
    $current_status = $subscription['status'];
    
    // If already cancelled or pending cancellation, no need to update
    if ($current_status === 'cancelled' || $current_status === 'pending-cancellation') {
        self::log_message("Subscription {$subscription['id']} is already {$current_status}, no status change needed");
        return;
    }
    
    // Check subscription status from PayPal
    $paypal_status = isset($subscription_data['status']) ? $subscription_data['status'] : '';
    self::log_message("PayPal subscription status: {$paypal_status}");
    
    // Check if we have billing_info with next_billing_time
    $billing_info = isset($subscription_data['billing_info']) ? $subscription_data['billing_info'] : array();
    $next_billing_time = isset($billing_info['next_billing_time']) ? $billing_info['next_billing_time'] : '';
    
    // Build update data
    $update_data = array(
        'cancellation_source' => 'paypal'  // Mark this as PayPal-initiated cancellation
    );
    
    // Store next billing date if available
    if (!empty($next_billing_time)) {
        $update_data['next_billing_date'] = $next_billing_time;
        self::log_message("Setting next billing date: {$next_billing_time}");
    }
    
    // If PayPal status is CANCELLED, set to pending cancellation
    if ($paypal_status === 'CANCELLED') {
        $update_data['status'] = 'pending-cancellation';
        
        // Log the action
        self::log_message("Setting subscription {$subscription['id']} to pending-cancellation from PayPal webhook");
        
        // Update the subscription
        $result = $db->update_subscription($subscription['id'], $update_data);
        
        if ($result) {
            self::log_message("Successfully set subscription {$subscription['id']} to pending-cancellation");
            self::log_message("Event: PayPal subscription cancelled: {$paypal_subscription_id}");
            
            // DO NOT run SiberianCMS cancellation yet - that happens when the end date is reached
        } else {
            self::log_message("Failed to update subscription {$subscription['id']} status");
        }
    } else {
        self::log_message("Unexpected PayPal status '{$paypal_status}' for cancelled webhook event");
    }
}

    /**
     * Handle PayPal subscription suspension.
     */
    private static function handle_subscription_suspended($subscription_data) {
        self::log_message("Processing PayPal subscription suspension: " . json_encode($subscription_data));
        
        // Get subscription ID
        $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
        
        if (empty($paypal_subscription_id)) {
            self::log_message("Missing PayPal subscription ID in webhook data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription in our system
        $subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
        
        if (!$subscription) {
            self::log_message("No subscription found with PayPal ID: {$paypal_subscription_id}");
            return;
        }
        
        // Only update if currently active
        if ($subscription['status'] === 'active') {
            // Set grace period
            $grace_end = new DateTime();
            $grace_end->add(new DateInterval('P7D')); // 7 days grace period
            
            // Update to expired with grace period
            $db->update_subscription($subscription['id'], array(
                'status' => 'expired',
                'grace_period_end' => $grace_end->format('Y-m-d H:i:s')
            ));
            
            self::log_message("Updated subscription {$subscription['id']} status to expired due to suspension");
            
            // Note: We don't deactivate in SiberianCMS yet, as the subscription is in grace period
        } else {
            self::log_message("Subscription {$subscription['id']} is not active, no status change needed");
        }
        
        // Trigger event for other components
        do_action('swsib_paypal_subscription_suspended', $paypal_subscription_id, $subscription_data);
    }

    /**
     * Handle PayPal payment failure.
     */
    private static function handle_payment_failed($subscription_data) {
        self::log_message("Processing PayPal payment failure: " . json_encode($subscription_data));
        
        // Get subscription ID
        $paypal_subscription_id = isset($subscription_data['id']) ? $subscription_data['id'] : '';
        
        if (empty($paypal_subscription_id)) {
            self::log_message("Missing PayPal subscription ID in webhook data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription in our system
        $subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
        
        if (!$subscription) {
            self::log_message("No subscription found with PayPal ID: {$paypal_subscription_id}");
            return;
        }
        
        // Get current retry count
        $retry_count = isset($subscription['retry_count']) ? intval($subscription['retry_count']) : 0;
        $retry_count++;
        
        // Set retry period (3 days)
        $retry_end = new DateTime();
        $retry_end->add(new DateInterval('P3D'));
        
        // Update subscription with payment failure info
        $update_data = array(
            'payment_status' => 'failed',
            'retry_count' => $retry_count,
            'retry_period_end' => $retry_end->format('Y-m-d H:i:s'),
            'last_payment_error' => current_time('mysql')
        );
        
        // If this is the third retry or more, set to expired with grace period
        if ($retry_count >= 3) {
            $update_data['status'] = 'expired';
            
            // Set grace period of 7 days
            $grace_end = new DateTime();
            $grace_end->add(new DateInterval('P7D'));
            $update_data['grace_period_end'] = $grace_end->format('Y-m-d H:i:s');
            
            self::log_message("Subscription {$subscription['id']} has failed {$retry_count} times, marking as expired with grace period");
        } else {
            self::log_message("Subscription {$subscription['id']} payment failed, retry #{$retry_count}");
        }
        
        // Update the subscription
        $db->update_subscription($subscription['id'], $update_data);
        
        // If expired, set status to pending-cancellation in PayPal
        if ($retry_count >= 3) {
            // Only attempt to cancel in PayPal if subscription is still active
            if (isset($subscription_data['status']) && $subscription_data['status'] === 'ACTIVE') {
                self::cancel_paypal_subscription($paypal_subscription_id);
            }
        }
        
        // Trigger event for other components
        do_action('swsib_paypal_payment_failed', $paypal_subscription_id, $subscription_data);
    }

    /**
     * Handle PayPal payment completion.
     */
    private static function handle_payment_completed($payment_data) {
        self::log_message("Processing PayPal payment completion: " . json_encode($payment_data));
        
        // Get billing agreement ID or subscription ID
        $agreement_id = isset($payment_data['billing_agreement_id']) ? $payment_data['billing_agreement_id'] : '';
        
        if (empty($agreement_id)) {
            self::log_message("No billing agreement ID found in payment data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription in our system
        $subscription = $db->get_subscription_by_payment_id($agreement_id, 'paypal');
        
        if (!$subscription) {
            self::log_message("No subscription found with PayPal billing agreement ID: {$agreement_id}");
            return;
        }
        
        // Reset retry count and update payment status
        $update_data = array(
            'payment_status' => 'paid',
            'retry_count' => 0,
            'retry_period_end' => null,
            'last_payment_date' => current_time('mysql')
        );
        
        // If subscription was expired, reactivate it
        if ($subscription['status'] === 'expired') {
            $update_data['status'] = 'active';
            
            // Calculate new end date
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
            $update_data['end_date'] = SwiftSpeed_Siberian_Subscription_Handler::calculate_end_date($subscription['billing_frequency']);
            
            self::log_message("Reactivating expired subscription {$subscription['id']} after successful payment");
        }
        
        // Update the subscription
        $db->update_subscription($subscription['id'], $update_data);
        
        // If reactivated or status is now active, update in SiberianCMS
        if ($subscription['status'] === 'expired' || $update_data['status'] === 'active') {
            self::activate_siberian_subscription($subscription['id']);
            
            // Trigger renewal event
            do_action('swsib_paypal_subscription_renewed', $agreement_id, $payment_data);
        } else {
            // Regular payment event
            do_action('swsib_paypal_payment_completed', $agreement_id, $payment_data);
        }
    }

    /**
     * Handle PayPal payment refund.
     */
    private static function handle_payment_refunded($payment_data) {
        self::log_message("Processing PayPal payment refund: " . json_encode($payment_data));
        
        // Get billing agreement ID or subscription ID
        $agreement_id = isset($payment_data['billing_agreement_id']) ? $payment_data['billing_agreement_id'] : '';
        
        if (empty($agreement_id)) {
            self::log_message("No billing agreement ID found in refund data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription in our system
        $subscription = $db->get_subscription_by_payment_id($agreement_id, 'paypal');
        
        if (!$subscription) {
            self::log_message("No subscription found with PayPal billing agreement ID: {$agreement_id}");
            return;
        }
        
        // If it's a refund, immediately cancel the subscription
        if ($subscription['status'] !== 'cancelled') {
            $db->update_subscription_status($subscription['id'], 'cancelled');
            
            self::log_message("Cancelled subscription {$subscription['id']} due to refund");
            
            // Also deactivate in SiberianCMS
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
            SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
                $subscription['admin_id'],
                $subscription['admin_email'],
                $subscription['application_id'],
                $subscription['siberian_plan_id'],
                'cancel'
            );
            
            // Cancel in PayPal if not already cancelled
            self::cancel_paypal_subscription($agreement_id);
            
            // Trigger event
            do_action('swsib_paypal_payment_refunded', $agreement_id, $payment_data);
        } else {
            self::log_message("Subscription {$subscription['id']} is already cancelled");
        }
    }

    /**
     * Handle PayPal payment denial.
     */
    private static function handle_payment_denied($payment_data) {
        self::log_message("Processing PayPal payment denial: " . json_encode($payment_data));
        
        // Get billing agreement ID or subscription ID
        $agreement_id = isset($payment_data['billing_agreement_id']) ? $payment_data['billing_agreement_id'] : '';
        
        if (empty($agreement_id)) {
            self::log_message("No billing agreement ID found in denied payment data");
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Find subscription in our system
        $subscription = $db->get_subscription_by_payment_id($agreement_id, 'paypal');
        
        if (!$subscription) {
            self::log_message("No subscription found with PayPal billing agreement ID: {$agreement_id}");
            return;
        }
        
        // Get current retry count
        $retry_count = isset($subscription['retry_count']) ? intval($subscription['retry_count']) : 0;
        $retry_count++;
        
        // Set retry period (3 days)
        $retry_end = new DateTime();
        $retry_end->add(new DateInterval('P3D'));
        
        // Update subscription with payment failure info
        $update_data = array(
            'payment_status' => 'failed',
            'retry_count' => $retry_count,
            'retry_period_end' => $retry_end->format('Y-m-d H:i:s'),
            'last_payment_error' => current_time('mysql')
        );
        
        // If this is the third retry or more, set to expired with grace period
        if ($retry_count >= 3) {
            $update_data['status'] = 'expired';
            
            // Set grace period of 7 days
            $grace_end = new DateTime();
            $grace_end->add(new DateInterval('P7D'));
            $update_data['grace_period_end'] = $grace_end->format('Y-m-d H:i:s');
            
            self::log_message("Subscription {$subscription['id']} has failed {$retry_count} times, marking as expired with grace period");
            
            // Cancel in PayPal
            self::cancel_paypal_subscription($agreement_id);
        } else {
            self::log_message("Subscription {$subscription['id']} payment denied, retry #{$retry_count}");
        }
        
        // Update the subscription
        $db->update_subscription($subscription['id'], $update_data);
        
        // Trigger event
        do_action('swsib_paypal_payment_denied', $agreement_id, $payment_data);
    }

    /**
     * Get payment portal - PayPal doesn't have a portal so we return false.
     */
    public static function get_payment_portal($subscription_id, $subscription) {
        // PayPal doesn't have a customer portal like Stripe, so we return false
        return false;
    }

    /**
     * Cancel PayPal subscription.
     */
    public static function cancel_paypal_subscription($paypal_subscription_id) {
        self::log_message("Cancelling PayPal subscription: {$paypal_subscription_id}");
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            self::log_message("Failed to get PayPal access token for cancellation");
            return false;
        }
        
        // Get API base URL
        $api_base_url = self::get_api_base_url();
        
        // Create cancellation request
        $cancellation_data = array(
            'reason' => 'Cancelled by admin or due to failed payments'
        );
        
        $response = wp_remote_post(
            $api_base_url . '/v1/billing/subscriptions/' . $paypal_subscription_id . '/cancel',
            array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($cancellation_data)
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error cancelling PayPal subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            self::log_message("Successfully cancelled PayPal subscription: {$paypal_subscription_id}");
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            self::log_message("Failed to cancel PayPal subscription. Response code: {$response_code}, body: {$body}");
            return false;
        }
    }
}