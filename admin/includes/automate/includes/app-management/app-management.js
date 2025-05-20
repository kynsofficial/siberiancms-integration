/**
 * SwiftSpeed Siberian App Management JavaScript
 */
(function($) {
    'use strict';

    // Task progress tracking
    let taskProgressInterval = null;
    let taskStartTime = 0;
    let taskRunning = false;
    let currentTaskId = null;
    let progressHistory = []; // Store progress history for better display
    let retryCount = 0; // Track retry attempts
    let maxRetries = 5; // Maximum number of retries for AJAX calls
    let notificationShown = {}; // Track notifications to prevent duplicates
    let localTaskLogs = {}; // Store local task logs rather than fetching from server
    let logHashes = {}; // Track unique logs to avoid duplicates
    let batchProcessing = false; // Flag to track if we're processing batches
    let currentBatch = 0; // Current batch being processed
    let totalBatches = 0; // Total number of batches
    let previewModal = null; // Preview modal reference
    let currentPage = 1; // Current page for data preview
    
    // Initialize the app management module
    function initAppManagement() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('.email-template-toggle').off('click');
        $('select[id$="_frequency"]').off('change');
        $('.close-progress').off('click');
        $('.run-app-management').off('click');
        $('.save-app-management-automation').off('change click');
        $('.save-subscription-size-limits').off('click');
        $('.preview-app-data-button').off('click');
        $(document).off('click', '.app-modal-close');
        $(document).off('click', '.app-pagination-prev');
        $(document).off('click', '.app-pagination-next');
        
        // Make delete immediately and send warning checkboxes mutually exclusive
        $('input[name="size_violation_apps_delete_immediately"], input[name="size_violation_apps_send_warning"]').off('change');
        $('input[name="size_violation_apps_delete_immediately"]').on('change', function() {
            if ($(this).prop('checked')) {
                $('input[name="size_violation_apps_send_warning"]').prop('checked', false);
            }
        });
        
        $('input[name="size_violation_apps_send_warning"]').on('change', function() {
            if ($(this).prop('checked')) {
                $('input[name="size_violation_apps_delete_immediately"]').prop('checked', false);
            }
        });
        
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
            
            // Don't stop the task, just hide the progress
            if (taskRunning && taskProgressInterval) {
                clearInterval(taskProgressInterval);
                taskProgressInterval = null;
                taskRunning = false;
                batchProcessing = false;
            }
        });
        
        // Run app management tasks
        $('.run-app-management').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(swsib_automate.confirm_delete_apps)) {
                return;
            }
            
            const task = $(this).data('task');
            const $button = $(this);
            const originalText = $button.text();
            
            // Reset progress tracking variables
            progressHistory = [];
            retryCount = 0;
            notificationShown = {};
            localTaskLogs = {};
            logHashes = {};
            batchProcessing = false;
            currentBatch = 0;
            totalBatches = 0;
            
            // Store original text and disable button
            $button.data('original-text', originalText);
            $button.prop('disabled', true).text(swsib_automate.task_running);
            
            // Start task progress tracking
            const taskId = 'app_management_' + task;
            startTask(taskId);
            
            // Add direct information about what we're doing
            if (task === 'zero_size') {
                addLocalLog('Starting zero size apps cleanup task', 'info');
                addLocalLog('This task will remove applications with 0 bytes size', 'info');
            } else if (task === 'inactive') {
                addLocalLog('Starting deleted apps cleanup task', 'info');
                addLocalLog('This task will remove applications that have been deleted by the user from their editor', 'info');
            } else if (task === 'size_violation') {
                addLocalLog('Starting size violation apps cleanup task', 'info');
                addLocalLog('This task will process applications that exceed their subscription size limit', 'info');
            } else if (task === 'no_users') {
                addLocalLog('Starting apps without users cleanup task', 'info');
                addLocalLog('This task will remove applications whose owners have been deleted', 'info');
            }
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_manage_apps',
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
                            
                            // Store total batches for progress calculation
                            if (response.data.batch_count) {
                                totalBatches = response.data.batch_count;
                            }
                            
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
                            // Fall back to tracking progress via AJAX polling
                            trackAppManagementProgress(task);
                        }
                    } else {
                        addLocalLog('Error: ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                        failTask();
                        
                        // Reset button
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    // Check if we have response data
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        addLocalLog('Error: ' + xhr.responseJSON.data.message, 'error');
                    } else {
                        addLocalLog('AJAX error when starting task: ' + error, 'error');
                    }
                    
                    // Still try to track progress
                    addLocalLog('Task may be running in background, continuing to monitor...', 'info');
                    
                    // Continue with progress tracking
                    trackAppManagementProgress(task);
                }
            });
        });
        
        // Save app management automation settings
        $('.save-app-management-automation').on('click', function(e) {
            e.preventDefault();
            const $form = $(this).closest('form');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            // Get specific form data by card type
            // This helps to ensure we're only saving data for a single card
            const taskType = determineCardType($form);
            
            if (!taskType) {
                console.error('Could not determine card type from form');
                $button.prop('disabled', false).text(originalText);
                return;
            }
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_app_management_automation',
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
                        
                        // Reload page after a delay as requested
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
        
        // Save subscription size limits
        $('.save-subscription-size-limits').on('click', function(e) {
            e.preventDefault();
            const $form = $(this).closest('form');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            const limits = [];
            $form.find('tr.subscription-row').each(function() {
                const $row = $(this);
                const subscriptionId = $row.data('subscription-id');
                const limit = $row.find('input[name="subscription_limit[' + subscriptionId + ']"]').val();
                
                limits.push({
                    subscription_id: subscriptionId,
                    size_limit: limit
                });
            });
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_subscription_size_limits',
                    nonce: swsib_automate.nonce,
                    limits: limits
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification
                        showNotification('Size limits saved successfully', 'success');
                        
                        // Reload page after a delay
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showNotification(response.data.message || 'Failed to save size limits', 'error');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    showNotification('Failed to save size limits: ' + error, 'error');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Preview app data buttons
        $('.preview-app-data-button').on('click', function(e) {
            e.preventDefault();
            const dataType = $(this).data('type');
            loadAppDataPreview(dataType, 1);
        });
        
        // Close modal handler
        function setupModalCloseHandlers() {
            $('.app-modal-close').off('click').on('click', function(e) {
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
        $(document).on('click', '.app-pagination-prev', function() {
            if (currentPage > 1) {
                currentPage--;
                const dataType = $(this).data('type');
                loadAppDataPreview(dataType, currentPage);
            }
        });
        
        $(document).on('click', '.app-pagination-next', function() {
            currentPage++;
            const dataType = $(this).data('type');
            loadAppDataPreview(dataType, currentPage);
        });
        
        // Load app counts - delay to prevent jerky UI when switching tabs
        setTimeout(function() {
            loadAppCounts();
        }, 300);
    }
    
    /**
     * Process the next batch
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
                action: 'swsib_process_app_batch',
                nonce: swsib_automate.nonce,
                task: task,
                batch: batchIndex
            },
            timeout: 60000, // 60-second timeout (batches can take time)
            success: function(response) {
                console.log("Batch response:", response);
                
                if (response.success) {
                    // Calculate batch-based progress
                    if (totalBatches > 0) {
                        // For batch-based progress, use the current batch / total batches
                        const batchProgress = Math.min(100, Math.round((batchIndex + 1) / totalBatches * 100));
                        $('.task-progress-bar').css('width', batchProgress + '%');
                        $('.task-progress-percentage').text(batchProgress + '%');
                    } else if (response.data && response.data.progress !== undefined) {
                        // Fallback to server-provided progress
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
                    trackAppManagementProgress(task);
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
     * Load app data preview
     */
    function loadAppDataPreview(dataType, page) {
        // Remove any existing modals first
        if (previewModal) {
            previewModal.remove();
            previewModal = null;
        }
        
        // Create modal
        previewModal = $('<div class="swsib-app-modal"></div>');
        const modalContent = $('<div class="swsib-app-modal-content"></div>');
        const modalHeader = $('<div class="swsib-app-modal-header"><h3>Data Preview</h3><button type="button" class="app-modal-close" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        const modalBody = $('<div class="swsib-app-modal-body"></div>');
        const modalFooter = $('<div class="swsib-app-modal-footer"><div class="swsib-app-pagination"><button class="button app-pagination-prev" data-type="' + dataType + '">&laquo; Previous</button><span class="app-pagination-info">Page 1</span><button class="button app-pagination-next" data-type="' + dataType + '">Next &raquo;</button></div></div>');
        
        modalContent.append(modalHeader);
        modalContent.append(modalBody);
        modalContent.append(modalFooter);
        previewModal.append(modalContent);
        $('body').append(previewModal);
        
        // Add delay before showing to allow animation
        setTimeout(function() {
            previewModal.addClass('show');
            // Set up close button handlers after modal is created
            $('.app-modal-close').off('click').on('click', function() {
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
        previewModal.find('.swsib-app-modal-body').html('<div class="loading"><div class="spinner"></div><span>Loading data...</span></div>');
        previewModal.find('.app-pagination-info').text('Loading...');
        
        // Update pagination buttons data type
        previewModal.find('.app-pagination-prev, .app-pagination-next').data('type', dataType);
        
        // Set current page
        currentPage = page;
        
        // Load data via AJAX
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_preview_app_data',
                nonce: swsib_automate.nonce,
                data_type: dataType,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    // Update modal title
                    previewModal.find('.swsib-app-modal-header h3').text(response.data.title || 'Data Preview');
                    
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
                                
                                // Format dates (common in application records)
                                if (field.includes('date') || field.includes('time') || field === 'created_at' || field === 'updated_at') {
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
                    previewModal.find('.swsib-app-modal-body').html(tableWrapper);
                    
                    // Update pagination
                    previewModal.find('.app-pagination-info').text('Page ' + page + ' of ' + response.data.total_pages);
                    
                    // Enable/disable pagination buttons
                    if (page <= 1) {
                        previewModal.find('.app-pagination-prev').prop('disabled', true);
                    } else {
                        previewModal.find('.app-pagination-prev').prop('disabled', false);
                    }
                    
                    if (page >= response.data.total_pages) {
                        previewModal.find('.app-pagination-next').prop('disabled', true);
                    } else {
                        previewModal.find('.app-pagination-next').prop('disabled', false);
                    }
                } else {
                    previewModal.find('.swsib-app-modal-body').html('<div class="error-message">Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to load data') + '</div>');
                }
            },
            error: function() {
                previewModal.find('.swsib-app-modal-body').html('<div class="error-message">Failed to load data. Please try again.</div>');
            }
        });
    }
    
    /**
     * Track app management progress
     */
    function trackAppManagementProgress(task) {
        if (!taskRunning) return;
        
        // Make AJAX call to get progress
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_app_management_progress',
                nonce: swsib_automate.nonce,
                task_type: task
            },
            timeout: 10000, // 10-second timeout
            success: function(response) {
                if (response.success) {
                    // Get progress data
                    const progressData = response.data;
                    
                    // Update progress with batch-based calculation if we have batch data
                    if (progressData.current_batch !== undefined && progressData.batch_count !== undefined) {
                        const batchProgress = Math.min(100, Math.round(progressData.current_batch / progressData.batch_count * 100));
                        $('.task-progress-bar').css('width', batchProgress + '%');
                        $('.task-progress-percentage').text(batchProgress + '%');
                        
                        // Store total batches for future calculations
                        totalBatches = progressData.batch_count;
                    } 
                    // Fallback to regular progress
                    else if (progressData.progress !== undefined) {
                        $('.task-progress-bar').css('width', progressData.progress + '%');
                        $('.task-progress-percentage').text(progressData.progress + '%');
                    }
                    
                    // Update processed and total items
                    if (progressData.total > 0) {
                        $('.task-processed').text(progressData.processed || 0);
                        $('.task-total').text(progressData.total);
                    }
                    
                    // Show processed, deleted, skipped, warned details if available
                    if ($('.task-details').length === 0) {
                        // Create details element if it doesn't exist
                        const $details = $('<div class="task-details"></div>');
                        $('.task-progress-info').append($details);
                    }
                    
                    let detailsText = '';
                    if (progressData.deleted !== undefined) {
                        detailsText += 'Deleted: ' + progressData.deleted;
                    }
                    if (progressData.skipped !== undefined) {
                        detailsText += (detailsText ? ' | ' : '') + 'Skipped: ' + progressData.skipped;
                    }
                    if (progressData.warned !== undefined) {
                        detailsText += (detailsText ? ' | ' : '') + 'Warned: ' + progressData.warned;
                    }
                    if (progressData.errors !== undefined) {
                        detailsText += (detailsText ? ' | ' : '') + 'Errors: ' + progressData.errors;
                    }
                    
                    if (detailsText) {
                        $('.task-details').text(detailsText);
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
                    
                    // If task is completed or failed and we're not batch processing, update status
                    if (progressData.status === 'completed' && !batchProcessing) {
                        completeTask();
                    } else if (progressData.status === 'failed') {
                        failTask();
                    }
                }
                
                // If we're not batch processing and task is still running, check progress again after a delay
                if (taskRunning && !batchProcessing && (!response.data.status || response.data.status === 'running')) {
                    setTimeout(function() {
                        trackAppManagementProgress(task);
                    }, 3000);
                }
            },
            error: function(xhr, status, error) {
                console.log("Error getting progress:", error);
                
                // If we're not batch processing and task is still running, check progress again after a delay
                if (taskRunning && !batchProcessing) {
                    setTimeout(function() {
                        trackAppManagementProgress(task);
                    }, 5000);
                }
            }
        });
    }
    
    /**
     * Determine the card type from the form fields
     */
    function determineCardType($form) {
        if ($form.find('#zero_size_apps_frequency').length) {
            return 'zero_size';
        } else if ($form.find('#inactive_apps_frequency').length) {
            return 'inactive';
        } else if ($form.find('#size_violation_apps_frequency').length) {
            return 'size_violation';
        } else if ($form.find('#apps_no_users_frequency').length) {
            return 'no_users';
        }
        return null;
    }

    /**
     * Load app counts
     */
    function loadAppCounts() {
        // Load zero size apps count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_zero_size_apps_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.zero-size-apps-count').text(response.data.count);
                }
            }
        });
        
        // Load inactive apps count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_inactive_apps_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.inactive-apps-count').text(response.data.count);
                }
            }
        });
        
        // Load size violation apps count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_size_violation_apps_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.size-violation-apps-count').text(response.data.count);
                }
            }
        });
        
        // Load apps without users count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_apps_without_users_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.apps-without-users-count').text(response.data.count);
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
        progressHistory = [];
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
        const taskType = currentTaskId.split('_')[2];
        const buttonSelector = '.run-app-management[data-task="' + taskType + '"]';
        
        const $button = $(buttonSelector);
        const originalText = $button.data('original-text') || 'Run Now';
        $button.prop('disabled', false).text(originalText);
        
        // Reload counts after a short delay
        setTimeout(function() {
            loadAppCounts();
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
        const taskType = currentTaskId.split('_')[2];
        const buttonSelector = '.run-app-management[data-task="' + taskType + '"]';
        
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
        if (message.indexOf('Processing') === 0) {
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
            'app_management_zero_size': 'Remove Zero Size Apps',
            'app_management_inactive': 'Remove Deleted Apps',
            'app_management_size_violation': 'Process Size Violation Apps',
            'app_management_no_users': 'Remove Apps Without Users'
        };
        
        return taskMap[taskId] || taskId;
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        initAppManagement();
    });
    
    // Export functions to global scope for main automate.js to use
    window.swsib_app_management = {
        init: initAppManagement,
        loadCounts: loadAppCounts
    };

})(jQuery);

