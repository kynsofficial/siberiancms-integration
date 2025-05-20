/**
 * SwiftSpeed Siberian User Management JavaScript
 */
(function($) {
    'use strict';

    // Task progress tracking
    let taskProgressInterval = null;
    let taskStartTime = 0;
    let taskRunning = false;
    let currentTaskId = null;
    let retryCount = 0; // Track retry attempts
    let maxRetries = 15; // Maximum number of retries for AJAX calls
    let notificationShown = {}; // Track notifications to prevent duplicates
    let localTaskLogs = {}; // Store local task logs
    let logHashes = {}; // Track unique logs to avoid duplicates
    let batchProcessing = false; // Flag to track if we're processing batches
    let currentBatch = 0; // Current batch being processed
    let previewModal = null; // Preview modal reference
    let currentPage = 1; // Current page for data preview
    
    // Initialize the user management module
    function initUserManagement() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('.email-template-toggle').off('click');
        $('select[id$="_frequency"]').off('change');
        $('.close-progress').off('click');
        $('.run-user-management').off('click');
        $('.save-user-management-automation').off('click');
        $('.preview-user-data-button').off('click');
        $(document).off('click', '.user-modal-close');
        $(document).off('click', '.user-pagination-prev');
        $(document).off('click', '.user-pagination-next');
        
        // Toggle task settings
        $('.task-settings-toggle').on('click', function() {
            const $settingsFields = $(this).closest('.task-settings').find('.task-settings-fields');
            $settingsFields.slideToggle(200);
            
            const $icon = $(this).find('.dashicons');
            if ($settingsFields.is(':visible')) {
                $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            } else {
                $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            }
            
            // Make sure only this card expands, not adjacent ones
            $(this).closest('.task-card').addClass('expanded');
        });
        
        // Toggle email template editor
        $('.email-template-toggle').on('click', function() {
            const $templateContent = $(this).closest('.email-template-header').next('.email-template-content');
            $templateContent.slideToggle(200);
            
            const $icon = $(this).find('.dashicons');
            if ($templateContent.is(':visible')) {
                $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
            } else {
                $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
            }
        });
        
        // Hide/show custom frequency input based on selection
        $('select[id$="_frequency"]').on('change', function() {
            const customContainer = $(this).closest('.task-settings-field-group').find('.custom-frequency-container');
            if ($(this).val() === 'custom') {
                customContainer.slideDown(200);
            } else {
                customContainer.slideUp(200);
            }
        });
        
        // Close progress indicator
        $('.close-progress').on('click', function() {
            $('#task-progress-container').hide();
            
            // Stop progress tracking
            if (taskRunning && taskProgressInterval) {
                clearInterval(taskProgressInterval);
                taskProgressInterval = null;
                taskRunning = false;
                batchProcessing = false;
            }
        });
        
        // Run user management tasks
        $('.run-user-management').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(swsib_automate.confirm_delete_users)) {
                return;
            }
            
            const task = $(this).data('task');
            const $button = $(this);
            const originalText = $button.text();
            
            // Reset progress tracking variables
            retryCount = 0;
            notificationShown = {};
            localTaskLogs = {};
            logHashes = {};
            batchProcessing = false;
            currentBatch = 0;
            
            // Store original text and disable button
            $button.data('original-text', originalText);
            $button.prop('disabled', true).text(swsib_automate.task_running);
            
            // Start task progress tracking
            const taskId = 'user_' + task;
            startTask(taskId);
            
            // Add direct information about what we're doing
            if (task === 'inactive') {
                addLocalLog('Starting inactive users cleanup task', 'info');
                addLocalLog('This task will remove users who have not logged in for the specified period', 'info');
            } else if (task === 'no_apps') {
                addLocalLog('Starting users without apps cleanup task', 'info');
                addLocalLog('This task will remove users who registered but never created applications', 'info');
            }
            
            // Start the task via AJAX
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_manage_users',
                    nonce: swsib_automate.nonce,
                    task: task,
                    mode: 'start'
                },
                timeout: 30000, // 30-second timeout
                success: function(response) {
                    if (response.success) {
                        addLocalLog('Task initialized successfully on server', 'success');
                        
                        // If there are batches to process, start batch processing
                        if (response.data && response.data.next_batch !== undefined) {
                            batchProcessing = true;
                            currentBatch = response.data.next_batch;
                            
                            // Check if we need to process more batches
                            if (!response.data.completed) {
                                // Process the next batch after a short delay
                                setTimeout(function() {
                                    processNextBatch(task, currentBatch);
                                }, 500);
                            } else {
                                // Task is already complete (no batches to process)
                                completeTask();
                            }
                        } else {
                            // No batch information, fall back to progress tracking
                            trackUserTaskProgress(task);
                        }
                    } else {
                        addLocalLog('Error: ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                        failTask();
                        
                        // Reset button
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    // For timeout or errors, the task might still be running in the background
                    addLocalLog('Error starting task: ' + error, 'error');
                    failTask();
                    
                    // Reset button
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Save user management automation settings
        $('.save-user-management-automation').on('click', function(e) {
            e.preventDefault();
            const $form = $(this).closest('form');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_user_management_automation',
                    nonce: swsib_automate.nonce,
                    settings: $form.serialize()
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification
                        showNotification('Settings saved successfully', 'success');
                        
                        // Update UI to reflect new settings
                        var isEnabled = $form.find('input[name$="_enabled"]').prop('checked');
                        
                        // Find the closest task card and update its badge
                        var $taskCard = $button.closest('.task-card');
                        var $badge = $taskCard.find('.task-card-badge');
                        
                        if (isEnabled) {
                            $badge.addClass('active').text('Automated');
                        } else {
                            $badge.removeClass('active').text('Manual');
                        }
                        
                        // Reload page after a delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showNotification(response.data.message || 'Failed to save settings', 'error');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('Failed to save settings: ' + error, 'error');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Preview data buttons
        $('.preview-user-data-button').on('click', function(e) {
            e.preventDefault();
            const dataType = $(this).data('type');
            loadUserDataPreview(dataType, 1);
        });
        
        // Close modal handler - using direct binding instead of delegation
        function setupModalCloseHandlers() {
            $('.user-modal-close').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (previewModal) {
                    previewModal.removeClass('show');
                    setTimeout(function() {
                        previewModal.remove();
                        previewModal = null;
                    }, 300);
                }
            });
        }
        
        // Pagination click handlers
        $(document).on('click', '.user-pagination-prev', function() {
            if (currentPage > 1) {
                currentPage--;
                const dataType = $(this).data('type');
                loadUserDataPreview(dataType, currentPage);
            }
        });
        
        $(document).on('click', '.user-pagination-next', function() {
            currentPage++;
            const dataType = $(this).data('type');
            loadUserDataPreview(dataType, currentPage);
        });
        
        // Load user counts - delay to prevent jerky UI when switching tabs
        setTimeout(function() {
            loadUserCounts();
        }, 300);
    }
    
    /**
     * Process the next batch with improved error handling and debugging
     */
    function processNextBatch(task, batchIndex) {
        if (!taskRunning || !batchProcessing) {
            console.log("Task not running or batch processing stopped");
            return;
        }
        
        addLocalLog('Processing batch ' + batchIndex, 'info');
        console.log("Processing batch:", batchIndex, "for task:", task);
        
        // Make AJAX call to process batch
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_process_user_batch',
                nonce: swsib_automate.nonce,
                task: task,
                batch: batchIndex
            },
            timeout: 60000, // 60-second timeout (batches can take time)
            success: function(response) {
                console.log("Batch response:", response);
                
                if (response.success) {
                    // Update progress
                    if (response.data && response.data.progress !== undefined) {
                        $('.task-progress-bar').css('width', response.data.progress + '%');
                        $('.task-progress-percentage').text(response.data.progress + '%');
                    }
                    
                    // Debug information
                    console.log("Response data:", response.data);
                    console.log("Next batch:", response.data.next_batch);
                    console.log("Completed:", response.data.completed);
                    
                    // Check if there are more batches to process
                    if (response.data && response.data.completed === false) {
                        // Update current batch
                        currentBatch = response.data.next_batch;
                        console.log("Moving to next batch:", currentBatch);
                        
                        // Process the next batch after a short delay
                        setTimeout(function() {
                            processNextBatch(task, currentBatch);
                        }, 500);
                    } else {
                        // All batches are processed
                        console.log("All batches processed, completing task");
                        batchProcessing = false;
                        completeTask();
                    }
                    
                    // Get the latest progress
                    trackUserTaskProgress(task);
                } else {
                    console.error("Error processing batch:", response.data ? response.data.message : "Unknown error");
                    addLocalLog('Error processing batch: ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                    batchProcessing = false;
                    failTask();
                }
            },
            error: function(xhr, status, error) {
                // Handle error - check if task is still running
                console.error("AJAX error:", error, "Status:", status);
                addLocalLog('Error processing batch: ' + error, 'error');
                
                // Increment retry counter
                retryCount++;
                
                if (retryCount <= 3) {
                    // Try again after a delay
                    console.log("Retrying batch", batchIndex, "attempt", retryCount);
                    addLocalLog('Retrying batch ' + batchIndex + ' (attempt ' + retryCount + ')', 'warning');
                    setTimeout(function() {
                        processNextBatch(task, batchIndex);
                    }, 2000);
                } else {
                    // Too many failures
                    console.error("Too many retry attempts, failing task");
                    batchProcessing = false;
                    failTask();
                }
            }
        });
    }
    
    /**
     * Track user management task progress
     */
    function trackUserTaskProgress(task) {
        if (!taskRunning) return;
        
        // Make AJAX call to get progress
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_user_progress',
                nonce: swsib_automate.nonce,
                task_type: task
            },
            timeout: 10000, // 10-second timeout
            success: function(response) {
                if (response.success) {
                    // Get progress data
                    const progressData = response.data;
                    
                    // Update progress with real data
                    const progress = progressData.progress || 0;
                    $('.task-progress-bar').css('width', progress + '%');
                    $('.task-progress-percentage').text(progress + '%');
                    
                    if (progressData.total > 0) {
                        $('.task-processed').text(progressData.processed || 0);
                        $('.task-total').text(progressData.total);
                    }
                    
                    // Update current item
                    if (progressData.current_item) {
                        $('.task-current-item').text(progressData.current_item);
                    }
                    
                    // Display logs from server
                    if (progressData.logs && progressData.logs.length > 0) {
                        // Create a hash of logs we've already processed
                        if (!logHashes[task]) {
                            logHashes[task] = {};
                        }
                        
                        // Process each log
                        progressData.logs.forEach(function(log) {
                            if (!log.message) return; // Skip empty messages
                            
                            // Create a unique hash for this log
                            const logHash = log.time + '-' + log.message.substring(0, 50);
                            
                            // Only add if we haven't shown this exact log yet
                            if (!logHashes[task][logHash]) {
                                const timestamp = new Date(log.time * 1000).toLocaleTimeString();
                                addProgressLog(log.message, log.type || 'info', timestamp);
                                logHashes[task][logHash] = true;
                            }
                        });
                    }
                    
                    // If task is completed and we're not batch processing, complete task
                    if (progressData.status === 'completed' && !batchProcessing) {
                        completeTask();
                    } else if (progressData.status === 'failed') {
                        failTask();
                    }
                }
                
                // If we're not batch processing and task is still running, check progress again after a delay
                if (taskRunning && !batchProcessing && response.data.status === 'running') {
                    setTimeout(function() {
                        trackUserTaskProgress(task);
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                console.log("Error getting progress:", error);
                
                // If we're not batch processing and task is still running, check progress again after a delay
                if (taskRunning && !batchProcessing) {
                    setTimeout(function() {
                        trackUserTaskProgress(task);
                    }, 5000);
                }
            }
        });
    }
    
    /**
     * Load user data preview
     */
    function loadUserDataPreview(dataType, page) {
        // Remove any existing modals first
        if (previewModal) {
            previewModal.remove();
            previewModal = null;
        }
        
        // Create modal
        previewModal = $('<div class="swsib-user-modal"></div>');
        const modalContent = $('<div class="swsib-user-modal-content"></div>');
        const modalHeader = $('<div class="swsib-user-modal-header"><h3>User Data Preview</h3><button type="button" class="user-modal-close" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        const modalBody = $('<div class="swsib-user-modal-body"></div>');
        const modalFooter = $('<div class="swsib-user-modal-footer"><div class="swsib-user-pagination"><button class="button user-pagination-prev" data-type="' + dataType + '">&laquo; Previous</button><span class="user-pagination-info">Page 1</span><button class="button user-pagination-next" data-type="' + dataType + '">Next &raquo;</button></div></div>');
        
        modalContent.append(modalHeader);
        modalContent.append(modalBody);
        modalContent.append(modalFooter);
        previewModal.append(modalContent);
        $('body').append(previewModal);
        
        // Add delay before showing to allow animation
        setTimeout(function() {
            previewModal.addClass('show');
            // Set up close button handlers after modal is created
            $('.user-modal-close').off('click').on('click', function() {
                if (previewModal) {
                    previewModal.removeClass('show');
                    setTimeout(function() {
                        previewModal.remove();
                        previewModal = null;
                    }, 300);
                }
            });
        }, 10);

        // Show loading
        previewModal.find('.swsib-user-modal-body').html('<div class="loading"><div class="spinner"></div><span>Loading data...</span></div>');
        previewModal.find('.user-pagination-info').text('Loading...');
        
        // Update pagination buttons data type
        previewModal.find('.user-pagination-prev, .user-pagination-next').data('type', dataType);
        
        // Set current page
        currentPage = page;
        
        // Load data via AJAX
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_preview_user_data',
                nonce: swsib_automate.nonce,
                data_type: dataType,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    // Update modal title
                    previewModal.find('.swsib-user-modal-header h3').text(response.data.title || 'User Data Preview');
                    
                    // Create table with responsive wrapper
                    const tableWrapper = $('<div class="table-responsive"></div>');
                    const table = $('<table class="wp-list-table widefat striped"></table>');
                    const thead = $('<thead></thead>');
                    const tbody = $('<tbody></tbody>');
                    
                    // Add headers
                    const headerRow = $('<tr></tr>');
                    response.data.headers.forEach(function(header) {
                        headerRow.append('<th>' + header + '</th>');
                    });
                    thead.append(headerRow);
                    table.append(thead);
                    
                    // Add data rows
                    if (response.data.items && response.data.items.length > 0) {
                        response.data.items.forEach(function(item) {
                            const row = $('<tr></tr>');
                            response.data.fields.forEach(function(field) {
                                // Format the field data
                                let cellValue = item[field] !== null && item[field] !== undefined ? item[field] : '';
                                
                                // Format dates (common in database records)
                                if (field.includes('date') || field.includes('time') || field === 'created_at' || field === 'last_action') {
                                    if (cellValue && !isNaN(Date.parse(cellValue))) {
                                        const date = new Date(cellValue);
                                        cellValue = date.toLocaleString();
                                    }
                                }
                                
                                row.append('<td>' + cellValue + '</td>');
                            });
                            tbody.append(row);
                        });
                    } else {
                        // No items found
                        const emptyRow = $('<tr><td colspan="' + response.data.headers.length + '" class="no-items">No items found</td></tr>');
                        tbody.append(emptyRow);
                    }
                    
                    table.append(tbody);
                    tableWrapper.append(table);
                    
                    // Add to modal
                    previewModal.find('.swsib-user-modal-body').html(tableWrapper);
                    
                    // Update pagination
                    previewModal.find('.user-pagination-info').text('Page ' + page + ' of ' + response.data.total_pages);
                    
                    // Enable/disable pagination buttons
                    if (page <= 1) {
                        previewModal.find('.user-pagination-prev').prop('disabled', true);
                    } else {
                        previewModal.find('.user-pagination-prev').prop('disabled', false);
                    }
                    
                    if (page >= response.data.total_pages) {
                        previewModal.find('.user-pagination-next').prop('disabled', true);
                    } else {
                        previewModal.find('.user-pagination-next').prop('disabled', false);
                    }
                } else {
                    previewModal.find('.swsib-user-modal-body').html('<div class="error-message">Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to load data') + '</div>');
                }
            },
            error: function() {
                previewModal.find('.swsib-user-modal-body').html('<div class="error-message">Failed to load data. Please try again.</div>');
            }
        });
    }
    
    /**
     * Load user counts
     */
    function loadUserCounts() {
        // Load inactive users count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_inactive_users_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.inactive-users-count').text(response.data.count);
                }
            }
        });
        
        // Load users without apps count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_users_without_apps_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.users-without-apps-count').text(response.data.count);
                }
            }
        });
    }
    
    /**
     * Start task tracking
     */
    function startTask(taskId) {
        // Set task ID
        currentTaskId = taskId;
        
        // Reset tracking variables
        retryCount = 0;
        notificationShown = {};
        localTaskLogs = {};
        logHashes = {};
        
        // Reset progress UI
        $('.task-progress-bar').css('width', '0%');
        $('.task-progress-percentage').text('0%');
        $('.task-processed').text('0');
        $('.task-total').text('0');
        $('.task-time-elapsed').text('00:00:00');
        $('.task-current-item').text('');
        $('.task-progress-log').empty();
        
        // Update title
        $('.task-title').text('Task in Progress: ' + getTaskTitle(taskId));
        
        // Show progress container
        $('#task-progress-container').show();
        
        // Mark task as running
        taskRunning = true;
        taskStartTime = new Date().getTime();
        
        // Start elapsed time counter
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
        }
        
        // Update elapsed time every second
        taskProgressInterval = setInterval(function() {
            if (taskRunning) {
                updateElapsedTime();
            }
        }, 1000);
    }
    
    /**
     * Complete task
     */
    function completeTask() {
        taskRunning = false;
        batchProcessing = false;
        
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }
        
        $('.task-progress-bar').css('width', '100%');
        $('.task-progress-percentage').text('100%');
        
        addProgressLog('Task completed successfully', 'success');
        
        // Reset button
        const taskType = currentTaskId.split('_')[1];
        const buttonSelector = '.run-user-management[data-task="' + taskType + '"]';
        
        const $button = $(buttonSelector);
        const originalText = $button.data('original-text') || 'Run Now';
        $button.prop('disabled', false).text(originalText);
        
        // Reload counts after a short delay
        setTimeout(function() {
            loadUserCounts();
        }, 1000);
    }
    
    /**
     * Fail task
     */
    function failTask() {
        taskRunning = false;
        batchProcessing = false;
        
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }
        
        addProgressLog('Task failed or was interrupted', 'error');
        
        // Reset button
        const taskType = currentTaskId.split('_')[1];
        const buttonSelector = '.run-user-management[data-task="' + taskType + '"]';
        
        const $button = $(buttonSelector);
        const originalText = $button.data('original-text') || 'Run Now';
        $button.prop('disabled', false).text(originalText);
    }
    
    /**
     * Add local log
     */
    function addLocalLog(message, type) {
        // Add to progress log UI
        addProgressLog(message, type);
        
        // Store in local task logs
        if (!localTaskLogs[currentTaskId]) {
            localTaskLogs[currentTaskId] = [];
        }
        
        localTaskLogs[currentTaskId].push({
            time: new Date().getTime(),
            message: message,
            type: type
        });
        
        // Update current item in UI for important processing logs
        if (message.indexOf('Processing') === 0 || message.indexOf('Optimizing') === 0 || 
            message.indexOf('Cleaning') === 0 || message.indexOf('Deleting') === 0) {
            $('.task-current-item').text(message);
        }
    }
    
    /**
     * Add log entry to progress log
     */
    function addProgressLog(message, type, timestamp) {
        const $log = $('.task-progress-log');
        const ts = timestamp || new Date().toLocaleTimeString();
        const $entry = $('<div class="log-entry ' + type + '"></div>');
        $entry.text('[' + ts + '] ' + message);
        $log.append($entry);
        
        // Auto-scroll to bottom, but only if we're already at or near the bottom
        const isScrolledToBottom = $log[0].scrollHeight - $log.scrollTop() - $log.outerHeight() < 50;
        if (isScrolledToBottom) {
            $log.scrollTop($log[0].scrollHeight);
        }
    }
    
    /**
     * Update elapsed time
     */
    function updateElapsedTime() {
        if (!taskRunning) {
            return;
        }
        
        const now = new Date().getTime();
        const elapsed = Math.floor((now - taskStartTime) / 1000);
        
        const hours = Math.floor(elapsed / 3600);
        const minutes = Math.floor((elapsed % 3600) / 60);
        const seconds = elapsed % 60;
        
        const hoursStr = hours.toString().padStart(2, '0');
        const minutesStr = minutes.toString().padStart(2, '0');
        const secondsStr = seconds.toString().padStart(2, '0');
        
        $('.task-time-elapsed').text(hoursStr + ':' + minutesStr + ':' + secondsStr);
    }
    
    /**
     * Show notification
     */
    function showNotification(message, type) {
        // Create a unique key for this notification
        const notificationKey = type + '-' + message;
        
        // Check if we've already shown this notification recently
        if (notificationShown[notificationKey]) {
            return;
        }
        
        // Mark this notification as shown
        notificationShown[notificationKey] = true;
        
        // Remove any existing notifications
        $('.swsib-notification').remove();
        
        // Create notification element
        const $notification = $('<div class="swsib-notification ' + type + '"></div>');
        $notification.text(message);
        
        // Append to body
        $('body').append($notification);
        
        // Animate in
        setTimeout(function() {
            $notification.addClass('show');
        }, 10);
        
        // Animate out after delay
        setTimeout(function() {
            $notification.removeClass('show');
            setTimeout(function() {
                $notification.remove();
                
                // Clear notification shown status after it's been removed
                setTimeout(function() {
                    delete notificationShown[notificationKey];
                }, 500);
            }, 500);
        }, 3000);
    }
    
    /**
     * Get readable task title
     */
    function getTaskTitle(taskId) {
        const taskMap = {
            'user_inactive': 'Remove Inactive Users',
            'user_no_apps': 'Remove Users Without Apps'
        };
        
        return taskMap[taskId] || taskId;
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        initUserManagement();
    });
    
    // Export functions to global scope for main automate.js to use
    window.swsib_user_management = {
        init: initUserManagement,
        loadCounts: loadUserCounts
    };

})(jQuery);

