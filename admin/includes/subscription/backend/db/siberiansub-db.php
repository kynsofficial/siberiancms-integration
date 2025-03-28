<?php
/**
 * PE Subscription Database Operations - Fixed Version
 * Enhanced to properly handle SiberianCMS database connectivity
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class to handle subscription-related database operations with SiberianCMS.
 */
class SwiftSpeed_Siberian_SiberianSub_DB {

    /**
     * SiberianCMS database connection
     */
    private $conn = null;

    /**
     * Plugin options.
     */
    private $options;
    
    /**
     * Connection state tracking.
     */
    private $is_connected = false;
    private $last_error = '';

    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->options = get_option('swsib_options', array());
    }

    /**
     * Central logging method.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'database', $message);
        }
    }

    /**
     * Establish a connection to the SiberianCMS database.
     * Fixed to ensure proper connection handling.
     */
    private function connect() {
        // If we already have a connection, return it
        if ($this->conn !== null && $this->is_connected) {
            $this->log_message("Using existing SiberianCMS database connection");
            return $this->conn;
        }

        $this->log_message("Establishing new connection to SiberianCMS database");
        
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();

        $host     = isset($db_options['host'])     ? $db_options['host']     : '';
        $database = isset($db_options['database']) ? $db_options['database'] : '';
        $username = isset($db_options['username']) ? $db_options['username'] : '';
        $password = isset($db_options['password']) ? $db_options['password'] : '';
        $port     = !empty($db_options['port'])    ? intval($db_options['port']) : 3306;

        $this->log_message("Connection params - Host: {$host}, Database: {$database}, User: {$username}, Port: {$port}");
        
        if (empty($host) || empty($database) || empty($username)) {
            $this->last_error = "Missing required DB configuration (host/db/user).";
            $this->log_message($this->last_error);
            $this->is_connected = false;
            return false;
        }

        // Attempt to connect
        try {
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
            $this->log_message("Successfully connected to SiberianCMS database: {$database}");
            
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
     * Close the database connection.
     */
    private function close() {
        if ($this->conn !== null && $this->is_connected) {
            $this->conn->close();
            $this->conn = null;
            $this->is_connected = false;
            $this->log_message("Closed SiberianCMS database connection");
        }
    }

    /**
     * Get WP subscription data directly from WordPress database
     * Fixed to better separate WP DB operations from SiberianCMS operations
     */
    private function get_wp_subscription($subscription_id) {
        global $wpdb;
        $this->log_message("Getting WP subscription data for ID: {$subscription_id}");
        
        // First try the new DB table approach
        $table_name = $wpdb->prefix . 'swsib_subscriptions';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
        
        if ($table_exists) {
            $this->log_message("Using DB table: {$table_name}");
            $subscription = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE id = %s",
                    $subscription_id
                ),
                ARRAY_A
            );
            
            if ($subscription) {
                if (!empty($subscription['customer_data'])) {
                    $subscription['customer_data'] = maybe_unserialize($subscription['customer_data']);
                }
                $this->log_message("Found subscription in DB table");
                return $subscription;
            }
        }
        
        // Fallback to options-based storage
        $this->log_message("Trying options storage for subscription");
        $options = get_option('swsib_options', array());
        if (isset($options['subscription']['user_subscriptions'])) {
            foreach ($options['subscription']['user_subscriptions'] as $sub) {
                if ($sub['id'] === $subscription_id) {
                    $this->log_message("Found subscription in options");
                    return $sub;
                }
            }
        }
        
        $this->log_message("Subscription not found: {$subscription_id}");
        return null;
    }

    /**
     * Get table name with proper prefix.
     * Enhanced with better error handling.
     */
    private function get_table_name($base_name) {
        $prefix = isset($this->options['db_connect']['prefix']) ? $this->options['db_connect']['prefix'] : '';
        $prefixed_table = $prefix . $base_name;
        
        $this->log_message("Resolving table name for: {$base_name}, prefix: {$prefix}");
        
        // Check if table exists with prefix
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Cannot check tables - no connection. Defaulting to: {$prefixed_table}");
            return $prefixed_table; // Default to prefixed name if connection fails
        }
        
        $chk_table = $conn->query("SHOW TABLES LIKE '{$prefixed_table}'");
        if ($chk_table && $chk_table->num_rows > 0) {
            $this->log_message("Found table with prefix: {$prefixed_table}");
            return $prefixed_table;
        }
        
        // Try without prefix
        $chk_table = $conn->query("SHOW TABLES LIKE '{$base_name}'");
        if ($chk_table && $chk_table->num_rows > 0) {
            $this->log_message("Found table without prefix: {$base_name}");
            return $base_name;
        }
        
        // Default to prefixed version, even if it doesn't exist
        $this->log_message("Table not found! Checked: {$prefixed_table} and {$base_name}. Using: {$prefixed_table}");
        return $prefixed_table;
    }

