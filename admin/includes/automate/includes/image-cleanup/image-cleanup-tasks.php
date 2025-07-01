<?php
/**
 * Image Cleanup - Task execution
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Image_Tasks {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Data handler
     */
    private $data;
    
    /**
     * Is background processing
     */
    private $is_background_processing = false;
    
    /**
     * Maximum batches to process in a single run
     */
    private $max_batches_per_run = 10;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null, $data = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
        $this->data = $data;
    }
    
    /**
     * Log message
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * AJAX handler for cleaning up orphaned image folders
     * This is the entry point when clicking the Run Cleanup button
     */
    public function ajax_cleanup_images() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get mode (start, continue, status)
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'start';
        
        // For starting a new task
        if ($mode === 'start') {
            $this->log_message("Starting new image cleanup task in batch mode");
            
            // Initialize the task with batch processing
            $result = $this->data->initialize_task('cleanup');
            
            if (!$result) {
                $this->log_message("Failed to initialize image cleanup task");
                wp_send_json_error(array('message' => 'Failed to initialize image cleanup task'));
                return;
            }
            
            // Process the first batch to get things started
            $batch_result = $this->data->process_batch('cleanup', 0);
            
            // Trigger a loopback request to continue processing in the background if not completed
            if ($batch_result['success'] && !$batch_result['completed']) {
                $this->trigger_loopback_request('cleanup');
            }
            
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => 'Image cleanup task started',
                    'progress' => $batch_result['progress'],
                    'next_batch' => $batch_result['next_batch'],
                    'completed' => $batch_result['completed'],
                    'background_enabled' => true,
                    'is_running' => !$batch_result['completed']
                ));
            } else {
                wp_send_json_error(array('message' => $batch_result['message']));
            }
            return;
        } else if ($mode === 'legacy') {
            // Legacy direct processing (for backward compatibility)
            $this->legacy_process_orphaned_folders();
            return;
        }
        
        wp_send_json_error(array('message' => 'Invalid mode'));
    }
    
    /**
     * AJAX handler for getting cleanup progress
     */
    public function ajax_get_cleanup_progress() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get task ID from the request
        $task_type = isset($_POST['task_type']) ? sanitize_text_field($_POST['task_type']) : 'cleanup';
        
        // Get progress data from data handler
        $progress_data = $this->data->get_progress_data($task_type);
        
        $this->log_message("Received image cleanup progress request for task_type: {$task_type}");
        
        // Add background processing information
        if (isset($progress_data['status']) && $progress_data['status'] === 'running') {
            $progress_data['is_running'] = true;
            $progress_data['background_enabled'] = true;
            $progress_data['heartbeat_age'] = isset($progress_data['last_update']) ? time() - $progress_data['last_update'] : 0;
        }
        
        // If the task is running but hasn't been updated recently, check if we need to restart
        if (isset($progress_data['status']) && $progress_data['status'] === 'running' && 
            isset($progress_data['last_update']) && (time() - $progress_data['last_update']) > 60) {
            // Trigger a new loopback request
            $this->ensure_background_processing($task_type);
        }
        
        if (isset($progress_data['progress']) && isset($progress_data['processed']) && isset($progress_data['total'])) {
            $this->log_message("Returning image cleanup progress data: progress={$progress_data['progress']}, processed={$progress_data['processed']}, total={$progress_data['total']}, logs=" . count($progress_data['logs']));
        }
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * AJAX handler for processing image cleanup batch
     */
    public function ajax_process_image_batch() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib-automate-nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            return;
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            return;
        }
        
        // Get batch index
        $batch_index = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
        
        // Get task type
        $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : 'cleanup';
        
        // Process the batch
        $batch_result = $this->data->process_batch($task, $batch_index);
        
        // Trigger a loopback request to continue processing in the background if not completed and not a background process
        if ($batch_result['success'] && !$batch_result['completed'] && !$this->is_background_processing) {
            $this->trigger_loopback_request($task);
        }
        
        // Return the result
        if ($batch_result['success']) {
            wp_send_json_success(array(
                'message' => 'Batch processed',
                'progress' => $batch_result['progress'],
                'next_batch' => $batch_result['next_batch'],
                'completed' => $batch_result['completed'],
                'background_enabled' => true,
                'is_running' => !$batch_result['completed']
            ));
        } else {
            wp_send_json_error(array('message' => $batch_result['message']));
        }
    }
    
    /**
     * Ensure background processing is continuing for a task
     */
    private function ensure_background_processing($task) {
        $progress_file = $this->data->get_progress_file($task);
        if (!file_exists($progress_file)) {
            return false;
        }
        
        $progress_data = json_decode(file_get_contents($progress_file), true);
        if (!$progress_data || !isset($progress_data['status']) || $progress_data['status'] !== 'running') {
            return false;
        }
        
        // Check if the last update was less than 1 minute ago - if so, assume it's still running
        if (isset($progress_data['last_update']) && (time() - $progress_data['last_update']) < 60) {
            return true;
        }
        
        // If it's been more than 1 minute, trigger a new loopback request
        $this->log_message("Restarting background processing for task: $task");
        $this->trigger_loopback_request($task);
        
        return true;
    }
    
    /**
     * Trigger a loopback request to continue processing in the background
     */
    private function trigger_loopback_request($task) {
        // Create a specific persistent nonce key for the task
        $nonce_action = 'swsib_image_cleanup_loopback_' . $task;
        $nonce = wp_create_nonce($nonce_action);
        
        // Store the nonce in an option for validation later
        update_option('swsib_image_loopback_nonce_' . $task, $nonce, false);
        
        $url = admin_url('admin-ajax.php?action=swsib_image_cleanup_loopback&task=' . urlencode($task) . '&nonce=' . $nonce);
        
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array('Cache-Control' => 'no-cache'),
        );
        
        $this->log_message("Triggering loopback request for task: $task");
        wp_remote_get($url, $args);
    }
    
    /**
     * Legacy direct processing method (kept for backward compatibility)
     */
    private function legacy_process_orphaned_folders() {
        $app_ids = $this->data->get_application_ids();
        if ($app_ids === false) {
            wp_send_json_error(array('message' => 'Failed to get application IDs from database'));
            return;
        }
        
        $installation_path = $this->data->get_installation_path();
        if (empty($installation_path)) {
            wp_send_json_error(array('message' => 'Installation path not configured'));
            return;
        }
        
        $image_folders = $this->data->get_image_folders($installation_path);
        if ($image_folders === false) {
            wp_send_json_error(array('message' => 'Failed to access image folders'));
            return;
        }
        
        // Generate a unique task ID
        $task_id = 'image_cleanup_' . uniqid();
        
        // Get orphaned folders
        $orphaned_folders = array();
        $skipped = 0;
        foreach ($image_folders as $folder) {
            if (is_numeric($folder) && !in_array($folder, $app_ids)) {
                $orphaned_folders[] = $folder;
            } else if (!is_numeric($folder)) {
                $skipped++;
            }
        }
        
        if (count($orphaned_folders) === 0) {
            wp_send_json_success(array(
                'message' => 'No orphaned folders found',
                'deleted' => 0,
                'skipped' => $skipped
            ));
            return;
        }
        
        // Process each folder
        $deleted = 0;
        $errors = 0;
        
        $options = get_option('swsib_options', array());
        $installation = isset($options['installation']) ? $options['installation'] : array();
        $connection_method = isset($installation['connection_method']) ? $installation['connection_method'] : 'ftp';
        
        // Base image application path
        $image_app_path = rtrim($installation_path, '/') . '/images/application';
        
        foreach ($orphaned_folders as $folder) {
            $folder_path = $image_app_path . '/' . $folder;
            
            // Delete the folder based on connection method
            if ($connection_method === 'ftp') {
                $result = $this->data->delete_folder_ftp($folder_path, $options);
            } else {
                $result = $this->data->delete_folder_local($folder_path);
            }
            
            if ($result['success']) {
                $deleted++;
            } else {
                $errors++;
            }
        }
        
        // Record cleanup results
        $this->data->record_cleanup_results($deleted, $errors, $skipped);
        
        wp_send_json_success(array(
            'message' => "Successfully deleted $deleted orphaned folders with $errors errors. Skipped $skipped non-application folders.",
            'deleted' => $deleted,
            'errors' => $errors,
            'skipped' => $skipped
        ));
    }
    
    /**
     * Handle scheduled task (called from cron)
     * Now delegates to the main class for batch processing
     */
    public function handle_scheduled_task($args = array()) {
        $this->log_message("Running scheduled image cleanup task");
        
        // For compatibility with the scheduler, we'll call the handle_scheduled_task method in the main class
        if (function_exists('swsib') && isset(swsib()->image_cleanup)) {
            return swsib()->image_cleanup->handle_scheduled_task('image_cleanup', array('task' => 'cleanup'));
        }
        
        $this->log_message("Main image cleanup class not found, using direct task execution");
        
        // Initialize the task
        $result = $this->data->initialize_task('cleanup');
        
        if (!$result) {
            $this->log_message("Failed to initialize scheduled image cleanup task");
            return false;
        }
        
        // Process all batches
        $batch_index = 0;
        $completed = false;
        
        while (!$completed) {
            $batch_result = $this->data->process_batch('cleanup', $batch_index);
            
            if (!$batch_result['success']) {
                $this->log_message("Failed to process batch $batch_index: " . $batch_result['message']);
                return false;
            }
            
            $completed = $batch_result['completed'];
            $batch_index = $batch_result['next_batch'];
            
            // Add a small delay to prevent server overload
            if (!$completed) {
                usleep(100000); // 100ms delay
            }
        }
        
        $this->log_message("Completed scheduled image cleanup task");
        return true;
    }
}