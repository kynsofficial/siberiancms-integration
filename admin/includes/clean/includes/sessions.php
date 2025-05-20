<?php
/**
 * Sessions tab for SiberianCMS Clean-up Tools
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="swsib-clean-section sessions-section">
    <h3><?php _e('Sessions Management', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-clean-description">
        <?php _e('Manage active sessions in your SiberianCMS installation. You can selectively delete sessions or clear all sessions at once.', 'swiftspeed-siberian'); ?>
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
    
    <div class="swsib-notice warning">
        <p><strong><?php _e('Warning:', 'swiftspeed-siberian'); ?></strong> 
        <?php _e('Clearing all sessions will log out all users from the platform. Users will need to log in again.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Search and actions -->
    <div class="swsib-clean-search-box">
        <input type="text" class="swsib-clean-search-input" placeholder="<?php _e('Search by session ID...', 'swiftspeed-siberian'); ?>">
        <button type="button" class="swsib-clean-button swsib-clean-search-button"><?php _e('Search', 'swiftspeed-siberian'); ?></button>
    </div>
    
    <div class="swsib-clean-actions">
        <div class="swsib-clean-actions-left">
            <button type="button" id="bulk-delete-sessions" class="swsib-clean-button danger"><?php _e('Delete Selected', 'swiftspeed-siberian'); ?></button>
            <button type="button" id="clear-all-sessions" class="swsib-clean-button danger"><?php _e('Clear All Sessions', 'swiftspeed-siberian'); ?></button>
            <button type="button" class="swsib-clean-button swsib-clean-refresh-button refresh-data-button info">
                <span class="refresh-icon"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
            </button>
        </div>
    </div>
    
    <!-- Sessions table -->
    <table class="swsib-clean-table sessions-table">
        <thead>
            <tr>
                <th class="checkbox-cell"><input type="checkbox" id="select-all-sessions"></th>
                <th class="sortable" data-column="session_id"><?php _e('Session ID', 'swiftspeed-siberian'); ?></th>
                <th class="sortable" data-column="modified"><?php _e('Modified', 'swiftspeed-siberian'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="3" class="text-center loading-indicator">
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