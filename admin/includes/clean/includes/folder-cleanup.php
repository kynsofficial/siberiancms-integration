<?php
/**
 * Folder Cleanup tab for SiberianCMS Clean-up Tools
 * Provides a file manager interface to browse and manage Siberian installation files
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="swsib-clean-section folder-cleanup-section">
    <h3><?php _e('Folder Cleanup', 'swiftspeed-siberian'); ?></h3>
    
    <div class="swsib-clean-description">
        <?php _e('Browse and manage files in your SiberianCMS installation. This tool allows you to view file details, delete unnecessary files, and free up disk space.', 'swiftspeed-siberian'); ?>
    </div>
    
    <!-- Progress bar for bulk operations -->
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
        <p><strong><?php _e('DANGER!', 'swiftspeed-siberian'); ?></strong> 
        <?php _e('Deleting files or folders will significantly impact your SiberianCMS installation functionality and may cause irreversible damage. DO NOT use this feature unless you know exactly what you are doing! Deleting core system files can break your entire installation.', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Connection Status -->
    <div id="file-connect-status" class="swsib-notice info">
        <p><?php _e('Checking connection to your SiberianCMS installation...', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- File Explorer -->
    <div id="file-explorer" class="swsib-clean-file-explorer" style="display: none;">
        <!-- Breadcrumb Navigation -->
        <div class="swsib-clean-breadcrumb-nav">
            <ol id="path-breadcrumbs" class="swsib-clean-breadcrumbs"></ol>
        </div>

        <!-- Filter controls -->
        <div class="swsib-clean-filter">
            <div class="swsib-clean-filter-left">
                <input type="text" id="file-search" class="swsib-clean-search-input" placeholder="Search files...">
                <button type="button" id="file-search-btn" class="swsib-clean-button swsib-clean-search-button">Search</button>
            </div>
            <div class="swsib-clean-filter-right">
                <select id="items-per-page" class="swsib-clean-select">
                    <option value="25" selected>25 items</option>
                    <option value="50">50 items</option>
                    <option value="100">100 items</option>
                </select>
            </div>
        </div>
        
        <!-- Actions Bar -->
        <div class="swsib-clean-actions">
            <div class="swsib-clean-actions-left">
                <button type="button" id="delete-selected-files" class="swsib-clean-button danger"><?php _e('Delete Selected', 'swiftspeed-siberian'); ?></button>
                <button type="button" id="refresh-file-list" class="swsib-clean-button swsib-clean-refresh-button info">
                    <span class="refresh-icon"></span> <?php _e('Refresh', 'swiftspeed-siberian'); ?>
                </button>
            </div>
            <div class="swsib-clean-actions-right">
                <div class="swsib-clean-file-info">
                    <span id="selected-count">0</span> <?php _e('items selected', 'swiftspeed-siberian'); ?>
                </div>
            </div>
        </div>
        
        <!-- File Listing -->
        <table class="swsib-clean-table file-browser-table">
            <thead>
                <tr>
                    <th class="checkbox-cell"><input type="checkbox" id="select-all-files"></th>
                    <th class="swsib-clean-file-icon"></th>
                    <th class="sortable" data-sort="name"><?php _e('Name', 'swiftspeed-siberian'); ?></th>
                    <th class="sortable" data-sort="type"><?php _e('Type', 'swiftspeed-siberian'); ?></th>
                    <th class="sortable" data-sort="size"><?php _e('Size', 'swiftspeed-siberian'); ?></th>
                    <th class="sortable" data-sort="modified"><?php _e('Last Modified', 'swiftspeed-siberian'); ?></th>
                    <th><?php _e('Actions', 'swiftspeed-siberian'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="7" class="text-center loading-indicator">
                        <div class="swsib-clean-spinner"></div> <?php _e('Loading files...', 'swiftspeed-siberian'); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="swsib-clean-pagination">
            <div class="swsib-clean-pagination-info">
                Showing <span id="pagination-start">1</span> to <span id="pagination-end">25</span> of <span id="pagination-total">0</span> items
            </div>
            <div class="swsib-clean-pagination-controls">
                <button type="button" id="pagination-prev" class="swsib-clean-pagination-prev" disabled>Prev</button>
                <span id="pagination-pages"></span>
                <button type="button" id="pagination-next" class="swsib-clean-pagination-next" disabled>Next</button>
            </div>
        </div>
    </div>
    
    <!-- File Preview Modal -->
    <div id="file-preview-modal" class="swsib-clean-modal-overlay">
        <div class="swsib-clean-modal swsib-clean-file-preview-modal">
            <div class="swsib-clean-modal-header">
                <h3 id="preview-file-name"></h3>
                <button type="button" class="swsib-clean-modal-close">&times;</button>
            </div>
            <div class="swsib-clean-modal-body">
                <div id="file-preview-loading" class="file-preview-loading">
                    <div class="swsib-clean-spinner"></div> 
                    <?php _e('Loading file preview...', 'swiftspeed-siberian'); ?>
                </div>
                <div id="file-preview-content" class="file-preview-content"></div>
                <div id="file-preview-error" class="file-preview-error" style="display: none;">
                    <?php _e('Unable to preview this file. The file may be too large or in an unsupported format.', 'swiftspeed-siberian'); ?>
                </div>
            </div>
            <div class="swsib-clean-modal-footer">
                <button type="button" class="swsib-clean-button swsib-clean-modal-cancel"><?php _e('Close', 'swiftspeed-siberian'); ?></button>
                <button type="button" id="download-file-btn" class="swsib-clean-button primary"><?php _e('Download', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>
    </div>
    
    <!-- File Edit Modal -->
    <div id="file-edit-modal" class="swsib-clean-modal-overlay">
        <div class="swsib-clean-modal swsib-clean-file-edit-modal">
            <div class="swsib-clean-modal-header">
                <h3 id="edit-file-name"></h3>
                <button type="button" class="swsib-clean-modal-close">&times;</button>
            </div>
            <div class="swsib-clean-modal-body">
                <div id="file-edit-loading" class="file-edit-loading">
                    <div class="swsib-clean-spinner"></div> 
                    <?php _e('Loading file content...', 'swiftspeed-siberian'); ?>
                </div>
                <textarea id="file-edit-content" class="file-edit-content"></textarea>
                <div id="file-edit-error" class="file-edit-error" style="display: none;">
                    <?php _e('Unable to edit this file. The file may be too large or in an unsupported format.', 'swiftspeed-siberian'); ?>
                </div>
            </div>
            <div class="swsib-clean-modal-footer">
                <button type="button" class="swsib-clean-button swsib-clean-modal-cancel"><?php _e('Cancel', 'swiftspeed-siberian'); ?></button>
                <button type="button" id="save-file-btn" class="swsib-clean-button primary"><?php _e('Save Changes', 'swiftspeed-siberian'); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom styles for the file explorer */
.swsib-clean-file-explorer {
    margin-top: 20px;
}

.swsib-clean-breadcrumb-nav {
    margin-bottom: 20px;
}

.swsib-clean-breadcrumbs {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    padding: 0;
    margin: 0;
    background-color: #f8f9fa;
    border-radius: 4px;
    padding: 10px 15px;
    border: 1px solid #ddd;
}

.swsib-clean-breadcrumbs li {
    display: inline-block;
    margin-right: 5px;
}

