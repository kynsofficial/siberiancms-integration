<?php
/**
 * Storage Manager for Backup/Restore functionality.
 * Manages all storage providers.
 */
class SwiftSpeed_Siberian_Storage_Manager {
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Available storage providers.
     * 
     * @var array
     */
    private $providers = [];
    
    /**
     * Backup directory.
     * 
     * @var string
     */
    private $backup_dir;
    protected $storage_manager;


    /**
     * Constructor.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->load_providers();
        
        // Add AJAX handlers for Google Drive authentication
        add_action('wp_ajax_swsib_gdrive_auth_redirect', array($this, 'handle_gdrive_auth_redirect'));
        add_action('wp_ajax_swsib_gdrive_auth_callback', array($this, 'handle_gdrive_auth_callback'));
        add_action('wp_ajax_swsib_test_storage_connection', array($this, 'handle_test_storage_connection'));
        
        // Add new AJAX handler to check connection status
        add_action('wp_ajax_swsib_check_storage_connection', array($this, 'handle_check_storage_connection'));
        
        // Add an action hook to process Google Drive auth on page load if needed
        add_action('admin_init', array($this, 'process_gdrive_auth_on_page_load'));

       
       
           // Get storage manager instance
           if (function_exists('swsib') && isset(swsib()->storage_manager)) {
        $this->storage_manager = swsib()->storage_manager;
    }

    }

/**
 * Clean up old backups - delegates to storage manager.
 *
 * @param string $backup_type Type of backup to clean up
 * @return void
 */
protected function cleanup_old_backups($backup_type) {
    if ($this->storage_manager) {
        $this->log_message('Triggering cleanup of old ' . $backup_type . ' backups');
        $this->storage_manager->cleanup_old_backups();
    } else {
        $this->log_message('Cannot clean up old backups: Storage manager not available');
    }
}
    
    /**
     * Process Google Drive authentication data on page load if needed.
     * This is a new method to handle auth data from the query string.
     */
    public function process_gdrive_auth_on_page_load() {
        // Only run on admin pages
        if (!is_admin()) {
            return;
        }
        
        // Check if we have the auth data in the URL
        if (isset($_GET['gdrive_auth_data']) && !empty($_GET['gdrive_auth_data'])) {
            // Verify this is our backup/restore page
            if (isset($_GET['page']) && $_GET['page'] === 'swsib-integration') {
                $this->log_message('Found auth data in URL, processing');
                $this->handle_gdrive_auth_from_query();
            }
        }
    }
    
    /**
     * Handle Google Drive auth data from query string.
     * This is a new method that processes the auth data from the URL.
     */
    public function handle_gdrive_auth_from_query() {
        // Get Google Drive provider
        $provider = $this->get_provider('gdrive');
        
        if (!$provider) {
            $this->log_message('Google Drive provider not found');
            return;
        }
        
        // Process the tokens from the proxy
        if (method_exists($provider, 'process_proxy_tokens')) {
            $encrypted_data = sanitize_text_field($_GET['gdrive_auth_data']);
            $this->log_message('Processing auth data from URL, length: ' . strlen($encrypted_data));
            
            $tokens = $provider->process_proxy_tokens($encrypted_data);
            
            if (is_wp_error($tokens)) {
                $this->log_message('Error processing auth tokens: ' . $tokens->get_error_message());
                return;
            }
            
            $this->log_message('Successfully processed auth tokens from URL');
            
            // Save the tokens in WordPress options
            $options = get_option('swsib_options', []);
            
            if (!isset($options['backup_restore'])) {
                $options['backup_restore'] = [];
            }
            
            if (!isset($options['backup_restore']['storage'])) {
                $options['backup_restore']['storage'] = [];
            }
            
            if (!isset($options['backup_restore']['storage']['gdrive'])) {
                $options['backup_restore']['storage']['gdrive'] = [];
            }
            
            $options['backup_restore']['storage']['gdrive']['access_token'] = $tokens['access_token'];
            $options['backup_restore']['storage']['gdrive']['refresh_token'] = $tokens['refresh_token'];
            
            // Add account info if available
            if (isset($tokens['email'])) {
                $options['backup_restore']['storage']['gdrive']['account_info'] = $tokens['email'];
                $this->log_message('Saved account email: ' . $tokens['email']);
            }
            
            $update_result = update_option('swsib_options', $options);
            $this->log_message('Options update result: ' . ($update_result ? 'Success' : 'No change or failure'));
            
            // Reload the page with success parameter but without the auth data to avoid
            // reprocessing if the user refreshes the page
            $redirect_url = admin_url('admin.php?page=swsib-integration&tab_id=backup_restore&gdrive_auth_success=1') . '#settings';
            wp_redirect($redirect_url);
            exit;
        }
    }
    
