<?php
/**
 * SFTP Connection Handler for Siberian CMS integration
 * 
 * Handles all SFTP-specific connection operations with fallback to phpseclib
 */
class SwiftSpeed_Siberian_SFTP_Connection {
    
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
            swsib()->logging->write_to_log('sftp', 'backend', $message);
        }
    }
    
   /**
     * Check if SFTP is available (either via SSH2 extension or phpseclib)
     */
    private function is_sftp_available() {
        if (extension_loaded('ssh2')) {
            $this->log_message('Using native SSH2 extension for SFTP connections');
            return 'ssh2';
        } elseif (class_exists('\phpseclib3\Net\SFTP')) {
            $this->log_message('Native SSH2 extension not available, using phpseclib fallback for SFTP connections');
            return 'phpseclib';
        }
        $this->log_message('No SFTP capability available - SSH2 extension not loaded and phpseclib not found');
        return false;
    }

 /**
     * Get SFTP availability status for admin UI
     */
    public function get_sftp_status() {
        $status = array(
            'available' => false,
            'method' => 'none',
            'extension_loaded' => extension_loaded('ssh2'),
            'phpseclib_available' => class_exists('\phpseclib3\Net\SFTP')
        );
        
        if (extension_loaded('ssh2')) {
            $status['available'] = true;
            $status['method'] = 'ssh2';
        } elseif (class_exists('\phpseclib3\Net\SFTP')) {
            $status['available'] = true;
            $status['method'] = 'phpseclib';
        }
        
        return $status;
    }
    
    
    /**
     * Test SFTP connection
     */
    public function test_connection($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path_sftp']) ? sanitize_text_field($params['path_sftp']) : '/';
        
        $this->log_message('Starting SFTP connection test to ' . $host . ':' . $port);
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            $this->log_message('No SFTP capability available (SSH2 extension not loaded and phpseclib not found)');
            return array(
                'success' => false,
                'message' => __('SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.', 'swiftspeed-siberian')
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->test_connection_ssh2($host, $username, $password, $port, $path);
        }
        // Fallback to phpseclib
        else {
            return $this->test_connection_phpseclib($host, $username, $password, $port, $path);
        }
    }
    
    /**
     * Test SFTP connection using SSH2 extension
     */
    private function test_connection_ssh2($host, $username, $password, $port, $path) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            $this->log_message('Could not connect to SFTP server ' . $host . ':' . $port);
            return array(
                'success' => false,
                'message' => __('Could not connect to SFTP server', 'swiftspeed-siberian')
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            $this->log_message('SFTP authentication failed for user ' . $username);
            return array(
                'success' => false,
                'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            $this->log_message('Could not initialize SFTP subsystem');
            return array(
                'success' => false,
                'message' => __('Could not initialize SFTP subsystem', 'swiftspeed-siberian')
            );
        }
        
        // Use root directory to start
        $path_to_use = '/';
        
        // If a specific path was provided, try to access it
        if (!empty($path) && $path != '/' && $path != '.') {
            if (@is_dir("ssh2.sftp://$sftp" . $path)) {
                $path_to_use = $path;
            } else {
                $this->log_message('Could not access specified path: ' . $path . '. Using ' . $path_to_use . ' instead.');
            }
        }
        
        // Try to open the directory for reading
        $sftp_dir = @opendir("ssh2.sftp://$sftp" . $path_to_use);
        
        if (!$sftp_dir) {
            $this->log_message('Could not open directory: ' . $path_to_use);
            return array(
                'success' => false,
                'message' => __('Could not access the specified directory', 'swiftspeed-siberian')
            );
        }
        
        // Read the directory contents
        $directories = array();
        $file_list = array();
        
        while (($file = readdir($sftp_dir)) !== false) {
            // Skip . and .. entries
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $path_to_use;
            if ($full_path !== '/') {
                $full_path .= '/';
            }
            $full_path .= $file;
            
            if (@is_dir("ssh2.sftp://$sftp" . $full_path)) {
                $directories[] = array(
                    'name' => $file,
                    'path' => $full_path
                );
            } else {
                $file_list[] = array(
                    'name' => $file,
                    'path' => $full_path
                );
            }
        }
        
        closedir($sftp_dir);
        
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
        
        return array(
            'success' => true,
            'message' => __('SFTP connection successful!', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $file_list,
            'path' => $path_to_use
        );
    }
    
    /**
     * Test SFTP connection using phpseclib
     */
    private function test_connection_phpseclib($host, $username, $password, $port, $path) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                $this->log_message('SFTP authentication failed for user ' . $username);
                return array(
                    'success' => false,
                    'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
                );
            }
            
            // Use root directory to start
            $path_to_use = '/';
            
            // If a specific path was provided, try to access it
            if (!empty($path) && $path != '/' && $path != '.') {
                if ($sftp->is_dir($path)) {
                    $path_to_use = $path;
                } else {
                    $this->log_message('Could not access specified path: ' . $path . '. Using ' . $path_to_use . ' instead.');
                }
            }
            
            // List directory contents
            $list = $sftp->nlist($path_to_use);
            
            if ($list === false) {
                $this->log_message('Could not list directory contents');
                return array(
                    'success' => false,
                    'message' => __('Could not list directory contents', 'swiftspeed-siberian')
                );
            }
            
            // Process directory list
            $directories = array();
            $file_list = array();
            
            foreach ($list as $file) {
                // Skip . and .. entries
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $full_path = $path_to_use;
                if ($full_path !== '/') {
                    $full_path .= '/';
                }
                $full_path .= $file;
                
                if ($sftp->is_dir($full_path)) {
                    $directories[] = array(
                        'name' => $file,
                        'path' => $full_path
                    );
                } else {
                    $file_list[] = array(
                        'name' => $file,
                        'path' => $full_path
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
            
            return array(
                'success' => true,
                'message' => __('SFTP connection successful (using phpseclib)!', 'swiftspeed-siberian'),
                'directories' => $directories,
                'files' => $file_list,
                'path' => $path_to_use
            );
        } catch (\Exception $e) {
            $this->log_message('SFTP error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => __('SFTP error: ', 'swiftspeed-siberian') . $e->getMessage()
            );
        }
    }
    
    /**
     * Browse directory via SFTP
     */
    public function browse_directory($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Browsing SFTP directory: ' . $path);
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            return array(
                'success' => false,
                'message' => __('SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->browse_directory_ssh2($host, $username, $password, $port, $path);
        }
        // Fallback to phpseclib
        else {
            return $this->browse_directory_phpseclib($host, $username, $password, $port, $path);
        }
    }
    
    /**
     * Browse directory using SSH2 extension
     */
    private function browse_directory_ssh2($host, $username, $password, $port, $path) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            return array(
                'success' => false,
                'message' => __('Could not connect to SFTP server', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            return array(
                'success' => false,
                'message' => __('SFTP authentication failed', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            return array(
                'success' => false,
                'message' => __('Could not initialize SFTP subsystem', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Try to open the directory for reading
        $sftp_dir = @opendir("ssh2.sftp://$sftp" . $path);
        
        if (!$sftp_dir) {
            return array(
                'success' => false,
                'message' => __('Could not access the specified directory', 'swiftspeed-siberian'),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
        
        // Read the directory contents
        $directories = array();
        $file_list = array();
        
        while (($file = readdir($sftp_dir)) !== false) {
            // Skip . and .. entries
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $path;
            if ($full_path !== '/') {
                $full_path .= '/';
            }
            $full_path .= $file;
            
            if (@is_dir("ssh2.sftp://$sftp" . $full_path)) {
                $directories[] = array(
                    'name' => $file,
                    'path' => $full_path
                );
            } else {
                // Get file stats if possible
                $stats = @ssh2_sftp_stat($sftp, $full_path);
                $file_size = isset($stats['size']) ? swsib_format_file_size($stats['size']) : '-';
                $modified = isset($stats['mtime']) ? date('Y-m-d H:i:s', $stats['mtime']) : '-';
                $permissions = isset($stats['mode']) ? substr(sprintf('%o', $stats['mode']), -4) : '-';
                
                $file_list[] = array(
                    'name' => $file,
                    'path' => $full_path,
                    'size' => $file_size,
                    'modified' => $modified,
                    'permissions' => $permissions
                );
            }
        }
        
        closedir($sftp_dir);
        
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
        
        $this->log_message("SFTP directory contents: " . count($directories) . " directories, " . count($file_list) . " files");
        
        return array(
            'success' => true,
            'message' => __('Directory listed successfully', 'swiftspeed-siberian'),
            'directories' => $directories,
            'files' => $file_list,
            'path' => $path
        );
    }
    
    /**
     * Browse directory using phpseclib
     */
    private function browse_directory_phpseclib($host, $username, $password, $port, $path) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                return array(
                    'success' => false,
                    'message' => __('SFTP authentication failed', 'swiftspeed-siberian'),
                    'directories' => array(),
                    'files' => array(),
                    'path' => $path
                );
            }
            
            // List directory contents
            $list = $sftp->nlist($path);
            $list_long = $sftp->rawlist($path);
            
            if ($list === false) {
                return array(
                    'success' => false,
                    'message' => __('Could not list directory contents', 'swiftspeed-siberian'),
                    'directories' => array(),
                    'files' => array(),
                    'path' => $path
                );
            }
            
            // Process directory list
            $directories = array();
            $file_list = array();
            
            foreach ($list as $file) {
                // Skip . and .. entries
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $full_path = $path;
                if ($full_path !== '/') {
                    $full_path .= '/';
                }
                $full_path .= $file;
                
                if ($sftp->is_dir($full_path)) {
                    $directories[] = array(
                        'name' => $file,
                        'path' => $full_path
                    );
                } else {
                    // Get detailed file information
                    $file_info = isset($list_long[$file]) ? $list_long[$file] : array();
                    $size = isset($file_info['size']) ? swsib_format_file_size($file_info['size']) : '-';
                    $modified = isset($file_info['mtime']) ? date('Y-m-d H:i:s', $file_info['mtime']) : '-';
                    $permissions = isset($file_info['permissions']) ? $file_info['permissions'] : '-';
                    
                    $file_list[] = array(
                        'name' => $file,
                        'path' => $full_path,
                        'size' => $size,
                        'modified' => $modified,
                        'permissions' => $permissions
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
            
            $this->log_message("SFTP directory contents (phpseclib): " . count($directories) . " directories, " . count($file_list) . " files");
            
            return array(
                'success' => true,
                'message' => __('Directory listed successfully', 'swiftspeed-siberian'),
                'directories' => $directories,
                'files' => $file_list,
                'path' => $path
            );
        } catch (\Exception $e) {
            $this->log_message('SFTP error: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => __('SFTP error: ', 'swiftspeed-siberian') . $e->getMessage(),
                'directories' => array(),
                'files' => array(),
                'path' => $path
            );
        }
    }
    
    /**
     * Verify SFTP directory for Siberian installation
     */
    public function verify_installation($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '/';
        
        $this->log_message('Verifying Siberian installation at SFTP path: ' . $path);
        
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
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->verify_installation_ssh2($host, $username, $password, $port, $path, $required_indicators);
        }
        // Fallback to phpseclib
        else {
            return $this->verify_installation_phpseclib($host, $username, $password, $port, $path, $required_indicators);
        }
    }
    
    /**
     * Verify installation using SSH2 extension
     */
    private function verify_installation_ssh2($host, $username, $password, $port, $path, $required_indicators) {
        $found_indicators = array();
        $missing_indicators = array();
        
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators)
            );
        }
        
        // Check for index.php
        if (file_exists("ssh2.sftp://$sftp" . $path . '/index.php')) {
            $found_indicators[] = 'index.php';
        }
        
        // Check for app, var, and lib directories
        foreach (array('app', 'var', 'lib') as $dir) {
            if (is_dir("ssh2.sftp://$sftp" . $path . '/' . $dir)) {
                $found_indicators[] = $dir;
                
                // If we found app directory, check for app.ini
                if ($dir === 'app' && is_dir("ssh2.sftp://$sftp" . $path . '/app/configs')) {
                    if (file_exists("ssh2.sftp://$sftp" . $path . '/app/configs/app.ini')) {
                        $found_indicators[] = 'app/configs/app.ini';
                    }
                }
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
        
        $this->log_message('Siberian verification result (SSH2): ' . ($is_siberian ? 'Valid' : 'Invalid') . 
                         ', Found: ' . implode(', ', $found_indicators) . 
                         ', Missing: ' . implode(', ', $missing_indicators));
        
        return array(
            'is_siberian' => $is_siberian,
            'found' => $found_indicators,
            'missing' => $missing_indicators
        );
    }
    
    /**
     * Verify installation using phpseclib
     */
    private function verify_installation_phpseclib($host, $username, $password, $port, $path, $required_indicators) {
        $found_indicators = array();
        $missing_indicators = array();
        
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                return array(
                    'is_siberian' => false,
                    'found' => array(),
                    'missing' => array_keys($required_indicators)
                );
            }
            
            // Check for index.php
            if ($sftp->file_exists($path . '/index.php')) {
                $found_indicators[] = 'index.php';
            }
            
            // Check for app, var, and lib directories
            foreach (array('app', 'var', 'lib') as $dir) {
                if ($sftp->is_dir($path . '/' . $dir)) {
                    $found_indicators[] = $dir;
                    
                    // If we found app directory, check for app.ini
                    if ($dir === 'app' && $sftp->is_dir($path . '/app/configs')) {
                        if ($sftp->file_exists($path . '/app/configs/app.ini')) {
                            $found_indicators[] = 'app/configs/app.ini';
                        }
                    }
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
            
            $this->log_message('Siberian verification result (phpseclib): ' . ($is_siberian ? 'Valid' : 'Invalid') . 
                             ', Found: ' . implode(', ', $found_indicators) . 
                             ', Missing: ' . implode(', ', $missing_indicators));
            
            return array(
                'is_siberian' => $is_siberian,
                'found' => $found_indicators,
                'missing' => $missing_indicators
            );
        } catch (\Exception $e) {
            $this->log_message('SFTP error during verification: ' . $e->getMessage());
            return array(
                'is_siberian' => false,
                'found' => array(),
                'missing' => array_keys($required_indicators),
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get file contents via SFTP
     */
    public function get_file_contents($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Getting file contents from SFTP path: ' . $path);
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            return array(
                'success' => false,
                'message' => __('SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.', 'swiftspeed-siberian')
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->get_file_contents_ssh2($host, $username, $password, $port, $path);
        }
        // Fallback to phpseclib
        else {
            return $this->get_file_contents_phpseclib($host, $username, $password, $port, $path);
        }
    }
    
    /**
     * Get file contents using SSH2 extension
     */
    private function get_file_contents_ssh2($host, $username, $password, $port, $path) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            return array(
                'success' => false,
                'message' => __('Could not connect to SFTP server', 'swiftspeed-siberian')
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            return array(
                'success' => false,
                'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            return array(
                'success' => false,
                'message' => __('Could not initialize SFTP subsystem', 'swiftspeed-siberian')
            );
        }
        
        // Get file stats
        $stat = @ssh2_sftp_stat($sftp, $path);
        
        if (!$stat) {
            return array(
                'success' => false,
                'message' => __('Could not access file', 'swiftspeed-siberian')
            );
        }
        
        $file_size = $stat['size'];
        
        // Get maximum allowed size (default to 5MB)
        $max_size = apply_filters('swsib_max_file_size', 5 * 1024 * 1024);
        
        // Check if file is too large
        if ($file_size > $max_size) {
            return array(
                'success' => false,
                'message' => sprintf(__('File is too large (%s). Maximum allowed size is %s.', 'swiftspeed-siberian'), 
                            swsib_format_file_size($file_size), 
                            swsib_format_file_size($max_size))
            );
        }
        
        // Open the file for reading
        $stream = @fopen("ssh2.sftp://$sftp" . $path, 'r');
        
        if (!$stream) {
            return array(
                'success' => false,
                'message' => __('Failed to open file', 'swiftspeed-siberian')
            );
        }
        
        // Read file contents
        $contents = stream_get_contents($stream);
        fclose($stream);
        
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
                    'message' => __('This file type cannot be previewed (binary content)', 'swiftspeed-siberian')
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
     * Get file contents using phpseclib
     */
    private function get_file_contents_phpseclib($host, $username, $password, $port, $path) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                return array(
                    'success' => false,
                    'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
                );
            }
            
            // Get file size - use stat instead of size() method which doesn't exist in phpseclib3
            $stat = $sftp->stat($path);
            
            if ($stat === false) {
                return array(
                    'success' => false,
                    'message' => __('Could not access file', 'swiftspeed-siberian')
                );
            }
            
            $file_size = isset($stat['size']) ? $stat['size'] : 0;
            
            // Get maximum allowed size (default to 5MB)
            $max_size = apply_filters('swsib_max_file_size', 5 * 1024 * 1024);
            
            // Check if file is too large
            if ($file_size > $max_size) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('File is too large (%s). Maximum allowed size is %s.', 'swiftspeed-siberian'), 
                                swsib_format_file_size($file_size), 
                                swsib_format_file_size($max_size))
                );
            }
            
            // Read file contents
            $contents = $sftp->get($path);
            
            if ($contents === false) {
                return array(
                    'success' => false,
                    'message' => __('Failed to read file', 'swiftspeed-siberian')
                );
            }
            
            // Check file extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            
            // Determine if this is a binary/image file
            $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            
            if (in_array($ext, $image_extensions)) {
                // For images, encode as base64
                $contents = base64_encode($contents);
            } else {
                // For text files, check if content is not binary
                // Note: Using the global function, not a class method
                if (!function_exists('swsib_is_binary_content')) {
                    require_once(dirname(dirname(dirname(__FILE__))) . '/fileconnect.php');
                }
                
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
                        'message' => __('This file type cannot be previewed (binary content)', 'swiftspeed-siberian')
                    );
                }
            }
            
            return array(
                'success' => true,
                'contents' => $contents,
                'size' => $file_size
            );
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => __('SFTP error: ', 'swiftspeed-siberian') . $e->getMessage()
            );
        }
    }
    
    /**
     * Delete file or directory via SFTP
     */
    public function delete_file($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        $type = isset($params['type']) ? sanitize_text_field($params['type']) : 'file';
        
        $this->log_message('Deleting ' . $type . ' from SFTP path: ' . $path);
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            return array(
                'success' => false,
                'message' => __('SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.', 'swiftspeed-siberian')
            );
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            return $this->delete_file_ssh2($host, $username, $password, $port, $path, $type);
        }
        // Fallback to phpseclib
        else {
            return $this->delete_file_phpseclib($host, $username, $password, $port, $path, $type);
        }
    }
    
    /**
     * Delete file using SSH2 extension
     */
    private function delete_file_ssh2($host, $username, $password, $port, $path, $type) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            return array(
                'success' => false,
                'message' => __('Could not connect to SFTP server', 'swiftspeed-siberian')
            );
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            return array(
                'success' => false,
                'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
            );
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            return array(
                'success' => false,
                'message' => __('Could not initialize SFTP subsystem', 'swiftspeed-siberian')
            );
        }
        
        // Perform delete operation
        if ($type === 'file') {
            // Delete file
            $delete = @ssh2_sftp_unlink($sftp, $path);
            
            if (!$delete) {
                return array(
                    'success' => false,
                    'message' => __('Failed to delete file', 'swiftspeed-siberian')
                );
            }
            
            return array(
                'success' => true,
                'message' => __('File deleted successfully', 'swiftspeed-siberian')
            );
        } else {
            // Delete directory recursively
            $result = $this->sftp_delete_dir_ssh2($sftp, $path);
            
            if ($result) {
                return array(
                    'success' => true,
                    'message' => __('Directory deleted successfully', 'swiftspeed-siberian')
                );
            } else {
                return array(
                    'success' => false,
                    'message' => __('Failed to delete directory', 'swiftspeed-siberian')
                );
            }
        }
    }
    
    /**
     * Delete file using phpseclib
     */
    private function delete_file_phpseclib($host, $username, $password, $port, $path, $type) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                return array(
                    'success' => false,
                    'message' => __('SFTP authentication failed', 'swiftspeed-siberian')
                );
            }
            
            // Perform delete operation
            if ($type === 'file') {
                // Delete file
                $delete = $sftp->delete($path);
                
                if (!$delete) {
                    return array(
                        'success' => false,
                        'message' => __('Failed to delete file', 'swiftspeed-siberian')
                    );
                }
                
                return array(
                    'success' => true,
                    'message' => __('File deleted successfully', 'swiftspeed-siberian')
                );
            } else {
                // Delete directory recursively
                $result = $this->sftp_delete_dir_phpseclib($sftp, $path);
                
                if ($result) {
                    return array(
                        'success' => true,
                        'message' => __('Directory deleted successfully', 'swiftspeed-siberian')
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => __('Failed to delete directory', 'swiftspeed-siberian')
                    );
                }
            }
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => __('SFTP error: ', 'swiftspeed-siberian') . $e->getMessage()
            );
        }
    }
    
    /**
     * Recursive directory deletion via SSH2 SFTP
     */
    private function sftp_delete_dir_ssh2($sftp, $directory) {
        // Get directory listing
        $dir = @opendir("ssh2.sftp://$sftp" . $directory);
        
        if (!$dir) {
            return false;
        }
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $directory . '/' . $file;
            
            if (is_dir("ssh2.sftp://$sftp" . $full_path)) {
                // Recursively delete subdirectory
                if (!$this->sftp_delete_dir_ssh2($sftp, $full_path)) {
                    closedir($dir);
                    return false;
                }
            } else {
                // Delete file
                if (!@ssh2_sftp_unlink($sftp, $full_path)) {
                    closedir($dir);
                    return false;
                }
            }
        }
        
        closedir($dir);
        
        // Delete the directory itself
        return @ssh2_sftp_rmdir($sftp, $directory);
    }
    
    /**
     * Recursive directory deletion via phpseclib SFTP
     */
    private function sftp_delete_dir_phpseclib($sftp, $directory) {
        // Get directory listing
        $list = $sftp->nlist($directory);
        
        if ($list === false) {
            return false;
        }
        
        foreach ($list as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $full_path = $directory . '/' . $file;
            
            if ($sftp->is_dir($full_path)) {
                // Recursively delete subdirectory
                if (!$this->sftp_delete_dir_phpseclib($sftp, $full_path)) {
                    return false;
                }
            } else {
                // Delete file
                if (!$sftp->delete($full_path)) {
                    return false;
                }
            }
        }
        
        // Delete the directory itself
        return $sftp->rmdir($directory);
    }
    
    /**
     * Download file via SFTP
     */
    public function download_file($params) {
        $host = isset($params['host_sftp']) ? sanitize_text_field($params['host_sftp']) : '';
        $username = isset($params['username_sftp']) ? sanitize_text_field($params['username_sftp']) : '';
        $password = isset($params['password_sftp']) ? $params['password_sftp'] : '';
        $port = isset($params['port_sftp']) ? intval($params['port_sftp']) : 22;
        $path = isset($params['path']) ? sanitize_text_field($params['path']) : '';
        
        $this->log_message('Downloading file from SFTP path: ' . $path);
        
        // Check if SFTP is available
        $sftp_method = $this->is_sftp_available();
        if (!$sftp_method) {
            wp_die(__('SFTP is not available. Please install the SSH2 PHP extension or run "composer require phpseclib/phpseclib:^3.0" in the plugin directory.', 'swiftspeed-siberian'));
        }
        
        // Use SSH2 extension if available
        if ($sftp_method === 'ssh2') {
            $this->download_file_ssh2($host, $username, $password, $port, $path);
        }
        // Fallback to phpseclib
        else {
            $this->download_file_phpseclib($host, $username, $password, $port, $path);
        }
    }
    
    /**
     * Download file using SSH2 extension
     */
    private function download_file_ssh2($host, $username, $password, $port, $path) {
        // Connect to the server
        $connection = @ssh2_connect($host, $port);
        
        if (!$connection) {
            wp_die(__('Could not connect to SFTP server', 'swiftspeed-siberian'));
        }
        
        // Try to authenticate
        if (!@ssh2_auth_password($connection, $username, $password)) {
            wp_die(__('SFTP authentication failed', 'swiftspeed-siberian'));
        }
        
        // Initialize SFTP subsystem
        $sftp = @ssh2_sftp($connection);
        
        if (!$sftp) {
            wp_die(__('Could not initialize SFTP subsystem', 'swiftspeed-siberian'));
        }
        
        // Create temporary file to store downloaded content
        $temp_file = wp_tempnam('swsib_download_');
        
        // Open source file for reading
        $src_stream = @fopen("ssh2.sftp://$sftp" . $path, 'r');
        
        if (!$src_stream) {
            wp_die(__('Failed to open file on SFTP server', 'swiftspeed-siberian'));
        }
        
        // Open destination file for writing
        $dst_stream = @fopen($temp_file, 'w');
        
        if (!$dst_stream) {
            fclose($src_stream);
            wp_die(__('Failed to create temporary file for download', 'swiftspeed-siberian'));
        }
        
        // Copy the file
        $bytes_copied = stream_copy_to_stream($src_stream, $dst_stream);
        
        // Close file handles
        fclose($src_stream);
        fclose($dst_stream);
        
        if ($bytes_copied === false) {
            @unlink($temp_file);
            wp_die(__('Failed to download file from SFTP server', 'swiftspeed-siberian'));
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
    
    /**
     * Download file using phpseclib
     */
    private function download_file_phpseclib($host, $username, $password, $port, $path) {
        try {
            // Create SFTP connection
            $sftp = new \phpseclib3\Net\SFTP($host, $port);
            
            if (!$sftp->login($username, $password)) {
                wp_die(__('SFTP authentication failed', 'swiftspeed-siberian'));
            }
            
            // Create temporary file to store downloaded content
            $temp_file = wp_tempnam('swsib_download_');
            
            // Download file to temporary location
            $file_content = $sftp->get($path);
            
            if ($file_content === false) {
                wp_die(__('Failed to download file from SFTP server', 'swiftspeed-siberian'));
            }
            
            // Write content to temporary file
            if (file_put_contents($temp_file, $file_content) === false) {
                wp_die(__('Failed to create temporary file for download', 'swiftspeed-siberian'));
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
        } catch (\Exception $e) {
            wp_die(__('SFTP error: ', 'swiftspeed-siberian') . $e->getMessage());
        }
    }
}