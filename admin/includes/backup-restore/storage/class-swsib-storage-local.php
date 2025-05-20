<?php
/**
 * Local WordPress filesystem storage provider.
 */
class SwiftSpeed_Siberian_Storage_Local implements SwiftSpeed_Siberian_Storage_Interface {
    /**
     * Storage configuration.
     * 
     * @var array
     */
    private $config;
    
    /**
     * Base storage directory.
     * 
     * @var string
     */
    private $storage_dir;
    
    /**
     * URL to the storage directory.
     * 
     * @var string
     */
    private $storage_url;
    
    /**
     * Constructor.
     * 
     * @param array $config Optional configuration.
     */
    public function __construct($config = []) {
        $this->config = $config;
        $this->storage_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->storage_url = content_url('swsib-backups/');
    }
    
    /**
     * Log a message to the debug log if logging is enabled.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'storage', $message);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function initialize() {
        if (!file_exists($this->storage_dir)) {
            if (!wp_mkdir_p($this->storage_dir)) {
                return new WP_Error('storage_init', __('Failed to create local storage directory', 'swiftspeed-siberian'));
            }
        }
        
        // Create .htaccess to prevent direct access
        $htaccess_file = $this->storage_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order deny,allow\nDeny from all");
        }
        
        // Create index.php for security
        $index_file = $this->storage_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.");
        }
        
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function upload_file($source_path, $destination_path, $metadata = []) {
        if (!file_exists($source_path)) {
            $this->log_message('Source file does not exist: ' . $source_path);
            return new WP_Error('upload_error', __('Source file does not exist', 'swiftspeed-siberian'));
        }
        
        $destination = $this->storage_dir . $destination_path;
        $destination_dir = dirname($destination);
        
        if (!file_exists($destination_dir)) {
            if (!wp_mkdir_p($destination_dir)) {
                $this->log_message('Failed to create destination directory: ' . $destination_dir);
                return new WP_Error('upload_error', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Get file size
        $file_size = filesize($source_path);
        $this->log_message('Uploading file: ' . $source_path . ' to ' . $destination . ' (Size: ' . size_format($file_size, 2) . ')');
        
        // For very large files (over 100MB), use chunked copy to avoid memory issues
        if ($file_size > 100 * 1024 * 1024) {
            $this->log_message('Using chunked copy for large file');
            $success = $this->chunked_copy($source_path, $destination);
        } else {
            // Use regular copy for smaller files
            $success = @copy($source_path, $destination);
        }
        
        if (!$success) {
            $this->log_message('Failed to copy file to destination');
            return new WP_Error('upload_error', __('Failed to copy file to destination', 'swiftspeed-siberian'));
        }
        
        // Store metadata if provided
        if (!empty($metadata)) {
            $metadata_file = $destination . '.meta';
            file_put_contents($metadata_file, wp_json_encode($metadata));
        }
        
        $this->log_message('File uploaded successfully: ' . $destination_path);
        
        return [
            'file' => $destination_path,
            'url' => $this->storage_url . $destination_path,
            'size' => $file_size,
            'path' => $destination,
            'timestamp' => time(),
        ];
    }
    
    /**
     * Copy a file in chunks to avoid memory issues with large files.
     * 
     * @param string $source_path Source file path
     * @param string $destination_path Destination file path
     * @param int $chunk_size Size of each chunk in bytes
     * @return bool True on success, false on failure
     */
    protected function chunked_copy($source_path, $destination_path, $chunk_size = 1048576) {
        $this->log_message('Starting chunked copy from ' . $source_path . ' to ' . $destination_path);
        
        $source = @fopen($source_path, 'rb');
        if (!$source) {
            $this->log_message('Failed to open source file for reading');
            return false;
        }
        
        $destination = @fopen($destination_path, 'wb');
        if (!$destination) {
            @fclose($source);
            $this->log_message('Failed to open destination file for writing');
            return false;
        }
        
        $total_bytes = 0;
        $total_size = filesize($source_path);
        $chunk_number = 0;
        
        while (!feof($source)) {
            $bytes_read = 0;
            $chunk = '';
            
            // Read a chunk
            while (!feof($source) && $bytes_read < $chunk_size) {
                $buffer = fread($source, min($chunk_size - $bytes_read, 8192));
                if ($buffer === false) {
                    $this->log_message('Error reading from source file');
                    @fclose($source);
                    @fclose($destination);
                    return false;
                }
                
                $bytes_read += strlen($buffer);
                $chunk .= $buffer;
            }
            
            // Write the chunk
            $bytes_written = fwrite($destination, $chunk);
            if ($bytes_written === false || $bytes_written != strlen($chunk)) {
                $this->log_message('Error writing to destination file');
                @fclose($source);
                @fclose($destination);
                return false;
            }
            
            $total_bytes += $bytes_written;
            $chunk_number++;
            
            // Log progress every 10 chunks
            if ($chunk_number % 10 === 0) {
                $percent = $total_size > 0 ? round(($total_bytes / $total_size) * 100, 2) : 0;
                $this->log_message(sprintf('Copied %s of %s (%.2f%%) in %d chunks',
                    size_format($total_bytes, 2),
                    size_format($total_size, 2),
                    $percent,
                    $chunk_number
                ));
            }
        }
        
        @fclose($source);
        @fclose($destination);
        
        // Verify file sizes match
        $destination_size = filesize($destination_path);
        if ($destination_size !== $total_size) {
            $this->log_message(sprintf('Size mismatch after chunked copy: source=%d, destination=%d',
                $total_size,
                $destination_size
            ));
            return false;
        }
        
        $this->log_message('Chunked copy completed successfully: ' . size_format($total_bytes, 2));
        return true;
    }
    
