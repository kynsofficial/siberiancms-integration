<?php
/**
 * Applications tab for SiberianCMS Clean-up Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="swsib-clean-section applications-section">
    <h3><?php _e('Applications Management', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-clean-description">
        <?php _e('Manage applications in your SiberianCMS installation. You can delete, lock, or unlock applications as needed.', 'swiftspeed-siberian'); ?>
    </div>
    
    <!-- Progress bar for bulk operations - Moved to appear after tabs -->
    <div class="swsib-clean-progress">
        <div class="swsib-clean-progress-header">
            <h4><?php _e('Operation Progress', 'swiftspeed-siberian'); ?></h4>
        </div>
        
        <div class="swsib-clean-progress-bar">
            <div class="swsib-clean-progress-bar-fill"></div>
        </div>
        
        <div class="swsib-clean-progress-text"><?php _e('Processing...', 'swiftspeed-siberian'); ?></div>
        
        <div class="swsib-clean-progress-details">
            <h5><?php _e('Operation Log:', 'swiftspeed-siberian'); ?></h5>
            <div class="swsib-clean-log"></div>
        </div>
    </div>
    
    <div class="swsib-notice danger">
        <p><strong><?php _e('Warning:', 'swiftspeed-siberian'); ?></strong> 
        <?php _e('Deleting an application will permanently remove all its data, including features, options, and user content. This action cannot be undone.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Top actions bar with search -->
    <div class="swsib-clean-top-actions">
        <div class="swsib-clean-top-actions-left">
            <div class="swsib-clean-search-box">
                <input type="text" class="swsib-clean-search-input" placeholder="<?php _e('Search by ID, name, or admin email...', 'swiftspeed-siberian'); ?>">
                <button type="button" class="swsib-clean-button swsib-clean-search-button"><?php _e('Search', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>
    </div>
    
    <div class="swsib-clean-actions">
        <div class="swsib-clean-actions-left">
            <button type="button" id="bulk-delete-apps" class="swsib-clean-button danger"><?php _e('Delete Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" id="bulk-lock-apps" class="swsib-clean-button warning"><?php _e('Lock Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" id="bulk-unlock-apps" class="swsib-clean-button primary"><?php _e('Unlock Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" class="swsib-clean-button swsib-clean-refresh-button refresh-data-button info">
                <span class="refresh-icon"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Applications table -->
    <table class="swsib-clean-table applications-table">
        <thead>
            <tr>
                <th class="checkbox-cell"><input type="checkbox" id="select-all-apps"></th>
                <th class="sortable" data-column="app_id"><?php _e('App ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="name"><?php _e('Name', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="admin_id"><?php _e('Admin ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="admin_email"><?php _e('Admin Email', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="is_locked"><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="created_at"><?php _e('Created', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="updated_at"><?php _e('Updated', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="size_on_disk"><?php _e('Size on Disk', 'swiftspeed-siberian'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="9" class="text-center loading-indicator">
                    <div class="swsib-clean-spinner"></div> <?php _e('Loading...', 'swiftspeed-siberian'); ?>
                </td>
            </tr>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <div class="swsib-clean-pagination swsib-hidden">
        <div class="swsib-clean-pagination-info"></div>
        <div class="swsib-clean-pagination-controls"></div>
    </div>
</div>