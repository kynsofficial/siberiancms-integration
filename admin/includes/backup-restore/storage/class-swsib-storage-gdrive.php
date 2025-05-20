<?php
/**
 * Google Drive storage provider.
 * Modified to use the SwiftSpeed central authentication proxy with improved token handling.
 */
class SwiftSpeed_Siberian_Storage_GDrive implements SwiftSpeed_Siberian_Storage_Interface {
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
     * SwiftSpeed proxy server URL.
     * 
     * @var string
     */
    private $proxy_server = 'https://swiftspeed.app';
    
    /**
     * Constructor.
     * 
     * @param array $config Optional configuration.
     */
    public function __construct($config = []) {
        $this->config = $config;
        $this->temp_dir = WP_CONTENT_DIR . '/swsib-backups/temp/';
        
        // Filter hook to allow changing the proxy server URL
        $this->proxy_server = apply_filters('swsib_gdrive_proxy_server', $this->proxy_server);
        
        // Ensure the temp directory exists
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
    }
    
    /**
     * Get proxy authentication URL.
     */
    public function get_proxy_auth_url($return_url = '') {
        if (empty($return_url)) {
            // Use tab_id parameter instead of tab
            $return_url = admin_url('admin.php?page=swsib-integration&tab_id=backup_restore') . '#settings';
        }
        
        $site_url = site_url();
        
        // Build the proxy authentication URL with required parameters
        $auth_url = trailingslashit($this->proxy_server) . 'wp-json/swiftspeed-gdrive-api/v1/auth';
        $auth_url = add_query_arg(array(
            'site_url' => urlencode($site_url),
            'return_url' => urlencode($return_url),
            'timestamp' => time(), // Add timestamp to prevent caching issues
        ), $auth_url);
        
        $this->log_message('Generated auth URL: ' . $auth_url);
        
        return $auth_url;
    }
    
