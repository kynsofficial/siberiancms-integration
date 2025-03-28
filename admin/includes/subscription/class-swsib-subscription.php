<?php
/**
 * PE Subscription integration functionality for the plugin.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle Siberian CMS PE Subscription integration.
 */
class SwiftSpeed_Siberian_Subscription {

    /**
     * Plugin options.
     */
    private $options;

    /**
     * DB connection handler.
     */
    public $db;

    /**
     * Active tab in subscription section.
     */
    private $active_section = 'general';

    /**
     * Initialize the class.
     */
    public function __construct() {
        // Get plugin options.
        $this->options = get_option('swsib_options', array());

        // Load dependencies.
        $this->load_dependencies();

        // Register admin assets and AJAX hooks.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Register AJAX handlers.
        add_action('wp_ajax_swsib_test_subscription_db', array($this, 'ajax_test_subscription_db'));
        // Note: toggle_subscription_integration is now handled by the hook loader
        add_action('wp_ajax_swsib_subscription_add_allowed_origin', array($this, 'ajax_add_allowed_origin'));
        add_action('wp_ajax_swsib_subscription_delete_allowed_origin', array($this, 'ajax_delete_allowed_origin'));
        add_action('wp_ajax_swsib_save_subscription_plan', array($this, 'ajax_save_subscription_plan'));
        add_action('wp_ajax_swsib_delete_subscription_plan', array($this, 'ajax_delete_subscription_plan'));
        add_action('wp_ajax_swsib_activate_subscription', array($this, 'ajax_activate_subscription'));
        add_action('wp_ajax_swsib_cancel_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_swsib_fix_integration_conflict', array($this, 'ajax_fix_integration_conflict'));
        
        // New AJAX handlers for enhanced UI
        add_action('wp_ajax_swsib_load_subscription_section', array($this, 'ajax_load_subscription_section'));
        add_action('wp_ajax_swsib_save_default_currency', array($this, 'ajax_save_default_currency'));
        add_action('wp_ajax_swsib_save_payment_settings_ajax', array($this, 'ajax_save_payment_settings'));
        add_action('wp_ajax_swsib_save_checkout_page', array($this, 'ajax_save_checkout_page'));
        add_action('wp_ajax_swsib_save_management_url', array($this, 'ajax_save_management_url'));

        // Form submission handlers for each section
        add_action('admin_post_swsib_save_subscription_general', array($this, 'process_general_settings_submission'));
        add_action('admin_post_swsib_save_subscription_payments', array($this, 'process_payment_methods_submission'));
        add_action('wp_ajax_swsib_create_checkout_page', array($this, 'ajax_create_checkout_page'));
        add_action('wp_ajax_swsib_create_subscriptions_page', array($this, 'ajax_create_subscriptions_page'));


        // Check if integration is enabled before adding frontend hooks
        if ($this->is_integration_enabled()) {
            // Initialize the frontend components
            $this->init_frontend();
        }
    }

    /**
     * Initialize frontend components for subscription functionality
     */
    private function init_frontend() {
        // Initialize frontend class - this loads JS/CSS and handles frontend interactions
        if (!class_exists('SwiftSpeed_Siberian_Subscription_Public')) {
            require_once SWSIB_PLUGIN_DIR . '/admin/includes/subscription/public/public.php';
            new SwiftSpeed_Siberian_Subscription_Public();
        }
    }

