<?php
/**
 * DB Connect functionality for the plugin.
 * (Formerly Advanced Features)
 */
class SwiftSpeed_Siberian_Advanced_Features {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * DB Backup instance
     */
    private $db_backup;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Register AJAX handler for testing database connection
        add_action('wp_ajax_swsib_test_db_connection', array($this, 'ajax_test_db_connection'));
        
        // Register specific filter to save DB Connect settings without overwriting other tabs
        add_filter('pre_update_option_swsib_options', array($this, 'filter_update_options'), 10, 2);
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Initialize DB Backup if DB is configured
        if (swsib()->is_db_configured()) {
            $this->init_db_backup();
        }
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('db_connect', 'backend', $message);
        }
    }
    
    /**
     * Initialize DB Backup.
     */
    private function init_db_backup() {
        $this->log_message('Initializing DB Backup module');
        require_once dirname(__FILE__) . '/backup/class-swsib-db-backup.php';
        $this->db_backup = new SwiftSpeed_Siberian_DB_Backup();
    }
    
    /**
     * Enqueue scripts and styles for DB Connect.
     */
    public function enqueue_scripts($hook) {
        // Only load on plugin admin page
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        
        wp_enqueue_style(
            'swsib-db-connect-css',
            SWSIB_PLUGIN_URL . 'admin/includes/advanced-features/advanced-features.css',
            array(),
            SWSIB_VERSION
        );
        
        wp_enqueue_script(
            'swsib-db-connect-js',
            SWSIB_PLUGIN_URL . 'admin/includes/advanced-features/advanced-features.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );
        
        wp_localize_script(
            'swsib-db-connect-js',
            'swsib_af_vars',
            array(
                'ajax_url'              => admin_url('admin-ajax.php'),
                'nonce'                 => wp_create_nonce('swsib-nonce'),
                'testing_text'          => __('Testing...', 'swiftspeed-siberian'),
                'fill_required_fields'  => __('Please fill in all required fields', 'swiftspeed-siberian'),
                'error_occurred'        => __('Error occurred during test. Please try again.', 'swiftspeed-siberian')
            )
        );
        // Script enqueuing logging removed.
    }
    
    /**
     * Filter to properly handle saving options without overwriting other tabs.
     */
    public function filter_update_options($new_value, $old_value) {
        if (isset($_POST['option_page']) && $_POST['option_page'] === 'swsib_db_connect_options') {
            if (is_array($old_value) && is_array($new_value)) {
                // Preserve settings not related to db_connect.
                $db_connect = isset($new_value['db_connect']) ? $new_value['db_connect'] : array();
                foreach ($old_value as $key => $value) {
                    if ($key !== 'db_connect') {
                        $new_value[$key] = $value;
                    }
                }
                $new_value['db_connect'] = $db_connect;
                $this->process_special_db_connect_logic($new_value);
            }
        }
        return $new_value;
    }
    
    /**
     * Process special logic for DB Connect settings.
     */
    private function process_special_db_connect_logic(&$options) {
        if (!isset($options['db_connect'])) {
            return;
        }
        
        $enabled = isset($options['db_connect']['enabled']) && $options['db_connect']['enabled'];
        $options['db_connect']['enabled'] = $enabled;
        
        if ($enabled) {
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
                    // Log successful connection test.
                    $this->log_message('Database connection test successful');
                } else {
                    add_settings_error(
                        'swsib_options',
                        'db_connect_error',
                        __('Database connection failed: ', 'swiftspeed-siberian') . $result['message'],
                        'error'
                    );
                    $options['db_connect']['is_configured'] = false;
                    $this->log_message('Database connection test failed: ' . $result['message']);
                }
            }
        } else {
            $options['db_connect']['is_configured'] = false;
        }
    }
    
    /**
     * Display DB Connect settings.
     */
    public function display_settings() {
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        $enabled = isset($db_options['enabled']) ? $db_options['enabled'] : false;
        $host = isset($db_options['host']) ? $db_options['host'] : '';
        $database = isset($db_options['database']) ? $db_options['database'] : '';
        $username = isset($db_options['username']) ? $db_options['username'] : '';
        $password = isset($db_options['password']) ? $db_options['password'] : '';
        $port = isset($db_options['port']) ? $db_options['port'] : '3306';
        ?>
        <h2><?php _e('DB Connect Configuration', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Configure direct database access to your Siberian CMS.', 'swiftspeed-siberian'); ?>
        </p>
        
        <div class="swsib-notice info">
            <?php _e('Enabling DB Connect allows this plugin to directly interact with your Siberian CMS database for added functionality like WooCommerce integration, cleanup tools, and automation.', 'swiftspeed-siberian'); ?>
        </div>
         <div class="swsib-notice warning">
            <p><strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
            <?php _e('You should only configure this if you are technical and you know exactly what you are doing.', 'swiftspeed-siberian'); ?></p>
            
            <p><?php _e('To find your Siberian database details, check this file in your Siberian installation:', 'swiftspeed-siberian'); ?> 
            <code>/app/configs/app.ini</code></p>
            
            <p><?php _e('Look for these lines:', 'swiftspeed-siberian'); ?></p>
            <pre>
resources.db.params.host = ""
resources.db.params.dbname = ""
resources.db.params.username = ""
resources.db.params.password = ""</pre>
            
            <p><?php _e('Use those details to complete the form below and click "Test Connection" to verify.', 'swiftspeed-siberian'); ?></p>
        </div>
        
        <div class="swsib-field switch-field">
            <label for="swsib_options_db_connect_enabled"><?php _e('Enable DB Connect', 'swiftspeed-siberian'); ?></label>
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
                <input type="button" name="test_connection" id="test_db_connection" class="button button-secondary" value="<?php _e('Test Connection', 'swiftspeed-siberian'); ?>" />
                <div id="test_connection_result" style="display: none; margin-top: 10px;"></div>
            </div>
            
            <div class="swsib-notice info">
                <strong><?php _e('Swiftspeed Kept Your Security In Mind:', 'swiftspeed-siberian'); ?></strong>
                <p><?php _e('It is completely safe to configure this feature. Your database credentials are stored securely in your WordPress.', 'swiftspeed-siberian'); ?></p>
                <p><?php _e('Even in the event of a breach of this WordPress installation, the Siberian CMS passwords are hashed and appear as *******; they are not stored or visible in plain text.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <?php
            if (swsib()->is_db_configured() && isset($this->db_backup)) {
                $this->db_backup->display_settings();
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Test database connection.
     */
    public function test_db_connection($db_config) {
        // (Removed noncritical informational log here)
        
        try {
            $conn = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database'],
                $db_config['port']
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
            wp_send_json_error('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            $this->log_message('DB connection test failed: Permission denied');
            wp_send_json_error('Permission denied');
        }
        
        $db_config = array(
            'host'     => isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '',
            'database' => isset($_POST['database']) ? sanitize_text_field($_POST['database']) : '',
            'username' => isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '',
            'password' => isset($_POST['password']) ? $_POST['password'] : '',
            'port'     => isset($_POST['port']) ? sanitize_text_field($_POST['port']) : '3306'
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
    
    /**
     * Process settings for DB Connect.
     * (Kept for backward compatibility; handled by filter_update_options.)
     */
    public function process_settings($input) {
        return $input;
    }
}
?>