    /**
     * Decode and process tokens from the proxy.
     */
    public function process_proxy_tokens($encrypted_data) {
        if (empty($encrypted_data)) {
            return new WP_Error('missing_data', __('No token data received from authentication proxy', 'swiftspeed-siberian'));
        }
        
        // First URL decode the data
        $encrypted_data = urldecode($encrypted_data);
        
        // Log some debugging info
        $this->log_message('Processing auth data length: ' . strlen($encrypted_data));
        
        // Decode the data
        try {
            $encrypted_data = base64_decode($encrypted_data);
            if ($encrypted_data === false) {
                return new WP_Error('base64_decode_failed', __('Failed to base64 decode token data', 'swiftspeed-siberian'));
            }
            
            $package = json_decode($encrypted_data, true);
            
            if (!is_array($package) || !isset($package['key']) || !isset($package['iv']) || !isset($package['data'])) {
                return new WP_Error('invalid_data', __('Invalid token data format', 'swiftspeed-siberian'));
            }
            
            // Decrypt the tokens
            if (!function_exists('openssl_decrypt')) {
                return new WP_Error('decryption_not_supported', __('OpenSSL decryption is not available on this server', 'swiftspeed-siberian'));
            }
            
            $key = base64_decode($package['key']);
            $iv = base64_decode($package['iv']);
            
            if ($key === false || $iv === false) {
                return new WP_Error('key_iv_decode_failed', __('Failed to decode encryption key or IV', 'swiftspeed-siberian'));
            }
            
            $decrypted = openssl_decrypt(
                $package['data'],
                'AES-256-CBC',
                $key,
                0,
                $iv
            );
            
            if ($decrypted === false) {
                return new WP_Error('decryption_failed', __('Failed to decrypt token data: ' . openssl_error_string(), 'swiftspeed-siberian'));
            }
            
            $tokens = json_decode($decrypted, true);
            
            if (!is_array($tokens) || !isset($tokens['access_token']) || !isset($tokens['refresh_token'])) {
                return new WP_Error('invalid_tokens', __('Invalid token data content', 'swiftspeed-siberian'));
            }
            
            // Log success
            $this->log_message('Successfully processed auth tokens');
            
            return $tokens;
            
        } catch (Exception $e) {
            $this->log_message('Error processing tokens: ' . $e->getMessage());
            return new WP_Error('token_processing_error', $e->getMessage());
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
            return new WP_Error('storage_init', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
        }
        
        return true;
    }
    
    /**
     * Log a message for debugging.
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'storage', $message);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function upload_file($source_path, $destination_path, $metadata = []) {
        if (!file_exists($source_path)) {
            return new WP_Error('upload_error', __('Source file does not exist', 'swiftspeed-siberian'));
        }
        
        if (!$this->is_configured()) {
            return new WP_Error('upload_error', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get the folder ID to upload to
        $folder_id = $this->get_or_create_backup_folder();
        if (is_wp_error($folder_id)) {
            return $folder_id;
        }
        
        // Prepare file for upload
        $file_name = basename($destination_path);
        $file_size = filesize($source_path);
        
        // For large files, use a chunked upload approach
        if ($file_size > 5 * 1024 * 1024) { // 5MB threshold
            return $this->upload_large_file($source_path, $destination_path, $folder_id, $metadata);
        }
        
        // For smaller files, read the entire content
        $file_content = file_get_contents($source_path);
        if ($file_content === false) {
            return new WP_Error('upload_error', __('Failed to read source file', 'swiftspeed-siberian'));
        }
        
        // Build the request to upload the file
        $boundary = uniqid();
        $content_type = 'application/zip';
        $mime_boundary = "---------------------{$boundary}";
        
        $data = "--{$mime_boundary}\r\n";
        $data .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $data .= json_encode([
            'name' => $file_name,
            'parents' => [$folder_id],
            'mimeType' => $content_type,
            'description' => 'Siberian CMS Backup - ' . date('Y-m-d H:i:s'),
        ]);
        $data .= "\r\n";
        $data .= "--{$mime_boundary}\r\n";
        $data .= "Content-Type: {$content_type}\r\n\r\n";
        $data .= $file_content;
        $data .= "\r\n--{$mime_boundary}--";
        
        // Make the API request
        $response = wp_remote_post(
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
                    'Content-Type' => "multipart/related; boundary={$mime_boundary}",
                    'Content-Length' => strlen($data),
                ],
                'body' => $data,
                'timeout' => 120,
                'method' => 'POST',
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code < 200 || $status_code >= 300) {
            // Check if token expired (401) and try to refresh
            if ($status_code === 401 && !empty($this->config['refresh_token'])) {
                $refresh_result = $this->refresh_access_token();
                if (!is_wp_error($refresh_result)) {
                    // Try again with the new token
                    return $this->upload_file($source_path, $destination_path, $metadata);
                }
            }
            
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            $this->log_message('Upload failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to upload file to Google Drive. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('Uploaded file successfully: ' . $file_name . ' (' . $response_body['id'] . ')');
        
        return [
            'file' => $destination_path,
            'file_id' => $response_body['id'],
            'size' => $file_size,
            'timestamp' => time(),
            'storage' => 'gdrive',
        ];
    }
    
    /**
     * Upload a large file to Google Drive using chunked upload.
     */
   /**
 * Upload a large file to Google Drive using chunked upload.
 */
private function upload_large_file($source_path, $destination_path, $folder_id, $metadata = []) {
    $file_name = basename($destination_path);
    $file_size = filesize($source_path);
    
    // Step 1: Start a resumable upload session
    $response = wp_remote_post(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['access_token'],
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => $file_size,
            ],
            'body' => json_encode([
                'name' => $file_name,
                'parents' => [$folder_id],
                'mimeType' => 'application/zip',
                'description' => 'Siberian CMS Backup - ' . date('Y-m-d H:i:s'),
            ]),
            'method' => 'POST',
            'timeout' => 60,
        ]
    );
    
