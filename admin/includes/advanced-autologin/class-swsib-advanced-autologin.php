<?php
/**
 * Advanced Auto Login functionality for the plugin.
 */
class SwiftSpeed_Siberian_Advanced_AutoLogin {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Register AJAX handlers for managing buttons
        add_action('wp_ajax_swsib_add_advanced_login_button', array($this, 'ajax_add_button'));
        add_action('wp_ajax_swsib_delete_advanced_login_button', array($this, 'ajax_delete_button'));
        
        // Register AJAX handler for fetching Siberian roles (similar to autologin)
        add_action('wp_ajax_swsib_get_advanced_roles', array($this, 'ajax_get_roles'));
        
        // Register action for form submission
        add_action('admin_post_swsib_save_advanced_autologin_settings', array($this, 'process_form_submission'));
        
        // Register scripts and styles for admin page
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets($hook) {
        // Only load on plugin admin page
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        
        // Enqueue the CSS
        wp_enqueue_style(
            'swsib-advanced-autologin-css',
            SWSIB_PLUGIN_URL . 'admin/includes/advanced-autologin/advanced-autologin.css',
            array(),
            SWSIB_VERSION
        );
        
        // Ensure color picker is loaded
        wp_enqueue_style('wp-color-picker');
        
        // Enqueue the JS
        wp_enqueue_script(
            'swsib-advanced-autologin-js',
            SWSIB_PLUGIN_URL . 'admin/includes/advanced-autologin/advanced-autologin.js',
            array('jquery', 'wp-color-picker'),
            SWSIB_VERSION,
            true
        );
        
        // Pass PHP variables to JavaScript
        wp_localize_script(
            'swsib-advanced-autologin-js',
            'swsib_adv_autologin_vars',
            array(
                'nonce'              => wp_create_nonce('swsib-nonce'),
                'ajax_url'           => admin_url('admin-ajax.php'),
                'is_db_configured'   => swsib()->is_db_configured(),
                'db_connect_url'     => admin_url('admin.php?page=swsib-integration&tab_id=db_connect'),
                'add_button_text'    => __('Create Shortcode', 'swiftspeed-siberian'),
                'delete_button_text' => __('Delete', 'swiftspeed-siberian'),
                'confirm_delete'     => __('Are you sure you want to delete this button?', 'swiftspeed-siberian')
            )
        );
    }
    
