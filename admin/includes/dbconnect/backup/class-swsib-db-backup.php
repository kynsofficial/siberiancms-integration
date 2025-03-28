<?php
/**
 * Database backup functionality for the plugin.
 */
class SwiftSpeed_Siberian_DB_Backup {

    private $options;
    private $backup_dir;
    private $backup_url;

    public function __construct() {
        $this->options    = swsib()->get_options();
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        $this->backup_url = content_url('swsib-backups/');
        $this->ensure_backup_directory();

        // Register AJAX handlers
        add_action('wp_ajax_swsib_start_backup', array($this, 'ajax_start_backup'));
        add_action('wp_ajax_swsib_backup_progress', array($this, 'ajax_backup_progress'));
        add_action('wp_ajax_swsib_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_swsib_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_swsib_process_next_table', array($this, 'ajax_process_next_table'));
        add_action('wp_ajax_swsib_cancel_backup', array($this, 'ajax_cancel_backup'));

        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        if (!wp_next_scheduled('swsib_cleanup_old_backups')) {
            wp_schedule_event(time(), 'daily', 'swsib_cleanup_old_backups');
            $this->log_message('Scheduled daily cleanup of old backups.');
        }
        add_action('swsib_cleanup_old_backups', array($this, 'cleanup_old_backups'));
    }

    /**
     * Write critical messages to the central logging manager.
     */
    private function log_message($message) {
        if (swsib()->logging) {
            swsib()->logging->write_to_log('db_connect', 'backend', $message);
        }
    }