    if (is_wp_error($response)) {
        $this->log_message('Error starting resumable upload: ' . $response->get_error_message());
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        // Check if token expired (401) and try to refresh
        if ($status_code === 401 && !empty($this->config['refresh_token'])) {
            $refresh_result = $this->refresh_access_token();
            if (!is_wp_error($refresh_result)) {
                // Try again with the new token
                return $this->upload_large_file($source_path, $destination_path, $folder_id, $metadata);
            }
        }
        
        $this->log_message('Failed to start resumable upload. Status: ' . $status_code);
        return new WP_Error(
            'upload_error',
            __('Failed to start resumable upload', 'swiftspeed-siberian')
        );
    }
    
    // Get the upload URL from the 'Location' header
    $upload_url = wp_remote_retrieve_header($response, 'location');
    if (empty($upload_url)) {
        $this->log_message('Missing upload URL in response headers');
        return new WP_Error(
            'upload_error',
            __('Missing upload URL in response headers', 'swiftspeed-siberian')
        );
    }
    
    // Step 2: Upload the file in chunks
    $chunk_size = 5 * 1024 * 1024; // 5MB chunks
    $num_chunks = ceil($file_size / $chunk_size);
    
    $file_handle = fopen($source_path, 'rb');
    if ($file_handle === false) {
        $this->log_message('Failed to open source file');
        return new WP_Error(
            'upload_error',
            __('Failed to open source file', 'swiftspeed-siberian')
        );
    }
    
    $this->log_message('Starting chunked upload: ' . $file_name . ' (' . $file_size . ' bytes, ' . $num_chunks . ' chunks)');
    
    $file_id = null;
    
