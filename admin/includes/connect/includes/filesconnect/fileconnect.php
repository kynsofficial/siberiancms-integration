<?php
/**
 * File Connect functionality for handling Siberian installation connections.
 * Enhanced implementation with FTP, SFTP and local connections.
 */
class SwiftSpeed_Siberian_File_Connect {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Connection handlers
     */
    private $ftp_handler;
    private $sftp_handler;
    private $local_handler;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Include the required files
        require_once(dirname(__FILE__) . '/class-ftp-connection.php');
        require_once(dirname(__FILE__) . '/class-sftp-connection.php');
        require_once(dirname(__FILE__) . '/class-local-connection.php');
        
        // Initialize connection handlers
        $this->ftp_handler = new SwiftSpeed_Siberian_FTP_Connection($this->options);
        $this->sftp_handler = new SwiftSpeed_Siberian_SFTP_Connection($this->options);
        $this->local_handler = new SwiftSpeed_Siberian_Local_Connection($this->options);
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Register direct form submission handlers
        add_action('admin_post_swsib_save_installation_settings', array($this, 'process_installation_form_submission'));
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Connection test and verification handlers
        add_action('wp_ajax_swsib_test_installation_connection', array($this, 'ajax_test_installation_connection'));
        add_action('wp_ajax_swsib_browse_directory', array($this, 'ajax_browse_directory'));
        add_action('wp_ajax_swsib_verify_siberian_installation', array($this, 'ajax_verify_siberian_installation'));
        
