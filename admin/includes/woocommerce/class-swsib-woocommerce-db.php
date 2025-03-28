<?php
/**
 * WooCommerce integration database operations
 */
class SwiftSpeed_Siberian_WooCommerce_DB
{
    /**
     * Database connection
     */
    private $conn = null;

    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Connection state tracking
     */
    private $is_connected = false;
    private $last_error = '';

    /**
     * Initialize the class
     */
    public function __construct()
    {
        $this->options = get_option('swsib_options', array());
    }

    /**
     * Central logging method
     */
    private function log_message($message)
    {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('woocommerce', 'backend', $message);
        }
    }

    /**
     * Establish a connection to the SiberianCMS database
     */
    private function connect()
    {
        // If we already have a conn, return it
        if ($this->conn !== null && $this->is_connected) {
            return $this->conn;
        }

        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();

        $host     = isset($db_options['host'])     ? $db_options['host']     : '';
        $database = isset($db_options['database']) ? $db_options['database'] : '';
        $username = isset($db_options['username']) ? $db_options['username'] : '';
        $password = isset($db_options['password']) ? $db_options['password'] : '';
        $port     = !empty($db_options['port'])    ? intval($db_options['port']) : 3306;

        if (empty($host) || empty($database) || empty($username)) {
            $this->last_error = "Missing required DB configuration (host/db/user).";
            $this->log_message($this->last_error);
            $this->is_connected = false;
            return false;
        }

        // Attempt to connect
        try {
            // $this->log_message("Connecting to DB at {$host}:{$port}, database: {$database}, user: {$username}");
            
            // Create a new connection
            $this->conn = new mysqli($host, $username, $password, $database, $port);

            // Check for connection errors
            if ($this->conn->connect_error) {
                $this->last_error = "DB connection error: " . $this->conn->connect_error;
                $this->log_message($this->last_error);
                $this->conn = null;
                $this->is_connected = false;
                return false;
            }

            // Set charset
            $this->conn->set_charset('utf8');
            $this->is_connected = true;
            // $this->log_message("Successfully connected to SiberianCMS database");
            
            return $this->conn;
        } catch (Exception $e) {
            $this->last_error = "DB connection exception: " . $e->getMessage();
            $this->log_message($this->last_error);
            $this->conn = null;
            $this->is_connected = false;
            return false;
        }
    }

    /**
     * Close the database connection
     */
    private function close()
    {
        if ($this->conn !== null && $this->is_connected) {
            // $this->log_message("Closing database connection");
            $this->conn->close();
            $this->conn = null;
            $this->is_connected = false;
        }
    }

    /**
     * Test DB connection
     */
    public function test_connection()
    {
        $conn = $this->connect();
        if (!$conn) {
            return array(
                'success' => false,
                'message' => __('Failed to connect to DB. Check settings: ', 'swiftspeed-siberian') . $this->last_error
            );
        }

        try {
            $test = $conn->query("SELECT 1");
            
            if ($test) {
                $this->log_message("DB connection test successful");
                $this->close();
                return array(
                    'success' => true,
                    'message' => __('Connection successful!', 'swiftspeed-siberian')
                );
            } else {
                $this->log_message("DB test query failed: " . $conn->error);
                $this->close();
                return array(
                    'success' => false,
                    'message' => __('Connection established but test query failed: ', 'swiftspeed-siberian') . $conn->error
                );
            }
        } catch (Exception $e) {
            $this->log_message("Error testing connection: " . $e->getMessage());
            $this->close();
            return array(
                'success' => false,
                'message' => __('Error testing connection: ', 'swiftspeed-siberian') . $e->getMessage()
            );
        }
    }

    /**
     * Get app quantity for a specific subscription plan
     * Retrieves plan info without closing the connection
     */
    private function get_app_quantity_for_plan($conn, $siberian_plan_id, $prefix = '')
    {
        try {
            $table_sub = $prefix . 'subscription';
            
            // First check if table exists with prefix
            $chk_sub = $conn->query("SHOW TABLES LIKE '{$table_sub}'");
            if ($chk_sub->num_rows === 0) {
                // Try without prefix
                $table_sub = 'subscription';
                $chk_sub = $conn->query("SHOW TABLES LIKE '{$table_sub}'");
                if ($chk_sub->num_rows === 0) {
                    return 1; // Default to 1 if table not found
                }
            }
            
            $sql = "SELECT app_quantity FROM {$table_sub} WHERE subscription_id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return 1; // Default if prepare fails
            }
            
            $stmt->bind_param('i', $siberian_plan_id);
            if (!$stmt->execute()) {
                $stmt->close();
                return 1; // Default if execute fails
            }
            
            $stmt->bind_result($app_quantity);
            $found = $stmt->fetch();
            $stmt->close();
            
            return $found && $app_quantity ? $app_quantity : 1;
        } catch (Exception $e) {
            $this->log_message("Error getting app quantity: " . $e->getMessage());
            return 1; // Default on exception
        }
    }

    /**
     * Return all SiberianCMS roles
     */
    public function get_siberian_roles()
    {
        $conn = $this->connect();
        if (!$conn) {
            return array();
        }

        try {
            $prefix    = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $table_acl = $prefix . 'acl_role';

            $chk_acl = $conn->query("SHOW TABLES LIKE '{$table_acl}'");
            if ($chk_acl->num_rows === 0) {
                // Try without prefix
                $table_acl = 'acl_role';
                $chk_acl   = $conn->query("SHOW TABLES LIKE '{$table_acl}'");
                if ($chk_acl->num_rows === 0) {
                    // $this->log_message("acl_role table not found in DB. Tables checked: " . $prefix . "acl_role and acl_role");
                    $this->close();
                    return array();
                }
            }

            // $this->log_message("Using table {$table_acl} for role query");
            $sql    = "SELECT role_id, code, label, parent_id, is_self_assignable FROM {$table_acl} ORDER BY role_id ASC";
            $result = $conn->query($sql);
            if (!$result) {
                $this->log_message("Error querying roles: " . $conn->error);
                $this->close();
                return array();
            }

            $roles = array();
            while ($row = $result->fetch_assoc()) {
                $roles[] = $row;
            }
            
            // $this->log_message("Retrieved " . count($roles) . " roles from database");
            $this->close();
            return $roles;
        } catch (Exception $e) {
            $this->log_message("Exception in get_siberian_roles: " . $e->getMessage());
            $this->close();
            return array();
        }
    }

    /**
     * Get SiberianCMS subscription plans
     */
    public function get_siberian_plans()
    {
        $conn = $this->connect();
        if (!$conn) {
            return array();
        }

        try {
            $prefix      = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $table_sub   = $prefix . 'subscription';

            $chk_sub = $conn->query("SHOW TABLES LIKE '{$table_sub}'");
            if ($chk_sub->num_rows === 0) {
                // Try no prefix
                $table_sub = 'subscription';
                $chk_sub   = $conn->query("SHOW TABLES LIKE '{$table_sub}'");
                if ($chk_sub->num_rows === 0) {
                    $this->log_message("subscription table not found in DB. Tables checked: " . $prefix . "subscription and subscription");
                    $this->close();
                    return array();
                }
            }

            // $this->log_message("Using table {$table_sub} for subscription plan query");
            $sql = "SELECT subscription_id, name, payment_frequency, regular_payment, app_quantity
                    FROM {$table_sub}
                    WHERE is_active = 1";
            $result = $conn->query($sql);
            if (!$result) {
                $this->log_message("Error querying subscriptions: " . $conn->error);
                $this->close();
                return array();
            }

            $plans = array();
            while ($row = $result->fetch_assoc()) {
                $plans[] = $row;
            }
            
            // $this->log_message("Retrieved " . count($plans) . " subscription plans from database");
            $this->close();
            return $plans;
        } catch (Exception $e) {
            $this->log_message("Exception in get_siberian_plans: " . $e->getMessage());
            $this->close();
            return array();
        }
    }

    /**
     * Save mapping between Siberian plan & WooCommerce product
     */
    public function save_mapping($siberian_plan_id, $woo_product_id, $role_id)
    {
        if (!is_numeric($siberian_plan_id) || !is_numeric($woo_product_id)) {
            return array(
                'success' => false,
                'message' => __('Invalid plan or product ID', 'swiftspeed-siberian')
            );
        }

        $mappings = $this->get_mappings();

        // Check for duplicates
        foreach ($mappings as $m) {
            if ((string)$m['siberian_plan_id'] === (string)$siberian_plan_id) {
                return array(
                    'success' => false,
                    'message' => __('This Siberian plan is already mapped', 'swiftspeed-siberian')
                );
            }
            if ((string)$m['woo_product_id'] === (string)$woo_product_id) {
                return array(
                    'success' => false,
                    'message' => __('This WooCommerce product is already mapped', 'swiftspeed-siberian')
                );
            }
        }

        $new_mapping = array(
            'id'               => uniqid(),
            'siberian_plan_id' => $siberian_plan_id,
            'woo_product_id'   => $woo_product_id,
            'role_id'          => $role_id
        );

        $this->options['woocommerce']['mappings'][] = $new_mapping;
        update_option('swsib_options', $this->options);
        
        $this->log_message("Created new mapping: Siberian plan {$siberian_plan_id} -> WooCommerce product {$woo_product_id} with role {$role_id}");

        return array(
            'success' => true,
            'message' => __('Mapping added successfully', 'swiftspeed-siberian'),
            'mapping' => $new_mapping
        );
    }

    /**
     * Retrieve existing mappings
     */
    public function get_mappings()
    {
        $woo_opts = isset($this->options['woocommerce']) ? $this->options['woocommerce'] : array();
        return isset($woo_opts['mappings']) ? $woo_opts['mappings'] : array();
    }

    /**
     * Delete a mapping by ID
     */
    public function delete_mapping($mapping_id)
    {
        $mappings = $this->get_mappings();

        $found = false;
        foreach ($mappings as $key => $map) {
            if ($map['id'] === $mapping_id) {
                unset($mappings[$key]);
                $found = true;
                $this->log_message("Deleted mapping with ID: {$mapping_id}");
                break;
            }
        }

        if (!$found) {
            return array(
                'success' => false,
                'message' => __('Mapping not found', 'swiftspeed-siberian')
            );
        }

        $mappings = array_values($mappings);
        $this->options['woocommerce']['mappings'] = $mappings;
        update_option('swsib_options', $this->options);

        return array(
            'success' => true,
            'message' => __('Mapping deleted successfully', 'swiftspeed-siberian')
        );
    }

    /**
     * Find a mapping by Siberian plan (not used heavily but kept for completeness)
     */
    public function find_mapping_by_siberian_plan($siberian_plan_id)
    {
        $all = $this->get_mappings();
        foreach ($all as $map) {
            if ((string)$map['siberian_plan_id'] === (string)$siberian_plan_id) {
                return $map;
            }
        }
        return null;
    }

    /**
     * Find mapping by a Woo product ID
     */
    public function find_mapping_by_woo_product($woo_product_id)
    {
        $all = $this->get_mappings();
        foreach ($all as $map) {
            if ((string)$map['woo_product_id'] === (string)$woo_product_id) {
                return $map;
            }
        }
        return null;
    }

    /**
     * Look up a Siberian admin by their email
     */
    public function find_admin_by_email($email)
    {
        $conn = $this->connect();
        if (!$conn) {
            return null;
        }

        try {
            $prefix   = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $adm_tbl  = $prefix . 'admin';

            $chk_adm = $conn->query("SHOW TABLES LIKE '{$adm_tbl}'");
            if ($chk_adm->num_rows === 0) {
                // try without prefix
                $adm_tbl  = 'admin';
                $chk_adm = $conn->query("SHOW TABLES LIKE '{$adm_tbl}'");

                if ($chk_adm->num_rows === 0) {
                    $this->log_message("admin table not found in DB. Tables checked: " . $prefix . "admin and admin");
                    $this->close();
                    return null;
                }
            }

            $this->log_message("Using table {$adm_tbl} to look up admin by email: {$email}");
            $stmt = $conn->prepare("SELECT admin_id, role_id, email FROM {$adm_tbl} WHERE email = ? LIMIT 1");
            if (!$stmt) {
                $this->log_message("Failed to prepare admin lookup statement: " . $conn->error);
                $this->close();
                return null;
            }
            
            $stmt->bind_param('s', $email);
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute admin lookup: " . $stmt->error);
                $stmt->close();
                $this->close();
                return null;
            }
            
            $res = $stmt->get_result();

            if ($res->num_rows === 0) {
                $this->log_message("No admin found with email: {$email}");
                $stmt->close();
                $this->close();
                return null;
            }

            $admin = $res->fetch_assoc();
            $this->log_message("Found admin ID: {$admin['admin_id']} with role: {$admin['role_id']} for email: {$email}");
            $stmt->close();
            $this->close();
            return $admin;
        } catch (Exception $e) {
            $this->log_message("Exception in find_admin_by_email: " . $e->getMessage());
            $this->close();
            return null;
        }
    }

    /**
     * Update a Siberian admin's role
     */
    public function update_admin_role($admin_id, $role_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return false;
        }

        try {
            $prefix  = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $adm_tbl = $prefix . 'admin';

            $chk_adm = $conn->query("SHOW TABLES LIKE '{$adm_tbl}'");
            if ($chk_adm->num_rows === 0) {
                // No prefix
                $adm_tbl = 'admin';
                $chk_adm = $conn->query("SHOW TABLES LIKE '{$adm_tbl}'");

                if ($chk_adm->num_rows === 0) {
                    $this->log_message("admin table not found in DB for update_admin_role. Tables checked: " . $prefix . "admin and admin");
                    $this->close();
                    return false;
                }
            }

            $this->log_message("Using table {$adm_tbl} to update admin ID {$admin_id} to role {$role_id}");
            $stmt = $conn->prepare("UPDATE {$adm_tbl} SET role_id = ? WHERE admin_id = ?");
            if (!$stmt) {
                $this->log_message("Failed to prepare role update statement: " . $conn->error);
                $this->close();
                return false;
            }
            
            $stmt->bind_param('ii', $role_id, $admin_id);
            $res = $stmt->execute();

            if (!$res) {
                $this->log_message("Failed to execute role update: " . $stmt->error);
                $stmt->close();
                $this->close();
                return false;
            }

            $success = $res && ($stmt->affected_rows > 0 || $stmt->affected_rows === 0);
            $stmt->close();
            $this->close();

            if ($success) {
                $this->log_message("Successfully updated admin ID {$admin_id} to role {$role_id}");
            } else {
                $this->log_message("No changes made when updating admin ID {$admin_id} to role {$role_id}");
            }
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in update_admin_role: " . $e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * Create or update a subscription application in Siberian
     * FIXED: Keeps connection open throughout the entire operation and only closes at the end
     */
    public function create_or_update_subscription_application($application_id, $siberian_plan_id, $subscription_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return false;
        }

        try {
            $prefix     = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $sub_app_tbl= $prefix . 'subscription_application';

            $chk_app = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");
            if ($chk_app->num_rows === 0) {
                // no prefix
                $sub_app_tbl = 'subscription_application';
                $chk_app     = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");

                if ($chk_app->num_rows === 0) {
                    $this->log_message("subscription_application table not found. Tables checked: " . $prefix . "subscription_application and subscription_application");
                    $this->close();
                    return false;
                }
            }

            $this->log_message("Using table {$sub_app_tbl} for subscription application operations");
            
            // Check if record exists using COUNT() - fixed to keep the connection open
            $check_stmt = $conn->prepare("SELECT COUNT(*) FROM {$sub_app_tbl} WHERE app_id = ? AND subscription_id = ?");
            if (!$check_stmt) {
                $this->log_message("Failed to prepare subscription check statement: " . $conn->error);
                $this->close();
                return false;
            }
            
            $check_stmt->bind_param('ii', $application_id, $siberian_plan_id);
            if (!$check_stmt->execute()) {
                $this->log_message("Failed to execute subscription check: " . $check_stmt->error);
                $check_stmt->close();
                $this->close();
                return false;
            }
            
            $check_stmt->bind_result($count);
            $check_stmt->fetch();
            $check_stmt->close();

            // Get app quantity directly using our helper method
            $app_quantity = $this->get_app_quantity_for_plan($conn, $siberian_plan_id, $prefix);
            $this->log_message("Retrieved app quantity for plan: {$app_quantity}");

            $note        = "WooSubscription ID {$subscription_id}";
            $expire_date = date('Y-m-d H:i:s', strtotime('+1 year')); // default 1-yr expiry
            $success = false;

            if ($count > 0) {
                // Record exists - update
                $this->log_message("Subscription application record exists, updating...");
                
                $upd_stmt = $conn->prepare("
                    UPDATE {$sub_app_tbl}
                    SET is_active = 1,
                        app_quantity = ?,
                        note = ?,
                        expire_at = ?,
                        updated_at = NOW()
                    WHERE app_id = ? AND subscription_id = ?
                ");
                
                if (!$upd_stmt) {
                    $this->log_message("Failed to prepare subscription update statement: " . $conn->error);
                    $this->close();
                    return false;
                }
                
                $upd_stmt->bind_param('issii', $app_quantity, $note, $expire_date, $application_id, $siberian_plan_id);
                $success = $upd_stmt->execute();
                
                if (!$success) {
                    $this->log_message("Failed to execute subscription update: " . $upd_stmt->error);
                    $upd_stmt->close();
                    $this->close();
                    return false;
                }
                
                $upd_stmt->close();
                $this->log_message("Successfully updated subscription application record for app ID: {$application_id}");
            } else {
                // Create new record - make sure fields match your actual DB structure
                $this->log_message("No existing subscription application record, creating new one...");
                
                // Match the example table structure with the correct fields
                $ins_stmt = $conn->prepare("
                    INSERT INTO {$sub_app_tbl} (
                        subscription_id, app_id, app_quantity, payment_method,
                        status, note, is_active, is_subscription_deleted,
                        last_check_status, last_check_message, expire_at,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, 'WooCommerce',
                        'Active', ?, 1, 0,
                        0, '', ?, NOW(), NOW()
                    )
                ");
                
                if (!$ins_stmt) {
                    $this->log_message("Failed to prepare subscription insert statement: " . $conn->error);
                    $this->close();
                    return false;
                }
                
                $ins_stmt->bind_param('iiiss', $siberian_plan_id, $application_id, $app_quantity, $note, $expire_date);
                $success = $ins_stmt->execute();
                
                if (!$success) {
                    $this->log_message("Failed to execute subscription insert: " . $ins_stmt->error);
                    $ins_stmt->close();
                    $this->close();
                    return false;
                }
                
                $ins_stmt->close();
                $this->log_message("Successfully inserted new subscription application record");
            }

            // Unlock the app since it's newly subscribed
            if ($success) {
                // Unlock application without closing and reopening the connection
                $unlock_stmt = $conn->prepare("UPDATE {$prefix}application SET is_locked = 0 WHERE app_id = ?");
                if (!$unlock_stmt) {
                    $table_app = 'application';
                    $unlock_stmt = $conn->prepare("UPDATE {$table_app} SET is_locked = 0 WHERE app_id = ?");
                }
                
                if ($unlock_stmt) {
                    $unlock_stmt->bind_param('i', $application_id);
                    $unlock_result = $unlock_stmt->execute();
                    $unlock_stmt->close();
                    
                    if ($unlock_result) {
                        $this->log_message("Unlocked application ID {$application_id}");
                    } else {
                        $this->log_message("Failed to unlock application #{$application_id}");
                    }
                }
            }
            
            // Finally close the connection at the end of the operation
            $this->close();
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in create_or_update_subscription_application: " . $e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * Delete subscription application record
     */
    public function delete_subscription_application($application_id, $siberian_plan_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return false;
        }

        try {
            $prefix = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $sub_app_tbl = $prefix . 'subscription_application';

            $chk_app = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");
            if ($chk_app->num_rows === 0) {
                // no prefix
                $sub_app_tbl = 'subscription_application';
                $chk_app     = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");
                if ($chk_app->num_rows === 0) {
                    $this->log_message("subscription_application table not found. Tables checked: " . $prefix . "subscription_application and subscription_application");
                    $this->close();
                    return false;
                }
            }

            $this->log_message("Using table {$sub_app_tbl} to delete subscription application");
            $stmt = $conn->prepare("DELETE FROM {$sub_app_tbl} WHERE app_id = ? AND subscription_id = ?");
            if (!$stmt) {
                $this->log_message("Failed to prepare subscription delete statement: " . $conn->error);
                $this->close();
                return false;
            }
            
            $stmt->bind_param('ii', $application_id, $siberian_plan_id);
            $res = $stmt->execute();

            if (!$res) {
                $this->log_message("Failed to execute subscription delete: " . $stmt->error);
                $stmt->close();
                $this->close();
                return false;
            }
            
            $success = $res && $stmt->affected_rows > 0;
            $stmt->close();

            if ($success) {
                $this->log_message("Deleted sub_app record (app #{$application_id}, subID #{$siberian_plan_id})");
                // Lock the application without closing and reopening the connection
                $table_app = $prefix . 'application';
                $lock_stmt = $conn->prepare("UPDATE {$table_app} SET is_locked = 1 WHERE app_id = ?");
                if (!$lock_stmt) {
                    $table_app = 'application';
                    $lock_stmt = $conn->prepare("UPDATE {$table_app} SET is_locked = 1 WHERE app_id = ?");
                }
                
                if ($lock_stmt) {
                    $lock_stmt->bind_param('i', $application_id);
                    $lock_result = $lock_stmt->execute();
                    $lock_stmt->close();
                    
                    if ($lock_result) {
                        $this->log_message("Locked application ID {$application_id}");
                    } else {
                        $this->log_message("Failed to lock application #{$application_id}");
                    }
                }
            } else {
                $this->log_message("No subscription_application record found for app #{$application_id}, subID #{$siberian_plan_id}");
            }

            $this->close();
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in delete_subscription_application: " . $e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * Lock an application in SiberianCMS
     */
    public function lock_application($application_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return false;
        }

        try {
            $prefix   = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $app_tbl  = $prefix . 'application';

            $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");
            if ($chk_app->num_rows === 0) {
                // try no prefix
                $app_tbl = 'application';
                $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");

                if ($chk_app->num_rows === 0) {
                    $this->log_message("application table not found to lock app #{$application_id}. Tables checked: " . $prefix . "application and application");
                    $this->close();
                    return false;
                }
            }

            $this->log_message("Using table {$app_tbl} to lock application ID {$application_id}");
            $stmt = $conn->prepare("UPDATE {$app_tbl} SET is_locked = 1 WHERE app_id = ?");
            if (!$stmt) {
                $this->log_message("Failed to prepare application lock statement: " . $conn->error);
                $this->close();
                return false;
            }
            
            $stmt->bind_param('i', $application_id);
            $res = $stmt->execute();

            if (!$res) {
                $this->log_message("Failed to execute application lock: " . $stmt->error);
                $stmt->close();
                $this->close();
                return false;
            }
            
            $success = $res && ($stmt->affected_rows > 0 || $stmt->affected_rows === 0);
            $stmt->close();

            if ($success) {
                $this->log_message("Locked application ID {$application_id}");
            } else {
                $this->log_message("Failed to lock application #{$application_id}");
            }

            $this->close();
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in lock_application: " . $e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * Unlock an application in SiberianCMS
     */
    public function unlock_application($application_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return false;
        }

        try {
            $prefix  = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
            $app_tbl = $prefix . 'application';

            $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");
            if ($chk_app->num_rows === 0) {
                // no prefix
                $app_tbl = 'application';
                $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");

                if ($chk_app->num_rows === 0) {
                    $this->log_message("application table not found to unlock app #{$application_id}. Tables checked: " . $prefix . "application and application");
                    $this->close();
                    return false;
                }
            }

            $this->log_message("Using table {$app_tbl} to unlock application ID {$application_id}");
            $stmt = $conn->prepare("UPDATE {$app_tbl} SET is_locked = 0 WHERE app_id = ?");
            if (!$stmt) {
                $this->log_message("Failed to prepare application unlock statement: " . $conn->error);
                $this->close();
                return false;
            }
            
            $stmt->bind_param('i', $application_id);
            $res = $stmt->execute();

            if (!$res) {
                $this->log_message("Failed to execute application unlock: " . $stmt->error);
                $stmt->close();
                $this->close();
                return false;
            }
            
            $success = $res && ($stmt->affected_rows > 0 || $stmt->affected_rows === 0);
            $stmt->close();

            if ($success) {
                $this->log_message("Unlocked application ID {$application_id}");
            } else {
                $this->log_message("Failed to unlock application #{$application_id}");
            }

            $this->close();
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in unlock_application: " . $e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * Check active subscriptions for a given admin
     */
    public function check_admin_active_subscriptions($admin_id)
    {
        $conn = $this->connect();
        if (!$conn) {
            return array();
        }

        try {
            $prefix = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';

            $app_tbl = $prefix . 'application';
            $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");
            if ($chk_app->num_rows === 0) {
                // no prefix
                $app_tbl = 'application';
                $chk_app = $conn->query("SHOW TABLES LIKE '{$app_tbl}'");
                if ($chk_app->num_rows === 0) {
                    $this->log_message("application table not found in DB for check_admin_active_subscriptions. Tables checked: " . $prefix . "application and application");
                    $this->close();
                    return array();
                }
            }

            $sub_app_tbl = $prefix . 'subscription_application';
            $chk_sub_app = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");
            if ($chk_sub_app->num_rows === 0) {
                // no prefix
                $sub_app_tbl = 'subscription_application';
                $chk_sub_app = $conn->query("SHOW TABLES LIKE '{$sub_app_tbl}'");
                if ($chk_sub_app->num_rows === 0) {
                    $this->log_message("subscription_application table not found in DB for check_admin_active_subscriptions. Tables checked: " . $prefix . "subscription_application and subscription_application");
                    $this->close();
                    return array();
                }
            }

            $this->log_message("Checking active subscriptions for admin ID {$admin_id}");
            
            // Retrieve active subscription rows for the given admin
            $sql = "
                SELECT sa.subscription_id, sa.app_id
                FROM {$sub_app_tbl} sa
                JOIN {$app_tbl} a ON sa.app_id = a.app_id
                WHERE a.admin_id = ? AND sa.is_active = 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $this->log_message("Failed to prepare active subscriptions statement: " . $conn->error);
                $this->close();
                return array();
            }
            
            $stmt->bind_param('i', $admin_id);
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute active subscriptions query: " . $stmt->error);
                $stmt->close();
                $this->close();
                return array();
            }
            
            $res = $stmt->get_result();

            $subs = array();
            while ($row = $res->fetch_assoc()) {
                $subs[] = $row;
            }
            
            $this->log_message("Found " . count($subs) . " active subscriptions for admin ID {$admin_id}");
            $stmt->close();
            $this->close();
            return $subs;
        } catch (Exception $e) {
            $this->log_message("Exception in check_admin_active_subscriptions: " . $e->getMessage());
            $this->close();
            return array();
        }
    }
}