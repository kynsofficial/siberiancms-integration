<?php
/**
 * Core Backup & Restore functionality for the plugin.
 * This refactored class serves as a central hub that coordinates all sub-components.
 * 
 * @since 2.3.0
 */
class SwiftSpeed_Siberian_Backup_Restore_Core {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Storage manager instance.
     * 
     * @var SwiftSpeed_Siberian_Storage_Manager
     */
    private $storage_manager;
    
    /**
     * UI instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Restore_UI
     */
    private $ui;
    
    /**
     * Backup processor instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Processor
     */
    private $backup_processor;
    
    /**
     * Restore processor instance.
     * 
     * @var SwiftSpeed_Siberian_Restore_Processor
     */
    private $restore_processor;
    
    /**
     * Settings manager instance.
     * 
     * @var SwiftSpeed_Siberian_Settings_Manager
     */
    private $settings_manager;
    
    /**
     * Cron manager instance.
     * 
     * @var SwiftSpeed_Siberian_Cron_Manager
     */
    private $cron_manager;
    
    /**
     * Backup directory path.
     * 
     * @var string
     */
    private $backup_dir;
    
    /**
     * Initialize the class and set up all sub-components.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Set up the backup directory
        $this->backup_dir = WP_CONTENT_DIR . '/swsib-backups/';
        
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components in order of dependencies
        $this->initialize_storage_manager();
        $this->initialize_settings_manager();
        $this->initialize_backup_processor();
        $this->initialize_restore_processor();
        $this->initialize_cron_manager();
        $this->initialize_ui();
    }
    
    /**
     * Load dependencies for all components.
     *
     * @return void
     */
    private function load_dependencies() {
        // Base classes
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-interface.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-base-backup.php';
        
        // Storage providers
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-manager.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/storage/class-swsib-storage-local.php';
        
        // Backup classes
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-file-backup.php';
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup/class-swsib-full-backup.php';
        
        // Restore class
        require_once SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/restore/class-swsib-restore.php';
    }
    
    /**
     * Initialize the storage manager.
     *
     * @return void
     */
    private function initialize_storage_manager() {
        $this->storage_manager = new SwiftSpeed_Siberian_Storage_Manager();
    }
    
    /**
     * Initialize the settings manager.
     *
     * @return void
     */
    private function initialize_settings_manager() {
        $this->settings_manager = new SwiftSpeed_Siberian_Settings_Manager();
        $this->settings_manager->set_storage_manager($this->storage_manager);
    }
    
    /**
     * Initialize the backup processor.
     *
     * @return void
     */
    private function initialize_backup_processor() {
        $this->backup_processor = new SwiftSpeed_Siberian_Backup_Processor();
        $this->backup_processor->set_storage_manager($this->storage_manager);
    }
    
    /**
     * Initialize the restore processor.
     *
     * @return void
     */
    private function initialize_restore_processor() {
        $this->restore_processor = new SwiftSpeed_Siberian_Restore_Processor();
    }
    
    /**
     * Initialize the cron manager.
     *
     * @return void
     */
    private function initialize_cron_manager() {
        $this->cron_manager = new SwiftSpeed_Siberian_Cron_Manager();
        $this->cron_manager->set_backup_processor($this->backup_processor);
        $this->cron_manager->set_storage_manager($this->storage_manager);
    }
    
    /**
     * Initialize the UI handler.
     *
     * @return void
     */
    private function initialize_ui() {
        $this->ui = new SwiftSpeed_Siberian_Backup_Restore_UI();
        $this->ui->set_core($this);
        $this->ui->set_storage_manager($this->storage_manager);
    }
    
    /**
     * Get the storage manager instance.
     *
     * @return SwiftSpeed_Siberian_Storage_Manager Storage manager instance.
     */
    public function get_storage_manager() {
        return $this->storage_manager;
    }
    
    /**
     * Get the UI instance.
     *
     * @return SwiftSpeed_Siberian_Backup_Restore_UI UI instance.
     */
    public function get_ui() {
        return $this->ui;
    }
    
    /**
     * Get the backup processor instance.
     *
     * @return SwiftSpeed_Siberian_Backup_Processor Backup processor instance.
     */
    public function get_backup_processor() {
        return $this->backup_processor;
    }
    
    /**
     * Get the restore processor instance.
     *
     * @return SwiftSpeed_Siberian_Restore_Processor Restore processor instance.
     */
    public function get_restore_processor() {
        return $this->restore_processor;
    }
    
    /**
     * Get the settings manager instance.
     *
     * @return SwiftSpeed_Siberian_Settings_Manager Settings manager instance.
     */
    public function get_settings_manager() {
        return $this->settings_manager;
    }
    
    /**
     * Get the cron manager instance.
     *
     * @return SwiftSpeed_Siberian_Cron_Manager Cron manager instance.
     */
    public function get_cron_manager() {
        return $this->cron_manager;
    }
    
    /**
     * Log a message for debugging.
     * 
     * @param string $message The message to log.
     * @return void
     */
    public function log_message($message) {
        if (function_exists('swsib') && swsib()->logging) {
            swsib()->logging->write_to_log('backup', 'backup', $message);
        }
    }
}