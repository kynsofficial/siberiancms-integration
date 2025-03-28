<?php
/**
 * Subscription Handler - Updated Version with Database Storage
 *
 * Manages subscription creation, activation, and status updates.
 * Enhanced for proper database storage and role management.
 * Refactored to use dedicated handlers for Stripe and PayPal.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle subscription operations.
 */
class SwiftSpeed_Siberian_Subscription_Handler {

    /**
     * Plugin options.
     */
    private static $options = null;

    /**
     * DB module instance for WordPress subscriptions.
     */
    private static $db_module = null;

    /**
     * SiberianCMS DB module instance.
     */
    private static $siber_db = null;

    /**
     * Grace period in days for expired subscriptions before cancellation.
     */
    private static $grace_period_days = 7;

    /**
     * Retry period in days for payment retries on expired subscriptions.
     */
    private static $retry_period_days = 3;

    /**
     * Initialize the handler.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
        
        // Initialize payment-specific handlers
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
        
        SwiftSpeed_Siberian_Stripe_Sub_Handler::init();
        SwiftSpeed_Siberian_PayPal_Sub_Handler::init();
        
        // Register AJAX handlers
        add_action('wp_ajax_swsib_cancel_subscription', array(__CLASS__, 'handle_cancel_subscription'));
        add_action('wp_ajax_swsib_set_pending_cancellation', array(__CLASS__, 'handle_set_pending_cancellation'));
        add_action('wp_ajax_swsib_uncancel_subscription', array(__CLASS__, 'handle_uncancel_subscription'));
        add_action('wp_ajax_swsib_delete_subscription', array(__CLASS__, 'handle_delete_subscription'));
        add_action('wp_ajax_swsib_bulk_delete_subscriptions', array(__CLASS__, 'handle_bulk_delete_subscriptions'));
        add_action('wp_ajax_swsib_bulk_cancel_subscriptions', array(__CLASS__, 'handle_bulk_cancel_subscriptions'));
        add_action('wp_ajax_swsib_activate_subscription', array(__CLASS__, 'handle_activate_subscription'));
        
        // Initialize frontend functionality
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/public/templates/frontend-shortcode.php';
        SwiftSpeed_Siberian_Subscription_Frontend::init();
        
        // Add cron to check for expired subscriptions and pending cancellations
        if (!wp_next_scheduled('swsib_check_pending_cancellations')) {
            wp_schedule_event(time(), 'daily', 'swsib_check_pending_cancellations');
        }
        add_action('swsib_check_pending_cancellations', array(__CLASS__, 'process_pending_cancellations'));
        
        // Add cron to check for expired subscriptions
        if (!wp_next_scheduled('swsib_check_expired_subscriptions')) {
            wp_schedule_event(time(), 'twicedaily', 'swsib_check_expired_subscriptions');
        }
        add_action('swsib_check_expired_subscriptions', array(__CLASS__, 'process_expired_subscriptions'));
        
        // Add cron to attempt payment retries
        if (!wp_next_scheduled('swsib_retry_failed_payments')) {
            wp_schedule_event(time(), 'daily', 'swsib_retry_failed_payments');
        }
        add_action('swsib_retry_failed_payments', array(__CLASS__, 'retry_failed_payments'));
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
     * Get DB module instance for WordPress.
     */
    public static function get_db_module() {
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
     * Calculate subscription end date based on billing frequency.
     */
    public static function calculate_end_date($billing_frequency) {
        $date = new DateTime();
        
        switch ($billing_frequency) {
            case 'weekly':
                $date->add(new DateInterval('P1W'));
                break;
                
            case 'monthly':
                $date->add(new DateInterval('P1M'));
                break;
                
            case 'quarterly':
                $date->add(new DateInterval('P3M'));
                break;
                
            case 'biannually':
                $date->add(new DateInterval('P6M'));
                break;
                
            case 'annually':
                $date->add(new DateInterval('P1Y'));
                break;
                
            default:
                $date->add(new DateInterval('P1M')); // Default to monthly
        }
        
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Create subscription record.
     * Fixed to consider application_id when checking for duplicates.
     */
    public static function create_subscription($plan, $checkout_data, $payment_id, $customer_data = array(), $payment_method = 'manual') {
        // Get DB module
        $db = self::get_db_module();
        
        // First check if a subscription with this payment_id already exists
        if (!empty($payment_id) && $payment_method !== 'manual') {
            $existing_subscription = $db->get_subscription_by_payment_id($payment_id, $payment_method);
            if ($existing_subscription) {
                self::log_message("Subscription already exists with payment ID: {$payment_id}, returning existing ID: {$existing_subscription['id']}");
                return $existing_subscription['id'];
            }
        }
        
        // NOTE: This duplicated recent subscription check was removed as it prevented
        // creating multiple subscriptions for different apps with same plan

        // Calculate tax amount
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/tax-handler.php';
        $tax_amount = SwiftSpeed_Siberian_Tax_Handler::calculate_tax($plan, $customer_data);
        $total_amount = $plan['price'] + $tax_amount;
        
        // Get the user ID - use user_id from checkout_data if available, otherwise get current user
        $user_id = isset($checkout_data['user_id']) ? (int)$checkout_data['user_id'] : get_current_user_id();
        
        self::log_message("Creating subscription with user ID: {$user_id}, plan ID: {$plan['id']}, payment ID: {$payment_id}, method: {$payment_method}");
        
        // Create subscription record with common fields for all payment methods
        $subscription = array(
            'id' => uniqid('sub_'),
            'user_id' => $user_id,
            'plan_id' => $plan['id'],
            'payment_id' => $payment_id,
            'admin_id' => isset($checkout_data['admin_id']) ? (int)$checkout_data['admin_id'] : 0,
            'admin_email' => isset($checkout_data['admin_email']) ? $checkout_data['admin_email'] : '',
            'application_id' => isset($checkout_data['application_id']) ? (int)$checkout_data['application_id'] : 0,
            'siberian_plan_id' => isset($checkout_data['siberian_sub_id']) ? $checkout_data['siberian_sub_id'] : 0,
            'amount' => (float)$plan['price'],
            'tax_amount' => (float)$tax_amount,
            'total_amount' => (float)$total_amount,
            'currency' => $plan['currency'],
            'status' => 'active',
            'start_date' => current_time('mysql'),
            'end_date' => self::calculate_end_date($plan['billing_frequency']),
            'billing_frequency' => $plan['billing_frequency'],
            'payment_method' => $payment_method,
            'customer_data' => $customer_data,
            'app_quantity' => isset($plan['app_quantity']) ? (int)$plan['app_quantity'] : 1,
            'payment_status' => 'paid',
            'last_payment_date' => current_time('mysql'),
            'retry_count' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        // Add gateway-specific fields based on payment method
        switch ($payment_method) {
            case 'stripe':
                // For Stripe, check for subscription ID format
                $subscription['is_stripe_subscription'] = (strpos($payment_id, 'sub_') === 0) ? 1 : 0;
                
                // Store Stripe customer ID if available
                $stripe_customer_id = get_user_meta($user_id, 'swsib_stripe_customer_id', true);
                if (!empty($stripe_customer_id)) {
                    $subscription['stripe_customer_id'] = $stripe_customer_id;
                }
                break;
                
            case 'paypal':
                // For PayPal, store payer ID if available
                $paypal_payer_id = get_user_meta($user_id, 'swsib_paypal_payer_id', true);
                if (!empty($paypal_payer_id)) {
                    $subscription['paypal_payer_id'] = $paypal_payer_id;
                }
                break;
        }
        
        try {
            // Create the subscription
            $subscription_id = $db->create_subscription($subscription);
            
            if (!$subscription_id) {
                self::log_message('Failed to create subscription for payment ID: ' . $payment_id);
                return false;
            }
            
            self::log_message('Created subscription: ' . $subscription_id . ' with payment ID: ' . $payment_id . ' using ' . $payment_method);
            
            // Store success data in user meta for redirect handling
            if ($user_id > 0) {
                update_user_meta($user_id, 'swsib_checkout_success_data', array(
                    'subscription_id' => $subscription_id,
                    'plan_name' => isset($plan['name']) ? $plan['name'] : 'Subscription',
                    'timestamp' => time()
                ));
            }
            
            return $subscription_id;
        } catch (Exception $e) {
            self::log_message('Exception in create_subscription: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Activate subscription in SiberianCMS.
     * Centralized function to handle all SiberianCMS activation.
     */
    public static function activate_siberian_subscription($checkout_data, $plan = null, $subscription_id = 0) {
        self::log_message("Activating subscription in SiberianCMS");
        
        if (!isset($checkout_data['application_id']) || !isset($checkout_data['siberian_sub_id'])) {
            self::log_message('ERROR: Missing application_id or siberian_sub_id in checkout data');
            return false;
        }
        
        $db = self::get_db_module();
        if (!$db) {
            self::log_message('ERROR: Failed to initialize DB module');
            return false;
        }
        
        // Get subscription data if ID is provided but no checkout data
        if ($subscription_id && (empty($checkout_data['application_id']) || empty($checkout_data['siberian_sub_id']))) {
            $subscription = $db->get_subscription($subscription_id);
            if ($subscription) {
                $checkout_data['application_id'] = $subscription['application_id'];
                $checkout_data['siberian_sub_id'] = $subscription['siberian_plan_id'];
                $checkout_data['admin_id'] = $subscription['admin_id'];
                $checkout_data['admin_email'] = $subscription['admin_email'];
            }
        }
        
        // Verify SiberianCMS database is configured
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            self::log_message('ERROR: SiberianCMS database not configured');
            return false;
        }
        
        // Get the SiberianCMS DB module
        $siber_db = self::get_siber_db();
        if (!$siber_db) {
            self::log_message('ERROR: Failed to initialize SiberianCMS DB module');
            return false;
        }
        
        // Create/update the subscription application
        $result = $siber_db->create_or_update_subscription_application(
            $checkout_data['application_id'],
            $checkout_data['siberian_sub_id'],
            $subscription_id
        );
        
        if (!$result) {
            self::log_message('ERROR: Failed to create/update subscription application in SiberianCMS');
            return false;
        }
        
        self::log_message("SiberianCMS subscription activation successful");
        
        // Update admin role if needed
        $admin_id = isset($checkout_data['admin_id']) ? $checkout_data['admin_id'] : 0;
        $admin_email = isset($checkout_data['admin_email']) ? $checkout_data['admin_email'] : '';
        
        if (!empty($admin_id) || !empty($admin_email)) {
            self::update_siberian_subscription(
                $admin_id,
                $admin_email,
                $checkout_data['application_id'],
                $checkout_data['siberian_sub_id'],
                'activate'
            );
        }
        
        return true;
    }

   /**
 * Update subscription status by payment ID.
 * Modified to REQUIRE payment_method to prevent cross-gateway conflicts
 */
public static function update_subscription_status_by_payment($payment_id, $status, $payment_method) {
    // Payment method is now required
    if (empty($payment_method)) {
        self::log_message("Error: Payment method must be specified when updating by payment_id");
        return false;
    }
    
    // Get DB module
    $db = self::get_db_module();
    
    // Find subscription by payment ID - using BOTH payment_id AND payment_method
    $subscription = $db->get_subscription_by_payment_id($payment_id, $payment_method);
    
    if (!$subscription) {
        self::log_message("No subscription found for payment ID: {$payment_id} with method: {$payment_method}");
        return false;
    }
    
    // Prepare update data based on status
    $update_data = array('status' => $status);
    
    // If status is failed or past due, set payment status
    if ($status === 'failed' || $status === 'past_due') {
        $update_data['payment_status'] = 'failed';
        $update_data['last_payment_error'] = current_time('mysql');
        
        // Set retry period
        $retry_end = new DateTime();
        $retry_end->add(new DateInterval('P' . self::$retry_period_days . 'D'));
        $update_data['retry_period_end'] = $retry_end->format('Y-m-d H:i:s');
    }
    
    // Update the subscription
    $result = $db->update_subscription($subscription['id'], $update_data);
    
    if ($result) {
        self::log_message("Updated subscription {$subscription['id']} status to {$status}");
        
        // If status is cancelled, also handle in SiberianCMS
        if ($status === 'cancelled') {
            self::update_siberian_subscription(
                $subscription['admin_id'],
                $subscription['admin_email'],
                $subscription['application_id'], 
                $subscription['siberian_plan_id'], 
                'cancel'
            );
        }
        
        // Trigger status change action
        do_action('swsib_subscription_status_changed', $subscription['id'], $subscription['status'], $status);
        
        return true;
    }
    
    return false;
}
    /**
     * Update SiberianCMS subscription with role management.
     * This function handles admin role updates based on subscription status.
     */
    public static function update_siberian_subscription($admin_id, $admin_email, $application_id, $siberian_plan_id, $action = 'activate') {
        self::log_message("Updating SiberianCMS subscription - Action: {$action}");
        
        $siber_db = self::get_siber_db();
        if (!$siber_db) {
            self::log_message("Failed to load SiberianCMS DB module for integration");
            return false;
        }
        
        if (empty($application_id) || empty($siberian_plan_id)) {
            self::log_message("Missing required application_id or siberian_plan_id");
            return false;
        }
        
        $sub_app_success = false;
        
        if ($action === 'activate') {
            // For activation, should already be done by the calling function
            $sub_app_success = true; 
        } else {
            // For cancellation, delete the subscription application
            $sub_app_success = $siber_db->delete_subscription_application(
                $application_id,
                $siberian_plan_id
            );
            self::log_message("SiberianCMS subscription deletion result: " . ($sub_app_success ? "Success" : "Failed"));
        }
        
        // Get admin info - try by ID first, then by email if necessary
        $admin = null;
        if (empty($admin_id) && !empty($admin_email)) {
            $admin = $siber_db->find_admin_by_email($admin_email);
            if ($admin) {
                $admin_id = $admin['admin_id'];
            }
        } else if (!empty($admin_id)) {
            // Get admin info to ensure we have the current role
            $admin = $siber_db->get_admin_by_id($admin_id);
        }
        
        if (!$admin) {
            self::log_message("Admin not found for ID: {$admin_id} or email: {$admin_email}");
            return $sub_app_success;
        }
        
        $admin_id = $admin['admin_id'];
        $current_role_id = $admin['role_id'];
        
        self::log_message("Processing admin role update after " . ($action === 'activate' ? 'activation' : 'cancellation') . " for admin ID {$admin_id}");
        
        // Check if admin is a super admin (role ID 1) - if so, exit and don't change role
        if ($current_role_id == '1') {
            self::log_message("Admin {$admin_id} is a super admin (role ID 1). Skipping role update.");
            return $sub_app_success;
        }
        
        // Check all active subscriptions for this admin
        $active_subscriptions = $siber_db->check_admin_active_subscriptions($admin_id);
        self::log_message("Found " . count($active_subscriptions) . " active subscriptions for admin ID {$admin_id}");
        
        // Get options
        $options = get_option('swsib_options', array());
        
        // Get role priorities from subscription settings
        $role_priorities = isset($options['subscription']['role_priorities']) ? 
                          $options['subscription']['role_priorities'] : array();
                          
        // Get fallback role ID from subscription settings
        $fallback_role_id = isset($options['subscription']['fallback_role_id']) ? 
                           $options['subscription']['fallback_role_id'] : '2';
        
        // If no active subscriptions, assign fallback role
        if (empty($active_subscriptions)) {
            self::log_message("No active subscriptions remain. Assigning fallback role ID: {$fallback_role_id}");
            if ($current_role_id != $fallback_role_id) {
                $result = $siber_db->update_admin_role($admin_id, $fallback_role_id);
                self::log_message("Fallback role update result: " . ($result ? "Success" : "Failed"));
            } else {
                self::log_message("Admin already has fallback role ID: {$fallback_role_id}");
            }
            return $sub_app_success;
        }
        
        // Get all subscription plans to match with active subscriptions
        $plans = isset($options['subscription']['plans']) ? 
                $options['subscription']['plans'] : array();
                
        // Determine roles assigned by active subscriptions
        $assigned_roles = array();
        
        foreach ($active_subscriptions as $sub) {
            $active_siberian_id = $sub['subscription_id'];
            
            // Find matching plan and get role
            foreach ($plans as $plan) {
                if ((string)$plan['siberian_plan_id'] === (string)$active_siberian_id) {
                    if (isset($plan['role_id'])) {
                        $assigned_roles[] = $plan['role_id'];
                        self::log_message("Adding role ID: {$plan['role_id']} from active subscription #{$active_siberian_id}");
                    }
                    break;
                }
            }
        }
        
        $assigned_roles = array_unique($assigned_roles);
        self::log_message("Assigned roles after deduplication: " . implode(', ', $assigned_roles));
        
        // If no assigned roles found, use fallback
        if (empty($assigned_roles)) {
            self::log_message("No mapped roles found, reverting to fallback role: {$fallback_role_id}");
            if ($current_role_id != $fallback_role_id) {
                $result = $siber_db->update_admin_role($admin_id, $fallback_role_id);
                self::log_message("Fallback role update result: " . ($result ? "Success" : "Failed"));
            } else {
                self::log_message("Admin already has fallback role ID: {$fallback_role_id}");
            }
            return $sub_app_success;
        }
        
        // Determine highest priority role
        $highest_role = null;
        
        // Check each role priority in order
        foreach ($role_priorities as $priority_role) {
            if (in_array($priority_role, $assigned_roles)) {
                $highest_role = $priority_role;
                self::log_message("Highest priority role determined: {$highest_role}");
                break;
            }
        }
        
        // If no priority-based match, use first available role
        if (!$highest_role) {
            $highest_role = reset($assigned_roles);
            self::log_message("No priority-based match, using first role: {$highest_role}");
        }
        
        // Update admin role if different from current
        if ($highest_role && ($current_role_id != $highest_role)) {
            $result = $siber_db->update_admin_role($admin_id, $highest_role);
            self::log_message("Admin role update to highest priority role {$highest_role} result: " . ($result ? "Success" : "Failed"));
        } else {
            self::log_message("No role change needed - current role: {$current_role_id}, highest role: {$highest_role}");
        }
        
        return $sub_app_success;
    }
    
    /**
     * Retry failed payments for subscriptions in the retry period.
     * This is run via cron schedule.
     */
    public static function retry_failed_payments() {
        self::log_message("Running retry_failed_payments check");
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get current date
        $now = new DateTime();
        
        // Get subscriptions with failed payment status that are in retry period
        $subscriptions = $db->get_all_subscriptions(array(
            'status' => 'active',
            'payment_status' => 'failed'
        ));
        
        foreach ($subscriptions as $subscription) {
            // Skip if no retry period is set
            if (empty($subscription['retry_period_end'])) {
                continue;
            }
            
            // Get retry period end date
            $retry_end = new DateTime($subscription['retry_period_end']);
            
            // If still within retry period, attempt to charge again
            if ($now <= $retry_end) {
                self::log_message("Attempting payment retry for subscription {$subscription['id']}");
                
                // Increment retry count
                $db->update_subscription($subscription['id'], array(
                    'retry_count' => $subscription['retry_count'] + 1
                ));
                
                // For Stripe subscriptions, retry is handled automatically by Stripe
                $is_stripe = ($subscription['payment_method'] === 'stripe');
                
                if ($is_stripe && isset($subscription['payment_id']) && 
                    strpos($subscription['payment_id'], 'sub_') === 0) {
                    self::log_message("This is a Stripe subscription - retries are handled by Stripe automatically");
                    continue;
                }
                
                // For other payment methods, implement retry logic here
                self::log_message("Payment retry #{$subscription['retry_count']} for subscription {$subscription['id']}");
            }
            // If retry period has passed, move to expired state
            else if ($now > $retry_end) {
                self::log_message("Retry period expired for subscription {$subscription['id']}, marking as expired");
                
                // Set grace period
                $grace_end = new DateTime();
                $grace_end->add(new DateInterval('P' . self::$grace_period_days . 'D'));
                
                // Update status to expired
                $db->update_subscription($subscription['id'], array(
                    'status' => 'expired',
                    'grace_period_end' => $grace_end->format('Y-m-d H:i:s')
                ));
                
                // We don't deactivate in SiberianCMS yet - the subscription remains usable during grace period
                self::log_message("Subscription {$subscription['id']} has entered grace period until {$grace_end->format('Y-m-d')}");
            }
        }
    }
    
    /**
     * Process expired subscriptions that have passed their grace period.
     * This is run via cron schedule and handles the proper grace periods for expired subscriptions.
     */
    public static function process_expired_subscriptions() {
        self::log_message("Running process_expired_subscriptions check");
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get current date
        $now = new DateTime();
        
        // Check for active subscriptions that have passed their end date
        $active_subscriptions = $db->get_all_subscriptions(array(
            'status' => 'active'
        ));
        
        foreach ($active_subscriptions as $subscription) {
            $end_date = new DateTime($subscription['end_date']);
            
            if ($now > $end_date) {
                self::log_message("Subscription {$subscription['id']} has passed its end date, marking as expired");
                
                // Set a retry period of 3 days for payment retries
                $retry_end = clone $now;
                $retry_end->add(new DateInterval('P3D')); // 3 days retry period
                
                // Update to retry status first
                $db->update_subscription($subscription['id'], array(
                    'payment_status' => 'failed',
                    'retry_period_end' => $retry_end->format('Y-m-d H:i:s'),
                    'retry_count' => isset($subscription['retry_count']) ? ($subscription['retry_count'] + 1) : 1
                ));
                
                self::log_message("Subscription {$subscription['id']} set to payment retry period until {$retry_end->format('Y-m-d H:i:s')}");
            }
        }
        
        // Check for subscriptions in retry period
        $retry_subscriptions = $db->get_all_subscriptions(array(
            'payment_status' => 'failed'
        ));
        
        foreach ($retry_subscriptions as $subscription) {
            if (empty($subscription['retry_period_end'])) {
                continue; // Skip if no retry period set
            }
            
            $retry_end = new DateTime($subscription['retry_period_end']);
            
            if ($now > $retry_end) {
                self::log_message("Retry period ended for subscription {$subscription['id']}, marking as expired");
                
                // Set grace period of 7 days after retry period
                $grace_end = clone $now;
                $grace_end->add(new DateInterval('P7D')); // 7 days grace period
                
                // Update status to expired
                $db->update_subscription($subscription['id'], array(
                    'status' => 'expired',
                    'grace_period_end' => $grace_end->format('Y-m-d H:i:s')
                ));
                
                self::log_message("Subscription {$subscription['id']} marked as expired with grace period until {$grace_end->format('Y-m-d H:i:s')}");
            }
        }
        
        // Check expired subscriptions that have passed grace period
        $expired_subscriptions = $db->get_all_subscriptions(array(
            'status' => 'expired'
        ));
        
        foreach ($expired_subscriptions as $subscription) {
            if (!empty($subscription['grace_period_end'])) {
                $grace_end = new DateTime($subscription['grace_period_end']);
                
                if ($now > $grace_end) {
                    self::log_message("Grace period ended for expired subscription {$subscription['id']}, finalizing cancellation");
                    
                    // Cancel in payment gateway if needed
                    if ($subscription['payment_method'] === 'stripe' && 
                        isset($subscription['payment_id']) && 
                        strpos($subscription['payment_id'], 'sub_') === 0) {
                        
                        self::log_message("Cancelling expired Stripe subscription: {$subscription['payment_id']}");
                        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                        SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_stripe_subscription_immediately($subscription['payment_id']);
                    }
                    
                    if ($subscription['payment_method'] === 'paypal' && 
                        isset($subscription['payment_id'])) {
                        
                        self::log_message("Cancelling expired PayPal subscription: {$subscription['payment_id']}");
                        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                        SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_paypal_subscription($subscription['payment_id']);
                    }
                    
                    // Update status to cancelled
                    $db->update_subscription_status($subscription['id'], 'cancelled');
                    
                    // Deactivate in SiberianCMS and update roles
                    self::update_siberian_subscription(
                        $subscription['admin_id'],
                        $subscription['admin_email'],
                        $subscription['application_id'],
                        $subscription['siberian_plan_id'],
                        'cancel'
                    );
                    
                    self::log_message("Expired subscription {$subscription['id']} cancelled after grace period");
                }
            }
        }
    }
    
 /**
 * Process all pending cancellations that have reached their end date or next billing date.
 * Enhanced to properly handle PayPal cancellations at the right time and track cancellation source.
 */
public static function process_pending_cancellations() {
    self::log_message("Running process_pending_cancellations check");
    
    // Get DB module
    $db = self::get_db_module();
    
    // Get current date
    $now = new DateTime();
    
    // Get all pending cancellation subscriptions
    $pending_subscriptions = $db->get_all_subscriptions(array(
        'status' => 'pending-cancellation'
    ));
    
    foreach ($pending_subscriptions as $subscription) {
        // First check if we have a next_billing_date to use
        if (isset($subscription['next_billing_date']) && !empty($subscription['next_billing_date'])) {
            $next_billing = new DateTime($subscription['next_billing_date']);
            
            // If next billing date has passed, finalize cancellation
            if ($now >= $next_billing) {
                self::log_message("Subscription {$subscription['id']} has reached its next billing date, finalizing cancellation");
                
                // Now is the time to cancel in PayPal if this is a PayPal subscription
                if ($subscription['payment_method'] === 'paypal' && 
                    isset($subscription['payment_id'])) {
                    
                    self::log_message("Cancelling PayPal subscription after reaching next billing date: {$subscription['payment_id']}");
                    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                    SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_paypal_subscription($subscription['payment_id']);
                }
                
                // Update status to cancelled
                $db->update_subscription_status($subscription['id'], 'cancelled');
                
                // Deactivate in SiberianCMS and update roles
                self::update_siberian_subscription(
                    $subscription['admin_id'],
                    $subscription['admin_email'],
                    $subscription['application_id'],
                    $subscription['siberian_plan_id'],
                    'cancel'
                );
                
                self::log_message("Subscription {$subscription['id']} cancelled after reaching next billing date");
                continue; // Skip to next subscription
            } else {
                self::log_message("Subscription {$subscription['id']} next billing date not reached yet: {$subscription['next_billing_date']}");
                continue; // Skip to next subscription since we have a valid future next_billing_date
            }
        }
        
        // If no next_billing_date or it's invalid, fall back to end_date
        $end_date = new DateTime($subscription['end_date']);
        
        // If end date has passed, apply grace period before finalizing cancellation
        if ($now >= $end_date) {
            self::log_message("Subscription {$subscription['id']} has reached its end date, checking grace period");
            
            // Check if we already set a grace period end date
            if (empty($subscription['grace_period_end'])) {
                // Set a 7-day grace period for user-initiated cancellations
                $grace_end = clone $now;
                $grace_end->add(new DateInterval('P7D')); // 7 days grace period
                
                // Update subscription with grace period end date
                $db->update_subscription($subscription['id'], array(
                    'grace_period_end' => $grace_end->format('Y-m-d H:i:s')
                ));
                
                self::log_message("Added grace period until {$grace_end->format('Y-m-d H:i:s')} for subscription {$subscription['id']}");
                continue; // Skip to next subscription, we'll finalize on a future cron run
            }
            
            // Check if grace period has passed
            $grace_end = new DateTime($subscription['grace_period_end']);
            if ($now >= $grace_end) {
                // Grace period has passed, finalize cancellation
                self::log_message("Grace period ended for subscription {$subscription['id']}, finalizing cancellation");
                
                // Now is the time to cancel in PayPal if this is a PayPal subscription
                if ($subscription['payment_method'] === 'paypal' && 
                    isset($subscription['payment_id'])) {
                    
                    self::log_message("Cancelling PayPal subscription after grace period: {$subscription['payment_id']}");
                    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                    SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_paypal_subscription($subscription['payment_id']);
                }
                
                // Make sure Stripe subscription is cancelled too
                if ($subscription['payment_method'] === 'stripe' && 
                    isset($subscription['payment_id']) && 
                    strpos($subscription['payment_id'], 'sub_') === 0) {
                    
                    self::log_message("Confirming Stripe subscription cancellation: {$subscription['payment_id']}");
                    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                    SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_stripe_subscription_immediately($subscription['payment_id']);
                }
                
                // Update status to cancelled
                $db->update_subscription_status($subscription['id'], 'cancelled');
                
                // Deactivate in SiberianCMS and update roles
                self::update_siberian_subscription(
                    $subscription['admin_id'],
                    $subscription['admin_email'],
                    $subscription['application_id'],
                    $subscription['siberian_plan_id'],
                    'cancel'
                );
                
                self::log_message("Subscription {$subscription['id']} cancelled after grace period");
            } else {
                self::log_message("Subscription {$subscription['id']} still in grace period until {$grace_end->format('Y-m-d H:i:s')}");
            }
        }
    }
}
    /**
     * Delegate to specific payment handler for subscription cancellation.
     */
    public static function cancel_subscription($subscription_id, $force_cancel = false) {
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            self::log_message("Cannot cancel subscription - not found: $subscription_id");
            return false;
        }
        
        // Delegate to the appropriate payment handler
        if ($subscription['payment_method'] === 'stripe') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
            return SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_subscription($subscription, $force_cancel);
        } else if ($subscription['payment_method'] === 'paypal') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
            return SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_subscription($subscription, $force_cancel);
        }
        
        // For other payment methods or if delegation fails
        return false;
    }

 /**
 * AJAX handler for setting a subscription to pending cancellation.
 */
public static function handle_set_pending_cancellation() {
    // Accept both admin and frontend nonces
    if ((!isset($_POST['nonce']) || 
        (!wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce') && 
         !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce') &&
         !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_checkout_nonce')))) {
        wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        return;
    }

    $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
    if (empty($subscription_id)) {
        wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
        return;
    }
    
    // Get DB module
    $db = self::get_db_module();
    
    // Get subscription
    $subscription = $db->get_subscription($subscription_id);
    
    if (!$subscription) {
        self::log_message("Subscription not found: {$subscription_id}");
        wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
        return;
    }
    
    // Check if current user is admin or subscription owner
    if (!current_user_can('manage_options') && $subscription['user_id'] !== get_current_user_id()) {
        self::log_message("User doesn't have permission to cancel subscription: {$subscription_id}");
        wp_send_json_error(array('message' => __('You do not have permission to cancel this subscription', 'swiftspeed-siberian')));
        return;
    }
    
    // Only allow pending cancellation for active subscriptions
    if ($subscription['status'] !== 'active') {
        self::log_message("Cannot set non-active subscription to pending cancellation: {$subscription_id}, current status: {$subscription['status']}");
        wp_send_json_error(array('message' => __('Only active subscriptions can be set to pending cancellation', 'swiftspeed-siberian')));
        return;
    }
    
    // Log detailed information about the subscription being cancelled
    self::log_message("Processing pending cancellation for subscription {$subscription_id}");
    self::log_message("Subscription details: " . json_encode($subscription));
    
    $stripe_result = false;
    $paypal_result = false;
    
    // For Stripe subscriptions, call the appropriate handler
    if ($subscription['payment_method'] === 'stripe') {
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
        $stripe_result = SwiftSpeed_Siberian_Stripe_Sub_Handler::set_pending_cancellation($subscription);
        if (!$stripe_result) {
            self::log_message("Failed to update Stripe subscription, but continuing with local update");
        } else {
            self::log_message("Successfully updated Stripe subscription to cancel at period end");
        }
    }
    
    // For PayPal subscriptions, call the appropriate handler
    if ($subscription['payment_method'] === 'paypal') {
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
        $paypal_result = SwiftSpeed_Siberian_PayPal_Sub_Handler::set_pending_cancellation($subscription);
        if (!$paypal_result) {
            self::log_message("Failed to update PayPal subscription, but continuing with local update");
        } else {
            self::log_message("Successfully updated PayPal subscription for pending cancellation");
        }
    }
    
    // Update status to pending cancellation
    $result = $db->update_subscription_status($subscription_id, 'pending-cancellation');
    
    if ($result) {
        self::log_message("Subscription {$subscription_id} set to pending cancellation successfully");
        
        // Add detailed success message
        $success_message = __('Subscription set to pending cancellation. Your subscription will be cancelled at the end of the current billing period.', 'swiftspeed-siberian');
        
        // Refresh the database record after update
        $updated_subscription = $db->get_subscription($subscription_id);
        if ($updated_subscription) {
            self::log_message("Updated subscription status confirmed: " . $updated_subscription['status']);
        } else {
            self::log_message("Warning: Unable to retrieve updated subscription record");
        }
        
        wp_send_json_success(array(
            'message' => $success_message,
            'subscription_id' => $subscription_id,
            'status' => 'pending-cancellation'
        ));
    } else {
        self::log_message("Failed to update subscription status for {$subscription_id}");
        wp_send_json_error(array('message' => __('Failed to update subscription status', 'swiftspeed-siberian')));
    }
}

    /**
     * AJAX handler for uncancelling a subscription (changing from pending-cancellation back to active).
     */
    public static function handle_uncancel_subscription() {
        // Accept both admin and frontend nonces
        if (!isset($_POST['nonce']) || 
            (!wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce') && 
             !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce'))) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Check if current user is admin or subscription owner
        if (!current_user_can('manage_options') && $subscription['user_id'] !== get_current_user_id()) {
            wp_send_json_error(array('message' => __('You do not have permission to modify this subscription', 'swiftspeed-siberian')));
            return;
        }
        
        // Only allow uncancelling pending-cancellation subscriptions
        if ($subscription['status'] !== 'pending-cancellation') {
            wp_send_json_error(array('message' => __('Only subscriptions in pending cancellation state can be uncancelled', 'swiftspeed-siberian')));
            return;
        }
        
        // For Stripe subscriptions, call the appropriate handler
        if ($subscription['payment_method'] === 'stripe') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
            $stripe_result = SwiftSpeed_Siberian_Stripe_Sub_Handler::uncancel_subscription($subscription);
            if (!$stripe_result) {
                self::log_message("Failed to uncancel Stripe subscription, but continuing with local update");
            }
        }
        
        // For PayPal subscriptions, call the appropriate handler
        if ($subscription['payment_method'] === 'paypal') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
            $paypal_result = SwiftSpeed_Siberian_PayPal_Sub_Handler::uncancel_subscription($subscription);
            if (!$paypal_result) {
                self::log_message("Failed to uncancel PayPal subscription, but continuing with local update");
            }
        }
        
        // Update subscription status to active
        $result = $db->update_subscription_status($subscription_id, 'active');
        
        if ($result) {
            self::log_message("Subscription {$subscription_id} uncancelled successfully");
            
            // Clear any grace period that might have been set
            $db->update_subscription($subscription_id, array(
                'grace_period_end' => null
            ));
            
            // Return success
            wp_send_json_success(array(
                'message' => __('Subscription uncancelled successfully. Your subscription is now active again.', 'swiftspeed-siberian'),
                'subscription_id' => $subscription_id
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to uncancel subscription', 'swiftspeed-siberian')));
        }
    }

    /**
     * Handle subscription cancellation via AJAX.
     */
    public static function handle_cancel_subscription() {
        // Verify nonce - Accept both admin and frontend nonces
        if (!isset($_POST['nonce']) || 
            (!wp_verify_nonce($_POST['nonce'], 'swsib_subscription_frontend_nonce') && 
             !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce'))) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get subscription ID
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        // Check for force cancel flag
        $force_cancel = isset($_POST['force_cancel']) && filter_var($_POST['force_cancel'], FILTER_VALIDATE_BOOLEAN);
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Check if current user is admin or subscription owner
        if (!current_user_can('manage_options') && $subscription['user_id'] !== get_current_user_id()) {
            wp_send_json_error(array('message' => __('You do not have permission to cancel this subscription', 'swiftspeed-siberian')));
            return;
        }
        
        // Only admins can use force_cancel
        if ($force_cancel && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only administrators can force cancel a subscription', 'swiftspeed-siberian')));
            return;
        }
        
        // SCENARIO 1: Regular user cancellation (set to pending-cancellation)
        if (!current_user_can('manage_options') && $subscription['status'] === 'active' && !$force_cancel) {
            // Delegate to the appropriate payment handler for pending cancellation
            if ($subscription['payment_method'] === 'stripe') {
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                SwiftSpeed_Siberian_Stripe_Sub_Handler::set_pending_cancellation($subscription);
            } else if ($subscription['payment_method'] === 'paypal') {
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                SwiftSpeed_Siberian_PayPal_Sub_Handler::set_pending_cancellation($subscription);
            }
            
            // Update to pending-cancellation locally
            $result = $db->update_subscription_status($subscription_id, 'pending-cancellation');
            
            if ($result) {
                self::log_message("Subscription {$subscription_id} set to pending-cancellation by user");
                
                wp_send_json_success(array(
                    'message' => __('Subscription has been set to pending cancellation and will be cancelled at the end of the current billing period.', 'swiftspeed-siberian')
                ));
            } else {
                wp_send_json_error(array('message' => __('Failed to update subscription status', 'swiftspeed-siberian')));
            }
            return;
        }
        
        // SCENARIO 2: Admin force cancellation or pending-cancellation being finalized
        
        // Delegate to the appropriate payment handler for force cancellation
        if ($subscription['payment_method'] === 'stripe') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
            SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_subscription($subscription, true);
        } else if ($subscription['payment_method'] === 'paypal') {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
            SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_subscription($subscription, true);
        }
        
        // Update local subscription status to cancelled immediately
        $result = $db->update_subscription_status($subscription_id, 'cancelled');
        
        if ($result) {
            // Deactivate in SiberianCMS and update roles
            self::update_siberian_subscription(
                $subscription['admin_id'],
                $subscription['admin_email'],
                $subscription['application_id'],
                $subscription['siberian_plan_id'],
                'cancel'
            );
            
            self::log_message("Subscription {$subscription_id} cancelled successfully by admin or after pending period");
            
            // Return success
            wp_send_json_success(array(
                'message' => __('Subscription cancelled successfully', 'swiftspeed-siberian'),
                'subscription_id' => $subscription_id
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to cancel subscription', 'swiftspeed-siberian')));
        }
    }

    /**
     * Handle subscription deletion via AJAX.
     */
    public static function handle_delete_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Only admins can delete subscriptions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to delete subscriptions', 'swiftspeed-siberian')));
            return;
        }
        
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('No subscription specified', 'swiftspeed-siberian')));
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Only allow deletion of non-active and non-pending-cancellation subscriptions
        if ($subscription['status'] === 'active') {
            wp_send_json_error(array('message' => __('Cannot delete an active subscription. Cancel it first.', 'swiftspeed-siberian')));
            return;
        }
        
        if ($subscription['status'] === 'pending-cancellation') {
            wp_send_json_error(array('message' => __('Cannot delete a subscription in pending cancellation state. Cancel it completely first.', 'swiftspeed-siberian')));
            return;
        }
        
        // Delete the subscription
        $result = $db->delete_subscription($subscription_id);
        
        if ($result) {
            self::log_message("Subscription {$subscription_id} deleted successfully");
            
            wp_send_json_success(array(
                'message' => __('Subscription deleted successfully', 'swiftspeed-siberian')
            ));
        } else {
            wp_send_json_error(array('message' => __('Failed to delete subscription', 'swiftspeed-siberian')));
        }
    }
    
    /**
     * Handle bulk subscription deletion via AJAX.
     */
    public static function handle_bulk_delete_subscriptions() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Only admins can delete subscriptions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to delete subscriptions', 'swiftspeed-siberian')));
            return;
        }
        
        $subscription_ids = isset($_POST['subscription_ids']) ? $_POST['subscription_ids'] : array();
        if (empty($subscription_ids) || !is_array($subscription_ids)) {
            wp_send_json_error(array('message' => __('No subscriptions specified', 'swiftspeed-siberian')));
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        $deleted_count = 0;
        $active_count = 0;
        $pending_count = 0;
        
        // Process each subscription
        foreach ($subscription_ids as $subscription_id) {
            $subscription = $db->get_subscription($subscription_id);
            
            if (!$subscription) {
                continue;
            }
            
            // Only allow deletion of non-active and non-pending-cancellation subscriptions
            if ($subscription['status'] === 'active') {
                $active_count++;
                continue;
            }
            
            if ($subscription['status'] === 'pending-cancellation') {
                $pending_count++;
                continue;
            }
            
            // Delete the subscription
            $result = $db->delete_subscription($subscription_id);
            
            if ($result) {
                $deleted_count++;
            }
        }
        
        $message = '';
        if ($deleted_count > 0) {
            $message = sprintf(__('%d subscriptions deleted successfully.', 'swiftspeed-siberian'), $deleted_count);
        }
        
        if ($active_count > 0) {
            $message .= ' ' . sprintf(__('%d active subscriptions could not be deleted.', 'swiftspeed-siberian'), $active_count);
        }
        
        if ($pending_count > 0) {
            $message .= ' ' . sprintf(__('%d pending cancellation subscriptions could not be deleted.', 'swiftspeed-siberian'), $pending_count);
        }
        
        if ($deleted_count === 0 && $active_count === 0 && $pending_count === 0) {
            wp_send_json_error(array('message' => __('No subscriptions found to delete', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("Bulk deleted {$deleted_count} subscriptions");
        
        wp_send_json_success(array('message' => $message));
    }
    
    /**
     * Handle bulk subscription cancellation via AJAX.
     */
    public static function handle_bulk_cancel_subscriptions() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Only admins can perform bulk cancellations
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform bulk cancellations', 'swiftspeed-siberian')));
            return;
        }
        
        $subscription_ids = isset($_POST['subscription_ids']) ? $_POST['subscription_ids'] : array();
        if (empty($subscription_ids) || !is_array($subscription_ids)) {
            wp_send_json_error(array('message' => __('No subscriptions specified', 'swiftspeed-siberian')));
            return;
        }
        
        // Get DB module
        $db = self::get_db_module();
        
        $cancelled_count = 0;
        $already_cancelled_count = 0;
        
        // Process each subscription
        foreach ($subscription_ids as $subscription_id) {
            $subscription = $db->get_subscription($subscription_id);
            
            if (!$subscription) {
                continue;
            }
            
            // Skip already cancelled or expired
            if ($subscription['status'] === 'cancelled' || $subscription['status'] === 'expired') {
                $already_cancelled_count++;
                continue;
            }
            
            // Delegate to appropriate payment handler
            if ($subscription['payment_method'] === 'stripe') {
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/stripe/stripe-sub-handler.php';
                SwiftSpeed_Siberian_Stripe_Sub_Handler::cancel_subscription($subscription, true);
            } else if ($subscription['payment_method'] === 'paypal') {
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/payments/paypal/paypal-sub-handler.php';
                SwiftSpeed_Siberian_PayPal_Sub_Handler::cancel_subscription($subscription, true);
            }
            
            // Update status to cancelled
            $result = $db->update_subscription_status($subscription_id, 'cancelled');
            
            if ($result) {
                $cancelled_count++;
                
                // Deactivate in SiberianCMS and update roles
                self::update_siberian_subscription(
                    $subscription['admin_id'],
                    $subscription['admin_email'],
                    $subscription['application_id'],
                    $subscription['siberian_plan_id'],
                    'cancel'
                );
            }
        }
        
        $message = '';
        if ($cancelled_count > 0) {
            $message = sprintf(__('%d subscriptions cancelled successfully.', 'swiftspeed-siberian'), $cancelled_count);
        }
        
        if ($already_cancelled_count > 0) {
            $message .= ' ' . sprintf(__('%d subscriptions were already cancelled or expired.', 'swiftspeed-siberian'), $already_cancelled_count);
        }
        
        if ($cancelled_count === 0 && $already_cancelled_count === 0) {
            wp_send_json_error(array('message' => __('No subscriptions found to cancel', 'swiftspeed-siberian')));
            return;
        }
        
        self::log_message("Bulk cancelled {$cancelled_count} subscriptions");
        
        wp_send_json_success(array('message' => $message));
    }

    /**
     * AJAX handler for activating a subscription.
     */
    public static function handle_activate_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field($_POST['subscription_id']) : '';
        if (empty($subscription_id)) {
            wp_send_json_error(array('message' => __('Invalid subscription ID', 'swiftspeed-siberian')));
            return;
        }

        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }

        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            wp_send_json_error(array('message' => __('Database connection not configured', 'swiftspeed-siberian')));
            return;
        }

        // Update subscription data
        $update_data = array(
            'status' => 'active',
            'end_date' => self::calculate_end_date($subscription['billing_frequency']),
            'payment_status' => 'paid',
            'retry_count' => 0,
            'grace_period_end' => null,
            'retry_period_end' => null,
            'last_payment_date' => current_time('mysql')
        );
        
        $result = $db->update_subscription($subscription_id, $update_data);
        
        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to update subscription status', 'swiftspeed-siberian')));
            return;
        }

        // Activate in SiberianCMS
        $activation_result = self::activate_siberian_subscription(
            array(
                'admin_id' => $subscription['admin_id'],
                'admin_email' => $subscription['admin_email'],
                'application_id' => $subscription['application_id'],
                'siberian_sub_id' => $subscription['siberian_plan_id']
            ),
            null,
            $subscription_id
        );

        if (!$activation_result) {
            wp_send_json_error(array('message' => __('Failed to activate subscription in SiberianCMS', 'swiftspeed-siberian')));
            return;
        }

        wp_send_json_success(array('message' => __('Subscription activated successfully', 'swiftspeed-siberian')));
    }
    
    /**
     * Reactivate an expired subscription after successful renewal payment.
     */
    public static function reactivate_expired_subscription($subscription_id) {
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            self::log_message("Error: Cannot reactivate subscription - not found: $subscription_id");
            return false;
        }
        
        // Check if subscription is expired or cancelled
        if ($subscription['status'] !== 'expired' && $subscription['status'] !== 'cancelled') {
            self::log_message("Error: Cannot reactivate subscription that is not expired or cancelled: $subscription_id");
            return false;
        }
        
        // A cancelled subscription cannot be renewed
        if ($subscription['status'] === 'cancelled') {
            self::log_message("Error: Cannot renew a cancelled subscription: $subscription_id");
            return false;
        }
        
        // Update subscription data
        $update_data = array(
            'status' => 'active',
            'end_date' => self::calculate_end_date($subscription['billing_frequency']),
            'payment_status' => 'paid',
            'retry_count' => 0,
            'grace_period_end' => null,
            'retry_period_end' => null,
            'last_payment_date' => current_time('mysql'),
            'start_date' => current_time('mysql')
        );
        
        $result = $db->update_subscription($subscription_id, $update_data);
        
        if (!$result) {
            self::log_message("Error: Failed to update subscription status for reactivation: $subscription_id");
            return false;
        }
        
        // Activate in SiberianCMS and update roles
        $activation_result = self::activate_siberian_subscription(
            array(
                'admin_id' => $subscription['admin_id'],
                'admin_email' => $subscription['admin_email'],
                'application_id' => $subscription['application_id'],
                'siberian_sub_id' => $subscription['siberian_plan_id']
            ),
            null,
            $subscription_id
        );
        
        if (!$activation_result) {
            self::log_message("Warning: Failed to activate subscription in SiberianCMS: $subscription_id");
            // Continue anyway as the subscription record was updated
        } else {
            self::log_message("Successfully reactivated subscription $subscription_id in SiberianCMS");
        }
        
        self::log_message("Successfully reactivated subscription $subscription_id");
        return true;
    }
    
    /**
     * Get all subscriptions for the current user.
     * Updated to use database instead of options.
     */
    public static function get_user_subscriptions($user_id) {
        $db = self::get_db_module();
        return $db->get_user_subscriptions($user_id);
    }
    
    /**
     * Get all subscriptions (admin function).
     * Updated to use database instead of options.
     */
    public static function get_all_subscriptions() {
        $db = self::get_db_module();
        return $db->get_all_subscriptions();
    }
}