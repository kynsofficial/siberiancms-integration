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

        // Check for shortcodes - add advanced login shortcode to the check
        $has_our_shortcode = (
            has_shortcode($post->post_content, 'swsib_login') ||
            has_shortcode($post->post_content, 'swiftspeedsiberiancms') ||
            has_shortcode($post->post_content, 'swsib_advanced_login')
        );

        if (!$has_our_shortcode) {
            return;
        }

        // Get optional notification text
        $notification_text = isset($this->options['auto_login']['notification_text'])
            ? $this->options['auto_login']['notification_text']
            : 'Connecting to Siberian. Please wait...';

        // Get login notification text
        $login_notification_text = isset($this->options['auto_login']['login_notification_text'])
            ? $this->options['auto_login']['login_notification_text']
            : 'You are being redirected to login page. Please wait...';
            
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

        // Localize with notification texts
        wp_localize_script(
            'swsib-public-js',
            'swsib_vars',
            array(
                'notification_text' => esc_html($notification_text),
                'login_notification_text' => esc_html($login_notification_text),
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

        // Check if Siberian configuration is enabled
        $enable_siberian_config = isset($this->options['auto_login']['enable_siberian_config']) 
            ? $this->options['auto_login']['enable_siberian_config'] 
            : true;
            
        if (!$enable_siberian_config) {
            $this->log_frontend('Auto-login attempted but Integration configuration is disabled in settngs.');
            wp_die('Incomplete access configurations. Please contact the administrator.');
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
        
        // Check for advanced auto login role override
        $default_role_id = $this->options['auto_login']['default_role_id'] ?? '2';
        $sync_existing_role = false;
        
        // Check if this is coming from an advanced auto login button
        if (isset($_GET['swsib_btn']) && isset($_GET['swsib_role'])) {
            // Verify the button exists in our settings
            $button_id = sanitize_text_field($_GET['swsib_btn']);
            $role_id = sanitize_text_field($_GET['swsib_role']);
            $sync_existing_role = isset($_GET['swsib_sync_role']) && $_GET['swsib_sync_role'] === '1';
            
            $advanced_autologin_options = isset($this->options['advanced_autologin']) ? $this->options['advanced_autologin'] : array();
            $buttons = isset($advanced_autologin_options['buttons']) ? $advanced_autologin_options['buttons'] : array();
            
            if (isset($buttons[$button_id]) && $buttons[$button_id]['role_id'] == $role_id) {
                // Use the role ID from the advanced button
                $default_role_id = $role_id;
                $this->log_frontend("Using role ID {$role_id} from advanced auto login button {$button_id}");
                
                // Check if we should sync existing role
                if ($sync_existing_role && isset($buttons[$button_id]['sync_existing_role']) && $buttons[$button_id]['sync_existing_role']) {
                    $this->log_frontend("Role syncing is enabled for button {$button_id}");
                } else {
                    $sync_existing_role = false;
                }
            } else {
                $this->log_frontend("Invalid advanced auto login button ID or role: {$button_id}, {$role_id}");
            }
        }

        // Validate settings
        if (empty($siberian_url) || empty($api_user) || empty($api_password)) {
            $this->log_frontend('Missing Siberian URL or API credentials for auto-login.');
            wp_die(
                'Siberian API settings are not configured. Please contact the administrator.'
            );
        }

        // Ensure trailing slash
        $siberian_url = trailingslashit($siberian_url);

        // Get or create the user's Siberian password from WP user meta
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
            
            // If role sync is enabled, update the user's role
            if ($sync_existing_role) {
                $this->log_frontend("Syncing existing user role to {$default_role_id} for user ID {$user_exists['id']}");
                
                // Prepare update data for role sync
                $update_data = array(
                    'user_id'   => $user_exists['id'],
                    'email'     => $user_email,
                    'password'  => $siberian_password,
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'role_id'   => $default_role_id
                );
                
                // Update the user with the new role
                $sync_result = $this->update_user_with_role(
                    $siberian_url, $api_user, $api_password,
                    $update_data
                );
                
                if (is_wp_error($sync_result)) {
                    $this->log_frontend('Failed to sync user role: ' . $sync_result->get_error_message());
                    // We don't stop the process if role sync fails - continue with authentication
                } else {
                    $this->log_frontend("Successfully synced user role to {$default_role_id}");
                }
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
     * Update user in Siberian with specified role
     * This is a specialized version of update_user that explicitly includes role_id parameter
     */
    private function update_user_with_role($siberian_url, $api_user, $api_password, $user_data) {
        $update_endpoint = $siberian_url . 'admin/api_account/update';
        $response = wp_remote_post($update_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            ),
            'body' => $user_data
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
    * 
    * @param string $text Display text for the button
    * @param string $class CSS classes to add to the button
    * @param string $redirect Optional URL to redirect to after login
    * @param string $color Background color for the button
    * @param string $text_color Text color for the button
    * @return string HTML for the button
    */
   public function generate_autologin_button($text = '', $class = '', $redirect = '', $color = '', $text_color = '') {
       // Check if Siberian configuration is enabled
       $enable_siberian_config = isset($this->options['auto_login']['enable_siberian_config']) 
           ? $this->options['auto_login']['enable_siberian_config'] 
           : true;
           
       if (!$enable_siberian_config) {
           $this->log_frontend('Auto-login button requested but Siberian configuration is disabled.');
           return '<div class="swsib-disabled">Incomplete access configurations. Please contact the administrator.</div>';
       }
   
       // Check if user is logged in
       if (!is_user_logged_in()) {
           // Check if login redirect is enabled
           $enable_login_redirect = isset($this->options['auto_login']['enable_login_redirect']) 
               ? $this->options['auto_login']['enable_login_redirect'] 
               : false;
           
           // If login redirect is enabled, show login button
           if ($enable_login_redirect) {
               // Get custom message and button text
               $not_logged_in_message = isset($this->options['auto_login']['not_logged_in_message']) 
                   ? $this->options['auto_login']['not_logged_in_message'] 
                   : 'You must be logged in to access or create an app.';
               
               $login_button_text = isset($this->options['auto_login']['login_button_text']) 
                   ? $this->options['auto_login']['login_button_text'] 
                   : 'Login';
               
               // Get login redirect URL
               $login_redirect_url = isset($this->options['auto_login']['login_redirect_url']) && !empty($this->options['auto_login']['login_redirect_url']) 
                   ? $this->options['auto_login']['login_redirect_url'] 
                   : wp_login_url(get_permalink());
               
               // Use default colors if none specified
               if (empty($color)) {
                   $color = $this->options['auto_login']['button_color'] ?? '#3a4b79';
               }
               
               if (empty($text_color)) {
                   $text_color = $this->options['auto_login']['button_text_color'] ?? '#ffffff';
               }
               
               // Create a unique ID for the button
               $button_id = 'swsib-login-button-' . wp_rand(1000, 9999);
               
               // Generate login button HTML
               $html = '<div class="swsib-login-required">';
               $html .= '<p>' . esc_html($not_logged_in_message) . '</p>';
               $html .= '<style type="text/css">
                   #' . $button_id . ' {
                       background-color: ' . esc_attr($color) . ' !important;
                       color: ' . esc_attr($text_color) . ' !important;
                   }
                   #' . $button_id . ':hover {
                       filter: brightness(115%) !important;
                   }
                   .swsib-login-required {
                       text-align: center;
                       padding: 20px;
                       margin: 20px 0;
                       background-color: #f8f9fa;
                       border-radius: 5px;
                   }
                   .swsib-login-required p {
                       margin-bottom: 15px;
                   }
                   .swsib-disabled {
                       text-align: center;
                       padding: 10px;
                       margin: 10px 0;
                       background-color: #f8d7da;
                       color: #721c24;
                       border-radius: 5px;
                   }
               </style>';
               $html .= '<a id="' . esc_attr($button_id) . '" href="' . esc_url($login_redirect_url) . '" class="swsib-button">' . esc_html($login_button_text) . '</a>';
               $html .= '</div>';
               
               return $html;
           }
           
           // Default message if login redirect is not enabled
           return '<div class="swsib-login-required">You must be logged in to access your app.</div>';
       }

       // Check if Siberian URL is configured
       $siberian_url = $this->options['auto_login']['siberian_url'] ?? '';
       if (empty($siberian_url)) {
           $this->log_frontend('Siberian URL not configured for auto-login button.');
           return '<div class="swsib-error">Siberian URL is not configured. Please contact the administrator.</div>';
       }

       // Only use default text if none provided
       // This ensures that text explicitly passed to this function takes precedence
       if (empty($text)) {
           $text = $this->options['auto_login']['autologin_text'] ?? 'App Dashboard';
       }
       // Merge with any custom classes
       $class = 'swsib-button ' . trim($class);

       // Use default colors if none specified
       if (empty($color)) {
           $color = $this->options['auto_login']['button_color'] ?? '#3a4b79';
       }
       
       if (empty($text_color)) {
           $text_color = $this->options['auto_login']['button_text_color'] ?? '#ffffff';
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
                   color: ' . esc_attr($text_color) . ' !important;
               }
               #' . $button_id . ':hover {
                   filter: brightness(115%) !important;
               }
               .swsib-disabled {
                   text-align: center;
                   padding: 10px;
                   margin: 10px 0;
                   background-color: #f8d7da;
                   color: #721c24;
                   border-radius: 5px;
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