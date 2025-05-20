<?php
/**
 * Cron Manager component.
 * Handles scheduled backup tasks and cron job management with improved reliability.
 * Enhanced with multiple schedule support.
 * 
 * @since 2.3.0
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
     * Initialize the class.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Register custom cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Register necessary action hooks for backup
        add_action('swsib_check_scheduled_backups', array($this, 'check_scheduled_backups'));
        add_action('swsib_hourly_backup_check', array($this, 'force_check_backups'));
        add_action('swsib_process_queued_backup', array($this, 'process_queued_backup'), 10, 1);
        add_action('swsib_cleanup_old_backups', array($this, 'cleanup_old_backups'));
        
        // IMPORTANT: This hook will be used directly for all scheduled backups
        add_action('swsib_run_backup_schedule', array($this, 'run_backup_schedule'), 10, 1);
        
        // Register AJAX handlers
        add_action('wp_ajax_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_nopriv_swsib_trigger_scheduled_backup', array($this, 'ajax_trigger_scheduled_backup'));
        add_action('wp_ajax_swsib_add_backup_schedule', array($this, 'ajax_add_backup_schedule'));
        add_action('wp_ajax_swsib_delete_backup_schedule', array($this, 'ajax_delete_backup_schedule'));
        add_action('wp_ajax_swsib_get_backup_schedules', array($this, 'ajax_get_backup_schedules'));
        
        // Admin init hook to provide additional trigger
        add_action('admin_init', array($this, 'admin_init_check_backups'), 20);
        
        // Front end hook for additional trigger
        add_action('wp_loaded', array($this, 'front_end_check_backups'), 20);
        
        // Register cron tasks after everything is set up
        add_action('init', array($this, 'register_cron_tasks'), 20);
    }
    
    /**
     * Set the backup processor instance.
     *
     * @param SwiftSpeed_Siberian_Backup_Processor $backup_processor Backup processor instance.
     * @return void
     */
    public function set_backup_processor($backup_processor) {
        $this->backup_processor = $backup_processor;
    }
    
    /**
     * Set the storage manager instance.
     *
     * @param SwiftSpeed_Siberian_Storage_Manager $storage_manager Storage manager instance.
     * @return void
     */
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    /**
     * Admin init task check - additional trigger for admin pages
     */
    public function admin_init_check_backups() {
        // Get the last admin check time
        $last_admin_check = get_option('swsib_last_admin_backup_check', 0);
        $current_time = time();
        
        // Only check every 5 minutes to prevent excessive checks
        if (($current_time - $last_admin_check) > 300) {
            $this->log_message("Admin page visit triggering backup process check");
            $this->check_scheduled_backups();
            update_option('swsib_last_admin_backup_check', $current_time);
        }
    }
    
    /**
     * Front end task check - additional trigger for frontend visits
     */
    public function front_end_check_backups() {
        // Only run on non-admin pages and limit frequency
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        
        // Get the last front end check time
        $last_frontend_check = get_option('swsib_last_frontend_backup_check', 0);
        $current_time = time();
        
        // Only check every 15 minutes to prevent excessive checks on high-traffic sites
        if (($current_time - $last_frontend_check) > 900) {
            $this->log_message("Frontend page visit triggering backup process check");
            $this->check_scheduled_backups();
            update_option('swsib_last_frontend_backup_check', $current_time);
        }
    }
    
    /**
     * Register cron tasks with improved reliability.
     */
    public function register_cron_tasks() {
        // Register the regular check for due backups (every 5 minutes)
        if (!wp_next_scheduled('swsib_check_scheduled_backups')) {
            wp_schedule_event(time() + 60, 'swsib_every_5_minutes', 'swsib_check_scheduled_backups');
        }
        
        // Register hourly backup check (as a fallback)
        if (!wp_next_scheduled('swsib_hourly_backup_check')) {
            wp_schedule_event(time() + 900, 'hourly', 'swsib_hourly_backup_check');
        }
        
        // Clean up old backups task
        if (!wp_next_scheduled('swsib_cleanup_old_backups')) {
            wp_schedule_event(time() + 3600, 'daily', 'swsib_cleanup_old_backups');
        }
        
        // Register individual backup schedules
        $this->register_backup_schedules();
    }
    
    /**
     * Register individual backup schedules from saved configurations
     * 
     * @return void
     */
    private function register_backup_schedules() {
        // Get all backup schedules
        $backup_schedules = $this->get_backup_schedules();
        
        if (empty($backup_schedules)) {
            $this->log_message("No backup schedules found to register");
            return;
        }
        
        $this->log_message("Registering " . count($backup_schedules) . " backup schedules");
        
        // Loop through each schedule and register it
        foreach ($backup_schedules as $schedule_id => $schedule) {
            // Skip disabled schedules
            if (empty($schedule['enabled'])) {
                $this->log_message("Skipping disabled schedule: {$schedule_id}");
                continue;
            }
            
            // IMPORTANT: We use a single hook name for all backups
            // This ensures WordPress can properly register the callback
            $hook = 'swsib_run_backup_schedule';
            
            // Check if this specific scheduled event exists
            if (!wp_next_scheduled($hook, array($schedule_id))) {
                // Get the schedule interval
                $interval_value = isset($schedule['interval_value']) ? intval($schedule['interval_value']) : 1;
                $interval_unit = isset($schedule['interval_unit']) ? $schedule['interval_unit'] : 'days';
                
                // Get the schedule name
                $schedule_name = $this->get_schedule_name($interval_value, $interval_unit);
                
                // Schedule the event with the schedule ID as an argument
                $next_run = isset($schedule['next_run']) && $schedule['next_run'] > time() ? 
                            $schedule['next_run'] : (time() + 300);
                
                $this->log_message("Scheduling backup for ID {$schedule_id} ({$schedule['name']}) with schedule: {$schedule_name}, next run: " . date('Y-m-d H:i:s', $next_run));
                
                wp_schedule_event($next_run, $schedule_name, $hook, array($schedule_id));
            } else {
                $this->log_message("Schedule already exists for ID {$schedule_id} ({$schedule['name']})");
            }
        }
    }
    
    /**
     * Get appropriate schedule name based on interval 
     * 
     * @param int $interval_value The interval value
     * @param string $interval_unit The interval unit (minutes, hours, days, weeks, months)
     * @return string The schedule name
     */
    private function get_schedule_name($interval_value, $interval_unit) {
        // Create a custom schedule name
        $schedule_name = 'swsib_every_' . $interval_value . '_' . $interval_unit;
        
        // Check for standard WordPress schedules or our defined schedules
        switch ($interval_unit) {
            case 'minutes':
                if ($interval_value == 1) {
                    $schedule_name = 'swsib_every_minute';
                } elseif ($interval_value == 5) {
                    $schedule_name = 'swsib_every_5_minutes';
                } elseif ($interval_value == 10) {
                    $schedule_name = 'swsib_every_10_minutes';
                } elseif ($interval_value == 15) {
                    $schedule_name = 'swsib_every_15_minutes';
                } elseif ($interval_value == 30) {
                    $schedule_name = 'swsib_every_30_minutes';
                }
                break;
                
            case 'hours':
                if ($interval_value == 1) {
                    $schedule_name = 'hourly';
                } elseif ($interval_value == 2) {
                    $schedule_name = 'every_2_hours';
                } elseif ($interval_value == 12) {
                    $schedule_name = 'twicedaily';
                }
                break;
                
            case 'days':
                if ($interval_value == 1) {
                    $schedule_name = 'daily';
                } elseif ($interval_value == 3) {
                    $schedule_name = 'every_3_days';
                } elseif ($interval_value == 7) {
                    $schedule_name = 'weekly';
                }
                break;
                
            case 'weeks':
                if ($interval_value == 1) {
                    $schedule_name = 'weekly';
                } elseif ($interval_value == 2) {
                    $schedule_name = 'every_2_weeks';
                }
                break;
                
            case 'months':
                if ($interval_value == 1) {
                    $schedule_name = 'every_month';
                } elseif ($interval_value == 3) {
                    $schedule_name = 'every_3_months';
                } elseif ($interval_value == 6) {
                    $schedule_name = 'every_6_months';
                } elseif ($interval_value == 12) {
                    $schedule_name = 'every_year';
                }
                break;
        }
        
        // Create a custom schedule if one doesn't already exist
        if (!$this->schedule_exists($schedule_name)) {
            $schedule_name = $this->create_custom_schedule($interval_value, $interval_unit);
        }
        
        return $schedule_name;
    }
    
    /**
     * Check if a schedule exists
     * 
     * @param string $schedule_name The schedule name
     * @return bool True if the schedule exists
     */
    private function schedule_exists($schedule_name) {
        $schedules = wp_get_schedules();
        return isset($schedules[$schedule_name]);
    }
    
    /**
     * Create a custom schedule for the given interval
     * 
     * @param int $interval_value The interval value
     * @param string $interval_unit The interval unit (minutes, hours, days, weeks, months)
     * @return string The schedule name
     */
    private function create_custom_schedule($interval_value, $interval_unit) {
        $schedule_name = 'swsib_every_' . $interval_value . '_' . $interval_unit;
        $interval_seconds = $this->convert_to_seconds($interval_value, $interval_unit);
        
        // Register custom schedule
        add_filter('cron_schedules', function($schedules) use ($schedule_name, $interval_seconds, $interval_value, $interval_unit) {
            $schedules[$schedule_name] = array(
                'interval' => $interval_seconds,
                'display' => sprintf(__('Every %d %s', 'swiftspeed-siberian'), $interval_value, $interval_unit)
            );
            return $schedules;
        });
        
        return $schedule_name;
    }
    
    /**
     * Convert interval to seconds
     * 
     * @param int $value The interval value
     * @param string $unit The interval unit (minutes, hours, days, weeks, months)
     * @return int The interval in seconds
     */
    private function convert_to_seconds($value, $unit) {
        switch ($unit) {
            case 'minutes':
                return $value * MINUTE_IN_SECONDS;
            case 'hours':
                return $value * HOUR_IN_SECONDS;
            case 'days':
                return $value * DAY_IN_SECONDS;
            case 'weeks':
                return $value * WEEK_IN_SECONDS;
            case 'months':
                return $value * 30 * DAY_IN_SECONDS; // Approximate
            default:
                return DAY_IN_SECONDS; // Default to 1 day
        }
    }
    
    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function add_cron_schedules($schedules) {
        // Add weekly schedule if not exists
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display' => __('Once Weekly', 'swiftspeed-siberian')
            );
        }
        
        // Add monthly schedule if not exists
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display' => __('Once Monthly', 'swiftspeed-siberian')
            );
        }
        
        // Add hourly schedule if not exists
        if (!isset($schedules['hourly'])) {
            $schedules['hourly'] = array(
                'interval' => HOUR_IN_SECONDS,
                'display' => __('Once Hourly', 'swiftspeed-siberian')
            );
        }
        
        // Add twice daily schedule if not exists
        if (!isset($schedules['twicedaily'])) {
            $schedules['twicedaily'] = array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display' => __('Twice Daily', 'swiftspeed-siberian')
            );
        }
        
        // Add minutes-based schedules for more frequent checks
        $schedules['swsib_every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_5_minutes'] = array(
            'interval' => 5 * 60,
            'display' => __('Every 5 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_10_minutes'] = array(
            'interval' => 10 * 60,
            'display' => __('Every 10 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_15_minutes'] = array(
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes', 'swiftspeed-siberian')
        );
        
        $schedules['swsib_every_30_minutes'] = array(
            'interval' => 30 * 60,
            'display' => __('Every 30 Minutes', 'swiftspeed-siberian')
        );
        
        // Add hours-based schedules
        if (!isset($schedules['every_2_hours'])) {
            $schedules['every_2_hours'] = array(
                'interval' => 2 * HOUR_IN_SECONDS,
                'display' => __('Every 2 Hours', 'swiftspeed-siberian')
            );
        }
        
        if (!isset($schedules['every_3_hours'])) {
            $schedules['every_3_hours'] = array(
                'interval' => 3 * HOUR_IN_SECONDS,
                'display' => __('Every 3 Hours', 'swiftspeed-siberian')
            );
        }
        
        if (!isset($schedules['every_6_hours'])) {
            $schedules['every_6_hours'] = array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 Hours', 'swiftspeed-siberian')
            );
        }
        
        // Add days-based schedules
        if (!isset($schedules['every_3_days'])) {
            $schedules['every_3_days'] = array(
                'interval' => 3 * DAY_IN_SECONDS,
                'display' => __('Every 3 Days', 'swiftspeed-siberian')
            );
        }
        
        // Add weeks-based schedules
        if (!isset($schedules['every_2_weeks'])) {
            $schedules['every_2_weeks'] = array(
                'interval' => 2 * WEEK_IN_SECONDS,
                'display' => __('Every 2 Weeks', 'swiftspeed-siberian')
            );
        }
        
        // Add months-based schedules
        if (!isset($schedules['every_month'])) {
            $schedules['every_month'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display' => __('Every Month', 'swiftspeed-siberian')
            );
        }
        
        if (!isset($schedules['every_3_months'])) {
            $schedules['every_3_months'] = array(
                'interval' => 90 * DAY_IN_SECONDS,
                'display' => __('Every 3 Months', 'swiftspeed-siberian')
            );
        }
        
        if (!isset($schedules['every_6_months'])) {
            $schedules['every_6_months'] = array(
                'interval' => 180 * DAY_IN_SECONDS,
                'display' => __('Every 6 Months', 'swiftspeed-siberian')
            );
        }
        
        if (!isset($schedules['every_year'])) {
            $schedules['every_year'] = array(
                'interval' => 365 * DAY_IN_SECONDS,
                'display' => __('Every Year', 'swiftspeed-siberian')
            );
        }
        
        return $schedules;
    }
    
    /**
     * Main handler for the scheduled backup that runs via cron
     * This is the primary trigger point for all scheduled backups
     *
     * @param string $schedule_id The schedule ID to run
     * @return void
     */
    public function run_backup_schedule($schedule_id) {
        if (!$this->backup_processor) {
            $this->log_message('Cannot run scheduled backup: Backup processor not set');
            return;
        }
        
        $this->log_message("Run backup schedule trigger fired for schedule ID: {$schedule_id}");
        
        // Check if a backup is already in progress
        $current_backup = get_option('swsib_current_backup', array());
        if (!empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
            $this->log_message('A backup is already in progress, queueing this scheduled backup for later');
            $this->queue_backup_for_later($schedule_id);
            return;
        }
        
        // Get the schedule to run
        $backup_schedules = $this->get_backup_schedules();
        
        if (!isset($backup_schedules[$schedule_id])) {
            $this->log_message("Scheduled backup failed: Schedule ID {$schedule_id} not found");
            return;
        }
        
        $schedule = $backup_schedules[$schedule_id];
        
        // Check if this schedule is enabled
        if (empty($schedule['enabled'])) {
            $this->log_message("Schedule {$schedule_id} is disabled, skipping");
            return;
        }
        
        $this->log_message("Running scheduled backup for schedule: {$schedule_id} ({$schedule['name']}), type: {$schedule['type']}");
        
        // Update last run time
        $schedule['last_run'] = time();
        
        // Calculate next run time based on the interval
        $interval_seconds = $this->convert_to_seconds(
            $schedule['interval_value'], 
            $schedule['interval_unit']
        );
        $schedule['next_run'] = time() + $interval_seconds;
        
        $backup_schedules[$schedule_id] = $schedule;
        update_option('swsib_backup_schedules', $backup_schedules);
        update_option('swsib_last_scheduled_backup', time());
        
        // Define backup settings based on schedule
        $backup_type = isset($schedule['type']) ? $schedule['type'] : 'full';
        $storage_providers = isset($schedule['storages']) ? $schedule['storages'] : array('local');
        
        // Make sure 'local' is included
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        // Set primary storage
        $primary_storage = $storage_providers[0];
        
        // Set up params for the backup processor
        $params = array(
            'backup_type' => $backup_type,
            'storage' => $primary_storage,
            'storage_providers' => $storage_providers,
            'scheduled' => true,
            'allow_background' => true,
            'auto_lock' => !empty($schedule['auto_lock']),
            'schedule_id' => $schedule_id,
            'schedule_name' => $schedule['name'],
        );
        
        // Log the params for debugging
        $this->log_message("Backup params: " . json_encode($params));
        
        // Run the backup using the appropriate method
        $result = $this->backup_processor->run_scheduled_backup($params);
        
        if (is_wp_error($result)) {
            $this->log_message('Scheduled backup failed: ' . $result->get_error_message());
            
            // Add retry mechanism for failed backups
            $this->retry_failed_backup($schedule_id, $result->get_error_message());
        } else {
            $this->log_message('Scheduled backup started successfully with ID: ' . (!empty($result['id']) ? $result['id'] : 'unknown'));
            
            // Ensure background processing is enabled
            $this->trigger_background_processing();
        }
    }
    
    /**
     * Retry a failed scheduled backup
     * 
     * @param string $schedule_id The schedule ID to retry
     * @param string $error_message The error message from the previous attempt
     */
    private function retry_failed_backup($schedule_id, $error_message) {
        // Get the number of retry attempts for this schedule
        $retry_attempts = get_option('swsib_backup_retry_' . $schedule_id, 0);
        
        // Max 3 retry attempts
        if ($retry_attempts < 3) {
            $retry_attempts++;
            update_option('swsib_backup_retry_' . $schedule_id, $retry_attempts);
            
            // Schedule a retry in 15 minutes
            $retry_time = time() + (15 * MINUTE_IN_SECONDS);
            
            $this->log_message("Scheduling retry #{$retry_attempts} for backup schedule {$schedule_id} at " . 
                             date('Y-m-d H:i:s', $retry_time) . " (Previous error: {$error_message})");
            
            wp_schedule_single_event($retry_time, 'swsib_run_backup_schedule', array($schedule_id));
        } else {
            $this->log_message("Maximum retry attempts reached for schedule {$schedule_id}, giving up");
            
            // Reset retry counter for next time
            delete_option('swsib_backup_retry_' . $schedule_id);
        }
    }
    
    /**
     * Trigger background processing to ensure scheduled backups continue
     */
    private function trigger_background_processing() {
        if (!wp_next_scheduled('swsib_process_background_backup')) {
            wp_schedule_single_event(time() + 30, 'swsib_process_background_backup');
        }
        
        // Also send a non-blocking request to ensure it starts immediately
        $this->send_background_trigger_request();
    }
    
    /**
     * Send a non-blocking request to trigger background processing
     */
    private function send_background_trigger_request() {
        // Create the check URL
        $url = admin_url('admin-ajax.php?action=swsib_force_check_backup&key=' . md5('swsib_force_check_backup'));
        
        // Send a non-blocking request
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'headers'   => array(
                'Cache-Control' => 'no-cache',
            ),
        );
        
        // Log the attempt
        $this->log_message('Sending background trigger request to continue processing');
        
        // Send the request
        wp_remote_get($url, $args);
    }
    
    /**
     * Queue a backup to run later when the current backup completes
     * 
     * @param string $schedule_id Schedule ID to queue
     */
    private function queue_backup_for_later($schedule_id) {
        // Store the schedule ID to run later
        $queued_backups = get_option('swsib_queued_backups', array());
        
        // Get schedule info for better logging and scheduling
        $backup_schedules = $this->get_backup_schedules();
        $schedule_info = isset($backup_schedules[$schedule_id]) ? $backup_schedules[$schedule_id] : null;
        
        // Calculate when to run based on interval if available
        $next_run_time = time() + 600; // Default to 10 minutes later
        
        if ($schedule_info) {
            // Get interval settings from schedule
            $interval_value = isset($schedule_info['interval_value']) ? intval($schedule_info['interval_value']) : 1;
            $interval_unit = isset($schedule_info['interval_unit']) ? $schedule_info['interval_unit'] : 'days';
            
            // Adjust next run time based on interval
            if ($interval_unit === 'minutes' && $interval_value < 30) {
                $next_run_time = time() + (60 * $interval_value); // Run after one interval
            } else if ($interval_unit === 'minutes') {
                $next_run_time = time() + 1800; // For longer minute intervals, run after 30 minutes
            } else if ($interval_unit === 'hours' && $interval_value === 1) {
                $next_run_time = time() + 3600; // For hourly, run after one hour
            } else {
                $next_run_time = time() + 7200; // For longer intervals, run after 2 hours
            }
            
            $schedule_name = $schedule_info['name'];
            $this->log_message("Queueing backup schedule '{$schedule_name}' to run at " . date('Y-m-d H:i:s', $next_run_time));
        } else {
            $this->log_message("Queueing backup schedule ID '{$schedule_id}' to run at " . date('Y-m-d H:i:s', $next_run_time));
        }
        
        // Add to queue with timestamp for when to run
        $queued_backups[$schedule_id] = array(
            'schedule_id' => $schedule_id, 
            'run_at' => $next_run_time
        );
        
        update_option('swsib_queued_backups', $queued_backups);
        
        // Schedule a single event to process this queued backup
        wp_schedule_single_event($next_run_time, 'swsib_process_queued_backup', array($schedule_id));
    }
    
    /**
     * Process a queued backup that was delayed
     * 
     * @param string $schedule_id Schedule ID to process
     */
    public function process_queued_backup($schedule_id) {
        $queued_backups = get_option('swsib_queued_backups', array());
        
        // Check if this backup is still in the queue
        if (isset($queued_backups[$schedule_id])) {
            $this->log_message("Processing queued backup for schedule ID: {$schedule_id}");
            
            // Remove from queue
            unset($queued_backups[$schedule_id]);
            update_option('swsib_queued_backups', $queued_backups);
            
            // Process the backup - but first check if another backup is running
            $current_backup = get_option('swsib_current_backup', array());
            
            if (!empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
                $this->log_message('A backup is still in progress, re-queueing this scheduled backup for later');
                $this->queue_backup_for_later($schedule_id);
                return;
            }
            
            // Process the scheduled backup
            $this->run_backup_schedule($schedule_id);
        } else {
            $this->log_message("Queued backup for schedule ID {$schedule_id} not found in queue");
        }
    }
    
    /**
     * Regular check for scheduled backups with improved reliability.
     * 
     * @return void
     */
    public function check_scheduled_backups() {
        // Check for queued backups first
        $this->check_queued_backups();
        
        // Backup already in progress?
        $current_backup = get_option('swsib_current_backup', array());
        if (!empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
            $this->log_message('Backup already in progress, skipping scheduled check');
            return;
        }
        
        // Get all backup schedules
        $backup_schedules = $this->get_backup_schedules();
        
        if (empty($backup_schedules)) {
            $this->log_message('No backup schedules configured');
            return;
        }
        
        $current_time = time();
        
        foreach ($backup_schedules as $schedule_id => $schedule) {
            // Skip disabled schedules
            if (empty($schedule['enabled'])) {
                continue;
            }
            
            // Get last run time
            $last_run = isset($schedule['last_run']) ? $schedule['last_run'] : 0;
            
            // Get interval settings
            $interval_value = isset($schedule['interval_value']) ? intval($schedule['interval_value']) : 1;
            $interval_unit = isset($schedule['interval_unit']) ? $schedule['interval_unit'] : 'days';
            
            // Convert interval to seconds
            $interval_seconds = $this->convert_to_seconds($interval_value, $interval_unit);
            
            // Check if it's time for the next backup
            $elapsed = $current_time - $last_run;
            $next_run = $last_run + $interval_seconds;
            
            // Ensure next_run is stored in the schedule
            if (!isset($schedule['next_run']) || $schedule['next_run'] < $current_time) {
                $schedule['next_run'] = $next_run;
                $backup_schedules[$schedule_id] = $schedule;
                update_option('swsib_backup_schedules', $backup_schedules);
            }
            
            // If backup is due (considering a 5-minute buffer for reliability)
            if ($elapsed >= $interval_seconds || $current_time >= ($next_run - 300)) {
                $this->log_message("Scheduled backup {$schedule_id} ({$schedule['name']}) is due. Last backup: " . date('Y-m-d H:i:s', $last_run) . 
                                 ", Next backup due: " . date('Y-m-d H:i:s', $next_run) . 
                                 ", Current time: " . date('Y-m-d H:i:s', $current_time));
                                 
                // Check again if a backup is running (could have started since we checked earlier)
                $current_backup = get_option('swsib_current_backup', array());
                if (!empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
                    $this->log_message('A backup has started since our last check, queueing this scheduled backup for later');
                    $this->queue_backup_for_later($schedule_id);
                    return;
                }
                
                // Reset the retry counter for this schedule
                delete_option('swsib_backup_retry_' . $schedule_id);
                
                // Run the backup
                $this->run_backup_schedule($schedule_id);
                
                // After starting a backup, don't check other schedules in this run
                return;
            } else {
                $this->log_message("Scheduled backup check for {$schedule_id} ({$schedule['name']}) - Not due yet. Next backup in " . 
                                 human_time_diff($current_time, $next_run) . 
                                 " at " . date('Y-m-d H:i:s', $next_run));
            }
        }
    }
    
    /**
     * Check for any queued backups that need to be run
     */
    private function check_queued_backups() {
        $queued_backups = get_option('swsib_queued_backups', array());
        $current_time = time();
        
        if (empty($queued_backups)) {
            return;
        }
        
        $this->log_message("Checking " . count($queued_backups) . " queued backups");
        
        // Check if backup is already running
        $current_backup = get_option('swsib_current_backup', array());
        if (!empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
            $this->log_message('Backup already in progress, skipping queued backups check');
            return;
        }
        
        // Find due backups
        foreach ($queued_backups as $schedule_id => $queue_info) {
            if ($current_time >= $queue_info['run_at']) {
                $this->log_message("Queued backup for schedule ID {$schedule_id} is now due, running");
                
                // Remove from queue
                unset($queued_backups[$schedule_id]);
                update_option('swsib_queued_backups', $queued_backups);
                
                // Reset the retry counter for this schedule
                delete_option('swsib_backup_retry_' . $schedule_id);
                
                // Run the backup
                $this->run_backup_schedule($schedule_id);
                
                // Only run one queued backup at a time
                break;
            }
        }
    }
    
    /**
     * Force check all scheduled backups.
     * This is a reliable fallback method that runs hourly.
     *
     * @return void
     */
    public function force_check_backups() {
        $this->log_message('Running hourly force check for scheduled backups');
        $this->check_scheduled_backups();
        
        // Also check for any stalled background backups
        $this->check_stalled_backups();
    }
    
    /**
     * Check for any stalled background backups and attempt to resume them
     */
    private function check_stalled_backups() {
        // Check if a backup is in progress
        $current_backup = get_option('swsib_current_backup', array());
        
        if (empty($current_backup)) {
            return;
        }
        
        if (in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'))) {
            // Check if the backup is stalled (no heartbeat for 10+ minutes)
            $heartbeat = get_option('swsib_backup_heartbeat', 0);
            $current_time = time();
            
            if ($heartbeat > 0 && ($current_time - $heartbeat) > 600) {
                $this->log_message('Found stalled backup (no heartbeat for 10+ minutes), attempting to resume');
                
                // Clear any locks that might be preventing the backup from continuing
                delete_option('swsib_backup_process_lock');
                
                // Ensure background processing is enabled
                update_option('swsib_background_processing', true);
                
                // Update heartbeat to now
                update_option('swsib_backup_heartbeat', $current_time);
                
                // Trigger background processing
                $this->trigger_background_processing();
            }
        }
    }
    
    /**
     * AJAX handler for external cron trigger.
     * 
     * @return void
     */
    public function ajax_trigger_scheduled_backup() {
        // Basic security check with a key parameter
        $expected_key = md5('swsib_trigger_scheduled_backup');
        $provided_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        
        if ($provided_key === $expected_key || current_user_can('manage_options')) {
            $this->log_message('External cron trigger received for scheduled backup');
            
            // Check if a specific schedule ID was provided
            $schedule_id = isset($_GET['schedule_id']) ? sanitize_text_field($_GET['schedule_id']) : null;
            
            if ($schedule_id) {
                $this->log_message("Running specific scheduled backup: {$schedule_id}");
                $this->run_backup_schedule($schedule_id);
                wp_die('Specific backup triggered for schedule: ' . $schedule_id);
            } else {
                // Run all scheduled backups
                $this->check_scheduled_backups();
                wp_die('All scheduled backups checked');
            }
        } else {
            wp_die('Invalid security key');
        }
    }
    
    /**
     * AJAX handler for adding a new backup schedule
     */
    public function ajax_add_backup_schedule() {
        // Security check
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get schedule data
        $schedule_id = isset($_POST['id']) && !empty($_POST['id']) ? sanitize_text_field($_POST['id']) : 'schedule-' . time() . '-' . rand(1000, 9999);
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : __('Backup Schedule', 'swiftspeed-siberian');
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'full';
        $interval_value = isset($_POST['interval_value']) ? intval($_POST['interval_value']) : 1;
        $interval_unit = isset($_POST['interval_unit']) ? sanitize_key($_POST['interval_unit']) : 'days';
        $auto_lock = isset($_POST['auto_lock']) && $_POST['auto_lock'] === 'true';
        $enabled = isset($_POST['enabled']) ? $_POST['enabled'] === 'true' : true;
        
        // Get storage providers - handle multiple selections
        $storage_providers = isset($_POST['storages']) ? (array)$_POST['storages'] : array('local');
        
        // Ensure local storage is always included
        if (!in_array('local', $storage_providers)) {
            $storage_providers[] = 'local';
        }
        
        // Sanitize storage providers
        $storage_providers = array_map('sanitize_key', $storage_providers);
        
        // Get current schedules
        $schedules = $this->get_backup_schedules();
        
        // Check if we're updating an existing schedule
        $is_update = isset($schedules[$schedule_id]);
        
        // Check if a schedule for this backup type already exists (only for new schedules)
        if (!$is_update) {
            foreach ($schedules as $existing_schedule) {
                if ($existing_schedule['type'] === $type) {
                    wp_send_json_error(array(
                        'message' => sprintf(
                            __('A schedule for %s backup already exists. Only one schedule per backup type is allowed.', 'swiftspeed-siberian'),
                            $type === 'full' ? 'full' : ($type === 'db' ? 'database' : 'files')
                        )
                    ));
                    return;
                }
            }
        }
        
        // Create or update the schedule
        $schedule = array(
            'id' => $schedule_id,
            'enabled' => $enabled,
            'name' => $name,
            'type' => $type,
            'interval_value' => $interval_value,
            'interval_unit' => $interval_unit,
            'auto_lock' => $auto_lock,
            'storages' => $storage_providers,
            'next_run' => $is_update && isset($schedules[$schedule_id]['next_run']) ? 
                        $schedules[$schedule_id]['next_run'] : (time() + 300), // Default to 5 minutes from now
            'created' => $is_update && isset($schedules[$schedule_id]['created']) ? 
                         $schedules[$schedule_id]['created'] : time(),
            'last_run' => $is_update && isset($schedules[$schedule_id]['last_run']) ? 
                        $schedules[$schedule_id]['last_run'] : 0,
        );
        
        // Add or update the schedule
        $schedules[$schedule_id] = $schedule;
        update_option('swsib_backup_schedules', $schedules);
        
        // Reset the schedule in cron
        $this->reset_schedule($schedule_id);
        
        wp_send_json_success(array(
            'message' => $is_update ? 
                         __('Backup schedule updated successfully', 'swiftspeed-siberian') : 
                         __('Backup schedule added successfully', 'swiftspeed-siberian'),
            'schedule' => $schedule
        ));
    }
    
    /**
     * AJAX handler for deleting a backup schedule
     */
    public function ajax_delete_backup_schedule() {
        // Security check
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get schedule ID
        $schedule_id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
        
        if (empty($schedule_id)) {
            wp_send_json_error(array('message' => __('No schedule ID provided', 'swiftspeed-siberian')));
        }
        
        // Get current schedules
        $schedules = $this->get_backup_schedules();
        
        if (!isset($schedules[$schedule_id])) {
            wp_send_json_error(array('message' => __('Schedule not found', 'swiftspeed-siberian')));
        }
        
        // Clear the scheduled event - IMPORTANT: Use the shared hook name with the schedule ID as parameter
        wp_clear_scheduled_hook('swsib_run_backup_schedule', array($schedule_id));
        
        // Remove from queued backups if present
        $queued_backups = get_option('swsib_queued_backups', array());
        if (isset($queued_backups[$schedule_id])) {
            unset($queued_backups[$schedule_id]);
            update_option('swsib_queued_backups', $queued_backups);
        }
        
        // Remove any retry counters for this schedule
        delete_option('swsib_backup_retry_' . $schedule_id);
        
        // Remove the schedule
        unset($schedules[$schedule_id]);
        update_option('swsib_backup_schedules', $schedules);
        
        wp_send_json_success(array(
            'message' => __('Backup schedule deleted successfully', 'swiftspeed-siberian')
        ));
    }
    
    /**
     * AJAX handler for getting all backup schedules
     */
    public function ajax_get_backup_schedules() {
        // Security check
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Get the schedules
        $schedules = $this->get_backup_schedules();
        
        // Enhance with next run time in human readable format
        foreach ($schedules as &$schedule) {
            if (isset($schedule['next_run'])) {
                $schedule['next_run_human'] = human_time_diff(time(), $schedule['next_run']);
                $schedule['next_run_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['next_run']);
            } else {
                $schedule['next_run_human'] = __('Not scheduled', 'swiftspeed-siberian');
                $schedule['next_run_date'] = __('Not scheduled', 'swiftspeed-siberian');
            }
            
            if (isset($schedule['last_run']) && $schedule['last_run'] > 0) {
                $schedule['last_run_human'] = human_time_diff($schedule['last_run'], time());
                $schedule['last_run_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['last_run']);
            } else {
                $schedule['last_run_human'] = __('Never', 'swiftspeed-siberian');
                $schedule['last_run_date'] = __('Never', 'swiftspeed-siberian');
            }
            
            // Check for missed runs
            if (isset($schedule['next_run']) && $schedule['next_run'] < time() && $schedule['enabled']) {
                $schedule['missed'] = true;
                $schedule['missed_by'] = human_time_diff($schedule['next_run'], time());
            } else {
                $schedule['missed'] = false;
            }
        }
        
        wp_send_json_success(array(
            'schedules' => $schedules
        ));
    }
    
    /**
     * Cleanup old backups task.
     *
     * @return void
     */
    public function cleanup_old_backups() {
        if (!$this->backup_processor) {
            $this->log_message('Cannot cleanup old backups: Backup processor not set');
            return;
        }
        
        $this->log_message('Running scheduled cleanup of old backups');
        
        // Run the cleanup via the backup processor
        $this->backup_processor->cleanup_old_backups();
    }
    
    /**
     * Reset and reschedule backup events for a specific schedule.
     * Useful after settings changes.
     *
     * @param string $schedule_id The schedule ID to reset
     * @return void
     */
    public function reset_schedule($schedule_id = null) {
        if ($schedule_id) {
            // Clear existing scheduled event for this schedule - IMPORTANT: Use the shared hook name
            wp_clear_scheduled_hook('swsib_run_backup_schedule', array($schedule_id));
            
            // Get the schedule
            $schedules = $this->get_backup_schedules();
            
            if (isset($schedules[$schedule_id])) {
                $schedule = $schedules[$schedule_id];
                
                // Skip if disabled
                if (empty($schedule['enabled'])) {
                    return;
                }
                
                // Get interval settings
                $interval_value = isset($schedule['interval_value']) ? intval($schedule['interval_value']) : 1;
                $interval_unit = isset($schedule['interval_unit']) ? $schedule['interval_unit'] : 'days';
                
                // Get the schedule name
                $schedule_name = $this->get_schedule_name($interval_value, $interval_unit);
                
                // Schedule the event
                $next_run = isset($schedule['next_run']) && $schedule['next_run'] > time() ? 
                            $schedule['next_run'] : (time() + 300);
                
                wp_schedule_event($next_run, $schedule_name, 'swsib_run_backup_schedule', array($schedule_id));
                
                $this->log_message("Schedule reset for ID {$schedule_id} ({$schedule['name']}) with schedule: {$schedule_name}, next run: " . date('Y-m-d H:i:s', $next_run));
            }
        } else {
            // Reset all schedules
            $this->clear_scheduled_events();
            $this->register_backup_schedules();
            
            $this->log_message('All backup schedules have been reset');
        }
    }
    
    /**
     * Clear all scheduled backup events.
     *
     * @return void
     */
    public function clear_scheduled_events() {
        // Clear all individual schedule events
        $schedules = $this->get_backup_schedules();
        
        foreach ($schedules as $schedule_id => $schedule) {
            wp_clear_scheduled_hook('swsib_run_backup_schedule', array($schedule_id));
            
            // Also clear any retry counters
            delete_option('swsib_backup_retry_' . $schedule_id);
        }
        
        // Clear all queued backup events
        $queued_backups = get_option('swsib_queued_backups', array());
        foreach ($queued_backups as $schedule_id => $queue_info) {
            wp_clear_scheduled_hook('swsib_process_queued_backup', array($schedule_id));
        }
        
        // Clear the queued backups option
        update_option('swsib_queued_backups', array());
        
        // Clear main cron events
        wp_clear_scheduled_hook('swsib_check_scheduled_backups');
        wp_clear_scheduled_hook('swsib_hourly_backup_check');
        
        $this->log_message('All scheduled backup events cleared');
    }
    
    /**
     * Get all backup schedules.
     * 
     * @return array Array of backup schedules
     */
    public function get_backup_schedules() {
        return get_option('swsib_backup_schedules', array());
    }
    
    /**
     * Log a message for debugging.
     * 
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'cron', $message);
        }
    }
}