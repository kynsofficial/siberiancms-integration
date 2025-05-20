<?php
/**
 * Base abstract class for backup functionality.
 * OPTIMIZED VERSION 3.0: Enhanced archiving for large files, improved memory management
 */
abstract class SwiftSpeed_Siberian_Base_Backup {
    /**
     * Plugin options.
     * 
     * @var array
     */
    protected $options;
    
    /**
     * Backup directory.
     * 
     * @var string
     */
    protected $backup_dir;
    
    /**
     * Backup temporary directory.
     * 
     * @var string
     */
    protected $temp_dir;
    
    /**
     * Current backup status.
     * 
     * @var array
     */
    protected $status;
    
    /**
     * Maximum archive chunk size for large files
     * 
     * @var int
     */
    protected $archive_chunk_size = 1024 * 1024 * 5; // 5MB for better handling of very large files
    
    /**
     * Maximum file size to add to ZIP at once (100MB)
     * 
     * @var int
     */
    protected $max_direct_zip_size = 104857600; // 100MB
    
    /**
     * Constructor.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->temp_dir = $this->backup_dir . 'temp/';
        $this->ensure_directories();
    }
    
    /**
     * Write to log using the central logging manager.
     *
     * @param string $message The message to log.
     * @return void
     */
     public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
    
    /**
     * Ensure that necessary directories exist.
     *
     * @return bool True on success, false on failure.
     */
    protected function ensure_directories() {
        $directories = [$this->backup_dir, $this->temp_dir];
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                if (!wp_mkdir_p($dir)) {
                    $this->log_message('Failed to create directory: ' . $dir);
                    return false;
                }
            }
        }
        
        // Create .htaccess to prevent direct access
        $htaccess_file = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order deny,allow\nDeny from all");
        }
        
        // Create index.php for security
        $index_file = $this->backup_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.");
        }
        
        return true;
    }
    
    /**
     * Start backup process.
     *
     * @param array $params Backup parameters.
     * @return array|WP_Error Backup status or error.
     */
    abstract public function start_backup($params = []);
    
    /**
     * Process next part of the backup.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    abstract public function process_next($status);
    

/**
 * Create final backup archive with optimized chunking approach for large files.
 *
 * @param array $status Current backup status.
 * @return array|WP_Error Updated backup status or error.
 */
