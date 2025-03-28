<?php
/**
 * Stripe Subscription Handler
 *
 * Handles Stripe-specific subscription functionality.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle Stripe subscription operations.
 */
class SwiftSpeed_Siberian_Stripe_Sub_Handler {

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
     * Helper method to cancel a Stripe subscription
     */
    public static function cancel_stripe_subscription($stripe_subscription_id) {
        return self::cancel_stripe_subscription_immediately($stripe_subscription_id);
    }

    /**
     * Helper method to cancel a Stripe subscription immediately.
     */
    public static function cancel_stripe_subscription_immediately($stripe_sub_id) {
        // Get Stripe API key
        $options = get_option('swsib_options', array());
        $stripe_settings = isset($options['subscription']['payment_gateways']['stripe']) ? 
                         $options['subscription']['payment_gateways']['stripe'] : 
                         array();
        
        if (!isset($stripe_settings['enabled']) || !$stripe_settings['enabled']) {
            self::log_message("Cannot cancel Stripe subscription - Stripe is not enabled");
            return false;
        }
        
        $test_mode = isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'];
        $secret_key = $test_mode ? 
                    $stripe_settings['test_secret_key'] : 
                    $stripe_settings['live_secret_key'];
        
        if (empty($secret_key)) {
            self::log_message("Cannot cancel Stripe subscription - Secret key not configured");
            return false;
        }
        
        // Make API request to cancel the subscription
        $response = wp_remote_request(
            'https://api.stripe.com/v1/subscriptions/' . $stripe_sub_id,
            array(
                'method' => 'DELETE',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'timeout' => 30 // Increase timeout to 30 seconds
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error cancelling Stripe subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if ($response_code !== 200) {
            self::log_message("Error response from Stripe API: " . $body);
            return false;
        }
        
        if (isset($data->status) && $data->status === 'canceled') {
            self::log_message("Successfully cancelled Stripe subscription: " . $stripe_sub_id);
            return true;
        }
        
        self::log_message("Unknown response when cancelling Stripe subscription: " . $body);
        return false;
    }

    /**
     * Set Stripe subscription to cancel at period end.
     */
    public static function set_stripe_subscription_cancel_at_period_end($stripe_sub_id) {
        // Get Stripe API key
        $options = get_option('swsib_options', array());
        $stripe_settings = isset($options['subscription']['payment_gateways']['stripe']) ? 
                         $options['subscription']['payment_gateways']['stripe'] : 
                         array();
        
        if (!isset($stripe_settings['enabled']) || !$stripe_settings['enabled']) {
            self::log_message("Cannot update Stripe subscription - Stripe is not enabled");
            return false;
        }
        
        $test_mode = isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'];
        $secret_key = $test_mode ? 
                    $stripe_settings['test_secret_key'] : 
                    $stripe_settings['live_secret_key'];
        
        if (empty($secret_key)) {
            self::log_message("Cannot update Stripe subscription - Secret key not configured");
            return false;
        }
        
        // Make API request to set cancel_at_period_end to true
        $response = wp_remote_post(
            'https://api.stripe.com/v1/subscriptions/' . $stripe_sub_id,
            array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => array(
                    'cancel_at_period_end' => 'true'
                ),
                'timeout' => 30 // Increase timeout to 30 seconds
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error updating Stripe subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            self::log_message("Error response from Stripe API: " . $body);
            return false;
        }
        
        $data = json_decode($body);
        if (isset($data->cancel_at_period_end) && $data->cancel_at_period_end === true) {
            self::log_message("Successfully set Stripe subscription to cancel at period end: " . $stripe_sub_id);
            return true;
        }
        
        self::log_message("Unknown response when updating Stripe subscription: " . $body);
        return false;
    }

    /**
     * Helper method to uncancel a Stripe subscription.
     */
    public static function uncancel_stripe_subscription($stripe_sub_id) {
        // Get Stripe API key
        $options = get_option('swsib_options', array());
        $stripe_settings = isset($options['subscription']['payment_gateways']['stripe']) ? 
                         $options['subscription']['payment_gateways']['stripe'] : 
                         array();
        
        if (!isset($stripe_settings['enabled']) || !$stripe_settings['enabled']) {
            self::log_message("Cannot update Stripe subscription - Stripe is not enabled");
            return false;
        }
        
        $test_mode = isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'];
        $secret_key = $test_mode ? 
                    $stripe_settings['test_secret_key'] : 
                    $stripe_settings['live_secret_key'];
        
        if (empty($secret_key)) {
            self::log_message("Cannot update Stripe subscription - Secret key not configured");
            return false;
        }
        
        // Make API request to set cancel_at_period_end to false
        $response = wp_remote_post(
            'https://api.stripe.com/v1/subscriptions/' . $stripe_sub_id,
            array(
                'method' => 'POST',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ),
                'body' => array(
                    'cancel_at_period_end' => 'false'
                ),
                'timeout' => 30 // Increase timeout to 30 seconds
            )
        );
        
        if (is_wp_error($response)) {
            self::log_message("Error updating Stripe subscription: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            self::log_message("Error response from Stripe API: " . $body);
            return false;
        }
        
        $data = json_decode($body);
        if (isset($data->cancel_at_period_end) && $data->cancel_at_period_end === false) {
            self::log_message("Successfully uncancelled Stripe subscription: " . $stripe_sub_id);
            return true;
        }
        
        self::log_message("Unknown response when updating Stripe subscription: " . $body);
        return false;
    }

    /**
     * Set subscription to pending cancellation.
     */
    public static function set_pending_cancellation($subscription) {
        if ($subscription['payment_method'] !== 'stripe' || 
            !isset($subscription['payment_id']) || 
            strpos($subscription['payment_id'], 'sub_') !== 0) {
            return false;
        }
        
        self::log_message("Setting Stripe subscription to cancel at period end: {$subscription['payment_id']}");
        return self::set_stripe_subscription_cancel_at_period_end($subscription['payment_id']);
    }

    /**
     * Uncancel a subscription.
     */
    public static function uncancel_subscription($subscription) {
        if ($subscription['payment_method'] !== 'stripe' || 
            !isset($subscription['payment_id']) || 
            strpos($subscription['payment_id'], 'sub_') !== 0) {
            return false;
        }
        
        self::log_message("Resetting Stripe subscription cancel_at_period_end flag: {$subscription['payment_id']}");
        return self::uncancel_stripe_subscription($subscription['payment_id']);
    }

    /**
     * Cancel a subscription.
     */
    public static function cancel_subscription($subscription, $force_cancel = false) {
        if ($subscription['payment_method'] !== 'stripe' || 
            !isset($subscription['payment_id']) || 
            strpos($subscription['payment_id'], 'sub_') !== 0) {
            return false;
        }
        
        if ($force_cancel) {
            self::log_message("Admin force cancelling Stripe subscription immediately: {$subscription['payment_id']}");
            return self::cancel_stripe_subscription_immediately($subscription['payment_id']);
        } else {
            self::log_message("Setting Stripe subscription to cancel at period end: {$subscription['payment_id']}");
            return self::set_stripe_subscription_cancel_at_period_end($subscription['payment_id']);
        }
    }
}