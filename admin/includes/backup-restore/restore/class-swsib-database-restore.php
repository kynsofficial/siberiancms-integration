<?php
/**
 * Database restore handler for Siberian CMS backups.
 * OPTIMIZED VERSION 2.0: Complete intelligence implementation with proper user settings
 */
class SwiftSpeed_Siberian_Database_Restore {
    /**
     * Database connection instance.
     * 
     * @var mysqli
     */
    private $db_connection;
    
    /**
     * Current database batch size (dynamic).
     * 
     * @var int
     */
    private $db_batch_size = 5000;
    
    /**
     * Maximum number of retries for failed operations.
     * 
     * @var int
     */
    private $max_retries = 3;
    
    /**
     * Process database restore with user configuration.
     */
    public function process($status) {
        if (!$status['has_db']) {
            // No database to restore, move to files phase
            $status['phase'] = $status['has_files'] ? 'files' : 'cleanup';
            $status['status'] = 'processing';
            $status['message'] = $status['has_files'] 
                ? __('Preparing to restore files...', 'swiftspeed-siberian')
                : __('Finalizing restore...', 'swiftspeed-siberian');
            
            return $status;
        }
        
        // Check if database hasn't been initialized yet
        if (!isset($status['db_initialized'])) {
            return $this->initialize_database_restore($status);
        }
        
        // Restore the next batch of tables
        return $this->restore_database_batch($status);
    }
    
    /**
     * Initialize database restore with integrity checks.
     */
    private function initialize_database_restore($status) {
        // Find SQL files to restore
        $sql_files = [];
        
        if (file_exists($status['extract_dir'] . 'backup.sql')) {
            // Single SQL file
            $sql_files[] = $status['extract_dir'] . 'backup.sql';
        } elseif (file_exists($status['extract_dir'] . 'database/')) {
            // Multiple SQL files in database directory
            $db_dir = $status['extract_dir'] . 'database/';
            $files = glob($db_dir . '*.sql');
            
            if (!empty($files)) {
                $sql_files = $files;
            }
        }
        
        if (empty($sql_files)) {
            $this->log_message('No SQL files found for restore');
            $status['phase'] = $status['has_files'] ? 'files' : 'cleanup';
            $status['message'] = $status['has_files'] 
                ? __('Preparing to restore files...', 'swiftspeed-siberian')
                : __('Finalizing restore...', 'swiftspeed-siberian');
            
            return $status;
        }
        
        // Connect to database
        $options = swsib()->get_options();
        $db_options = isset($options['db_connect']) ? $options['db_connect'] : [];
        
        if (empty($db_options['host']) || empty($db_options['database']) || 
            empty($db_options['username']) || empty($db_options['password'])) {
            return new WP_Error('db_config', __('Database configuration is incomplete', 'swiftspeed-siberian'));
        }
        
        $conn = $this->get_db_connection($db_options);
        if (!$conn) {
            return new WP_Error('db_connect', __('Could not connect to database server', 'swiftspeed-siberian'));
        }
        
        // Test write permissions to database
        $test_query = "CREATE TABLE IF NOT EXISTS test_permissions_" . time() . " (id INT);";
        if (!$conn->query($test_query)) {
            return new WP_Error('db_permission', __('No write permission to database', 'swiftspeed-siberian'));
        }
        
        // Clean up test table
        $conn->query("DROP TABLE IF EXISTS test_permissions_" . time() . ";");
        
        // Parse SQL files to get tables
        $tables = [];
        $total_sql_size = 0;
        
        foreach ($sql_files as $file) {
            $table_name = pathinfo($file, PATHINFO_FILENAME);
            $file_size = filesize($file);
            $total_sql_size += $file_size;
            
            if ($table_name !== 'backup') {
                $tables[] = [
                    'name' => $table_name,
                    'file' => $file,
                    'size' => $file_size,
                    'processed' => false,
                ];
            } else {
                // For the combined backup.sql file, extract table names from the file
                $content = file_get_contents($file);
                
                if (preg_match_all('/DROP TABLE IF EXISTS `([^`]+)`/', $content, $matches)) {
                    // Estimate size per table in combined file
                    $table_count = count($matches[1]);
                    $avg_size = $file_size / max(1, $table_count);
                    
                    foreach ($matches[1] as $table) {
                        $tables[] = [
                            'name' => $table,
                            'file' => $file,
                            'size' => $avg_size,
                            'processed' => false,
                        ];
                    }
                }
            }
        }
        
        // Create table queue
        $queue = new SplQueue();
        foreach ($tables as $table) {
            $queue->enqueue($table);
        }
        
        // Initialize batch size from user settings (already calculated in main class)
        $this->db_batch_size = $status['db_batch_size'];
        
        // Update status
        $status['db_initialized'] = true;
        $status['db_queue'] = serialize($queue);
        $status['tables_total'] = count($tables);
        $status['tables_processed'] = 0;
        $status['sql_files'] = $sql_files;
        $status['db_size'] = $total_sql_size;
        $status['message'] = sprintf(
            __('Restoring database... (%d tables, %s)', 'swiftspeed-siberian'),
            count($tables),
            size_format($total_sql_size)
        );
        
        $this->log_message('Database restore initialized with ' . count($tables) . ' tables, size: ' . size_format($total_sql_size));
        $this->log_message('Using batch size: ' . $this->db_batch_size . ' (based on user setting: ' . $status['max_steps'] . ')');
        
        return $status;
    }
    