protected function create_final_archive($status) {
    // Get session tracking for already added backups
    $history_added_session = get_option('swsib_history_added_session', array());
    $backup_key = $status['id'] . '-' . (isset($status['file']) ? $status['file'] : '');
    
    // CRITICAL CHECK: Fail if there are critical errors
    if (!empty($status['critical_errors'])) {
        $this->log_message('Backup contains critical errors - cannot create archive');
        $status['status'] = 'error';
        $error_messages = array_map(function($error) {
            return $error['message'];
        }, $status['critical_errors']);
        $status['message'] = __('Backup failed - critical errors: ', 'swiftspeed-siberian') . implode('; ', $error_messages);
        $this->update_status($status);
        
        // Clean up temp files
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        return $status;
    }
    
    // Increase memory limit and execution time
    @ini_set('memory_limit', '2048M');  
    @set_time_limit(600); // 10 minutes
    
    $status['status'] = 'creating_archive';
    $status['message'] = __('Creating backup archive...', 'swiftspeed-siberian');
    $status['progress'] = 95;
    $this->update_status($status);
    
    // Determine where to save the final ZIP file - in the main backup directory, not temp
    $backup_filename = 'siberian-backup-' . $status['backup_type'] . '-' . date('Y-m-d-H-i-s') . '.zip';
    $temp_zip_file = $status['temp_dir'] . $backup_filename;
    $final_zip_file = $this->backup_dir . $backup_filename;
    
    $this->log_message('Creating final backup archive: ' . $temp_zip_file);
    
    // First, try to use system zip command if available for better performance with large files
    if ($this->is_system_zip_available()) {
        $this->log_message('Using system zip command for better performance');
        $result = $this->create_archive_with_system_zip($status['temp_dir'], $temp_zip_file);
        if ($result === true) {
            $this->log_message('Successfully created archive with system zip');
        } else {
            $this->log_message('System zip failed, falling back to PHP ZipArchive');
            $result = $this->create_archive_with_php($status, $temp_zip_file);
            if (!$result) {
                return $status; // Error already set in create_archive_with_php
            }
        }
    } else {
        $result = $this->create_archive_with_php($status, $temp_zip_file);
        if (!$result) {
            return $status; // Error already set in create_archive_with_php
        }
    }
    
    // Ensure the backup directory exists
    if (!file_exists(dirname($final_zip_file))) {
        wp_mkdir_p(dirname($final_zip_file));
    }
    
    // Memory cleanup before moving file
    $this->memory_cleanup();
    
    // Try to move the file to the final location
    if (file_exists($temp_zip_file)) {
        if (!@rename($temp_zip_file, $final_zip_file)) {
            // If rename fails, try copy+delete
            if (!@copy($temp_zip_file, $final_zip_file)) {
                $this->log_message('Failed to move backup file to permanent location. Keeping in temp directory.');
                $final_zip_file = $temp_zip_file; // Just use the temp location
            } else {
                @unlink($temp_zip_file); // Copy successful, remove temp file
                $this->log_message('Backup file copied to permanent location: ' . $final_zip_file);
            }
        } else {
            $this->log_message('Backup file moved to permanent location: ' . $final_zip_file);
        }
    } else {
        $this->log_message('Warning: Temp backup file not found: ' . $temp_zip_file);
        $final_zip_file = $temp_zip_file; // Just use the temp location even though it's missing
    }
    
    // Initialize storage tracking arrays
    $status['uploaded_to'] = ['local']; // Always start with local storage
    $status['all_storage_info'] = [];
    
    // Upload to all selected storage providers
    if (!empty($status['params']['storage_providers']) && is_array($status['params']['storage_providers'])) {
        foreach ($status['params']['storage_providers'] as $provider) {
            // Skip local as it's already saved locally
            if ($provider === 'local') {
                continue;
            }
            
            $this->log_message('Uploading backup to storage provider: ' . $provider);
            
            // Update status message to indicate current provider
            $status['status'] = 'uploading';
            $status['message'] = sprintf(
                __('Uploading backup to %s...', 'swiftspeed-siberian'),
                ucfirst($provider)
            );
            $status['progress'] = 97;
            $this->update_status($status);
            
            // Load the appropriate storage provider
            $storage = $this->get_storage_provider($provider);
            if (is_wp_error($storage)) {
                $this->log_message('Error getting storage provider: ' . $storage->get_error_message());
                $status['errors'][] = [
                    'provider' => $provider,
                    'message' => $storage->get_error_message()
                ];
                continue;
            }
            
            // Initialize storage
            $init_result = $storage->initialize();
            if (is_wp_error($init_result)) {
                $this->log_message('Error initializing storage provider: ' . $init_result->get_error_message());
                $status['errors'][] = [
                    'provider' => $provider,
                    'message' => $init_result->get_error_message()
                ];
                continue;
            }
            
            // Upload file
            $destination = basename($final_zip_file);
            $metadata = [
                'type' => 'backup',
                'backup_type' => $status['backup_type'],
                'timestamp' => time(),
                'source' => site_url(),
            ];
            
            // For large files, use chunked upload if available
            $file_size = file_exists($final_zip_file) ? filesize($final_zip_file) : 0;
            $upload_result = null;
            
            if ($file_size > 100 * 1024 * 1024 && method_exists($storage, 'upload_file_chunked')) { // 100 MB
                $this->log_message('Using chunked upload for large file: ' . size_format($file_size, 2));
                $upload_result = $storage->upload_file_chunked($final_zip_file, $destination, $metadata);
            } else {
                $upload_result = $storage->upload_file($final_zip_file, $destination, $metadata);
            }
            
            if (is_wp_error($upload_result)) {
                $this->log_message('Error uploading to ' . $provider . ': ' . $upload_result->get_error_message());
                $status['errors'][] = [
                    'provider' => $provider,
                    'message' => $upload_result->get_error_message()
                ];
                continue;
            }
            
            // Add to successful uploads
            $status['uploaded_to'][] = $provider;
            $status['all_storage_info'][$provider] = $upload_result;
            
            $this->log_message(sprintf(
                'Backup uploaded to %s: %s',
                ucfirst($provider),
                !empty($upload_result['url']) ? $upload_result['url'] : $destination
            ));
        }
    }
    
    // Set primary storage (for backward compatibility)
    $status['storage'] = 'local'; // Default to local
    
    // If we successfully uploaded to a non-local storage, set it as primary
    if (count($status['uploaded_to']) > 1) {
        // Set first non-local provider as primary
        foreach ($status['uploaded_to'] as $provider) {
            if ($provider !== 'local') {
                $status['storage'] = $provider;
                // Also set storage_info for backward compatibility
                if (isset($status['all_storage_info'][$provider])) {
                    $status['storage_info'] = $status['all_storage_info'][$provider];
                }
                break;
            }
        }
    }
    
    // Update status with file info for history
    $status['status'] = 'completed';
    $status['message'] = __('Backup completed successfully', 'swiftspeed-siberian');
    $status['progress'] = 100;
    $status['completed'] = time();
    $status['file'] = basename($final_zip_file);
    $status['size'] = size_format(file_exists($final_zip_file) ? filesize($final_zip_file) : 0, 2);
    $status['path'] = $final_zip_file;
    
    $this->update_status($status);
    
    // Log detailed info about storage providers before adding to history
    $this->log_message('Backup completed with uploaded_to: ' . json_encode($status['uploaded_to']));
    
    // IMPORTANT: Use both static variable and session option to track if we've already added this backup to history
    // This prevents duplicate entries when multiple storage providers are used
    static $history_added = array();
    $backup_key = $status['id'] . '-' . $status['file'];
    
    if (!isset($history_added[$backup_key]) && !isset($history_added_session[$backup_key])) {
        $this->log_message('Adding backup to history: ' . json_encode([
            'id' => $status['id'],
            'file' => $status['file'],
            'status' => $status['status'],
            'backup_type' => $status['backup_type'],
            'uploaded_to' => $status['uploaded_to']
        ]));
        
        // Add to backup history
        $history_result = $this->add_to_backup_history($status);
        $this->log_message('Add to history result: ' . ($history_result ? 'success' : 'failed'));
        
        // Mark this backup as added to history in both static array and session option
        $history_added[$backup_key] = true;
        $history_added_session[$backup_key] = time();
        update_option('swsib_history_added_session', $history_added_session);
    } else {
        $this->log_message('Skipping duplicate add to history for: ' . $backup_key);
    }
    
    // Clean up temp files AFTER adding to history to prevent race conditions
    $this->cleanup_temp_files($status['temp_dir']);
    
    $this->log_message('Backup completed successfully. Archive: ' . $status['file'] . ' (' . $status['size'] . ')');
    
    // Clean up old session entries (older than 1 hour)
    $current_time = time();
    foreach ($history_added_session as $key => $timestamp) {
        if ($current_time - $timestamp > 3600) { // 1 hour
            unset($history_added_session[$key]);
        }
    }
    update_option('swsib_history_added_session', $history_added_session);
    
    return $status;
}

