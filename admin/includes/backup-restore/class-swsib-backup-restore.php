<?php
/**
 * Backup & Restore functionality for the plugin.
 * This is the bootstrap class that loads the split components.
 */
class SwiftSpeed_Siberian_Backup_Restore {
    
    /**
     * Core functionality instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Restore_Core
     */
    private $core;
    
    /**
     * UI functionality instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Restore_UI
     */
    private $ui;
    
    /**
     * Initialize the class.
     */
    public function __construct() {
        // Define and create the core directory if it doesn't exist
        $core_dir = SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/core/';
        if (!file_exists($core_dir)) {
            wp_mkdir_p($core_dir);
        }
        
        // Load core components
        require_once $core_dir . 'class-swsib-backup-restore-core.php';
        require_once $core_dir . 'class-swsib-backup-restore-ui.php';
        require_once $core_dir . 'class-swsib-backup-processor.php';
        require_once $core_dir . 'class-swsib-restore-processor.php';
        require_once $core_dir . 'class-swsib-settings-manager.php';
        require_once $core_dir . 'class-swsib-cron-manager.php';
        
        // Initialize core component - now responsible for bootstrapping all sub-components
        $this->core = new SwiftSpeed_Siberian_Backup_Restore_Core();
        $this->ui = $this->core->get_ui();
    }
    
    /**
     * Display the backup/restore settings page.
     *
     * @return void
     */
    public function display_settings() {
        $this->ui->display_settings();
    }
}