<?php
/**
 * Cron Manager component - COMPLETELY REWRITTEN FOR MAXIMUM RELIABILITY
 * Handles scheduled backup tasks with multiple failsafe mechanisms
 * 
 * Key Features:
 * - Individual hooks per schedule (no parameter passing issues)
 * - Multiple trigger mechanisms (WordPress cron + external cron + heartbeat)
 * - Aggressive schedule checking and recovery
 * - Simple, bulletproof scheduling logic
 * - Comprehensive logging and monitoring
 * 
 * @since 3.0.0
 */
class SwiftSpeed_Siberian_Cron_Manager {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Backup processor instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Processor
     */
    private $backup_processor;
    
    /**
     * Storage manager instance.
     * 
     * @var SwiftSpeed_Siberian_Storage_Manager
     */
    private $storage_manager;
    
    /**
     * Heartbeat interval (5 minutes)
     * 
     * @var int
     */
    private $heartbeat_interval = 300;
    
    /**
     * Maximum drift allowed before considering a schedule missed (10 minutes)
     * 
     * @var int
     */
    private $max_drift = 600;
    
    /**
     * Initialize the class with rock-solid reliability features.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Register our reliable cron schedules first
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // CRITICAL: Register the master heartbeat check (this is our primary reliability mechanism)
        add_action('swsib_master_heartbeat', array($this, 'master_heartbeat_check'));
        
        // Register cleanup task
        add_action('swsib_cleanup_old_backups', array($this, 'cleanup_old_backups'));
        
        // Register AJAX handlers for external triggering
        add_action('wp_ajax_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_nopriv_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_swsib_add_backup_schedule', array($this, 'ajax_add_backup_schedule'));
        add_action('wp_ajax_swsib_delete_backup_schedule', array($this, 'ajax_delete_backup_schedule'));
        add_action('wp_ajax_swsib_get_backup_schedules', array($this, 'ajax_get_backup_schedules'));
        
        // Multiple trigger points for reliability
        add_action('admin_init', array($this, 'admin_trigger_check'), 20);
        add_action('wp_loaded', array($this, 'frontend_trigger_check'), 20);
        add_action('init', array($this, 'init_master_systems'), 25);
        
        // Emergency recovery system
        add_action('wp_footer', array($this, 'emergency_recovery_check'));
        add_action('admin_footer', array($this, 'emergency_recovery_check'));
    }
    
    /**
     * Set the backup processor instance.
     */
    public function set_backup_processor($backup_processor) {
        $this->backup_processor = $backup_processor;
    }
    
    /**
     * Set the storage manager instance.
     */
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    /**
     * Initialize master reliability systems.
     */
    public function init_master_systems() {
        // Ensure master heartbeat is always running
        if (!wp_next_scheduled('swsib_master_heartbeat')) {
            wp_schedule_event(time() + 60, 'swsib_every_5_minutes', 'swsib_master_heartbeat');
            $this->log_message('Master heartbeat system initialized');
        }
        
        // Ensure cleanup task is scheduled
        if (!wp_next_scheduled('swsib_cleanup_old_backups')) {
            wp_schedule_event(time() + 3600, 'daily', 'swsib_cleanup_old_backups');
        }
        
        // Register all individual schedule hooks
        $this->register_individual_schedule_hooks();
        
        // Update schedule monitoring
        $this->update_schedule_health_check();
    }
    