/**
 * Check if system zip command is available
 * 
 * @return bool True if system zip is available
 */
protected function is_system_zip_available() {
    if (!function_exists('exec') || !function_exists('shell_exec')) {
        return false;
    }
    
    // Check if exec is disabled
    $disabled_functions = ini_get('disable_functions');
    if ($disabled_functions) {
        $disabled = explode(',', $disabled_functions);
        if (in_array('exec', $disabled) || in_array('shell_exec', $disabled)) {
            return false;
        }
    }
    
    // Check if zip command exists
    @exec('which zip 2>&1', $output, $return_code);
    
    return $return_code === 0;
}

/**
 * Create archive using system zip command
 * 
 * @param string $source_dir Source directory
 * @param string $zip_file Destination zip file
 * @return bool True on success, false on failure
 */
protected function create_archive_with_system_zip($source_dir, $zip_file) {
    // Change to the source directory
    $original_dir = getcwd();
    chdir($source_dir);
    
    // Create README file
    $readme = $this->create_backup_readme($this->status);
    file_put_contents('README.txt', $readme);
    
    // Use system zip to create archive
    $command = sprintf(
        'zip -r -9 %s * > /dev/null 2>&1',
        escapeshellarg($zip_file)
    );
    
    @exec($command, $output, $return_code);
    
    // Return to original directory
    chdir($original_dir);
    
    return $return_code === 0 && file_exists($zip_file);
}

/**
 * Create archive using PHP ZipArchive with chunking for large files
 * 
 * @param array $status Current backup status
 * @param string $zip_file Destination zip file
 * @return bool True on success, false on failure
 */
