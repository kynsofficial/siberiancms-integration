/**
 * SwiftSpeed Siberian Database Cleanup JavaScript
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
    let progressUpdateInterval = 3000; // Time between progress updates (3 seconds)
    let resumptionMessageShown = false; // Flag to track if resumption message has been shown
    
    // Initialize the DB cleanup module
    function initDbCleanup() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('select[id$="_frequency"]').off('change');
        $('.close-progress').off('click');
        $('.run-db-cleanup').off('click');
        $('.save-db-cleanup-automation').off('click');
        $('.preview-db-data-button').off('click');
        $(document).off('click', '.db-modal-close');
        $(document).off('click', '.db-pagination-prev');
        $(document).off('click', '.db-pagination-next');
        
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
        
        // Run database cleanup tasks
        $('.run-db-cleanup').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(swsib_automate.confirm_run_task)) {
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
            resumptionMessageShown = false;
            
            // Store original text and disable button
            $button.data('original-text', originalText);
            $button.prop('disabled', true).text(swsib_automate.task_running);
            
            // Start task progress tracking
            const taskId = 'db_cleanup_' + task;
            startTask(taskId);
            
            // Add direct information about what we're doing
            addLocalLog('Starting database cleanup task: ' + task, 'info');
            if (task === 'sessions') {
                addLocalLog('This task will clean up expired session data in the database', 'info');
            } else if (task === 'mail_logs') {
                addLocalLog('This task will clean up old mail logs from the database', 'info');
            } else if (task === 'source_queue') {
                addLocalLog('This task will clean up old entries from the source queue', 'info');
            } else if (task === 'optimize') {
                addLocalLog('This task will optimize database tables to improve performance', 'info');
            } else if (task === 'backoffice_alerts') {
                addLocalLog('This task will clean up old backoffice alert notifications', 'info');
            } else if (task === 'cleanup_log') {
                addLocalLog('This task will clean up old entries in the cleanup log', 'info');
            }
            
            // Start the task via AJAX
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_cleanup_database',
                    nonce: swsib_automate.nonce,
                    task: task,
                    mode: 'start'
                },
                timeout: 30000, // 30-second timeout
                success: function(response) {
                    if (response.success) {
                        addLocalLog('Task initialized successfully on server', 'success');
                        addLocalLog('Processing will continue in the background even if you leave this page', 'info');
                        
                        // Start tracking progress
                        startProgressTracking(task);
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
                    addLocalLog('Task may be running in background, continuing to monitor...', 'info');
                    
                    // Start tracking progress anyway
                    startProgressTracking(task);
                }
            });
        });

        // Save database cleanup automation settings
        $('.save-db-cleanup-automation').on('click', function(e) {
            e.preventDefault();
            const $form = $(this).closest('form');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_db_cleanup_automation',
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
        $('.preview-db-data-button').on('click', function(e) {
            e.preventDefault();
            const dataType = $(this).data('type');
            loadDataPreview(dataType, 1);
        });
        
        // Close modal handler - using direct binding instead of delegation
        function setupModalCloseHandlers() {
            $('.db-modal-close').off('click').on('click', function(e) {
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
        $(document).on('click', '.db-pagination-prev', function() {
            if (currentPage > 1) {
                currentPage--;
                const dataType = $(this).data('type');
                loadDataPreview(dataType, currentPage);
            }
        });
        
        $(document).on('click', '.db-pagination-next', function() {
            currentPage++;
            const dataType = $(this).data('type');
            loadDataPreview(dataType, currentPage);
        });
        
        // Check if a task is currently running on page load
        checkForRunningTasks();
        
        // Load task counts - delay to prevent jerky UI when switching tabs
        setTimeout(function() {
            loadTaskCounts();
        }, 300);
    }
    
    /**
     * Check if a task is currently running on page load
     */
    function checkForRunningTasks() {
        console.log("Checking for running database cleanup tasks...");
        
        // Check for all possible tasks
        checkTaskStatus('sessions');
        checkTaskStatus('mail_logs');
        checkTaskStatus('source_queue');
        checkTaskStatus('optimize');
        checkTaskStatus('backoffice_alerts');
        checkTaskStatus('cleanup_log');
    }
    
    /**
     * Check if a specific task is running
     */
    function checkTaskStatus(task) {
        console.log("Checking status for task:", task);
        
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_db_cleanup_progress',
                nonce: swsib_automate.nonce,
                task_type: task
            },
            success: function(response) {
                if (response.success) {
                    const progressData = response.data;
                    console.log("Got progress data for task:", task, progressData);
                    
                    // Check if the task is running
                    if (progressData.status === 'running') {
                        console.log('Found running task:', task);
                        
                        // Resume progress tracking
                        resumeTaskTracking(task, progressData);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log("Error checking task status for " + task + ":", error);
                // Don't show any visible error to the user
            }
        });
    }
    
    /**
     * Resume tracking a task that was already running
     */
    function resumeTaskTracking(task, progressData) {
        // Set task as running
        const taskId = 'db_cleanup_' + task;
        taskRunning = true;
        currentTaskId = taskId;
        
        // Update UI
        const $button = $('.run-db-cleanup[data-task="' + task + '"]');
        $button.prop('disabled', true).text(swsib_automate.task_running);
        
        // Show progress container
        $('#task-progress-container').show();
        
        // Update progress bar
        const progress = progressData.progress || 0;
        $('#task-progress-container .task-progress-bar').css('width', progress + '%');
        $('#task-progress-container .task-progress-percentage').text(progress + '%');
        
        // Update processed/total
        if (progressData.total > 0) {
            $('#task-progress-container .task-processed').text(progressData.processed || 0);
            $('#task-progress-container .task-total').text(progressData.total);
        }
        
        // Update current item
        if (progressData.current_item) {
            $('#task-progress-container .task-current-item').text(progressData.current_item);
        }
        
        // Update title
        $('#task-progress-container .task-title').text('Task in Progress: ' + getTaskTitle(taskId));
        
        // Only add resumption log once
        if (!resumptionMessageShown) {
            // Add resumption log
            const timestamp = new Date().toLocaleTimeString();
            const $log = $('#task-progress-container .task-progress-log');
            const $entry = $('<div class="log-entry info"></div>');
            $entry.text('[' + timestamp + '] Resumed task tracking after page reload');
            $log.append($entry);
            resumptionMessageShown = true;
        }
        
        // Clear existing logs to avoid duplicates
        logHashes[task] = {};
        
        // Display existing logs if any
        if (progressData.logs && progressData.logs.length > 0) {
            const $log = $('#task-progress-container .task-progress-log');
            
            progressData.logs.forEach(function(log) {
                if (!log.message) return; // Skip empty messages
                
                // Create a unique hash for this log
                const logHash = log.time + '-' + log.message.substring(0, 50);
                
                // Only add if we haven't shown this exact log yet
                if (!logHashes[task] || !logHashes[task][logHash]) {
                    const timestamp = new Date(log.time * 1000).toLocaleTimeString();
                    const $entry = $('<div class="log-entry ' + (log.type || 'info') + '"></div>');
                    $entry.text('[' + timestamp + '] ' + log.message);
                    $log.append($entry);
                    
                    // Store this log hash
                    if (!logHashes[task]) {
                        logHashes[task] = {};
                    }
                    logHashes[task][logHash] = true;
                }
            });
            
            // Auto-scroll to bottom
            $log.scrollTop($log[0].scrollHeight);
        }
        
        // Start progress tracking with a delay to avoid immediate hammering
        setTimeout(function() {
            startProgressTracking(task);
        }, 1000);
    }
    
    /**
     * Start tracking progress for a task
     */
    function startProgressTracking(task) {
        // Set task start time
        taskStartTime = new Date().getTime();
        
        // Start elapsed time counter
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
        }
        
        taskProgressInterval = setInterval(function() {
            if (taskRunning) {
                // Update elapsed time
                updateElapsedTime();
                
                // Fetch progress updates
                trackDbCleanupProgress(task);
            } else {
                // Stop interval if task is no longer running
                clearInterval(taskProgressInterval);
                taskProgressInterval = null;
            }
        }, progressUpdateInterval);
        
        // Fetch initial progress update immediately
        trackDbCleanupProgress(task);
    }
    
    /**
     * Load data preview
     */
    function loadDataPreview(dataType, page) {
        // Remove any existing modals first
        if (previewModal) {
            previewModal.remove();
            previewModal = null;
        }
        
        // Create modal
        previewModal = $('<div class="swsib-db-modal"></div>');
        const modalContent = $('<div class="swsib-db-modal-content"></div>');
        const modalHeader = $('<div class="swsib-db-modal-header"><h3>Data Preview</h3><button type="button" class="db-modal-close" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        const modalBody = $('<div class="swsib-db-modal-body"></div>');
        const modalFooter = $('<div class="swsib-db-modal-footer"><div class="swsib-db-pagination"><button class="button db-pagination-prev" data-type="' + dataType + '">&laquo; Previous</button><span class="db-pagination-info">Page 1</span><button class="button db-pagination-next" data-type="' + dataType + '">Next &raquo;</button></div></div>');
        
        modalContent.append(modalHeader);
        modalContent.append(modalBody);
        modalContent.append(modalFooter);
        previewModal.append(modalContent);
        $('body').append(previewModal);
        
        // Add delay before showing to allow animation
        setTimeout(function() {
            previewModal.addClass('show');
            // Set up close button handlers after modal is created
            $('.db-modal-close').off('click').on('click', function() {
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
        previewModal.find('.swsib-db-modal-body').html('<div class="loading"><div class="spinner"></div><span>Loading data...</span></div>');
        previewModal.find('.db-pagination-info').text('Loading...');
        
        // Update pagination buttons data type
        previewModal.find('.db-pagination-prev, .db-pagination-next').data('type', dataType);
        
        // Set current page
        currentPage = page;
        
        // Load data via AJAX
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_preview_db_data',
                nonce: swsib_automate.nonce,
                data_type: dataType,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    // Update modal title
                    previewModal.find('.swsib-db-modal-header h3').text(response.data.title || 'Data Preview');
                    
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
                                if (field.includes('date') || field.includes('time') || field === 'created_at' || field === 'modified' || field === 'executed_at') {
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
                    previewModal.find('.swsib-db-modal-body').html(tableWrapper);
                    
                    // Update pagination
                    previewModal.find('.db-pagination-info').text('Page ' + page + ' of ' + response.data.total_pages);
                    
                    // Enable/disable pagination buttons
                    if (page <= 1) {
                        previewModal.find('.db-pagination-prev').prop('disabled', true);
                    } else {
                        previewModal.find('.db-pagination-prev').prop('disabled', false);
                    }
                    
                    if (page >= response.data.total_pages) {
                        previewModal.find('.db-pagination-next').prop('disabled', true);
                    } else {
                        previewModal.find('.db-pagination-next').prop('disabled', false);
                    }
                } else {
                    previewModal.find('.swsib-db-modal-body').html('<div class="error-message">Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to load data') + '</div>');
                }
            },
            error: function() {
                previewModal.find('.swsib-db-modal-body').html('<div class="error-message">Failed to load data. Please try again.</div>');
            }
        });
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
                action: 'swsib_process_db_batch',
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
                    trackDbCleanupProgress(task);
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
     * Track database cleanup progress
     */
    function trackDbCleanupProgress(task) {
        if (!taskRunning) return;
        
        console.log("Tracking progress for task:", task);
        
        // Make AJAX call to get progress
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_db_cleanup_progress',
                nonce: swsib_automate.nonce,
                task_type: task
            },
            timeout: 10000, // 10-second timeout
            success: function(response) {
                if (response.success) {
                    // Reset retry counter on success
                    retryCount = 0;
                    
                    // Get progress data
                    const progressData = response.data;
                    console.log("Progress data:", progressData);
                    
                    // Check if task is completed or cancelled
                    if (progressData.status === 'completed') {
                        completeTask();
                        return;
                    } else if (progressData.status === 'cancelled') {
                        addLocalLog('Task was cancelled', 'warning');
                        failTask();
                        return;
                    }
                    
                    // Update progress with real data
                    const progress = progressData.progress || 0;
                    $('.task-progress-bar').css('width', progress + '%');
                    $('.task-progress-percentage').text(progress + '%');
                    
                    if (progressData.total > 0) {
                        $('.task-processed').text(progressData.processed || 0);
                        $('.task-total').text(progressData.total);
                    }
                    
                    // Show details about deleted/optimized items if available
                    if ($('.task-details').length === 0) {
                        // Create details element if it doesn't exist
                        const $details = $('<div class="task-details"></div>');
                        $('.task-progress-info').append($details);
                    }
                    
                    let detailsText = '';
                    if (progressData.deleted !== undefined) {
                        if (task === 'optimize') {
                            detailsText += 'Optimized: ' + progressData.deleted;
                        } else {
                            detailsText += 'Deleted: ' + progressData.deleted;
                        }
                    }
                    if (progressData.skipped !== undefined) {
                        detailsText += (detailsText ? ' | ' : '') + 'Skipped: ' + progressData.skipped;
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
                    
                    // Check if the task is actually running (has a recent heartbeat)
                    if (progressData.last_update && (Date.now() / 1000 - progressData.last_update > 300)) {
                        // More than 5 minutes since the last update - task might be stalled
                        addLocalLog('Task appears to be stalled (no updates for more than 5 minutes)', 'warning');
                    }
                    
                    // Check if task is completed and we're not batch processing
                    if (progressData.status === 'completed' && !batchProcessing) {
                        completeTask();
                        return;
                    }
                } else {
                    console.log("Error getting progress:", response.data ? response.data.message : 'Unknown error');
                    
                    // Increment retry counter
                    retryCount++;
                    
                    if (retryCount >= maxRetries) {
                        // Too many failures, assume task is no longer running
                        addLocalLog('Failed to get progress updates after multiple attempts. Task may have stopped.', 'error');
                        failTask();
                    }
                }
                
                // If task is still running and not batch processing, check progress again after delay
                if (taskRunning && !batchProcessing && response.data.status === 'running') {
                    // Progress tracking will continue via interval timer
                }
            },
            error: function(xhr, status, error) {
                console.log("Error getting progress:", error);
                
                // Increment retry counter
                retryCount++;
                
                if (retryCount >= maxRetries) {
                    // Too many failures, assume task is no longer running
                    addLocalLog('Failed to get progress updates after multiple attempts. Task may have stopped.', 'error');
                    failTask();
                }
                
                // If task is still running and not batch processing, continue to check progress via interval
            }
        });
    }
    
    /**
     * Load task counts
     */
    function loadTaskCounts() {
        // Load sessions count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_sessions_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.sessions-count').text(response.data.count);
                }
            }
        });
        
        // Load mail logs count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_mail_logs_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.mail-logs-count').text(response.data.count);
                }
            }
        });
        
        // Load source queue count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_source_queue_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.source-queue-count').text(response.data.count);
                }
            }
        });
        
        // Load backoffice alerts count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_backoffice_alerts_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.backoffice-alerts-count').text(response.data.count);
                }
            }
        });
        
        // Load cleanup log count
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_cleanup_log_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.cleanup-log-count').text(response.data.count);
                }
            }
        });
        
        // Load optimize tables data
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_optimize_tables_info',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.tables-count').text(response.data.count);
                    $('.tables-size').text(response.data.size);
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
        resumptionMessageShown = false;
        
        // Reset progress UI
        $('.task-progress-bar').css('width', '0%');
        $('.task-progress-percentage').text('0%');
        $('.task-processed').text('0');
        $('.task-total').text('0');
        $('.task-time-elapsed').text('00:00:00');
        $('.task-current-item').text('');
        $('.task-progress-log').empty();
        
        // Reset progress bar color
        $('.task-progress-bar').css('background-color', '#2271b1');
        
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
        const taskType = currentTaskId.split('_')[2];
        const buttonSelector = '.run-db-cleanup[data-task="' + taskType + '"]';
        
        const $button = $(buttonSelector);
        const originalText = $button.data('original-text') || 'Run Now';
        $button.prop('disabled', false).text(originalText);
        
        // Reload counts after a short delay
        setTimeout(function() {
            loadTaskCounts();
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
        const buttonSelector = '.run-db-cleanup[data-task="' + taskType + '"]';
        
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
            'db_cleanup_sessions': 'Sessions Cleanup',
            'db_cleanup_mail_logs': 'Mail Logs Cleanup',
            'db_cleanup_source_queue': 'Source Queue Cleanup',
            'db_cleanup_optimize': 'Database Optimization',
            'db_cleanup_backoffice_alerts': 'Backoffice Alerts Cleanup',
            'db_cleanup_cleanup_log': 'Cleanup Log Maintenance'
        };
        
        return taskMap[taskId] || taskId;
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        initDbCleanup();
    });
    
    // Export functions to global scope for main automate.js to use
    window.swsib_db_cleanup = {
        init: initDbCleanup,
        loadCounts: loadTaskCounts
    };

})(jQuery);

