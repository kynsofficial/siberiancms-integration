<?php
/**
 * Advanced shortcode functionality for the plugin.
 */
class SwiftSpeed_Siberian_Advanced_Shortcodes {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        
        // Get plugin options
        $this->options = get_option('swsib_options', array());
        
        // Register shortcodes
        add_shortcode('swsib_advanced_login', array($this, 'advanced_login_shortcode'));
    }

    /**
     * Log messages to central logging system
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('autologin_advanced', 'frontend', $message);
        }
    }
    
    /**
     * Advanced login shortcode with role-specific assignment
     */
    public function advanced_login_shortcode($atts) {
        // Check if advanced auto login is enabled
        $advanced_autologin_options = isset($this->options['advanced_autologin']) ? $this->options['advanced_autologin'] : array();
        $enabled = isset($advanced_autologin_options['enabled']) ? $advanced_autologin_options['enabled'] : false;
        
        if (!$enabled) {
            $this->log_message('Advanced Auto Login is disabled. Shortcode requested but not processed.');
            return '<div class="swsib-disabled">Advanced Auto Login is disabled. Please enable it in plugin settings.</div>';
        }
        
        // Parse attributes
        $atts = shortcode_atts(array(
            'id' => '',
            'class' => '',
        ), $atts, 'swsib_advanced_login');
        
        // Check if button ID is provided
        if (empty($atts['id'])) {
            $this->log_message('Missing button ID in advanced auto login shortcode');
            return '<div class="swsib-error">Button ID is required for advanced auto login shortcode.</div>';
        }
        
        // Get button data
        $buttons = isset($advanced_autologin_options['buttons']) ? $advanced_autologin_options['buttons'] : array();
        
        if (!isset($buttons[$atts['id']])) {
            $this->log_message('Invalid button ID in advanced auto login shortcode: ' . $atts['id']);
            return '<div class="swsib-error">Invalid button ID. Please check your shortcode.</div>';
        }
        
        $button = $buttons[$atts['id']];
        
        // Get button properties
        $text = $button['text'];
        $role_id = $button['role_id'];
        $color = $button['color'];
        $text_color = $button['text_color'];
        $sync_existing_role = isset($button['sync_existing_role']) && $button['sync_existing_role'] ? '1' : '0';
        
        // Add classes
        $class = 'swsib-advanced-button ' . trim($atts['class']);
        
        // Get public class instance
        $public = new SwiftSpeed_Siberian_Public();
        
        // Generate and return button HTML with custom role ID
        // We'll use a special URL parameter to identify the button ID for role assignment
        // Explicitly pass the text from our button configuration to ensure it's used
        $button_html = $public->generate_autologin_button(
            $text,  // Use our button text, not the default
            $class,
            '',  // No specific redirect
            $color,
            $text_color
        );
        
        // Modify the URL to include the button ID, role, and sync_role flag
        $button_html = str_replace(
            'swsib_auth=1', 
            'swsib_auth=1&swsib_btn=' . urlencode($atts['id']) . '&swsib_role=' . urlencode($role_id) . '&swsib_sync_role=' . $sync_existing_role, 
            $button_html
        );
        
        return $button_html;
    }
}