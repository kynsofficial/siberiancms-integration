<?php
/**
 * Plugin Name: SiberianCMS Integration
 * Plugin URI: https://swiftspeed.app
 * Description: Enhanced integration between WordPress and Siberian CMS with auto-login capabilities and extended features.
 * Version: 1.0.0
 * Author: Ssu-Technology Limited and Àgba Akin
 * Author URI: https://akinolaakeem.com
 * Text Domain: swiftspeed-siberian
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('SWSIB_VERSION', '1.0.0');
define('SWSIB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SWSIB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SWSIB_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('SWSIB_LOG_DIR', SWSIB_PLUGIN_DIR . 'log/');

// Feature flags
define('SWSIB_ENABLE_AUTO_LOGIN', true); // Set to false to disable auto-login functionality

/**
 * The core plugin class
 */
class SwiftSpeed_Siberian_Integration {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options = array();

    /**
     * License client instance
     */
    public $license = null;
    
    /**
     * Logging manager instance
     */
    public $logging = null;

    /**
     * Initialize the plugin (constructor)
     */
    private function __construct() {
        // Register a shutdown function to catch fatal errors
        register_shutdown_function(array($this, 'handle_fatal_error'));
        
        try {
            // Initialize plugin options
            $this->options = $this->get_options();
            
            // Initialize logging system
            $this->init_logging();
            
            // Load all required code
            $this->load_dependencies();
            
            // Initialize hooks
            $this->init_hooks();
            
            // Initialize license client
            $this->license = swsib_license();
        } catch (Exception $e) {
            // Log any critical error that occurs during setup
            $this->log_system('Exception during initialization: ' . $e->getMessage(), 'error');
            
            // Show admin notice about the error
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>SwiftSpeed Siberian Integration Error:</strong> ' . esc_html($e->getMessage()) . '</p>';
                echo '</div>';
            });
        }
    }

    /**
     * Initialize logging system
     */
    private function init_logging() {
        // Create log directory if it doesn't exist
        if (!file_exists(SWSIB_LOG_DIR)) {
            wp_mkdir_p(SWSIB_LOG_DIR);
            
            // Create .htaccess to protect logs
            file_put_contents(SWSIB_LOG_DIR . '.htaccess', "Order deny,allow\nDeny from all");
            
            // Create index.php to prevent directory listing
            file_put_contents(SWSIB_LOG_DIR . 'index.php', "<?php\n// Silence is golden.");
        }
        
        // Load and initialize the logging manager
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/logging/class-swsib-logging-manager.php';
        $this->logging = new SwiftSpeed_Siberian_Logging_Manager();
    }
    
    /**
     * Log system messages (only for genuine errors or critical issues)
     */
    private function log_system($message, $type = 'info') {
        if ($this->logging) {
            // By default, let's send system logs to the "system" module; "error" if it's an error
            $this->logging->write_to_log('system', $type === 'error' ? 'error' : 'info', $message);
        } else {
            // Fallback if logging manager failed to initialize
            $log_file = SWSIB_PLUGIN_DIR . 'system_' . $type . '.log';
            $time = date("Y-m-d H:i:s");
            file_put_contents($log_file, "[$time] $message\n", FILE_APPEND);
        }
    }

    /**
     * Handle fatal errors
     */
    public function handle_fatal_error() {
        $error = error_get_last();
        
        // Only capture certain types of fatal errors
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_COMPILE_ERROR, E_COMPILE_WARNING, E_CORE_ERROR, E_CORE_WARNING))) {
            // Check if our plugin triggered the error
            if (strpos($error['file'], 'swiftspeed-siberian-integration') !== false) {
                // Log the error
                $this->log_system(
                    'Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'],
                    'error'
                );
                
                // Set a transient for display on the next page load
                set_transient('swsib_fatal_error', $error, 60 * 60); // 1 hour
            }
        }
    }
    
    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // License client
        require_once SWSIB_PLUGIN_DIR . 'licensing/class-swsib-license-client.php';
        
        // Admin
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/class-swsib-admin.php';
        
        // Shortcodes
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/class-swsib-shortcodes.php';
        
        // Public
        require_once SWSIB_PLUGIN_DIR . 'public/includes/class-swsib-public.php';
        
        // Password Sync
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/compatibility/class-swsib-password-sync.php';
    }
    
    /**
     * Register various hooks
     */
    private function init_hooks() {
        // Check memory limit before hooking further
        $this->check_memory_limit();
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Uninstall
        register_uninstall_hook(__FILE__, array('SwiftSpeed_Siberian_Integration', 'uninstall'));
        
        // Admin logic
        if (is_admin()) {
            add_action('init', array($this, 'init_admin'), 20);
            // Add settings link on plugin page
            add_filter('plugin_action_links_' . SWSIB_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        } else {
            // Public (only if auto-login is enabled)
            if (SWSIB_ENABLE_AUTO_LOGIN) {
                add_action('wp', array($this, 'init_public'), 10);
            }
        }
        
        // Shortcodes
        add_action('init', array($this, 'init_shortcodes'), 10);
        
        // Delayed rewrite flush
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 20);
        
        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /**
     * Check memory limit and potentially show admin notice (no log unless it’s truly problematic)
     */
    private function check_memory_limit() {
        $memory_limit = $this->get_memory_limit();
        
        // If memory is < 64MB, show a warning in admin
        if ($memory_limit < 67108864) {
            add_action('admin_notices', function() use ($memory_limit) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>SwiftSpeed Siberian Integration:</strong> ';
                echo 'Your WordPress memory limit is ' . size_format($memory_limit) . ', which may be too low. ';
                echo 'Consider increasing it to at least 64MB for optimal performance.</p>';
                echo '</div>';
            });
        }
    }
    
    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit == -1) {
            // No limit
            return PHP_INT_MAX;
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $memory_value = (int) $memory_limit;
        
        switch ($unit) {
            case 'g':
                $memory_value *= 1024;
            case 'm':
                $memory_value *= 1024;
            case 'k':
                $memory_value *= 1024;
        }
        
        return $memory_value;
    }
    
    /**
     * Add Settings link on the Plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=swsib-integration') . '">' . __('Settings', 'swiftspeed-siberian') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Initialize admin area
     */
    public function init_admin() {
        new SwiftSpeed_Siberian_Admin();
    }
    
    /**
     * Initialize public-facing features
     */
    public function init_public() {
        if (SWSIB_ENABLE_AUTO_LOGIN) {
            new SwiftSpeed_Siberian_Public();
            new SwiftSpeed_Siberian_Password_Sync();
        }
    }
    
    /**
     * Initialize shortcodes
     */
    public function init_shortcodes() {
        new SwiftSpeed_Siberian_Shortcodes();
    }
    
    /**
     * Maybe flush rewrite rules
     */
    public function maybe_flush_rewrite_rules() {
        if (get_transient('swsib_flush_rewrite_rules')) {
            delete_transient('swsib_flush_rewrite_rules');
            flush_rewrite_rules();
        }
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'swiftspeed-siberian',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Not really an error or future problem – so no need to log it
        $default_options = array(
            'auto_login' => array(
                'siberian_url' => '',
                'autologin_text' => 'App Dashboard',
                'app_key' => '',
                'api_user' => '',
                'api_password' => '',
                'connection_type' => 'api',
                'keep_data_on_uninstall' => true
            ),
            'db_connect' => array(
                'host' => '',
                'database' => '',
                'username' => '',
                'password' => '',
                'port' => '3306',
                'prefix' => '',
                'is_configured' => false
            ),
            'logging' => array(
                'loggers' => array()
            )
        );
        
        // Initialize plugin options if none exist
        if (!get_option('swsib_options')) {
            update_option('swsib_options', $default_options);
        }
        
        // Flag to flush rewrite on next load
        set_transient('swsib_flush_rewrite_rules', true);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Also not an error – just routine. We'll not log it.
        flush_rewrite_rules();
    }
    
    /**
     * Plugin uninstall (static for uninstall hook)
     */
    public static function uninstall() {
        $options = get_option('swsib_options', array());
        $keep_data = isset($options['auto_login']['keep_data_on_uninstall'])
            ? $options['auto_login']['keep_data_on_uninstall']
            : true;
        
        if (!$keep_data) {
            // Delete all plugin options
            delete_option('swsib_options');
            delete_option('swsib_instance_id');
            
            // Remove user meta
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'swsib_%'");
            
            // Remove logs if they exist
            if (file_exists(SWSIB_PLUGIN_DIR . 'log')) {
                $it = new RecursiveDirectoryIterator(SWSIB_PLUGIN_DIR . 'log', RecursiveDirectoryIterator::SKIP_DOTS);
                $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) {
                    if ($file->isDir()) {
                        rmdir($file->getRealPath());
                    } else {
                        unlink($file->getRealPath());
                    }
                }
                rmdir(SWSIB_PLUGIN_DIR . 'log');
            }
        }
    }

    /**
     * Retrieve plugin options
     */
    public function get_options() {
        return get_option('swsib_options', array());
    }

    /**
     * Check if external DB connection is configured
     */
    public function is_db_configured() {
        $options = $this->get_options();
        return !empty($options['db_connect']['is_configured']);
    }

    /**
     * Helper to get a specific option
     */
    public function get_option($section, $key, $default = '') {
        return isset($this->options[$section][$key])
            ? $this->options[$section][$key]
            : $default;
    }
    
    /**
     * Helper to log from anywhere inside the plugin
     */
    public function log($module, $type, $message) {
        if ($this->logging) {
            $this->logging->write_to_log($module, $type, $message);
        }
    }
}

/**
 * Main function to get plugin instance
 */
function swsib() {
    return SwiftSpeed_Siberian_Integration::get_instance();
}

/**
 * Global helper to log messages from anywhere
 */
function swsib_log($module, $type, $message) {
    $instance = swsib();
    if ($instance && $instance->logging) {
        $instance->log($module, $type, $message);
    }
}

// Instantiate the plugin
swsib();
