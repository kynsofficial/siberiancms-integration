<?php
/**
 * Database operations for the plugin.
 */
class SwiftSpeed_Siberian_DB {
    
    /**
     * Database connection
     */
    private $conn = null;
    
    /**
     * Database config
     */
    private $config = array();
    
    /**
     * Initialize the class
     */
    public function __construct() {
        // Get database config - load only when first needed instead of constructor
        $this->config = array();
        
        // Add hooks for user handling - use lower priority to reduce memory usage
        add_action('user_register', array($this, 'sync_user_on_register'), 99, 1);
        add_action('password_reset', array($this, 'sync_user_on_password_reset'), 99, 2);
        add_action('profile_update', array($this, 'sync_user_on_profile_update'), 99, 2);
    }
    
    /**
     * Lazy load database config
     */
    private function load_config() {
        if (empty($this->config)) {
            $options = swsib()->get_options();
            $this->config = isset($options['db_connect']) ? $options['db_connect'] : array();
        }
    }
    
    /**
     * Connect to the database
     */
    public function connect() {
        // Check if already connected
        if ($this->conn !== null) {
            return true;
        }
        
        // Load config if not already loaded
        $this->load_config();
        
        // Check if config is valid
        if (empty($this->config['host']) || 
            empty($this->config['database']) || 
            empty($this->config['username']) || 
            empty($this->config['password'])) {
            return new WP_Error('db_config', __('Database configuration is incomplete', 'swiftspeed-siberian'));
        }
        
        // Get port
        $port = isset($this->config['port']) && !empty($this->config['port']) ? intval($this->config['port']) : 3306;
        
        try {
            // Set execution time limit for connection attempt
            $original_time_limit = ini_get('max_execution_time');
            set_time_limit(30); // 30 seconds max for DB connection
            
            // Create connection with timeout
            $this->conn = new mysqli();
            $this->conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10); // 10 second timeout
            
            // Connect
            $this->conn->real_connect(
                $this->config['host'],
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $port
            );
            
            // Restore original time limit
            set_time_limit($original_time_limit);
            
            // Check connection
            if ($this->conn->connect_error) {
                $this->conn = null;
                return new WP_Error('db_connect', $this->conn->connect_error);
            }
            
            // Set charset
            $this->conn->set_charset('utf8');
            
            return true;
        } catch (Exception $e) {
            $this->conn = null;
            return new WP_Error('db_exception', $e->getMessage());
        }
    }
    
    /**
     * Close the database connection
     */
    public function close() {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }
    }
    
    /**
     * Get table name with prefix
     */
    private function get_table($table) {
        $prefix = isset($this->config['prefix']) ? $this->config['prefix'] : '';
        return $prefix . $table;
    }
    
    /**
     * Find Siberian admin by email
     * Based on the admin table structure from Siberian
     */
    public function find_admin_by_email($email) {
        // Connect to database
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            // Only select necessary columns to save memory
            $stmt = $this->conn->prepare("SELECT admin_id, email, password, firstname, lastname FROM " . $this->get_table('admin') . " WHERE email = ? LIMIT 1");
            
            if (!$stmt) {
                return new WP_Error('db_prepare', $this->conn->error);
            }
            
            $stmt->bind_param("s", $email);
            
            // Set execution time for query
            $original_time_limit = ini_get('max_execution_time');
            set_time_limit(10); // 10 seconds max for query
            
            $exec_result = $stmt->execute();
            
            // Restore original time limit
            set_time_limit($original_time_limit);
            
            if (!$exec_result) {
                $stmt->close();
                return new WP_Error('db_execute', $stmt->error);
            }
            
            // Get result
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            
            $stmt->close();
            
            return $admin;
        } catch (Exception $e) {
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
    
    /**
     * Create or update Siberian admin
     */
    public function create_or_update_admin($user_data) {
        // Connect to database
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            // Check if admin exists
            $existing_admin = $this->find_admin_by_email($user_data['email']);
            
            if (is_wp_error($existing_admin)) {
                return $existing_admin;
            }
            
            // Determine the current time in MySQL format
            $current_time = date('Y-m-d H:i:s');
            
            if ($existing_admin) {
                // Update existing admin
                $stmt = $this->conn->prepare("UPDATE " . $this->get_table('admin') . " 
                    SET password = ?, firstname = ?, lastname = ?, updated_at = ? 
                    WHERE email = ?");
                
                $stmt->bind_param("sssss", 
                    $user_data['password'],
                    $user_data['firstname'],
                    $user_data['lastname'],
                    $current_time,
                    $user_data['email']
                );
                
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                
                return array(
                    'success' => true,
                    'action' => 'updated',
                    'affected' => $affected,
                    'admin_id' => $existing_admin['admin_id']
                );
            } else {
                // Create new admin with default role_id=3 (typical for regular users)
                $role_id = 3; // Default role ID for regular users
                
                $stmt = $this->conn->prepare("INSERT INTO " . $this->get_table('admin') . " 
                    (role_id, email, password, firstname, lastname, is_active, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, 1, ?, ?)");
                
                $stmt->bind_param("issssss", 
                    $role_id,
                    $user_data['email'],
                    $user_data['password'],
                    $user_data['firstname'],
                    $user_data['lastname'],
                    $current_time,
                    $current_time
                );
                
                $stmt->execute();
                $admin_id = $stmt->insert_id;
                $stmt->close();
                
                return array(
                    'success' => true,
                    'action' => 'created',
                    'admin_id' => $admin_id
                );
            }
        } catch (Exception $e) {
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
    
    /**
     * Get Siberian password hash from WordPress password
     * Implements the specific hashing method used by Siberian
     */
    private function get_siberian_password_hash($password) {
        // Based on the sample password format from the database, 
        // Siberian appears to use a single hash algorithm
        // We'll implement this based on the most common method (MD5)
        return md5($password);
    }
    
    /**
     * Sync user on registration
     */
    public function sync_user_on_register($user_id) {
        // Check if DB connection is configured
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        // Get WordPress user data
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            return;
        }
        
        // Get password from user meta (available during registration)
        $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
        
        // If no plain password is stored, we can't sync the password
        if (empty($plain_password)) {
            return;
        }
        
        // Prepare admin data
        $admin_data = array(
            'email' => $user_data->user_email,
            'password' => $this->get_siberian_password_hash($plain_password),
            'firstname' => $user_data->first_name,
            'lastname' => $user_data->last_name
        );
        
        // Create or update admin in Siberian
        $result = $this->create_or_update_admin($admin_data);
        
        // Store Siberian admin ID in user meta if successful
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user_id, 'swsib_admin_id', $result['admin_id']);
            
            // Log the action
            error_log('SwiftSpeed Siberian: Synced user ' . $user_id . ' with Siberian admin ' . $result['admin_id']);
        }
        
        // Remove temporary plain password
        delete_user_meta($user_id, 'swsib_plain_password');
    }
    
    /**
     * Sync user on password reset
     */
    public function sync_user_on_password_reset($user, $new_password) {
        // Check if DB connection is configured
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        // Prepare admin data
        $admin_data = array(
            'email' => $user->user_email,
            'password' => $this->get_siberian_password_hash($new_password),
            'firstname' => $user->first_name,
            'lastname' => $user->last_name
        );
        
        // Create or update admin in Siberian
        $result = $this->create_or_update_admin($admin_data);
        
        // Store Siberian admin ID in user meta if successful
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user->ID, 'swsib_admin_id', $result['admin_id']);
            
            // Log the action
            error_log('SwiftSpeed Siberian: Updated password for user ' . $user->ID . ' in Siberian (admin_id: ' . $result['admin_id'] . ')');
        }
    }
    
    /**
     * Sync user on profile update
     */
    public function sync_user_on_profile_update($user_id, $old_user_data) {
        // Check if DB connection is configured
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        // Get WordPress user data
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            return;
        }
        
        // Find existing admin in Siberian
        $existing_admin = $this->find_admin_by_email($user_data->user_email);
        
        // If admin not found and old email was different, try finding by old email
        if (!$existing_admin && $old_user_data->user_email !== $user_data->user_email) {
            $existing_admin = $this->find_admin_by_email($old_user_data->user_email);
        }
        
        // If password was reset, we'll have plain text password in user meta
        $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
        
        // Prepare admin data
        $admin_data = array(
            'email' => $user_data->user_email,
            'firstname' => $user_data->first_name,
            'lastname' => $user_data->last_name
        );
        
        // Add password if available
        if (!empty($plain_password)) {
            $admin_data['password'] = $this->get_siberian_password_hash($plain_password);
            
            // Remove temporary plain password
            delete_user_meta($user_id, 'swsib_plain_password');
        } elseif ($existing_admin) {
            // Keep existing password if no new password provided
            $admin_data['password'] = $existing_admin['password'];
        } else {
            // If no existing admin and no password, generate a random password
            // This is not ideal but prevents sync failures due to missing password
            $random_password = wp_generate_password(12, false);
            $admin_data['password'] = $this->get_siberian_password_hash($random_password);
        }
        
        // Create or update admin in Siberian
        $result = $this->create_or_update_admin($admin_data);
        
        // Store Siberian admin ID in user meta if successful
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user_id, 'swsib_admin_id', $result['admin_id']);
            
            // Log the action
            error_log('SwiftSpeed Siberian: Updated profile for user ' . $user_id . ' in Siberian (admin_id: ' . $result['admin_id'] . ')');
        }
    }
    
    /**
     * Capture plain password during user creation/update
     * This method should be called from registration and password update hooks
     */
    public function capture_plain_password($user_id, $password) {
        // Store the plain password temporarily
        update_user_meta($user_id, 'swsib_plain_password', $password);
    }
    
    /**
     * Authenticate user through direct database connection
     */
    public function authenticate_user($user_id, $token) {
        // Connect to database
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            // Get WordPress user data
            $user_data = get_userdata($user_id);
            if (!$user_data) {
                return new WP_Error('invalid_user', __('Invalid user ID', 'swiftspeed-siberian'));
            }
            
            // Check token
            $stored_token = get_user_meta($user_id, 'swsib_token', true);
            if (empty($stored_token) || $stored_token !== $token) {
                return new WP_Error('invalid_token', __('Invalid or expired token', 'swiftspeed-siberian'));
            }
            
            // Check if admin exists in Siberian
            $siberian_admin = $this->find_admin_by_email($user_data->user_email);
            
            if (is_wp_error($siberian_admin)) {
                return $siberian_admin;
            }
            
            if (!$siberian_admin) {
                // Create admin in Siberian if they don't exist
                // Get password from user meta if available
                $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
                
                // If no plain password is stored, we can't set the password properly
                // Use a random password as fallback (not ideal but prevents failures)
                if (empty($plain_password)) {
                    $plain_password = wp_generate_password(12, false);
                }
                
                $new_admin_data = array(
                    'email' => $user_data->user_email,
                    'password' => $this->get_siberian_password_hash($plain_password),
                    'firstname' => $user_data->first_name,
                    'lastname' => $user_data->last_name
                );
                
                $create_result = $this->create_or_update_admin($new_admin_data);
                
                if (is_wp_error($create_result)) {
                    return $create_result;
                }
                
                $siberian_admin_id = $create_result['admin_id'];
                
                // Store Siberian admin ID in user meta
                update_user_meta($user_id, 'swsib_admin_id', $siberian_admin_id);
            } else {
                $siberian_admin_id = $siberian_admin['admin_id'];
                
                // Update stored Siberian admin ID
                update_user_meta($user_id, 'swsib_admin_id', $siberian_admin_id);
            }
            
            // Return authentication result
            return array(
                'success' => true,
                'admin_id' => $siberian_admin_id,
                'email' => $user_data->user_email,
                'firstname' => $user_data->first_name,
                'lastname' => $user_data->last_name
            );
        } catch (Exception $e) {
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
}