<?php
/**
 * Checkout Handler - Fixed Version for SiberianCMS Integration
 *
 * Manages checkout processes and URL handling for subscription creation.
 * Fixed application_id tracking to prevent duplicate subscriptions.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle checkout processes.
 */
class SwiftSpeed_Siberian_Checkout_Handler {

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
        
        // Register checkout processing hooks
        add_action('template_redirect', array(__CLASS__, 'process_checkout_token'));
        add_action('init', array(__CLASS__, 'handle_checkout_redirect'));
        
        // Add popup for subscription purchases
        add_action('wp_footer', array(__CLASS__, 'enqueue_post_purchase_popup'));
        
        // Register shortcodes
        add_shortcode('swsib_checkout', array(__CLASS__, 'checkout_shortcode'));
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
     * Process checkout token during template_redirect.
     * Enhanced to properly track application_id and prevent duplicates.
     */
    public static function process_checkout_token() {
        if (!isset($_GET['swsib_token'])) {
            return;
        }
        
        $token = sanitize_text_field($_GET['swsib_token']);
        self::log_message("Processing token: $token");
        
        $data = get_transient($token);
        if (!$data) {
            self::log_message("Token data not found or expired");
            wp_redirect(home_url('/'));
            exit;
        }
        
        self::log_message("Token data retrieved: " . print_r($data, true));
        
        // Log in the user if not already logged in
        if (isset($data['user_id'])) {
            $user = get_user_by('ID', $data['user_id']);
            if ($user) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                self::log_message("User authenticated: {$user->ID}");
            } else {
                self::log_message("User not found: {$data['user_id']}");
            }
        }
        
        // Store checkout data in user meta - more reliable than session
        if (isset($data['user_id'])) {
            update_user_meta($data['user_id'], 'swsib_checkout_data', $data);
            self::log_message("Checkout data stored in user meta");
        }
        
        // Check if we're on the checkout page
        $checkout_page_id = self::get_checkout_page_id();
        
        // If checkout page isn't set or we're not on it, redirect
        if (!$checkout_page_id || !is_page($checkout_page_id)) {
            if ($checkout_page_id) {
                self::log_message("Redirecting to checkout page ID: $checkout_page_id");
                wp_redirect(get_permalink($checkout_page_id));
                exit;
            } else {
                // Create a default checkout page if not set
                self::create_default_checkout_page();
            }
        }
    }

    /**
     * Handle checkout success/cancel redirects.
     * Simplified to use webhook data for subscription creation.
     */
    public static function handle_checkout_redirect() {
        // Check if this is a successful redirect from payment gateway
        if (isset($_GET['swsib_checkout_success']) && $_GET['swsib_checkout_success'] === '1') {
            self::log_message("Detected successful checkout redirect. Processing...");
            self::handle_checkout_success_redirect();
        }
        
        // Check if this is a renewal success redirect
        if (isset($_GET['swsib_stripe_renewal_success']) && $_GET['swsib_stripe_renewal_success'] === '1') {
            self::log_message("Detected successful renewal redirect. Processing...");
            self::handle_renewal_success_redirect();
        }
        
        // Check if this is a cancellation redirect
        if (isset($_GET['swsib_checkout_cancel']) && $_GET['swsib_checkout_cancel'] === '1') {
            self::log_message("Detected cancelled checkout redirect.");
            // Currently no special handling needed for cancellation
        }
        
        // Check if this is a return from Stripe portal
        if (isset($_GET['swsib_stripe_portal_return']) && $_GET['swsib_stripe_portal_return'] === '1') {
            self::log_message("User returned from Stripe portal");
            // You might want to refresh subscription data or show a message
        }
    }

/**
 * Handle successful redirect from payment gateway checkout.
 * Enhanced to better handle different payment gateways while preserving popup functionality.
 */
