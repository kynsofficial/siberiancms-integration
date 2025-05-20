/**
 * SwiftSpeed Siberian Automation System JavaScript
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
    let activeAjaxRequests = {}; // Track active AJAX requests by taskId
    let notificationShown = {}; // Track notifications to prevent duplicates
    let localTaskLogs = {}; // Store local task logs rather than fetching from server

    // Initialize the automation system
    function initAutomation() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('.email-template-toggle').off('click');
        $('.smtp-config-toggle').off('click');
        $('select[id$="_frequency"]').off('change');
        $('.close-progress').off('click');

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

        // Toggle SMTP configuration
        $('.smtp-config-toggle').on('click', function() {
            const $configFields = $(this).closest('.smtp-config-header').siblings('.smtp-config-fields');
            $configFields.slideToggle(200);

            const $icon = $(this).find('.dashicons');
            if ($configFields.is(':visible')) {
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
            }
        });

        // Handle tab navigation without page reload
        $('.swsib-automate-tabs a').on('click', function(e) {
            e.preventDefault();

            // Get the tab ID from the href
            var tabId = $(this).attr('href').split('automate_tab=')[1];

            // Remove active class from all tabs
            $('.swsib-automate-tabs a').removeClass('active');

            // Add active class to clicked tab
            $(this).addClass('active');

            // Hide all tab content
            $('.swsib-automate-tab-content > div').hide();

            // Show the selected tab content
            $('#automate-tab-' + tabId).show();

            // Store the active tab in localStorage
            localStorage.setItem('swsib_active_automate_tab', tabId);

            // Update URL without reloading the page
            if (history.pushState) {
                var newUrl = $(this).attr('href');
                window.history.pushState({path: newUrl}, '', newUrl);
            }
            
            // Load module-specific content if needed
            if (tabId === 'actions' && window.swsib_actions && typeof window.swsib_actions.reload === 'function') {
                window.swsib_actions.reload();
            }
        });

        // Check if we have a stored active tab
        var storedTab = localStorage.getItem('swsib_active_automate_tab');
        if (storedTab) {
            $('.swsib-automate-tabs a[href*="automate_tab=' + storedTab + '"]').trigger('click');
        }

        // Initialize app management if it exists
        if (typeof window.swsib_app_management !== 'undefined' && typeof window.swsib_app_management.init === 'function') {
            window.swsib_app_management.init();
        }

        // Initialize user management if it exists
        if (typeof window.swsib_user_management !== 'undefined' && typeof window.swsib_user_management.init === 'function') {
            window.swsib_user_management.init();
        }

        // Initialize WP tasks if it exists
        if (typeof window.swsib_wp_tasks !== 'undefined' && typeof window.swsib_wp_tasks.init === 'function') {
            window.swsib_wp_tasks.init();
        }

        // Initialize Image Cleanup if it exists
        if (typeof window.swsib_image_cleanup !== 'undefined' && typeof window.swsib_image_cleanup.init === 'function') {
            window.swsib_image_cleanup.init();
        }

        // Initialize API tasks if it exists
        if (typeof window.swsib_api_tasks !== 'undefined' && typeof window.swsib_api_tasks.init === 'function') {
            window.swsib_api_tasks.init();
        }
        
        // Initialize DB Cleanup if it exists
        if (typeof window.swsib_db_cleanup !== 'undefined' && typeof window.swsib_db_cleanup.init === 'function') {
            window.swsib_db_cleanup.init();
        }

        // Load task counts - delay to prevent jerky UI when switching tabs
        setTimeout(function() {
            loadTaskCounts();
        }, 300);
    }

    /**
     * Update progress UI for simulated progress
     */
    function updateSimulatedProgress(taskId, currentStep, totalSteps, processed = 0, total = 0) {
        if (!taskRunning) return;

        // Calculate progress percentage
        const progress = Math.min(100, Math.round((currentStep / totalSteps) * 100));

        // Update progress bar
        $('.task-progress-bar').animate({
            width: progress + '%'
        }, 300);
        $('.task-progress-percentage').text(progress + '%');

        // Update processed/total if provided
        if (total > 0) {
            $('.task-processed').text(processed);
            $('.task-total').text(total);
        }

        // Store this progress in history
        progressHistory.push({
            progress: progress,
            processed: processed,
            total: total,
            current_item: $('.task-current-item').text()
        });

        // Keep history at a reasonable size
        if (progressHistory.length > 50) {
            progressHistory.shift();
        }
    }

    // Add local log - create simulation of server logs
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

    // Show notification - prevent duplicate notifications
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

    // Load task counts for cards
    function loadTaskCounts() {
        // Call app management counts if available
        if (typeof window.swsib_app_management !== 'undefined' && typeof window.swsib_app_management.loadCounts === 'function') {
            window.swsib_app_management.loadCounts();
        }

        // Call user management counts if available
        if (typeof window.swsib_user_management !== 'undefined' && typeof window.swsib_user_management.loadCounts === 'function') {
            window.swsib_user_management.loadCounts();
        }

        // Call wp tasks counts if available
        if (typeof window.swsib_wp_tasks !== 'undefined' && typeof window.swsib_wp_tasks.loadCounts === 'function') {
            window.swsib_wp_tasks.loadCounts();
        }

        // Call image cleanup counts if available
        if (typeof window.swsib_image_cleanup !== 'undefined' && typeof window.swsib_image_cleanup.loadCounts === 'function') {
            window.swsib_image_cleanup.loadCounts();
        }

        // Call API tasks counts if available
        if (typeof window.swsib_api_tasks !== 'undefined' && typeof window.swsib_api_tasks.loadCounts === 'function') {
            window.swsib_api_tasks.loadCounts();
        }
        
        // Call DB cleanup counts if available
        if (typeof window.swsib_db_cleanup !== 'undefined' && typeof window.swsib_db_cleanup.loadCounts === 'function') {
            window.swsib_db_cleanup.loadCounts();
        }
    }

    // Start task tracking
    function startTask(taskId) {
        // Set task ID
        currentTaskId = taskId;

        // Reset tracking variables
        progressHistory = [];
        retryCount = 0;
        notificationShown = {};
        localTaskLogs[taskId] = [];

        // Cancel any existing tracking for this task
        if (activeAjaxRequests[taskId]) {
            try {
                activeAjaxRequests[taskId].abort();
            } catch(e) {
                console.log('Error aborting previous request:', e);
            }
            delete activeAjaxRequests[taskId];
        }

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

    // Complete task
    function completeTask() {
        taskRunning = false;

        // Clear any existing AJAX requests for this task
        if (currentTaskId && activeAjaxRequests[currentTaskId]) {
            try {
                activeAjaxRequests[currentTaskId].abort();
            } catch(e) {
                console.log('Error aborting request:', e);
            }
            delete activeAjaxRequests[currentTaskId];
        }

        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }

        $('.task-progress-bar').css('width', '100%');
        $('.task-progress-percentage').text('100%');

        addProgressLog('Task completed successfully', 'success');

        // Reload counts after a short delay
        setTimeout(function() {
            loadTaskCounts();
        }, 1000);
        
        // Refresh action logs if available
        if (window.swsib_actions && typeof window.swsib_actions.reload === 'function') {
            setTimeout(function() {
                window.swsib_actions.reload();
            }, 1500);
        }
    }

    // Fail task
    function failTask() {
        taskRunning = false;

        // Clear any existing AJAX requests for this task
        if (currentTaskId && activeAjaxRequests[currentTaskId]) {
            try {
                activeAjaxRequests[currentTaskId].abort();
            } catch(e) {
                console.log('Error aborting request:', e);
            }
            delete activeAjaxRequests[currentTaskId];
        }

        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }

        addProgressLog('Task failed', 'error');
    }

    // Add log entry to progress log
    function addProgressLog(message, type) {
        const $log = $('.task-progress-log');
        const timestamp = new Date().toLocaleTimeString();
        const $entry = $('<div class="log-entry ' + type + '"></div>');
        $entry.text('[' + timestamp + '] ' + message);
        $log.append($entry);
        $log.scrollTop($log[0].scrollHeight);
    }

    // Update elapsed time
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

    // Get readable task title
    function getTaskTitle(taskId) {
        const taskMap = {
            'db_cleanup_sessions': 'Clear Sessions',
            'db_cleanup_mail_logs': 'Clear Mail Logs',
            'db_cleanup_source_queue': 'Clear Source Queue'
        };

        return taskMap[taskId] || taskId;
    }

    // Initialize when document is ready
    $(document).ready(function() {
        // Make sure all existing handlers are removed before initializing
        initAutomation();
    });

    // Export functions to be used by other modules
    window.swsib_automate_core = {
        startTask: startTask,
        completeTask: completeTask,
        failTask: failTask,
        addProgressLog: addProgressLog,
        showNotification: showNotification,
        addLocalLog: addLocalLog,
        updateSimulatedProgress: updateSimulatedProgress
    };

})(jQuery);