protected function create_archive_with_php($status, $zip_file) {
    $zip = new ZipArchive();
    
    if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
        $status['status'] = 'error';
        $status['message'] = __('Could not create ZIP archive', 'swiftspeed-siberian');
        $this->update_status($status);
        $this->log_message('Could not create ZIP archive: ' . $zip_file);
        return false;
    }
    
    // Add README first (it's small)
    $readme = $this->create_backup_readme($status);
    $zip->addFromString('README.txt', $readme);
    
    // Get all files to add
    $base_dir = $status['temp_dir'];
    $files = $this->get_files_recursive($base_dir);
    
    $total_files = count($files);
    $processed_files = 0;
    $total_size = 0;
    $last_update = time();
    
    $this->log_message('Adding ' . $total_files . ' files to archive');
    
    // Sort files by size - process small files first, then large files
    $file_sizes = [];
    foreach ($files as $file) {
        if (is_file($file)) {
            $file_sizes[$file] = filesize($file);
        }
    }
    asort($file_sizes);
    
    // Process files in batches based on size
    $batch_files = [];
    $batch_size = 0;
    $max_batch_size = 50 * 1024 * 1024; // 50MB batches
    
    foreach ($file_sizes as $file => $size) {
        $relative_path = str_replace($base_dir, '', $file);
        if (empty($relative_path)) continue;
        
        // For very large files, process individually
        if ($size > $this->max_direct_zip_size) {
            // Process current batch first
            if (!empty($batch_files)) {
                $this->add_files_to_zip($zip, $batch_files, $base_dir);
                $processed_files += count($batch_files);
                $batch_files = [];
                $batch_size = 0;
                
                // Memory cleanup
                $this->memory_cleanup();
            }
            
            // Handle large file
            $this->log_message('Adding large file to archive: ' . $relative_path . ' (' . size_format($size, 2) . ')');
            
            // For extremely large files, use external method if available
            if ($size > 500 * 1024 * 1024 && method_exists($zip, 'addFileWithoutCompression')) {
                // Some PHP installations have this method for large files
                $result = $zip->addFileWithoutCompression($file, $relative_path);
            } else {
                // Use regular addFile but ensure we have enough memory
                $required_memory = $size * 2; // Estimate 2x file size needed
                $this->ensure_memory_available($required_memory);
                
                $result = $zip->addFile($file, $relative_path);
            }
            
            if (!$result) {
                $this->log_message('Failed to add large file: ' . $relative_path);
            }
            
            $total_size += $size;
            $processed_files++;
            
            // Close and reopen zip to flush buffers
            $zip->close();
            $zip->open($zip_file, ZipArchive::CREATE);
            
            // Aggressive memory cleanup
            $this->memory_cleanup();
            
        } else {
            // Add regular files to batch
            $batch_files[$file] = $relative_path;
            $batch_size += $size;
            $total_size += $size;
            
            // Process batch when it reaches size limit
            if ($batch_size >= $max_batch_size) {
                $this->add_files_to_zip($zip, $batch_files, $base_dir);
                $processed_files += count($batch_files);
                
                // Update status periodically
                $now = time();
                if (($now - $last_update) >= 3) {
                    $last_update = $now;
                    $status['message'] = sprintf(
                        __('Creating backup archive: %d of %d files (%.1f%%, %s)', 'swiftspeed-siberian'),
                        $processed_files,
                        $total_files,
                        ($processed_files / $total_files) * 100,
                        size_format($total_size, 2)
                    );
                    $this->update_status($status);
                }
                
                $batch_files = [];
                $batch_size = 0;
                
                // Memory cleanup
                $this->memory_cleanup();
            }
        }
    }
    
    // Process remaining files
    if (!empty($batch_files)) {
        $this->add_files_to_zip($zip, $batch_files, $base_dir);
        $processed_files += count($batch_files);
    }
    
    // Add directories
    foreach ($files as $file) {
        if (is_dir($file) && $file !== $base_dir) {
            $relative_path = str_replace($base_dir, '', $file);
            if (!empty($relative_path)) {
                $zip->addEmptyDir($relative_path);
            }
        }
    }
    
    $zip->close();
    
    return true;
}

/**
 * Add multiple files to zip archive
 * 
 * @param ZipArchive $zip Zip archive object
 * @param array $files Array of files with full path as key and relative path as value
 * @param string $base_dir Base directory to remove from paths
 */
protected function add_files_to_zip($zip, $files, $base_dir) {
    foreach ($files as $file => $relative_path) {
        $zip->addFile($file, $relative_path);
    }
}

/**
 * Ensure enough memory is available for operation
 * 
 * @param int $required_bytes Bytes required
 * @return bool True if memory is available
 */
protected function ensure_memory_available($required_bytes) {
    $memory_limit = $this->get_memory_limit();
    $current_usage = memory_get_usage(true);
    $available = $memory_limit - $current_usage;
    
    if ($available < $required_bytes) {
        // Try to increase memory limit
        $new_limit = $current_usage + $required_bytes + (100 * 1024 * 1024); // Add 100MB buffer
        $new_limit_mb = ceil($new_limit / (1024 * 1024));
        
        @ini_set('memory_limit', $new_limit_mb . 'M');
        $this->log_message('Increased memory limit to ' . $new_limit_mb . 'M for large file processing');
    }
    
    return true;
}