    /**
     * Restore a batch of database tables with performance metrics.
     */
    private function restore_database_batch($status) {
        $queue = unserialize($status['db_queue']);
        $batch_count = 0;
        $batch_start_time = microtime(true);
        $batch_size_processed = 0;
        
        // Adjust batch size based on performance
        $this->adjust_db_batch_size($status);
        
        // Use status-configured batch size
        $this->db_batch_size = $status['db_batch_size'];
        
        // Get connection
        $options = swsib()->get_options();
        $db_options = isset($options['db_connect']) ? $options['db_connect'] : [];
        $conn = $this->get_db_connection($db_options);
        
        if (!$conn) {
            return new WP_Error('db_connect', __('Could not connect to database server', 'swiftspeed-siberian'));
        }
        
        while (!$queue->isEmpty() && $batch_count < $this->db_batch_size) {
            $table = $queue->dequeue();
            $table_start_time = microtime(true);
            
            $status['current_table'] = $table['name'];
            $status['tables_processed']++;
            
            // Calculate progress
            if ($status['tables_total'] > 0) {
                $db_progress = ($status['tables_processed'] / $status['tables_total']) * 50;
                $status['progress'] = $db_progress;
            }
            
            // Restore table with verification
            $result = $this->restore_table_with_verification($table['file'], $table['name']);
            
            if (is_wp_error($result)) {
                $this->log_message('Error restoring table ' . $table['name'] . ': ' . $result->get_error_message());
                
                // CRITICAL TABLE RESTORE ERRORS
                if ($result->get_error_code() === 'critical_table_error') {
                    $status['critical_errors'][] = [
                        'table' => $table['name'],
                        'message' => $result->get_error_message(),
                        'type' => 'table_restore_failure'
                    ];
                    
                    // Fail the restore immediately
                    $status['status'] = 'error';
                    $status['phase'] = 'error';
                    $status['message'] = sprintf(
                        __('Critical error restoring table %s: %s', 'swiftspeed-siberian'),
                        $table['name'],
                        $result->get_error_message()
                    );
                    $this->log_message('CRITICAL ERROR: Table ' . $table['name'] . ' failed to restore - failing restore');
                    return $status;
                }
                
                // Non-critical errors are still logged
                $status['errors'][] = [
                    'table' => $table['name'],
                    'message' => $result->get_error_message()
                ];
                continue;
            }
            
            // Update processing stats
            $batch_size_processed += $table['size'];
            $table_duration = microtime(true) - $table_start_time;
            
            // Track restored size, not backup size
            $status['db_processed_size'] += $table['size'];
            $status['processed_size'] = $status['db_processed_size'] + $status['files_processed_size'];
            
            $batch_count++;
        }
        
        $status['db_queue'] = serialize($queue);
        
        // Update performance metrics
        $batch_duration = microtime(true) - $batch_start_time;
        if ($batch_duration > 0 && $batch_size_processed > 0) {
            $current_speed = $batch_size_processed / $batch_duration;
            
            // Use weighted average for speed
            if ($status['bytes_per_second'] > 0) {
                $status['bytes_per_second'] = ($status['bytes_per_second'] * 0.7) + ($current_speed * 0.3);
            } else {
                $status['bytes_per_second'] = $current_speed;
            }
            
            // Update DB specific speed
            $status['db_speed'] = $current_speed;
            
            // Update speed history for smoothing
            if (!isset($status['speed_history'])) {
                $status['speed_history'] = [];
            }
            $status['speed_history'][] = $current_speed;
            if (count($status['speed_history']) > 5) {
                array_shift($status['speed_history']);
            }
        }
        
        // Update batch metrics (inherit from main class structure)
        $status['batch_metrics']['last_batch_time'] = $batch_duration;
        $status['batch_metrics']['last_batch_size'] = $batch_size_processed;
        $status['batch_metrics']['last_batch_files'] = $batch_count;
        $status['batch_metrics']['last_memory_usage'] = memory_get_usage(true);
        
        // Update status message
        $status['message'] = $this->generate_status_message($status);
        
        // Check if all tables are processed
        if ($queue->isEmpty() && empty($status['retry_tables'])) {
            $status['phase'] = $status['has_files'] ? 'files' : 'cleanup';
            $status['message'] = $status['has_files'] 
                ? __('Preparing to restore files...', 'swiftspeed-siberian')
                : __('Finalizing restore...', 'swiftspeed-siberian');
            
            // Close connection
            $this->close_db_connection();
        }
        
        // Cleanup memory
        $this->memory_cleanup();
        
        return $status;
    }
    