    for ($chunk = 0; $chunk < $num_chunks; $chunk++) {
        $chunk_start = $chunk * $chunk_size;
        $chunk_end = min($chunk_start + $chunk_size - 1, $file_size - 1);
        $chunk_length = $chunk_end - $chunk_start + 1;
        
        // Seek to the chunk position
        fseek($file_handle, $chunk_start);
        $chunk_data = fread($file_handle, $chunk_length);
        
        if ($chunk_data === false) {
            fclose($file_handle);
            $this->log_message('Failed to read chunk data at position ' . $chunk_start);
            return new WP_Error(
                'upload_error',
                __('Failed to read chunk data', 'swiftspeed-siberian')
            );
        }
        
        // Upload the chunk
        $response = wp_remote_request(
            $upload_url,
            [
                'headers' => [
                    'Content-Length' => $chunk_length,
                    'Content-Range' => "bytes {$chunk_start}-{$chunk_end}/{$file_size}",
                ],
                'body' => $chunk_data,
                'method' => 'PUT',
                'timeout' => 120,
            ]
        );
        
        if (is_wp_error($response)) {
            fclose($file_handle);
            $this->log_message('Error uploading chunk ' . ($chunk + 1) . '/' . $num_chunks . ': ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $this->log_message('Chunk ' . ($chunk + 1) . '/' . $num_chunks . ' status code: ' . $status_code);
        
        // Last chunk should return a success code with file metadata
        if ($chunk === $num_chunks - 1) {
            // Accept any 2xx status code as success for the final chunk
            if ($status_code >= 200 && $status_code < 300) {
                $response_body = json_decode(wp_remote_retrieve_body($response), true);
                
                // Try to get file ID from response
                if (isset($response_body['id'])) {
                    $file_id = $response_body['id'];
                    $this->log_message('Got file ID from response: ' . $file_id);
                } else {
                    // If no ID in response, try to verify the file was uploaded by searching for it
                    $this->log_message('No file ID in response, searching for file by name');
                    $search_id = $this->find_file_by_name($file_name);
                    
                    if (!is_wp_error($search_id) && !empty($search_id)) {
                        $file_id = $search_id;
                        $this->log_message('Found file ID by search: ' . $file_id);
                    } else {
                        $this->log_message('No file ID found by search either, but not failing upload');
                        // Don't fail here - the file might still have uploaded correctly
                    }
                }
            } else {
                // Non-2xx status code for final chunk
                $this->log_message('Final chunk received non-success status code: ' . $status_code);
                
                // Try to verify the file was uploaded anyway by searching for it
                $search_id = $this->find_file_by_name($file_name);
                
                if (!is_wp_error($search_id) && !empty($search_id)) {
                    $file_id = $search_id;
                    $this->log_message('Despite error status code, found file ID by search: ' . $file_id);
                } else {
                    fclose($file_handle);
                    $this->log_message('Failed to complete chunked upload. Status: ' . $status_code);
                    return new WP_Error(
                        'upload_error',
                        __('Failed to complete chunked upload', 'swiftspeed-siberian')
                    );
                }
            }
        } 
        // Intermediate chunks should return 308 Resume Incomplete, but also accept other success codes
        else if ($status_code !== 308 && ($status_code < 200 || $status_code >= 300)) {
            fclose($file_handle);
            $this->log_message('Unexpected status code during chunked upload: ' . $status_code);
            return new WP_Error(
                'upload_error',
                __('Unexpected status code during chunked upload', 'swiftspeed-siberian')
            );
        }
        
        $this->log_message('Uploaded chunk ' . ($chunk + 1) . '/' . $num_chunks);
    }
    
    fclose($file_handle);
    
    // If no file ID was returned, but we uploaded all chunks successfully, verify the file exists
    if (empty($file_id)) {
        $this->log_message('No file ID was returned, but all chunks uploaded. Verifying file exists.');
        $search_id = $this->find_file_by_name($file_name);
        
        if (!is_wp_error($search_id) && !empty($search_id)) {
            $file_id = $search_id;
            $this->log_message('Found file by name verification: ' . $file_id);
        } else {
            $this->log_message('Upload completed but file verification failed');
            return new WP_Error(
                'upload_error',
                __('Upload completed but no file ID was returned', 'swiftspeed-siberian')
            );
        }
    }
    
    $this->log_message('Chunked upload completed successfully: ' . $file_name . ' (ID: ' . $file_id . ')');
    
    return [
        'file' => $destination_path,
        'file_id' => $file_id,
        'size' => $file_size,
        'timestamp' => time(),
        'storage' => 'gdrive',
    ];
}
    /**
     * {@inheritdoc}
     */
    public function download_file($source_path, $destination_path) {
        if (!$this->is_configured()) {
            return new WP_Error('download_error', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
        }
        
        // If source_path is a file ID, use it directly
        $file_id = (strpos($source_path, 'gdrive:') === 0) ? substr($source_path, 7) : $source_path;
        
        // Check if it's a valid ID, otherwise search by name
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $file_id)) {
            $file_name = basename($source_path);
            $file_id = $this->find_file_by_name($file_name);
            
            if (is_wp_error($file_id)) {
                return $file_id;
            }
            
            if (empty($file_id)) {
                return new WP_Error('download_error', __('File not found in Google Drive', 'swiftspeed-siberian'));
            }
        }
        
        // Create destination directory if it doesn't exist
        $destination_dir = dirname($destination_path);
        if (!file_exists($destination_dir)) {
            if (!wp_mkdir_p($destination_dir)) {
                return new WP_Error('download_error', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        // Download the file
        $response = wp_remote_get(
            "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
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
            // Check if token expired (401) and try to refresh
            if ($status_code === 401 && !empty($this->config['refresh_token'])) {
                $refresh_result = $this->refresh_access_token();
                if (!is_wp_error($refresh_result)) {
                    // Try again with the new token
                    return $this->download_file($source_path, $destination_path);
                }
            }
            
            $this->log_message('Download failed with status code: ' . $status_code);
            
            return new WP_Error(
                'download_error',
                sprintf(
                    __('Failed to download file from Google Drive. HTTP Status: %s', 'swiftspeed-siberian'),
                    $status_code
                )
            );
        }
        
        $this->log_message('Downloaded file successfully: ' . basename($destination_path));
        return true;
    }
    
/**
 * List files in the Google Drive backup folder with comprehensive debugging and full folder search.
 */
public function list_files($directory = '') {
    if (!$this->is_configured()) {
        return new WP_Error('list_error', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
    }
    
    // Try to refresh token to ensure we have a valid one
    $this->refresh_access_token();
    
    // Get the folder ID to list files from
    $folder_id = $this->get_backup_folder();
    if (is_wp_error($folder_id)) {
        return $folder_id;
    }
    
    if (empty($folder_id)) {
        $this->log_message('Backup folder not found, trying to create it');
        $folder_id = $this->get_or_create_backup_folder();
        
        if (is_wp_error($folder_id)) {
            $this->log_message('Failed to create backup folder: ' . $folder_id->get_error_message());
            return $folder_id;
        }
        
        if (empty($folder_id)) {
            $this->log_message('Backup folder not found and could not be created');
            return [];
        }
    }
    
    $this->log_message('Using folder ID: ' . $folder_id);
    
    // List all files in the folder - not just application/zip to see everything that's there
    // Then we'll filter client-side to ensure we don't miss anything
    $query = "'{$folder_id}' in parents and trashed = false";
    $response = wp_remote_get(
        'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . 
        '&fields=files(id,name,mimeType,size,createdTime,modifiedTime)' . 
        '&pageSize=1000', // Get up to 1000 files at once
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['access_token'],
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
        // Check if token expired (401) and try to refresh
        if ($status_code === 401) {
            $refresh_result = $this->refresh_access_token();
            if (!is_wp_error($refresh_result)) {
                // Try again with the new token
                return $this->list_files($directory);
            }
        }
        
        $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
        $this->log_message('List files failed: ' . $error_message);
        
        return new WP_Error(
            'list_error',
            sprintf(
                __('Failed to list files from Google Drive. Error: %s', 'swiftspeed-siberian'),
                $error_message
            )
        );
    }
    
    // Debug the raw response to see all files
    if (!empty($response_body['files'])) {
        $this->log_message('Raw Google Drive response contains ' . count($response_body['files']) . ' total files:');
        foreach ($response_body['files'] as $file) {
            $this->log_message('  - ' . $file['name'] . ' (' . ($file['mimeType'] ?? 'unknown type') . ')');
        }
    } else {
        $this->log_message('No files found in Google Drive folder');
    }
    
    $files = [];
    $backup_pattern = '/^siberian-backup-(full|db|file|files)-(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})\.zip$/';
    
    if (!empty($response_body['files'])) {
        foreach ($response_body['files'] as $file) {
            // Explicitly check if the filename matches our backup pattern
            if (preg_match($backup_pattern, $file['name'])) {
                $timestamp = strtotime($file['modifiedTime']);
                $files[] = [
                    'file' => $file['name'],
                    'file_id' => $file['id'],
                    'path' => 'gdrive:' . $file['id'],
                    'size' => isset($file['size']) ? size_format($file['size'], 2) : '0 KB',
                    'bytes' => isset($file['size']) ? intval($file['size']) : 0,
                    'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp),
                    'timestamp' => $timestamp,
                    'type' => 'gdrive',
                ];
                $this->log_message('Added matching backup file: ' . $file['name']);
            } else {
                $this->log_message('Skipping non-backup file: ' . $file['name']);
            }
        }
    }
    
    $this->log_message('Found ' . count($files) . ' Siberian backup files in Google Drive');
    return $files;
}

/**
 * Check if a file exists in Google Drive by its name.
 * 
 * @param string $file_name The name of the file to check
 * @return boolean True if the file exists, false otherwise
 */
private function file_exists_by_name($file_name) {
    $folder_id = $this->get_backup_folder();
    
    if (is_wp_error($folder_id) || empty($folder_id)) {
        return false;
    }
    
    $query = "name = '{$file_name}' and '{$folder_id}' in parents and trashed = false";
    $response = wp_remote_get(
        'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id)',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['access_token'],
            ],
            'timeout' => 10,
        ]
    );
    
    if (is_wp_error($response)) {
        $this->log_message('File exists check error: ' . $response->get_error_message());
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        return false;
    }
    
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($response_body['files']);
}
/**
 * Check if the current access token is valid.
 *
 * @return bool Whether the token is valid.
 */
private function is_token_valid() {
    if (empty($this->config['access_token'])) {
        return false;
    }
    
    // Make a simple API call to check token validity
    $response = wp_remote_get(
        'https://www.googleapis.com/drive/v3/about?fields=user',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['access_token'],
            ],
            'timeout' => 10,
        ]
    );
    
    if (is_wp_error($response)) {
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    return $status_code >= 200 && $status_code < 300;
}


    /**
     * {@inheritdoc}
     */
    public function delete_file($file_path) {
        if (!$this->is_configured()) {
            return new WP_Error('delete_error', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
        }
        
        // If file_path is a file ID, use it directly
        $file_id = (strpos($file_path, 'gdrive:') === 0) ? substr($file_path, 7) : $file_path;
        
        // Check if it's a valid ID, otherwise search by name
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $file_id)) {
            $file_name = basename($file_path);
            $file_id = $this->find_file_by_name($file_name);
            
            if (is_wp_error($file_id)) {
                return $file_id;
            }
            
            if (empty($file_id)) {
                return new WP_Error('delete_error', __('File not found in Google Drive', 'swiftspeed-siberian'));
            }
        }
        
        // Delete the file
        $response = wp_remote_request(
            "https://www.googleapis.com/drive/v3/files/{$file_id}",
            [
                'method' => 'DELETE',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
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
            // Check if token expired (401) and try to refresh
            if ($status_code === 401 && !empty($this->config['refresh_token'])) {
                $refresh_result = $this->refresh_access_token();
                if (!is_wp_error($refresh_result)) {
                    // Try again with the new token
                    return $this->delete_file($file_path);
                }
            }
            
            $this->log_message('Delete file failed with status code: ' . $status_code);
            
            return new WP_Error(
                'delete_error',
                sprintf(
                    __('Failed to delete file from Google Drive. HTTP Status: %s', 'swiftspeed-siberian'),
                    $status_code
                )
            );
        }
        
        $this->log_message('Deleted file successfully: ' . $file_id);
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function file_exists($file_path) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $file_id = (strpos($file_path, 'gdrive:') === 0) ? substr($file_path, 7) : null;
        
        if ($file_id) {
            // Check if file exists by ID
            $response = wp_remote_get(
                "https://www.googleapis.com/drive/v3/files/{$file_id}?fields=id",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                    ],
                    'timeout' => 10,
                ]
            );
            
            if (is_wp_error($response)) {
                $this->log_message('File exists check error: ' . $response->get_error_message());
                return false;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            return $status_code >= 200 && $status_code < 300;
        } else {
            // Check if file exists by name
            $file_name = basename($file_path);
            $folder_id = $this->get_backup_folder();
            
            if (is_wp_error($folder_id) || empty($folder_id)) {
                return false;
            }
            
            $query = "name = '{$file_name}' and '{$folder_id}' in parents and trashed = false";
            $response = wp_remote_get(
                'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id)',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                    ],
                    'timeout' => 10,
                ]
            );
            