// Add modal CSS styles for the preview functionality
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('swsib-app-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'swsib-app-modal-styles';
        style.textContent = `
        /* App Modal Styles */
        .swsib-app-modal {
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
        
        .swsib-app-modal.show {
            opacity: 1;
        }
        
        .swsib-app-modal-content {
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
        
        .swsib-app-modal.show .swsib-app-modal-content {
            transform: translateY(0);
        }
        
        .swsib-app-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e4e7;
            background-color: #fff;
        }
        
        .swsib-app-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #1d2327;
        }
        
        .app-modal-close {
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
        
        .app-modal-close:hover,
        .app-modal-close:focus {
            color: #d63638;
            outline: none;
        }
        
        .swsib-app-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
            background-color: #f0f0f1;
        }
        
        .swsib-app-modal-footer {
            padding: 16px 24px;
            background-color: #fff;
            border-top: 1px solid #e2e4e7;
        }
        
        .swsib-app-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .app-pagination-info {
            font-size: 14px;
            color: #3c434a;
            font-weight: 500;
        }
        
        .app-pagination-prev, 
        .app-pagination-next {
            min-width: 100px;
        }
        
        .app-pagination-prev:disabled,
        .app-pagination-next:disabled {
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
        
        .swsib-app-modal-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .swsib-app-modal-body th {
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
        
        .swsib-app-modal-body td {
            padding: 10px 16px;
            border-bottom: 1px solid #e2e4e7;
            vertical-align: middle;
        }
        
        .swsib-app-modal-body tr:hover {
            background-color: #f8f9fa;
        }
        
        .swsib-app-modal-body tr:last-child td {
            border-bottom: none;
        }
        
        .swsib-app-modal-body .no-items {
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
        
        /* Task card actions with preview button */
        .task-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .task-card-actions button {
            margin: 0 0 10px 0;
        }
        
        /* Task details display */
        .task-details {
            margin-top: 5px;
            font-size: 13px;
            color: #666;
            background-color: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        `;
        document.head.appendChild(style);
    }
});