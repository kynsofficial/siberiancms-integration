<?php
/**
 * PE Subscription - Subscription Plans Tab Content
 *
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Get options
$options = get_option('swsib_options', array());
$subscription_options = isset($options['subscription']) ? $options['subscription'] : array();

// Get all plans
$plans = isset($subscription_options['plans']) ? $subscription_options['plans'] : array();

// Get tax rules
$tax_rules = isset($subscription_options['tax_rules']) ? $subscription_options['tax_rules'] : array();

// Get available currencies
$currencies = array(
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

// Get default currency
$default_currency = isset($subscription_options['default_currency']) ? $subscription_options['default_currency'] : 'USD';

// Get available billing frequencies
$billing_frequencies = array(
    'weekly' => __('Weekly', 'swiftspeed-siberian'),
    'monthly' => __('Monthly', 'swiftspeed-siberian'),
    'quarterly' => __('Quarterly (3 months)', 'swiftspeed-siberian'),
    'biannually' => __('Bi-annually (6 months)', 'swiftspeed-siberian'),
    'annually' => __('Annually (1 year)', 'swiftspeed-siberian')
);

// Get roles if DB is configured
$roles = array();
if (swsib()->is_db_configured() && isset($this->db)) {
    $roles = $this->db->get_siberian_roles();
}

// Get Siberian plans if DB is configured
$siberian_plans = array();
if (swsib()->is_db_configured() && isset($this->db)) {
    $siberian_plans = $this->db->get_siberian_plans();
}

// Get countries list for tax settings
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
    'NL' => __('Netherlands', 'swiftspeed-siberian'),
    'ALL' => __('All Countries', 'swiftspeed-siberian')
);
?>

<div class="swsib-notice info">
    <p>
        <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
        <?php _e('Configure subscription plans that will be presented to users. Each plan must be mapped to a SiberianCMS subscription and have an assigned role.', 'swiftspeed-siberian'); ?>
    </p>
</div>

<!-- Currency Settings Section -->
<div class="swsib-section">
    <h3><?php _e('Default Currency', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-field">
        <label for="swsib_options_subscription_default_currency"><?php _e('Default Currency', 'swiftspeed-siberian'); ?></label>
        <div class="swsib-input-group">
            <select id="swsib_options_subscription_default_currency" class="swsib-select">
                <?php foreach ($currencies as $code => $name): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($default_currency, $code); ?>>
                        <?php echo esc_html($name . ' (' . $code . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="save_default_currency" class="button button-primary">
                <?php _e('Save Currency Setting', 'swiftspeed-siberian'); ?>
            </button>
        </div>
        <p class="swsib-field-note">
            <?php _e('Select the default currency for new subscription plans.', 'swiftspeed-siberian'); ?>
        </p>
    </div>
</div>

<?php if (swsib()->is_db_configured()): ?>
    <!-- Subscription Plans Section -->
    <div class="swsib-section">
        <h3><?php _e('Subscription Plans', 'swiftspeed-siberian'); ?></h3>
        
        <div class="swsib-add-plan">
            <button type="button" id="add_subscription_plan_button" class="button button-primary">
                <?php _e('Add New Plan', 'swiftspeed-siberian'); ?>
            </button>
        </div>
        
        <!-- Add/Edit Plan Form (initially hidden) -->
        <div id="subscription_plan_form" class="swsib-plan-form" style="display: none;">
            <h4><?php _e('Add New Subscription Plan', 'swiftspeed-siberian'); ?></h4>
            
            <input type="hidden" id="subscription_plan_id" value="">
            
            <div class="swsib-form-row">
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_name"><?php _e('Plan Name', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                        <input type="text" id="subscription_plan_name" class="swsib-w-100" required>
                        <p class="swsib-field-note">
                            <?php _e('Enter a descriptive name for this subscription plan.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_price"><?php _e('Plan Price', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                        <input type="number" id="subscription_plan_price" class="swsib-w-100" step="0.01" min="0" required>
                        <p class="swsib-field-note">
                            <?php _e('Enter the price for this subscription plan.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_currency"><?php _e('Currency', 'swiftspeed-siberian'); ?></label>
                        <select id="subscription_plan_currency" class="swsib-select swsib-w-100">
                            <?php foreach ($currencies as $code => $name): ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($default_currency, $code); ?>>
                                    <?php echo esc_html($name . ' (' . $code . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="swsib-form-row">
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_billing_frequency"><?php _e('Billing Frequency', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                        <select id="subscription_plan_billing_frequency" class="swsib-select swsib-w-100" required>
                            <?php foreach ($billing_frequencies as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, 'monthly'); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="swsib-field-note">
                            <?php _e('Select how often the subscription will be billed.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_app_quantity"><?php _e('App Quantity', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                        <input type="number" id="subscription_plan_app_quantity" class="swsib-w-100" min="1" value="1" required>
                        <p class="swsib-field-note">
                            <?php _e('Regardless of App Quantity you write here, the system will only allocate the on number of apps for this subscription based on the number of apps you set in the SiberianCMS Plan you are linking. For consistency sake, enter same number of apps as your SiberianCMS plan', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="swsib-form-row">
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_siberian_id"><?php _e('SiberianCMS Plan', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                        <select id="subscription_plan_siberian_id" class="swsib-select swsib-w-100" required>
                            <option value=""><?php _e('Select a SiberianCMS plan', 'swiftspeed-siberian'); ?></option>
                            <?php if (!empty($siberian_plans)): ?>
                                <?php foreach ($siberian_plans as $plan): ?>
                                    <option value="<?php echo esc_attr($plan['subscription_id']); ?>">
                                        <?php echo esc_html($plan['name'] . ' - ' . $plan['regular_payment'] . ' (App Qty: ' . $plan['app_quantity'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="swsib-field-note">
                            <?php _e('Select the corresponding plan in SiberianCMS.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="swsib-form-column">
                    <div class="swsib-field">
                        <label for="subscription_plan_role_id"><?php _e('Assigned Role', 'swiftspeed-siberian'); ?></label>
                        <select id="subscription_plan_role_id" class="swsib-select swsib-w-100">
                            <?php if (!empty($roles)): ?>
                                <?php foreach ($roles as $role): ?>
                                    <?php if ($role['role_id'] != 1): // Exclude admin role ?>
                                        <option value="<?php echo esc_attr($role['role_id']); ?>" <?php selected($role['role_id'], 2); ?>>
                                            <?php echo esc_html($role['label'] . ' (ID: ' . $role['role_id'] . ')'); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="2"><?php _e('Default (ID: 2)', 'swiftspeed-siberian'); ?></option>
                            <?php endif; ?>
                        </select>
                        <p class="swsib-field-note">
                            <?php _e('Select the role that will be assigned to users with this subscription.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="swsib-field">
                <label for="subscription_plan_description"><?php _e('Description', 'swiftspeed-siberian'); ?></label>
                <textarea id="subscription_plan_description" class="swsib-w-100" rows="4"></textarea>
                <p class="swsib-field-note">
                    <?php _e('Enter a description for this subscription plan.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            
            <div class="swsib-form-actions">
                <button type="button" id="save_subscription_plan" class="button button-primary">
                    <?php _e('Save Plan', 'swiftspeed-siberian'); ?>
                </button>
                <button type="button" id="cancel_subscription_plan" class="button button-secondary">
                    <?php _e('Cancel', 'swiftspeed-siberian'); ?>
                </button>
            </div>
        </div>
        
        <!-- Existing Plans -->
        <div class="swsib-plan-cards">
            <?php if (empty($plans)): ?>
                <p class="swsib-text-center"><?php _e('No subscription plans defined yet. Add your first plan above.', 'swiftspeed-siberian'); ?></p>
            <?php else: ?>
                <?php foreach ($plans as $plan): ?>
                    <div class="swsib-plan-card">
                        <h3 class="plan-name"><?php echo esc_html($plan['name']); ?></h3>
                        <div class="swsib-plan-card-price plan-price" 
                             data-price="<?php echo esc_attr($plan['price']); ?>"
                             data-currency="<?php echo esc_attr($plan['currency']); ?>">
                            <?php echo esc_html($plan['price'] . ' ' . $plan['currency']); ?>
                        </div>
                        <div class="swsib-plan-card-details">
                            <p class="plan-billing" data-billing="<?php echo esc_attr($plan['billing_frequency']); ?>">
                                <strong><?php _e('Billing:', 'swiftspeed-siberian'); ?></strong> 
                                <?php echo esc_html($billing_frequencies[$plan['billing_frequency']]); ?>
                            </p>
                            <p class="plan-app-quantity" data-quantity="<?php echo esc_attr($plan['app_quantity']); ?>">
                                <strong><?php _e('Apps Quantity:', 'swiftspeed-siberian'); ?></strong> 
                                <?php echo esc_html($plan['app_quantity']); ?>
                            </p>
                            <p class="plan-description">
                                <?php echo esc_html($plan['description']); ?>
                            </p>
                            <p class="plan-siberian-id" data-siberian-id="<?php echo esc_attr($plan['siberian_plan_id']); ?>">
                                <strong><?php _e('SiberianCMS Plan ID:', 'swiftspeed-siberian'); ?></strong> 
                                <?php echo esc_html($plan['siberian_plan_id']); ?>
                            </p>
                            <p class="plan-role-id" data-role-id="<?php echo esc_attr($plan['role_id']); ?>">
                                <strong><?php _e('Role ID:', 'swiftspeed-siberian'); ?></strong> 
                                <?php echo esc_html($plan['role_id']); ?>
                                <?php
                                if (!empty($roles)) {
                                    foreach ($roles as $role) {
                                        if ($role['role_id'] == $plan['role_id']) {
                                            echo ' (' . esc_html($role['label']) . ')';
                                            break;
                                        }
                                    }
                                }
                                ?>
                            </p>
                        </div>
                        <div class="swsib-plan-card-actions">
                            <button type="button" class="button edit-subscription-plan" data-plan-id="<?php echo esc_attr($plan['id']); ?>">
                                <?php _e('Edit', 'swiftspeed-siberian'); ?>
                            </button>
                            <button type="button" class="button delete-subscription-plan" data-plan-id="<?php echo esc_attr($plan['id']); ?>">
                                <?php _e('Delete', 'swiftspeed-siberian'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tax Rules Section -->
    <!-- Tax Rules Section -->
<div class="swsib-section">
    <h3><?php _e('Tax Rules', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-notice info">
        <p><?php _e('Create tax rules to apply to your subscription plans. Tax will be calculated based on the customer\'s country during checkout.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <div class="swsib-add-tax">
        <button type="button" id="add_tax_rule_button" class="button button-primary">
            <?php _e('Add New Tax Rule', 'swiftspeed-siberian'); ?>
        </button>
    </div>
    
    <!-- Add/Edit Tax Form (initially hidden) -->
    <div id="tax_rule_form" class="swsib-plan-form" style="display: none;">
        <h4><?php _e('Add New Tax Rule', 'swiftspeed-siberian'); ?></h4>
        
        <input type="hidden" id="tax_rule_id" value="">
        
        <div class="swsib-form-row">
            <div class="swsib-form-column">
                <div class="swsib-field">
                    <label for="tax_rule_name"><?php _e('Tax Name', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                    <input type="text" id="tax_rule_name" class="swsib-w-100" required>
                    <p class="swsib-field-note">
                        <?php _e('Enter a name for this tax (e.g., VAT, GST, Sales Tax).', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
            
            <div class="swsib-form-column">
                <div class="swsib-field">
                    <label for="tax_rule_percentage"><?php _e('Tax Percentage', 'swiftspeed-siberian'); ?> <span class="required">*</span></label>
                    <input type="number" id="tax_rule_percentage" class="swsib-w-100" step="0.01" min="0" max="100" required>
                    <p class="swsib-field-note">
                        <?php _e('Enter the tax percentage (e.g., 20 for 20%).', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="swsib-form-row">
            <div class="swsib-form-column">
                <div class="swsib-field">
                    <label for="tax_rule_countries_selection"><?php _e('Apply to Countries', 'swiftspeed-siberian'); ?></label>
                    <div class="swsib-country-selection">
                        <select id="tax_rule_countries_selection" class="swsib-select swsib-w-100">
                            <option value="all"><?php _e('All Countries', 'swiftspeed-siberian'); ?></option>
                            <option value="selected"><?php _e('Selected Countries', 'swiftspeed-siberian'); ?></option>
                        </select>
                        
                        <div id="tax_rule_countries_container" class="swsib-checkbox-container" style="display: none;">
                            <div class="swsib-checkbox-scroll">
                                <?php foreach ($countries as $code => $name): ?>
                                    <?php if ($code !== 'ALL'): ?>
                                    <div class="swsib-checkbox-item">
                                        <label>
                                            <input type="checkbox" name="tax_rule_countries[]" value="<?php echo esc_attr($code); ?>">
                                            <?php echo esc_html($name); ?>
                                        </label>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <p class="swsib-field-note">
                        <?php _e('Select whether this tax applies to all countries or only specific ones.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
            
            <div class="swsib-form-column">
                <div class="swsib-field">
                    <label for="tax_rule_plans_selection"><?php _e('Apply to Plans', 'swiftspeed-siberian'); ?></label>
                    <div class="swsib-plan-selection">
                        <select id="tax_rule_plans_selection" class="swsib-select swsib-w-100">
                            <option value="all"><?php _e('All Plans', 'swiftspeed-siberian'); ?></option>
                            <option value="selected"><?php _e('Selected Plans', 'swiftspeed-siberian'); ?></option>
                        </select>
                        
                        <div id="tax_rule_plans_container" class="swsib-checkbox-container" style="display: none;">
                            <div class="swsib-checkbox-scroll">
                                <?php if (!empty($plans)): ?>
                                    <?php foreach ($plans as $plan): ?>
                                        <div class="swsib-checkbox-item">
                                            <label>
                                                <input type="checkbox" name="tax_rule_plans[]" value="<?php echo esc_attr($plan['id']); ?>" 
                                                       data-plan-name="<?php echo esc_attr($plan['name']); ?>">
                                                <?php echo esc_html($plan['name'] . ' (' . $plan['price'] . ' ' . $plan['currency'] . ')'); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p><?php _e('No plans available. Please create a plan first.', 'swiftspeed-siberian'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <p class="swsib-field-note">
                        <?php _e('Select whether this tax applies to all plans or only specific ones.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="swsib-form-row">
            <div class="swsib-form-column">
                <div class="swsib-field">
                    <label class="swsib-checkbox-label" for="tax_rule_enabled">
                        <input type="checkbox" id="tax_rule_enabled" checked>
                        <?php _e('Enable Tax Rule', 'swiftspeed-siberian'); ?>
                    </label>
                    <p class="swsib-field-note">
                        <?php _e('Uncheck to disable this tax rule without deleting it.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="swsib-form-actions">
            <button type="button" id="save_tax_rule" class="button button-primary">
                <?php _e('Save Tax Rule', 'swiftspeed-siberian'); ?>
            </button>
            <button type="button" id="cancel_tax_rule" class="button button-secondary">
                <?php _e('Cancel', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Existing Tax Rules -->
    <div class="swsib-tax-rules-container">
        <?php if (empty($tax_rules)): ?>
            <p class="swsib-text-center"><?php _e('No tax rules defined yet. Add your first tax rule above.', 'swiftspeed-siberian'); ?></p>
        <?php else: ?>
            <table class="widefat swsib-tax-rules-table">
                <thead>
                    <tr>
                        <th><?php _e('Name', 'swiftspeed-siberian'); ?></th>
                        <th><?php _e('Rate', 'swiftspeed-siberian'); ?></th>
                        <th><?php _e('Countries', 'swiftspeed-siberian'); ?></th>
                        <th><?php _e('Applied Plans', 'swiftspeed-siberian'); ?></th>
                        <th><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                        <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                    </tr>
                </thead>
                <tbody>



           <tbody>
<?php foreach ($tax_rules as $rule): ?>
    <tr data-rule-id="<?php echo esc_attr($rule['id']); ?>">
        <td><?php echo esc_html($rule['name']); ?></td>
        <td><?php echo esc_html($rule['percentage'] . '%'); ?></td>
        <td>
            <!-- Countries column -->
        </td>
        <td>
            <!-- Applied Plans column -->
            <?php 
            if (isset($rule['plans']) && is_array($rule['plans'])) {
                if (in_array('all', $rule['plans'])) {
                    echo esc_html__('All Plans', 'swiftspeed-siberian');
                } else {
                    $plan_names = array();
                    
                    // Loop each plan ID from the tax rule
                    foreach ($rule['plans'] as $plan_id) {
                        // Compare with each plan in $plans 
                        foreach ($plans as $plan) {
                            // IMPORTANT:
                            // Some code uses string IDs like "sub_plan_ABC..."
                            // Make sure we check both exactly and with "sub_plan_" prefix:
                            if (
                                $plan['id'] === $plan_id 
                                || 'sub_plan_' . $plan['id'] === $plan_id
                            ) {
                                $plan_names[] = $plan['name'];
                                break;
                            }
                        }
                    }
                    
                    if (!empty($plan_names)) {
                        echo esc_html(implode(', ', $plan_names));
                    } else {
                        // If no match was found, fallback to raw IDs
                        echo esc_html(implode(', ', $rule['plans']));
                    }
                }
            } else {
                echo esc_html__('All Plans', 'swiftspeed-siberian');
            }
            ?>
        </td>
        <td>
                                <span class="swsib-status <?php echo (isset($rule['enabled']) && $rule['enabled']) ? 'active' : 'inactive'; ?>">
                                    <?php echo (isset($rule['enabled']) && $rule['enabled']) ? esc_html__('Active', 'swiftspeed-siberian') : esc_html__('Inactive', 'swiftspeed-siberian'); ?>
                                </span>
                            </td>
                            <td class="swsib-action-buttons">
                                <button type="button" class="button button-small edit-tax-rule" data-rule-id="<?php echo esc_attr($rule['id']); ?>">
                                    <?php _e('Edit', 'swiftspeed-siberian'); ?>
                                </button>
                                <button type="button" class="button button-small delete-tax-rule" data-rule-id="<?php echo esc_attr($rule['id']); ?>">
                                    <?php _e('Delete', 'swiftspeed-siberian'); ?>
                                </button>
                                <?php if (isset($rule['enabled']) && $rule['enabled']): ?>
                                    <button type="button" class="button button-small toggle-tax-rule" 
                                        data-rule-id="<?php echo esc_attr($rule['id']); ?>" 
                                        data-action="disable"
                                        data-status="active">
                                        <?php _e('Disable', 'swiftspeed-siberian'); ?>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="button button-small toggle-tax-rule" 
                                        data-rule-id="<?php echo esc_attr($rule['id']); ?>" 
                                        data-action="enable"
                                        data-status="inactive">
                                        <?php _e('Enable', 'swiftspeed-siberian'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
    
    
<?php else: ?>
    <div class="swsib-notice warning">
        <p><strong><?php _e('Database Connection Required', 'swiftspeed-siberian'); ?></strong></p>
        <p><?php _e('You must configure the database connection in the DB Connect tab before managing subscription plans.', 'swiftspeed-siberian'); ?></p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect'); ?>" class="button">
                <?php _e('Configure Database', 'swiftspeed-siberian'); ?>
            </a>
        </p>
    </div>
<?php endif; ?>