// Add modal CSS styles for the preview functionality
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('swsib-db-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'swsib-db-modal-styles';
        style.textContent = `
        /* Modern Modal Styles */
        .swsib-db-modal {
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
        
        .swsib-db-modal.show {
            opacity: 1;
        }
        
        .swsib-db-modal-content {
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
        
        .swsib-db-modal.show .swsib-db-modal-content {
            transform: translateY(0);
        }
        
        .swsib-db-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e4e7;
            background-color: #fff;
        }
        
        .swsib-db-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #1d2327;
        }
        
        .db-modal-close {
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
        
        .db-modal-close:hover,
        .db-modal-close:focus {
            color: #d63638;
            outline: none;
        }
        
        .swsib-db-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
            background-color: #f0f0f1;
        }
        
        .swsib-db-modal-footer {
            padding: 16px 24px;
            background-color: #fff;
            border-top: 1px solid #e2e4e7;
        }
        
        .swsib-db-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .db-pagination-info {
            font-size: 14px;
            color: #3c434a;
            font-weight: 500;
        }
        
        .db-pagination-prev, 
        .db-pagination-next {
            min-width: 100px;
        }
        
        .db-pagination-prev:disabled,
        .db-pagination-next:disabled {
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
        
        .swsib-db-modal-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .swsib-db-modal-body th {
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
        
        .swsib-db-modal-body td {
            padding: 10px 16px;
            border-bottom: 1px solid #e2e4e7;
            vertical-align: middle;
        }
        
        .swsib-db-modal-body tr:hover {
            background-color: #f8f9fa;
        }
        
        .swsib-db-modal-body tr:last-child td {
            border-bottom: none;
        }
        
        .swsib-db-modal-body .no-items {
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