    /**
     * Check if PE Subscription integration is enabled.
     */
    public function is_integration_enabled() {
        return isset($this->options['subscription']['integration_enabled']) && 
               filter_var($this->options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Load required files and initialize DB connection.
     */
    private function load_dependencies() {
        // Load DB module if DB is configured
        if (swsib()->is_db_configured()) {
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/siberiansub-db.php';
            $this->db = new SwiftSpeed_Siberian_SiberianSub_DB();
        }

        // Load email sender class
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/email/subscription-email-sender.php';
        
        // Load email handler class
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/email/subscription-email-handler.php';
    }

    /**
     * Central logging method.
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'backend', $message);
        }
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }

        // Enqueue jQuery UI Sortable library
        wp_enqueue_script('jquery-ui-sortable');

        // Add CSS
        wp_enqueue_style(
            'swsib-subscription-css',
            SWSIB_PLUGIN_URL . 'admin/includes/subscription/subscription.css',
            array(),
            SWSIB_VERSION
        );

        // Add JavaScript
        wp_enqueue_script(
            'swsib-subscription-js',
            SWSIB_PLUGIN_URL . 'admin/includes/subscription/subscription.js',
            array('jquery', 'jquery-ui-sortable'),
            SWSIB_VERSION,
            true
        );
        
        // Always load subscription management CSS and JS for seamless tab navigation
        wp_enqueue_style(
            'swsib-admin-subscriptions-css',
            SWSIB_PLUGIN_URL . 'admin/includes/subscription/include/manage-subscriptions.css',
            array(),
            SWSIB_VERSION
        );
        
        wp_enqueue_script(
            'swsib-admin-subscriptions-js',
            SWSIB_PLUGIN_URL . 'admin/includes/subscription/include/manage-subscriptions.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );

        // Get options for the subscription settings
        $options = get_option('swsib_options', array());
        
        // Get integration statuses
        $woo_enabled = isset($options['woocommerce']['integration_enabled']) && 
                       filter_var($options['woocommerce']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        $subscription_enabled = isset($options['subscription']['integration_enabled']) && 
                               filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);
        $integration_conflict = $woo_enabled && $subscription_enabled;
    
    

        // Localize script
        wp_localize_script('swsib-subscription-js', 'swsib_subscription', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('swsib_subscription_nonce'),
            'is_db_configured' => swsib()->is_db_configured(),
            'woocommerce_enabled' => $woo_enabled,
            'integration_enabled' => $subscription_enabled,
            'integration_conflict' => $integration_conflict,
            'testing_message' => __('Testing connection...', 'swiftspeed-siberian'),
            'test_success' => __('Connection successful!', 'swiftspeed-siberian'),
            'test_failure' => __('Connection failed.', 'swiftspeed-siberian'),
            'default_currency' => isset($options['subscription']['default_currency']) 
                ? $options['subscription']['default_currency'] 
                : 'USD',
            'currencies' => $this->get_currencies(),
            'confirmation_delete_plan' => __('Are you sure you want to delete this subscription plan?', 'swiftspeed-siberian'),
            'confirmation_cancel_subscription' => __('Are you sure you want to cancel this subscription?', 'swiftspeed-siberian'),
            'confirmation_fix_conflict' => __('This will disable both integrations to fix the conflict. You can then enable the one you want to use. Continue?', 'swiftspeed-siberian'),
            'confirm_enable' => __('Are you sure you want to enable PE Subscription integration?', 'swiftspeed-siberian'),
            'confirm_disable' => __('Are you sure you want to disable PE Subscription integration? This will affect any existing subscriptions.', 'swiftspeed-siberian')
        ));
        
        // Also localize the subscription management script with necessary data
        if (isset($_GET['tab_id']) && $_GET['tab_id'] === 'subscription') {
            // Initialize DB module for subscriptions if needed
            require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
            $db = new SwiftSpeed_Siberian_Subscriptions_DB();
            
            wp_localize_script('swsib-admin-subscriptions-js', 'swsib_subscription', array(
                'nonce' => wp_create_nonce('swsib_subscription_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'confirm_cancel' => __('Are you sure you want to cancel this subscription? This will set it to pending cancellation status.', 'swiftspeed-siberian'),
                'confirm_uncancel' => __('Are you sure you want to resume this subscription? This will change its status back to active.', 'swiftspeed-siberian'),
                'confirm_force_cancel' => __('Are you sure you want to force cancel this subscription? This will immediately cancel the subscription.', 'swiftspeed-siberian'),
                'confirm_activate' => __('Are you sure you want to activate this subscription? This will reactivate it in SiberianCMS.', 'swiftspeed-siberian'),
                'confirm_delete' => __('Are you sure you want to delete this subscription? This action cannot be undone.', 'swiftspeed-siberian'),
                'confirm_bulk_delete' => __('Are you sure you want to delete the selected subscriptions? This action cannot be undone.', 'swiftspeed-siberian'),
                'confirm_bulk_cancel' => __('Are you sure you want to set the selected subscriptions to pending cancellation?', 'swiftspeed-siberian'),
                'confirm_bulk_force_cancel' => __('Are you sure you want to force cancel all selected subscriptions? This will skip the pending cancellation state.', 'swiftspeed-siberian'),
                'processing' => __('Processing...', 'swiftspeed-siberian'),
                'success_cancel' => __('Subscription set to pending cancellation', 'swiftspeed-siberian'),
                'success_uncancel' => __('Subscription successfully resumed and restored to active status', 'swiftspeed-siberian'),
                'success_force_cancel' => __('Subscription cancelled successfully', 'swiftspeed-siberian'),
                'success_activate' => __('Subscription activated successfully', 'swiftspeed-siberian'),
                'success_delete' => __('Subscription deleted successfully', 'swiftspeed-siberian'),
                'error_general' => __('An error occurred. Please try again.', 'swiftspeed-siberian'),
                'no_selection' => __('Please select at least one subscription', 'swiftspeed-siberian'),
                'no_action' => __('Please select an action', 'swiftspeed-siberian')
            ));
        }
        
        // Enqueue WordPress Media scripts for image upload
        wp_enqueue_media();
    }

    /**
     * AJAX handler for loading subscription section content.
     */
    public function ajax_load_subscription_section() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get section
        $section = isset($_POST['section']) ? sanitize_key($_POST['section']) : 'general';
        
        // Start output buffering
        ob_start();
        
        // Set active section for includes
        $this->active_section = $section;
        
        // Load the corresponding section
        switch ($section) {
            case 'general':
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/general-settings.php';
                break;
            case 'plans':
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/subscription-plan.php';
                break;
            case 'payment':
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/payment-method.php';
                break;
            case 'manage':
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/manage.php';
                break;
            case 'emails':
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/subscription-email.php';
                break;
            default:
                include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/general-settings.php';
        }
        
        // Get the output
        $html = ob_get_clean();
        
        // Send response
        wp_send_json_success(array(
            'html' => $html
        ));
    }

/**
 * AJAX handler to create checkout page
 */
public function ajax_create_checkout_page() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        return;
    }
    
