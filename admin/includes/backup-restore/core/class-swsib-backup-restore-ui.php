<?php
/**
 * UI handling for Backup & Restore functionality.
 * Performance optimized version
 * 
 * @since 2.3.0
 */
class SwiftSpeed_Siberian_Backup_Restore_UI {
    
    /**
     * Plugin options.
     * 
     * @var array
     */
    private $options;
    
    /**
     * Core functionality instance.
     * 
     * @var SwiftSpeed_Siberian_Backup_Restore_Core
     */
    private $core;
    
    /**
     * Storage manager instance.
     * 
     * @var SwiftSpeed_Siberian_Storage_Manager
     */
    private $storage_manager;
    
    /**
     * Cron manager instance.
     * 
     * @var SwiftSpeed_Siberian_Cron_Manager
     */
    private $cron_manager;
    
    /**
     * Cache for backup history to prevent redundant DB queries
     * 
     * @var array
     */
    private $backup_history_cache = null;
    
    /**
     * Cache for restore history to prevent redundant DB queries
     * 
     * @var array
     */
    private $restore_history_cache = null;
    
    /**
     * Initialize the class.
     */
    public function __construct() {
        $this->options = swsib()->get_options();
        
        // Enqueue scripts and styles with better dependency management
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handler for deleting restore history
        add_action('wp_ajax_swsib_delete_restore_history', array($this, 'ajax_delete_restore_history'));
    }
    
    /**
     * Set the core instance.
     *
     * @param SwiftSpeed_Siberian_Backup_Restore_Core $core Core instance.
     * @return void
     */
    public function set_core($core) {
        $this->core = $core;
    }
    
    /**
     * Set the storage manager instance.
     *
     * @param SwiftSpeed_Siberian_Storage_Manager $storage_manager Storage manager instance.
     * @return void
     */
    public function set_storage_manager($storage_manager) {
        $this->storage_manager = $storage_manager;
    }
    
    /**
     * Set the cron manager instance.
     *
     * @param SwiftSpeed_Siberian_Cron_Manager $cron_manager Cron manager instance.
     * @return void
     */
    public function set_cron_manager($cron_manager) {
        $this->cron_manager = $cron_manager;
    }
    
    /**
     * Enqueue scripts and styles with improved loading.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'swsib-integration') === false) {
            return;
        }
        
        // Add version for cache busting
        $version = defined('WP_DEBUG') && WP_DEBUG ? time() : SWSIB_VERSION;
        
        // Enqueue minified CSS if not in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_enqueue_style(
                'swsib-backup-restore-css',
                SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.css',
                array(),
                $version
            );
        } else {
            // Use minified CSS if available, otherwise use regular CSS
            $min_file = SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.min.css';
            $regular_file = SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.css';
            
            wp_enqueue_style(
                'swsib-backup-restore-css',
                file_exists(SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup-restore.min.css') ? $min_file : $regular_file,
                array(),
                $version
            );
        }
        
        // Defer loading JavaScript to improve page render time
        // Use minified JS if not in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_enqueue_script(
                'swsib-backup-restore-js',
                SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.js',
                array('jquery'),
                $version,
                true // Load in footer for faster page rendering
            );
        } else {
            // Use minified JS if available, otherwise use regular JS
            $min_file = SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.min.js';
            $regular_file = SWSIB_PLUGIN_URL . 'admin/includes/backup-restore/backup-restore.js';
            
            wp_enqueue_script(
                'swsib-backup-restore-js',
                file_exists(SWSIB_PLUGIN_DIR . 'admin/includes/backup-restore/backup-restore.min.js') ? $min_file : $regular_file,
                array('jquery'),
                $version,
                true // Load in footer for faster page rendering
            );
        }
        
        wp_localize_script(
            'swsib-backup-restore-js',
            'swsib_backup_restore',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('swsib_backup_restore_nonce'),
                'backup_nonce' => wp_create_nonce('swsib_backup_nonce'),
                'restore_nonce' => wp_create_nonce('swsib_restore_nonce'),
                'confirm_delete' => __('Are you sure you want to delete this backup?', 'swiftspeed-siberian'),
                'confirm_restore' => __('Are you sure you want to restore this backup? This will overwrite your current database and/or files.', 'swiftspeed-siberian'),
                'confirm_cancel' => __('Are you sure you want to cancel this process?', 'swiftspeed-siberian'),
                'no_backups' => __('No backups available yet.', 'swiftspeed-siberian'),
                'starting_backup' => __('Starting backup process...', 'swiftspeed-siberian'),
                'starting_restore' => __('Starting restore process...', 'swiftspeed-siberian'),
                'backup_error' => __('Error during backup process', 'swiftspeed-siberian'),
                'restore_error' => __('Error during restore process', 'swiftspeed-siberian'),
                'background_enabled' => __('Backup will continue in the background even if you close this page.', 'swiftspeed-siberian'),
                'confirm_delete_history' => __('Are you sure you want to delete the restore history?', 'swiftspeed-siberian'),
                'confirm_delete_schedule' => __('Are you sure you want to delete this backup schedule?', 'swiftspeed-siberian'),
                'add_schedule' => __('Add Schedule', 'swiftspeed-siberian'),
                'edit_schedule' => __('Edit Schedule', 'swiftspeed-siberian'),
                'schedule_added' => __('Backup schedule added successfully', 'swiftspeed-siberian'),
                'schedule_updated' => __('Backup schedule updated successfully', 'swiftspeed-siberian'),
                'schedule_deleted' => __('Backup schedule deleted successfully', 'swiftspeed-siberian'),
                'duplicate_schedule' => __('You already have a schedule for this backup type. Only one schedule per backup type is allowed.', 'swiftspeed-siberian'),
            )
        );
    }
    
    /**
     * Display settings page with optimized loading.
     *
     * @return void
     */
    public function display_settings() {
        // Check if a URL hash is present - we'll use this to identify active tab
        $hash = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '#') !== false 
            ? substr($_SERVER['REQUEST_URI'], strpos($_SERVER['REQUEST_URI'], '#') + 1) 
            : '';
        
