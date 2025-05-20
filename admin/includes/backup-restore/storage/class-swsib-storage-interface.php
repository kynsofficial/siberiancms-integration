<?php
/**
 * Storage Interface for Backup/Restore functionality.
 * Defines the contract for all storage providers.
 */
interface SwiftSpeed_Siberian_Storage_Interface {
    /**
     * Initialize the storage provider.
     * 
     * @return bool True on success, false on failure.
     */
    public function initialize();
    
    /**
     * Upload a file to the storage provider.
     * 
     * @param string $source_path Local path to the file to upload.
     * @param string $destination_path Path within the storage provider.
     * @param array $metadata Optional metadata for the file.
     * @return array|WP_Error Result array with file info or WP_Error on failure.
     */
    public function upload_file($source_path, $destination_path, $metadata = []);
    
    /**
     * Download a file from the storage provider.
     * 
     * @param string $source_path Path within the storage provider.
     * @param string $destination_path Local path to save the file.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function download_file($source_path, $destination_path);
    
    /**
     * List files in a directory in the storage provider.
     * 
     * @param string $directory Directory path within the storage provider.
     * @return array|WP_Error Array of file info or WP_Error on failure.
     */
    public function list_files($directory = '');
    
    /**
     * Delete a file from the storage provider.
     * 
     * @param string $file_path Path to the file within the storage provider.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function delete_file($file_path);
    
    /**
     * Check if a file exists in the storage provider.
     * 
     * @param string $file_path Path to the file within the storage provider.
     * @return bool True if the file exists, false otherwise.
     */
    public function file_exists($file_path);
    
    /**
     * Get the display name of the storage provider.
     * 
     * @return string The display name.
     */
    public function get_display_name();
    
    /**
     * Get the unique identifier of the storage provider.
     * 
     * @return string The identifier.
     */
    public function get_identifier();
    
    /**
     * Check if the storage provider is properly configured.
     * 
     * @return bool True if configured, false otherwise.
     */
    public function is_configured();
    
    /**
     * Get configuration form fields for the admin UI.
     * 
     * @return array Array of form fields.
     */
    public function get_config_fields();
    
    /**
     * Validate configuration values.
     * 
     * @param array $config Configuration values.
     * @return array|WP_Error Validated config or WP_Error on validation failure.
     */
    public function validate_config($config);
    
    /**
     * Test the connection to the storage provider.
     * 
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function test_connection();
}