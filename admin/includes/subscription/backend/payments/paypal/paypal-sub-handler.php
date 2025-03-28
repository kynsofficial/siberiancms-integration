<?php
/**
 * PayPal Subscription Handler
 *
 * Handles PayPal-specific subscription functionality.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle PayPal subscription operations.
 */
class SwiftSpeed_Siberian_PayPal_Sub_Handler {

    /**
     * Plugin options.
     */
    private static $options = null;

    /**
     * Initialize the handler.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
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
     * Get PayPal API credentials based on mode.
     */
    private static function get_api_credentials() {
        $options = get_option('swsib_options', array());
        $settings = isset($options['subscription']['payment_gateways']['paypal']) ? 
                 $options['subscription']['payment_gateways']['paypal'] : 
                 array();
                 
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
    private static function get_api_base_url() {
        $options = get_option('swsib_options', array());
        $settings = isset($options['subscription']['payment_gateways']['paypal']) ? 
                 $options['subscription']['payment_gateways']['paypal'] : 
                 array();
        $is_sandbox_mode = isset($settings['sandbox_mode']) && $settings['sandbox_mode'];
        
        return $is_sandbox_mode ? 
            'https://api-m.sandbox.paypal.com' : 
            'https://api-m.paypal.com';
    }

    /**
     * Get PayPal access token.
     */
    private static function get_access_token() {
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

    /**
     * Suspend PayPal subscription.
     */
    public static function suspend_paypal_subscription($paypal_subscription_id) {
        self::log_message("Suspending PayPal subscription: {$paypal_subscription_id}");
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            self::log_message("Failed to get PayPal access token for suspension");
            return false;
        }
        
        // Get API base URL
        $api_base_url = self::get_api_base_url();
        
        // Create suspension request
        $suspension_data = array(
            'reason' => 'Suspended by admin temporarily'
        );
        
        $response = wp_remote_post(
            $api_base_url . '/v1/billing/subscriptions/' . $paypal_subscription_id . '/suspend',
            array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($suspension_data)
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error suspending PayPal subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            self::log_message("Successfully suspended PayPal subscription: {$paypal_subscription_id}");
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            self::log_message("Failed to suspend PayPal subscription. Response code: {$response_code}, body: {$body}");
            return false;
        }
    }

    /**
     * Activate/resume PayPal subscription.
     */
    public static function activate_paypal_subscription($paypal_subscription_id) {
        self::log_message("Activating PayPal subscription: {$paypal_subscription_id}");
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            self::log_message("Failed to get PayPal access token for activation");
            return false;
        }
        
        // Get API base URL
        $api_base_url = self::get_api_base_url();
        
        // Create activation request
        $activation_data = array(
            'reason' => 'Activated by admin or customer request'
        );
        
        $response = wp_remote_post(
            $api_base_url . '/v1/billing/subscriptions/' . $paypal_subscription_id . '/activate',
            array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($activation_data)
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error activating PayPal subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 204) {
            self::log_message("Successfully activated PayPal subscription: {$paypal_subscription_id}");
            return true;
        } else {
            $body = wp_remote_retrieve_body($response);
            self::log_message("Failed to activate PayPal subscription. Response code: {$response_code}, body: {$body}");
            return false;
        }
    }

    /**
     * Get PayPal subscription details.
     */
    public static function get_subscription_details($paypal_subscription_id) {
        self::log_message("Getting PayPal subscription details: {$paypal_subscription_id}");
        
        // Get access token
        $access_token = self::get_access_token();
        
        if (!$access_token) {
            self::log_message("Failed to get PayPal access token");
            return false;
        }
        
        // Get API base URL
        $api_base_url = self::get_api_base_url();
        
        // Make request to get subscription details
        $response = wp_remote_get(
            $api_base_url . '/v1/billing/subscriptions/' . $paypal_subscription_id,
            array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json'
                )
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error getting PayPal subscription details: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            self::log_message("Error response from PayPal API: " . $body);
            return false;
        }
        
        $subscription_data = json_decode($body, true);
        
        if (!$subscription_data) {
            self::log_message("Invalid response from PayPal API: " . $body);
            return false;
        }
        
        return $subscription_data;
    }

    /**
     * Set subscription to pending cancellation.
     * For PayPal, we store the status locally and will cancel it when the period ends.
     */
/**
 * Set subscription to pending cancellation.
 * For PayPal, we store the status locally and will cancel it when the period ends.
 * Now includes cancellation_source tracking.
 */