    private function ensure_backup_directory() {
        if (!file_exists($this->backup_dir)) {
            if (!wp_mkdir_p($this->backup_dir)) {
                $this->log_message('Failed to create backup directory: ' . $this->backup_dir);
            }
        }
        $htaccess_file = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order deny,allow\nDeny from all");
        }
        $index_file = $this->backup_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, "<?php\n// Silence is golden.");
        }
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        wp_enqueue_style(
            'swsib-db-backup-css',
            SWSIB_PLUGIN_URL . 'admin/includes/dbconnect/backup/db-backup.css',
            array(),
            SWSIB_VERSION . '.' . time()
        );
        wp_enqueue_script(
            'swsib-db-backup-js',
            SWSIB_PLUGIN_URL . 'admin/includes/dbconnect/backup/db-backup.js',
            array('jquery'),
            SWSIB_VERSION . '.' . time(),
            true
        );
        wp_localize_script(
            'swsib-db-backup-js',
            'swsib_db_backup',
            array(
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('swsib_db_backup_nonce'),
                'starting_backup'  => __('Starting backup process...', 'swiftspeed-siberian'),
                'confirm_delete'   => __('Are you sure you want to delete this backup?', 'swiftspeed-siberian'),
                'no_backups'       => __('No backups available yet.', 'swiftspeed-siberian')
            )
        );
    }

    public function display_settings() {
        $backups = $this->get_available_backups();
        ?>
        <hr style="margin: 30px 0 20px 0;">
        <div class="swsib-section-header">
            <h3><?php _e('Database Backup', 'swiftspeed-siberian'); ?></h3>
        </div>
        <div class="swsib-field">
            <div class="swsib-notice info">
                <p><?php _e('Create a backup of your Siberian CMS database for safekeeping. This will export all tables into a single restorable SQL file.', 'swiftspeed-siberian'); ?></p>
            </div>
            <div class="swsib-backup-container">
                <div class="swsib-backup-controls">
                    <button type="button" id="start-db-backup" class="button button-primary">
                        <span class="dashicons dashicons-database-export"></span>
                        <?php _e('Create New Backup', 'swiftspeed-siberian'); ?>
                    </button>
                    <div id="backup-progress-container" style="display: none;">
                        <div class="backup-progress-bar">
                            <div class="backup-progress-fill"></div>
                        </div>
                        <div class="backup-status-text"></div>
                        <div class="backup-stats"></div>
                        <div class="backup-cancel-container">
                            <button type="button" id="cancel-db-backup" class="button">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php _e('Cancel Backup', 'swiftspeed-siberian'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="swsib-backup-history">
                    <h4><?php _e('Backup History', 'swiftspeed-siberian'); ?></h4>
                    <?php if (empty($backups)): ?>
                        <p class="no-backups-message"><?php _e('No backups available yet.', 'swiftspeed-siberian'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'swiftspeed-siberian'); ?></th>
                                    <th><?php _e('File Size', 'swiftspeed-siberian'); ?></th>
                                    <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td><?php echo esc_html($backup['date']); ?></td>
                                        <td><?php echo esc_html($backup['size']); ?></td>
                                        <td class="backup-actions">
                                            <a href="<?php echo esc_url($backup['url']); ?>" class="button download-backup">
                                                <span class="dashicons dashicons-download"></span>
                                                <?php _e('Download', 'swiftspeed-siberian'); ?>
                                            </a>
                                            <a href="#" class="button delete-backup" data-file="<?php echo esc_attr($backup['file']); ?>">
                                                <span class="dashicons dashicons-trash"></span>
                                                <?php _e('Delete', 'swiftspeed-siberian'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_available_backups() {
        $backups = array();
        if (!file_exists($this->backup_dir)) {
            return $backups;
        }
        $files = glob($this->backup_dir . 'siberian-backup-*.zip');
        if (!$files) {
            return $backups;
        }
        foreach ($files as $file) {
            $filename  = basename($file);
            $timestamp = filemtime($file);
            $size      = size_format(filesize($file), 2);

            // Use custom nonce param name for download
            $download_url = add_query_arg(array(
                'action'                        => 'swsib_download_backup',
                'file'                          => $filename,
                'swsib_download_backup_nonce'   => wp_create_nonce('swsib_download_backup_nonce'),
            ), admin_url('admin-ajax.php'));

            $backups[] = array(
                'file' => $filename,
                'path' => $file,
                'url'  => $download_url,
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp),
                'size' => $size
            );
        }
        usort($backups, function($a, $b) {
            return filemtime($b['path']) - filemtime($a['path']);
        });
        return $backups;
    }

    // AJAX handler to start the backup
    public function ajax_start_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_db_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        if (!file_exists($this->backup_dir) && !wp_mkdir_p($this->backup_dir)) {
            $this->log_message('Failed to create backup directory during start_backup.');
            wp_send_json_error(array('message' => __('Failed to create backup directory', 'swiftspeed-siberian')));
        }
        $backup_id = 'siberian-backup-' . date('Y-m-d-H-i-s') . '-' . wp_generate_password(8, false);
        $temp_dir  = $this->backup_dir . $backup_id . '/';
        if (!file_exists($temp_dir) && !wp_mkdir_p($temp_dir)) {
            $this->log_message('Failed to create temporary directory: ' . $temp_dir);
            wp_send_json_error(array('message' => __('Failed to create temporary directory', 'swiftspeed-siberian')));
        }
        $status = array(
            'id'               => $backup_id,
            'temp_dir'         => $temp_dir,
            'tables'           => array(),
            'current_table'    => '',
            'total_tables'     => 0,
            'processed_tables' => 0,
            'total_rows'       => 0,
            'processed_rows'   => 0,
            'started'          => time(),
            'status'           => 'initializing',
            'message'          => __('Initializing backup...', 'swiftspeed-siberian'),
            'progress'         => 0
        );
        update_option('swsib_current_backup', $status);
        $result = $this->start_backup_process($status);
        if (is_wp_error($result)) {
            $this->log_message('Backup start failed: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        $this->log_message('Backup process started with ID ' . $backup_id);
        wp_send_json_success($status);
    }

    private function start_backup_process($status) {
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        if (empty($db_options['host']) || empty($db_options['database']) || empty($db_options['username']) || empty($db_options['password'])) {
            $this->log_message('Backup start aborted: Database configuration is incomplete.');
            return new WP_Error('db_config', __('Database configuration is incomplete', 'swiftspeed-siberian'));
        }
        $conn = new mysqli(
            $db_options['host'],
            $db_options['username'],
            $db_options['password'],
            $db_options['database'],
            isset($db_options['port']) ? intval($db_options['port']) : 3306
        );
        if ($conn->connect_error) {
            $this->log_message('Backup start aborted: ' . $conn->connect_error);
            return new WP_Error('db_connect', $conn->connect_error);
        }
        $conn->set_charset('utf8');
        $tables = array();
        $result = $conn->query('SHOW TABLES');
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        if (empty($tables)) {
            $this->log_message('Backup aborted: No tables found in database.');
            return new WP_Error('no_tables', __('No tables found in database', 'swiftspeed-siberian'));
        }
        $status['tables']       = $tables;
        $status['total_tables'] = count($tables);
        $status['status']       = 'get_table_data';
        $status['message']      = __('Analyzing database structure...', 'swiftspeed-siberian');
        update_option('swsib_current_backup', $status);

        $total_rows = 0;
        foreach ($tables as $table) {
            $result = $conn->query("SELECT COUNT(*) AS count FROM `{$table}`");
            $row    = $result->fetch_assoc();
            $total_rows += $row['count'];
        }
        $status['total_rows'] = $total_rows;
        update_option('swsib_current_backup', $status);

        $status = $this->process_next_table($status);
        $conn->close();
        return $status;
    }

    private function process_next_table($status) {
        set_time_limit(300);
        if ($status['processed_tables'] >= $status['total_tables']) {
            $this->log_message('All tables processed. Creating final archive.');
            $this->create_final_archive($status);
            return $status;
        }
        $table = $status['tables'][$status['processed_tables']];
        $status['current_table']   = $table;
        $status['status']          = 'processing_table';
        $status['message']         = sprintf(
            __('Backing up table: %s (%d of %d)', 'swiftspeed-siberian'),
            $table,
            $status['processed_tables'] + 1,
            $status['total_tables']
        );
        update_option('swsib_current_backup', $status);

        $result = $this->export_table($status, $table);
        if (is_wp_error($result)) {
            $status['status']  = 'error';
            $status['message'] = $result->get_error_message();
            update_option('swsib_current_backup', $status);
            $this->log_message('Error exporting table ' . $table . ': ' . $result->get_error_message());
            return $status;
        }

        $status['processed_tables']++;
        if ($status['total_tables'] > 0) {
            $status['progress'] = ($status['processed_tables'] / $status['total_tables']) * 100;
        }
        update_option('swsib_current_backup', $status);
        return $status;
    }

    private function export_table($status, $table) {
        set_time_limit(300);
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        $conn = new mysqli(
            $db_options['host'],
            $db_options['username'],
            $db_options['password'],
            $db_options['database'],
            isset($db_options['port']) ? intval($db_options['port']) : 3306
        );
        if ($conn->connect_error) {
            return new WP_Error('db_connect', $conn->connect_error);
        }
        $conn->set_charset('utf8');

        $output_file = $status['temp_dir'] . $table . '.sql';
        $file = fopen($output_file, 'w');
        if (!$file) {
            return new WP_Error(
                'file_open',
                sprintf(__('Could not open output file for table: %s', 'swiftspeed-siberian'), $table)
            );
        }

        // Table structure
        fwrite($file, "-- Table structure for table `{$table}`\n");
        fwrite($file, "DROP TABLE IF EXISTS `{$table}`;\n");
        $create_table_result = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$create_table_result) {
            fclose($file);
            return new WP_Error(
                'query_error',
                sprintf(__('Error getting table structure for %s: %s', 'swiftspeed-siberian'), $table, $conn->error)
            );
        }
        $row = $create_table_result->fetch_assoc();
        fwrite($file, $row['Create Table'] . ";\n\n");

        // Data
        $count_result = $conn->query("SELECT COUNT(*) AS count FROM `{$table}`");
        $row_count    = $count_result->fetch_assoc()['count'];

        if ($row_count > 0) {
            fwrite($file, "-- Data for table `{$table}`\n");
            $columns_result = $conn->query("SHOW COLUMNS FROM `{$table}`");
            $columns = array();
            while ($column = $columns_result->fetch_assoc()) {
                $columns[] = '`' . $column['Field'] . '`';
            }

            // If small table, export in one go
            if ($row_count <= 500) {
                $data_result = $conn->query("SELECT * FROM `{$table}`");
                if (!$data_result) {
                    fclose($file);
                    return new WP_Error(
                        'query_error',
                        sprintf(__('Error exporting data for %s: %s', 'swiftspeed-siberian'), $table, $conn->error)
                    );
                }
                if ($data_result->num_rows > 0) {
                    fwrite($file, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES\n");
                    $row_index = 0;
                    while ($row = $data_result->fetch_assoc()) {
                        $row_index++;
                        $status['processed_rows']++;
                        $values = array();
                        foreach ($row as $value) {
                            $values[] = is_null($value) ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                        }
                        $suffix = $row_index == $row_count ? ");\n\n" : "),\n";
                        fwrite($file, '(' . implode(', ', $values) . $suffix);
                    }
                }
            } else {
                // Large table: batch export
                $batch_size = ($row_count > 10000) ? 1000 : 500;
                $batches    = ceil($row_count / $batch_size);
                for ($batch = 0; $batch < $batches; $batch++) {
                    $offset      = $batch * $batch_size;
                    $data_result = $conn->query("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}");
                    if (!$data_result) {
                        fclose($file);
                        return new WP_Error(
                            'query_error',
                            sprintf(__('Error exporting data for %s: %s', 'swiftspeed-siberian'), $table, $conn->error)
                        );
                    }
                    if ($data_result->num_rows > 0) {
                        fwrite($file, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES\n");
                        $row_index  = 0;
                        $batch_rows = $data_result->num_rows;
                        while ($row = $data_result->fetch_assoc()) {
                            $row_index++;
                            $status['processed_rows']++;
                            $values = array();
                            foreach ($row as $value) {
                                $values[] = is_null($value) ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                            }
                            $suffix = $row_index == $batch_rows ? ");\n\n" : "),\n";
                            fwrite($file, '(' . implode(', ', $values) . $suffix);
                        }
                    }
                    // Update progress every few batches
                    if ($batch % 5 === 0 || $batch === $batches - 1) {
                        $percent_complete = min(100, (($offset + $batch_size) / $row_count) * 100);
                        $status['message'] = sprintf(
                            __('Backing up table: %s (%d/%d tables, %d%% of rows)', 'swiftspeed-siberian'),
                            $table,
                            $status['processed_tables'] + 1,
                            $status['total_tables'],
                            floor($percent_complete)
                        );
                        if ($status['total_tables'] > 0) {
                            $table_progress = $status['processed_tables'] / $status['total_tables'];
                            $row_progress   = ($percent_complete / 100) / $status['total_tables'];
                            $status['progress'] = min(95, ($table_progress + $row_progress) * 100);
                        }
                        update_option('swsib_current_backup', $status);
                        update_option('swsib_last_backup_update', time());
                    }
                }
            }
        } else {
            fwrite($file, "-- Table `{$table}` has no data\n\n");
            $status['processed_tables']++;
            if ($status['total_tables'] > 0) {
                $status['progress'] = ($status['processed_tables'] / $status['total_tables']) * 100;
            }
            update_option('swsib_current_backup', $status);
        }

        fclose($file);
        $conn->close();
        return true;
    }

    // Create a final ZIP archive with a combined SQL file and README
    private function create_final_archive($status) {
        $status['status']   = 'creating_archive';
        $status['message']  = __('Creating backup archive...', 'swiftspeed-siberian');
        $status['progress'] = 95;
        update_option('swsib_current_backup', $status);

        $zip_file = $this->backup_dir . $status['id'] . '.zip';
        $this->log_message('Creating final archive: ' . $zip_file);

        // Combine SQL files
        $combined_sql = $status['temp_dir'] . 'backup.sql';
        $sql_files    = glob($status['temp_dir'] . '*.sql');
        $combined_handle = fopen($combined_sql, 'w');
        if (!$combined_handle) {
            $status['status']  = 'error';
            $status['message'] = __('Could not create combined SQL file', 'swiftspeed-siberian');
            update_option('swsib_current_backup', $status);
            $this->log_message('Failed to create combined SQL file.');
            return;
        }
        foreach ($sql_files as $file) {
            $contents = file_get_contents($file);
            if ($contents !== false) {
                fwrite($combined_handle, $contents . "\n\n");
            }
        }
        fclose($combined_handle);

        // Create ZIP
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
            $status['status']  = 'error';
            $status['message'] = __('Could not create ZIP archive', 'swiftspeed-siberian');
            update_option('swsib_current_backup', $status);
            $this->log_message('Could not create ZIP archive.');
            return;
        }
        $zip->addFile($combined_sql, 'backup.sql');
        $readme = $this->create_backup_readme($status);
        $zip->addFromString('README.txt', $readme);
        $zip->close();

        // Cleanup temp files
        array_map('unlink', glob($status['temp_dir'] . '*.sql'));
        if (file_exists($combined_sql)) {
            unlink($combined_sql);
        }
        rmdir($status['temp_dir']);

        $status['status']    = 'completed';
        $status['message']   = __('Backup completed successfully', 'swiftspeed-siberian');
        $status['progress']  = 100;
        $status['completed'] = time();
        $status['file']      = basename($zip_file);
        $status['size']      = size_format(filesize($zip_file), 2);
        update_option('swsib_current_backup', $status);

        $this->log_message('Backup completed. Archive: ' . $status['file'] . ' (' . $status['size'] . ')');
    }

    // AJAX handler to process the next table (one per call)
    public function ajax_process_next_table() {
        set_time_limit(300);
        check_ajax_referer('swsib_db_backup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        $status = get_option('swsib_current_backup', array());
        if (empty($status) || in_array($status['status'], array('error','completed'))) {
            wp_die();
        }
        $status = $this->process_next_table($status);
        wp_send_json_success($status);
    }

    // AJAX handler for checking backup progress
    public function ajax_backup_progress() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_db_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        $status = get_option('swsib_current_backup', array());
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup found', 'swiftspeed-siberian')));
        }
        wp_send_json_success($status);
    }

    // AJAX handler for downloading a backup
    public function ajax_download_backup() {
        // Check our custom nonce parameter
        if (
            ! isset($_GET['swsib_download_backup_nonce']) ||
            ! wp_verify_nonce($_GET['swsib_download_backup_nonce'], 'swsib_download_backup_nonce')
        ) {
            wp_die(__('Security check failed', 'swiftspeed-siberian'));
        }
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied', 'swiftspeed-siberian'));
        }
        $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
        if (
            empty($file) ||
            pathinfo($file, PATHINFO_EXTENSION) !== 'zip' ||
            !file_exists($this->backup_dir . $file)
        ) {
            wp_die(__('Invalid backup file', 'swiftspeed-siberian'));
        }
        $file_path = $this->backup_dir . $file;
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        if (ob_get_level()) {
            ob_end_clean();
        }
        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            wp_die(__('Error opening file', 'swiftspeed-siberian'));
        }
        while (!feof($handle)) {
            echo fread($handle, 4096);
            flush();
        }
        fclose($handle);
        exit;
    }

    // AJAX handler for deleting a backup
    public function ajax_delete_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_db_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        $file = isset($_POST['file']) ? sanitize_file_name($_POST['file']) : '';
        if (
            empty($file) ||
            pathinfo($file, PATHINFO_EXTENSION) !== 'zip' ||
            !file_exists($this->backup_dir . $file)
        ) {
            wp_send_json_error(array('message' => __('Invalid backup file', 'swiftspeed-siberian')));
        }
        if (unlink($this->backup_dir . $file)) {
            $this->log_message('Deleted backup file: ' . $file);
            wp_send_json_success(array('message' => __('Backup deleted successfully', 'swiftspeed-siberian')));
        } else {
            $this->log_message('Failed to delete backup file: ' . $file);
            wp_send_json_error(array('message' => __('Could not delete backup file', 'swiftspeed-siberian')));
        }
    }

    // AJAX handler for canceling a backup
    public function ajax_cancel_backup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_db_backup_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        $status = get_option('swsib_current_backup', array());
        if (empty($status)) {
            wp_send_json_error(array('message' => __('No active backup to cancel', 'swiftspeed-siberian')));
        }
        if (!empty($status['temp_dir']) && file_exists($status['temp_dir'])) {
            array_map('unlink', glob($status['temp_dir'] . '*.*'));
            rmdir($status['temp_dir']);
        }
        delete_option('swsib_current_backup');
        delete_option('swsib_last_backup_update');
        $this->log_message('Backup process canceled.');
        wp_send_json_success(array('message' => __('Backup canceled successfully', 'swiftspeed-siberian')));
    }

    public function cleanup_old_backups() {
        $max_age     = 30 * DAY_IN_SECONDS;
        $max_backups = 10;
        $backups     = $this->get_available_backups();
        if (count($backups) > $max_backups) {
            $to_delete = array_slice($backups, $max_backups);
            foreach ($to_delete as $backup) {
                if (file_exists($backup['path'])) {
                    unlink($backup['path']);
                }
            }
            $this->log_message('Deleted backups exceeding maximum count.');
        }
        $current_time = time();
        foreach ($backups as $backup) {
            $file_time = filemtime($backup['path']);
            if (($current_time - $file_time) > $max_age) {
                if (file_exists($backup['path'])) {
                    unlink($backup['path']);
                    $this->log_message('Deleted old backup: ' . $backup['file']);
                }
            }
        }
    }

    // Create a readme file with backup information
    private function create_backup_readme($status) {
        $site_url   = site_url();
        $db_options = isset($this->options['db_connect']) ? $this->options['db_connect'] : array();
        $date       = date_i18n(get_option('date_format') . ' ' . get_option('time_format'));
        $readme     = "Siberian CMS Database Backup\n";
        $readme    .= "==============================\n\n";
        $readme    .= "Backup created on: {$date}\n";
        $readme    .= "Site URL: {$site_url}\n";
        $readme    .= "Database: {$db_options['database']}\n";
        $readme    .= "Tables: {$status['total_tables']}\n";
        $readme    .= "Total rows: {$status['total_rows']}\n\n";
        $readme    .= "Created by SwiftSpeed Siberian Integration Plugin\n";
        return $readme;
    }
}
?>