/**
 * Get memory limit in bytes
 * 
 * @return int Memory limit in bytes
 */
protected function get_memory_limit() {
    $memory_limit = ini_get('memory_limit');
    
    if ($memory_limit == -1) {
        // Unlimited
        return PHP_INT_MAX;
    }
    
    $unit = strtoupper(substr($memory_limit, -1));
    $value = intval(substr($memory_limit, 0, -1));
    
    switch ($unit) {
        case 'G':
            $value *= 1024;
        case 'M':
            $value *= 1024;
        case 'K':
            $value *= 1024;
    }
    
    return $value;
}

    /**
     * Run memory cleanup operations
     * 
     * @return void
     */
    protected function memory_cleanup() {
        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Upload backup to a storage provider.
     *
     * @param array $status Current backup status.
     * @param string $zip_file Path to the ZIP file.
     * @return array|WP_Error Updated backup status or error.
     */
    protected function upload_to_storage($status, $zip_file) {
        if (empty($status['params']['storage'])) {
            return $status;
        }
        
        $storage_type = $status['params']['storage'];
        if ($storage_type === 'local') {
            return $status; // No need to upload
        }
        
        $status['status'] = 'uploading';
        $status['message'] = sprintf(
            __('Uploading backup to %s...', 'swiftspeed-siberian'),
            ucfirst($storage_type)
        );
        $status['progress'] = 97;
        $this->update_status($status);
        
        // Load the appropriate storage provider
        $storage = $this->get_storage_provider($storage_type);
        if (is_wp_error($storage)) {
            $status['status'] = 'error';
            $status['message'] = $storage->get_error_message();
            $this->update_status($status);
            return $status;
        }
        
        // Initialize storage
        $init_result = $storage->initialize();
        if (is_wp_error($init_result)) {
            $status['status'] = 'error';
            $status['message'] = $init_result->get_error_message();
            $this->update_status($status);
            return $status;
        }
        
        // Upload file
        $destination = basename($zip_file);
        $metadata = [
            'type' => 'backup',
            'backup_type' => $status['backup_type'],
            'timestamp' => time(),
            'source' => site_url(),
        ];
        
        // For large files, use chunked upload if available
        $file_size = file_exists($zip_file) ? filesize($zip_file) : 0;
        $upload_result = null;
        
        if ($file_size > 100 * 1024 * 1024 && method_exists($storage, 'upload_file_chunked')) { // 100 MB
            $this->log_message('Using chunked upload for large file: ' . size_format($file_size, 2));
            $upload_result = $storage->upload_file_chunked($zip_file, $destination, $metadata);
        } else {
            $upload_result = $storage->upload_file($zip_file, $destination, $metadata);
        }
        
        if (is_wp_error($upload_result)) {
            $status['status'] = 'error';
            $status['message'] = $upload_result->get_error_message();
            $this->update_status($status);
            return $status;
        }
        
        // Update status with storage info
        $status['storage'] = $storage_type;
        $status['storage_info'] = $upload_result;
        
        $this->log_message(sprintf(
            'Backup uploaded to %s: %s',
            ucfirst($storage_type),
            !empty($upload_result['url']) ? $upload_result['url'] : $destination
        ));
        
        return $status;
    }
    
    /**
     * Cleanup temporary files.
     *
     * @param string $temp_dir Temporary directory to clean up.
     * @return void
     */
    protected function cleanup_temp_files($temp_dir) {
        if (empty($temp_dir) || !file_exists($temp_dir)) {
            return;
        }
        
        $this->log_message('Cleaning up temporary files in: ' . $temp_dir);
        
        $files = $this->get_files_recursive($temp_dir);
        rsort($files); // Sort in reverse to delete files before directories
        
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file) && $file !== $temp_dir) {
                @rmdir($file);
            }
        }
        
        @rmdir($temp_dir);
    }
    
    /**
     * Get all files in a directory recursively.
     *
     * @param string $dir Directory to scan.
     * @return array List of files and directories.
     */
    protected function get_files_recursive($dir) {
        $result = [];
        $dir = rtrim($dir, '/\\') . '/'; // Ensure trailing slash
        
        if (!file_exists($dir)) {
            return $result;
        }
        
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($it as $file) {
                $result[] = $file->getPathname();
            }
            
            // Always include the base directory
            $result[] = $dir;
            
            return $result;
        } catch (Exception $e) {
            $this->log_message('Error scanning directory: ' . $e->getMessage());
            
            // Fallback to simplified scanning
            $files = @scandir($dir);
            if ($files === false) {
                return [$dir];
            }
            
            $result[] = $dir;
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $path = $dir . $file;
                $result[] = $path;
                
                if (is_dir($path)) {
                    $subdir_files = $this->get_files_recursive($path . '/');
                    $result = array_merge($result, $subdir_files);
                }
            }
            
            return $result;
        }
    }
    
    /**
     * Create a README file for the backup.
     *
     * @param array $status Current backup status.
     * @return string README contents.
     */
    protected function create_backup_readme($status) {
        $site_url = site_url();
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        
        $readme = "Siberian CMS Backup\n";
        $readme .= "==============================\n\n";
        $readme .= "Backup created on: {$date}\n";
        $readme .= "Site URL: {$site_url}\n";
        $readme .= "Backup type: " . ucfirst($status['backup_type']) . "\n";
        
        if ($status['backup_type'] === 'file') {
            $readme .= "Backed up files: " . count($status['processed_files']) . "\n";
            $readme .= "Total size: " . size_format($status['total_size'], 2) . "\n";
        } elseif ($status['backup_type'] === 'db') {
            $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
            $readme .= "Database: " . (isset($db_options['database']) ? $db_options['database'] : 'Unknown') . "\n";
            $readme .= "Tables: " . $status['total_tables'] . "\n";
            $readme .= "Total rows: " . $status['total_rows'] . "\n";
        } elseif ($status['backup_type'] === 'full') {
            $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
            $readme .= "Database: " . (isset($db_options['database']) ? $db_options['database'] : 'Unknown') . "\n";
            $readme .= "Tables: " . (isset($status['total_tables']) ? $status['total_tables'] : '0') . "\n";
            $readme .= "Total rows: " . (isset($status['total_rows']) ? $status['total_rows'] : '0') . "\n";
            $readme .= "Backed up files: " . (isset($status['processed_files']) ? count($status['processed_files']) : '0') . "\n";
            $readme .= "Total file size: " . (isset($status['total_size']) ? size_format($status['total_size'], 2) : '0') . "\n";
        }
        
        $readme .= "\nCreated by SwiftSpeed Siberian Integration Plugin\n";
        return $readme;
    }
    
    /**
     * Update backup status.
     *
     * @param array $status New backup status.
     * @return void
     */
    protected function update_status($status) {
        $this->status = $status;
        update_option('swsib_current_backup', $status);
        update_option('swsib_last_backup_update', time());
    }
    
