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
    
    // Set default color from options
    $default_color = isset($options['auto_login']['button_color']) ? $options['auto_login']['button_color'] : '#3a4b79';
    
    $atts = shortcode_atts(array(
        'text' => '',
        'class' => '',
        'redirect' => '',
        'style' => 'default', // default, primary, secondary, success
        'color' => $default_color, // Custom color attribute
    ), $atts, 'swsib_login');
    
    // Get public class instance
    $public = new SwiftSpeed_Siberian_Public();
    
    // Add style class if provided
    $class = $atts['class'];
    if (!empty($atts['style']) && $atts['style'] !== 'default') {
        $class .= ' swsib-button-' . $atts['style'];
    }
    
    // Specifically override color with shortcode attribute if provided
    $color = $atts['color'];
    
    $this->log_message('Generating login button with text: ' . ($atts['text'] ? $atts['text'] : 'default') . ' and color: ' . $color);
    
    // Generate and return button HTML
    return $public->generate_autologin_button(
        $atts['text'],
        $class,
        $atts['redirect'],
        $color
    );
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