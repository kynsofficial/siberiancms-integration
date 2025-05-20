<?php
/**
 * Siberian API Data Handling
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class SwiftSpeed_Siberian_API_Data {
    
    /**
     * Database connection
     */
    private $db_connection;
    
    /**
     * Database name
     */
    private $db_name;
    
    /**
     * Constructor
     */
    public function __construct($db_connection = null, $db_name = null) {
        $this->db_connection = $db_connection;
        $this->db_name = $db_name;
    }
    
    /**
     * Log message
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('automate', 'backend', $message);
        }
    }
    
    /**
     * Execute API command on the Siberian installation
     */
    public function execute_command($command, $siberian_url, $api_user, $api_password) {
        $this->log_message("Executing API command: $command at URL: $siberian_url");
        
        if (empty($siberian_url)) {
            return array(
                'success' => false,
                'message' => 'Siberian URL not configured.'
            );
        }
        
        if (empty($api_user) || empty($api_password)) {
            return array(
                'success' => false,
                'message' => 'API credentials not configured.'
            );
        }
        
        // Ensure URL ends with a trailing slash
        $siberian_url = trailingslashit($siberian_url);
        
        // Map commands to API endpoints
        $endpoints = array(
            'manifest' => 'backoffice/api_options/manifest',
            'clearcache' => 'backoffice/api_options/clearcache',
            'cleartmp' => 'backoffice/api_options/cleartmp',
            'clearlogs' => 'backoffice/api_options/clearlogs'
        );
        
        if (!isset($endpoints[$command])) {
            return array(
                'success' => false,
                'message' => 'Unknown command: ' . $command
            );
        }
        
        $endpoint = $endpoints[$command];
        $full_url = $siberian_url . $endpoint;
        
        // Make API request with Basic Auth
        $response = wp_remote_get($full_url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($api_user . ':' . $api_password)
            )
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_message("API command execution failed: " . $response->get_error_message());
            
            return array(
                'success' => false,
                'message' => 'API command execution failed: ' . $response->get_error_message()
            );
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log_message("API Response Code: $response_code");
        $this->log_message("API Response: $response_body");
        
        if ($response_code !== 200) {
            return array(
                'success' => false,
                'message' => 'Received response code ' . $response_code
            );
        }
        
        // Parse JSON response
        $data = json_decode($response_body, true);
        
        if (empty($data) || !isset($data['success'])) {
            return array(
                'success' => false,
                'message' => 'Invalid response from API'
            );
        }
        
        if ($data['success'] == 1) {
            $this->record_command_execution($command, $command, $response_body);
            
            return array(
                'success' => true,
                'message' => 'Command executed successfully.',
                'output' => $data
            );
        } else {
            return array(
                'success' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Unknown error'
            );
        }
    }
    
    /**
     * Get API command history
     */
    public function get_command_history($command, $limit = 10) {
        if (!$this->db_connection) {
            return array();
        }
        
        $command = $this->db_connection->real_escape_string($command);
        $limit = intval($limit);
        
        // Query the database for command history
        $query = "SELECT * FROM swsib_command_log 
                 WHERE command = '{$command}'
                 ORDER BY executed_at DESC
                 LIMIT {$limit}";
        
        $result = $this->db_connection->query($query);
        
        if (!$result) {
            $this->log_message("Failed to get command history: " . $this->db_connection->error);
            return array();
        }
        
        $history = array();
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        return $history;
    }
    
    /**
     * Record command execution in the database
     */
    private function record_command_execution($command, $type, $output) {
        if (!$this->db_connection) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        
        $command = $this->db_connection->real_escape_string($command);
        $type = $this->db_connection->real_escape_string($type);
        $output_text = $this->db_connection->real_escape_string($output);
        
        // Create command log table if it doesn't exist
        $this->ensure_command_log_table_exists();
        
        $query = "INSERT INTO swsib_command_log (command, type, output, executed_at)
                 VALUES ('$command', '$type', '$output_text', '$timestamp')";
        
        $this->db_connection->query($query);
    }
    
    /**
     * Ensure command log table exists
     */
    private function ensure_command_log_table_exists() {
        if (!$this->db_connection) {
            return;
        }
        
        // Check if table exists
        $table_check = $this->db_connection->query("SHOW TABLES LIKE 'swsib_command_log'");
        
        if ($table_check && $table_check->num_rows == 0) {
            // Table doesn't exist, create it
            $create_table_query = "
                CREATE TABLE IF NOT EXISTS swsib_command_log (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    command VARCHAR(50) NOT NULL,
                    type VARCHAR(50) NOT NULL,
                    output TEXT NOT NULL,
                    executed_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            
            $this->db_connection->query($create_table_query);
        }
    }
    
    /**
     * Get API commands statistics
     */
    public function get_command_stats() {
        if (!$this->db_connection) {
            return array();
        }
        
        // Ensure the table exists
        $this->ensure_command_log_table_exists();
        
        // Get today's date for filtering
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this_week = date('Y-m-d', strtotime('-7 days'));
        $this_month = date('Y-m-d', strtotime('-30 days'));
        
        $commands = array('manifest', 'clearcache', 'cleartmp', 'clearlogs');
        $stats = array();
        
        foreach ($commands as $command) {
            $command_escaped = $this->db_connection->real_escape_string($command);
            
            // Get count of executions
            $total_query = "SELECT COUNT(*) FROM swsib_command_log WHERE command = '{$command_escaped}'";
            $today_query = "SELECT COUNT(*) FROM swsib_command_log WHERE command = '{$command_escaped}' AND DATE(executed_at) = '{$today}'";
            $yesterday_query = "SELECT COUNT(*) FROM swsib_command_log WHERE command = '{$command_escaped}' AND DATE(executed_at) = '{$yesterday}'";
            $week_query = "SELECT COUNT(*) FROM swsib_command_log WHERE command = '{$command_escaped}' AND DATE(executed_at) >= '{$this_week}'";
            $month_query = "SELECT COUNT(*) FROM swsib_command_log WHERE command = '{$command_escaped}' AND DATE(executed_at) >= '{$this_month}'";
            
            $total_result = $this->db_connection->query($total_query);
            $today_result = $this->db_connection->query($today_query);
            $yesterday_result = $this->db_connection->query($yesterday_query);
            $week_result = $this->db_connection->query($week_query);
            $month_result = $this->db_connection->query($month_query);
            
            $stats[$command] = array(
                'total' => ($total_result) ? $total_result->fetch_row()[0] : 0,
                'today' => ($today_result) ? $today_result->fetch_row()[0] : 0,
                'yesterday' => ($yesterday_result) ? $yesterday_result->fetch_row()[0] : 0,
                'week' => ($week_result) ? $week_result->fetch_row()[0] : 0,
                'month' => ($month_result) ? $month_result->fetch_row()[0] : 0,
                'last_run' => null
            );
            
            // Get last run time
            $last_run_query = "SELECT executed_at FROM swsib_command_log WHERE command = '{$command_escaped}' ORDER BY executed_at DESC LIMIT 1";
            $last_run_result = $this->db_connection->query($last_run_query);
            
            if ($last_run_result && $last_run_result->num_rows > 0) {
                $stats[$command]['last_run'] = $last_run_result->fetch_row()[0];
            }
        }
        
        return $stats;
    }
}