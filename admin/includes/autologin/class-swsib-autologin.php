<?php
/**
 * Auto Login functionality for the plugin.
 */
class SwiftSpeed_Siberian_Autologin {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = get_option('swsib_options', array());
        
        // Log initialization
        $this->log_message("Auto Login class initialized");
        
        // Register AJAX handlers for testing API connection
        add_action('wp_ajax_swsib_test_api', array($this, 'ajax_test_api'));
        
        // Register action for form submission
        add_action('admin_post_swsib_save_autologin_settings', array($this, 'process_form_submission'));
        
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
        
        // Get script/style directory path
        $dir_path = plugin_dir_path(__FILE__);
        $dir_url = plugin_dir_url(__FILE__);
        
        // Enqueue the CSS
        wp_enqueue_style(
            'swsib-autologin-css',
            $dir_url . 'autologin.css',
            array(),
            SWSIB_VERSION
        );
        
        // Ensure color picker is loaded
        wp_enqueue_style('wp-color-picker');
        
        // Enqueue the JS
        wp_enqueue_script(
            'swsib-autologin-js',
            $dir_url . 'autologin.js',
            array('jquery', 'wp-color-picker'),
            SWSIB_VERSION,
            true
        );
        
        // Get current settings for passing to JS
        $auto_login_options = isset($this->options['auto_login']) ? $this->options['auto_login'] : array();
        $button_color = isset($auto_login_options['button_color']) ? $auto_login_options['button_color'] : '#3a4b79';
        $button_text_color = isset($auto_login_options['button_text_color']) ? $auto_login_options['button_text_color'] : '#ffffff';
        