    // Create page with checkout shortcode
    $page_id = wp_insert_post(array(
        'post_title' => __('Subscription Checkout', 'swiftspeed-siberian'),
        'post_content' => '[swsib_checkout]',
        'post_status' => 'publish',
        'post_type' => 'page'
    ));
    
    if (is_wp_error($page_id)) {
        wp_send_json_error(array('message' => $page_id->get_error_message()));
        return;
    }
    
    // Update option
    $this->options = get_option('swsib_options', array());
    if (!isset($this->options['subscription'])) {
        $this->options['subscription'] = array();
    }
    $this->options['subscription']['checkout_page_id'] = $page_id;
    update_option('swsib_options', $this->options);
    
    // Get page URL
    $page_url = get_permalink($page_id);
    $edit_url = admin_url('post.php?post=' . $page_id . '&action=edit');
    
    wp_send_json_success(array(
        'message' => __('Checkout page created successfully', 'swiftspeed-siberian'),
        'page_id' => $page_id,
        'page_url' => $page_url,
        'edit_url' => $edit_url
    ));
}

/**
 * AJAX handler to create subscriptions page
 */
public function ajax_create_subscriptions_page() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        return;
    }
    
    // Create page with subscriptions shortcode
    $page_id = wp_insert_post(array(
        'post_title' => __('Manage Subscriptions', 'swiftspeed-siberian'),
        'post_content' => '[swsib_subscriptions]',
        'post_status' => 'publish',
        'post_type' => 'page'
    ));
    
    if (is_wp_error($page_id)) {
        wp_send_json_error(array('message' => $page_id->get_error_message()));
        return;
    }
    
    // Update option
    $this->options = get_option('swsib_options', array());
    if (!isset($this->options['subscription'])) {
        $this->options['subscription'] = array();
    }
    $this->options['subscription']['detected_subscription_url'] = get_permalink($page_id);
    update_option('swsib_options', $this->options);
    
    // Get page URL
    $page_url = get_permalink($page_id);
    
    wp_send_json_success(array(
        'message' => __('Manage Subscriptions page created successfully', 'swiftspeed-siberian'),
        'page_id' => $page_id,
        'page_url' => $page_url
    ));
}
    /**
     * AJAX handler for saving checkout page.
     */
    public function ajax_save_checkout_page() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get checkout page ID
        $checkout_page_id = isset($_POST['checkout_page_id']) ? intval($_POST['checkout_page_id']) : 0;
        
        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Save checkout page ID
        $options['subscription']['checkout_page_id'] = $checkout_page_id;
        update_option('swsib_options', $options);
        
        wp_send_json_success(array(
            'message' => __('Checkout page saved successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for saving management URL.
     */
    public function ajax_save_management_url() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get management URL
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        
        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Save management URL
        $options['subscription']['manage_subscription_url'] = $url;
        update_option('swsib_options', $options);
        
        wp_send_json_success(array(
            'message' => __('Management URL saved successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for saving default currency.
     */
    public function ajax_save_default_currency() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get default currency
        $default_currency = isset($_POST['default_currency']) ? sanitize_text_field($_POST['default_currency']) : 'USD';
        
        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Save default currency
        $options['subscription']['default_currency'] = $default_currency;
        update_option('swsib_options', $options);
        
        wp_send_json_success(array(
            'message' => __('Default currency updated successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for saving payment settings.
     */
    public function ajax_save_payment_settings() {
        // Verify nonce
        if (!isset($_POST['_wpnonce_swsib_subscription_payments']) || 
            !wp_verify_nonce($_POST['_wpnonce_swsib_subscription_payments'], 'swsib_subscription_payments_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Process payment methods
        if (isset($_POST['swsib_options']['subscription']['payment_gateways'])) {
            $payment_gateways = $_POST['swsib_options']['subscription']['payment_gateways'];
            
            // Initialize payment gateways array if not exists
            if (!isset($options['subscription']['payment_gateways'])) {
                $options['subscription']['payment_gateways'] = array();
            }
            
            // Process Stripe settings
            if (isset($payment_gateways['stripe'])) {
                $stripe_settings = $payment_gateways['stripe'];
                
                $options['subscription']['payment_gateways']['stripe'] = array(
                    'enabled' => isset($stripe_settings['enabled']) && $stripe_settings['enabled'] ? true : false,
                    'test_mode' => isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'] ? true : false,
                    'test_publishable_key' => isset($stripe_settings['test_publishable_key']) 
                        ? sanitize_text_field($stripe_settings['test_publishable_key']) 
                        : '',
                    'test_secret_key' => isset($stripe_settings['test_secret_key']) 
                        ? sanitize_text_field($stripe_settings['test_secret_key']) 
                        : '',
                    'live_publishable_key' => isset($stripe_settings['live_publishable_key']) 
                        ? sanitize_text_field($stripe_settings['live_publishable_key']) 
                        : '',
                    'live_secret_key' => isset($stripe_settings['live_secret_key']) 
                        ? sanitize_text_field($stripe_settings['live_secret_key']) 
                        : '',
                    'webhook_secret' => isset($stripe_settings['webhook_secret']) 
                        ? sanitize_text_field($stripe_settings['webhook_secret']) 
                        : '',
                );
            }
            
            // Process PayPal settings
            if (isset($payment_gateways['paypal'])) {
                $paypal_settings = $payment_gateways['paypal'];
                
                $options['subscription']['payment_gateways']['paypal'] = array(
                    'enabled' => isset($paypal_settings['enabled']) && $paypal_settings['enabled'] ? true : false,
                    'sandbox_mode' => isset($paypal_settings['sandbox_mode']) && $paypal_settings['sandbox_mode'] ? true : false,
                    'sandbox_client_id' => isset($paypal_settings['sandbox_client_id']) 
                        ? sanitize_text_field($paypal_settings['sandbox_client_id']) 
                        : '',
                    'sandbox_client_secret' => isset($paypal_settings['sandbox_client_secret']) 
                        ? sanitize_text_field($paypal_settings['sandbox_client_secret']) 
                        : '',
                    'live_client_id' => isset($paypal_settings['live_client_id']) 
                        ? sanitize_text_field($paypal_settings['live_client_id']) 
                        : '',
                    'live_client_secret' => isset($paypal_settings['live_client_secret']) 
                        ? sanitize_text_field($paypal_settings['live_client_secret']) 
                        : '',
                    'webhook_id' => isset($paypal_settings['webhook_id']) 
                        ? sanitize_text_field($paypal_settings['webhook_id']) 
                        : '',
                );
            }
            
            // Save the updated options
            update_option('swsib_options', $options);
            
            wp_send_json_success(array(
                'message' => __('Payment settings saved successfully', 'swiftspeed-siberian')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('No payment gateway settings provided', 'swiftspeed-siberian')
            ));
        }
    }

    /**
     * Get available currencies.
     */
    private function get_currencies() {
        return array(
            'USD' => __('US Dollar', 'swiftspeed-siberian'),
            'EUR' => __('Euro', 'swiftspeed-siberian'),
            'GBP' => __('British Pound', 'swiftspeed-siberian'),
            'AUD' => __('Australian Dollar', 'swiftspeed-siberian'),
            'CAD' => __('Canadian Dollar', 'swiftspeed-siberian'),
            'JPY' => __('Japanese Yen', 'swiftspeed-siberian'),
            'INR' => __('Indian Rupee', 'swiftspeed-siberian'),
            'NGN' => __('Nigerian Naira', 'swiftspeed-siberian'),
            'ZAR' => __('South African Rand', 'swiftspeed-siberian')
        );
    }

    /**
     * AJAX handler for fixing integration conflict
     */
    public function ajax_fix_integration_conflict() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Get options
        $options = get_option('swsib_options', array());
        
        // Disable both integrations
        if (isset($options['subscription'])) {
            $options['subscription']['integration_enabled'] = false;
        }
        
        if (isset($options['woocommerce'])) {
            $options['woocommerce']['integration_enabled'] = false;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        wp_send_json_success(array(
            'message' => __(
                'Integration conflict fixed. Both integrations have been disabled. You can now enable the one you want to use.',
                'swiftspeed-siberian'
            )
        ));
    }

    /**
     * AJAX handler for testing database connection.
     */
    public function ajax_test_subscription_db() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Check DB configuration
        if (!swsib()->is_db_configured()) {
            wp_send_json_error(array('message' => __('Database not configured', 'swiftspeed-siberian')));
            return;
        }

        // Perform connection test
        if (isset($this->db)) {
            $result = $this->db->test_connection();
            if ($result['success']) {
                wp_send_json_success(array('message' => $result['message']));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
        } else {
            wp_send_json_error(array('message' => __('DB module not initialized', 'swiftspeed-siberian')));
        }
    }

    /**
     * AJAX handler for adding allowed origin.
     */
    public function ajax_add_allowed_origin() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $origin_url = isset($_POST['origin_url']) ? esc_url_raw(trim($_POST['origin_url'])) : '';
        if (empty($origin_url)) {
            wp_send_json_error(array('message' => __('Please enter a valid URL', 'swiftspeed-siberian')));
            return;
        }

        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        if (!isset($options['subscription']['allowed_origins_list'])) {
            $options['subscription']['allowed_origins_list'] = array();
        }

        // Check for duplicates
        foreach ($options['subscription']['allowed_origins_list'] as $origin) {
            if ($origin['url'] === $origin_url) {
                wp_send_json_error(array('message' => __('This origin is already in the list', 'swiftspeed-siberian')));
                return;
            }
        }

        // Add new origin
        $new_origin = array(
            'id' => uniqid('sub_cors_'),
            'url' => $origin_url
        );
        $options['subscription']['allowed_origins_list'][] = $new_origin;
        update_option('swsib_options', $options);

        wp_send_json_success(array(
            'message' => __('Origin added successfully', 'swiftspeed-siberian'),
            'origin' => $new_origin
        ));
    }

    /**
     * AJAX handler for deleting allowed origin.
     */
    public function ajax_delete_allowed_origin() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $origin_id = isset($_POST['origin_id']) ? sanitize_text_field($_POST['origin_id']) : '';
        if (empty($origin_id)) {
            wp_send_json_error(array('message' => __('Missing origin ID', 'swiftspeed-siberian')));
            return;
        }

        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription']['allowed_origins_list'])) {
            wp_send_json_error(array('message' => __('No origins found', 'swiftspeed-siberian')));
            return;
        }

        // Find and remove the origin
        $found = false;
        foreach ($options['subscription']['allowed_origins_list'] as $key => $origin) {
            if ($origin['id'] === $origin_id) {
                unset($options['subscription']['allowed_origins_list'][$key]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(array('message' => __('Origin not found', 'swiftspeed-siberian')));
            return;
        }

        // Re-index array and save
        $options['subscription']['allowed_origins_list'] = array_values($options['subscription']['allowed_origins_list']);
        update_option('swsib_options', $options);

        wp_send_json_success(array('message' => __('Origin deleted successfully', 'swiftspeed-siberian')));
    }

    /**
     * AJAX handler for saving subscription plan.
     */
    public function ajax_save_subscription_plan() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        // Check if DB is configured
        if (!swsib()->is_db_configured() || !isset($this->db)) {
            wp_send_json_error(array('message' => __('Database connection not configured', 'swiftspeed-siberian')));
            return;
        }

        // Validate required fields
        $required_fields = array('name', 'price', 'billing_frequency', 'app_quantity');
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                wp_send_json_error(array('message' => sprintf(__('Missing required field: %s', 'swiftspeed-siberian'), $field)));
                return;
            }
        }

        // Prepare plan data
        $plan_data = array(
            'name' => sanitize_text_field($_POST['name']),
            'price' => floatval($_POST['price']),
            'billing_frequency' => sanitize_text_field($_POST['billing_frequency']),
            'currency' => isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'USD',
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'app_quantity' => intval($_POST['app_quantity']),
            'siberian_plan_id' => isset($_POST['siberian_plan_id']) ? intval($_POST['siberian_plan_id']) : 0,
            'role_id' => isset($_POST['role_id']) ? intval($_POST['role_id']) : 2,
        );

        // Add or update plan
        $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
        $is_update = !empty($plan_id);

        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        if (!isset($options['subscription']['plans'])) {
            $options['subscription']['plans'] = array();
        }

        if ($is_update) {
            // Update existing plan
            $found = false;
            foreach ($options['subscription']['plans'] as $key => $plan) {
                if ($plan['id'] === $plan_id) {
                    $plan_data['id'] = $plan_id;
                    $options['subscription']['plans'][$key] = $plan_data;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                wp_send_json_error(array('message' => __('Plan not found', 'swiftspeed-siberian')));
                return;
            }

            $message = __('Subscription plan updated successfully', 'swiftspeed-siberian');
        } else {
            // Add new plan
            $plan_data['id'] = uniqid('sub_plan_');
            $options['subscription']['plans'][] = $plan_data;
            $message = __('Subscription plan added successfully', 'swiftspeed-siberian');
        }

        // Save options
        update_option('swsib_options', $options);

        wp_send_json_success(array(
            'message' => $message,
            'plan' => $plan_data
        ));
    }

    /**
     * AJAX handler for deleting subscription plan.
     */
    public function ajax_delete_subscription_plan() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $plan_id = isset($_POST['plan_id']) ? sanitize_text_field($_POST['plan_id']) : '';
        if (empty($plan_id)) {
            wp_send_json_error(array('message' => __('Missing plan ID', 'swiftspeed-siberian')));
            return;
        }

        // Get options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription']['plans'])) {
            wp_send_json_error(array('message' => __('No plans found', 'swiftspeed-siberian')));
            return;
        }

        // Find and remove the plan
        $found = false;
        foreach ($options['subscription']['plans'] as $key => $plan) {
            if ($plan['id'] === $plan_id) {
                unset($options['subscription']['plans'][$key]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(array('message' => __('Plan not found', 'swiftspeed-siberian')));
            return;
        }

        // Re-index array and save
        $options['subscription']['plans'] = array_values($options['subscription']['plans']);
        update_option('swsib_options', $options);

        wp_send_json_success(array('message' => __('Subscription plan deleted successfully', 'swiftspeed-siberian')));
    }

    /**
     * AJAX handler for activating a subscription.
     */
    public function ajax_activate_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $subscription_id = isset($_POST['subscription_id']) ? intval($_POST['subscription_id']) : 0;
        if (!$subscription_id) {
            wp_send_json_error(array('message' => __('Invalid subscription ID', 'swiftspeed-siberian')));
            return;
        }

        // Get subscription data
        $subscriptions = $this->get_all_subscriptions();
        $subscription = null;
        foreach ($subscriptions as $sub) {
            if ($sub['id'] == $subscription_id) {
                $subscription = $sub;
                break;
            }
        }

        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }

        if (!isset($this->db)) {
            wp_send_json_error(array('message' => __('Database connection not configured', 'swiftspeed-siberian')));
            return;
        }

        $result = $this->db->create_or_update_subscription_application(
            $subscription['application_id'],
            $subscription['siberian_plan_id'],
            $subscription_id
        );

        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to activate subscription in SiberianCMS', 'swiftspeed-siberian')));
            return;
        }

        // Update local subscription status
        $options = get_option('swsib_options', array());
        foreach ($options['subscription']['user_subscriptions'] as $key => $sub) {
            if ($sub['id'] == $subscription_id) {
                $options['subscription']['user_subscriptions'][$key]['status'] = 'active';
                break;
            }
        }
        update_option('swsib_options', $options);

        wp_send_json_success(array('message' => __('Subscription activated successfully', 'swiftspeed-siberian')));
    }

    /**
     * AJAX handler for canceling a subscription.
     */
    public function ajax_cancel_subscription() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }

        $subscription_id = isset($_POST['subscription_id']) ? intval($_POST['subscription_id']) : 0;
        if (!$subscription_id) {
            wp_send_json_error(array('message' => __('Invalid subscription ID', 'swiftspeed-siberian')));
            return;
        }

        // Get subscription data
        $subscriptions = $this->get_all_subscriptions();
        $subscription = null;
        foreach ($subscriptions as $sub) {
            if ($sub['id'] == $subscription_id) {
                $subscription = $sub;
                break;
            }
        }

        if (!$subscription) {
            wp_send_json_error(array('message' => __('Subscription not found', 'swiftspeed-siberian')));
            return;
        }

        if (!isset($this->db)) {
            wp_send_json_error(array('message' => __('Database connection not configured', 'swiftspeed-siberian')));
            return;
        }

        $result = $this->db->delete_subscription_application(
            $subscription['application_id'],
            $subscription['siberian_plan_id']
        );

        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to cancel subscription in SiberianCMS', 'swiftspeed-siberian')));
            return;
        }

        // Update local subscription status
        $options = get_option('swsib_options', array());
        foreach ($options['subscription']['user_subscriptions'] as $key => $sub) {
            if ($sub['id'] == $subscription_id) {
                $options['subscription']['user_subscriptions'][$key]['status'] = 'cancelled';
                break;
            }
        }
        update_option('swsib_options', $options);

        wp_send_json_success(array('message' => __('Subscription cancelled successfully', 'swiftspeed-siberian')));
    }

    /**
     * Process form submission for general settings.
     */
    public function process_general_settings_submission() {
        if (
            !isset($_POST['_wpnonce_swsib_subscription_general']) || 
            !wp_verify_nonce($_POST['_wpnonce_swsib_subscription_general'], 'swsib_subscription_general_nonce')
        ) {
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=general&error=nonce_failed'));
            exit;
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }

        if (isset($_POST['swsib_options']['subscription'])) {
            $subscription_data = $_POST['swsib_options']['subscription'];
            
            // Preserve integration_enabled status
            if (!isset($subscription_data['integration_enabled']) && isset($options['subscription']['integration_enabled'])) {
                $subscription_data['integration_enabled'] = $options['subscription']['integration_enabled'];
            }
            
            // Process fallback role
            if (isset($subscription_data['fallback_role_id'])) {
                $options['subscription']['fallback_role_id'] = sanitize_text_field($subscription_data['fallback_role_id']);
            }
            
            // Process role priorities
            if (isset($subscription_data['role_priorities']) && is_array($subscription_data['role_priorities'])) {
                $options['subscription']['role_priorities'] = array_map('sanitize_text_field', $subscription_data['role_priorities']);
            }
            
            // Process popup messages
            if (isset($subscription_data['popup_message'])) {
                $options['subscription']['popup_message'] = sanitize_textarea_field($subscription_data['popup_message']);
            }
            
            if (isset($subscription_data['popup_action'])) {
                if (strpos(trim($subscription_data['popup_action']), '[') === 0) {
                    $options['subscription']['popup_action'] = wp_unslash($subscription_data['popup_action']);
                } else {
                    $options['subscription']['popup_action'] = sanitize_text_field($subscription_data['popup_action']);
                }
            }
            
            // Process purchase popup settings
            if (isset($subscription_data['purchase_popup_message'])) {
                $options['subscription']['purchase_popup_message'] = sanitize_textarea_field($subscription_data['purchase_popup_message']);
            }
            
            if (isset($subscription_data['purchase_popup_action'])) {
                if (strpos(trim($subscription_data['purchase_popup_action']), '[') === 0) {
                    $options['subscription']['purchase_popup_action'] = wp_unslash($subscription_data['purchase_popup_action']);
                } else {
                    $options['subscription']['purchase_popup_action'] = sanitize_text_field($subscription_data['purchase_popup_action']);
                }
            }
            
            if (isset($subscription_data['manage_subscription_url'])) {
                $options['subscription']['manage_subscription_url'] = esc_url_raw($subscription_data['manage_subscription_url']);
            }
            
            // Process checkout page
            if (isset($subscription_data['checkout_page_id'])) {
                $options['subscription']['checkout_page_id'] = intval($subscription_data['checkout_page_id']);
            }
            
            // Save the updated options
            update_option('swsib_options', $options);
            
            // Redirect back to the general settings tab with success message
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=general&updated=true'));
            exit;
        }
        
        // Fallback redirect if no settings were updated
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=general'));
        exit;
    }

    /**
     * Process form submission for payment methods.
     */
    public function process_payment_methods_submission() {
        if (
            !isset($_POST['_wpnonce_swsib_subscription_payments']) || 
            !wp_verify_nonce($_POST['_wpnonce_swsib_subscription_payments'], 'swsib_subscription_payments_nonce')
        ) {
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=payment&error=nonce_failed'));
            exit;
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Process payment methods
        if (isset($_POST['swsib_options']['subscription']['payment_gateways'])) {
            $payment_gateways = $_POST['swsib_options']['subscription']['payment_gateways'];
            
            // Initialize payment gateways array if not exists
            if (!isset($options['subscription']['payment_gateways'])) {
                $options['subscription']['payment_gateways'] = array();
            }
            
            // Process Stripe settings
            if (isset($payment_gateways['stripe'])) {
                $stripe_settings = $payment_gateways['stripe'];
                
                $options['subscription']['payment_gateways']['stripe'] = array(
                    'enabled' => isset($stripe_settings['enabled']) && $stripe_settings['enabled'] ? true : false,
                    'test_mode' => isset($stripe_settings['test_mode']) && $stripe_settings['test_mode'] ? true : false,
                    'test_publishable_key' => isset($stripe_settings['test_publishable_key']) 
                        ? sanitize_text_field($stripe_settings['test_publishable_key']) 
                        : '',
                    'test_secret_key' => isset($stripe_settings['test_secret_key']) 
                        ? sanitize_text_field($stripe_settings['test_secret_key']) 
                        : '',
                    'live_publishable_key' => isset($stripe_settings['live_publishable_key']) 
                        ? sanitize_text_field($stripe_settings['live_publishable_key']) 
                        : '',
                    'live_secret_key' => isset($stripe_settings['live_secret_key']) 
                        ? sanitize_text_field($stripe_settings['live_secret_key']) 
                        : '',
                    'webhook_secret' => isset($stripe_settings['webhook_secret']) 
                        ? sanitize_text_field($stripe_settings['webhook_secret']) 
                        : '',
                );
            }
            
            // Process PayPal settings
            if (isset($payment_gateways['paypal'])) {
                $paypal_settings = $payment_gateways['paypal'];
                
                $options['subscription']['payment_gateways']['paypal'] = array(
                    'enabled' => isset($paypal_settings['enabled']) && $paypal_settings['enabled'] ? true : false,
                    'sandbox_mode' => isset($paypal_settings['sandbox_mode']) && $paypal_settings['sandbox_mode'] ? true : false,
                    'sandbox_client_id' => isset($paypal_settings['sandbox_client_id']) 
                        ? sanitize_text_field($paypal_settings['sandbox_client_id']) 
                        : '',
                    'sandbox_client_secret' => isset($paypal_settings['sandbox_client_secret']) 
                        ? sanitize_text_field($paypal_settings['sandbox_client_secret']) 
                        : '',
                    'live_client_id' => isset($paypal_settings['live_client_id']) 
                        ? sanitize_text_field($paypal_settings['live_client_id']) 
                        : '',
                    'live_client_secret' => isset($paypal_settings['live_client_secret']) 
                        ? sanitize_text_field($paypal_settings['live_client_secret']) 
                        : '',
                    'webhook_id' => isset($paypal_settings['webhook_id']) 
                        ? sanitize_text_field($paypal_settings['webhook_id']) 
                        : '',
                );
            }
            
            // Save the updated options
            update_option('swsib_options', $options);
            
            // Redirect back to the payment methods tab with success message
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=payment&updated=true'));
            exit;
        }
        
        // Fallback redirect if no settings were updated
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=payment'));
        exit;
    }

    /**
     * Get all user subscriptions.
     */
    private function get_all_subscriptions() {
        $options = get_option('swsib_options', array());
        return isset($options['subscription']['user_subscriptions']) 
            ? $options['subscription']['user_subscriptions'] 
            : array();
    }

    /**
     * Display the PE Subscription settings page with the toggle system.
     */
    public function display_settings() {
        // Get options
        $options = get_option('swsib_options', array());

        // Get integration status
        $integration_enabled = isset($options['subscription']['integration_enabled']) ? 
                              filter_var($options['subscription']['integration_enabled'], FILTER_VALIDATE_BOOLEAN) : 
                              false;

        // Check if WooCommerce is active
        $woo_enabled = isset($options['woocommerce']['integration_enabled']) && 
                       filter_var($options['woocommerce']['integration_enabled'], FILTER_VALIDATE_BOOLEAN);

        // Check for integration conflict
        $integration_conflict = $woo_enabled && $integration_enabled;

        // Identify active section
        $section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'general';
        $this->active_section = $section;

        // Display success/error messages
        if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
            echo '<div class="swsib-notice success"><p>' . __('Settings saved successfully.', 'swiftspeed-siberian') . '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'nonce_failed') {
            echo '<div class="swsib-notice error"><p>' . __('Security check failed. Please try again.', 'swiftspeed-siberian') . '</p></div>';
        }

        // Display conflict notice if it exists
        if ($integration_conflict) {
            echo '<div class="swsib-notice error" id="integration_conflict_notice">
                <p><strong>' . __('Integration Conflict Detected', 'swiftspeed-siberian') . '</strong></p>
                <p>' . __('Both PE Subscriptions and WooCommerce integration are currently active. This can cause unexpected behavior as these integrations are designed to be mutually exclusive.', 'swiftspeed-siberian') . '</p>
                <p>' . __('Click the button below to fix this issue by disabling both integrations, then you can enable the one you want to use.', 'swiftspeed-siberian') . '</p>
                <button type="button" id="fix_integration_conflict" class="button button-primary">' . __('Fix Integration Conflict', 'swiftspeed-siberian') . '</button>
            </div>';
        }

        // Title
        echo '<h2>' . __('PE Subscriptions', 'swiftspeed-siberian') . '</h2>';
        echo '<p class="panel-description">' . __('Configure and manage PE subscriptions for SiberianCMS integration.', 'swiftspeed-siberian') . '</p>';

        // Display integration toggle
        ?>
        <div class="swsib-section">
            <h3><?php _e('Integration Status', 'swiftspeed-siberian'); ?></h3>
            
            <?php if ($woo_enabled && !$integration_enabled): ?>
            <div class="swsib-notice warning">
                <p><strong><?php _e('WooCommerce Integration Active', 'swiftspeed-siberian'); ?></strong></p>
                <p><?php _e('The WooCommerce integration is currently active. You need to disable it before enabling PE Subscription integration.', 'swiftspeed-siberian'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=woocommerce'); ?>" class="button"><?php _e('Sell With WooCommerce', 'swiftspeed-siberian'); ?></a></p>
            </div>
            <?php endif; ?>
            
            <div class="swsib-field">
                <label class="swsib-toggle-label">
                    <input type="checkbox" 
                           id="subscription_integration_toggle" 
                           <?php checked($integration_enabled); ?> 
                           <?php disabled($woo_enabled); ?>>
                    <span class="swsib-toggle-switch"></span>
                    <span class="swsib-toggle-text">
                        <?php echo $integration_enabled ? 
                            __('Integration Enabled', 'swiftspeed-siberian') : 
                            __('Integration Disabled', 'swiftspeed-siberian'); ?>
                    </span>
                </label>
                <p class="swsib-field-note">
                    <?php _e('Sell PE Subscription integration natively, instead of using woocommerce. You cannot enable this feature if you are already using Sell With WooCommerce.', 'swiftspeed-siberian'); ?>
                </p>
                <div id="subscription_toggle_result" class="swsib-message"></div>
            </div>
        </div>
        
        <div class="swsib-notice warning">
            <p>
                <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
                <?php
                    echo __('For this integration to work, you need to install and configure the Subscription Patcher Module in your SiberianCMS installation. You can obtain the module from ', 'swiftspeed-siberian') .
                         '<a href="https://swiftspeed.app/my-account/licenses/" target="_blank">' . __('our licenses page', 'swiftspeed-siberian') . '</a>. ' .
                         __('Additionally, you must set up roles, plans, and configure your WordPress URL. Please refer to ', 'swiftspeed-siberian') .
                         '<a href="https://swiftspeed.app/kb/siberiancms-plugin-doc/" target="_blank">' . __('the full documentation', 'swiftspeed-siberian') . '</a> ' .
                         __('for complete instructions.', 'swiftspeed-siberian');
                ?>
            </p>
        </div>
        <?php

        // Show the section tabs (only if integration is enabled or we're in a conflict state)
        if ($integration_enabled || $integration_conflict) {
            ?>
            <div class="swsib-section-tabs">
                <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=general'); ?>" 
                   class="<?php echo $section === 'general' ? 'active' : ''; ?>">
                    <?php _e('General Settings', 'swiftspeed-siberian'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=plans'); ?>" 
                   class="<?php echo $section === 'plans' ? 'active' : ''; ?>">
                    <?php _e('Subscription Plans', 'swiftspeed-siberian'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=payment'); ?>" 
                   class="<?php echo $section === 'payment' ? 'active' : ''; ?>">
                    <?php _e('Payment Methods', 'swiftspeed-siberian'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=emails'); ?>" 
                   class="<?php echo $section === 'emails' ? 'active' : ''; ?>">
                    <?php _e('Email Notifications', 'swiftspeed-siberian'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=subscription&section=manage'); ?>" 
                   class="<?php echo $section === 'manage' ? 'active' : ''; ?>">
                    <?php _e('Manage Subscriptions', 'swiftspeed-siberian'); ?>
                </a>
            </div>
            
            <!-- Content container for AJAX loading -->
            <div id="swsib-subscription-content">
            <?php
    
            // Load the corresponding section
            switch ($section) {
                case 'general':
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/general-settings.php';
                    break;
                case 'plans':
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/subscription-plan.php';
                    break;
                case 'payment':
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/payment-method.php';
                    break;
                case 'emails':
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/subscription-email.php';
                    break;
                case 'manage':
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/manage.php';
                    break;
                default:
                    include SWSIB_PLUGIN_DIR . 'admin/includes/subscription/include/general-settings.php';
            }
            
            // Close content container
            echo '</div>';
        } else {
            // Integration is disabled, show a message
            echo '<div class="swsib-notice info">';
            echo '<p>' . __('Enable the PE Subscription integration above to configure and manage subscriptions.', 'swiftspeed-siberian') . '</p>';
            echo '</div>';
        }
    }
}