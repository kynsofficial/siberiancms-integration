<?php
/**
 * Source Queue tab for SiberianCMS Clean-up Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="swsib-clean-section source-queue-section">
    <h3><?php _e('Source Queue Management', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-clean-description">
        <?php _e('Manage source queue items in your SiberianCMS installation. The source queue contains build requests for mobile applications.', 'swiftspeed-siberian'); ?>
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
    
    <div class="swsib-notice info">
        <p><?php _e('Old and completed source queue items can be safely deleted to free up database space. Incomplete or pending items should generally be kept.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Search and actions -->
    <div class="swsib-clean-search-box">
        <input type="text" class="swsib-clean-search-input" placeholder="<?php _e('Search by name, URL, or host...', 'swiftspeed-siberian'); ?>">
        <button type="button" class="swsib-clean-button swsib-clean-search-button"><?php _e('Search', 'swiftspeed-siberian'); ?></button>
    </div>
    
    <div class="swsib-clean-actions">
        <div class="swsib-clean-actions-left">
            <button type="button" id="bulk-delete-source-queue" class="swsib-clean-button danger"><?php _e('Delete Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" id="clear-all-source-queue" class="swsib-clean-button danger"><?php _e('Clear All Source Queue', 'swiftspeed-siberian'); ?></button>
            <button type="button" class="swsib-clean-button swsib-clean-refresh-button refresh-data-button info">
                <span class="refresh-icon"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Source Queue table -->
    <table class="swsib-clean-table source-queue-table">
        <thead>
            <tr>
                <th class="checkbox-cell"><input type="checkbox" id="select-all-source-queue"></th>
                <th class="sortable" data-column="source_queue_id"><?php _e('ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="name"><?php _e('Name', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="app_id"><?php _e('App ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="host"><?php _e('Host', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="type"><?php _e('Type', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="status"><?php _e('Status', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="created_at"><?php _e('Created', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="updated_at"><?php _e('Updated', 'swiftspeed-siberian'); ?></th>
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