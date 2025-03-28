<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package SwiftSpeed_Siberian
 */

class SwiftSpeed_Siberian_Admin {

    /**
     * Plugin options.
     *
     * @var array
     */
    private $options;

    /**
     * Current active tab.
     *
     * @var string
     */
    private $active_tab;

    /**
     * Feature instances.
     *
     * @var SwiftSpeed_Siberian_Autologin
     */
    private $autologin;

    /**
     * Password sync instance.
     *
     * @var SwiftSpeed_Siberian_Password_Sync
     */
    private $password_sync;

    /**
     * Database connection instance.
     *
     * @var SwiftSpeed_Siberian_Dbconnect
     */
    private $db_connect;

    /**
     * WooCommerce integration instance.
     *
     * @var SwiftSpeed_Siberian_WooCommerce
     */
    private $woocommerce;

    /**
     * PE Subscription instance.
     *
     * @var SwiftSpeed_Siberian_Subscription
     */
    private $subscription;

    /**
     * Clean-up instance.
     *
     * @var SwiftSpeed_Siberian_Clean
     */
    private $clean;

    /**
     * Automation instance.
     *
     * @var SwiftSpeed_Siberian_Automate
     */
    private $automate;

    /**
     * Logging manager instance.
     *
     * @var SwiftSpeed_Siberian_Logging_Manager
     */
    private $logging;

    /**
     * Advanced auto login instance.
     *
     * @var SwiftSpeed_Siberian_Advanced_AutoLogin
     */
    private $advanced_autologin;

    /**
     * Backup & Restore instance.
     *
     * @var SwiftSpeed_Siberian_Backup_Restore
     */
    private $backup_restore;

    /**
     * Track if license form has been displayed.
     *
     * @var bool
     */
    private $license_form_displayed = false;

    /**
     * Initialize the class.
     */
    public function __construct() {
        // Check for fatal errors from previous runs.
        $this->check_fatal_errors();

        // Get plugin options.
        $this->options = get_option('swsib_options', array());

        // Initialize feature classes.
        $this->load_feature_classes();

        // Add admin menu—using a lower priority to reduce conflicts.
        add_action('admin_menu', array($this, 'add_menu_page'), 99);

        // Enqueue admin scripts and styles.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add filter to persist active tab on settings save.
        add_filter('wp_redirect', array($this, 'settings_save_redirect'), 10, 2);
    }

    /**
     * Log a message to the central logging manager.
     *
     * @param string $message The log message.
     * @return void
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('admin', 'backend', $message);
        }
    }

    /**
     * Load feature classes required for the admin functionality.
     *
     * @return void
     */
    private function load_feature_classes() {
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/autologin/class-swsib-autologin.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/compatibility/class-swsib-password-sync.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/dbconnect/dbconnect.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/woocommerce/class-swsib-woocommerce.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/clean/class-swsib-clean.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/automate/class-swsib-automate.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/logging/class-swsib-logging-manager.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/advanced-autologin/class-swsib-advanced-autologin.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/class-swsib-backup-restore.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/class-swsib-subscription.php';

        // Instantiate each feature class.
        $this->autologin           = new SwiftSpeed_Siberian_Autologin();
        $this->password_sync       = new SwiftSpeed_Siberian_Password_Sync();
        $this->db_connect          = new SwiftSpeed_Siberian_Dbconnect();
        $this->woocommerce         = new SwiftSpeed_Siberian_WooCommerce();
        $this->subscription        = new SwiftSpeed_Siberian_Subscription();
        $this->clean               = new SwiftSpeed_Siberian_Clean();
        $this->automate            = new SwiftSpeed_Siberian_Automate();
        $this->logging             = swsib()->logging ?: new SwiftSpeed_Siberian_Logging_Manager();
        $this->advanced_autologin  = new SwiftSpeed_Siberian_Advanced_AutoLogin();
        $this->backup_restore      = new SwiftSpeed_Siberian_Backup_Restore();
    }