        // Check if we have a URL hash that matches our tabs
        $has_backup_hash = ($hash === 'backup');
        $has_settings_hash = ($hash === 'settings');
        
        // Check for POST data active_tab from form submission
        $post_active_tab = isset($_POST['active_tab']) ? sanitize_key($_POST['active_tab']) : '';
        
        // Check for GET section parameter
        $get_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : '';
        
        // Determine active tab with priority order: 
        // 1. URL hash (most important)
        // 2. POST active_tab 
        // 3. GET section
        // 4. Default to backup
        $active_tab = $has_backup_hash ? 'backup' : 
                     ($has_settings_hash ? 'settings' : 
                     (!empty($post_active_tab) ? $post_active_tab : 
                     (!empty($get_section) ? $get_section : 'backup')));
        
        // Define tabs
        $tabs = array(
            'backup' => __('Backup', 'swiftspeed-siberian'),
            'settings' => __('Settings', 'swiftspeed-siberian'),
        );
        
        ?>
        <div class="siberian-section-header">
            <h2><?php _e('Backup & Restore', 'swiftspeed-siberian'); ?></h2>
        </div>
        
        <!-- Info Block -->
        <div class="swsib-notice info">
            <p><?php _e('Regular backups are essential for protecting your Siberian CMS data. Create backups before major changes and store them in multiple locations.', 'swiftspeed-siberian'); ?></p>
            <p><?php _e('Use the options below to create, manage, and restore backups of your database and files.', 'swiftspeed-siberian'); ?></p>
            <p><strong><?php _e('New Feature:', 'swiftspeed-siberian'); ?></strong> <?php _e('Backups now continue in the background even if you close this page.', 'swiftspeed-siberian'); ?></p>
        </div>
        
        <div class="siberian-tabs-nav">
            <ul class="subsubsub">
                <?php foreach ($tabs as $tab_id => $tab_label): ?>
                    <li>
                        <a href="#<?php echo esc_attr($tab_id); ?>" class="<?php echo ($active_tab === $tab_id ? 'current' : ''); ?>">
                            <?php echo esc_html($tab_label); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="clear"></div>
        
        <div class="siberian-tabs-content" data-active-tab="<?php echo esc_attr($active_tab); ?>">
            <!-- Backup Tab -->
            <div id="backup" class="siberian-backup-tab-content" style="<?php echo $active_tab !== 'backup' ? 'display:none;' : ''; ?>">
                <?php $this->display_backup_tab(); ?>
            </div>
            
            <!-- Settings Tab -->
            <div id="settings" class="siberian-settings-tab-content" style="<?php echo $active_tab !== 'settings' ? 'display:none;' : ''; ?>">
                <?php $this->display_settings_tab(); ?>
            </div>
        </div>
        
        <!-- Toast Container -->
        <div id="siberian-toast-container"></div>
        
        <!-- Add script to make sure URL hash is respected on initial load -->
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Get the hash from URL
            var hash = window.location.hash;
            