.swsib-clean-breadcrumbs li:not(:last-child):after {
    content: "/";
    margin-left: 5px;
    color: #6c757d;
}

.swsib-clean-breadcrumbs a {
    color: #3a4b79;
    text-decoration: none;
    cursor: pointer;
}

.swsib-clean-breadcrumbs a:hover {
    text-decoration: underline;
}

.swsib-clean-breadcrumbs li:last-child a {
    color: #495057;
    font-weight: 600;
    cursor: default;
}

.swsib-clean-breadcrumbs li:last-child a:hover {
    text-decoration: none;
}

.swsib-clean-file-info {
    font-size: 14px;
    color: #6c757d;
}

.swsib-clean-file-icon {
    width: 24px;
    text-align: center;
}
/* Remove underline from directory links */
.directory-link {
    text-decoration: none;
    color: #3a4b79;
}

.directory-link:hover {
    color: #2c3a5e;
    text-decoration: none; /* Ensure no underline on hover either */
}

/* Optional: add a subtle effect for better user experience */
.directory-link:hover {
    font-weight: 500;
}

.file-type-icon {
    width: 24px;
    height: 24px;
    display: inline-block;
    background-position: center;
    background-repeat: no-repeat;
    background-size: contain;
}

.file-browser-table .folder-row {
    background-color: rgba(58, 75, 121, 0.05);
}

.file-browser-table .parent-folder-row {
    background-color: rgba(58, 75, 121, 0.1);
}

/* Filter controls */
.swsib-clean-filter {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.swsib-clean-filter-left {
    display: flex;
}

.swsib-clean-search-input {
    width: 250px;
    margin-right: 10px;
}

.swsib-clean-select {
    padding: 4px 8px;
    border-radius: 4px;
    border: 1px solid #ddd;
}

/* File action buttons */
.file-action-button {
    padding: 4px 8px;
    margin: 0 3px;
    font-size: 12px;
}

/* Pagination */
.swsib-clean-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    padding: 10px 0;
    border-top: 1px solid #eee;
}

.swsib-clean-pagination-info {
    font-size: 13px;
    color: #666;
}

.swsib-clean-pagination-controls {
    display: flex;
    align-items: center;
}

.swsib-clean-pagination-controls button {
    padding: 5px 10px;
    margin: 0 2px;
    background: #f1f1f1;
    border: 1px solid #ddd;
    cursor: pointer;
    border-radius: 3px;
}

.swsib-clean-pagination-controls button.active {
    background: #2271b1;
    color: #fff;
    border-color: #135e96;
}

.swsib-clean-pagination-controls button:hover:not([disabled]) {
    background: #ddd;
}

.swsib-clean-pagination-controls button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* File preview styles */
.swsib-clean-file-preview-modal {
    width: 80%;
    max-width: 1200px;
    max-height: 80vh;
}

.file-preview-content {
    max-height: 60vh;
    overflow: auto;
    background-color: #f8f9fa;
    border-radius: 4px;
    border: 1px solid #ddd;
    padding: 15px;
}

.file-preview-content img {
    max-width: 100%;
    height: auto;
    display: block;
    margin: 0 auto;
}

.file-preview-content pre {
    margin: 0;
    padding: 15px;
    background-color: #f5f5f5;
    border-radius: 4px;
    overflow: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.5;
}

.file-preview-content pre code {
    font-family: monospace;
}

.file-preview-loading,
.file-preview-error,
.file-edit-loading,
.file-edit-error {
    padding: 30px;
    text-align: center;
    background-color: #f9f9f9;
    border-radius: 4px;
}

.file-preview-error,
.file-edit-error {
    color: #dc3545;
    background-color: #f8d7da;
    padding: 15px;
}

/* File Edit styles */
.swsib-clean-file-edit-modal {
    width: 80%;
    max-width: 1200px;
    max-height: 90vh;
}

