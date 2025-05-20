<?php
/**
 * Image Folder Cleanup Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Image_Cleanup {
    
    /**
     * Database connection
     */
    public $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Data handler
     */
    public $data;
    
    /**
     * Tasks handler
     */
    private $tasks;
    
    /**
     * Settings handler
     */
    private $settings;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
        
        // Get database connection from options if not provided
        if (!$this->db_connection) {
            $options = get_option('swsib_options', array());
            $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
            
            if (!empty($db_options['host']) && !empty($db_options['database']) && 
                !empty($db_options['username']) && !empty($db_options['password'])) {
                
                $db_connection = new mysqli(
                    $db_options['host'],
                    $db_options['username'],
                    $db_options['password'],
                    $db_options['database'],
                    isset($db_options['port']) ? intval($db_options['port']) : 3306
                );
                
                if (!$db_connection->connect_error) {
                    $this->db_connection = $db_connection;
                    $this->db_name = $db_options['database'];
                    $this->log_message("Database connection established in constructor");
                } else {
                    $this->log_message("Database connection failed: " . $db_connection->connect_error);
                }
            }
        }
        
        // Include required files
        $this->include_files();
        
        // Initialize components
        $this->data = new SwiftSpeed_Siberian_Image_Data($this->db_connection, $this->db_name);
        $this->tasks = new SwiftSpeed_Siberian_Image_Tasks($this->db_connection, $this->db_name, $this->data);
        $this->settings = new SwiftSpeed_Siberian_Image_Settings($this->db_connection, $this->db_name, $this->data);
        
        // Register AJAX handlers
        $this->register_ajax_handlers();
        
        // Add direct handler for task execution
        add_action('swsib_run_scheduled_task', array($this, 'handle_scheduled_task'), 10, 2);
    }
    
    /**
     * Include required files
     */
    private function include_files() {
        $dir = plugin_dir_path(__FILE__);
        $include_dir = dirname($dir) . '/image-cleanup/';
        
        // Create directory if it doesn't exist
        if (!file_exists($include_dir)) {
            wp_mkdir_p($include_dir);
        }
        
        require_once($include_dir . 'image-cleanup-data.php');
        require_once($include_dir . 'image-cleanup-tasks.php');
        require_once($include_dir . 'image-cleanup-settings.php');
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Count AJAX handler
        add_action('wp_ajax_swsib_get_orphaned_images_count', array($this->data, 'ajax_get_orphaned_images_count'));
        
        // Task execution AJAX handlers
        add_action('wp_ajax_swsib_cleanup_images', array($this->tasks, 'ajax_cleanup_images'));
        add_action('wp_ajax_swsib_get_cleanup_progress', array($this->tasks, 'ajax_get_cleanup_progress'));
        add_action('wp_ajax_swsib_process_image_batch', array($this->tasks, 'ajax_process_image_batch'));
        
        // Preview AJAX handler
        add_action('wp_ajax_swsib_preview_orphaned_folders', array($this->data, 'ajax_preview_orphaned_folders'));
        
        // Settings AJAX handler
        add_action('wp_ajax_swsib_save_image_cleanup_automation', array($this->settings, 'ajax_save_image_cleanup_automation'));
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
     * Get progress file path
     */
    private function get_progress_file($task) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $swsib_dir = $base_dir . '/swsib';
        
        // Create directory if it doesn't exist
        if (!file_exists($swsib_dir)) {
            wp_mkdir_p($swsib_dir);
        }
        
        return $swsib_dir . '/swsib_image_' . sanitize_file_name($task) . '_progress.json';
    }
    
    /**
     * Check if there are orphaned folders to clean up
     * 
     * @return int Number of orphaned folders
     */
    private function count_orphaned_folders() {
        $orphaned_count = $this->data->get_orphaned_images_count();
        $this->log_message("Found $orphaned_count orphaned folders to clean up");
        return $orphaned_count;
    }

    /**
     * Format deleted folders for display in UI
     * 
     * @param array $deleted_folders Array of deleted folder objects
     * @return array Formatted data for UI display
     */
    private function format_deleted_folders_for_display($deleted_folders) {
        if (empty($deleted_folders) || !is_array($deleted_folders)) {
            return array(
                'deleted_folders_array' => array(),
                'deleted_folders_count' => 0,
                'deleted_folders_detail' => 'No folders deleted in this batch',
                'deleted_folders_summary' => 'No folders deleted',
                'deleted_folders' => 'No folders deleted'
            );
        }
        
        // Create a simple array of folder IDs for display
        $folder_ids = array();
        $formatted_folders = array();
        $folder_html_items = array();
        
        foreach ($deleted_folders as $folder) {
            // Extract key information
            $folder_id = isset($folder['folder_id']) ? $folder['folder_id'] : 'unknown';
            $folder_path = isset($folder['folder_path']) ? $folder['folder_path'] : 'unknown';
            $timestamp = isset($folder['timestamp']) ? $folder['timestamp'] : time();
            $time_formatted = date('Y-m-d H:i:s', $timestamp);
            
            // Add to simple arrays for display
            $folder_ids[] = $folder_id;
            
            // Create a simple string representation
            $formatted_folders[] = "Folder $folder_id ($time_formatted)";
            
            // Create HTML representation - changed to match requested format
            $folder_html_items[] = "$time_formatted - /images/application/<strong>$folder_id</strong>";
        }
        
        // Create summary text (limited to first 5)
        $summary_ids = array_slice($folder_ids, 0, 5);
        $summary_text = "Folders: " . implode(", ", $summary_ids);
        
        if (count($folder_ids) > 5) {
            $summary_text .= ", ... and " . (count($folder_ids) - 5) . " more";
        }
        
        // Create HTML display
        $folders_html = implode("<br>", $folder_html_items);
        
        return array(
            'deleted_folders_count' => count($deleted_folders),
            'deleted_folders_detail' => "Deleted " . count($deleted_folders) . " folders in this batch",
            'deleted_folders' => $folders_html // Renamed from deleted_folders_html to deleted_folders
        );
    }
    
    /**
     * Handle scheduled task - Called by the scheduler
     * 
     * Processes ONE BATCH per run, then returns.
     */
    public function handle_scheduled_task($task_type, $task_args) {
        if ($task_type !== 'image_cleanup') {
            return array('success' => false, 'message' => 'Not an image cleanup task');
        }
        
        $this->log_message("Handling scheduled image cleanup task");
        
        // First, check if there are any orphaned folders to clean up
        $orphaned_count = $this->count_orphaned_folders();
        
        if ($orphaned_count <= 0) {
            $this->log_message("No orphaned folders found to clean up");
            return array(
                'success' => true,
                'message' => "No orphaned folders found to clean up",
                'operation_details' => array(
                    'processed' => 0,
                    'total' => 0,
                    'deleted' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'timestamp' => time(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time()),
                    'progress_percentage' => 100,
                    'summary' => "No orphaned folders found to clean up",
                    'deleted_folders_array' => array(),
                    'deleted_folders_count' => 0
                )
            );
        }
        
        // Get progress file
        $progress_file = $this->get_progress_file('cleanup');
        
        // Initialize if needed or if previous task completed
        if (!file_exists($progress_file)) {
            $this->log_message("No progress file, initializing task");
            $this->data->initialize_task('cleanup');
        } else {
            $progress_data = json_decode(file_get_contents($progress_file), true);
            if (isset($progress_data['status']) && $progress_data['status'] === 'completed') {
                $this->log_message("Previous task completed, initializing new task");
                $this->data->initialize_task('cleanup');
            }
        }
        
        // Get current batch index
        $progress_data = json_decode(file_get_contents($progress_file), true);
        $batch_index = isset($progress_data['current_batch']) ? $progress_data['current_batch'] : 0;
        
        // Process ONE batch
        $this->log_message("Processing batch $batch_index for automated cleanup");
        $batch_result = $this->data->process_batch('cleanup', $batch_index);
        
        // Get updated progress data
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        // Extract deleted folders information
        $deleted_folders = array();
        if (isset($progress_data['deleted_folders']) && is_array($progress_data['deleted_folders'])) {
            $deleted_folders = $progress_data['deleted_folders'];
        } else {
            // Extract from batch_result if available
            if (isset($batch_result['deleted_folders']) && is_array($batch_result['deleted_folders'])) {
                $deleted_folders = $batch_result['deleted_folders'];
            } else if (isset($progress_data['logs'])) {
                // As a fallback, extract from logs
                foreach ($progress_data['logs'] as $log) {
                    if (isset($log['type']) && $log['type'] === 'success' && 
                        isset($log['message']) && strpos($log['message'], 'Successfully deleted folder') !== false) {
                        
                        // Extract folder ID from the message
                        $matches = array();
                        if (preg_match('/Successfully deleted folder: (\d+)/', $log['message'], $matches)) {
                            $folder_id = $matches[1];
                            $deleted_folders[] = array(
                                'folder_id' => $folder_id,
                                'folder_path' => rtrim($progress_data['installation_path'], '/') . '/images/application/' . $folder_id,
                                'timestamp' => isset($log['time']) ? $log['time'] : time()
                            );
                        }
                    }
                }
            }
        }
        
        // Format deleted folders for display
        $folders_display = $this->format_deleted_folders_for_display($deleted_folders);
        
        // Prepare operation details
        $operation_details = array(
            'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
            'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
            'skipped' => isset($progress_data['skipped']) ? $progress_data['skipped'] : 0,
            'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
            'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
            'timestamp' => time(),
            'timestamp_formatted' => date('Y-m-d H:i:s', time()),
            'progress_percentage' => isset($progress_data['progress']) ? $progress_data['progress'] : 0,
            'batch_index' => $batch_index,
            'next_batch' => isset($batch_result['next_batch']) ? $batch_result['next_batch'] : ($batch_index + 1)
        );
        
        // Add the formatted folder display information
        $operation_details = array_merge($operation_details, $folders_display);
        
        if ($batch_result['completed']) {
            $this->log_message("Cleanup task completed");
            
            // Check if anything was actually deleted
            if ($operation_details['deleted'] > 0) {
                $message = "Cleanup completed. Deleted {$operation_details['deleted']} folders.";
            } else {
                $message = "Cleanup completed. No folders were deleted.";
            }
            
            $operation_details['summary'] = $message;
            
            return array(
                'success' => true,
                'message' => $message,
                'operation_details' => $operation_details
            );
        } else {
            $this->log_message("Batch $batch_index processed, continuing in next run");
            
            $operation_details['summary'] = "Processed {$operation_details['processed']} out of {$operation_details['total']} folders ({$operation_details['progress_percentage']}%).";
            
            return array(
                'success' => true,
                'message' => "Batch $batch_index processed. Will continue next run.",
                'operation_details' => $operation_details,
                'completed' => false
            );
        }
    }
    
    /**
     * Process image cleanup (for manual runs via UI)
     */
    public function process_image_cleanup($task_id) {
        $this->log_message("Processing manual image cleanup: $task_id");
        
        // Check if there are any orphaned folders to clean up
        $orphaned_count = $this->count_orphaned_folders();
        
        if ($orphaned_count <= 0) {
            $this->log_message("No orphaned folders found to clean up");
            
            // Save to transient
            set_transient('swsib_task_' . $task_id, array(
                'status' => 'completed',
                'processed' => 0,
                'total' => 0,
                'deleted' => 0,
                'errors' => 0,
                'message' => "No orphaned folders found to clean up"
            ), HOUR_IN_SECONDS);
            
            return true;
        }
        
        // Initialize task
        $this->data->initialize_task('cleanup');
        
        // Process the first batch
        $batch_result = $this->data->process_batch('cleanup', 0);
        
        // Get progress data
        $progress_file = $this->get_progress_file('cleanup');
        $progress_data = json_decode(file_get_contents($progress_file), true);
        
        // Extract deleted folders
        $deleted_folders = array();
        if (isset($progress_data['deleted_folders']) && is_array($progress_data['deleted_folders'])) {
            $deleted_folders = $progress_data['deleted_folders'];
        } else if (isset($batch_result['deleted_folders']) && is_array($batch_result['deleted_folders'])) {
            $deleted_folders = $batch_result['deleted_folders'];
        } else if (isset($progress_data['logs'])) {
            // Extract from logs if needed
            foreach ($progress_data['logs'] as $log) {
                if (isset($log['type']) && $log['type'] === 'success' && 
                    isset($log['message']) && strpos($log['message'], 'Successfully deleted folder') !== false) {
                    
                    // Extract folder ID from the message
                    $matches = array();
                    if (preg_match('/Successfully deleted folder: (\d+)/', $log['message'], $matches)) {
                        $folder_id = $matches[1];
                        $deleted_folders[] = array(
                            'folder_id' => $folder_id,
                            'folder_path' => rtrim($progress_data['installation_path'], '/') . '/images/application/' . $folder_id,
                            'timestamp' => isset($log['time']) ? $log['time'] : time()
                        );
                    }
                }
            }
        }
        
        // Format deleted folders for display
        $folders_display = $this->format_deleted_folders_for_display($deleted_folders);
        
        // Create transient data
        $transient_data = array(
            'status' => $batch_result['completed'] ? 'completed' : 'in_progress',
            'processed' => isset($progress_data['processed']) ? $progress_data['processed'] : 0,
            'total' => isset($progress_data['total']) ? $progress_data['total'] : 0,
            'deleted' => isset($progress_data['deleted']) ? $progress_data['deleted'] : 0,
            'errors' => isset($progress_data['errors']) ? $progress_data['errors'] : 0,
            'message' => $batch_result['completed'] ? 
                "Cleanup completed. Deleted " . (isset($progress_data['deleted']) ? $progress_data['deleted'] : 0) . " folders." :
                "Processed " . (isset($progress_data['processed']) ? $progress_data['processed'] : 0) . " folders so far."
        );
        
        // Add folder display information
        $transient_data = array_merge($transient_data, $folders_display);
        
        // Save to transient
        set_transient('swsib_task_' . $task_id, $transient_data, HOUR_IN_SECONDS);
        
        return true;
    }
    
    /**
     * Display image cleanup automation settings
     */
    public function display_settings() {
        $this->settings->display_settings();
    }
    
    /**
     * Process settings for image cleanup automation
     */
    public function process_settings($input) {
        return $this->settings->process_settings($input);
    }
}