    /**
     * Check for fatal errors from previous runs and display an admin notice if needed.
     *
     * @return void
     */
    private function check_fatal_errors() {
        $error = get_transient('swsib_fatal_error');
        if ($error) {
            delete_transient('swsib_fatal_error');

            // Log the fatal error for later debugging.
            $this->log_message(
                sprintf(
                    "Fatal error encountered: %s in %s on line %s",
                    $error['message'],
                    $error['file'],
                    $error['line']
                )
            );

            add_action('admin_notices', function() use ($error) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>Swiftspeed Siberian Integration encountered a fatal error:</strong> ' . esc_html($error['message']) . '</p>';
                echo '<p>Error occurred in: ' . esc_html($error['file']) . ' on line ' . esc_html($error['line']) . '</p>';
                echo '</div>';
            });
        }
    }

    /**
     * Add the admin menu page.
     *
     * @return void
     */
    public function add_menu_page() {
        add_menu_page(
            __('SwiftSpeed Siberian', 'swiftspeed-siberian'),
            __('Siberian CMS', 'swiftspeed-siberian'),
            'manage_options',
            'swsib-integration',
            array($this, 'display_settings_page'),
            'dashicons-smartphone',
            30
        );
    }

    /**
     * Modify the redirect after settings save to preserve the active tab.
     *
     * @param string $location The URL to redirect to.
     * @param int    $status   HTTP status code.
     * @return string
     */
    public function settings_save_redirect($location, $status) {
        if (strpos($location, 'admin.php?page=swsib-integration') !== false) {
            if (isset($_POST['tab_id']) && !empty($_POST['tab_id'])) {
                $tab_id   = sanitize_key($_POST['tab_id']);
                $location = add_query_arg('tab_id', $tab_id, $location);
            }
        }
        return $location;
    }

    /**
     * Enqueue admin-specific scripts and styles.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        wp_enqueue_style('dashicons');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style(
            'swsib-admin',
            SWSIB_PLUGIN_URL . 'admin/admin.css',
            array('wp-color-picker'),
            SWSIB_VERSION
        );
        wp_enqueue_script(
            'swsib-admin',
            SWSIB_PLUGIN_URL . 'admin/admin.js',
            array('jquery', 'wp-color-picker'),
            SWSIB_VERSION . '.' . time(),
            true
        );

        // Retrieve license status (with forced check).
        $license        = swsib()->license;
        $license_valid  = $license->is_valid(true);

        wp_localize_script(
            'swsib-admin',
            'swsib_admin',
            array(
                'ajax_url'              => admin_url('admin-ajax.php'),
                'nonce'                 => wp_create_nonce('swsib-nonce'),
                'is_db_configured'      => swsib()->is_db_configured(),
                'is_license_valid'      => $license_valid,
                'tabs_with_save_button' => json_encode(array(
                    'auto_login',
                    'db_connect',
                    'clean',
                    'automate',
                    'logging',
                    'advanced_autologin',
                    'backup_restore'
                )),
                'tabs_always_no_save'   => json_encode(array('compatibility', 'license', 'subscription', 'woocommerce')),
                'premium_tabs'          => json_encode(array(
                    'woocommerce',
                    'subscription',
                    'clean',
                    'automate',
                    'advanced_autologin',
                    'backup_restore'
                ))
            )
        );
    }

    /**
     * Display the settings page.
     *
     * @return void
     */
    public function display_settings_page() {
        $license         = swsib()->license;
        // Force license check on settings page load.
        $license_valid   = $license->is_valid(true);
        $is_license_valid = $license_valid;
        $is_db_configured = swsib()->is_db_configured();

        // Determine the active tab from the URL parameter; default to "auto_login".
        $tab_param        = isset($_GET['tab_id']) ? sanitize_key($_GET['tab_id']) : 'auto_login';
        $this->active_tab = $tab_param;

        settings_errors('swsib_options');
        settings_errors('swsib_license');

        $premium_tabs = array('woocommerce', 'subscription', 'clean', 'automate', 'advanced_autologin', 'backup_restore');

        $showing_license_form = in_array($this->active_tab, $premium_tabs) && !$is_license_valid;
        $this->license_form_displayed = false;
        ?>
        <div class="wrap swsib-wrap">
            <div class="swsib-header">
                <h1><?php _e('Swiftspeed Siberian Integration', 'swiftspeed-siberian'); ?></h1>
                <p><?php _e('Customize integration between WordPress and Siberian CMS.', 'swiftspeed-siberian'); ?></p>
            </div>
            <div class="swsib-container">
                <div class="swsib-sidebar">
                    <div class="swsib-tabs">
                        <ul>
                            <li>
                                <a href="#auto-login-tab" class="<?php echo $this->active_tab === 'auto_login' ? 'active' : ''; ?>" data-tab-id="auto_login">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <?php _e('Auto Login', 'swiftspeed-siberian'); ?>
                                </a>
                            </li>
                            <!-- Moved DB Connect to be the second tab -->
                            <li>
                                <a href="#db-connect-tab" class="<?php echo $this->active_tab === 'db_connect' ? 'active' : ''; ?>" data-tab-id="db_connect">
                                    <span class="dashicons dashicons-database"></span>
                                    <?php _e('DB Connect', 'swiftspeed-siberian'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="#compatibility-tab" class="<?php echo $this->active_tab === 'compatibility' ? 'active' : ''; ?>" data-tab-id="compatibility">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                    <?php _e('Compatibility', 'swiftspeed-siberian'); ?>
                                </a>
                            </li>
                            <!-- Advanced Auto Login Tab -->
                            <li>
                                <a href="#advanced-autologin-tab" class="<?php echo $this->active_tab === 'advanced_autologin' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="advanced_autologin">
                                    <span class="dashicons dashicons-admin-network"></span>
                                    <?php _e('Advanced Auto Login', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a href="#woocommerce-tab" class="<?php echo $this->active_tab === 'woocommerce' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="woocommerce">
                                    <span class="dashicons dashicons-cart"></span>
                                    <?php _e('Sell With WooCommerce', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <!-- New PE Subscription Tab -->
                            <li>
                                <a href="#subscription-tab" class="<?php echo $this->active_tab === 'subscription' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="subscription">
                                    <span class="dashicons dashicons-money-alt"></span>
                                    <?php _e('Sell PE Subscriptions', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a href="#clean-tab" class="<?php echo $this->active_tab === 'clean' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="clean">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php _e('Clean', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a href="#automate-tab" class="<?php echo $this->active_tab === 'automate' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="automate">
                                    <span class="dashicons dashicons-controls-repeat"></span>
                                    <?php _e('Automate', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a href="#backup-restore-tab" class="<?php echo $this->active_tab === 'backup_restore' ? 'active' : ''; ?> <?php echo (!$is_license_valid || !$is_db_configured) ? 'license-required' : ''; ?>" data-tab-id="backup_restore">
                                    <span class="dashicons dashicons-database-export"></span>
                                    <?php _e('Backup & Restore', 'swiftspeed-siberian'); ?>
                                    <?php if (!$is_license_valid): ?>
                                        <span class="swsib-lock dashicons dashicons-lock"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a href="#logging-tab" class="<?php echo $this->active_tab === 'logging' ? 'active' : ''; ?>" data-tab-id="logging">
                                    <span class="dashicons dashicons-text-page"></span>
                                    <?php _e('Logging', 'swiftspeed-siberian'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="#license-tab" class="<?php echo $this->active_tab === 'license' ? 'active' : ''; ?>" data-tab-id="license">
                                    <span class="dashicons dashicons-admin-network"></span>
                                    <?php _e('License', 'swiftspeed-siberian'); ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="swsib-content">
                    <!-- Auto Login Tab -->
                    <div id="auto-login-tab" class="swsib-tab-content <?php echo $this->active_tab === 'auto_login' ? 'active' : ''; ?>" data-tab-id="auto_login">
                        <?php $this->autologin->display_settings(); ?>
                    </div>

                    <!-- DB Connect Tab (formerly Advanced Features) -->
                    <div id="db-connect-tab" class="swsib-tab-content <?php echo $this->active_tab === 'db_connect' ? 'active' : ''; ?>" data-tab-id="db_connect">
                        <?php $this->db_connect->display_settings(); ?>
                    </div>

                    <!-- Compatibility Tab -->
                    <div id="compatibility-tab" class="swsib-tab-content <?php echo $this->active_tab === 'compatibility' ? 'active' : ''; ?>" data-tab-id="compatibility">
                        <?php $this->password_sync->display_admin_settings(); ?>
                    </div>

                    <!-- Advanced Auto Login Tab -->
                    <div id="advanced-autologin-tab" class="swsib-tab-content <?php echo $this->active_tab === 'advanced_autologin' ? 'active' : ''; ?>" data-tab-id="advanced_autologin">
                        <?php if ($is_license_valid): ?>
                            <?php if ($is_db_configured): ?>
                                <?php $this->advanced_autologin->display_settings(); ?>
                            <?php else: ?>
                                <div class="swsib-notice warning">
                                    <p><strong><?php _e('DB Connect Required', 'swiftspeed-siberian'); ?></strong></p>
                                    <p><?php _e('You need to configure DB Connect before using Advanced Auto Login.', 'swiftspeed-siberian'); ?></p>
                                    <p><a href="#" class="swsib-tab-link" data-tab="db-connect-tab"><?php _e('Configure DB Connect', 'swiftspeed-siberian'); ?></a></p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- WooCommerce Tab -->
                    <div id="woocommerce-tab" class="swsib-tab-content <?php echo $this->active_tab === 'woocommerce' ? 'active' : ''; ?>" data-tab-id="woocommerce">
                        <?php if ($is_license_valid): ?>
                            <?php $this->woocommerce->display_settings(); ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- PE Subscription Tab -->
                    <div id="subscription-tab" class="swsib-tab-content <?php echo $this->active_tab === 'subscription' ? 'active' : ''; ?>" data-tab-id="subscription">
                        <?php if ($is_license_valid): ?>
                            <?php $this->subscription->display_settings(); ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Clean Tab -->
                    <div id="clean-tab" class="swsib-tab-content <?php echo $this->active_tab === 'clean' ? 'active' : ''; ?>" data-tab-id="clean">
                        <?php if ($is_license_valid): ?>
                            <?php if ($is_db_configured): ?>
                                <?php $this->clean->display_settings(); ?>
                            <?php else: ?>
                                <div class="swsib-notice warning">
                                    <p><strong><?php _e('DB Connect Required', 'swiftspeed-siberian'); ?></strong></p>
                                    <p><?php _e('You need to configure DB Connect before using Clean tools.', 'swiftspeed-siberian'); ?></p>
                                    <p><a href="#" class="swsib-tab-link" data-tab="db-connect-tab"><?php _e('Configure DB Connect', 'swiftspeed-siberian'); ?></a></p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Automate Tab -->
                    <div id="automate-tab" class="swsib-tab-content <?php echo $this->active_tab === 'automate' ? 'active' : ''; ?>" data-tab-id="automate">
                        <?php if ($is_license_valid): ?>
                            <?php if ($is_db_configured): ?>
                                <?php $this->automate->display_settings(); ?>
                            <?php else: ?>
                                <div class="swsib-notice warning">
                                    <p><strong><?php _e('DB Connect Required', 'swiftspeed-siberian'); ?></strong></p>
                                    <p><?php _e('You need to configure DB Connect before using Automation tools.', 'swiftspeed-siberian'); ?></p>
                                    <p><a href="#" class="swsib-tab-link" data-tab="db-connect-tab"><?php _e('Configure DB Connect', 'swiftspeed-siberian'); ?></a></p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Backup & Restore Tab -->
                    <div id="backup-restore-tab" class="swsib-tab-content <?php echo $this->active_tab === 'backup_restore' ? 'active' : ''; ?>" data-tab-id="backup_restore">
                        <?php if ($is_license_valid): ?>
                            <?php if ($is_db_configured): ?>
                                <?php $this->backup_restore->display_settings(); ?>
                            <?php else: ?>
                                <div class="swsib-notice warning">
                                    <p><strong><?php _e('DB Connect Required', 'swiftspeed-siberian'); ?></strong></p>
                                    <p><?php _e('You need to configure DB Connect before using Backup & Restore tools.', 'swiftspeed-siberian'); ?></p>
                                    <p><a href="#" class="swsib-tab-link" data-tab="db-connect-tab"><?php _e('Configure DB Connect', 'swiftspeed-siberian'); ?></a></p>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php swsib()->license->display_activation_form(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Logging Tab -->
                    <div id="logging-tab" class="swsib-tab-content <?php echo $this->active_tab === 'logging' ? 'active' : ''; ?>" data-tab-id="logging">
                        <?php $this->logging->display_settings(); ?>
                    </div>

                    <!-- License Tab -->
                    <div id="license-tab" class="swsib-tab-content <?php echo $this->active_tab === 'license' ? 'active' : ''; ?>" data-tab-id="license">
                        <?php swsib()->license->display_license_tab(); ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Handle clicks on tab links that require license activation.
                $('.swsib-tab-link').on('click', function(e) {
                    e.preventDefault();
                    var tabId = $(this).data('tab');
                    $('.swsib-tabs a[href="#' + tabId + '"]').trigger('click');
                });
                $('.swsib-tabs a.license-required').on('click', function(e) {
                    if (!<?php echo $is_license_valid ? 'true' : 'false'; ?>) {
                        e.preventDefault();
                        $('.swsib-tabs a[href="#license-tab"]').trigger('click');
                    }
                });
            });
        </script>
        <?php
    }
}