/**
 * Get application name by ID.
 *
 * @param int $application_id The application ID.
 * @return string The application name or empty string if not found.
 */
public function get_app_name_by_id($application_id) {
    if (empty($application_id) || !is_numeric($application_id)) {
        $this->log_message("Cannot get app name - ID is empty or invalid");
        return '';
    }
    
    $conn = $this->connect();
    if (!$conn) {
        $this->log_message("Failed to connect to SiberianCMS database");
        return '';
    }

    try {
        $app_tbl = $this->get_table_name('application');

        $this->log_message("Using table {$app_tbl} to look up app name by ID: {$application_id}");
        $stmt = $conn->prepare("SELECT name FROM {$app_tbl} WHERE app_id = ? LIMIT 1");
        if (!$stmt) {
            $this->log_message("Failed to prepare app name lookup statement: " . $conn->error);
            $this->close();
            return '';
        }
        
        $stmt->bind_param('i', $application_id);
        if (!$stmt->execute()) {
            $this->log_message("Failed to execute app name lookup: " . $stmt->error);
            $stmt->close();
            $this->close();
            return '';
        }
        
        $stmt->bind_result($app_name);
        $found = $stmt->fetch();
        $stmt->close();
        
        if ($found && $app_name) {
            $this->log_message("Retrieved app name for ID {$application_id}: {$app_name}");
            $this->close();
            return $app_name;
        } else {
            $this->log_message("No app found with ID: {$application_id}");
            $this->close();
            return '';
        }
    } catch (Exception $e) {
        $this->log_message("Exception in get_app_name_by_id: " . $e->getMessage());
        $this->close();
        return '';
    }
}

/**
 * Get comprehensive app details for a subscription.
 *
 * @param int $application_id The application ID.
 * @param int $siberian_plan_id The subscription plan ID.
 * @return array An array of app details.
 */
