<?php
/**
 * Abstract Payment Gateway Base Class
 * 
 * Provides common functionality for all payment gateways.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Load the interface
require_once dirname(__FILE__) . '/payment-gateway-interface.php';

/**
 * Abstract base class for payment gateway implementations.
 */
abstract class SwiftSpeed_Siberian_Payment_Gateway_Base implements SwiftSpeed_Siberian_Payment_Gateway_Interface {
    
    /**
     * Gateway ID.
     * 
     * @var string
     */
    protected static $gateway_id = '';
    
    /**
     * Gateway name.
     * 
     * @var string
     */
    protected static $gateway_name = '';
    
    /**
     * Gateway options.
     * 
     * @var array
     */
    protected static $options = null;
    
    /**
     * DB module instance.
     * 
     * @var SwiftSpeed_Siberian_Subscriptions_DB
     */
    private static $db_module = null;
    
    /**
     * Initialize the payment gateway.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
    }
    
    /**
     * Get DB module instance.
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
     * Check if the gateway is enabled.
     *
     * @return bool True if enabled, false otherwise.
     */
    public static function is_enabled() {
        $settings = static::get_settings();
        return isset($settings['enabled']) && $settings['enabled'];
    }
    
    /**
     * Get the gateway settings.
     *
     * @return array Gateway settings.
     */
    public static function get_settings() {
        if (self::$options === null) {
            self::$options = get_option('swsib_options', array());
        }
        
        return isset(self::$options['subscription']['payment_gateways'][static::$gateway_id]) 
            ? self::$options['subscription']['payment_gateways'][static::$gateway_id] 
            : array();
    }
    
    /**
     * Central logging method.
     *
     * @param string $message The message to log.
     */
    protected static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'payment_' . static::$gateway_id, $message);
        }
    }
    
    /**
     * Default method to check if a subscription can be managed by this gateway.
     *
     * @param array $subscription Subscription data.
     * @return bool True if manageable, false otherwise.
     */
    public static function can_manage_subscription($subscription) {
        if (!isset($subscription['payment_method']) || $subscription['payment_method'] !== static::$gateway_id) {
            return false;
        }
        
        return self::is_enabled();
    }
    
    /**
     * After successful payment, trigger the activation process.
     *
     * @param string $subscription_id The subscription ID.
     * @param array $checkout_data The checkout data.
     * @param array $plan The plan data.
     * @return bool Success status.
     */
    protected static function trigger_activation($subscription_id, $checkout_data, $plan) {
        // Load subscription handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        // Activate in SiberianCMS
        $activation_result = SwiftSpeed_Siberian_Subscription_Handler::activate_siberian_subscription(
            $checkout_data,
            $plan,
            $subscription_id
        );
        
        if (!$activation_result) {
            self::log_message("Failed to activate subscription in SiberianCMS: {$subscription_id}");
            return false;
        }
        
        self::log_message("Successfully activated subscription in SiberianCMS: {$subscription_id}");
        return true;
    }
    
    /**
     * After cancellation, trigger the deactivation process.
     *
     * @param array $subscription The subscription data.
     * @return bool Success status.
     */
    protected static function trigger_deactivation($subscription) {
        // Load subscription handler
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/handlers/subscription-handler.php';
        
        // Deactivate in SiberianCMS
        $result = SwiftSpeed_Siberian_Subscription_Handler::update_siberian_subscription(
            $subscription['admin_id'],
            $subscription['admin_email'],
            $subscription['application_id'],
            $subscription['siberian_plan_id'],
            'cancel'
        );
        
        if (!$result) {
            self::log_message("Failed to deactivate subscription in SiberianCMS: {$subscription['id']}");
            return false;
        }
        
        self::log_message("Successfully deactivated subscription in SiberianCMS: {$subscription['id']}");
        return true;
    }
    
    /**
     * Update subscription status and trigger appropriate actions.
     * Updated to use database instead of options.
     *
     * @param string $subscription_id The subscription ID.
     * @param string $status The new status.
     * @param array $additional_data Additional data to update.
     * @return bool Success status.
     */
    protected static function update_subscription_status($subscription_id, $status, $additional_data = array()) {
        // Get DB module
        $db = self::get_db_module();
        
        // Get subscription
        $subscription = $db->get_subscription($subscription_id);
        
        if (!$subscription) {
            self::log_message("Subscription not found: {$subscription_id}");
            return false;
        }
        
        $old_status = $subscription['status'];
        
        // Only update if status is different
        if ($old_status !== $status) {
            // Update status and additional data
            $update_data = array_merge(array('status' => $status), $additional_data);
            $result = $db->update_subscription($subscription_id, $update_data);
            
            if (!$result) {
                self::log_message("Failed to update subscription status: {$subscription_id}");
                return false;
            }
            
            // Trigger actions based on status change
            if ($status === 'active' && ($old_status === 'expired' || $old_status === 'cancelled')) {
                self::trigger_activation(
                    $subscription_id,
                    array(
                        'admin_id' => $subscription['admin_id'],
                        'admin_email' => $subscription['admin_email'],
                        'application_id' => $subscription['application_id'],
                        'siberian_sub_id' => $subscription['siberian_plan_id']
                    ),
                    array() // Plan details not needed for reactivation
                );
            } elseif (($status === 'cancelled' || $status === 'expired') && $old_status === 'active') {
                self::trigger_deactivation($subscription);
            }
            
            // Fire a status change action for other components to hook into
            do_action('swsib_subscription_status_changed', $subscription_id, $old_status, $status);
            
            self::log_message("Updated subscription {$subscription_id} status from {$old_status} to {$status}");
            return true;
        }
        
        // Status was already set to the requested value
        return true;
    }
}