    /**
     * Write to log using the central logging manager
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('autologin_advanced', 'backend', $message);
        }
    }
    
    /**
     * Process form submission
     */
    public function process_form_submission() {
        // Log form submission
        $this->log_message("Advanced Auto Login form submission received");
        
        // Check nonce (custom field name to avoid duplicate id="_wpnonce")
        if (
            ! isset($_POST['_wpnonce_swsib_advanced_autologin']) ||
            ! wp_verify_nonce($_POST['_wpnonce_swsib_advanced_autologin'], 'swsib_advanced_autologin_nonce')
        ) {
            $this->log_message("Advanced Auto Login nonce verification failed");
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=advanced_autologin&error=nonce_failed'));
            exit;
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize advanced_autologin array if it doesn't exist
        if (!isset($options['advanced_autologin'])) {
            $options['advanced_autologin'] = array(
                'enabled' => false,
                'buttons' => array()
            );
        }
        
        // Process each field
        if (isset($_POST['swsib_options']['advanced_autologin'])) {
            // Store enabled state
            $options['advanced_autologin']['enabled'] = 
                isset($_POST['swsib_options']['advanced_autologin']['enabled']);
            
            // Process buttons (if present in the form)
            if (
                isset($_POST['swsib_options']['advanced_autologin']['buttons']) &&
                is_array($_POST['swsib_options']['advanced_autologin']['buttons'])
            ) {
                // Process each button
                foreach ($_POST['swsib_options']['advanced_autologin']['buttons'] as $button_id => $button_data) {
                    $options['advanced_autologin']['buttons'][$button_id] = array(
                        'text'                => sanitize_text_field($button_data['text'] ?? ''),
                        'role_id'             => sanitize_text_field($button_data['role_id'] ?? '2'),
                        'color'               => sanitize_hex_color($button_data['color'] ?? '#3a4b79'),
                        'text_color'          => sanitize_hex_color($button_data['text_color'] ?? '#ffffff'),
                        'sync_existing_role'  => ! empty($button_data['sync_existing_role'])
                    );
                }
            }
        }
        
        // Save options
        update_option('swsib_options', $options);
        $this->log_message("Advanced Auto Login settings saved");
        
        // Add settings updated notice
        add_settings_error(
            'swsib_options',
            'settings_updated',
            __('Advanced Auto Login settings saved.', 'swiftspeed-siberian'),
            'updated'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // Redirect back to the tab with tab-specific parameter
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=advanced_autologin&advanced_autologin_updated=true'));
        exit;
    }

    /**
     * AJAX handler to add a new button
     */
    public function ajax_add_button() {
        // Check nonce
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        // Check permissions
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        // Get button data
        $text               = sanitize_text_field($_POST['text'] ?? '');
        $role_id            = sanitize_text_field($_POST['role_id'] ?? '2');
        $sync_existing_role = ! empty($_POST['sync_existing_role']);
        // Default colors
        $auto_login_options = $this->options['auto_login'] ?? array();
        $color              = $auto_login_options['button_color'] ?? '#3a4b79';
        $text_color         = $auto_login_options['button_text_color'] ?? '#ffffff';
        if (empty($text)) {
            wp_send_json_error(array('message' => 'Button text is required'));
        }
        $options = get_option('swsib_options', array());
        if (! isset($options['advanced_autologin']['buttons'])) {
            $options['advanced_autologin']['buttons'] = array();
        }
        $button_id = 'btn_' . uniqid();
        $options['advanced_autologin']['buttons'][$button_id] = array(
            'text'               => $text,
            'role_id'            => $role_id,
            'color'              => $color,
            'text_color'         => $text_color,
            'sync_existing_role' => $sync_existing_role
        );
        update_option('swsib_options', $options);
        $this->log_message("New advanced auto login button created: {$button_id}");
        wp_send_json_success(array(
            'button_id' => $button_id,
            'button'    => $options['advanced_autologin']['buttons'][$button_id]
        ));
    }
    
    /**
     * AJAX handler to delete a button
     */
    public function ajax_delete_button() {
        // Check nonce
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        // Check permissions
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        $button_id = sanitize_text_field($_POST['button_id'] ?? '');
        if (empty($button_id)) {
            wp_send_json_error(array('message' => 'Button ID is required'));
        }
        $options = get_option('swsib_options', array());
        if (! isset($options['advanced_autologin']['buttons'][$button_id])) {
            wp_send_json_error(array('message' => 'Button not found'));
        }
        unset($options['advanced_autologin']['buttons'][$button_id]);
        update_option('swsib_options', $options);
        $this->log_message("Advanced auto login button deleted: {$button_id}");
        wp_send_json_success(array('message' => 'Button deleted successfully'));
    }
    
    /**
     * AJAX handler for getting Siberian roles
     */
    public function ajax_get_roles() {
        // Check nonce
        if (! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        // Check permissions
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        $roles_result = $this->get_siberian_roles();
        if (is_wp_error($roles_result)) {
            wp_send_json_error(array('message' => $roles_result->get_error_message()));
        } else {
            wp_send_json_success(array('roles' => $roles_result));
        }
    }
    
    /**
     * Get Siberian roles directly from DB
     */
    private function get_siberian_roles() {
        if (! swsib()->is_db_configured()) {
            return new WP_Error('db_not_configured', 'Database connection is not configured');
        }
        $db_options = $this->options['db_connect'] ?? array();
        $host       = $db_options['host'] ?? '';
        $database   = $db_options['database'] ?? '';
        $username   = $db_options['username'] ?? '';
        $password   = $db_options['password'] ?? '';
        $port       = ! empty($db_options['port']) ? intval($db_options['port']) : 3306;
        $prefix     = $db_options['prefix'] ?? '';
        $conn = new mysqli($host, $username, $password, $database, $port);
        if ($conn->connect_error) {
            return new WP_Error('db_connect', 'Database connection failed: ' . $conn->connect_error);
        }
        $conn->set_charset('utf8');
        $table_name = $prefix . 'acl_role';
        $table_check = $conn->query("SHOW TABLES LIKE '{$table_name}'");
        if ($table_check->num_rows === 0) {
            $table_name = 'acl_role';
            $table_check = $conn->query("SHOW TABLES LIKE '{$table_name}'");
            if ($table_check->num_rows === 0) {
                $conn->close();
                return new WP_Error('table_not_found', 'Table acl_role not found in database');
            }
        }
        $query  = "SELECT role_id, code, label, parent_id, is_self_assignable FROM {$table_name} ORDER BY role_id ASC";
        $result = $conn->query($query);
        if (! $result) {
            $conn->close();
            return new WP_Error('query_error', 'Error querying roles: ' . $conn->error);
        }
        $roles = array();
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
        $conn->close();
        return $roles;
    }
    
    /**
     * Display advanced auto login settings
     */
    public function display_settings() {
        $advanced_autologin_options = $this->options['advanced_autologin'] ?? array();
        $enabled  = ! empty($advanced_autologin_options['enabled']);
        $buttons  = $advanced_autologin_options['buttons'] ?? array();
        $auto_login_options = $this->options['auto_login'] ?? array();
        $is_db_configured   = swsib()->is_db_configured();
        $roles_error        = '';
        $roles              = array();
        if ($is_db_configured) {
            $roles_result = $this->get_siberian_roles();
            if (is_wp_error($roles_result)) {
                $roles_error = $roles_result->get_error_message();
            } else {
                $roles = $roles_result;
            }
        }
        if (isset($_GET['advanced_autologin_updated']) && $_GET['advanced_autologin_updated'] === 'true') {
            echo '<div class="swsib-notice success"><p>' . __('Advanced Auto Login settings saved successfully.', 'swiftspeed-siberian') . '</p></div>';
        }
        if (isset($_GET['error']) && $_GET['error'] === 'nonce_failed') {
            echo '<div class="swsib-notice error"><p>' . __('Security check failed. Please try again.', 'swiftspeed-siberian') . '</p></div>';
        }
        ?>
        <h2><?php _e('Advanced Auto Login', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Create multiple auto-login buttons with different roles for your Siberian CMS users.', 'swiftspeed-siberian'); ?>
        </p>
        
        <?php if (! $is_db_configured): ?>
            <div class="swsib-notice warning">
                <p><strong><?php _e('DB Connect Required', 'swiftspeed-siberian'); ?></strong></p>
                <p><?php _e('You need to configure DB Connect before using Advanced Auto Login.', 'swiftspeed-siberian'); ?></p>
                <p><a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect'); ?>" class="button button-secondary"><?php _e('Configure DB Connect', 'swiftspeed-siberian'); ?></a></p>
            </div>
        <?php else: ?>
            <div class="swsib-notice warning">
                <p><strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
                <?php _e('After adding or removing buttons, be sure to click "Save Changes" for your changes to take effect on the frontend.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="swsib-advanced-autologin-form" class="swsib-settings-form">
                <?php
                // Custom nonce field to avoid duplicate id="_wpnonce"
                wp_nonce_field('swsib_advanced_autologin_nonce', '_wpnonce_swsib_advanced_autologin');
                ?>
                <input type="hidden" name="action" value="swsib_save_advanced_autologin_settings">
                <input type="hidden" name="tab_id" value="advanced_autologin">
                
                <div class="swsib-field switch-field">
                    <label for="swsib_options_advanced_autologin_enabled"><?php _e('Enable Advanced Auto Login', 'swiftspeed-siberian'); ?></label>
                    <div class="toggle-container">
                        <label class="switch">
                            <input type="checkbox" id="swsib_options_advanced_autologin_enabled"
                                   name="swsib_options[advanced_autologin][enabled]"
                                   value="1" <?php checked($enabled); ?> />
                            <span class="slider round"></span>
                        </label>
                        <p class="swsib-field-note"><?php _e('Enable to configure role-specific auto-login buttons.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div id="advanced-autologin-settings" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                    <div class="swsib-section-header">
                        <h3><?php _e('How It Works', 'swiftspeed-siberian'); ?></h3>
                    </div>
                    <div class="swsib-notice info">
                        <p><?php _e('Each button you create will have its own unique shortcode. When users use a specific button, they will be assigned the role you select for that button.', 'swiftspeed-siberian'); ?></p>
                        <p><?php printf(
                            __('Button appearance and behavior are inherited from the <a href="%s">Auto Login</a> configurations. The only difference is that each button can assign a different role to users.', 'swiftspeed-siberian'),
                            admin_url('admin.php?page=swsib-integration&tab_id=auto_login')
                        ); ?></p>
                    </div>
                    
                    <?php if (! empty($roles_error)): ?>
                        <div class="swsib-notice error">
                            <p><strong><?php _e('Error loading roles:', 'swiftspeed-siberian'); ?></strong> <?php echo esc_html($roles_error); ?></p>
                            <p><?php _e('Please check your DB Connect settings and make sure your connection is working properly.', 'swiftspeed-siberian'); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="swsib-section-header">
                        <h3><?php _e('Create New Button', 'swiftspeed-siberian'); ?></h3>
                    </div>
                    <div class="swsib-button-creator">
                        <div class="swsib-field">
                            <label for="new_button_text"><?php _e('Button Text', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="new_button_text" value="" placeholder="<?php _e('Enter button text', 'swiftspeed-siberian'); ?>" />
                        </div>
                        <div class="swsib-field">
                            <label for="new_button_role"><?php _e('Siberian Role', 'swiftspeed-siberian'); ?></label>
                            <?php if (! empty($roles)): ?>
                                <select id="new_button_role">
                                    <?php foreach ($roles as $role): 
                                        $option_text = 'Role ID ' . $role['role_id'] . ', ' . $role['code'] . ', ' . $role['label'];
                                        if ($role['role_id'] == 2) {
                                            $option_text .= ' (Standard SiberianCMS signup access)';
                                        }
                                        ?>
                                        <option value="<?php echo esc_attr($role['role_id']); ?>" <?php selected($role['role_id'], 2); ?>>
                                            <?php echo esc_html($option_text); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" id="new_button_role" value="2" readonly />
                                <p class="swsib-field-note"><?php _e('Unable to load roles. Default role (ID 2) will be used.', 'swiftspeed-siberian'); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="swsib-field switch-field">
                            <label for="new_button_sync_existing_role"><?php _e('Sync Existing User Role', 'swiftspeed-siberian'); ?></label>
                            <div class="toggle-container">
                                <label class="switch">
                                    <input type="checkbox" id="new_button_sync_existing_role" value="1" />
                                    <span class="slider round"></span>
                                </label>
                                <p class="swsib-field-note swsib-warning-note">
                                    <strong><?php _e('Warning:', 'swiftspeed-siberian'); ?></strong> 
                                    <?php _e('This will update existing Siberian user roles to match this button\'s role when they login. Only enable if you know exactly what you\'re doing.', 'swiftspeed-siberian'); ?>
                                </p>
                            </div>
                        </div>
                        <input type="hidden" id="new_button_color"       value="<?php echo esc_attr($auto_login_options['button_color'] ?? '#3a4b79'); ?>" />
                        <input type="hidden" id="new_button_text_color"  value="<?php echo esc_attr($auto_login_options['button_text_color'] ?? '#ffffff'); ?>" />
                        <div class="swsib-field">
                            <button type="button" id="add_advanced_button" class="button button-primary"><?php _e('Create Shortcode', 'swiftspeed-siberian'); ?></button>
                        </div>
                    </div>
                    
                    <div class="swsib-section-header">
                        <h3><?php _e('Existing Buttons', 'swiftspeed-siberian'); ?></h3>
                    </div>
                    <div id="advanced-buttons-list" class="swsib-buttons-list">
                        <?php if (empty($buttons)): ?>
                            <div class="swsib-no-buttons">
                                <p><?php _e('No buttons created yet. Use the form above to create your first button.', 'swiftspeed-siberian'); ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($buttons as $button_id => $button): ?>
                                <div class="swsib-button-item" data-id="<?php echo esc_attr($button_id); ?>">
                                    <input type="hidden" name="swsib_options[advanced_autologin][buttons][<?php echo esc_attr($button_id); ?>][text]"           value="<?php echo esc_attr($button['text']); ?>" class="button-text-input" />
                                    <input type="hidden" name="swsib_options[advanced_autologin][buttons][<?php echo esc_attr($button_id); ?>][role_id]"        value="<?php echo esc_attr($button['role_id']); ?>" class="button-role-input" />
                                    <input type="hidden" name="swsib_options[advanced_autologin][buttons][<?php echo esc_attr($button_id); ?>][color]"          value="<?php echo esc_attr($button['color']); ?>" />
                                    <input type="hidden" name="swsib_options[advanced_autologin][buttons][<?php echo esc_attr($button_id); ?>][text_color]"     value="<?php echo esc_attr($button['text_color']); ?>" />
                                    <input type="hidden" name="swsib_options[advanced_autologin][buttons][<?php echo esc_attr($button_id); ?>][sync_existing_role]" value="<?php echo ! empty($button['sync_existing_role']) ? '1' : '0'; ?>" class="button-sync-role-input" />
                                    
                                    <div class="button-info">
                                        <div class="button-name"><?php echo esc_html($button['text']); ?></div>
                                        <div class="button-role">
                                            <?php 
                                            $role_label = 'Role ID: ' . $button['role_id'];
                                            if (! empty($roles)) {
                                                foreach ($roles as $role) {
                                                    if ($role['role_id'] == $button['role_id']) {
                                                        $role_label = $role['label'] . ' (ID: ' . $role['role_id'] . ')';
                                                        break;
                                                    }
                                                }
                                            }
                                            echo esc_html($role_label);
                                            ?>
                                        </div>
                                        <div class="button-sync-role">
                                            <?php if (! empty($button['sync_existing_role'])): ?>
                                                <span class="sync-enabled"><?php _e('Role Sync: Enabled', 'swiftspeed-siberian'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="button-shortcode">
                                            <code>[swsib_advanced_login id="<?php echo esc_attr($button_id); ?>"]</code>
                                            <button type="button" class="copy-shortcode" data-shortcode='[swsib_advanced_login id="<?php echo esc_attr($button_id); ?>"]'>
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="button-actions">
                                        <button type="button" class="edit-button" data-id="<?php echo esc_attr($button_id); ?>">
                                            <span class="dashicons dashicons-edit"></span>
                                        </button>
                                        <button type="button" class="delete-button" data-id="<?php echo esc_attr($button_id); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </div>
                                    
                                    <div class="button-edit-form" style="display:none;">
                                        <div class="edit-field">
                                            <label><?php _e('Button Text:', 'swiftspeed-siberian'); ?></label>
                                            <input type="text" class="edit-button-text" value="<?php echo esc_attr($button['text']); ?>" />
                                        </div>
                                        <div class="edit-field">
                                            <label><?php _e('Role ID:', 'swiftspeed-siberian'); ?></label>
                                            <?php if (! empty($roles)): ?>
                                                <select class="edit-button-role">
                                                    <?php foreach ($roles as $role): 
                                                        $option_text = 'Role ID ' . $role['role_id'] . ', ' . $role['code'] . ', ' . $role['label'];
                                                        if ($role['role_id'] == 2) {
                                                            $option_text .= ' (Standard SiberianCMS signup access)';
                                                        }
                                                        ?>
                                                        <option value="<?php echo esc_attr($role['role_id']); ?>" <?php selected($role['role_id'], $button['role_id']); ?>>
                                                            <?php echo esc_html($option_text); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="text" class="edit-button-role" value="<?php echo esc_attr($button['role_id']); ?>" />
                                            <?php endif; ?>
                                        </div>
                                        <div class="edit-field switch-field">
                                            <label><?php _e('Sync Existing User Role:', 'swiftspeed-siberian'); ?></label>
                                            <div class="toggle-container">
                                                <label class="switch">
                                                    <input type="checkbox" class="edit-button-sync-role" value="1" <?php checked(! empty($button['sync_existing_role'])); ?> />
                                                    <span class="slider round"></span>
                                                </label>
                                                <p class="swsib-field-note swsib-warning-note">
                                                    <strong><?php _e('Warning:', 'swiftspeed-siberian'); ?></strong> 
                                                    <?php _e('This will update existing Siberian user roles to match this button\'s role when they login. Only enable if you know exactly what you\'re doing.', 'swiftspeed-siberian'); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="edit-actions">
                                            <button type="button" class="button button-primary save-button-edit"><?php _e('Save', 'swiftspeed-siberian'); ?></button>
                                            <button type="button" class="button cancel-button-edit"><?php _e('Cancel', 'swiftspeed-siberian'); ?></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div id="general-button-behavior-section" class="swsib-section-header">
                        <h3><?php _e('General Button Behavior', 'swiftspeed-siberian'); ?></h3>
                    </div>
                    <div class="swsib-notice info">
                        <p><strong><?php _e('Button Design & Colors:', 'swiftspeed-siberian'); ?></strong> 
                        <?php printf(
                            __('To customize button appearance, go to <a href="%s">Auto Login tab</a>.', 'swiftspeed-siberian'),
                            admin_url('admin.php?page=swsib-integration&tab_id=auto_login&section=button-design-section')
                        ); ?></p>
                        <p><strong><?php _e('Auto-Authentication:', 'swiftspeed-siberian'); ?></strong> 
                        <?php printf(
                            __('To enable automatic authentication without clicking, go to <a href="%s">Automatic Authentication in Auto Login tab</a>.', 'swiftspeed-siberian'),
                            admin_url('admin.php?page=swsib-integration&tab_id=auto_login&section=auto-authentication-section')
                        ); ?></p>
                        <p><strong><?php _e('Non-Logged In User Behavior:', 'swiftspeed-siberian'); ?></strong> 
                        <?php printf(
                            __('To customize what happens when users are not logged in, go to <a href="%s">Non-Logged In User in Auto Login tab</a>.', 'swiftspeed-siberian'),
                            admin_url('admin.php?page=swsib-integration&tab_id=auto_login&section=non-logged-in-section')
                        ); ?></p>
                    </div>
                </div>
                
                <div class="swsib-actions" id="advanced-autologin-save-button-container">
                    <input type="submit" name="submit" id="advanced-autologin-save-button" class="button button-primary" value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>">
                </div>
            </form>
            
            <style>
            .swsib-highlight-section {
                animation: highlight-pulse 1s ease-in-out;
                background-color: rgba(255, 255, 0, 0.2);
                border-radius: 4px;
                padding: 10px;
                transition: background-color 0.5s ease-out;
            }
            @keyframes highlight-pulse {
                0% { background-color: rgba(255, 255, 0, 0); }
                50% { background-color: rgba(255, 255, 0, 0.3); }
                100% { background-color: rgba(255, 255, 0, 0.2); }
            }
            .swsib-warning-note {
                color: #d63638;
            }
            .sync-enabled {
                display: inline-block;
                background-color: #ffdddd;
                color: #d63638;
                font-size: 0.85em;
                padding: 2px 6px;
                border-radius: 3px;
                margin-top: 5px;
            }
            </style>
        <?php endif;
    }
}
