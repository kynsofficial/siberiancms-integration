<?php
/**
 * Password synchronization functionality between WordPress and Siberian CMS.
 */
class SwiftSpeed_Siberian_Password_Sync {
    
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
        
        // Register the shortcode
        add_shortcode('swsib_password_sync', array($this, 'password_sync_shortcode'));
        
        // Process form submission (front-end)
        add_action('template_redirect', array($this, 'process_form_submission'));
        
        // Enqueue assets (front-end)
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Display Compatibility settings in admin
     */
    public function display_admin_settings() {
        ?>
        <h2><?php _e('Siberian Previewer Compatibility', 'swiftspeed-siberian'); ?></h2>
        <p class="panel-description">
            <?php _e('To ensure compatibility with Siberian CMS Previewer, users need to have their WordPress and Siberian passwords synchronized.', 'swiftspeed-siberian'); ?>
        </p>
        
        <div class="swsib-notice info">
            <p><strong><?php _e('Password Synchronization:', 'swiftspeed-siberian'); ?></strong> 
            <?php _e('When users access the Siberian Previewer, they need to authenticate with credentials recognized by the Siberian CMS database. This plugin ensures password synchronization between WordPress and Siberian CMS.', 'swiftspeed-siberian'); ?></p>
        </div>
        
        <div class="shortcode-info">
            <h3><?php _e('Password Sync Form Shortcode', 'swiftspeed-siberian'); ?></h3>
            <p><?php _e('Use the following shortcode to display a password synchronization form on your site:', 'swiftspeed-siberian'); ?></p>
            <code class="shortcode-example">[swsib_password_sync]</code>
            
            <h4><?php _e('Implementation Guide', 'swiftspeed-siberian'); ?></h4>
            <ol>
                <li><?php _e('Create a new page in WordPress where users can update their passwords.', 'swiftspeed-siberian'); ?></li>
                <li><?php _e('Add the shortcode <code>[swsib_password_sync]</code> to that page.', 'swiftspeed-siberian'); ?></li>
                <li><?php _e('In your Siberian Previewer setup, link to this page with guidance on password updates if users have login issues.', 'swiftspeed-siberian'); ?></li>
            </ol>
            
            <h4><?php _e('How It Works', 'swiftspeed-siberian'); ?></h4>
            <p><?php _e('The password sync form will:', 'swiftspeed-siberian'); ?></p>
            <ul>
                <li><?php _e('Verify user email via OTP (one-time password)', 'swiftspeed-siberian'); ?></li>
                <li><?php _e('Allow users to set a new password (min 6 characters, Siberian-compatible)', 'swiftspeed-siberian'); ?></li>
                <li><?php _e('Update the password in both WordPress and Siberian CMS simultaneously', 'swiftspeed-siberian'); ?></li>
                <li><?php _e('Create a Siberian user account if one doesn\'t exist', 'swiftspeed-siberian'); ?></li>
            </ul>
        </div>
        
        <div class="swsib-notice warning">
            <p><strong><?php _e('Important:', 'swiftspeed-siberian'); ?></strong> 
            <?php _e('Ensure your Siberian API credentials are correctly set in the Auto Login tab for this feature to work properly.', 'swiftspeed-siberian'); ?></p>
        </div>
        <?php
    }

    /**
     * Enqueue scripts and styles (FRONT-END).
     */
    public function enqueue_scripts() {
        global $post;
        
        // Only enqueue on pages with our shortcode
        if ( ! is_a($post, 'WP_Post') || ! has_shortcode( $post->post_content, 'swsib_password_sync' ) ) {
            return;
        }
        
        swsib_log('compatibility', 'backend', 'Enqueuing password sync styles on page ID: ' . $post->ID);
        
        // Enqueue CSS with a version parameter to force cache refresh
        wp_enqueue_style(
            'swsib-password-sync-css',
            SWSIB_PLUGIN_URL . 'admin/includes/compatibility/password-sync.css',
            array(),
            SWSIB_VERSION . '.' . time()
        );
        
    }
    
    /**
     * Password sync shortcode (FRONT-END).
     */
    public function password_sync_shortcode($atts) {
        swsib_log('compatibility', 'backend', 'Password sync shortcode called');
        
        // Shortcode attributes
        $atts = shortcode_atts(array(
            'title'       => __('Fix Authentication Issue', 'swiftspeed-siberian'),
            'description' => __('Update your password to fix authentication issues', 'swiftspeed-siberian'),
        ), $atts, 'swsib_password_sync');
        
        // Start output buffering
        ob_start();
        
        // Render the form HTML
        $this->render_password_sync_form($atts);
        
        return ob_get_clean();
    }
    
