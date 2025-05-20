<?php
/**
 * Database backup functionality for Siberian CMS.
 * OPTIMIZED VERSION: Improved performance, advanced error handling,
 * better memory management, and MULTI-TABLE PROCESSING support.
 */
class SwiftSpeed_Siberian_DB_Backup extends SwiftSpeed_Siberian_Base_Backup {
    /**
     * Database connection instance.
     * 
     * @var mysqli
     */
    private $db_connection;
    
    /**
     * Base Database batch size (dynamic based on table size).
     * 
     * @var int
     */
    private $db_batch_base = 5000;
    
    /**
     * Start time for performance metrics.
     * 
     * @var float
     */
    private $start_time;
    
    /**
     * Maximum batch size for large tables
     * 
     * @var int
     */
    private $max_batch_size = 20000;
    
    /**
     * Minimum batch size for small tables
     * 
     * @var int
     */
    private $min_batch_size = 100;
    
    /**
     * Number of tables to process simultaneously
     * 
     * @var int
     */
    private $tables_per_batch = 5;
    
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct();
        $this->start_time = microtime(true);
        
        // Get backup settings
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $max_steps = isset($backup_settings['max_steps']) ? intval($backup_settings['max_steps']) : 5;
        
        // Calculate base DB batch size from user settings - normalize to 2-25 range
        $max_steps = max(2, min(25, $max_steps)); 
        $this->db_batch_base = $this->calculate_db_batch_base($max_steps);
        
