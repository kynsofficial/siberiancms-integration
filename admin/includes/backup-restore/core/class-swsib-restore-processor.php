<?php
/**
 * Restore Processor component.
 * Handles restore-related AJAX endpoints and processing.
 * 
 * @since 2.3.0
 */
class SwiftSpeed_Siberian_Restore_Processor {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Restore instance.
     * 
     * @var SwiftSpeed_Siberian_Restore
     */
    private $restore;
    
    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Initialize components
        $this->init_components();
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
    }
    
    /**
     * Initialize components.
     *
     * @return void
     */
    private function init_components() {
        $this->restore = new SwiftSpeed_Siberian_Restore();
    }
    
    /**
     * Register AJAX handlers.
     *
     * @return void
     */
    private function register_ajax_handlers() {
        // Restore handlers
        add_action('wp_ajax_swsib_start_restore', array($this, 'ajax_start_restore'));
        add_action('wp_ajax_swsib_process_restore_step', array($this, 'ajax_process_restore_step'));
        add_action('wp_ajax_swsib_restore_progress', array($this, 'ajax_restore_progress'));
        add_action('wp_ajax_swsib_cancel_restore', array($this, 'ajax_cancel_restore'));
    }
    
    /**
     * AJAX handler for starting a restore process.
     * 
     * @return void
     */
    public function ajax_start_restore() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get backup ID and storage from request
        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $storage = isset($_POST['storage']) ? sanitize_text_field($_POST['storage']) : 'local';
        
        if (empty($backup_id)) {
            wp_send_json_error(array('message' => __('No backup ID provided', 'swiftspeed-siberian')));
        }
        
        // Get backup history
        $history = get_option('swsib_backup_history', array());
        
        if (!isset($history[$backup_id])) {
            wp_send_json_error(array('message' => __('Backup not found in history', 'swiftspeed-siberian')));
        }
        
        $backup = $history[$backup_id];
        
        // Start the restore process
        $result = $this->restore->start_restore($backup);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for processing the next restore step.
     * Unlike backup which uses background processing server-side,
     * restore uses AJAX-driven step processing to mirror the UI pattern.
     * 
     * @return void
     */
    public function ajax_process_restore_step() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current restore status
        $status = get_option('swsib_current_restore', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active restore process found', 'swiftspeed-siberian')));
        }
        
        // Process the next step - this now handles multiple steps like backup does
        $result = $this->restore->process_next_step($status);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX handler for checking restore progress.
     * 
     * @return void
     */
    public function ajax_restore_progress() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current restore status
        $status = get_option('swsib_current_restore', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active restore process found', 'swiftspeed-siberian')));
        }
        
        // Add elapsed time
        if (isset($status['started'])) {
            $status['elapsed_time'] = time() - $status['started'];
        }
        
        wp_send_json_success($status);
    }
    
    /**
     * AJAX handler for canceling a restore process.
     * 
     * @return void
     */
    public function ajax_cancel_restore() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get current restore status
        $status = get_option('swsib_current_restore', array());
        
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active restore process found', 'swiftspeed-siberian')));
        }
        
        // Cancel the restore
        $result = $this->restore->cancel_restore($status);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('Restore canceled successfully', 'swiftspeed-siberian')));
    }
    
    /**
     * Log a message for debugging.
     * 
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'restore', $message);
        }
    }
}