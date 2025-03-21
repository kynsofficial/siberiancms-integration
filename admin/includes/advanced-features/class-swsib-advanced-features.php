<?php
/**
 * Advanced Features functionality for the plugin.
 */
class SwiftSpeed_Siberian_Advanced_Features {
    
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
        
        // Register AJAX handlers for testing database connection
        add_action('wp_ajax_swsib_test_db_connection', array($this, 'ajax_test_db_connection'));
    }
    
    /**
     * Display Advanced Features settings
     */
    public function display_settings() {
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        $enabled = isset($db_options['enabled']) ? $db_options['enabled'] : false;
        $host = isset($db_options['host']) ? $db_options['host'] : '';
        $database = isset($db_options['database']) ? $db_options['database'] : '';
        $username = isset($db_options['username']) ? $db_options['username'] : '';
        $password = isset($db_options['password']) ? $db_options['password'] : '';
        $port = isset($db_options['port']) ? $db_options['port'] : '3306';
        $prefix = isset($db_options['prefix']) ? $db_options['prefix'] : '';
        ?>
        <h2><?php _e('Advanced Features Configuration', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Configure advanced features that require direct database access to your Siberian CMS.', 'swiftspeed-siberian'); ?>
        </p>
        
        <div class="swsib-notice info">
            <?php _e('Enabling advanced features allows this plugin to directly interact with your Siberian CMS database for added functionality like WooCommerce integration, cleanup tools, and automation.', 'swiftspeed-siberian'); ?>
        </div>
        
        <div class="swsib-field switch-field">
            <label for="swsib_options_db_connect_enabled"><?php _e('Enable Advanced Features', 'swiftspeed-siberian'); ?></label>
            <div class="toggle-container">
                <label class="switch">
                    <input type="checkbox" id="swsib_options_db_connect_enabled" 
                        name="swsib_options[db_connect][enabled]" 
                        value="1" 
                        <?php checked($enabled); ?> 
                        class="toggle-advanced-features" />
                    <span class="slider round"></span>
                </label>
                <p class="swsib-field-note"><?php _e('Enable direct database connection for advanced features.', 'swiftspeed-siberian'); ?></p>
            </div>
        </div>
        
        <div id="advanced-features-config" style="<?php echo $enabled ? '' : 'display: none;'; ?>">
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
            
            <div class="swsib-field">
                <label for="swsib_options_db_connect_prefix"><?php _e('Table Prefix', 'swiftspeed-siberian'); ?></label>
                <input type="text" id="swsib_options_db_connect_prefix" 
                    name="swsib_options[db_connect][prefix]" 
                    value="<?php echo esc_attr($prefix); ?>" 
                    placeholder="sae_" />
                <p class="swsib-field-note"><?php _e('Leave empty if not using a table prefix', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <div class="swsib-field">
                <input type="submit" name="test_connection" id="test_connection" class="button button-secondary" value="<?php _e('Test Connection', 'swiftspeed-siberian'); ?>" />
            </div>
            
            <div class="swsib-notice warning">
                <strong><?php _e('Security Note:', 'swiftspeed-siberian'); ?></strong>
                <?php _e('Database credentials are stored securely in your WordPress options table. However, direct database access should only be used when necessary and with proper security measures in place.', 'swiftspeed-siberian'); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Test database connection
     */
    public function test_db_connection($db_config) {
        // Test database connection
        try {
            $conn = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database'],
                $db_config['port']
            );
            
            if ($conn->connect_error) {
                return array(
                    'success' => false,
                    'message' => $conn->connect_error
                );
            }
            
            $conn->close();
            return array(
                'success' => true,
                'message' => __('Connection successful', 'swiftspeed-siberian')
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * AJAX handler for testing database connection
     */
    public function ajax_test_db_connection() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // Get DB config from POST data
        $db_config = array(
            'host' => isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '',
            'database' => isset($_POST['database']) ? sanitize_text_field($_POST['database']) : '',
            'username' => isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '',
            'password' => isset($_POST['password']) ? $_POST['password'] : '',
            'port' => isset($_POST['port']) ? sanitize_text_field($_POST['port']) : '3306'
        );
        
        // Test connection
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
    
    /**
     * Process settings for Advanced Features
     */
    public function process_settings($input) {
        $options = get_option('swsib_options', array());
        
        // Initialize db_connect array if not exists
        if (!isset($options['db_connect'])) {
            $options['db_connect'] = array();
        }
        
        // Update enabled status first
        $options['db_connect']['enabled'] = isset($input['db_connect']['enabled']);
        
        // Only process other fields if DB connect is enabled
        if ($options['db_connect']['enabled']) {
            // Update only the fields that are set
            if (isset($input['db_connect']['host'])) {
                $options['db_connect']['host'] = sanitize_text_field($input['db_connect']['host']);
            }
            
            if (isset($input['db_connect']['database'])) {
                $options['db_connect']['database'] = sanitize_text_field($input['db_connect']['database']);
            }
            
            if (isset($input['db_connect']['username'])) {
                $options['db_connect']['username'] = sanitize_text_field($input['db_connect']['username']);
            }
            
            if (isset($input['db_connect']['password'])) {
                // Don't sanitize password but still save it
                $options['db_connect']['password'] = $input['db_connect']['password'];
            }
            
            if (isset($input['db_connect']['port'])) {
                $options['db_connect']['port'] = sanitize_text_field($input['db_connect']['port']);
            }
            
            if (isset($input['db_connect']['prefix'])) {
                $options['db_connect']['prefix'] = sanitize_text_field($input['db_connect']['prefix']);
            }
            
            // Check if DB connection is configured
            $is_configured = !empty($options['db_connect']['host']) && 
                            !empty($options['db_connect']['database']) && 
                            !empty($options['db_connect']['username']) && 
                            !empty($options['db_connect']['password']);
            
            $options['db_connect']['is_configured'] = $is_configured;
            
            // Test connection if configured and requested
            if ($is_configured && isset($_POST['test_connection'])) {
                $result = $this->test_db_connection($options['db_connect']);
                if ($result['success']) {
                    add_settings_error(
                        'swsib_options',
                        'db_connect_success',
                        __('Database connection successful!', 'swiftspeed-siberian'),
                        'updated'
                    );
                } else {
                    add_settings_error(
                        'swsib_options',
                        'db_connect_error',
                        __('Database connection failed: ', 'swiftspeed-siberian') . $result['message'],
                        'error'
                    );
                    $options['db_connect']['is_configured'] = false;
                }
            }
        } else {
            // If DB connect is disabled, mark as not configured
            $options['db_connect']['is_configured'] = false;
        }
        
        return $options;
    }
}