    /**
     * Upload a large file with chunking support.
     * This method is not required by the interface but added for consistency 
     * with other providers and better handling of large files.
     * 
     * @param string $source_path Local path to the file to upload.
     * @param string $destination_path Path within the storage provider.
     * @param array $metadata Optional metadata for the file.
     * @return array|WP_Error Result array with file info or WP_Error on failure.
     */
    public function upload_file_chunked($source_path, $destination_path, $metadata = []) {
        $this->log_message('Using chunked upload method for: ' . $source_path);
        
        // For local storage, this is just a wrapper to the regular upload method
        // but we use the chunked_copy method internally for large files
        return $this->upload_file($source_path, $destination_path, $metadata);
    }
    
    /**
     * {@inheritdoc}
     */
    public function download_file($source_path, $destination_path) {
        $source = $this->storage_dir . $source_path;
        
        if (!file_exists($source)) {
            $this->log_message('Source file does not exist in storage: ' . $source);
            return new WP_Error('download_error', __('Source file does not exist in storage', 'swiftspeed-siberian'));
        }
        
        $destination_dir = dirname($destination_path);
        if (!file_exists($destination_dir)) {
            if (!wp_mkdir_p($destination_dir)) {
                $this->log_message('Failed to create destination directory: ' . $destination_dir);
                return new WP_Error('download_error', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Get file size
        $file_size = filesize($source);
        $this->log_message('Downloading file: ' . $source . ' to ' . $destination_path . ' (Size: ' . size_format($file_size, 2) . ')');
        
        // For very large files (over 100MB), use chunked copy
        if ($file_size > 100 * 1024 * 1024) {
            $this->log_message('Using chunked copy for large file download');
            $success = $this->chunked_copy($source, $destination_path);
        } else {
            // Use regular copy for smaller files
            $success = @copy($source, $destination_path);
        }
        
        if (!$success) {
            $this->log_message('Failed to copy file to destination');
            return new WP_Error('download_error', __('Failed to copy file to destination', 'swiftspeed-siberian'));
        }
        
        $this->log_message('File downloaded successfully to: ' . $destination_path);
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function list_files($directory = '') {
        $path = $this->storage_dir . $directory;
        $path = rtrim($path, '/') . '/';
        
        if (!file_exists($path)) {
            $this->log_message('Directory does not exist: ' . $path);
            return [];
        }
        
        $files = [];
        try {
            $dir_iterator = new RecursiveDirectoryIterator($path);
            $iterator = new RecursiveIteratorIterator($dir_iterator, RecursiveIteratorIterator::SELF_FIRST);
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'zip') {
                    $rel_path = str_replace($this->storage_dir, '', $file->getPathname());
                    $files[] = [
                        'file' => $rel_path,
                        'path' => $file->getPathname(),
                        'url' => $this->storage_url . $rel_path,
                        'size' => size_format($file->getSize(), 2),
                        'bytes' => $file->getSize(),
                        'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $file->getMTime()),
                        'timestamp' => $file->getMTime(),
                        'type' => 'local',
                    ];
                }
            }
            
            $this->log_message('Found ' . count($files) . ' backup files in directory: ' . $path);
        } catch (Exception $e) {
            $this->log_message('Error listing files: ' . $e->getMessage());
        }
        
        return $files;
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete_file($file_path) {
        $file = $this->storage_dir . $file_path;
        
        if (!file_exists($file)) {
            $this->log_message('File does not exist in storage: ' . $file);
            return new WP_Error('delete_error', __('File does not exist in storage', 'swiftspeed-siberian'));
        }
        
        // Delete metadata file if it exists
        $metadata_file = $file . '.meta';
        if (file_exists($metadata_file)) {
            @unlink($metadata_file);
        }
        
        if (!@unlink($file)) {
            $this->log_message('Failed to delete file: ' . $file);
            return new WP_Error('delete_error', __('Failed to delete file', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Successfully deleted file: ' . $file_path);
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function file_exists($file_path) {
        $exists = file_exists($this->storage_dir . $file_path);
        $this->log_message('Checking if file exists: ' . $file_path . ' - ' . ($exists ? 'Yes' : 'No'));
        return $exists;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_name() {
        return __('WordPress Filesystem', 'swiftspeed-siberian');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_identifier() {
        return 'local';
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_configured() {
        return true; // Local storage is always available
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config_fields() {
        return [
            [
                'name' => 'storage_path',
                'label' => __('Storage Path (Relative to wp-content)', 'swiftspeed-siberian'),
                'type' => 'text',
                'default' => 'swsib-backups',
                'description' => __('Path within wp-content where backups will be stored', 'swiftspeed-siberian'),
                'placeholder' => 'swsib-backups',
            ],
            [
                'name' => 'max_backups',
                'label' => __('Maximum Number of Backups to Keep', 'swiftspeed-siberian'),
                'type' => 'number',
                'default' => 10,
                'min' => 1,
                'max' => 100,
                'description' => __('Oldest backups will be automatically removed when this limit is reached', 'swiftspeed-siberian'),
            ],
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function validate_config($config) {
        $validated = [];
        
        // Storage path (sanitize to prevent directory traversal)
        if (isset($config['storage_path'])) {
            $storage_path = sanitize_file_name($config['storage_path']);
            $validated['storage_path'] = $storage_path ?: 'swsib-backups';
        } else {
            $validated['storage_path'] = 'swsib-backups';
        }
        
        // Max backups
        if (isset($config['max_backups'])) {
            $max_backups = intval($config['max_backups']);
            $validated['max_backups'] = ($max_backups >= 1 && $max_backups <= 100) ? $max_backups : 10;
        } else {
            $validated['max_backups'] = 10;
        }
        
        return $validated;
    }
    
    /**
     * {@inheritdoc}
     */
    public function test_connection() {
        if (!is_writable($this->storage_dir) && !wp_mkdir_p($this->storage_dir)) {
            $this->log_message('Storage directory is not writable: ' . $this->storage_dir);
            return new WP_Error('test_connection', __('Storage directory is not writable', 'swiftspeed-siberian'));
        }
        
        // Try to create a test file
        $test_file = $this->storage_dir . 'test-' . uniqid() . '.txt';
        if (file_put_contents($test_file, 'test') === false) {
            $this->log_message('Failed to write test file: ' . $test_file);
            return new WP_Error('test_connection', __('Failed to write test file', 'swiftspeed-siberian'));
        }
        
        // Clean up
        @unlink($test_file);
        $this->log_message('Connection test successful');
        
        return true;
    }
}