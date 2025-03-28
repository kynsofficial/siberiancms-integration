<?php
/**
 * PE Subscription - General Settings Tab Content
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

// Get default role ID
$fallback_role_id = isset($subscription_options['fallback_role_id']) ? $subscription_options['fallback_role_id'] : '2';

// Get role priorities
$role_priorities = isset($subscription_options['role_priorities']) ? $subscription_options['role_priorities'] : array();

// Get post-purchase popup settings
$purchase_popup_message = isset($subscription_options['purchase_popup_message']) ? 
                         $subscription_options['purchase_popup_message'] : 
                         'Congratulations, your subscription payment has been successfully confirmed and your account access has been upgraded. Your application is now ready for those interesting features.';
$purchase_popup_action = isset($subscription_options['purchase_popup_action']) ? 
                        $subscription_options['purchase_popup_action'] : '';
$manage_subscription_url = isset($subscription_options['manage_subscription_url']) ? 
                          $subscription_options['manage_subscription_url'] : '';

// Get allowed origins
$allowed_origins_list = isset($subscription_options['allowed_origins_list']) ? 
                       $subscription_options['allowed_origins_list'] : array();

// Get roles if DB is configured
$roles = array();
if (swsib()->is_db_configured() && isset($this->db)) {
    $roles = $this->db->get_siberian_roles();
}

// Check for existing checkout page
$checkout_page_id = isset($subscription_options['checkout_page_id']) ? 
                   intval($subscription_options['checkout_page_id']) : 0;

// Detect checkout page with shortcode
$checkout_pages = get_posts(array(
    'post_type' => 'page',
    'posts_per_page' => 1,
    's' => '[swsib_checkout]',
    'fields' => 'ids'
));

if (!empty($checkout_pages) && !$checkout_page_id) {
    $checkout_page_id = $checkout_pages[0];
    // Update option
    $options['subscription']['checkout_page_id'] = $checkout_page_id;
    update_option('swsib_options', $options);
}

$checkout_page_url = $checkout_page_id ? get_permalink($checkout_page_id) : '';

// Display success message if settings were updated
if (isset($_GET['updated']) && $_GET['updated'] === 'true' && isset($_GET['section']) && $_GET['section'] === 'general') {
    echo '<div class="swsib-notice success"><p>' . __('General settings updated successfully.', 'swiftspeed-siberian') . '</p></div>';
}

// Display error message if nonce verification failed
if (isset($_GET['error']) && $_GET['error'] === 'nonce_failed' && isset($_GET['section']) && $_GET['section'] === 'general') {
    echo '<div class="swsib-notice error"><p>' . __('Security check failed. Please try again.', 'swiftspeed-siberian') . '</p></div>';
}
?>

<div class="swsib-notice info">
    <p>
        <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
        <?php _e('Configure general settings for the PE Subscription integration, including role management, CORS domains, and checkout settings.', 'swiftspeed-siberian'); ?>
    </p>
</div>

<?php if (!swsib()->is_db_configured()): ?>
    <div class="swsib-notice warning">
        <p><strong><?php _e('Database Connection Required', 'swiftspeed-siberian'); ?></strong></p>
        <p><?php _e('You must configure the database connection in the DB Connect tab before using PE Subscriptions.', 'swiftspeed-siberian'); ?></p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect'); ?>" class="button">
                <?php _e('Configure Database', 'swiftspeed-siberian'); ?>
            </a>
        </p>
    </div>
<?php else: ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swsib-settings-form" id="general_settings_form">
        <?php wp_nonce_field('swsib_subscription_general_nonce', '_wpnonce_swsib_subscription_general'); ?>
        <input type="hidden" name="action" value="swsib_save_subscription_general">
        <input type="hidden" name="tab_id" value="subscription">
        <input type="hidden" name="section" value="general">

        <!-- Database Connection Test -->
        <div class="swsib-section">
            <h3><?php _e('Database Connection', 'swiftspeed-siberian'); ?></h3>
            <div class="swsib-field">
                <p><?php _e('Test the connection to the SiberianCMS database.', 'swiftspeed-siberian'); ?></p>
                <button type="button" id="test_subscription_db_connection" class="button button-secondary">
                    <?php _e('Test DB Connection', 'swiftspeed-siberian'); ?>
                </button>
                <div id="subscription_db_test_result" class="swsib-test-result"></div>
            </div>
        </div>

        <!-- Role Management Section -->
        <div class="swsib-section">
            <h3><?php _e('Role Management', 'swiftspeed-siberian'); ?></h3>
            <div class="swsib-field">
                <label for="swsib_options_subscription_fallback_role_id"><?php _e('Fallback Role', 'swiftspeed-siberian'); ?></label>
                <select id="swsib_options_subscription_fallback_role_id"
                        name="swsib_options[subscription][fallback_role_id]" class="swsib-select">
                    <?php if (!empty($roles)): ?>
                        <?php foreach ($roles as $role):
                            $selected = $fallback_role_id == $role['role_id'] ? 'selected' : '';
                            $role_name = $role['label'] . ' (ID: ' . $role['role_id'] . ')';
                            if ($role['role_id'] == 2) {
                                $role_name .= ' - ' . __('Default', 'swiftspeed-siberian');
                            }
                            ?>
                            <option value="<?php echo esc_attr($role['role_id']); ?>" <?php echo $selected; ?>>
                                <?php echo esc_html($role_name); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="2">Default (ID: 2)</option>
                    <?php endif; ?>
                </select>
                <p class="swsib-field-note">
                    <?php _e('This role will be assigned when all active subscriptions are cancelled or expired. Typically, this would be the role assigned to free or basic users.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            <div class="swsib-field">
                <h4><?php _e('Role Priority', 'swiftspeed-siberian'); ?></h4>
                <div class="swsib-notice info">
                    <p><?php _e('Arrange roles from highest priority (top) to lowest priority (bottom). When a user has multiple subscriptions, they will be assigned the highest priority role based on this arrangement.', 'swiftspeed-siberian'); ?></p>
                </div>
                <div id="role_priority_container" class="swsib-sortable-container">
                    <ul id="subscription_role_priority_list" class="swsib-sortable-list">
                        <?php
                        if (empty($role_priorities) && !empty($roles)) {
                            foreach ($roles as $role) {
                                if ($role['role_id'] != 1) {
                                    $role_priorities[] = $role['role_id'];
                                }
                            }
                        }
                        if (!empty($role_priorities)) {
                            foreach ($role_priorities as $r_id) {
                                $role_name = '';
                                foreach ($roles as $role) {
                                    if ($role['role_id'] == $r_id) {
                                        $role_name = $role['label'] . ' (ID: ' . $role['role_id'] . ')';
                                        break;
                                    }
                                }
                                if (!empty($role_name)) {
                                    echo '<li class="swsib-sortable-item" data-role-id="' . esc_attr($r_id) . '">';
                                    echo '<div class="swsib-sortable-handle" style="background-color: #3a4b79"></div>';
                                    echo '<span>' . esc_html($role_name) . '</span>';
                                    echo '<input type="hidden" name="swsib_options[subscription][role_priorities][]" value="' . esc_attr($r_id) . '">';
                                    echo '</li>';
                                }
                            }
                        }
                        ?>
                    </ul>
                    <p class="swsib-field-note">
                        <?php _e('Role ID 1 (Super Admin) is excluded. If a super admin buys a subscription, their role will not change.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- CORS Settings Section -->
        <div class="swsib-section">
            <h3><?php _e('CORS Settings', 'swiftspeed-siberian'); ?></h3>
            <p><?php _e('Configure Cross-Origin Resource Sharing for SiberianCMS integration.', 'swiftspeed-siberian'); ?></p>
            <div class="swsib-field">
                <label for="swsib_options_subscription_allowed_origin_url"><?php _e('Add Allowed Origin', 'swiftspeed-siberian'); ?></label>
                <div class="swsib-input-group">
                    <input type="url"
                           id="swsib_options_subscription_allowed_origin_url"
                           class="regular-text"
                           placeholder="https://example.com" />
                    <button type="button" id="add_subscription_allowed_origin" class="button button-secondary">
                        <?php _e('Add Origin', 'swiftspeed-siberian'); ?>
                    </button>
                </div>
                <p class="swsib-field-note">
                    <?php _e('Enter the full URL of your SiberianCMS installation (e.g., https://dev.swiftspeedappcreator.com).', 'swiftspeed-siberian'); ?>
                </p>
                
                <div id="subscription_allowed_origins_container" class="swsib-table-container">
                    <h4><?php _e('Allowed Origins', 'swiftspeed-siberian'); ?></h4>
                    <p><?php _e('Below are the origins allowed to make cross-origin requests to this site.', 'swiftspeed-siberian'); ?></p>
                    <table class="widefat swsib-table">
                        <thead>
                            <tr>
                                <th><?php _e('Origin URL', 'swiftspeed-siberian'); ?></th>
                                <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="subscription_allowed_origins_tbody">
                            <?php
                            if (empty($allowed_origins_list)) {
                                echo '<tr class="no-origins-row"><td colspan="2">' .
                                     __('No origins added yet.', 'swiftspeed-siberian') . '</td></tr>';
                            } else {
                                foreach ($allowed_origins_list as $origin) {
                                    echo '<tr data-origin-id="' . esc_attr($origin['id']) . '">';
                                    echo '<td><a href="' . esc_url($origin['url']) . '" target="_blank" rel="noopener noreferrer">' . 
                                         esc_html($origin['url']) . '</a></td>';
                                    echo '<td>';
                                    echo '<button type="button" class="button button-small delete-subscription-origin" data-origin-id="' . esc_attr($origin['id']) . '">';
                                    _e('Delete', 'swiftspeed-siberian');
                                    echo '</button>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="swsib-notice warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e('IMPORTANT:', 'swiftspeed-siberian'); ?></strong>
                        <?php _e('You must add the URL of your SiberianCMS installation above. Otherwise, CORS will fail and the integration will not work properly.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Post-Purchase Popup Settings -->
        <div class="swsib-section">
            <h3><?php _e('Post-Purchase Popup Settings', 'swiftspeed-siberian'); ?></h3>
            <div class="swsib-notice info">
                <p>
                    <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
                    <?php _e('Configure the popup that will appear after a successful purchase. This popup will include a "Manage Subscriptions" button and an optional action button.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            <div class="swsib-field">
                <label for="swsib_options_subscription_purchase_popup_message"><?php _e('Popup Message after Successful Purchase', 'swiftspeed-siberian'); ?></label>
                <textarea id="swsib_options_subscription_purchase_popup_message"
                          name="swsib_options[subscription][purchase_popup_message]"
                          rows="3" class="regular-text"><?php echo esc_textarea($purchase_popup_message); ?></textarea>
                <p class="swsib-field-note">
                    <?php _e('Enter the message to display after a successful purchase (e.g., "Congratulations, your subscription has been activated. Your app is now ready.").', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            <div class="swsib-field">
                <label for="swsib_options_subscription_purchase_popup_action"><?php _e('Popup Action after Successful Purchase', 'swiftspeed-siberian'); ?></label>
                <input type="text" id="swsib_options_subscription_purchase_popup_action"
                       name="swsib_options[subscription][purchase_popup_action]"
                       value="<?php echo esc_attr($purchase_popup_action); ?>"
                       class="regular-text" />
                <p class="swsib-field-note">
                    <?php _e('Enter a URL (to display as "Continue to Your App" button) or a shortcode for the popup action.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            <div class="swsib-field">
                <label for="swsib_options_subscription_manage_subscription_url"><?php _e('Manage Subscription Page URL', 'swiftspeed-siberian'); ?></label>
                <input type="url" id="swsib_options_subscription_manage_subscription_url"
                       name="swsib_options[subscription][manage_subscription_url]"
                       value="<?php echo esc_url($manage_subscription_url); ?>"
                       class="regular-text" />
                <p class="swsib-field-note">
                    <?php _e('Enter the URL for the "Manage Subscriptions" button. If left empty, the system will search for a page with the [swsib_subscriptions] shortcode.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
        </div>

        <!-- Checkout Page -->
        <div class="swsib-section">
            <h3><?php _e('Checkout Page', 'swiftspeed-siberian'); ?></h3>
            <div class="swsib-field">
                <label><?php _e('Checkout Page URL', 'swiftspeed-siberian'); ?></label>
                <?php if (!empty($checkout_page_url)): ?>
                    <input type="text" value="<?php echo esc_url($checkout_page_url); ?>" class="regular-text" readonly />
                    <p class="swsib-field-note"><?php _e('This is the URL for your subscription checkout page.', 'swiftspeed-siberian'); ?></p>
                    <a href="<?php echo esc_url($checkout_page_url); ?>" target="_blank" class="button">
                        <?php _e('View Checkout Page', 'swiftspeed-siberian'); ?>
                    </a>
                    <?php if ($checkout_page_id): ?>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $checkout_page_id . '&action=edit')); ?>" target="_blank" class="button">
                        <?php _e('Edit Checkout Page', 'swiftspeed-siberian'); ?>
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="swsib-notice warning">
                        <p><?php _e('No checkout page detected. Create a page with the [swsib_checkout] shortcode.', 'swiftspeed-siberian'); ?></p>
                        <p>
                            <button type="button" id="create_checkout_page" class="button button-primary">
                                <?php _e('Create Checkout Page', 'swiftspeed-siberian'); ?>
                            </button>
                        </p>
                    </div>
                <?php endif; ?>
                <input type="hidden" id="swsib_options_subscription_checkout_page_id" 
                       name="swsib_options[subscription][checkout_page_id]" 
                       value="<?php echo esc_attr($checkout_page_id); ?>" />
            </div>
        </div>

        <div class="swsib-actions">
            <button type="submit" name="submit_general" id="submit_general" class="button button-primary">
                <?php _e('Save General Settings', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </form>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Create checkout page
    $('#create_checkout_page').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('<?php _e('Creating...', 'swiftspeed-siberian'); ?>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_create_checkout_page',
                nonce: swsib_subscription.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the hidden input
                    $('#swsib_options_subscription_checkout_page_id').val(response.data.page_id);
                    
                    // Replace the warning with success
                    $button.closest('.swsib-notice').replaceWith(
                        '<div class="swsib-notice success">' +
                        '<p>' + response.data.message + '</p>' +
                        '</div>' +
                        '<input type="text" value="' + response.data.page_url + '" class="regular-text" readonly />' +
                        '<p class="swsib-field-note"><?php _e('This is the URL for your subscription checkout page.', 'swiftspeed-siberian'); ?></p>' +
                        '<a href="' + response.data.page_url + '" target="_blank" class="button">' +
                        '<?php _e('View Checkout Page', 'swiftspeed-siberian'); ?>' +
                        '</a>' +
                        '<a href="' + response.data.edit_url + '" target="_blank" class="button">' +
                        '<?php _e('Edit Checkout Page', 'swiftspeed-siberian'); ?>' +
                        '</a>'
                    );
                } else {
                    // Show error
                    $button.after('<div class="swsib-notice error"><p>' + response.data.message + '</p></div>');
                    $button.prop('disabled', false).text('<?php _e('Create Checkout Page', 'swiftspeed-siberian'); ?>');
                }
            },
            error: function() {
                // Show error
                $button.after('<div class="swsib-notice error"><p><?php _e('An error occurred. Please try again.', 'swiftspeed-siberian'); ?></p></div>');
                $button.prop('disabled', false).text('<?php _e('Create Checkout Page', 'swiftspeed-siberian'); ?>');
            }
        });
    });
});
</script>