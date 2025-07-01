/**
 * SwiftSpeed Siberian Image Cleanup JavaScript
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
    let maxRetries = 15; // Maximum number of retries for AJAX calls
    let notificationShown = {}; // Track notifications to prevent duplicates
    let localTaskLogs = {}; // Store local task logs rather than fetching from server
    let logHashes = {}; // Track unique logs to avoid duplicates
    let batchProcessing = false; // Flag to track if we're processing batches
    let currentBatch = 0; // Current batch being processed
    let previewModal = null; // Preview modal reference
    let currentPage = 1; // Current page for data preview
    let progressUpdateInterval = 3000; // Time between progress updates (3 seconds)
    let resumptionMessageShown = false; // Flag to track if resumption message has been shown
    
    // Initialize the image cleanup module
    function initImageCleanup() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('select[id$="_frequency"]').off('change');
        $('.run-image-cleanup').off('click');
        $('.save-image-cleanup-automation').off('click');
        $('.preview-orphaned-folders-button').off('click');
        $(document).off('click', '.image-modal-close');
        $(document).off('click', '.image-pagination-prev');
        $(document).off('click', '.image-pagination-next');
        
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
        
        // Preview orphaned folders button
        $('.preview-orphaned-folders-button').on('click', function(e) {
            e.preventDefault();
            loadFoldersPreview(1);
        });
        
        // Run image cleanup
        $('.run-image-cleanup').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(swsib_automate.confirm_clean_images)) {
                return;
            }
            
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
            startTask('image_cleanup');
            
            // Add direct information about what we're doing
            addLocalLog('Starting image cleanup task...', 'info');
            addLocalLog('This will scan for orphaned image folders and remove them', 'info');
            addLocalLog('Processing will continue in the background even if you leave this page', 'info');
            
            // Start the task with batch processing
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_cleanup_images',
                    nonce: swsib_automate.nonce,
                    mode: 'start'
                },
                timeout: 30000, // 30-second timeout
                success: function(response) {
                    if (response.success) {
                        addLocalLog('Image cleanup task initialized successfully', 'success');
                        
                        // Start tracking progress
                        startProgressTracking();
                    } else {
                        addLocalLog('Error: ' + (response.data ? response.data.message : 'Unknown error'), 'error');
                        failTask();
                        
                        // Reset button
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    addLocalLog('AJAX error when starting cleanup: ' + error, 'error');
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        addLocalLog('Server says: ' + xhr.responseJSON.data.message, 'error');
                    }
                    failTask();
                    
                    // Reset button
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Save image cleanup automation settings
        $('.save-image-cleanup-automation').on('click', function(e) {
            e.preventDefault();
            const $form = $(this).closest('form');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_image_cleanup_automation',
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
        
        // Pagination click handlers for preview
        $(document).on('click', '.image-pagination-prev', function() {
            if (currentPage > 1) {
                currentPage--;
                loadFoldersPreview(currentPage);
            }
        });
        
        $(document).on('click', '.image-pagination-next', function() {
            currentPage++;
            loadFoldersPreview(currentPage);
        });
        
        // Check if a task is currently running on page load
        checkForRunningTasks();
        
        // Load orphaned images count
        loadOrphanedImagesCount();
    }
    
    /**
     * Check if a task is currently running on page load
     */
    function checkForRunningTasks() {
        console.log("Checking for running Image Cleanup tasks...");
        
        // Check for cleanup task
        checkTaskStatus('cleanup');
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
                action: 'swsib_get_cleanup_progress',
                nonce: swsib_automate.nonce,
                task_type: task
            },
            success: function(response) {
                if (response.success) {
                    const progressData = response.data;
                    console.log("Got progress data for task:", task, progressData);
                    
                    // Check if the task is running
                    if (progressData.status === 'running' || progressData.is_running) {
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
        const taskId = 'image_cleanup';
        taskRunning = true;
        currentTaskId = taskId;
        
        // Update UI
        const $button = $('.run-image-cleanup');
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
        $('#task-progress-container .task-title').text('Task in Progress: Image Folder Cleanup');
        
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
            startProgressTracking();
        }, 1000);
    }
    
    /**
     * Start tracking progress for a task
     */
    function startProgressTracking() {
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
                trackImageCleanupProgress();
            } else {
                // Stop interval if task is no longer running
                clearInterval(taskProgressInterval);
                taskProgressInterval = null;
            }
        }, progressUpdateInterval);
        
        // Fetch initial progress update immediately
        trackImageCleanupProgress();
    }
    
    /**
     * Process the next batch
     */
    function processNextBatch(batchIndex) {
        if (!taskRunning || !batchProcessing) {
            console.log("Task not running or batch processing stopped");
            return;
        }
        
        addLocalLog('Processing batch ' + batchIndex, 'info');
        console.log("Processing batch:", batchIndex);
        
        // Make AJAX call to process batch
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_process_image_batch',
                nonce: swsib_automate.nonce,
                batch: batchIndex,
                task: 'cleanup'
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
                            processNextBatch(currentBatch);
                        }, 500);
                    } else {
                        // All batches are processed
                        console.log("All batches processed, completing task");
                        batchProcessing = false;
                        completeTask();
                    }
                    
                    // Get the latest progress
                    trackImageCleanupProgress();
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
                        processNextBatch(batchIndex);
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
     * Track image cleanup progress
     */
    function trackImageCleanupProgress() {
        if (!taskRunning) return;
        
        // Make AJAX call to get progress
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_cleanup_progress',
                nonce: swsib_automate.nonce,
                task_type: 'cleanup'
            },
            timeout: 10000, // 10-second timeout
            success: function(response) {
                if (response.success) {
                    // Reset retry counter on success
                    retryCount = 0;
                    
                    // Get progress data
                    const progressData = response.data;
                    
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
                    
                    // Show deleted, skipped, errors details if available
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
                        if (!logHashes['cleanup']) {
                            logHashes['cleanup'] = {};
                        }
                        
                        // Process each log
                        progressData.logs.forEach(function(log) {
                            if (!log.message) return; // Skip empty messages
                            
                            // Create a unique hash for this log
                            const logHash = log.time + '-' + log.message.substring(0, 50);
                            
                            // Only add if we haven't shown this exact log yet
                            if (!logHashes['cleanup'][logHash]) {
                                const timestamp = new Date(log.time * 1000).toLocaleTimeString();
                                addProgressLog(log.message, log.type || 'info', timestamp);
                                logHashes['cleanup'][logHash] = true;
                            }
                        });
                    }
                    
                    // If task is no longer running or the background processing was disabled, check status
                    if (!progressData.is_running || !progressData.background_enabled) {
                        if (progressData.status === 'running') {
                            // Task is in an inconsistent state - either stalled or just initialized
                            // Check heartbeat age
                            const heartbeatAge = progressData.heartbeat_age || 0;
                            
                            if (heartbeatAge > 300) { // 5 minutes
                                // Task has stalled
                                addLocalLog('Task appears to be stalled (no updates for ' + Math.floor(heartbeatAge / 60) + ' minutes)', 'warning');
                            }
                        }
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
                
                // If we're not batch processing and task is still running, check progress again after a delay
                if (taskRunning && !batchProcessing && response.data && response.data.status === 'running') {
                    // Progress will be checked by the interval timer
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
            }
        });
    }
    
    /**
     * Load folders preview
     */
    function loadFoldersPreview(page) {
        // Remove any existing modals first
        if (previewModal) {
            previewModal.remove();
            previewModal = null;
        }
        
        // Create modal
        previewModal = $('<div class="swsib-image-modal"></div>');
        const modalContent = $('<div class="swsib-image-modal-content"></div>');
        const modalHeader = $('<div class="swsib-image-modal-header"><h3>Orphaned Image Folders Preview</h3><button type="button" class="image-modal-close" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>');
        const modalBody = $('<div class="swsib-image-modal-body"></div>');
        const modalFooter = $('<div class="swsib-image-modal-footer"><div class="swsib-image-pagination"><button class="button image-pagination-prev">&laquo; Previous</button><span class="image-pagination-info">Page 1</span><button class="button image-pagination-next">Next &raquo;</button></div></div>');
        
        modalContent.append(modalHeader);
        modalContent.append(modalBody);
        modalContent.append(modalFooter);
        previewModal.append(modalContent);
        $('body').append(previewModal);
        
        // Add delay before showing to allow animation
        setTimeout(function() {
            previewModal.addClass('show');
            // Set up close button handlers after modal is created
            $('.image-modal-close').off('click').on('click', function() {
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
        previewModal.find('.swsib-image-modal-body').html('<div class="loading"><div class="spinner"></div><span>Loading folders...</span></div>');
        previewModal.find('.image-pagination-info').text('Loading...');
        
        // Set current page
        currentPage = page;
        
        // Load data via AJAX
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_preview_orphaned_folders',
                nonce: swsib_automate.nonce,
                page: page,
                per_page: 10
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Update modal title with counts
                    if (response.data.orphaned_count !== undefined && response.data.non_app_count !== undefined) {
                        previewModal.find('.swsib-image-modal-header h3').text(
                            'Orphaned Image Folders Preview (' + response.data.orphaned_count + ' orphaned, ' + 
                            response.data.non_app_count + ' non-application)'
                        );
                    } else {
                        previewModal.find('.swsib-image-modal-header h3').text(response.data.title || 'Orphaned Image Folders Preview');
                    }
                    
                    // Create table with responsive wrapper
                    const tableWrapper = $('<div class="table-responsive"></div>');
                    const table = $('<table class="wp-list-table widefat striped"></table>');
                    const thead = $('<thead></thead>');
                    const tbody = $('<tbody></tbody>');
                    
                    // Add headers
                    const headerRow = $('<tr></tr>');
                    if (response.data.headers && Array.isArray(response.data.headers)) {
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
                                    
                                    // Apply status formatting
                                    if (field === 'status') {
                                        if (cellValue.includes('Orphaned')) {
                                            cellValue = '<span class="status-orphaned">' + cellValue + '</span>';
                                        } else if (cellValue.includes('Non-Application')) {
                                            cellValue = '<span class="status-non-app">' + cellValue + '</span>';
                                        }
                                    }
                                    
                                    row.append('<td>' + cellValue + '</td>');
                                });
                                tbody.append(row);
                            });
                        } else {
                            // No items found
                            const emptyRow = $('<tr><td colspan="' + response.data.headers.length + '" class="no-items">No orphaned folders found</td></tr>');
                            tbody.append(emptyRow);
                        }
                        
                        table.append(tbody);
                        tableWrapper.append(table);
                        
                        // Add to modal
                        previewModal.find('.swsib-image-modal-body').html(tableWrapper);
                        
                        // Update pagination
                        previewModal.find('.image-pagination-info').text('Page ' + page + ' of ' + response.data.total_pages);
                        
                        // Enable/disable pagination buttons
                        if (page <= 1) {
                            previewModal.find('.image-pagination-prev').prop('disabled', true);
                        } else {
                            previewModal.find('.image-pagination-prev').prop('disabled', false);
                        }
                        
                        if (page >= response.data.total_pages) {
                            previewModal.find('.image-pagination-next').prop('disabled', true);
                        } else {
                            previewModal.find('.image-pagination-next').prop('disabled', false);
                        }
                    } else {
                        previewModal.find('.swsib-image-modal-body').html('<div class="error-message">Error: Invalid response format</div>');
                    }
                } else {
                    previewModal.find('.swsib-image-modal-body').html('<div class="error-message">Error: ' + (response.data && response.data.message ? response.data.message : 'Failed to load folders') + '</div>');
                }
            },
            error: function() {
                previewModal.find('.swsib-image-modal-body').html('<div class="error-message">Failed to load folders. Please try again.</div>');
            }
        });
    }
    
    /**
     * Load orphaned images count
     */
    function loadOrphanedImagesCount() {
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_orphaned_images_count',
                nonce: swsib_automate.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.orphaned-images-count').text(response.data.count);
                } else {
                    console.error('Error getting orphaned images count:', response.data.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error getting orphaned images count:', error);
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
        $('.task-title').text('Task in Progress: Image Folder Cleanup');
        
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
        const $button = $('.run-image-cleanup');
        const originalText = $button.data('original-text') || 'Run Cleanup';
        $button.prop('disabled', false).text(originalText);
        
        // Reload counts after a short delay
        setTimeout(function() {
            loadOrphanedImagesCount();
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
        const $button = $('.run-image-cleanup');
        const originalText = $button.data('original-text') || 'Run Cleanup';
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
        if (message.indexOf('Processing') === 0 || message.indexOf('Deleting') === 0) {
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
        if (window.swsib_automate_core && typeof window.swsib_automate_core.showNotification === 'function') {
            window.swsib_automate_core.showNotification(message, type);
            return;
        }
        
        // Fallback implementation if core function is not available
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
    
    // Initialize when document is ready
    $(document).ready(function() {
        initImageCleanup();
    });
    
    // Export functions to global scope for main automate.js to use
    window.swsib_image_cleanup = {
        init: initImageCleanup,
        loadCounts: loadOrphanedImagesCount
    };

})(jQuery);

// Add modal CSS styles for the preview functionality
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('swsib-image-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'swsib-image-modal-styles';
        style.textContent = `
        /* Modern Modal Styles */
        .swsib-image-modal {
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
        
        .swsib-image-modal.show {
            opacity: 1;
        }
        
        .swsib-image-modal-content {
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
        
        .swsib-image-modal.show .swsib-image-modal-content {
            transform: translateY(0);
        }
        
        .swsib-image-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            border-bottom: 1px solid #e2e4e7;
            background-color: #fff;
        }
        
        .swsib-image-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            color: #1d2327;
        }
        
        .image-modal-close {
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
        
        .image-modal-close:hover,
        .image-modal-close:focus {
            color: #d63638;
            outline: none;
        }
        
        .swsib-image-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
            background-color: #f0f0f1;
        }
        
        .swsib-image-modal-footer {
            padding: 16px 24px;
            background-color: #fff;
            border-top: 1px solid #e2e4e7;
        }
        
        .swsib-image-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .image-pagination-info {
            font-size: 14px;
            color: #3c434a;
            font-weight: 500;
        }
        
        .image-pagination-prev, 
        .image-pagination-next {
            min-width: 100px;
        }
        
        .image-pagination-prev:disabled,
        .image-pagination-next:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Status colors */
        .status-orphaned {
            color: #d63638;
            font-weight: 600;
        }
        
        .status-non-app {
            color: #3582c4;
            font-style: italic;
        }
        
        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            margin-bottom: 10px;
            background-color: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .swsib-image-modal-body table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .swsib-image-modal-body th {
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
        
        .swsib-image-modal-body td {
            padding: 10px 16px;
            border-bottom: 1px solid #e2e4e7;
            vertical-align: middle;
        }
        
        .swsib-image-modal-body tr:hover {
            background-color: #f8f9fa;
        }
        
        .swsib-image-modal-body tr:last-child td {
            border-bottom: none;
        }
        
        .swsib-image-modal-body .no-items {
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
        
        /* Preview button style */
        .preview-orphaned-folders-button {
            margin-right: 10px;
        }
        `;
        document.head.appendChild(style);
    }
});