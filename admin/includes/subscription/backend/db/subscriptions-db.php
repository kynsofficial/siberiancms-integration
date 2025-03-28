<?php
/**
 * Subscriptions Database Handler for SwiftSpeed Siberian
 *
 * Handles all subscription-related database operations
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SwiftSpeed_Siberian_Subscriptions_DB {

    /**
     * Table name for subscriptions
     */
    private $subscriptions_table;

    /**
     * Initialize the subscriptions DB handler.
     */
    public function __construct() {
        global $wpdb;
        
        // Make sure the database manager is loaded
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/subscription/backend/db/database-manager.php';
        
        // Get the table name
        $this->subscriptions_table = SwiftSpeed_Siberian_Database_Manager::get_subscriptions_table();
        
        // Make sure tables exist
        SwiftSpeed_Siberian_Database_Manager::maybe_create_tables();
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
     * Create a subscription
     */
    public function create_subscription($subscription_data) {
        global $wpdb;
        
        // Make sure subscription ID exists
        if (empty($subscription_data['id'])) {
            $subscription_data['id'] = uniqid('sub_');
        }
        
        // Add timestamps
        $subscription_data['created_at'] = current_time('mysql');
        $subscription_data['updated_at'] = current_time('mysql');
        
        // Serialize customer_data if needed
        if (isset($subscription_data['customer_data']) && is_array($subscription_data['customer_data'])) {
            $subscription_data['customer_data'] = maybe_serialize($subscription_data['customer_data']);
        }
        
        // Make sure all fields have appropriate default values to avoid database errors
        $defaults = array(
            'grace_period_end' => null,
            'retry_period_end' => null,
            'last_payment_error' => null,
            'is_stripe_subscription' => 0,
            'stripe_customer_id' => null,
            'paypal_payer_id' => null
        );
        
        // Merge with defaults to ensure all fields are set
        $subscription_data = array_merge($defaults, $subscription_data);
        
        // Debug log the data being inserted
        $this->log_message("Inserting subscription data: " . json_encode($subscription_data));
        
        // Check if subscription with this ID already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->subscriptions_table} WHERE id = %s",
            $subscription_data['id']
        ));
        
        if ($existing) {
            $this->log_message("Subscription ID already exists, updating instead: " . $subscription_data['id']);
            return $this->update_subscription($subscription_data['id'], $subscription_data);
        }
        
        // Insert the subscription
        $result = $wpdb->insert(
            $this->subscriptions_table,
            $subscription_data
        );
        
        if ($result === false) {
            $this->log_message("Failed to create subscription: " . $wpdb->last_error);
            return false;
        }
        
        $this->log_message("Created subscription: " . $subscription_data['id']);
        return $subscription_data['id'];
    }

    /**
     * Get a subscription by ID
     */
    public function get_subscription($subscription_id) {
        global $wpdb;
        
        $subscription = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->subscriptions_table} WHERE id = %s",
                $subscription_id
            ),
            ARRAY_A
        );
        
        if ($subscription) {
            // Unserialize customer_data
            if (!empty($subscription['customer_data'])) {
                $subscription['customer_data'] = maybe_unserialize($subscription['customer_data']);
            }
        }
        
        return $subscription;
    }
    
    /**
     * Get a subscription by payment ID
     * Modified to ALWAYS require payment_method to prevent cross-gateway conflicts
     */
    public function get_subscription_by_payment_id($payment_id, $payment_method) {
        global $wpdb;
        
        // Payment method is now required
        if (empty($payment_method)) {
            $this->log_message("ERROR: Payment method must be specified when retrieving by payment_id");
            return false;
        }
        
        $query = "SELECT * FROM {$this->subscriptions_table} WHERE payment_id = %s AND payment_method = %s";
        $params = array($payment_id, $payment_method);
        
        $subscription = $wpdb->get_row(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
        
        if ($subscription) {
            // Unserialize customer_data
            if (!empty($subscription['customer_data'])) {
                $subscription['customer_data'] = maybe_unserialize($subscription['customer_data']);
            }
        }
        
        return $subscription;
    }
    
    /**
     * Update a subscription
     */
    public function update_subscription($subscription_id, $subscription_data) {
        global $wpdb;
        
        // Don't update ID
        if (isset($subscription_data['id'])) {
            unset($subscription_data['id']);
        }
        
        // Update timestamp
        $subscription_data['updated_at'] = current_time('mysql');
        
        // Serialize customer_data if needed
        if (isset($subscription_data['customer_data']) && is_array($subscription_data['customer_data'])) {
            $subscription_data['customer_data'] = maybe_serialize($subscription_data['customer_data']);
        }
        
        // Update the subscription
        $result = $wpdb->update(
            $this->subscriptions_table,
            $subscription_data,
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            $this->log_message("Failed to update subscription {$subscription_id}: " . $wpdb->last_error);
            return false;
        }
        
        $this->log_message("Updated subscription: {$subscription_id}");
        return true;
    }
    
    /**
     * Delete a subscription
     */
    public function delete_subscription($subscription_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->subscriptions_table,
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            $this->log_message("Failed to delete subscription {$subscription_id}: " . $wpdb->last_error);
            return false;
        }
        
        $this->log_message("Deleted subscription: {$subscription_id}");
        return true;
    }
    
    /**
     * Get all subscriptions
     */
    public function get_all_subscriptions($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'user_id' => 0,
            'status' => '',
            'payment_method' => '',
            'orderby' => 'start_date',
            'order' => 'DESC',
            'limit' => 0,
            'offset' => 0
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $query = "SELECT * FROM {$this->subscriptions_table} WHERE 1=1";
        $query_args = array();
        
        // Filter by user ID
        if (!empty($args['user_id'])) {
            $query .= " AND user_id = %d";
            $query_args[] = $args['user_id'];
        }
        
        // Filter by status
        if (!empty($args['status'])) {
            if (is_array($args['status'])) {
                $placeholders = array_fill(0, count($args['status']), '%s');
                $placeholders_str = implode(', ', $placeholders);
                $query .= " AND status IN ($placeholders_str)";
                $query_args = array_merge($query_args, $args['status']);
            } else {
                $query .= " AND status = %s";
                $query_args[] = $args['status'];
            }
        }
        
        // Filter by payment method
        if (!empty($args['payment_method'])) {
            $query .= " AND payment_method = %s";
            $query_args[] = $args['payment_method'];
        }
        
        // Order
        $valid_orderby = array('id', 'user_id', 'status', 'start_date', 'end_date', 'created_at', 'updated_at');
        if (in_array($args['orderby'], $valid_orderby)) {
            $query .= " ORDER BY " . $args['orderby'];
            $query .= ($args['order'] === 'ASC') ? " ASC" : " DESC";
        }
        
        // Limit
        if ($args['limit'] > 0) {
            $query .= " LIMIT %d";
            $query_args[] = $args['limit'];
            
            if ($args['offset'] > 0) {
                $query .= " OFFSET %d";
                $query_args[] = $args['offset'];
            }
        }
        
        // Prepare the query if we have args
        if (!empty($query_args)) {
            $query = $wpdb->prepare($query, $query_args);
        }
        
        $subscriptions = $wpdb->get_results($query, ARRAY_A);
        
        // Unserialize customer_data
        foreach ($subscriptions as &$subscription) {
            if (!empty($subscription['customer_data'])) {
                $subscription['customer_data'] = maybe_unserialize($subscription['customer_data']);
            }
        }
        
        return $subscriptions;
    }
    
    /**
     * Get subscriptions by user ID
     */
    public function get_user_subscriptions($user_id) {
        return $this->get_all_subscriptions(array(
            'user_id' => $user_id
        ));
    }
    
    /**
     * Update subscription status
     */
    public function update_subscription_status($subscription_id, $status, $additional_data = array()) {
        $update_data = array_merge(
            array('status' => $status),
            $additional_data
        );
        
        return $this->update_subscription($subscription_id, $update_data);
    }
    
    /**
     * Get subscription count by status
     */
    public function get_subscription_count_by_status() {
        global $wpdb;
        
        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->subscriptions_table} GROUP BY status",
            ARRAY_A
        );
        
        $status_counts = array(
            'active' => 0,
            'pending-cancellation' => 0,
            'cancelled' => 0,
            'expired' => 0
        );
        
        foreach ($counts as $count) {
            $status_counts[$count['status']] = (int)$count['count'];
        }
        
        return $status_counts;
    }
    
    /**
     * Create or update subscription application in SiberianCMS
     * FIXED: Now properly handles the database connection
     */
    public function create_or_update_subscription_application($application_id, $subscription_id, $internal_subscription_id = 0) {
        // Check if the application and siberian CMS is properly set up
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot create/update subscription application.");
            return false;
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return false;
            }
            
            // Prepare query to insert or update subscription application
            $query = "INSERT INTO application_subscription (application_id, subscription_id, created_at, updated_at) 
                    VALUES (:application_id, :subscription_id, NOW(), NOW()) 
                    ON DUPLICATE KEY UPDATE updated_at = NOW()";
                    
            $result = swsib()->db_connection->executeQuery(
                $query,
                array(
                    'application_id' => $application_id,
                    'subscription_id' => $subscription_id
                )
            );
            
            if ($result) {
                $this->log_message("Successfully created/updated subscription application for app {$application_id}, subscription {$subscription_id}");
                return true;
            } else {
                $this->log_message("Failed to create/update subscription application");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in create_or_update_subscription_application: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete subscription application in SiberianCMS
     */
    public function delete_subscription_application($application_id, $subscription_id) {
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot delete subscription application.");
            return false;
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return false;
            }
            
            // Prepare query to delete subscription application
            $query = "DELETE FROM application_subscription 
                    WHERE application_id = :application_id 
                    AND subscription_id = :subscription_id";
                    
            $result = swsib()->db_connection->executeQuery(
                $query,
                array(
                    'application_id' => $application_id,
                    'subscription_id' => $subscription_id
                )
            );
            
            if ($result) {
                $this->log_message("Successfully deleted subscription application for app {$application_id}, subscription {$subscription_id}");
                return true;
            } else {
                $this->log_message("Failed to delete subscription application");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in delete_subscription_application: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Find admin by email in SiberianCMS
     */
    public function find_admin_by_email($admin_email) {
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot find admin by email.");
            return false;
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return false;
            }
            
            // Prepare query to find admin by email
            $query = "SELECT admin_id, role_id, name, email FROM admin WHERE email = :email LIMIT 1";
                    
            $result = swsib()->db_connection->executeQuery(
                $query,
                array('email' => $admin_email)
            );
            
            if ($result && isset($result[0])) {
                return $result[0];
            } else {
                $this->log_message("Admin not found for email: {$admin_email}");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in find_admin_by_email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get admin by ID from SiberianCMS
     */
    public function get_admin_by_id($admin_id) {
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot get admin by ID.");
            return false;
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return false;
            }
            
            // Prepare query to get admin by ID
            $query = "SELECT admin_id, role_id, name, email FROM admin WHERE admin_id = :admin_id LIMIT 1";
                    
            $result = swsib()->db_connection->executeQuery(
                $query,
                array('admin_id' => $admin_id)
            );
            
            if ($result && isset($result[0])) {
                return $result[0];
            } else {
                $this->log_message("Admin not found for ID: {$admin_id}");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in get_admin_by_id: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update admin role in SiberianCMS
     */
    public function update_admin_role($admin_id, $role_id) {
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot update admin role.");
            return false;
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return false;
            }
            
            // Prepare query to update admin role
            $query = "UPDATE admin SET role_id = :role_id WHERE admin_id = :admin_id";
                    
            $result = swsib()->db_connection->executeQuery(
                $query,
                array(
                    'admin_id' => $admin_id,
                    'role_id' => $role_id
                )
            );
            
            if ($result) {
                $this->log_message("Successfully updated role to {$role_id} for admin {$admin_id}");
                return true;
            } else {
                $this->log_message("Failed to update admin role");
                return false;
            }
        } catch (Exception $e) {
            $this->log_message("Exception in update_admin_role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check admin active subscriptions in SiberianCMS
     */
    public function check_admin_active_subscriptions($admin_id) {
        if (!function_exists('swsib') || !method_exists(swsib(), 'is_db_configured') || !swsib()->is_db_configured()) {
            $this->log_message("Database not configured. Cannot check admin active subscriptions.");
            return array();
        }
        
        try {
            // Ensure we have a valid database connection
            if (!property_exists(swsib(), 'db_connection') || !swsib()->db_connection) {
                $this->log_message("No database connection available in swsib() object");
                return array();
            }
            
            // Get applications owned by this admin
            $app_query = "SELECT application_id FROM application WHERE admin_id = :admin_id";
            $apps = swsib()->db_connection->executeQuery(
                $app_query,
                array('admin_id' => $admin_id)
            );
            
            if (!$apps || empty($apps)) {
                $this->log_message("No applications found for admin {$admin_id}");
                return array();
            }
            
            // Extract application IDs
            $app_ids = array();
            foreach ($apps as $app) {
                $app_ids[] = $app['application_id'];
            }
            
            // Create placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($app_ids), '?'));
            
            // Get active subscriptions for these applications
            $sub_query = "SELECT application_id, subscription_id FROM application_subscription WHERE application_id IN ($placeholders)";
            $params = $app_ids;
            
            $subscriptions = swsib()->db_connection->executeQuery(
                $sub_query,
                $params
            );
            
            if ($subscriptions && !empty($subscriptions)) {
                $this->log_message("Found " . count($subscriptions) . " active subscriptions for admin {$admin_id}");
                return $subscriptions;
            } else {
                $this->log_message("No active subscriptions found for admin {$admin_id}");
                return array();
            }
        } catch (Exception $e) {
            $this->log_message("Exception in check_admin_active_subscriptions: " . $e->getMessage());
            return array();
        }
    }
}