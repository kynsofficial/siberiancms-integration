<?php
/**
 * Plugin Name: SiberianCMS Integration
 * Plugin URI: https://swiftspeed.app
 * Description: Enhanced integration between WordPress and Siberian CMS with auto-login capabilities and extended features.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Author: Ssu-Technology Limited and Àgba Akin
 * Author URI: https://akinolaakeem.com
 * Text Domain: swiftspeed-siberian
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/* ============================================================================
   CONSTANTS
============================================================================= */
define( 'SWSIB_VERSION', '1.0.0' );
define( 'SWSIB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWSIB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWSIB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SWSIB_LOG_DIR', SWSIB_PLUGIN_DIR . 'log/' );

// Feature flags.
define( 'SWSIB_ENABLE_AUTO_LOGIN', true );

/* ============================================================================
   LOAD HOOK LOADERS EARLY TO ENSURE PROPER INITIALIZATION
============================================================================= */
require_once SWSIB_PLUGIN_DIR . '/admin/includes/woocommerce/woocommerce-hook-loader.php';
// DEPRECATED: Old subscription hook loader - kept for reference but commented out
// require_once SWSIB_PLUGIN_DIR . '/admin/includes/subscription/subscription-hook-loader.php';

/* ============================================================================
   LOAD NEW SUBSCRIPTION INTEGRATION
============================================================================= */

// Load Composer autoloader if it exists
if (file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
}
/* ============================================================================
   LOAD OTHER DEPENDENCIES
============================================================================= */
require_once SWSIB_PLUGIN_DIR . 'licensing/class-swsib-license-client.php';
require_once SWSIB_PLUGIN_DIR . 'admin/includes/class-swsib-admin.php';
require_once SWSIB_PLUGIN_DIR . 'admin/includes/class-swsib-shortcodes.php';
require_once SWSIB_PLUGIN_DIR . 'admin/includes/advanced-autologin/class-swsib-advanced-shortcodes.php';
require_once SWSIB_PLUGIN_DIR . 'public/includes/class-swsib-public.php';
require_once SWSIB_PLUGIN_DIR . 'admin/includes/compatibility/class-swsib-password-sync.php';
require_once SWSIB_PLUGIN_DIR . 'admin/includes/advanced-autologin/class-swsib-advanced-autologin.php';

/* ============================================================================
   CORE PLUGIN CLASS – MAIN SETUP, HOOKS, ACTIVATION, LOGGING, ETC.
============================================================================= */

require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/subscription-integration.php';
SwiftSpeed_Siberian_Subscription_Integration::init();

class SwiftSpeed_Siberian_Integration {

    private static $instance = null;
    private $options = array();
    public $license = null;
    public $logging = null;
    public $woocommerce = null; // This will be set to our hook loader class.

