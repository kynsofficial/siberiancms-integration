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
            
            if ($batch_result['success']) {
                wp_send_json_success(array(
                    'message' => 'Image cleanup task started',
                    'progress' => $batch_result['progress'],
                    'next_batch' => $batch_result['next_batch'],
                    'completed' => $batch_result['completed']
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
        
        // Return the result
        if ($batch_result['success']) {
            wp_send_json_success(array(
                'message' => 'Batch processed',
                'progress' => $batch_result['progress'],
                'next_batch' => $batch_result['next_batch'],
                'completed' => $batch_result['completed']
            ));
        } else {
            wp_send_json_error(array('message' => $batch_result['message']));
        }
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
     */
    public function handle_scheduled_task($args = array()) {
        $this->log_message("Running scheduled image cleanup task");
        
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