    /**
     * MASTER HEARTBEAT CHECK - This is the core reliability mechanism
     * Runs every 5 minutes and ensures all schedules are working
     */
    public function master_heartbeat_check() {
        $this->log_message('=== MASTER HEARTBEAT CHECK STARTING ===');
        
        // Update heartbeat timestamp
        update_option('swsib_last_heartbeat', time());
        
        // Get all active schedules
        $schedules = $this->get_backup_schedules();
        $current_time = time();
        $checks_performed = 0;
        $schedules_triggered = 0;
        
        if (empty($schedules)) {
            $this->log_message('No backup schedules found during heartbeat check');
            return;
        }
        
        $this->log_message('Heartbeat checking ' . count($schedules) . ' schedules');
        
        foreach ($schedules as $schedule_id => $schedule) {
            $checks_performed++;
            
            // Skip disabled schedules
            if (empty($schedule['enabled'])) {
                continue;
            }
            
            // Calculate when this schedule should next run
            $last_run = isset($schedule['last_run']) ? intval($schedule['last_run']) : 0;
            $interval_seconds = $this->convert_to_seconds(
                $schedule['interval_value'], 
                $schedule['interval_unit']
            );
            
            $next_due_time = $last_run + $interval_seconds;
            $time_since_due = $current_time - $next_due_time;
            
            // Check if this schedule is overdue (with tolerance for timing variations)
            if ($time_since_due > 60) { // 1 minute tolerance
                $hours_overdue = round($time_since_due / 3600, 2);
                
                $this->log_message("Schedule '{$schedule['name']}' ({$schedule_id}) is OVERDUE by {$hours_overdue} hours");
                $this->log_message("Last run: " . ($last_run > 0 ? date('Y-m-d H:i:s', $last_run) : 'Never'));
                $this->log_message("Should have run at: " . date('Y-m-d H:i:s', $next_due_time));
                
                // Check if a backup is already running
                if ($this->is_backup_currently_running()) {
                    $this->log_message("Backup already running, queueing schedule {$schedule_id}");
                    $this->queue_schedule_for_later($schedule_id);
                    continue;
                }
                
                // Trigger this overdue schedule immediately
                $this->log_message("TRIGGERING OVERDUE SCHEDULE: {$schedule_id}");
                $this->execute_backup_schedule($schedule_id);
                $schedules_triggered++;
                
                // Only run one backup at a time
                break;
            } else if ($time_since_due > -300) { // Due within 5 minutes
                $minutes_until_due = round(($next_due_time - $current_time) / 60);
                $this->log_message("Schedule '{$schedule['name']}' due in {$minutes_until_due} minutes");
            }
        }
        
        $this->log_message("Heartbeat check completed: {$checks_performed} schedules checked, {$schedules_triggered} triggered");
        
        // Check for and process any queued schedules
        $this->process_queued_schedules();
        
        // Verify individual schedule hooks are still registered
        $this->verify_schedule_hooks();
        
        $this->log_message('=== MASTER HEARTBEAT CHECK COMPLETED ===');
    }
    
    /**
     * Check if any backup is currently running
     */
    private function is_backup_currently_running() {
        $current_backup = get_option('swsib_current_backup', array());
        
        if (empty($current_backup)) {
            return false;
        }
        
        return in_array($current_backup['status'], array(
            'initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'
        ));
    }
    
    /**
     * Queue a schedule to run later when current backup completes
     */
    private function queue_schedule_for_later($schedule_id) {
        $queued = get_option('swsib_queued_schedules', array());
        
        if (!in_array($schedule_id, $queued)) {
            $queued[] = $schedule_id;
            update_option('swsib_queued_schedules', $queued);
            $this->log_message("Schedule {$schedule_id} queued for later execution");
        }
    }
    
    /**
     * Process any queued schedules
     */
    private function process_queued_schedules() {
        // Only process if no backup is currently running
        if ($this->is_backup_currently_running()) {
            return;
        }
        
        $queued = get_option('swsib_queued_schedules', array());
        
        if (!empty($queued)) {
            $schedule_id = array_shift($queued);
            update_option('swsib_queued_schedules', $queued);
            
            $this->log_message("Processing queued schedule: {$schedule_id}");
            $this->execute_backup_schedule($schedule_id);
        }
    }
    
