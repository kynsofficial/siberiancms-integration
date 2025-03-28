<?php
/**
 * PE Subscription - Enhanced Admin Manage Subscriptions Tab Content
 *
 * Improved version with better status handling, AJAX search, and proper subscription management.
 *
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Get options for plans and settings.
$options = get_option('swsib_options', array());
$subscription_options = isset($options['subscription']) ? $options['subscription'] : array();

// Get all plans.
$plans = isset($subscription_options['plans']) ? $subscription_options['plans'] : array();

// Initialize DB module for subscriptions
require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/subscriptions-db.php';
$db = new SwiftSpeed_Siberian_Subscriptions_DB();

// Get all subscriptions
$user_subscriptions = $db->get_all_subscriptions();

// Get status counts
$status_counts = $db->get_subscription_count_by_status();

// Format subscription data for display.
$formatted_subscriptions = array();

foreach ($user_subscriptions as $subscription) {
    $plan_name     = '';
    $plan_price    = '';
    $plan_currency = '';

    // Find plan details for this subscription.
    foreach ($plans as $plan) {
        if ($plan['id'] === $subscription['plan_id']) {
            $plan_name     = $plan['name'];
            $plan_price    = $plan['price'];
            $plan_currency = $plan['currency'];
            break;
        }
    }

    // Get user details.
    $user_info  = get_userdata($subscription['user_id']);
    $user_name  = $user_info ? $user_info->display_name : __('Unknown User', 'swiftspeed-siberian');
    $user_email = $user_info ? $user_info->user_email : '';

    // Format dates.
    $start_date = new DateTime($subscription['start_date']);
    $end_date   = new DateTime($subscription['end_date']);
    $now        = new DateTime();

    // Use the subscription status as provided.
    $status = $subscription['status'];

    // Check for retry period.
    $in_retry_period = false;
    $retry_message   = '';
    if (isset($subscription['retry_period_end']) && !empty($subscription['retry_period_end'])) {
        $retry_end = new DateTime($subscription['retry_period_end']);
        if ($now <= $retry_end) {
            $in_retry_period = true;
            $days_left     = $retry_end->diff($now)->days;
            $retry_message = sprintf(__('Payment failed. Retrying for %d more days.', 'swiftspeed-siberian'), $days_left);
        }
    }

    // Check for grace period.
    $in_grace_period = false;
    $grace_message   = '';
    if ($status === 'expired' && isset($subscription['grace_period_end']) && !empty($subscription['grace_period_end'])) {
        $grace_end = new DateTime($subscription['grace_period_end']);
        if ($now <= $grace_end) {
            $in_grace_period = true;
            $days_left     = $grace_end->diff($now)->days;
            $grace_message = sprintf(__('In grace period. %d days until cancellation.', 'swiftspeed-siberian'), $days_left);
        }
    }

    // Format payment method display
    $payment_method = isset($subscription['payment_method']) ? $subscription['payment_method'] : 'manual';
    $payment_method_display = ucfirst($payment_method);
    
    // Special case for common payment methods
    if ($payment_method === 'stripe') {
        $payment_method_display = 'Stripe';
    } elseif ($payment_method === 'paypal') {
        $payment_method_display = 'PayPal';
    } elseif ($payment_method === 'manual') {
        $payment_method_display = __('Manual', 'swiftspeed-siberian');
    }

   $formatted_subscriptions[] = array(
    'id' => $subscription['id'],
    'user_id' => $subscription['user_id'],
    'user_name' => $user_name,
    'user_email' => $user_email,
    'plan_name' => $plan_name,
    'amount' => isset($subscription['total_amount']) ? $subscription['total_amount'] : $plan_price,
    'tax_amount' => isset($subscription['tax_amount']) ? $subscription['tax_amount'] : 0,
    'total_amount' => isset($subscription['total_amount']) ? $subscription['total_amount'] : $plan_price,
    'currency' => $plan_currency,
    'status' => $status,
    'start_date' => $start_date->format('Y-m-d'),
    'end_date' => $end_date->format('Y-m-d'),
    'billing_frequency' => $subscription['billing_frequency'],
    'payment_method' => $payment_method,
    'payment_method_display' => $payment_method_display,
    'payment_id' => isset($subscription['payment_id']) ? $subscription['payment_id'] : '',
    'application_id' => $subscription['application_id'],
    'siberian_plan_id' => $subscription['siberian_plan_id'],
    'customer_data' => isset($subscription['customer_data']) ? $subscription['customer_data'] : array(),
    'stripe_customer_id'=> isset($subscription['stripe_customer_id']) ? $subscription['stripe_customer_id'] : '',
    'payment_status' => isset($subscription['payment_status']) ? $subscription['payment_status'] : 'paid',
    'last_payment_date' => isset($subscription['last_payment_date']) ? $subscription['last_payment_date'] : '',
    'retry_count' => isset($subscription['retry_count']) ? $subscription['retry_count'] : 0,
    'in_retry_period' => $in_retry_period,
    'retry_message' => $retry_message,
    'in_grace_period' => $in_grace_period,
    'grace_message' => $grace_message,
    'is_stripe' => isset($subscription['payment_method']) && $subscription['payment_method'] === 'stripe',
    'cancellation_source' => isset($subscription['cancellation_source']) ? $subscription['cancellation_source'] : ''
    );
}

// Get the custom management URL if set.
$custom_subscription_url = isset($subscription_options['manage_subscription_url']) ? $subscription_options['manage_subscription_url'] : '';
$detected_subscription_url = '';

// Auto-detect the Manage Subscriptions page if no custom URL is provided.
if (empty($custom_subscription_url)) {
    $pages = get_posts(array(
        'post_type'      => 'page',
        'posts_per_page' => 1,
        's'              => '[swsib_subscriptions]',
        'fields'         => 'ids'
    ));
    if (!empty($pages)) {
        $detected_subscription_url = get_permalink($pages[0]);
    }
}

$manage_subscription_url = !empty($custom_subscription_url) ? $custom_subscription_url : $detected_subscription_url;


// Localize subscription data and nonces for JavaScript
wp_localize_script('swsib-admin-subscriptions-js', 'swsib_subscription', array(
    'nonce' => wp_create_nonce('swsib_subscription_nonce'),
    'ajaxurl' => admin_url('admin-ajax.php'),
    
    // Confirmation messages
    'confirm_cancel' => __('Are you sure you want to cancel this subscription? This will set it to pending cancellation status.', 'swiftspeed-siberian'),
    'confirm_uncancel' => __('Are you sure you want to resume this subscription? This will change its status back to active.', 'swiftspeed-siberian'),
    'confirm_force_cancel' => __('Are you sure you want to force cancel this subscription? This will immediately cancel the subscription.', 'swiftspeed-siberian'),
    'confirm_activate' => __('Are you sure you want to activate this subscription? This will reactivate it in SiberianCMS.', 'swiftspeed-siberian'),
    'confirm_delete' => __('Are you sure you want to delete this subscription? This action cannot be undone.', 'swiftspeed-siberian'),
    'confirm_bulk_delete' => __('Are you sure you want to delete the selected subscriptions? This action cannot be undone.', 'swiftspeed-siberian'),
    'confirm_bulk_cancel' => __('Are you sure you want to set the selected subscriptions to pending cancellation?', 'swiftspeed-siberian'),
    'confirm_bulk_resume' => __('Are you sure you want to resume the selected subscriptions? This will change their status back to active.', 'swiftspeed-siberian'),
    'confirm_bulk_force_cancel' => __('Are you sure you want to force cancel all selected subscriptions? This will skip the pending cancellation state.', 'swiftspeed-siberian'),
    
    // Action labels for bulk actions
    'bulk_action_default' => __('Bulk Actions', 'swiftspeed-siberian'),
    'bulk_action_cancel' => __('Set to Pending Cancellation', 'swiftspeed-siberian'),
    'bulk_action_resume' => __('Resume Subscriptions', 'swiftspeed-siberian'),
    'bulk_action_force_cancel' => __('Force Cancel', 'swiftspeed-siberian'),
    'bulk_action_delete' => __('Delete', 'swiftspeed-siberian'),
    
    // Processing state
    'processing' => __('Processing...', 'swiftspeed-siberian'),
    
    // Success messages
    'success_cancel' => __('Subscription set to pending cancellation', 'swiftspeed-siberian'),
    'success_uncancel' => __('Subscription successfully resumed and restored to active status', 'swiftspeed-siberian'),
    'bulk_action_resume' => __('Resume Subscriptions', 'swiftspeed-siberian'),
    'bulk_action_force_cancel' => __('Force Cancel', 'swiftspeed-siberian'),
    'bulk_action_delete' => __('Delete', 'swiftspeed-siberian'),
    
    // Processing state
    'processing' => __('Processing...', 'swiftspeed-siberian'),
    
    // Success messages
    'success_cancel' => __('Subscription set to pending cancellation', 'swiftspeed-siberian'),
    'success_uncancel' => __('Subscription successfully resumed and restored to active status', 'swiftspeed-siberian'),
    'success_force_cancel' => __('Subscription cancelled successfully', 'swiftspeed-siberian'),
    'success_activate' => __('Subscription activated successfully', 'swiftspeed-siberian'),
    'success_delete' => __('Subscription deleted successfully', 'swiftspeed-siberian'),
    'success_bulk_action' => __('Bulk action completed successfully', 'swiftspeed-siberian'),
    
    // Error messages
    'error_general' => __('An error occurred. Please try again.', 'swiftspeed-siberian'),
    'no_selection' => __('Please select at least one subscription', 'swiftspeed-siberian'),
    'no_action' => __('Please select an action', 'swiftspeed-siberian'),
    'error_cannot_delete' => __('Only cancelled or expired subscriptions can be deleted', 'swiftspeed-siberian'),
    'error_cannot_cancel' => __('Only active subscriptions can be cancelled', 'swiftspeed-siberian'),
    'error_cannot_force_cancel' => __('Only pending-cancellation subscriptions can be force cancelled', 'swiftspeed-siberian'),
    'error_cannot_resume' => __('Only pending-cancellation subscriptions can be resumed', 'swiftspeed-siberian'),
    'error_cannot_activate' => __('Only expired subscriptions can be activated', 'swiftspeed-siberian')
));
?>

<div class="swsib-notice info">
    <p>
        <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
        <?php _e('Manage user subscriptions and view subscription details. You can manage subscriptions manually and update their status as needed.', 'swiftspeed-siberian'); ?>
    </p>
</div>

<?php if (!swsib()->is_db_configured()): ?>
    <div class="swsib-notice warning">
        <p><strong><?php _e('Database Connection Required', 'swiftspeed-siberian'); ?></strong></p>
        <p><?php _e('You must configure the database connection in the DB Connect tab before managing subscriptions.', 'swiftspeed-siberian'); ?></p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect'); ?>" class="button">
                <?php _e('Configure Database', 'swiftspeed-siberian'); ?>
            </a>
        </p>
    </div>
<?php else: ?>
    <div class="swsib-section">
        <h3><?php _e('Subscription Management', 'swiftspeed-siberian'); ?></h3>

        <!-- Dashboard Subscription Summary Cards -->
        <?php if (!empty($formatted_subscriptions)): ?>
        <div class="swsib-dashboard-cards">
            <div class="swsib-card active<?php echo ($status_counts['active'] == 0 ? ' empty' : ''); ?>" data-status="active">
                <h4><?php _e('Active', 'swiftspeed-siberian'); ?></h4>
                <div class="swsib-card-count"><?php echo $status_counts['active']; ?></div>
            </div>
            <div class="swsib-card pending<?php echo ($status_counts['pending-cancellation'] == 0 ? ' empty' : ''); ?>" data-status="pending-cancellation">
                <h4><?php _e('Pending Cancellation', 'swiftspeed-siberian'); ?></h4>
                <div class="swsib-card-count"><?php echo $status_counts['pending-cancellation']; ?></div>
            </div>
            <div class="swsib-card expired<?php echo ($status_counts['expired'] == 0 ? ' empty' : ''); ?>" data-status="expired">
                <h4><?php _e('Expired', 'swiftspeed-siberian'); ?></h4>
                <div class="swsib-card-count"><?php echo $status_counts['expired']; ?></div>
            </div>
            <div class="swsib-card cancelled<?php echo ($status_counts['cancelled'] == 0 ? ' empty' : ''); ?>" data-status="cancelled">
                <h4><?php _e('Cancelled', 'swiftspeed-siberian'); ?></h4>
                <div class="swsib-card-count"><?php echo $status_counts['cancelled']; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search and Bulk Actions -->
        <div class="swsib-subscription-tools">
            <div class="swsib-subscription-search">
                <input type="text" id="subscription-search" placeholder="<?php _e('Search subscriptions by name, email, or plan...', 'swiftspeed-siberian'); ?>" class="swsib-search-input">
                <div id="search-suggestions" class="search-suggestions-dropdown" style="display: none;"></div>
                <select id="subscription-filter" class="swsib-filter-select">
                    <option value="all"><?php _e('All Statuses', 'swiftspeed-siberian'); ?></option>
                    <option value="active"><?php _e('Active', 'swiftspeed-siberian'); ?></option>
                    <option value="pending-cancellation"><?php _e('Pending Cancellation', 'swiftspeed-siberian'); ?></option>
                    <option value="cancelled"><?php _e('Cancelled', 'swiftspeed-siberian'); ?></option>
                    <option value="expired"><?php _e('Expired', 'swiftspeed-siberian'); ?></option>
                </select>
                <button type="button" id="subscription-search-reset" class="button button-secondary"><?php _e('Reset', 'swiftspeed-siberian'); ?></button>
            </div>
            <div class="swsib-bulk-actions">
                <select id="bulk-action-selector" class="swsib-bulk-action-select">
                    <option value=""><?php _e('Bulk Actions', 'swiftspeed-siberian'); ?></option>
                    <option value="cancel"><?php _e('Set to Pending Cancellation', 'swiftspeed-siberian'); ?></option>
                    <option value="resume"><?php _e('Resume Subscriptions', 'swiftspeed-siberian'); ?></option>
                    <option value="force-cancel"><?php _e('Force Cancel', 'swiftspeed-siberian'); ?></option>
                    <option value="delete"><?php _e('Delete', 'swiftspeed-siberian'); ?></option>
                </select>
                <button type="button" id="bulk-action-apply" class="button" disabled><?php _e('Apply', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>

        <?php if (empty($formatted_subscriptions)): ?>
            <div class="swsib-notice info">
                <p><?php _e('No subscriptions found. Subscriptions will appear here after users purchase them.', 'swiftspeed-siberian'); ?></p>
            </div>
        <?php else: ?>
            <div id="swsib-message-container"></div>
            
            <!-- Loading indicator -->
            <div id="subscription-loader" class="swsib-loader-overlay" style="display: none;">
                <div class="swsib-loader"></div>
            </div>
            
            <div class="swsib-table-container">
                <table class="swsib-subscriptions-table widefat">
                    <thead>
                        <tr>
                            <th class="swsib-checkbox-column">
                                <input type="checkbox" id="select-all-subscriptions">
                            </th>
                            <th><?php _e('User', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Plan', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Amount', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Via', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Start Date', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('End Date', 'swiftspeed-siberian'); ?></th>
                            <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="subscriptions-list">
                        <?php foreach ($formatted_subscriptions as $subscription): ?>
                            <tr data-subscription-id="<?php echo esc_attr($subscription['id']); ?>" 
    data-status="<?php echo esc_attr($subscription['status']); ?>"
    data-user-name="<?php echo esc_attr($subscription['user_name']); ?>"
    data-user-email="<?php echo esc_attr($subscription['user_email']); ?>"
    data-plan-name="<?php echo esc_attr($subscription['plan_name']); ?>"
    data-cancellation-source="<?php echo esc_attr($subscription['cancellation_source']); ?>"
    class="subscription-row">
    <td>
        <input type="checkbox" class="subscription-select" data-id="<?php echo esc_attr($subscription['id']); ?>">
    </td>
    <td>
        <?php echo esc_html($subscription['user_name']); ?><br>
        <small><?php echo esc_html($subscription['user_email']); ?></small>
    </td>
    <td class="subscription-plan" data-plan-id="<?php echo esc_attr($subscription['plan_name']); ?>">
        <?php echo esc_html($subscription['plan_name']); ?>
    </td>
    <td class="subscription-price" data-price="<?php echo esc_attr($subscription['amount']); ?>">
        <?php 
        if ($subscription['tax_amount'] > 0) {
            echo esc_html($subscription['total_amount'] . ' ' . $subscription['currency']);
            echo '<br><small>' . __('Tax: ', 'swiftspeed-siberian') . esc_html($subscription['tax_amount'] . ' ' . $subscription['currency']) . '</small>';
        } else {
            echo esc_html($subscription['amount'] . ' ' . $subscription['currency']);
        }
        ?>
    </td>
    <td>
        <span class="swsib-status subscription-status <?php echo esc_attr($subscription['status']); ?>">
            <?php 
            switch($subscription['status']) {
                case 'active':
                    _e('Active', 'swiftspeed-siberian');
                    break;
                case 'pending-cancellation':
                    _e('Pending Cancellation', 'swiftspeed-siberian');
                    break;
                case 'cancelled':
                    _e('Cancelled', 'swiftspeed-siberian');
                    break;
                case 'expired':
                    _e('Expired', 'swiftspeed-siberian');
                    break;
                default:
                    echo esc_html(ucfirst($subscription['status']));
            }
            ?>
        </span>
        <?php if ($subscription['in_retry_period']): ?>
        <div class="swsib-status-warning retry">
            <?php echo esc_html($subscription['retry_message']); ?>
        </div>
        <?php endif; ?>
        <?php if ($subscription['in_grace_period']): ?>
        <div class="swsib-status-warning grace">
            <?php echo esc_html($subscription['grace_message']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($subscription['cancellation_source']) && $subscription['cancellation_source'] === 'paypal'): ?>
        <div class="swsib-status-note paypal-cancelled">
            <small><?php _e('Cancelled via PayPal by User', 'swiftspeed-siberian'); ?></small>
        </div>
        <?php endif; ?>
    </td>
    <td>
        <span class="swsib-payment-method-badge swsib-payment-method-<?php echo esc_attr($subscription['payment_method']); ?>">
            <?php echo esc_html($subscription['payment_method_display']); ?>
        </span>
    </td>
    <td><?php echo esc_html($subscription['start_date']); ?></td>
    <td><?php echo esc_html($subscription['end_date']); ?></td>
    <td class="swsib-action-buttons">
        <?php if ($subscription['status'] === 'active'): ?>
            <button type="button" class="button button-small cancel-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Cancel', 'swiftspeed-siberian'); ?>
            </button>
        <?php elseif ($subscription['status'] === 'pending-cancellation'): ?>
            <?php 
            // Only show resume button if not cancelled via PayPal 
            if (empty($subscription['cancellation_source']) || $subscription['cancellation_source'] !== 'paypal'): 
            ?>
            <button type="button" class="button button-small uncancel-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Resume', 'swiftspeed-siberian'); ?>
            </button>
            <?php endif; ?>
            <button type="button" class="button button-small force-cancel-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Force Cancel', 'swiftspeed-siberian'); ?>
            </button>
        <?php elseif ($subscription['status'] === 'expired'): ?>
            <button type="button" class="button button-small activate-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Activate', 'swiftspeed-siberian'); ?>
            </button>
            <button type="button" class="button button-small delete-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Delete', 'swiftspeed-siberian'); ?>
            </button>
        <?php elseif ($subscription['status'] === 'cancelled'): ?>
            <button type="button" class="button button-small delete-subscription" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
                <?php _e('Delete', 'swiftspeed-siberian'); ?>
            </button>
        <?php endif; ?>
        
        <button type="button" class="button button-small view-subscription-details" data-subscription-id="<?php echo esc_attr($subscription['id']); ?>">
            <?php _e('Details', 'swiftspeed-siberian'); ?>
        </button>
    </td>
</tr>
                            <!-- Details row (hidden by default) -->
                            <tr class="subscription-details-row" id="details-<?php echo esc_attr($subscription['id']); ?>" style="display: none;">
                                <td colspan="9" class="subscription-details-cell">
                                    <div class="swsib-subscription-details">
                                      <div class="swsib-detail-section">
    <h4><?php _e('Subscription Summary', 'swiftspeed-siberian'); ?></h4>
    <table class="swsib-detail-table">
        <tr>
            <th><?php _e('Subscription ID', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['id']); ?></td>
        </tr>
        <tr>
            <th><?php _e('User', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['user_name'] . ' (' . $subscription['user_email'] . ')'); ?></td>
        </tr>
        <tr>
            <th><?php _e('User ID', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['user_id']); ?></td>
        </tr>
        <tr>
            <th><?php _e('Plan', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['plan_name']); ?></td>
        </tr>
        <tr>
            <th><?php _e('Status', 'swiftspeed-siberian'); ?></th>
            <td>
                <span class="swsib-status <?php echo esc_attr($subscription['status']); ?>">
                    <?php echo esc_html(ucfirst($subscription['status'])); ?>
                </span>
                <?php if (!empty($subscription['cancellation_source'])): ?>
                <span class="swsib-cancellation-source">
                    <?php 
                    if ($subscription['cancellation_source'] === 'paypal') {
                        _e('(Cancelled via PayPal)', 'swiftspeed-siberian');
                    } elseif ($subscription['cancellation_source'] === 'frontend') {
                        _e('(Cancelled by user)', 'swiftspeed-siberian');
                    }
                    ?>
                </span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th><?php _e('Amount', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['amount'] . ' ' . $subscription['currency']); ?></td>
        </tr>
        <?php if ($subscription['tax_amount'] > 0): ?>
        <tr>
            <th><?php _e('Tax Amount', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['tax_amount'] . ' ' . $subscription['currency']); ?></td>
        </tr>
        <tr>
            <th><?php _e('Total Amount', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['total_amount'] . ' ' . $subscription['currency']); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th><?php _e('Billing Frequency', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html(ucfirst($subscription['billing_frequency'])); ?></td>
        </tr>
        <tr>
            <th><?php _e('Start Date', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['start_date']); ?></td>
        </tr>
        <tr>
            <th><?php _e('End Date', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['end_date']); ?></td>
        </tr>
        <?php if (!empty($subscription['next_billing_date'])): ?>
        <tr>
            <th><?php _e('Next Billing Date', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['next_billing_date']); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th><?php _e('Application ID', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['application_id']); ?></td>
        </tr>
        <tr>
            <th><?php _e('SiberianCMS Plan ID', 'swiftspeed-siberian'); ?></th>
            <td><?php echo esc_html($subscription['siberian_plan_id']); ?></td>
        </tr>
    </table>
</div>

                                        <?php if (!empty($subscription['customer_data'])): ?>
                                        <div class="swsib-detail-section">
                                            <h4><?php _e('Customer Information', 'swiftspeed-siberian'); ?></h4>
                                            <table class="swsib-detail-table">
                                                <?php
                                                $customer = $subscription['customer_data'];
                                                if (!empty($customer['first_name']) || !empty($customer['last_name'])): ?>
                                                <tr>
                                                    <th><?php _e('Name', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html((isset($customer['first_name']) ? $customer['first_name'] : '') . ' ' . (isset($customer['last_name']) ? $customer['last_name'] : '')); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if (!empty($customer['email'])): ?>
                                                <tr>
                                                    <th><?php _e('Email', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($customer['email']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if (!empty($customer['phone'])): ?>
                                                <tr>
                                                    <th><?php _e('Phone', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($customer['phone']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if (!empty($customer['address']) || !empty($customer['city']) || !empty($customer['state']) || !empty($customer['zip'])): ?>
                                                <tr>
                                                    <th><?php _e('Address', 'swiftspeed-siberian'); ?></th>
                                                    <td>
                                                        <?php 
                                                        $address_parts = array();
                                                        if (!empty($customer['address'])) $address_parts[] = $customer['address'];
                                                        if (!empty($customer['city'])) $address_parts[] = $customer['city'];
                                                        if (!empty($customer['state'])) $address_parts[] = $customer['state'];
                                                        if (!empty($customer['zip'])) $address_parts[] = $customer['zip'];
                                                        echo esc_html(implode(', ', $address_parts));
                                                        ?>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if (!empty($customer['country'])): ?>
                                                <tr>
                                                    <th><?php _e('Country', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($customer['country']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                        <?php endif; ?>

                                        <div class="swsib-detail-section">
                                            <h4><?php _e('Payment Details', 'swiftspeed-siberian'); ?></h4>
                                            <table class="swsib-detail-table">
                                                <tr>
                                                    <th><?php _e('Payment Method', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($subscription['payment_method_display']); ?></td>
                                                </tr>
                                                <?php if (!empty($subscription['payment_id'])): ?>
                                                <tr>
                                                    <th><?php _e('Payment ID', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($subscription['payment_id']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($subscription['is_stripe'] && !empty($subscription['stripe_customer_id'])): ?>
                                                <tr>
                                                    <th><?php _e('Stripe Customer ID', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($subscription['stripe_customer_id']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <tr>
                                                    <th><?php _e('Payment Status', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html(ucfirst($subscription['payment_status'])); ?></td>
                                                </tr>
                                                <?php if (!empty($subscription['last_payment_date'])): ?>
                                                <tr>
                                                    <th><?php _e('Last Payment Date', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($subscription['last_payment_date']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($subscription['retry_count'] > 0): ?>
                                                <tr>
                                                    <th><?php _e('Payment Retry Count', 'swiftspeed-siberian'); ?></th>
                                                    <td><?php echo esc_html($subscription['retry_count']); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="swsib-table-pagination" <?php echo (count($formatted_subscriptions) <= 20 ? 'style="display:none;"' : ''); ?>>
                <div class="swsib-pagination-info">
                    <span id="pagination-count"><?php echo sprintf(__('Showing %d of %d subscriptions', 'swiftspeed-siberian'), min(20, count($formatted_subscriptions)), count($formatted_subscriptions)); ?></span>
                </div>
                <div class="swsib-pagination-controls">
                    <button type="button" class="button pagination-prev" disabled><?php _e('Previous', 'swiftspeed-siberian'); ?></button>
                    <span id="pagination-current">1</span> / <span id="pagination-total"><?php echo ceil(count($formatted_subscriptions) / 20); ?></span>
                    <button type="button" class="button pagination-next" <?php echo (count($formatted_subscriptions) <= 20 ? 'disabled' : ''); ?>><?php _e('Next', 'swiftspeed-siberian'); ?></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="swsib-section">
        <h3><?php _e('Frontend Management', 'swiftspeed-siberian'); ?></h3>
        
        <div class="swsib-notice info">
            <p><?php _e('Use this shortcode to allow users to manage their subscriptions from the frontend of your website:', 'swiftspeed-siberian'); ?></p>
            <code>[swsib_subscriptions]</code>
        </div>
        
        <div class="swsib-field">
            <p class="swsib-field-note">
                <?php _e('This shortcode displays a list of subscriptions for the current logged-in user. Users can view their subscription details, manage active subscriptions, and cancel their subscriptions if needed.', 'swiftspeed-siberian'); ?>
                <?php _e('If a user paid with Stripe, they will also see a "Manage in Stripe" button that takes them to the Stripe Customer Portal.', 'swiftspeed-siberian'); ?>
            </p>
        </div>
        
        <div class="swsib-field">
            <label><?php _e('Manage Subscriptions URL', 'swiftspeed-siberian'); ?></label>
            <?php if (!empty($manage_subscription_url)): ?>
                <input type="text" value="<?php echo esc_url($manage_subscription_url); ?>" class="regular-text" readonly />
                <p class="swsib-field-note"><?php _e('This is the URL for your Manage Subscriptions page.', 'swiftspeed-siberian'); ?></p>
                <a href="<?php echo esc_url($manage_subscription_url); ?>" target="_blank" class="button">
                    <?php _e('View Subscriptions Page', 'swiftspeed-siberian'); ?>
                </a>
            <?php else: ?>
                <div class="swsib-notice warning">
                    <p><?php _e('No page with the [swsib_subscriptions] shortcode was found. Create a page with this shortcode to allow users to manage their subscriptions.', 'swiftspeed-siberian'); ?></p>
                    <p>
                        <button type="button" id="create_subscriptions_page" class="button">
                            <?php _e('Create Page', 'swiftspeed-siberian'); ?>
                        </button>
                    </p>
                </div>
                <div id="subscriptions_page_creation_result"></div>
            <?php endif; ?>
        </div>
    </div>

   

<?php endif; ?>