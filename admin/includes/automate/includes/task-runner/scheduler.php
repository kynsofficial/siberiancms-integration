<?php
/**
 * Task Scheduler for Automation
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_Scheduler {
    
    /**
     * Debug mode for verbose logging
     */
    private $debug_mode = true; // Set to true for more verbose logging

    /**
     * Default log limit
     */
    const DEFAULT_LOG_LIMIT = 100;
    
    /**
     * Maximum log limit
     */
    const MAX_LOG_LIMIT = 200;

    /**
     * Flag to prevent recursive task checking
     */
    private $is_processing_tasks = false;
    
    /**
     * Flag to prevent recursive option updates
     */
    private $is_updating_options = false;

    /**
     * Last log time for throttling logs
     */
    private $last_log_time = 0;
    
    /**
     * Last check time for tasks
     */
    private $last_check_time = 0;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add hook to ensure schedules are registered
        add_action('init', array($this, 'ensure_schedules_registered'));
        
        // Add a filter to check tasks on admin_init (improves reliability)
        add_action('admin_init', array($this, 'maybe_check_tasks'));
        
        // Add a filter to check tasks after options are updated
        add_action('updated_option', array($this, 'check_after_option_update'), 10, 3);
    }
    
    /**
     * Ensure schedules are registered
     */
    public function ensure_schedules_registered() {
        // Get schedules to force registration
        $schedules = wp_get_schedules();
        if (!isset($schedules['swsib_every_minute'])) {
            // Force register our schedules if they don't exist yet
            $filter = $GLOBALS['wp_filter']['cron_schedules'] ?? null;
            if ($filter && method_exists($filter, 'apply_filters')) {
                $filter->apply_filters($schedules, array());
            }
        }
    }
    
    /**
     * Log message with throttling to prevent excessive logging
     */
    private function log_message($message, $force = false) {
        // If it's been less than 60 seconds since the last log and not forced, skip logging
        $current_time = time();
        if (!$force && !$this->debug_mode && ($current_time - $this->last_log_time) < 60) {
            return;
        }
        
        $this->last_log_time = $current_time;
        
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * Schedule a task to run at a specific interval
     */
    public function schedule_task($task_type, $task_args = array(), $interval = 86400) {
        // Generate a unique key for this task
        $task_key = $this->generate_task_key($task_type, $task_args);
        
        // Get current scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // FIXED: Before adding a new task, clean up duplicate tasks for API commands
        // This prevents multiple tasks for the same command but with different keys
        if ($task_type === 'api_command' && isset($task_args['command'])) {
            $command = $task_args['command'];
            $this->cleanup_duplicate_api_tasks($command, $scheduled_tasks);
        }
        
        // Calculate next run time - ensure it's in the future
        $next_run = time() + $interval;
        
        // Update the task schedule
        $scheduled_tasks[$task_key] = array(
            'task_type' => $task_type,
            'task_args' => $task_args,
            'interval' => $interval,
            'next_run' => $next_run,
            'last_run' => 0,
            'status' => 'scheduled',
            'created_at' => time()
        );
        
        // Prevent recursion during option update
        $this->is_updating_options = true;
        
        // Save the updated schedule
        update_option('swsib_scheduled_tasks', $scheduled_tasks);
        
        // Reset the flag
        $this->is_updating_options = false;
        
        $this->log_message("Scheduled task {$task_type} with key {$task_key} to run every " . $this->format_interval($interval) . ", next run at " . date('Y-m-d H:i:s', $next_run), true);
        
        // Force immediate check to ensure the task is scheduled properly
        $this->schedule_next_check(false);
        
        return true;
    }
    
    /**
     * FIXED: New function to clean up duplicate API tasks for the same command
     * This prevents the issue where multiple tasks are created for the same command
     */
    private function cleanup_duplicate_api_tasks($command, &$scheduled_tasks) {
        $tasks_to_remove = array();
        
        // Look for tasks with the same command
        foreach ($scheduled_tasks as $key => $task) {
            if ($task['task_type'] === 'api_command') {
                $task_command = null;
                
                // Check both 'command' and 'type' keys
                if (isset($task['task_args']['command'])) {
                    $task_command = $task['task_args']['command'];
                } elseif (isset($task['task_args']['type'])) {
                    $task_command = $task['task_args']['type'];
                }
                
                // If this task is for the same command, mark it for removal
                if ($task_command === $command) {
                    $tasks_to_remove[] = $key;
                    $this->log_message("Found duplicate API task for command '{$command}' with key '{$key}', will be replaced", true);
                }
            }
        }
        
        // Remove the duplicates
        foreach ($tasks_to_remove as $key) {
            unset($scheduled_tasks[$key]);
        }
    }
    
/**
 * Schedule the next task check
 */
public function schedule_next_check($force = false) {
    // Schedule the next check based on the shortest interval task or a minute, whichever is shorter
    $shortest_interval = $this->get_shortest_task_interval();
    
    // Default to one minute if no tasks or for immediate execution
    if ($shortest_interval === false) {
        $shortest_interval = 60; // 1 minute
    }
    
    // Make sure we don't check more often than every 30 seconds
    $shortest_interval = max(30, $shortest_interval);
    
    // Only schedule if not already scheduled or if force is true
    if ($force || !wp_next_scheduled('swsib_check_scheduled_tasks')) {
        // Clear any existing scheduled checks
        wp_clear_scheduled_hook('swsib_check_scheduled_tasks');
        
        // Schedule check in the immediate future if forced (10 seconds), otherwise use shortest interval
        $next_check_time = time() + ($force ? 10 : $shortest_interval);
        wp_schedule_single_event($next_check_time, 'swsib_check_scheduled_tasks');
        
        $this->log_message("Scheduled next task check at " . date('Y-m-d H:i:s', $next_check_time), $this->debug_mode);
        
        // Also make sure our recurrent check hook is scheduled
        if (!wp_next_scheduled('swsib_recurrent_task_check')) {
            // Ensure we use a valid schedule if swsib_every_minute is not registered yet
            $schedules = wp_get_schedules();
            $schedule = isset($schedules['swsib_every_minute']) ? 'swsib_every_minute' : 'hourly';
            wp_schedule_event(time() + 30, $schedule, 'swsib_recurrent_task_check');
        }
    }
    
    // Store last check time to prevent duplicate checks
    $this->last_check_time = time();
    
    // Prevent recursion during option update
    $this->is_updating_options = true;
    update_option('swsib_last_check_time', $this->last_check_time);
    $this->is_updating_options = false;
}
    
    /**
     * Check if a task check is due based on admin activity
     */
    public function maybe_check_tasks() {
        // Prevent recursive calls
        if ($this->is_processing_tasks) {
            $this->log_message("Task processing already in progress in maybe_check_tasks, skipping.", true);
            return;
        }
        
        // Get the last check time
        $last_check_time = get_option('swsib_last_check_time', 0);
        $current_time = time();
        
        // If it's been more than 5 minutes since the last check, force a check
        if (($current_time - $last_check_time) > 300) {
            $this->log_message("Admin activity triggered task check (last check was " . $this->format_interval($current_time - $last_check_time) . " ago)", true);
            
            // Set processing flag
            $this->is_processing_tasks = true;
            
            // Run tasks
            $this->force_check_tasks();
            
            // Reset processing flag
            $this->is_processing_tasks = false;
            
            // Update last check time
            $this->is_updating_options = true;
            update_option('swsib_last_check_time', $current_time);
            $this->is_updating_options = false;
        }
    }
    
    /**
     * Check tasks after specific options are updated
     */
    public function check_after_option_update($option_name, $old_value, $new_value) {
        // Skip if we're already processing tasks or if we triggered this update ourselves
        if ($this->is_processing_tasks || $this->is_updating_options) {
            $this->log_message("Skipping option update trigger for {$option_name} - already processing tasks or update was triggered internally", $this->debug_mode);
            return;
        }
        
        // If this is our options being updated, check tasks
        if ($option_name === 'swsib_options' || $option_name === 'swsib_scheduled_tasks') {
            $this->log_message("Option update triggered task check: {$option_name}", true);
            
            // Set the flags to prevent recursion
            $this->is_processing_tasks = true;
            
            // Run the tasks
            $this->force_check_tasks();
            
            // Reset the processing flag
            $this->is_processing_tasks = false;
            
            // Update last check time - using flag to prevent recursion
            $this->is_updating_options = true;
            update_option('swsib_last_check_time', time());
            $this->is_updating_options = false;
        }
    }
    
    /**
     * Get the shortest interval among all scheduled tasks
     */
    private function get_shortest_task_interval() {
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        if (empty($scheduled_tasks)) {
            return false;
        }
        
        $shortest_interval = PHP_INT_MAX;
        
        foreach ($scheduled_tasks as $task) {
            if (isset($task['interval']) && $task['interval'] < $shortest_interval) {
                $shortest_interval = $task['interval'];
            }
        }
        
        return $shortest_interval;
    }
    
    /**
     * Unschedule a task
     */
    public function unschedule_task($task_type, $task_args = array()) {
        // Generate a unique key for this task
        $task_key = $this->generate_task_key($task_type, $task_args);
        
        // Get current scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Remove the task if it exists
        if (isset($scheduled_tasks[$task_key])) {
            unset($scheduled_tasks[$task_key]);
            
            // Set flag to prevent recursion
            $this->is_updating_options = true;
            update_option('swsib_scheduled_tasks', $scheduled_tasks);
            $this->is_updating_options = false;
            
            $this->log_message("Unscheduled task {$task_type} with key {$task_key}", true);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Generate a unique key for a task
     * FIXED: Improved to handle API command tasks consistently
     */
    private function generate_task_key($task_type, $task_args = array()) {
        // Ensure task type is valid
        $task_type = sanitize_key($task_type);
        
        // Sort args to ensure consistent key generation
        if (!empty($task_args)) {
            ksort($task_args);
        }
        
        // Generate a key based on task type and serialized args
        $task_key = $task_type;
        
        // FIXED: Special handling for API command tasks to ensure consistent keys
        if ($task_type === 'api_command') {
            // For API commands, create a consistent key based on the command name
            $command = '';
            
            // Check both possible locations for the command name
            if (isset($task_args['command'])) {
                $command = sanitize_key($task_args['command']);
            } elseif (isset($task_args['type'])) {
                $command = sanitize_key($task_args['type']);
            }
            
            if (!empty($command)) {
                // Use a simplified key format for API commands
                return $task_type . '_' . $command;
            }
        }
        
        // Standard key generation for other task types
        if (!empty($task_args)) {
            // For simple task args, include them in the key
            if (isset($task_args['type'])) {
                $task_key .= '_' . sanitize_key($task_args['type']);
            }
            
            if (isset($task_args['task'])) {
                $task_key .= '_' . sanitize_key($task_args['task']);
            }
            
            // FIXED: Also include command in the key
            if (isset($task_args['command'])) {
                $task_key .= '_' . sanitize_key($task_args['command']);
            }
            
            // For more complex args, hash them
            $args_string = json_encode($task_args);
            $task_key .= '_' . md5($args_string);
        }
        
        return $task_key;
    }
    
    /**
     * Format an interval in seconds to a human-readable string
     */
    private function format_interval($interval) {
        if ($interval < 60) {
            return $interval . ' seconds';
        } elseif ($interval < 3600) {
            $minutes = floor($interval / 60);
            return $minutes . ' minute' . ($minutes != 1 ? 's' : '');
        } elseif ($interval < 86400) {
            $hours = floor($interval / 3600);
            return $hours . ' hour' . ($hours != 1 ? 's' : '');
        } elseif ($interval < 604800) {
            $days = floor($interval / 86400);
            return $days . ' day' . ($days != 1 ? 's' : '');
        } elseif ($interval < 2592000) {
            $weeks = floor($interval / 604800);
            return $weeks . ' week' . ($weeks != 1 ? 's' : '');
        } elseif ($interval < 31536000) {
            $months = floor($interval / 2592000);
            return $months . ' month' . ($months != 1 ? 's' : '');
        } else {
            $years = floor($interval / 31536000);
            return $years . ' year' . ($years != 1 ? 's' : '');
        }
    }
    
    /**
     * Check if automation is enabled for a task
     * FIXED: Improved handling of API command tasks
     */
    private function is_automation_enabled($task_type, $task_args) {
        $options = get_option('swsib_options', array());
        
        // Default to not enabled
        $enabled = false;
        
        // Make sure automate settings exist
        if (!isset($options['automate'])) {
            $this->log_message("No automation settings found in options", false);
            return false;
        }
        
        // Debug log the task type and args
        $this->log_message("Checking if automation is enabled for task_type: {$task_type}", $this->debug_mode);
        
        switch ($task_type) {
            case 'wp_cleanup':
                if (isset($task_args['task'])) {
                    $task = $task_args['task'];
                    if (isset($options['automate']['wp_tasks'][$task]['enabled'])) {
                        $enabled = !empty($options['automate']['wp_tasks'][$task]['enabled']);
                        $this->log_message("WP Cleanup task '{$task}' enabled: " . ($enabled ? 'yes' : 'no'), $this->debug_mode);
                    }
                }
                break;
                
            case 'api_command':
                // FIXED: Improved handling of API command settings lookup
                $command = '';
                
                // First check for command key, then type key
                if (isset($task_args['command'])) {
                    $command = $task_args['command'];
                } elseif (isset($task_args['type'])) {
                    $command = $task_args['type'];
                }
                
                if (!empty($command) && isset($options['automate']['api'][$command]['enabled'])) {
                    $enabled = !empty($options['automate']['api'][$command]['enabled']);
                    $this->log_message("API Command task '{$command}' enabled: " . ($enabled ? 'yes' : 'no'), $this->debug_mode);
                } else {
                    $this->log_message("API Command task '{$command}' not found in settings or disabled", $this->debug_mode);
                }
                break;
                
            case 'db_cleanup':
                if (isset($task_args['task'])) {
                    $task = $task_args['task'];
                    if (isset($options['automate']['db_cleanup'][$task]['enabled'])) {
                        $enabled = !empty($options['automate']['db_cleanup'][$task]['enabled']);
                        $this->log_message("DB Cleanup task '{$task}' enabled: " . ($enabled ? 'yes' : 'no'), $this->debug_mode);
                    }
                }
                break;
                
            case 'image_cleanup':
                // IMPROVED: Direct check for image_cleanup enabled status
                if (isset($options['automate']['image_cleanup']['enabled'])) {
                    $enabled = !empty($options['automate']['image_cleanup']['enabled']);
                    $this->log_message("Image Cleanup task enabled: " . ($enabled ? 'yes' : 'no'), true);
                }
                break;
                
            case 'user_management':
                if (isset($task_args['task'])) {
                    $task = $task_args['task'];
                    if (isset($options['automate']['user_management'][$task]['enabled'])) {
                        $enabled = !empty($options['automate']['user_management'][$task]['enabled']);
                        $this->log_message("User Management task '{$task}' enabled: " . ($enabled ? 'yes' : 'no'), $this->debug_mode);
                    }
                }
                break;
                
            case 'app_management':
                if (isset($task_args['task'])) {
                    $task = $task_args['task'];
                    if (isset($options['automate']['app_management'][$task]['enabled'])) {
                        $enabled = !empty($options['automate']['app_management'][$task]['enabled']);
                        $this->log_message("App Management task '{$task}' enabled: " . ($enabled ? 'yes' : 'no'), $this->debug_mode);
                    }
                }
                break;
        }
        
        return $enabled;
    }
    
    /**
     * Force execute all due tasks
     * IMPROVED: Better handling of task execution and more detailed logging
     * FIXED: Added protection against recursive execution
     */
    public function force_check_tasks() {
        // Prevent recursive execution
        if ($this->is_processing_tasks) {
            $this->log_message("Task processing already in progress, skipping force_check_tasks.", true);
            return;
        }
        
        // Set the processing flag to prevent recursion
        $this->is_processing_tasks = true;
        
        $current_time = time();
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        if (empty($scheduled_tasks)) {
            $this->log_message("No scheduled tasks found", $this->debug_mode);
            $this->is_processing_tasks = false;
            return;
        }
        
        $tasks_executed = 0;
        $tasks_found = 0;
        $tasks_skipped = 0;
        $tasks_already_running = 0;
        
        $this->log_message("Checking for due tasks at " . date('Y-m-d H:i:s'), $this->debug_mode);
        
        foreach ($scheduled_tasks as $task_key => $task) {
            // Find tasks that are due to run
            if (isset($task['next_run']) && $task['next_run'] <= $current_time) {
                $tasks_found++;
                $this->log_message("Found due task: {$task_key}, scheduled to run at " . date('Y-m-d H:i:s', $task['next_run']), $this->debug_mode);
                
                // Add additional task details for debugging
                $task_details = json_encode($task);
                $this->log_message("Task details: {$task_details}", $this->debug_mode);
                
                // IMPROVED: Check if automation is enabled for this task with more verbose logging
                if (!$this->is_automation_enabled($task['task_type'], $task['task_args'])) {
                    $this->log_message("Skipping task {$task_key} - automation is disabled", true);
                    $tasks_skipped++;
                    continue;
                }
                
                // Reset stuck tasks
                if (isset($task['status']) && $task['status'] === 'running') {
                    if (isset($task['last_run']) && ($current_time - $task['last_run']) > 900) {
                        $this->log_message("Resetting stuck task: {$task_key} (running for " . $this->format_interval($current_time - $task['last_run']) . ")", true);
                        $scheduled_tasks[$task_key]['status'] = 'scheduled';
                        
                        // Use flag to prevent recursion
                        $this->is_updating_options = true;
                        update_option('swsib_scheduled_tasks', $scheduled_tasks);
                        $this->is_updating_options = false;
                    } else {
                        $this->log_message("Task {$task_key} is already running (started " . $this->format_interval($current_time - $task['last_run']) . " ago)", $this->debug_mode);
                        $tasks_already_running++;
                        continue; // Skip if still legitimately running
                    }
                }
                
                $this->log_message("Executing due task: {$task_key}", true);
                
                // Mark as running
                $scheduled_tasks[$task_key]['status'] = 'running';
                $scheduled_tasks[$task_key]['last_run'] = $current_time;
                
                // Use flag to prevent recursion
                $this->is_updating_options = true;
                update_option('swsib_scheduled_tasks', $scheduled_tasks);
                $this->is_updating_options = false;
                
                try {
                    // Execute task directly
                    $task_runner = new SwiftSpeed_Siberian_Task_Runner();
                    $this->log_message("Calling task runner for task: {$task_key}", $this->debug_mode);
                    $result = $task_runner->run_scheduled_task($task['task_type'], $task['task_args']);
                    
                    $tasks_executed++;
                    
                    // Extract operation details if present
                    $operation_details = isset($result['operation_details']) ? $result['operation_details'] : array();
                    
                    // Update task status based on result
                    $this->mark_task_completed(
                        $task['task_type'],
                        $task['task_args'],
                        isset($result['success']) ? $result['success'] : true,
                        isset($result['message']) ? $result['message'] : 'Task executed',
                        $operation_details
                    );
                    
                    $this->log_message("Task {$task_key} executed successfully: " . json_encode($result), true);
                } catch (Exception $e) {
                    $this->log_message("Error executing task {$task_key}: " . $e->getMessage(), true);
                    
                    // Reset task status so it will run again
                    $scheduled_tasks[$task_key]['status'] = 'scheduled';
                    
                    // Use flag to prevent recursion
                    $this->is_updating_options = true;
                    update_option('swsib_scheduled_tasks', $scheduled_tasks);
                    $this->is_updating_options = false;
                }
            }
        }
        
        if ($tasks_found > 0) {
            $this->log_message("Force check found {$tasks_found} due tasks, executed {$tasks_executed} tasks, skipped {$tasks_skipped} tasks (automation disabled), {$tasks_already_running} tasks already running", true);
        } else {
            $this->log_message("Force check found no due tasks", $this->debug_mode);
        }
        
        // Reset the processing flag
        $this->is_processing_tasks = false;
    }
    
    /**
     * Check for tasks that need to be run
     */
    public function check_scheduled_tasks() {
        // Skip if already processing
        if ($this->is_processing_tasks) {
            $this->log_message("Task processing already in progress, skipping check_scheduled_tasks.", true);
            return;
        }
        
        // Set the processing flag
        $this->is_processing_tasks = true;
        
        // Call force_check_tasks which handles everything
        $this->force_check_tasks();
        
        // Reset the processing flag
        $this->is_processing_tasks = false;
    }
  
    /**
     * Recurrent task check - runs every minute via WordPress cron
     * IMPROVED: Better handling of task execution and more detailed logging
     */
    public function recurrent_task_check() {
        // Skip if already processing
        if ($this->is_processing_tasks) {
            $this->log_message("Task processing already in progress, skipping recurrent_task_check.", true);
            return;
        }
        
        $this->log_message("Recurrent task check triggered at " . date('Y-m-d H:i:s'), $this->debug_mode);
        
        // Update last check time
        $this->is_updating_options = true;
        update_option('swsib_last_check_time', time());
        $this->is_updating_options = false;
        
        // Set the processing flag
        $this->is_processing_tasks = true;
        
        // Call force_check_tasks which handles everything
        $this->force_check_tasks();
        
        // Reset the processing flag
        $this->is_processing_tasks = false;
    }

    /**
     * Update a task's schedule after it has been run
     * FIXED: Ensure consistent task argument handling
     */
    private function update_task_schedule($task_key, $interval) {
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Update the task if it exists
        if (isset($scheduled_tasks[$task_key])) {
            // Calculate next run time - ensure it's in the future
            $next_run = time() + $interval;
            
            $scheduled_tasks[$task_key]['next_run'] = $next_run;
            $scheduled_tasks[$task_key]['status'] = 'scheduled';
            
            // Save the updated schedule - using flag to prevent recursion
            $this->is_updating_options = true;
            update_option('swsib_scheduled_tasks', $scheduled_tasks);
            $this->is_updating_options = false;
            
            $this->log_message("Updated schedule for task {$task_key}, next run at " . date('Y-m-d H:i:s', $next_run), true);
        }
    }
    
    /**
     * Mark a task as completed
     * FIXED: Maintain consistent task arguments when rescheduling
     */
    public function mark_task_completed($task_type, $task_args = array(), $success = true, $message = '', $operation_details = array()) {
        // FIXED: For API commands, ensure we have standardized task args
        if ($task_type === 'api_command') {
            $command = '';
            
            // Extract the command name, checking both possible locations
            if (isset($task_args['command'])) {
                $command = $task_args['command'];
            } elseif (isset($task_args['type'])) {
                $command = $task_args['type'];
            }
            
            if (!empty($command)) {
                // Standardize the task args to always include both command and type
                $task_args = array(
                    'command' => $command,
                    'type' => $command
                );
                
                $this->log_message("Standardized API command task args for '{$command}'", $this->debug_mode);
            }
        }
        
        // Generate a unique key for this task
        $task_key = $this->generate_task_key($task_type, $task_args);
        
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Update the task if it exists
        if (isset($scheduled_tasks[$task_key])) {
            $interval = isset($scheduled_tasks[$task_key]['interval']) ? 
                        $scheduled_tasks[$task_key]['interval'] : 86400;
            
            $this->update_task_schedule($task_key, $interval);
            
            $this->log_message("Marked task {$task_key} as completed with status: " . 
                              ($success ? 'success' : 'failed') . ", message: {$message}", true);
            
            // Trigger immediate check for more tasks
            $this->schedule_next_check(false);
        } else {
            $this->log_message("Task {$task_key} not found in scheduled tasks when marking as completed", true);
            
            // FIXED: For API commands, if task not found, look for it with different arg formats
            if ($task_type === 'api_command' && !empty($task_args)) {
                $this->log_message("Attempting to find API command task with alternative key formats", true);
                $this->find_and_update_api_task($task_type, $task_args, $success, $message, $operation_details);
            }
        }
        
        // Record the task execution in the history
        $this->record_task_history($task_type, $task_args, $success, $message, $operation_details);
    }
    
    /**
     * FIXED: New function to find and update API tasks that might have been created with different arg formats
     */
    private function find_and_update_api_task($task_type, $task_args, $success, $message, $operation_details) {
        if ($task_type !== 'api_command' || empty($task_args)) {
            return false;
        }
        
        $command = '';
        
        // Get the command name
        if (isset($task_args['command'])) {
            $command = $task_args['command'];
        } elseif (isset($task_args['type'])) {
            $command = $task_args['type'];
        }
        
        if (empty($command)) {
            return false;
        }
        
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        $found = false;
        
        // Look for tasks with matching command in any format
        foreach ($scheduled_tasks as $key => $task) {
            if ($task['task_type'] === 'api_command') {
                $task_command = '';
                
                // Check for command or type
                if (isset($task['task_args']['command'])) {
                    $task_command = $task['task_args']['command'];
                } elseif (isset($task['task_args']['type'])) {
                    $task_command = $task['task_args']['type'];
                }
                
                // If this is our command, update it
                if ($task_command === $command) {
                    $found = true;
                    $interval = isset($task['interval']) ? $task['interval'] : 86400;
                    
                    // Standardize the task args
                    $scheduled_tasks[$key]['task_args'] = array(
                        'command' => $command,
                        'type' => $command
                    );
                    
                    // Update next run
                    $scheduled_tasks[$key]['next_run'] = time() + $interval;
                    $scheduled_tasks[$key]['status'] = 'scheduled';
                    $scheduled_tasks[$key]['last_run'] = time();
                    
                    $this->log_message("Found and updated API task with alternative format for command '{$command}', key: {$key}", true);
                    break;
                }
            }
        }
        
        // If we found and updated a task, save the changes
        if ($found) {
            // Use flag to prevent recursion
            $this->is_updating_options = true;
            update_option('swsib_scheduled_tasks', $scheduled_tasks);
            $this->is_updating_options = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * Record a task execution in the history
     */
    private function record_task_history($task_type, $task_args = array(), $success = true, $message = '', $operation_details = array()) {
        // Get task history - initialize if not exists
        $task_history = get_option('swsib_task_history', array());
        if (!is_array($task_history)) {
            $task_history = array();
        }
        
        // Get the configured log limit
        $log_limit = $this->get_log_limit();
        
        // Try to get operation details from the global task runner result if not provided
        if (empty($operation_details) && function_exists('swsib')) {
            global $swsib_last_task_result;
            if (!empty($swsib_last_task_result) && isset($swsib_last_task_result['operation_details'])) {
                $operation_details = $swsib_last_task_result['operation_details'];
                $this->log_message("Found operation details from last task result", true);
            }
        }
        
        // Make sure we have at least a timestamp in operation details
        if (empty($operation_details)) {
            $operation_details = array('timestamp' => time());
        } else if (!isset($operation_details['timestamp'])) {
            $operation_details['timestamp'] = time();
        }
        
        // Add the new entry to the beginning of the array
        array_unshift($task_history, array(
            'task_type' => $task_type,
            'task_args' => $task_args,
            'timestamp' => time(),
            'success' => $success,
            'message' => $message,
            'operation_details' => $operation_details
        ));
        
        // Trim the array to the configured limit
        if (count($task_history) > $log_limit) {
            $task_history = array_slice($task_history, 0, $log_limit);
        }
        
        // Log the operation for debugging
        $this->log_message("Recording task history for {$task_type}: " . substr(json_encode($operation_details), 0, 200) . "...", true);
        
        // Save the updated history - using flag to prevent recursion
        $this->is_updating_options = true;
        update_option('swsib_task_history', $task_history);
        $this->is_updating_options = false;
    }
    
    /**
     * Get the configured log limit
     */
    private function get_log_limit() {
        $options = get_option('swsib_options', array());
        $log_limit = isset($options['automate']['action_logs_limit']) ? 
                    intval($options['automate']['action_logs_limit']) : self::DEFAULT_LOG_LIMIT;
        
        // Ensure limit is valid
        $log_limit = min(self::MAX_LOG_LIMIT, max(50, $log_limit));
        
        return $log_limit;
    }
    
    /**
     * Get task history
     */
    public function get_task_history($limit = 10) {
        // Get task history
        $task_history = get_option('swsib_task_history', array());
        
        // Limit the number of entries
        return array_slice($task_history, 0, $limit);
    }
    
    /**
     * Get all scheduled tasks
     */
    public function get_scheduled_tasks() {
        return get_option('swsib_scheduled_tasks', array());
    }
    
    /**
     * AJAX handler for getting task schedule
     */
    public function ajax_get_task_schedule() {
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
        
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Format tasks for display
        $formatted_tasks = array();
        $current_time = time();
        
        foreach ($scheduled_tasks as $task_key => $task) {
            // Check if automation is enabled for this task
            $automation_enabled = $this->is_automation_enabled($task['task_type'], $task['task_args']);
            
            $time_until_next_run = isset($task['next_run']) ? max(0, $task['next_run'] - $current_time) : 0;
            
            $formatted_tasks[] = array(
                'key' => $task_key,
                'type' => isset($task['task_type']) ? $task['task_type'] : 'unknown',
                'args' => isset($task['task_args']) ? $task['task_args'] : array(),
                'interval' => isset($task['interval']) ? $this->format_interval($task['interval']) : 'unknown',
                'next_run' => isset($task['next_run']) ? date('Y-m-d H:i:s', $task['next_run']) : 'unknown',
                'last_run' => isset($task['last_run']) && $task['last_run'] ? date('Y-m-d H:i:s', $task['last_run']) : 'Never',
                'status' => isset($task['status']) ? $task['status'] : 'unknown',
                'time_until_next_run' => $this->format_interval($time_until_next_run),
                'automation_enabled' => $automation_enabled
            );
        }
        
        // Get WP cron events for reference
        $cron_events = array();
        $cron_array = _get_cron_array();
        
        if (!empty($cron_array)) {
            foreach ($cron_array as $timestamp => $hooks) {
                foreach ($hooks as $hook => $events) {
                    foreach ($events as $key => $event) {
                        if ($hook === 'swsib_run_scheduled_task' || $hook === 'swsib_check_scheduled_tasks' || $hook === 'swsib_hourly_tasks_check' || $hook === 'swsib_recurrent_task_check') {
                            $cron_events[] = array(
                                'hook' => $hook,
                                'timestamp' => $timestamp,
                                'time' => date('Y-m-d H:i:s', $timestamp),
                                'args' => $event['args']
                            );
                        }
                    }
                }
            }
        }
        
        wp_send_json_success(array(
            'tasks' => $formatted_tasks,
            'cron_events' => $cron_events
        ));
    }
    
    /**
     * AJAX handler for getting task history
     */
    public function ajax_get_task_history() {
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
        
        // Get limit
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        
        // Get task history
        $task_history = $this->get_task_history($limit);
        
        // Format history for display
        $formatted_history = array();
        
        foreach ($task_history as $entry) {
            $formatted_history[] = array(
                'type' => isset($entry['task_type']) ? $entry['task_type'] : 'unknown',
                'args' => isset($entry['task_args']) ? $entry['task_args'] : array(),
                'timestamp' => isset($entry['timestamp']) ? date('Y-m-d H:i:s', $entry['timestamp']) : 'unknown',
                'success' => isset($entry['success']) ? $entry['success'] : false,
                'message' => isset($entry['message']) ? $entry['message'] : '',
                'operation_details' => isset($entry['operation_details']) ? $entry['operation_details'] : array()
            );
        }
        
        wp_send_json_success(array(
            'history' => $formatted_history
        ));
    }
    
    /**
     * AJAX handler for running a task immediately
     */
    public function ajax_run_task_now() {
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
        
        // Get task key
        $task_key = isset($_POST['task_key']) ? sanitize_text_field($_POST['task_key']) : '';
        
        if (empty($task_key)) {
            wp_send_json_error(array('message' => 'Task key not provided.'));
            return;
        }
        
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Check if task exists
        if (!isset($scheduled_tasks[$task_key])) {
            wp_send_json_error(array('message' => 'Task not found.'));
            return;
        }
        
        // Get task details
        $task = $scheduled_tasks[$task_key];
        
        // Prevent recursive execution
        if ($this->is_processing_tasks) {
            wp_send_json_error(array('message' => 'Task processing already in progress, try again later.'));
            return;
        }
        
        // Set processing flag
        $this->is_processing_tasks = true;
        
        // Run the task immediately - Manual runs don't need to check automation status
        $this->log_message("Manually running task {$task_key} of type {$task['task_type']}", true);
        
        // Update task status to running
        $scheduled_tasks[$task_key]['status'] = 'running';
        $scheduled_tasks[$task_key]['last_run'] = time();
        
        // Use flag to prevent recursion
        $this->is_updating_options = true;
        update_option('swsib_scheduled_tasks', $scheduled_tasks);
        $this->is_updating_options = false;
        
        // Create task runner and run task
        $task_runner = new SwiftSpeed_Siberian_Task_Runner();
        $result = $task_runner->run_scheduled_task($task['task_type'], $task['task_args']);
        
        // Reset processing flag
        $this->is_processing_tasks = false;
        
        wp_send_json_success(array('message' => "Task {$task_key} has been executed immediately."));
    }
    
    /**
     * Function to reset a stuck task
     */
    public function reset_task($task_key) {
        // Get scheduled tasks
        $scheduled_tasks = get_option('swsib_scheduled_tasks', array());
        
        // Check if task exists
        if (!isset($scheduled_tasks[$task_key])) {
            return false;
        }
        
        // Reset task status
        $scheduled_tasks[$task_key]['status'] = 'scheduled';
        
        // If the task is overdue, schedule it to run in 1 minute
        if ($scheduled_tasks[$task_key]['next_run'] < time()) {
            $scheduled_tasks[$task_key]['next_run'] = time() + 60;
        }
        
        // Use flag to prevent recursion
        $this->is_updating_options = true;
        
        // Update tasks
        update_option('swsib_scheduled_tasks', $scheduled_tasks);
        
        // Reset flag
        $this->is_updating_options = false;
        
        $this->log_message("Reset task {$task_key} from 'running' to 'scheduled' state", true);
        
        // Trigger next check
        $this->schedule_next_check(true);
        
        return true;
    }
    
    /**
     * AJAX handler for resetting a stuck task
     */
    public function ajax_reset_task() {
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
        
        // Get task key
        $task_key = isset($_POST['task_key']) ? sanitize_text_field($_POST['task_key']) : '';
        
        if (empty($task_key)) {
            wp_send_json_error(array('message' => 'Task key not provided.'));
            return;
        }
        
        // Reset the task
        $result = $this->reset_task($task_key);
        
        if ($result) {
            wp_send_json_success(array('message' => "Task {$task_key} has been reset."));
        } else {
            wp_send_json_error(array('message' => "Task {$task_key} not found."));
        }
    }
}