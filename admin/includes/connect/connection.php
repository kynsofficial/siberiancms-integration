<?php
/**
 * DB Connect functionality for the plugin.
 * Main coordinator class for database and filesystem connections.
 */
class SwiftSpeed_Siberian_Dbconnect {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Active tab
     */
    private $active_tab = 'database';
    
    /**
     * Database connection handler
     */
    private $db_connect;
    
    /**
     * File connection handler
     */
    private $file_connect;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get plugin options
        $this->options = swsib()->get_options();
        
        // Include the required files
        require_once(dirname(__FILE__) . '/includes/dbconnect/dbconnect.php');
        require_once(dirname(__FILE__) . '/includes/filesconnect/fileconnect.php');
        
        // Initialize the handlers
        $this->db_connect = new SwiftSpeed_Siberian_DB_Connect();
        $this->file_connect = new SwiftSpeed_Siberian_File_Connect();
        
        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Set active tab
        if (isset($_GET['dbconnect_tab'])) {
            $this->active_tab = sanitize_key($_GET['dbconnect_tab']);
        }
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
     * Enqueue scripts and styles for DB Connect.
     */
    public function enqueue_scripts($hook) {
        // Only load on plugin admin page
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        
        wp_enqueue_style(
            'swsib-db-connect-css',
            SWSIB_PLUGIN_URL . 'admin/includes/connect/connection.css',
            array(),
            SWSIB_VERSION
        );
        
        wp_enqueue_script(
            'swsib-db-connect-js',
            SWSIB_PLUGIN_URL . 'admin/includes/connect/connection.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );
        
        wp_localize_script(
            'swsib-db-connect-js',
            'swsib_af_vars',
            array(
                'ajax_url'             => admin_url('admin-ajax.php'),
                'nonce'                => wp_create_nonce('swsib-nonce'),
                'testing_text'         => __('Testing...', 'swiftspeed-siberian'),
                'button_original_text' => __('Test Connection', 'swiftspeed-siberian'),
                'fill_required_fields' => __('Please fill in all required fields', 'swiftspeed-siberian'),
                'error_occurred'       => __('Error occurred during test. Please try again.', 'swiftspeed-siberian'),
                'wp_path'              => ABSPATH
            )
        );
    }
    
    /**
     * Display DB Connect settings.
     */
    public function display_settings() {
        // Display the title and description first
        ?>
        <h2><?php _e('DB Connect & Installation Path', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('Configure database connection and access to your Siberian CMS installation.', 'swiftspeed-siberian'); ?>
        </p>
        <?php
        
        // Display error messages if any
        if (isset($_GET['error']) && $_GET['error'] === 'nonce_failed') {
            echo '<div class="swsib-notice error"><p>' 
               . __('Security check failed. Please try again.', 'swiftspeed-siberian') 
               . '</p></div>';
        }
        
        // Get the current tab from URL parameter
        $current_tab = isset($_GET['dbconnect_tab']) ? sanitize_key($_GET['dbconnect_tab']) : 'database';
        
        // Display success messages based on tab
        if ($current_tab == 'database' && isset($_GET['db_connect_updated']) && $_GET['db_connect_updated'] === 'true') {
            echo '<div class="swsib-notice success"><p>' 
                . __('DB Connect settings saved successfully.', 'swiftspeed-siberian') 
                . '</p></div>';
        }
        
        if ($current_tab == 'installation' && isset($_GET['installation_updated']) && $_GET['installation_updated'] === 'true') {
            echo '<div class="swsib-notice success"><p>' 
                . __('Installation settings saved successfully.', 'swiftspeed-siberian') 
                . '</p></div>';
        }
        
        // Display tabs
        ?>
        <div class="swsib-section-tabs">
            <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect&dbconnect_tab=database'); ?>" 
               class="<?php echo $current_tab == 'database' ? 'active' : ''; ?>"
               data-tab="database">
                <span class="dashicons dashicons-database"></span>
                <?php _e('Database Connection', 'swiftspeed-siberian'); ?>
            </a>
            <a href="<?php echo admin_url('admin.php?page=swsib-integration&tab_id=db_connect&dbconnect_tab=installation'); ?>" 
               class="<?php echo $current_tab == 'installation' ? 'active' : ''; ?>"
               data-tab="installation">
                <span class="dashicons dashicons-admin-site"></span>
                <?php _e('Installation Path', 'swiftspeed-siberian'); ?>
            </a>
        </div>
        <?php
        
        // Display the database tab content - hidden if not active
        $db_style = $current_tab == 'database' ? '' : 'style="display: none;"';
        echo '<div id="database-tab-content" ' . $db_style . '>';
        $this->db_connect->display_database_settings();
        echo '</div>';
        
        // Display the installation tab content - hidden if not active
        $install_style = $current_tab == 'installation' ? '' : 'style="display: none;"';
        echo '<div id="installation-tab-content" ' . $install_style . '>';
        $this->file_connect->display_installation_settings();
        echo '</div>';
    }
}