            if (is_wp_error($response)) {
                $this->log_message('File exists check error: ' . $response->get_error_message());
                return false;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                return false;
            }
            
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            return !empty($response_body['files']);
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_display_name() {
        return __('Google Drive', 'swiftspeed-siberian');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_identifier() {
        return 'gdrive';
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_configured() {
        return !empty($this->config['access_token']) && !empty($this->config['refresh_token']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config_fields() {
        return [
            [
                'name' => 'folder_name',
                'label' => __('Backup Folder Name', 'swiftspeed-siberian'),
                'type' => 'text',
                'default' => 'SiberianCMS Backups',
                'description' => __('Name of the folder in Google Drive where backups will be stored', 'swiftspeed-siberian'),
                'placeholder' => 'SiberianCMS Backups',
            ],
            [
                'name' => 'auth_button',
                'label' => __('Authenticate with Google Drive', 'swiftspeed-siberian'),
                'type' => 'auth_button',
                'text' => $this->is_configured() ? __('Reconnect to Google Drive', 'swiftspeed-siberian') : __('Connect to Google Drive', 'swiftspeed-siberian'),
                'description' => __('Click to authorize this plugin to access your Google Drive using the SwiftSpeed authentication proxy', 'swiftspeed-siberian'),
                'use_central_api' => true,
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
            [
                'name' => 'proxy_note',
                'label' => __('Authentication Method', 'swiftspeed-siberian'),
                'type' => 'html',
                'html' => sprintf(
                    '<p><strong>%s</strong></p><p>%s</p>',
                    __('Using SwiftSpeed Authentication Proxy', 'swiftspeed-siberian'),
                    __('This plugin uses a central authentication service to securely connect to Google Drive without requiring you to create your own Google API credentials.', 'swiftspeed-siberian')
                ),
            ],
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function validate_config($config) {
        $validated = [];
        
        // Folder Name
        if (isset($config['folder_name'])) {
            $validated['folder_name'] = sanitize_text_field($config['folder_name']) ?: 'SiberianCMS Backups';
        } else {
            $validated['folder_name'] = 'SiberianCMS Backups';
        }
        
        // Access Token and Refresh Token (if available)
        if (!empty($config['access_token'])) {
            $validated['access_token'] = sanitize_text_field($config['access_token']);
        } else if (!empty($this->config['access_token'])) {
            // Keep existing token if not provided in new config
            $validated['access_token'] = $this->config['access_token'];
        }
        
        if (!empty($config['refresh_token'])) {
            $validated['refresh_token'] = sanitize_text_field($config['refresh_token']);
        } else if (!empty($this->config['refresh_token'])) {
            // Keep existing token if not provided in new config
            $validated['refresh_token'] = $this->config['refresh_token'];
        }
        
        // Account info (if available)
        if (!empty($config['account_info'])) {
            $validated['account_info'] = sanitize_text_field($config['account_info']);
        } else if (!empty($this->config['account_info'])) {
            // Keep existing account info if not provided in new config
            $validated['account_info'] = $this->config['account_info'];
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
            return new WP_Error('test_connection', __('Google Drive is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Check access token validity by making a simple API call
        $response = wp_remote_get(
            'https://www.googleapis.com/drive/v3/about?fields=user',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Test connection error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 401) {
            // Token expired, try to refresh it
            $refresh_result = $this->refresh_access_token();
            if (is_wp_error($refresh_result)) {
                $this->log_message('Token refresh failed: ' . $refresh_result->get_error_message());
                return $refresh_result;
            }
            
            // Try again with the new token
            $response = wp_remote_get(
                'https://www.googleapis.com/drive/v3/about?fields=user',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                    ],
                    'timeout' => 15,
                ]
            );
            
            if (is_wp_error($response)) {
                $this->log_message('Test connection after token refresh error: ' . $response->get_error_message());
                return $response;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
        }
        
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            $this->log_message('Test connection failed: ' . $error_message);
            
            return new WP_Error(
                'test_connection',
                sprintf(
                    __('Failed to connect to Google Drive. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Store account info if available
        if (isset($response_body['user']['emailAddress']) && !empty($response_body['user']['emailAddress'])) {
            $this->config['account_info'] = $response_body['user']['emailAddress'];
            
            // Save account info to options
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
            
            $this->log_message('Updated account info: ' . $response_body['user']['emailAddress']);
        }
        
        // Check for backup folder
        $folder_result = $this->get_or_create_backup_folder();
        if (is_wp_error($folder_result)) {
            $this->log_message('Folder check failed: ' . $folder_result->get_error_message());
            return $folder_result;
        }
        
        $this->log_message('Test connection successful');
        return true;
    }
    

/**
 * Refresh the access token using the refresh token via the proxy.
 */
public function refresh_access_token() {
    if (empty($this->config['refresh_token'])) {
        return new WP_Error('refresh_token', __('Missing refresh token', 'swiftspeed-siberian'));
    }

    $site_url = site_url();

    // Log the refresh token attempt
    $this->log_message('Attempting to refresh access token via proxy');

    // Try to refresh token via the proxy
    $proxy_refresh_url = trailingslashit($this->proxy_server) . 'wp-json/swiftspeed-gdrive-api/v1/refresh';
    $response = wp_remote_post($proxy_refresh_url, [
        'body' => [
            'refresh_token' => $this->config['refresh_token'],
            'site_url' => $site_url,
            'timestamp' => time(), // Prevent caching issues
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        $this->log_message('Proxy token refresh error: ' . $response->get_error_message());
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code >= 200 && $status_code < 300 && !empty($response_body['access_token'])) {
        // Update access token
        $this->config['access_token'] = $response_body['access_token'];

        // Save updated token to options
        $options = get_option('swsib_options', []);
        $options['backup_restore']['storage']['gdrive']['access_token'] = $response_body['access_token'];

        // Store account info if provided
        if (!empty($response_body['email'])) {
            $options['backup_restore']['storage']['gdrive']['account_info'] = $response_body['email'];
            $this->config['account_info'] = $response_body['email'];
        }

        update_option('swsib_options', $options);

        $this->log_message('Successfully refreshed access token via proxy');
        return true;
    }

    $error_message = !empty($response_body['message']) ? $response_body['message'] : 'Unknown error';
    $this->log_message('Proxy token refresh failed: ' . $error_message);

    return new WP_Error(
        'refresh_token',
        sprintf(
            __('Failed to refresh access token. Error: %s', 'swiftspeed-siberian'),
            $error_message
        )
    );
}

    
    /**
     * Get the backup folder ID.
    
     */
    private function get_backup_folder() {
        $folder_name = !empty($this->config['folder_name']) ? $this->config['folder_name'] : 'SiberianCMS Backups';
        
        $query = "name = '{$folder_name}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $response = wp_remote_get(
            'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id)',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Error getting folder: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 401) {
            // Token expired, try to refresh it
            $refresh_result = $this->refresh_access_token();
            if (is_wp_error($refresh_result)) {
                $this->log_message('Token refresh failed: ' . $refresh_result->get_error_message());
                return $refresh_result;
            }
            
            // Try again with the new token
            return $this->get_backup_folder();
        }
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            $this->log_message('Failed to get folder: ' . $error_message);
            
            return new WP_Error(
                'get_folder',
                sprintf(
                    __('Failed to get backup folder. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($response_body['files'])) {
            $this->log_message('Folder not found: ' . $folder_name);
            return '';
        }
        
        $this->log_message('Folder found: ' . $response_body['files'][0]['id']);
        return $response_body['files'][0]['id'];
    }
    
    /**
     * Find a file by name in the backup folder.
     */
    private function find_file_by_name($file_name) {
        $folder_id = $this->get_backup_folder();
        
        if (is_wp_error($folder_id)) {
            return $folder_id;
        }
        
        if (empty($folder_id)) {
            return '';
        }
        
        $query = "name = '{$file_name}' and '{$folder_id}' in parents and trashed = false";
        $response = wp_remote_get(
            'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id)',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['access_token'],
                ],
                'timeout' => 15,
            ]
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Error finding file: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            // Check if token expired (401) and try to refresh
            if ($status_code === 401 && !empty($this->config['refresh_token'])) {
                $refresh_result = $this->refresh_access_token();
                if (!is_wp_error($refresh_result)) {
                    // Try again with the new token
                    return $this->find_file_by_name($file_name);
                }
            }
            
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($response_body['error']['message']) ? $response_body['error']['message'] : 'Unknown error';
            $this->log_message('Failed to find file: ' . $error_message);
            
            return new WP_Error(
                'find_file',
                sprintf(
                    __('Failed to find file. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($response_body['files'])) {
            $this->log_message('File not found: ' . $file_name);
            return '';
        }
        
        $this->log_message('File found: ' . $response_body['files'][0]['id']);
        return $response_body['files'][0]['id'];
    }
}