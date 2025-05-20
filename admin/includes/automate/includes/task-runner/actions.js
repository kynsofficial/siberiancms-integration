/**
 * SwiftSpeed Siberian Automated Actions JavaScript
 */
(function($) {
    'use strict';

    // Current pagination state
    let currentPage = 1;
    let totalPages = 1;
    let perPage = 20;
    let totalItems = 0;
    let isLoading = false;

    // Initialize the actions tab
    function initActions() {
        console.log('Initializing Automated Actions tab');
        
        // Register event handlers
        registerEventHandlers();
        
        // Load initial logs
        loadActionLogs(1);
    }

    // Register event handlers
    function registerEventHandlers() {
        // Refresh logs button
        $('#refresh-action-logs').on('click', function() {
            loadActionLogs(currentPage);
        });
        
        // Clear logs button - Remove any existing handlers first to prevent duplicates
        $('#clear-action-logs').off('click').on('click', function() {
            if (confirm('Are you sure you want to clear all action logs? This cannot be undone.')) {
                clearActionLogs();
            }
        });
        
        // Save log limit button
        $('#save-action-limit').on('click', function() {
            saveActionLogLimit();
        });
        
        // Pagination buttons
        $('#action-logs-first-page').on('click', function() {
            if (currentPage > 1 && !isLoading) {
                loadActionLogs(1);
            }
        });
        
        $('#action-logs-prev-page').on('click', function() {
            if (currentPage > 1 && !isLoading) {
                loadActionLogs(currentPage - 1);
            }
        });
        
        $('#action-logs-next-page').on('click', function() {
            if (currentPage < totalPages && !isLoading) {
                loadActionLogs(currentPage + 1);
            }
        });
        
        $('#action-logs-last-page').on('click', function() {
            if (currentPage < totalPages && !isLoading) {
                loadActionLogs(totalPages);
            }
        });
        
        // Modal close buttons
        $('.action-modal-close').on('click', function() {
            $('#action-details-modal').hide();
        });
        
        // Close modal when clicking outside
        $(window).on('click', function(event) {
            if ($(event.target).is('#action-details-modal')) {
                $('#action-details-modal').hide();
            }
        });
        
        // Move the refresh tasks button functionality here
        $('#refresh-tasks-button').off('click').on('click', function() {
            const $button = $(this);
            const $message = $('#task-refresh-message');
            
            $button.prop('disabled', true);
            $button.find('.dashicons').addClass('spin');
            $message.text('Checking tasks...').css('color', '#0073aa').show();
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_run_all_due_tasks',
                    nonce: swsib_automate.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $message.text('Tasks checked successfully!').css('color', 'green');
                        
                        // Reload action logs after a short delay
                        setTimeout(function() {
                            loadActionLogs(1);
                        }, 1500);
                        
                        // Hide message after 5 seconds
                        setTimeout(function() {
                            $message.fadeOut();
                        }, 5000);
                    } else {
                        $message.text('Error: ' + (response.data ? response.data.message : 'Unknown error')).css('color', 'red');
                    }
                },
                error: function() {
                    $message.text('Error checking tasks').css('color', 'red');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('spin');
                    
                    // Add CSS for spin animation if it's not already in the stylesheet
                    if (!$('#task-refresh-styles').length) {
                        $('head').append(
                            '<style id="task-refresh-styles">' +
                            '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }' +
                            '.dashicons.spin { animation: spin 1s linear infinite; }' +
                            '</style>'
                        );
                    }
                }
            });
        });
    }

    // Load action logs
    function loadActionLogs(page) {
        console.log('Loading action logs, page:', page);
        
        if (isLoading) return;
        
        isLoading = true;
        currentPage = page;
        
        // Show loading spinner
        $('#action-logs-tbody').html('<tr><td colspan="5" class="action-logs-empty">Loading logs...</td></tr>');
        $('#action-logs-loading').show();
        
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_action_logs',
                nonce: swsib_automate.nonce,
                page: page,
                per_page: perPage
            },
            success: function(response) {
                console.log('Action logs response:', response);
                
                if (response.success) {
                    // Update pagination information
                    totalPages = response.data.total_pages;
                    totalItems = response.data.total_items;
                    currentPage = response.data.page;
                    
                    // Update logs table
                    updateLogsTable(response.data.logs);
                    
                    // Update pagination UI
                    updatePagination();
                } else {
                    $('#action-logs-tbody').html('<tr><td colspan="5" class="action-logs-empty">' + 
                                                (response.data.message || 'Error loading logs') + '</td></tr>');
                    console.error('Error loading logs:', response.data.message);
                }
            },
            error: function(xhr, status, error) {
                $('#action-logs-tbody').html('<tr><td colspan="5" class="action-logs-empty">Error loading logs. Please try again.</td></tr>');
                console.error('AJAX error loading logs:', error, xhr.responseText);
            },
            complete: function() {
                $('#action-logs-loading').hide();
                isLoading = false;
            }
        });
    }

    // Update logs table with data
    function updateLogsTable(logs) {
        const $tbody = $('#action-logs-tbody');
        
        if (!logs || logs.length === 0) {
            $tbody.html('<tr><td colspan="5" class="action-logs-empty">No logs found.</td></tr>');
            return;
        }
        
        let html = '';
        
        logs.forEach(function(log) {
            const statusClass = log.success ? 'success' : 'error';
            const statusText = log.success ? 'Success' : 'Failed';
            
            html += '<tr>';
            html += '<td>' + log.date + '</td>';
            html += '<td>' + log.task_type + '</td>';
            html += '<td>' + log.summary + '</td>';
            html += '<td><span class="action-status ' + statusClass + '">' + statusText + '</span></td>';
            html += '<td><button type="button" class="button button-small view-action-details" data-task-id="' + log.task_id + '">View Details</button></td>';
            html += '</tr>';
        });
        
        $tbody.html(html);
        
        // Attach event handlers for detail buttons
        $('.view-action-details').on('click', function() {
            const taskId = $(this).data('task-id');
            viewActionDetails(taskId);
        });
    }

    // Update pagination UI
    function updatePagination() {
        $('#action-logs-count').text(totalItems + ' ' + (totalItems === 1 ? 'item' : 'items'));
        $('#action-logs-current-page').text(currentPage);
        $('#action-logs-total-pages').text(totalPages);
        
        // Enable/disable pagination buttons
        $('#action-logs-first-page, #action-logs-prev-page').prop('disabled', currentPage <= 1);
        $('#action-logs-next-page, #action-logs-last-page').prop('disabled', currentPage >= totalPages);
    }

    // Calculate days left before auto-deletion
    function calculateDaysLeft(warnedDate, warningPeriod) {
        if (!warnedDate) return 'Unknown';
        
        // Default warning period to 14 days if not specified
        warningPeriod = warningPeriod || 14;
        
        const warnedTime = new Date(warnedDate).getTime();
        const currentTime = new Date().getTime();
        const warningPeriodMs = warningPeriod * 24 * 60 * 60 * 1000;
        const autoDeleteTime = warnedTime + warningPeriodMs;
        
        // Calculate days left
        const msLeft = autoDeleteTime - currentTime;
        const daysLeft = Math.ceil(msLeft / (24 * 60 * 60 * 1000));
        
        if (daysLeft < 0) return 'Overdue for deletion';
        if (daysLeft === 0) return 'Due for deletion today';
        return daysLeft + ' day' + (daysLeft === 1 ? '' : 's') + ' left';
    }

    // View action details
    function viewActionDetails(taskId) {
        // Reset modal content
        $('#action-details-type').text('');
        $('#action-details-time').text('');
        $('#action-details-status').text('');
        $('#action-details-args').text('');
        $('#action-details-message').html('');
        $('#action-details-operation').html('');
        
        // Hide user and app details sections initially
        $('#processed-users-container').hide();
        $('#created-users-list').hide().find('.users-list').empty();
        $('#deleted-users-list').hide().find('.users-list').empty();
        $('#users-created-count').text('0');
        $('#users-deleted-count').text('0');
        $('#users-errors-count').text('0');
        
        // Hide apps container
        $('#processed-apps-container').hide();
        $('#deleted-apps-list').hide().find('.apps-list').empty();
        $('#apps-deleted-count').text('0');
        $('#apps-skipped-count').text('0');
        $('#apps-errors-count').text('0');
        
        // Hide DB items container
        $('#processed-items-container').hide();
        $('#deleted-items-list').hide().find('.deleted-items-list').empty();
        $('#optimized-tables-list').hide().find('.optimized-tables-list').empty();
        $('#items-deleted-count').text('0');
        $('#items-skipped-count').text('0');
        $('#items-errors-count').text('0');
        
        // Show loading spinner
        $('#action-details-loading').show();
        $('#action-details-content').hide();
        
        // Show modal
        $('#action-details-modal').show();
        
        // Get action details
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_action_details',
                nonce: swsib_automate.nonce,
                task_id: taskId
            },
            success: function(response) {
                console.log('Action details response:', response);
                
                if (response.success) {
                    const details = response.data;
                    
                    // Get warning period from task args if available (for calculating days left)
                    let warningPeriod = 14; // Default
                    if (details.task_args && details.task_args.settings && details.task_args.settings.warning_period) {
                        warningPeriod = parseInt(details.task_args.settings.warning_period);
                    }
                    
                    // Update modal title
                    $('#action-details-title').text('Task Details: ' + details.task_type);
                    
                    // Update details fields
                    $('#action-details-type').text(details.task_type);
                    $('#action-details-time').text(details.date);
                    
                    const statusClass = details.success ? 'success' : 'error';
                    const statusText = details.success ? 'Success' : 'Failed';
                    $('#action-details-status').html('<span class="action-status ' + statusClass + '">' + statusText + '</span>');
                    
                    // Format task args
                    let argsText = '';
                    try {
                        argsText = JSON.stringify(details.task_args, null, 2);
                    } catch (e) {
                        argsText = 'Unable to format task arguments';
                    }
                    $('#action-details-args').text(argsText);
                    
                    // Format message
                    $('#action-details-message').html(details.message);
                    
                    // Format operation details - Improved to handle objects better and remove redundant info
                    let operationHtml = '';
                    if (details.operation_details && Object.keys(details.operation_details).length > 0) {
                        operationHtml += '<div class="operation-details">';
                        
                        // Skip these keys as they are either displayed elsewhere or redundant
                        const skipKeys = [
                            'timestamp', 'created_users_list', 'deleted_users_list', 
                            'processed_users', 'timestamp_formatted', 'created_users', 
                            'deleted_users', 'detailed_summary', 'deleted_apps_list',
                            'deleted_apps', 'deleted_items', 'deleted_items_list',
                            'deleted_items_formatted', 'optimized_tables',
                            'optimized_tables_formatted', 'optimized_tables_list',
                            'warned_users', 'warned_users_list', 'warned_users_formatted',
                            'skipped_users', 'skipped_users_list', 'skipped_users_formatted'
                        ];
                        
                        // Process by specific operation detail types
                        if (details.operation_details.processed !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Processed:</span> ' + details.operation_details.processed + '</div>';
                        }
                        
                        if (details.operation_details.total !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Total:</span> ' + details.operation_details.total + '</div>';
                        }
                        
                        if (details.operation_details.created !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Created:</span> ' + details.operation_details.created + '</div>';
                            $('#users-created-count').text(details.operation_details.created);
                        }
                        
                        if (details.operation_details.deleted !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Deleted:</span> ' + details.operation_details.deleted + '</div>';
                            $('#users-deleted-count').text(details.operation_details.deleted);
                            $('#apps-deleted-count').text(details.operation_details.deleted);
                            $('#items-deleted-count').text(details.operation_details.deleted);
                        }
                        
                        if (details.operation_details.warned !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Warned:</span> ' + details.operation_details.warned + '</div>';
                        }
                        
                        if (details.operation_details.auto_deleted !== undefined && details.operation_details.auto_deleted > 0) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Auto-Deleted After Warning:</span> ' + details.operation_details.auto_deleted + '</div>';
                        }
                        
                        if (details.operation_details.skipped !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Skipped:</span> ' + details.operation_details.skipped + '</div>';
                            $('#apps-skipped-count').text(details.operation_details.skipped);
                            $('#items-skipped-count').text(details.operation_details.skipped);
                        }
                        
                        if (details.operation_details.optimized !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Optimized:</span> ' + details.operation_details.optimized + '</div>';
                            $('#items-deleted-count').text(details.operation_details.optimized);
                        }
                        
                        if (details.operation_details.errors !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Errors:</span> ' + details.operation_details.errors + '</div>';
                            $('#users-errors-count').text(details.operation_details.errors);
                            $('#apps-errors-count').text(details.operation_details.errors);
                            $('#items-errors-count').text(details.operation_details.errors);
                        }
                        
                        if (details.operation_details.count !== undefined) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Count:</span> ' + details.operation_details.count + '</div>';
                        }
                        
                        if (details.operation_details.auto_deleted_msg) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Auto-Deletion Note:</span> ' + details.operation_details.auto_deleted_msg + '</div>';
                        }
                        
                        // Add summary if available
                        if (details.operation_details.summary) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Summary:</span> ' + details.operation_details.summary + '</div>';
                        }
                        
                        // Add detailed summary if available
                        if (details.operation_details.detailed_summary) {
                            operationHtml += '<div class="operation-detail"><span class="detail-label">Detailed Summary:</span> ' + details.operation_details.detailed_summary + '</div>';
                        }
                        
                        // Add any custom details
                        for (const key in details.operation_details) {
                            if (!skipKeys.includes(key) && 
                                ['processed', 'total', 'deleted', 'errors', 'count', 'summary', 'created', 
                                'created_users', 'deleted_users', 'detailed_summary', 'task', 'skipped',
                                'warned', 'optimized', 'progress_percentage', 'warned_users', 
                                'warned_users_count', 'deleted_users_count', 'skipped_users', 
                                'skipped_users_count', 'auto_deleted', 'auto_deleted_msg'].indexOf(key) === -1) {
                                
                                // Skip anything that's an object array representation
                                if (typeof details.operation_details[key] === 'string' && 
                                    details.operation_details[key].includes('[object Object]')) {
                                    continue;
                                }
                                
                                operationHtml += '<div class="operation-detail"><span class="detail-label">' + 
                                                capitalizeFirstLetter(key) + ':</span> ' + details.operation_details[key] + '</div>';
                            }
                        }
                        
                        operationHtml += '</div>';
                        
                        // Handle user tasks (WP cleanup tasks)
                        if (details.task_type_raw === 'wp_cleanup') {
                            if (details.task_args.task === 'unsynced_users' || details.task_args.task === 'spam_users') {
                                // Show the users container
                                $('#processed-users-container').show();
                                
                                // Process created users list for unsynced_users task
                                if (details.operation_details.created_users && 
                                    details.operation_details.created_users.length > 0) {
                                    
                                    let createdUsersHtml = '';
                                    details.operation_details.created_users.forEach(function(user) {
                                        createdUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                        if (typeof user === 'object') {
                                            if (user.user_email) {
                                                createdUsersHtml += '<strong>' + user.user_email + '</strong>';
                                            } else if (user.email) {
                                                createdUsersHtml += '<strong>' + user.email + '</strong>';
                                            }
                                            
                                            if (user.firstname || user.lastname) {
                                                createdUsersHtml += ' (' + (user.firstname || '') + ' ' + (user.lastname || '') + ')';
                                            }
                                            
                                            if (user.ID) {
                                                createdUsersHtml += ' - WordPress ID: ' + user.ID;
                                            }
                                            
                                            if (user.id || user.admin_id || user.siberian_id) {
                                                createdUsersHtml += ' - Siberian ID: ' + (user.id || user.admin_id || user.siberian_id);
                                            }
                                        } else {
                                            createdUsersHtml += user;
                                        }
                                        createdUsersHtml += '</div>';
                                    });
                                    
                                    $('#created-users-list .users-list').html(createdUsersHtml);
                                    $('#created-users-list').show();
                                }
                                
                                // Process deleted users list
                                if (details.operation_details.deleted_users && 
                                    details.operation_details.deleted_users.length > 0) {
                                    
                                    let deletedUsersHtml = '';
                                    details.operation_details.deleted_users.forEach(function(user) {
                                        deletedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                        if (typeof user === 'object') {
                                            if (user.user_email) {
                                                deletedUsersHtml += '<strong>' + user.user_email + '</strong>';
                                            } else if (user.email) {
                                                deletedUsersHtml += '<strong>' + user.email + '</strong>';
                                            }
                                            
                                            if (user.user_login) {
                                                deletedUsersHtml += ' (' + user.user_login + ')';
                                            }
                                            
                                            if (user.display_name) {
                                                deletedUsersHtml += ' - Display name: ' + user.display_name;
                                            }
                                            
                                            if (user.ID) {
                                                deletedUsersHtml += ' - WordPress ID: ' + user.ID;
                                            }
                                        } else {
                                            deletedUsersHtml += user;
                                        }
                                        deletedUsersHtml += '</div>';
                                    });
                                    
                                    $('#deleted-users-list .users-list').html(deletedUsersHtml);
                                    $('#deleted-users-list').show();
                                }
                            }
                        }
                        
                        // Handle user management tasks
                        if (details.task_type_raw === 'user_management') {
                            // Show the users container and update counts
                            $('#processed-users-container').show();
                            
                            if (details.operation_details.warned !== undefined) {
                                $('#users-created-count').text(details.operation_details.warned);
                            }
                            
                            // Process deleted users list
                            if (details.operation_details.deleted_users_formatted && 
                                details.operation_details.deleted_users_formatted.length > 0) {
                                
                                let deletedUsersHtml = '';
                                details.operation_details.deleted_users_formatted.forEach(function(userText) {
                                    let additionalInfo = '';
                                    if (userText.includes('Auto-deleted after warning period')) {
                                        additionalInfo = ' <span style="color: #d63638;">(Auto-deleted after warning period)</span>';
                                    }
                                    deletedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">' + userText + additionalInfo + '</div>';
                                });
                                
                                $('#deleted-users-list .users-list').html(deletedUsersHtml);
                                $('#deleted-users-list').show();
                            } 
                            else if (details.operation_details.deleted_users && 
                                details.operation_details.deleted_users.length > 0) {
                                
                                let deletedUsersHtml = '';
                                details.operation_details.deleted_users.forEach(function(user) {
                                    deletedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    if (typeof user === 'object') {
                                        const timestamp = user.timestamp || new Date().toLocaleString();
                                        const id = user.id || "unknown";
                                        const email = user.email || "no email";
                                        const name = user.name || "";
                                        const autoDeleted = user.auto_delete ? ' <span style="color: #d63638;">(Auto-deleted after warning period)</span>' : '';
                                        
                                        deletedUsersHtml += timestamp + ' - <strong>' + id + '</strong> - ' + email;
                                        if (name) {
                                            deletedUsersHtml += ' - ' + name;
                                        }
                                        deletedUsersHtml += autoDeleted;
                                    } else {
                                        deletedUsersHtml += user;
                                    }
                                    deletedUsersHtml += '</div>';
                                });
                                
                                $('#deleted-users-list .users-list').html(deletedUsersHtml);
                                $('#deleted-users-list').show();
                            }
                            
                            // Process warned users list - Add a new section for warned users
                            if (!$('#warned-users-list').length) {
                                $('#deleted-users-list').after('<div id="warned-users-list" style="display: none;"><h4>Warned Users:</h4><div class="users-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; margin-bottom: 15px;"></div></div>');
                            }
                            
                            if (details.operation_details.warned_users_formatted && 
                                details.operation_details.warned_users_formatted.length > 0) {
                                
                                let warnedUsersHtml = '';
                                details.operation_details.warned_users_formatted.forEach(function(userText) {
                                    warnedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">' + userText + '</div>';
                                });
                                
                                $('#warned-users-list .users-list').html(warnedUsersHtml);
                                $('#warned-users-list').show();
                            }
                            else if (details.operation_details.warned_users && 
                                    details.operation_details.warned_users.length > 0) {
                                
                                let warnedUsersHtml = '';
                                details.operation_details.warned_users.forEach(function(user) {
                                    warnedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    if (typeof user === 'object') {
                                        const timestamp = user.timestamp || new Date().toLocaleString();
                                        const id = user.id || "unknown";
                                        const email = user.email || "no email";
                                        const name = user.name || "";
                                        
                                        // Check for warning date to calculate days remaining
                                        let warningInfo = '';
                                        if (user.warned_date) {
                                            const daysLeft = calculateDaysLeft(user.warned_date, warningPeriod);
                                            warningInfo = ' - <span style="color: #d63638;">Warning sent: ' + user.warned_date + 
                                                          ' - ' + daysLeft + '</span>';
                                        }
                                        
                                        warnedUsersHtml += timestamp + ' - <strong>' + id + '</strong> - ' + email;
                                        if (name) {
                                            warnedUsersHtml += ' - ' + name;
                                        }
                                        warnedUsersHtml += warningInfo;
                                    } else {
                                        warnedUsersHtml += user;
                                    }
                                    warnedUsersHtml += '</div>';
                                });
                                
                                $('#warned-users-list .users-list').html(warnedUsersHtml);
                                $('#warned-users-list').show();
                            }
                            
                            // Process skipped users list - Add a new section for skipped users
                            if (!$('#skipped-users-list').length) {
                                $('#warned-users-list').after('<div id="skipped-users-list" style="display: none;"><h4>Skipped Users:</h4><div class="users-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px;"></div></div>');
                            }
                            
                            if (details.operation_details.skipped_users_formatted && 
                                details.operation_details.skipped_users_formatted.length > 0) {
                                
                                let skippedUsersHtml = '';
                                details.operation_details.skipped_users_formatted.forEach(function(userText) {
                                    skippedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">' + userText + '</div>';
                                });
                                
                                $('#skipped-users-list .users-list').html(skippedUsersHtml);
                                $('#skipped-users-list').show();
                            }
                            else if (details.operation_details.skipped_users && 
                                    details.operation_details.skipped_users.length > 0) {
                                
                                let skippedUsersHtml = '';
                                details.operation_details.skipped_users.forEach(function(user) {
                                    skippedUsersHtml += '<div class="user-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    if (typeof user === 'object') {
                                        const timestamp = user.timestamp || new Date().toLocaleString();
                                        const id = user.id || "unknown";
                                        const email = user.email || "no email";
                                        const name = user.name || "";
                                        const reason = user.reason || "No reason specified";
                                        
                                        // Add days remaining info for users that were warned but not yet due for deletion
                                        let warningInfo = '';
                                        if (user.warned_date) {
                                            const daysLeft = calculateDaysLeft(user.warned_date, warningPeriod);
                                            warningInfo = ' - Warning sent: ' + user.warned_date + ' - ' + daysLeft;
                                        }
                                        
                                        skippedUsersHtml += timestamp + ' - <strong>' + id + '</strong> - ' + email;
                                        if (name) {
                                            skippedUsersHtml += ' - ' + name;
                                        }
                                        skippedUsersHtml += ' - Reason: ' + reason + warningInfo;
                                    } else {
                                        skippedUsersHtml += user;
                                    }
                                    skippedUsersHtml += '</div>';
                                });
                                
                                $('#skipped-users-list .users-list').html(skippedUsersHtml);
                                $('#skipped-users-list').show();
                            }
                        }
                        
                        // Handle app management tasks
                        if (details.task_type_raw === 'app_management') {
                            // Show the apps container
                            $('#processed-apps-container').show();
                            
                            // Process deleted apps list
                            if (details.operation_details.deleted_apps_formatted && 
                                details.operation_details.deleted_apps_formatted.length > 0) {
                                
                                let deletedAppsHtml = '';
                                details.operation_details.deleted_apps_formatted.forEach(function(appText) {
                                    deletedAppsHtml += '<div class="app-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">' + appText + '</div>';
                                });
                                
                                $('#deleted-apps-list .apps-list').html(deletedAppsHtml);
                                $('#deleted-apps-list').show();
                            }
                            else if (details.operation_details.deleted_apps && 
                                details.operation_details.deleted_apps.length > 0) {
                                
                                let deletedAppsHtml = '';
                                details.operation_details.deleted_apps.forEach(function(app) {
                                    deletedAppsHtml += '<div class="app-item" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    if (typeof app === 'object') {
                                        const timestamp = app.timestamp || new Date().toLocaleString();
                                        const id = app.app_id || "unknown";
                                        const name = app.name || "unknown";
                                        
                                        deletedAppsHtml += timestamp + ' - <strong>' + id + '</strong> - ' + name;
                                        
                                        if (app.email) {
                                            deletedAppsHtml += ' - Owner: ' + app.email;
                                        }
                                        
                                        if (app.size_mb) {
                                            deletedAppsHtml += ' - Size: ' + app.size_mb + ' MB';
                                        }
                                        
                                        if (app.size_limit_mb) {
                                            deletedAppsHtml += ' - Limit: ' + app.size_limit_mb + ' MB';
                                        }
                                    } else {
                                        deletedAppsHtml += app;
                                    }
                                    deletedAppsHtml += '</div>';
                                });
                                
                                $('#deleted-apps-list .apps-list').html(deletedAppsHtml);
                                $('#deleted-apps-list').show();
                            }
                        }
                        
                        // Handle DB cleanup tasks
                        if (details.task_type_raw === 'db_cleanup') {
                            // Show the items container
                            $('#processed-items-container').show();
                            
                            // Set appropriate title based on task
                            let titleText = 'Deleted Items:';
                            if (details.task_args.task === 'optimize') {
                                titleText = 'Optimized Tables:';
                            } else if (details.task_args.task === 'sessions') {
                                titleText = 'Deleted Sessions:';
                            } else if (details.task_args.task === 'mail_logs') {
                                titleText = 'Deleted Mail Logs:';
                            } else if (details.task_args.task === 'source_queue') {
                                titleText = 'Deleted Queue Items:';
                            } else if (details.task_args.task === 'backoffice_alerts') {
                                titleText = 'Deleted Alerts:';
                            } else if (details.task_args.task === 'cleanup_log') {
                                titleText = 'Deleted Log Entries:';
                            }
                            $('#deleted-items-title').text(titleText);
                            
                            // Process deleted items list (formatted)
                            if (details.operation_details.deleted_items_formatted && 
                                details.operation_details.deleted_items_formatted.length > 0) {
                                
                                let deletedItemsHtml = '';
                                details.operation_details.deleted_items_formatted.forEach(function(item) {
                                    deletedItemsHtml += '<div class="item-entry" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    deletedItemsHtml += item;
                                    deletedItemsHtml += '</div>';
                                });
                                
                                $('#deleted-items-list .deleted-items-list').html(deletedItemsHtml);
                                $('#deleted-items-list').show();
                            }
                            
                            // Process optimized tables list (formatted)
                            if (details.operation_details.optimized_tables_formatted && 
                                details.operation_details.optimized_tables_formatted.length > 0) {
                                
                                let optimizedTablesHtml = '';
                                details.operation_details.optimized_tables_formatted.forEach(function(table) {
                                    optimizedTablesHtml += '<div class="table-entry" style="margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #eee;">';
                                    optimizedTablesHtml += table;
                                    optimizedTablesHtml += '</div>';
                                });
                                
                                $('#optimized-tables-list .optimized-tables-list').html(optimizedTablesHtml);
                                $('#optimized-tables-list').show();
                            }
                        }
                    } else {
                        operationHtml = '<p>No detailed information available for this task.</p>';
                    }
                    
                    $('#action-details-operation').html(operationHtml);
                    
                    // Show content
                    $('#action-details-content').show();
                } else {
                    $('#action-details-message').html('<p class="error">' + (response.data.message || 'Error loading task details') + '</p>');
                    $('#action-details-content').show();
                }
            },
            error: function(xhr, status, error) {
                $('#action-details-message').html('<p class="error">Error loading task details. Please try again.</p>');
                $('#action-details-content').show();
                console.error('AJAX error loading task details:', error, xhr.responseText);
            },
            complete: function() {
                $('#action-details-loading').hide();
            }
        });
    }

    // Save action log limit
    function saveActionLogLimit() {
        const $button = $('#save-action-limit');
        const $select = $('#action_logs_limit');
        const limit = $select.val();
        
        $button.prop('disabled', true);
        $button.text('Saving...');
        
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_save_action_limit',
                nonce: swsib_automate.nonce,
                limit: limit
            },
            success: function(response) {
                if (response.success) {
                    // Show success notification
                    if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                        window.swsib_automate_core.showNotification(response.data.message, 'success');
                    } else {
                        alert(response.data.message);
                    }
                    
                    // Reload logs
                    loadActionLogs(1);
                } else {
                    if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                        window.swsib_automate_core.showNotification(response.data.message || 'Error saving log limit', 'error');
                    } else {
                        alert(response.data.message || 'Error saving log limit');
                    }
                }
            },
            error: function(xhr, status, error) {
                if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                    window.swsib_automate_core.showNotification('Error saving log limit', 'error');
                } else {
                    alert('Error saving log limit');
                }
                console.error('AJAX error saving log limit:', error, xhr.responseText);
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.text('Save');
            }
        });
    }

    // Clear all action logs
    function clearActionLogs() {
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_clear_action_logs',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success notification
                    if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                        window.swsib_automate_core.showNotification(response.data.message, 'success');
                    } else {
                        alert(response.data.message);
                    }
                    
                    // Reload logs
                    loadActionLogs(1);
                } else {
                    if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                        window.swsib_automate_core.showNotification(response.data.message || 'Error clearing logs', 'error');
                    } else {
                        alert(response.data.message || 'Error clearing logs');
                    }
                }
            },
            error: function(xhr, status, error) {
                if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
                    window.swsib_automate_core.showNotification('Error clearing logs', 'error');
                } else {
                    alert('Error clearing logs');
                }
                console.error('AJAX error clearing logs:', error, xhr.responseText);
            }
        });
    }

    // Helper function to capitalize first letter
    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1).replace(/_/g, ' ');
    }

    // Initialize when document is ready
    initActions();

    // Initialize again when tab is clicked
    $(document).on('click', '.swsib-automate-tabs a[href*="automate_tab=actions"]', function() {
        console.log('Actions tab clicked, reloading data');
        loadActionLogs(1);
    });
    
    // Check if we're already on the actions tab and initialize
    if (window.location.href.indexOf('automate_tab=actions') > -1) {
        console.log('Already on actions tab, initializing');
        setTimeout(function() {
            loadActionLogs(1);
        }, 500);
    }

    // Expose public methods
    window.swsib_actions = {
        reload: function() {
            loadActionLogs(1);
        }
    };

})(jQuery);