// Add modal CSS styles for the preview functionality
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('swsib-user-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'swsib-user-modal-styles';
        style.textContent = `
        /* Modern Modal Styles */
        .swsib-user-modal {
            display: flex;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            opacity: 0;
            transition: opacity 0.3s ease;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(3px);
        }
        
        .swsib-user-modal.show {
            opacity: 1;
        }
        
        .swsib-user-modal-content {
            position: relative;
            background-color: #fff;
            margin: auto;
            width: 90%;
            max-width: 1200px;
            max-height: 90vh;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            transform: translateY(20px);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .swsib-user-modal.show .swsib-user-modal-content {
            transform: translateY(0);
        }
        
        .swsib-user-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e4e7;
            background-color: #fff;
        }
        
        .swsib-user-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #1d2327;
        }
        
        .user-modal-close {
            background: transparent;
            border: none;
            cursor: pointer;
            color: #757575;
            font-size: 24px;
            padding: 0;
            line-height: 1;
            transition: color 0.2s ease;
            z-index: 100001; /* Ensure the close button is above other elements */
        }
        
        .user-modal-close:hover,
        .user-modal-close:focus {
            color: #d63638;
            outline: none;
        }
        
        .swsib-user-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
            background-color: #f0f0f1;
        }
        
        .swsib-user-modal-footer {
            padding: 16px 24px;
            background-color: #fff;
            border-top: 1px solid #e2e4e7;
        }
        
        .swsib-user-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-pagination-info {
            font-size: 14px;
            color: #3c434a;
            font-weight: 500;
        }
        
        .user-pagination-prev, 
        .user-pagination-next {
            min-width: 100px;
        }
        
        .user-pagination-prev:disabled,
        .user-pagination-next:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 10px;
            background-color: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .swsib-user-modal-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .swsib-user-modal-body th {
            text-align: left;
            padding: 12px 16px;
            font-weight: 600;
            background-color: #f8f9fa;
            border-bottom: 2px solid #ddd;
            color: #2c3338;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .swsib-user-modal-body td {
            padding: 10px 16px;
            border-bottom: 1px solid #e2e4e7;
            vertical-align: middle;
        }
        
        .swsib-user-modal-body tr:hover {
            background-color: #f8f9fa;
        }
        
        .swsib-user-modal-body tr:last-child td {
            border-bottom: none;
        }
        
        .swsib-user-modal-body .no-items {
            text-align: center;
            color: #757575;
            padding: 24px;
            font-style: italic;
        }
        
        .error-message {
            padding: 20px;
            background-color: #fcf0f1;
            color: #d63638;
            border-left: 4px solid #d63638;
            margin: 10px 0;
            border-radius: 3px;
            text-align: center;
        }
        
        /* Fix for task card actions */
        .task-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .task-card-actions button {
            margin: 0 0 10px 0;
        }
        `;
        document.head.appendChild(style);
    }
});