    /**
     * Write to log using the central logging manager.

     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'storage', $message);
        }
    }
    
    /**
     * Load all available storage providers.
     *
     * @return void
     */
    private function load_providers() {
        // Define available providers
        $available_providers = [
            'local' => 'SwiftSpeed_Siberian_Storage_Local',
            'gdrive' => 'SwiftSpeed_Siberian_Storage_GDrive',
            'gcs' => 'SwiftSpeed_Siberian_Storage_GCS',
            's3' => 'SwiftSpeed_Siberian_Storage_S3',
        ];
        
        foreach ($available_providers as $id => $class_name) {
            $file_path = SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-' . $id . '.php';
            
            if (file_exists($file_path)) {
                require_once $file_path;
                
                if (class_exists($class_name)) {
                    // Get provider config
                    $config = [];
                    if (isset($this->options['backup_restore']['storage'][$id])) {
                        $config = $this->options['backup_restore']['storage'][$id];
                    }
                    
                    $this->providers[$id] = new $class_name($config);
                }
            }
        }
    }
    
    /**
     * Get all available storage providers.

     */
    public function get_providers() {
        return $this->providers;
    }
    
    /**
     * Get a specific storage provider.
     
     */
    public function get_provider($provider_id) {
        return isset($this->providers[$provider_id]) ? $this->providers[$provider_id] : null;
    }
    
    /**
     * Get all configured storage providers.

     */
    public function get_configured_providers() {
        $configured = [];
        
        foreach ($this->providers as $id => $provider) {
            if ($provider->is_configured()) {
                $configured[$id] = $provider;
            }
        }
        
        return $configured;
    }
    
    /**
     * Get form fields for all storage providers.

     */
    public function get_provider_fields() {
        $fields = [];
        
        foreach ($this->providers as $id => $provider) {
            $fields[$id] = [
                'id' => $id,
                'name' => $provider->get_display_name(),
                'fields' => $provider->get_config_fields(),
                'is_configured' => $provider->is_configured(),
            ];
        }
        
        return $fields;
    }
    
    /**
     * Test connection to a storage provider.
     */
    public function test_connection($provider_id) {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        return $provider->test_connection();
    }
    
    /**
     * Validate storage provider configuration.
     */
    public function validate_config($provider_id, $config) {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        return $provider->validate_config($config);
    }
    
    /**
     * Upload a file to a storage provider.
     */
    public function upload_file($provider_id, $source_path, $destination_path, $metadata = []) {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        if (!$provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Storage provider not configured', 'swiftspeed-siberian'));
        }
        
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            return $init_result;
        }
        
        return $provider->upload_file($source_path, $destination_path, $metadata);
    }
    
    /**
     * Download a file from a storage provider.
     *
     */
    public function download_file($provider_id, $source_path, $destination_path) {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        if (!$provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Storage provider not configured', 'swiftspeed-siberian'));
        }
        
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            return $init_result;
        }
        
        return $provider->download_file($source_path, $destination_path);
    }
    
    /**
     * List files in a storage provider.
     */
    public function list_files($provider_id, $directory = '') {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        if (!$provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Storage provider not configured', 'swiftspeed-siberian'));
        }
        
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            return $init_result;
        }
        
        return $provider->list_files($directory);
    }
    
    /**
     * Delete a file from a storage provider.
     */
    public function delete_file($provider_id, $file_path) {
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            return new WP_Error('provider_not_found', __('Storage provider not found', 'swiftspeed-siberian'));
        }
        
        if (!$provider->is_configured()) {
            return new WP_Error('provider_not_configured', __('Storage provider not configured', 'swiftspeed-siberian'));
        }
        
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            return $init_result;
        }
        
        return $provider->delete_file($file_path);
    }
    
/**
 * Get all available backups from all storage providers.
 * Enhanced to properly detect and display all backups even those without history entries.
 */
