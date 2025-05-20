<?php
/**
 * Local Connection Handler for Siberian CMS integration
 * 
 * Handles all local filesystem connection operations
 */
class SwiftSpeed_Siberian_Local_Connection {
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Initialize the class
     */
    public function __construct($options) {
        $this->options = $options;
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('local_connect', 'backend', $message);
        }
    }
    
    /**
     * Test local filesystem path
     */
    public function test_connection($params) {
        $path = isset($params['path_local']) ? rtrim(sanitize_text_field($params['path_local']), '/') : '/';
        $this->log_message('Testing local path: ' . $path);
        
        if (!is_dir($path)) {
            // If provided path doesn't exist, try WordPress root directory
            $path = ABSPATH;
            if (!is_dir($path)) {
                $this->log_message('Path does not exist: ' . $path);
                return array(
                    'success' => false,
                    'message' => __('The specified path does not exist or is not a directory', 'swiftspeed-siberian')
                );
            }
        }
        
        $this->log_message('Directory exists: ' . $path);
        
        // Check if directory is readable
        if (!is_readable($path)) {
            $this->log_message('Directory is not readable: ' . $path);
            return array(
                'success' => false,
                'message' => __('The directory exists but is not readable by the web server', 'swiftspeed-siberian')
            );
        }
        
        // Get directory contents
        $contents = scandir($path);
        $directories = array();
        $files = array();
        
        foreach ($contents as $item) {
            if ($item !== '.' && $item !== '..') {
                $full_path = $path . '/' . $item;
                if (is_dir($full_path)) {
                    $directories[] = array(
                        'name' => $item,
                        'path' => $full_path
                    );
                } else {
                    $files[] = array(
                        'name' => $item,
                        'path' => $full_path
                    );
                }
            }
        }
        
        // Add parent directory if not at root
        if ($path !== '/' && $path !== DIRECTORY_SEPARATOR) {
            $parent_path = dirname($path);
            if ($parent_path !== $path) {
                array_unshift($directories, array(
                    'name' => '..',
                    'path' => $parent_path,
                    'is_parent' => true
                ));
            }
        }
        
        return array(
            'success' => true,
            'message' => __('Local filesystem access successful!', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $files,
            'path' => $path
        );
    }
    
    /**
     * Browse local directory
     */
    public function browse_directory($params) {
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Browsing local directory: ' . $path);
        
        if (!is_dir($path)) {
            return array(
                'success' => false,
                'message' => __('The specified path does not exist or is not a directory', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        if (!is_readable($path)) {
            return array(
                'success' => false,
                'message' => __('The directory is not readable', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        $contents = scandir($path);
        $directories = array();
        $files = array();
        
        foreach ($contents as $item) {
            if ($item !== '.' && $item !== '..') {
                $full_path = rtrim($path, '/') . '/' . $item;
                if (is_dir($full_path)) {
                    $directories[] = array(
                        'name' => $item,
                        'path' => $full_path
                    );
                } else {
                    // Get file details
                    $file_size = filesize($full_path);
                    $file_modified = filemtime($full_path);
                    $file_perms = substr(sprintf('%o', fileperms($full_path)), -4);
                    
                    $files[] = array(
                        'name' => $item,
                        'path' => $full_path,
                        'size' => swsib_format_file_size($file_size),
                        'modified' => date('Y-m-d H:i:s', $file_modified),
                        'permissions' => $file_perms
                    );
                }
            }
        }
        
        // Add parent directory if not at root
        if ($path !== '/' && $path !== DIRECTORY_SEPARATOR) {
            $parent_path = dirname($path);
            if ($parent_path !== $path) {
                array_unshift($directories, array(
                    'name' => '..',
                    'path' => $parent_path,
                    'is_parent' => true
                ));
            }
        }
        
        $this->log_message("Local directory contents: " . count($directories) . " directories, " . count($files) . " files");
        
        return array(
            'success' => true,
            'message' => __('Directory listed successfully', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $files,
            'path' => $path
        );
    }
    
    /**
     * Verify local directory for Siberian installation
     */
    public function verify_installation($params) {
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Verifying Siberian installation at local path: ' . $path);
        
        // Define required Siberian indicators
        $required_indicators = array(
            'index.php' => 'Main index file',
            'app' => 'Application directory',
            'var' => 'Variable data directory',
            'lib' => 'Library directory',
            'app/configs/app.ini' => 'Configuration file'
        );
        
        $found_indicators = array();
        $missing_indicators = array();
        
        if (!is_dir($path) || !is_readable($path)) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Check for index.php
        if (file_exists($path . '/index.php')) {
            $found_indicators[] = 'index.php';
        }
        
        // Check for app, var, and lib directories
        foreach (array('app', 'var', 'lib') as $dir) {
            $dir_path = rtrim($path, '/') . '/' . $dir;
            if (is_dir($dir_path)) {
                $found_indicators[] = $dir;
            }
        }
        
        // Check for app.ini
        if (in_array('app', $found_indicators)) {
            $app_ini_path = rtrim($path, '/') . '/app/configs/app.ini';
            if (file_exists($app_ini_path)) {
                $found_indicators[] = 'app/configs/app.ini';
            }
        }
        
        // Determine missing indicators
        foreach (array_keys($required_indicators) as $indicator) {
            if (!in_array($indicator, $found_indicators)) {
                $missing_indicators[] = $indicator;
            }
        }
        
        // Determine if this is a valid Siberian installation (needs at least index.php and 3 more components)
        $is_siberian = (count($found_indicators) >= 4) && in_array('index.php', $found_indicators);
        
        $this->log_message('Siberian verification result: ' . ($is_siberian ? 'Valid' : 'Invalid') . 
                         ', Found: ' . implode(', ', $found_indicators) . 
                         ', Missing: ' . implode(', ', $missing_indicators));
        
        return array(
            'is_siberian' => $is_siberian,
            'found' => $found_indicators,
            'missing' => $missing_indicators
        );
    }
    
    /**
     * Get file contents from local filesystem
     */
    public function get_file_contents($params) {
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Getting file contents from local path: ' . $path);
        
        // Check if file exists
        if (!file_exists($path)) {
            return array(
                'success' => false,
                'message' => 'File does not exist'
            );
        }
        
        // Check if file is readable
        if (!is_readable($path)) {
            return array(
                'success' => false,
                'message' => 'File is not readable'
            );
        }
        
        // Get file size
        $file_size = filesize($path);
        
        // Get maximum allowed size (default to 5MB)
        $max_size = apply_filters('swsib_max_file_size', 5 * 1024 * 1024);
        
        // Check if file is too large
        if ($file_size > $max_size) {
            return array(
                'success' => false,
                'message' => sprintf('File is too large (%s). Maximum allowed size is %s.', 
                             swsib_format_file_size($file_size), 
                             swsib_format_file_size($max_size))
            );
        }
        
        // Read file contents
        $contents = file_get_contents($path);
        
        // Check file extension
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        // Determine if this is a binary/image file
        $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        
        if (in_array($ext, $image_extensions)) {
            // For images, encode as base64
            $contents = base64_encode($contents);
        } else {
            // For text files, check if content is not binary
            if (!swsib_is_binary_content($contents)) {
                // For text files, limit content size if too large
                if (strlen($contents) > $max_size) {
                    $contents = substr($contents, 0, $max_size) . 
                                "\n\n... (file truncated, too large to display completely)";
                }
            } else {
                // Binary content that isn't a recognized image
                return array(
                    'success' => false,
                    'message' => 'This file type cannot be previewed (binary content)'
                );
            }
        }
        
        return array(
            'success' => true,
            'contents' => $contents,
            'size' => $file_size
        );
    }
    
    /**
     * Delete file or directory from local filesystem
     */
    public function delete_file($params) {
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'file';
        
        $this->log_message('Deleting ' . $type . ' from local path: ' . $path);
        
        // Check if file/directory exists
        if (!file_exists($path)) {
            return array(
                'success' => false,
                'message' => 'File or directory does not exist'
            );
        }
        
        // Check if file/directory is writable
        if (!is_writable($path)) {
            return array(
                'success' => false,
                'message' => 'File or directory is not writable'
            );
        }
        
        // Perform delete operation
        if ($type === 'file') {
            // Delete file
            $delete = @unlink($path);
            
            if (!$delete) {
                return array(
                    'success' => false,
                    'message' => 'Failed to delete file'
                );
            }
            
            return array(
                'success' => true,
                'message' => 'File deleted successfully'
            );
        } else {
            // Delete directory recursively
            $result = $this->recursive_rmdir($path);
            
            if ($result) {
                return array(
                    'success' => true,
                    'message' => 'Directory deleted successfully'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Failed to delete directory'
                );
            }
        }
    }
    
    /**
     * Recursive directory deletion for local filesystem
     */
    private function recursive_rmdir($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $objects = scandir($dir);
        
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }
            
            $path = $dir . '/' . $object;
            
            if (is_dir($path)) {
                $this->recursive_rmdir($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * Download file from local filesystem
     */
    public function download_file($params) {
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Downloading file from local path: ' . $path);
        
        // Check if file exists
        if (!file_exists($path)) {
            wp_die('File does not exist');
        }
        
        // Check if file is readable
        if (!is_readable($path)) {
            wp_die('File is not readable');
        }
        
        // Get file info
        $file_name = basename($path);
        $file_size = filesize($path);
        $file_type = mime_content_type($path);
        
        // Send file to browser
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $file_type);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Clear output buffer
        ob_clean();
        flush();
        
        // Output file
        readfile($path);
        
        // Exit
        exit;
    }
}