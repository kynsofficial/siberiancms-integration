<?php
/**
 * Task Runner for Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Task_Runner {
    
    /**
     * Log message
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * Run a scheduled task
     * 
     * @param string $task_type The type of task to run
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    public function run_scheduled_task($task_type, $task_args = array()) {
        self::log_message("Running scheduled task: {$task_type}");
        
        $success = false;
        $message = '';
        $operation_details = array();
        
        try {
            // Run the appropriate task based on type
            switch ($task_type) {
                case 'api_command':
                    $result = self::run_api_command($task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                case 'image_cleanup':
                    $result = self::run_image_cleanup($task_type, $task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                case 'user_management':
                    $result = self::run_user_management($task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                case 'app_management':
                    $result = self::run_app_management($task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                case 'db_cleanup':
                    $result = self::run_db_cleanup($task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                case 'wp_cleanup':
                    $result = self::run_wp_cleanup($task_args);
                    $success = $result['success'];
                    $message = $result['message'];
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    break;
                    
                default:
                    self::log_message("Unknown task type: {$task_type}");
                    $message = "Unknown task type: {$task_type}";
                    break;
            }
        } catch (Exception $e) {
            self::log_message("Exception running task {$task_type}: " . $e->getMessage());
            $success = false;
            $message = "Error: " . $e->getMessage();
            $operation_details = array('error' => $e->getMessage());
        }
        
        // Make sure timestamp_formatted is included in operation details in human-readable format
        if (!isset($operation_details['timestamp_formatted'])) {
            $operation_details['timestamp_formatted'] = date('Y-m-d H:i:s', time());
        }
        
        // For image cleanup and db cleanup, ensure we have a properly formatted progress summary
        if (($task_type === 'image_cleanup' || $task_type === 'db_cleanup' || $task_type === 'user_management' || $task_type === 'app_management') && 
            isset($operation_details['processed']) && isset($operation_details['total'])) {
            
            $progress = isset($operation_details['progress_percentage']) ? 
                      $operation_details['progress_percentage'] : 
                      (isset($operation_details['progress']) ? $operation_details['progress'] : 0);
            
            if (!isset($operation_details['summary'])) {
                $operation_details['summary'] = sprintf(__('Processed %d out of %d items (%d%%).', 'swiftspeed-siberian'), 
                                                     $operation_details['processed'], $operation_details['total'], $progress);
            }
        }
        
        // For image cleanup, format deleted folders for display in the UI
        if ($task_type === 'image_cleanup' && isset($operation_details['deleted_folders']) && is_array($operation_details['deleted_folders'])) {
            // Create a formatted html list of deleted folders
            $deleted_folders_html = array();
            
            foreach ($operation_details['deleted_folders'] as $folder) {
                $folder_id = isset($folder['id']) ? $folder['id'] : 'unknown';
                $path = isset($folder['path']) ? $folder['path'] : 'unknown';
                $timestamp = isset($folder['timestamp']) ? $folder['timestamp'] : date('Y-m-d H:i:s');
                
                // Format like the WP Tasks display
                $deleted_folders_html[] = "$timestamp - <strong>$folder_id</strong> - $path";
            }
            
            // Add the formatted html list to operation details
            $operation_details['deleted_folders_html'] = $deleted_folders_html;
        }
        
        // For app management tasks, format deleted apps for display
        if ($task_type === 'app_management' && isset($operation_details['deleted_apps']) && is_array($operation_details['deleted_apps'])) {
            // Create a formatted html list of deleted apps
            $deleted_apps_html = array();
            
            foreach ($operation_details['deleted_apps'] as $app) {
                if (is_array($app)) {
                    $id = isset($app['app_id']) ? $app['app_id'] : 'unknown';
                    $name = isset($app['name']) ? $app['name'] : 'unknown';
                    $email = isset($app['email']) ? $app['email'] : '';
                    $size_mb = isset($app['size_mb']) ? $app['size_mb'] : '';
                    $size_limit_mb = isset($app['size_limit_mb']) ? $app['size_limit_mb'] : '';
                    $timestamp = isset($app['timestamp']) ? $app['timestamp'] : date('Y-m-d H:i:s');
                    
                    // Format for display
                    $app_info = "$timestamp - <strong>$id</strong> - $name";
                    if (!empty($email)) {
                        $app_info .= " - Owner: $email";
                    }
                    if (!empty($size_mb)) {
                        $app_info .= " - Size: $size_mb MB";
                    }
                    if (!empty($size_limit_mb)) {
                        $app_info .= " - Limit: $size_limit_mb MB";
                    }
                    
                    $deleted_apps_html[] = $app_info;
                } else {
                    // Simple string
                    $deleted_apps_html[] = $app;
                }
            }
            
            // Add the formatted html list to operation details
            $operation_details['deleted_apps_formatted'] = $deleted_apps_html;
        }
        
        // For user management tasks, format deleted/warned users for display
        if ($task_type === 'user_management' && isset($operation_details['deleted_users']) && is_array($operation_details['deleted_users'])) {
            // Create a formatted html list of deleted users
            $deleted_users_html = array();
            
            foreach ($operation_details['deleted_users'] as $user) {
                $id = isset($user['id']) ? $user['id'] : 'unknown';
                $email = isset($user['email']) ? $user['email'] : 'unknown';
                $name = isset($user['name']) ? $user['name'] : '';
                $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                
                // Format for display
                $deleted_users_html[] = "$timestamp - <strong>$id</strong> - $email - $name";
            }
            
            // Add the formatted html list and count to operation details
            $operation_details['deleted_users_count'] = count($operation_details['deleted_users']);
            // $operation_details['deleted_users_formatted'] = $deleted_users_html;
        }
        
        if ($task_type === 'user_management' && isset($operation_details['warned_users']) && is_array($operation_details['warned_users'])) {
            // Create a formatted html list of warned users
            $warned_users_html = array();
            
            foreach ($operation_details['warned_users'] as $user) {
                $id = isset($user['id']) ? $user['id'] : 'unknown';
                $email = isset($user['email']) ? $user['email'] : 'unknown';
                $name = isset($user['name']) ? $user['name'] : '';
                $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                
                // Format for display
                $warned_users_html[] = "$timestamp - <strong>$id</strong> - $email - $name";
            }
            
            // Add the formatted html list and count to operation details
            $operation_details['warned_users_count'] = count($operation_details['warned_users']);
            $operation_details['warned_users_formatted'] = $warned_users_html;
        }
        
        // For db cleanup tasks, similarly format deleted/optimized items for display
        if ($task_type === 'db_cleanup' && isset($operation_details['deleted_items']) && is_array($operation_details['deleted_items'])) {
            // Create a formatted html list of deleted items
            $deleted_items_html = array();
            
            foreach ($operation_details['deleted_items'] as $item) {
                if (is_array($item)) {
                    $id = isset($item['id']) ? $item['id'] : 'unknown';
                    $timestamp = isset($item['timestamp']) ? $item['timestamp'] : date('Y-m-d H:i:s');
                    
                    $details = '';
                    if (isset($item['title'])) {
                        $details .= " - " . $item['title'];
                    }
                    if (isset($item['from'])) {
                        $details .= " - From: " . $item['from'];
                    }
                    if (isset($item['name'])) {
                        $details .= " - " . $item['name'];
                    }
                    if (isset($item['status'])) {
                        $details .= " - Status: " . $item['status'];
                    }
                    if (isset($item['modified'])) {
                        $details .= " - Modified: " . $item['modified'];
                    }
                    
                    // Format for display
                    $deleted_items_html[] = "$timestamp - <strong>$id</strong>$details";
                } else {
                    // For simple string values
                    $deleted_items_html[] = esc_html($item);
                }
            }
            
            // Add the formatted html list and count to operation details
            $operation_details['deleted_items_count'] = count($operation_details['deleted_items']);
            $operation_details['deleted_items_formatted'] = $deleted_items_html;
        }
        
        // For db cleanup optimize tasks, format optimized tables similarly
        if ($task_type === 'db_cleanup' && isset($task_args['task']) && $task_args['task'] === 'optimize' && 
            isset($operation_details['optimized_tables']) && is_array($operation_details['optimized_tables'])) {
            
            // Create a formatted html list of optimized tables
            $optimized_tables_html = array();
            
            foreach ($operation_details['optimized_tables'] as $table) {
                if (is_array($table)) {
                    $table_name = isset($table['table_name']) ? $table['table_name'] : 'unknown';
                    $size_mb = isset($table['size_mb']) ? $table['size_mb'] : 'unknown';
                    $timestamp = isset($table['timestamp']) ? $table['timestamp'] : date('Y-m-d H:i:s');
                    
                    // Format like the image cleanup display
                    $optimized_tables_html[] = "$timestamp - <strong>$table_name</strong> - Size: $size_mb MB";
                } else {
                    // For simple string values
                    $optimized_tables_html[] = esc_html($table);
                }
            }
            
            // Add the formatted html list and count to operation details
            $operation_details['optimized_tables_formatted'] = $optimized_tables_html;
            $operation_details['optimized_tables_count'] = count($operation_details['optimized_tables']);
        }
        
        // Ensure we have a batch number in the message for batch-based tasks
        if (($task_type === 'image_cleanup' || $task_type === 'db_cleanup' || $task_type === 'user_management' || $task_type === 'app_management') && 
            strpos($message, 'Batch') === false && strpos($message, 'completed') === false) {
            
            $batch_index = isset($operation_details['batch_index']) ? $operation_details['batch_index'] : 0;
            $message = sprintf(__('Batch %d processed. Will continue next run.', 'swiftspeed-siberian'), $batch_index);
        }
        
        // Store the result in a global variable for compatibility with other parts of the system
        global $swsib_last_task_result;
        $swsib_last_task_result = array(
            'success' => $success,
            'message' => $message,
            'operation_details' => $operation_details
        );
        
        return array(
            'success' => $success,
            'message' => $message,
            'operation_details' => $operation_details
        );
    }
    
    /**
     * Run an API command task - simplified to directly use SwiftSpeed_Siberian_API_Data
     * 
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_api_command($task_args) {
        // Get command from task args
        if (!isset($task_args['command']) && !isset($task_args['type'])) {
            if (isset($task_args['task'])) {
                $command = $task_args['task'];
            } else {
                return array(
                    'success' => false,
                    'message' => 'Missing command argument',
                    'operation_details' => array(
                        'error' => 'Missing command argument',
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
        } else {
            $command = isset($task_args['command']) ? $task_args['command'] : $task_args['type'];
        }
        
        self::log_message("Running API command: {$command}");
        
        // Get API connection details
        $options = get_option('swsib_options', array());
        $auto_login = isset($options['auto_login']) ? $options['auto_login'] : array();
        
        $siberian_url = isset($auto_login['siberian_url']) ? $auto_login['siberian_url'] : '';
        $api_user = isset($auto_login['api_user']) ? $auto_login['api_user'] : '';
        $api_password = isset($auto_login['api_password']) ? $auto_login['api_password'] : '';
        
        if (empty($siberian_url) || empty($api_user) || empty($api_password)) {
            return array(
                'success' => false,
                'message' => 'API credentials not configured.',
                'operation_details' => array(
                    'error' => 'API credentials not configured',
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
        
        // Directly use SwiftSpeed_Siberian_API_Data class - same approach as the "Run Now" button
        $api_data = new SwiftSpeed_Siberian_API_Data();
        $result = $api_data->execute_command($command, $siberian_url, $api_user, $api_password);
        
        // Format the operation details to make them more readable and detailed
        $operation_details = array(
            'command' => $command,
            'timestamp_formatted' => date('Y-m-d H:i:s', time()),
            'status' => $result['success'] ? 'Success' : 'Failed'
        );
        
        // Extract and format useful information from the API response
        if (isset($result['output'])) {
            // Extract API response details
            if (is_array($result['output'])) {
                if (isset($result['output']['success'])) {
                    $operation_details['api_success'] = $result['output']['success'] ? 'Yes' : 'No';
                }
                
                if (isset($result['output']['message'])) {
                    $operation_details['api_message'] = $result['output']['message'];
                }
                
                // Add more details from API response if available
                foreach ($result['output'] as $key => $value) {
                    if ($key !== 'success' && $key !== 'message') {
                        // Don't include complex nested objects directly
                        if (!is_array($value) && !is_object($value)) {
                            $operation_details['api_' . $key] = $value;
                        } elseif (is_array($value) && count($value) < 10) {
                            // Include small arrays with useful data
                            $operation_details['api_' . $key] = json_encode($value);
                        }
                    }
                }
                
                // For debugging, include a formatted string representation of the entire output
                $operation_details['api_response'] = json_encode($result['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                // If output is not an array, include it directly
                $operation_details['api_response'] = is_string($result['output']) ? 
                    $result['output'] : json_encode($result['output']);
            }
        }
        
        // If we have response data, include that too
        if (isset($result['data'])) {
            foreach ($result['data'] as $key => $value) {
                if (!is_array($value) && !is_object($value)) {
                    $operation_details['data_' . $key] = $value;
                }
            }
        }
        
        // Include server info from the request
        $operation_details['request_url'] = $siberian_url . (isset($result['request_endpoint']) ? $result['request_endpoint'] : '');
        
        // Replace the original operation_details with our formatted version
        $result['operation_details'] = $operation_details;
        
        return $result;
    }
    
    /**
     * Run an image cleanup task
     * 
     * @param string $task_type The task type (image_cleanup)
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_image_cleanup($task_type, $task_args) {
        self::log_message("Running image cleanup task");
        
        // Create image cleanup instance
        $image_cleanup = new SwiftSpeed_Siberian_Image_Cleanup();
        
        // Call the handle_scheduled_task method to process one batch
        $result = $image_cleanup->handle_scheduled_task($task_type, $task_args);
        
        // Format the deleted folders for display if they exist
        if (isset($result['operation_details']['deleted_folders']) && is_array($result['operation_details']['deleted_folders'])) {
            // Format each deleted folder entry
            $formatted_folders = array();
            foreach ($result['operation_details']['deleted_folders'] as $index => $folder) {
                $timestamp = isset($folder['timestamp']) ? $folder['timestamp'] : date('Y-m-d H:i:s');
                $id = isset($folder['id']) ? $folder['id'] : "unknown-$index";
                $path = isset($folder['path']) ? $folder['path'] : "/unknown/path/$index";
                
                // Format for UI display
                $formatted_folders[] = "$timestamp - <strong>$id</strong> - $path";
            }
            
            // Add formatted folders to operation details
            $result['operation_details']['deleted_folders_html'] = $formatted_folders;
            
            // Extract the batch number from the message or next_batch
            $batch_index = 0;
            if (isset($result['operation_details']['next_batch'])) {
                $batch_index = $result['operation_details']['next_batch'] - 1;
            } elseif (preg_match('/Batch (\d+) processed/', $result['message'], $matches)) {
                $batch_index = intval($matches[1]);
            }
            
            // Update the message with the correct batch number
            if (strpos($result['message'], 'Batch') !== false) {
                $result['message'] = "Batch $batch_index processed. Will continue next run.";
            }
        }
        
        return $result;
    }
    
    /**
     * Run a user management task - properly using the handle_scheduled_task method
     * 
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_user_management($task_args) {
        self::log_message("Running user management task");
        
        // Create user management instance and call the handle_scheduled_task method
        $user_management = new SwiftSpeed_Siberian_User_Management();
        $result = $user_management->handle_scheduled_task('user_management', $task_args);
        
        // Format the user details for display
        if (isset($result['operation_details']['deleted_users']) && is_array($result['operation_details']['deleted_users'])) {
            // Format deleted users for display
            $formatted_users = array();
            foreach ($result['operation_details']['deleted_users'] as $user) {
                $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                $id = isset($user['id']) ? $user['id'] : "unknown";
                $email = isset($user['email']) ? $user['email'] : "no-email";
                $name = isset($user['name']) ? $user['name'] : "";
                
                $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
            }
            
            // $result['operation_details']['deleted_users_formatted'] = $formatted_users;
            $result['operation_details']['deleted_users_count'] = count($result['operation_details']['deleted_users']);
        }
        
        if (isset($result['operation_details']['warned_users']) && is_array($result['operation_details']['warned_users'])) {
            // Format warned users for display
            $formatted_users = array();
            foreach ($result['operation_details']['warned_users'] as $user) {
                $timestamp = isset($user['timestamp']) ? $user['timestamp'] : date('Y-m-d H:i:s');
                $id = isset($user['id']) ? $user['id'] : "unknown";
                $email = isset($user['email']) ? $user['email'] : "no-email";
                $name = isset($user['name']) ? $user['name'] : "";
                
                $formatted_users[] = "$timestamp - <strong>$id</strong> - $email - $name";
            }
            
            $result['operation_details']['warned_users_formatted'] = $formatted_users;
            $result['operation_details']['warned_users_count'] = count($result['operation_details']['warned_users']);
        }
        
        // Make sure we have a summary
        if (isset($result['operation_details']['processed']) && isset($result['operation_details']['total'])) {
            $progress = isset($result['operation_details']['progress_percentage']) ? 
                      $result['operation_details']['progress_percentage'] : 0;
            
            $result['operation_details']['summary'] = sprintf(
                __('Processed %d out of %d users (%d%%).', 'swiftspeed-siberian'),
                $result['operation_details']['processed'],
                $result['operation_details']['total'],
                $progress
            );
        }
        
        // Ensure proper batch message format
        if (isset($result['operation_details']['status']) && $result['operation_details']['status'] === 'in_progress' && strpos($result['message'], 'Batch') === false) {
            $batch_index = isset($result['operation_details']['batch_index']) ? $result['operation_details']['batch_index'] : 0;
            $result['message'] = sprintf(__('Batch %d processed. Will continue next run.', 'swiftspeed-siberian'), $batch_index);
        }
        
        return $result;
    }
    
    /**
     * Run an app management task - using handle_scheduled_task method
     * 
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_app_management($task_args) {
        self::log_message("Running app management task");
        
        // Get app management task type from args
        $task_type = '';
        if (isset($task_args['task'])) {
            $task_type = $task_args['task'];
        }
        
        if (empty($task_type)) {
            return array(
                'success' => false,
                'message' => 'Missing task argument for app management',
                'operation_details' => array(
                    'error' => 'Missing task argument',
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
        
        // Create app management instance
        $app_management = new SwiftSpeed_Siberian_App_Management();
        
        // Use handle_scheduled_task method instead of process_app_management
        $result = $app_management->handle_scheduled_task('app_management_' . $task_type, $task_args);
        
        // Format the app details for display
        if (isset($result['operation_details']['deleted_apps_list']) && is_array($result['operation_details']['deleted_apps_list'])) {
            // Format deleted apps for display
            $formatted_apps = array();
            foreach ($result['operation_details']['deleted_apps_list'] as $app) {
                if (isset($app['action']) && $app['action'] === 'deleted') {
                    $timestamp = isset($app['timestamp']) ? $app['timestamp'] : date('Y-m-d H:i:s');
                    $id = isset($app['app_id']) ? $app['app_id'] : "unknown";
                    $name = isset($app['name']) ? $app['name'] : "unknown";
                    
                    $app_details = "$timestamp - <strong>$id</strong> - $name";
                    
                    if (isset($app['email']) && !empty($app['email'])) {
                        $app_details .= " - Owner: " . $app['email'];
                    }
                    
                    if (isset($app['size_mb'])) {
                        $app_details .= " - Size: " . $app['size_mb'] . " MB";
                    }
                    
                    if (isset($app['size_limit_mb'])) {
                        $app_details .= " - Limit: " . $app['size_limit_mb'] . " MB";
                    }
                    
                    $formatted_apps[] = array(
                        'app_id' => $id,
                        'name' => $name,
                        'size_mb' => isset($app['size_mb']) ? $app['size_mb'] : '',
                        'size_limit_mb' => isset($app['size_limit_mb']) ? $app['size_limit_mb'] : '',
                        'email' => isset($app['email']) ? $app['email'] : '',
                        'timestamp' => $timestamp
                    );
                }
            }
            
            // Add to operation details
            $result['operation_details']['deleted_apps'] = $formatted_apps;
            $result['operation_details']['deleted_apps_formatted'] = array_map(function($app) {
                $timestamp = $app['timestamp'];
                $id = $app['app_id'];
                $name = $app['name'];
                $email = !empty($app['email']) ? " - Owner: " . $app['email'] : "";
                $size = !empty($app['size_mb']) ? " - Size: " . $app['size_mb'] . " MB" : "";
                $limit = !empty($app['size_limit_mb']) ? " - Limit: " . $app['size_limit_mb'] . " MB" : "";
                
                return "$timestamp - <strong>$id</strong> - $name$email$size$limit";
            }, $formatted_apps);
        }
        
        // Make sure we have a summary
        if (isset($result['operation_details']['processed']) && isset($result['operation_details']['total'])) {
            $progress = isset($result['operation_details']['progress_percentage']) ? 
                      $result['operation_details']['progress_percentage'] : 0;
            
            $result['operation_details']['summary'] = sprintf(
                __('Processed %d out of %d apps (%d%%).', 'swiftspeed-siberian'),
                $result['operation_details']['processed'],
                $result['operation_details']['total'],
                $progress
            );
        }
        
        return $result;
    }
    
    /**
     * Run a database cleanup task
     * 
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_db_cleanup($task_args) {
        self::log_message("Running database cleanup task");
        
        if (!isset($task_args['task'])) {
            return array(
                'success' => false,
                'message' => 'Missing task argument',
                'operation_details' => array(
                    'error' => 'Missing task argument',
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
        
        $task = $task_args['task'];
        
        self::log_message("Running database cleanup task: {$task}");
        
        try {
            // Create DB cleanup instance with database connection
            $options = get_option('swsib_options', array());
            $db_options = isset($options['db_connect']) ? $options['db_connect'] : array();
            
            $db_connection = null;
            $db_name = null;
            
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
                    $db_name = $db_options['database'];
                }
            }
            
            if (!$db_connection) {
                return array(
                    'success' => false,
                    'message' => 'Database connection failed or not configured.',
                    'operation_details' => array(
                        'error' => 'Database connection failed or not configured.',
                        'timestamp_formatted' => date('Y-m-d H:i:s', time())
                    )
                );
            }
            
            $db_cleanup = new SwiftSpeed_Siberian_DB_Cleanup($db_connection, $db_name);
            
            // Use handle_scheduled_task method instead of direct task methods
            $result = $db_cleanup->handle_scheduled_task('db_cleanup', array('task' => $task));
            
            // Ensure operation_details has proper formatting
            $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
            
            if (!isset($operation_details['timestamp_formatted'])) {
                $operation_details['timestamp_formatted'] = date('Y-m-d H:i:s', time());
            }
            
            if (!isset($operation_details['task'])) {
                $operation_details['task'] = $task;
            }
            
            // Make sure we have a proper message
            if (isset($operation_details['batch_index'])) {
                $result['message'] = sprintf(__('Batch %d processed. Will continue next run.', 'swiftspeed-siberian'), 
                                          $operation_details['batch_index']);
            } elseif (isset($operation_details['completed']) && $operation_details['completed']) {
                $result['message'] = "All batches processed. Task completed.";
            }
            
            // Update the result with improved operation details
            $result['operation_details'] = $operation_details;
            
            return $result;
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error running database cleanup task: ' . $e->getMessage(),
                'operation_details' => array(
                    'error' => $e->getMessage(),
                    'timestamp_formatted' => date('Y-m-d H:i:s', time())
                )
            );
        }
    }
    
    /**
     * Run a WordPress cleanup task - call directly to the handle_scheduled_task method
     * 
     * @param array $task_args Arguments for the task
     * @return array Success status and message
     */
    private static function run_wp_cleanup($task_args) {
        self::log_message("Running WordPress cleanup task");
        
        // Create WP tasks instance and call handle_scheduled_task
        $wp_tasks = new SwiftSpeed_Siberian_WP_Tasks();
        return $wp_tasks->handle_scheduled_task('wp_cleanup', $task_args);
    }
    
    /**
     * AJAX handler for getting task progress
     */
    public static function ajax_get_task_progress() {
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
        
        // Get task data from transient
        $progress_data = get_transient('swsib_task_' . $task_id);
        
        if (!$progress_data) {
            wp_send_json_error(array('message' => 'No progress data found for task: ' . $task_id));
            return;
        }
        
        wp_send_json_success($progress_data);
    }
    
    /**
     * AJAX handler for running all due tasks
     */
    public static function ajax_run_all_due_tasks() {
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
        
        // Run all due tasks
        $scheduler = new SwiftSpeed_Siberian_Scheduler();
        $scheduler->force_check_tasks();
        
        wp_send_json_success(array('message' => 'All due tasks have been executed.'));
    }
}