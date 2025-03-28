<?php
/**
 * WooCommerce Hook Loader – Consolidated File
 *
 * This file now combines the functionality of:
 *   1. WooCommerce hook loader (subscription status hooks, payment complete handling, etc.)
 *   2. Mapping functionality (formerly in class-swsib-woocommerce-mapping.php)
 *   3. Role management functionality (formerly in class-swsib-woocommerce-role-manager.php)
 *   4. Additional WooCommerce integration features previously in the main plugin file:
 *      - CORS headers
 *      - AJAX handlers for token-based subscription order creation
 *      - Cart rebuild on token
 *      - Custom data transfer to order/subscription
 *      - Frontend popups for product access and post-purchase
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class SwiftSpeed_Siberian_Woocommerce_Hook_Loader {

    /**
     * DB module instance cached for reuse.
     */
    private static $db_module = null;

    /**
     * Plugin options cached for reuse.
     */
    private static $options = null;

    /**
     * Initialize hooks.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());

        // Check if integration is enabled before registering hooks
        if (!self::is_integration_enabled()) {
            // Only register the toggle action when integration is disabled
            add_action('wp_ajax_swsib_toggle_woocommerce_integration', array(__CLASS__, 'ajax_toggle_integration'));
            return;
        }

        // Core integration hooks.
        add_action('plugins_loaded', array(__CLASS__, 'register_hooks'), 20);
        add_action('init', array(__CLASS__, 'register_subscription_hooks'), 999);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_complete'), 999);
        add_action('wp_ajax_swsib_woocommerce_update_mapping', array(__CLASS__, 'ajax_update_mapping'));
        add_action('wp_ajax_swsib_woocommerce_delete_mapping', array(__CLASS__, 'ajax_delete_mapping'));
        add_action('wp_ajax_swsib_toggle_woocommerce_integration', array(__CLASS__, 'ajax_toggle_integration'));

        // New WooCommerce integration hooks moved from the main file.
        add_action('init', array(__CLASS__, 'add_cors_headers'), 1);
        add_action('init', array(__CLASS__, 'register_direct_ajax_handlers'));
        add_action('wp_ajax_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));
        add_action('wp_ajax_nopriv_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));
        add_action('template_redirect', array(__CLASS__, 'rebuild_cart_upon_token'));
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'ensure_custom_data_transfer'), 20, 2);
        add_action('woocommerce_checkout_subscription_created', array(__CLASS__, 'transfer_custom_data_to_subscription'), 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'save_custom_data_to_order_items'), 10, 4);
        add_action('woocommerce_payment_complete', array(__CLASS__, 'activate_subscription_on_payment'), 10, 1);
        add_action('wp_footer', array(__CLASS__, 'enqueue_product_popup'));
        add_action('wp_footer', array(__CLASS__, 'enqueue_purchase_success_popup'));
    }

    /**
     * Check if WooCommerce integration is enabled.
     */
    public static function is_integration_enabled() {
        if (self::$options === null) {
            self::$options = get_option('swsib_options', array());
        }
        
        return isset(self::$options['woocommerce']['integration_enabled']) && 
               filter_var(self::$options['woocommerce']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * AJAX handler for toggling WooCommerce integration.
     */
    public static function ajax_toggle_integration() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_woocommerce_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        $enable = isset($_POST['enable']) ? filter_var($_POST['enable'], FILTER_VALIDATE_BOOLEAN) : false;
        
        $options = get_option('swsib_options', array());
        
        // Check if PE Subscription is enabled
        $pe_enabled = isset($options['subscription']['integration_enabled']) && 
                      filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        
        if ($enable && $pe_enabled) {
            wp_send_json_error(array(
                'message' => __('PE Subscription integration is currently active. Please disable it first.', 'swiftspeed-siberian'),
                'conflict' => true
            ));
            return;
        }
        
        // Update WooCommerce integration status
        if (!isset($options['woocommerce'])) {
            $options['woocommerce'] = array();
        }
        
        $options['woocommerce']['integration_enabled'] = $enable;
        update_option('swsib_options', $options);
        self::$options = $options;
        
        self::log_message("WooCommerce integration " . ($enable ? "enabled" : "disabled"));
        
        wp_send_json_success(array(
            'message' => $enable ? 
                __('WooCommerce integration enabled successfully', 'swiftspeed-siberian') : 
                __('WooCommerce integration disabled successfully', 'swiftspeed-siberian'),
            'status' => $enable
        ));
    }

    /**
     * Standard logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('woocommerce', 'backend', $message);
        }
    }

    /**
     * Register all hooks needed for WooCommerce integration.
     */
    public static function register_hooks() {
        if ( ! class_exists('WooCommerce') || ! class_exists('WC_Subscriptions') ) {
            return;
        }
        self::register_subscription_hooks();
    }

    /**
     * Register subscription-specific hooks.
     */
    public static function register_subscription_hooks() {
        if ( ! class_exists('WooCommerce') || ! class_exists('WC_Subscriptions') ) {
            return;
        }
        add_action('woocommerce_subscription_status_active', array(__CLASS__, 'subscription_activated'), 999);
        add_action('woocommerce_subscription_status_cancelled', array(__CLASS__, 'subscription_cancelled'), 999);
        add_action('woocommerce_subscription_status_expired', array(__CLASS__, 'subscription_expired'), 999);
        add_action('woocommerce_subscription_status_on-hold', array(__CLASS__, 'subscription_cancelled'), 999);
        add_action('woocommerce_subscription_status_changed', array(__CLASS__, 'subscription_status_changed'), 999, 3);
        // Removed subscription_status_updated hook to prevent duplicates.
    }

    /**
     * AJAX handler for updating a mapping.
     */
    public static function ajax_update_mapping() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'swsib_woocommerce_nonce') ) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if ( ! class_exists('WooCommerce') || ! class_exists('WC_Subscriptions') ) {
            wp_send_json_error(array('message' => __('WooCommerce or Subscriptions inactive', 'swiftspeed-siberian')));
        }
        $siberian_plan_id = isset($_POST['siberian_plan_id']) ? intval($_POST['siberian_plan_id']) : 0;
        $woo_product_id   = isset($_POST['woo_product_id']) ? intval($_POST['woo_product_id']) : 0;
        $role_id          = isset($_POST['role_id']) ? sanitize_text_field($_POST['role_id']) : '2';

        if ( $siberian_plan_id && $woo_product_id ) {
            $mappings = self::get_woocommerce_mappings();
            foreach ($mappings as $m) {
                if ((string)$m['siberian_plan_id'] === (string)$siberian_plan_id) {
                    wp_send_json_error(array('message' => __('This Siberian plan is already mapped', 'swiftspeed-siberian')));
                    return;
                }
                if ((string)$m['woo_product_id'] === (string)$woo_product_id) {
                    wp_send_json_error(array('message' => __('This WooCommerce product is already mapped', 'swiftspeed-siberian')));
                    return;
                }
            }
            $new_mapping = array(
                'id'               => uniqid(),
                'siberian_plan_id' => $siberian_plan_id,
                'woo_product_id'   => $woo_product_id,
                'role_id'          => $role_id
            );
            $options = get_option('swsib_options', array());
            if ( ! isset($options['woocommerce']) ) {
                $options['woocommerce'] = array();
            }
            if ( ! isset($options['woocommerce']['mappings']) ) {
                $options['woocommerce']['mappings'] = array();
            }
            $options['woocommerce']['mappings'][] = $new_mapping;
            update_option('swsib_options', $options);
            self::$options = $options;
            self::log_message("Added new mapping: Siberian plan ID {$siberian_plan_id} to WooCommerce product ID {$woo_product_id} with role ID {$role_id}");
            wp_send_json_success(array(
                'message' => __('Mapping added successfully', 'swiftspeed-siberian'),
                'mapping' => $new_mapping
            ));
        } else {
            wp_send_json_error(array('message' => __('Missing required fields', 'swiftspeed-siberian')));
        }
    }

    /**
     * AJAX handler for deleting a mapping.
     */
    public static function ajax_delete_mapping() {
        if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'swsib_woocommerce_nonce') ) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if ( ! class_exists('WooCommerce') || ! class_exists('WC_Subscriptions') ) {
            wp_send_json_error(array('message' => __('WooCommerce or Subscriptions inactive', 'swiftspeed-siberian')));
        }
        $mapping_id = isset($_POST['mapping_id']) ? sanitize_text_field($_POST['mapping_id']) : '';
        if ( empty($mapping_id) ) {
            wp_send_json_error(array('message' => __('Missing mapping ID', 'swiftspeed-siberian')));
            return;
        }
        $options = get_option('swsib_options', array());
        if ( ! isset($options['woocommerce']['mappings']) ) {
            wp_send_json_error(array('message' => __('No mappings found', 'swiftspeed-siberian')));
            return;
        }
        $mappings = $options['woocommerce']['mappings'];
        $found = false;
        foreach ($mappings as $key => $map) {
            if ($map['id'] === $mapping_id) {
                self::log_message("Deleting mapping with ID: {$mapping_id} (Siberian plan: {$map['siberian_plan_id']}, WooCommerce product: {$map['woo_product_id']})");
                unset($mappings[$key]);
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            wp_send_json_error(array('message' => __('Mapping not found', 'swiftspeed-siberian')));
            return;
        }
        $options['woocommerce']['mappings'] = array_values($mappings);
        update_option('swsib_options', $options);
        self::$options = $options;
        wp_send_json_success(array('message' => __('Mapping deleted successfully', 'swiftspeed-siberian')));
    }

    /**
     * Handle WooCommerce payment complete – check for subscriptions.
     */
    public static function handle_payment_complete($order_id) {
        self::log_message("Payment complete for order #{$order_id}, checking for subscriptions");
        if ( ! function_exists('wcs_get_subscriptions_for_order') ) {
            return;
        }
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        if ( empty($subscriptions) ) {
            self::log_message("No subscriptions found for order #{$order_id}");
            return;
        }
        foreach ($subscriptions as $subscription) {
            $subscription_id = $subscription->get_id();
            self::log_message("Force processing subscription #{$subscription_id} after payment");
            self::subscription_activated($subscription);
        }
    }

    /**
     * Handle general subscription status changes.
     */
    public static function subscription_status_changed($subscription_id, $old_status, $new_status) {
        self::log_message("Subscription #{$subscription_id} status changed from {$old_status} to {$new_status}");
        $subscription = wcs_get_subscription($subscription_id);
        if ( ! $subscription ) {
            self::log_message("Could not find subscription #{$subscription_id}");
            return;
        }
        if ($new_status === 'active') {
            self::subscription_activated($subscription);
        } else if (in_array($new_status, array('cancelled', 'expired', 'on-hold'))) {
            self::subscription_cancelled($subscription);
        }
    }

    /**
     * Handle subscription activation.
     * 
     * Added a guard so that if the subscription was already processed,
     * the function will exit early.
     */
    public static function subscription_activated($subscription) {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        
        // Guard: if already processed for activation, exit.
        if ($subscription->get_meta('_swsib_processed_activation') === '1') {
            self::log_message("Subscription #{$subscription_id} already activated. Skipping duplicate processing.");
            return;
        }
        
        self::log_message("=== Processing activation for subscription #{$subscription_id} ===");
        if ( ! function_exists('swsib') ) {
            self::log_message("Error: swsib() function not available for subscription activation");
            return;
        }
        $custom_data = self::extract_custom_data($subscription);
        if (empty($custom_data)) {
            self::log_message("No custom data found for subscription #{$subscription_id}");
            return;
        }
        self::log_message("Found custom data: " . print_r($custom_data, true));
        if ($subscription->get_status() !== 'active') {
            $subscription->update_status('active');
            self::log_message("Forced subscription to active status");
        }
        $success = self::update_siberian_subscription($subscription, $custom_data, 'activate');
        self::log_message("Siberian integration " . ($success ? "completed successfully" : "failed") . " for subscription #{$subscription_id}");
        
        // Mark this subscription as processed for activation.
        $subscription->update_meta_data('_swsib_processed_activation', '1');
        $subscription->save();
    }

    /**
     * Handle subscription cancellation.
     * 
     * Added a guard so that if the subscription was already processed for cancellation,
     * the function will exit early.
     */
    public static function subscription_cancelled($subscription) {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        
        // Guard: if already processed for cancellation, exit.
        if ($subscription->get_meta('_swsib_processed_cancellation') === '1') {
            self::log_message("Subscription #{$subscription_id} already cancelled. Skipping duplicate processing.");
            return;
        }
        
        self::log_message("=== Processing cancellation for subscription #{$subscription_id} ===");
        if ( ! function_exists('swsib') ) {
            self::log_message("Error: swsib() function not available for subscription cancellation");
            return;
        }
        $custom_data = self::extract_custom_data($subscription);
        if (empty($custom_data)) {
            self::log_message("No custom data found for subscription #{$subscription_id}");
            return;
        }
        self::log_message("Found custom data: " . print_r($custom_data, true));
        $success = self::update_siberian_subscription($subscription, $custom_data, 'cancel');
        self::log_message("Siberian integration " . ($success ? "completed successfully" : "failed") . " for subscription #{$subscription_id}");
        
        // Mark as processed for cancellation.
        $subscription->update_meta_data('_swsib_processed_cancellation', '1');
        $subscription->save();
    }

    /**
     * Handle subscription expiration (same as cancellation).
     */
    public static function subscription_expired($subscription) {
        self::log_message("Subscription expired – handling as cancellation");
        self::subscription_cancelled($subscription);
    }

    /**
     * Update SiberianCMS subscription.
     *
     * NOTE: Always update/delete the subscription_application FIRST then re-check final role assignment.
     */
    private static function update_siberian_subscription($subscription, $custom_data, $action = 'activate') {
        self::log_message("Updating SiberianCMS subscription – Action: {$action}");
        $db_module = self::get_db_module();
        if ( ! $db_module ) {
            self::log_message("Failed to load DB module for integration");
            return false;
        }
        if (empty($custom_data['applicationId'])) {
            self::log_message("Missing required applicationId in custom data");
            return false;
        }
        $application_id = intval($custom_data['applicationId']);
        $admin_email = isset($custom_data['adminEmail']) ? $custom_data['adminEmail'] : '';
        if (empty($admin_email)) {
            self::log_message("No adminEmail found in custom data, cannot look up user");
        }
        $siberian_subscription_id = null;
        if ( ! empty($custom_data['selectedSubscriptionId'])) {
            $siberian_subscription_id = $custom_data['selectedSubscriptionId'];
            self::log_message("Using selectedSubscriptionId from custom data: {$siberian_subscription_id}");
        } else {
            $items = $subscription->get_items();
            if ( ! empty($items)) {
                $item = reset($items);
                $product_id = $item->get_product_id();
                $siberian_subscription_id = self::get_siberian_id_from_product($product_id);
                if ($siberian_subscription_id) {
                    self::log_message("Found Siberian subscription ID {$siberian_subscription_id} from product mapping");
                } else {
                    self::log_message("Could not find Siberian subscription ID from product mapping");
                    return false;
                }
            } else {
                self::log_message("No items found in subscription");
                return false;
            }
        }
        if ( ! $siberian_subscription_id ) {
            self::log_message("Could not determine Siberian subscription ID");
            return false;
        }
        $sub_app_success = false;
        if ($action === 'activate') {
            $sub_app_success = $db_module->create_or_update_subscription_application(
                $application_id,
                $siberian_subscription_id,
                $subscription->get_id()
            );
            self::log_message("Subscription application create/update result: " . ($sub_app_success ? "Success" : "Failed"));
            if ($sub_app_success) {
                $unlock_result = $db_module->unlock_application($application_id);
                self::log_message("Application unlock result: " . ($unlock_result ? "Success" : "Failed"));
            }
        } else {
            $sub_app_success = $db_module->delete_subscription_application(
                $application_id,
                $siberian_subscription_id
            );
            self::log_message("Subscription application delete result: " . ($sub_app_success ? "Success" : "Failed"));
            if ($sub_app_success) {
                $lock_result = $db_module->lock_application($application_id);
                self::log_message("Application lock result: " . ($lock_result ? "Success" : "Failed"));
            }
        }
        if (empty($admin_email)) {
            return $sub_app_success;
        }
        $admin = $db_module->find_admin_by_email($admin_email);
        if ( ! $admin ) {
            self::log_message("Admin not found for email: {$admin_email}");
            return $sub_app_success;
        }
        $admin_id  = $admin['admin_id'];
        $current_role_id = $admin['role_id'];
        self::log_message("Found admin ID: {$admin_id} with current role: {$current_role_id}");
        
        // Check if admin is a super admin (role ID 1) - if so, exit and don't change role
        if ($current_role_id == '1') {
            self::log_message("Admin {$admin_id} is a super admin (role ID 1). Skipping role update.");
            return $sub_app_success;
        }
        
        $active_subscriptions = $db_module->check_admin_active_subscriptions($admin_id);
        self::log_message("Found " . count($active_subscriptions) . " active subscriptions for admin ID {$admin_id}");
        if (empty($active_subscriptions)) {
            $fallback_role_id = self::get_fallback_role_id();
            self::log_message("No active subscriptions remain. Using fallback role ID: {$fallback_role_id}");
            if ($current_role_id != $fallback_role_id) {
                $result = $db_module->update_admin_role($admin_id, $fallback_role_id);
                self::log_message("Fallback role update result: " . ($result ? "Success" : "Failed"));
            } else {
                self::log_message("Admin already has fallback role ID: {$fallback_role_id}");
            }
            return $sub_app_success;
        }
        $assigned_roles = array();
        $mappings = self::get_woocommerce_mappings();
        foreach ($active_subscriptions as $sub) {
            $active_siberian_id = $sub['subscription_id'];
            foreach ($mappings as $mapping) {
                if ((string)$mapping['siberian_plan_id'] === (string)$active_siberian_id) {
                    $assigned_roles[] = $mapping['role_id'];
                    self::log_message("Adding role ID: {$mapping['role_id']} from active subscription #{$active_siberian_id}");
                    break;
                }
            }
        }
        $assigned_roles = array_unique($assigned_roles);
        self::log_message("Assigned roles after deduplication: " . implode(', ', $assigned_roles));
        if (empty($assigned_roles)) {
            $fallback_role_id = self::get_fallback_role_id();
            self::log_message("No mapped roles found, reverting to fallback role: {$fallback_role_id}");
            if ($current_role_id != $fallback_role_id) {
                $result = $db_module->update_admin_role($admin_id, $fallback_role_id);
                self::log_message("Fallback role update result: " . ($result ? "Success" : "Failed"));
            } else {
                self::log_message("Admin already has fallback role ID: {$fallback_role_id}");
            }
            return $sub_app_success;
        }
        $highest_role = null;
        $role_priorities = self::get_role_priorities();
        foreach ($role_priorities as $priority_role) {
            if (in_array($priority_role, $assigned_roles)) {
                $highest_role = $priority_role;
                self::log_message("Highest priority role determined: {$highest_role}");
                break;
            }
        }
        if (!$highest_role) {
            $highest_role = reset($assigned_roles);
            self::log_message("No priority-based match, using first role: {$highest_role}");
        }
        if ($highest_role && ($current_role_id != $highest_role)) {
            $result = $db_module->update_admin_role($admin_id, $highest_role);
            self::log_message("Admin role update to highest priority result: " . ($result ? "Success" : "Failed"));
        } else {
            self::log_message("No role change needed");
        }
        return $sub_app_success;
    }

    /**
     * Extract custom data from subscription and order using fallback methods.
     */
    private static function extract_custom_data($subscription) {
        $subscription_id = $subscription->get_id();
        self::log_message("Extracting custom data for subscription #{$subscription_id}");
        $custom_data = array();
        $data_keys = array('_swsib_custom_data', '_swiftspeed_custom_data');
        foreach ($data_keys as $key) {
            $subscription_data = $subscription->get_meta($key, true);
            if ( ! empty($subscription_data)) {
                self::log_message("Found custom data in subscription meta {$key}");
                return $subscription_data;
            }
        }
        $fields = array(
            'adminId' => $subscription->get_meta('_adminId', true),
            'applicationId' => $subscription->get_meta('_applicationId', true),
            'adminEmail' => $subscription->get_meta('_adminEmail', true),
            'selectedSubscriptionId' => $subscription->get_meta('_selectedSubscriptionId', true)
        );
        $has_data = false;
        foreach ($fields as $k => $v) {
            if ( ! empty($v)) {
                $custom_data[$k] = $v;
                $has_data = true;
            }
        }
        if ($has_data) {
            self::log_message("Built custom data from individual subscription meta fields");
            return $custom_data;
        }
        $order = method_exists($subscription, 'get_parent') ? $subscription->get_parent() : wc_get_order($subscription->get_parent_id());
        if ($order) {
            foreach ($data_keys as $key) {
                $order_data = $order->get_meta($key, true);
                if ( ! empty($order_data)) {
                    self::log_message("Found custom data in order meta {$key}");
                    return $order_data;
                }
            }
            $fields = array(
                'adminId' => $order->get_meta('_adminId', true),
                'applicationId' => $order->get_meta('_applicationId', true),
                'adminEmail' => $order->get_meta('_adminEmail', true),
                'selectedSubscriptionId' => $order->get_meta('_selectedSubscriptionId', true)
            );
            $has_data = false;
            foreach ($fields as $k => $v) {
                if ( ! empty($v)) {
                    $custom_data[$k] = $v;
                    $has_data = true;
                }
            }
            if ($has_data) {
                self::log_message("Built custom data from individual order meta fields");
                return $custom_data;
            }
            foreach ($order->get_items() as $item) {
                foreach ($data_keys as $key) {
                    $item_data = $item->get_meta($key, true);
                    if ( ! empty($item_data)) {
                        self::log_message("Found custom data in order item meta {$key}");
                        return $item_data;
                    }
                }
                $fields = array(
                    'adminId' => $item->get_meta('_adminId', true),
                    'applicationId' => $item->get_meta('_applicationId', true),
                    'adminEmail' => $item->get_meta('_adminEmail', true),
                    'selectedSubscriptionId' => $item->get_meta('_selectedSubscriptionId', true)
                );
                $has_data = false;
                foreach ($fields as $k => $v) {
                    if ( ! empty($v)) {
                        $custom_data[$k] = $v;
                        $has_data = true;
                    }
                }
                if ($has_data) {
                    self::log_message("Built custom data from individual order item meta fields");
                    return $custom_data;
                }
            }
        }
        foreach ($subscription->get_items() as $item) {
            foreach ($data_keys as $key) {
                $item_data = $item->get_meta($key, true);
                if ( ! empty($item_data)) {
                    self::log_message("Found custom data in subscription item meta {$key}");
                    return $item_data;
                }
            }
            $fields = array(
                'adminId' => $item->get_meta('_adminId', true),
                'applicationId' => $item->get_meta('_applicationId', true),
                'adminEmail' => $item->get_meta('_adminEmail', true),
                'selectedSubscriptionId' => $item->get_meta('_selectedSubscriptionId', true)
            );
            $has_data = false;
            foreach ($fields as $k => $v) {
                if ( ! empty($v)) {
                    $custom_data[$k] = $v;
                    $has_data = true;
                }
            }
            if ($has_data) {
                self::log_message("Built custom data from individual subscription item meta fields");
                return $custom_data;
            }
        }
        self::log_message("No custom data found after all extraction attempts");
        return $custom_data;
    }

    /**
     * Get DB module instance.
     */
    private static function get_db_module() {
        if (self::$db_module !== null) {
            return self::$db_module;
        }
        if (function_exists('swsib') && swsib() && isset(swsib()->woocommerce) && isset(swsib()->woocommerce->db)) {
            self::$db_module = swsib()->woocommerce->db;
            self::log_message("Using DB module from main plugin instance");
            return self::$db_module;
        }
        $db_class_file = SWSIB_PLUGIN_DIR . 'admin/includes/woocommerce/class-swsib-woocommerce-db.php';
        self::log_message("Trying to load DB module from: $db_class_file");
        if (file_exists($db_class_file)) {
            require_once $db_class_file;
            self::$db_module = new SwiftSpeed_Siberian_WooCommerce_DB();
            self::log_message("Successfully loaded DB module from file");
            return self::$db_module;
        }
        $alternate_db_class_file = SWSIB_PLUGIN_DIR . 'admin/includes/woocommerce/class-swsib-woocommerce-db.php';
        if ($db_class_file !== $alternate_db_class_file) {
            self::log_message("Trying alternate case for DB module: $alternate_db_class_file");
            if (file_exists($alternate_db_class_file)) {
                require_once $alternate_db_class_file;
                self::$db_module = new SwiftSpeed_Siberian_WooCommerce_DB();
                self::log_message("Successfully loaded DB module from alternate case file");
                return self::$db_module;
            }
        }
        if (class_exists('SwiftSpeed_Siberian_WooCommerce_DB')) {
            self::log_message("DB module class already exists, creating new instance");
            self::$db_module = new SwiftSpeed_Siberian_WooCommerce_DB();
            return self::$db_module;
        }
        $possible_paths = array(
            SWSIB_PLUGIN_DIR . 'admin/includes/dbconnect/class-swsib-woocommerce-db.php',
            SWSIB_PLUGIN_DIR . 'includes/class-swsib-woocommerce-db.php',
            SWSIB_PLUGIN_DIR . 'includes/woocommerce/class-swsib-woocommerce-db.php'
        );
        foreach ($possible_paths as $path) {
            self::log_message("Checking alternative path for DB module: $path");
            if (file_exists($path)) {
                require_once $path;
                if (class_exists('SwiftSpeed_Siberian_WooCommerce_DB')) {
                    self::$db_module = new SwiftSpeed_Siberian_WooCommerce_DB();
                    self::log_message("Successfully loaded DB module from alternative path");
                    return self::$db_module;
                }
            }
        }
        self::log_message("Could not load DB module class file or class");
        return false;
    }

    /**
     * Get WooCommerce mappings from plugin options.
     */
    private static function get_woocommerce_mappings() {
        if (self::$options === null) {
            self::$options = get_option('swsib_options', array());
        }
        $woo_opts = isset(self::$options['woocommerce']) ? self::$options['woocommerce'] : array();
        return isset($woo_opts['mappings']) ? $woo_opts['mappings'] : array();
    }

    /**
     * Get role priorities from settings.
     */
    private static function get_role_priorities() {
        if (self::$options === null) {
            self::$options = get_option('swsib_options', array());
        }
        $woo_opts = isset(self::$options['woocommerce']) ? self::$options['woocommerce'] : array();
        return isset($woo_opts['role_priorities']) ? $woo_opts['role_priorities'] : array();
    }

    /**
     * Get fallback role ID from settings.
     */
    private static function get_fallback_role_id() {
        if (self::$options === null) {
            self::$options = get_option('swsib_options', array());
        }
        $woo_opts = isset(self::$options['woocommerce']) ? self::$options['woocommerce'] : array();
        return isset($woo_opts['fallback_role_id']) ? $woo_opts['fallback_role_id'] : '2';
    }

    /**
     * Get Siberian subscription ID from WooCommerce product ID.
     */
    private static function get_siberian_id_from_product($product_id) {
        $mappings = self::get_woocommerce_mappings();
        foreach ($mappings as $mapping) {
            if ((string)$mapping['woo_product_id'] === (string)$product_id) {
                return $mapping['siberian_plan_id'];
            }
        }
        return null;
    }

    /* ===============================================
       NEW FUNCTIONS – moved from main plugin file
       =============================================== */

    /**
     * Add CORS headers based on allowed origins.
     */
    public static function add_cors_headers() {
        $allowed_origins = array();
        $woo_integration = isset(swsib()->woocommerce) ? swsib()->woocommerce : null;
        if ($woo_integration && method_exists($woo_integration, 'get_allowed_origins_list')) {
            $allowed_origins = $woo_integration->get_allowed_origins_list();
        } else {
            $options  = get_option('swsib_options', array());
            $woo_opts = isset($options['woocommerce']) ? $options['woocommerce'] : array();
            $allowed_origins_list = isset($woo_opts['allowed_origins_list']) ? $woo_opts['allowed_origins_list'] : array();
            foreach ($allowed_origins_list as $entry) {
                if ( ! empty($entry['url'])) {
                    $allowed_origins[] = rtrim($entry['url'], '/');
                }
            }
        }
        $current_home = rtrim(home_url(), '/');
        if (!in_array($current_home, $allowed_origins, true)) {
            $allowed_origins[] = $current_home;
        }
        $parsed = parse_url(home_url());
        if (isset($parsed['scheme'], $parsed['host'])) {
            $root_domain = $parsed['scheme'] . '://' . $parsed['host'];
            if (!in_array($root_domain, $allowed_origins, true)) {
                $allowed_origins[] = $root_domain;
            }
        }
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
     * Register direct AJAX handlers.
     */
    public static function register_direct_ajax_handlers() {
        add_action('wp_ajax_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));
        add_action('wp_ajax_nopriv_create_subscription_order', array(__CLASS__, 'handle_create_subscription_order'));
    }

    /**
     * AJAX handler for subscription order creation (token-based).
     */
    public static function handle_create_subscription_order() {
        self::log_message("=== START: Create Subscription Order Process ===");
        $raw_payload = file_get_contents('php://input');
        if ( ! empty($raw_payload)) {
            self::log_message("RAW JSON PAYLOAD: " . $raw_payload);
            $json_data = json_decode($raw_payload, true);
            if (is_array($json_data)) {
                $_POST = array_merge($_POST, $json_data);
                self::log_message("Merged JSON payload with \$_POST");
            }
        }
        $admin_id    = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : 0;
        $app_id      = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
        $admin_email = isset($_POST['admin_email']) ? sanitize_email($_POST['admin_email']) : '';
        $sub_id      = isset($_POST['selected_subscription_id']) ? sanitize_text_field($_POST['selected_subscription_id']) : '';
        self::log_message("Data received - admin_id: $admin_id, app_id: $app_id, email: $admin_email, sub_id: $sub_id");
        if (empty($admin_email) || empty($sub_id)) {
            self::log_message("ERROR: Missing required fields");
            wp_send_json_error(array('message' => 'Missing required fields.'));
            return;
        }
        $user = get_user_by('email', $admin_email);
        if ( ! $user) {
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
                wp_send_json_error(array('message' => 'User creation failed: ' . $user_id->get_error_message()));
                return;
            }
            $user = get_user_by('id', $user_id);
            wp_new_user_notification($user_id, null, 'user');
            self::log_message("User created: ID $user_id, username: $new_username");
        } else {
            self::log_message("User found: ID {$user->ID}, username: {$user->user_login}");
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        $options   = get_option('swsib_options', array());
        $woo_opts  = isset($options['woocommerce']) ? $options['woocommerce'] : array();
        $mappings  = isset($woo_opts['mappings']) ? $woo_opts['mappings'] : array();
        self::log_message("Found " . count($mappings) . " mapping(s)");
        $product_id = null;
        foreach ($mappings as $mapping) {
            self::log_message("Mapping: Siberian ID {$mapping['siberian_plan_id']} => WooCommerce Product {$mapping['woo_product_id']}");
            if ((string)$mapping['siberian_plan_id'] === (string)$sub_id) {
                $product_id = $mapping['woo_product_id'];
                self::log_message("Matched subscription $sub_id to product $product_id");
                break;
            }
        }
        if ( ! $product_id) {
            self::log_message("ERROR: No mapping found for subscription ID $sub_id");
            wp_send_json_error(array('message' => 'No product mapping found for this subscription.'));
            return;
        }
        self::log_message("Verifying existence of product ID $product_id");
        $wc_product = wc_get_product($product_id);
        if ( ! $wc_product) {
            self::log_message("ERROR: Product ID $product_id not found");
            wp_send_json_error(array('message' => 'Product does not exist in WooCommerce.'));
            return;
        }
        self::log_message("Product verified: " . $wc_product->get_name());
        $token = uniqid('swsib_token_', true);
        $transient_data = array(
            'user_id'       => $user->ID,
            'admin_id'      => $admin_id,
            'application_id'=> $app_id,
            'admin_email'   => $admin_email,
            'siberian_sub'  => $sub_id,
            'wc_product_id' => $product_id,
            'timestamp'     => time()
        );
        set_transient($token, $transient_data, 3600);
        $token_url = add_query_arg('swsib_token', $token, home_url('/'));
        self::log_message("Returning token URL: $token_url");
        self::log_message("=== END: Create Subscription Order Process ===");
        wp_send_json_success(array('checkout_url' => $token_url));
    }

    /**
     * Rebuild cart upon token during template_redirect.
     */
    public static function rebuild_cart_upon_token() {
        if ( ! isset($_GET['swsib_token'])) {
            return;
        }
        $token = sanitize_text_field($_GET['swsib_token']);
        $data  = get_transient($token);
        self::log_message("Processing token: $token");
        if ( ! $data) {
            self::log_message("Token data not found or expired");
            wp_redirect(home_url('/cart/'));
            exit;
        }
        self::log_message("Token data retrieved: " . print_r($data, true));
        $user = get_user_by('ID', $data['user_id']);
        if ($user) {
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);
            self::log_message("User authenticated: {$user->ID}");
        } else {
            self::log_message("User not found: {$data['user_id']}");
        }
        if (function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if ( ! isset(WC()->cart)) {
            self::log_message("WooCommerce cart not available");
            wp_redirect(home_url('/cart/'));
            exit;
        }
        WC()->cart->empty_cart();
        $custom_data = array(
            'adminId' => $data['admin_id'],
            'applicationId' => $data['application_id'],
            'adminEmail' => $data['admin_email'],
            'selectedSubscriptionId' => $data['siberian_sub']
        );
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('swsib_custom_data', $custom_data);
            self::log_message("Custom data stored in session: " . print_r($custom_data, true));
        }
        $item_data = array('custom_data' => $custom_data);
        self::log_message("Adding product to cart with custom data: " . print_r($custom_data, true));
        $added = WC()->cart->add_to_cart($data['wc_product_id'], 1, 0, array(), $item_data);
        if ( ! $added) {
            self::log_message("Failed to add product to cart");
            wp_redirect(home_url('/cart/'));
            exit;
        }
        $cart_items = WC()->cart->get_cart();
        $found_data = false;
        foreach ($cart_items as $item) {
            if (isset($item['custom_data'])) {
                $found_data = true;
                self::log_message("Verified custom data in cart item: " . print_r($item['custom_data'], true));
            }
        }
        if ( ! $found_data) {
            self::log_message("WARNING: Custom data not found in cart after adding!");
        }
        delete_transient($token);
        self::log_message("Token deleted, redirecting to checkout");
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Ensure custom data is transferred to the order.
     */
    public static function ensure_custom_data_transfer($order, $data) {
        if ( ! function_exists('WC') || ! WC()->cart) {
            return;
        }
        $custom_data = WC()->session && WC()->session->get('swsib_custom_data') ?
                       WC()->session->get('swsib_custom_data') : null;
        if (empty($custom_data)) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['custom_data']) && is_array($cart_item['custom_data'])) {
                    $custom_data = $cart_item['custom_data'];
                    self::log_message("Found custom data in cart item: " . print_r($custom_data, true));
                    break;
                }
            }
        }
        if (empty($custom_data)) {
            self::log_message("No custom data found during checkout");
            return;
        }
        self::log_message("Transferring custom data to order: " . print_r($custom_data, true));
        $order->update_meta_data('_swsib_custom_data', $custom_data);
        $order->update_meta_data('_swiftspeed_custom_data', $custom_data);
        foreach ($custom_data as $key => $value) {
            $order->update_meta_data('_' . $key, $value);
            self::log_message("Added order meta: _{$key} = {$value}");
        }
        $order->save();
        if (WC()->session && WC()->session->get('swsib_custom_data')) {
            WC()->session->__unset('swsib_custom_data');
        }
    }

    /**
     * Transfer custom data to subscription.
     */
    public static function transfer_custom_data_to_subscription($subscription, $order, $product_id) {
        $custom_data = $order->get_meta('_swsib_custom_data', true);
        if (empty($custom_data)) {
            self::log_message("No consolidated custom data found in order, checking individual fields");
            $admin_id = $order->get_meta('_adminId', true);
            $application_id = $order->get_meta('_applicationId', true);
            $admin_email = $order->get_meta('_adminEmail', true);
            $selected_subscription_id = $order->get_meta('_selectedSubscriptionId', true);
            if ($admin_id || $application_id || $admin_email || $selected_subscription_id) {
                $custom_data = array(
                    'adminId' => $admin_id,
                    'applicationId' => $application_id,
                    'adminEmail' => $admin_email,
                    'selectedSubscriptionId' => $selected_subscription_id
                );
                self::log_message("Constructed custom data from order meta: " . print_r($custom_data, true));
            }
        }
        if (empty($custom_data)) {
            self::log_message("No custom data found in order meta, checking line items");
            foreach ($order->get_items() as $item) {
                $item_custom_data = $item->get_meta('_swsib_custom_data', true);
                if ( ! empty($item_custom_data) && is_array($item_custom_data)) {
                    $custom_data = $item_custom_data;
                    self::log_message("Found custom data in order line item: " . print_r($custom_data, true));
                    break;
                }
                $admin_id = $item->get_meta('_adminId', true);
                $application_id = $item->get_meta('_applicationId', true);
                $admin_email = $item->get_meta('_adminEmail', true);
                $selected_subscription_id = $item->get_meta('_selectedSubscriptionId', true);
                if ($admin_id || $application_id || $admin_email || $selected_subscription_id) {
                    $custom_data = array(
                        'adminId' => $admin_id,
                        'applicationId' => $application_id,
                        'adminEmail' => $admin_email,
                        'selectedSubscriptionId' => $selected_subscription_id
                    );
                    self::log_message("Constructed custom data from item meta: " . print_r($custom_data, true));
                    break;
                }
            }
        }
        if ( ! empty($custom_data)) {
            self::log_message("Transferring custom data to subscription #{$subscription->get_id()}: " . print_r($custom_data, true));
            $subscription->update_meta_data('_swsib_custom_data', $custom_data);
            $subscription->update_meta_data('_swiftspeed_custom_data', $custom_data);
            foreach ($custom_data as $key => $value) {
                if ( ! empty($value)) {
                    $subscription->update_meta_data('_' . $key, $value);
                    self::log_message("Added subscription meta: _{$key} = {$value}");
                }
            }
            $subscription->save();
            self::log_message("Successfully saved custom data to subscription #{$subscription->get_id()}");
            if ($subscription->get_status() !== 'active') {
                self::log_message("Setting subscription #{$subscription->get_id()} status to active");
                $subscription->update_status('active');
            }
        } else {
            self::log_message("No custom data found to transfer to subscription #{$subscription->get_id()}");
        }
    }

    /**
     * Save custom data to order line items.
     */
    public static function save_custom_data_to_order_items($item, $cart_item_key, $values, $order) {
        if (isset($values['custom_data'])) {
            self::log_message("Saving custom data to order item: " . print_r($values['custom_data'], true));
            $item->add_meta_data('_swsib_custom_data', $values['custom_data'], true);
            $item->add_meta_data('_swiftspeed_custom_data', $values['custom_data'], true);
            foreach ($values['custom_data'] as $key => $value) {
                $item->add_meta_data('_' . $key, $value, true);
                self::log_message("Added meta data to order item: _{$key} = {$value}");
            }
        }
    }

    /**
     * Activate subscription on payment (ensure status is active).
     */
    public static function activate_subscription_on_payment($order_id) {
        if ( ! function_exists('wcs_get_subscriptions_for_order') ) {
            return;
        }
        self::log_message("Payment complete for order #{$order_id}, processing subscriptions...");
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        if (empty($subscriptions)) {
            self::log_message("No subscriptions found for order #{$order_id}");
            return;
        }
        foreach ($subscriptions as $subscription) {
            $subscription_id = $subscription->get_id();
            self::log_message("Processing subscription #{$subscription_id} after payment");
            if ($subscription->get_status() !== 'active') {
                self::log_message("Setting subscription #{$subscription_id} to active status");
                $subscription->update_status('active');
            }
        }
    }

    /**
     * Enqueue product access popup on single product pages.
     */
    public static function enqueue_product_popup() {
        if ( ! is_product() ) {
            return;
        }
        global $post;
        $options = get_option('swsib_options', array());
        if (isset($options['woocommerce']['mappings'])) {
            $mapped = false;
            foreach ($options['woocommerce']['mappings'] as $mapping) {
                if ((string)$mapping['woo_product_id'] === (string)$post->ID) {
                    $mapped = true;
                    break;
                }
            }
            if ( ! $mapped ) {
                return;
            }
        } else {
            return;
        }
        $woo_opts = isset($options['woocommerce']) ? $options['woocommerce'] : array();
        $popup_message = isset($woo_opts['popup_message']) ? $woo_opts['popup_message'] : "You must create an app to purchase this subscription.";
        $popup_action  = isset($woo_opts['popup_action']) ? $woo_opts['popup_action'] : '[swiftspeedsiberiancms]';
        $is_shortcode = (strpos(trim($popup_action), '[') === 0);
        $action_html = $is_shortcode ? do_shortcode($popup_action) : sprintf(
            '<div class="wp-block-button"><a href="%s" class="wp-block-button__link">%s</a></div>',
            esc_url($popup_action),
            esc_html__('Login', 'swiftspeed-siberian')
        );
        ?>
        <style>
            #swsib-popup {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                min-width: 300px;
                background: #fff;
                padding: 20px;
                z-index: 9999;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.5);
                text-align: center;
            }
            #swsib-popup p { margin-bottom: 20px; font-size: 16px; }
            #swsib-popup a,
            #swsib-popup button {
                display: inline-block;
                text-decoration: none !important;
                padding: 10px 18px;
                font-size: 13px;
                color: #ffffff !important;
                background-color: #3a4b79 !important;
                border-radius: 5px;
                border: none;
                cursor: pointer;
                font-weight: 600;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                transition: background-color 0.2s ease;
            }
            #swsib-popup a:hover,
            #swsib-popup button:hover { background-color: #2e3d5d !important; }
            #swsib-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9998;
            }
        </style>
        <div id="swsib-popup">
            <p><?php echo esc_html($popup_message); ?></p>
            <?php echo $action_html; ?>
        </div>
        <div id="swsib-overlay"></div>
        <script type="text/javascript">
            jQuery(document).ready(function($){});
        </script>
        <?php
    }

    /**
     * Enqueue post-purchase popup on order-received page.
     */
    public static function enqueue_purchase_success_popup() {
        if ( ! is_wc_endpoint_url('order-received') ) {
            return;
        }
        $order_id = absint(get_query_var('order-received'));
        if ( ! $order_id ) { return; }
        $order = wc_get_order($order_id);
        if ( ! $order ) { return; }
        $has_mapped_product = false;
        $mapped_product_ids = array();
        $options = get_option('swsib_options', array());
        if (isset($options['woocommerce']['mappings'])) {
            foreach ($options['woocommerce']['mappings'] as $mapping) {
                $mapped_product_ids[] = $mapping['woo_product_id'];
            }
            foreach ($order->get_items() as $item) {
                if (in_array($item->get_product_id(), $mapped_product_ids)) {
                    $has_mapped_product = true;
                    break;
                }
            }
        }
        if ( ! $has_mapped_product ) {
            return;
        }
        self::log_message("Found mapped product in order #{$order_id}, showing purchase popup");
        $woo_opts = isset($options['woocommerce']) ? $options['woocommerce'] : array();
        $popup_message = isset($woo_opts['purchase_popup_message']) ? $woo_opts['purchase_popup_message'] : "Congratulations, your subscription payment has been successfully confirmed and your account access has been upgraded. Your application is now ready for those interesting features.";
        $popup_action = isset($woo_opts['purchase_popup_action']) ? $woo_opts['purchase_popup_action'] : '';
        $manage_subscription_url = isset($woo_opts['manage_subscription_url']) && ! empty($woo_opts['manage_subscription_url']) ? $woo_opts['manage_subscription_url'] : home_url('/my-account/subscriptions/');
        $action_html = '';
        if ( ! empty($popup_action)) {
            $action_html = (strpos(trim($popup_action), '[') === 0) ? do_shortcode($popup_action) : sprintf(
                '<a href="%s" class="button">%s</a>',
                esc_url($popup_action),
                esc_html__('Continue to Your App', 'swiftspeed-siberian')
            );
        }
        $manage_sub_html = sprintf(
            '<a href="%s" class="button">%s</a>',
            esc_url($manage_subscription_url),
            esc_html__('Manage Subscriptions', 'swiftspeed-siberian')
        );
        ?>
        <style>
            #swsib-purchase-popup {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                min-width: 300px;
                background: #fff;
                padding: 20px;
                z-index: 9999;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.5);
                text-align: center;
            }
            #swsib-purchase-popup p { margin-bottom: 20px; font-size: 16px; }
            #swsib-purchase-popup a {
                display: inline-block;
                text-decoration: none !important;
                padding: 10px 18px;
                font-size: 13px;
                color: #ffffff !important;
                background-color: #3a4b79 !important;
                border-radius: 5px;
                border: none;
                cursor: pointer;
                font-weight: 600;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                transition: background-color 0.2s ease;
                margin: 0 5px;
            }
            #swsib-purchase-popup a:hover { background-color: #2e3d5d !important; }
            #swsib-purchase-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9998;
            }
        </style>
        <div id="swsib-purchase-popup">
            <p><?php echo esc_html($popup_message); ?></p>
            <?php 
                if ( ! empty($action_html)) { echo $action_html; }
                echo $manage_sub_html; 
            ?>
        </div>
        <div id="swsib-purchase-overlay"></div>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                $("#swsib-purchase-popup, #swsib-purchase-overlay").show();
                $("#swsib-purchase-overlay").on('click', function() {
                    $("#swsib-purchase-popup, #swsib-purchase-overlay").hide();
                });
            });
        </script>
        <?php
    }
}

// Initialize the hook loader.
SwiftSpeed_Siberian_Woocommerce_Hook_Loader::init();