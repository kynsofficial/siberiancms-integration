<?php
/**
 * Automated Actions Tab
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Actions {
    
    /**
     * Max log entries allowed
     */
    const MAX_LOG_ENTRIES = 200;
    
    /**
     * Default log entries
     */
    const DEFAULT_LOG_LIMIT = 100;
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Register AJAX handlers
        $this->register_ajax_handlers();
    }
    
    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        add_action('wp_ajax_swsib_get_action_logs', array($this, 'ajax_get_action_logs'));
        add_action('wp_ajax_swsib_get_action_details', array($this, 'ajax_get_action_details'));
        add_action('wp_ajax_swsib_save_action_limit', array($this, 'ajax_save_action_limit'));
        add_action('wp_ajax_swsib_clear_action_logs', array($this, 'ajax_clear_action_logs'));
    }
    
    /**
     * AJAX handler for getting action logs
     */
    public function ajax_get_action_logs() {
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
        
        // Get page number
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        
        // Validate page and per_page
        $page = max(1, $page);
        $per_page = max(5, min(50, $per_page));
        
        // Get task history
        $task_history = get_option('swsib_task_history', array());
        
        // Calculate total pages
        $total_items = count($task_history);
        $total_pages = ceil($total_items / $per_page);
        
        // Get page of logs
        $start = ($page - 1) * $per_page;
        $logs = array_slice($task_history, $start, $per_page);
        
        // Format logs for display
        $formatted_logs = array();
        foreach ($logs as $index => $log) {
            $task_type = isset($log['task_type']) ? $log['task_type'] : 'unknown';
            $task_args = isset($log['task_args']) ? $log['task_args'] : array();
            $timestamp = isset($log['timestamp']) ? $log['timestamp'] : 0;
            $success = isset($log['success']) ? $log['success'] : false;
            $message = isset($log['message']) ? $log['message'] : '';
            $operation_details = isset($log['operation_details']) ? $log['operation_details'] : array();
            
            // Generate summary based on task type and args
            $summary = $this->generate_summary($task_type, $task_args, $message, $operation_details);
            
            $formatted_logs[] = array(
                'id' => $start + $index + 1,
                'task_id' => $timestamp . '_' . md5($task_type . json_encode($task_args)),
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
                'task_type' => $this->get_task_type_label($task_type, $task_args),
                'task_type_raw' => $task_type,
                'task_args' => $task_args,
                'success' => $success,
                'message' => $message,
                'summary' => $summary,
                'operation_details' => $operation_details
            );
        }
        
        wp_send_json_success(array(
            'logs' => $formatted_logs,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'page' => $page,
            'per_page' => $per_page
        ));
    }
    
    /**
     * AJAX handler for getting action details
     */
    public function ajax_get_action_details() {
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
        
        // Get task ID
        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        
        if (empty($task_id)) {
            wp_send_json_error(array('message' => 'Task ID not provided.'));
            return;
        }
        
        // Parse task ID to get timestamp and hash
        $parts = explode('_', $task_id);
        if (count($parts) < 2) {
            wp_send_json_error(array('message' => 'Invalid task ID format.'));
            return;
        }
        
        $timestamp = intval($parts[0]);
        
        // Get task history
        $task_history = get_option('swsib_task_history', array());
        
        // Find the matching task
        $task_details = null;
        foreach ($task_history as $task) {
            if (isset($task['timestamp']) && $task['timestamp'] == $timestamp) {
                // Generate hash to double-check
                $hash = md5($task['task_type'] . json_encode($task['task_args']));
                if ($parts[1] === $hash) {
                    $task_details = $task;
                    break;
                }
            }
        }
        
        if (!$task_details) {
            wp_send_json_error(array('message' => 'Task not found.'));
            return;
        }
        
        // Clean up operation details to remove raw data
        if (isset($task_details['operation_details'])) {
            // Remove keys that cause [object Object] rendering
            $keysToRemove = ['timestamp'];
            foreach ($keysToRemove as $key) {
                if (isset($task_details['operation_details'][$key])) {
                    unset($task_details['operation_details'][$key]);
                }
            }
            
            // Preserve detailed lists for any type of task
            
            // For WP cleanup tasks
            if (isset($task_details['operation_details']['created_users_list'])) {
                $task_details['operation_details']['created_users'] = $task_details['operation_details']['created_users_list'];
            }
            
            if (isset($task_details['operation_details']['deleted_users_list'])) {
                $task_details['operation_details']['deleted_users'] = $task_details['operation_details']['deleted_users_list'];
            }
            
            // For app management - handle deleted_apps_list
            if (isset($task_details['operation_details']['deleted_apps_list']) && 
                is_array($task_details['operation_details']['deleted_apps_list'])) {
                
                // Convert to a more display-friendly format
                $task_details['operation_details']['deleted_apps'] = array();
                
                foreach ($task_details['operation_details']['deleted_apps_list'] as $app) {
                    if (is_array($app) && isset($app['action']) && $app['action'] === 'deleted') {
                        $formatted_app = array(
                            'app_id' => isset($app['app_id']) ? $app['app_id'] : 'unknown',
                            'name' => isset($app['name']) ? $app['name'] : 'unknown',
                            'email' => isset($app['email']) ? $app['email'] : '',
                            'size_mb' => isset($app['size_mb']) ? $app['size_mb'] : '',
                            'size_limit_mb' => isset($app['size_limit_mb']) ? $app['size_limit_mb'] : '',
                            'timestamp' => isset($app['timestamp']) ? $app['timestamp'] : date('Y-m-d H:i:s')
                        );
                        
                        $task_details['operation_details']['deleted_apps'][] = $formatted_app;
                    }
                }
                
                // Generate formatted output strings for UI display
                $task_details['operation_details']['deleted_apps_formatted'] = array();
                
                foreach ($task_details['operation_details']['deleted_apps'] as $app) {
                    $timestamp = $app['timestamp'];
                    $id = $app['app_id'];
                    $name = $app['name'];
                    
                    $displayApp = "$timestamp - <strong>$id</strong> - $name";
                    
                    if (!empty($app['email'])) {
                        $displayApp .= " - Owner: " . $app['email'];
                    }
                    
                    if (!empty($app['size_mb'])) {
                        $displayApp .= " - Size: " . $app['size_mb'] . " MB";
                    }
                    
                    if (!empty($app['size_limit_mb'])) {
                        $displayApp .= " - Limit: " . $app['size_limit_mb'] . " MB";
                    }
                    
                    $task_details['operation_details']['deleted_apps_formatted'][] = $displayApp;
                }
            }
            
            
            // For User Management - handle warned_users
            if (isset($task_details['operation_details']['warned_users']) && 
                is_array($task_details['operation_details']['warned_users'])) {
                
                // Convert users to a more display-friendly format
                $task_details['operation_details']['warned_users_formatted'] = array();
                
                foreach ($task_details['operation_details']['warned_users'] as $user) {
                    if (is_array($user)) {
                        $id = isset($user['id']) ? $user['id'] : 'unknown';
                        $email = isset($user['email']) ? $user['email'] : 'unknown';
                        $name = isset($user['name']) ? $user['name'] : '';
                        $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                        
                        $displayUser = "$timestamp - <strong>$id</strong> - $email";
                        if (!empty($name)) {
                            $displayUser .= " - $name";
                        }
                        
                        $task_details['operation_details']['warned_users_formatted'][] = $displayUser;
                    } else {
                        // For simple string values
                        $task_details['operation_details']['warned_users_formatted'][] = esc_html($user);
                    }
                }
            }
            
            // For db cleanup tasks, similarly format deleted/optimized items for display
            if ($task_details['task_type'] === 'db_cleanup' && isset($task_details['operation_details']['deleted_items']) && is_array($task_details['operation_details']['deleted_items'])) {
                // Create a formatted html list of deleted items
                $task_details['operation_details']['deleted_items_formatted'] = array();
                
                foreach ($task_details['operation_details']['deleted_items'] as $item) {
                    if (is_array($item)) {
                        $id = isset($item['id']) ? $item['id'] : 'unknown';
                        $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                        
                        $displayItem = "$timestamp - <strong>$id</strong>";
                        if (isset($item['title'])) {
                            $displayItem .= " - " . $item['title'];
                        }
                        if (isset($item['from'])) {
                            $displayItem .= " - From: " . $item['from'];
                        }
                        if (isset($item['name'])) {
                            $displayItem .= " - " . $item['name'];
                        }
                        if (isset($item['status'])) {
                            $displayItem .= " - Status: " . $item['status'];
                        }
                        if (isset($item['modified'])) {
                            $displayItem .= " - Modified: " . $item['modified'];
                        }
                        
                        $task_details['operation_details']['deleted_items_formatted'][] = $displayItem;
                    } else {
                        // For simple string values
                        $task_details['operation_details']['deleted_items_formatted'][] = esc_html($item);
                    }
                }
            }
            
            // For db cleanup optimize tasks, format optimized tables similarly
            if ($task_details['task_type'] === 'db_cleanup' && isset($task_details['task_args']['task']) && 
                $task_details['task_args']['task'] === 'optimize' && 
                isset($task_details['operation_details']['optimized_tables']) && 
                is_array($task_details['operation_details']['optimized_tables'])) {
                
                // Create a formatted html list of optimized tables
                $task_details['operation_details']['optimized_tables_formatted'] = array();
                
                foreach ($task_details['operation_details']['optimized_tables'] as $table) {
                    if (is_array($table)) {
                        $table_name = isset($table['table_name']) ? $table['table_name'] : 'unknown';
                        $size_mb = isset($table['size_mb']) ? $table['size_mb'] : 'unknown';
                        $timestamp = isset($table['timestamp']) ? $table['timestamp'] : date('Y-m-d H:i:s');
                        
                        $displayTable = "$timestamp - <strong>$table_name</strong> - Size: $size_mb MB";
                        
                        $task_details['operation_details']['optimized_tables_formatted'][] = $displayTable;
                    } else {
                        // For simple string values
                        $task_details['operation_details']['optimized_tables_formatted'][] = esc_html($table);
                    }
                }
            }
        }
        
        // Format task details for display
        $formatted_details = array(
            'task_type' => $this->get_task_type_label($task_details['task_type'], $task_details['task_args']),
            'task_type_raw' => $task_details['task_type'],
            'timestamp' => $task_details['timestamp'],
            'date' => date('Y-m-d H:i:s', $task_details['timestamp']),
            'success' => $task_details['success'],
            'message' => $task_details['message'],
            'task_args' => $task_details['task_args'],
            'operation_details' => isset($task_details['operation_details']) ? $task_details['operation_details'] : array()
        );
        
        wp_send_json_success($formatted_details);
    }
    
    /**
     * AJAX handler for saving action log limit
     */
    public function ajax_save_action_limit() {
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
        
        // Get limit value
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : self::DEFAULT_LOG_LIMIT;
        
        // Validate limit
        $limit = min(self::MAX_LOG_ENTRIES, max(50, $limit));
        
        // Update options
        $options = get_option('swsib_options', array());
        if (!isset($options['automate'])) {
            $options['automate'] = array();
        }
        $options['automate']['action_logs_limit'] = $limit;
        update_option('swsib_options', $options);
        
        // Trim logs if needed
        $this->trim_task_history($limit);
        
        wp_send_json_success(array(
            'message' => sprintf(__('Log limit updated to %d entries.', 'swiftspeed-siberian'), $limit)
        ));
    }
    
    /**
     * AJAX handler for clearing action logs
     */
    public function ajax_clear_action_logs() {
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
        
        // Clear all logs
        update_option('swsib_task_history', array());
        
        wp_send_json_success(array(
            'message' => __('All logs have been cleared.', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * Trim task history to the specified limit
     */
    private function trim_task_history($limit = null) {
        // If no limit specified, get from options
        if ($limit === null) {
            $options = get_option('swsib_options', array());
            $limit = isset($options['automate']['action_logs_limit']) ? 
                    intval($options['automate']['action_logs_limit']) : self::DEFAULT_LOG_LIMIT;
        }
        
        // Validate limit
        $limit = min(self::MAX_LOG_ENTRIES, max(50, $limit));
        
        // Get task history
        $task_history = get_option('swsib_task_history', array());
        
        // Trim if needed
        if (count($task_history) > $limit) {
            $task_history = array_slice($task_history, 0, $limit);
            update_option('swsib_task_history', $task_history);
        }
        
        return true;
    }
    
    /**
     * Generate a summary for a task based on its type and args
     */
    private function generate_summary($task_type, $task_args, $message, $operation_details) {
        $summary = '';
        
        // First check for operation details which may contain summary
        if (isset($operation_details['summary'])) {
            return $operation_details['summary'];
        }
        
        // Extract counts from message where possible
        $counts = $this->extract_counts_from_message($message);
        
        switch ($task_type) {
            case 'api_command':
                $command = isset($task_args['command']) ? $task_args['command'] : 
                          (isset($task_args['type']) ? $task_args['type'] : '');
                if (!empty($command)) {
                    $summary = sprintf(__('API command "%s" executed', 'swiftspeed-siberian'), $command);
                    if (!empty($counts)) {
                        $summary .= ': ' . $counts;
                    }
                }
                break;
                
            case 'image_cleanup':
                $deleted = isset($operation_details['deleted']) ? intval($operation_details['deleted']) : 0;
                $errors = isset($operation_details['errors']) ? intval($operation_details['errors']) : 0;
                $processed = isset($operation_details['processed']) ? intval($operation_details['processed']) : 0;
                $total = isset($operation_details['total']) ? intval($operation_details['total']) : 0;
                
                if ($processed > 0 && $total > 0) {
                    $progress = isset($operation_details['progress_percentage']) ? 
                               intval($operation_details['progress_percentage']) : 
                               min(100, round(($processed / $total) * 100));
                    
                    $summary = sprintf(__('Processed %d out of %d items (%d%%).', 'swiftspeed-siberian'), 
                                      $processed, $total, $progress);
                } else if ($deleted > 0) {
                    $summary = sprintf(__('Deleted %d orphaned image folders', 'swiftspeed-siberian'), $deleted);
                    if ($errors > 0) {
                        $summary .= sprintf(__(', with %d errors', 'swiftspeed-siberian'), $errors);
                    }
                } else if (!empty($counts)) {
                    $summary = $counts;
                } else {
                    $summary = __('Image cleanup completed', 'swiftspeed-siberian');
                }
                break;
                
            case 'user_management':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                $processed = isset($operation_details['processed']) ? intval($operation_details['processed']) : 0;
                $total = isset($operation_details['total']) ? intval($operation_details['total']) : 0;
                $deleted = isset($operation_details['deleted']) ? intval($operation_details['deleted']) : 0;
                $warned = isset($operation_details['warned']) ? intval($operation_details['warned']) : 0;
                $errors = isset($operation_details['errors']) ? intval($operation_details['errors']) : 0;
                
                if ($processed > 0 && $total > 0) {
                    $progress = isset($operation_details['progress_percentage']) ? 
                               intval($operation_details['progress_percentage']) : 
                               min(100, round(($processed / $total) * 100));
                    
                    $summary = sprintf(__('Processed %d out of %d users (%d%%).', 'swiftspeed-siberian'), 
                                      $processed, $total, $progress);
                    
                    // Add more details about warned/deleted if applicable
                    if ($warned > 0 || $deleted > 0) {
                        $details = [];
                        if ($warned > 0) {
                            $details[] = sprintf(__('warned %d', 'swiftspeed-siberian'), $warned);
                        }
                        if ($deleted > 0) {
                            $details[] = sprintf(__('deleted %d', 'swiftspeed-siberian'), $deleted);
                        }
                        if ($errors > 0) {
                            $details[] = sprintf(__('with %d errors', 'swiftspeed-siberian'), $errors);
                        }
                        
                        if (!empty($details)) {
                            $summary .= ' (' . implode(', ', $details) . ')';
                        }
                    }
                } else if (!empty($counts)) {
                    $summary = $counts;
                } else {
                    if ($task === 'inactive') {
                        $summary = __('Inactive users cleanup completed', 'swiftspeed-siberian');
                    } else if ($task === 'no_apps') {
                        $summary = __('Users without apps cleanup completed', 'swiftspeed-siberian');
                    } else {
                        $summary = __('User management task completed', 'swiftspeed-siberian');
                    }
                }
                break;
                
            case 'app_management':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                $processed = isset($operation_details['processed']) ? intval($operation_details['processed']) : 0;
                $total = isset($operation_details['total']) ? intval($operation_details['total']) : 0;
                $deleted = isset($operation_details['deleted']) ? intval($operation_details['deleted']) : 0;
                $skipped = isset($operation_details['skipped']) ? intval($operation_details['skipped']) : 0;
                $errors = isset($operation_details['errors']) ? intval($operation_details['errors']) : 0;
                
                if ($processed > 0 && $total > 0) {
                    $progress = isset($operation_details['progress_percentage']) ? 
                               intval($operation_details['progress_percentage']) : 
                               min(100, round(($processed / $total) * 100));
                    
                    $summary = sprintf(__('Processed %d out of %d apps (%d%%).', 'swiftspeed-siberian'), 
                                      $processed, $total, $progress);
                    
                    // Add more details if applicable
                    if ($deleted > 0 || $skipped > 0 || $errors > 0) {
                        $details = [];
                        if ($deleted > 0) {
                            $details[] = sprintf(__('deleted %d', 'swiftspeed-siberian'), $deleted);
                        }
                        if ($skipped > 0) {
                            $details[] = sprintf(__('skipped %d', 'swiftspeed-siberian'), $skipped);
                        }
                        if ($errors > 0) {
                            $details[] = sprintf(__('with %d errors', 'swiftspeed-siberian'), $errors);
                        }
                        
                        if (!empty($details)) {
                            $summary .= ' (' . implode(', ', $details) . ')';
                        }
                    }
                } else if ($processed > 0) {
                    if ($task === 'zero_size') {
                        $summary = sprintf(__('Processed %d zero-size apps: Deleted %d, Skipped %d, with %d errors', 'swiftspeed-siberian'), $processed, $deleted, $skipped, $errors);
                    } else if ($task === 'inactive') {
                        $summary = sprintf(__('Processed %d inactive apps: Deleted %d, Skipped %d, with %d errors', 'swiftspeed-siberian'), $processed, $deleted, $skipped, $errors);
                    } else if ($task === 'size_violation') {
                        $summary = sprintf(__('Processed %d size violation apps: Deleted %d, Skipped %d, with %d errors', 'swiftspeed-siberian'), $processed, $deleted, $skipped, $errors);
                    } else if ($task === 'no_users') {
                        $summary = sprintf(__('Processed %d apps without users: Deleted %d, Skipped %d, with %d errors', 'swiftspeed-siberian'), $processed, $deleted, $skipped, $errors);
                    } else {
                        $summary = sprintf(__('Processed %d applications', 'swiftspeed-siberian'), $processed);
                    }
                } else if (!empty($counts)) {
                    $summary = $counts;
                } else {
                    if ($task === 'zero_size') {
                        $summary = __('Zero size apps cleanup completed', 'swiftspeed-siberian');
                    } else if ($task === 'inactive') {
                        $summary = __('Inactive apps cleanup completed', 'swiftspeed-siberian');
                    } else if ($task === 'size_violation') {
                        $summary = __('Size violation apps cleanup completed', 'swiftspeed-siberian');
                    } else if ($task === 'no_users') {
                        $summary = __('Apps without users cleanup completed', 'swiftspeed-siberian');
                    } else {
                        $summary = __('App management task completed', 'swiftspeed-siberian');
                    }
                }
                break;
                
            case 'db_cleanup':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                $deleted = isset($operation_details['deleted']) ? intval($operation_details['deleted']) : 0;
                $errors = isset($operation_details['errors']) ? intval($operation_details['errors']) : 0;
                $processed = isset($operation_details['processed']) ? intval($operation_details['processed']) : 0;
                $total = isset($operation_details['total']) ? intval($operation_details['total']) : 0;
                
                if ($processed > 0 && $total > 0) {
                    $progress = isset($operation_details['progress_percentage']) ? 
                               intval($operation_details['progress_percentage']) : 
                               min(100, round(($processed / $total) * 100));
                    
                    $summary = sprintf(__('Processed %d out of %d items (%d%%).', 'swiftspeed-siberian'), 
                                      $processed, $total, $progress);
                } else if ($task === 'sessions') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Cleaned up %d sessions with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Sessions cleanup completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'mail_logs') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Cleaned up %d mail logs with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Mail logs cleanup completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'source_queue') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Cleaned up %d source queue entries with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Source queue cleanup completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'optimize') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Optimized %d database tables with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Database optimization completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'backoffice_alerts') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Cleaned up %d backoffice alerts with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Backoffice alerts cleanup completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'cleanup_log') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Cleaned up %d cleanup log entries with %d errors', 'swiftspeed-siberian'), $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Cleanup log maintenance completed', 'swiftspeed-siberian');
                    }
                } else {
                    if ($processed > 0) {
                        $summary = sprintf(__('Processed %d database items: deleted %d with %d errors', 'swiftspeed-siberian'), 
                                          $processed, $deleted, $errors);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Database cleanup completed', 'swiftspeed-siberian');
                    }
                }
                break;
                
            case 'wp_cleanup':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                $processed = isset($operation_details['processed']) ? intval($operation_details['processed']) : 0;
                $total = isset($operation_details['total']) ? intval($operation_details['total']) : 0;
                $created = isset($operation_details['created']) ? intval($operation_details['created']) : 0;
                $deleted = isset($operation_details['deleted']) ? intval($operation_details['deleted']) : 0;
                
                if ($task === 'spam_users') {
                    if ($deleted > 0) {
                        $summary = sprintf(__('Processed %d spam users: Deleted %d users', 'swiftspeed-siberian'), $processed, $deleted);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('Spam users cleanup completed', 'swiftspeed-siberian');
                    }
                } else if ($task === 'unsynced_users') {
                    if ($created > 0 || $deleted > 0) {
                        $summary = sprintf(__('Synchronized users: Created %d, Deleted %d', 'swiftspeed-siberian'), $created, $deleted);
                    } else if ($processed > 0) {
                        $summary = sprintf(__('Processed %d unsynced users out of %d', 'swiftspeed-siberian'), $processed, $total);
                    } else if (!empty($counts)) {
                        $summary = $counts;
                    } else {
                        $summary = __('User synchronization completed', 'swiftspeed-siberian');
                    }
                }
                break;
                
            default:
                // For unknown task types, use message as summary
                if (!empty($message)) {
                    $summary = $message;
                } else {
                    $summary = sprintf(__('Task "%s" executed', 'swiftspeed-siberian'), $task_type);
                }
                break;
        }
        
        return $summary;
    }
    
    /**
     * Extract counts from message
     */
    private function extract_counts_from_message($message) {
        $counts = '';
        
        // Look for patterns like "Deleted X items" or "Processed X out of Y"
        if (preg_match('/deleted\s+(\d+)/i', $message, $matches)) {
            $counts = sprintf(__('Deleted %d items', 'swiftspeed-siberian'), $matches[1]);
        } else if (preg_match('/cleaned\s+(\d+)/i', $message, $matches)) {
            $counts = sprintf(__('Cleaned %d items', 'swiftspeed-siberian'), $matches[1]);
        } else if (preg_match('/processed\s+(\d+)(?:\s+out\s+of\s+(\d+))?/i', $message, $matches)) {
            if (isset($matches[2])) {
                $counts = sprintf(__('Processed %d out of %d items', 'swiftspeed-siberian'), $matches[1], $matches[2]);
            } else {
                $counts = sprintf(__('Processed %d items', 'swiftspeed-siberian'), $matches[1]);
            }
        }
        
        return $counts;
    }
    
    /**
     * Get a human-readable label for a task type
     */
    private function get_task_type_label($task_type, $task_args = array()) {
        switch ($task_type) {
            case 'api_command':
                $command = isset($task_args['command']) ? $task_args['command'] : 
                          (isset($task_args['type']) ? $task_args['type'] : '');
                if (!empty($command)) {
                    return sprintf(__('API Command: %s', 'swiftspeed-siberian'), $command);
                }
                return __('API Command', 'swiftspeed-siberian');
                
            case 'image_cleanup':
                return __('Image Cleanup', 'swiftspeed-siberian');
                
            case 'user_management':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                if ($task === 'inactive') {
                    return __('Inactive Users Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'no_apps') {
                    return __('Users Without Apps Cleanup', 'swiftspeed-siberian');
                }
                return __('User Management', 'swiftspeed-siberian');
                
            case 'app_management':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                if ($task === 'zero_size') {
                    return __('Zero Size Apps Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'inactive') {
                    return __('Inactive Apps Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'size_violation') {
                    return __('Size Violation Apps Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'no_users') {
                    return __('Apps Without Users Cleanup', 'swiftspeed-siberian');
                } else if (!empty($task)) {
                    return sprintf(__('App Management: %s', 'swiftspeed-siberian'), ucfirst($task));
                }
                return __('App Management', 'swiftspeed-siberian');
                
            case 'db_cleanup':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                if ($task === 'sessions') {
                    return __('Sessions Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'mail_logs') {
                    return __('Mail Logs Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'source_queue') {
                    return __('Source Queue Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'optimize') {
                    return __('Database Optimization', 'swiftspeed-siberian');
                } else if ($task === 'backoffice_alerts') {
                    return __('Backoffice Alerts Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'cleanup_log') {
                    return __('Cleanup Log Maintenance', 'swiftspeed-siberian');
                }
                return __('Database Cleanup', 'swiftspeed-siberian');
                
            case 'wp_cleanup':
                $task = isset($task_args['task']) ? $task_args['task'] : '';
                if ($task === 'spam_users') {
                    return __('Spam Users Cleanup', 'swiftspeed-siberian');
                } else if ($task === 'unsynced_users') {
                    return __('User Synchronization', 'swiftspeed-siberian');
                }
                return __('WordPress Cleanup', 'swiftspeed-siberian');
                
            default:
                return ucfirst(str_replace('_', ' ', $task_type));
        }
    }
    
    /**
     * Display the settings
     */
    public function display_settings() {
        // Get the current log limit
        $options = get_option('swsib_options', array());
        $log_limit = isset($options['automate']['action_logs_limit']) ? 
                    intval($options['automate']['action_logs_limit']) : self::DEFAULT_LOG_LIMIT;
        
        // Make sure limit is valid
        $log_limit = min(self::MAX_LOG_ENTRIES, max(50, $log_limit));
        
        // Get task history for initial display
        $task_history = get_option('swsib_task_history', array());
        $total_logs = count($task_history);
        $logs_per_page = 20;
        $total_pages = ceil($total_logs / $logs_per_page);
        
        // Get first page of logs
        $first_page_logs = array_slice($task_history, 0, $logs_per_page);
?>
<div class="task-section">
    <h3><?php _e('Automated Actions Log', 'swiftspeed-siberian'); ?></h3>
    
    <div class="actions-info-text info-text">
        <p><?php _e('This tab displays a log of all automated tasks executed by the system. You can see when each task ran and what actions were performed.', 'swiftspeed-siberian'); ?></p>
        <p><strong><?php _e('Note:', 'swiftspeed-siberian'); ?></strong> <?php _e('To prevent performance issues, you cannot store more than 200 logs.', 'swiftspeed-siberian'); ?></p>
        
        <!-- Move the refresh button to this tab -->
        <p class="task-refresh-container">
            <button type="button" id="refresh-tasks-button" class="button button-secondary">
                <span class="dashicons dashicons-update"></span> <?php _e('Force Check All Tasks Now', 'swiftspeed-siberian'); ?>
            </button>
            <span id="task-refresh-message" style="display:none; margin-left: 10px;"></span>
        </p>
    </div>
    
    <!-- Automation Notice -->
    <div class="swsib-notice warning notice-warning is-dismissible action-notice">
        <p><strong>SwiftSpeed Siberian Automation Notice:</strong></p>
        
        <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
            <p>WP-Cron is disabled on this site. To ensure automated tasks run reliably, you <strong>must</strong> set up an external cron job.</p>
        <?php else: ?>
            <p>For the most reliable automation, we recommend setting up an external cron job to trigger scheduled tasks.</p>
        <?php endif; ?>
        
        <p>Add the following command to your server's crontab (run every 5 minutes):</p>
        <code style="display:block;padding:10px;background:#f1f1f1;margin:10px 0;">*/5 * * * * wget -q -O /dev/null '<?php echo admin_url('admin-ajax.php?action=swsib_force_check_tasks&key=' . md5('swsib_force_check_tasks')); ?>' >/dev/null 2>&1</code>
        
        <p><a href="https://developer.wordpress.org/plugins/cron/" target="_blank">Learn more about WordPress Cron</a></p>
    </div>
    
    <!-- Log Configuration -->
    <div class="action-settings-container">
        <h4><?php _e('Log Settings', 'swiftspeed-siberian'); ?></h4>
        <div class="action-settings-row">
            <label for="action_logs_limit"><?php _e('Maximum number of logs to keep:', 'swiftspeed-siberian'); ?></label>
            <select id="action_logs_limit" name="action_logs_limit">
                <option value="50" <?php selected($log_limit, 50); ?>>50 logs</option>
                <option value="100" <?php selected($log_limit, 100); ?>>100 logs</option>
                <option value="150" <?php selected($log_limit, 150); ?>>150 logs</option>
                <option value="200" <?php selected($log_limit, 200); ?>>200 logs</option>
            </select>
            <button type="button" id="save-action-limit" class="button button-primary"><?php _e('Save', 'swiftspeed-siberian'); ?></button>
        </div>
        <div class="action-settings-row">
            <button type="button" id="refresh-action-logs" class="button button-secondary">
                <span class="dashicons dashicons-update"></span> <?php _e('Refresh Logs', 'swiftspeed-siberian'); ?>
            </button>
            <button type="button" id="clear-action-logs" class="button button-secondary">
                <span class="dashicons dashicons-trash"></span> <?php _e('Clear All Logs', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Logs Table -->
    <div class="action-logs-container">
        <div id="action-logs-loading" class="loadings-spinner"></div>
        <table class="wp-list-table widefat fixed striped action-logs-table">
            <thead>
                <tr>
                    <th class="column-date"><?php _e('Date & Time', 'swiftspeed-siberian'); ?></th>
                    <th class="column-type"><?php _e('Task Type', 'swiftspeed-siberian'); ?></th>
                    <th class="column-summary"><?php _e('Summary', 'swiftspeed-siberian'); ?></th>
                    <th class="column-status"><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                    <th class="column-actions"><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                </tr>
            </thead>
            <tbody id="action-logs-tbody">
                <?php if (empty($first_page_logs)): ?>
                <tr>
                    <td colspan="5" class="action-logs-empty"><?php _e('No action logs found. Run some automated tasks to see them here.', 'swiftspeed-siberian'); ?></td>
                </tr>
                <?php else: ?>
                    <?php 
                    // Display first page of logs inline for immediate visibility
                    foreach ($first_page_logs as $index => $log): 
                        $task_type = isset($log['task_type']) ? $log['task_type'] : 'unknown';
                        $task_args = isset($log['task_args']) ? $log['task_args'] : array();
                        $timestamp = isset($log['timestamp']) ? $log['timestamp'] : 0;
                        $success = isset($log['success']) ? $log['success'] : false;
                        $message = isset($log['message']) ? $log['message'] : '';
                        $operation_details = isset($log['operation_details']) ? $log['operation_details'] : array();
                        
                        // Generate unique task ID
                        $task_id = $timestamp . '_' . md5($task_type . json_encode($task_args));
                        
                        // Generate summary
                        $summary = $this->generate_summary($task_type, $task_args, $message, $operation_details);
                        
                        // Get status class
                        $status_class = $success ? 'success' : 'error';
                        $status_text = $success ? __('Success', 'swiftspeed-siberian') : __('Failed', 'swiftspeed-siberian');
                    ?>
                    <tr>
                        <td><?php echo date('Y-m-d H:i:s', $timestamp); ?></td>
                        <td><?php echo $this->get_task_type_label($task_type, $task_args); ?></td>
                        <td><?php echo esc_html($summary); ?></td>
                        <td><span class="action-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                        <td><button type="button" class="button button-small view-action-details" data-task-id="<?php echo esc_attr($task_id); ?>"><?php _e('View Details', 'swiftspeed-siberian'); ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num" id="action-logs-count"><?php echo sprintf(_n('%s item', '%s items', $total_logs, 'swiftspeed-siberian'), number_format_i18n($total_logs)); ?></span>
                <span class="pagination-links">
                    <button type="button" class="button first-page" id="action-logs-first-page" <?php echo ($total_pages <= 1) ? 'disabled' : ''; ?>>
                        <span class="screen-reader-text"><?php _e('First page', 'swiftspeed-siberian'); ?></span>
                        <span aria-hidden="true">&laquo;</span>
                    </button>
                    <button type="button" class="button prev-page" id="action-logs-prev-page" <?php echo ($total_pages <= 1) ? 'disabled' : ''; ?>>
                        <span class="screen-reader-text"><?php _e('Previous page', 'swiftspeed-siberian'); ?></span>
                        <span aria-hidden="true">&lsaquo;</span>
                    </button>
                    <span class="paging-input">
                        <span id="action-logs-current-page">1</span> <?php _e('of', 'swiftspeed-siberian'); ?> <span id="action-logs-total-pages"><?php echo $total_pages; ?></span>
                    </span>
                    <button type="button" class="button next-page" id="action-logs-next-page" <?php echo ($total_pages <= 1) ? 'disabled' : ''; ?>>
                        <span class="screen-reader-text"><?php _e('Next page', 'swiftspeed-siberian'); ?></span>
                        <span aria-hidden="true">&rsaquo;</span>
                    </button>
                    <button type="button" class="button last-page" id="action-logs-last-page" <?php echo ($total_pages <= 1) ? 'disabled' : ''; ?>>
                        <span class="screen-reader-text"><?php _e('Last page', 'swiftspeed-siberian'); ?></span>
                        <span aria-hidden="true">&raquo;</span>
                    </button>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Log Details Modal -->
    <div id="action-details-modal" class="action-modal">
        <div class="action-modal-content">
            <div class="action-modal-header">
                <h3 id="action-details-title"><?php _e('Action Details', 'swiftspeed-siberian'); ?></h3>
                <span class="action-modal-close">&times;</span>
            </div>
            <div class="action-modal-body">
                <div id="action-details-loading" class="loadings-spinner"></div>
                <div id="action-details-content">
                    <div class="action-details-row">
                        <span class="action-details-label"><?php _e('Task Type:', 'swiftspeed-siberian'); ?></span>
                        <span id="action-details-type" class="action-details-value"></span>
                    </div>
                    <div class="action-details-row">
                        <span class="action-details-label"><?php _e('Execution Time:', 'swiftspeed-siberian'); ?></span>
                        <span id="action-details-time" class="action-details-value"></span>
                    </div>
                    <div class="action-details-row">
                        <span class="action-details-label"><?php _e('Status:', 'swiftspeed-siberian'); ?></span>
                        <span id="action-details-status" class="action-details-value"></span>
                    </div>
                    <div class="action-details-row">
                        <span class="action-details-label"><?php _e('Task Arguments:', 'swiftspeed-siberian'); ?></span>
                        <pre id="action-details-args" class="action-details-value"></pre>
                    </div>
                    <div class="action-details-row">
                        <span class="action-details-label"><?php _e('Message:', 'swiftspeed-siberian'); ?></span>
                        <div id="action-details-message" class="action-details-value"></div>
                    </div>
                    <div class="action-details-row" id="operation-details-container">
                        <span class="action-details-label"><?php _e('Operation Details:', 'swiftspeed-siberian'); ?></span>
                        <div id="action-details-operation" class="action-details-value"></div>
                    </div>
                    
                    <!-- Processed users section - for WP tasks -->
                    <div class="action-details-row" id="processed-users-container" style="display: none;">
                        <span class="action-details-label"><?php _e('Processed Users:', 'swiftspeed-siberian'); ?></span>
                        <div id="action-details-users" class="action-details-value">
                            <div class="task-card-counts" style="margin-bottom: 15px;">
                                <div class="task-card-count">
                                    <span><?php _e('Created', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="users-created-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Deleted', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="users-deleted-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Errors', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="users-errors-count">0</span>
                                </div>
                            </div>
                            <div id="created-users-list" style="display: none;">
                                <h4><?php _e('Created WordPress Users:', 'swiftspeed-siberian'); ?></h4>
                                <div class="users-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; margin-bottom: 15px;"></div>
                            </div>
                            <div id="deleted-users-list" style="display: none;">
                                <h4><?php _e('Deleted WordPress Users:', 'swiftspeed-siberian'); ?></h4>
                                <div class="users-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Processed apps section - for App Management tasks -->
                    <div class="action-details-row" id="processed-apps-container" style="display: none;">
                        <span class="action-details-label"><?php _e('Processed Apps:', 'swiftspeed-siberian'); ?></span>
                        <div id="action-details-apps" class="action-details-value">
                            <div class="task-card-counts" style="margin-bottom: 15px;">
                                <div class="task-card-count">
                                    <span><?php _e('Deleted', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="apps-deleted-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Skipped', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="apps-skipped-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Errors', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="apps-errors-count">0</span>
                                </div>
                            </div>
                            <div id="deleted-apps-list" style="display: none;">
                                <h4><?php _e('Deleted Applications:', 'swiftspeed-siberian'); ?></h4>
                                <div class="apps-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px;"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Processed DB items section - for DB Cleanup tasks -->
                    <div class="action-details-row" id="processed-items-container" style="display: none;">
                        <span class="action-details-label"><?php _e('Processed Items:', 'swiftspeed-siberian'); ?></span>
                        <div id="action-details-items" class="action-details-value">
                            <div class="task-card-counts" style="margin-bottom: 15px;">
                                <div class="task-card-count">
                                    <span><?php _e('Deleted/Optimized', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="items-deleted-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Skipped', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="items-skipped-count">0</span>
                                </div>
                                <div class="task-card-count">
                                    <span><?php _e('Errors', 'swiftspeed-siberian'); ?></span>
                                    <span class="task-card-count-value" id="items-errors-count">0</span>
                                </div>
                            </div>
                            <div id="deleted-items-list" style="display: none;">
                                <h4 id="deleted-items-title"><?php _e('Deleted Items:', 'swiftspeed-siberian'); ?></h4>
                                <div class="deleted-items-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; margin-bottom: 15px;"></div>
                            </div>
                            <div id="optimized-tables-list" style="display: none;">
                                <h4><?php _e('Optimized Tables:', 'swiftspeed-siberian'); ?></h4>
                                <div class="optimized-tables-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="action-modal-footer">
                <button type="button" class="button button-primary action-modal-close"><?php _e('Close', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php
    }
}