public function get_all_backups() {
    $all_backups = [];
    $backup_history = get_option('swsib_backup_history', []);
    $backup_pattern = '/^siberian-backup-(full|db|file|files)-(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.zip$/';
    
    // Map for tracking backups by filename to merge entries from different storages
    $backup_map = [];
    
    $this->log_message('Scanning for backups from all sources');
    
    // First add all backups from history
    foreach ($backup_history as $id => $backup) {
        // Skip entries without a filename
        if (empty($backup['file'])) {
            continue;
        }

        $backup['provider'] = isset($backup['storage']) ? $backup['storage'] : 'local';
        $backup['provider_name'] = $this->get_provider_display_name($backup['provider']);
        
        // Make sure the timestamp is set
        if (!isset($backup['timestamp']) && isset($backup['created'])) {
            $backup['timestamp'] = $backup['created'];
        } elseif (!isset($backup['timestamp'])) {
            // If no timestamp is available, try to parse it from the filename
            if (preg_match($backup_pattern, $backup['file'], $matches)) {
                $timestamp = strtotime(str_replace('-', ' ', $matches[2]));
                if ($timestamp) {
                    $backup['timestamp'] = $timestamp;
                } else {
                    $backup['timestamp'] = time(); // Fallback to current time
                }
            } else {
                $backup['timestamp'] = time(); // Fallback to current time
            }
        }
        
        $filename = $backup['file'];
        
        // Track backups by filename to merge later
        if (!isset($backup_map[$filename])) {
            $backup_map[$filename] = [
                'id' => $id,
                'file' => $filename,
                'backup_type' => isset($backup['backup_type']) ? $backup['backup_type'] : $this->detect_backup_type($filename),
                'storages' => [isset($backup['storage']) ? $backup['storage'] : 'local'],
                'providers' => [isset($backup['storage']) ? $backup['storage'] : 'local' => [
                    'provider' => isset($backup['storage']) ? $backup['storage'] : 'local',
                    'provider_name' => $backup['provider_name'],
                    'path' => isset($backup['path']) ? $backup['path'] : '',
                    'file_id' => isset($backup['storage_info']['file_id']) ? $backup['storage_info']['file_id'] : '',
                ]],
                'size' => isset($backup['size']) ? $backup['size'] : '0 KB',
                'bytes' => isset($backup['bytes']) ? $backup['bytes'] : 0,
                'created' => $backup['timestamp'],
                'timestamp' => $backup['timestamp'],
                'date' => isset($backup['date']) ? $backup['date'] : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $backup['timestamp']),
                'locked' => isset($backup['locked']) ? $backup['locked'] : false,
                'storage_names' => [$this->get_provider_display_name(isset($backup['storage']) ? $backup['storage'] : 'local')],
                'provider' => isset($backup['storage']) ? $backup['storage'] : 'local', // Primary provider for file operations
                'scheduled' => isset($backup['scheduled']) ? $backup['scheduled'] : false, // Preserve scheduled flag
            ];
            
            // Check if we have additional upload details in the 'uploaded_to' field
            if (isset($backup['uploaded_to']) && is_array($backup['uploaded_to'])) {
                $backup_map[$filename]['storages'] = $backup['uploaded_to'];
                
                $storage_names = [];
                foreach ($backup['uploaded_to'] as $provider_id) {
                    $storage_names[] = $this->get_provider_display_name($provider_id);
                }
                $backup_map[$filename]['storage_names'] = $storage_names;
            }
        } else {
            // If this backup is already in the map, add this storage if not already there
            if (!in_array($backup['provider'], $backup_map[$filename]['storages'])) {
                $backup_map[$filename]['storages'][] = $backup['provider'];
                $backup_map[$filename]['storage_names'][] = $backup['provider_name'];
                $backup_map[$filename]['providers'][$backup['provider']] = [
                    'provider' => $backup['provider'],
                    'provider_name' => $backup['provider_name'],
                    'path' => isset($backup['path']) ? $backup['path'] : '',
                    'file_id' => isset($backup['storage_info']['file_id']) ? $backup['storage_info']['file_id'] : '',
                ];
            }
            
            // Use the locked status from any provider (if one is locked, keep it locked)
            if (isset($backup['locked']) && $backup['locked']) {
                $backup_map[$filename]['locked'] = true;
            }
            
            // Preserve scheduled flag if it's set in any backup entry
            if (isset($backup['scheduled']) && $backup['scheduled']) {
                $backup_map[$filename]['scheduled'] = true;
            }
        }
    }
    
    $this->log_message('Found ' . count($backup_map) . ' backups in history');
    
    // Create a list of filenames already in history for quick lookup
    $history_filenames = [];
    foreach ($backup_history as $backup) {
        if (!empty($backup['file'])) {
            $history_filenames[$backup['file']] = true;
        }
    }
    
    // Scan local backup directory for files not in history
    $local_count = 0;
    if (file_exists($this->backup_dir) && is_dir($this->backup_dir)) {
        $files = scandir($this->backup_dir);
        
        foreach ($files as $file) {
            // Skip directories and non-zip files
            if (is_dir($this->backup_dir . $file) || !preg_match($backup_pattern, $file, $matches)) {
                continue;
            }
            
            $local_count++;
            $backup_type = $matches[1];
            $timestamp_part = $matches[2];
            $timestamp = strtotime(str_replace('-', ' ', $timestamp_part));
            
            // Check if this file is already in the map
            if (isset($backup_map[$file])) {
                // Add local provider if not already in the list
                if (!in_array('local', $backup_map[$file]['storages'])) {
                    $backup_map[$file]['storages'][] = 'local';
                    $backup_map[$file]['storage_names'][] = $this->get_provider_display_name('local');
                    
                    $file_path = $this->backup_dir . $file;
                    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
                    
                    $backup_map[$file]['providers']['local'] = [
                        'provider' => 'local',
                        'provider_name' => $this->get_provider_display_name('local'),
                        'path' => $file_path,
                    ];
                    
                    // Update size if we have a file size
                    if ($file_size > 0) {
                        $backup_map[$file]['size'] = size_format($file_size, 2);
                        $backup_map[$file]['bytes'] = $file_size;
                    }
                }
            } else {
                // FIX: Only add as a new backup if it isn't already in history
                if (!isset($history_filenames[$file])) {
                    // New file not in history
                    $file_path = $this->backup_dir . $file;
                    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
                    
                    $new_id = 'local-' . md5($file . $timestamp);
                    $backup_map[$file] = [
                        'id' => $new_id,
                        'file' => $file,
                        'backup_type' => $backup_type,
                        'storages' => ['local'],
                        'providers' => ['local' => [
                            'provider' => 'local',
                            'provider_name' => $this->get_provider_display_name('local'),
                            'path' => $file_path,
                        ]],
                        'size' => size_format($file_size, 2),
                        'bytes' => $file_size,
                        'created' => $timestamp,
                        'timestamp' => $timestamp,
                        'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp),
                        'locked' => false,
                        'storage_names' => [$this->get_provider_display_name('local')],
                        'provider' => 'local', // Primary provider for file operations
                        'scheduled' => false, // Default to false for newly discovered backups
                    ];
                    
                    $this->log_message('Found new local backup not in history: ' . $file);
                } else {
                    $this->log_message('Skipping local backup already in history: ' . $file);
                }
            }
        }
        
        $this->log_message('Found ' . $local_count . ' backups in local filesystem');
    }
    
    // Check all configured external storage providers
    $external_found = 0;
    foreach ($this->providers as $id => $provider) {
        if ($id === 'local' || !$provider->is_configured()) {
            continue;
        }
        
        $this->log_message('Checking for backups in ' . $id . ' storage');
        
        // Ensure provider is initialized properly
        $init_result = $provider->initialize();
        if (is_wp_error($init_result)) {
            $this->log_message('Failed to initialize provider: ' . $id . ' - ' . $init_result->get_error_message());
            continue;
        }
        
        // For Google Drive, try to refresh token if needed
        if ($id === 'gdrive' && method_exists($provider, 'refresh_access_token')) {
            // Try to refresh the token to ensure we have a valid one
            $refresh_result = $provider->refresh_access_token();
            if (is_wp_error($refresh_result)) {
                $this->log_message('Failed to refresh Google Drive token: ' . $refresh_result->get_error_message());
            }
        }
        
        // List files from the provider with retries for resilience
        $max_retries = 2;
        $backups = null;
        
        for ($retry = 0; $retry <= $max_retries; $retry++) {
            if ($retry > 0) {
                $this->log_message('Retrying provider ' . $id . ' (attempt ' . ($retry) . ' of ' . $max_retries . ')');
                // If this is a retry and provider supports token refresh, try to refresh again
                if (method_exists($provider, 'refresh_access_token')) {
                    $provider->refresh_access_token();
                }
            }
            
            $backups = $provider->list_files();
            
            if (!is_wp_error($backups)) {
                break; // Success, exit retry loop
            } else {
                $this->log_message('Error listing files from ' . $id . ' (attempt ' . ($retry + 1) . '): ' . $backups->get_error_message());
            }
        }
        
        if (!is_wp_error($backups) && is_array($backups)) {
            $provider_found = 0;
            $this->log_message('Successfully retrieved file list from ' . $id . ': ' . count($backups) . ' files found');
            
            foreach ($backups as $backup) {
                $file = $backup['file'];
                
                // Skip files that don't match our naming pattern
                if (!preg_match($backup_pattern, $file, $matches)) {
                    $this->log_message('Skipping file that does not match backup pattern: ' . $file);
                    continue;
                }
                
                $provider_found++;
                $backup_type = $matches[1];
                $timestamp_part = $matches[2];
                
                // Check if this file is already in our map
                if (isset($backup_map[$file])) {
                    // Add this provider if not already in the list
                    if (!in_array($id, $backup_map[$file]['storages'])) {
                        $backup_map[$file]['storages'][] = $id;
                        $backup_map[$file]['storage_names'][] = $provider->get_display_name();
                        
                        $backup_map[$file]['providers'][$id] = [
                            'provider' => $id,
                            'provider_name' => $provider->get_display_name(),
                            'path' => isset($backup['path']) ? $backup['path'] : '',
                            'file_id' => isset($backup['file_id']) ? $backup['file_id'] : '',
                        ];
                    }
                } else {
                    // New file not in history
                    // Try to get timestamp from filename or use the one from the backup object
                    $timestamp = isset($backup['timestamp']) ? $backup['timestamp'] : 0;
                    if (!$timestamp && isset($timestamp_part)) {
                        $timestamp = strtotime(str_replace('-', ' ', $timestamp_part));
                    }
                    
                    // For external providers, generate a predictable ID based on filename and timestamp
                    $new_id = $id . '-' . md5($file . ($timestamp ?? ''));
                    $backup_map[$file] = [
                        'id' => $new_id,
                        'file' => $file,
                        'backup_type' => $backup_type,
                        'storages' => [$id],
                        'providers' => [$id => [
                            'provider' => $id,
                            'provider_name' => $provider->get_display_name(),
                            'path' => isset($backup['path']) ? $backup['path'] : '',
                            'file_id' => isset($backup['file_id']) ? $backup['file_id'] : '',
                        ]],
                        'size' => isset($backup['size']) ? $backup['size'] : '0 KB',
                        'bytes' => isset($backup['bytes']) ? $backup['bytes'] : 0,
                        'created' => $timestamp,
                        'timestamp' => $timestamp,
                        'date' => isset($backup['date']) ? $backup['date'] : date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp ?: time()),
                        'locked' => false,
                        'storage_names' => [$provider->get_display_name()],
                        'provider' => $id, // Primary provider for file operations
                        'scheduled' => false, // Default to false for newly discovered backups
                    ];
                    $this->log_message('Found new backup in ' . $id . ': ' . $file);
                }
            }
            
            $this->log_message($provider_found . ' matching backup files found in ' . $id . ' storage');
            $external_found += $provider_found;
            
            // If we found new backups in external storage, add them to the database history
            if ($provider_found > 0) {
                $this->update_backup_history_from_storage($backup_map);
            }
        } else {
            if (is_wp_error($backups)) {
                $this->log_message('Error retrieving backups from ' . $id . ': ' . $backups->get_error_message());
            } else {
                $this->log_message('No backups found in ' . $id . ' or invalid response');
            }
        }
    }
    
    $this->log_message('Found a total of ' . $external_found . ' backups in external storage');
    
    // Convert backup map to list
    $all_backups = [];
    foreach ($backup_map as $filename => $backup) {
        // Ensure provider field is set correctly - use most "permanent" storage
        // Priority: gdrive, s3, gcs, local
        if (count($backup['storages']) > 1) {
            $priority_storages = ['gdrive', 's3', 'gcs', 'local'];
            foreach ($priority_storages as $storage_type) {
                if (in_array($storage_type, $backup['storages'])) {
                    $backup['provider'] = $storage_type;
                    break;
                }
            }
        }
        
        // Format as a single string for display - always use "Local" not "WordPress Filesystem"
        $storage_names = [];
        foreach ($backup['storage_names'] as $name) {
            if ($name === 'WordPress Filesystem') {
                $storage_names[] = 'Local';
            } else {
                $storage_names[] = $name;
            }
        }
        $backup['storage_names'] = $storage_names;
        $backup['storage_display'] = implode(', ', $storage_names);
        
        // Add to all backups list
        $all_backups[$backup['id']] = $backup;
    }
    
    // Sort by timestamp, newest first
    uasort($all_backups, function($a, $b) {
        return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
    });
    
    $this->log_message('Returning ' . count($all_backups) . ' total backup entries');
    return $all_backups;
}