        // Pass PHP variables to JavaScript
        wp_localize_script(
            'swsib-autologin-js',
            'swsib_autologin_vars',
            array(
                'button_color' => $button_color,
                'button_text_color' => $button_text_color,
                'nonce' => wp_create_nonce('swsib-nonce')
            )
        );
    }
    
    /**
     * Write to log using the central logging manager
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('auto_login', 'backend', $message);
        }
    }
    
    /**
     * Process form submission
     */
    public function process_form_submission() {
        // Log form submission
        $this->log_message("Form submission received");
        
        // Check nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'swsib_autologin_nonce')) {
            $this->log_message("Nonce verification failed");
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=auto_login&error=nonce_failed'));
            exit;
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize auto_login array if it doesn't exist
        if (!isset($options['auto_login'])) {
            $options['auto_login'] = array();
        }
        
        // Store the form data for logging
        $this->log_message("Form data: " . print_r($_POST['swsib_options']['auto_login'], true));
        
        // Process each field
        $auto_login = $_POST['swsib_options']['auto_login'];
        
        // URL fields
        $url_fields = array('siberian_url', 'login_redirect_url');
        foreach ($url_fields as $field) {
            if (isset($auto_login[$field])) {
                $options['auto_login'][$field] = esc_url_raw($auto_login[$field]);
                $this->log_message("Updated $field to: " . $options['auto_login'][$field]);
            }
        }
        
        // Text fields
        $text_fields = array('autologin_text', 'notification_text', 'api_user', 'default_role_id', 'processing_text', 'login_button_text', 'not_logged_in_message', 'login_notification_text');
        foreach ($text_fields as $field) {
            if (isset($auto_login[$field])) {
                $options['auto_login'][$field] = sanitize_text_field($auto_login[$field]);
                $this->log_message("Updated $field to: " . $options['auto_login'][$field]);
            }
        }
        
        // Color fields
        $color_fields = array('button_color', 'button_text_color', 'processing_bg_color', 'processing_text_color');
        foreach ($color_fields as $field) {
            if (isset($auto_login[$field])) {
                $options['auto_login'][$field] = sanitize_hex_color($auto_login[$field]);
                $this->log_message("Updated $field to: " . $options['auto_login'][$field]);
            }
        }
        
        // Password field (no sanitization)
        if (isset($auto_login['api_password'])) {
            $options['auto_login']['api_password'] = $auto_login['api_password'];
            $this->log_message("Updated api_password (value hidden)");
        }
        
        // Checkbox fields
        $checkbox_fields = array('keep_data_on_uninstall', 'auto_authenticate', 'enable_login_redirect', 'enable_siberian_config');
        foreach ($checkbox_fields as $field) {
            $options['auto_login'][$field] = isset($auto_login[$field]);
            $this->log_message("Updated $field to: " . ($options['auto_login'][$field] ? 'true' : 'false'));
        }
        
        // Preserve other fields if they exist
        $preserve_fields = array('app_key', 'connection_type');
        foreach ($preserve_fields as $field) {
            if (isset($options['auto_login'][$field])) {
                // Keep existing value
                $this->log_message("Preserved $field: " . $options['auto_login'][$field]);
            } else {
                // Set default value
                $options['auto_login'][$field] = '';
                $this->log_message("Set default empty value for $field");
            }
        }
        
        // Save options
        update_option('swsib_options', $options);
        $this->log_message("Saved options to database");
        
        // Add settings updated notice
        add_settings_error(
            'swsib_options',
            'settings_updated',
            __('Auto Login settings saved.', 'swiftspeed-siberian'),
            'updated'
        );
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // Redirect back to the tab
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=auto_login&settings-updated=true'));
        exit;
    }
    
    /**
     * Display Auto Login settings
     */
    public function display_settings() {
        $auto_login_options = isset($this->options['auto_login']) ? $this->options['auto_login'] : array();
        $siberian_url = isset($auto_login_options['siberian_url']) ? $auto_login_options['siberian_url'] : '';
        $autologin_text = isset($auto_login_options['autologin_text']) ? $auto_login_options['autologin_text'] : 'App Dashboard';
        $button_color = isset($auto_login_options['button_color']) ? $auto_login_options['button_color'] : '#3a4b79';
        $button_text_color = isset($auto_login_options['button_text_color']) ? $auto_login_options['button_text_color'] : '#ffffff';
        $notification_text = isset($auto_login_options['notification_text']) ? $auto_login_options['notification_text'] : 'Connecting to Siberian. Please wait...';
        $api_user = isset($auto_login_options['api_user']) ? $auto_login_options['api_user'] : '';
        $api_password = isset($auto_login_options['api_password']) ? $auto_login_options['api_password'] : '';
        $default_role_id = isset($auto_login_options['default_role_id']) ? $auto_login_options['default_role_id'] : '2';
        $keep_data = isset($auto_login_options['keep_data_on_uninstall']) ? $auto_login_options['keep_data_on_uninstall'] : true;
        $auto_authenticate = isset($auto_login_options['auto_authenticate']) ? $auto_login_options['auto_authenticate'] : false;
        $processing_text = isset($auto_login_options['processing_text']) ? $auto_login_options['processing_text'] : 'Processing...';
        $processing_bg_color = isset($auto_login_options['processing_bg_color']) ? $auto_login_options['processing_bg_color'] : '#f5f5f5';
        $processing_text_color = isset($auto_login_options['processing_text_color']) ? $auto_login_options['processing_text_color'] : '#333333';
        $enable_siberian_config = isset($auto_login_options['enable_siberian_config']) ? $auto_login_options['enable_siberian_config'] : true;
        
        // Login redirect settings
        $enable_login_redirect = isset($auto_login_options['enable_login_redirect']) ? $auto_login_options['enable_login_redirect'] : false;
        $login_redirect_url = isset($auto_login_options['login_redirect_url']) ? $auto_login_options['login_redirect_url'] : wp_login_url();
        $login_button_text = isset($auto_login_options['login_button_text']) ? $auto_login_options['login_button_text'] : 'Login';
        $not_logged_in_message = isset($auto_login_options['not_logged_in_message']) ? $auto_login_options['not_logged_in_message'] : 'You must be logged in to access or create an app.';
        $login_notification_text = isset($auto_login_options['login_notification_text']) ? $auto_login_options['login_notification_text'] : 'You are being redirected to login page. Please wait...';
        
        // Log current settings for debugging
        $this->log_message("Displaying settings with autologin_text: $autologin_text");
        ?>
        <h2><?php _e('Auto Login Settings', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Configure settings for automatic login between WordPress and Siberian CMS using API integration.', 'swiftspeed-siberian'); ?>
        </p>
        
        <!-- Direct form submission to admin-post.php -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="swsib-auto-login-form" class="swsib-settings-form">
            <?php wp_nonce_field('swsib_autologin_nonce'); ?>
            <input type="hidden" name="action" value="swsib_save_autologin_settings">
            <input type="hidden" name="tab_id" value="auto_login">
            
            <!-- Siberian Configuration Section -->
            <div class="swsib-section-header">
                <h3><?php _e('Siberian Configuration', 'swiftspeed-siberian'); ?></h3>
            </div>
            
            <div class="swsib-field switch-field">
                <label for="swsib_options_auto_login_enable_siberian_config"><?php _e('Enable Siberian Configuration', 'swiftspeed-siberian'); ?></label>
                <div class="toggle-container">
                    <label class="switch">
                        <input type="checkbox" id="swsib_options_auto_login_enable_siberian_config" 
                            name="swsib_options[auto_login][enable_siberian_config]" 
                            value="1" 
                            <?php checked($enable_siberian_config); ?> />
                        <span class="slider round"></span>
                    </label>
                    <p class="swsib-field-note"><?php _e('Enable to configure Siberian CMS integration.', 'swiftspeed-siberian'); ?></p>
                </div>
            </div>
            
            <div id="siberian-config-settings" style="<?php echo $enable_siberian_config ? '' : 'display: none;'; ?>">
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_siberian_url"><?php _e('Siberian CMS URL', 'swiftspeed-siberian'); ?></label>
                    <input type="url" id="swsib_options_auto_login_siberian_url" 
                        name="swsib_options[auto_login][siberian_url]" 
                        value="<?php echo esc_url($siberian_url); ?>" 
                        placeholder="https://your-siberian-installation.com" 
                        required />
                    <p class="swsib-field-note"><?php _e('The URL to your Siberian CMS installation.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_autologin_text"><?php _e('Auto-Login Button Text', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_autologin_text" 
                        name="swsib_options[auto_login][autologin_text]" 
                        value="<?php echo esc_attr($autologin_text); ?>" 
                        placeholder="App Dashboard" />
                    <p class="swsib-field-note"><?php _e('Text to display on the auto-login button.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_button_color"><?php _e('Button Background Color', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_button_color" 
                        class="swsib-color-picker" 
                        name="swsib_options[auto_login][button_color]" 
                        value="<?php echo esc_attr($button_color); ?>" 
                        data-default-color="#3a4b79" />
                    <p class="swsib-field-note"><?php _e('Choose a custom background color for the auto-login button.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_button_text_color"><?php _e('Button Text Color', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_button_text_color" 
                        class="swsib-color-picker" 
                        name="swsib_options[auto_login][button_text_color]" 
                        value="<?php echo esc_attr($button_text_color); ?>" 
                        data-default-color="#ffffff" />
                    <p class="swsib-field-note"><?php _e('Choose a custom text color for the auto-login button.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_notification_text"><?php _e('Notification Text', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_notification_text" 
                        name="swsib_options[auto_login][notification_text]" 
                        value="<?php echo esc_attr($notification_text); ?>" 
                        placeholder="Connecting to Siberian. Please wait..." />
                    <p class="swsib-field-note"><?php _e('Text to display while connecting to Siberian.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_api_user"><?php _e('API Username', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_api_user" 
                        name="swsib_options[auto_login][api_user]" 
                        value="<?php echo esc_attr($api_user); ?>" 
                        placeholder="API Username" />
                    <p class="swsib-field-note"><?php _e('Your Siberian CMS API username.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_api_password"><?php _e('API Password', 'swiftspeed-siberian'); ?></label>
                    <input type="password" id="swsib_options_auto_login_api_password" 
                        name="swsib_options[auto_login][api_password]" 
                        value="<?php echo esc_attr($api_password); ?>" 
                        placeholder="API Password" />
                    <p class="swsib-field-note"><?php _e('Your Siberian CMS API password.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_default_role_id"><?php _e('Default User Role ID', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_default_role_id" 
                        name="swsib_options[auto_login][default_role_id]" 
                        value="<?php echo esc_attr($default_role_id); ?>" 
                        placeholder="2" />
                    <p class="swsib-field-note"><?php _e('Default role ID to assign to new users created in Siberian CMS. Standard user role is 2.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <button type="button" id="test_api_connection" class="button button-secondary"><?php _e('Test API Connection', 'swiftspeed-siberian'); ?></button>
                </div>
                
                <!-- Shortcode info moved inside the Siberian config section -->
                <div class="shortcode-info">
                    <h3><?php _e('Shortcode Usage', 'swiftspeed-siberian'); ?></h3>
                    <p>
                        <?php _e('Use the following shortcode to display the auto-login button on your site:', 'swiftspeed-siberian'); ?>
                        <code class="shortcode-example">[swsib_login<?php 
                            echo !empty($autologin_text) ? ' text="' . esc_attr($autologin_text) . '"' : '';
                            echo ($button_color !== '#3a4b79') ? ' color="' . esc_attr($button_color) . '"' : '';
                        ?>]</code>
                    </p>
                    
                    <h4><?php _e('Button Preview', 'swiftspeed-siberian'); ?></h4>
                    <div class="button-preview" style="--button-hover-color: <?php echo esc_attr($this->adjust_color_brightness($button_color, -20)); ?>">
                        <div class="preview-row">
                            <span class="preview-label"><?php _e('Your button:', 'swiftspeed-siberian'); ?></span>
                            <a href="#" class="swsib-button" 
                               style="background-color: <?php echo esc_attr($button_color); ?>; color: <?php echo esc_attr($button_text_color); ?> !important;">
                                <?php echo esc_html($autologin_text ?: __('App Dashboard', 'swiftspeed-siberian')); ?>
                            </a>
                        </div>
                    </div>
                    
                    <p class="swsib-field-note">
                        <?php _e('Note: Legacy shortcode <code>[swiftspeedsiberiancms]</code> is supported for backward compatibility.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
            </div>

            <!-- Auto Authentication section -->
            <div class="swsib-section-header">
                <h3><?php _e('Automatic Authentication', 'swiftspeed-siberian'); ?></h3>
            </div>
            
            <div class="swsib-field switch-field">
                <label for="swsib_options_auto_login_auto_authenticate"><?php _e('Auto-Authenticate', 'swiftspeed-siberian'); ?></label>
                <div class="toggle-container">
                    <label class="switch">
                        <input type="checkbox" id="swsib_options_auto_login_auto_authenticate" 
                            name="swsib_options[auto_login][auto_authenticate]" 
                            value="1" 
                            <?php checked($auto_authenticate); ?> />
                        <span class="slider round"></span>
                    </label>
                    <p class="swsib-field-note"><?php _e('When enabled, users will be automatically authenticated when visiting a page with the shortcode, without needing to click the login button.', 'swiftspeed-siberian'); ?></p>
                </div>
            </div>

            <!-- Processing screen settings -->
            <div id="auto-authenticate-settings" style="<?php echo $auto_authenticate ? '' : 'display: none;'; ?>">
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_processing_text"><?php _e('Processing Screen Text', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_processing_text" 
                        name="swsib_options[auto_login][processing_text]" 
                        value="<?php echo esc_attr($processing_text); ?>" 
                        placeholder="Processing..." />
                    <p class="swsib-field-note"><?php _e('Text to display during automatic authentication.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_processing_bg_color"><?php _e('Processing Screen Background Color', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_processing_bg_color" 
                        class="swsib-color-picker" 
                        name="swsib_options[auto_login][processing_bg_color]" 
                        value="<?php echo esc_attr($processing_bg_color); ?>" 
                        data-default-color="#f5f5f5" />
                    <p class="swsib-field-note"><?php _e('Background color for the processing screen.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_processing_text_color"><?php _e('Processing Screen Text Color', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_processing_text_color" 
                        class="swsib-color-picker" 
                        name="swsib_options[auto_login][processing_text_color]" 
                        value="<?php echo esc_attr($processing_text_color); ?>" 
                        data-default-color="#333333" />
                    <p class="swsib-field-note"><?php _e('Text color for the processing screen.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <div class="processing-preview">
                        <h4><?php _e('Processing Screen Preview', 'swiftspeed-siberian'); ?></h4>
                        <div id="processing-preview-container" style="background-color: <?php echo esc_attr($processing_bg_color); ?>; color: <?php echo esc_attr($processing_text_color); ?>;">
                            <div class="processing-content">
                                <span class="processing-text"><?php echo esc_html($processing_text); ?></span>
                                <span class="processing-spinner"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Login Redirect Section -->
            <div class="swsib-section-header">
                <h3><?php _e('Login Redirect Settings', 'swiftspeed-siberian'); ?></h3>
            </div>
            
            <div class="swsib-field switch-field">
                <label for="swsib_options_auto_login_enable_login_redirect"><?php _e('Enable Login Redirect', 'swiftspeed-siberian'); ?></label>
                <div class="toggle-container">
                    <label class="switch">
                        <input type="checkbox" id="swsib_options_auto_login_enable_login_redirect" 
                            name="swsib_options[auto_login][enable_login_redirect]" 
                            value="1" 
                            <?php checked($enable_login_redirect); ?> />
                        <span class="slider round"></span>
                    </label>
                    <p class="swsib-field-note"><?php _e('When enabled, non-logged-in users will see a message and a login button.', 'swiftspeed-siberian'); ?></p>
                </div>
            </div>
            
            <div id="login-redirect-settings" style="<?php echo $enable_login_redirect ? '' : 'display: none;'; ?>">
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_not_logged_in_message"><?php _e('Message for Non-Logged-In Users', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_not_logged_in_message" 
                        name="swsib_options[auto_login][not_logged_in_message]" 
                        value="<?php echo esc_attr($not_logged_in_message); ?>" 
                        placeholder="You must be logged in to access or create an app." />
                    <p class="swsib-field-note"><?php _e('Message displayed to users who are not logged in.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_login_button_text"><?php _e('Login Button Text', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_login_button_text" 
                        name="swsib_options[auto_login][login_button_text]" 
                        value="<?php echo esc_attr($login_button_text); ?>" 
                        placeholder="Login" />
                    <p class="swsib-field-note"><?php _e('Text displayed on the login button.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_login_notification_text"><?php _e('Login Redirect Notification Text', 'swiftspeed-siberian'); ?></label>
                    <input type="text" id="swsib_options_auto_login_login_notification_text" 
                        name="swsib_options[auto_login][login_notification_text]" 
                        value="<?php echo esc_attr($login_notification_text); ?>" 
                        placeholder="You are being redirected to login page. Please wait..." />
                    <p class="swsib-field-note"><?php _e('Text to display while redirecting to the login page.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <label for="swsib_options_auto_login_login_redirect_url"><?php _e('Login Redirect URL', 'swiftspeed-siberian'); ?></label>
                    <input type="url" id="swsib_options_auto_login_login_redirect_url" 
                        name="swsib_options[auto_login][login_redirect_url]" 
                        value="<?php echo esc_url($login_redirect_url); ?>" 
                        placeholder="<?php echo esc_url(wp_login_url()); ?>" />
                    <p class="swsib-field-note"><?php _e('URL where users will be redirected to login. Leave empty to use the default WordPress login page.', 'swiftspeed-siberian'); ?></p>
                </div>
                
                <div class="swsib-field">
                    <div class="login-redirect-preview">
                        <h4><?php _e('Login Redirect Preview', 'swiftspeed-siberian'); ?></h4>
                        <div id="login-redirect-preview-container">
                            <div class="login-redirect-content">
                                <p class="login-message"><?php echo esc_html($not_logged_in_message); ?></p>
                                <a href="#" class="swsib-button" 
                                   id="login-button-preview" 
                                   style="background-color: <?php echo esc_attr($button_color); ?> !important; 
                                          color: <?php echo esc_attr($button_text_color); ?> !important;
                                          display: inline-block;
                                          padding: 10px 20px;
                                          border-radius: 4px;
                                          text-decoration: none;
                                          font-weight: 600;
                                          transition: all 0.3s ease;
                                          box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
                                          border: none;
                                          cursor: pointer;">
                                    <?php echo esc_html($login_button_text); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="swsib-field switch-field">
                <label for="swsib_options_auto_login_keep_data"><?php _e('Data Retention', 'swiftspeed-siberian'); ?></label>
                <div class="toggle-container">
                    <label class="switch">
                        <input type="checkbox" id="swsib_options_auto_login_keep_data" 
                            name="swsib_options[auto_login][keep_data_on_uninstall]" 
                            value="1" 
                            <?php checked($keep_data); ?> />
                        <span class="slider round"></span>
                    </label>
                    <p class="swsib-field-note"><?php _e('Keep plugin data when uninstalling. If enabled, all plugin settings and user data will be preserved.', 'swiftspeed-siberian'); ?></p>
                </div>
            </div>
            
            <div class="swsib-actions" id="auto-login-save-button-container">
                <input type="submit" name="submit" id="auto-login-save-button" class="button button-primary" value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>">
            </div>
        </form>
        <?php
    }
    
    /**
     * Adjust color brightness
     * @param string $hex Hex color code
     * @param int $steps Steps to adjust brightness (-255 to 255)
     * @return string Adjusted hex color
     */
    private function adjust_color_brightness($hex, $steps) {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Convert to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Adjust brightness
        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));
        
        // Convert back to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
    
    /**
     * AJAX handler for testing API connection
     */
    public function ajax_test_api() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get parameters
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $user = isset($_POST['user']) ? sanitize_text_field($_POST['user']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        
        if (empty($url) || empty($user) || empty($password)) {
            wp_send_json_error('Missing required parameters');
        }
        
        // Ensure URL ends with a trailing slash
        $url = trailingslashit($url);
        
        // Get current WordPress admin email to use for testing
        $current_user = wp_get_current_user();
        $test_email = $current_user->user_email;
        
        // Test endpoint based on Siberian API documentation - use 'exist' endpoint for testing
        $test_endpoint = $url . 'admin/api_account/exist';
        
        // Log attempt
        $this->log_message('Testing API connection to: ' . $test_endpoint);
        $this->log_message('Testing with email: ' . $test_email);
        
        // Make API request with BASIC AUTH and form data as tested in Postman
        $response = wp_remote_post($test_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded', // Use form data
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $password) // Basic Auth
            ),
            'body' => array(
                'email' => $test_email
            )
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_message('API Test Error: ' . $response->get_error_message());
            wp_send_json_error($response->get_error_message());
            return;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log_message('API Test Response Code: ' . $response_code);
        $this->log_message('API Test Response: ' . $response_body);
        
        if ($response_code !== 200) {
            wp_send_json_error('Received response code ' . $response_code);
            return;
        }
        
        // Parse response body
        $response_data = json_decode($response_body, true);
        
        if (!$response_data) {
            wp_send_json_error('Invalid response from API');
            return;
        }
        
        // Check if the API returned a success response
        // Even if the user doesn't exist, we consider this a successful API test
        // as long as the API is responsive and well-formed
        if (isset($response_data['success'])) {
            wp_send_json_success(array(
                'message' => __('API connection successful!', 'swiftspeed-siberian'),
                'response' => $response_data
            ));
            return;
        }
        
        // If there's an error message but response code was 200, report the error
        if (isset($response_data['error']) && isset($response_data['message'])) {
            wp_send_json_error($response_data['message']);
            return;
        }
        
        // Fallback success response if we got this far
        wp_send_json_success(array(
            'message' => __('API connection appears to be working!', 'swiftspeed-siberian'),
            'response' => $response_data
        ));
    }
}