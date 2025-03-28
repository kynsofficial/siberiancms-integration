<?php
/**
 * Shortcode functionality for the plugin.
 */
class SwiftSpeed_Siberian_Shortcodes {
    
    /**
     * Initialize the class
     */
    public function __construct() {
        
        // Register shortcodes
        add_shortcode('swsib_login', array($this, 'login_shortcode'));
        add_shortcode('swiftspeedsiberiancms', array($this, 'legacy_login_shortcode')); // For backward compatibility
        
    }

    /**
     * Log messages to central logging system
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('auto_login', 'frontend', $message);
        }
    }
    
   /**
 * Login shortcode
 */
public function login_shortcode($atts) {
    
    // Get the plugin options
    $options = get_option('swsib_options', array());
    
    // Check if auto-authenticate is enabled
    $auto_authenticate = isset($options['auto_login']['auto_authenticate']) ? $options['auto_login']['auto_authenticate'] : false;
    
    // Get role sync setting
    $sync_existing_role = isset($options['auto_login']['sync_existing_role']) ? $options['auto_login']['sync_existing_role'] : false;
    
    // Get processing screen settings if auto-authenticate is enabled
    if ($auto_authenticate) {
        $processing_text = isset($options['auto_login']['processing_text']) ? $options['auto_login']['processing_text'] : 'Processing...';
        $processing_bg_color = isset($options['auto_login']['processing_bg_color']) ? $options['auto_login']['processing_bg_color'] : '#f5f5f5';
        $processing_text_color = isset($options['auto_login']['processing_text_color']) ? $options['auto_login']['processing_text_color'] : '#333333';
        
        // Start auto authentication if user is logged in
        if (is_user_logged_in()) {
            
            // Log authentication process
            $this->log_message('Auto-authenticate enabled, showing processing screen and initiating authentication');
            
            // Generate processing screen HTML
            $html = $this->generate_processing_screen($processing_text, $processing_bg_color, $processing_text_color);
            
            // Add JavaScript to initiate authentication
            $auth_url = add_query_arg(array(
                'swsib_auth' => '1',
                'swsib_sync_role' => $sync_existing_role ? '1' : '0'
            ), home_url('/'));
            
            $html .= '<script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Redirect to authentication URL after a slight delay
                    setTimeout(function() {
                        window.location.href = "' . esc_url($auth_url) . '";
                    }, 1000);
                });
            </script>';
            
            return $html;
        }
    }
    
    // If auto-authentication is disabled or user is not logged in, display the button
    // Set default colors from options
    $default_color = isset($options['auto_login']['button_color']) ? $options['auto_login']['button_color'] : '#3a4b79';
    $default_text_color = isset($options['auto_login']['button_text_color']) ? $options['auto_login']['button_text_color'] : '#ffffff';
    
    $atts = shortcode_atts(array(
        'text' => '',
        'class' => '',
        'redirect' => '',
        'style' => 'default', // default, primary, secondary, success
        'color' => $default_color, // Custom color attribute
        'textcolor' => $default_text_color, // Custom text color attribute
    ), $atts, 'swsib_login');
    
    // Get public class instance
    $public = new SwiftSpeed_Siberian_Public();
    
    // Add style class if provided
    $class = $atts['class'];
    if (!empty($atts['style']) && $atts['style'] !== 'default') {
        $class .= ' swsib-button-' . $atts['style'];
    }
    
    // Specifically override colors with shortcode attributes if provided
    $color = $atts['color'];
    $text_color = $atts['textcolor'];
    
    $this->log_message('Generating login button with text: ' . ($atts['text'] ? $atts['text'] : 'default') . 
                      ', background color: ' . $color . ', and text color: ' . $text_color);
    
    // Generate button HTML
    $button_html = $public->generate_autologin_button(
        $atts['text'],
        $class,
        $atts['redirect'],
        $color,
        $text_color
    );
    
    // Add the sync_role parameter to the URL if sync_existing_role is enabled
    if ($sync_existing_role) {
        $button_html = str_replace(
            'swsib_auth=1', 
            'swsib_auth=1&swsib_sync_role=1', 
            $button_html
        );
    }
    
    return $button_html;
}

    /**
     * Generate processing screen HTML
     */
    private function generate_processing_screen($text, $bg_color, $text_color) {
        $html = '<style>
            .swsib-processing-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: ' . esc_attr($bg_color) . ';
                color: ' . esc_attr($text_color) . ';
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
            }
            .swsib-processing-content {
                text-align: center;
            }
            .swsib-processing-text {
                display: block;
                font-size: 24px;
                font-weight: 500;
                margin-bottom: 20px;
            }
            .swsib-processing-spinner {
                display: inline-block;
                width: 50px;
                height: 50px;
                border: 5px solid rgba(0,0,0,0.1);
                border-radius: 50%;
                border-top-color: #3498db;
                animation: swsib-spin 1s ease-in-out infinite;
            }
            @keyframes swsib-spin {
                to { transform: rotate(360deg); }
            }
        </style>
        <div class="swsib-processing-overlay">
            <div class="swsib-processing-content">
                <span class="swsib-processing-text">' . esc_html($text) . '</span>
                <span class="swsib-processing-spinner"></span>
            </div>
        </div>';
        
        return $html;
    }

    /**
     * Legacy login shortcode (for backward compatibility)
     */
    public function legacy_login_shortcode($atts) {
        
        $atts = shortcode_atts(array(
            'loginText' => '',
            'redirect' => ''
        ), $atts, 'swiftspeedsiberiancms');
        
        // Convert legacy attributes to new format
        return $this->login_shortcode(array(
            'text' => $atts['loginText'],
            'redirect' => $atts['redirect']
        ));
    }
}