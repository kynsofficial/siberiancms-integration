<?php
/**
 * WooCommerce integration functionality for the plugin.
 */
class SwiftSpeed_Siberian_WooCommerce
{
    /**
     * Plugin options.
     */
    private $options;

    /**
     * Dependencies status.
     */
    private $dependencies_status = [
        'woocommerce'              => false,
        'woocommerce_subscriptions'=> false
    ];

    /**
     * Module instances.
     */
    public $db;

    /**
     * Initialize the class.
     */
    public function __construct()
    {
        // Get plugin options.
        $this->options = get_option('swsib_options', array());

        // Check dependencies silently.
        $this->dependencies_status['woocommerce'] = class_exists('WooCommerce');
        $this->dependencies_status['woocommerce_subscriptions'] = class_exists('WC_Subscriptions');

        // Load DB module if DB is configured.
        if (swsib()->is_db_configured()) {
            $this->load_db_module();
        }

        // Register admin assets and AJAX hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_swsib_test_woocommerce_db', array($this, 'ajax_test_woocommerce_db'));
        
        // CORS-related AJAX handlers
        add_action('wp_ajax_swsib_woocommerce_add_allowed_origin', array($this, 'ajax_add_allowed_origin'));
        add_action('wp_ajax_swsib_woocommerce_delete_allowed_origin', array($this, 'ajax_delete_allowed_origin'));

        // Process admin form submissions for WooCommerce settings.
        add_action('admin_post_swsib_save_woocommerce_settings', array($this, 'process_form_submission'));
        
        // Order data processing hooks
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_custom_data_to_order_items'), 10, 4);
        add_action('woocommerce_checkout_create_order', array($this, 'associate_custom_data_with_order'), 10, 2);
        
        // $this->log_message("WooCommerce integration class initialized");
    }

