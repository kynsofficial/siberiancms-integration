/**
 * SwiftSpeed Siberian - Clean Feature Scripts (Updated)
 */
(function($) {
    'use strict';
    
    // Track loaded tabs to prevent reloading unnecessarily
    var loadedTabs = {};
    
    // Track current sorting state
    var sortState = {
        users: {
            column: 'admin_id',
            direction: 'DESC'
        },
        applications: {
            column: 'app_id',
            direction: 'DESC'
        },
        mail_log: {
            column: 'log_id',
            direction: 'DESC'
        },
        sessions: {
            column: 'modified',
            direction: 'DESC'
        },
        source_queue: {
            column: 'source_queue_id',
            direction: 'DESC'
        },
        folder_cleanup: {
            column: 'name',
            direction: 'ASC'
        }
    };
    
    // Store tab-specific functions globally to use across scopes
    var tabFunctions = {
        users: {
            load: null
        },
        applications: {
            load: null
        },
        mail_log: {
            load: null
        },
        sessions: {
            load: null
        },
        source_queue: {
            load: null
        },
        folder_cleanup: {
            load: null
        }
    };
    
    // Progress tracking for long operations
    var operationProgress = {
        totalSteps: 0,
        currentStep: 0,
        detailedLog: []
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('SwiftSpeed Siberian Clean Scripts loaded');
        
        // Initialize tab navigation
        initTabs();
        
        // Initialize the first active tab - FIX: Check if active tab exists
        var activeTab = $('.swsib-clean-tabs a.active');
        if (activeTab.length && activeTab.attr('href')) {
            var activeTabId = activeTab.attr('href').substring(1);
            initTabContent(activeTabId);
        } else {
            // Default to the first tab if no active tab is found
            var firstTab = $('.swsib-clean-tabs a').first();
            if (firstTab.length && firstTab.attr('href')) {
                firstTab.addClass('active');
                var firstTabId = firstTab.attr('href').substring(1);
                $('#' + firstTabId).addClass('active');
                initTabContent(firstTabId);
            }
        }
        
        // Initialize shared components
        initSharedComponents();
        
        // Move progress sections to appear after the tabs
        repositionProgressSections();
    });
    
    /**
     * Reposition progress sections to come after tabs but before the content
     */
    function repositionProgressSections() {
        $('.swsib-clean-tab-pane').each(function() {
            var progress = $(this).find('.swsib-clean-progress');
            $(this).prepend(progress);
        });
    }
    
    /**
     * Initialize tab navigation
     */
    function initTabs() {
        $('.swsib-clean-tabs a').on('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all tabs and panes
            $('.swsib-clean-tabs a').removeClass('active');
            $('.swsib-clean-tab-pane').removeClass('active');
            
            // Add active class to clicked tab and its corresponding pane
            $(this).addClass('active');
            var tabId = $(this).attr('href').substring(1);
            $('#' + tabId).addClass('active');
            
            // Initialize the tab's content if not already loaded
            initTabContent(tabId);
        });
    }
    
    /**
     * Initialize tab content based on tab ID
     */
    function initTabContent(tabId) {
        if (!tabId) return; // Safety check
        
        switch(tabId) {
            case 'users-tab':
                if (!loadedTabs.users) {
                    initUsersTab();
                    loadedTabs.users = true;
                }
                break;
            case 'applications-tab':
                if (!loadedTabs.applications) {
                    initApplicationsTab();
                    loadedTabs.applications = true;
                }
                break;
            case 'mail-log-tab':
                if (!loadedTabs.mail_log) {
                    initMailLogTab();
                    loadedTabs.mail_log = true;
                }
                break;
            case 'sessions-tab':
                if (!loadedTabs.sessions) {
                    initSessionsTab();
                    loadedTabs.sessions = true;
                }
                break;
            case 'source-queue-tab':
                if (!loadedTabs.source_queue) {
                    initSourceQueueTab();
                    loadedTabs.source_queue = true;
                }
                break;
            case 'folder-cleanup-tab':
                if (!loadedTabs.folder_cleanup) {
                    initFolderCleanupTab();
                    loadedTabs.folder_cleanup = true;
                }
                break;
        }
    }
    
    /**
     * Initialize shared components
     */
    function initSharedComponents() {
        // Initialize modals
        $(document).on('click', '.swsib-clean-modal-close, .swsib-clean-modal-cancel', function() {
            closeModal();
        });
        
        // Close modal when clicking on the overlay
        $(document).on('click', '.swsib-clean-modal-overlay', function(e) {
            if ($(e.target).hasClass('swsib-clean-modal-overlay')) {
                closeModal();
            }
        });
        
        // Press Escape to close modal
        $(document).keydown(function(e) {
            if (e.keyCode === 27 && $('.swsib-clean-modal-overlay').hasClass('active')) {
                closeModal();
            }
        });
        
        // Initialize column sorting for all tables
        $(document).on('click', '.swsib-clean-table th.sortable', function() {
            var tabId = $(this).closest('.swsib-clean-tab-pane').attr('id');
            if (!tabId) return; // Safety check
            
            var tabType = tabId.replace('-tab', '');
            var column = $(this).data('column');
            if (!column) return; // Safety check
            
            // Normalize tab type to match sortState keys
            var normalizedTabType = tabType;
            if (tabType === 'mail-log') normalizedTabType = 'mail_log';
            if (tabType === 'source-queue') normalizedTabType = 'source_queue';
            if (tabType === 'folder-cleanup') normalizedTabType = 'folder_cleanup';
            
            console.log('Sort clicked on column:', column, 'in tab:', tabType, 'normalized:', normalizedTabType);
            
            // Ensure the tab type exists in sortState
            if (!sortState[normalizedTabType]) {
                sortState[normalizedTabType] = {
                    column: 'name',
                    direction: 'ASC'
                };
            }
            
            // Toggle sort direction if same column, otherwise default to ASC
            if (sortState[normalizedTabType].column === column) {
                sortState[normalizedTabType].direction = sortState[normalizedTabType].direction === 'ASC' ? 'DESC' : 'ASC';
            } else {
                sortState[normalizedTabType].column = column;
                sortState[normalizedTabType].direction = 'ASC';
            }
            
            console.log('New sort state:', sortState[normalizedTabType]);
            
            // Reload data with new sort
            reloadTabData(tabType);
            
            // Update sort indicators
            updateSortIndicators(tabType);
        });
    }
    
    /**
     * Update sort indicators in table headers
     */
    function updateSortIndicators(tabType) {
        if (!tabType) return; // Safety check
        
        console.log('Updating sort indicators for', tabType);
        
        // Normalize tab type to match sortState keys
        var normalizedTabType = tabType;
        if (tabType === 'mail-log') normalizedTabType = 'mail_log';
        if (tabType === 'source-queue') normalizedTabType = 'source_queue';
        if (tabType === 'folder-cleanup') normalizedTabType = 'folder_cleanup';
        
        var table = $('#' + tabType + '-tab').find('.swsib-clean-table');
        if (!table.length) return; // Safety check
        
        table.find('th').removeClass('sort-asc sort-desc');
        
        if (sortState[normalizedTabType] && sortState[normalizedTabType].column) {
            var th = table.find('th[data-column="' + sortState[normalizedTabType].column + '"]');
            if (th.length) {
                th.addClass(sortState[normalizedTabType].direction === 'ASC' ? 'sort-asc' : 'sort-desc');
            }
        }
    }
    
    /**
     * Reload tab data based on tab type
     */
    function reloadTabData(tabType) {
        if (!tabType) return; // Safety check
        
        console.log('Reloading data for tab:', tabType);
        
        // Normalize tab type to match tabFunctions keys
        var normalizedTabType = tabType;
        if (tabType === 'mail-log') normalizedTabType = 'mail_log';
        if (tabType === 'source-queue') normalizedTabType = 'source_queue';
        if (tabType === 'folder-cleanup') normalizedTabType = 'folder_cleanup';
        
        if (tabFunctions[normalizedTabType] && typeof tabFunctions[normalizedTabType].load === 'function') {
            tabFunctions[normalizedTabType].load(1);
        } else {
            console.warn('Load function not available for', normalizedTabType);
            
            // Try to initialize the tab if not loaded
            switch(normalizedTabType) {
                case 'users':
                    initUsersTab();
                    break;
                case 'applications':
                    initApplicationsTab();
                    break;
                case 'mail_log':
                    initMailLogTab();
                    break;
                case 'sessions':
                    initSessionsTab();
                    break;
                case 'source_queue':
                    initSourceQueueTab();
                    break;
                case 'folder_cleanup':
                    initFolderCleanupTab();
                    break;
            }
        }
    }
    
    /**
     * Show a modal with the given title, message and callbacks
     */
    function showModal(title, message, confirmCallback, cancelCallback) {
        // Create modal if it doesn't exist
        if ($('.swsib-clean-modal-overlay').length === 0) {
            var modalHTML = '<div class="swsib-clean-modal-overlay">' +
                '   <div class="swsib-clean-modal">' +
                '       <div class="swsib-clean-modal-header">' +
                '           <h3></h3>' +
                '           <button type="button" class="swsib-clean-modal-close">&times;</button>' +
                '       </div>' +
                '       <div class="swsib-clean-modal-body"></div>' +
                '       <div class="swsib-clean-modal-footer">' +
                '           <button type="button" class="swsib-clean-button swsib-clean-modal-cancel">Cancel</button>' +
                '           <button type="button" class="swsib-clean-button primary swsib-clean-modal-confirm">Confirm</button>' +
                '       </div>' +
                '   </div>' +
                '</div>';
            
            $('body').append(modalHTML);
        }
        
        // Set modal content
        $('.swsib-clean-modal-header h3').text(title);
        $('.swsib-clean-modal-body').html(message);
        
        // Set confirm button callback
        $('.swsib-clean-modal-confirm').off('click').on('click', function() {
            if (typeof confirmCallback === 'function') {
                confirmCallback();
            }
            closeModal();
        });
        
        // Set cancel button callback
        if (typeof cancelCallback === 'function') {
            $('.swsib-clean-modal-cancel').off('click').on('click', function() {
                cancelCallback();
                closeModal();
            });
        }
        
        // Show modal
        $('.swsib-clean-modal-overlay').addClass('active');
    }
    
    /**
     * Close the modal
     */
    function closeModal() {
        $('.swsib-clean-modal-overlay').removeClass('active');
    }
    
    /**
     * Show a message
     */
    function showMessage(container, message, type, autoHide) {
        var messageHTML = '<div class="swsib-clean-message ' + type + '">' + message + '</div>';
        
        // Clear existing messages
        $(container).find('.swsib-clean-message').remove();
        
        // Add new message
        $(container).prepend(messageHTML);
        
        // Auto-hide message if specified
        if (autoHide) {
            setTimeout(function() {
                $(container).find('.swsib-clean-message').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
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
        var progressBar = container.find('.swsib-clean-progress-bar-fill');
        var progressText = container.find('.swsib-clean-progress-text');
        
        // Calculate percentage
        var percentage = 0;
        if (operationProgress.totalSteps > 0) {
            percentage = Math.round((operationProgress.currentStep / operationProgress.totalSteps) * 100);
        }
        
        // Update progress bar
        progressBar.css('width', percentage + '%');
        
        // Update progress text
        progressText.text(percentage + '% Complete - ' + operationProgress.currentStep + ' of ' + operationProgress.totalSteps);
        
        // Update detailed log
        if (logContainer.length) {
            // Keep the latest 10 log entries
            var logHTML = '';
            var startIndex = Math.max(0, operationProgress.detailedLog.length - 10);
            
            for (var i = startIndex; i < operationProgress.detailedLog.length; i++) {
                var entry = operationProgress.detailedLog[i];
                logHTML += '<div class="swsib-clean-log-entry ' + entry.type + '">' + entry.message + '</div>';
            }
            
            logContainer.html(logHTML);
            
            // Scroll to bottom of log
            logContainer.scrollTop(logContainer[0].scrollHeight);
        }
    }
    
    /**
     * Format date for display
     */
    function formatDate(dateString) {
        if (!dateString) return '-';
        
        var date = new Date(dateString);
        
        // Check if date is valid
        if (isNaN(date.getTime())) return dateString;
        
        // Format date into a more readable format
        var options = { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        };
        
        return date.toLocaleDateString(undefined, options);
    }
    
    /**
     * Format file size for display
     */
    function formatFileSize(bytes) {
        // Check for null, undefined, empty string or NaN
        if (bytes === null || bytes === undefined || bytes === '' || isNaN(parseInt(bytes))) {
            return '0 B';
        }
        
        bytes = parseInt(bytes, 10);
        
        // If bytes is 0 after parsing, return "0 B"
        if (bytes === 0) return '0 B';
        
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    /**
     * Truncate text for display
     */
    function truncateText(text, maxLength) {
        if (!text) return '-';
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }
    
    /**
     * Show loading spinner
     */
    function showLoadingSpinner(container) {
        container.html('<tr><td colspan="10" class="text-center loading-indicator"><div class="swsib-clean-spinner"></div> Loading data...</td></tr>');
    }
    
    /**
     * Initialize the Users tab
     */
    function initUsersTab() {
        var usersTab = $('#users-tab');
        var usersPagination = usersTab.find('.swsib-clean-pagination');
        var currentPage = 1;
        var totalPages = 1;
        
        // Store load function in the global scope
        tabFunctions.users.load = loadUsers;
        
        // Load users on tab initialization
        loadUsers(currentPage);
        
        // Search button click
        usersTab.find('.swsib-clean-search-button').on('click', function() {
            currentPage = 1;
            loadUsers(currentPage);
        });
        
        // Search input enter key
        usersTab.find('.swsib-clean-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadUsers(currentPage);
            }
        });
        
        // Refresh button
        usersTab.find('.refresh-data-button').on('click', function() {
            loadUsers(currentPage);
        });
        
        // Pagination
        usersTab.on('click', '.swsib-clean-pagination-controls button', function() {
            var page = $(this).data('page');
            
            if (page === 'prev') {
                page = Math.max(1, currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(totalPages, currentPage + 1);
            }
            
            if (page !== currentPage) {
                currentPage = page;
                loadUsers(currentPage);
            }
        });
        
        // Select all checkbox
        usersTab.on('change', '#select-all-users', function() {
            var isChecked = $(this).prop('checked');
            usersTab.find('.user-checkbox').prop('checked', isChecked);
            updateSelectionInfo();
        });
        
        // Bulk actions
        usersTab.find('#bulk-delete-users').on('click', function() {
            var selectedUsers = getSelectedUsers();
            
            if (selectedUsers.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Delete Selected Users',
                swsib_clean.confirm_delete_admins,
                function() {
                    deleteUsers(selectedUsers);
                }
            );
        });
        
        usersTab.find('#bulk-deactivate-users').on('click', function() {
            var selectedUsers = getSelectedUsers();
            
            if (selectedUsers.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Deactivate Selected Users',
                swsib_clean.confirm_deactivate_admins,
                function() {
                    deactivateUsers(selectedUsers);
                }
            );
        });
        
        // Add new Activate button handler
        usersTab.find('#bulk-activate-users').on('click', function() {
            var selectedUsers = getSelectedUsers();
            
            if (selectedUsers.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Activate Selected Users',
                'Are you sure you want to activate these users?',
                function() {
                    activateUsers(selectedUsers);
                }
            );
        });
        
        /**
         * Load users
         */
        function loadUsers(page) {
            var searchTerm = usersTab.find('.swsib-clean-search-input').val();
            
            // Show loading
            var userTable = usersTab.find('.swsib-clean-table tbody');
            showLoadingSpinner(userTable);
            usersPagination.addClass('swsib-hidden');
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_admins',
                    nonce: swsib_clean.nonce,
                    page: page,
                    per_page: 10,
                    search: searchTerm,
                    sort_column: sortState.users.column,
                    sort_direction: sortState.users.direction
                },
                success: function(response) {
                    if (response.success) {
                        displayUsers(response.data);
                        updateSortIndicators('users');
                    } else {
                        usersTab.find('.swsib-clean-table tbody').html('<tr><td colspan="8" class="text-center error-message">Error: ' + response.data.message + '</td></tr>');
                    }
                },
                error: function() {
                    usersTab.find('.swsib-clean-table tbody').html('<tr><td colspan="8" class="text-center error-message">An error occurred while loading users.</td></tr>');
                }
            });
        }
        
        /**
         * Display users
         */
        function displayUsers(data) {
            var users = data.admins;
            var tbody = usersTab.find('.swsib-clean-table tbody');
            
            // Update pagination vars
            currentPage = data.current_page;
            totalPages = data.pages;
            
            // Clear table
            tbody.empty();
            
            if (users.length === 0) {
                tbody.html('<tr><td colspan="8" class="text-center">No users found.</td></tr>');
                usersPagination.addClass('swsib-hidden');
                return;
            }
            
            // Add users to table
            $.each(users, function(i, user) {
                var rowClass = user.is_inactive ? 'inactive-user' : '';
                var statusClass = user.is_active == 1 ? 'success' : 'danger';
                var statusLabel = user.is_active == 1 ? 'Active' : 'Inactive';
                
                var row = '<tr class="' + rowClass + '">' +
                          '    <td class="checkbox-cell"><input type="checkbox" class="user-checkbox" value="' + user.admin_id + '"></td>' +
                          '    <td>' + user.admin_id + '</td>' +
                          '    <td>' + user.role_id + '</td>' +
                          '    <td>' + user.email + '</td>' +
                          '    <td>' + (user.firstname || '-') + ' ' + (user.lastname || '') + '</td>' +
                          '    <td>' + formatDate(user.last_action) + '</td>' +
                          '    <td><span class="swsib-clean-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
                          '    <td>' + formatDate(user.created_at) + '</td>' +
                          '</tr>';
                
                tbody.append(row);
            });
            
            // Update pagination
            updatePagination(usersPagination, currentPage, totalPages, data.total);
        }
        
        /**
         * Get selected users
         */
        function getSelectedUsers() {
            var selectedUsers = [];
            
            usersTab.find('.user-checkbox:checked').each(function() {
                selectedUsers.push($(this).val());
            });
            
            return selectedUsers;
        }
        
        /**
         * Delete users
         */
        function deleteUsers(userIds) {
            // Disable buttons
            usersTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = userIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + userIds.length + ' user(s)...' });
            
            // Show progress
            var progressContainer = usersTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = usersTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            // Setup detailed progress monitoring
            var progressMonitorInterval = setInterval(function() {
                $.ajax({
                    url: swsib_clean.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'swsib_get_deletion_progress',
                        nonce: swsib_clean.nonce,
                        operation_type: 'admin_deletion'
                    },
                    success: function(response) {
                        if (response.success && response.data.logs) {
                            // Update our progress with server-side logs
                            $.each(response.data.logs, function(i, logEntry) {
                                // Only add if we don't have it already
                                var hasLog = false;
                                for (var j = 0; j < operationProgress.detailedLog.length; j++) {
                                    if (operationProgress.detailedLog[j].message === logEntry.message) {
                                        hasLog = true;
                                        break;
                                    }
                                }
                                
                                if (!hasLog) {
                                    operationProgress.detailedLog.push(logEntry);
                                    // If it's a step completion log, increment step
                                    if (logEntry.message.indexOf('Completed:') === 0) {
                                        operationProgress.currentStep++;
                                    }
                                }
                            });
                            
                            // Update progress display
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                });
            }, 1000);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_admins',
                    nonce: swsib_clean.nonce,
                    admin_ids: userIds
                },
                success: function(response) {
                    clearInterval(progressMonitorInterval);
                    
                    if (response.success) {
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        // Mark operation as complete
                        operationProgress.currentStep = operationProgress.totalSteps;
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, response.data.message, 'success', true);
                        
                        // Reload users
                        loadUsers(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    clearInterval(progressMonitorInterval);
                    
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deletion process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(usersTab, 'An error occurred during the deletion process.', 'error', false);
                },
                complete: function() {
                    clearInterval(progressMonitorInterval);
                    
                    // Re-enable buttons
                    usersTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    usersTab.find('#select-all-users').prop('checked', false);
                }
            });
        }
        
        /**
         * Deactivate users
         */
        function deactivateUsers(userIds) {
            // Disable buttons
            usersTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = userIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deactivation of ' + userIds.length + ' user(s)...' });
            
            // Show progress
            var progressContainer = usersTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = usersTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_deactivate_admins',
                    nonce: swsib_clean.nonce,
                    admin_ids: userIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, response.data.message, 'success', true);
                        
                        // Reload users
                        loadUsers(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deactivation process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(usersTab, 'An error occurred during the deactivation process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    usersTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    usersTab.find('#select-all-users').prop('checked', false);
                }
            });
        }
        
        /**
         * Activate users
         */
        function activateUsers(userIds) {
            // Disable buttons
            usersTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = userIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting activation of ' + userIds.length + ' user(s)...' });
            
            // Show progress
            var progressContainer = usersTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = usersTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_activate_admins',
                    nonce: swsib_clean.nonce,
                    admin_ids: userIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, response.data.message, 'success', true);
                        
                        // Reload users
                        loadUsers(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(usersTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the activation process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(usersTab, 'An error occurred during the activation process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    usersTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    usersTab.find('#select-all-users').prop('checked', false);
                }
            });
        }
    }
    
    /**
     * Initialize the Applications tab
     */
    function initApplicationsTab() {
        var appsTab = $('#applications-tab');
        var appsPagination = appsTab.find('.swsib-clean-pagination');
        var currentPage = 1;
        var totalPages = 1;
        
        // Store load function in the global scope
        tabFunctions.applications.load = loadApplications;
        
        // Load applications on tab initialization
        loadApplications(currentPage);
        
        // Search button click
        appsTab.find('.swsib-clean-search-button').on('click', function() {
            currentPage = 1;
            loadApplications(currentPage);
        });
        
        // Search input enter key
        appsTab.find('.swsib-clean-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadApplications(currentPage);
            }
        });
        
        // Refresh button
        appsTab.find('.refresh-data-button').on('click', function() {
            loadApplications(currentPage);
        });
        
        // Pagination
        appsTab.on('click', '.swsib-clean-pagination-controls button', function() {
            var page = $(this).data('page');
            
            if (page === 'prev') {
                page = Math.max(1, currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(totalPages, currentPage + 1);
            }
            
            if (page !== currentPage) {
                currentPage = page;
                loadApplications(currentPage);
            }
        });
        
        // Select all checkbox
        appsTab.on('change', '#select-all-apps', function() {
            var isChecked = $(this).prop('checked');
            appsTab.find('.app-checkbox').prop('checked', isChecked);
        });
        
        // Bulk actions
        appsTab.find('#bulk-delete-apps').on('click', function() {
            var selectedApps = getSelectedApps();
            
            if (selectedApps.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Delete Selected Applications',
                swsib_clean.confirm_delete_apps,
                function() {
                    deleteApplications(selectedApps);
                }
            );
        });
        
        appsTab.find('#bulk-lock-apps').on('click', function() {
            var selectedApps = getSelectedApps();
            
            if (selectedApps.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Lock Selected Applications',
                swsib_clean.confirm_lock_apps,
                function() {
                    lockApplications(selectedApps);
                }
            );
        });
        
        appsTab.find('#bulk-unlock-apps').on('click', function() {
            var selectedApps = getSelectedApps();
            
            if (selectedApps.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Unlock Selected Applications',
                swsib_clean.confirm_unlock_apps,
                function() {
                    unlockApplications(selectedApps);
                }
            );
        });
        
        /**
         * Load applications
         */
        function loadApplications(page) {
            var searchTerm = appsTab.find('.swsib-clean-search-input').val();
            
            // Show loading
            var appsTable = appsTab.find('.swsib-clean-table tbody');
            showLoadingSpinner(appsTable);
            appsPagination.addClass('swsib-hidden');
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_applications',
                    nonce: swsib_clean.nonce,
                    page: page,
                    per_page: 10,
                    search: searchTerm,
                    sort_column: sortState.applications.column,
                    sort_direction: sortState.applications.direction
                },
                success: function(response) {
                    if (response.success) {
                        displayApplications(response.data);
                        updateSortIndicators('applications');
                    } else {
                        appsTab.find('.swsib-clean-table tbody').html('<tr><td colspan="9" class="text-center error-message">Error: ' + response.data.message + '</td></tr>');
                    }
                },
                error: function() {
                    appsTab.find('.swsib-clean-table tbody').html('<tr><td colspan="9" class="text-center error-message">An error occurred while loading applications.</td></tr>');
                }
            });
        }
        
        /**
         * Display applications
         */
        function displayApplications(data) {
            var apps = data.applications;
            var tbody = appsTab.find('.swsib-clean-table tbody');
            
            // Update pagination vars
            currentPage = data.current_page;
            totalPages = data.pages;
            
            // Clear table
            tbody.empty();
            
            if (apps.length === 0) {
                tbody.html('<tr><td colspan="9" class="text-center">No applications found.</td></tr>');
                appsPagination.addClass('swsib-hidden');
                return;
            }
            
            // Add applications to table
            $.each(apps, function(i, app) {
                var rowClass = app.is_locked == 1 ? 'locked-app' : '';
                var statusClass = app.is_locked == 1 ? 'danger' : 'success';
                var statusLabel = app.is_locked == 1 ? 'Locked' : 'Unlocked';
                
                // Format size on disk - ensure it's a proper number
                var sizeOnDisk = formatFileSize(app.size_on_disk);
                
                var row = '<tr class="' + rowClass + '">' +
                          '    <td class="checkbox-cell"><input type="checkbox" class="app-checkbox" value="' + app.app_id + '"></td>' +
                          '    <td>' + app.app_id + '</td>' +
                          '    <td>' + app.name + '</td>' +
                          '    <td>' + app.admin_id + '</td>' +
                          '    <td>' + (app.admin_email || '-') + '</td>' +
                          '    <td><span class="swsib-clean-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
                          '    <td>' + formatDate(app.created_at) + '</td>' +
                          '    <td>' + formatDate(app.updated_at) + '</td>' +
                          '    <td>' + sizeOnDisk + '</td>' +
                          '</tr>';
                
                tbody.append(row);
            });
            
            // Update pagination
            updatePagination(appsPagination, currentPage, totalPages, data.total);
        }
        
        /**
         * Get selected applications
         */
        function getSelectedApps() {
            var selectedApps = [];
            
            appsTab.find('.app-checkbox:checked').each(function() {
                selectedApps.push($(this).val());
            });
            
            return selectedApps;
        }
        
        /**
         * Delete applications
         */
        function deleteApplications(appIds) {
            // Disable buttons
            appsTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = appIds.length * 2; // Each app requires querying tables + deletion
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + appIds.length + ' application(s)...' });
            
            // Show progress
            var progressContainer = appsTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = appsTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            // Setup detailed progress monitoring
            var progressMonitorInterval = setInterval(function() {
                $.ajax({
                    url: swsib_clean.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'swsib_get_deletion_progress',
                        nonce: swsib_clean.nonce,
                        operation_type: 'application_deletion'
                    },
                    success: function(response) {
                        if (response.success && response.data.logs) {
                            // Update our progress with server-side logs
                            $.each(response.data.logs, function(i, logEntry) {
                                // Only add if we don't have it already
                                var hasLog = false;
                                for (var j = 0; j < operationProgress.detailedLog.length; j++) {
                                    if (operationProgress.detailedLog[j].message === logEntry.message) {
                                        hasLog = true;
                                        break;
                                    }
                                }
                                
                                if (!hasLog) {
                                    operationProgress.detailedLog.push(logEntry);
                                    
                                    // If it's a step completion log, increment step
                                    if (logEntry.message.indexOf('Completed:') === 0 ||
                                        logEntry.message.indexOf('Successfully deleted from') === 0) {
                                        operationProgress.currentStep++;
                                    }
                                }
                            });
                            
                            // Update progress display
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                });
            }, 1000);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_applications',
                    nonce: swsib_clean.nonce,
                    app_ids: appIds
                },
                success: function(response) {
                    clearInterval(progressMonitorInterval);
                    
                    if (response.success) {
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        
                        // Mark operation as complete
                        operationProgress.currentStep = operationProgress.totalSteps;
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, response.data.message, 'success', true);
                        
                        // Reload applications
                        loadApplications(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    clearInterval(progressMonitorInterval);
                    
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deletion process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(appsTab, 'An error occurred during the deletion process.', 'error', false);
                },
                complete: function() {
                    clearInterval(progressMonitorInterval);
                    
                    // Re-enable buttons
                    appsTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    appsTab.find('#select-all-apps').prop('checked', false);
                }
            });
        }
        
        /**
         * Lock applications
         */
        function lockApplications(appIds) {
            // Disable buttons
            appsTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = appIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Locking ' + appIds.length + ' application(s)...' });
            
            // Show progress
            var progressContainer = appsTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = appsTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_lock_applications',
                    nonce: swsib_clean.nonce,
                    app_ids: appIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, response.data.message, 'success', true);
                        
                        // Reload applications
                        loadApplications(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the locking process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(appsTab, 'An error occurred during the locking process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    appsTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    appsTab.find('#select-all-apps').prop('checked', false);
                }
            });
        }
        
        /**
         * Unlock applications
         */
        function unlockApplications(appIds) {
            // Disable buttons
            appsTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = appIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Unlocking ' + appIds.length + ' application(s)...' });
            
            // Show progress
            var progressContainer = appsTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = appsTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_unlock_applications',
                    nonce: swsib_clean.nonce,
                    app_ids: appIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, response.data.message, 'success', true);
                        
                        // Reload applications
                        loadApplications(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(appsTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the unlocking process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(appsTab, 'An error occurred during the unlocking process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    appsTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    appsTab.find('#select-all-apps').prop('checked', false);
                }
            });
        }
    }
    
    /**
     * Initialize the Mail Log tab
     */
    function initMailLogTab() {
        var mailLogTab = $('#mail-log-tab');
        var mailLogPagination = mailLogTab.find('.swsib-clean-pagination');
        var currentPage = 1;
        var totalPages = 1;
        
        // Store load function in the global scope
        tabFunctions.mail_log.load = loadMailLogs;
        
        // Load mail logs on tab initialization
        loadMailLogs(currentPage);
        
        // Search button click
        mailLogTab.find('.swsib-clean-search-button').on('click', function() {
            currentPage = 1;
            loadMailLogs(currentPage);
        });
        
        // Search input enter key
        mailLogTab.find('.swsib-clean-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadMailLogs(currentPage);
            }
        });
        
        // Refresh button
        mailLogTab.find('.refresh-data-button').on('click', function() {
            loadMailLogs(currentPage);
        });
        
        // Pagination
        mailLogTab.on('click', '.swsib-clean-pagination-controls button', function() {
            var page = $(this).data('page');
            
            if (page === 'prev') {
                page = Math.max(1, currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(totalPages, currentPage + 1);
            }
            
            if (page !== currentPage) {
                currentPage = page;
                loadMailLogs(currentPage);
            }
        });
        
        // Select all checkbox
        mailLogTab.on('change', '#select-all-mail-logs', function() {
            var isChecked = $(this).prop('checked');
            mailLogTab.find('.mail-log-checkbox').prop('checked', isChecked);
        });
        
        // Bulk delete
        mailLogTab.find('#bulk-delete-mail-logs').on('click', function() {
            var selectedMailLogs = getSelectedMailLogs();
            
            if (selectedMailLogs.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Delete Selected Mail Logs',
                swsib_clean.confirm_delete_mail_logs,
                function() {
                    deleteMailLogs(selectedMailLogs);
                }
            );
        });
        
        // Clear all
        mailLogTab.find('#clear-all-mail-logs').on('click', function() {
            showModal(
                'Clear All Mail Logs',
                swsib_clean.confirm_clear_all_mail_logs,
                function() {
                    clearAllMailLogs();
                }
            );
        });
        
        /**
         * Load mail logs
         */
        function loadMailLogs(page) {
            var searchTerm = mailLogTab.find('.swsib-clean-search-input').val();
            
            // Show loading
            var mailLogTable = mailLogTab.find('.swsib-clean-table tbody');
            showLoadingSpinner(mailLogTable);
            mailLogPagination.addClass('swsib-hidden');
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_mail_logs',
                    nonce: swsib_clean.nonce,
                    page: page,
                    per_page: 10,
                    search: searchTerm,
                    sort_column: sortState.mail_log.column,
                    sort_direction: sortState.mail_log.direction
                },
                success: function(response) {
                    if (response.success) {
                        displayMailLogs(response.data);
                        updateSortIndicators('mail_log');
                    } else {
                        mailLogTab.find('.swsib-clean-table tbody').html('<tr><td colspan="6" class="text-center error-message">Error: ' + response.data.message + '</td></tr>');
                    }
                },
                error: function() {
                    mailLogTab.find('.swsib-clean-table tbody').html('<tr><td colspan="6" class="text-center error-message">An error occurred while loading mail logs.</td></tr>');
                }
            });
        }
        
        /**
         * Display mail logs
         */
        function displayMailLogs(data) {
            var mailLogs = data.mail_logs;
            var tbody = mailLogTab.find('.swsib-clean-table tbody');
            
            // Update pagination vars
            currentPage = data.current_page;
            totalPages = data.pages;
            
            // Clear table
            tbody.empty();
            
            if (mailLogs.length === 0) {
                tbody.html('<tr><td colspan="6" class="text-center">No mail logs found.</td></tr>');
                mailLogPagination.addClass('swsib-hidden');
                return;
            }
            
            // Add mail logs to table
            $.each(mailLogs, function(i, log) {
                // Handle potentially missing or empty fields
                var title = log.title ? '<span class="swsib-clean-text-truncate" title="' + log.title + '">' + log.title + '</span>' : '-';
                var from = log.from ? '<span class="swsib-clean-text-truncate" title="' + log.from + '">' + log.from + '</span>' : '-';
                var recipients = log.recipients ? '<span class="swsib-clean-text-truncate" title="' + log.recipients + '">' + log.recipients + '</span>' : '-';
                
                // Determine status based on text_error field
                var statusClass = (log.text_error === null || log.text_error === '') ? 'success' : 'danger';
                var statusLabel = (log.text_error === null || log.text_error === '') ? 'Success' : 'Failed';
                
                var row = '<tr>' +
                          '    <td class="checkbox-cell"><input type="checkbox" class="mail-log-checkbox" value="' + log.log_id + '"></td>' +
                          '    <td>' + log.log_id + '</td>' +
                          '    <td>' + title + '</td>' +
                          '    <td>' + from + '</td>' +
                          '    <td>' + recipients + '</td>' +
                          '    <td><span class="swsib-clean-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
                          '</tr>';
                
                tbody.append(row);
            });
            
            // Update pagination
            updatePagination(mailLogPagination, currentPage, totalPages, data.total);
        }
        
        /**
         * Get selected mail logs
         */
        function getSelectedMailLogs() {
            var selectedMailLogs = [];
            
            mailLogTab.find('.mail-log-checkbox:checked').each(function() {
                selectedMailLogs.push($(this).val());
            });
            
            return selectedMailLogs;
        }
        
        /**
         * Delete mail logs
         */
        function deleteMailLogs(logIds) {
            // Disable buttons
            mailLogTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = logIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + logIds.length + ' mail log(s)...' });
            
            // Show progress
            var progressContainer = mailLogTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = mailLogTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_mail_logs',
                    nonce: swsib_clean.nonce,
                    log_ids: logIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(mailLogTab, response.data.message, 'success', true);
                        
                        // Reload mail logs
                        loadMailLogs(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(mailLogTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deletion process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(mailLogTab, 'An error occurred during the deletion process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    mailLogTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    mailLogTab.find('#select-all-mail-logs').prop('checked', false);
                }
            });
        }
        
        /**
         * Clear all mail logs
         */
        function clearAllMailLogs() {
            // Disable buttons
            mailLogTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = 1;
            operationProgress.detailedLog.push({ type: 'info', message: 'Clearing all mail logs...' });
            
            // Show progress
            var progressContainer = mailLogTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = mailLogTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_clear_all_mail_logs',
                    nonce: swsib_clean.nonce
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(mailLogTab, response.data.message, 'success', true);
                        
                        // Reload mail logs
                        loadMailLogs(1);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(mailLogTab, 'Error: ' + response.data.message, 'error', false);
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the clearing process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(mailLogTab, 'An error occurred during the clearing process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    mailLogTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    mailLogTab.find('#select-all-mail-logs').prop('checked', false);
                }
            });
        }
    }
    
    /**
     * Initialize the Sessions tab
     */
    function initSessionsTab() {
        var sessionsTab = $('#sessions-tab');
        var sessionsPagination = sessionsTab.find('.swsib-clean-pagination');
        var currentPage = 1;
        var totalPages = 1;
        
        // Store load function in the global scope
        tabFunctions.sessions.load = loadSessions;
        
        // Load sessions on tab initialization
        loadSessions(currentPage);
        
        // Search button click
        sessionsTab.find('.swsib-clean-search-button').on('click', function() {
            currentPage = 1;
            loadSessions(currentPage);
        });
        
        // Search input enter key
        sessionsTab.find('.swsib-clean-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadSessions(currentPage);
            }
        });
        
        // Refresh button
        sessionsTab.find('.refresh-data-button').on('click', function() {
            loadSessions(currentPage);
        });
        
        // Pagination
        sessionsTab.on('click', '.swsib-clean-pagination-controls button', function() {
            var page = $(this).data('page');
            
            if (page === 'prev') {
                page = Math.max(1, currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(totalPages, currentPage + 1);
            }
            
            if (page !== currentPage) {
                currentPage = page;
                loadSessions(currentPage);
            }
        });
        
        // Select all checkbox
        sessionsTab.on('change', '#select-all-sessions', function() {
            var isChecked = $(this).prop('checked');
            sessionsTab.find('.session-checkbox').prop('checked', isChecked);
        });
        
        // Bulk delete
        sessionsTab.find('#bulk-delete-sessions').on('click', function() {
            var selectedSessions = getSelectedSessions();
            
            if (selectedSessions.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Delete Selected Sessions',
                swsib_clean.confirm_delete_sessions,
                function() {
                    deleteSessions(selectedSessions);
                }
            );
        });
        
        // Clear all
        sessionsTab.find('#clear-all-sessions').on('click', function() {
            showModal(
                'Clear All Sessions',
                swsib_clean.confirm_clear_all_sessions,
                function() {
                    clearAllSessions();
                }
            );
        });
        
        /**
         * Load sessions
         */
        function loadSessions(page) {
            var searchTerm = sessionsTab.find('.swsib-clean-search-input').val();
            
            // Show loading
            var sessionsTable = sessionsTab.find('.swsib-clean-table tbody');
            showLoadingSpinner(sessionsTable);
            sessionsPagination.addClass('swsib-hidden');
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_sessions',
                    nonce: swsib_clean.nonce,
                    page: page,
                    per_page: 10,
                    search: searchTerm,
                    sort_column: sortState.sessions.column,
                    sort_direction: sortState.sessions.direction
                },
                success: function(response) {
                    if (response.success) {
                        displaySessions(response.data);
                        updateSortIndicators('sessions');
                    } else {
                        sessionsTab.find('.swsib-clean-table tbody').html('<tr><td colspan="3" class="text-center error-message">Error: ' + response.data.message + '</td></tr>');
                    }
                },
                error: function() {
                    sessionsTab.find('.swsib-clean-table tbody').html('<tr><td colspan="3" class="text-center error-message">An error occurred while loading sessions.</td></tr>');
                }
            });
        }
        
        /**
         * Display sessions
         */
        function displaySessions(data) {
            var sessions = data.sessions;
            var tbody = sessionsTab.find('.swsib-clean-table tbody');
            
            // Update pagination vars
            currentPage = data.current_page;
            totalPages = data.pages;
            
            // Clear table
            tbody.empty();
            
            if (sessions.length === 0) {
                tbody.html('<tr><td colspan="3" class="text-center">No sessions found.</td></tr>');
                sessionsPagination.addClass('swsib-hidden');
                return;
            }
            
            // Add sessions to table
            $.each(sessions, function(i, session) {
                // Convert modified timestamp to date
                var modifiedDate = formatDate(new Date(parseInt(session.modified) * 1000));
                
                var row = '<tr>' +
                          '    <td class="checkbox-cell"><input type="checkbox" class="session-checkbox" value="' + session.session_id + '"></td>' +
                          '    <td>' + session.session_id + '</td>' +
                          '    <td>' + modifiedDate + '</td>' +
                          '</tr>';
                
                tbody.append(row);
            });
            
            // Update pagination
            updatePagination(sessionsPagination, currentPage, totalPages, data.total);
        }
        
        /**
         * Get selected sessions
         */
        function getSelectedSessions() {
            var selectedSessions = [];
            
            sessionsTab.find('.session-checkbox:checked').each(function() {
                selectedSessions.push($(this).val());
            });
            
            return selectedSessions;
        }
        
        /**
         * Delete sessions
         */
        function deleteSessions(sessionIds) {
            // Disable buttons
            sessionsTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = sessionIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + sessionIds.length + ' session(s)...' });
            
            // Show progress
            var progressContainer = sessionsTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = sessionsTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_sessions',
                    nonce: swsib_clean.nonce,
                    session_ids: sessionIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sessionsTab, response.data.message, 'success', true);
                        
                        // Reload sessions
                        loadSessions(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sessionsTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deletion process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(sessionsTab, 'An error occurred during the deletion process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    sessionsTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    sessionsTab.find('#select-all-sessions').prop('checked', false);
                }
            });
        }
        
        /**
         * Clear all sessions
         */
        function clearAllSessions() {
            // Disable buttons
            sessionsTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = 1;
            operationProgress.detailedLog.push({ type: 'info', message: 'Clearing all sessions...' });
            
            // Show progress
            var progressContainer = sessionsTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = sessionsTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_clear_all_sessions',
                    nonce: swsib_clean.nonce
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sessionsTab, response.data.message, 'success', true);
                        
                        // Reload sessions
                        loadSessions(1);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sessionsTab, 'Error: ' + response.data.message, 'error', false);
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the clearing process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(sessionsTab, 'An error occurred during the clearing process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    sessionsTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    sessionsTab.find('#select-all-sessions').prop('checked', false);
                }
            });
        }
    }
    
    /**
     * Initialize the Source Queue tab
     */
    function initSourceQueueTab() {
        var sourceQueueTab = $('#source-queue-tab');
        var sourceQueuePagination = sourceQueueTab.find('.swsib-clean-pagination');
        var currentPage = 1;
        var totalPages = 1;
        
        // Store load function in the global scope
        tabFunctions.source_queue.load = loadSourceQueue;
        
        // Load source queue on tab initialization
        loadSourceQueue(currentPage);
        
        // Search button click
        sourceQueueTab.find('.swsib-clean-search-button').on('click', function() {
            currentPage = 1;
            loadSourceQueue(currentPage);
        });
        
        // Search input enter key
        sourceQueueTab.find('.swsib-clean-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                currentPage = 1;
                loadSourceQueue(currentPage);
            }
        });
        
        // Refresh button
        sourceQueueTab.find('.refresh-data-button').on('click', function() {
            loadSourceQueue(currentPage);
        });
        
        // Pagination
        sourceQueueTab.on('click', '.swsib-clean-pagination-controls button', function() {
            var page = $(this).data('page');
            
            if (page === 'prev') {
                page = Math.max(1, currentPage - 1);
            } else if (page === 'next') {
                page = Math.min(totalPages, currentPage + 1);
            }
            
            if (page !== currentPage) {
                currentPage = page;
                loadSourceQueue(currentPage);
            }
        });
        
        // Select all checkbox
        sourceQueueTab.on('change', '#select-all-source-queue', function() {
            var isChecked = $(this).prop('checked');
            sourceQueueTab.find('.source-queue-checkbox').prop('checked', isChecked);
        });
        
        // Bulk delete
        sourceQueueTab.find('#bulk-delete-source-queue').on('click', function() {
            var selectedSourceQueue = getSelectedSourceQueue();
            
            if (selectedSourceQueue.length === 0) {
                alert(swsib_clean.error_no_selection);
                return;
            }
            
            showModal(
                'Delete Selected Source Queue Items',
                swsib_clean.confirm_delete_source_queue,
                function() {
                    deleteSourceQueue(selectedSourceQueue);
                }
            );
        });
        
        // Clear all
        sourceQueueTab.find('#clear-all-source-queue').on('click', function() {
            showModal(
                'Clear All Source Queue Items',
                swsib_clean.confirm_clear_all_source_queue,
                function() {
                    clearAllSourceQueue();
                }
            );
        });
        
        /**
         * Load source queue
         */
        function loadSourceQueue(page) {
            var searchTerm = sourceQueueTab.find('.swsib-clean-search-input').val();
            
            // Show loading
            var sourceQueueTable = sourceQueueTab.find('.swsib-clean-table tbody');
            showLoadingSpinner(sourceQueueTable);
            sourceQueuePagination.addClass('swsib-hidden');
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_source_queue',
                    nonce: swsib_clean.nonce,
                    page: page,
                    per_page: 10,
                    search: searchTerm,
                    sort_column: sortState.source_queue.column,
                    sort_direction: sortState.source_queue.direction
                },
                success: function(response) {
                    if (response.success) {
                        displaySourceQueue(response.data);
                        updateSortIndicators('source_queue');
                    } else {
                        sourceQueueTab.find('.swsib-clean-table tbody').html('<tr><td colspan="9" class="text-center error-message">Error: ' + response.data.message + '</td></tr>');
                    }
                },
                error: function() {
                    sourceQueueTab.find('.swsib-clean-table tbody').html('<tr><td colspan="9" class="text-center error-message">An error occurred while loading source queue.</td></tr>');
                }
            });
        }
        
        /**
         * Display source queue
         */
        function displaySourceQueue(data) {
            var sourceQueueItems = data.source_queue_items;
            var tbody = sourceQueueTab.find('.swsib-clean-table tbody');
            
            // Update pagination vars
            currentPage = data.current_page;
            totalPages = data.pages;
            
            // Clear table
            tbody.empty();
            
            if (sourceQueueItems.length === 0) {
                tbody.html('<tr><td colspan="9" class="text-center">No source queue items found.</td></tr>');
                sourceQueuePagination.addClass('swsib-hidden');
                return;
            }
            
            // Add source queue items to table
            $.each(sourceQueueItems, function(i, item) {
                var status = item.status || 'unknown';
                var statusClass;
                
                // Determine badge class based on status
                switch(status.toLowerCase()) {
                    case 'success':
                        statusClass = 'success';
                        break;
                    case 'error':
                    case 'failed':
                        statusClass = 'danger';
                        break;
                    case 'pending':
                    case 'processing':
                        statusClass = 'warning';
                        break;
                    default:
                        statusClass = 'info';
                }
                
                var row = '<tr>' +
                          '    <td class="checkbox-cell"><input type="checkbox" class="source-queue-checkbox" value="' + item.source_queue_id + '"></td>' +
                          '    <td>' + item.source_queue_id + '</td>' +
                          '    <td>' + (item.name || '-') + '</td>' +
                          '    <td>' + (item.app_id || '-') + '</td>' +
                          '    <td>' + (item.host || '-') + '</td>' +
                          '    <td>' + (item.type || '-') + '</td>' +
                          '    <td><span class="swsib-clean-badge ' + statusClass + '">' + status + '</span></td>' +
                          '    <td>' + formatDate(item.created_at) + '</td>' +
                          '    <td>' + formatDate(item.updated_at) + '</td>' +
                          '</tr>';
                
                tbody.append(row);
            });
            
            // Update pagination
            updatePagination(sourceQueuePagination, currentPage, totalPages, data.total);
        }
        
        /**
         * Get selected source queue items
         */
        function getSelectedSourceQueue() {
            var selectedSourceQueue = [];
            
            sourceQueueTab.find('.source-queue-checkbox:checked').each(function() {
                selectedSourceQueue.push($(this).val());
            });
            
            return selectedSourceQueue;
        }
        
        /**
         * Delete source queue items
         */
        function deleteSourceQueue(sourceQueueIds) {
            // Disable buttons
            sourceQueueTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = sourceQueueIds.length;
            operationProgress.detailedLog.push({ type: 'info', message: 'Starting deletion of ' + sourceQueueIds.length + ' source queue item(s)...' });
            
            // Show progress
            var progressContainer = sourceQueueTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = sourceQueueTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_source_queue',
                    nonce: swsib_clean.nonce,
                    source_queue_ids: sourceQueueIds
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sourceQueueTab, response.data.message, 'success', true);
                        
                        // Reload source queue
                        loadSourceQueue(currentPage);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sourceQueueTab, 'Error: ' + response.data.message, 'error', false);
                        
                        if (response.data.errors) {
                            $.each(response.data.errors, function(i, error) {
                                operationProgress.detailedLog.push({ type: 'error', message: error });
                            });
                            updateProgressDisplay(progressContainer, logContainer);
                        }
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the deletion process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(sourceQueueTab, 'An error occurred during the deletion process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    sourceQueueTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    sourceQueueTab.find('#select-all-source-queue').prop('checked', false);
                }
            });
        }
        
        /**
         * Clear all source queue items
         */
        function clearAllSourceQueue() {
            // Disable buttons
            sourceQueueTab.find('.swsib-clean-button').prop('disabled', true);
            
            // Reset progress tracking
            resetProgress();
            operationProgress.totalSteps = 1;
            operationProgress.detailedLog.push({ type: 'info', message: 'Clearing all source queue items...' });
            
            // Show progress
            var progressContainer = sourceQueueTab.find('.swsib-clean-progress');
            progressContainer.addClass('active');
            
            // Show log
            var logContainer = sourceQueueTab.find('.swsib-clean-log');
            logContainer.addClass('active');
            
            // Initial progress update
            updateProgressDisplay(progressContainer, logContainer);
            
            $.ajax({
                url: swsib_clean.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_clear_all_source_queue',
                    nonce: swsib_clean.nonce
                },
                success: function(response) {
                    if (response.success) {
                        operationProgress.currentStep = operationProgress.totalSteps;
                        operationProgress.detailedLog.push({ type: 'success', message: response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sourceQueueTab, response.data.message, 'success', true);
                        
                        // Reload source queue
                        loadSourceQueue(1);
                    } else {
                        operationProgress.detailedLog.push({ type: 'error', message: 'Error: ' + response.data.message });
                        updateProgressDisplay(progressContainer, logContainer);
                        
                        showMessage(sourceQueueTab, 'Error: ' + response.data.message, 'error', false);
                    }
                },
                error: function() {
                    operationProgress.detailedLog.push({ type: 'error', message: 'An error occurred during the clearing process.' });
                    updateProgressDisplay(progressContainer, logContainer);
                    
                    showMessage(sourceQueueTab, 'An error occurred during the clearing process.', 'error', false);
                },
                complete: function() {
                    // Re-enable buttons
                    sourceQueueTab.find('.swsib-clean-button').prop('disabled', false);
                    
                    // Keep progress visible for user to review
                    
                    // Uncheck select all
                    sourceQueueTab.find('#select-all-source-queue').prop('checked', false);
                }
            });
        }
    }
    
    /**
     * Initialize the Folder Cleanup tab
     */
    function initFolderCleanupTab() {
        // Store load function in the global scope if needed
        tabFunctions.folder_cleanup = {
            load: function() {
                // The folder cleanup tab has its own AJAX calls and doesn't need a central load function
                // But we can define one to avoid 'undefined' warnings
                console.log("Folder cleanup tab initialized");
            }
        };
    }
    
    /**
     * Update pagination controls
     */
    function updatePagination(paginationContainer, currentPage, totalPages, totalItems) {
        // Show pagination if there are items
        if (totalItems > 0) {
            paginationContainer.removeClass('swsib-hidden');
        } else {
            paginationContainer.addClass('swsib-hidden');
            return;
        }
        
        // Update pagination info
        var startItem = ((currentPage - 1) * 10) + 1;
        var endItem = Math.min(startItem + 9, totalItems);
        
        paginationContainer.find('.swsib-clean-pagination-info').text(
            'Showing ' + startItem + ' to ' + endItem + ' of ' + totalItems + ' total'
        );
        
        // Clear pagination controls
        var paginationControls = paginationContainer.find('.swsib-clean-pagination-controls');
        paginationControls.empty();
        
        // Add previous button
        paginationControls.append(
            '<button type="button" class="swsib-clean-pagination-prev" data-page="prev" ' + 
            (currentPage === 1 ? 'disabled' : '') + 
            '>Prev</button>'
        );
        
        // Determine which page buttons to show
        var startPage = Math.max(1, currentPage - 2);
        var endPage = Math.min(totalPages, startPage + 4);
        
        if (endPage - startPage < 4 && startPage > 1) {
            startPage = Math.max(1, endPage - 4);
        }
        
        // Add page buttons
        for (var i = startPage; i <= endPage; i++) {
            paginationControls.append(
                '<button type="button" class="' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>'
            );
        }
        
        // Add next button
        paginationControls.append(
            '<button type="button" class="swsib-clean-pagination-next" data-page="next" ' + 
            (currentPage === totalPages || totalPages === 0 ? 'disabled' : '') + 
            '>Next</button>'
        );
    }
})(jQuery);