/**
 * Add completed backup to backup history.
 * 
 * @param array $status Current backup status
 * @return bool Success or failure
 */
protected function add_to_backup_history($status) {
    if (empty($status)) {
        $this->log_message('Cannot add empty backup to history');
        return false;
    }
    
    $this->log_message('Adding backup to history: ' . json_encode([
        'id' => isset($status['id']) ? $status['id'] : 'unknown',
        'file' => isset($status['file']) ? $status['file'] : 'unknown',
        'status' => isset($status['status']) ? $status['status'] : 'unknown',
        'backup_type' => isset($status['backup_type']) ? $status['backup_type'] : 'unknown',
        'uploaded_to' => isset($status['uploaded_to']) ? $status['uploaded_to'] : []
    ]));
    
    // Check if the status has required fields
    if (!isset($status['id']) || !isset($status['file']) || !isset($status['path'])) {
        $this->log_message('Backup status missing required fields for history - ID: ' . 
            (isset($status['id']) ? 'Yes' : 'No') . ', File: ' . 
            (isset($status['file']) ? 'Yes' : 'No') . ', Path: ' . 
            (isset($status['path']) ? 'Yes' : 'No'));
        return false;
    }
    
    // CRITICAL: Do not add backups with critical errors to history as successful
    if (!empty($status['critical_errors'])) {
        $this->log_message('Backup contains critical errors - not adding to regular history');
        
        // Add to a separate failed_backups option instead
        $failed_history = get_option('swsib_failed_backup_history', []);
        $failed_entry = [
            'id' => $status['id'],
            'file' => $status['file'],
            'path' => $status['path'],
            'critical_errors' => $status['critical_errors'],
            'backup_type' => isset($status['backup_type']) ? $status['backup_type'] : 'unknown',
            'failed_at' => time(),
            'error_count' => count($status['critical_errors']),
        ];
        
        $failed_history[$status['id']] = $failed_entry;
        update_option('swsib_failed_backup_history', $failed_history);
        
        return false;
    }

    // Verify the backup file actually exists
    if (!file_exists($status['path'])) {
        $this->log_message('Backup file does not exist at path: ' . $status['path']);
        
        // For cases where the path may be wrong but the backup is in the backup directory
        $alt_path = $this->backup_dir . $status['file'];
        if (file_exists($alt_path)) {
            $this->log_message('Found backup at alternate path: ' . $alt_path);
            $status['path'] = $alt_path;
        } else {
            $this->log_message('Backup file not found at alternate path either');
            return false;
        }
    }
    
    // Get current history
    $history = get_option('swsib_backup_history', []);
    
    // Check if a backup with the same ID already exists in history
    if (isset($history[$status['id']])) {
        $this->log_message('Backup with ID ' . $status['id'] . ' already exists in history, updating instead of adding');
        
        // Update the existing entry with new information
        $existing_backup = $history[$status['id']];
        
        // Merge uploaded_to arrays if they exist
        if (isset($status['uploaded_to']) && is_array($status['uploaded_to'])) {
            if (isset($existing_backup['uploaded_to']) && is_array($existing_backup['uploaded_to'])) {
                // Combine the arrays and make unique
                $existing_backup['uploaded_to'] = array_unique(array_merge($existing_backup['uploaded_to'], $status['uploaded_to']));
            } else {
                // Just use the new uploaded_to
                $existing_backup['uploaded_to'] = $status['uploaded_to'];
            }
        }
        
        // Update other fields that might have changed
        $existing_backup['size'] = isset($status['size']) ? $status['size'] : $existing_backup['size'];
        $existing_backup['path'] = $status['path'];
        $existing_backup['status'] = $status['status'];
        
        // Update storage info if available
        if (!empty($status['storage_info'])) {
            $existing_backup['storage_info'] = $status['storage_info'];
        }
        
        // Update all_storage_info if available
        if (isset($status['all_storage_info']) && is_array($status['all_storage_info'])) {
            if (isset($existing_backup['all_storage_info']) && is_array($existing_backup['all_storage_info'])) {
                $existing_backup['all_storage_info'] = array_merge($existing_backup['all_storage_info'], $status['all_storage_info']);
            } else {
                $existing_backup['all_storage_info'] = $status['all_storage_info'];
            }
        }
        
        // Update the history with the merged entry
        $history[$status['id']] = $existing_backup;
        
        // Save and return
        update_option('swsib_backup_history', $history);
        $this->log_message('Updated existing backup in history: ' . $status['id']);
        return true;
    }
    
    // Check if a backup with the same filename already exists
    $existing_backup_id = null;
    foreach ($history as $id => $backup) {
        if ($backup['file'] === $status['file']) {
            $existing_backup_id = $id;
            break;
        }
    }
    
    if ($existing_backup_id) {
        $this->log_message('Found existing backup in history with same filename: ' . $existing_backup_id);
        
        // Special case: if the existing backup has the same filename but a different ID,
        // we need to decide which one to keep. In this case, keep both but mark differently.
        if ($existing_backup_id !== $status['id']) {
            $this->log_message('Warning: Same filename but different ID detected. This should not happen normally.');
            
            // Add a special flag and timestamp to the entry
            $status['duplicate_warning'] = true;
            $status['duplicate_detected_at'] = time();
        }
    }
    
    // Create a new history entry
    $entry = [
        'id' => $status['id'],
        'backup_type' => isset($status['backup_type']) ? $status['backup_type'] : 'unknown',
        'file' => $status['file'],
        'path' => $status['path'],
        'size' => isset($status['size']) ? $status['size'] : '0 KB',
        'storage' => !empty($status['storage']) ? $status['storage'] : 'local',
        'storage_info' => !empty($status['storage_info']) ? $status['storage_info'] : [],
        'created' => isset($status['completed']) ? $status['completed'] : time(),
        // FIX: Ensure scheduled flag is correctly set from params or fallback to params directly
        'scheduled' => !empty($status['params']['scheduled']) ? true : false,
        // Set lock status - auto-lock if enabled in params
        'locked' => !empty($status['params']['auto_lock']),
        // Add schedule information if available
        'schedule_id' => isset($status['params']['schedule_id']) ? $status['params']['schedule_id'] : null,
        'schedule_name' => isset($status['params']['schedule_name']) ? $status['params']['schedule_name'] : null,
    ];
    
    // Log if auto-lock is enabled
    if (!empty($status['params']['auto_lock'])) {
        $this->log_message('Auto-lock enabled for this backup, setting as locked');
    }
    
    // Add multi-storage information if available
    if (isset($status['uploaded_to']) && is_array($status['uploaded_to'])) {
        $entry['uploaded_to'] = $status['uploaded_to'];
        $this->log_message('Adding uploaded_to information: ' . json_encode($status['uploaded_to']));
    }
    
    if (isset($status['all_storage_info']) && is_array($status['all_storage_info'])) {
        $entry['all_storage_info'] = $status['all_storage_info'];
    }
    
    // Add to history
    $history[$status['id']] = $entry;
    $this->log_message('Added new backup to history: ' . $status['id']);
    
    // Sort by creation date, newest first
    uasort($history, function($a, $b) {
        return $b['created'] - $a['created'];
    });
    
    // Save history
    $result = update_option('swsib_backup_history', $history);
    
    if ($result) {
        $this->log_message('Successfully added backup to history');
    } else {
        $this->log_message('Failed to update backup history in database');
    }
    
    // Clean up old backups if needed
    if (isset($status['backup_type'])) {
        $this->cleanup_old_backups($status['backup_type']);
    }
    
    return $result;
}
    
    /**
     * Get a storage provider instance.
     *
     * @param string $provider_type The storage provider type.
     * @return SwiftSpeed_Siberian_Storage_Interface|WP_Error Storage provider or error.
     */
    protected function get_storage_provider($provider_type) {
        $providers = [
            'local' => 'SwiftSpeed_Siberian_Storage_Local',
            'gdrive' => 'SwiftSpeed_Siberian_Storage_GDrive',
            'gcs' => 'SwiftSpeed_Siberian_Storage_GCS',
            's3' => 'SwiftSpeed_Siberian_Storage_S3',
        ];
        
        if (!isset($providers[$provider_type])) {
            return new WP_Error(
                'invalid_provider',
                sprintf(__('Invalid storage provider: %s', 'swiftspeed-siberian'), $provider_type)
            );
        }
        
        $provider_class = $providers[$provider_type];
        $file_path = SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-' . $provider_type . '.php';
        
        if (!file_exists($file_path)) {
            return new WP_Error(
                'provider_not_found',
                sprintf(__('Storage provider file not found: %s', 'swiftspeed-siberian'), $file_path)
            );
        }
        
        // Load the file if not already loaded
        if (!class_exists($provider_class)) {
            require_once $file_path;
        }
        
        // Get provider config
        $config = [];
        if (isset($this->options['backup_restore']['storage'][$provider_type])) {
            $config = $this->options['backup_restore']['storage'][$provider_type];
        }
        
        // Create provider instance
        $provider = new $provider_class($config);
        
        return $provider;
    }
    
    /**
     * Cancel an in-progress backup.
     *
     * @param array $status Current backup status.
     * @return bool True on success, false on failure.
     */
    public function cancel_backup($status) {
        if (empty($status)) {
            return false;
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        // Clean up temp directory
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        // Remove status
        delete_option('swsib_current_backup');
        delete_option('swsib_last_backup_update');
        
        return true;
    }
    
    /**
     * Cleanup old backups based on backup type
     * 
     * @param string $backup_type Type of backup to clean up
     */
    protected function cleanup_old_backups($backup_type) {
        $options = swsib()->get_options();
        $backup_settings = isset($options['backup_restore']) ? $options['backup_restore'] : array();
        
        // Get limits from settings
        $limit_key = 'max_backups_' . $backup_type;
        $limit = isset($backup_settings[$limit_key]) ? intval($backup_settings[$limit_key]) : 10;
        
        if ($limit <= 0) {
            return; // No limit set
        }
        
        // Get history
        $history = get_option('swsib_backup_history', []);
        
        // Filter backups by type and exclude locked ones
        $backups_of_type = [];
        foreach ($history as $id => $backup) {
            if ($backup['backup_type'] === $backup_type && empty($backup['locked'])) {
                $backups_of_type[$id] = $backup;
            }
        }
        
        // Sort by creation date, newest first
        uasort($backups_of_type, function($a, $b) {
            return $b['created'] - $a['created'];
        });
        
        // If we have more than the limit, remove old ones
        if (count($backups_of_type) > $limit) {
            $to_remove = array_slice($backups_of_type, $limit, null, true);
            
            foreach ($to_remove as $id => $backup) {
                // Delete the physical file
                if (isset($backup['path']) && file_exists($backup['path'])) {
                    @unlink($backup['path']);
                }
                
                // Remove from history
                unset($history[$id]);
                
                $this->log_message('Removed old backup during cleanup: ' . $id);
            }
            
            // Save updated history
            update_option('swsib_backup_history', $history);
        }
    }
}