    /**
     * Process form submission (FRONT-END).
     */
    public function process_form_submission() {
        // Check for resend via URL parameter
        if ( isset( $_GET['swsib_resend'] ) && isset( $_GET['email'] ) ) {
            $_POST['email'] = sanitize_email( urldecode( $_GET['email'] ) );
            $_POST['swsib_form_action'] = 'resend_otp';
            $this->handle_resend_otp();
            return;
        }
        
        if ( ! isset( $_POST['swsib_form_action'] ) ) {
            return;
        }
        
        $action = sanitize_text_field( $_POST['swsib_form_action'] );
        swsib_log('compatibility', 'backend', 'Form submission detected: ' . $action);
        
        switch ( $action ) {
            case 'verify_email':
                $this->handle_email_verification();
                break;
            case 'verify_otp':
                $this->handle_otp_verification();
                break;
            case 'update_password':
                $this->handle_password_update();
                break;
            case 'resend_otp':
                $this->handle_resend_otp();
                break;
        }
    }
    
    /**
     * Handle email verification step (FRONT-END).
     */
    private function handle_email_verification() {
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        
        swsib_log('compatibility', 'backend', 'Email verification requested for: ' . $email);
        
        if ( empty( $email ) ) {
            swsib_log('compatibility', 'backend', 'Email verification failed: Empty email');
            $this->set_error_message( __( 'Please enter a valid email address.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Check if email exists in WP
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            swsib_log('compatibility', 'backend', 'Email not found in WordPress: ' . $email);
            $this->set_error_message( __( 'No account found with that email address.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Generate OTP & store
        $otp = $this->generate_otp();
        $expiry = time() + ( 15 * 60 ); // 15 minutes
        update_user_meta( $user->ID, 'swsib_password_sync_otp', $otp );
        update_user_meta( $user->ID, 'swsib_password_sync_otp_expiry', $expiry );
        
        swsib_log('compatibility', 'backend', 'Generated OTP for user: ' . $email . ' (User ID: ' . $user->ID . ')');
        
        // Send OTP
        $sent = $this->send_otp_email( $user, $otp );
        if ( is_wp_error( $sent ) ) {
            swsib_log('compatibility', 'backend', 'Failed to send OTP email: ' . $sent->get_error_message() );
            $this->set_error_message( __( 'Failed to send verification code. Please try again later.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Save email in session / cookie
        update_option( 'swsib_temp_email_' . md5( $email ), $email, false );
        setcookie( 'swsib_email', $email, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        
        swsib_log('compatibility', 'backend', 'OTP sent successfully to: ' . $email . ' - Redirecting to OTP step');
        
        // Continue
        $this->set_success_message( __( 'Verification code sent! Check your email.', 'swiftspeed-siberian' ) );
        wp_redirect( add_query_arg( 'swsib_step', 'otp', $_SERVER['HTTP_REFERER'] ) );
        exit;
    }
    
    /**
     * Handle OTP verification step (FRONT-END).
     */
    private function handle_otp_verification() {
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $otp   = isset( $_POST['otp'] ) ? sanitize_text_field( $_POST['otp'] ) : '';
        
        swsib_log('compatibility', 'backend', 'OTP verification requested. Initial email: "' . $email . '", OTP: "' . $otp . '"');
        
        // Fallback methods to get email if not in POST
        if ( empty( $email ) ) {
            // Try cookie
            if ( isset( $_COOKIE['swsib_email'] ) ) {
                $email = sanitize_email( $_COOKIE['swsib_email'] );
                swsib_log('compatibility', 'backend', 'Retrieved email from cookie: ' . $email);
            }
            
            // If still empty, try the stored option
            if ( empty( $email ) ) {
                $options = wp_load_alloptions();
                foreach ( $options as $key => $value ) {
                    if ( strpos( $key, 'swsib_temp_email_' ) === 0 ) {
                        $email = get_option( $key );
                        swsib_log('compatibility', 'backend', 'Retrieved email from temp option: ' . $email);
                        break;
                    }
                }
            }
        }
        
        swsib_log('compatibility', 'backend', 'Final email for OTP verification: ' . $email);
        
        if ( empty( $email ) || empty( $otp ) ) {
            swsib_log('compatibility', 'backend', 'OTP verification failed: ' .
                ( empty( $email ) ? 'Empty email' : '' ) .
                ( empty( $otp ) ? 'Empty OTP' : '' ) );
            
            $this->set_error_message( __( 'Please provide both email and verification code.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Check user
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            swsib_log('compatibility', 'backend', 'Email not found during OTP verification: ' . $email);
            $this->set_error_message( __( 'No account found with that email address.', 'swiftspeed-siberian' ) );
            wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
            exit;
        }
        
        // Get stored OTP & expiry
        $stored_otp = get_user_meta( $user->ID, 'swsib_password_sync_otp', true );
        $expiry     = (int) get_user_meta( $user->ID, 'swsib_password_sync_otp_expiry', true );
        
        swsib_log('compatibility', 'backend', 'OTP verification - User ID: ' . $user->ID . 
            ', Stored OTP: ' . $stored_otp . 
            ', Submitted OTP: ' . $otp . 
            ', Expiry: ' . date( 'Y-m-d H:i:s', $expiry ) . 
            ', Current time: ' . date( 'Y-m-d H:i:s' ) );
        
        // Validate
        if ( empty( $stored_otp ) || time() > $expiry ) {
            swsib_log('compatibility', 'backend', 'OTP expired or not found for user: ' . $email);
            
            delete_user_meta( $user->ID, 'swsib_password_sync_otp' );
            delete_user_meta( $user->ID, 'swsib_password_sync_otp_expiry' );
            
            $this->set_error_message( __( 'Verification code expired. Please request a new one.', 'swiftspeed-siberian' ) );
            wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
            exit;
        }
        
        if ( $otp !== $stored_otp ) {
            swsib_log('compatibility', 'backend', 'Invalid OTP provided - User: ' . $email . 
                ', Expected: ' . $stored_otp . ', Received: ' . $otp );
            
            $this->set_error_message( __( 'Invalid verification code. Please try again.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // All good; store a token
        $token = wp_generate_password(32, false);
        update_user_meta( $user->ID, 'swsib_password_sync_token', $token );
        update_option( 'swsib_temp_token_' . md5( $email ), $token, false );
        
        swsib_log('compatibility', 'backend', 'OTP verification successful for user: ' . $email . ' - Redirecting to password step');
        
        $this->set_success_message( __( 'Verification successful! Create a new password.', 'swiftspeed-siberian' ) );
        wp_redirect( add_query_arg( 'swsib_step', 'password', $_SERVER['HTTP_REFERER'] ) );
        exit;
    }

    /**
     * Handle resend OTP (FRONT-END).
     */
    private function handle_resend_otp() {
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        
        swsib_log('compatibility', 'backend', 'OTP resend requested. Initial email: ' . $email);
        
        // Try cookie
        if ( empty( $email ) && isset( $_COOKIE['swsib_email'] ) ) {
            $email = sanitize_email( $_COOKIE['swsib_email'] );
            swsib_log('compatibility', 'backend', 'Retrieved email from cookie for resend: ' . $email);
        }
        
        // If still empty, check stored options
        if ( empty( $email ) ) {
            $options = wp_load_alloptions();
            foreach ( $options as $key => $value ) {
                if ( strpos( $key, 'swsib_temp_email_' ) === 0 ) {
                    $email = get_option( $key );
                    swsib_log('compatibility', 'backend', 'Retrieved email from temp option for resend: ' . $email);
                    break;
                }
            }
            
            if ( empty( $email ) ) {
                swsib_log('compatibility', 'backend', 'Email not found during resend OTP request');
                $this->set_error_message( __( 'Email address not found. Please start over.', 'swiftspeed-siberian' ) );
                wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
                exit;
            }
        }
        
        // Check user
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            swsib_log('compatibility', 'backend', 'User not found during resend OTP: ' . $email);
            $this->set_error_message( __( 'No account found with that email address.', 'swiftspeed-siberian' ) );
            wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
            exit;
        }
        
        // Generate new OTP
        $otp = $this->generate_otp();
        $expiry = time() + ( 15 * 60 );
        update_user_meta( $user->ID, 'swsib_password_sync_otp', $otp );
        update_user_meta( $user->ID, 'swsib_password_sync_otp_expiry', $expiry );
        
        swsib_log('compatibility', 'backend', 'Generated new OTP for resend - User: ' . $email . ' (User ID: ' . $user->ID . ')');
        
        // Resend email
        $site_name = get_bloginfo('name');
        $subject   = sprintf( __('[%s] Your Password Reset Verification Code', 'swiftspeed-siberian'), $site_name );
        
        $message = sprintf( __('Hello %s,', 'swiftspeed-siberian'), $user->display_name ) . "\r\n\r\n";
        $message .= __( 'You requested a password reset. Your verification code is:', 'swiftspeed-siberian' ) . "\r\n\r\n";
        $message .= '<strong>' . $otp . '</strong>' . "\r\n\r\n";
        $message .= __( 'It will expire in 15 minutes.', 'swiftspeed-siberian' ) . "\r\n\r\n";
        $message .= __( 'If you did not request this reset, ignore this email.', 'swiftspeed-siberian' ) . "\r\n\r\n";
        $message .= sprintf( __( 'Regards,' ) . "\r\n" . '%s Team', $site_name );
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
        
        // Send mail
        $result = wp_mail( $user->user_email, $subject, nl2br( $message ), $headers );
        if ( ! $result ) {
            global $phpmailer;
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                swsib_log('compatibility', 'backend', 'PHP Mailer error on resend: ' . $phpmailer->ErrorInfo);
            }
            $this->set_error_message( __( 'Failed to send verification code. Try again later.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        swsib_log('compatibility', 'backend', 'OTP resent successfully to: ' . $email);
        
        // Success
        $this->set_success_message( __( 'New verification code sent! Check your email.', 'swiftspeed-siberian' ) );
        wp_redirect( add_query_arg( 'swsib_step', 'otp', $_SERVER['HTTP_REFERER'] ) );
        exit;
    }
    
    /**
     * Handle password update step (FRONT-END).
     */
    private function handle_password_update() {
        $email            = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $token            = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';
        $password         = isset( $_POST['password'] ) ? $_POST['password'] : '';
        $confirm_password = isset( $_POST['confirm_password'] ) ? $_POST['confirm_password'] : '';
        
        swsib_log('compatibility', 'backend', 'Password update requested. Email: ' . $email);
        
        // Fallback to cookie
        if ( empty( $email ) && isset( $_COOKIE['swsib_email'] ) ) {
            $email = sanitize_email( $_COOKIE['swsib_email'] );
            swsib_log('compatibility', 'backend', 'Retrieved email from cookie for password update: ' . $email);
        }
        
        // If still empty, try options
        if ( empty( $email ) ) {
            $options = wp_load_alloptions();
            foreach ( $options as $key => $value ) {
                if ( strpos( $key, 'swsib_temp_email_' ) === 0 ) {
                    $email = get_option( $key );
                    swsib_log('compatibility', 'backend', 'Retrieved email from temp option for password update: ' . $email);
                    break;
                }
            }
        }
        
        if ( empty( $email ) || empty( $token ) || empty( $password ) || empty( $confirm_password ) ) {
            swsib_log('compatibility', 'backend', 'Password update failed - Missing fields. Email: ' . $email);
            
            $this->set_error_message( __( 'Please fill in all required fields.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Validate password length
        if ( strlen( $password ) < 6 ) {
            swsib_log('compatibility', 'backend', 'Password update failed - Password too short: ' . $email);
            
            $this->set_error_message( __( 'Password must be at least 6 characters long.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        if ( $password !== $confirm_password ) {
            swsib_log('compatibility', 'backend', 'Password update failed - Passwords do not match: ' . $email);
            
            $this->set_error_message( __( 'Passwords do not match.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Check user
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            swsib_log('compatibility', 'backend', 'Email not found during password update: ' . $email);
            
            $this->set_error_message( __( 'No account found with that email address.', 'swiftspeed-siberian' ) );
            wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
            exit;
        }
        
        // Verify token
        $stored_token = get_user_meta( $user->ID, 'swsib_password_sync_token', true );
        $temp_token   = get_option( 'swsib_temp_token_' . md5( $email ) );
        
        swsib_log('compatibility', 'backend', 'Verifying token - User: ' . $email . 
            ', Stored token: ' . substr( $stored_token, 0, 6 ) . '...' .
            ', Temp token: ' . substr( $temp_token, 0, 6 ) . '...' .
            ', Submitted token: ' . substr( $token, 0, 6 ) . '...');
        
        if ( ( empty( $stored_token ) || $token !== $stored_token ) && $token !== $temp_token ) {
            swsib_log('compatibility', 'backend', 'Invalid token for user: ' . $email);
            
            $this->set_error_message( __( 'Invalid request. Please try again.', 'swiftspeed-siberian' ) );
            wp_redirect( remove_query_arg( 'swsib_step', $_SERVER['HTTP_REFERER'] ) );
            exit;
        }
        
        // Check API config
        $siberian_url    = '';
        $api_user        = '';
        $api_password    = '';
        $default_role_id = '2';
        
        if ( isset( $this->options['auto_login'] ) ) {
            $siberian_url    = $this->options['auto_login']['siberian_url']    ?? '';
            $api_user        = $this->options['auto_login']['api_user']        ?? '';
            $api_password    = $this->options['auto_login']['api_password']    ?? '';
            $default_role_id = $this->options['auto_login']['default_role_id'] ?? '2';
        }
        
        if ( empty( $siberian_url ) || empty( $api_user ) || empty( $api_password ) ) {
            swsib_log('compatibility', 'backend', 'API not configured while updating password');
            
            $this->set_error_message( __( 'System integration not configured. Contact administrator.', 'swiftspeed-siberian' ) );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Ensure trailing slash
        $siberian_url = trailingslashit( $siberian_url );
        
        // Check if user exists in Siberian
        $check_result = $this->check_siberian_user_exists( $siberian_url, $api_user, $api_password, $email );
        if ( is_wp_error( $check_result ) ) {
            swsib_log('compatibility', 'backend', 'Error checking Siberian user: ' . $check_result->get_error_message() );
            
            $this->set_error_message( __( 'Error communicating with server: ', 'swiftspeed-siberian' ) . $check_result->get_error_message() );
            wp_redirect( $_SERVER['HTTP_REFERER'] );
            exit;
        }
        
        // Update WP password
        update_user_meta( $user->ID, 'siberian_cms_password', $password );
        update_user_meta( $user->ID, 'swsib_plain_password', $password );
        wp_set_password( $password, $user->ID );
        
        swsib_log('compatibility', 'backend', 'Updated WordPress password for user: ' . $email);
        
        // Update or create in Siberian
        if ( ! empty( $check_result['exists'] ) ) {
            // The user exists in Siberian => update
            $siberian_id   = $check_result['id'];
            $update_result = $this->update_siberian_user( $siberian_url, $api_user, $api_password, $siberian_id, $email, $password, $user->first_name, $user->last_name );
            
            if ( is_wp_error( $update_result ) ) {
                swsib_log('compatibility', 'backend', 'Siberian user update error: ' . $update_result->get_error_message() );
                $this->set_error_message( __( 'Password updated in WP, but failed to update remote system: ', 'swiftspeed-siberian' ) . $update_result->get_error_message() );
                wp_redirect( $_SERVER['HTTP_REFERER'] );
                exit;
            }
            
            swsib_log('compatibility', 'backend', 'Updated Siberian user password - User: ' . $email . ', Siberian ID: ' . $siberian_id);
        } else {
            // The user doesn't exist => create
            $create_result = $this->create_siberian_user( $siberian_url, $api_user, $api_password, $email, $password, $user->first_name, $user->last_name, $default_role_id );
            
            if ( is_wp_error( $create_result ) ) {
                swsib_log('compatibility', 'backend', 'Siberian user creation error: ' . $create_result->get_error_message() );
                $this->set_error_message( __( 'Password updated in WP, but failed to create remote account: ', 'swiftspeed-siberian' ) . $create_result->get_error_message() );
                wp_redirect( $_SERVER['HTTP_REFERER'] );
                exit;
            }
            
            swsib_log('compatibility', 'backend', 'Created new Siberian user - User: ' . $email);
        }
        
        // Cleanup
        delete_user_meta( $user->ID, 'swsib_password_sync_otp' );
        delete_user_meta( $user->ID, 'swsib_password_sync_otp_expiry' );
        delete_user_meta( $user->ID, 'swsib_password_sync_token' );
        delete_option( 'swsib_temp_email_' . md5( $email ) );
        delete_option( 'swsib_temp_token_' . md5( $email ) );
        
        if ( isset( $_COOKIE['swsib_email'] ) ) {
            setcookie( 'swsib_email', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
        }
        
        swsib_log('compatibility', 'backend', 'Password update completed successfully - User: ' . $email);
        
        // Done
        $this->set_success_message( __( 'Password successfully updated!', 'swiftspeed-siberian' ) );
        wp_redirect( add_query_arg( 'swsib_step', 'success', $_SERVER['HTTP_REFERER'] ) );
        exit;
    }
    
    /**
     * Render the password sync form (FRONT-END).
     */
    private function render_password_sync_form( $atts ) {
        $step = isset( $_GET['swsib_step'] ) ? sanitize_text_field( $_GET['swsib_step'] ) : 'email';
        
        $error_message   = get_transient( 'swsib_error_message' );
        $success_message = get_transient( 'swsib_success_message' );
        
        // Log what we're rendering
        $log_message = 'Rendering form step: ' . $step;
        if ( $error_message ) {
            $log_message .= ' [Error: ' . $error_message . ']';
        }
        if ( $success_message ) {
            $log_message .= ' [Success: ' . $success_message . ']';
        }
        swsib_log('compatibility', 'backend', $log_message);
        
        if ( $error_message ) {
            delete_transient( 'swsib_error_message' );
        }
        if ( $success_message ) {
            delete_transient( 'swsib_success_message' );
        }
        
        $form_action = esc_url_raw( add_query_arg( null, null ) );
        $email_from_cookie = isset( $_COOKIE['swsib_email'] ) ? sanitize_email( $_COOKIE['swsib_email'] ) : '';
        ?>
        <div class="swsib-password-sync-container">
            <h2><?php echo esc_html( $atts['title'] ); ?></h2>
            <p class="swsib-form-description"><?php echo esc_html( $atts['description'] ); ?></p>
            
            <div id="swsib-form-messages">
                <?php if ( $error_message ): ?>
                    <div class="swsib-message error"><?php echo esc_html( $error_message ); ?></div>
                <?php endif; ?>
                <?php if ( $success_message ): ?>
                    <div class="swsib-message success"><?php echo esc_html( $success_message ); ?></div>
                <?php endif; ?>
            </div>
            
            <?php if ( $step === 'email' ): ?>
                <!-- Step 1: Email verification -->
                <div class="swsib-form-step" id="swsib-step-email">
                    <h3><?php _e( 'Step 1: Verify Your Email', 'swiftspeed-siberian' ); ?></h3>
                    <form method="post" action="<?php echo esc_url( $form_action ); ?>" id="swsib-email-form">
                        <div class="swsib-form-group">
                            <label for="swsib-email"><?php _e( 'Email Address', 'swiftspeed-siberian' ); ?></label>
                            <input 
                                type="email" 
                                id="swsib-email" 
                                name="email" 
                                required 
                                placeholder="<?php _e( 'Your registered email', 'swiftspeed-siberian' ); ?>" 
                                value="<?php echo esc_attr( $email_from_cookie ); ?>"
                            />
                        </div>
                        <div class="swsib-form-actions">
                            <input type="hidden" name="swsib_form_action" value="verify_email">
                            <button type="submit" id="swsib-verify-email" class="swsib-button" onclick="this.innerHTML='Processing...'; this.disabled=true; this.form.submit();"><?php _e( 'Send Verification Code', 'swiftspeed-siberian' ); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if ( $step === 'otp' ): ?>
                <!-- Step 2: OTP verification -->
                <div class="swsib-form-step" id="swsib-step-otp">
                    <h3><?php _e( 'Step 2: Enter Verification Code', 'swiftspeed-siberian' ); ?></h3>
                    <p class="swsib-otp-instruction"><?php _e( 'A verification code was sent to your email. Check spam too.', 'swiftspeed-siberian' ); ?></p>
                    
                    <?php
                    // Debug info to show what email we're working with
                    $email = $email_from_cookie;
                    if ( empty( $email ) ) {
                        // Look for a stored temp_email
                        $options = wp_load_alloptions();
                        foreach ( $options as $key => $value ) {
                            if ( strpos( $key, 'swsib_temp_email_' ) === 0 ) {
                                $email = get_option( $key );
                                break;
                            }
                        }
                    }
                    swsib_log('compatibility', 'backend', 'OTP form email: ' . $email);
                    ?>
                    
                    <!-- Direct, simple form with absolute minimum complexity -->
                    <form method="post" action="<?php echo esc_url( $form_action ); ?>" id="swsib-otp-form">
                        <div class="swsib-form-group">
                            <label for="swsib-otp"><?php _e( 'Verification Code', 'swiftspeed-siberian' ); ?></label>
                            <input 
                                type="text" 
                                id="swsib-otp" 
                                name="otp" 
                                required 
                                placeholder="<?php _e( '6-digit code', 'swiftspeed-siberian' ); ?>"
                            />
                        </div>
                        
                        <div class="swsib-timer-container">
                            <p class="swsib-timer-text">
                                <?php _e( 'You can request a new code in ', 'swiftspeed-siberian' ); ?>
                                <span id="swsib-resend-timer">60</span>
                                <?php _e( ' seconds', 'swiftspeed-siberian' ); ?>
                            </p>
                        </div>
                        
                        <!-- Simplified resend mechanism - direct link instead of nested form -->
                       <div id="swsib-resend-container" style="display: none; margin-bottom: 20px; text-align: center;">
    <a href="<?php echo esc_url( add_query_arg( array(
        'swsib_resend' => '1',
        'email'        => urlencode( $email )
    ), $form_action ) ); ?>" class="swsib-link" style="text-decoration: none;" onclick="this.innerHTML='Processing...';">
        <?php _e( 'Resend verification code', 'swiftspeed-siberian' ); ?>
    </a>
</div>

                        
                        <div class="swsib-form-actions">
                            <a href="<?php echo esc_url( remove_query_arg( 'swsib_step', $form_action ) ); ?>" class="swsib-button swsib-button-secondary">
                                <?php _e( 'Back', 'swiftspeed-siberian' ); ?>
                            </a>
                            
                            <!-- Essential hidden fields -->
                            <input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
                            <input type="hidden" name="swsib_form_action" value="verify_otp">
                            
                            <!-- Direct submit button with inline onclick for extra assurance -->
                            <button type="submit" id="swsib-verify-otp" class="swsib-button" onclick="this.innerHTML='Processing...'; this.disabled=true; this.form.submit();">
                                <?php _e( 'Verify Code', 'swiftspeed-siberian' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
                <!-- Inline JavaScript for OTP countdown timer -->
                <script>
                document.addEventListener("DOMContentLoaded", function () {
                    var timerEl = document.getElementById('swsib-resend-timer');
                    var resendContainer = document.getElementById('swsib-resend-container');
                    var timerText = document.querySelector('.swsib-timer-text');
                    if (timerEl && resendContainer && timerText) {
                        var count = 60;
                        timerEl.innerText = count;
                        var timerInterval = setInterval(function(){
                            count--;
                            timerEl.innerText = count;
                            if (count <= 0) {
                                clearInterval(timerInterval);
                                timerText.style.display = "none";
                                resendContainer.style.display = "block";
                            }
                        }, 1000);
                    }
                });
                </script>
            <?php endif; ?>
            
            <?php if ( $step === 'password' ): ?>
                <!-- Step 3: Password update -->
                <div class="swsib-form-step" id="swsib-step-password">
                    <h3><?php _e( 'Step 3: Create New Password', 'swiftspeed-siberian' ); ?></h3>
                    <p class="swsib-password-instruction"><?php _e( 'Create a new password for your account.', 'swiftspeed-siberian' ); ?></p>
                    <form method="post" action="<?php echo esc_url( $form_action ); ?>" id="swsib-password-form">
                        <div class="swsib-form-group">
                            <label for="swsib-password"><?php _e( 'New Password', 'swiftspeed-siberian' ); ?></label>
                            <input 
                                type="password" 
                                id="swsib-password" 
                                name="password" 
                                required 
                                placeholder="<?php _e( 'Minimum 6 characters', 'swiftspeed-siberian' ); ?>"
                            />
                            <div class="swsib-password-requirements">
                                <p><?php _e( 'Password must be at least 6 characters', 'swiftspeed-siberian' ); ?></p>
                            </div>
                        </div>
                        <div class="swsib-form-group">
                            <label for="swsib-confirm-password"><?php _e( 'Confirm Password', 'swiftspeed-siberian' ); ?></label>
                            <input 
                                type="password" 
                                id="swsib-confirm-password" 
                                name="confirm_password" 
                                required 
                                placeholder="<?php _e( 'Confirm your password', 'swiftspeed-siberian' ); ?>"
                            />
                        </div>
                        <div class="swsib-form-actions">
                            <?php
                            $email = $email_from_cookie;
                            $token = '';
                            
                            if ( empty( $email ) ) {
                                $options = wp_load_alloptions();
                                foreach ( $options as $key => $value ) {
                                    if ( strpos( $key, 'swsib_temp_email_' ) === 0 ) {
                                        $email = get_option( $key );
                                    }
                                    if ( strpos( $key, 'swsib_temp_token_' ) === 0 ) {
                                        $token = get_option( $key );
                                    }
                                }
                            } else {
                                $token = get_option( 'swsib_temp_token_' . md5( $email ) );
                            }
                            ?>
                            <input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
                            <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                            <input type="hidden" name="swsib_form_action" value="update_password">
                            <button type="submit" id="swsib-update-password" class="swsib-button" onclick="this.innerHTML='Processing...'; this.disabled=true; this.form.submit();"><?php _e( 'Update Password', 'swiftspeed-siberian' ); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if ( $step === 'success' ): ?>
                <!-- Step 4: Success -->
                <div class="swsib-form-step" id="swsib-step-success">
                    <div class="swsib-success-message">
                        <div class="swsib-success-icon">✓</div>
                        <h3><?php _e( 'Password Updated Successfully!', 'swiftspeed-siberian' ); ?></h3>
                        <p><?php _e( 'Your password has been updated successfully.', 'swiftspeed-siberian' ); ?></p>
                        <p><?php _e( 'You can now log in with your new password.', 'swiftspeed-siberian' ); ?></p>
                    </div>
                    <div class="swsib-form-actions">
                        <a href="<?php echo esc_url( wp_login_url() ); ?>" class="swsib-button"><?php _e( 'Go to Login', 'swiftspeed-siberian' ); ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Set an error message in a transient.
     */
    private function set_error_message( $message ) {
        set_transient( 'swsib_error_message', $message, 60 ); // 1 minute
        swsib_log('compatibility', 'backend', 'Set error message: ' . $message);
    }
    
    /**
     * Set a success message in a transient.
     */
    private function set_success_message( $message ) {
        set_transient( 'swsib_success_message', $message, 60 ); // 1 minute
        swsib_log('compatibility', 'backend', 'Set success message: ' . $message);
    }
    
    /**
     * Generate a 6-digit OTP
     */
    private function generate_otp() {
        return sprintf('%06d', mt_rand(100000, 999999));
    }
    
    /**
     * Send the OTP email (uses a simpler approach).
     */
    private function send_otp_email( $user, $otp ) {
        $site_name = get_bloginfo('name');
        $subject   = sprintf( __('[%s] Your Password Reset Verification Code', 'swiftspeed-siberian'), $site_name );
        
        $message  = sprintf( __('Hello %s,', 'swiftspeed-siberian'), $user->display_name ) . "\r\n\r\n";
        $message .= __('You recently requested to reset your password.', 'swiftspeed-siberian') . "\r\n\r\n";
        $message .= __('Your verification code is:', 'swiftspeed-siberian') . "\r\n\r\n";
        $message .= '<strong>' . $otp . '</strong>' . "\r\n\r\n";
        $message .= __('This code will expire in 15 minutes.', 'swiftspeed-siberian') . "\r\n\r\n";
        $message .= __('If you did not request this, ignore it or contact the administrator.', 'swiftspeed-siberian') . "\r\n\r\n";
        $message .= sprintf( __('Regards,') . "\r\n" . '%s Team', $site_name );
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
        
        $result = wp_mail( $user->user_email, $subject, nl2br( $message ), $headers );
        if ( ! $result ) {
            global $phpmailer;
            if ( ! empty( $phpmailer->ErrorInfo ) ) {
                // Log the actual mailer error
                swsib_log('compatibility', 'backend', 'PHP Mailer Error: ' . $phpmailer->ErrorInfo);
            }
            return new WP_Error( 'email_failed', __( 'Failed to send email', 'swiftspeed-siberian' ) );
        }
        
        return true;
    }
    
    /**
     * Check if user exists in Siberian
     */
    private function check_siberian_user_exists( $siberian_url, $api_user, $api_password, $email ) {
        $exist_endpoint = $siberian_url . 'admin/api_account/exist';
        
        swsib_log('compatibility', 'backend', 'Checking if user exists in Siberian - Email: ' . $email . ', API Endpoint: ' . $exist_endpoint);
        
        $response = wp_remote_post( $exist_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_user . ':' . $api_password )
            ),
            'body' => array( 'email' => $email )
        ));
        
        if ( is_wp_error( $response ) ) {
            swsib_log('compatibility', 'backend', 'WP Error checking user existence in Siberian: ' . $response->get_error_message() );
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        swsib_log('compatibility', 'backend', 'Siberian API response - Code: ' . $response_code . ', Body: ' . $response_body);
        
        if ( $response_code !== 200 ) {
            return new WP_Error( 'api_error', 'API returned code ' . $response_code );
        }
        
        $data = json_decode( $response_body, true );
        if ( ! $data ) {
            return new WP_Error( 'invalid_response', 'Invalid response from API' );
        }
        
        if ( ! empty( $data['error'] ) ) {
            $error_message = $data['message'] ?? 'Unknown API error';
            return new WP_Error( 'api_error', $error_message );
        }
        
        swsib_log('compatibility', 'backend', 'User existence check result - Exists: ' . 
            ( ! empty( $data['exists'] ) ? 'Yes' : 'No' ) . 
            ( ! empty( $data['id'] ) ? ', ID: ' . $data['id'] : '' ) );
        
        return array(
            'exists' => ! empty( $data['exists'] ),
            'id'     => $data['id'] ?? null
        );
    }
    
    /**
     * Update an existing Siberian user
     */
    private function update_siberian_user( $siberian_url, $api_user, $api_password, $user_id, $email, $password, $firstname, $lastname ) {
        $update_endpoint = $siberian_url . 'admin/api_account/update';
        
        swsib_log('compatibility', 'backend', 'Updating Siberian user - Email: ' . $email . ', User ID: ' . $user_id . ', API Endpoint: ' . $update_endpoint);
        
        $response = wp_remote_post( $update_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_user . ':' . $api_password )
            ),
            'body' => array(
                'user_id'   => $user_id,
                'email'     => $email,
                'password'  => $password,
                'firstname' => $firstname,
                'lastname'  => $lastname
            )
        ));
        
        if ( is_wp_error( $response ) ) {
            swsib_log('compatibility', 'backend', 'WP Error updating Siberian user: ' . $response->get_error_message() );
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        swsib_log('compatibility', 'backend', 'Siberian API update response - Code: ' . $response_code . ', Body: ' . $response_body);
        
        if ( $response_code !== 200 ) {
            return new WP_Error( 'api_error', 'API returned code ' . $response_code );
        }
        
        $data = json_decode( $response_body, true );
        if ( ! $data ) {
            return new WP_Error( 'invalid_response', 'Invalid response from API' );
        }
        if ( ! empty( $data['error'] ) ) {
            $error_message = $data['message'] ?? 'Unknown API error';
            return new WP_Error( 'api_error', $error_message );
        }
        
        return $data;
    }
    
    /**
     * Create a new Siberian user
     */
    private function create_siberian_user( $siberian_url, $api_user, $api_password, $email, $password, $firstname, $lastname, $role_id = '2' ) {
        $create_endpoint = $siberian_url . 'admin/api_account/create';
        
        swsib_log('compatibility', 'backend', 'Creating new Siberian user - Email: ' . $email . ', Role ID: ' . $role_id . ', API Endpoint: ' . $create_endpoint);
        
        $response = wp_remote_post( $create_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_user . ':' . $api_password )
            ),
            'body' => array(
                'role_id'   => $role_id,
                'email'     => $email,
                'password'  => $password,
                'firstname' => $firstname,
                'lastname'  => $lastname
            )
        ));
        
        if ( is_wp_error( $response ) ) {
            swsib_log('compatibility', 'backend', 'WP Error creating Siberian user: ' . $response->get_error_message() );
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        
        swsib_log('compatibility', 'backend', 'Siberian API create response - Code: ' . $response_code . ', Body: ' . $response_body);
        
        if ( $response_code !== 200 ) {
            return new WP_Error( 'api_error', 'API returned code ' . $response_code );
        }
        
        $data = json_decode( $response_body, true );
        if ( ! $data ) {
            return new WP_Error( 'invalid_response', 'Invalid response from API' );
        }
        if ( ! empty( $data['error'] ) ) {
            $error_message = $data['message'] ?? 'Unknown API error';
            return new WP_Error( 'api_error', $error_message );
        }
        
        return $data;
    }
}