public function get_app_details_for_subscription($application_id, $siberian_plan_id) {
    $conn = $this->connect();
    if (!$conn) {
        $this->log_message("Failed to connect to SiberianCMS database");
        return array('app_name' => '', 'app_quantity' => 1);
    }

    try {
        // Get app name
        $app_name = $this->get_app_name_by_id($application_id);
        
        // Get app quantity
        $app_quantity = $this->get_app_quantity_for_plan($conn, $siberian_plan_id);
        
        $this->close();
        return array(
            'app_name' => $app_name,
            'app_quantity' => $app_quantity
        );
    } catch (Exception $e) {
        $this->log_message("Exception in get_app_details_for_subscription: " . $e->getMessage());
        $this->close();
        return array('app_name' => '', 'app_quantity' => 1);
    }
}

    /**
     * Get app quantity for a specific subscription plan.
     */
    private function get_app_quantity_for_plan($conn, $siberian_plan_id) {
        try {
            $table_sub = $this->get_table_name('subscription');
            $this->log_message("Getting app quantity for plan ID {$siberian_plan_id} from table {$table_sub}");
            
            $sql = "SELECT app_quantity FROM {$table_sub} WHERE subscription_id = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $this->log_message("Failed to prepare app quantity query: " . $conn->error);
                return 1; // Default if prepare fails
            }
            
            $stmt->bind_param('i', $siberian_plan_id);
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute app quantity query: " . $stmt->error);
                $stmt->close();
                return 1; // Default if execute fails
            }
            
            $stmt->bind_result($app_quantity);
            $found = $stmt->fetch();
            $stmt->close();
            
            if ($found && $app_quantity) {
                $this->log_message("Retrieved app quantity for plan ID {$siberian_plan_id}: {$app_quantity}");
                return $app_quantity;
            } else {
                $this->log_message("No app quantity found for plan ID {$siberian_plan_id}, using default: 1");
                return 1;
            }
        } catch (Exception $e) {
            $this->log_message("Error getting app quantity: " . $e->getMessage());
            return 1; // Default on exception
        }
    }

    /**
     * Test DB connection.
     */
    public function test_connection() {
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
     * Create or update a subscription application in Siberian.
     * Completely rewritten to fix SiberianCMS integration issues.
     */
    public function create_or_update_subscription_application($application_id, $siberian_plan_id, $subscription_id = 0) {
        $this->log_message("Starting create_or_update_subscription_application: app_id={$application_id}, siberian_plan_id={$siberian_plan_id}, subscription_id={$subscription_id}");
        
        // Validate inputs
        if (empty($application_id) || empty($siberian_plan_id)) {
            $this->log_message("Invalid parameters: application_id or siberian_plan_id is empty");
            return false;
        }
        
        // Make sure application_id and siberian_plan_id are integers
        $application_id = intval($application_id);
        $siberian_plan_id = intval($siberian_plan_id);
        
        // Establish database connection
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return false;
        }

        // Get subscription data if ID is provided
        $wp_subscription = null;
        if ($subscription_id) {
            $wp_subscription = $this->get_wp_subscription($subscription_id);
            if (!$wp_subscription) {
                $this->log_message("Warning: Subscription ID provided but not found in WordPress: {$subscription_id}");
                // Continue anyway, as we have the app_id and siberian_plan_id
            } else {
                $this->log_message("Retrieved subscription data from WordPress");
            }
        }

        try {
            // Get table name
            $sub_app_tbl = $this->get_table_name('subscription_application');
            $this->log_message("Using SiberianCMS table: {$sub_app_tbl}");
            
            // Check if record exists
            $check_sql = "SELECT COUNT(*) FROM {$sub_app_tbl} WHERE app_id = ? AND subscription_id = ?";
            $this->log_message("Executing query: {$check_sql} with params: app_id={$application_id}, subscription_id={$siberian_plan_id}");
            
            $check_stmt = $conn->prepare($check_sql);
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
            $this->log_message("Found {$count} existing subscription application records");

            // Get app quantity
            $app_quantity = $this->get_app_quantity_for_plan($conn, $siberian_plan_id);
            
            // Set default payment information
            $payment_method = 'PE Subscription';
            $payment_info = "PE Subscription ID {$subscription_id}";
            
            // Add payment-specific information if available
            if ($wp_subscription) {
                if (isset($wp_subscription['payment_method'])) {
                    $payment_method = ucfirst($wp_subscription['payment_method']);
                    $payment_info = "{$payment_method} Subscription ID {$subscription_id}";
                    
                    // Add payment ID if available
                    if (!empty($wp_subscription['payment_id'])) {
                        $payment_info .= " | Payment ID: {$wp_subscription['payment_id']}";
                    }
                    
                    // Add gateway-specific details
                    if ($wp_subscription['payment_method'] === 'stripe' && !empty($wp_subscription['stripe_customer_id'])) {
                        $payment_info .= " | Customer ID: {$wp_subscription['stripe_customer_id']}";
                    } else if ($wp_subscription['payment_method'] === 'paypal' && !empty($wp_subscription['paypal_payer_id'])) {
                        $payment_info .= " | Payer ID: {$wp_subscription['paypal_payer_id']}";
                    }
                }
            }
                
            // Get expiry date
            $expire_date = null;
            if ($wp_subscription && !empty($wp_subscription['end_date'])) {
                $expire_date = $wp_subscription['end_date'];
            } else {
                $expire_date = date('Y-m-d H:i:s', strtotime('+1 year'));
            }

            $success = false;

            if ($count > 0) {
                // Update existing record
                $this->log_message("Subscription application record exists, updating...");
                
                $update_sql = "
                    UPDATE {$sub_app_tbl}
                    SET is_active = 1,
                        app_quantity = ?,
                        payment_method = ?,
                        note = ?,
                        expire_at = ?,
                        updated_at = NOW()
                    WHERE app_id = ? AND subscription_id = ?
                ";
                
                $this->log_message("Executing update: " . $update_sql);
                
                $upd_stmt = $conn->prepare($update_sql);
                if (!$upd_stmt) {
                    $this->log_message("Failed to prepare update statement: " . $conn->error);
                    $this->close();
                    return false;
                }
                
                $upd_stmt->bind_param('isssii', $app_quantity, $payment_method, $payment_info, $expire_date, $application_id, $siberian_plan_id);
                $success = $upd_stmt->execute();
                
                if (!$success) {
                    $this->log_message("Failed to execute update: " . $upd_stmt->error);
                    $upd_stmt->close();
                    $this->close();
                    return false;
                }
                
                $this->log_message("Update executed, affected rows: " . $upd_stmt->affected_rows);
                $upd_stmt->close();
            } else {
                // Create new record
                $this->log_message("No existing subscription application record, creating new one...");
                
                // Status should be 'Active'
                $status = 'Active';
                
                $insert_sql = "
                    INSERT INTO {$sub_app_tbl} (
                        subscription_id, app_id, app_quantity, payment_method,
                        status, note, is_active, is_subscription_deleted,
                        last_check_status, last_check_message, expire_at,
                        created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, 1, 0,
                        0, '', ?, NOW(), NOW()
                    )
                ";
                
                $this->log_message("Executing insert: " . $insert_sql);
                
                $ins_stmt = $conn->prepare($insert_sql);
                if (!$ins_stmt) {
                    $this->log_message("Failed to prepare insert statement: " . $conn->error);
                    $this->log_message("SQL Error: " . $conn->error);
                    $this->close();
                    return false;
                }
                
                $ins_stmt->bind_param('iiissss', $siberian_plan_id, $application_id, $app_quantity, $payment_method, $status, $payment_info, $expire_date);
                $success = $ins_stmt->execute();
                
                if (!$success) {
                    $this->log_message("Failed to execute insert: " . $ins_stmt->error);
                    $ins_stmt->close();
                    $this->close();
                    return false;
                }
                
                $this->log_message("Insert executed, affected rows: " . $ins_stmt->affected_rows);
                $ins_stmt->close();
            }

            // Unlock the app if subscription is successful
            if ($success) {
                $table_app = $this->get_table_name('application');
                $this->log_message("Unlocking application in table: {$table_app}");
                
                $unlock_stmt = $conn->prepare("UPDATE {$table_app} SET is_locked = 0 WHERE app_id = ?");
                
                if ($unlock_stmt) {
                    $unlock_stmt->bind_param('i', $application_id);
                    $unlock_result = $unlock_stmt->execute();
                    $this->log_message("Unlock executed, affected rows: " . $unlock_stmt->affected_rows);
                    $unlock_stmt->close();
                    
                    if ($unlock_result) {
                        $this->log_message("Unlocked application ID {$application_id}");
                    } else {
                        $this->log_message("Failed to unlock application #{$application_id}");
                    }
                } else {
                    $this->log_message("Failed to prepare unlock statement: " . $conn->error);
                }
            }
            
            // Close the connection
            $this->close();
            $this->log_message("Operation completed with result: " . ($success ? "SUCCESS" : "FAILURE"));
            return $success;
        } catch (Exception $e) {
            $this->log_message("Exception in create_or_update_subscription_application: " . $e->getMessage());
            $this->log_message("Stack trace: " . $e->getTraceAsString());
            $this->close();
            return false;
        }
    }

    /**
     * Delete subscription application record.
     */
    public function delete_subscription_application($application_id, $siberian_plan_id) {
        $this->log_message("Deleting subscription application: app_id={$application_id}, siberian_plan_id={$siberian_plan_id}");
        
        // Make sure application_id and siberian_plan_id are integers
        $application_id = intval($application_id);
        $siberian_plan_id = intval($siberian_plan_id);
        
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return false;
        }

        try {
            $sub_app_tbl = $this->get_table_name('subscription_application');

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
                $this->log_message("Deleted subscription application record (app #{$application_id}, subID #{$siberian_plan_id})");
                // Lock the application
                $table_app = $this->get_table_name('application');
                $lock_stmt = $conn->prepare("UPDATE {$table_app} SET is_locked = 1 WHERE app_id = ?");
                
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
     * Find a Siberian admin by their email.
     */
    public function find_admin_by_email($email) {
        if (empty($email)) {
            $this->log_message("Cannot find admin - email is empty");
            return null;
        }
        
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return null;
        }

        try {
            $adm_tbl = $this->get_table_name('admin');

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
     * Get a Siberian admin by their ID.
     */
    public function get_admin_by_id($admin_id) {
        if (empty($admin_id) || !is_numeric($admin_id)) {
            $this->log_message("Cannot get admin - ID is empty or invalid");
            return null;
        }
        
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return null;
        }

        try {
            $adm_tbl = $this->get_table_name('admin');

            $this->log_message("Using table {$adm_tbl} to look up admin by ID: {$admin_id}");
            $stmt = $conn->prepare("SELECT admin_id, role_id, email FROM {$adm_tbl} WHERE admin_id = ? LIMIT 1");
            if (!$stmt) {
                $this->log_message("Failed to prepare admin lookup statement: " . $conn->error);
                $this->close();
                return null;
            }
            
            $stmt->bind_param('i', $admin_id);
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute admin lookup: " . $stmt->error);
                $stmt->close();
                $this->close();
                return null;
            }
            
            $res = $stmt->get_result();

            if ($res->num_rows === 0) {
                $this->log_message("No admin found with ID: {$admin_id}");
                $stmt->close();
                $this->close();
                return null;
            }

            $admin = $res->fetch_assoc();
            $this->log_message("Found admin ID: {$admin['admin_id']} with role: {$admin['role_id']}");
            $stmt->close();
            $this->close();
            return $admin;
        } catch (Exception $e) {
            $this->log_message("Exception in get_admin_by_id: " . $e->getMessage());
            $this->close();
            return null;
        }
    }

    /**
     * Update a Siberian admin's role.
     */
    public function update_admin_role($admin_id, $role_id) {
        if (empty($admin_id) || !is_numeric($admin_id) || empty($role_id) || !is_numeric($role_id)) {
            $this->log_message("Cannot update admin role - invalid admin_id or role_id");
            return false;
        }
        
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return false;
        }

        try {
            $adm_tbl = $this->get_table_name('admin');

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
     * Check active subscriptions for a given admin.
     */
    public function check_admin_active_subscriptions($admin_id) {
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return array();
        }

        try {
            $app_tbl = $this->get_table_name('application');
            $sub_app_tbl = $this->get_table_name('subscription_application');

            $this->log_message("Checking active subscriptions for admin ID {$admin_id}");
            
            // Retrieve active subscription rows for the given admin
            $sql = "
                SELECT sa.subscription_id, sa.app_id, sa.payment_method, sa.note 
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
                // Extract payment details from note
                $payment_details = $this->extract_payment_details_from_note($row['payment_method'], $row['note']);
                
                // Merge the payment details with the subscription row
                $row = array_merge($row, $payment_details);
                
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
    
    /**
     * Extract payment details from note based on payment method
     */
    private function extract_payment_details_from_note($payment_method, $note) {
        $details = array();
        
        // Default to empty values
        $details['subscription_id_external'] = '';
        $details['customer_id'] = '';
        
        // Skip if note is empty
        if (empty($note)) {
            return $details;
        }
        
        // Different extraction logic based on payment method
        switch (strtolower($payment_method)) {
            case 'stripe':
                // Extract Stripe subscription ID
                if (preg_match('/Stripe Subscription ID:\s*([^\s|]+)/', $note, $matches)) {
                    $details['stripe_subscription_id'] = $matches[1];
                } else if (preg_match('/Payment ID:\s*([^\s|]+)/', $note, $matches)) {
                    // Attempt to get from generic Payment ID field
                    $details['stripe_subscription_id'] = $matches[1];
                }
                
                // Extract Stripe customer ID
                if (preg_match('/Customer ID:\s*([^\s|]+)/', $note, $matches)) {
                    $details['stripe_customer_id'] = $matches[1];
                }
                break;
                
            case 'paypal':
                // Extract PayPal subscription ID
                if (preg_match('/PayPal Subscription ID:\s*([^\s|]+)/', $note, $matches)) {
                    $details['paypal_subscription_id'] = $matches[1];
                } else if (preg_match('/Payment ID:\s*([^\s|]+)/', $note, $matches)) {
                    // Attempt to get from generic Payment ID field
                    $details['paypal_subscription_id'] = $matches[1];
                }
                
                // Extract PayPal payer ID
                if (preg_match('/Payer ID:\s*([^\s|]+)/', $note, $matches)) {
                    $details['paypal_payer_id'] = $matches[1];
                }
                break;
                
            default:
                // Generic payment ID extraction as fallback
                if (preg_match('/Payment ID:\s*([^\s|]+)/', $note, $matches)) {
                    $details['payment_id_external'] = $matches[1];
                }
        }
        
        return $details;
    }
    
    /**
     * Get a subscription application record by payment gateway ID.
     */
    public function get_subscription_by_payment_id($payment_id, $payment_method = '') {
        if (empty($payment_id)) {
            $this->log_message("Cannot look up subscription - payment_id is empty");
            return null;
        }
        
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return null;
        }

        try {
            $sub_app_tbl = $this->get_table_name('subscription_application');

            $this->log_message("Looking for subscription with payment ID: {$payment_id}, method: {$payment_method}");
            
            // Different search patterns for different payment methods
            $search_pattern = '';
            if (!empty($payment_method)) {
                switch (strtolower($payment_method)) {
                    case 'stripe':
                        $search_pattern = '%Stripe Subscription ID: ' . $payment_id . '%';
                        break;
                    case 'paypal':
                        $search_pattern = '%PayPal Subscription ID: ' . $payment_id . '%';
                        break;
                    default:
                        $search_pattern = '%Payment ID: ' . $payment_id . '%';
                }
            } else {
                // If no payment method specified, try to find it with any pattern
                $search_pattern = '%' . $payment_id . '%';
            }
            
            // Search by payment ID
            $sql = "
                SELECT * FROM {$sub_app_tbl}
                WHERE note LIKE ? AND is_active = 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $this->log_message("Failed to prepare payment ID lookup statement: " . $conn->error);
                $this->close();
                return null;
            }
            
            $stmt->bind_param('s', $search_pattern);
            
            if (!$stmt->execute()) {
                $this->log_message("Failed to execute payment ID lookup: " . $stmt->error);
                $stmt->close();
                $this->close();
                return null;
            }
            
            $res = $stmt->get_result();
            
            if ($res->num_rows === 0) {
                $this->log_message("No subscription found with payment ID pattern: {$search_pattern}");
                $stmt->close();
                $this->close();
                return null;
            }
            
            $subscription = $res->fetch_assoc();
            $this->log_message("Found subscription for app ID: {$subscription['app_id']}, Siberian plan: {$subscription['subscription_id']}");
            
            // Add extracted payment details
            $payment_details = $this->extract_payment_details_from_note($subscription['payment_method'], $subscription['note']);
            $subscription = array_merge($subscription, $payment_details);
            
            $stmt->close();
            $this->close();
            return $subscription;
        } catch (Exception $e) {
            $this->log_message("Exception in get_subscription_by_payment_id: " . $e->getMessage());
            $this->close();
            return null;
        }
    }

    /**
     * Get all SiberianCMS roles.
     */
    public function get_siberian_roles() {
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return array();
        }

        try {
            $table_acl = $this->get_table_name('acl_role');

            $sql = "SELECT role_id, code, label, parent_id, is_self_assignable FROM {$table_acl} ORDER BY role_id ASC";
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
            
            $this->log_message("Retrieved " . count($roles) . " roles from SiberianCMS");
            $this->close();
            return $roles;
        } catch (Exception $e) {
            $this->log_message("Exception in get_siberian_roles: " . $e->getMessage());
            $this->close();
            return array();
        }
    }

    /**
     * Get SiberianCMS subscription plans.
     */
    public function get_siberian_plans() {
        $conn = $this->connect();
        if (!$conn) {
            $this->log_message("Failed to connect to SiberianCMS database");
            return array();
        }

        try {
            $table_sub = $this->get_table_name('subscription');

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
            
            $this->log_message("Retrieved " . count($plans) . " subscription plans from database");
            $this->close();
            return $plans;
        } catch (Exception $e) {
            $this->log_message("Exception in get_siberian_plans: " . $e->getMessage());
            $this->close();
            return array();
        }
    }
}