        // Calculate tables per batch based on max_steps - restore original behavior
        // Lower bound: 1 table at a time with max_steps=2
        // Upper bound: 5 tables at a time with max_steps=25
        $this->tables_per_batch = max(1, min(5, intval($max_steps / 5)));
        $this->log_message("Tables per batch set to: " . $this->tables_per_batch . " (max_steps: " . $max_steps . ")");
    }

    /**
     * Log a message with enhanced details for database backup.
     *
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'db_backup', $message);
        }
    }
    
    /**
     * Calculate base database batch size based on max steps setting (2-25).
     * 
     * @param int $max_steps Maximum steps setting (2-25)
     * @return int Base batch size for database operations
     */
    private function calculate_db_batch_base($max_steps) {
        // Map max_steps (2-25) to database batch sizes
        // 2 = most conservative (minimum speed)
        // 25 = maximum speed
        
        $min_batch = 500;   // For max_steps = 2
        $max_batch = 10000; // For max_steps = 25
        
        // Normalize max_steps to 0-1 range
        $factor = ($max_steps - 2) / 23;
        
        // Calculate batch size
        $batch_size = round($min_batch + ($factor * ($max_batch - $min_batch)));
        
        return max($min_batch, min($max_batch, $batch_size));
    }
    
    /**
     * Log current memory usage
     * 
     * @param string $checkpoint Checkpoint identifier
     * @return void
     */
    private function log_memory_usage($checkpoint) {
        $memory_usage = memory_get_usage(true);
        $formatted_usage = size_format($memory_usage, 2);
        $this->log_message("Memory usage at {$checkpoint}: {$formatted_usage}");
    }
    
    /**
     * Start the database backup process.
     *
     * @param array $params Backup parameters.
     * @return array|WP_Error Backup status or error.
     */
    public function start_backup($params = []) {
        // Increase memory limit and execution time for backup process
        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);
        
        // Default parameters
        $default_params = [
            'storage' => 'local',
            'storage_providers' => ['local'],
            'temp_dir' => null, // Allow specifying a temp directory
            'full_backup' => false, // Flag to indicate this is part of a full backup
            'id' => null, // Allow specifying a backup ID
            'allow_background' => false, // Flag to indicate if background processing is allowed
            'scheduled' => false, // Whether this is a scheduled backup
            'auto_lock' => false, // Whether to auto-lock the backup
            'schedule_id' => null, // Schedule identifier if applicable
            'schedule_name' => null, // Schedule name if applicable
        ];
        
        $params = wp_parse_args($params, $default_params);
        
        $this->log_message('Starting database backup with params: ' . json_encode([
            'storage' => $params['storage'],
            'storage_providers' => $params['storage_providers'],
            'full_backup' => $params['full_backup'] ? 'yes' : 'no',
            'scheduled' => $params['scheduled'] ? 'yes' : 'no',
            'auto_lock' => $params['auto_lock'] ? 'yes' : 'no',
            'memory_limit' => ini_get('memory_limit'),
            'batch_size' => $this->db_batch_base,
            'tables_per_batch' => $this->tables_per_batch,
        ]));
        
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
        
        if (empty($db_options['host']) || empty($db_options['database']) || 
            empty($db_options['username']) || empty($db_options['password'])) {
            $this->log_message('Database connection configuration is incomplete');
            return new WP_Error('db_config', __('Database configuration is incomplete', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Attempting database connection');
        
        $conn = $this->get_db_connection($db_options);
        if (!$conn) {
            return new WP_Error('db_connect', __('Could not connect to database server', 'swiftspeed-siberian'));
        }
        
        // Create backup ID and temporary directory
        $backup_id = !empty($params['id']) ? $params['id'] : 'siberian-backup-db-' . date('Y-m-d-H-i-s') . '-' . substr(md5(mt_rand()), 0, 8);
        $temp_dir = !empty($params['temp_dir']) ? $params['temp_dir'] : $this->temp_dir . $backup_id . '/';
        
        $this->log_message('Using backup ID: ' . $backup_id);
        $this->log_message('Using temp dir: ' . $temp_dir);
        
        if (!file_exists($temp_dir)) {
            if (!wp_mkdir_p($temp_dir)) {
                $this->log_message('Failed to create temporary directory: ' . $temp_dir);
                return new WP_Error('backup_error', __('Failed to create temporary directory', 'swiftspeed-siberian'));
            }
        }
        
        // Get tables
        $tables = [];
        $result = $conn->query('SHOW TABLES');
        if (!$result) {
            $this->log_message('Database error when getting tables: ' . $conn->error);
            return new WP_Error('db_error', 'Error getting tables: ' . $conn->error);
        }
        
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        if (empty($tables)) {
            $this->log_message('No tables found in database');
            return new WP_Error('no_tables', __('No tables found in database', 'swiftspeed-siberian'));
        }
        
        $this->log_message('Found ' . count($tables) . ' tables in database');
        
        // Initialize status
        $status = [
            'id' => $backup_id,
            'temp_dir' => $temp_dir,
            'tables' => $tables,
            'current_tables' => [], // Now tracking multiple active tables
            'total_tables' => count($tables),
            'processed_tables' => 0,
            'total_rows' => 0,
            'processed_rows' => 0,
            'started' => time(),
            'start_time' => microtime(true),
            'status' => 'get_table_data',
            'message' => __('Analyzing database structure...', 'swiftspeed-siberian'),
            'progress' => 0,
            'backup_type' => 'db',
            'full_backup' => !empty($params['full_backup']),
            'errors' => [],
            'critical_errors' => [],
            'db_size' => 0,
            'bytes_per_second' => 0,
            'params' => $params, // Store all params for later use
            'table_queue' => [], // Tables to process
            'active_table_states' => [], // Status of active tables - now supports multiple
            'completed_tables' => [], // Tables that have been completed
            'memory_usage' => [], // Memory usage tracking
            'batch_size' => $this->db_batch_base, // Current batch size
            'tables_per_batch' => $this->tables_per_batch, // Number of tables to process simultaneously
        ];
        
        // Count total rows and get DB size estimate
        $total_rows = 0;
        $total_db_size = 0;
        
        // Query to get table info including size
        $db_name = $db_options['database'];
        $table_list = implode("','", array_map([$conn, 'real_escape_string'], $tables));
        $size_query = "SELECT 
            table_name AS 'table',
            table_rows AS 'rows',
            data_length + index_length AS 'size' 
            FROM information_schema.TABLES 
            WHERE table_schema = '{$db_name}' 
            AND table_name IN ('$table_list')";
        
        $size_result = $conn->query($size_query);
        $table_sizes = [];
        
        if ($size_result) {
            while ($row = $size_result->fetch_assoc()) {
                $table_name = $row['table'];
                $row_count = $row['rows'];
                $size = $row['size'];
                
                $total_rows += $row_count;
                $total_db_size += $size;
                
                $table_sizes[$table_name] = [
                    'rows' => $row_count,
                    'size' => $size,
                    'processed' => false,
                    'batch_size' => $this->calculate_optimal_batch_size($row_count)
                ];
            }
        } else {
            // Fallback to direct count if information_schema query fails
            $this->log_message('Information schema query failed, using direct count fallback');
            foreach ($tables as $table) {
                $count_result = $conn->query("SELECT COUNT(*) AS count FROM `{$table}`");
                if (!$count_result) {
                    $this->log_message('Database error when counting rows: ' . $conn->error);
                    $status['errors'][] = [
                        'table' => $table,
                        'message' => 'Error counting rows: ' . $conn->error
                    ];
                    continue;
                }
                $row = $count_result->fetch_assoc();
                $row_count = $row['count'];
                $total_rows += $row_count;
                
                $table_sizes[$table] = [
                    'rows' => $row_count,
                    'size' => 0, // Unknown size
                    'processed' => false,
                    'batch_size' => $this->calculate_optimal_batch_size($row_count)
                ];
            }
        }
        
        $status['total_rows'] = $total_rows;
        $status['db_size'] = $total_db_size;
        $status['table_sizes'] = $table_sizes;
        
        // Set table processing queue - sort by size for more efficient processing
        $ordered_tables = $tables;
        usort($ordered_tables, function($a, $b) use ($table_sizes) {
            // Small tables first, then medium tables, then large tables
            $a_size = isset($table_sizes[$a]['size']) ? $table_sizes[$a]['size'] : 0;
            $b_size = isset($table_sizes[$b]['size']) ? $table_sizes[$b]['size'] : 0;
            
            // Process small tables first, then large tables
            return $a_size - $b_size;
        });
        
        $status['table_queue'] = $ordered_tables;
        
        $this->log_message('Total rows across all tables: ' . $total_rows);
        $this->log_message('Estimated DB size: ' . size_format($total_db_size, 2));
        
        $this->update_status($status);
        return $this->process_next($status);
    }
    
    /**
     * Get a database connection or create a new one.
     *
     * @param array $db_options Database connection options.
     * @return mysqli|false Database connection or false on failure.
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
     *
     * @return void
     */
    private function close_db_connection() {
        if ($this->db_connection) {
            @mysqli_close($this->db_connection);
            $this->db_connection = null;
        }
    }
    

   /**
 * Process the next step in the database backup.
 *
 * @param array $status Current backup status.
 * @return array|WP_Error Updated backup status or error.
 */
public function process_next($status) {
    if (empty($status)) {
        return new WP_Error('process_error', __('Invalid backup status', 'swiftspeed-siberian'));
    }
    
    if ($status['status'] === 'completed' || $status['status'] === 'error') {
        return $status; // Already completed or has error
    }
    
    // Increase memory limit and execution time for backup process
    @ini_set('memory_limit', '2048M');
    @set_time_limit(300); // 5 minutes
    
    // Begin tracking memory
    $status['memory_usage'][] = [
        'phase' => 'start_process_next',
        'memory' => memory_get_usage(true),
        'time' => microtime(true)
    ];
    
    // Get database connection
    $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
    $conn = $this->get_db_connection($db_options);
    
    if (!$conn) {
        $this->log_message('Failed to get database connection');
        $status['status'] = 'error';
        $status['message'] = __('Failed to connect to database server', 'swiftspeed-siberian');
        $status['critical_errors'][] = [
            'message' => 'Failed to connect to database server',
            'type' => 'connection_error'
        ];
        $this->update_status($status);
        return $status;
    }
    
    // Check if all tables are processed
    if (empty($status['table_queue']) && empty($status['active_table_states'])) {
        $this->log_message('All tables have been processed');
        
        // Finalize the backup
        $status['status'] = 'completed';
        $status['message'] = __('Database backup completed successfully', 'swiftspeed-siberian');
        $status['progress'] = 100;
        
        // Update elapsed time and speed metrics
        $elapsed_time = microtime(true) - $status['start_time'];
        $status['elapsed_time'] = $elapsed_time;
        
        if ($status['db_size'] > 0 && $elapsed_time > 0) {
            $status['bytes_per_second'] = $status['db_size'] / $elapsed_time;
        }
        
        $this->update_status($status);
        
        // Create final archive if not part of full backup
        if (empty($status['full_backup'])) {
            return $this->create_final_archive($status);
        }
        
        $this->log_memory_usage('Backup completion');
        return $status;
    }
    
    // MULTI-TABLE PROCESSING:
    // 1. Fill active_table_states with tables to process if we have less than tables_per_batch
    // 2. Process a batch from each active table
    // 3. Check which tables are complete and remove them from active_table_states

    // Fill active table states to maximum allowed tables_per_batch
    $active_count = count($status['active_table_states']);
    $tables_needed = min(count($status['table_queue']), $status['tables_per_batch'] - $active_count);
    
    if ($tables_needed > 0) {
        $this->log_message("Adding $tables_needed new tables to active processing");
        
        for ($i = 0; $i < $tables_needed; $i++) {
            // Get next table from queue
            $table = array_shift($status['table_queue']);
            
            // Initialize table state
            $status['active_table_states'][$table] = [
                'table' => $table,
                'phase' => 'export_structure',
                'offset' => 0,
                'total_rows' => isset($status['table_sizes'][$table]['rows']) ? $status['table_sizes'][$table]['rows'] : 0,
                'processed_rows' => 0,
                'batch_size' => isset($status['table_sizes'][$table]['batch_size']) ? $status['table_sizes'][$table]['batch_size'] : $this->db_batch_base,
                'sql_file' => $status['temp_dir'] . $table . '.sql',
                'started' => microtime(true)
            ];
            
            $this->log_message('Starting to process table: ' . $table . ' (' . 
                $status['active_table_states'][$table]['total_rows'] . ' rows, batch size: ' . 
                $status['active_table_states'][$table]['batch_size'] . ')');
        }
    }
    
    // Process one batch from each active table
    $completions = [];
    $total_batches_processed = 0;
    
    foreach ($status['active_table_states'] as $table => $table_state) {
        try {
            $this->log_message("Processing batch for table: " . $table . " (phase: " . $table_state['phase'] . ")");
            
            // Process current table based on phase
            if ($table_state['phase'] === 'export_structure') {
                // Export table structure
                $result = $this->export_table_structure($conn, $table_state);
                if (is_wp_error($result)) {
                    $this->log_message('Error exporting table structure: ' . $result->get_error_message());
                    
                    if ($result->get_error_code() === 'critical_error') {
                        $status['critical_errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message(),
                            'type' => 'structure_export_error'
                        ];
                        
                        // Skip this table and remove from active
                        $status['errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message()
                        ];
                        
                        $completions[] = $table;
                    } else {
                        // Non-critical error, record and continue
                        $status['errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message()
                        ];
                        
                        // Still move to data export phase
                        $table_state['phase'] = 'export_data';
                        $status['active_table_states'][$table] = $table_state;
                    }
                } else {
                    // Successfully exported structure, move to data export
                    $table_state = $result;
                    $table_state['phase'] = 'export_data';
                    $status['active_table_states'][$table] = $table_state;
                }
                
                $total_batches_processed++;
            } 
            else if ($table_state['phase'] === 'export_data') {
                // Export table data in batches
                $result = $this->export_table_data_batch($conn, $table_state);
                if (is_wp_error($result)) {
                    $this->log_message('Error exporting table data: ' . $result->get_error_message());
                    
                    if ($result->get_error_code() === 'critical_error') {
                        $status['critical_errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message(),
                            'type' => 'data_export_error'
                        ];
                        
                        // Skip this table and remove from active
                        $status['errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message()
                        ];
                        
                        $completions[] = $table;
                    } else {
                        // Non-critical error, try to continue with next batch
                        $status['errors'][] = [
                            'table' => $table,
                            'message' => $result->get_error_message()
                        ];
                        
                        // Advance the offset and try next batch
                        $table_state['offset'] += $table_state['batch_size'];
                        $status['active_table_states'][$table] = $table_state;
                    }
                } else {
                    // Update table state with progress
                    $status['active_table_states'][$table] = $result;
                    
                    // Check if table export is completed
                    if ($result['offset'] >= $result['total_rows']) {
                        $this->log_message('Completed exporting table: ' . $table);
                        
                        // Add to completed tables
                        $status['completed_tables'][] = $table;
                        
                        // Update processed tables count
                        $status['processed_tables']++;
                        
                        // Update processed rows count
                        $status['processed_rows'] += $result['processed_rows'];
                        
                        // Calculate table processing time
                        $table_time = microtime(true) - $result['started'];
                        $this->log_message('Table ' . $table . ' processed in ' . 
                            number_format($table_time, 2) . ' seconds, ' . 
                            $result['processed_rows'] . ' rows');
                        
                        // Add table to completions list to remove from active
                        $completions[] = $table;
                    }
                }
                
                $total_batches_processed++;
            }
        } 
        catch (Exception $e) {
            $this->log_message('Exception processing table ' . $table . ': ' . $e->getMessage());
            
            $status['errors'][] = [
                'table' => $table,
                'message' => 'Exception: ' . $e->getMessage()
            ];
            
            // Skip this table on exception
            $completions[] = $table;
        }
    }
    
    // Remove completed tables from active_table_states
    foreach ($completions as $table) {
        unset($status['active_table_states'][$table]);
    }
    
    $this->log_message('Processed ' . $total_batches_processed . ' batches across ' . 
                      count($status['active_table_states']) . ' active tables');
    
    // Update progress
    if ($status['total_tables'] > 0) {
        // Calculate progress based on processed tables and rows in active tables
        $table_progress = ($status['processed_tables'] / $status['total_tables']) * 100;
        
        // Factor in progress of active tables
        $active_progress = 0;
        if (!empty($status['active_table_states'])) {
            $active_tables_count = count($status['active_table_states']);
            $table_weight = (1 / $status['total_tables']) * 100;
            
            foreach ($status['active_table_states'] as $table_state) {
                $table_completion = 0;
                if ($table_state['total_rows'] > 0) {
                    $table_completion = ($table_state['processed_rows'] / $table_state['total_rows']);
                }
                
                $active_progress += $table_weight * $table_completion;
            }
        }
        
        $status['progress'] = min(99, $table_progress + $active_progress);
    }
    
    // UNIFIED STATUS MESSAGE - Always use the same format
    // If we have active tables, we're processing tables
    // If no active tables but we're still running, we're preparing to finalize
    if (!empty($status['active_table_states'])) {
    $active_tables = array_keys($status['active_table_states']);
    $status['current_tables'] = $active_tables; // Store as array for the JS
    
    // Calculate percentage for display
    $percentage = ($status['total_tables'] > 0) ? 
        round(($status['processed_tables'] / $status['total_tables']) * 100, 1) : 0;
    
    // Single, consistent status message format with percentage
    $status['message'] = sprintf(
        __('Processing %d of %d tables (%.1f%%, %s)', 'swiftspeed-siberian'),
        $status['processed_tables'],
        $status['total_tables'],
        $percentage,
        size_format($status['db_size'], 2)
    );
} else {
    // Calculate percentage for display
    $percentage = ($status['total_tables'] > 0) ? 
        round(($status['processed_tables'] / $status['total_tables']) * 100, 1) : 0;
    
    // About to finalize
    $status['message'] = sprintf(
        __('Processing %d of %d tables (%.1f%%, %s)', 'swiftspeed-siberian'),
        $status['processed_tables'],
        $status['total_tables'],
        $percentage,
        size_format($status['db_size'], 2)
    );
    
    // Clear current tables since we're not actively processing any
    $status['current_tables'] = [];
}
    
    // Track final memory usage for this step
    $status['memory_usage'][] = [
        'phase' => 'end_process_next',
        'memory' => memory_get_usage(true),
        'time' => microtime(true)
    ];
    
    // Update status
    $this->update_status($status);
    
    // Force garbage collection if available
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
    
    return $status;
}
    
    /**
     * Export the structure of a table.
     *
     * @param mysqli $conn Database connection.
     * @param array $table_state Current table state.
     * @return array|WP_Error Updated table state or error.
     */
    private function export_table_structure($conn, $table_state) {
        $table = $table_state['table'];
        $sql_file = $table_state['sql_file'];
        
        try {
            // Open file for writing
            $file = fopen($sql_file, 'w');
            if (!$file) {
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Could not open output file for table: %s', 'swiftspeed-siberian'), $table)
                );
            }
            
            // Write table header and drop statement
            fwrite($file, "-- Table structure for table `{$table}`\n");
            fwrite($file, "DROP TABLE IF EXISTS `{$table}`;\n");
            
            // Get create table statement
            $create_table_result = $conn->query("SHOW CREATE TABLE `{$table}`");
            if (!$create_table_result) {
                fclose($file);
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Error getting table structure for %s: %s', 'swiftspeed-siberian'), $table, $conn->error)
                );
            }
            
            $row = $create_table_result->fetch_assoc();
            if (!isset($row['Create Table'])) {
                fclose($file);
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Invalid CREATE TABLE statement for %s', 'swiftspeed-siberian'), $table)
                );
            }
            
            fwrite($file, $row['Create Table'] . ";\n\n");
            fclose($file);
            
            $this->log_message('Successfully exported structure for table: ' . $table);
            
            // Get columns for later data export
            $columns_result = $conn->query("SHOW COLUMNS FROM `{$table}`");
            $columns = [];
            
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = '`' . $column['Field'] . '`';
            }
            
            $table_state['columns'] = $columns;
            
            return $table_state;
            
        } catch (Exception $e) {
            if (isset($file) && $file) {
                fclose($file);
            }
            $this->log_message('Exception exporting table structure: ' . $e->getMessage());
            return new WP_Error('structure_export_error', $e->getMessage());
        }
    }
    
    /**
     * Export a batch of data from a table.
     *
     * @param mysqli $conn Database connection.
     * @param array $table_state Current table state.
     * @return array|WP_Error Updated table state or error.
     */
    private function export_table_data_batch($conn, $table_state) {
        $table = $table_state['table'];
        $sql_file = $table_state['sql_file'];
        $offset = $table_state['offset'];
        $batch_size = $table_state['batch_size'];
        $total_rows = $table_state['total_rows'];
        $columns = isset($table_state['columns']) ? $table_state['columns'] : [];
        
        // Skip if no rows or columns
        if ($total_rows == 0 || empty($columns)) {
            // Just update processed rows and return
            $table_state['processed_rows'] = 0;
            return $table_state;
        }
        
        try {
            // Open file for appending
            $file = fopen($sql_file, 'a');
            if (!$file) {
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Could not open output file for table data: %s', 'swiftspeed-siberian'), $table)
                );
            }
            
            // Add data header if this is the first batch
            if ($offset == 0) {
                fwrite($file, "-- Data for table `{$table}`\n");
            }
            
            // Prepare and execute query for this batch
            $query = "SELECT * FROM `{$table}` LIMIT ?, ?";
            $stmt = $conn->prepare($query);
            
            if (!$stmt) {
                fclose($file);
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Failed to prepare statement for %s: %s', 'swiftspeed-siberian'), $table, $conn->error)
                );
            }
            
            $stmt->bind_param("ii", $offset, $batch_size);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if (!$result) {
                fclose($file);
                return new WP_Error(
                    'critical_error',
                    sprintf(__('Error retrieving data from table: %s', 'swiftspeed-siberian'), $conn->error)
                );
            }
            
            $rows_in_batch = $result->num_rows;
            
            if ($rows_in_batch > 0) {
                // Write INSERT statement with column list
                fwrite($file, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES\n");
                
                $row_count = 0;
                
                while ($row = $result->fetch_assoc()) {
                    $row_count++;
                    
                    // Format values
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = is_null($value) ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                    }
                    
                    // Determine if this is the last row in the batch
                    $is_last_row = ($row_count == $rows_in_batch);
                    $line_suffix = $is_last_row ? ");\n\n" : "),\n";
                    
                    fwrite($file, '(' . implode(', ', $values) . $line_suffix);
                }
            }
            
            // Update table state
            $table_state['processed_rows'] += $rows_in_batch;
            $table_state['offset'] += $batch_size;
            
            fclose($file);
            $stmt->close();
            
            // Log progress periodically
            if ($offset == 0 || ($offset % ($batch_size * 10)) == 0 || $table_state['offset'] >= $total_rows) {
                $this->log_message(sprintf(
                    'Exported %d/%d rows from table %s (%.1f%%)',
                    $table_state['processed_rows'],
                    $total_rows,
                    $table,
                    ($table_state['processed_rows'] / max(1, $total_rows)) * 100
                ));
            }
            
            return $table_state;
            
        } catch (Exception $e) {
            if (isset($file) && $file) {
                fclose($file);
            }
            $this->log_message('Exception exporting table data: ' . $e->getMessage());
            return new WP_Error('data_export_error', $e->getMessage());
        }
    }
    
    /**
     * Calculate the optimal batch size for a table based on row count
     * 
     * @param int $row_count Number of rows in the table
     * @return int Optimal batch size
     */
    private function calculate_optimal_batch_size($row_count) {
        // Base batch size from constructor
        $base_batch_size = $this->db_batch_base;
        
        // Adjust based on table size
        if ($row_count > 1000000) {
            // Very large tables - use smaller batches for reliability
            $adjustment_factor = 0.2;
        } else if ($row_count > 100000) {
            // Large tables
            $adjustment_factor = 0.5;
        } else if ($row_count > 10000) {
            // Medium tables
            $adjustment_factor = 1.0;
        } else if ($row_count > 1000) {
            // Small-medium tables
            $adjustment_factor = 2.0;
        } else {
            // Very small tables - use larger batches for efficiency
            $adjustment_factor = 4.0;
        }
        
        // Calculate optimal batch size
        $optimal_batch_size = round($base_batch_size * $adjustment_factor);
        
        // Constrain to limits
        return max($this->min_batch_size, min($this->max_batch_size, $optimal_batch_size));
    }
    
    /**
     * Get the available memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    protected function get_memory_limit() {
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
                break;
            default:
                // No unit specified, value is already in bytes
                break;
        }
        
        return $value;
    }
    
    /**
     * Create a README file for the backup with improved information.
     *
     * @param array $status Current backup status.
     * @return string README contents.
     */
    protected function create_backup_readme($status) {
        $site_url = site_url();
        $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        
        $readme = "Siberian CMS Database Backup\n";
        $readme .= "==============================\n\n";
        $readme .= "Backup created on: {$date}\n";
        $readme .= "Site URL: {$site_url}\n";
        $readme .= "Backup type: Database Only\n";
        
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : [];
        $readme .= "Database: " . (isset($db_options['database']) ? $db_options['database'] : 'Unknown') . "\n";
        $readme .= "Tables: " . $status['total_tables'] . "\n";
        $readme .= "Total rows: " . $status['total_rows'] . "\n";
        $readme .= "Database size: " . size_format($status['db_size'], 2) . "\n";
        $readme .= "Tables processed per batch: " . $status['tables_per_batch'] . "\n";
        
        // Performance metrics
        if (isset($status['elapsed_time']) && $status['elapsed_time'] > 0) {
            $minutes = floor($status['elapsed_time'] / 60);
            $seconds = round($status['elapsed_time'] % 60);
            $readme .= "Backup duration: {$minutes}m {$seconds}s\n";
            
            if (isset($status['bytes_per_second']) && $status['bytes_per_second'] > 0) {
                $speed = size_format($status['bytes_per_second'], 2);
                $readme .= "Average speed: {$speed}/s\n";
            }
        }
        
        // Add error information
        if (!empty($status['errors'])) {
            $readme .= "\nWarning: " . count($status['errors']) . " errors occurred during backup.\n";
        }
        
        // Add critical error information
        if (!empty($status['critical_errors'])) {
            $readme .= "\n**CRITICAL ERRORS OCCURRED**\n";
            $readme .= "WARNING: " . count($status['critical_errors']) . " critical errors occurred during backup.\n";
            $readme .= "This backup may be incomplete or corrupted.\n";
            
            foreach ($status['critical_errors'] as $error) {
                $readme .= "- " . $error['message'] . "\n";
            }
        }
        
        $readme .= "\nCreated by SwiftSpeed Siberian Integration Plugin\n";
        return $readme;
    }
    
    /**
     * Create the final backup archive with optimized ZIP creation.
     *
     * @param array $status Current backup status.
     * @return array|WP_Error Updated backup status or error.
     */
    public function create_final_archive($status) {
        if ($status['processed_tables'] == 0) {
            $this->log_message('No tables were processed for backup');
            $status['status'] = 'error';
            $status['message'] = __('No tables were processed for backup', 'swiftspeed-siberian');
            $this->update_status($status);
            return $status;
        }
        
        // Increase limits
        @ini_set('memory_limit', '2048M');
        @set_time_limit(600); // 10 minutes
        
        $this->log_memory_usage('Before creating archive');
        
        // Use parent method for consistent handling of backup history
        return parent::create_final_archive($status);
    }
    
    /**
     * Cancel an in-progress backup.
     *
     * @param array $status Current backup status.
     * @return bool True on success, false on failure.
     */
    public function cancel_backup($status) {
        if (empty($status)) {
            return false;
        }
        
        $this->log_message('Canceling backup: ' . $status['id']);
        
        // Close database connection
        $this->close_db_connection();
        
        // Clean up temp directory
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            $this->cleanup_temp_files($status['temp_dir']);
        }
        
        return true;
    }
    
    /**
     * Destructor to ensure connections are closed.
     */
    public function __destruct() {
        $this->close_db_connection();
    }
}