/**
 * Detect backup type from filename.
 */
private function detect_backup_type($filename) {
    if (preg_match('/siberian-backup-(full|db|file|files)-(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})/', $filename, $matches)) {
        $type = $matches[1];
        if ($type === 'file') {
            return 'files';
        }
        return $type;
    }
    return 'unknown';
}
    
/**
 * Update backup history database with newly discovered backups from storage.
 * This ensures backups found in storage but not in history are properly recorded.
 * 
 * @param array $backup_map The map of all discovered backups
 * @return void
 */
private function update_backup_history_from_storage($backup_map) {
    $history = get_option('swsib_backup_history', []);
    $history_by_file = [];
    $history_by_id = [];
    $updated = false;
    
    // First, create maps of history entries by filename and ID for easy lookup
    foreach ($history as $id => $backup) {
        $history_by_file[$backup['file']] = $id;
        $history_by_id[$id] = true;
    }
    
    // Now check each backup in the map
    foreach ($backup_map as $filename => $backup) {
        // Skip if this backup ID is already in history OR a backup with the same filename exists
        if (isset($history_by_id[$backup['id']]) || isset($history_by_file[$filename])) {
            // If the backup exists but with different storage providers, update the storage info
            if (isset($history_by_file[$filename])) {
                $existing_id = $history_by_file[$filename];
                
                // Only update if this is a different entry (shouldn't happen, but check anyway)
                if ($existing_id !== $backup['id']) {
                    $this->log_message('Found backup with same filename but different ID: ' . $filename);
                    
                    // Merge storage providers if needed
                    if (isset($history[$existing_id]['uploaded_to']) && 
                        isset($backup['storages']) && 
                        is_array($backup['storages']) && 
                        is_array($history[$existing_id]['uploaded_to'])) {
                        
                        // Get existing and new storages
                        $existing_storages = $history[$existing_id]['uploaded_to'];
                        $new_storages = $backup['storages'];
                        
                        // Merge and make unique
                        $merged_storages = array_unique(array_merge($existing_storages, $new_storages));
                        
                        // Only update if there are new storage providers
                        if (count($merged_storages) > count($existing_storages)) {
                            $history[$existing_id]['uploaded_to'] = $merged_storages;
                            $updated = true;
                            $this->log_message('Updated storage providers for existing backup: ' . $filename);
                        }
                    }
                }
            }
            
            // Skip adding this backup as a new entry
            continue;
        }
        
        // Only proceed if we have a valid ID and filename
        if (empty($backup['id']) || empty($filename)) {
            continue;
        }
        
        // Add this backup to history as it's genuinely new
        $history[$backup['id']] = [
            'id' => $backup['id'],
            'backup_type' => $backup['backup_type'],
            'file' => $filename,
            'size' => $backup['size'],
            'bytes' => $backup['bytes'],
            'storage' => $backup['provider'],
            'created' => $backup['timestamp'],
            'locked' => false,
            'uploaded_to' => $backup['storages'],
        ];
        
        // Add storage_info if available
        if (isset($backup['providers'][$backup['provider']]['file_id'])) {
            $history[$backup['id']]['storage_info'] = [
                'file_id' => $backup['providers'][$backup['provider']]['file_id']
            ];
        }
        
        // Add path if it's a local backup
        if (in_array('local', $backup['storages']) && isset($backup['providers']['local']['path'])) {
            $history[$backup['id']]['path'] = $backup['providers']['local']['path'];
        }
        
        $updated = true;
        $this->log_message('Added previously unknown backup to history: ' . $filename);
    }
    
    // Save updated history if changes were made
    if ($updated) {
        update_option('swsib_backup_history', $history);
        $this->log_message('Updated backup history database with ' . count($history) . ' total entries');
    }
}
    
    /**
     * Get the display name of a storage provider.
     */
    private function get_provider_display_name($provider_id) {
        $provider = $this->get_provider($provider_id);
        if ($provider) {
            return $provider->get_display_name();
        }
        
        // Fallback display names
        $display_names = [
            'local' => __('Local', 'swiftspeed-siberian'),
            'gdrive' => __('Google Drive', 'swiftspeed-siberian'),
            'gcs' => __('Google Cloud Storage', 'swiftspeed-siberian'),
            's3' => __('Amazon S3', 'swiftspeed-siberian'),
        ];
        
        return isset($display_names[$provider_id]) ? $display_names[$provider_id] : $provider_id;
    }
    
    /**
     * Process settings for storage providers.
     */
    public function process_settings($input) {
        if (!isset($input['storage'])) {
            return $input;
        }
        
        $storage_settings = $input['storage'];
        $processed = [];
        
        // First, get existing storage settings to preserve tokens that might not be in the form
        $existing_options = get_option('swsib_options', []);
        $existing_storage = isset($existing_options['backup_restore']['storage']) ? $existing_options['backup_restore']['storage'] : [];
        
        foreach ($this->providers as $id => $provider) {
            if (isset($storage_settings[$id])) {
                // For Google Drive, preserve access and refresh tokens if they exist
                if ($id === 'gdrive') {
                    // Keep existing tokens if they are not in the new settings
                    if (isset($existing_storage['gdrive']['access_token']) && !isset($storage_settings['gdrive']['access_token'])) {
                        $storage_settings['gdrive']['access_token'] = $existing_storage['gdrive']['access_token'];
                    }
                    
                    if (isset($existing_storage['gdrive']['refresh_token']) && !isset($storage_settings['gdrive']['refresh_token'])) {
                        $storage_settings['gdrive']['refresh_token'] = $existing_storage['gdrive']['refresh_token'];
                    }
                    
                    if (isset($existing_storage['gdrive']['account_info']) && !isset($storage_settings['gdrive']['account_info'])) {
                        $storage_settings['gdrive']['account_info'] = $existing_storage['gdrive']['account_info'];
                    }
                }
                
                $config = $storage_settings[$id];
                $validated = $provider->validate_config($config);
                
                if (!is_wp_error($validated)) {
                    $processed[$id] = $validated;
                }
            } elseif (isset($existing_storage[$id])) {
                // Keep existing settings for providers not in the form
                $processed[$id] = $existing_storage[$id];
            }
        }
        
        $input['storage'] = $processed;
        return $input;
    }
    
    /**
     * Handle AJAX request to check storage connection status.
     */
    public function handle_check_storage_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $provider_id = isset($_POST['provider']) ? sanitize_key($_POST['provider']) : '';
        
        if (empty($provider_id)) {
            wp_send_json_error(array('message' => __('No provider specified', 'swiftspeed-siberian')));
        }
        
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            wp_send_json_error(array('message' => __('Provider not found', 'swiftspeed-siberian')));
        }
        
        // Check if the provider is configured
        $is_configured = $provider->is_configured();
        
        $response_data = array(
            'connected' => $is_configured,
            'provider' => $provider_id,
            'provider_name' => $provider->get_display_name(),
        );
        
        // If it's Google Drive, try to get the user email
        if ($provider_id === 'gdrive' && $is_configured) {
            // Make a simple API call to get user info
            try {
                $access_token = isset($this->options['backup_restore']['storage']['gdrive']['access_token']) 
                    ? $this->options['backup_restore']['storage']['gdrive']['access_token'] 
                    : '';
                
                if (!empty($access_token)) {
                    $response = wp_remote_get(
                        'https://www.googleapis.com/drive/v3/about?fields=user',
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $access_token,
                            ],
                            'timeout' => 10,
                        ]
                    );
                    
                    if (!is_wp_error($response)) {
                        $status_code = wp_remote_retrieve_response_code($response);
                        $response_body = json_decode(wp_remote_retrieve_body($response), true);
                        
                        if ($status_code >= 200 && $status_code < 300 && isset($response_body['user']['emailAddress'])) {
                            $response_data['email'] = $response_body['user']['emailAddress'];
                            
                            // Update account info in options
                            $options = get_option('swsib_options', []);
                            if (!isset($options['backup_restore'])) {
                                $options['backup_restore'] = [];
                            }
                            if (!isset($options['backup_restore']['storage'])) {
                                $options['backup_restore']['storage'] = [];
                            }
                            if (!isset($options['backup_restore']['storage']['gdrive'])) {
                                $options['backup_restore']['storage']['gdrive'] = [];
                            }
                            
                            $options['backup_restore']['storage']['gdrive']['account_info'] = $response_body['user']['emailAddress'];
                            update_option('swsib_options', $options);
                        } elseif ($status_code === 401 && method_exists($provider, 'refresh_access_token')) {
                            // Token might be expired, try to refresh
                            $refresh_result = $provider->refresh_access_token();
                            if (!is_wp_error($refresh_result)) {
                                // Try again with the new token
                                $access_token = isset($this->options['backup_restore']['storage']['gdrive']['access_token']) 
                                    ? $this->options['backup_restore']['storage']['gdrive']['access_token'] 
                                    : '';
                                
                                if (!empty($access_token)) {
                                    $response = wp_remote_get(
                                        'https://www.googleapis.com/drive/v3/about?fields=user',
                                        [
                                            'headers' => [
                                                'Authorization' => 'Bearer ' . $access_token,
                                            ],
                                            'timeout' => 10,
                                        ]
                                    );
                                    
                                    if (!is_wp_error($response)) {
                                        $status_code = wp_remote_retrieve_response_code($response);
                                        $response_body = json_decode(wp_remote_retrieve_body($response), true);
                                        
                                        if ($status_code >= 200 && $status_code < 300 && isset($response_body['user']['emailAddress'])) {
                                            $response_data['email'] = $response_body['user']['emailAddress'];
                                            
                                            // Update account info in options
                                            $options = get_option('swsib_options', []);
                                            if (!isset($options['backup_restore'])) {
                                                $options['backup_restore'] = [];
                                            }
                                            if (!isset($options['backup_restore']['storage'])) {
                                                $options['backup_restore']['storage'] = [];
                                            }
                                            if (!isset($options['backup_restore']['storage']['gdrive'])) {
                                                $options['backup_restore']['storage']['gdrive'] = [];
                                            }
                                            
                                            $options['backup_restore']['storage']['gdrive']['account_info'] = $response_body['user']['emailAddress'];
                                            update_option('swsib_options', $options);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore any errors, this is just extra information
                $this->log_message('Error getting user email: ' . $e->getMessage());
            }
        }
        
        wp_send_json_success($response_data);
    }
    
    /**
     * Handle Google Drive auth redirect AJAX request.
     */
    public function handle_gdrive_auth_redirect() {
        // Verify nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'swsib_backup_restore_nonce')) {
            wp_die(__('Security check failed', 'swiftspeed-siberian'));
        }
        
        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'swiftspeed-siberian'));
        }
        
        // Get the return URL if provided
        $return_url = isset($_GET['return_url']) ? esc_url_raw($_GET['return_url']) : '';
        
        // If empty, use the current admin page
        if (empty($return_url)) {
            $return_url = admin_url('admin.php?page=swsib-integration&tab_id=backup_restore#settings');
        }
        
        // Get the site URL if provided
        $site_url = isset($_GET['site_url']) ? esc_url_raw($_GET['site_url']) : site_url();
        
        // Get Google Drive provider
        $provider = $this->get_provider('gdrive');
        
        if (!$provider) {
            wp_die(__('Google Drive provider not found', 'swiftspeed-siberian'));
        }
        
        // Get proxy auth URL
        if (method_exists($provider, 'get_proxy_auth_url')) {
            $auth_url = $provider->get_proxy_auth_url($return_url);
            $this->log_message('Redirecting to auth URL: ' . $auth_url);
            wp_redirect($auth_url);
            exit;
        }
        
        wp_die(__('Method not supported by Google Drive provider', 'swiftspeed-siberian'));
    }
    
    /**
     * Handle Google Drive auth callback.
     */
    public function handle_gdrive_auth_callback() {
        // Check for encrypted auth data from the proxy
        if (isset($_GET['gdrive_auth_data']) && !empty($_GET['gdrive_auth_data'])) {
            // Get Google Drive provider
            $provider = $this->get_provider('gdrive');
            
            if (!$provider) {
                wp_die(__('Google Drive provider not found', 'swiftspeed-siberian'));
            }
            
            // Log that we received auth data
            $this->log_message('Received Google Drive auth data, length: ' . strlen($_GET['gdrive_auth_data']));
            
            // Process the tokens from the proxy
            if (method_exists($provider, 'process_proxy_tokens')) {
                $encrypted_data = sanitize_text_field($_GET['gdrive_auth_data']);
                $tokens = $provider->process_proxy_tokens($encrypted_data);
                
                if (is_wp_error($tokens)) {
                    $this->log_message('Error processing auth tokens: ' . $tokens->get_error_message());
                    wp_die($tokens->get_error_message());
                }
                
                $this->log_message('Successfully processed auth tokens');
                
                // Save the tokens in WordPress options
                $options = get_option('swsib_options', []);
                
                if (!isset($options['backup_restore'])) {
                    $options['backup_restore'] = [];
                }
                
                if (!isset($options['backup_restore']['storage'])) {
                    $options['backup_restore']['storage'] = [];
                }
                
                if (!isset($options['backup_restore']['storage']['gdrive'])) {
                    $options['backup_restore']['storage']['gdrive'] = [];
                }
                
                $options['backup_restore']['storage']['gdrive']['access_token'] = $tokens['access_token'];
                $options['backup_restore']['storage']['gdrive']['refresh_token'] = $tokens['refresh_token'];
                
                // Add account info if available
                if (isset($tokens['email'])) {
                    $options['backup_restore']['storage']['gdrive']['account_info'] = $tokens['email'];
                    $this->log_message('Saved account email: ' . $tokens['email']);
                }
                
                $update_result = update_option('swsib_options', $options);
                $this->log_message('Options update result: ' . ($update_result ? 'Success' : 'No change or failure'));
                
                // Redirect back to the settings page with a success parameter
                $return_url = admin_url('admin.php?page=swsib-integration&tab_id=backup_restore&gdrive_auth_success=1') . '#settings';
                wp_safe_redirect($return_url);
                exit;
            }
        }
        
        // If no auth data was provided or processing failed
        wp_die(__('Invalid Google Drive authentication data', 'swiftspeed-siberian'));
    }
    
    /**
     * Handle test storage connection AJAX request.
     */
    public function handle_test_storage_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        $provider_id = isset($_POST['provider']) ? sanitize_key($_POST['provider']) : '';
        
        if (empty($provider_id)) {
            wp_send_json_error(array('message' => __('No provider specified', 'swiftspeed-siberian')));
        }
        
        $provider = $this->get_provider($provider_id);
        
        if (!$provider) {
            wp_send_json_error(array('message' => __('Provider not found', 'swiftspeed-siberian')));
        }
        
        // Get settings from form
        $config = array();
        
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'swsib_options_backup_restore_storage_' . $provider_id . '_') === 0) {
                $setting_name = str_replace('swsib_options_backup_restore_storage_' . $provider_id . '_', '', $key);
                $config[$setting_name] = sanitize_text_field($value);
            }
        }
        
        // For Google Drive, add existing tokens if not in the form
        if ($provider_id === 'gdrive') {
            // If not set in form data, get from current options
            if (empty($config['access_token']) && isset($this->options['backup_restore']['storage']['gdrive']['access_token'])) {
                $config['access_token'] = $this->options['backup_restore']['storage']['gdrive']['access_token'];
            }
            
            if (empty($config['refresh_token']) && isset($this->options['backup_restore']['storage']['gdrive']['refresh_token'])) {
                $config['refresh_token'] = $this->options['backup_restore']['storage']['gdrive']['refresh_token'];
            }
            
            if (empty($config['account_info']) && isset($this->options['backup_restore']['storage']['gdrive']['account_info'])) {
                $config['account_info'] = $this->options['backup_restore']['storage']['gdrive']['account_info'];
            }
        }
        
        // Validate config
        $validated_config = $provider->validate_config($config);
        
        if (is_wp_error($validated_config)) {
            wp_send_json_error(array('message' => $validated_config->get_error_message()));
        }
        
        // Create a temporary provider with the new config
        $class_name = get_class($provider);
        $temp_provider = new $class_name($validated_config);
        
        // Test connection
        $result = $temp_provider->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => __('Connection successful', 'swiftspeed-siberian')));
    }
}