.file-edit-content {
    width: 100%;
    min-height: 400px;
    max-height: 60vh;
    font-family: monospace;
    font-size: 14px;
    line-height: 1.5;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

/* Sortable columns */
.sortable {
    cursor: pointer;
    position: relative;
    padding-right: 20px;
}

/* Remove the question mark and use an arrow instead */
.sort-icon::after {
    content: "\25bc";
    position: absolute;
    right: 5px;
    opacity: 0.5;
    font-size: 10px;
}

.sort-asc .sort-icon::after {
    content: "\25b2";
    opacity: 1;
}

.sort-desc .sort-icon::after {
    content: "\25bc";
    opacity: 1;
}

/* Configuration button */
.config-installation-button {
    display: inline-block;
    margin-top: 15px;
    padding: 8px 16px;
    background-color: #3a4b79;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-weight: 500;
    transition: background-color 0.2s;
}

.config-installation-button:hover {
    background-color: #2c3a5e;
    color: white;
}

/* Notification system */
.swsib-clean-notification {
    position: fixed;
    top: 50px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 4px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    z-index: 9999;
    display: none;
    max-width: 350px;
}

.swsib-clean-notification.success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.swsib-clean-notification.error {
    background-color: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.swsib-clean-notification.info {
    background-color: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}
</style>

<script>
/**
 * File Explorer Script for SiberianCMS Folder Cleanup.
 * 
 * This script handles browsing, viewing, and managing files 
 * in the SiberianCMS installation directory.
 */
jQuery(document).ready(function($) {
    // Define the admin URL first to avoid the "cannot access before initialization" error
    var adminUrl = '<?php echo esc_url(admin_url('admin.php?page=swsib-integration&tab_id=db_connect&dbconnect_tab=installation')); ?>';
    
    // Enable debugging - this helps track AJAX issues
    var debug = true;
    
    function debugLog() {
        if (debug && console && console.log) {
            console.log.apply(console, arguments);
        }
    }
    
    // Configuration
    const config = {
        currentPath: '', // Will be set after initial connection
        connectionMethod: '', // 'ftp', 'sftp', or 'local'
        connectionDetails: {}, // Will store connection parameters
        selectedItems: [],
        fileData: {
            directories: [],
            files: []
        },
        pagination: {
            currentPage: 1,
            itemsPerPage: 25,
            totalItems: 0,
            totalPages: 1
        },
        sorting: {
            column: 'name',
            direction: 'asc'
        },
        previewableExtensions: [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp',
            // Text files
            'txt', 'log', 'md', 'csv', 'json', 'xml',
            // Code files
            'php', 'js', 'css', 'html', 'htm', 'ini', 'conf', 'config',
            // Document files
            'pdf'
        ],
        imageExtensions: ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'],
        editableExtensions: [
            'txt', 'log', 'md', 'csv', 'json', 'xml', 'php', 'js', 'css', 
            'html', 'htm', 'ini', 'conf', 'config'
        ]
    };

    // DOM elements
    const elements = {
        fileExplorer: $('#file-explorer'),
        fileConnectStatus: $('#file-connect-status'),
        pathBreadcrumbs: $('#path-breadcrumbs'),
        fileTable: $('.file-browser-table tbody'),
        selectAllCheckbox: $('#select-all-files'),
        deleteSelectedBtn: $('#delete-selected-files'),
        refreshFileListBtn: $('#refresh-file-list'),
        selectedCountSpan: $('#selected-count'),
        fileSearch: $('#file-search'),
        fileSearchBtn: $('#file-search-btn'),
        itemsPerPage: $('#items-per-page'),
        paginationInfo: $('.swsib-clean-pagination-info'),
        paginationStart: $('#pagination-start'),
        paginationEnd: $('#pagination-end'),
        paginationTotal: $('#pagination-total'),
        paginationControls: $('.swsib-clean-pagination-controls'),
        paginationPages: $('#pagination-pages'),
        paginationPrev: $('#pagination-prev'),
        paginationNext: $('#pagination-next'),
        filePreviewModal: $('#file-preview-modal'),
        previewFileName: $('#preview-file-name'),
        filePreviewContent: $('#file-preview-content'),
        filePreviewLoading: $('#file-preview-loading'),
        filePreviewError: $('#file-preview-error'),
        downloadFileBtn: $('#download-file-btn'),
        fileEditModal: $('#file-edit-modal'),
        editFileName: $('#edit-file-name'),
        fileEditContent: $('#file-edit-content'),
        fileEditLoading: $('#file-edit-loading'),
        fileEditError: $('#file-edit-error'),
        saveFileBtn: $('#save-file-btn')
    };

    // Initialize
    init();

    /**
     * Initialize the file explorer
     */
    function init() {
        // Check if we have a connection to the installation
        checkConnection();

        // Bind event handlers
        bindEvents();
    }

    /**
     * Check connection to the SiberianCMS installation
     */
    function checkConnection() {
        // Show status message
        elements.fileConnectStatus.html(`
            <p>
                <div class="swsib-clean-spinner"></div> 
                ${swsib_clean.processing} Connecting to your SiberianCMS installation...
            </p>
        `);

        // Get the connection method and details from the plugin configuration
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_installation_config',
                nonce: swsib_clean.nonce // Use swsib_clean.nonce for this call
            },
            success: function(response) {
                if (response.success) {
                    // Store connection details
                    config.connectionMethod = response.data.method;
                    config.connectionDetails = response.data.details;
                    config.currentPath = response.data.details.path || '/';

                    // Show success message
                    let methodName = 'unknown';
                    if (config.connectionMethod === 'ftp') methodName = 'FTP';
                    else if (config.connectionMethod === 'sftp') methodName = 'SFTP';
                    else if (config.connectionMethod === 'local') methodName = 'Local filesystem';
                    
                    elements.fileConnectStatus.html(`
                        <p><strong>Connection successful!</strong> Using ${methodName} connection to browse files.</p>
                    `).removeClass('info warning danger').addClass('success');

                    // Log connection details for debugging
                    debugLog('Connection method:', config.connectionMethod);
                    debugLog('Connection details:', config.connectionDetails);

                    // Show file explorer
                    elements.fileExplorer.show();

                    // Load files for the initial path
                    loadFilesForPath(config.currentPath);
                } else {
                    showConnectionError(response.data.message || 'Failed to get installation connection details.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.log(xhr.responseText);
                showConnectionError('AJAX request failed. Please check your internet connection and try again.');
            }
        });
    }

    /**
     * Show connection error message with a button to go to Installation settings
     */
    function showConnectionError(message) {
        elements.fileConnectStatus.html(`
            <p><strong>Connection Error:</strong> ${message}</p>
            <p>Please configure your installation connection in the "Installation" tab before using this feature.</p>
            <a href="${adminUrl}" class="config-installation-button">Configure Installation Connection</a>
        `).removeClass('info success').addClass('danger');
    }

    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Refresh file list button
        elements.refreshFileListBtn.on('click', function() {
            loadFilesForPath(config.currentPath);
        });

        // Select all checkbox
        elements.selectAllCheckbox.on('change', function() {
            const isChecked = $(this).prop('checked');
            $('.file-item-checkbox').prop('checked', isChecked);
            updateSelectionInfo();
        });

        // Delete selected button
        elements.deleteSelectedBtn.on('click', function() {
            const selectedItems = getSelectedItems();
            if (selectedItems.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }

            const folders = selectedItems.filter(item => item.type === 'folder').length;
            const files = selectedItems.filter(item => item.type === 'file').length;
            
            let confirmMessage = 'Are you sure you want to delete ';
            if (files > 0 && folders > 0) {
                confirmMessage += `${files} file(s) and ${folders} folder(s)?`;
            } else if (files > 0) {
                confirmMessage += `${files} file(s)?`;
            } else {
                confirmMessage += `${folders} folder(s)?`;
            }
            
            confirmMessage += ' This action cannot be undone!';

            showModal(
                'Delete Selected Items',
                confirmMessage,
                function() {
                    deleteSelectedItems(selectedItems);
                }
            );
        });

        // Close preview modal when clicking on the close button or cancel button
        $('.swsib-clean-modal-close, .swsib-clean-modal-cancel').on('click', function() {
            closeModals();
        });

        // Close modal when clicking outside the modal
        $('.swsib-clean-modal-overlay').on('click', function(e) {
            if ($(e.target).hasClass('swsib-clean-modal-overlay')) {
                closeModals();
            }
        });

        // Download button in preview modal
        elements.downloadFileBtn.on('click', function() {
            const filePath = $(this).data('file-path');
            if (filePath) {
                downloadFile(filePath);
            }
        });

        // Save button in edit modal
        elements.saveFileBtn.on('click', function() {
            const filePath = $(this).data('file-path');
            const content = elements.fileEditContent.val();
            if (filePath && content !== undefined) {
                saveFile(filePath, content);
            }
        });

        // Search button click
        elements.fileSearchBtn.on('click', function() {
            filterAndDisplayFiles();
        });

        // Search input on enter key
        elements.fileSearch.on('keypress', function(e) {
            if (e.which === 13) {
                filterAndDisplayFiles();
            }
        });

        // Items per page change
        elements.itemsPerPage.on('change', function() {
            config.pagination.itemsPerPage = parseInt($(this).val(), 10);
            config.pagination.currentPage = 1; // Reset to first page when changing items per page
            filterAndDisplayFiles();
        });

        // Pagination next button
        elements.paginationNext.on('click', function() {
            if (config.pagination.currentPage < config.pagination.totalPages) {
                config.pagination.currentPage++;
                filterAndDisplayFiles();
            }
        });

        // Pagination previous button
        elements.paginationPrev.on('click', function() {
            if (config.pagination.currentPage > 1) {
                config.pagination.currentPage--;
                filterAndDisplayFiles();
            }
        });

        // Pagination page buttons
        $(document).on('click', '.pagination-page-btn', function() {
            const page = parseInt($(this).data('page'), 10);
            if (page !== config.pagination.currentPage) {
                config.pagination.currentPage = page;
                filterAndDisplayFiles();
            }
        });

        // Sortable column headers
        $('.file-browser-table th.sortable').on('click', function() {
            const column = $(this).data('sort');
            
            // Toggle sort direction if same column is clicked
            if (config.sorting.column === column) {
                config.sorting.direction = config.sorting.direction === 'asc' ? 'desc' : 'asc';
            } else {
                config.sorting.column = column;
                config.sorting.direction = 'asc';
            }
            
            // Update sort indicators
            updateSortIndicators();
            
            // Refresh the display with new sorting
            filterAndDisplayFiles();
        });
    }

    /**
     * Add method-specific parameters to AJAX request data
     */
    function addConnectionParams(data) {
        // Clone the data to avoid modifying the original
        let params = {...data};
        
        // Add the connection method
        params.method = config.connectionMethod;
        
        // Add method-specific parameters
        if (config.connectionMethod === 'ftp') {
            params.host_ftp = config.connectionDetails.host;
            params.username_ftp = config.connectionDetails.username;
            params.password_ftp = config.connectionDetails.password;
            params.port_ftp = config.connectionDetails.port || '21';
            params.path_local = ''; // Clear any local path
        } 
        else if (config.connectionMethod === 'sftp') {
            params.host_sftp = config.connectionDetails.host;
            params.username_sftp = config.connectionDetails.username;
            params.password_sftp = config.connectionDetails.password;
            params.port_sftp = config.connectionDetails.port || '22';
            params.path_local = ''; // Clear any local path
        } 
        else if (config.connectionMethod === 'local') {
            params.path_local = config.connectionDetails.path;
            // Clear any FTP/SFTP params
            params.host_ftp = '';
            params.username_ftp = '';
            params.password_ftp = '';
            params.port_ftp = '';
            params.host_sftp = '';
            params.username_sftp = '';
            params.password_sftp = '';
            params.port_sftp = '';
        }
        
        return params;
    }

    /**
     * Load files for the specified path
     */
    function loadFilesForPath(path) {
        // Show loading indicator
        elements.fileTable.html(`
            <tr>
                <td colspan="7" class="text-center loading-indicator">
                    <div class="swsib-clean-spinner"></div> Loading files...
                </td>
            </tr>
        `);

        // Reset search input
        elements.fileSearch.val('');

        // Clear selection
        config.selectedItems = [];
        elements.selectAllCheckbox.prop('checked', false);
        updateSelectionInfo();

        // Reset pagination to first page
        config.pagination.currentPage = 1;

        // Set current path
        config.currentPath = path;

        // Update breadcrumbs
        updateBreadcrumbs(path);

        // Prepare base data for the AJAX request
        let ajaxData = {
            action: 'swsib_browse_directory',
            nonce: swsib_af_vars.nonce, // Use swsib_af_vars.nonce for browse_directory
            path: path
        };
        
        // Add connection parameters
        ajaxData = addConnectionParams(ajaxData);
        
        // Debug log the request data
        debugLog('Browse directory request:', ajaxData);

        // Send AJAX request to get files
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    // Store the file data
                    config.fileData.directories = response.data.directories || [];
                    config.fileData.files = response.data.files || [];
                    
                    // Update the current path if provided
                    if (response.data.path) {
                        config.currentPath = response.data.path;
                        updateBreadcrumbs(response.data.path);
                    }
                    
                    // Update pagination
                    config.pagination.totalItems = config.fileData.directories.length + config.fileData.files.length;
                    config.pagination.totalPages = Math.ceil(config.pagination.totalItems / config.pagination.itemsPerPage);
                    
                    // Apply initial sorting
                    sortItems();
                    
                    // Display files with pagination
                    filterAndDisplayFiles();
                    
                    // Update sort indicators
                    updateSortIndicators();
                } else {
                    elements.fileTable.html(`
                        <tr>
                            <td colspan="7" class="text-center error-message">
                                Error: ${response.data.message || 'Failed to load files'}
                            </td>
                        </tr>
                    `);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.log(xhr.responseText);
                elements.fileTable.html(`
                    <tr>
                        <td colspan="7" class="text-center error-message">
                            AJAX request failed: ${error} - Please check your internet connection and try again.
                        </td>
                    </tr>
                `);
            }
        });
    }

    /**
     * Filter and display files based on search and pagination
     */
    function filterAndDisplayFiles() {
        const searchTerm = elements.fileSearch.val().toLowerCase();
        let filteredDirs = config.fileData.directories;
        let filteredFiles = config.fileData.files;
        
        // Apply search filter if there's a search term
        if (searchTerm) {
            // Always include parent directory if it exists
            const parentDir = filteredDirs.find(dir => dir.is_parent);
            
            // Filter directories
            filteredDirs = filteredDirs.filter(dir => {
                // Always include parent directory
                if (dir.is_parent) return true;
                
                const dirName = dir.name || getNameFromPath(dir.path);
                return dirName.toLowerCase().includes(searchTerm);
            });
            
            // Filter files
            filteredFiles = filteredFiles.filter(file => {
                const fileName = file.name || getNameFromPath(file.path);
                return fileName.toLowerCase().includes(searchTerm);
            });
            
            // If parent directory was filtered out but existed in original, add it back
            if (parentDir && !filteredDirs.some(dir => dir.is_parent)) {
                filteredDirs.unshift(parentDir);
            }
        }
        
        // Apply sorting
        filteredDirs = sortItems(filteredDirs, 'directories');
        filteredFiles = sortItems(filteredFiles, 'files');
        
        // Update pagination info
        const totalItems = filteredDirs.length + filteredFiles.length;
        const totalPages = Math.max(1, Math.ceil(totalItems / config.pagination.itemsPerPage));
        
        // Adjust current page if it's out of bounds
        if (config.pagination.currentPage > totalPages) {
            config.pagination.currentPage = totalPages;
        }
        
        // Calculate pagination values
        const start = (config.pagination.currentPage - 1) * config.pagination.itemsPerPage + 1;
        const end = Math.min(start + config.pagination.itemsPerPage - 1, totalItems);
        
        // Update pagination info display
        elements.paginationStart.text(totalItems > 0 ? start : 0);
        elements.paginationEnd.text(end);
        elements.paginationTotal.text(totalItems);
        
        // Update pagination controls
        updatePaginationControls(config.pagination.currentPage, totalPages);
        
        // Apply pagination
        const startIndex = (config.pagination.currentPage - 1) * config.pagination.itemsPerPage;
        const endIndex = startIndex + config.pagination.itemsPerPage;
        
        // Slice arrays for pagination, but always show parent directory if it exists
        let pagedDirs = [];
        let pagedFiles = [];
        
        // Check if parent directory exists
        const parentDir = filteredDirs.find(dir => dir.is_parent);
        
        if (parentDir) {
            // Always include parent directory
            pagedDirs.push(parentDir);
            
            // Get remaining directories (excluding parent) with pagination
            const remainingDirs = filteredDirs.filter(dir => !dir.is_parent);
            const itemsToDisplay = config.pagination.itemsPerPage - 1; // -1 for parent dir
            
            // If we're on the first page, show directories first
            if (config.pagination.currentPage === 1) {
                // Add directories (excluding parent) up to the limit
                pagedDirs = pagedDirs.concat(remainingDirs.slice(0, itemsToDisplay));
                
                // Add files if there's room
                if (pagedDirs.length < config.pagination.itemsPerPage) {
                    pagedFiles = filteredFiles.slice(0, config.pagination.itemsPerPage - pagedDirs.length);
                }
            } else {
                // For subsequent pages, calculate correct offsets
                const totalDirs = remainingDirs.length;
                const offset = startIndex - 1; // -1 for parent dir
                
                if (offset < totalDirs) {
                    // Still showing directories
                    pagedDirs = pagedDirs.concat(remainingDirs.slice(offset, offset + itemsToDisplay));
                    
                    // Add files if there's room
                    if (pagedDirs.length < config.pagination.itemsPerPage) {
                        pagedFiles = filteredFiles.slice(0, config.pagination.itemsPerPage - pagedDirs.length);
                    }
                } else {
                    // Only showing files
                    const fileOffset = offset - totalDirs;
                    pagedFiles = filteredFiles.slice(fileOffset, fileOffset + config.pagination.itemsPerPage - 1);
                }
            }
        } else {
            // No parent directory, simple pagination
            const combinedItems = filteredDirs.concat(filteredFiles);
            const pagedItems = combinedItems.slice(startIndex, endIndex);
            
            // Split back into directories and files
            pagedDirs = pagedItems.filter(item => item.type === 'folder' || item.path.indexOf('.') === -1);
            pagedFiles = pagedItems.filter(item => item.type === 'file' || item.path.indexOf('.') !== -1);
        }
        
        // Display the paged items
        displayItems(pagedDirs, pagedFiles);
    }

    /**
     * Display the specified items in the file browser
     */
    function displayItems(directories, files) {
        // Check if we have any items to display
        if (directories.length === 0 && files.length === 0) {
            elements.fileTable.html(`
                <tr>
                    <td colspan="7" class="text-center">
                        No files or directories found in this location.
                    </td>
                </tr>
            `);
            return;
        }

        // Clear table
        elements.fileTable.empty();

        // Add directories first
        directories.forEach(function(dir) {
            const isParentDir = dir.is_parent || false;
            const rowClass = isParentDir ? 'parent-folder-row' : 'folder-row';
            const dirName = dir.name || getNameFromPath(dir.path);
            
            // Create row HTML
            const row = `
                <tr class="${rowClass}" data-path="${dir.path}" data-type="folder" data-name="${dirName}">
                    <td class="checkbox-cell">
                        ${isParentDir ? '' : `<input type="checkbox" class="file-item-checkbox" data-path="${dir.path}" data-type="folder" data-name="${dirName}">`}
                    </td>
                    <td class="swsib-clean-file-icon">
                        <span class="dashicons dashicons-${isParentDir ? 'arrow-up-alt' : 'portfolio'}"></span>
                    </td>
                    <td>
                        <a href="#" class="directory-link" data-path="${dir.path}">${dirName}</a>
                    </td>
                    <td>Directory</td>
                    <td>-</td>
                    <td>${isParentDir ? '-' : (dir.modified || '-')}</td>
                    <td>
                        ${isParentDir ? '' : `
                            <button type="button" class="file-action-button swsib-clean-button danger delete-file-btn" data-path="${dir.path}" data-type="folder" data-name="${dirName}">
                                Delete
                            </button>
                        `}
                    </td>
                </tr>
            `;
            
            elements.fileTable.append(row);
        });

        // Add files
        files.forEach(function(file) {
            const fileName = file.name || getNameFromPath(file.path);
            const fileExtension = getFileExtension(fileName);
            const fileType = getFileType(fileExtension);
            const fileIcon = getFileIconClass(fileName, fileExtension);
            const canEdit = isEditableFile(fileExtension);
            
            // Create row HTML
            const row = `
                <tr data-path="${file.path}" data-type="file" data-name="${fileName}" data-extension="${fileExtension}">
                    <td class="checkbox-cell">
                        <input type="checkbox" class="file-item-checkbox" data-path="${file.path}" data-type="file" data-name="${fileName}" data-extension="${fileExtension}">
                    </td>
                    <td class="swsib-clean-file-icon">
                        <span class="dashicons ${fileIcon}"></span>
                    </td>
                    <td>${fileName}</td>
                    <td>${fileType}</td>
                    <td>${file.size || '-'}</td>
                    <td>${file.modified || '-'}</td>
                    <td>
                        ${isPreviewable(fileExtension) ? `
                            <button type="button" class="file-action-button swsib-clean-button info preview-file-btn" data-path="${file.path}" data-type="file" data-name="${fileName}" data-extension="${fileExtension}">
                                View
                            </button>
                        ` : ''}
                        ${canEdit ? `
                            <button type="button" class="file-action-button swsib-clean-button primary edit-file-btn" data-path="${file.path}" data-type="file" data-name="${fileName}" data-extension="${fileExtension}">
                                Edit
                            </button>
                        ` : ''}
                        <button type="button" class="file-action-button swsib-clean-button danger delete-file-btn" data-path="${file.path}" data-type="file" data-name="${fileName}">
                            Delete
                        </button>
                    </td>
                </tr>
            `;
            
            elements.fileTable.append(row);
        });

        // Bind event handlers for the new elements
        bindFileActions();
    }

    /**
     * Sort items based on current sort settings
     */
    function sortItems(items, itemType) {
        if (!items) {
            // If no specific items are provided, sort both directories and files
            sortItems(config.fileData.directories, 'directories');
            sortItems(config.fileData.files, 'files');
            return;
        }
        
        // Clone the array to avoid modifying the original
        const sortedItems = [...items];
        
        // Extract parent directory if it exists
        let parentDir = null;
        if (itemType === 'directories') {
            const parentIndex = sortedItems.findIndex(dir => dir.is_parent);
            if (parentIndex !== -1) {
                parentDir = sortedItems.splice(parentIndex, 1)[0];
            }
        }
        
        // Sort the items
        sortedItems.sort((a, b) => {
            let valueA, valueB;
            
            switch(config.sorting.column) {
                case 'name':
                    valueA = (a.name || getNameFromPath(a.path)).toLowerCase();
                    valueB = (b.name || getNameFromPath(b.path)).toLowerCase();
                    break;
                case 'type':
                    // For type sorting, use file extension or 'Directory'
                    if (itemType === 'directories') {
                        return 0; // Same type, no change
                    }
                    valueA = getFileType(getFileExtension(a.name || getNameFromPath(a.path))).toLowerCase();
                    valueB = getFileType(getFileExtension(b.name || getNameFromPath(b.path))).toLowerCase();
                    break;
                case 'size':
                    // For size sorting, parse size or use 0 for directories
                    valueA = itemType === 'directories' ? 0 : (parseInt(a.size) || 0);
                    valueB = itemType === 'directories' ? 0 : (parseInt(b.size) || 0);
                    break;
                case 'modified':
                    // For modified date sorting, compare dates or use empty string for unknown
                    valueA = a.modified ? new Date(a.modified).getTime() : 0;
                    valueB = b.modified ? new Date(b.modified).getTime() : 0;
                    break;
                default:
                    valueA = (a.name || getNameFromPath(a.path)).toLowerCase();
                    valueB = (b.name || getNameFromPath(b.path)).toLowerCase();
            }
            
            // Compare values with sort direction
            if (valueA === valueB) return 0;
            
            const comparison = valueA < valueB ? -1 : 1;
            return config.sorting.direction === 'asc' ? comparison : -comparison;
        });
        
        // Re-add parent directory at the beginning if it exists
        if (parentDir) {
            sortedItems.unshift(parentDir);
        }
        
        // If updating in place
        if (!itemType) {
            return;
        }
        
        return sortedItems;
    }

    /**
     * Update sort indicators in the column headers
     */
    function updateSortIndicators() {
        const headers = $('.file-browser-table th.sortable');
        
        // Remove all sort classes
        headers.removeClass('sort-asc sort-desc');
        
        // Add appropriate sort class to active column
        headers.filter(`[data-sort="${config.sorting.column}"]`)
            .addClass(config.sorting.direction === 'asc' ? 'sort-asc' : 'sort-desc');
    }

    /**
     * Update pagination controls
     */
    function updatePaginationControls(currentPage, totalPages) {
        // Update previous/next buttons
        elements.paginationPrev.prop('disabled', currentPage <= 1);
        elements.paginationNext.prop('disabled', currentPage >= totalPages);
        
        // Update page buttons
        let pagesHtml = '';
        
        // Determine range of page numbers to show
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        
        // Adjust start if end is maxed out
        if (endPage === totalPages) {
            startPage = Math.max(1, endPage - 4);
        }
        
        // First page
        if (startPage > 1) {
            pagesHtml += `<button type="button" class="pagination-page-btn" data-page="1">1</button>`;
            if (startPage > 2) {
                pagesHtml += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        // Page numbers
        for (let i = startPage; i <= endPage; i++) {
            pagesHtml += `<button type="button" class="pagination-page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
        }
        
        // Last page
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                pagesHtml += `<span class="pagination-ellipsis">...</span>`;
            }
            pagesHtml += `<button type="button" class="pagination-page-btn" data-page="${totalPages}">${totalPages}</button>`;
        }
        
        elements.paginationPages.html(pagesHtml);
    }

    /**
     * Bind event handlers for file actions
     */
    function bindFileActions() {
        // Directory links
        $('.directory-link').on('click', function(e) {
            e.preventDefault();
            const path = $(this).data('path');
            loadFilesForPath(path);
        });

        // File item checkboxes
        $('.file-item-checkbox').on('change', function() {
            updateSelectionInfo();
        });

        // Preview file buttons
        $('.preview-file-btn').on('click', function() {
            const path = $(this).data('path');
            const name = $(this).data('name');
            const extension = $(this).data('extension');
            previewFile(path, name, extension);
        });

        // Edit file buttons
        $('.edit-file-btn').on('click', function() {
            const path = $(this).data('path');
            const name = $(this).data('name');
            const extension = $(this).data('extension');
            editFile(path, name, extension);
        });

        // Delete file/folder buttons
        $('.delete-file-btn').on('click', function() {
            const path = $(this).data('path');
            const type = $(this).data('type');
            const name = $(this).data('name');
            
            // Show confirmation modal
            showModal(
                `Delete ${type === 'folder' ? 'Folder' : 'File'}`,
                `Are you sure you want to delete ${type === 'folder' ? 'folder' : 'file'} "${name}"? This action cannot be undone!`,
                function() {
                    // Delete file or folder
                    deleteItem(path, type, name);
                }
            );
        });
    }

    /**
     * Update breadcrumb navigation
     */
    function updateBreadcrumbs(path) {
        // Clear breadcrumbs
        elements.pathBreadcrumbs.empty();
        
        // Split path into parts
        const parts = path.split('/').filter(Boolean);
        
        // Add root
        elements.pathBreadcrumbs.append(`<li><a data-path="/">Root</a></li>`);
        
        // Add intermediate paths
        let currentPath = '';
        for (let i = 0; i < parts.length; i++) {
            currentPath += '/' + parts[i];
            const isLast = i === parts.length - 1;
            
            elements.pathBreadcrumbs.append(`
                <li><a data-path="${currentPath}" ${isLast ? 'class="active"' : ''}>${parts[i]}</a></li>
            `);
        }
        
        // Bind click event to breadcrumb links
        elements.pathBreadcrumbs.find('a').not('.active').on('click', function() {
            const path = $(this).data('path');
            loadFilesForPath(path);
        });
    }

    /**
     * Update selection info (count and total size)
     */
    function updateSelectionInfo() {
        const selectedItems = getSelectedItems();
        elements.selectedCountSpan.text(selectedItems.length);
    }

    /**
     * Get selected items from checkboxes
     */
    function getSelectedItems() {
        const selectedItems = [];
        
        $('.file-item-checkbox:checked').each(function() {
            const $this = $(this);
            selectedItems.push({
                path: $this.data('path'),
                type: $this.data('type'),
                name: $this.data('name'),
                extension: $this.data('extension')
            });
        });
        
        return selectedItems;
    }

    /**
     * Delete selected items
     */
    function deleteSelectedItems(items) {
        if (items.length === 0) return;
        
        // Disable buttons
        elements.deleteSelectedBtn.prop('disabled', true);
        elements.refreshFileListBtn.prop('disabled', true);
        
        // Reset progress tracking
        resetProgress();
        operationProgress.totalSteps = items.length;
        operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + items.length + ' item(s)...' });
        
        // Show progress
        const progressContainer = $('.swsib-clean-progress');
        progressContainer.addClass('active');
        
        // Show log
        const logContainer = $('.swsib-clean-log');
        logContainer.addClass('active');
        
        // Initial progress update
        updateProgressDisplay(progressContainer, logContainer);
        
        // Delete each item in sequence
        const deleteNext = function(index) {
            if (index >= items.length) {
                // All items deleted, refresh file list
                operationProgress.detailedLog.push({ type: 'success', message: 'All items deleted successfully.' });
                updateProgressDisplay(progressContainer, logContainer);
                
                // Re-enable buttons
                elements.deleteSelectedBtn.prop('disabled', false);
                elements.refreshFileListBtn.prop('disabled', false);
                
                // Refresh file list
                loadFilesForPath(config.currentPath);
                return;
            }
            
            const item = items[index];
            
            // Prepare base data for the AJAX request
            let ajaxData = {
                action: 'swsib_delete_file',
                nonce: swsib_clean.nonce, // Use swsib_clean.nonce for this call
                path: item.path,
                type: item.type
            };
            
            // Add connection parameters
            ajaxData = addConnectionParams(ajaxData);
            
            // Debug log the request data
            debugLog('Delete item request:', ajaxData);
            
            // Send AJAX request to delete item
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep++;
                        operationProgress.detailedLog.push({ 
                            type: 'success', 
                            message: `Deleted ${item.type} "${item.name}"`
                        });
                    } else {
                        operationProgress.detailedLog.push({ 
                            type: 'error', 
                            message: `Failed to delete ${item.type} "${item.name}": ${response.data.message}`
                        });
                    }
                    
                    // Update progress display
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    // Delete next item
                    deleteNext(index + 1);
                },
                error: function(xhr, status, error) {
                    debugLog('Error deleting item:', xhr.responseText);
                    operationProgress.detailedLog.push({ 
                        type: 'error', 
                        message: `AJAX error when deleting ${item.type} "${item.name}": ${error}`
                    });
                    
                    // Update progress display
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    // Delete next item
                    deleteNext(index + 1);
                }
            });
        };
        
        // Start deleting items
        deleteNext(0);
    }

    /**
     * Delete a single item
     */
    function deleteItem(path, type, name) {
        // Disable buttons
        $('.swsib-clean-button').prop('disabled', true);
        
        // Reset progress tracking
        resetProgress();
        operationProgress.totalSteps = 1;
        operationProgress.detailedLog.push({ 
            type: 'info', 
            message: `Starting deletion of ${type} "${name}"...`
        });
        
        // Show progress
        const progressContainer = $('.swsib-clean-progress');
        progressContainer.addClass('active');
        
        // Show log
        const logContainer = $('.swsib-clean-log');
        logContainer.addClass('active');
        
        // Initial progress update
        updateProgressDisplay(progressContainer, logContainer);
        
        // Prepare base data for the AJAX request
        let ajaxData = {
            action: 'swsib_delete_file',
            nonce: swsib_clean.nonce, // Use swsib_clean.nonce for this call
            path: path,
            type: type
        };
        
        // Add connection parameters
        ajaxData = addConnectionParams(ajaxData);
        
        // Debug log the request data
        debugLog('Delete single item request:', ajaxData);
        
        // Send AJAX request to delete item
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                if (response.success) {
                    operationProgress.currentStep = 1;
                    operationProgress.detailedLog.push({ 
                        type: 'success', 
                        message: `Deleted ${type} "${name}"`
                    });
                    
                    // Refresh file list
                    loadFilesForPath(config.currentPath);
                } else {
                    operationProgress.detailedLog.push({ 
                        type: 'error', 
                        message: `Failed to delete ${type} "${name}": ${response.data.message}`
                    });
                }
                
                // Update progress display
                updateProgressDisplay(progressContainer, logContainer);
                
                // Re-enable buttons
                $('.swsib-clean-button').prop('disabled', false);
            },
            error: function(xhr, status, error) {
                debugLog('Error deleting single item:', xhr.responseText);
                operationProgress.detailedLog.push({ 
                    type: 'error', 
                    message: `AJAX error when deleting ${type} "${name}": ${error}`
                });
                
                // Update progress display
                updateProgressDisplay(progressContainer, logContainer);
                
                // Re-enable buttons
                $('.swsib-clean-button').prop('disabled', false);
            }
        });
    }

    /**
     * Preview a file
     */
    function previewFile(path, name, extension) {
        // Show modal
        elements.filePreviewModal.addClass('active');
        
        // Set file name
        elements.previewFileName.text(name);
        
        // Show loading
        elements.filePreviewLoading.show();
        elements.filePreviewContent.empty().hide();
        elements.filePreviewError.hide();
        
        // Set download button data
        elements.downloadFileBtn.data('file-path', path);
        
        // Prepare base data for the AJAX request
        let ajaxData = {
            action: 'swsib_get_file_contents',
            nonce: swsib_clean.nonce, // Use swsib_clean.nonce for this call
            path: path
        };
        
        // Add connection parameters
        ajaxData = addConnectionParams(ajaxData);
        
        // Debug log the request data
        debugLog('Get file contents request:', ajaxData);
        
        // Send AJAX request to get file contents
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                elements.filePreviewLoading.hide();
                
                if (response.success) {
                    // Show preview based on file type
                    displayFilePreview(response.data.contents, extension);
                } else {
                    // Show error
                    elements.filePreviewError.show().text(response.data.message || 'Failed to load file contents');
                }
            },
            error: function(xhr, status, error) {
                elements.filePreviewLoading.hide();
                elements.filePreviewError.show().text('AJAX request failed: ' + error);
                debugLog('Error loading file preview:', xhr.responseText);
            }
        });
    }

    /**
     * Edit a file
     */
    function editFile(path, name, extension) {
        // Show modal
        elements.fileEditModal.addClass('active');
        
        // Set file name
        elements.editFileName.text(name);
        
        // Show loading
        elements.fileEditLoading.show();
        elements.fileEditContent.val('').hide();
        elements.fileEditError.hide();
        
        // Set save button data
        elements.saveFileBtn.data('file-path', path);
        
        // Prepare base data for the AJAX request
        let ajaxData = {
            action: 'swsib_get_file_contents',
            nonce: swsib_clean.nonce, // Use swsib_clean.nonce for this call
            path: path
        };
        
        // Add connection parameters
        ajaxData = addConnectionParams(ajaxData);
        
        // Debug log the request data
        debugLog('Get file contents for edit request:', ajaxData);
        
        // Send AJAX request to get file contents
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                elements.fileEditLoading.hide();
                
                if (response.success) {
                    // Load content into edit textarea
                    elements.fileEditContent.val(response.data.contents).show();
                } else {
                    // Show error
                    elements.fileEditError.show().text(response.data.message || 'Failed to load file contents for editing');
                }
            },
            error: function(xhr, status, error) {
                elements.fileEditLoading.hide();
                elements.fileEditError.show().text('AJAX request failed: ' + error);
                debugLog('Error loading file for edit:', xhr.responseText);
            }
        });
    }

    /**
     * Save file changes
     */
    function saveFile(path, content) {
        // Show loading
        elements.fileEditLoading.show();
        elements.fileEditContent.hide();
        elements.fileEditError.hide();
        
        // Prepare base data for the AJAX request
        let ajaxData = {
            action: 'swsib_save_file_contents',
            nonce: swsib_clean.nonce, // Use swsib_clean.nonce for this call
            path: path,
            content: content
        };
        
        // Add connection parameters
        ajaxData = addConnectionParams(ajaxData);
        
        // Debug log the request data
        debugLog('Save file contents request:', ajaxData);
        
        // Send AJAX request to save file
        $.ajax({
            url: swsib_clean.ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function(response) {
                elements.fileEditLoading.hide();
                
                if (response.success) {
                    // Close modal and show success message
                    closeModals();
                    
                    // Show success notification
                    showNotification('File saved successfully', 'success');
                    
                    // Refresh file list to show updated file details
                    loadFilesForPath(config.currentPath);
                } else {
                    // Show error
                    elements.fileEditError.show().text(response.data.message || 'Failed to save file');
                }
            },
            error: function(xhr, status, error) {
                elements.fileEditLoading.hide();
                elements.fileEditError.show().text('AJAX request failed: ' + error);
                debugLog('Error saving file:', xhr.responseText);
            }
        });
    }

    /**
     * Display file preview based on file type
     */
    function displayFilePreview(contents, extension) {
        // Check if contents is empty
        if (!contents) {
            elements.filePreviewError.show().text('File is empty or could not be read');
            return;
        }
        
        // Clear previous content
        elements.filePreviewContent.empty();
        
        // Determine how to display the file
        if (isImageExtension(extension)) {
            // For images, display with an img tag
            // Note: For FTP, this would typically be a base64 encoded image
            elements.filePreviewContent.html(`<img src="data:image/${extension};base64,${contents}" alt="Image Preview" />`);
        } else {
            // For text files, display with syntax highlighting if available
            elements.filePreviewContent.html(`<pre><code>${htmlEntities(contents)}</code></pre>`);
        }
        
        // Show content
        elements.filePreviewContent.show();
    }

    /**
     * Download a file
     */
    function downloadFile(path) {
        // Create a form to submit for file download
        const form = $('<form>', {
            action: swsib_clean.ajax_url,
            method: 'POST',
            target: '_blank'
        });
        
        // Add form fields
        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'swsib_download_file'
        }));
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: swsib_clean.nonce // Use swsib_clean.nonce for this call
        }));
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'path',
            value: path
        }));
        
        // Add method field
        form.append($('<input>', {
            type: 'hidden',
            name: 'method',
            value: config.connectionMethod
        }));
        
        // Add connection details based on method
        if (config.connectionMethod === 'ftp') {
            form.append($('<input>', {
                type: 'hidden',
                name: 'host_ftp',
                value: config.connectionDetails.host
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'username_ftp',
                value: config.connectionDetails.username
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'password_ftp',
                value: config.connectionDetails.password
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'port_ftp',
                value: config.connectionDetails.port || '21'
            }));
        } 
        else if (config.connectionMethod === 'sftp') {
            form.append($('<input>', {
                type: 'hidden',
                name: 'host_sftp', 
                value: config.connectionDetails.host
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'username_sftp',
                value: config.connectionDetails.username
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'password_sftp',
                value: config.connectionDetails.password
            }));
            form.append($('<input>', {
                type: 'hidden',
                name: 'port_sftp',
                value: config.connectionDetails.port || '22'
            }));
        }
        else if (config.connectionMethod === 'local') {
            form.append($('<input>', {
                type: 'hidden',
                name: 'path_local',
                value: config.connectionDetails.path
            }));
        }
        
        // Append form to body, submit, and remove
        $('body').append(form);
        form.submit();
        form.remove();
    }

    /**
     * Close all modals
     */
    function closeModals() {
        elements.filePreviewModal.removeClass('active');
        elements.filePreviewContent.empty();
        elements.previewFileName.text('');
        elements.downloadFileBtn.data('file-path', '');
        
        elements.fileEditModal.removeClass('active');
        elements.fileEditContent.val('');
        elements.editFileName.text('');
        elements.saveFileBtn.data('file-path', '');
    }

    /**
     * Show a modal with the given title, message and callbacks
     */
    function showModal(title, message, confirmCallback) {
        // Create modal if it doesn't exist
        if ($('.swsib-clean-modal-overlay.confirmation-modal').length === 0) {
            const modalHTML = `
                <div class="swsib-clean-modal-overlay confirmation-modal">
                    <div class="swsib-clean-modal">
                        <div class="swsib-clean-modal-header">
                            <h3></h3>
                            <button type="button" class="swsib-clean-modal-close">&times;</button>
                        </div>
                        <div class="swsib-clean-modal-body"></div>
                        <div class="swsib-clean-modal-footer">
                            <button type="button" class="swsib-clean-button swsib-clean-modal-cancel">Cancel</button>
                            <button type="button" class="swsib-clean-button danger swsib-clean-modal-confirm">Confirm</button>
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHTML);
            
            // Bind close events
            $('.confirmation-modal .swsib-clean-modal-close, .confirmation-modal .swsib-clean-modal-cancel').on('click', function() {
                $('.confirmation-modal').removeClass('active');
            });
            
            // Close modal when clicking on the overlay
            $('.confirmation-modal').on('click', function(e) {
                if ($(e.target).hasClass('swsib-clean-modal-overlay')) {
                    $('.confirmation-modal').removeClass('active');
                }
            });
        }
        
        // Set modal content
        $('.confirmation-modal .swsib-clean-modal-header h3').text(title);
        $('.confirmation-modal .swsib-clean-modal-body').html(message);
        
        // Set confirm button callback
        $('.confirmation-modal .swsib-clean-modal-confirm').off('click').on('click', function() {
            $('.confirmation-modal').removeClass('active');
            if (confirmCallback) confirmCallback();
        });
        
        // Show modal
        $('.confirmation-modal').addClass('active');
    }

    /**
     * Show a notification message
     */
    function showNotification(message, type) {
        // Create notification if it doesn't exist
        if ($('.swsib-clean-notification').length === 0) {
            $('body').append('<div class="swsib-clean-notification"></div>');
        }
        
        // Set notification content
        $('.swsib-clean-notification')
            .html(message)
            .removeClass('success error info')
            .addClass(type)
            .fadeIn()
            .delay(3000)
            .fadeOut();
    }

    /**
     * Reset progress tracking
     */
    function resetProgress() {
        operationProgress = {
            totalSteps: 0,
            currentStep: 0,
            detailedLog: []
        };
    }

    /**
     * Update progress display
     */
    function updateProgressDisplay(container, logContainer) {
        const progressBar = container.find('.swsib-clean-progress-bar-fill');
        const progressText = container.find('.swsib-clean-progress-text');
        
        // Calculate percentage
        let percentage = 0;
        if (operationProgress.totalSteps > 0) {
            percentage = Math.round((operationProgress.currentStep / operationProgress.totalSteps) * 100);
        }
        
        // Update progress bar
        progressBar.css('width', percentage + '%');
        
        // Update progress text
        progressText.text(percentage + '% Complete - ' + operationProgress.currentStep + ' of ' + operationProgress.totalSteps);
        
        // Update detailed log
        logContainer.empty();
        operationProgress.detailedLog.forEach(function(entry) {
            logContainer.append(`<div class="swsib-clean-log-entry ${entry.type}">${entry.message}</div>`);
        });
        
        // Scroll to bottom of log
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }

    /**
     * Helper: Get file extension from file name
     */
    function getFileExtension(fileName) {
        return fileName.split('.').pop().toLowerCase();
    }

    /**
     * Helper: Get file name from path
     */
    function getNameFromPath(path) {
        return path.split('/').pop();
    }

    /**
     * Helper: Get file type based on extension
     */
    function getFileType(extension) {
        const imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];
        const codeTypes = ['php', 'js', 'css', 'html', 'htm', 'xml', 'json', 'ini', 'conf', 'config'];
        const textTypes = ['txt', 'md', 'log', 'csv'];
        const archiveTypes = ['zip', 'tar', 'gz', 'rar', '7z', 'bz2'];
        const documentTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        
        if (imageTypes.includes(extension)) return 'Image';
        if (codeTypes.includes(extension)) return 'Code';
        if (textTypes.includes(extension)) return 'Text';
        if (archiveTypes.includes(extension)) return 'Archive';
        if (documentTypes.includes(extension)) return 'Document';
        if (extension === 'php') return 'PHP Script';
        if (extension === 'js') return 'JavaScript';
        if (extension === 'css') return 'CSS Stylesheet';
        if (extension === 'html' || extension === 'htm') return 'HTML Document';
        
        return 'File';
    }

    /**
     * Helper: Get appropriate dashicon class for file
     */
    function getFileIconClass(filename, extension) {
        extension = extension.toLowerCase();
        
        if (extension === 'php') return 'dashicons-media-code';
        else if (extension === 'js') return 'dashicons-media-code';
        else if (extension === 'css') return 'dashicons-media-code';
        else if (extension === 'html' || extension === 'htm') return 'dashicons-media-text';
        else if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'].includes(extension)) return 'dashicons-format-image';
        else if (extension === 'pdf') return 'dashicons-pdf';
        else if (['zip', 'tar', 'gz', 'rar', '7z', 'bz2'].includes(extension)) return 'dashicons-media-archive';
        else if (['txt', 'md', 'log', 'csv', 'xml', 'json'].includes(extension)) return 'dashicons-media-text';
        else if (filename === '.htaccess') return 'dashicons-privacy';
        else return 'dashicons-media-default'; // Default file icon
    }

    /**
     * Helper: Check if file can be previewed
     */
    function isPreviewable(extension) {
        return config.previewableExtensions.includes(extension);
    }

    /**
     * Helper: Check if file is editable
     */
    function isEditableFile(extension) {
        return config.editableExtensions.includes(extension);
    }

    /**
     * Helper: Check if file is an image
     */
    function isImageExtension(extension) {
        return config.imageExtensions.includes(extension);
    }

    /**
     * Helper: Encode HTML entities
     */
    function htmlEntities(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // A global variable to track progress
    var operationProgress = {
        totalSteps: 0,
        currentStep: 0,
        detailedLog: []
    };
});
</script>