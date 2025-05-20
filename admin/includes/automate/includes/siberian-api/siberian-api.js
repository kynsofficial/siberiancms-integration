/**
 * SwiftSpeed Siberian API JavaScript
 */
(function($) {
    'use strict';

    // Task progress tracking
    let taskProgressInterval = null;
    let taskStartTime = 0;
    let taskRunning = false;
    let currentTaskId = null;
    let notificationShown = {};
    
    // Initialize the API module
    function initSiberianApi() {
        // Clear any previous event handlers to prevent duplicates
        $('.task-settings-toggle').off('click');
        $('select[id^="api_"][id$="_frequency"]').off('change');
        $('.run-api-command').off('click');
        $('.save-api-automation').off('click');
        
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
        $('select[id^="api_"][id$="_frequency"]').on('change', function() {
            const customContainer = $(this).closest('.task-settings-field-group').find('.custom-frequency-container');
            if ($(this).val() === 'custom') {
                customContainer.slideDown(200);
            } else {
                customContainer.slideUp(200);
            }
        });
        
        // Run API command
        $('.run-api-command').on('click', function() {
            if (!confirm(swsib_automate.confirm_run_task)) {
                return;
            }
            
            const command = $(this).data('command');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text(swsib_automate.task_running);
            
            // Start task progress tracking
            const taskId = 'api_' + command;
            startTask(taskId);
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_run_api_command',
                    nonce: swsib_automate.nonce,
                    command: command
                },
                success: function(response) {
                    if (response.success) {
                        addProgressLog('Command executed successfully', 'success');
                        
                        if (response.data && response.data.output) {
                            const output = response.data.output;
                            
                            if (output.message) {
                                addProgressLog('API Response: ' + output.message, 'info');
                            }
                            
                            if (output.success) {
                                completeTask();
                            } else {
                                failTask();
                            }
                        } else {
                            completeTask();
                        }
                    } else {
                        addProgressLog('Error: ' + (response.data.message || 'Unknown error'), 'error');
                        failTask();
                    }
                    
                    $button.prop('disabled', false).text(originalText);
                },
                error: function(xhr, status, error) {
                    addProgressLog('Error: ' + error, 'error');
                    failTask();
                    
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Save API automation settings
        $('.save-api-automation').on('click', function() {
            const $form = $(this).closest('form');
            const command = $form.data('command');
            const $button = $(this);
            const originalText = $button.text();
            
            $button.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: swsib_automate.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_save_api_automation',
                    nonce: swsib_automate.nonce,
                    command: command,
                    settings: $form.serialize()
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification
                        showNotification('Settings saved successfully', 'success');
                        
                        // Update UI to reflect new settings
                        const $taskCard = $form.closest('.task-card');
                        const $enableCheckbox = $form.find('input[name^="api_' + command + '_enabled"]');
                        
                        if ($enableCheckbox.length > 0) {
                            const isEnabled = $enableCheckbox.prop('checked');
                            const $badge = $taskCard.find('.task-card-badge');
                            
                            if (isEnabled) {
                                $badge.addClass('active').text('Automated');
                            } else {
                                $badge.removeClass('active').text('Manual');
                            }
                        }
                    } else {
                        showNotification(response.data.message || 'Failed to save settings', 'error');
                    }
                    
                    $button.prop('disabled', false).text(originalText);
                },
                error: function(xhr, status, error) {
                    showNotification('Failed to save settings: ' + error, 'error');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }
    
    /**
     * Start task tracking
     */
    function startTask(taskId) {
        // Set task ID
        currentTaskId = taskId;
        
        // Reset tracking variables
        notificationShown = {};
        
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
        
        // Add initial log
        addProgressLog('Starting ' + getTaskTitle(taskId), 'info');
    }
    
    /**
     * Complete task
     */
    function completeTask() {
        taskRunning = false;
        
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }
        
        $('.task-progress-bar').css('width', '100%');
        $('.task-progress-percentage').text('100%');
        
        addProgressLog('Task completed successfully', 'success');
    }
    
    /**
     * Fail task
     */
    function failTask() {
        taskRunning = false;
        
        if (taskProgressInterval) {
            clearInterval(taskProgressInterval);
            taskProgressInterval = null;
        }
        
        addProgressLog('Task failed or was interrupted', 'error');
    }
    
    /**
     * Add log entry to progress log
     */
    function addProgressLog(message, type) {
        const $log = $('.task-progress-log');
        const timestamp = new Date().toLocaleTimeString();
        const $entry = $('<div class="log-entry ' + type + '"></div>');
        $entry.text('[' + timestamp + '] ' + message);
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
            'api_manifest': 'Manifest Rebuild',
            'api_clearcache': 'Clear Cache',
            'api_cleartmp': 'Clear Tmp',
            'api_clearlogs': 'Clear Logs'
        };
        
        return taskMap[taskId] || taskId;
    }
    
    // Initialize when document is ready
    $(document).ready(function() {
        initSiberianApi();
    });
    
    // Export functions to global scope for main automate.js to use
    window.swsib_siberian_api = {
        init: initSiberianApi
    };

})(jQuery);

// Add notification CSS styles if not already in the page
document.addEventListener('DOMContentLoaded', function() {
    if (!document.getElementById('swsib-api-notification-styles')) {
        const style = document.createElement('style');
        style.id = 'swsib-api-notification-styles';
        style.textContent = `
        .swsib-notification {
            position: fixed;
            top: 50px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 100000;
            font-size: 14px;
            max-width: 300px;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateX(100%);
            opacity: 0;
        }
        
        .swsib-notification.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .swsib-notification.success {
            background-color: #46b450;
            color: #fff;
        }
        
        .swsib-notification.error {
            background-color: #dc3232;
            color: #fff;
        }
        
        .swsib-notification.info {
            background-color: #00a0d2;
            color: #fff;
        }
        
        .swsib-notification.warning {
            background-color: #ffb900;
            color: #fff;
        }
        `;
        document.head.appendChild(style);
    }
});