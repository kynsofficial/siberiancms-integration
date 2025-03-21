<?php
/**
 * The public-facing functionality of the plugin.
 */
class SwiftSpeed_Siberian_Public {

    /**
     * Plugin options
     */
    private $options;

    /**
     * Constructor
     */
    public function __construct() {
        // Get plugin options
        $this->options = get_option('swsib_options', array());

        // Enqueue scripts and styles if shortcodes are present
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Process direct authentication requests
        add_action('template_redirect', array($this, 'process_auth_request'));
    }

    /**
     * Log messages to the "auto_login_frontend" channel
     * Keep only truly relevant errors or suspicious events
     */
    private function log_frontend($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('auto_login', 'frontend', $message);
        }
    }

    /**
     * Enqueue scripts and styles only on pages containing our shortcodes
     */
    public function enqueue_scripts() {
        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // Check for shortcodes
        $has_our_shortcode = (
            has_shortcode($post->post_content, 'swsib_login') ||
            has_shortcode($post->post_content, 'swiftspeedsiberiancms')
        );

        if (!$has_our_shortcode) {
            return;
        }

        // Get optional notification text
        $notification_text = isset($this->options['auto_login']['notification_text'])
            ? $this->options['auto_login']['notification_text']
            : 'Connecting to Siberian. Please wait...';

        // Enqueue CSS
        wp_enqueue_style(
            'swsib-public-css',
            SWSIB_PLUGIN_URL . 'public/public.css',
            array(),
            SWSIB_VERSION
        );

        // Enqueue JS
        wp_enqueue_script(
            'swsib-public-js',
            SWSIB_PLUGIN_URL . 'public/public.js',
            array('jquery'),
            SWSIB_VERSION,
            true
        );

        // Localize with optional notification text
        wp_localize_script(
            'swsib-public-js',
            'swsib_vars',
            array(
                'notification_text' => esc_html($notification_text),
            )
        );
    }

    /**
     * Process direct authentication requests (e.g. ?swsib_auth=1)
     */
    public function process_auth_request() {
        if (!isset($_GET['swsib_auth'])) {
            return;
        }

        // If user not logged in, redirect them to WP login
        if (!is_user_logged_in()) {
            $this->log_frontend('User attempted auto-login but is not logged in to WP.');
            wp_redirect(
                wp_login_url(
                    add_query_arg('swsib_auth', '1', home_url())
                )
            );
            exit;
        }

        // Gather user data
        $current_user    = wp_get_current_user();
        $user_email      = $current_user->user_email;
        $firstname       = $current_user->first_name ?: $current_user->display_name;
        $lastname        = $current_user->last_name ?: '';

        // Get Siberian settings from options
        $siberian_url    = $this->options['auto_login']['siberian_url']  ?? '';
        $api_user        = $this->options['auto_login']['api_user']      ?? '';
        $api_password    = $this->options['auto_login']['api_password']  ?? '';
        $default_role_id = $this->options['auto_login']['default_role_id'] ?? '2';

        // Validate settings
        if (empty($siberian_url) || empty($api_user) || empty($api_password)) {
            $this->log_frontend('Missing Siberian URL or API credentials for auto-login.');
            wp_die(
                'Siberian API settings are not configured. Please contact the administrator.'
            );
        }

        // Ensure trailing slash
        $siberian_url = trailingslashit($siberian_url);

        // Get or create the user’s Siberian password from WP user meta
        $siberian_password = get_user_meta($current_user->ID, 'siberian_cms_password', true);

        // Check if user exists in Siberian
        $user_exists = $this->check_user_exists($siberian_url, $api_user, $api_password, $user_email);
        if (is_wp_error($user_exists)) {
            $this->log_frontend('WP Error checking user in Siberian: ' . $user_exists->get_error_message());
            wp_die('Error communicating with Siberian: ' . $user_exists->get_error_message());
        }

        $redirect_url = '';

        // If user already exists in Siberian
        if (!empty($user_exists['exists'])) {
            // If we have no stored password, generate one, update them in Siberian
            if (empty($siberian_password)) {
                $siberian_password = $this->generate_strong_password();
                $update_result = $this->update_user(
                    $siberian_url, $api_user, $api_password,
                    $user_exists['id'], $user_email,
                    $siberian_password, $firstname, $lastname
                );
                if (is_wp_error($update_result)) {
                    $this->log_frontend('Could not update existing Siberian user: ' . $update_result->get_error_message());
                    wp_die('Error updating user in Siberian: ' . $update_result->get_error_message());
                }
                update_user_meta($current_user->ID, 'siberian_cms_password', $siberian_password);
            }

            // Attempt authentication
            $auth_result = $this->authenticate_user(
                $siberian_url, $api_user, $api_password,
                $user_email, $siberian_password
            );
            if (is_wp_error($auth_result)) {
                // If authentication fails, generate a new password, update Siberian user, try again
                $siberian_password = $this->generate_strong_password();
                $update_result = $this->update_user(
                    $siberian_url, $api_user, $api_password,
                    $user_exists['id'], $user_email,
                    $siberian_password, $firstname, $lastname
                );
                if (is_wp_error($update_result)) {
                    $this->log_frontend('Retry update after auth fail: ' . $update_result->get_error_message());
                    wp_die('Error updating user in Siberian: ' . $update_result->get_error_message());
                }
                update_user_meta($current_user->ID, 'siberian_cms_password', $siberian_password);

                // Retry authentication
                $auth_result = $this->authenticate_user(
                    $siberian_url, $api_user, $api_password,
                    $user_email, $siberian_password
                );
                if (is_wp_error($auth_result)) {
                    $this->log_frontend('Failed second authentication attempt: ' . $auth_result->get_error_message());
                    wp_die('Could not authenticate with Siberian: ' . $auth_result->get_error_message());
                }
            }

            // Check for redirect
            if (!empty($auth_result['redirect_url'])) {
                $redirect_url = $auth_result['redirect_url'];
            } else {
                wp_die('Authentication succeeded but no redirect URL was returned from Siberian.');
            }

        } else {
            // If user does not exist in Siberian, create them
            $siberian_password = $this->generate_strong_password();
            $create_result = $this->create_user(
                $siberian_url, $api_user, $api_password,
                $user_email, $siberian_password,
                $firstname, $lastname, $default_role_id
            );
            if (is_wp_error($create_result)) {
                $this->log_frontend('Error creating new Siberian user: ' . $create_result->get_error_message());
                wp_die('Could not create Siberian user: ' . $create_result->get_error_message());
            }

            // Save new password in user meta
            update_user_meta($current_user->ID, 'siberian_cms_password', $siberian_password);

            // If the create response has a redirect URL, use it. Otherwise, we attempt to authenticate
            if (!empty($create_result['redirect_url'])) {
                $redirect_url = $create_result['redirect_url'];
            } else {
                $auth_result = $this->authenticate_user(
                    $siberian_url, $api_user, $api_password,
                    $user_email, $siberian_password
                );
                if (is_wp_error($auth_result)) {
                    $this->log_frontend('Created user but unable to authenticate: ' . $auth_result->get_error_message());
                    wp_die('User created, but could not authenticate: ' . $auth_result->get_error_message());
                }
                if (!empty($auth_result['redirect_url'])) {
                    $redirect_url = $auth_result['redirect_url'];
                } else {
                    wp_die('User created, authentication succeeded, but no redirect URL was returned.');
                }
            }
        }

        // Redirect if we have a URL
        if (!empty($redirect_url)) {
            wp_redirect($redirect_url);
            exit;
        }
        // If we get here, no redirect was found
        wp_die('No redirect URL available from Siberian.');
    }

    /**
     * Check if user exists in Siberian
     */
    private function check_user_exists($siberian_url, $api_user, $api_password, $email) {
        $exist_endpoint = $siberian_url . 'admin/api_account/exist';
        $response = wp_remote_post($exist_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            ),
            'body' => array('email' => $email)
        ));

        if (is_wp_error($response)) {
            return $response; // We'll log in process_auth_request
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'API returned code ' . $code);
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('invalid_response', 'Invalid JSON response from Siberian');
        }
        if (!empty($data['error'])) {
            return new WP_Error('api_error', $data['message'] ?? 'Unknown API error');
        }

        return array(
            'exists' => !empty($data['exists']),
            'id'     => $data['id'] ?? null
        );
    }

    /**
     * Update user in Siberian
     */
    private function update_user($siberian_url, $api_user, $api_password, $user_id, $email, $password, $firstname, $lastname) {
        $update_endpoint = $siberian_url . 'admin/api_account/update';
        $response = wp_remote_post($update_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            ),
            'body' => array(
                'user_id'   => $user_id,
                'email'     => $email,
                'password'  => $password,
                'firstname' => $firstname,
                'lastname'  => $lastname
            )
        ));

        if (is_wp_error($response)) {
            return $response; 
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'API returned code ' . $code);
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('invalid_response', 'Invalid JSON response from Siberian');
        }
        if (!empty($data['error'])) {
            return new WP_Error('api_error', $data['message'] ?? 'Unknown API error');
        }

        return $data;
    }

    /**
     * Create user in Siberian
     */
    private function create_user($siberian_url, $api_user, $api_password, $email, $password, $firstname, $lastname, $role_id = '2') {
        $create_endpoint = $siberian_url . 'admin/api_account/create';
        $response = wp_remote_post($create_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            ),
            'body' => array(
                'role_id'   => $role_id,
                'email'     => $email,
                'password'  => $password,
                'firstname' => $firstname,
                'lastname'  => $lastname
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'API returned code ' . $code);
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('invalid_response', 'Invalid JSON response from Siberian');
        }
        if (!empty($data['error'])) {
            return new WP_Error('api_error', $data['message'] ?? 'Unknown API error');
        }

        return $data;
    }

    /**
     * Authenticate user in Siberian
     */
    private function authenticate_user($siberian_url, $api_user, $api_password, $email, $password) {
        $auth_endpoint = $siberian_url . 'admin/api_account/authenticate';
        $response = wp_remote_post($auth_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            ),
            'body' => array(
                'email'    => $email,
                'password' => $password
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'API returned code ' . $code);
        }

        $data = json_decode($body, true);
        if (!$data) {
            return new WP_Error('invalid_response', 'Invalid JSON response from Siberian');
        }
        if (!empty($data['error'])) {
            return new WP_Error('api_error', $data['message'] ?? 'Unknown API error');
        }

        return $data;
    }

    /**
     * Generate a strong password for Siberian
     */
    private function generate_strong_password() {
        // Basic combination logic
        $uppercase    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase    = 'abcdefghijklmnopqrstuvwxyz';
        $numbers      = '0123456789';
        $specials     = '!@#$%^&*()-_=+';
        $all_chars    = $uppercase . $lowercase . $numbers . $specials;

        // Ensure at least 1 uppercase, 1 lowercase, 1 digit, 1 special
        $password  = $uppercase[rand(0, strlen($uppercase) - 1)];
        $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
        $password .= $numbers[rand(0, strlen($numbers) - 1)];
        $password .= $specials[rand(0, strlen($specials) - 1)];

        // Fill up to at least 9 chars
        for ($i = 0; $i < 8; $i++) {
            $password .= $all_chars[rand(0, strlen($all_chars) - 1)];
        }

        // Shuffle
        return str_shuffle($password);
    }

    /**
     * Generate an auto-login button
     */
    public function generate_autologin_button($text = '', $class = '', $redirect = '', $color = '') {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            // This is not necessarily an "error," but let's let the user know
            return '<div class="swsib-login-required">You must be logged in to access your Siberian app.</div>';
        }

        // Check if Siberian URL is configured
        $siberian_url = $this->options['auto_login']['siberian_url'] ?? '';
        if (empty($siberian_url)) {
            $this->log_frontend('Siberian URL not configured for auto-login button.');
            return '<div class="swsib-error">Siberian URL is not configured. Please contact the administrator.</div>';
        }

        // Default button text from plugin settings
        if (empty($text)) {
            $text = $this->options['auto_login']['autologin_text'] ?? 'App Dashboard';
        }
        // Merge with any custom classes
        $class = 'swsib-button ' . trim($class);

        // Use default color if none specified
        if (empty($color)) {
            $color = $this->options['auto_login']['button_color'] ?? '#3a4b79';
        }

        // Build auth URL with optional redirect param
        $auth_url = add_query_arg('swsib_auth', '1', home_url('/'));
        if (!empty($redirect)) {
            $auth_url = add_query_arg('redirect', urlencode($redirect), $auth_url);
        }

        // Create a unique ID so we can override color with highest specificity
        $button_id    = 'swsib-button-' . wp_rand(1000, 9999);
        $inline_style = '
            <style type="text/css">
                #' . $button_id . ' {
                    background-color: ' . esc_attr($color) . ' !important;
                }
                #' . $button_id . ':hover {
                    filter: brightness(115%) !important;
                }
            </style>';

        // Return final HTML
        return $inline_style . '
            <a id="' . esc_attr($button_id) . '" 
               href="' . esc_url($auth_url) . '" 
               class="' . esc_attr($class) . '" 
               data-color="' . esc_attr($color) . '">
               ' . esc_html($text) . '
            </a>';
    }
}