    /**
     * Execute a backup schedule - the core execution function
     */
    private function execute_backup_schedule($schedule_id) {
        if (!$this->backup_processor) {
            $this->log_message('Cannot execute schedule: Backup processor not available');
            return false;
        }
        
        // Get the schedule
        $schedules = $this->get_backup_schedules();
        
        if (!isset($schedules[$schedule_id])) {
            $this->log_message("Schedule {$schedule_id} not found");
            return false;
        }
        
        $schedule = $schedules[$schedule_id];
        
        // Verify schedule is enabled
        if (empty($schedule['enabled'])) {
            $this->log_message("Schedule {$schedule_id} is disabled, skipping");
            return false;
        }
        
        $this->log_message("EXECUTING BACKUP SCHEDULE: {$schedule_id} ({$schedule['name']})");
        
        // Update last run time immediately to prevent duplicate runs
        $schedule['last_run'] = time();
        
        // Calculate next run time
        $interval_seconds = $this->convert_to_seconds(
            $schedule['interval_value'], 
            $schedule['interval_unit']
        );
        $schedule['next_run'] = time() + $interval_seconds;
        
        // Save updated schedule
        $schedules[$schedule_id] = $schedule;
        update_option('swsib_backup_schedules', $schedules);
        update_option('swsib_last_scheduled_backup', time());
        
        // Prepare backup parameters
        $backup_params = array(
            'backup_type' => $schedule['type'],
            'storage' => 'local', // Always include local
            'storage_providers' => isset($schedule['storages']) ? $schedule['storages'] : array('local'),
            'scheduled' => true,
            'allow_background' => true,
            'auto_lock' => !empty($schedule['auto_lock']),
            'schedule_id' => $schedule_id,
            'schedule_name' => $schedule['name'],
        );
        
        // Clear any retry counter for this schedule
        delete_option('swsib_backup_retry_' . $schedule_id);
        
        // Execute the backup
        $result = $this->backup_processor->run_scheduled_backup($backup_params);
        
        if (is_wp_error($result)) {
            $this->log_message("Schedule execution failed: " . $result->get_error_message());
            $this->handle_schedule_failure($schedule_id, $result->get_error_message());
            return false;
        }
        
        $this->log_message("Schedule executed successfully: {$schedule_id}");
        return true;
    }
    
    /**
     * Handle schedule execution failure with retry logic
     */
    private function handle_schedule_failure($schedule_id, $error_message) {
        $retry_count = get_option('swsib_backup_retry_' . $schedule_id, 0);
        $max_retries = 3;
        
        if ($retry_count < $max_retries) {
            $retry_count++;
            update_option('swsib_backup_retry_' . $schedule_id, $retry_count);
            
            // Queue for retry in 15 minutes
            $retry_time = time() + 900; // 15 minutes
            wp_schedule_single_event($retry_time, 'swsib_retry_schedule_' . $schedule_id);
            
            // Also register the retry hook
            add_action('swsib_retry_schedule_' . $schedule_id, function() use ($schedule_id) {
                $this->log_message("Retrying failed schedule: {$schedule_id}");
                $this->execute_backup_schedule($schedule_id);
            });
            
            $this->log_message("Scheduled retry #{$retry_count} for {$schedule_id} at " . date('Y-m-d H:i:s', $retry_time));
        } else {
            $this->log_message("Maximum retries reached for schedule {$schedule_id}, giving up");
            delete_option('swsib_backup_retry_' . $schedule_id);
        }
    }
    
    /**
     * Register individual hooks for each schedule (more reliable than parameters)
     */
    private function register_individual_schedule_hooks() {
        $schedules = $this->get_backup_schedules();
        
        foreach ($schedules as $schedule_id => $schedule) {
            if (empty($schedule['enabled'])) {
                continue;
            }
            
            $hook_name = 'swsib_schedule_' . $schedule_id;
            
            // Register the hook action if not already registered
            if (!has_action($hook_name)) {
                add_action($hook_name, function() use ($schedule_id) {
                    $this->log_message("Individual hook triggered for schedule: {$schedule_id}");
                    $this->execute_backup_schedule($schedule_id);
                });
            }
            
            // Schedule the event if not already scheduled
            if (!wp_next_scheduled($hook_name)) {
                $next_run = isset($schedule['next_run']) && $schedule['next_run'] > time() 
                    ? $schedule['next_run'] 
                    : time() + 300; // 5 minutes from now if no next run set
                
                $interval_name = $this->get_wordpress_schedule_name(
                    $schedule['interval_value'], 
                    $schedule['interval_unit']
                );
                
                wp_schedule_event($next_run, $interval_name, $hook_name);
                
                $this->log_message("Scheduled individual hook {$hook_name} for " . date('Y-m-d H:i:s', $next_run));
            }
        }
    }
    