    /**
     * Restore a table with integrity verification.
     */
    private function restore_table_with_verification($file_path, $table_name = null) {
        if (!file_exists($file_path)) {
            return new WP_Error('critical_table_error', __('SQL file not found', 'swiftspeed-siberian'));
        }
        
        // Connect to database
        $options = swsib()->get_options();
        $db_options = isset($options['db_connect']) ? $options['db_connect'] : [];
        $conn = $this->get_db_connection($db_options);
        
        if (!$conn) {
            return new WP_Error('critical_table_error', __('Could not connect to database server', 'swiftspeed-siberian'));
        }
        
        // Start transaction for atomicity
        $conn->begin_transaction();
        
        try {
            // Disable foreign key checks temporarily
            $conn->query('SET FOREIGN_KEY_CHECKS=0');
            
            // For large files, use streaming approach
            $file_size = filesize($file_path);
            if ($file_size > 10 * 1024 * 1024) { // 10MB
                $result = $this->restore_large_sql_file($conn, $file_path, $table_name);
            } else {
                // Read the file
                $sql_content = file_get_contents($file_path);
                
                if ($sql_content === false) {
                    throw new Exception('Failed to read SQL file');
                }
                
                // Use proper SQL statement parsing
                $queries = $this->parse_sql_statements($sql_content);
                
                foreach ($queries as $query) {
                    $query = trim($query);
                    
                    if (empty($query)) {
                        continue;
                    }
                    
                    if (!$conn->query($query)) {
                        throw new Exception('SQL Error: ' . $conn->error);
                    }
                }
                
                $result = true;
            }
            
            // CRITICAL VERIFICATION: Check if table was created/restored
            if ($table_name) {
                $check_query = "SHOW TABLES LIKE '" . $conn->real_escape_string($table_name) . "'";
                $check_result = $conn->query($check_query);
                
                if (!$check_result || $check_result->num_rows === 0) {
                    throw new Exception('Table ' . $table_name . ' does not exist after restore');
                }
                
                // Verify table has data (if it should have data)
                $count_query = "SELECT COUNT(*) as row_count FROM `{$table_name}`";
                $count_result = $conn->query($count_query);
                
                if ($count_result) {
                    $count_row = $count_result->fetch_assoc();
                    $this->log_message('Table ' . $table_name . ' restored with ' . $count_row['row_count'] . ' rows');
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            // Re-enable foreign key checks
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            
            return $result;
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            
            // Re-enable foreign key checks even on error
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            
            $error_message = 'Error restoring table ' . $table_name . ': ' . $e->getMessage();
            $this->log_message($error_message);
            
            // Check if this is a critical error
            if (strpos($e->getMessage(), 'Syntax error') !== false || 
                strpos($e->getMessage(), 'Table') !== false ||
                strpos($e->getMessage(), 'database') !== false) {
                return new WP_Error('critical_table_error', $error_message);
            }
            
            return new WP_Error('table_error', $error_message);
        }
    }
    
    /**
     * Parse SQL statements properly to handle semicolons in data.
     */
    private function parse_sql_statements($sql_content) {
        $queries = [];
        $current_query = '';
        $in_string = false;
        $string_delimiter = '';
        $escaped = false;
        
        for ($i = 0; $i < strlen($sql_content); $i++) {
            $char = $sql_content[$i];
            
            // Check for string delimiters
            if (!$escaped && ($char === '"' || $char === "'")) {
                if ($in_string && $string_delimiter === $char) {
                    $in_string = false;
                    $string_delimiter = '';
                } elseif (!$in_string) {
                    $in_string = true;
                    $string_delimiter = $char;
                }
            }
            
            // Check for escaped characters
            if ($char === '\\' && !$escaped) {
                $escaped = true;
            } else {
                $escaped = false;
            }
            
            // Add character to current query
            $current_query .= $char;
            
            // Check for statement delimiter (semicolon)
            if (!$in_string && $char === ';') {
                $query = trim($current_query);
                if (!empty($query)) {
                    $queries[] = $query;
                }
                $current_query = '';
            }
        }
        
        // Add any remaining query
        $query = trim($current_query);
        if (!empty($query)) {
            $queries[] = $query;
        }
        
        return $queries;
    }
    
    /**
     * Restore a large SQL file using streaming.
     */
    private function restore_large_sql_file($conn, $file_path, $table_name = null) {
        $file = fopen($file_path, 'r');
        
        if (!$file) {
            throw new Exception('Failed to open SQL file');
        }
        
        $sql = '';
        $line = '';
        
        while (!feof($file)) {
            $line = fgets($file);
            
            // Skip comments
            if (substr($line, 0, 2) == '--' || $line == '') {
                continue;
            }
            
            $sql .= $line;
            
            // If the line contains a semicolon at the end, execute the SQL
            if (substr(trim($line), -1, 1) == ';') {
                $sql = trim($sql);
                if (!empty($sql)) {
                    if (!$conn->query($sql)) {
                        fclose($file);
                        throw new Exception('SQL Error: ' . $conn->error);
                    }
                }
                $sql = '';
            }
        }
        
        fclose($file);
        
        return true;
    }
    
    /**
     * Get a database connection.
     */
    private function get_db_connection($db_options) {
        if ($this->db_connection && @mysqli_ping($this->db_connection)) {
            return $this->db_connection;
        }
        
        // Close existing connection if it exists but isn't working
        if ($this->db_connection) {
            @mysqli_close($this->db_connection);
            $this->db_connection = null;
        }
        
        // Create a new connection
        try {
            $this->log_message('Creating new database connection to: ' . $db_options['host']);
            
            $this->db_connection = new mysqli(
                $db_options['host'],
                $db_options['username'],
                $db_options['password'],
                $db_options['database'],
                isset($db_options['port']) ? intval($db_options['port']) : 3306
            );
            
            if ($this->db_connection->connect_error) {
                $this->log_message('Database connection error: ' . $this->db_connection->connect_error);
                return false;
            }
            
            // Set better timeout values
            $this->db_connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);
            $this->db_connection->options(MYSQLI_OPT_READ_TIMEOUT, 60);
            
            // Set UTF-8 character set
            $this->db_connection->set_charset('utf8');
            
            return $this->db_connection;
        } catch (Exception $e) {
            $this->log_message('Database connection exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Close the database connection.
     */
    private function close_db_connection() {
        if ($this->db_connection) {
            @mysqli_close($this->db_connection);
            $this->db_connection = null;
        }
    }
    
    /**
     * Adjust database batch size based on performance.
     */
    private function adjust_db_batch_size(&$status) {
        $metrics = $status['batch_metrics'];
        $current_batch_size = $status['db_batch_size'];
        
        if ($metrics['last_batch_time'] > 0 && $metrics['last_batch_files'] > 0) {
            $optimal_time = $metrics['optimal_time_per_batch'];
            $actual_time = $metrics['last_batch_time'];
            $memory_usage = $metrics['last_memory_usage'];
            $memory_limit = $this->get_memory_limit();
            $memory_percentage = ($memory_usage / $memory_limit) * 100;
            
            // Calculate adjustment factor
            $time_factor = $optimal_time / max(1, $actual_time);
            
            // Adjust for memory usage
            $memory_factor = 1;
            if ($memory_percentage > 70) {
                $memory_factor = 0.7;
            } else if ($memory_percentage < 40) {
                $memory_factor = 1.2;
            }
            
            // Calculate new batch size
            $new_batch_size = round($current_batch_size * $time_factor * $memory_factor);
            
            // Ensure batch size stays within reasonable limits
            $new_batch_size = max(1, min(10000, $new_batch_size));
            
            if (abs($new_batch_size - $current_batch_size) > 5) {
                $this->log_message(sprintf(
                    'Adjusting DB batch size from %d to %d (time: %.1fs, memory: %.1f%%)',
                    $current_batch_size,
                    $new_batch_size,
                    $actual_time,
                    $memory_percentage
                ));
            }
            
            $status['db_batch_size'] = $new_batch_size;
        }
    }
    
    /**
     * Generate a detailed status message based on current restore state.
     */
    private function generate_status_message($status) {
        $processed_count = isset($status['tables_processed']) ? $status['tables_processed'] : 0;
        $total_count = isset($status['tables_total']) ? $status['tables_total'] : 0;
        $size_text = isset($status['db_processed_size']) ? size_format($status['db_processed_size'], 2) : '0 B';
        $item_text = 'tables';
        
        if (!empty($status['current_table'])) {
            $item_text .= ' (current: ' . esc_html($status['current_table']) . ')';
        }
        
        // Calculate progress percentage
        $progress = 0;
        if ($total_count > 0) {
            $progress = ($processed_count / $total_count) * 100;
        }
        
        $message = sprintf(
            __('Restoring database: %d of %d %s (%.1f%%, %s)', 'swiftspeed-siberian'),
            $processed_count,
            $total_count,
            $item_text,
            $progress,
            $size_text
        );
        
        // Add speed if available
        if (isset($status['bytes_per_second']) && $status['bytes_per_second'] > 0) {
            $speed_text = size_format($status['bytes_per_second'], 2) . '/s';
            $message .= ' at ' . $speed_text;
        }
        
        return $message;
    }
    
    /**
     * Get the available memory limit in bytes.
     */
    private function get_memory_limit() {
        $memory_limit = ini_get('memory_limit');
        
        // Convert to bytes
        $unit = strtoupper(substr($memory_limit, -1));
        $value = intval(substr($memory_limit, 0, -1));
        
        switch ($unit) {
            case 'G':
                $value *= 1024;
                // Fall through
            case 'M':
                $value *= 1024;
                // Fall through
            case 'K':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Clean up memory after processing.
     */
    protected function memory_cleanup() {
        // Force garbage collection if available
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    /**
     * Write to log using the central logging manager.
     */
    private function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'restore', $message);
        }
    }
    
    /**
     * Destructor to ensure connections are closed.
     */
    public function __destruct() {
        $this->close_db_connection();
    }
}