public static function set_pending_cancellation($subscription) {
    if ($subscription['payment_method'] !== 'paypal' || !isset($subscription['payment_id'])) {
        return false;
    }
    
    self::log_message("Setting PayPal subscription to pending-cancellation locally: {$subscription['payment_id']}");
    
    // Get subscription details to check current status and next billing date
    $subscription_details = self::get_subscription_details($subscription['payment_id']);
    
    // Create update data with cancellation_source set to 'frontend'
    $update_data = array(
        'status' => 'pending-cancellation',
        'cancellation_source' => 'frontend'  // Set source to frontend since user initiated it
    );
    
    if ($subscription_details) {
        self::log_message("Retrieved PayPal subscription details: " . json_encode($subscription_details));
        
        // Store next billing date in the database for our local tracking
        if (isset($subscription_details['billing_info']) && isset($subscription_details['billing_info']['next_billing_time'])) {
            $update_data['next_billing_date'] = $subscription_details['billing_info']['next_billing_time'];
            self::log_message("Storing next billing date in database: {$subscription_details['billing_info']['next_billing_time']}");
        }
    }
    
    // Update the subscription with our data
    require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
    $db = new SwiftSpeed_Siberian_Subscriptions_DB();
    $result = $db->update_subscription($subscription['id'], $update_data);
    
    // Just return true to indicate we've processed it successfully
    return $result;
}


  /**
 * Uncancel a subscription.
 * For PayPal, we simply change the local status if it was only marked pending
 * and the cancellation was initiated from our frontend.
 */
public static function uncancel_subscription($subscription) {
    if ($subscription['payment_method'] !== 'paypal' || !isset($subscription['payment_id'])) {
        return false;
    }
    
    self::log_message("Attempting to resume PayPal subscription: {$subscription['payment_id']}");
    
    // Check if this was a frontend-initiated cancellation
    $cancellation_source = isset($subscription['cancellation_source']) ? $subscription['cancellation_source'] : '';
    
    if ($cancellation_source !== 'frontend') {
        self::log_message("Cannot uncancel - cancellation was not initiated from frontend (source: {$cancellation_source})");
        return false;
    }
    
    // If the subscription was only marked pending-cancellation locally (not cancelled in PayPal),
    // we can just update our local status
    
    // Get subscription details to check current status
    $subscription_details = self::get_subscription_details($subscription['payment_id']);
    
    if ($subscription_details && isset($subscription_details['status'])) {
        // If PayPal subscription is still active, we can safely uncancel locally
        if ($subscription_details['status'] === 'ACTIVE') {
            self::log_message("PayPal subscription is still active, can safely uncancel locally");
            
            // Update the database to remove pending cancellation status
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
            $db = new SwiftSpeed_Siberian_Subscriptions_DB();
            $db->update_subscription($subscription['id'], array(
                'status' => 'active',
                'cancellation_source' => null,
                'next_billing_date' => null  // Clear the planned cancellation date
            ));
            
            return true;
        }
        // If it's already cancelled in PayPal, we can't uncancel it
        else if ($subscription_details['status'] === 'CANCELLED') {
            self::log_message("Cannot uncancel - PayPal subscription is already cancelled");
            return false;
        }
    }
    
    // Default to allowing the action to proceed
    return true;
}


  /**
 * Cancel a subscription.
 */
public static function cancel_subscription($subscription, $force_cancel = false) {
    if ($subscription['payment_method'] !== 'paypal' || !isset($subscription['payment_id'])) {
        return false;
    }
    
    // Only actually cancel in PayPal if admin is force cancelling
    if ($force_cancel) {
        self::log_message("Admin force cancelling PayPal subscription immediately: {$subscription['payment_id']}");
        return self::cancel_paypal_subscription($subscription['payment_id']);
    } else {
        self::log_message("Not immediately cancelling PayPal subscription - will cancel at billing period end");
        
        // Get subscription details
        $subscription_details = self::get_subscription_details($subscription['payment_id']);
        
        if ($subscription_details) {
            // Store next billing date for our tracking
            if (isset($subscription_details['billing_info']) && isset($subscription_details['billing_info']['next_billing_time'])) {
                require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
                $db = new SwiftSpeed_Siberian_Subscriptions_DB();
                
                $db->update_subscription($subscription['id'], array(
                    'next_billing_date' => $subscription_details['billing_info']['next_billing_time'],
                    'cancellation_source' => 'frontend'  // Track this as frontend cancellation
                ));
                
                self::log_message("Updated next billing date in database for pending cancellation: {$subscription_details['billing_info']['next_billing_time']}");
            }
        }
        
        // Just return true to indicate we've processed it
        return true;
    }
  }
}