<?php
/**
 * License client functionality for the Siberian Integration plugin.
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * SwiftSpeed Siberian License Client class
 */
class SwiftSpeed_Siberian_License_Client {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options = array();
    
    /**
     * Cached license status (bool)
     */
    private $license_status = null;
    
    /**
     * Cached license details (array)
     */
    private $license_details = null;
    
    /**
     * Whether license is activated on this site (bool)
     */
    private $is_activated_on_site = null;
    
    /**
     * API base URL
     */
    private $api_base_url = 'https://swiftspeed.app/wcls-api/v1/';
    
    /**
     * Holds a custom notice for admin display
     */
    private $license_notice = null;
    
    /**
     * Private constructor (singleton pattern)
     */
    private function __construct() {
        // Load plugin options
        $this->options = get_option('swsib_options', array());
        
        // Hooks
        add_action('admin_init', array($this, 'process_license_form'), 5);
        add_action('admin_init', array($this, 'check_license_status'), 30);

        // Remove WordPress settings errors related to license
        add_action('admin_init', array($this, 'remove_all_license_notices'), 1);
        add_filter('pre_update_option_swsib_license_messages', array($this, 'prevent_license_settings_storage'), 10, 2);
        
        // Daily license check
        if (!wp_next_scheduled('swsib_daily_license_check')) {
            wp_schedule_event(time(), 'daily', 'swsib_daily_license_check');
        }
        add_action('swsib_daily_license_check', array($this, 'background_license_check'));
        
        // Cleanup on plugin deactivation
        register_deactivation_hook(SWSIB_PLUGIN_BASENAME, array($this, 'deactivation_cleanup'));
        
        // Add license refresh on tab navigation to premium tabs
        add_action('admin_footer', array($this, 'add_license_refresh_script'));
    }
    
