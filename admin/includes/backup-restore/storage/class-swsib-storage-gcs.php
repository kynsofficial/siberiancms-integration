<?php
/**
 * Google Cloud Storage provider.
 */
class SwiftSpeed_Siberian_Storage_GCS implements SwiftSpeed_Siberian_Storage_Interface {
    /**
     * Storage configuration.
     * 
     * @var array
     */
    private $config;
    
    /**
     * Temporary directory for downloads.
     * 
     * @var string
     */
    private $temp_dir;
    
    /**
     * JWT token cache.
     * 
     * @var array
     */
    private $token_cache;
    
    /**
     * Constructor.
     * 
     * @param array $config Optional configuration.
     */
    public function __construct($config = []) {
        $this->config = $config;
        $this->temp_dir = WP_CONTENT_DIR . '/swsib-backups/temp/';
        $this->token_cache = get_transient('swsib_gcs_token_cache') ?: [];
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
        if (!file_exists($this->temp_dir)) {
            if (!wp_mkdir_p($this->temp_dir)) {
                return new WP_Error('storage_init', __('Failed to create temporary directory', 'swiftspeed-siberian'));
            }
        }
        
        if (!$this->is_configured()) {
            return new WP_Error('storage_init', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
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
        
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('upload_error', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get file size to determine upload method
        $file_size = filesize($source_path);
        $this->log_message('Uploading file: ' . $source_path . ' to GCS (Size: ' . size_format($file_size, 2) . ')');
        
        // For large files (over 100MB), use chunked upload
        if ($file_size > 100 * 1024 * 1024) {
            $this->log_message('File size exceeds 100MB, using chunked upload');
            return $this->upload_file_chunked($source_path, $destination_path, $metadata);
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        // Prepare file for upload
        $file_content = file_get_contents($source_path);
        if ($file_content === false) {
            $this->log_message('Failed to read source file: ' . $source_path);
            return new WP_Error('upload_error', __('Failed to read source file', 'swiftspeed-siberian'));
        }
        
        $bucket = $this->config['bucket'];
        $object_name = $this->prepare_object_name($destination_path);
        
        // Make the API request to upload the file
        $this->log_message('Sending GCS upload request for: ' . $object_name);
        $response = wp_remote_request(
            "https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=media&name=" . urlencode($object_name),
            [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/zip',
                ],
                'body' => $file_content,
                'timeout' => 120,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            $this->log_message('Upload failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to upload file to Google Cloud Storage. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('File uploaded successfully to GCS: ' . $object_name);
        
        return [
            'file' => basename($destination_path),
            'object_name' => $object_name,
            'size' => $file_size,
            'timestamp' => time(),
            'storage' => 'gcs',
        ];
    }
    
    /**
     * Upload a large file to Google Cloud Storage using resumable uploads.
     * 
     * @param string $source_path Local path to the file to upload.
     * @param string $destination_path Path within the storage provider.
     * @param array $metadata Optional metadata for the file.
     * @return array|WP_Error Result array with file info or WP_Error on failure.
     */
    public function upload_file_chunked($source_path, $destination_path, $metadata = []) {
        if (!file_exists($source_path)) {
            $this->log_message('Source file does not exist: ' . $source_path);
            return new WP_Error('upload_error', __('Source file does not exist', 'swiftspeed-siberian'));
        }
        
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('upload_error', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        $file_size = filesize($source_path);
        $bucket = $this->config['bucket'];
        $object_name = $this->prepare_object_name($destination_path);
        
        $this->log_message('Starting resumable upload for: ' . $object_name . ' (Size: ' . size_format($file_size, 2) . ')');
        
        // Step 1: Initiate a resumable upload
        $upload_url = $this->initiate_resumable_upload($bucket, $object_name, $token);
        if (is_wp_error($upload_url)) {
            $this->log_message('Failed to initiate resumable upload: ' . $upload_url->get_error_message());
            return $upload_url;
        }
        
        $this->log_message('Resumable upload initiated with URL: ' . $upload_url);
        
        // Step 2: Upload the file in chunks
        $chunk_size = 5 * 1024 * 1024; // 5MB chunks
        $total_chunks = ceil($file_size / $chunk_size);
        
        $file_handle = fopen($source_path, 'rb');
        if (!$file_handle) {
            $this->log_message('Failed to open source file: ' . $source_path);
            return new WP_Error('upload_error', __('Failed to open source file', 'swiftspeed-siberian'));
        }
        
        $success = false;
        
        for ($chunk = 0; $chunk < $total_chunks; $chunk++) {
            $start_byte = $chunk * $chunk_size;
            $end_byte = min($start_byte + $chunk_size - 1, $file_size - 1);
            $chunk_length = $end_byte - $start_byte + 1;
            
            $this->log_message(sprintf('Uploading chunk %d of %d (bytes %d-%d)', $chunk + 1, $total_chunks, $start_byte, $end_byte));
            
            // Seek to the chunk position
            fseek($file_handle, $start_byte);
            $chunk_data = fread($file_handle, $chunk_length);
            
            if ($chunk_data === false) {
                fclose($file_handle);
                $this->log_message('Failed to read chunk ' . ($chunk + 1));
                return new WP_Error('upload_error', __('Failed to read file chunk', 'swiftspeed-siberian'));
            }
            
            // Upload the chunk
            $result = $this->upload_chunk($upload_url, $chunk_data, $start_byte, $end_byte, $file_size);
            
            if (is_wp_error($result)) {
                fclose($file_handle);
                $this->log_message('Failed to upload chunk ' . ($chunk + 1) . ': ' . $result->get_error_message());
                return $result;
            }
            
            // If we got a 200 or 201 response, the upload is complete
            if ($result === true) {
                $success = true;
                $this->log_message('Chunk upload completed the file upload');
                break;
            }
        }
        
        fclose($file_handle);
        
        if (!$success) {
            $this->log_message('Chunked upload failed to complete');
            return new WP_Error('upload_error', __('Failed to complete resumable upload', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Chunked upload completed successfully: ' . $object_name);
        
        return [
            'file' => basename($destination_path),
            'object_name' => $object_name,
            'size' => $file_size,
            'timestamp' => time(),
            'storage' => 'gcs',
        ];
    }
    
    /**
     * Initiates a resumable upload session with Google Cloud Storage.
     * 
     * @param string $bucket The GCS bucket
     * @param string $object_name The object name
     * @param string $token The authentication token
     * @return string|WP_Error The resumable upload URL or error
     */
    private function initiate_resumable_upload($bucket, $object_name, $token) {
        $this->log_message('Initiating resumable upload session');
        
        $response = wp_remote_post(
            "https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=resumable&name=" . urlencode($object_name),
            [
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'X-Upload-Content-Type' => 'application/zip',
                ],
                'body' => json_encode([
                    'name' => $object_name,
                ]),
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Initiate resumable upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            
            $this->log_message('Initiate resumable upload failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to initiate resumable upload. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Get the Location header for the resumable upload
        $upload_url = wp_remote_retrieve_header($response, 'location');
        
        if (empty($upload_url)) {
            $this->log_message('No Location header in resumable upload response');
            return new WP_Error('upload_error', __('No Location header in resumable upload response', 'swiftspeed-siberian'));
        }
        
        return $upload_url;
    }
    
    /**
     * Uploads a chunk in a resumable upload.
     * 
     * @param string $upload_url The resumable upload URL
     * @param string $chunk_data The chunk data
     * @param int $start_byte The start byte position
     * @param int $end_byte The end byte position
     * @param int $total_size The total file size
     * @return bool|WP_Error True if upload is complete, false if more chunks needed, WP_Error on failure
     */
    private function upload_chunk($upload_url, $chunk_data, $start_byte, $end_byte, $total_size) {
        $this->log_message(sprintf('Uploading chunk bytes %d-%d of %d', $start_byte, $end_byte, $total_size));
        
        $content_range = "bytes {$start_byte}-{$end_byte}/{$total_size}";
        
        $response = wp_remote_request(
            $upload_url,
            [
                'method' => 'PUT',
                'headers' => [
                    'Content-Length' => strlen($chunk_data),
                    'Content-Range' => $content_range,
                ],
                'body' => $chunk_data,
                'timeout' => 60,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Upload chunk error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        // HTTP status 308 means the chunk was accepted and more chunks are expected
        if ($status_code === 308) {
            $this->log_message('Chunk uploaded successfully, more chunks needed');
            return false;
        }
        
        // HTTP status 200 or 201 means the upload is complete
        if ($status_code === 200 || $status_code === 201) {
            $this->log_message('Chunk uploaded successfully, upload complete');
            return true;
        }
        
        // Any other status code is an error
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
        
        $this->log_message('Upload chunk failed with status code ' . $status_code . ': ' . $error_message);
        
        return new WP_Error(
            'upload_error',
            sprintf(
                __('Failed to upload chunk. Error: %s', 'swiftspeed-siberian'),
                $error_message
            )
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function download_file($source_path, $destination_path) {
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('download_error', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        $bucket = $this->config['bucket'];
        $object_name = $this->prepare_object_name($source_path);
        
        // Create destination directory if it doesn't exist
        $destination_dir = dirname($destination_path);
        if (!file_exists($destination_dir)) {
            if (!wp_mkdir_p($destination_dir)) {
                $this->log_message('Failed to create destination directory: ' . $destination_dir);
                return new WP_Error('download_error', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        $this->log_message('Downloading file from GCS: ' . $object_name . ' to ' . $destination_path);
        
        // Make the API request to download the file
        $response = wp_remote_get(
            "https://storage.googleapis.com/storage/v1/b/{$bucket}/o/" . urlencode($object_name) . '?alt=media',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 300,
                'stream' => true,
                'filename' => $destination_path,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Download error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            
            $this->log_message('Download failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'download_error',
                sprintf(
                    __('Failed to download file from Google Cloud Storage. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Verify the downloaded file
        if (!file_exists($destination_path)) {
            $this->log_message('Download appears to have succeeded, but file does not exist at destination: ' . $destination_path);
            return new WP_Error('download_error', __('Downloaded file not found at destination', 'swiftspeed-siberian'));
        }
        
        $this->log_message('File downloaded successfully to: ' . $destination_path);
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function list_files($directory = '') {
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('list_error', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        $bucket = $this->config['bucket'];
        $prefix = $this->prepare_object_name($directory);
        
        $this->log_message('Listing files from GCS with prefix: ' . $prefix);
        
        // Build query parameters
        $query = [];
        if (!empty($prefix)) {
            $query['prefix'] = $prefix;
        }
        
        $query_string = !empty($query) ? '?' . http_build_query($query) : '';
        
        // Make the API request to list files
        $response = wp_remote_get(
            "https://storage.googleapis.com/storage/v1/b/{$bucket}/o{$query_string}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('List files error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            
            $this->log_message('List files failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'list_error',
                sprintf(
                    __('Failed to list files from Google Cloud Storage. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $files = [];
        
        if (!empty($response_body['items'])) {
            $this->log_message('Found ' . count($response_body['items']) . ' objects in GCS bucket');
            
            foreach ($response_body['items'] as $item) {
                // Only include .zip files
                if (substr($item['name'], -4) !== '.zip') {
                    continue;
                }
                
                $timestamp = strtotime($item['updated']);
                $files[] = [
                    'file' => basename($item['name']),
                    'object_name' => $item['name'],
                    'url' => "https://storage.googleapis.com/{$bucket}/" . urlencode($item['name']),
                    'size' => size_format($item['size'], 2),
                    'bytes' => intval($item['size']),
                    'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp),
                    'timestamp' => $timestamp,
                    'type' => 'gcs',
                ];
            }
            
            $this->log_message('Found ' . count($files) . ' .zip backup files in GCS');
        } else {
            $this->log_message('No files found in GCS bucket with prefix: ' . $prefix);
        }
        
        return $files;
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete_file($file_path) {
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('delete_error', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        $bucket = $this->config['bucket'];
        $object_name = $this->prepare_object_name($file_path);
        
        $this->log_message('Deleting file from GCS: ' . $object_name);
        
        // Make the API request to delete the file
        $response = wp_remote_request(
            "https://storage.googleapis.com/storage/v1/b/{$bucket}/o/" . urlencode($object_name),
            [
                'method' => 'DELETE',
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 30,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Delete file error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300 && $status_code !== 404) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            
            $this->log_message('Delete file failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'delete_error',
                sprintf(
                    __('Failed to delete file from Google Cloud Storage. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('File deleted successfully from GCS');
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function file_exists($file_path) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return false;
        }
        
        $bucket = $this->config['bucket'];
        $object_name = $this->prepare_object_name($file_path);
        
        $this->log_message('Checking if file exists in GCS: ' . $object_name);
        
        // Make the API request to get metadata about the file
        $response = wp_remote_get(
            "https://storage.googleapis.com/storage/v1/b/{$bucket}/o/" . urlencode($object_name),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('File exists check error: ' . $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $exists = ($status_code >= 200 && $status_code < 300);
        
        $this->log_message('File exists check: ' . ($exists ? 'Yes' : 'No'));
        return $exists;
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_name() {
        return __('Google Cloud Storage', 'swiftspeed-siberian');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_identifier() {
        return 'gcs';
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_configured() {
        return !empty($this->config['project_id']) &&
               !empty($this->config['private_key_id']) &&
               !empty($this->config['private_key']) &&
               !empty($this->config['client_email']) &&
               !empty($this->config['bucket']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config_fields() {
        return [
            [
                'name' => 'project_id',
                'label' => __('Project ID', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Google Cloud Project ID', 'swiftspeed-siberian'),
                'placeholder' => 'my-project-123456',
                'required' => true,
            ],
            [
                'name' => 'client_email',
                'label' => __('Client Email', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Service account client email', 'swiftspeed-siberian'),
                'placeholder' => 'my-service-account@my-project-123456.iam.gserviceaccount.com',
                'required' => true,
            ],
            [
                'name' => 'private_key_id',
                'label' => __('Private Key ID', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Service account private key ID', 'swiftspeed-siberian'),
                'placeholder' => 'abcdef1234567890abcdef1234567890abcdef12',
                'required' => true,
            ],
            [
                'name' => 'private_key',
                'label' => __('Private Key', 'swiftspeed-siberian'),
                'type' => 'textarea',
                'description' => __('Service account private key (full content)', 'swiftspeed-siberian'),
                'placeholder' => '-----BEGIN PRIVATE KEY-----\nMIIE...\n-----END PRIVATE KEY-----\n',
                'required' => true,
            ],
            [
                'name' => 'bucket',
                'label' => __('Bucket Name', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Google Cloud Storage Bucket Name', 'swiftspeed-siberian'),
                'placeholder' => 'my-bucket',
                'required' => true,
            ],
            [
                'name' => 'prefix',
                'label' => __('Folder Prefix', 'swiftspeed-siberian'),
                'type' => 'text',
                'default' => 'siberian-backups/',
                'description' => __('Folder path within the bucket to store backups', 'swiftspeed-siberian'),
                'placeholder' => 'siberian-backups/',
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
        
        // Project ID
        if (empty($config['project_id'])) {
            return new WP_Error('validate_config', __('Project ID is required', 'swiftspeed-siberian'));
        }
        $validated['project_id'] = sanitize_text_field($config['project_id']);
        
        // Client Email
        if (empty($config['client_email'])) {
            return new WP_Error('validate_config', __('Client Email is required', 'swiftspeed-siberian'));
        }
        $validated['client_email'] = sanitize_text_field($config['client_email']);
        
        // Private Key ID
        if (empty($config['private_key_id'])) {
            return new WP_Error('validate_config', __('Private Key ID is required', 'swiftspeed-siberian'));
        }
        $validated['private_key_id'] = sanitize_text_field($config['private_key_id']);
        
        // Private Key
        if (empty($config['private_key'])) {
            return new WP_Error('validate_config', __('Private Key is required', 'swiftspeed-siberian'));
        }
        $validated['private_key'] = sanitize_textarea_field($config['private_key']);
        
        // Bucket
        if (empty($config['bucket'])) {
            return new WP_Error('validate_config', __('Bucket Name is required', 'swiftspeed-siberian'));
        }
        $validated['bucket'] = sanitize_text_field($config['bucket']);
        
        // Prefix (optional)
        if (isset($config['prefix'])) {
            $prefix = sanitize_text_field($config['prefix']);
            $prefix = rtrim($prefix, '/') . '/'; // Ensure trailing slash
            $validated['prefix'] = $prefix;
        } else {
            $validated['prefix'] = 'siberian-backups/';
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
        if (!$this->is_configured()) {
            $this->log_message('Google Cloud Storage is not properly configured');
            return new WP_Error('test_connection', __('Google Cloud Storage is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get authentication token
        $token = $this->get_auth_token();
        if (is_wp_error($token)) {
            $this->log_message('Authentication failed: ' . $token->get_error_message());
            return $token;
        }
        
        $bucket = $this->config['bucket'];
        
        $this->log_message('Testing connection to GCS bucket: ' . $bucket);
        
        // Check if bucket exists and is accessible
        $response = wp_remote_get(
            "https://storage.googleapis.com/storage/v1/b/{$bucket}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Connection test failed: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            
            $this->log_message('Connection test failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'test_connection',
                sprintf(
                    __('Failed to access Google Cloud Storage bucket. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('Connection test successful');
        return true;
    }
    
    /**
     * Get authentication token for Google Cloud Storage API.
     * 
     * @return string|WP_Error Authentication token or WP_Error on failure.
     */
    private function get_auth_token() {
        // Check if we already have a valid token in cache
        if (!empty($this->token_cache['token']) && $this->token_cache['expires'] > time()) {
            $this->log_message('Using cached authentication token');
            return $this->token_cache['token'];
        }
        
        $this->log_message('Generating new authentication token');
        
        // Prepare JWT claims
        $now = time();
        $expiration = $now + 3600; // Token valid for 1 hour
        
        $claims = [
            'iss' => $this->config['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $expiration,
            'iat' => $now,
        ];
        
        // Create JWT header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $this->config['private_key_id'],
        ];
        
        // Encode header and claims
        $base64_header = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $base64_claims = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
        
        // Create signature
        $signature_data = $base64_header . '.' . $base64_claims;
        $private_key = $this->config['private_key'];
        
        // Ensure the private key has the right format
        if (strpos($private_key, '-----BEGIN PRIVATE KEY-----') === false) {
            $private_key = "-----BEGIN PRIVATE KEY-----\n" . $private_key . "\n-----END PRIVATE KEY-----\n";
        }
        
        $signature = '';
        $result = openssl_sign($signature_data, $signature, $private_key, OPENSSL_ALGO_SHA256);
        
        if (!$result) {
            $this->log_message('Failed to sign JWT: ' . openssl_error_string());
            return new WP_Error('auth_token', __('Failed to sign JWT: ' . openssl_error_string(), 'swiftspeed-siberian'));
        }
        
        // Encode signature
        $base64_signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        
        // Create JWT
        $jwt = $base64_header . '.' . $base64_claims . '.' . $base64_signature;
        
        // Exchange JWT for access token
        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Token exchange error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code < 200 || $status_code >= 300 || empty($response_body['access_token'])) {
            $error_message = isset($response_body['error_description']) ? $response_body['error_description'] : 'Unknown error';
            
            $this->log_message('Token exchange failed: ' . $error_message);
            
            return new WP_Error(
                'auth_token',
                sprintf(
                    __('Failed to obtain access token. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Cache the token
        $this->token_cache = [
            'token' => $response_body['access_token'],
            'expires' => time() + $response_body['expires_in'] - 300, // 5 minutes buffer
        ];
        
        set_transient('swsib_gcs_token_cache', $this->token_cache, $response_body['expires_in']);
        
        $this->log_message('New authentication token generated successfully');
        return $response_body['access_token'];
    }
    
    /**
     * Prepare an object name with the correct prefix.
     * 
     * @param string $object_name The object name or path to prepare.
     * @return string The prepared object name.
     */
    private function prepare_object_name($object_name) {
        $prefix = !empty($this->config['prefix']) ? $this->config['prefix'] : 'siberian-backups/';
        $prefix = rtrim($prefix, '/') . '/';
        
        // If the object name already has the prefix, return it as is
        if (strpos($object_name, $prefix) === 0) {
            return $object_name;
        }
        
        // Remove any leading slashes
        $object_name = ltrim($object_name, '/');
        
        // If the object name starts with 'gcs:', remove it
        if (strpos($object_name, 'gcs:') === 0) {
            $object_name = substr($object_name, 4);
        }
        
        // Combine the prefix and object name
        return $prefix . $object_name;
    }
}