        // File explorer AJAX handlers
        add_action('wp_ajax_swsib_get_installation_config', array($this, 'ajax_get_installation_config'));
        add_action('wp_ajax_swsib_get_file_contents', array($this, 'ajax_get_file_contents'));
        add_action('wp_ajax_swsib_delete_file', array($this, 'ajax_delete_file'));
        add_action('wp_ajax_swsib_download_file', array($this, 'ajax_download_file'));
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('fileconnect', 'backend', $message);
        }
    }
    
    /**
     * Process form submission for Installation settings.
     */
    public function process_installation_form_submission() {
        // Log form submission
        $this->log_message("Installation form submission received");
        
        // Check nonce
        if (
            ! isset($_POST['_wpnonce_swsib_installation']) ||
            ! wp_verify_nonce($_POST['_wpnonce_swsib_installation'], 'swsib_installation_nonce')
        ) {
            $this->log_message("Nonce verification failed");
            wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=db_connect&error=nonce_failed'));
            exit;
        }
        
        // Log entire POST data for debugging
        $this->log_message("Installation form data: " . print_r($_POST, true));
        
        // Get current options
        $options = get_option('swsib_options', array());
        
        // Initialize installation array if it doesn't exist
        if (!isset($options['installation'])) {
            $options['installation'] = array();
        }
        
        // Process each field
        if (isset($_POST['swsib_options']['installation'])) {
            $installation = $_POST['swsib_options']['installation'];
            
            // Checkbox field
            $options['installation']['enabled'] = isset($installation['enabled']);
            
            // Connection method
            if (isset($installation['connection_method'])) {
                $options['installation']['connection_method'] = sanitize_text_field($installation['connection_method']);
            }
            
            // Get selected connection method
            $connection_method = isset($installation['connection_method']) ? $installation['connection_method'] : 'ftp';
            
            // Process method-specific fields - store them in their own arrays
            $method_fields = array('host', 'username', 'password', 'port', 'path');
            
            // Initialize method specific options if needed
            if (!isset($options['installation'][$connection_method])) {
                $options['installation'][$connection_method] = array();
            }
            
            // Log field names being processed
            $field_debug = array();
            foreach ($method_fields as $field) {
                $field_name = $field . '_' . $connection_method;
                $field_debug[$field_name] = isset($installation[$field_name]) ? 'set' : 'not set';
            }
            $this->log_message("Field processing status: " . print_r($field_debug, true));
            
            // Process each field with method-specific suffix
            foreach ($method_fields as $field) {
                $field_name = $field . '_' . $connection_method;
                if (isset($installation[$field_name])) {
                    // Log the path value being saved for debugging
                    if ($field === 'path') {
                        $this->log_message("Saving path for $connection_method: " . $installation[$field_name]);
                    }
                    
                    $options['installation'][$connection_method][$field] = sanitize_text_field($installation[$field_name]);
                }
            }
            
            // Fill in default port if not specified
            if ($connection_method === 'ftp' && empty($options['installation'][$connection_method]['port'])) {
                $options['installation'][$connection_method]['port'] = '21';
            }
            
            // Default port for SFTP
            if ($connection_method === 'sftp' && empty($options['installation'][$connection_method]['port'])) {
                $options['installation'][$connection_method]['port'] = '22';
            }
            
            // Mark configured - check if active method has required fields
            if ($options['installation']['enabled']) {
                $is_local = ($connection_method === 'local');
                
                // Special handling for local method
                if ($is_local) {
                    $options['installation']['is_configured'] = !empty($options['installation'][$connection_method]['path']);
                } 
                // FTP and SFTP connections require the same fields
                else {
                    $options['installation']['is_configured'] = 
                        !empty($options['installation'][$connection_method]['host']) &&
                        !empty($options['installation'][$connection_method]['username']) &&
                        !empty($options['installation'][$connection_method]['password']) &&
                        !empty($options['installation'][$connection_method]['path']);
                }
            } else {
                $options['installation']['is_configured'] = false;
            }
            
            $this->log_message("Installation form data processed: " . print_r($options['installation'], true));
        } else {
            $this->log_message("WARNING: No installation form data found in POST");
        }
        
        update_option('swsib_options', $options);
        
        // After saving, we need to reload our options to ensure consistency
        $this->options = get_option('swsib_options', array());
        
        wp_redirect(admin_url('admin.php?page=swsib-integration&tab_id=db_connect&dbconnect_tab=installation&installation_updated=true'));
        exit;
    }
    
    /**
     * Display Installation Path settings.
     */
    public function display_installation_settings() {
        // Reload options right before display to ensure we have the latest values
        $this->options = get_option('swsib_options', array());
        
        $install_options = isset($this->options['installation']) ? $this->options['installation'] : array();
        $enabled = !empty($install_options['enabled']);
        $connection_method = isset($install_options['connection_method']) ? $install_options['connection_method'] : 'ftp';
        
        ?>
        <div class="swsib-section">
            <div class="swsib-notice info">
                <?php _e('This feature allows the plugin to connect to your server to locate and interact with your Siberian CMS installation files, enabling more advanced features.', 'swiftspeed-siberian'); ?>
            </div>
            
            <div class="swsib-notice warning">
                <p><strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
                <?php _e('You should only configure this if you have access to your server credentials. After connecting, you will be able to browse and locate your Siberian CMS installation.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="swsib-settings-form" id="installation-settings-form">
                <?php
                wp_nonce_field('swsib_installation_nonce', '_wpnonce_swsib_installation');
                ?>
                <input type="hidden" name="action" value="swsib_save_installation_settings">
                <input type="hidden" name="tab_id" value="db_connect">
                <input type="hidden" name="dbconnect_tab" value="installation">
                
                <div class="swsib-field switch-field">
                    <label for="swsib_options_installation_enabled"><?php _e('Enable Installation Connection', 'swiftspeed-siberian'); ?></label>
                    <div class="toggle-container">
                        <label class="switch">
                            <input type="checkbox"
                                   id="swsib_options_installation_enabled"
                                   name="swsib_options[installation][enabled]"
                                   value="1"
                                   <?php checked($enabled); ?>
                                   class="toggle-installation-features" />
                            <span class="slider round"></span>
                        </label>
                        <p class="swsib-field-note"><?php _e('Enable connection to your Siberian CMS installation directory.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div id="installation-config" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
                    <div class="swsib-field">
                        <label for="swsib_options_installation_connection_method"><?php _e('Connection Method', 'swiftspeed-siberian'); ?></label>
                        <select id="swsib_options_installation_connection_method"
                                name="swsib_options[installation][connection_method]"
                                class="connection-method-select swsib-select">
                            <option value="ftp" <?php selected($connection_method, 'ftp'); ?>><?php _e('FTP', 'swiftspeed-siberian'); ?></option>
                            <option value="sftp" <?php selected($connection_method, 'sftp'); ?>><?php _e('SFTP (Secure FTP)', 'swiftspeed-siberian'); ?></option>
                            <option value="local" <?php selected($connection_method, 'local'); ?>><?php _e('Local Filesystem (Same WordPress User)', 'swiftspeed-siberian'); ?></option>
                        </select>
                        
                        <!-- Connection method notes -->
                        <p class="swsib-field-note method-note-ftp" <?php echo $connection_method != 'ftp' ? 'style="display:none;"' : ''; ?>>
                            <?php _e('Use this if your Siberian CMS is on a remote server or uses different credentials than WordPress.', 'swiftspeed-siberian'); ?>
                        </p>
                        <p class="swsib-notice warning" <?php echo $connection_method != 'sftp' ? 'style="display:none;"' : ''; ?>>
                            <?php _e('Use this for secure SSH/SFTP connections to your server (recommended for security).', 'swiftspeed-siberian'); ?>
                        </p>
                        <p class="swsib-field-note method-note-local" <?php echo $connection_method != 'local' ? 'style="display:none;"' : ''; ?>>
                            <?php _e('Use this if your WordPress and Siberian CMS are on the same server and owned by the same user.', 'swiftspeed-siberian'); ?>
                        </p>
                    </div>
                    
                    <?php
                    // Get method-specific values for FTP
                    $ftp_options = isset($install_options['ftp']) ? $install_options['ftp'] : array();
                    $ftp_host = isset($ftp_options['host']) ? $ftp_options['host'] : '';
                    $ftp_username = isset($ftp_options['username']) ? $ftp_options['username'] : '';
                    $ftp_password = isset($ftp_options['password']) ? $ftp_options['password'] : '';
                    $ftp_port = isset($ftp_options['port']) ? $ftp_options['port'] : '21';
                    $ftp_path = isset($ftp_options['path']) ? $ftp_options['path'] : '';
                    
                    // Debug: Print out the path to verify
                    $this->log_message('FTP Path value from options: ' . $ftp_path);
                    
                    // Get method-specific values for SFTP
                    $sftp_options = isset($install_options['sftp']) ? $install_options['sftp'] : array();
                    $sftp_host = isset($sftp_options['host']) ? $sftp_options['host'] : '';
                    $sftp_username = isset($sftp_options['username']) ? $sftp_options['username'] : '';
                    $sftp_password = isset($sftp_options['password']) ? $sftp_options['password'] : '';
                    $sftp_port = isset($sftp_options['port']) ? $sftp_options['port'] : '22';
                    $sftp_path = isset($sftp_options['path']) ? $sftp_options['path'] : '';
                    
                    // Get method-specific values for Local
                    $local_options = isset($install_options['local']) ? $install_options['local'] : array();
                    $local_path = isset($local_options['path']) ? $local_options['path'] : '';
                    ?>
                    
                    <!-- FTP Connection Fields -->
                    <div class="connection-fields-container connection-fields-ftp" <?php echo $connection_method != 'ftp' ? 'style="display:none;"' : ''; ?>>
                        <div class="swsib-field">
                            <label for="swsib_options_installation_host_ftp">
                                <?php _e('FTP Server Hostname/IP', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_host_ftp"
                                   name="swsib_options[installation][host_ftp]"
                                   value="<?php echo esc_attr($ftp_host); ?>"
                                   placeholder="example.com or IP address"
                                   <?php echo ($enabled && $connection_method == 'ftp') ? 'required' : ''; ?> />
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_username_ftp">
                                <?php _e('FTP Username', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_username_ftp"
                                   name="swsib_options[installation][username_ftp]"
                                   value="<?php echo esc_attr($ftp_username); ?>"
                                   placeholder="username"
                                   <?php echo ($enabled && $connection_method == 'ftp') ? 'required' : ''; ?> />
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_password_ftp">
                                <?php _e('FTP Password', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="password"
                                   id="swsib_options_installation_password_ftp"
                                   name="swsib_options[installation][password_ftp]"
                                   value="<?php echo esc_attr($ftp_password); ?>"
                                   placeholder="password"
                                   <?php echo ($enabled && $connection_method == 'ftp') ? 'required' : ''; ?> />
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_port_ftp">
                                <?php _e('FTP Port', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_port_ftp"
                                   name="swsib_options[installation][port_ftp]"
                                   value="<?php echo esc_attr($ftp_port); ?>"
                                   placeholder="21" />
                            <p class="swsib-field-note">
                                <?php _e('Default: 21', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_path_ftp">
                                <?php _e('FTP Installation Path', 'swiftspeed-siberian'); ?>
                            </label>
                            <div class="path-input-group">
                                <input type="text"
                                       id="swsib_options_installation_path_ftp"
                                       name="swsib_options[installation][path_ftp]"
                                       value="<?php echo esc_attr($ftp_path); ?>"
                                       placeholder="/"
                                       <?php echo ($enabled && $connection_method == 'ftp') ? 'required' : ''; ?> />
                                <button type="button" class="button browse-directory-button" data-method="ftp">
                                    <?php _e('Browse', 'swiftspeed-siberian'); ?>
                                </button>
                            </div>
                            <p class="swsib-field-note">
                                <?php _e('Path to your Siberian CMS installation. You can either enter it directly or click "Browse" to navigate to it.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- SFTP Connection Fields -->

 
                    <!-- SFTP Connection Fields -->
                    <div class="connection-fields-container connection-fields-sftp" <?php echo $connection_method != 'sftp' ? 'style="display:none;"' : ''; ?>>
                        <?php
                        // Get SFTP extension status
                        $sftp_status = $this->sftp_handler->get_sftp_status();
                        
                        // Show appropriate message based on availability
                        if ($sftp_status['extension_loaded']) {
                            echo '<div class="swsib-notice success"><p>' . 
                                 __('<strong>PHP SSH2 Extension:</strong> Available and active. SFTP connections will use the native extension for optimal performance.', 'swiftspeed-siberian') . 
                                 '</p></div>';
                        } else if ($sftp_status['phpseclib_available']) {
                            echo '<div class="swsib-notice info"><p>' . 
                                 __('<strong>PHP SSH2 Extension:</strong> Not available. SFTP connections will use the phpseclib fallback method.', 'swiftspeed-siberian') . 
                                 '<br><small>' . __('For best performance, ask your server administrator to install the PHP SSH2 extension: <code>sudo apt-get install -y php-ssh2</code>', 'swiftspeed-siberian') . '</small>' .
                                 '</p></div>';
                        } else {
                            echo '<div class="swsib-notice warning"><p>' . 
                                 __('<strong>SFTP Support:</strong> Limited. Please install the PHP SSH2 extension or run <code>composer require phpseclib/phpseclib:^3.0</code> in the plugin directory.', 'swiftspeed-siberian') . 
                                 '</p></div>';
                        }
                        ?>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_host_sftp">
                                <?php _e('SFTP Server Hostname/IP', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_host_sftp"
                                   name="swsib_options[installation][host_sftp]"
                                   value="<?php echo esc_attr($sftp_host); ?>"
                                   placeholder="example.com or IP address"
                                   <?php echo ($enabled && $connection_method == 'sftp') ? 'required' : ''; ?> />
                        </div>


                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_username_sftp">
                                <?php _e('SFTP Username', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_username_sftp"
                                   name="swsib_options[installation][username_sftp]"
                                   value="<?php echo esc_attr($sftp_username); ?>"
                                   placeholder="username"
                                   <?php echo ($enabled && $connection_method == 'sftp') ? 'required' : ''; ?> />
                        </div>
                                 
                         
                        <div class="swsib-field">
                            <label for="swsib_options_installation_password_sftp">
                                <?php _e('SFTP Password', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="password"
                                   id="swsib_options_installation_password_sftp"
                                   name="swsib_options[installation][password_sftp]"
                                   value="<?php echo esc_attr($sftp_password); ?>"
                                   placeholder="password"
                                   <?php echo ($enabled && $connection_method == 'sftp') ? 'required' : ''; ?> />
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_port_sftp">
                                <?php _e('SFTP Port', 'swiftspeed-siberian'); ?>
                            </label>
                            <input type="text"
                                   id="swsib_options_installation_port_sftp"
                                   name="swsib_options[installation][port_sftp]"
                                   value="<?php echo esc_attr($sftp_port); ?>"
                                   placeholder="22" />
                            <p class="swsib-field-note">
                                <?php _e('Default: 22', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        
                        <div class="swsib-field">
                            <label for="swsib_options_installation_path_sftp">
                                <?php _e('SFTP Installation Path', 'swiftspeed-siberian'); ?>
                            </label>
                            <div class="path-input-group">
                                <input type="text"
                                       id="swsib_options_installation_path_sftp"
                                       name="swsib_options[installation][path_sftp]"
                                       value="<?php echo esc_attr($sftp_path); ?>"
                                       placeholder="/"
                                       <?php echo ($enabled && $connection_method == 'sftp') ? 'required' : ''; ?> />
                                <button type="button" class="button browse-directory-button" data-method="sftp">
                                    <?php _e('Browse', 'swiftspeed-siberian'); ?>
                                </button>
                            </div>
                            <p class="swsib-field-note">
                                <?php _e('Path to your Siberian CMS installation. You can either enter it directly or click "Browse" to navigate to it.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Local Connection Fields -->
                    <div class="connection-fields-container connection-fields-local" <?php echo $connection_method != 'local' ? 'style="display:none;"' : ''; ?>>
                        <div class="swsib-field">
                            <label for="swsib_options_installation_path_local">
                                <?php _e('Local Installation Path', 'swiftspeed-siberian'); ?>
                            </label>
                            <div class="path-input-group">
                                <input type="text"
                                       id="swsib_options_installation_path_local"
                                       name="swsib_options[installation][path_local]"
                                       value="<?php echo esc_attr($local_path); ?>"
                                       placeholder="<?php echo ABSPATH . 'siberian'; ?>"
                                       <?php echo ($enabled && $connection_method == 'local') ? 'required' : ''; ?> />
                                <button type="button" class="button browse-directory-button" data-method="local">
                                    <?php _e('Browse', 'swiftspeed-siberian'); ?>
                                </button>
                            </div>
                            <p class="swsib-field-note">
                                <?php _e('Full server path to your Siberian CMS installation (e.g., /var/www/html/siberian)', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="swsib-field">
                        <button type="button" id="test_installation_connection" class="button button-secondary">
                            <?php _e('Test Connection', 'swiftspeed-siberian'); ?>
                        </button>
                        <button type="button" id="verify_siberian_installation" class="button button-secondary" style="<?php echo (!empty($ftp_path) || !empty($sftp_path) || !empty($local_path)) ? '' : 'display:none;' ?> margin-left: 10px;">
                            <?php _e('Verify Siberian Installation', 'swiftspeed-siberian'); ?>
                        </button>
                        <div id="test_installation_result" style="display: none; margin-top: 10px;"></div>
                    </div>
                    
                    <!-- Directory browser modal -->
                    <div id="directory_browser_dialog" class="directory-browser-modal" style="display:none;">
                        <div class="directory-browser-content">
                            <div class="directory-browser-header">
                                <h3><?php _e('Browse Server Directory', 'swiftspeed-siberian'); ?></h3>
                                <span class="directory-browser-close dashicons dashicons-no-alt"></span>
                            </div>
                            <div class="directory-browser-body">
                                <p><?php _e('Navigate to the directory where your Siberian CMS is installed:', 'swiftspeed-siberian'); ?></p>
                                <div id="directory_listing"></div>
                                <div id="directory_browser_path">
                                    <p><?php _e('Current path:', 'swiftspeed-siberian'); ?> <span id="current_path"></span></p>
                                </div>
                                <div id="directory_browser_loading" style="display:none;">
                                    <p><?php _e('Loading directories...', 'swiftspeed-siberian'); ?></p>
                                </div>
                            </div>
                            <div class="directory-browser-footer">
                                <button type="button" class="button button-primary directory-browser-select"><?php _e('Select This Directory', 'swiftspeed-siberian'); ?></button>
                                <button type="button" class="button directory-browser-close"><?php _e('Cancel', 'swiftspeed-siberian'); ?></button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="swsib-notice info">
                        <strong><?php _e('Security Note:', 'swiftspeed-siberian'); ?></strong>
                        <p><?php _e('Your server credentials are stored securely in your WordPress database and are only used to connect to your Siberian CMS installation.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
                
                <div class="swsib-actions">
                    <input type="submit" name="submit" class="button button-primary" id="save-installation-settings" value="<?php _e('Save Changes', 'swiftspeed-siberian'); ?>" <?php echo (isset($install_options['is_configured']) && $install_options['is_configured']) ? '' : 'disabled'; ?>>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Initial connection test to verify credentials
     */
    public function ajax_test_installation_connection() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            $this->log_message('Installation connection test failed: Security check failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            $this->log_message('Installation connection test failed: Permission denied');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Log all post data for debugging
        $this->log_message('Testing connection with parameters: ' . print_r($_POST, true));
        
        // Get method-specific fields
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : 'ftp';
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $result = $this->ftp_handler->test_connection($_POST);
                break;
            case 'sftp':
                $result = $this->sftp_handler->test_connection($_POST);
                break;
            case 'local':
            default:
                $result = $this->local_handler->test_connection($_POST);
                break;
        }
        
        // Log the result
        $this->log_message('Connection test result: ' . print_r($result, true));
        
        if ($result['success']) {
            // If path was not provided, update it with what we found
            if (empty($_POST['path_' . $method]) || $_POST['path_' . $method] == '/') {
                $path_message = '<br>' . __('Found suitable directory at: ', 'swiftspeed-siberian') . '<strong>' . $result['path'] . '</strong>';
            } else {
                $path_message = '';
            }
            
            $response = array(
                'message' => __('Connection successful!', 'swiftspeed-siberian') . $path_message . '<br><br>' . 
                             __('You can now click "Verify Siberian Installation" to confirm this is a valid Siberian installation.', 'swiftspeed-siberian'),
                'directories' => $result['directories'],
                'files' => $result['files'],
                'path' => $result['path']
            );
            
            wp_send_json_success($response);
        } else {
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * Verify a path as a Siberian installation
     */
    public function ajax_verify_siberian_installation() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get method-specific fields
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : 'ftp';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $result = $this->ftp_handler->verify_installation($_POST);
                break;
            case 'sftp':
                $result = $this->sftp_handler->verify_installation($_POST);
                break;
            case 'local':
            default:
                $result = $this->local_handler->verify_installation($_POST);
                break;
        }
        
        if ($result['is_siberian']) {
            wp_send_json_success(array(
                'message' => __('Siberian installation verified successfully!', 'swiftspeed-siberian') . '<br><br>' .
                            __('You can now click "Save Changes" to store these settings.', 'swiftspeed-siberian'),
                'is_siberian' => true,
                'indicators' => $result['found']
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('This does not appear to be a valid Siberian installation. Missing required components:', 'swiftspeed-siberian') . ' ' . implode(', ', $result['missing']),
                'is_siberian' => false,
                'found_indicators' => $result['found'],
                'missing_indicators' => $result['missing']
            ));
        }
    }
    
    /**
     * Browse directory to list files and folders
     */
    public function ajax_browse_directory() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : '';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '/';
        
        $this->log_message("Directory browser request - Method: $method, Path: $path");
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $result = $this->ftp_handler->browse_directory($_POST);
                break;
            case 'sftp':
                $result = $this->sftp_handler->browse_directory($_POST);
                break;
            case 'local':
            default:
                $result = $this->local_handler->browse_directory($_POST);
                break;
        }
        
        if ($result['success']) {
            $this->log_message("Directory listed successfully. Found " . count($result['directories']) . " directories and " . count($result['files']) . " files.");
            wp_send_json_success(array(
                'message' => __('Directory listed successfully', 'swiftspeed-siberian'),
                'directories' => $result['directories'],
                'files' => $result['files'],
                'path' => $result['path']
            ));
        } else {
            $this->log_message("Directory listing failed: " . $result['message']);
            wp_send_json_error(array(
                'message' => $result['message']
            ));
        }
    }
    
    /**
     * AJAX handler for getting installation configuration
     */
    public function ajax_get_installation_config() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Reload options to ensure we have the latest values
        $this->options = get_option('swsib_options', array());
        
        // Get installation configuration
        $install_options = isset($this->options['installation']) ? $this->options['installation'] : array();
        
        // Check if installation connection is configured and enabled
        if (empty($install_options['enabled']) || empty($install_options['is_configured'])) {
            wp_send_json_error(array(
                'message' => 'Installation connection is not configured or not enabled',
                'options' => $install_options
            ));
            return;
        }
        
        // Get connection method
        $connection_method = isset($install_options['connection_method']) ? $install_options['connection_method'] : 'ftp';
        
        // Get connection details
        $connection_details = isset($install_options[$connection_method]) ? $install_options[$connection_method] : array();
        
        // Basic validation of connection details
        if ($connection_method === 'ftp' || $connection_method === 'sftp') {
            if (empty($connection_details['host']) || empty($connection_details['username']) || 
                empty($connection_details['password']) || empty($connection_details['path'])) {
                wp_send_json_error(array('message' => $connection_method . ' connection details are incomplete'));
                return;
            }
        } else { // local
            if (empty($connection_details['path'])) {
                wp_send_json_error(array('message' => 'Local filesystem path is not configured'));
                return;
            }
        }
        
        // Return connection configuration
        wp_send_json_success(array(
            'method' => $connection_method,
            'details' => $connection_details
        ));
    }
    
    /**
     * AJAX handler for getting file contents
     */
    public function ajax_get_file_contents() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get parameters
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : '';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        
        if (empty($path)) {
            wp_send_json_error(array('message' => 'File path not provided'));
            return;
        }
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $result = $this->ftp_handler->get_file_contents($_POST);
                break;
            case 'sftp':
                $result = $this->sftp_handler->get_file_contents($_POST);
                break;
            case 'local':
            default:
                $result = $this->local_handler->get_file_contents($_POST);
                break;
        }
        
        if ($result['success']) {
            wp_send_json_success(array(
                'contents' => $result['contents'],
                'size' => $result['size']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX handler for deleting a file or directory
     */
    public function ajax_delete_file() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Get parameters
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : '';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'file';
        
        if (empty($path)) {
            wp_send_json_error(array('message' => 'File path not provided'));
            return;
        }
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $result = $this->ftp_handler->delete_file($_POST);
                break;
            case 'sftp':
                $result = $this->sftp_handler->delete_file($_POST);
                break;
            case 'local':
            default:
                $result = $this->local_handler->delete_file($_POST);
                break;
        }
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX handler for file download
     * Note: This will output a file for download, so no JSON response
     */
    public function ajax_download_file() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-clean-nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }
        
        // Get parameters
        $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : '';
        $path = isset($_POST['path']) ? sanitize_text_field($_POST['path']) : '';
        
        if (empty($path)) {
            wp_die('File path not provided');
        }
        
        // Route to the appropriate handler
        switch ($method) {
            case 'ftp':
                $this->ftp_handler->download_file($_POST);
                break;
            case 'sftp':
                $this->sftp_handler->download_file($_POST);
                break;
            case 'local':
            default:
                $this->local_handler->download_file($_POST);
                break;
        }
        
        // Should not reach here, but just in case
        wp_die('Something went wrong during file download');
    }
}

// Helper function to format file size for display (shared utility)
function swsib_format_file_size($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// Helper function to check if content is binary (shared utility)
function swsib_is_binary_content($content) {
    // Check for null bytes
    if (strpos($content, "\0") !== false) {
        return true;
    }
    
    // Check for unprintable characters
    $unprintable = 0;
    $total = strlen($content);
    
    // Check only the first 1000 characters for performance
    $sample_size = min(1000, $total);
    
    for ($i = 0; $i < $sample_size; $i++) {
        $char = ord($content[$i]);
        if ($char < 32 && $char != 9 && $char != 10 && $char != 13) {
            $unprintable++;
        }
    }
    
    // If more than 10% of the first 1000 chars are unprintable, consider it binary
    return ($unprintable / $sample_size) > 0.1;
}