    /**
     * Add script to refresh license when navigating to premium tabs
     */
    public function add_license_refresh_script() {
        // Only add script on plugin admin page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'swsib-integration') === false) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Define premium tabs
            var premiumTabs = ['woocommerce', 'clean', 'automate', 'advanced_autologin', 'backup_restore'];
            
            // Listen for tab changes
            $('.swsib-tabs a').on('click', function() {
                var tabId = $(this).data('tab-id');
                
                // If navigating to a premium tab, refresh license status
                if (premiumTabs.includes(tabId)) {
                    // Make AJAX call to refresh license
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'swsib_refresh_license',
                            nonce: '<?php echo wp_create_nonce('swsib-refresh-license'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                // Instead of reloading the page, if the license is invalid, switch to the License tab
                                if (!response.data.is_valid) {
                                    $('.swsib-tabs a[href="#license-tab"]').trigger('click');
                                }
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
    
    /* -------------------------------------------------------------------------
        LOGGING HELPERS
       ------------------------------------------------------------------------- */
       
    /**
     * Log errors related to user-initiated form actions (i.e. in WP Admin UI)
     * Example: user hits "Activate License," we get an error from the server
     */
    private function log_frontend($message) {
        if (function_exists('swsib') && swsib()->logging) {
            // We log to: module => "License", type => "frontend"
            swsib()->logging->write_to_log('License', 'frontend', $message);
        }
    }
    
    /**
     * Log "behind-the-scenes" or critical logic (API issues, background checks, etc.)
     */
    private function log_backend($message) {
        if (function_exists('swsib') && swsib()->logging) {
            // We log to: module => "License", type => "backend"
            swsib()->logging->write_to_log('License', 'backend', $message);
        }
    }

    /* -------------------------------------------------------------------------
        SINGLETON ACCESS
       ------------------------------------------------------------------------- */
    
    /**
     * Returns the single instance of this class
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /* -------------------------------------------------------------------------
        ADMIN NOTICES & WP SETTINGS ERRORS
       ------------------------------------------------------------------------- */
    
    /**
     * Prevent WordPress from storing license-related settings errors
     */
    public function prevent_license_settings_storage($new_value, $old_value) {
        return false; // always
    }
    
    /**
     * Remove all license notices from WP settings errors
     */
    public function remove_all_license_notices() {
        global $wp_settings_errors;
        
        if (!empty($wp_settings_errors) && is_array($wp_settings_errors)) {
            foreach ($wp_settings_errors as $key => $error) {
                if (isset($error['setting']) && (
                    $error['setting'] === 'swsib_license' ||
                    strpos($error['setting'], 'swsib_license') !== false
                )) {
                    unset($wp_settings_errors[$key]);
                }
            }
            $wp_settings_errors = array_values($wp_settings_errors);
        }
    }
    
    /* -------------------------------------------------------------------------
        SCHEDULING & CLEANUP
       ------------------------------------------------------------------------- */
    
    /**
     * Clear the scheduled daily check on plugin deactivation
     */
    public function deactivation_cleanup() {
        wp_clear_scheduled_hook('swsib_daily_license_check');
    }
    
    /**
     * Background daily check
     */
    public function background_license_check() {
        // Force a fresh check
        $old_status = $this->license_status;  // store to see if changed
        $is_valid   = $this->is_valid(true);
        
        // If license changed from valid to invalid, drop transients
        if ($old_status && !$is_valid) {
            delete_transient('swsib_license_check');
            delete_transient('swsib_license_activated');
        }
    }
    
    /* -------------------------------------------------------------------------
        PERIODIC LICENSE CHECK
       ------------------------------------------------------------------------- */
    
    /**
     * Check license status occasionally (once per day) or on forced param
     */
    public function check_license_status() {
        $last_check = get_option('swsib_license_last_check', 0);
        $now        = time();
        
        // Only once a day unless forced via ?force_license_check=1
        if (($now - $last_check) < DAY_IN_SECONDS && !isset($_GET['force_license_check'])) {
            return;
        }
        
        // Save time & do a forced check now
        update_option('swsib_license_last_check', $now);
        $this->is_valid(true);
    }
    
    /* -------------------------------------------------------------------------
        NOTICES FOR ADMIN
       ------------------------------------------------------------------------- */
    
    /**
     * Set a custom notice shown on the License admin UI
     */
    private function set_license_notice($message, $type = 'error') {
        $this->license_notice = array(
            'message' => $message,
            'type'    => $type
        );
    }
    
    /**
     * Return any existing notice
     */
    public function get_license_notice() {
        return $this->license_notice;
    }
    
    /* -------------------------------------------------------------------------
        LICENSE VALIDATION & CACHING
       ------------------------------------------------------------------------- */
    
    /**
     * Check if license is valid + activated on this site
     */
    public function is_valid($force_check = false) {
        // If we have a known status and not forcing a re-check, just reuse
        if (!$force_check && $this->license_status !== null) {
            return $this->license_status && $this->is_activated_on_site;
        }
        
        // Get license key from user settings
        $license_key = $this->get_license_key();
        if (empty($license_key)) {
            $this->license_status       = false;
            $this->is_activated_on_site = false;
            
            // Don't set a license notice on initial plugin activation
            // Only set "License not found" when the license had been activated before
            $had_license_before = get_option('swsib_had_license_before', false);
            if ($had_license_before) {
                $this->set_license_notice(__('License not found.', 'swiftspeed-siberian'));
            }
            
            return false;
        }
        
        // Set flag to indicate we had a license before
        if (!get_option('swsib_had_license_before', false)) {
            update_option('swsib_had_license_before', true);
        }
        
        // If not forcing a fresh call, try cached result
        if (!$force_check) {
            $cached_status    = get_transient('swsib_license_check');
            $cached_activated = get_transient('swsib_license_activated');
            if ($cached_status !== false && $cached_activated !== false) {
                // Check if it's now expired
                if (!empty($cached_status['details']['expires'])) {
                    $exp = strtotime($cached_status['details']['expires']);
                    if ($exp && $exp <= time()) {
                        // Expired
                        $this->license_status       = false;
                        $this->is_activated_on_site = false;
                        $this->license_details      = $cached_status['details'];
                        delete_transient('swsib_license_check');
                        delete_transient('swsib_license_activated');
                        return false;
                    }
                }
                // Otherwise restore from cache
                $this->license_status       = $cached_status['status'];
                $this->license_details      = $cached_status['details'] ?? null;
                $this->is_activated_on_site = $cached_activated;
                return $this->license_status && $this->is_activated_on_site;
            }
        }
        
        // Make a fresh API call
        $validation = $this->validate_license($license_key, $this->get_instance_id());
        
        if (!empty($validation['success'])) {
            // Possibly valid license, or valid but not "activated" for this domain
            $is_valid         = !empty($validation['valid']);
            $this->license_status = $is_valid;
            
            $activated = true;
            // If we have an error saying "instance is not activated," 
            // that means the license is valid but not for this domain
            if (!$is_valid && !empty($validation['error']) &&
                strpos($validation['error'], 'instance is not activated') !== false
            ) {
                $activated            = false;
                $this->license_status = true; // license is valid, just not active for this domain
            }
            $this->is_activated_on_site = $activated;
            
            // Grab license details if available
            if (!empty($validation['license'])) {
                $this->license_details = $validation['license'];
            }
            
            // Decide how long we keep the positive result in cache
            $cache_time = 12 * HOUR_IN_SECONDS;
            if (!empty($this->license_details['expires'])) {
                $expiry_time = strtotime($this->license_details['expires']);
                if ($expiry_time) {
                    $time_left = $expiry_time - time();
                    if ($time_left < 12 * HOUR_IN_SECONDS) {
                        $cache_time = HOUR_IN_SECONDS;
                    }
                    if ($time_left < HOUR_IN_SECONDS) {
                        $cache_time = 5 * MINUTE_IN_SECONDS;
                    }
                    if ($time_left <= 0) {
                        // Already expired
                        $this->license_status       = false;
                        $this->is_activated_on_site = false;
                        $cache_time = 0;
                        return false;
                    }
                }
            }
            
            // If > 0, store
            if ($cache_time > 0) {
                set_transient('swsib_license_check', array(
                    'status'  => $this->license_status,
                    'details' => $this->license_details
                ), $cache_time);
                set_transient('swsib_license_activated', $this->is_activated_on_site, $cache_time);
            }
            
            return $this->license_status && $this->is_activated_on_site;
        }
        
        // If we got here, license validation fully failed
        $this->license_status       = false;
        $this->is_activated_on_site = false;
        
        // Cache negative for 2 hours
        set_transient('swsib_license_check', array(
            'status'  => false,
            'details' => $validation['license'] ?? null
        ), 2 * HOUR_IN_SECONDS);
        set_transient('swsib_license_activated', false, 2 * HOUR_IN_SECONDS);
        
        return false;
    }
    
    /**
     * Validate license (API)
     */
    private function validate_license($license_key, $instance_id) {
        $url = add_query_arg(array(
            'license_key' => $license_key,
            'instance_id' => $instance_id,
            'domain'      => parse_url(site_url(), PHP_URL_HOST),
        ), $this->api_base_url . 'validate');
        
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => true,
        ));
        
        if (is_wp_error($response)) {
            // This is a "backend" error, as it's from the server's perspective
            $this->log_backend('License validation WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error'   => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            $this->log_backend('License validation: invalid JSON response.');
            return array(
                'success' => false,
                'error'   => 'Invalid response from licensing server'
            );
        }
        
        return $data;
    }
    
    /* -------------------------------------------------------------------------
        LICENSE ACTIVATION & DEACTIVATION
       ------------------------------------------------------------------------- */
    
    /**
     * Activate license with API
     */
    private function activate_license($license_key, $instance_id, $domain) {
        $url = add_query_arg(array(
            'license_key' => $license_key,
            'instance_id' => $instance_id,
            'domain'      => $domain,
            'platform'    => 'WordPress'
        ), $this->api_base_url . 'activate');
        
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => true,
        ));
        
        if (is_wp_error($response)) {
            // This is user-initiated, so we can call log_frontend
            $this->log_frontend('License activation WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error'   => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            $this->log_frontend('License activation: invalid JSON from server.');
            return array(
                'success' => false,
                'error'   => 'Invalid response from licensing server'
            );
        }
        
        return $data;
    }
    
    /**
     * Deactivate license with API
     */
    private function deactivate_license($license_key, $instance_id) {
        $url = add_query_arg(array(
            'license_key' => $license_key,
            'instance_id' => $instance_id
        ), $this->api_base_url . 'deactivate');
        
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => true,
        ));
        
        if (is_wp_error($response)) {
            // Also user-initiated (admin form), so log to "frontend"
            $this->log_frontend('License deactivation WP Error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error'   => $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!$data) {
            $this->log_frontend('License deactivation: invalid JSON from server.');
            return array(
                'success' => false,
                'error'   => 'Invalid response from licensing server'
            );
        }
        
        return $data;
    }
    
    /* -------------------------------------------------------------------------
        GETTERS
       ------------------------------------------------------------------------- */
    
    /**
     * Retrieve license key from plugin options
     */
    public function get_license_key() {
        return $this->options['license_key'] ?? '';
    }
    
    /**
     * Return license details ( triggers is_valid if not set )
     */
    public function get_license_details() {
        if ($this->license_details === null) {
            $this->is_valid(); // triggers a check
        }
        return $this->license_details;
    }
    
    /**
     * Return whether license is activated on this site ( triggers is_valid if unknown )
     */
    public function is_activated_on_site() {
        if ($this->is_activated_on_site === null) {
            $this->is_valid();
        }
        return $this->is_activated_on_site;
    }
    
    /**
     * Retrieve or create a unique instance ID for this site
     */
    public function get_instance_id() {
        $instance_id = get_option('swsib_instance_id');
        if (empty($instance_id)) {
            $instance_id = md5(
                get_site_url() . '-siberian-integration-' . wp_generate_password(12, false)
            );
            update_option('swsib_instance_id', $instance_id);
        }
        return $instance_id;
    }
    
    /* -------------------------------------------------------------------------
        FORM HANDLERS FOR ADMIN (ACTIVATE / DEACTIVATE)
       ------------------------------------------------------------------------- */
    
    /**
     * Detect license form submission in admin
     */
    public function process_license_form() {
        if (!isset($_POST['swsib_license_action'])) {
            return;
        }
        
        // Reset prior notice
        $this->license_notice = null;
        
        // Verify nonce
        $nonce_verified = false;
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'swsib_license_nonce') !== false) {
                if (wp_verify_nonce($value, 'swsib_license_action')) {
                    $nonce_verified = true;
                    break;
                }
            }
        }
        
        if (!$nonce_verified) {
            $this->set_license_notice(__('Security check failed.', 'swiftspeed-siberian'));
            return;
        }
        
        // Activate or Deactivate
        $action = sanitize_text_field($_POST['swsib_license_action']);
        if ($action === 'activate') {
            $this->handle_license_activation();
        } elseif ($action === 'deactivate') {
            $this->handle_license_deactivation();
        }
    }
    
    /**
     * Handle license activation from user's form
     */
    private function handle_license_activation() {
        $license_key = isset($_POST['license_key']) 
            ? sanitize_text_field($_POST['license_key']) 
            : '';
        
        if (empty($license_key)) {
            $this->set_license_notice(__('Please enter a valid license key.', 'swiftspeed-siberian'));
            return;
        }
        
        $instance_id = $this->get_instance_id();
        $domain      = parse_url(site_url(), PHP_URL_HOST);
        
        $activation  = $this->activate_license($license_key, $instance_id, $domain);
        if (empty($activation['success'])) {
            // We may have an error message
            $error_message = $activation['error'] 
                ?? __('License activation failed.', 'swiftspeed-siberian');
            // Set notice so the user sees it
            $this->set_license_notice($error_message);
            return;
        }
        
        // If success, store license key
        $this->options['license_key'] = $license_key;
        update_option('swsib_options', $this->options);
        
        // Set flag to indicate we had a license before
        update_option('swsib_had_license_before', true);
        
        // Clear out cached status
        delete_transient('swsib_license_check');
        delete_transient('swsib_license_activated');
        $this->license_status       = null;
        $this->is_activated_on_site = null;
        $this->license_details      = null;
        
        // Possibly store returned license details
        if (!empty($activation['license'])) {
            $this->license_details = $activation['license'];
        }
        
        // Show success
        $this->set_license_notice(
            __('License activated successfully.', 'swiftspeed-siberian'), 
            'updated'
        );
    }
    
    /**
     * Handle license deactivation (admin form)
     */
    private function handle_license_deactivation() {
        $license_key = $this->get_license_key();
        if (empty($license_key)) {
            $this->set_license_notice(__('No license key found to deactivate.', 'swiftspeed-siberian'));
            return;
        }
        
        $instance_id  = $this->get_instance_id();
        $deactivation = $this->deactivate_license($license_key, $instance_id);
        if (empty($deactivation['success'])) {
            $error_message = $deactivation['error']
                ?? __('License deactivation failed.', 'swiftspeed-siberian');
            $this->set_license_notice($error_message);
            return;
        }
        
        // We keep the key but mark it as not activated
        delete_transient('swsib_license_check');
        set_transient('swsib_license_activated', false, MONTH_IN_SECONDS);
        $this->license_status       = true;  // valid license, not active here
        $this->is_activated_on_site = false;
        $this->license_details      = null;
        
        $this->set_license_notice(
            __('License deactivated on this site. Key is still saved if you wish to reactivate later.', 'swiftspeed-siberian'),
            'updated'
        );
    }

    /**
     * AJAX callback for refreshing license
     */
    public function ajax_refresh_license() {
        check_ajax_referer('swsib-refresh-license', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $is_valid = $this->is_valid(true);
        
        wp_send_json_success(array(
            'is_valid' => $is_valid,
            'message' => $is_valid ? 'License is valid' : 'License is invalid'
        ));
    }
    
    /* -------------------------------------------------------------------------
        DISPLAY METHODS FOR ADMIN UI
       ------------------------------------------------------------------------- */
    
    /**
     * Display short license activation form (shown if license is invalid)
     */
    public function display_activation_form() {
        $license_key = $this->get_license_key();
        $has_license = !empty($license_key);
        $form_id = 'swsib-license-form-' . uniqid(); // Generate unique ID for the form
        ?>
        <div class="swsib-license-form">
            <div class="swsib-notice warning">
                <p>
                    <?php _e('This feature requires a valid license. Enter your key to unlock Pro features.', 'swiftspeed-siberian'); ?>
                </p>
            </div>
            
            <?php $this->display_license_notice(); ?>
            
            <form method="post" action="" id="<?php echo esc_attr($form_id); ?>">
                <?php wp_nonce_field('swsib_license_action', 'swsib_license_nonce_' . uniqid()); ?>
                <input type="hidden" name="swsib_license_action" value="activate">
                
                <div class="swsib-field">
                    <label for="license_key_<?php echo esc_attr($form_id); ?>"><?php _e('License Key', 'swiftspeed-siberian'); ?></label>
                    <input 
                        type="text" 
                        name="license_key" 
                        id="license_key_<?php echo esc_attr($form_id); ?>" 
                        value="<?php echo esc_attr($license_key); ?>" 
                        class="regular-text" 
                        required
                    >
                </div>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $has_license 
                            ? __('Reactivate License', 'swiftspeed-siberian') 
                            : __('Activate License', 'swiftspeed-siberian'); ?>
                    </button>
                </p>
                
                <?php if (!$has_license): ?>
                <p class="description">
                    <?php _e('Don\'t have a license key?', 'swiftspeed-siberian'); ?>
                    <a href="https://swiftspeed.app/product/siberian-integration/" target="_blank">
                        <?php _e('Purchase one here', 'swiftspeed-siberian'); ?>
                    </a>.
                </p>
                <?php endif; ?>
                
                <div class="swsib-license-buttons">
                    <a href="https://swiftspeed.app/my-account/licenses/" 
                       target="_blank" 
                       class="swsib-btn swsib-btn-mint">
                        <?php _e('Get Existing License', 'swiftspeed-siberian'); ?>
                    </a>
                    <a href="https://swiftspeed.app/product-category/siberiancms-integration/" 
                       target="_blank" 
                       class="swsib-btn swsib-btn-purple">
                        <?php _e('Buy License', 'swiftspeed-siberian'); ?>
                    </a>
                </div>
            </form>
        </div>
        <?php
    }
    
    /**
     * Display the admin notice if it's set
     */
    public function display_license_notice() {
        static $shown = false;
        if ($shown || !$this->license_notice) {
            return;
        }
        $shown = true;
        
        $type_class = ($this->license_notice['type'] === 'updated') ? 'success' : $this->license_notice['type'];
        ?>
        <div class="swsib-notice <?php echo esc_attr($type_class); ?>">
            <p><strong><?php echo esc_html($this->license_notice['message']); ?></strong></p>
        </div>
        <?php
    }
    
    /**
     * Display license tab content:
     * If valid => show full info 
     * If invalid => show activation form
     */
    public function display_license_tab() {
        $license_key = $this->get_license_key();
        $is_active   = $this->is_valid();
        
        if ($is_active) {
            $this->display_license_info();
        } else {
            $this->display_activation_form();
        }
    }
    
    /**
     * Display detailed license info (when license is valid)
     */
    public function display_license_info() {
        $license_key     = $this->get_license_key();
        $license_details = $this->get_license_details();
        $form_id = 'swsib-license-info-' . uniqid(); // Generate unique ID for the form
        
        // Re-check states
        $is_valid     = $this->is_valid();
        $is_activated = $this->is_activated_on_site();
        ?>
        <div class="swsib-license-info">
            <h3><?php _e('License Information', 'swiftspeed-siberian'); ?></h3>
            
            <?php $this->display_license_notice(); ?>
            
            <table class="form-table">
                <tr>
                    <th><?php _e('License Key', 'swiftspeed-siberian'); ?></th>
                    <td><code><?php echo esc_html($license_key); ?></code></td>
                </tr>
                <tr>
                    <th><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                    <td>
                        <?php if ($is_activated): ?>
                            <span class="swsib-status active">
                                <?php _e('Active', 'swiftspeed-siberian'); ?>
                            </span>
                        <?php else: ?>
                            <span class="swsib-status inactive">
                                <?php _e('Inactive on this site', 'swiftspeed-siberian'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($license_details['expires'])): ?>
                <tr>
                    <th><?php _e('Expires', 'swiftspeed-siberian'); ?></th>
                    <td>
                        <?php 
                        $exp_date = strtotime($license_details['expires']);
                        echo $exp_date 
                            ? date_i18n(get_option('date_format'), $exp_date)
                            : __('Never', 'swiftspeed-siberian');
                        ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php _e('Domain', 'swiftspeed-siberian'); ?></th>
                    <td><?php echo esc_html(parse_url(site_url(), PHP_URL_HOST)); ?></td>
                </tr>
            </table>
            
            <div class="swsib-license-actions" style="margin-top: 20px;">
                <!-- Refresh & Deactivate/Reactivate -->
                <div style="display: flex; gap: 10px;">
                    <a href="<?php echo esc_url(add_query_arg('force_license_check', '1')); ?>" 
                       class="button button-secondary">
                        <?php _e('Refresh License Status', 'swiftspeed-siberian'); ?>
                    </a>
                    
                    <form method="post" action="" id="<?php echo esc_attr($form_id); ?>">
                        <?php wp_nonce_field('swsib_license_action', 'swsib_license_nonce_' . uniqid()); ?>
                        
                        <?php if ($is_activated): ?>
                            <input type="hidden" name="swsib_license_action" value="deactivate">
                            <button type="submit" class="button button-secondary"
                                onclick="return confirm('<?php esc_attr_e('Are you sure you want to deactivate this license on this site?', 'swiftspeed-siberian'); ?>');">
                                <?php _e('Deactivate on this Site', 'swiftspeed-siberian'); ?>
                            </button>
                        <?php else: ?>
                            <input type="hidden" name="swsib_license_action" value="activate">
                            <button type="submit" class="button button-primary">
                                <?php _e('Reactivate on this Site', 'swiftspeed-siberian'); ?>
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <p class="description" style="margin-top: 10px;">
                    <?php if ($is_activated): ?>
                        <?php _e('Deactivating frees up a slot for use on another site.', 'swiftspeed-siberian'); ?>
                    <?php else: ?>
                        <?php _e('Reactivate to enable premium features here.', 'swiftspeed-siberian'); ?>
                    <?php endif; ?>
                </p>
                
                <!-- Manage license & buy new -->
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <a href="https://swiftspeed.app/my-account/licenses/" 
                       target="_blank" 
                       class="swsib-btn swsib-btn-mint">
                        <?php _e('Manage License', 'swiftspeed-siberian'); ?>
                    </a>
                    <a href="https://swiftspeed.app/product-category/siberiancms-integration/" 
                       target="_blank" 
                       class="swsib-btn swsib-btn-purple">
                        <?php _e('Buy New License', 'swiftspeed-siberian'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * Initialize the license client
 */
function swsib_license() {
    return SwiftSpeed_Siberian_License_Client::instance();
}

// Add AJAX handler for license refresh
add_action('wp_ajax_swsib_refresh_license', function() {
    swsib_license()->ajax_refresh_license();
});