private static function handle_checkout_success_redirect() {
    $session_key = isset($_GET['session_key']) ? sanitize_text_field($_GET['session_key']) : '';
    $gateway = isset($_GET['gateway']) ? sanitize_text_field($_GET['gateway']) : 'stripe';
    
    self::log_message("Processing checkout success redirect with session key: $session_key and gateway: $gateway");
    
    // Check if user is logged in - redirect to login if not, rather than returning
    if (!is_user_logged_in()) {
        self::log_message("User is not logged in, redirecting to login");
        wp_redirect(wp_login_url(add_query_arg(array('redirect_to' => $_SERVER['REQUEST_URI']))));
        exit;
    }
    
    $user_id = get_current_user_id();
    
    // Get success data
    $success_data = get_user_meta($user_id, 'swsib_checkout_success_data', true);
    
    if (!$success_data) {
        self::log_message("No success data found for user ID: $user_id");
        
        // Wait a moment for webhook to process
        sleep(2);
        
        // Check again
        $success_data = get_user_meta($user_id, 'swsib_checkout_success_data', true);
        
        if (!$success_data) {
            self::log_message("Still no success data found after delay");
            
            // If we still don't have success data and we have a session key, try to get checkout data
            if (!empty($session_key)) {
                // Use the right transient key based on the gateway
                $transient_key = 'swsib_' . $gateway . '_checkout_' . $session_key;
                self::log_message("Looking for checkout data in transient: $transient_key");
                
                $checkout_data = get_transient($transient_key);
                
                if (!$checkout_data) {
                    self::log_message("No checkout data found in transient, trying user meta");
                    
                    // Try user meta as fallback
                    $checkout_data = get_user_meta($user_id, 'swsib_' . $gateway . '_checkout_data', true);
                    
                    if (!$checkout_data) {
                        self::log_message("No checkout data found in user meta either");
                        wp_redirect(self::get_success_redirect_url());
                        exit;
                    }
                }
                
                self::log_message("Found checkout data: " . print_r($checkout_data, true));
                
                // For PayPal, we need special handling since webhooks might not have processed yet
                if ($gateway === 'paypal') {
                    self::log_message("Processing PayPal-specific redirect logic");
                    
                    // Look for PayPal subscription ID
                    $paypal_subscription_id = '';
                    
                    if (isset($checkout_data['paypal_subscription_id'])) {
                        $paypal_subscription_id = $checkout_data['paypal_subscription_id'];
                        self::log_message("Found PayPal subscription ID in checkout data: $paypal_subscription_id");
                    } else {
                        // Try to get from user meta
                        $paypal_subscription_id = get_user_meta($user_id, 'swsib_paypal_subscription_id', true);
                        self::log_message("Found PayPal subscription ID in user meta: $paypal_subscription_id");
                    }
                    
                    if (!empty($paypal_subscription_id)) {
                        // Get DB module
                        $db = self::get_db_module();
                        
                        // Check if subscription already exists - use BOTH payment_id AND payment_method
                        $existing_subscription = $db->get_subscription_by_payment_id($paypal_subscription_id, 'paypal');
                        
                        if ($existing_subscription) {
                            self::log_message("Found existing subscription with PayPal ID: $paypal_subscription_id");
                            
                            // Ensure subscription is active and activated in SiberianCMS
                            if ($existing_subscription['status'] !== 'active') {
                                self::log_message("Setting subscription to active status");
                                $db->update_subscription_status($existing_subscription['id'], 'active');
                            }
                            
                            // Activate in SiberianCMS
                            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-handler.php';
                            SwiftSpeed_Siberian_PayPal_Handler::activate_siberian_subscription($existing_subscription['id']);
                            
                            // Store success data for redirect
                            $success_data = array(
                                'subscription_id' => $existing_subscription['id'],
                                'plan_name' => isset($existing_subscription['plan_name']) ? $existing_subscription['plan_name'] : 'Subscription',
                                'timestamp' => time()
                            );
                            
                            update_user_meta($user_id, 'swsib_checkout_success_data', $success_data);
                        } 
                        else {
                            // Subscription doesn't exist yet, we need to create it
                            self::log_message("No subscription found with PayPal ID: $paypal_subscription_id, creating from checkout data");
                            
                            // Get plan details
                            $plan_id = isset($checkout_data['plan_id']) ? $checkout_data['plan_id'] : '';
                            
                            if (empty($plan_id)) {
                                self::log_message("No plan ID found in checkout data");
                                wp_redirect(self::get_success_redirect_url());
                                exit;
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
                                self::log_message("Plan not found for ID: $plan_id");
                                wp_redirect(self::get_success_redirect_url());
                                exit;
                            }
                            
                            // Get customer data
                            $customer_data = isset($checkout_data['customer_data']) ? $checkout_data['customer_data'] : array();
                            
                            // Create subscription
                            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
                            
                            $subscription_id = SwiftSpeed_Siberian_Subscription_Handler::create_subscription(
                                $plan,
                                $checkout_data,
                                $paypal_subscription_id,
                                $customer_data,
                                'paypal' // Explicitly set payment method to avoid conflicts
                            );
                            
                            if ($subscription_id) {
                                self::log_message("Created subscription with ID: $subscription_id");
                                
                                // Update status to active
                                $db->update_subscription_status($subscription_id, 'active');
                                
                                // Activate in SiberianCMS
                                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-handler.php';
                                SwiftSpeed_Siberian_PayPal_Handler::activate_siberian_subscription($subscription_id);
                                
                                // Store success data for redirect
                                $success_data = array(
                                    'subscription_id' => $subscription_id,
                                    'plan_name' => isset($plan['name']) ? $plan['name'] : 'Subscription',
                                    'timestamp' => time()
                                );
                                
                                update_user_meta($user_id, 'swsib_checkout_success_data', $success_data);
                            } else {
                                self::log_message("Failed to create subscription");
                            }
                        }
                    }
                }
                // Handle Stripe-specific logic (if not already handled by webhooks)
                else if ($gateway === 'stripe') {
                    self::log_message("Processing Stripe-specific redirect logic");
                    
                    // Look for Stripe subscription ID
                    $stripe_session_id = '';
                    
                    if (isset($checkout_data['stripe_session_id'])) {
                        $stripe_session_id = $checkout_data['stripe_session_id'];
                        self::log_message("Found Stripe session ID in checkout data: $stripe_session_id");
                        
                        // Stripe normally relies on webhooks for subscription creation
                        // Here we're just checking if those webhooks have fired properly
                        
                        // If there's a subscription ID in user meta from a completed webhook, use that
                        $stripe_sub_id = get_user_meta($user_id, 'swsib_stripe_subscription_id', true);
                        
                        if (!empty($stripe_sub_id)) {
                            self::log_message("Found Stripe subscription ID in user meta: $stripe_sub_id");
                            
                            // Get DB module
                            $db = self::get_db_module();
                            
                            // Check if subscription already exists - use BOTH payment_id AND payment_method
                            $existing_subscription = $db->get_subscription_by_payment_id($stripe_sub_id, 'stripe');
                            
                            if ($existing_subscription) {
                                self::log_message("Found existing subscription with Stripe ID: $stripe_sub_id");
                                
                                // Store success data for redirect
                                $success_data = array(
                                    'subscription_id' => $existing_subscription['id'],
                                    'plan_name' => isset($existing_subscription['plan_name']) ? $existing_subscription['plan_name'] : 'Subscription',
                                    'timestamp' => time()
                                );
                                
                                update_user_meta($user_id, 'swsib_checkout_success_data', $success_data);
                            }
                        }
                    }
                }
                
                // Look for recent subscriptions as fallback
                if (!$success_data) {
                    $db = self::get_db_module();
                    
                    // Filter by the correct payment method to avoid conflicts
                    $recent_subscriptions = $db->get_all_subscriptions(array(
                        'user_id' => $user_id,
                        'payment_method' => $gateway // Filter by gateway
                    ));
                    
                    // Sort by creation date (newest first)
                    usort($recent_subscriptions, function($a, $b) {
                        return strtotime($b['created_at']) - strtotime($a['created_at']);
                    });
                    
                    if (!empty($recent_subscriptions)) {
                        $recent = $recent_subscriptions[0];
                        if (strtotime($recent['created_at']) > time() - 300) { // Created in last 5 minutes
                            self::log_message("Found recent subscription {$recent['id']} as fallback");
                            
                            // Get the plan name if not explicitly stored
                            $plan_name = 'Subscription';
                            if (isset($checkout_data['plan_id'])) {
                                $options = get_option('swsib_options', array());
                                if (isset($options['subscription']['plans'])) {
                                    foreach ($options['subscription']['plans'] as $p) {
                                        if ($p['id'] === $checkout_data['plan_id']) {
                                            $plan_name = $p['name'];
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // Store success data for redirect
                            $success_data = array(
                                'subscription_id' => $recent['id'],
                                'plan_name' => $plan_name,
                                'timestamp' => time()
                            );
                            
                            // Store for future reference
                            update_user_meta($user_id, 'swsib_checkout_success_data', $success_data);
                        }
                    }
                }
            }
        }
    }
    
    // If we have success data, redirect to specific subscription
    if ($success_data && !empty($success_data['subscription_id'])) {
        // Check if this is a renewal
        $is_renewal = isset($success_data['is_renewal']) && $success_data['is_renewal'];
        
        $success_url = add_query_arg(
            array(
                'swsib_success' => '1',
                'subscription_id' => $success_data['subscription_id'],
                'renewed' => $is_renewal ? '1' : '0'
            ),
            self::get_success_redirect_url()
        );
        
        self::log_message("Redirecting to success page: $success_url");
        
        // Clean up success data
        delete_user_meta($user_id, 'swsib_checkout_success_data');
        
        // Redirect
        wp_redirect($success_url);
        exit;
    }
    
    // Otherwise just redirect to subscriptions page
    self::log_message("Redirecting to generic success page");
    wp_redirect(self::get_success_redirect_url());
    exit;
}

    /**
     * Handle successful redirect from renewal process.
     */
    private static function handle_renewal_success_redirect() {
        $session_key = isset($_GET['session_key']) ? sanitize_text_field($_GET['session_key']) : '';
        self::log_message("Processing renewal success redirect with session key: $session_key");
        
        // If we have a session key, try to get renewal data from transient
        if (!empty($session_key)) {
            $renewal_data = get_transient('swsib_stripe_renewal_' . $session_key);
            
            if ($renewal_data) {
                self::log_message("Found renewal data in transient for session key: $session_key");
                
                // Process the renewal data directly
                $user_id = isset($renewal_data['user_id']) ? $renewal_data['user_id'] : 0;
                
                if ($user_id) {
                    // Include needed handlers
                    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
                    
                    // Reactivate the subscription
                    $result = SwiftSpeed_Siberian_Subscription_Handler::reactivate_expired_subscription(
                        $renewal_data['subscription_id']
                    );
                    
                    if ($result) {
                        self::log_message("Successfully reactivated subscription after renewal: " . $renewal_data['subscription_id']);
                        
                        // Store success data for redirect
                        update_user_meta($user_id, 'swsib_checkout_success_data', array(
                            'subscription_id' => $renewal_data['subscription_id'],
                            'plan_name' => isset($renewal_data['plan']['name']) ? $renewal_data['plan']['name'] : 'Subscription',
                            'timestamp' => time(),
                            'is_renewal' => true
                        ));
                        
                        // Clean up the transient
                        delete_transient('swsib_stripe_renewal_' . $session_key);
                        
                        // Redirect to success page
                        $success_url = add_query_arg(
                            array(
                                'swsib_success' => '1',
                                'subscription_id' => $renewal_data['subscription_id'],
                                'renewed' => '1'
                            ),
                            self::get_success_redirect_url()
                        );
                        
                        self::log_message("Redirecting to renewal success page: $success_url");
                        wp_redirect($success_url);
                        exit;
                    } else {
                        self::log_message("Failed to reactivate subscription after renewal: " . $renewal_data['subscription_id']);
                    }
                }
            } else {
                self::log_message("No renewal data found in transient for session key: $session_key");
            }
        }
        
        // If we reach here, something went wrong in processing
        // Redirect to the subscriptions page with an error message
        $subscriptions_url = add_query_arg(
            array(
                'swsib_error' => '1',
                'message' => urlencode(__('There was a problem processing your renewal.', 'swiftspeed-siberian'))
            ),
            home_url('/my-account/subscriptions/')
        );
        
        self::log_message("Redirecting to subscriptions page with error message");
        wp_redirect($subscriptions_url);
        exit;
    }

    /**
     * Enqueue post-purchase popup.
     */
    public static function enqueue_post_purchase_popup() {
        // Get integration status
        $options = get_option('swsib_options', array());
        $integration_enabled = isset($options['subscription']['integration_enabled']) && 
                              filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        // Check if integration is enabled
        if (!$integration_enabled) {
            return;
        }
        
        // Check if this is a success page with subscription ID
        if (!isset($_GET['swsib_success']) || !isset($_GET['subscription_id'])) {
            return;
        }
        
        $subscription_id = sanitize_text_field($_GET['subscription_id']);
        self::log_message("Displaying post-purchase popup for subscription: $subscription_id");
        
        // Check if this is a renewal
        $is_renewal = isset($_GET['renewed']) && $_GET['renewed'] === '1';
        
        // Get popup message and action
        $popup_message = isset($options['subscription']['purchase_popup_message']) ? 
                        $options['subscription']['purchase_popup_message'] : 
                        ($is_renewal 
                            ? __('Congratulations! Your subscription has been renewed.', 'swiftspeed-siberian')
                            : __('Congratulations! Your subscription is now active.', 'swiftspeed-siberian'));
        
        $popup_action = isset($options['subscription']['purchase_popup_action']) ? 
                       $options['subscription']['purchase_popup_action'] : 
                       '';
        
        // Get manage subscription URL
        $manage_url = isset($options['subscription']['manage_subscription_url']) ? 
                     $options['subscription']['manage_subscription_url'] : 
                     '';
                     
        // If no manage URL set, look for a page with the shortcode
        if (empty($manage_url)) {
            $pages = get_posts(array(
                'post_type' => 'page',
                'posts_per_page' => 1,
                's' => '[swsib_subscriptions]',
                'fields' => 'ids'
            ));
            
            if (!empty($pages)) {
                $manage_url = get_permalink($pages[0]);
            } else {
                $manage_url = home_url('/');
            }
        }
        
        // Prepare action HTML
        $action_html = '';
        if (!empty($popup_action)) {
            if (strpos(trim($popup_action), '[') === 0) {
                // Shortcode
                $action_html = do_shortcode($popup_action);
            } else {
                // URL
                $action_html = sprintf(
                    '<a href="%s" class="swsib-button">%s</a>',
                    esc_url($popup_action),
                    __('Continue to Your App', 'swiftspeed-siberian')
                );
            }
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'swsib-subscription-public-css',
            SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/public.css',
            array(),
            SWSIB_VERSION
        );
        
        // Output popup HTML
        ?>
        <div class="swsib-success-overlay"></div>
        <div class="swsib-success-popup">
            <h3><?php echo $is_renewal ? __('Subscription Renewed', 'swiftspeed-siberian') : __('Subscription Activated', 'swiftspeed-siberian'); ?></h3>
            <p><?php echo esc_html($popup_message); ?></p>
            <div class="swsib-success-popup-buttons">
                <?php if (!empty($action_html)): ?>
                    <?php echo $action_html; ?>
                <?php endif; ?>
                <a href="<?php echo esc_url($manage_url); ?>" class="swsib-button secondary">
                    <?php _e('Manage Subscriptions', 'swiftspeed-siberian'); ?>
                </a>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.swsib-success-overlay').on('click', function() {
                $('.swsib-success-popup, .swsib-success-overlay').fadeOut();
            });
        });
        </script>
        <?php
    }

    /**
     * Get success redirect URL with auto-create functionality.
     */
    public static function get_success_redirect_url() {
        self::log_message("Starting get_success_redirect_url function");
        
        // Get options
        $options = get_option('swsib_options', array());
        
        // Check if we stored a known good URL in our options
        if (isset($options['subscription']['detected_subscription_url']) && 
            !empty($options['subscription']['detected_subscription_url'])) {
            $url = $options['subscription']['detected_subscription_url'];
            self::log_message("Using stored subscription URL from options: " . $url);
            return $url;
        }
        
        // Check if custom URL is set
        if (isset($options['subscription']['manage_subscription_url']) && 
            !empty($options['subscription']['manage_subscription_url'])) {
            $url = $options['subscription']['manage_subscription_url'];
            self::log_message("Using custom management URL: " . $url);
            return $url;
        }
        
        // Search for page with our shortcode
        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => 1,
            's' => '[swsib_subscriptions]',
            'fields' => 'ids'
        ));
        
        if (!empty($pages)) {
            $url = get_permalink($pages[0]);
            self::log_message("Found page with [swsib_subscriptions] shortcode: " . $url);
            
            // Store this for future use
            if (!isset($options['subscription'])) {
                $options['subscription'] = array();
            }
            $options['subscription']['detected_subscription_url'] = $url;
            update_option('swsib_options', $options);
            
            return $url;
        }
        
        // If no page exists yet, create one
        self::log_message("No subscription management page found, creating one now");
        
        $new_page_id = wp_insert_post(array(
            'post_title' => 'Manage Subscriptions',
            'post_content' => '[swsib_subscriptions]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'comment_status' => 'closed'
        ));
        
        if ($new_page_id && !is_wp_error($new_page_id)) {
            $url = get_permalink($new_page_id);
            self::log_message("Created new subscription management page: " . $url);
            
            // Store this for future use
            if (!isset($options['subscription'])) {
                $options['subscription'] = array();
            }
            $options['subscription']['detected_subscription_url'] = $url;
            update_option('swsib_options', $options);
            
            return $url;
        }
        
        // If we're still here, use the site's home URL as a fallback
        self::log_message("Couldn't create page, using home URL as fallback");
        return home_url('/');
    }

    /**
     * Create subscription order URL for SiberianCMS.
     * Enhanced to properly track application_id and prevent duplicate subscriptions.
     */
    public static function create_subscription_order_url($admin_id, $app_id, $admin_email, $sub_id) {
        self::log_message("Creating subscription order URL for admin_id: $admin_id, app_id: $app_id, email: $admin_email, sub_id: $sub_id");
        
        // Validate application_id and subscription_id
        if (empty($app_id) || empty($sub_id)) {
            self::log_message("ERROR: Missing required application_id or subscription_id");
            return false;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        $plans = isset($options['subscription']['plans']) ? $options['subscription']['plans'] : array();
        
        // Find matching subscription plan
        $matched_plan = null;
        foreach ($plans as $plan) {
            self::log_message("Checking plan: Siberian ID {$plan['siberian_plan_id']} => Plan {$plan['name']}");
            if ((string)$plan['siberian_plan_id'] === (string)$sub_id) {
                $matched_plan = $plan;
                self::log_message("Matched subscription $sub_id to plan {$plan['name']}");
                break;
            }
        }
        
        if (!$matched_plan) {
            self::log_message("ERROR: No mapping found for subscription ID $sub_id");
            return false;
        }
        
        // Get user or create one
        $user = get_user_by('email', $admin_email);
        if (!$user) {
            self::log_message("User not found, creating user for $admin_email");
            $base_username = sanitize_user(current(explode('@', $admin_email)), true);
            $counter = 1;
            $new_username = $base_username;
            while (username_exists($new_username)) {
                $new_username = $base_username . $counter;
                $counter++;
            }
            $password = wp_generate_password(12, false);
            $user_id = wp_create_user($new_username, $password, $admin_email);
            if (is_wp_error($user_id)) {
                self::log_message("ERROR: User creation failed: " . $user_id->get_error_message());
                return false;
            }
            $user = get_user_by('id', $user_id);
            wp_new_user_notification($user_id, null, 'user');
            self::log_message("User created: ID $user_id, username: $new_username");
        } else {
            self::log_message("User found: ID {$user->ID}, username: {$user->user_login}");
        }
        
        // Generate token for checkout with complete data
        $token = uniqid('swsib_pe_token_', true);
        $transient_data = array(
            'user_id' => $user->ID,
            'admin_id' => $admin_id,
            'application_id' => $app_id, // Crucial to track unique apps
            'admin_email' => $admin_email,
            'siberian_sub_id' => $sub_id,
            'plan_id' => $matched_plan['id'],
            'timestamp' => time()
        );
        
        // Store the data with a longer expiration
        set_transient($token, $transient_data, 7200); // 2 hours
        
        // Generate checkout URL
        $checkout_page_id = self::get_checkout_page_id();
        if ($checkout_page_id) {
            $checkout_url = add_query_arg('swsib_token', $token, get_permalink($checkout_page_id));
        } else {
            // Fallback to home with token
            $checkout_url = add_query_arg('swsib_token', $token, home_url('/'));
        }
        
        self::log_message("Returning checkout URL: $checkout_url");
        return $checkout_url;
    }

    /**
     * Create a default checkout page if none exists.
     */
    private static function create_default_checkout_page() {
        self::log_message("Creating default checkout page");
        
        // Check if a page with checkout shortcode already exists
        $existing_pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => 1,
            's' => '[swsib_checkout',
            'fields' => 'ids'
        ));
        
        if (!empty($existing_pages)) {
            $checkout_page_id = $existing_pages[0];
            self::log_message("Found existing checkout page with ID: $checkout_page_id");
        } else {
            // Create a new checkout page
            $checkout_page = array(
                'post_title' => 'Subscription Checkout',
                'post_content' => '[swsib_checkout]',
                'post_status' => 'publish',
                'post_type' => 'page'
            );
            
            $checkout_page_id = wp_insert_post($checkout_page);
            self::log_message("Created new checkout page with ID: $checkout_page_id");
        }
        
        // Update the plugin options to use this page
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        $options['subscription']['checkout_page_id'] = $checkout_page_id;
        update_option('swsib_options', $options);
        
        // Redirect to the checkout page
        wp_redirect(get_permalink($checkout_page_id));
        exit;
    }

    /**
     * Get checkout page ID from options.
     */
    public static function get_checkout_page_id() {
        $options = get_option('swsib_options', array());
        
        // If checkout page ID is set in options, use that
        if (isset($options['subscription']['checkout_page_id']) && 
            intval($options['subscription']['checkout_page_id']) > 0) {
            return intval($options['subscription']['checkout_page_id']);
        }
        
        // Otherwise try to find a page with the checkout shortcode
        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => 1,
            's' => '[swsib_checkout',
            'fields' => 'ids'
        ));
        
        if (!empty($pages)) {
            $checkout_page_id = $pages[0];
            
            // Update the option for future use
            if (!isset($options['subscription'])) {
                $options['subscription'] = array();
            }
            $options['subscription']['checkout_page_id'] = $checkout_page_id;
            update_option('swsib_options', $options);
            
            return $checkout_page_id;
        }
        
        return 0;
    }

    /**
     * Checkout shortcode handler.
     * Fixed to include application_id in checkout data.
     */
    public static function checkout_shortcode($atts) {
        // Extract attributes
        $atts = shortcode_atts(array(
            'title' => __('Complete Your Subscription', 'swiftspeed-siberian')
        ), $atts);
        
        // Check for checkout data in different places
        $checkout_data = null;
        
        // Check in URL for token
        if (isset($_GET['swsib_token'])) {
            $token = sanitize_text_field($_GET['swsib_token']);
            $data = get_transient($token);
            
            if ($data) {
                $checkout_data = $data;
                self::log_message("Got checkout data from transient: " . print_r($checkout_data, true));
            }
        }
        
        // If no data in token, check user meta
        if (!$checkout_data && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $checkout_data = get_user_meta($user_id, 'swsib_checkout_data', true);
            
            if ($checkout_data) {
                self::log_message("Got checkout data from user meta: " . print_r($checkout_data, true));
            }
        }
        
        // Add user_id to checkout data if present
        if ($checkout_data && !isset($checkout_data['user_id']) && is_user_logged_in()) {
            $checkout_data['user_id'] = get_current_user_id();
            self::log_message("Added user_id to checkout data: " . get_current_user_id());
        }
        
        // Validate application_id and siberian_sub_id in checkout data
        if ($checkout_data) {
            if (empty($checkout_data['application_id'])) {
                self::log_message("ERROR: No application_id in checkout data");
                return '<div class="swsib-notice error"><p>' . __('Missing application information. Please try again.', 'swiftspeed-siberian') . '</p></div>';
            }
            
            if (empty($checkout_data['siberian_sub_id'])) {
                self::log_message("ERROR: No siberian_sub_id in checkout data");
                return '<div class="swsib-notice error"><p>' . __('Missing subscription information. Please try again.', 'swiftspeed-siberian') . '</p></div>';
            }
        }
        
        // If we have checkout data, process it
        if ($checkout_data) {
            // Get plan details
            $options = get_option('swsib_options', array());
            $plans = isset($options['subscription']['plans']) ? $options['subscription']['plans'] : array();
            
            $plan = null;
            foreach ($plans as $p) {
                if ($p['id'] === $checkout_data['plan_id']) {
                    $plan = $p;
                    break;
                }
            }
            
            if (!$plan) {
                self::log_message("Error: Plan not found for checkout data: " . print_r($checkout_data, true));
                return '<div class="swsib-notice error"><p>' . __('Subscription plan not found.', 'swiftspeed-siberian') . '</p></div>';
            }
            
            // Get available payment methods
            $payment_gateways = isset($options['subscription']['payment_gateways']) 
                ? $options['subscription']['payment_gateways'] 
                : array();
            
            // Filter to active payment gateways
            $active_gateways = array();
            foreach ($payment_gateways as $gateway_id => $gateway) {
                if (isset($gateway['enabled']) && $gateway['enabled']) {
                    $active_gateways[$gateway_id] = $gateway;
                }
            }
            
            // Get country list for the dropdown
            $countries = array(
                'US' => __('United States', 'swiftspeed-siberian'),
                'CA' => __('Canada', 'swiftspeed-siberian'),
                'GB' => __('United Kingdom', 'swiftspeed-siberian'),
                'AU' => __('Australia', 'swiftspeed-siberian'),
                'DE' => __('Germany', 'swiftspeed-siberian'),
                'FR' => __('France', 'swiftspeed-siberian'),
                'IN' => __('India', 'swiftspeed-siberian'),
                'NG' => __('Nigeria', 'swiftspeed-siberian'),
                'ZA' => __('South Africa', 'swiftspeed-siberian'),
                'JP' => __('Japan', 'swiftspeed-siberian'),
                'BR' => __('Brazil', 'swiftspeed-siberian'),
                'ES' => __('Spain', 'swiftspeed-siberian'),
                'IT' => __('Italy', 'swiftspeed-siberian'),
                'NL' => __('Netherlands', 'swiftspeed-siberian')
            );
            
            // Get user data for pre-filling form
            $user_info = null;
            if (is_user_logged_in()) {
                $user_info = get_userdata(get_current_user_id());
            }
            
            // Load Stripe.js if needed
            if (isset($active_gateways['stripe'])) {
                wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
                
                // Get Stripe publishable key
                $stripe_settings = $active_gateways['stripe'];
                $stripe_pk = $stripe_settings['test_mode'] 
                    ? $stripe_settings['test_publishable_key'] 
                    : $stripe_settings['live_publishable_key'];
                
                // Load our checkout JS
                wp_enqueue_script(
                    'swsib-subscription-public-js',
                    SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/public.js',
                    array('jquery', 'stripe-js'),
                    SWSIB_VERSION,
                    true
                );
                
                // Localize script with payment data
                wp_localize_script('swsib-subscription-public-js', 'swsib_subscription_checkout', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('swsib_subscription_checkout_nonce'),
                    'plan' => $plan,
                    'checkout_data' => $checkout_data,
                    'payment_gateways' => $active_gateways,
                    'stripe_pk' => $stripe_pk,
                    'countries' => $countries
                ));
                
                // Load our styles
                wp_enqueue_style(
                    'swsib-subscription-public-css',
                    SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/public.css',
                    array(),
                    SWSIB_VERSION
                );
            }
            
            self::log_message("Rendering checkout page for plan: {$plan['name']} (ID: {$plan['id']})");
            
            ob_start();
            include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/public/templates/checkout.php';
            return ob_get_clean();
        } else {
            // No checkout data found - show a message
            self::log_message("No checkout data found for checkout page");
            return '<div class="swsib-container swsib-checkout">
                <div class="swsib-notice info">
                    <p>' . __('No checkout data found. Please try again.', 'swiftspeed-siberian') . '</p>
                    <p>' . __('This page is a checkout endpoint for subscription purchases. It requires specific data to be passed from SiberianCMS to function properly.', 'swiftspeed-siberian') . '</p>
                    <p>' . __('If you are a site administrator, please ensure you have configured the subscription plans and payment methods in the admin dashboard.', 'swiftspeed-siberian') . '</p>
                </div>
            </div>';
        }
    }
}