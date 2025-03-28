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
        // Load config only when needed.
        $this->config = array();
        
        // Add hooks for user handling (lower priority to reduce memory usage)
        add_action('user_register', array($this, 'sync_user_on_register'), 99, 1);
        add_action('password_reset', array($this, 'sync_user_on_password_reset'), 99, 2);
        add_action('profile_update', array($this, 'sync_user_on_profile_update'), 99, 2);
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('db_connect', 'backend', $message);
        }
    }
    
    /**
     * Lazy load database config.
     */
    private function load_config() {
        if (empty($this->config)) {
            $options = swsib()->get_options();
            $this->config = isset($options['db_connect']) ? $options['db_connect'] : array();
        }
    }
    
    /**
     * Connect to the database.
     */
    public function connect() {
        if ($this->conn !== null) {
            return true;
        }
        
        $this->load_config();
        
        if (empty($this->config['host']) || 
            empty($this->config['database']) || 
            empty($this->config['username']) || 
            empty($this->config['password'])) {
            $this->log_message('Database configuration is incomplete.');
            return new WP_Error('db_config', __('Database configuration is incomplete', 'swiftspeed-siberian'));
        }
        
        $port = isset($this->config['port']) && !empty($this->config['port']) ? intval($this->config['port']) : 3306;
        
        try {
            $original_time_limit = ini_get('max_execution_time');
            set_time_limit(30); // 30 seconds max for DB connection
            
            $this->conn = new mysqli();
            $this->conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10); // 10-second timeout
            
            $this->conn->real_connect(
                $this->config['host'],
                $this->config['username'],
                $this->config['password'],
                $this->config['database'],
                $port
            );
            
            set_time_limit($original_time_limit);
            
            if ($this->conn->connect_error) {
                $error = $this->conn->connect_error;
                $this->log_message('Database connection failed: ' . $error);
                $this->conn = null;
                return new WP_Error('db_connect', $error);
            }
            
            $this->conn->set_charset('utf8');
            return true;
        } catch (Exception $e) {
            $this->conn = null;
            $this->log_message('Exception during DB connection: ' . $e->getMessage());
            return new WP_Error('db_exception', $e->getMessage());
        }
    }
    
    /**
     * Close the database connection.
     */
    public function close() {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }
    }
    
    /**
     * Get table name with prefix.
     */
    private function get_table($table) {
        $prefix = isset($this->config['prefix']) ? $this->config['prefix'] : '';
        return $prefix . $table;
    }
    
    /**
     * Get Siberian roles from the database.
     * 
     * @return array|WP_Error Array of roles or error
     */
    public function get_acl_roles() {
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            $query = "SELECT role_id, code, label, parent_id, is_self_assignable FROM " . $this->get_table('acl_role') . " ORDER BY role_id ASC";
            $result = $this->conn->query($query);
            
            if (!$result) {
                $this->log_message('DB query error in get_acl_roles: ' . $this->conn->error);
                return new WP_Error('db_query', $this->conn->error);
            }
            
            $roles = array();
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row;
            }
            
            return $roles;
        } catch (Exception $e) {
            $this->log_message('Exception in get_acl_roles: ' . $e->getMessage());
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
    
    /**
     * Find Siberian admin by email.
     */
    public function find_admin_by_email($email) {
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            $stmt = $this->conn->prepare("SELECT admin_id, email, password, firstname, lastname FROM " . $this->get_table('admin') . " WHERE email = ? LIMIT 1");
            if (!$stmt) {
                $this->log_message('DB prepare error in find_admin_by_email: ' . $this->conn->error);
                return new WP_Error('db_prepare', $this->conn->error);
            }
            
            $stmt->bind_param("s", $email);
            
            $original_time_limit = ini_get('max_execution_time');
            set_time_limit(10);
            $exec_result = $stmt->execute();
            set_time_limit($original_time_limit);
            
            if (!$exec_result) {
                $stmt->close();
                $this->log_message('DB execute error in find_admin_by_email: ' . $stmt->error);
                return new WP_Error('db_execute', $stmt->error);
            }
            
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            $stmt->close();
            
            return $admin;
        } catch (Exception $e) {
            $this->log_message('Exception in find_admin_by_email: ' . $e->getMessage());
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
    
    /**
     * Create or update Siberian admin.
     */
    public function create_or_update_admin($user_data) {
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            $existing_admin = $this->find_admin_by_email($user_data['email']);
            if (is_wp_error($existing_admin)) {
                return $existing_admin;
            }
            
            $current_time = date('Y-m-d H:i:s');
            
            if ($existing_admin) {
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
                $options = swsib()->get_options();
                $default_role_id = swsib()->is_db_configured() && isset($options['auto_login']['default_role_id']) ? 
                    $options['auto_login']['default_role_id'] : '2';
                $role_id = isset($user_data['role_id']) ? $user_data['role_id'] : $default_role_id;
                
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
            $this->log_message('Exception in create_or_update_admin: ' . $e->getMessage());
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
    
    /**
     * Get Siberian password hash from WordPress password.
     */
    private function get_siberian_password_hash($password) {
        return md5($password);
    }
    
    /**
     * Sync user on registration.
     */
    public function sync_user_on_register($user_id) {
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            return;
        }
        
        $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
        if (empty($plain_password)) {
            return;
        }
        
        $admin_data = array(
            'email'     => $user_data->user_email,
            'password'  => $this->get_siberian_password_hash($plain_password),
            'firstname' => $user_data->first_name,
            'lastname'  => $user_data->last_name
        );
        
        $result = $this->create_or_update_admin($admin_data);
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user_id, 'swsib_admin_id', $result['admin_id']);
            $this->log_message('Synced user ' . $user_id . ' with Siberian admin ' . $result['admin_id']);
        }
        delete_user_meta($user_id, 'swsib_plain_password');
    }
    
    /**
     * Sync user on password reset.
     */
    public function sync_user_on_password_reset($user, $new_password) {
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        $admin_data = array(
            'email'     => $user->user_email,
            'password'  => $this->get_siberian_password_hash($new_password),
            'firstname' => $user->first_name,
            'lastname'  => $user->last_name
        );
        
        $result = $this->create_or_update_admin($admin_data);
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user->ID, 'swsib_admin_id', $result['admin_id']);
            $this->log_message('Updated password for user ' . $user->ID . ' in Siberian (admin_id: ' . $result['admin_id'] . ')');
        }
    }
    
    /**
     * Sync user on profile update.
     */
    public function sync_user_on_profile_update($user_id, $old_user_data) {
        if (!swsib()->is_db_configured()) {
            return;
        }
        
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            return;
        }
        
        $existing_admin = $this->find_admin_by_email($user_data->user_email);
        if (!$existing_admin && $old_user_data->user_email !== $user_data->user_email) {
            $existing_admin = $this->find_admin_by_email($old_user_data->user_email);
        }
        
        $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
        $admin_data = array(
            'email'     => $user_data->user_email,
            'firstname' => $user_data->first_name,
            'lastname'  => $user_data->last_name
        );
        
        if (!empty($plain_password)) {
            $admin_data['password'] = $this->get_siberian_password_hash($plain_password);
            delete_user_meta($user_id, 'swsib_plain_password');
        } elseif ($existing_admin) {
            $admin_data['password'] = $existing_admin['password'];
        } else {
            $random_password = wp_generate_password(12, false);
            $admin_data['password'] = $this->get_siberian_password_hash($random_password);
        }
        
        $result = $this->create_or_update_admin($admin_data);
        if (!is_wp_error($result) && $result['success']) {
            update_user_meta($user_id, 'swsib_admin_id', $result['admin_id']);
            $this->log_message('Updated profile for user ' . $user_id . ' in Siberian (admin_id: ' . $result['admin_id'] . ')');
        }
    }
    
    /**
     * Capture plain password during user creation/update.
     */
    public function capture_plain_password($user_id, $password) {
        update_user_meta($user_id, 'swsib_plain_password', $password);
    }
    
    /**
     * Authenticate user through direct database connection.
     */
    public function authenticate_user($user_id, $token) {
        $result = $this->connect();
        if (is_wp_error($result)) {
            return $result;
        }
        
        try {
            $user_data = get_userdata($user_id);
            if (!$user_data) {
                return new WP_Error('invalid_user', __('Invalid user ID', 'swiftspeed-siberian'));
            }
            
            $stored_token = get_user_meta($user_id, 'swsib_token', true);
            if (empty($stored_token) || $stored_token !== $token) {
                return new WP_Error('invalid_token', __('Invalid or expired token', 'swiftspeed-siberian'));
            }
            
            $siberian_admin = $this->find_admin_by_email($user_data->user_email);
            if (is_wp_error($siberian_admin)) {
                return $siberian_admin;
            }
            
            if (!$siberian_admin) {
                $plain_password = get_user_meta($user_id, 'swsib_plain_password', true);
                if (empty($plain_password)) {
                    $plain_password = wp_generate_password(12, false);
                }
                
                $new_admin_data = array(
                    'email'     => $user_data->user_email,
                    'password'  => $this->get_siberian_password_hash($plain_password),
                    'firstname' => $user_data->first_name,
                    'lastname'  => $user_data->last_name
                );
                
                $create_result = $this->create_or_update_admin($new_admin_data);
                if (is_wp_error($create_result)) {
                    return $create_result;
                }
                
                $siberian_admin_id = $create_result['admin_id'];
                update_user_meta($user_id, 'swsib_admin_id', $siberian_admin_id);
            } else {
                $siberian_admin_id = $siberian_admin['admin_id'];
                update_user_meta($user_id, 'swsib_admin_id', $siberian_admin_id);
            }
            
            $this->log_message('Authenticated user ' . $user_id . ' with Siberian admin ' . $siberian_admin_id);
            return array(
                'success'   => true,
                'admin_id'  => $siberian_admin_id,
                'email'     => $user_data->user_email,
                'firstname' => $user_data->first_name,
                'lastname'  => $user_data->last_name
            );
        } catch (Exception $e) {
            $this->log_message('Exception in authenticate_user: ' . $e->getMessage());
            return new WP_Error('db_query', $e->getMessage());
        } finally {
            $this->close();
        }
    }
}
?>
