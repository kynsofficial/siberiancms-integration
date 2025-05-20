<?php
/**
 * Amazon S3 storage provider.
 */
class SwiftSpeed_Siberian_Storage_S3 implements SwiftSpeed_Siberian_Storage_Interface {
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
     * Constructor.
     * 
     * @param array $config Optional configuration.
     */
    public function __construct($config = []) {
        $this->config = $config;
        $this->temp_dir = WP_CONTENT_DIR . '/swsib-backups/temp/';
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
                $this->log_message('Failed to create temporary directory: ' . $this->temp_dir);
                return new WP_Error('storage_init', __('Failed to create temporary directory', 'swiftspeed-siberian'));
            }
        }
        
        if (!$this->is_configured()) {
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('storage_init', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
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
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('upload_error', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Get file size to determine upload method
        $file_size = filesize($source_path);
        $this->log_message('Uploading file: ' . $source_path . ' to S3 (Size: ' . size_format($file_size, 2) . ')');
        
        // For large files (over 100MB), use chunked upload
        if ($file_size > 100 * 1024 * 1024) {
            $this->log_message('File size exceeds 100MB, using chunked upload');
            return $this->upload_file_chunked($source_path, $destination_path, $metadata);
        }
        
        // Ensure the S3 key has the correct prefix
        $s3_key = $this->prepare_s3_key($destination_path);
        
        // For smaller files, read the entire content
        $file_content = file_get_contents($source_path);
        if ($file_content === false) {
            $this->log_message('Failed to read source file: ' . $source_path);
            return new WP_Error('upload_error', __('Failed to read source file', 'swiftspeed-siberian'));
        }
        
        // Generate the S3 signature
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_type = 'application/zip';
        $content_sha256 = hash('sha256', $file_content);
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'Content-Type' => $content_type,
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "PUT\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $file_content,
            'timeout' => 120,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $this->log_message('Sending S3 PUT request for: ' . $s3_key);
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('S3 upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('S3 upload failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to upload file to Amazon S3. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Build the URL to the file
        $file_url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}";
        $this->log_message('File uploaded successfully to S3: ' . $file_url);
        
        return [
            'file' => basename($destination_path),
            's3_key' => $s3_key,
            'url' => $file_url,
            'size' => $file_size,
            'timestamp' => time(),
            'storage' => 's3',
        ];
    }
    
    /**
     * Upload a large file to S3 using multipart upload.
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
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('upload_error', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        $file_size = filesize($source_path);
        $s3_key = $this->prepare_s3_key($destination_path);
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        $this->log_message('Starting multipart upload for: ' . $s3_key . ' (Size: ' . size_format($file_size, 2) . ')');
        
        // Step 1: Initiate multipart upload
        $upload_id = $this->initiate_multipart_upload($s3_key);
        if (is_wp_error($upload_id)) {
            return $upload_id;
        }
        
        $this->log_message('Multipart upload initiated with ID: ' . $upload_id);
        
        // Step 2: Upload parts
        $chunk_size = 5 * 1024 * 1024; // 5MB chunk size (minimum for S3)
        $total_chunks = ceil($file_size / $chunk_size);
        $parts = [];
        
        $file_handle = fopen($source_path, 'rb');
        if (!$file_handle) {
            $this->log_message('Failed to open source file: ' . $source_path);
            // Abort the multipart upload
            $this->abort_multipart_upload($s3_key, $upload_id);
            return new WP_Error('upload_error', __('Failed to open source file', 'swiftspeed-siberian'));
        }
        
        for ($part_number = 1; $part_number <= $total_chunks; $part_number++) {
            $this->log_message('Uploading part ' . $part_number . ' of ' . $total_chunks);
            
            // Read chunk
            $chunk_data = fread($file_handle, $chunk_size);
            if ($chunk_data === false) {
                $this->log_message('Failed to read chunk ' . $part_number);
                fclose($file_handle);
                // Abort the multipart upload
                $this->abort_multipart_upload($s3_key, $upload_id);
                return new WP_Error('upload_error', __('Failed to read file chunk', 'swiftspeed-siberian'));
            }
            
            // Upload part
            $etag = $this->upload_part($s3_key, $upload_id, $part_number, $chunk_data);
            if (is_wp_error($etag)) {
                $this->log_message('Failed to upload part ' . $part_number . ': ' . $etag->get_error_message());
                fclose($file_handle);
                // Abort the multipart upload
                $this->abort_multipart_upload($s3_key, $upload_id);
                return $etag;
            }
            
            $parts[] = [
                'PartNumber' => $part_number,
                'ETag' => $etag,
            ];
            
            $this->log_message('Successfully uploaded part ' . $part_number . ' of ' . $total_chunks);
        }
        
        fclose($file_handle);
        
        // Step 3: Complete multipart upload
        $complete_result = $this->complete_multipart_upload($s3_key, $upload_id, $parts);
        if (is_wp_error($complete_result)) {
            $this->log_message('Failed to complete multipart upload: ' . $complete_result->get_error_message());
            // Abort the multipart upload
            $this->abort_multipart_upload($s3_key, $upload_id);
            return $complete_result;
        }
        
        // Build the URL to the file
        $file_url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}";
        $this->log_message('Multipart upload completed successfully: ' . $file_url);
        
        return [
            'file' => basename($destination_path),
            's3_key' => $s3_key,
            'url' => $file_url,
            'size' => $file_size,
            'timestamp' => time(),
            'storage' => 's3',
        ];
    }
    
    /**
     * Initiate a multipart upload to S3.
     * 
     * @param string $s3_key The S3 object key
     * @return string|WP_Error Upload ID on success, WP_Error on failure
     */
    private function initiate_multipart_upload($s3_key) {
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_type = 'application/zip';
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'Content-Type' => $content_type,
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "POST\n";
        $canonical_request .= "/{$s3_key}?uploads\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'POST',
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}?uploads",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Initiate multipart upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Initiate multipart upload failed: ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to initiate multipart upload. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Parse the response to get the upload ID
        $response_body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($response_body);
        
        if (!$xml || !isset($xml->UploadId)) {
            $this->log_message('Invalid response from S3 for multipart initiation');
            return new WP_Error('upload_error', __('Invalid response from S3 for multipart initiation', 'swiftspeed-siberian'));
        }
        
        return (string) $xml->UploadId;
    }
    
    /**
     * Upload a part in a multipart upload.
     * 
     * @param string $s3_key The S3 object key
     * @param string $upload_id The upload ID
     * @param int $part_number The part number (1-10000)
     * @param string $part_data The part data
     * @return string|WP_Error ETag on success, WP_Error on failure
     */
    private function upload_part($s3_key, $upload_id, $part_number, $part_data) {
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', $part_data);
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => strlen($part_data),
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $query_string = "partNumber={$part_number}&uploadId=" . urlencode($upload_id);
        $canonical_request = "PUT\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= $query_string . "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $part_data,
            'timeout' => 60,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}?{$query_string}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Upload part error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Upload part failed: ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to upload part. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        // Get the ETag from the response headers
        $etag = wp_remote_retrieve_header($response, 'etag');
        if (empty($etag)) {
            $this->log_message('No ETag in upload part response');
            return new WP_Error('upload_error', __('No ETag in upload part response', 'swiftspeed-siberian'));
        }
        
        // Remove quotes from ETag if present
        $etag = trim($etag, '"');
        
        return $etag;
    }
    
    /**
     * Complete a multipart upload.
     * 
     * @param string $s3_key The S3 object key
     * @param string $upload_id The upload ID
     * @param array $parts Array of parts with PartNumber and ETag
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function complete_multipart_upload($s3_key, $upload_id, $parts) {
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Create XML body with parts
        $xml_body = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $xml_body .= '<Part>';
            $xml_body .= '<PartNumber>' . $part['PartNumber'] . '</PartNumber>';
            $xml_body .= '<ETag>"' . $part['ETag'] . '"</ETag>';
            $xml_body .= '</Part>';
        }
        $xml_body .= '</CompleteMultipartUpload>';
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', $xml_body);
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'Content-Type' => 'application/xml',
            'Content-Length' => strlen($xml_body),
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $query_string = "uploadId=" . urlencode($upload_id);
        $canonical_request = "POST\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= $query_string . "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'POST',
            'headers' => $headers,
            'body' => $xml_body,
            'timeout' => 60,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}?{$query_string}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Complete multipart upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Complete multipart upload failed: ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to complete multipart upload. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        return true;
    }
    
    /**
     * Abort a multipart upload.
     * 
     * @param string $s3_key The S3 object key
     * @param string $upload_id The upload ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function abort_multipart_upload($s3_key, $upload_id) {
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $query_string = "uploadId=" . urlencode($upload_id);
        $canonical_request = "DELETE\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= $query_string . "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $this->log_message('Aborting multipart upload: ' . $upload_id);
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}?{$query_string}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Abort multipart upload error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Abort multipart upload failed: ' . $error_message);
            
            return new WP_Error(
                'upload_error',
                sprintf(
                    __('Failed to abort multipart upload. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('Multipart upload aborted successfully');
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function download_file($source_path, $destination_path) {
        if (!$this->is_configured()) {
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('download_error', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Ensure the S3 key has the correct prefix
        $s3_key = $this->prepare_s3_key($source_path);
        
        // Generate the S3 signature
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Create destination directory if it doesn't exist
        $destination_dir = dirname($destination_path);
        if (!file_exists($destination_dir)) {
            if (!wp_mkdir_p($destination_dir)) {
                $this->log_message('Failed to create destination directory: ' . $destination_dir);
                return new WP_Error('download_error', __('Failed to create destination directory', 'swiftspeed-siberian'));
            }
        }
        
        $this->log_message('Downloading file from S3: ' . $s3_key . ' to ' . $destination_path);
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "GET\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 300,
            'stream' => true,
            'filename' => $destination_path,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Download error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Download failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'download_error',
                sprintf(
                    __('Failed to download file from Amazon S3. Error: %s (HTTP Status: %s)', 'swiftspeed-siberian'),
                    $error_message,
                    $status_code
                )
            );
        }
        
        $this->log_message('File downloaded successfully from S3');
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function list_files($directory = '') {
        if (!$this->is_configured()) {
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('list_error', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Ensure the S3 prefix has the correct format
        $prefix = $this->prepare_s3_key($directory);
        $this->log_message('Listing files from S3 with prefix: ' . $prefix);
        
        // Generate the S3 signature
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Build the query string
        $query = [
            'list-type' => '2',
        ];
        
        if (!empty($prefix)) {
            $query['prefix'] = $prefix;
        }
        
        $query_string = http_build_query($query);
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "GET\n";
        $canonical_request .= "/\n";
        $canonical_request .= $query_string . "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/?" . $query_string,
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('List files error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('List files failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'list_error',
                sprintf(
                    __('Failed to list files from Amazon S3. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $xml = simplexml_load_string($response_body);
        
        if (!$xml) {
            $this->log_message('Failed to parse S3 response');
            return new WP_Error('list_error', __('Failed to parse S3 response', 'swiftspeed-siberian'));
        }
        
        $files = [];
        
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $key = (string) $content->Key;
                
                // Only include .zip files
                if (substr($key, -4) !== '.zip') {
                    continue;
                }
                
                $last_modified = strtotime((string) $content->LastModified);
                $size = (int) $content->Size;
                
                $files[] = [
                    'file' => basename($key),
                    's3_key' => $key,
                    'url' => "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}",
                    'size' => size_format($size, 2),
                    'bytes' => $size,
                    'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_modified),
                    'timestamp' => $last_modified,
                    'type' => 's3',
                ];
            }
        }
        
        $this->log_message('Found ' . count($files) . ' backup files in S3');
        return $files;
    }
    
    /**
     * {@inheritdoc}
     */
    public function delete_file($file_path) {
        if (!$this->is_configured()) {
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('delete_error', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        // Ensure the S3 key has the correct prefix
        $s3_key = $this->prepare_s3_key($file_path);
        $this->log_message('Deleting file from S3: ' . $s3_key);
        
        // Generate the S3 signature
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "DELETE\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}",
            $request_args
        );
        
        if (is_wp_error($response)) {
            $this->log_message('Delete file error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code < 200 || $status_code >= 300 && $status_code !== 404) {
            $response_body = wp_remote_retrieve_body($response);
            $error_message = __('Unknown error', 'swiftspeed-siberian');
            
            if (!empty($response_body)) {
                $xml = simplexml_load_string($response_body);
                if ($xml && isset($xml->Message)) {
                    $error_message = (string) $xml->Message;
                }
            }
            
            $this->log_message('Delete file failed with status code ' . $status_code . ': ' . $error_message);
            
            return new WP_Error(
                'delete_error',
                sprintf(
                    __('Failed to delete file from Amazon S3. Error: %s', 'swiftspeed-siberian'),
                    $error_message
                )
            );
        }
        
        $this->log_message('File deleted successfully from S3');
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function file_exists($file_path) {
        if (!$this->is_configured()) {
            return false;
        }
        
        // Ensure the S3 key has the correct prefix
        $s3_key = $this->prepare_s3_key($file_path);
        $this->log_message('Checking if file exists in S3: ' . $s3_key);
        
        // Generate the S3 signature
        $date = gmdate('Ymd\THis\Z');
        $short_date = gmdate('Ymd');
        
        $region = $this->config['region'];
        $bucket = $this->config['bucket'];
        $access_key = $this->config['access_key'];
        $secret_key = $this->config['secret_key'];
        
        // Prepare the canonical request
        $content_sha256 = hash('sha256', '');
        
        // Construct request headers
        $headers = [
            'Host' => "{$bucket}.s3.{$region}.amazonaws.com",
            'X-Amz-Content-Sha256' => $content_sha256,
            'X-Amz-Date' => $date,
        ];
        
        // Create canonical headers string
        $canonical_headers = '';
        $signed_headers = '';
        ksort($headers);
        
        foreach ($headers as $key => $value) {
            $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
            $signed_headers .= strtolower($key) . ';';
        }
        $signed_headers = rtrim($signed_headers, ';');
        
        // Create canonical request
        $canonical_request = "HEAD\n";
        $canonical_request .= "/{$s3_key}\n";
        $canonical_request .= "\n";
        $canonical_request .= $canonical_headers . "\n";
        $canonical_request .= $signed_headers . "\n";
        $canonical_request .= $content_sha256;
        
        // Create string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$short_date}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        
        // Calculate signature
        $k_date = hash_hmac('sha256', $short_date, "AWS4{$secret_key}", true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
        
        // Build the Authorization header
        $authorization = "{$algorithm} ";
        $authorization .= "Credential={$access_key}/{$credential_scope}, ";
        $authorization .= "SignedHeaders={$signed_headers}, ";
        $authorization .= "Signature={$signature}";
        
        // Set up request arguments
        $request_args = [
            'method' => 'HEAD',
            'headers' => $headers,
            'timeout' => 15,
        ];
        
        // Add the Authorization header
        $request_args['headers']['Authorization'] = $authorization;
        
        // Make the request
        $response = wp_remote_request(
            "https://{$bucket}.s3.{$region}.amazonaws.com/{$s3_key}",
            $request_args
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
        return __('Amazon S3', 'swiftspeed-siberian');
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_identifier() {
        return 's3';
    }
    
    /**
     * {@inheritdoc}
     */
    public function is_configured() {
        return !empty($this->config['access_key']) &&
               !empty($this->config['secret_key']) &&
               !empty($this->config['bucket']) &&
               !empty($this->config['region']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get_config_fields() {
        return [
            [
                'name' => 'access_key',
                'label' => __('Access Key ID', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Amazon S3 Access Key ID', 'swiftspeed-siberian'),
                'placeholder' => 'AKIAIOSFODNN7EXAMPLE',
                'required' => true,
            ],
            [
                'name' => 'secret_key',
                'label' => __('Secret Access Key', 'swiftspeed-siberian'),
                'type' => 'password',
                'description' => __('Amazon S3 Secret Access Key', 'swiftspeed-siberian'),
                'placeholder' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'required' => true,
            ],
            [
                'name' => 'bucket',
                'label' => __('Bucket Name', 'swiftspeed-siberian'),
                'type' => 'text',
                'description' => __('Amazon S3 Bucket Name', 'swiftspeed-siberian'),
                'placeholder' => 'my-bucket',
                'required' => true,
            ],
            [
                'name' => 'region',
                'label' => __('Region', 'swiftspeed-siberian'),
                'type' => 'select',
                'options' => [
                    'us-east-1' => 'US East (N. Virginia)',
                    'us-east-2' => 'US East (Ohio)',
                    'us-west-1' => 'US West (N. California)',
                    'us-west-2' => 'US West (Oregon)',
                    'af-south-1' => 'Africa (Cape Town)',
                    'ap-east-1' => 'Asia Pacific (Hong Kong)',
                    'ap-south-1' => 'Asia Pacific (Mumbai)',
                    'ap-northeast-1' => 'Asia Pacific (Tokyo)',
                    'ap-northeast-2' => 'Asia Pacific (Seoul)',
                    'ap-northeast-3' => 'Asia Pacific (Osaka-Local)',
                    'ap-southeast-1' => 'Asia Pacific (Singapore)',
                    'ap-southeast-2' => 'Asia Pacific (Sydney)',
                    'ca-central-1' => 'Canada (Central)',
                    'eu-central-1' => 'Europe (Frankfurt)',
                    'eu-west-1' => 'Europe (Ireland)',
                    'eu-west-2' => 'Europe (London)',
                    'eu-west-3' => 'Europe (Paris)',
                    'eu-north-1' => 'Europe (Stockholm)',
                    'eu-south-1' => 'Europe (Milan)',
                    'me-south-1' => 'Middle East (Bahrain)',
                    'sa-east-1' => 'South America (São Paulo)',
                ],
                'default' => 'us-east-1',
                'description' => __('Amazon S3 Region', 'swiftspeed-siberian'),
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
        
        // Access Key
        if (empty($config['access_key'])) {
            return new WP_Error('validate_config', __('Access Key ID is required', 'swiftspeed-siberian'));
        }
        $validated['access_key'] = sanitize_text_field($config['access_key']);
        
        // Secret Key
        if (empty($config['secret_key'])) {
            return new WP_Error('validate_config', __('Secret Access Key is required', 'swiftspeed-siberian'));
        }
        $validated['secret_key'] = sanitize_text_field($config['secret_key']);
        
        // Bucket
        if (empty($config['bucket'])) {
            return new WP_Error('validate_config', __('Bucket Name is required', 'swiftspeed-siberian'));
        }
        $validated['bucket'] = sanitize_text_field($config['bucket']);
        
        // Region
        if (empty($config['region'])) {
            return new WP_Error('validate_config', __('Region is required', 'swiftspeed-siberian'));
        }
        $validated['region'] = sanitize_text_field($config['region']);
        
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
            $this->log_message('Amazon S3 is not properly configured');
            return new WP_Error('test_connection', __('Amazon S3 is not properly configured', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Testing connection to Amazon S3');
        
        // Try to list the bucket contents to validate connection
        $response = $this->list_files();
        
        if (is_wp_error($response)) {
            $this->log_message('Connection test failed: ' . $response->get_error_message());
            return $response;
        }
        
        $this->log_message('Connection test successful');
        return true;
    }
    
    /**
     * Prepare an S3 key with the correct prefix.
     * 
     * @param string $key The key or path to prepare.
     * @return string The prepared S3 key.
     */
    private function prepare_s3_key($key) {
        $prefix = !empty($this->config['prefix']) ? $this->config['prefix'] : 'siberian-backups/';
        $prefix = rtrim($prefix, '/') . '/';
        
        // If the key already has the prefix, return it as is
        if (strpos($key, $prefix) === 0) {
            return $key;
        }
        
        // Remove any leading slashes
        $key = ltrim($key, '/');
        
        // If the key starts with 's3:', remove it
        if (strpos($key, 's3:') === 0) {
            $key = substr($key, 3);
        }
        
        // Combine the prefix and key
        return $prefix . $key;
    }
}