    // Private constructor to enforce singleton.
    private function __construct() {
        register_shutdown_function( [ $this, 'handle_fatal_error' ] );
        try {
            $this->options = $this->get_options();
            $this->init_logging();
            $this->load_dependencies();
            $this->init_hooks();

            if ( function_exists( 'swsib_license' ) ) {
                $this->license = swsib_license();
            }
            // Assign the WooCommerce integration to the hook loader class
            $this->woocommerce = SwiftSpeed_Siberian_Woocommerce_Hook_Loader::class;
        } catch ( Exception $e ) {
            $this->log_system( 'Exception during initialization: ' . $e->getMessage(), 'error' );
            add_action( 'admin_notices', function() use ( $e ) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>SwiftSpeed Siberian Integration Error:</strong> ' . esc_html( $e->getMessage() ) . '</p>';
                echo '</div>';
            } );
        }
    }

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_logging() {
        if ( ! file_exists( SWSIB_LOG_DIR ) ) {
            wp_mkdir_p( SWSIB_LOG_DIR );
            file_put_contents( SWSIB_LOG_DIR . '.htaccess', "Order deny,allow\nDeny from all" );
            file_put_contents( SWSIB_LOG_DIR . 'index.php', "<?php\n// Silence is golden." );
        }
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/logging/class-swsib-logging-manager.php';
        $this->logging = new SwiftSpeed_Siberian_Logging_Manager();
    }

    private function log_system( $message, $type = 'info' ) {
        if ( $this->logging ) {
            $this->logging->write_to_log( 'system', $type === 'error' ? 'error' : 'info', $message );
        } else {
            $time = date( "Y-m-d H:i:s" );
            file_put_contents( SWSIB_PLUGIN_DIR . 'system_' . $type . '.log', "[$time] $message\n", FILE_APPEND );
        }
    }

    public function handle_fatal_error() {
        $error = error_get_last();
        if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_COMPILE_WARNING, E_CORE_ERROR, E_CORE_WARNING ] ) ) {
            if ( strpos( $error['file'], 'swiftspeed-siberian-integration' ) !== false ) {
                $this->log_system( 'Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], 'error' );
                set_transient( 'swsib_fatal_error', $error, 3600 );
            }
        }
    }

    private function load_dependencies() {
        // All dependencies are loaded above.
        // This function can be used for any additional runtime dependencies.
    }

    private function init_hooks() {
        $this->check_memory_limit();
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
        register_uninstall_hook( __FILE__, [ 'SwiftSpeed_Siberian_Integration', 'uninstall' ] );

        if ( is_admin() ) {
            add_action( 'init', [ $this, 'init_admin' ], 20 );
            add_filter( 'plugin_action_links_' . SWSIB_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
            add_action( 'admin_init', [ $this, 'register_settings' ] );
        } else {
            if ( defined( 'SWSIB_ENABLE_AUTO_LOGIN' ) && SWSIB_ENABLE_AUTO_LOGIN ) {
                add_action( 'wp', [ $this, 'init_public' ], 10 );
            }
        }
        add_action( 'init', [ $this, 'init_shortcodes' ], 10 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
    }

    public function register_settings() {
        register_setting( 'swsib_options', 'swsib_options' );
        register_setting( 'swsib_db_connect_options', 'swsib_options' );
        register_setting( 'swsib_woocommerce_options', 'swsib_options' );
        register_setting( 'swsib_clean_options', 'swsib_options' );
        register_setting( 'swsib_automate_options', 'swsib_options' );
        register_setting( 'swsib_logging_options', 'swsib_options' );
        register_setting( 'swsib_advanced_autologin_options', 'swsib_options' );
        register_setting( 'swsib_backup_restore_options', 'swsib_options' );
    }

    private function check_memory_limit() {
        $mem = $this->get_memory_limit();
        if ( $mem < 67108864 ) {
            add_action( 'admin_notices', function() use ( $mem ) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>SwiftSpeed Siberian Integration:</strong> Your WordPress memory limit is ' . size_format( $mem ) . '. Consider increasing it to at least 64MB.</p>';
                echo '</div>';
            } );
        }
    }

    private function get_memory_limit() {
        $limit = ini_get( 'memory_limit' );
        if ( $limit == -1 ) {
            return PHP_INT_MAX;
        }
        $unit = strtolower( substr( $limit, -1 ) );
        $val = (int)$limit;
        switch ( $unit ) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=swsib-integration' ) . '">' . __('Settings', 'swiftspeed-siberian') . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function init_admin() {
        new SwiftSpeed_Siberian_Admin();
    }

    public function init_public() {
        new SwiftSpeed_Siberian_Public();
        new SwiftSpeed_Siberian_Password_Sync();
    }

    public function init_shortcodes() {
        new SwiftSpeed_Siberian_Shortcodes();
        new SwiftSpeed_Siberian_Advanced_Shortcodes();
    }

    public function maybe_flush_rewrite_rules() {
        if ( get_transient( 'swsib_flush_rewrite_rules' ) ) {
            delete_transient( 'swsib_flush_rewrite_rules' );
            flush_rewrite_rules();
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'swiftspeed-siberian',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    public function activate() {
        $default_options = array(
            'auto_login' => array(
                'siberian_url'   => '',
                'autologin_text' => 'App Dashboard',
                'app_key'        => '',
                'api_user'       => '',
                'api_password'   => '',
                'connection_type'=> 'api',
                'keep_data_on_uninstall' => true
            ),
            'db_connect' => array(
                'host'     => '',
                'database' => '',
                'username' => '',
                'password' => '',
                'port'     => '3306',
                'prefix'   => '',
                'is_configured' => false
            ),
            'advanced_autologin' => array(
                'enabled' => false,
                'buttons' => array()
            ),
            'logging' => array(
                'loggers' => array()
            ),
            // Add default settings for WooCommerce integration (disabled by default)
            'woocommerce' => array(
                'integration_enabled' => false
            ),
            // Add default settings for PE Subscription integration (disabled by default)
            'subscription' => array(
                'integration_enabled' => false
            )
        );
        
        // Only set default options if they don't exist yet
        if ( ! get_option( 'swsib_options' ) ) {
            update_option( 'swsib_options', $default_options );
        } else {
            // If options already exist, make sure integration flags are properly set
            $existing_options = get_option('swsib_options', array());
            
            // Ensure WooCommerce integration has a default
            if (!isset($existing_options['woocommerce']['integration_enabled'])) {
                if (!isset($existing_options['woocommerce'])) {
                    $existing_options['woocommerce'] = array();
                }
                $existing_options['woocommerce']['integration_enabled'] = false;
            }
            
            // Ensure PE Subscription integration has a default
            if (!isset($existing_options['subscription']['integration_enabled'])) {
                if (!isset($existing_options['subscription'])) {
                    $existing_options['subscription'] = array();
                }
                $existing_options['subscription']['integration_enabled'] = false;
            }
            
            // Update options with the new defaults
            update_option('swsib_options', $existing_options);
        }
        
        set_transient( 'swsib_flush_rewrite_rules', true );
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public static function uninstall() {
        $opts = get_option( 'swsib_options', array() );
        $keep = isset( $opts['auto_login']['keep_data_on_uninstall'] ) ? $opts['auto_login']['keep_data_on_uninstall'] : true;
        if ( ! $keep ) {
            delete_option( 'swsib_options' );
            delete_option( 'swsib_instance_id' );
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'swsib_%'" );
            if ( file_exists( SWSIB_PLUGIN_DIR . 'log' ) ) {
                $it = new RecursiveDirectoryIterator( SWSIB_PLUGIN_DIR . 'log', RecursiveDirectoryIterator::SKIP_DOTS );
                $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
                foreach ( $files as $f ) {
                    if ( $f->isDir() ) {
                        rmdir( $f->getRealPath() );
                    } else {
                        unlink( $f->getRealPath() );
                    }
                }
                rmdir( SWSIB_PLUGIN_DIR . 'log' );
            }
        }
    }

    public function get_options() {
        return get_option( 'swsib_options', array() );
    }

    public function is_db_configured() {
        $opts = $this->get_options();
        return ! empty( $opts['db_connect']['is_configured'] );
    }

    public function get_option( $section, $key, $default = '' ) {
        return isset( $this->options[$section][$key] ) ? $this->options[$section][$key] : $default;
    }

    public function log( $module, $type, $message ) {
        if ( $this->logging ) {
            $this->logging->write_to_log( $module, $type, $message );
        }
    }
}

function swsib() {
    return SwiftSpeed_Siberian_Integration::get_instance();
}

function swsib_log($module, $type, $message) {
    static $logged_messages = array();
    $message_key = md5($module . '_' . $type . '_' . $message);
    if (isset($logged_messages[$message_key])) {
        $time_diff = time() - $logged_messages[$message_key];
        if ($time_diff < 5) { return; }
    }
    $logged_messages[$message_key] = time();
    foreach ($logged_messages as $key => $timestamp) {
        if (time() - $timestamp > 30) {
            unset($logged_messages[$key]);
        }
    }
    $instance = swsib();
    if ($instance && $instance->logging) {
        $instance->logging->log($module, $type, $message);
    }
}

// Nonce standardization fix for subscription system
class SWSIB_Subscription_Nonce_Fix {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Only run the fix if we're not in admin
        if (!is_admin() || wp_doing_ajax()) {
            // Hook into AJAX actions to intercept and fix nonces
            add_action('wp_ajax_swsib_calculate_tax', array($this, 'fix_nonce_verification'), 1);
            add_action('wp_ajax_nopriv_swsib_calculate_tax', array($this, 'fix_nonce_verification'), 1);
            add_action('wp_ajax_swsib_process_payment', array($this, 'fix_nonce_verification'), 1);
            add_action('wp_ajax_nopriv_swsib_process_payment', array($this, 'fix_nonce_verification'), 1);
            
            // Add our nonce via JavaScript - using wp_enqueue_scripts to ensure jQuery is loaded first
            add_action('wp_enqueue_scripts', array($this, 'enqueue_nonce_script'));
            
            // Add handler to debug AJAX errors
            add_action('wp_ajax_nopriv_swsib_debug_nonce', array($this, 'debug_nonce'));
            add_action('wp_ajax_swsib_debug_nonce', array($this, 'debug_nonce'));
        }
    }
    
    /**
     * Enqueue the nonce script correctly with jQuery dependency
     */
    public function enqueue_nonce_script() {
        // Properly enqueue our script with jQuery as a dependency
        wp_enqueue_script(
            'swsib-nonce-fix',
            '',  // Empty URL means inline script
            array('jquery'),  // Explicitly depend on jQuery
            SWSIB_VERSION,
            true  // Load in footer
        );
        
        // Generate the nonce
        $nonce = wp_create_nonce('swsib_subscription_checkout_nonce');
        
        // Add inline script
        wp_add_inline_script('swsib-nonce-fix', $this->get_nonce_script($nonce));
    }
    
    /**
     * Get the nonce script code
     */
    private function get_nonce_script($nonce) {
        return "
        /* SwiftSpeed Subscription Nonce Fix */
        window.swsib_standard_nonce = '$nonce';
        
        jQuery(document).ready(function($) {
            console.log('SwiftSpeed Subscription Nonce Fix activated');
            
            // Update existing JavaScript objects if they exist
            if (typeof swsib_subscription_public !== 'undefined') {
                swsib_subscription_public.nonce = window.swsib_standard_nonce;
                swsib_subscription_public.checkout_nonce = window.swsib_standard_nonce;
                console.log('Updated public nonce');
            }
            
            if (typeof swsib_subscription_checkout !== 'undefined') {
                swsib_subscription_checkout.nonce = window.swsib_standard_nonce;
                console.log('Updated checkout nonce');
            }
            
            // Add nonce to checkout form if it exists
            $('#swsib-checkout-form').each(function() {
                if (!$(this).find('input[name=\"swsib_checkout_nonce\"]').length) {
                    $(this).append('<input type=\"hidden\" name=\"swsib_checkout_nonce\" value=\"' + window.swsib_standard_nonce + '\">');
                    console.log('Added nonce field to checkout form');
                }
            });
            
            // Intercept AJAX requests related to subscriptions
            $(document).ajaxSend(function(e, xhr, options) {
                if (options.data && 
                    (typeof options.data === 'string') &&
                    (options.data.indexOf('action=swsib_calculate_tax') > -1 || 
                     options.data.indexOf('action=swsib_process_payment') > -1)) {
                    
                    // Replace any nonce with our standard one
                    if (options.data.indexOf('nonce=') > -1) {
                        options.data = options.data.replace(/nonce=[^&]+/, 'nonce=' + window.swsib_standard_nonce);
                    } else {
                        options.data += '&nonce=' + window.swsib_standard_nonce;
                    }
                }
            });
        });";
    }
    
    public function fix_nonce_verification() {
        if (isset($_POST['nonce'])) {
            // Log the original nonce for debugging
            if (function_exists('swsib_log')) {
                swsib_log('nonce_fix', 'info', 'Original nonce: ' . $_POST['nonce']);
            }
            
            // Always replace with a fresh valid nonce
            $_POST['nonce'] = wp_create_nonce('swsib_subscription_checkout_nonce');
            
            if (function_exists('swsib_log')) {
                swsib_log('nonce_fix', 'info', 'Replaced with valid nonce: ' . $_POST['nonce']);
            }
        }
    }
    
    public function debug_nonce() {
        // This is a utility function for debugging nonce issues
        $response = array(
            'success' => true,
            'message' => 'Nonce debug information',
            'data' => array(
                'post_nonce' => isset($_POST['nonce']) ? $_POST['nonce'] : 'Not set',
                'generated_nonce' => wp_create_nonce('swsib_subscription_checkout_nonce'),
                'verification' => isset($_POST['nonce']) ? wp_verify_nonce($_POST['nonce'], 'swsib_subscription_checkout_nonce') : false,
                'post_data' => $_POST,
                'user_id' => get_current_user_id(),
                'is_user_logged_in' => is_user_logged_in()
            )
        );
        
        wp_send_json($response);
    }








}

// Initialize the nonce fix
SWSIB_Subscription_Nonce_Fix::get_instance();

swsib();