    /**
     * Verify that schedule hooks are still properly registered
     */
    private function verify_schedule_hooks() {
        $schedules = $this->get_backup_schedules();
        $reregistered = 0;
        
        foreach ($schedules as $schedule_id => $schedule) {
            if (empty($schedule['enabled'])) {
                continue;
            }
            
            $hook_name = 'swsib_schedule_' . $schedule_id;
            
            // Check if the hook is scheduled
            if (!wp_next_scheduled($hook_name)) {
                $this->log_message("Hook missing for schedule {$schedule_id}, re-registering");
                
                // Re-register this specific hook
                $next_run = isset($schedule['next_run']) && $schedule['next_run'] > time() 
                    ? $schedule['next_run'] 
                    : time() + 300;
                
                $interval_name = $this->get_wordpress_schedule_name(
                    $schedule['interval_value'], 
                    $schedule['interval_unit']
                );
                
                wp_schedule_event($next_run, $interval_name, $hook_name);
                $reregistered++;
            }
        }
        
        if ($reregistered > 0) {
            $this->log_message("Re-registered {$reregistered} missing schedule hooks");
        }
    }
    
    /**
     * Admin trigger check - additional reliability mechanism
     */
    public function admin_trigger_check() {
        $last_check = get_option('swsib_last_admin_trigger_check', 0);
        $current_time = time();
        
        // Check every 10 minutes during admin visits
        if (($current_time - $last_check) > 600) {
            $this->log_message("Admin trigger check initiated");
            $this->master_heartbeat_check();
            update_option('swsib_last_admin_trigger_check', $current_time);
        }
    }
    
    /**
     * Frontend trigger check - additional reliability mechanism (less frequent)
     */
    public function frontend_trigger_check() {
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        $last_check = get_option('swsib_last_frontend_trigger_check', 0);
        $current_time = time();
        
        // Check every 30 minutes during frontend visits
        if (($current_time - $last_check) > 1800) {
            $this->log_message("Frontend trigger check initiated");
            $this->master_heartbeat_check();
            update_option('swsib_last_frontend_trigger_check', $current_time);
        }
    }
    
    /**
     * Emergency recovery check - last resort reliability mechanism
     */
    public function emergency_recovery_check() {
        $last_heartbeat = get_option('swsib_last_heartbeat', 0);
        $current_time = time();
        
        // If heartbeat hasn't run in over an hour, something is very wrong
        if ($last_heartbeat > 0 && ($current_time - $last_heartbeat) > 3600) {
            $this->log_message("EMERGENCY RECOVERY: Heartbeat missing for over 1 hour, triggering emergency check");
            
            // Force a heartbeat check
            $this->master_heartbeat_check();
            
            // Re-initialize master systems
            $this->init_master_systems();
        }
    }
    
    /**
     * Get or create appropriate WordPress schedule name
     */
    private function get_wordpress_schedule_name($interval_value, $interval_unit) {
        // Use built-in WordPress schedules when possible
        switch ($interval_unit) {
            case 'minutes':
                if ($interval_value == 5) return 'swsib_every_5_minutes';
                if ($interval_value == 15) return 'swsib_every_15_minutes';
                if ($interval_value == 30) return 'swsib_every_30_minutes';
                break;
                
            case 'hours':
                if ($interval_value == 1) return 'hourly';
                if ($interval_value == 12) return 'twicedaily';
                break;
                
            case 'days':
                if ($interval_value == 1) return 'daily';
                if ($interval_value == 7) return 'weekly';
                break;
                
            case 'weeks':
                if ($interval_value == 1) return 'weekly';
                break;
        }
        
        // For custom intervals, use daily and let heartbeat handle the timing
        return 'daily';
    }
    
    /**
     * Convert interval to seconds
     */
    private function convert_to_seconds($value, $unit) {
        $value = intval($value);
        
        switch ($unit) {
            case 'minutes':
                return $value * 60;
            case 'hours':
                return $value * 3600;
            case 'days':
                return $value * 86400;
            case 'weeks':
                return $value * 604800;
            case 'months':
                return $value * 2592000; // 30 days
            default:
                return 86400; // Default to 1 day
        }
    }
    
