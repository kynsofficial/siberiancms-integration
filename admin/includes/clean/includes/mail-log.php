<?php
/**
 * Mail Log tab for SiberianCMS Clean-up Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="swsib-clean-section mail-log-section">
    <h3><?php _e('Mail Log Management', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-clean-description">
        <?php _e('Manage mail logs in your SiberianCMS installation. You can selectively delete logs or clear all logs at once.', 'swiftspeed-siberian'); ?>
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
        <p><?php _e('Mail logs can accumulate over time and take up database space. Regular cleaning is recommended for optimal performance.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Top actions bar with search -->
    <div class="swsib-clean-top-actions">
        <div class="swsib-clean-top-actions-left">
            <div class="swsib-clean-search-box">
                <input type="text" class="swsib-clean-search-input" placeholder="<?php _e('Search by title, sender, or recipient...', 'swiftspeed-siberian'); ?>">
                <button type="button" class="swsib-clean-button swsib-clean-search-button"><?php _e('Search', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>
    </div>
    
    <div class="swsib-clean-actions">
        <div class="swsib-clean-actions-left">
            <button type="button" id="bulk-delete-mail-logs" class="swsib-clean-button danger"><?php _e('Delete Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" id="clear-all-mail-logs" class="swsib-clean-button danger"><?php _e('Clear All Mail Logs', 'swiftspeed-siberian'); ?></button>
            <button type="button" class="swsib-clean-button swsib-clean-refresh-button refresh-data-button info">
                <span class="refresh-icon"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Mail Logs table -->
    <table class="swsib-clean-table mail-logs-table">
        <thead>
            <tr>
                <th class="checkbox-cell"><input type="checkbox" id="select-all-mail-logs"></th>
                <th class="sortable" data-column="log_id"><?php _e('ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="title"><?php _e('Title', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="from"><?php _e('From', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="recipients"><?php _e('Recipients', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="status"><?php _e('Status', 'swiftspeed-siberian'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="6" class="text-center loading-indicator">
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