            if (hash) {
                // Remove # and handle any additional parameters
                var tabId = hash.substring(1).split('&')[0];
                
                // Trigger click on correct tab
                $('.siberian-tabs-nav .subsubsub a[href="#' + tabId + '"]').click();
                
                // Also update the data attribute
                $('.siberian-tabs-content').attr('data-active-tab', tabId);
                
                // Update hidden input field for form submissions
                $('input[name="active_tab"]').val(tabId);
            }
        });
        </script>
        <?php
    }
    
    /**
     * Display backup tab content with optimized lazy loading.
     *
     * @return void
     */
    private function display_backup_tab() {
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $storage_providers = $this->storage_manager->get_configured_providers();
        $current_backup = get_option('swsib_current_backup', array());
        $current_restore = get_option('swsib_current_restore', array());
        
        // Determine if a backup is in progress
        $backup_in_progress = !empty($current_backup) && in_array($current_backup['status'], array('initializing', 'processing', 'phase_db', 'phase_files', 'phase_finalize', 'uploading'));
        
        // Determine if a restore is in progress
        $restore_in_progress = !empty($current_restore) && in_array($current_restore['status'], array('processing'));
        
        ?>
        <div class="siberian-backup-section">
            <div class="swsib-notice info">
                <p><?php _e('Create and manage backups of your Siberian CMS. You can backup the database, files, or both.', 'swiftspeed-siberian'); ?></p>
            </div>
            
            <div class="siberian-backup-container">
                <h3><?php _e('Create New Backup', 'swiftspeed-siberian'); ?></h3>
                
                <!-- Backup Controls -->
                <div class="siberian-backup-controls" <?php echo $backup_in_progress || $restore_in_progress ? 'style="display:none;"' : ''; ?>>
                    <form id="swsib-create-backup-form">
                        <!-- Backup Type Selection with Cards -->
                        <div class="siberian-backup-type-cards">
                            <div class="siberian-backup-type-card <?php echo isset($_GET['type']) && $_GET['type'] === 'full' ? 'active' : ''; ?>">
                                <input type="radio" name="backup_type" value="full" id="backup-type-full" <?php echo isset($_GET['type']) && $_GET['type'] === 'full' ? 'checked' : 'checked'; ?>>
                                <div class="siberian-backup-type-card-header">
                                    <div class="siberian-backup-type-card-icon">
                                        <span class="dashicons dashicons-database-export"></span>
                                    </div>
                                    <h4 class="siberian-backup-type-card-title"><?php _e('Full Backup', 'swiftspeed-siberian'); ?></h4>
                                </div>
                                <p class="siberian-backup-type-card-description"><?php _e('Backup both database and files for a complete snapshot.', 'swiftspeed-siberian'); ?></p>
                            </div>
                            
                            <div class="siberian-backup-type-card <?php echo isset($_GET['type']) && $_GET['type'] === 'db' ? 'active' : ''; ?>">
                                <input type="radio" name="backup_type" value="db" id="backup-type-db" <?php echo isset($_GET['type']) && $_GET['type'] === 'db' ? 'checked' : ''; ?>>
                                <div class="siberian-backup-type-card-header">
                                    <div class="siberian-backup-type-card-icon">
                                        <span class="dashicons dashicons-database"></span>
                                    </div>
                                    <h4 class="siberian-backup-type-card-title"><?php _e('Database Only', 'swiftspeed-siberian'); ?></h4>
                                </div>
                                <p class="siberian-backup-type-card-description"><?php _e('Backup only the database.', 'swiftspeed-siberian'); ?></p>
                            </div>
                            
                            <div class="siberian-backup-type-card <?php echo isset($_GET['type']) && $_GET['type'] === 'files' ? 'active' : ''; ?>">
                                <input type="radio" name="backup_type" value="files" id="backup-type-files" <?php echo isset($_GET['type']) && $_GET['type'] === 'files' ? 'checked' : ''; ?>>
                                <div class="siberian-backup-type-card-header">
                                    <div class="siberian-backup-type-card-icon">
                                        <span class="dashicons dashicons-media-document"></span>
                                    </div>
                                    <h4 class="siberian-backup-type-card-title"><?php _e('Files Only', 'swiftspeed-siberian'); ?></h4>
                                </div>
                                <p class="siberian-backup-type-card-description"><?php _e('Backup only the files.', 'swiftspeed-siberian'); ?></p>
                            </div>
                        </div>
                        
                        <div class="siberian-field-group">
                            <label class="siberian-label"><?php _e('Storage Locations', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-field">
                                <div class="siberian-storage-checkboxes">
                                    <label class="siberian-storage-checkbox">
                                        <input type="checkbox" name="storage_providers[]" value="local" checked disabled>
                                        <input type="hidden" name="storage_providers[]" value="local">
                                        <?php _e('Local (WordPress Filesystem)', 'swiftspeed-siberian'); ?>
                                    </label>
                                    <?php foreach ($storage_providers as $id => $provider): ?>
                                        <?php if ($id !== 'local'): ?>
                                            <label class="siberian-storage-checkbox">
                                                <input type="checkbox" name="storage_providers[]" value="<?php echo esc_attr($id); ?>">
                                                <?php echo esc_html($provider->get_display_name()); ?>
                                                <?php if ($provider->is_configured()): ?>
                                                    <span class="dashicons dashicons-yes-alt storage-connected" title="<?php esc_attr_e('Connected', 'swiftspeed-siberian'); ?>"></span>
                                                <?php endif; ?>
                                            </label>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <p class="siberian-field-note">
                                    <?php _e('Choose where to store the backup. You can select multiple storage locations. The backup will always be stored locally and optionally in the selected cloud storage locations.', 'swiftspeed-siberian'); ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="siberian-field-group siberian-files-options" style="display:none;">
                            <label class="siberian-label"><?php _e('File Options', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-field">
                                <label>
                                    <input type="checkbox" name="include_all_files" value="1" checked> 
                                    <?php _e('Include all files', 'swiftspeed-siberian'); ?>
                                </label>
                                <p class="siberian-field-note">
                                    <?php _e('Include all files in the Siberian CMS installation.', 'swiftspeed-siberian'); ?>
                                </p>
                            </div>
                            <div class="siberian-custom-file-paths" style="display:none;">
                                <div class="siberian-field">
                                    <label for="include_paths"><?php _e('Include Paths', 'swiftspeed-siberian'); ?></label>
                                    <textarea name="include_paths" rows="3" placeholder="<?php echo esc_attr(__('Enter paths to include, one per line', 'swiftspeed-siberian')); ?>"></textarea>
                                    <p class="siberian-field-note">
                                        <?php _e('Specify paths to include in the backup. Leave empty to include the entire installation.', 'swiftspeed-siberian'); ?>
                                    </p>
                                </div>
                                <div class="siberian-field">
                                    <label for="exclude_paths"><?php _e('Exclude Paths', 'swiftspeed-siberian'); ?></label>
                                    <textarea name="exclude_paths" rows="3" placeholder="<?php echo esc_attr(__('Enter paths to exclude, one per line', 'swiftspeed-siberian')); ?>"></textarea>
                                    <p class="siberian-field-note">
                                        <?php _e('Specify paths to exclude from the backup.', 'swiftspeed-siberian'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Add lock backup option during creation -->
                        <div class="siberian-field">
                            <label>
                                <input type="checkbox" name="lock_backup" value="1"> 
                                <?php _e('Lock this backup', 'swiftspeed-siberian'); ?>
                            </label>
                            <p class="siberian-field-note">
                                <?php _e('Locked backups will not be automatically deleted when retention limits are reached.', 'swiftspeed-siberian'); ?>
                            </p>
                        </div>
                        
                        <div class="siberian-field">
                            <button type="button" id="siberian-start-backup" class="button button-primary">
                                <span class="dashicons dashicons-database-export"></span>
                                <?php _e('Start Backup', 'swiftspeed-siberian'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Backup Progress -->
                <div id="siberian-backup-progress-container" <?php echo !$backup_in_progress ? 'style="display:none;"' : ''; ?>>
                    <div class="siberian-backup-progress-bar">
                        <div class="siberian-backup-progress-fill" style="width: <?php echo !empty($current_backup) ? esc_attr($current_backup['progress']) . '%' : '0%'; ?>"></div>
                    </div>
                    <div class="siberian-backup-status-text">
                        <?php echo !empty($current_backup) ? esc_html($current_backup['message']) : ''; ?>
                    </div>
                    <div class="siberian-background-info">
                        <span class="dashicons dashicons-update-alt"></span>
                        <?php _e('Backup will continue in the background even if you leave this page.', 'swiftspeed-siberian'); ?>
                    </div>
                    <div class="siberian-backup-stats"></div>
                    <!-- Performance Metrics -->
                    <div class="siberian-backup-performance">
                        <?php if (!empty($current_backup)): ?>
                            <div class="performance-metric">Size: <span class="metric-value">Calculating...</span></div>
                            <div class="performance-metric">Speed: <span class="metric-value">Calculating...</span></div>
                            <div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="siberian-backup-cancel-container">
                        <button type="button" id="siberian-cancel-backup" class="button">
                            <span class="dashicons dashicons-no-alt"></span>
                            <?php _e('Cancel Backup', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Restore Progress with Enhanced Performance Metrics -->
                <div id="siberian-restore-progress-container" <?php echo !$restore_in_progress ? 'style="display:none;"' : ''; ?>>
                    <h3><?php _e('Restore Progress', 'swiftspeed-siberian'); ?></h3>
                    
                    <div class="siberian-restore-progress-bar">
                        <div class="siberian-restore-progress-fill" style="width: <?php echo !empty($current_restore) ? esc_attr($current_restore['progress']) . '%' : '0%'; ?>"></div>
                    </div>
                    <div class="siberian-restore-status-text">
                        <?php echo !empty($current_restore) ? esc_html($current_restore['message']) : ''; ?>
                    </div>
                    <div class="siberian-restore-stats"></div>
                    <!-- Performance Metrics -->
                    <div class="siberian-restore-performance">
                        <?php if (!empty($current_restore)): ?>
                            <div class="performance-metric">Size: <span class="metric-value">Calculating...</span></div>
                            <div class="performance-metric">Speed: <span class="metric-value">Calculating...</span></div>
                            <div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>
                        <?php endif; ?>
                    </div>
                    <div class="siberian-restore-cancel-container">
                        <button type="button" id="siberian-cancel-restore" class="button">
                            <span class="dashicons dashicons-no-alt"></span>
                            <?php _e('Cancel Restore', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Backup History - Lazy loaded to improve initial page load -->
                <div class="siberian-backup-history">
                    <div class="siberian-history-header">
                        <h3><?php _e('Backup History', 'swiftspeed-siberian'); ?></h3>
                        <button type="button" id="siberian-refresh-backup-history" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                    
                    <div id="siberian-backup-history-list">
                        <div class="siberian-loading-indicator">
                            <span class="dashicons dashicons-update spinning"></span> 
                            <?php _e('Loading backup history...', 'swiftspeed-siberian'); ?>
                        </div>
                    </div>
                </div>
                
                <?php
                // Display restore history (lazy loaded)
                $restore_history = $this->get_restore_history();
                if (!empty($restore_history)):
                ?>
                <div class="siberian-restore-history">
                    <div class="siberian-history-header">
                        <h3><?php _e('Restore History', 'swiftspeed-siberian'); ?></h3>
                        <div>
                            <button type="button" id="siberian-clear-restore-history" class="button button-secondary">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Clear History', 'swiftspeed-siberian'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Render restore history with optimized markup -->
                    <div id="siberian-restore-history-list">
                        <?php $this->render_restore_history($restore_history); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
   /**
     * Display settings tab content with improved scheduling UI.
     *
     * @return void
     */
    private function display_settings_tab() {
        $backup_settings = isset($this->options['backup_restore']) ? $this->options['backup_restore'] : array();
        $storage_providers = $this->storage_manager->get_provider_fields();
        
        // Get the current tab for form submission
        $active_tab = 'settings';
        
        ?>
        <div class="siberian-settings-section">
            <form method="post" action="options.php" id="siberian-backup-settings-form">
                <?php settings_fields('swsib_options'); ?>
                <input type="hidden" name="tab_id" value="backup_restore">
                <!-- Hidden field to preserve active tab during form submission -->
                <input type="hidden" name="active_tab" value="<?php echo esc_attr($active_tab); ?>">
                
                <h3><?php _e('General Settings', 'swiftspeed-siberian'); ?></h3>
                
                <div class="siberian-field">
                    <label for="swsib_options_backup_restore_max_backups_db">
                        <?php _e('Maximum Database Backups to Keep', 'swiftspeed-siberian'); ?>
                    </label>
                    <input type="number" 
                           id="swsib_options_backup_restore_max_backups_db" 
                           name="swsib_options[backup_restore][max_backups_db]" 
                           value="<?php echo isset($backup_settings['max_backups_db']) ? esc_attr($backup_settings['max_backups_db']) : '10'; ?>" 
                           min="1" 
                           max="100">
                    <p class="siberian-field-note">
                        <?php _e('The maximum number of database backups to keep. Oldest backups will be automatically deleted when this limit is reached. Locked backups are never deleted automatically.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <div class="siberian-field">
                    <label for="swsib_options_backup_restore_max_backups_file">
                        <?php _e('Maximum File Backups to Keep', 'swiftspeed-siberian'); ?>
                    </label>
                    <input type="number" 
                           id="swsib_options_backup_restore_max_backups_file" 
                           name="swsib_options[backup_restore][max_backups_file]" 
                           value="<?php echo isset($backup_settings['max_backups_file']) ? esc_attr($backup_settings['max_backups_file']) : '5'; ?>" 
                           min="1" 
                           max="100">
                    <p class="siberian-field-note">
                        <?php _e('The maximum number of file backups to keep. Oldest backups will be automatically deleted when this limit is reached. Locked backups are never deleted automatically.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <div class="siberian-field">
                    <label for="swsib_options_backup_restore_max_backups_full">
                        <?php _e('Maximum Full Backups to Keep', 'swiftspeed-siberian'); ?>
                    </label>
                    <input type="number" 
                           id="swsib_options_backup_restore_max_backups_full" 
                           name="swsib_options[backup_restore][max_backups_full]" 
                           value="<?php echo isset($backup_settings['max_backups_full']) ? esc_attr($backup_settings['max_backups_full']) : '3'; ?>" 
                           min="1" 
                           max="100">
                    <p class="siberian-field-note">
                        <?php _e('The maximum number of full backups to keep. Oldest backups will be automatically deleted when this limit is reached. Locked backups are never deleted automatically.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <div class="siberian-field">
                    <label for="swsib_options_backup_restore_max_steps">
                        <?php _e('Background Processing Batch Size', 'swiftspeed-siberian'); ?>
                    </label>
                    <select id="swsib_options_backup_restore_max_steps" 
                            name="swsib_options[backup_restore][max_steps]">
                        <option value="2" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 2); ?>>2 - <?php _e('Minimum (Most Reliable)', 'swiftspeed-siberian'); ?></option>
                        <option value="5" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 5); ?>>5 - <?php _e('Conservative (Default)', 'swiftspeed-siberian'); ?></option>
                        <option value="10" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 10); ?>>10 - <?php _e('Balanced', 'swiftspeed-siberian'); ?></option>
                        <option value="15" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 15); ?>>15 - <?php _e('Faster', 'swiftspeed-siberian'); ?></option>
                        <option value="20" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 20); ?>>20 - <?php _e('Aggressive', 'swiftspeed-siberian'); ?></option>
                        <option value="25" <?php selected(isset($backup_settings['max_steps']) ? $backup_settings['max_steps'] : 5, 25); ?>>25 - <?php _e('Maximum Speed', 'swiftspeed-siberian'); ?></option>
                    </select>
                    <p class="siberian-field-note">
                        <?php _e('Controls how many operations to perform in each background processing step. Higher values increase backup and restore speed but require more server resources.', 'swiftspeed-siberian'); ?>
                    </p>
                    <div class="swsib-notice info">
                        <p><strong><?php _e('Server Requirements:', 'swiftspeed-siberian'); ?></strong></p>
                        <ul>
                            <li><?php _e('Minimum (2-5): Basic server configuration (Best of Large sites above 1G)', 'swiftspeed-siberian'); ?></li>
                            <li><?php _e('Balanced (10): Memory: 512M, Max execution time: 60s ', 'swiftspeed-siberian'); ?></li>
                            <li><?php _e('Faster (15): Memory: 1024M, Max execution time: 120s', 'swiftspeed-siberian'); ?></li>
                            <li><?php _e('Aggressive (20): Memory: 1536M, Max execution time: 300s (May fail for large sites above 1G)', 'swiftspeed-siberian'); ?></li>
                            <li><?php _e('Maximum (25): Memory: 2048M, Max execution time: 600s, Post size: 100M+ (Will fail for large sites above 1G)', 'swiftspeed-siberian'); ?></li>
                        </ul>
                        <p><?php _e('If your backup or restore fails, try lowering this value. Higher values are only recommended for powerful servers.', 'swiftspeed-siberian'); ?></p>
                        <p><?php _e('NOTE: Aggressive and Maximum should only be selected for sites lesser than 2G in total. Anything above 4G should use Balanced or Minimum, else backup/restore will fail', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>

                <h3><?php _e('Backup Schedules', 'swiftspeed-siberian'); ?></h3>
                
                <div class="siberian-field">
                    <p class="siberian-field-note">
                        <?php _e('Create multiple backup schedules to automate different types of backups with different frequencies and storage locations.', 'swiftspeed-siberian'); ?>
                    </p>
                    
                    <button type="button" id="siberian-add-schedule" class="button button-primary">
                        <span class="dashicons dashicons-plus"></span>
                        <?php _e('Add Backup Schedule', 'swiftspeed-siberian'); ?>
                    </button>
                </div>
                
                <!-- Schedule List Container -->
                <div id="siberian-schedule-list-container" class="siberian-schedules-container">
                    <div class="siberian-loading-indicator">
                        <span class="dashicons dashicons-update spinning"></span> 
                        <?php _e('Loading backup schedules...', 'swiftspeed-siberian'); ?>
                    </div>
                </div>
                
                <!-- Add External Cron Support Section -->
                <div class="siberian-field">
                    <h4><?php _e('Backup Reliability Settings', 'swiftspeed-siberian'); ?></h4>
                    <label>
                        <input type="checkbox" 
                              id="swsib_options_backup_restore_use_external_cron" 
                              name="swsib_options[backup_restore][use_external_cron]" 
                              value="1" 
                              <?php checked(!empty($backup_settings['use_external_cron'])); ?>>
                        <?php _e('Use External Cron System', 'swiftspeed-siberian'); ?>
                    </label>
                    <p class="siberian-field-note">
                        <?php _e('Recommended for reliable backups. Provides URLs you can call from an external cron service to trigger scheduled backups.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <?php 
                // Generate the cron URL for external calls
                $cron_url = admin_url('admin-ajax.php?action=swsib_trigger_scheduled_backup&key=' . md5('swsib_trigger_scheduled_backup')); 
                ?>
                <div class="siberian-field external-cron-info" <?php echo empty($backup_settings['use_external_cron']) ? 'style="display:none;"' : ''; ?>>
                    <h4><?php _e('External Cron URL', 'swiftspeed-siberian'); ?></h4>
                    <div class="siberian-cron-url-container">
                        <input type="text" readonly value="<?php echo esc_url($cron_url); ?>" class="siberian-cron-url">
                        <button type="button" class="button button-secondary copy-cron-url">
                            <span class="dashicons dashicons-clipboard"></span> <?php _e('Copy', 'swiftspeed-siberian'); ?>
                        </button>
                    </div>
                    <p class="siberian-field-note">
                        <?php _e('Set up a cron job on your server to call this URL to check all schedules. You can also trigger specific schedules by adding &schedule_id=SCHEDULE_ID to the URL.', 'swiftspeed-siberian'); ?>
                    </p>
                    <code class="siberian-cron-example">
                        <?php 
                        echo "*/15 * * * * wget -q -O /dev/null '" . esc_url($cron_url) . "' >/dev/null 2>&1";
                        ?>
                    </code>
                    <p class="siberian-field-note">
                        <?php _e('The example above runs every 15 minutes, which is recommended for reliability.', 'swiftspeed-siberian'); ?>
                    </p>
                </div>
                
                <!-- Storage Providers section -->
                <h3><?php _e('Storage Providers', 'swiftspeed-siberian'); ?></h3>
                
                <div class="siberian-storage-providers">
                    <!-- Storage Provider Tabs -->
                    <div class="siberian-storage-tabs">
                        <ul>
                            <?php foreach ($storage_providers as $id => $provider): ?>
                                <li>
                                    <a href="#siberian-storage-<?php echo esc_attr($id); ?>" class="<?php echo $id === 'local' ? 'active' : ''; ?>">
                                        <?php echo esc_html($provider['name']); ?>
                                        <?php if ($provider['is_configured'] || $id === 'local'): ?>
                                            <span class="dashicons dashicons-yes-alt"></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <!-- Storage Provider Content - Implement lazy loading for less important providers -->
                    <div class="siberian-storage-content">
                        <?php foreach ($storage_providers as $id => $provider): ?>
                            <div id="siberian-storage-<?php echo esc_attr($id); ?>" class="siberian-storage-tab-content <?php echo $id === 'local' ? 'active' : ''; ?>">
                                <h4><?php echo esc_html($provider['name']); ?> <?php _e('Settings', 'swiftspeed-siberian'); ?></h4>
                                
                                <?php if ($id === 'local'): ?>
                                    <div class="swsib-notice info">
                                        <p><?php _e('Local storage uses the WordPress filesystem to store backups. It is always available and requires no configuration.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                <?php elseif ($id === 'gdrive'): ?>
                                    <div class="swsib-notice info">
                                        <p><strong><?php _e('Google Drive Integration:', 'swiftspeed-siberian'); ?></strong></p>
                                        <p><?php _e('Click the "Connect to Google Drive" button below to authorize access to your Google Drive account. Your backups will be stored in a dedicated folder in your Google Drive.', 'swiftspeed-siberian'); ?></p>
                                    </div>
                                    
                                    <?php
                                    // Get the latest options directly from the database to ensure we have the current state
                                    $all_options = get_option('swsib_options', array());
                                    $gdrive_options = isset($all_options['backup_restore']['storage']['gdrive']) ? 
                                                     $all_options['backup_restore']['storage']['gdrive'] : array();
                                    
                                    $is_connected = !empty($gdrive_options['access_token']);
                                    $account_info = isset($gdrive_options['account_info']) ? $gdrive_options['account_info'] : '';
                                    
                                    if ($is_connected && !empty($account_info)): ?>
                                        <div class="siberian-field">
                                            <div class="gdrive-connected-status">
                                                <p><span class="dashicons dashicons-yes-alt"></span> <?php echo sprintf(__('Connected to Google Drive (%s)', 'swiftspeed-siberian'), esc_html($account_info)); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    // Display Google Drive authentication success message if in URL
                                    if (isset($_GET['gdrive_auth_success']) && $_GET['gdrive_auth_success'] == 1): ?>
                                        <div class="swsib-notice success">
                                            <p><span class="dashicons dashicons-yes-alt"></span> <?php _e('Successfully connected to Google Drive!', 'swiftspeed-siberian'); ?></p>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php 
                                // Render form fields for this provider
                                $this->render_storage_provider_fields($id, $provider, $backup_settings);
                                ?>
                                
                                <?php if ($id !== 'local'): ?>
                                    <div class="siberian-field">
                                        <button type="button" class="button siberian-test-storage-connection" data-provider="<?php echo esc_attr($id); ?>">
                                            <?php _e('Test Connection', 'swiftspeed-siberian'); ?>
                                        </button>
                                        <span class="siberian-test-result" id="siberian-test-result-<?php echo esc_attr($id); ?>"></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="siberian-actions">
                    <input type="submit" name="submit" class="button button-primary" value="<?php _e('Save Settings', 'swiftspeed-siberian'); ?>">
                </div>
            </form>
        </div>
        
        <!-- Schedule Modal with Improved UI and Styling -->
        <div id="siberian-schedule-modal" class="siberian-modal" style="display:none;">
            <div class="siberian-modal-backdrop"></div>
            <div class="siberian-modal-content">
                <div class="siberian-modal-header">
                    <h3 id="siberian-schedule-modal-title"><?php _e('Add Backup Schedule', 'swiftspeed-siberian'); ?></h3>
                    <span class="siberian-modal-close">&times;</span>
                </div>
                <div class="siberian-modal-body">
                    <form id="siberian-schedule-form">
                        <input type="hidden" id="schedule-id" name="id" value="">
                        
                        <div class="siberian-field">
                            <label for="schedule-name"><?php _e('Schedule Name', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="schedule-name" name="name" value="" required>
                            <p class="siberian-field-note"><?php _e('A descriptive name for this backup schedule.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <label for="schedule-enabled"><?php _e('Status', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-toggle-container">
                                <label class="siberian-toggle">
                                    <input type="checkbox" id="schedule-enabled" name="enabled" value="1" checked>
                                    <span class="siberian-toggle-slider"></span>
                                    <span class="siberian-toggle-label"><?php _e('Enabled', 'swiftspeed-siberian'); ?></span>
                                </label>
                            </div>
                            <p class="siberian-field-note"><?php _e('Enable or disable this backup schedule.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <label for="schedule-type"><?php _e('Backup Type', 'swiftspeed-siberian'); ?></label>
                            <select id="schedule-type" name="type">
                                <option value="full"><?php _e('Full Backup (Database + Files)', 'swiftspeed-siberian'); ?></option>
                                <option value="db"><?php _e('Database Only', 'swiftspeed-siberian'); ?></option>
                                <option value="files"><?php _e('Files Only', 'swiftspeed-siberian'); ?></option>
                            </select>
                            <p class="siberian-field-note"><?php _e('Choose what to include in the backup.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <label><?php _e('Run backup every', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-interval-setting">
                                <input type="number" id="schedule-interval-value" name="interval_value" value="1" min="1" max="100">
                                
                                <select id="schedule-interval-unit" name="interval_unit">
                                    <option value="minutes"><?php _e('Minute(s)', 'swiftspeed-siberian'); ?></option>
                                    <option value="hours"><?php _e('Hour(s)', 'swiftspeed-siberian'); ?></option>
                                    <option value="days" selected><?php _e('Day(s)', 'swiftspeed-siberian'); ?></option>
                                    <option value="weeks"><?php _e('Week(s)', 'swiftspeed-siberian'); ?></option>
                                    <option value="months"><?php _e('Month(s)', 'swiftspeed-siberian'); ?></option>
                                </select>
                            </div>
                            <p class="siberian-field-note"><?php _e('Specify how often automated backups should run.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <label for="schedule-auto-lock"><?php _e('Auto Lock', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-toggle-container">
                                <label class="siberian-toggle">
                                    <input type="checkbox" id="schedule-auto-lock" name="auto_lock" value="1">
                                    <span class="siberian-toggle-slider"></span>
                                    <span class="siberian-toggle-label"><?php _e('Auto Lock Backups', 'swiftspeed-siberian'); ?></span>
                                </label>
                            </div>
                            <p class="siberian-field-note"><?php _e('If enabled, backups created by this schedule will be locked automatically. Locked backups are never deleted automatically.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <label><?php _e('Storage Locations', 'swiftspeed-siberian'); ?></label>
                            <div class="siberian-storage-checkboxes" id="schedule-storage-providers">
                                <label class="siberian-storage-checkbox">
                                    <input type="checkbox" name="storages[]" value="local" checked disabled>
                                    <input type="hidden" name="storages[]" value="local">
                                    <?php _e('Local (WordPress Filesystem)', 'swiftspeed-siberian'); ?>
                                </label>
                                <?php foreach ($storage_providers as $id => $provider): ?>
                                    <?php if ($id !== 'local' && $provider['is_configured']): ?>
                                        <label class="siberian-storage-checkbox">
                                            <input type="checkbox" name="storages[]" value="<?php echo esc_attr($id); ?>">
                                            <?php echo esc_html($provider['name']); ?>
                                        </label>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <p class="siberian-field-note"><?php _e('Choose where to store scheduled backups. You can select multiple storage locations.', 'swiftspeed-siberian'); ?></p>
                        </div>
                        
                        <div class="siberian-field">
                            <div class="siberian-next-run-info"></div>
                        </div>
                    </form>
                </div>
                <div class="siberian-modal-footer">
                    <button type="button" class="button" id="siberian-schedule-cancel"><?php _e('Cancel', 'swiftspeed-siberian'); ?></button>
                    <button type="button" class="button button-primary" id="siberian-schedule-save"><?php _e('Save Schedule', 'swiftspeed-siberian'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    /**
     * Render storage provider fields - optimized helper method
     * 
     * @param string $provider_id The provider ID
     * @param array $provider The provider config
     * @param array $backup_settings Current backup settings
     */
    private function render_storage_provider_fields($provider_id, $provider, $backup_settings) {
        // Skip certain fields based on provider type
        $skip_fields = array();
        
        if ($provider_id === 'gdrive') {
            $skip_fields = array('client_id', 'client_secret');
        } elseif ($provider_id === 'local') {
            $skip_fields = array('storage_path', 'max_backups');
        }
        
        // Always skip max_backups field for all providers
        $skip_fields[] = 'max_backups';
        
        foreach ($provider['fields'] as $field) {
            // Skip fields in the skip list
            if (in_array($field['name'], $skip_fields)) {
                continue;
            }
            
            echo '<div class="siberian-field">';
            
            // Field label
            echo '<label for="swsib_options_backup_restore_storage_' . esc_attr($provider_id) . '_' . esc_attr($field['name']) . '">';
            echo esc_html($field['label']);
            if (!empty($field['required'])) {
                echo '<span class="siberian-required">*</span>';
            }
            echo '</label>';
            
            // Field input based on type
            switch ($field['type']) {
                case 'text':
                case 'number':
                    echo '<input type="' . esc_attr($field['type']) . '" ' .
                         'id="swsib_options_backup_restore_storage_' . esc_attr($provider_id) . '_' . esc_attr($field['name']) . '" ' .
                         'name="swsib_options[backup_restore][storage][' . esc_attr($provider_id) . '][' . esc_attr($field['name']) . ']" ' .
                         'value="' . (isset($backup_settings['storage'][$provider_id][$field['name']]) ? 
                                    esc_attr($backup_settings['storage'][$provider_id][$field['name']]) : 
                                    (isset($field['default']) ? esc_attr($field['default']) : '')) . '" ' .
                         'placeholder="' . (isset($field['placeholder']) ? esc_attr($field['placeholder']) : '') . '"' .
                         (!empty($field['min']) ? ' min="' . esc_attr($field['min']) . '"' : '') .
                         (!empty($field['max']) ? ' max="' . esc_attr($field['max']) . '"' : '') .
                         '>';
                    break;
                    
                case 'password':
                    echo '<input type="password" ' .
                         'id="swsib_options_backup_restore_storage_' . esc_attr($provider_id) . '_' . esc_attr($field['name']) . '" ' .
                         'name="swsib_options[backup_restore][storage][' . esc_attr($provider_id) . '][' . esc_attr($field['name']) . ']" ' .
                         'value="' . (isset($backup_settings['storage'][$provider_id][$field['name']]) ? 
                                    esc_attr($backup_settings['storage'][$provider_id][$field['name']]) : '') . '" ' .
                         'placeholder="' . (isset($field['placeholder']) ? esc_attr($field['placeholder']) : '') . '">';
                    break;
                    
                case 'textarea':
                    echo '<textarea ' .
                         'id="swsib_options_backup_restore_storage_' . esc_attr($provider_id) . '_' . esc_attr($field['name']) . '" ' .
                         'name="swsib_options[backup_restore][storage][' . esc_attr($provider_id) . '][' . esc_attr($field['name']) . ']" ' .
                         'rows="4" ' .
                         'placeholder="' . (isset($field['placeholder']) ? esc_attr($field['placeholder']) : '') . '">' .
                         (isset($backup_settings['storage'][$provider_id][$field['name']]) ? 
                          esc_textarea($backup_settings['storage'][$provider_id][$field['name']]) : 
                          (isset($field['default']) ? esc_textarea($field['default']) : '')) .
                         '</textarea>';
                    break;
                    
                case 'select':
                    echo '<select ' .
                         'id="swsib_options_backup_restore_storage_' . esc_attr($provider_id) . '_' . esc_attr($field['name']) . '" ' .
                         'name="swsib_options[backup_restore][storage][' . esc_attr($provider_id) . '][' . esc_attr($field['name']) . ']">';
                    
                    foreach ($field['options'] as $option_value => $option_label) {
                        $selected = isset($backup_settings['storage'][$provider_id][$field['name']]) ? 
                                   $backup_settings['storage'][$provider_id][$field['name']] : 
                                   (isset($field['default']) ? $field['default'] : '');
                        
                        echo '<option value="' . esc_attr($option_value) . '" ' . 
                              selected($selected, $option_value, false) . '>' .
                              esc_html($option_label) . 
                              '</option>';
                    }
                    
                    echo '</select>';
                    break;
                    
                case 'auth_button':
                    echo '<button type="button" class="button siberian-auth-provider-button" ' .
                         'data-provider="' . esc_attr($provider_id) . '" data-use-central-api="1">' .
                         esc_html($field['text']) .
                         '</button>';
                    break;
            }
            
            // Field description if set
            if (isset($field['description'])) {
                echo '<p class="siberian-field-note">' . esc_html($field['description']) . '</p>';
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Delete restore history AJAX handler.
     */
    public function ajax_delete_restore_history() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'swsib_backup_restore_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'swiftspeed-siberian')));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied', 'swiftspeed-siberian')));
        }
        
        // Delete history
        delete_option('swsib_restore_history');
        
        // Clear cache
        $this->restore_history_cache = null;
        
        wp_send_json_success(array('message' => __('Restore history cleared successfully', 'swiftspeed-siberian')));
    }
    
    /**
     * Render restore history table - optimized helper method
     * 
     * @param array $restore_history The restore history data
     */
    private function render_restore_history($restore_history) {
        if (empty($restore_history)) {
            echo '<p class="siberian-no-backups-message">' . esc_html__('No restore history available.', 'swiftspeed-siberian') . '</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Date', 'swiftspeed-siberian') . '</th>';
        echo '<th>' . esc_html__('Backup Used', 'swiftspeed-siberian') . '</th>';
        echo '<th>' . esc_html__('Type', 'swiftspeed-siberian') . '</th>';
        echo '<th>' . esc_html__('Duration', 'swiftspeed-siberian') . '</th>';
        echo '<th>' . esc_html__('Status', 'swiftspeed-siberian') . '</th>';
        echo '<th>' . esc_html__('Details', 'swiftspeed-siberian') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($restore_history as $id => $restore) {
            echo '<tr>';
            
            // Date
            echo '<td>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $restore['completed']) . '</td>';
            
            // Backup used
            $backup_id = $restore['backup_id'];
            $backup_history = $this->get_backup_history();
            $backup_name = isset($backup_history[$backup_id]) ? esc_html($backup_history[$backup_id]['file']) : esc_html($backup_id);
            echo '<td>' . $backup_name . '</td>';
            
            // Type
            $types = array();
            if (!empty($restore['has_db'])) {
                $types[] = __('Database', 'swiftspeed-siberian');
            }
            if (!empty($restore['has_files'])) {
                $types[] = __('Files', 'swiftspeed-siberian');
            }
            echo '<td>' . implode(' + ', $types) . '</td>';
            
            // Duration
            echo '<td>' . $this->format_time_duration($restore['duration']) . '</td>';
            
            // Status
            $status = isset($restore['status']) ? $restore['status'] : 'completed';
            $status_class = $status === 'completed' ? 'siberian-status-success' : 'siberian-status-warning';
            echo '<td>';
            echo '<span class="' . esc_attr($status_class) . '">';
            if ($status === 'completed') {
                _e('Successful', 'swiftspeed-siberian');
            } else if ($status === 'partial') {
                _e('Partial', 'swiftspeed-siberian');
            } else {
                echo esc_html(ucfirst($status));
            }
            echo '</span>';
            echo '</td>';
            
            // Details
            echo '<td>';
            $details = array();
            
            // Size info
            if (isset($restore['total_size']) && $restore['total_size'] > 0) {
                $details[] = size_format($restore['total_size']);
            }
            
            // Speed info
            if (isset($restore['speed']) && $restore['speed'] > 0) {
                $details[] = size_format($restore['speed']) . '/s avg';
            }
            
            // Error info
            if (isset($restore['error_count']) && $restore['error_count'] > 0) {
                $details[] = sprintf(__('%d errors', 'swiftspeed-siberian'), $restore['error_count']);
            }
            
            echo implode(' | ', $details);
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    
    /**
     * Get backup history with caching for better performance
     * 
     * @return array Backup history
     */
    private function get_backup_history() {
        if ($this->backup_history_cache === null) {
            $this->backup_history_cache = get_option('swsib_backup_history', array());
        }
        
        return $this->backup_history_cache;
    }
    
    /**
     * Get restore history with caching for better performance
     * 
     * @return array Restore history
     */
    private function get_restore_history() {
        if ($this->restore_history_cache === null) {
            $this->restore_history_cache = get_option('swsib_restore_history', array());
        }
        
        return $this->restore_history_cache;
    }
    
    /**
     * Detect backup type from filename.
     */
    public function detect_backup_type($filename) {
        if (preg_match('/siberian-backup-(full|db|file|files)-(\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2})/', $filename, $matches)) {
            $type = $matches[1];
            if ($type === 'file') {
                return 'files';
            }
            return $type;
        }
        return 'unknown';
    }
    
    /**
     * Format a time duration in seconds into a human-readable string.
     */
    private function format_time_duration($seconds) {
        if ($seconds < 60) {
            return sprintf(_n('%d second', '%d seconds', $seconds, 'swiftspeed-siberian'), $seconds);
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $seconds = $seconds % 60;
            return sprintf(
                _n('%d minute', '%d minutes', $minutes, 'swiftspeed-siberian') . ', ' . 
                _n('%d second', '%d seconds', $seconds, 'swiftspeed-siberian'), 
                $minutes, $seconds
            );
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return sprintf(
                _n('%d hour', '%d hours', $hours, 'swiftspeed-siberian') . ', ' . 
                _n('%d minute', '%d minutes', $minutes, 'swiftspeed-siberian'), 
                $hours, $minutes
            );
        }
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