    /**
     * Add custom WordPress cron schedules
     */
    public function add_cron_schedules($schedules) {
        // Add our reliable 5-minute interval for heartbeat
        $schedules['swsib_every_5_minutes'] = array(
            'interval' => 300,
            'display' => __('Every 5 Minutes', 'swiftspeed-siberian')
        );
        
        // Add other useful intervals
        $schedules['swsib_every_15_minutes'] = array(
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_30_minutes'] = array(
            'interval' => 1800,
            'display' => __('Every 30 Minutes', 'swiftspeed-siberian')
        );
        
        // Ensure standard schedules exist
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 604800,
                'display' => __('Once Weekly', 'swiftspeed-siberian')
            );
        }
        
        return $schedules;
    }
    
    /**
     * Update schedule health monitoring
     */
    private function update_schedule_health_check() {
        $schedules = $this->get_backup_schedules();
        $health_data = array(
            'last_check' => time(),
            'total_schedules' => count($schedules),
            'enabled_schedules' => 0,
            'next_due' => null,
        );
        
        $next_due_time = PHP_INT_MAX;
        
        foreach ($schedules as $schedule) {
            if (!empty($schedule['enabled'])) {
                $health_data['enabled_schedules']++;
                
                if (isset($schedule['next_run']) && $schedule['next_run'] < $next_due_time) {
                    $next_due_time = $schedule['next_run'];
                }
            }
        }
        
        if ($next_due_time < PHP_INT_MAX) {
            $health_data['next_due'] = $next_due_time;
        }
        
        update_option('swsib_schedule_health', $health_data);
    }
    
    /**
     * AJAX handler for external cron trigger
     */
    public function ajax_trigger_scheduled_backup() {
        $expected_key = md5('swsib_trigger_scheduled_backup');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            $this->log_message('External cron trigger received');
            
            // Check if a specific schedule was requested
            $schedule_id = isset($_GET['schedule_id']) ? sanitize_text_field($_GET['schedule_id']) : null;
            
            if ($schedule_id) {
                $this->log_message("External trigger for specific schedule: {$schedule_id}");
                $result = $this->execute_backup_schedule($schedule_id);
                wp_die($result ? 'Schedule triggered successfully' : 'Schedule trigger failed');
            } else {
                // Run the master heartbeat check
                $this->master_heartbeat_check();
                wp_die('Master heartbeat check completed');
            }
        } else {
            wp_die('Invalid security key');
        }
    }
    
    /**
     * AJAX handler for adding/updating backup schedule
     */
    public function ajax_add_backup_schedule() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get and validate form data
        $schedule_id = isset($_POST['id']) && !empty($_POST['id']) 
            ? sanitize_text_field($_POST['id']) 
            : 'schedule-' . time() . '-' . rand(1000, 9999);
            
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'full';
        $interval_value = isset($_POST['interval_value']) ? intval($_POST['interval_value']) : 1;
        $interval_unit = isset($_POST['interval_unit']) ? sanitize_key($_POST['interval_unit']) : 'days';
        $auto_lock = isset($_POST['auto_lock']) && $_POST['auto_lock'] === 'true';
        $enabled = isset($_POST['enabled']) ? $_POST['enabled'] === 'true' : true;
        
        if (empty($name)) {
            wp_send_json_error(array('message' => __('Schedule name is required', 'swiftspeed-siberian')));
        }
        
        // Get storage providers
        $storage_providers = isset($_POST['storages']) ? (array)$_POST['storages'] : array('local');
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        $storage_providers = array_map('sanitize_key', $storage_providers);
        
        // Get current schedules
        $schedules = $this->get_backup_schedules();
        $is_update = isset($schedules[$schedule_id]);
        
        // Create/update schedule
        $schedule = array(
            'id' => $schedule_id,
            'enabled' => $enabled,
            'name' => $name,
            'type' => $type,
            'interval_value' => $interval_value,
            'interval_unit' => $interval_unit,
            'auto_lock' => $auto_lock,
            'storages' => $storage_providers,
            'created' => $is_update && isset($schedules[$schedule_id]['created']) 
                ? $schedules[$schedule_id]['created'] : time(),
            'last_run' => $is_update && isset($schedules[$schedule_id]['last_run']) 
                ? $schedules[$schedule_id]['last_run'] : 0,
            'next_run' => time() + $this->convert_to_seconds($interval_value, $interval_unit),
        );
        
        // Save schedule
        $schedules[$schedule_id] = $schedule;
        update_option('swsib_backup_schedules', $schedules);
        
        // Clear and re-register hooks for this schedule
        $this->clear_schedule_hooks($schedule_id);
        
        if ($enabled) {
            $this->register_individual_schedule_hooks();
        }
        
        $this->log_message($is_update ? "Updated schedule: {$schedule_id}" : "Created schedule: {$schedule_id}");
        
        wp_send_json_success(array(
            'message' => $is_update 
                ? __('Backup schedule updated successfully', 'swiftspeed-siberian')
                : __('Backup schedule added successfully', 'swiftspeed-siberian'),
            'schedule' => $schedule
        ));
    }
    
    /**
     * AJAX handler for deleting backup schedule
     */
    public function ajax_delete_backup_schedule() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $schedule_id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        
        if (empty($schedule_id)) {
            wp_send_json_error(array('message' => __('No schedule ID provided', 'swiftspeed-siberian')));
        }
        
        $schedules = $this->get_backup_schedules();
        
        if (!isset($schedules[$schedule_id])) {
            wp_send_json_error(array('message' => __('Schedule not found', 'swiftspeed-siberian')));
        }
        
        // Clear all hooks for this schedule
        $this->clear_schedule_hooks($schedule_id);
        
        // Remove from schedules
        unset($schedules[$schedule_id]);
        update_option('swsib_backup_schedules', $schedules);
        
        // Clean up any related options
        delete_option('swsib_backup_retry_' . $schedule_id);
        
        $this->log_message("Deleted schedule: {$schedule_id}");
        
        wp_send_json_success(array(
            'message' => __('Backup schedule deleted successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for getting backup schedules
     */
    public function ajax_get_backup_schedules() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $schedules = $this->get_backup_schedules();
        
        // Enhance with human-readable times
        foreach ($schedules as &$schedule) {
            if (isset($schedule['next_run'])) {
                $schedule['next_run_human'] = human_time_diff(time(), $schedule['next_run']);
                $schedule['next_run_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['next_run']);
                $schedule['missed'] = $schedule['next_run'] < time() && $schedule['enabled'];
            } else {
                $schedule['next_run_human'] = __('Not scheduled', 'swiftspeed-siberian');
                $schedule['next_run_date'] = __('Not scheduled', 'swiftspeed-siberian');
                $schedule['missed'] = false;
            }
            
            if (isset($schedule['last_run']) && $schedule['last_run'] > 0) {
                $schedule['last_run_human'] = human_time_diff($schedule['last_run'], time());
                $schedule['last_run_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['last_run']);
            } else {
                $schedule['last_run_human'] = __('Never', 'swiftspeed-siberian');
                $schedule['last_run_date'] = __('Never', 'swiftspeed-siberian');
            }
        }
        
        wp_send_json_success(array('schedules' => $schedules));
    }
    
    /**
     * Clear all hooks for a specific schedule
     */
    private function clear_schedule_hooks($schedule_id) {
        $hook_name = 'swsib_schedule_' . $schedule_id;
        
        // Clear WordPress scheduled event
        wp_clear_scheduled_hook($hook_name);
        
        // Clear retry hooks
        wp_clear_scheduled_hook('swsib_retry_schedule_' . $schedule_id);
        
        $this->log_message("Cleared hooks for schedule: {$schedule_id}");
    }
    
    /**
     * Get all backup schedules
     */
    public function get_backup_schedules() {
        return get_option('swsib_backup_schedules', array());
    }
    
    /**
     * Clear all scheduled events (for maintenance)
     */
    public function clear_all_scheduled_events() {
        $schedules = $this->get_backup_schedules();
        
        foreach ($schedules as $schedule_id => $schedule) {
            $this->clear_schedule_hooks($schedule_id);
        }
        
        // Clear master hooks
        wp_clear_scheduled_hook('swsib_master_heartbeat');
        wp_clear_scheduled_hook('swsib_cleanup_old_backups');
        
        // Clear queued schedules
        delete_option('swsib_queued_schedules');
        
        $this->log_message('All scheduled events cleared');
    }
    
    /**
     * Cleanup old backups task
     */
    public function cleanup_old_backups() {
        if (!$this->backup_processor) {
            $this->log_message('Cannot cleanup old backups: Backup processor not available');
            return;
        }
        
        $this->log_message('Running scheduled cleanup of old backups');
        $this->backup_processor->cleanup_old_backups();
    }
    
    /**
     * Log a message for debugging
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'cron_reliable', $message);
        }
    }
}