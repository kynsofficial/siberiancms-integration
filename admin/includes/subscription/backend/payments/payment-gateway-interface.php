<?php
/**
 * Payment Gateway Interface
 * 
 * Defines the standard interface that all payment gateways must implement.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Interface for payment gateway implementations.
 */
interface SwiftSpeed_Siberian_Payment_Gateway_Interface {
    
    /**
     * Initialize the payment gateway.
     */
    public static function init();
    
    /**
     * Process a payment for a subscription.
     *
     * @param array $payment_data Additional payment data from the form.
     * @param array $checkout_data Checkout session data.
     * @param array $customer_data Customer information.
     * @return void Response is sent via wp_send_json.
     */
    public static function process_payment($payment_data, $checkout_data, $customer_data);
    
    /**
     * Process a renewal payment for a subscription.
     *
     * @param string $subscription_id The subscription ID to renew.
     * @param array $payment_data Additional payment data.
     * @param array $customer_data Customer information.
     * @return void Response is sent via wp_send_json.
     */
    public static function process_renewal($subscription_id, $payment_data, $customer_data);
    
    /**
     * Handle webhooks from the payment gateway.
     *
     * @return void Sends appropriate HTTP response.
     */
    public static function handle_webhook();
    
    /**
     * Generate a payment management portal URL.
     *
     * @param string $subscription_id The subscription ID.
     * @param array $subscription The subscription data.
     * @return string|false Portal URL or false on failure.
     */
    public static function get_payment_portal($subscription_id, $subscription);
    
    /**
     * Check if a subscription can be managed via this gateway.
     *
     * @param array $subscription Subscription data.
     * @return bool True if manageable, false otherwise.
     */
    public static function can_manage_subscription($subscription);
    
    /**
     * Get the gateway settings.
     *
     * @return array Gateway settings.
     */
    public static function get_settings();
    
    /**
     * Check if the gateway is enabled.
     *
     * @return bool True if enabled, false otherwise.
     */
    public static function is_enabled();
}