<?php
/**
 * DB Connect functionality for handling Siberian database connections.
 */
class SwiftSpeed_Siberian_DB_Connect {
    
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
        
        // Register AJAX handlers
        add_action('wp_ajax_swsib_test_db_connection', array($this, 'ajax_test_db_connection'));
        
        // Register direct form submission handlers
        add_action('admin_post_swsib_save_dbconnect_settings', array($this, 'process_form_submission'));
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('dbconnect', 'backend', $message);
        }
    }
    
    /**
     * Process form submission for DB Connect settings.
     */
    public function process_form_submission() {
        // Log form submission
        $this->log_message("DB Connect form submission received");
        
        // Check nonce using our custom field name
        if (
            ! isset($_POST['_wpnonce_swsib_dbconnect']) ||
            ! wp_verify_nonce($_POST['_wpnonce_swsib_dbconnect'], 'swsib_dbconnect_nonce')
        ) {
            $this->log_message("Nonce verification failed");
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=db_connect&error=nonce_failed'));
            exit;
        }
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize db_connect array if it doesn't exist
        if (!isset($options['db_connect'])) {
            $options['db_connect'] = array();
        }
        
        // Process each field
        if (isset($_POST['swsib_options']['db_connect'])) {
            $db_connect = $_POST['swsib_options']['db_connect'];
            
            // Checkbox field
            $options['db_connect']['enabled'] = isset($db_connect['enabled']);
            
            // Text fields
            $text_fields = array('host', 'database', 'username', 'password', 'port', 'prefix');
            foreach ($text_fields as $field) {
                if (isset($db_connect[$field])) {
                    $options['db_connect'][$field] = sanitize_text_field($db_connect[$field]);
                }
            }
            
            // Mark configured
            if ($options['db_connect']['enabled']) {
                $options['db_connect']['is_configured'] =
                    !empty($options['db_connect']['host']) &&
                    !empty($options['db_connect']['database']) &&
                    !empty($options['db_connect']['username']) &&
                    !empty($options['db_connect']['password']);
            } else {
                $options['db_connect']['is_configured'] = false;
            }
            
            $this->log_message("Form data processed: " . print_r($options['db_connect'], true));
        } else {
            $this->log_message("WARNING: No form data found in POST");
        }
        
        update_option('swsib_options', $options);
        
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=db_connect&dbconnect_tab=database&db_connect_updated=true'));
        exit;
    }
    
    /**
     * Display Database settings.
     */
    public function display_database_settings() {
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        $enabled   = ! empty($db_options['enabled']);
        $host      = isset($db_options['host'])     ? $db_options['host']     : '';
        $database  = isset($db_options['database']) ? $db_options['database'] : '';
        $username  = isset($db_options['username']) ? $db_options['username'] : '';
        $password  = isset($db_options['password']) ? $db_options['password'] : '';
        $port      = isset($db_options['port'])     ? $db_options['port']     : '3306';
        $prefix    = isset($db_options['prefix'])   ? $db_options['prefix']   : '';
        
        ?>
        <div class="swsib-section">
            <div class="swsib-notice info">
                <?php _e('Enabling DB Connect allows this plugin to directly interact with your Siberian CMS database for added functionality like WooCommerce integration, cleanup tools, and automation.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="swsib-notice warning">
              <p><?php _e('To find your Siberian database details, check this file in your Siberian installation:', 'swiftspeed-siberian'); ?> 
                <code>/app/configs/app.ini</code></p>
                
                <p><?php _e('Look for these lines:', 'swiftspeed-siberian'); ?></p>
                <pre>
resources.db.params.host = ""
resources.db.params.dbname = ""
resources.db.params.username = ""
resources.db.params.password = ""
                </pre>
                
                <p><?php _e('Use those details to complete the form below and click "Test Connection" to verify.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swsib-settings-form">
                <?php
                // Custom nonce field name/ID to avoid duplicate #_wpnonce
                wp_nonce_field('swsib_dbconnect_nonce','_wpnonce_swsib_dbconnect');
                ?>
                <input type="hidden" name="action" value="swsib_save_dbconnect_settings">
                <input type="hidden" name="tab_id" value="db_connect">
                
                <div class="swsib-field switch-field">
                    <label for="swsib_options_db_connect_enabled"><?php _e('Enable DB Connect', 'swiftspeed-siberian'); ?></label>
                    <div class="toggle-container">
                        <label class="switch">
                            <input type="checkbox"
                                   id="swsib_options_db_connect_enabled"
                                   name="swsib_options[db_connect][enabled]"
                                   value="1"
                                   <?php checked($enabled); ?>
                                   class="toggle-advanced-features" />
                            <span class="slider round"></span>
                        </label>
                        <p class="swsib-field-note"><?php _e('Enable direct database connection for advanced features.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div id="advanced-features-config" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                    <div class="swsib-field">
                        <label for="swsib_options_db_connect_host"><?php _e('Database Host', 'swiftspeed-siberian'); ?></label>
                        <input type="text" id="swsib_options_db_connect_host"
                               name="swsib_options[db_connect][host]"
                               value="<?php echo esc_attr($host); ?>"
                               placeholder="localhost"
                               <?php echo $enabled ? 'required' : ''; ?> />
                    </div>
                    
                    <div class="swsib-field">
                        <label for="swsib_options_db_connect_database"><?php _e('Database Name', 'swiftspeed-siberian'); ?></label>
                        <input type="text" id="swsib_options_db_connect_database"
                               name="swsib_options[db_connect][database]"
                               value="<?php echo esc_attr($database); ?>"
                               placeholder="siberian_db"
                               <?php echo $enabled ? 'required' : ''; ?> />
                    </div>
                    
                    <div class="swsib-field">
                        <label for="swsib_options_db_connect_username"><?php _e('Database Username', 'swiftspeed-siberian'); ?></label>
                        <input type="text" id="swsib_options_db_connect_username"
                               name="swsib_options[db_connect][username]"
                               value="<?php echo esc_attr($username); ?>"
                               placeholder="username"
                               <?php echo $enabled ? 'required' : ''; ?> />
                    </div>
                    
                    <div class="swsib-field">
                        <label for="swsib_options_db_connect_password"><?php _e('Database Password', 'swiftspeed-siberian'); ?></label>
                        <input type="password" id="swsib_options_db_connect_password"
                               name="swsib_options[db_connect][password]"
                               value="<?php echo esc_attr($password); ?>"
                               placeholder="password"
                               <?php echo $enabled ? 'required' : ''; ?> />
                    </div>
                    
                    <div class="swsib-field">
                        <label for="swsib_options_db_connect_port"><?php _e('Database Port', 'swiftspeed-siberian'); ?></label>
                        <input type="text" id="swsib_options_db_connect_port"
                               name="swsib_options[db_connect][port]"
                               value="<?php echo esc_attr($port); ?>"
                               placeholder="3306" />
                        <p class="swsib-field-note"><?php _e('Default: 3306', 'swiftspeed-siberian'); ?></p>
                    </div>
                    
                    <!-- Prefix field remains in the form but hidden, to maintain data compatibility -->
                    <input type="hidden" id="swsib_options_db_connect_prefix"
                           name="swsib_options[db_connect][prefix]"
                           value="<?php echo esc_attr($prefix); ?>" />
                    
                    <div class="swsib-field">
                        <button type="button" id="test_db_connection" class="button button-secondary">
                            <?php _e('Test Connection', 'swiftspeed-siberian'); ?>
                        </button>
                        <div id="test_connection_result" style="display: none; margin-top: 10px;"></div>
                    </div>
                    
                    <div class="swsib-notice info">
                        <strong><?php _e('Swiftspeed Kept Your Security In Mind:', 'swiftspeed-siberian'); ?></strong>
                        <p><?php _e('It is completely safe to configure this feature. Your database credentials are stored securely in your WordPress.', 'swiftspeed-siberian'); ?></p>
                        <p><?php _e('Even in the event of a breach of this WordPress installation, the Siberian CMS passwords are hashed and not visible in plain text.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div class="swsib-actions">
                    <input type="submit" name="submit" class="button button-primary" value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>">
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Test database connection.
     */
    public function test_db_connection($db_config) {
        try {
            $conn = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database'],
                isset($db_config['port']) ? intval($db_config['port']) : 3306
            );
            
            if ($conn->connect_error) {
                $this->log_message('Database connection test failed: ' . $conn->connect_error);
                return array(
                    'success' => false,
                    'message' => $conn->connect_error
                );
            }
            
            $conn->close();
            $this->log_message('Database connection test successful');
            return array(
                'success' => true,
                'message' => __('Connection successful', 'swiftspeed-siberian')
            );
        } catch (Exception $e) {
            $this->log_message('Database connection test exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * AJAX handler for testing database connection.
     */
    public function ajax_test_db_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            $this->log_message('DB connection test failed: Security check failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            $this->log_message('DB connection test failed: Permission denied');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $db_config = array(
            'host'     => isset($_POST['host'])     ? sanitize_text_field($_POST['host'])     : '',
            'database' => isset($_POST['database']) ? sanitize_text_field($_POST['database']) : '',
            'username' => isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '',
            'password' => isset($_POST['password']) ? $_POST['password'] : '',
            'port'     => isset($_POST['port'])     ? sanitize_text_field($_POST['port'])     : '3306'
        );
        
        $result = $this->test_db_connection($db_config);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('Database connection successful!', 'swiftspeed-siberian')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Database connection failed: ', 'swiftspeed-siberian') . $result['message']
            ));
        }
    }
}