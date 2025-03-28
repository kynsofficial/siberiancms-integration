<?php
/**
 * Database Manager for SwiftSpeed Siberian
 *
 * Handles database table creation and initialization
 *
 * @package SwiftSpeed_Siberian
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SwiftSpeed_Siberian_Database_Manager {

    /**
     * Table name for subscriptions
     */
    private static $subscriptions_table;

    /**
     * Initialize the database manager.
     */
    public static function init() {
        global $wpdb;
        self::$subscriptions_table = $wpdb->prefix . 'swsib_subscriptions';
        
        // Create tables on activation
        add_action('swsib_plugin_activated', array(__CLASS__, 'create_tables'));
        
        // Make sure tables exist when integration is initialized
        self::maybe_create_tables();
    }
    
/**
 * Check if a table exists and create it if it doesn't or if it's missing required columns
 */
public static function maybe_create_tables() {
    global $wpdb;
    
    $recreate_table = false;
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '" . self::$subscriptions_table . "'") === self::$subscriptions_table;
    
    if (!$table_exists) {
        self::log_message("Subscriptions table does not exist. Creating now.");
        $recreate_table = true;
    } else {
        // Check if all required columns exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM " . self::$subscriptions_table);
        $column_names = array();
        foreach ($columns as $column) {
            $column_names[] = $column->Field;
        }
        
        // Required columns that must exist
        $required_columns = array('cancellation_source', 'next_billing_date');
        
        // Check if any required column is missing
        foreach ($required_columns as $column) {
            if (!in_array($column, $column_names)) {
                self::log_message("Required column '$column' is missing. Will recreate table.");
                $recreate_table = true;
                break;
            }
        }
    }
    
    // Recreate table if needed
    if ($recreate_table) {
        self::create_tables();
    }
}

/**
 * Create database tables
 */
public static function create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Check if the table already exists, drop it if so
    if (self::table_exists(self::$subscriptions_table)) {
        $wpdb->query("DROP TABLE IF EXISTS " . self::$subscriptions_table);
        self::log_message("Dropped existing subscriptions table to recreate it");
    }
    
    $sql = "CREATE TABLE " . self::$subscriptions_table . " (
        `id` varchar(255) NOT NULL,
        `user_id` bigint(20) NOT NULL,
        `plan_id` varchar(255) NOT NULL,
        `payment_id` varchar(255) DEFAULT NULL,
        `admin_id` bigint(20) DEFAULT 0,
        `admin_email` varchar(255) DEFAULT NULL,
        `application_id` bigint(20) DEFAULT 0,
        `siberian_plan_id` varchar(255) DEFAULT NULL,
        `amount` decimal(10,2) NOT NULL,
        `tax_amount` decimal(10,2) DEFAULT 0.00,
        `total_amount` decimal(10,2) NOT NULL,
        `currency` varchar(10) NOT NULL,
        `status` varchar(50) NOT NULL,
        `start_date` datetime NOT NULL,
        `end_date` datetime NOT NULL,
        `next_billing_date` datetime DEFAULT NULL,
        `billing_frequency` varchar(50) NOT NULL,
        `payment_method` varchar(50) DEFAULT 'manual',
        `customer_data` longtext DEFAULT NULL,
        `app_quantity` int(11) DEFAULT 1,
        `payment_status` varchar(50) DEFAULT 'paid',
        `last_payment_date` datetime DEFAULT NULL,
        `last_payment_error` datetime DEFAULT NULL,
        `retry_count` int(11) DEFAULT 0,
        `grace_period_end` datetime DEFAULT NULL,
        `retry_period_end` datetime DEFAULT NULL,
        `is_stripe_subscription` tinyint(1) DEFAULT 0,
        `stripe_customer_id` varchar(255) DEFAULT NULL,
        `paypal_payer_id` varchar(255) DEFAULT NULL,
        `cancellation_source` varchar(50) DEFAULT NULL,
        `created_at` datetime NOT NULL,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `status` (`status`),
        KEY `payment_id` (`payment_id`)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    self::log_message("Created subscriptions table: " . self::$subscriptions_table);
    
    // Verify table was created
    if (self::table_exists(self::$subscriptions_table)) {
        self::log_message("Table creation verified: " . self::$subscriptions_table);
    } else {
        self::log_message("ERROR: Failed to create table: " . self::$subscriptions_table);
    }
}

    /**
     * Check if a table exists
     */
    private static function table_exists($table_name) {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }
    
    /**
     * Get the subscriptions table name
     */
    public static function get_subscriptions_table() {
        return self::$subscriptions_table;
    }
    
    /**
     * Central logging method.
     */
    private static function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('subscription', 'backend', $message);
        }
    }
}

// Initialize the database manager
SwiftSpeed_Siberian_Database_Manager::init();