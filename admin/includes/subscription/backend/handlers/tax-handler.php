<?php
/**
 * Tax Handler
 *
 * Manages tax calculations and tax rule management.
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle tax operations.
 */
class SwiftSpeed_Siberian_Tax_Handler {

    /**
     * Plugin options.
     */
    private static $options = null;

    /**
     * Initialize the handler.
     */
    public static function init() {
        self::$options = get_option('swsib_options', array());
        
        // Register AJAX handlers for tax rule management.
        add_action('wp_ajax_swsib_save_tax_rule', array(__CLASS__, 'ajax_save_tax_rule'));
        add_action('wp_ajax_swsib_get_tax_rule', array(__CLASS__, 'ajax_get_tax_rule'));
        add_action('wp_ajax_swsib_delete_tax_rule', array(__CLASS__, 'ajax_delete_tax_rule'));
        add_action('wp_ajax_swsib_toggle_tax_rule', array(__CLASS__, 'ajax_toggle_tax_rule'));

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
     * Calculate tax amount based on plan and customer country.
     */
    public static function calculate_tax($plan, $customer_data) {
        $tax_amount = 0;
        
        // Get options
        $options = get_option('swsib_options', array());
        $tax_rules = isset($options['subscription']['tax_rules']) ? $options['subscription']['tax_rules'] : array();
        
        // Get customer country (default to 'ALL' if not provided)
        $country = isset($customer_data['country']) ? strtoupper($customer_data['country']) : 'ALL';
        
        self::log_message("Calculating tax for plan ID: {$plan['id']}, country: $country");
        
        // Find applicable tax rules
        foreach ($tax_rules as $rule) {
            // Skip disabled rules
            if (isset($rule['enabled']) && !$rule['enabled']) {
                continue;
            }
            
            // Check if the rule applies to this plan
            $applies_to_plan = in_array('all', $rule['plans']) || in_array($plan['id'], $rule['plans']);
            if (!$applies_to_plan) {
                continue;
            }
            
            // Check if the rule applies to this country
            $applies_to_country = in_array('ALL', $rule['countries']) || in_array($country, $rule['countries']);
            if (!$applies_to_country) {
                continue;
            }
            
            // Calculate tax
            $tax_rate = floatval($rule['percentage']);
            $tax_amount += $plan['price'] * ($tax_rate / 100);
            
            self::log_message("Applied tax rule: {$rule['name']}, Rate: {$rule['percentage']}%, Amount: $tax_amount");
        }
        
        return $tax_amount;
    }

    /**
     * AJAX handler for saving a tax rule.
     */
    public static function ajax_save_tax_rule() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get tax data
        $tax_data = isset($_POST['tax_data']) ? $_POST['tax_data'] : array();
        
        if (empty($tax_data) || !isset($tax_data['name'], $tax_data['percentage'], $tax_data['rule_id'])) {
            wp_send_json_error(array('message' => __('Invalid tax rule data', 'swiftspeed-siberian')));
            return;
        }
        
        // Sanitize data
        $rule = array(
            'id' => sanitize_text_field($tax_data['rule_id']),
            'name' => sanitize_text_field($tax_data['name']),
            'percentage' => floatval($tax_data['percentage']),
            'countries' => isset($tax_data['countries']) ? array_map('sanitize_text_field', $tax_data['countries']) : array('ALL'),
            'plans' => isset($tax_data['plans']) ? array_map('sanitize_text_field', $tax_data['plans']) : array('all'),
            'enabled' => isset($tax_data['enabled']) ? filter_var($tax_data['enabled'], FILTER_VALIDATE_BOOLEAN) : true
        );
        
        // Get current options
        $options = get_option('swsib_options', array());
        if (!isset($options['subscription'])) {
            $options['subscription'] = array();
        }
        
        // Initialize tax_rules if not exists
        if (!isset($options['subscription']['tax_rules'])) {
            $options['subscription']['tax_rules'] = array();
        }
        
        // Check if updating or creating
        $is_update = false;
        foreach ($options['subscription']['tax_rules'] as $key => $existing_rule) {
            if ($existing_rule['id'] === $rule['id']) {
                $options['subscription']['tax_rules'][$key] = $rule;
                $is_update = true;
                break;
            }
        }
        
        // If not updating, add new rule
        if (!$is_update) {
            $options['subscription']['tax_rules'][] = $rule;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        // Log the action
        self::log_message(sprintf(
            'Tax rule %s: %s (%s%%)',
            $is_update ? 'updated' : 'created',
            $rule['name'],
            $rule['percentage']
        ));
        
        // Return success
        wp_send_json_success(array(
            'message' => $is_update 
                ? __('Tax rule updated successfully', 'swiftspeed-siberian') 
                : __('Tax rule created successfully', 'swiftspeed-siberian'),
            'rule' => $rule
        ));
    }
    
    /**
     * AJAX handler for getting tax rule details.
     */
    public static function ajax_get_tax_rule() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get rule ID
        $rule_id = isset($_POST['rule_id']) ? sanitize_text_field($_POST['rule_id']) : '';
        
        if (empty($rule_id)) {
            wp_send_json_error(array('message' => __('Invalid tax rule ID', 'swiftspeed-siberian')));
            return;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        $tax_rules = isset($options['subscription']['tax_rules']) ? $options['subscription']['tax_rules'] : array();
        
        // Find the rule
        $rule = null;
        foreach ($tax_rules as $existing_rule) {
            if ($existing_rule['id'] === $rule_id) {
                $rule = $existing_rule;
                break;
            }
        }
        
        if (!$rule) {
            wp_send_json_error(array('message' => __('Tax rule not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Return rule data
        wp_send_json_success(array(
            'rule' => $rule
        ));
    }
    
    /**
     * AJAX handler for deleting tax rule.
     */
    public static function ajax_delete_tax_rule() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get rule ID
        $rule_id = isset($_POST['rule_id']) ? sanitize_text_field($_POST['rule_id']) : '';
        
        if (empty($rule_id)) {
            wp_send_json_error(array('message' => __('Invalid tax rule ID', 'swiftspeed-siberian')));
            return;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        $tax_rules = isset($options['subscription']['tax_rules']) ? $options['subscription']['tax_rules'] : array();
        
        // Find and remove the rule
        $found = false;
        $rule_name = '';
        foreach ($tax_rules as $key => $rule) {
            if ($rule['id'] === $rule_id) {
                $rule_name = $rule['name'];
                unset($options['subscription']['tax_rules'][$key]);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            wp_send_json_error(array('message' => __('Tax rule not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Re-index array
        $options['subscription']['tax_rules'] = array_values($options['subscription']['tax_rules']);
        
        // Save options
        update_option('swsib_options', $options);
        
        // Log the action
        self::log_message(sprintf('Tax rule deleted: %s', $rule_name));
        
        // Return success
        wp_send_json_success(array(
            'message' => __('Tax rule deleted successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for toggling tax rule status.
     */
    public static function ajax_toggle_tax_rule() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_subscription_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
            return;
        }
        
        // Get parameters
        $rule_id = isset($_POST['rule_id']) ? sanitize_text_field($_POST['rule_id']) : '';
        $toggle_action = isset($_POST['toggle_action']) ? sanitize_text_field($_POST['toggle_action']) : '';
        
        if (empty($rule_id) || !in_array($toggle_action, array('enable', 'disable'))) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'swiftspeed-siberian')));
            return;
        }
        
        // Get options
        $options = get_option('swsib_options', array());
        $tax_rules = isset($options['subscription']['tax_rules']) ? $options['subscription']['tax_rules'] : array();
        
        // Find and update the rule
        $found = false;
        $rule_name = '';
        foreach ($tax_rules as $key => $rule) {
            if ($rule['id'] === $rule_id) {
                $rule_name = $rule['name'];
                $options['subscription']['tax_rules'][$key]['enabled'] = ($toggle_action === 'enable');
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            wp_send_json_error(array('message' => __('Tax rule not found', 'swiftspeed-siberian')));
            return;
        }
        
        // Save options
        update_option('swsib_options', $options);
        
        // Log the action
        self::log_message(sprintf(
            'Tax rule %s: %s',
            $toggle_action === 'enable' ? 'enabled' : 'disabled',
            $rule_name
        ));
        
        // Return success
        wp_send_json_success(array(
            'message' => $toggle_action === 'enable' 
                ? __('Tax rule enabled successfully', 'swiftspeed-siberian') 
                : __('Tax rule disabled successfully', 'swiftspeed-siberian')
        ));
    }


}
