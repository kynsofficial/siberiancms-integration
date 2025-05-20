<?php
/**
 * FTP Connection Handler for Siberian CMS integration
 * 
 * Handles all FTP-specific connection operations
 */
class SwiftSpeed_Siberian_FTP_Connection {
    
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
            swsib()->logging->write_to_log('ftp_connect', 'backend', $message);
        }
    }
    
    /**
     * Test FTP connection
     */
    public function test_connection($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path_ftp']) ? sanitize_text_field($params['path_ftp']) : '/';
        
        $this->log_message('Starting FTP connection test to ' . $host . ':' . $port);
        
        if (!function_exists('ftp_connect')) {
            $this->log_message('FTP functions are not available on this server');
            return array(
                'success' => false,
                'message' => __('FTP functions are not available on this server', 'swiftspeed-siberian')
            );
        }
        
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            $this->log_message('Could not connect to FTP server ' . $host . ':' . $port);
            return array(
                'success' => false,
                'message' => __('Could not connect to FTP server', 'swiftspeed-siberian')
            );
        }
        
        $this->log_message('FTP server connection established, attempting login with username: ' . $username);
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            $this->log_message('FTP login failed for user ' . $username);
            return array(
                'success' => false,
                'message' => __('FTP login failed', 'swiftspeed-siberian')
            );
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Use root directory to start
        $path_to_use = '/';
        $chdir = @ftp_chdir($ftp_conn, $path_to_use);
        
        if (!$chdir) {
            // If root doesn't work, try current directory
            $path_to_use = '.';
        }
        
        // If a specific path was provided, try to change to it
        if (!empty($path) && $path != '/' && $path != '.') {
            $chdir = @ftp_chdir($ftp_conn, $path);
            if ($chdir) {
                $path_to_use = $path;
            } else {
                $this->log_message('Could not access specified path: ' . $path . '. Using ' . $path_to_use . ' instead.');
            }
        }
        
        // List files in whatever directory we successfully accessed
        $this->log_message('Successfully accessed directory: ' . $path_to_use);
        $files = @ftp_nlist($ftp_conn, '.');
        
        if (!$files) {
            ftp_close($ftp_conn);
            $this->log_message('Could not list files in the directory');
            return array(
                'success' => false,
                'message' => __('Could not list files in the directory', 'swiftspeed-siberian')
            );
        }
        
        // Separately track directories and files
        $directories = array();
        $file_list = array();
        
        // Try to identify directories
        foreach ($files as $item) {
            // Skip . and .. entries
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            // Try to determine if this is a directory
            $is_dir = false;
            
            // Method 1: Check if we can change into it
            $curr_dir = ftp_pwd($ftp_conn); // Get current directory
            if (@ftp_chdir($ftp_conn, $item)) {
                $is_dir = true;
                @ftp_chdir($ftp_conn, $curr_dir); // Change back
            }
            
            if ($is_dir) {
                $path_separator = $path_to_use === '/' ? '' : '/';
                $directories[] = array(
                    'name' => $item,
                    'path' => $path_to_use . $path_separator . $item
                );
            } else {
                $path_separator = $path_to_use === '/' ? '' : '/';
                $file_list[] = array(
                    'name' => $item,
                    'path' => $path_to_use . $path_separator . $item
                );
            }
        }
        
        // Add parent directory if not at root
        if ($path_to_use !== '/' && $path_to_use !== '') {
            $parent_path = dirname($path_to_use);
            if ($parent_path == '\\' || $parent_path == '.') {
                $parent_path = '/';
            }
            
            array_unshift($directories, array(
                'name' => '..',
                'path' => $parent_path,
                'is_parent' => true
            ));
        }
        
        ftp_close($ftp_conn);
        
        return array(
            'success' => true,
            'message' => __('FTP connection successful!', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $file_list,
            'path' => $path_to_use
        );
    }
    
    /**
     * Browse directory via FTP
     */
    public function browse_directory($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Browsing FTP directory: ' . $path);
        
        if (!function_exists('ftp_connect')) {
            return array(
                'success' => false,
                'message' => __('FTP functions not available', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            return array(
                'success' => false,
                'message' => __('Could not connect to FTP server', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => __('FTP login failed', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Change to directory
        $chdir = @ftp_chdir($ftp_conn, $path);
        if (!$chdir) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => __('Could not access the specified directory', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // List files in current directory
        $files = @ftp_nlist($ftp_conn, '.');
        
        if (!$files) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => __('Could not list files in the directory', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Separately track directories and files
        $directories = array();
        $file_list = array();
        
        // Try to identify directories
        foreach ($files as $item) {
            // Skip . and .. entries
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            // Try to determine if this is a directory
            $is_dir = false;
            
            // Method 1: Check if we can change into it
            $curr_dir = ftp_pwd($ftp_conn); // Get current directory
            if (@ftp_chdir($ftp_conn, $item)) {
                $is_dir = true;
                @ftp_chdir($ftp_conn, $curr_dir); // Change back
            }
            
            if ($is_dir) {
                $path_separator = $path === '/' ? '' : '/';
                $directories[] = array(
                    'name' => $item,
                    'path' => $path . $path_separator . $item
                );
            } else {
                $path_separator = $path === '/' ? '' : '/';
                
                // Try to get file size and modification time if possible
                $file_size = @ftp_size($ftp_conn, $item);
                $file_size_formatted = ($file_size >= 0) ? swsib_format_file_size($file_size) : '-';
                
                $modified_time = @ftp_mdtm($ftp_conn, $item);
                $modified_formatted = ($modified_time != -1) ? date('Y-m-d H:i:s', $modified_time) : '-';
                
                $file_list[] = array(
                    'name' => $item,
                    'path' => $path . $path_separator . $item,
                    'size' => $file_size_formatted,
                    'modified' => $modified_formatted,
                    'permissions' => '-'  // FTP doesn't easily provide permissions in a standard format
                );
            }
        }
        
        // Add parent directory if not at root
        if ($path !== '/' && $path !== '') {
            $parent_path = dirname($path);
            if ($parent_path == '\\' || $parent_path == '.') {
                $parent_path = '/';
            }
            
            array_unshift($directories, array(
                'name' => '..',
                'path' => $parent_path,
                'is_parent' => true
            ));
        }
        
        ftp_close($ftp_conn);
        
        $this->log_message("FTP directory contents: " . count($directories) . " directories, " . count($file_list) . " files");
        
        return array(
            'success' => true,
            'message' => __('Directory listed successfully', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $file_list,
            'path' => $path
        );
    }
    
    /**
     * Verify FTP directory for Siberian installation
     */
    public function verify_installation($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Verifying Siberian installation at FTP path: ' . $path);
        
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
        
        // Connect to FTP
        if (!function_exists('ftp_connect')) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Change to directory
        $chdir = @ftp_chdir($ftp_conn, $path);
        if (!$chdir) {
            ftp_close($ftp_conn);
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // List files in current directory to check for index.php and directories
        $files = @ftp_nlist($ftp_conn, '.');
        if ($files) {
            foreach ($files as $file) {
                if ($file == 'index.php') {
                    $found_indicators[] = 'index.php';
                }
                
                // Check for app, var, and lib directories
                if (in_array($file, array('app', 'var', 'lib'))) {
                    // Try to change into the directory to confirm it's actually a directory
                    $curr_dir = ftp_pwd($ftp_conn);
                    if (@ftp_chdir($ftp_conn, $file)) {
                        $found_indicators[] = $file;
                        
                        // If we found app directory, check for app.ini
                        if ($file === 'app') {
                            if (@ftp_chdir($ftp_conn, 'configs')) {
                                $config_files = @ftp_nlist($ftp_conn, '.');
                                if ($config_files && in_array('app.ini', $config_files)) {
                                    $found_indicators[] = 'app/configs/app.ini';
                                }
                            }
                        }
                        
                        ftp_chdir($ftp_conn, $curr_dir); // Change back
                    }
                }
            }
        }
        
        ftp_close($ftp_conn);
        
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
     * Get file contents via FTP
     */
    public function get_file_contents($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Getting file contents from FTP path: ' . $path);
        
        // Check if FTP functions are available
        if (!function_exists('ftp_connect')) {
            return array(
                'success' => false,
                'message' => 'FTP functions are not available on this server'
            );
        }
        
        // Connect to FTP
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            return array(
                'success' => false,
                'message' => 'Could not connect to FTP server'
            );
        }
        
        // Login
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => 'FTP login failed'
            );
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Create temporary file to store downloaded content
        $temp_file = wp_tempnam('swsib_ftp_');
        
        // Get file size
        $file_size = ftp_size($ftp_conn, $path);
        
        // Get maximum allowed size (default to 5MB)
        $max_size = apply_filters('swsib_max_file_size', 5 * 1024 * 1024);
        
        // Check if file is too large
        if ($file_size > $max_size) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => sprintf('File is too large (%s). Maximum allowed size is %s.', 
                             swsib_format_file_size($file_size), 
                             swsib_format_file_size($max_size))
            );
        }
        
        // Download file to temporary location
        $download = @ftp_get($ftp_conn, $temp_file, $path, FTP_BINARY);
        
        // Close FTP connection
        ftp_close($ftp_conn);
        
        if (!$download) {
            @unlink($temp_file);
            return array(
                'success' => false,
                'message' => 'Failed to download file from FTP server'
            );
        }
        
        // Read file contents
        $contents = file_get_contents($temp_file);
        
        // Delete temporary file
        @unlink($temp_file);
        
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
     * Delete file or directory via FTP
     */
    public function delete_file($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'file';
        
        $this->log_message('Deleting ' . $type . ' from FTP path: ' . $path);
        
        // Check if FTP functions are available
        if (!function_exists('ftp_connect')) {
            return array(
                'success' => false,
                'message' => 'FTP functions are not available on this server'
            );
        }
        
        // Connect to FTP
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            return array(
                'success' => false,
                'message' => 'Could not connect to FTP server'
            );
        }
        
        // Login
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            return array(
                'success' => false,
                'message' => 'FTP login failed'
            );
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Perform delete operation
        if ($type === 'file') {
            // Delete file
            $delete = @ftp_delete($ftp_conn, $path);
            
            if (!$delete) {
                ftp_close($ftp_conn);
                return array(
                    'success' => false,
                    'message' => 'Failed to delete file'
                );
            }
            
            ftp_close($ftp_conn);
            return array(
                'success' => true,
                'message' => 'File deleted successfully'
            );
        } else {
            // Delete directory recursively
            $result = $this->ftp_delete_dir($ftp_conn, $path);
            ftp_close($ftp_conn);
            
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
     * Recursive directory deletion via FTP
     */
    private function ftp_delete_dir($ftp_conn, $directory) {
        // Get directory listing
        $contents = ftp_nlist($ftp_conn, $directory);
        
        // If listing not successful, try rawlist
        if ($contents === false) {
            return false;
        }
        
        // Loop through contents
        foreach ($contents as $item) {
            // Skip . and .. entries
            if ($item === '.' || $item === '..' || $item === $directory . '/.' || $item === $directory . '/..') {
                continue;
            }
            
            // Check if item is a directory
            $is_dir = false;
            $current_dir = ftp_pwd($ftp_conn);
            
            if (@ftp_chdir($ftp_conn, $item)) {
                $is_dir = true;
                ftp_chdir($ftp_conn, $current_dir);
            }
            
            if ($is_dir) {
                // Recursively delete directory
                $this->ftp_delete_dir($ftp_conn, $item);
            } else {
                // Delete file
                ftp_delete($ftp_conn, $item);
            }
        }
        
        // Delete the directory itself
        return @ftp_rmdir($ftp_conn, $directory);
    }
    
    /**
     * Download file via FTP
     */
    public function download_file($params) {
        $host = isset($params['host_ftp']) ? sanitize_text_field($params['host_ftp']) : '';
        $username = isset($params['username_ftp']) ? sanitize_text_field($params['username_ftp']) : '';
        $password = isset($params['password_ftp']) ? $params['password_ftp'] : '';
        $port = isset($params['port_ftp']) ? intval($params['port_ftp']) : 21;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Downloading file from FTP path: ' . $path);
        
        // Check if FTP functions are available
        if (!function_exists('ftp_connect')) {
            wp_die('FTP functions are not available on this server');
        }
        
        // Connect to FTP
        $ftp_conn = @ftp_connect($host, $port, 10);
        if (!$ftp_conn) {
            wp_die('Could not connect to FTP server');
        }
        
        // Login
        $login = @ftp_login($ftp_conn, $username, $password);
        if (!$login) {
            ftp_close($ftp_conn);
            wp_die('FTP login failed');
        }
        
        // Set passive mode
        ftp_pasv($ftp_conn, true);
        
        // Create temporary file to store downloaded content
        $temp_file = wp_tempnam('swsib_download_');
        
        // Download file to temporary location
        $download = @ftp_get($ftp_conn, $temp_file, $path, FTP_BINARY);
        
        // Close FTP connection
        ftp_close($ftp_conn);
        
        if (!$download) {
            @unlink($temp_file);
            wp_die('Failed to download file from FTP server');
        }
        
        // Get file info
        $file_name = basename($path);
        $file_size = filesize($temp_file);
        $file_type = mime_content_type($temp_file);
        
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
        readfile($temp_file);
        
        // Delete temporary file
        @unlink($temp_file);
        
        // Exit
        exit;
    }
}