    /**
     * Process admin form submission for WooCommerce-related settings.
     */
    public function process_form_submission()
    {
        $this->log_message("Received WooCommerce settings form submission");

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'swsib_woocommerce_nonce')) {
            $this->log_message("Nonce verification failed");
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=woocommerce&error=nonce_failed'));
            exit;
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['woocommerce'])) {
            $options['woocommerce'] = array();
        }

        if (isset($_POST['swsib_options']['woocommerce'])) {
            $woocommerce = $_POST['swsib_options']['woocommerce'];
            $current_opts = isset($options['woocommerce']) ? $options['woocommerce'] : array();

            // Preserve mappings & allowed origins if not in form data.
            if (!isset($woocommerce['mappings']) && isset($current_opts['mappings'])) {
                $woocommerce['mappings'] = $current_opts['mappings'];
                $this->log_message("Preserved existing mappings");
            }
            if (!isset($woocommerce['allowed_origins_list']) && isset($current_opts['allowed_origins_list'])) {
                $woocommerce['allowed_origins_list'] = $current_opts['allowed_origins_list'];
                $this->log_message("Preserved existing allowed origins");
            }

            // Special handling for shortcode fields to prevent excessive escaping
            $shortcode_fields = ['popup_action', 'purchase_popup_action'];
            
            foreach ($shortcode_fields as $field) {
                if (isset($woocommerce[$field])) {
                    // First check if it's a shortcode (starts with '[')
                    if (strpos(trim($woocommerce[$field]), '[') === 0) {
                        // Store without additional escaping
                        $woocommerce[$field] = wp_unslash($woocommerce[$field]);
                        $this->log_message("Processed shortcode field {$field} without escaping: " . $woocommerce[$field]);
                    } else {
                        // Regular sanitization for URLs
                        $woocommerce[$field] = sanitize_text_field($woocommerce[$field]);
                    }
                }
            }

            // Process manage subscription URL if present
            if (isset($woocommerce['manage_subscription_url'])) {
                $woocommerce['manage_subscription_url'] = esc_url_raw($woocommerce['manage_subscription_url']);
            }

            $options['woocommerce'] = $woocommerce;
            update_option('swsib_options', $options);
            $this->options = $options; // Update local copy

            $this->log_message("WooCommerce settings saved successfully");
        } else {
            $this->log_message("No WooCommerce form data received");
        }

        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=woocommerce&woocommerce_updated=true'));
        exit;
    }

    /**
     * Central logging method.
     */
    private function log_message($message)
    {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('woocommerce', 'backend', $message);
        }
    }

    /**
     * Load the DB module (if database is configured).
     */
    private function load_db_module()
    {
        require_once plugin_dir_path(__FILE__) . 'class-swsib-woocommerce-db.php';
        $this->db = new SwiftSpeed_Siberian_WooCommerce_DB();
        // $this->log_message("Database module loaded");
    }

    /**
     * Returns an array of allowed origin domains from the plugin settings.
     */
    public function get_allowed_origins_list() {
        $woo_opts = isset($this->options['woocommerce']) ? $this->options['woocommerce'] : array();
        $allowed_origins_list = isset($woo_opts['allowed_origins_list']) ? $woo_opts['allowed_origins_list'] : array();

        $domains = array();
        if (!empty($allowed_origins_list)) {
            foreach ($allowed_origins_list as $origin_entry) {
                if (!empty($origin_entry['url'])) {
                    // Remove any trailing slash for consistency.
                    $domains[] = rtrim($origin_entry['url'], '/');
                }
            }
        }
        
        // Log what domains were retrieved.
        $this->log_message('get_allowed_origins_list() returning: ' . implode(', ', $domains));
        
        return $domains;
    }

    /**
     * Check if dependencies are met.
     */
    private function are_dependencies_met()
    {
        return $this->dependencies_status['woocommerce'] &&
               $this->dependencies_status['woocommerce_subscriptions'];
    }

    /**
     * Enqueue admin scripts/styles for the WooCommerce settings tab.
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }

        wp_enqueue_style(
            'swsib-woocommerce-css',
            SWSIB_PLUGIN_URL . 'admin/includes/woocommerce/woocommerce.css',
            array(),
            SWSIB_VERSION
        );

        wp_enqueue_script(
            'swsib-woocommerce-js',
            SWSIB_PLUGIN_URL . 'admin/includes/woocommerce/woocommerce.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );

        $localized_data = array(
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'nonce'             => wp_create_nonce('swsib_woocommerce_nonce'),
            'dependency_status' => $this->dependencies_status,
            'dependency_urls'   => array(
                'woocommerce'              => 'https://wordpress.org/plugins/woocommerce/',
                'woocommerce_subscriptions'=> 'https://woocommerce.com/products/woocommerce-subscriptions/'
            ),
            'is_db_configured'  => swsib()->is_db_configured(),
            'testing_message'   => __('Testing connection...', 'swiftspeed-siberian'),
            'test_success'      => __('Connection successful!', 'swiftspeed-siberian'),
            'test_failure'      => __('Connection failed.', 'swiftspeed-siberian')
        );

        wp_localize_script('swsib-woocommerce-js', 'swsib_woocommerce', $localized_data);
    }

    /**
     * Save custom data to order line items.
     * This ensures each field is saved individually with proper prefixes.
     */
    public function save_custom_data_to_order_items($item, $cart_item_key, $values, $order)
    {
        if (isset($values['custom_data'])) {
            $this->log_message('Saving custom data to order item: ' . print_r($values['custom_data'], true));
            
            // Save consolidated custom data with both keys for better compatibility
            $item->add_meta_data('_swsib_custom_data', $values['custom_data'], true);
            $item->add_meta_data('_swiftspeed_custom_data', $values['custom_data'], true);
            
            foreach ($values['custom_data'] as $key => $value) {
                // Save each custom data element individually with underscore prefix
                $item->add_meta_data('_' . $key, $value, true);
                $this->log_message("Added meta data to order item: _{$key} = {$value}");
            }
        }
    }

    /**
     * Associate custom data with order.
     * Save as both consolidated meta and individual fields for maximum compatibility.
     */
    public function associate_custom_data_with_order($order, $data)
    {
        if (!function_exists('WC') || !is_object($order)) {
            return;
        }

        $custom_data_summary = array();
        
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['custom_data']) && is_array($cart_item['custom_data'])) {
                    foreach ($cart_item['custom_data'] as $key => $value) {
                        $custom_data_summary[$key] = $value;
                        
                        // Also save each field individually at the order level for better visibility
                        $order->update_meta_data('_' . $key, $value);
                    }
                }
            }
        }

        if (!empty($custom_data_summary)) {
            $this->log_message('Associating custom data with order #' . $order->get_id() . ': ' . print_r($custom_data_summary, true));
            
            // Save the consolidated data with both keys for maximum compatibility
            $order->update_meta_data('_swsib_custom_data', $custom_data_summary);
            $order->update_meta_data('_swiftspeed_custom_data', $custom_data_summary);
            $order->save();
        }
    }

    /**
     * AJAX handler for testing WooCommerce database connection.
     */
    public function ajax_test_woocommerce_db()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_woocommerce_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if (!swsib()->is_db_configured()) {
            wp_send_json_error(array('message' => __('Database not configured', 'swiftspeed-siberian')));
        }
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
     * AJAX handler: add a new allowed origin.
     */
    public function ajax_add_allowed_origin()
    {
        // Check for nonce from either 'nonce' or 'security'
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['security']) ? $_POST['security'] : '');
        if (empty($nonce) || !wp_verify_nonce($nonce, 'swsib_woocommerce_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }

        $origin_url = isset($_POST['origin_url']) ? esc_url_raw(trim($_POST['origin_url'])) : '';
        if (empty($origin_url)) {
            wp_send_json_error(array('message' => __('Please enter a valid URL', 'swiftspeed-siberian')));
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['woocommerce'])) {
            $options['woocommerce'] = array();
        }
        if (!isset($options['woocommerce']['allowed_origins_list'])) {
            $options['woocommerce']['allowed_origins_list'] = array();
        }

        foreach ($options['woocommerce']['allowed_origins_list'] as $origin) {
            if ($origin['url'] === $origin_url) {
                wp_send_json_error(array('message' => __('This origin is already in the list', 'swiftspeed-siberian')));
                return;
            }
        }

        $new_origin = array(
            'id'  => uniqid(),
            'url' => $origin_url
        );
        $options['woocommerce']['allowed_origins_list'][] = $new_origin;

        update_option('swsib_options', $options);
        $this->options = $options; // Update local copy
        
        wp_send_json_success(array(
            'message' => __('Origin added successfully', 'swiftspeed-siberian'),
            'origin'  => $new_origin
        ));
    }

    /**
     * AJAX handler for deleting an allowed origin.
     */
    public function ajax_delete_allowed_origin()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_woocommerce_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Accept the ID from either 'origin_id' or 'mapping_id'
        $origin_id = '';
        if (isset($_POST['origin_id'])) {
            $origin_id = sanitize_text_field($_POST['origin_id']);
        } elseif (isset($_POST['mapping_id'])) {
            $origin_id = sanitize_text_field($_POST['mapping_id']);
        }
        
        if (empty($origin_id)) {
            wp_send_json_error(array('message' => __('Missing origin ID', 'swiftspeed-siberian')));
        }

        $options = get_option('swsib_options', array());
        if (!isset($options['woocommerce']['allowed_origins_list'])) {
            wp_send_json_error(array('message' => __('No origins found', 'swiftspeed-siberian')));
            return;
        }

        $found = false;
        foreach ($options['woocommerce']['allowed_origins_list'] as $key => $origin) {
            if ($origin['id'] === $origin_id) {
                unset($options['woocommerce']['allowed_origins_list'][$key]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            wp_send_json_error(array('message' => __('Origin not found', 'swiftspeed-siberian')));
            return;
        }

        $options['woocommerce']['allowed_origins_list'] = array_values($options['woocommerce']['allowed_origins_list']);
        update_option('swsib_options', $options);
        $this->options = $options; // Update local copy
        
        wp_send_json_success(array('message' => __('Origin deleted successfully', 'swiftspeed-siberian')));
    }

    /**
     * Get WooCommerce subscription products for the mapping interface.
     */
    private function get_woocommerce_subscription_products()
    {
        if (!function_exists('wc_get_products')) {
            return array();
        }

        $args = array(
            'status' => 'publish',
            'limit'  => -1,
            'type'   => array('subscription', 'variable-subscription'),
            'return' => 'objects'
        );

        $products = wc_get_products($args);
        $subscription_products = array();

        foreach ($products as $product) {
            $price = $product->get_price();
            $formatted_price = strip_tags(wc_price($price));

            if ($product->is_type('variable-subscription')) {
                $price = $product->get_variation_price('min');
                $formatted_price = strip_tags(wc_price($price));
            }

            $billing_interval = get_post_meta($product->get_id(), '_subscription_period_interval', true);
            $billing_period   = get_post_meta($product->get_id(), '_subscription_period', true);
            $suffix = ($billing_interval && $billing_period) ? " every {$billing_interval} {$billing_period}" : '';

            $subscription_products[] = array(
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $formatted_price . $suffix
            );
        }
        return $subscription_products;
    }
    
    /**
     * Display the WooCommerce integration settings in the admin area.
     */
    public function display_settings()
    {
        $woo_options = isset($this->options['woocommerce']) ? $this->options['woocommerce'] : array();

        $fallback_role_id = isset($woo_options['fallback_role_id']) ? $woo_options['fallback_role_id'] : '2';
        $restrict_product_access = isset($woo_options['restrict_product_access']) ? $woo_options['restrict_product_access'] : true;

        // Product access popup settings
        $popup_message = isset($woo_options['popup_message']) ? $woo_options['popup_message'] : '';
        $popup_action = isset($woo_options['popup_action']) ? wp_unslash($woo_options['popup_action']) : '';

        // Post-purchase popup settings
        $purchase_popup_message = isset($woo_options['purchase_popup_message']) ? $woo_options['purchase_popup_message'] : '';
        $purchase_popup_action = isset($woo_options['purchase_popup_action']) ? wp_unslash($woo_options['purchase_popup_action']) : '';
        $manage_subscription_url = isset($woo_options['manage_subscription_url']) ? $woo_options['manage_subscription_url'] : '';

        $allowed_origins_list = isset($woo_options['allowed_origins_list']) ? $woo_options['allowed_origins_list'] : array();

        // Check roles if DB is configured.
        $roles = array();
        if (swsib()->is_db_configured() && isset($this->db)) {
            $roles = $this->db->get_siberian_roles();
        }

        if (isset($_GET['woocommerce_updated']) && $_GET['woocommerce_updated'] === 'true') {
            echo '<div class="swsib-notice success"><p>' . __('WooCommerce settings saved successfully.', 'swiftspeed-siberian') . '</p></div>';
        }

        if (isset($_GET['error']) && $_GET['error'] === 'nonce_failed') {
            echo '<div class="swsib-notice error"><p>' . __('Security check failed. Please try again.', 'swiftspeed-siberian') . '</p></div>';
        }
        ?>
        <h2><?php _e('WooCommerce Integration', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description"><?php _e('Integrate SiberianCMS PE with WordPress WooCommerce for subscription management.', 'swiftspeed-siberian'); ?></p>

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

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swsib-settings-form">
            <?php wp_nonce_field('swsib_woocommerce_nonce'); ?>
            <input type="hidden" name="action" value="swsib_save_woocommerce_settings">
            <input type="hidden" name="tab_id" value="woocommerce">

            <?php if (!$this->dependencies_status['woocommerce']): ?>
            <div class="swsib-notice warning">
                <p><strong><?php _e('WooCommerce Required', 'swiftspeed-siberian'); ?></strong></p>
                <p><?php _e('The WooCommerce plugin is required for this functionality.', 'swiftspeed-siberian'); ?></p>
                <p>
                    <a href="<?php echo admin_url('plugin-install.php?s=WooCommerce&tab=search&type=term'); ?>"
                       target="_blank" class="button button-secondary">
                       <?php _e('Get WooCommerce', 'swiftspeed-siberian'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <?php if (!$this->dependencies_status['woocommerce_subscriptions']): ?>
            <div class="swsib-notice warning">
                <p><strong><?php _e('WooCommerce Subscriptions Required', 'swiftspeed-siberian'); ?></strong></p>
                <p><?php _e('The WooCommerce Subscriptions plugin is required for this functionality.', 'swiftspeed-siberian'); ?></p>
                <p>
                    <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank"
                       class="button button-secondary">
                       <?php _e('Get WooCommerce Subscriptions', 'swiftspeed-siberian'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <?php if (!swsib()->is_db_configured()): ?>
                <div class="swsib-notice warning">
                    <p><strong><?php _e('Database Connection Required', 'swiftspeed-siberian'); ?></strong></p>
                    <p><?php _e('You must configure the database connection in the DB Connect tab before using this feature.', 'swiftspeed-siberian'); ?></p>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect'); ?>" class="button">
                            <?php _e('Configure Database', 'swiftspeed-siberian'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <div class="swsib-field">
                    <h3><?php _e('Siberian Database Connection', 'swiftspeed-siberian'); ?></h3>
                    <p><?php _e('Test the connection to the SiberianCMS database.', 'swiftspeed-siberian'); ?></p>
                    <button type="button" id="test_woocommerce_db_connection" class="button button-secondary">
                        <?php _e('Test DB Connection', 'swiftspeed-siberian'); ?>
                    </button>
                    <div id="woocommerce_db_test_result" class="swsib-test-result"></div>
                </div>

                <?php if ($this->are_dependencies_met()): ?>
                    <!-- Role Management Section -->
                    <div class="swsib-section">
                        <h3><?php _e('Role Management', 'swiftspeed-siberian'); ?></h3>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_fallback_role_id"><?php _e('Fallback Role', 'swiftspeed-siberian'); ?></label>
                            <select id="swsib_options_woocommerce_fallback_role_id"
                                    name="swsib_options[woocommerce][fallback_role_id]" class="swsib-select">
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
                                <?php _e('This role will be assigned when all active subscriptions are cancelled/expired, could be that role you just want to give to free users.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <h4><?php _e('Role Priority', 'swiftspeed-siberian'); ?></h4>
                                      <div class="swsib-notice info">
                    <p><?php _e('You have to drag from highest to the lowest, becareful when setting up this part, the logic is that, using this arrangement, when a user buys subcription, the system checks all the users currently active subscriptions, retrieve which of them has the highest role based on your arrangement and assign it to the user. to fully understand the logic, read our full docs.', 'swiftspeed-siberian'); ?></p>
                        </div>
                            <div id="role_priority_container" class="swsib-sortable-container">
                                <ul id="role_priority_list" class="swsib-sortable-list">
                                    <?php
                                    $role_priorities = isset($woo_options['role_priorities']) ? $woo_options['role_priorities'] : array();
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
                                                echo '<input type="hidden" name="swsib_options[woocommerce][role_priorities][]" value="' . esc_attr($r_id) . '">';
                                                echo '</li>';
                                            }
                                        }
                                    }
                                    ?>
                                </ul>
                                <p class="swsib-field-note">
                                    <?php _e('Role ID 1 (Super Admin) is excluded. This implies that if a super admin with Role ID one is buying any subscription, regardless of mapped role to that subscription, their role will not change.', 'swiftspeed-siberian'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Mapping Section -->
                    <div class="swsib-section">
                        <h3><?php _e('Subscription Mapping', 'swiftspeed-siberian'); ?></h3>
                        <div class="swsib-notice info">
                   <p><?php _e('Map SiberianCMS plans to WooCommerce subscription products.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        <div id="add_mapping_form" class="swsib-form">
                            <h4><?php _e('Add New Mapping', 'swiftspeed-siberian'); ?></h4>
                            <div class="swsib-form-row">
                                <div class="swsib-form-col">
                                    <label for="siberian_plan_id"><?php _e('SiberianCMS Plan', 'swiftspeed-siberian'); ?></label>
                                    <select id="siberian_plan_id" class="swsib-select">
                                        <option value=""><?php _e('Select a plan', 'swiftspeed-siberian'); ?></option>
                                        <?php
                                        if (isset($this->db)) {
                                            $siberian_plans = $this->db->get_siberian_plans();
                                            if (!empty($siberian_plans)) {
                                                $existing_mappings = isset($woo_options['mappings']) ? $woo_options['mappings'] : array();
                                                foreach ($siberian_plans as $plan) {
                                                    $plan_already_mapped = false;
                                                    foreach ($existing_mappings as $m) {
                                                        if ($m['siberian_plan_id'] == $plan['subscription_id']) {
                                                            $plan_already_mapped = true;
                                                            break;
                                                        }
                                                    }
                                                    $disabled   = $plan_already_mapped ? 'disabled' : '';
                                                    $extra_text = $plan_already_mapped ? ' (' . __('Already mapped', 'swiftspeed-siberian') . ')' : '';
                                                    echo '<option value="' . esc_attr($plan['subscription_id']) . '" ' . $disabled . '>';
                                                    echo esc_html($plan['name'] . ' - ' . $plan['regular_payment'] . ' (App Qty: ' . $plan['app_quantity'] . ')' . $extra_text);
                                                    echo '</option>';
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="swsib-form-col">
                                    <label for="woo_product_id"><?php _e('WooCommerce Product', 'swiftspeed-siberian'); ?></label>
                                    <select id="woo_product_id" class="swsib-select">
                                        <option value=""><?php _e('Select a product', 'swiftspeed-siberian'); ?></option>
                                        <?php
                                        $subscription_products = $this->get_woocommerce_subscription_products();
                                        if (!empty($subscription_products)) {
                                            $existing_mappings = isset($woo_options['mappings']) ? $woo_options['mappings'] : array();
                                            foreach ($subscription_products as $prod) {
                                                $already_mapped = false;
                                                foreach ($existing_mappings as $m) {
                                                    if ($m['woo_product_id'] == $prod['id']) {
                                                        $already_mapped = true;
                                                        break;
                                                    }
                                                }
                                                $disabled   = $already_mapped ? 'disabled' : '';
                                                $extra_text = $already_mapped ? ' (' . __('Already mapped', 'swiftspeed-siberian') . ')' : '';
                                                echo '<option value="' . esc_attr($prod['id']) . '" ' . $disabled . '>';
                                                echo esc_html($prod['name'] . ' - ' . $prod['price'] . $extra_text);
                                                echo '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="swsib-form-col">
                                    <label for="role_id"><?php _e('Assigned Role', 'swiftspeed-siberian'); ?></label>
                                    <select id="role_id" class="swsib-select">
                                        <?php if (!empty($roles)) {
                                            foreach ($roles as $r) {
                                                if ($r['role_id'] == 1) {
                                                    continue;
                                                }
                                                echo '<option value="' . esc_attr($r['role_id']) . '">' . esc_html($r['label'] . ' (ID: ' . $r['role_id'] . ')') . '</option>';
                                            }
                                        } else {
                                            echo '<option value="2">Default (ID: 2)</option>';
                                        } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="swsib-form-actions">
                                <button type="button" id="add_mapping_button" class="button button-primary">
                                    <?php _e('Add Mapping', 'swiftspeed-siberian'); ?>
                                </button>
                                <div id="mapping_message" class="swsib-message"></div>
                            </div>
                        </div>
                        <div id="existing_mappings" class="swsib-table-container">
                            <h4><?php _e('Existing Mappings', 'swiftspeed-siberian'); ?></h4>
                            <table class="widefat swsib-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('SiberianCMS Plan', 'swiftspeed-siberian'); ?></th>
                                        <th><?php _e('WooCommerce Product', 'swiftspeed-siberian'); ?></th>
                                        <th><?php _e('Assigned Role', 'swiftspeed-siberian'); ?></th>
                                        <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mappings_tbody">
                                    <?php
                                    $mappings = isset($woo_options['mappings']) ? $woo_options['mappings'] : array();
                                    if (empty($mappings)) {
                                        echo '<tr class="no-mappings-row"><td colspan="4">' .
                                             __('No mappings found. Add your first mapping above.', 'swiftspeed-siberian') .
                                             '</td></tr>';
                                    } else {
                                        foreach ($mappings as $map) {
                                            echo '<tr data-mapping-id="' . esc_attr($map['id']) . '">';
                                            echo '<td class="siberian-plan-id" data-plan-id="' . esc_attr($map['siberian_plan_id']) . '">';
                                            $plan_name = '';
                                            if (isset($this->db)) {
                                                $plans = $this->db->get_siberian_plans();
                                                foreach ($plans as $p) {
                                                    if ($p['subscription_id'] == $map['siberian_plan_id']) {
                                                        $plan_name = $p['name'] . ' - ' . $p['regular_payment'];
                                                        break;
                                                    }
                                                }
                                            }
                                            echo esc_html($plan_name);
                                            echo '</td>';
                                            echo '<td class="woo-product-id" data-product-id="' . esc_attr($map['woo_product_id']) . '">';
                                            $prod_name = '';
                                            foreach ($subscription_products as $prod) {
                                                if ($prod['id'] == $map['woo_product_id']) {
                                                    $prod_name = $prod['name'] . ' - ' . $prod['price'];
                                                    break;
                                                }
                                            }
                                            echo esc_html($prod_name);
                                            echo '</td>';
                                            echo '<td>';
                                            $role_name = '';
                                            if (!empty($roles)) {
                                                foreach ($roles as $r) {
                                                    if ($r['role_id'] == $map['role_id']) {
                                                        $role_name = $r['label'] . ' (ID: ' . $r['role_id'] . ')';
                                                        break;
                                                    }
                                                }
                                            }
                                            echo esc_html($role_name);
                                            echo '</td>';
                                            echo '<td>';
                                            echo '<button type="button" class="button button-small delete-mapping" data-mapping-id="' . esc_attr($map['id']) . '">';
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
                    </div>
                    
                    <!-- Product Access Control Section -->
                    <div class="swsib-section">
                        <h3><?php _e('Product Access Control', 'swiftspeed-siberian'); ?></h3>
                        <div class="swsib-notice info">
                            <p>
                                <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
                                <?php _e('Configure the popup that will appear on mapped products. In the fields below, enter the text you want to display and either a URL or a shortcode for the popup action. If you enter a URL, a button will be displayed; if you enter a shortcode (e.g. [swsib_login text="Create an App"]) it will show auto login button and allow your user to reauthenticate back to your SiberianCMS.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_popup_message"><?php _e('Popup Message for Product', 'swiftspeed-siberian'); ?></label>
                            <textarea id="swsib_options_woocommerce_popup_message"
                                      name="swsib_options[woocommerce][popup_message]"
                                      rows="3" class="regular-text"><?php echo isset($woo_options['popup_message']) ? esc_textarea($woo_options['popup_message']) : ''; ?></textarea>
                            <p class="swsib-field-note">
                                <?php _e('Enter the text to display in the popup (e.g., "You must create an app to buy this subscription.").', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_popup_action"><?php _e('Popup Action for Product', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="swsib_options_woocommerce_popup_action"
                                   name="swsib_options[woocommerce][popup_action]"
                                   value="<?php echo esc_attr($popup_action); ?>"
                                   class="regular-text" />
                            <p class="swsib-field-note">
                                <?php _e('Enter a URL (to display as a button) or a shortcode (e.g. [swsib_login text="Create an App"]) for the popup action.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Post-Purchase Popup Settings -->
                    <div class="swsib-section">
                        <h3><?php _e('Post-Purchase Popup Settings', 'swiftspeed-siberian'); ?></h3>
                        <div class="swsib-notice info">
                            <p>
                                <strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong>
                                <?php _e('Configure the popup that will appear after a successful purchase of a mapped product. This popup will include a "Manage Subscriptions" button and an optional action button.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_purchase_popup_message"><?php _e('Popup Message after Successful Purchase', 'swiftspeed-siberian'); ?></label>
                            <textarea id="swsib_options_woocommerce_purchase_popup_message"
                                      name="swsib_options[woocommerce][purchase_popup_message]"
                                      rows="3" class="regular-text"><?php echo isset($woo_options['purchase_popup_message']) ? esc_textarea($woo_options['purchase_popup_message']) : ''; ?></textarea>
                            <p class="swsib-field-note">
                                <?php _e('Enter the message to display after a successful purchase (e.g., "Congratulations, your subscription has been activated. Your app is now ready.").', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_purchase_popup_action"><?php _e('Popup Action after Successful Purchase', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="swsib_options_woocommerce_purchase_popup_action"
                                   name="swsib_options[woocommerce][purchase_popup_action]"
                                   value="<?php echo esc_attr($purchase_popup_action); ?>"
                                   class="regular-text" />
                            <p class="swsib-field-note">
                                <?php _e('Enter a URL (to display as "Continue to Your App" button) or a shortcode for the popup action.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_manage_subscription_url"><?php _e('Manage Subscription Page URL', 'swiftspeed-siberian'); ?></label>
                            <input type="url" id="swsib_options_woocommerce_manage_subscription_url"
                                   name="swsib_options[woocommerce][manage_subscription_url]"
                                   value="<?php echo esc_url($manage_subscription_url); ?>"
                                   class="regular-text" />
                            <p class="swsib-field-note">
                                <?php _e('Enter the URL for the "Manage Subscriptions" button. If left empty, it will default to: [your-site]/my-account/subscriptions/', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- CORS Settings Section -->
                    <div class="swsib-section">
                        <h3><?php _e('CORS Settings', 'swiftspeed-siberian'); ?></h3>
                        <p><?php _e('Configure Cross-Origin Resource Sharing for SiberianCMS integration.', 'swiftspeed-siberian'); ?></p>
                        <div class="swsib-field">
                            <label for="swsib_options_woocommerce_allowed_origin_url"><?php _e('Add Allowed Origin', 'swiftspeed-siberian'); ?></label>
                            <div class="swsib-input-group">
                                <input type="url"
                                       id="swsib_options_woocommerce_allowed_origin_url"
                                       class="regular-text"
                                       placeholder="https://example.com" />
                                <button type="button" id="add_allowed_origin" class="button button-secondary">
                                    <?php _e('Add Origin', 'swiftspeed-siberian'); ?>
                                </button>
                            </div>
                            <p class="swsib-field-note">
                                <?php _e('Enter the full URL of your SiberianCMS installation (e.g., https://dev.swiftspeedappcreator.com).', 'swiftspeed-siberian'); ?>
                            </p>
                            
                            <div id="allowed_origins_container" class="swsib-table-container">
                                <h4><?php _e('Allowed Origins', 'swiftspeed-siberian'); ?></h4>
                                <p><?php _e('Below are the origins allowed to make cross-origin requests to this site.', 'swiftspeed-siberian'); ?></p>
                                <table class="widefat swsib-table">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Origin URL', 'swiftspeed-siberian'); ?></th>
                                            <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="allowed_origins_tbody">
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
                                                echo '<button type="button" class="button button-small delete-origin" data-origin-id="' . esc_attr($origin['id']) . '">';
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
                                    <?php _e('You must add the URL of your SiberianCMS installation above. Otherwise, CORS will fail.', 'swiftspeed-siberian'); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="swsib-actions">
                <input type="submit" name="submit" id="submit" class="button button-primary"
                       value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>">
            </div>
        </form>
        <?php
    }
}