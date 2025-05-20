/**
 * SwiftSpeed Siberian Integration
 * Backup & Restore Scripts - Performance Optimized Version
 */
jQuery(document).ready(function($) {
    'use strict';

    // Global state tracking - simplified for better performance
    const state = {
        pollingActive: false,
        pollingInterval: null,
        pollingTimeout: null,
        pollingDelay: 2000, // 2 seconds between updates
        lastProgressUpdate: 0,
        backgroundMode: false,
        currentBackupId: null,
        currentRestoreId: null,
        lastStatus: null,
        speedHistory: [],
        startTime: 0,
        // Restore-specific tracking
        restorePollingActive: false,
        restorePollingTimeout: null,
        restoreLastStatus: null,
        restoreSpeedHistory: [],
        restoreStartTime: 0
    };

    // Lazy loading flag to defer non-critical operations
    let uiFullyInitialized = false;
    
    // Initialize only critical components immediately
    initCriticalComponents();
    
    // Defer non-critical initialization
    setTimeout(initRemainingComponents, 100);

    /**
     * Initialize critical components only (for faster initial load)
     */
    function initCriticalComponents() {
        // Initialize tabs with minimal overhead
        initTabs();
        
        // Handle hash in URL for direct tab access
        const hash = window.location.hash;
        if (hash) {
            const tabId = hash.substring(1);
            $('.siberian-tabs-nav .subsubsub a[href="#' + tabId + '"]').click();
        }
        
        // Only bind critical event handlers initially
        $('.siberian-tabs-nav .subsubsub a').on('click', handleTabClick);
        $('#siberian-start-backup').on('click', startBackup);
        $('#siberian-cancel-backup').on('click', cancelBackup);
        $('#siberian-cancel-restore').on('click', cancelRestore);
    }
    
    /**
     * Initialize remaining components after critical UI is loaded
     */
    function initRemainingComponents() {
        bindRemainingUIEvents();
        
        // Only check for active processes after a slight delay
        setTimeout(checkActiveProcesses, 200);
        
        // Mark UI as fully initialized
        uiFullyInitialized = true;

        // Handle external cron checkbox toggling
        $('#swsib_options_backup_restore_use_external_cron').on('change', function() {
            if ($(this).is(':checked')) {
                $('.external-cron-info').slideDown(200);
            } else {
                $('.external-cron-info').slideUp(200);
            }
        });

        // Copy cron URL button
        $('.copy-cron-url').on('click', function() {
            const cronUrl = $('.siberian-cron-url').val();
            navigator.clipboard.writeText(cronUrl).then(function() {
                $(this).html('<span class="dashicons dashicons-yes"></span> Copied!');
                setTimeout(() => {
                    $(this).html('<span class="dashicons dashicons-clipboard"></span> Copy');
                }, 2000);
            });
        });

        // Load backup history on initial page load
        loadBackupHistoryIfNeeded();

        // Load backup schedules
        loadBackupSchedules();
            
        // Reload schedules every 5 minutes to keep next run times updated
        setInterval(loadBackupSchedules, 300000);
    }

    /**
     * Handle tab click with improved performance
     */
    function handleTabClick(e) {
        e.preventDefault();
        
        const targetId = $(this).attr('href').substring(1);
        
        // Switch tabs efficiently
        $('.siberian-tabs-content > div').hide();
        $('#' + targetId).show();
        
        // Update active class
        $('.siberian-tabs-nav .subsubsub a').removeClass('current');
        $(this).addClass('current');
        
        // Store the active tab
        $('.siberian-tabs-content').attr('data-active-tab', targetId);
        
        // Update the URL hash for tab persistence
        window.location.hash = targetId;

        // Also update the hidden input field for form submissions
        $('input[name="active_tab"]').val(targetId);

        // Load backup history if on backup tab and not already loaded
        if (targetId === 'backup') {
            loadBackupHistoryIfNeeded();
        }
    }

    /**
     * Initialize tabs with proper namespacing
     */
    function initTabs() {
        // Show the initial tab efficiently
        const initialTab = $('.siberian-tabs-content').attr('data-active-tab') || 
                          $('.siberian-tabs-nav .subsubsub a.current').attr('href')?.substring(1) || 
                          $('.siberian-tabs-nav .subsubsub a:first').attr('href')?.substring(1);
                           
        if (initialTab) {
            // Hide siblings first for better performance
            $('#' + initialTab).siblings().hide();
            $('#' + initialTab).show();
            $('.siberian-tabs-nav .subsubsub a[href="#' + initialTab + '"]').addClass('current');
            // Update hidden input field for form submissions
            $('input[name="active_tab"]').val(initialTab);
        }
    }

    /**
     * Bind remaining UI events - done after initial load
     */
    function bindRemainingUIEvents() {
        // Backup type selection
        $('.siberian-backup-type-card').on('click', function() {
            const radioBtn = $(this).find('input[type="radio"]');
            radioBtn.prop('checked', true).trigger('change');
            $('.siberian-backup-type-card').removeClass('active');
            $(this).addClass('active');
        });

        // File options toggle based on backup type
        $('#swsib-create-backup-form input[name="backup_type"]').on('change', function() {
            if ($(this).val() === 'files' || $(this).val() === 'full') {
                $('.siberian-files-options').slideDown(200);
            } else {
                $('.siberian-files-options').slideUp(200);
            }
        });
        
        // Custom file paths toggle
        $('input[name="include_all_files"]').on('change', function() {
            if ($(this).is(':checked')) {
                $('.siberian-custom-file-paths').slideUp(200);
            } else {
                $('.siberian-custom-file-paths').slideDown(200);
            }
        });
        
        // Scheduled backups toggle
        $('#swsib_options_backup_restore_scheduled_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('.siberian-scheduled-backup-options').slideDown(200);
            } else {
                $('.siberian-scheduled-backup-options').slideUp(200);
            }
        });
        
        // Storage provider tabs
        $('.siberian-storage-tabs a').on('click', function(e) {
            e.preventDefault();
            $('.siberian-storage-tab-content').removeClass('active');
            $($(this).attr('href')).addClass('active');
            $('.siberian-storage-tabs a').removeClass('active');
            $(this).addClass('active');
        });
        
        // Refresh backup history button
        $('#siberian-refresh-backup-history').on('click', refreshBackupHistory);
        
        // Test storage connection button
        $('.siberian-test-storage-connection').on('click', testStorageConnection);
        
        // Authentication button for cloud storage providers
        $('.siberian-auth-provider-button').on('click', function(e) {
            e.preventDefault();
            const provider = $(this).data('provider');
            authenticateStorageProvider(provider);
        });
        
        // Clear restore history button
        $('#siberian-clear-restore-history').on('click', clearRestoreHistory);
        
        // Bind schedule management events
        bindScheduleManagementEvents();
        
        // Bind history action buttons
        bindBackupHistoryActions();
    }
    
    /**
     * Bind schedule management events
     */
    function bindScheduleManagementEvents() {
        // Add schedule button
        $('#siberian-add-schedule').on('click', function() {
            resetScheduleForm();
            $('#siberian-schedule-modal-title').text(swsib_backup_restore.add_schedule);
            $('#siberian-schedule-modal').fadeIn();
            updateNextRunPreview();
        });
        
        // Close modal
        $('.siberian-modal-close, #siberian-schedule-cancel').on('click', function() {
            $('#siberian-schedule-modal').fadeOut();
        });
        
        // Also close when clicking on backdrop, but not when clicking modal content
        $(document).on('click', '.siberian-modal-backdrop', function(e) {
            if ($(e.target).hasClass('siberian-modal-backdrop')) {
                $('#siberian-schedule-modal').fadeOut();
            }
        });
        
        // Save schedule button
        $('#siberian-schedule-save').on('click', saveSchedule);
        
        // Update next run preview when interval changes
        $('#schedule-interval-value, #schedule-interval-unit').on('change', updateNextRunPreview);
    }
    
    /**
     * Reset the schedule form
     */
    function resetScheduleForm() {
        $('#schedule-id').val('');
        $('#schedule-name').val('');
        $('#schedule-enabled').prop('checked', true);
        $('#schedule-type').val('full');
        $('#schedule-interval-value').val('1');
        $('#schedule-interval-unit').val('days');
        $('#schedule-auto-lock').prop('checked', false);
        
        // Reset storage checkboxes
        $('#schedule-storage-providers input[type="checkbox"]').prop('checked', false);
        $('#schedule-storage-providers input[value="local"]').prop('checked', true);
    }
    
    /**
     * Update next run preview
     */
    function updateNextRunPreview() {
        const intervalValue = $('#schedule-interval-value').val();
        const intervalUnit = $('#schedule-interval-unit').val();
        
        // Calculate next run time
        const nextRunDate = new Date();
        
        switch (intervalUnit) {
            case 'minutes':
                nextRunDate.setMinutes(nextRunDate.getMinutes() + parseInt(intervalValue));
                break;
            case 'hours':
                nextRunDate.setHours(nextRunDate.getHours() + parseInt(intervalValue));
                break;
            case 'days':
                nextRunDate.setDate(nextRunDate.getDate() + parseInt(intervalValue));
                break;
            case 'weeks':
                nextRunDate.setDate(nextRunDate.getDate() + (parseInt(intervalValue) * 7));
                break;
            case 'months':
                nextRunDate.setMonth(nextRunDate.getMonth() + parseInt(intervalValue));
                break;
        }
        
        // Format date
        const formattedDate = nextRunDate.toLocaleString();
        
        // Update preview
        $('.siberian-next-run-info').html(
            '<strong>' + 'Next Run:' + '</strong> ' + formattedDate
        );
    }
    
    /**
     * Save schedule with improved error handling and single response
     * Fixed to properly create new schedules instead of always modifying existing ones
     */
    function saveSchedule() {
        const scheduleId = $('#schedule-id').val();
        const scheduleName = $('#schedule-name').val();
        
        // Basic validation
        if (!scheduleName.trim()) {
            showToast('Please enter a schedule name', 'error');
            return;
        }
        
        // Show save button loading state
        const saveBtn = $('#siberian-schedule-save');
        const originalBtnText = saveBtn.html();
        saveBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Saving...');
        
        // Get form data
        const storages = [];
        $('#schedule-storage-providers input[name="storages[]"]:checked').each(function() {
            storages.push($(this).val());
        });
        
        // Ensure we have at least one storage
        if (storages.length === 0) {
            storages.push('local');
        }
        
        // Send AJAX request
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_add_backup_schedule',
                nonce: swsib_backup_restore.backup_nonce,
                id: scheduleId, // Empty for new schedules, filled for updates
                name: scheduleName,
                type: $('#schedule-type').val(),
                interval_value: $('#schedule-interval-value').val(),
                interval_unit: $('#schedule-interval-unit').val(),
                auto_lock: $('#schedule-auto-lock').is(':checked'),
                enabled: $('#schedule-enabled').is(':checked'),
                storages: storages
            },
            success: function(response) {
                // Restore button state
                saveBtn.prop('disabled', false).html(originalBtnText);
                
                if (response.success) {
                    // Hide the modal
                    $('#siberian-schedule-modal').fadeOut();
                    
                    // Reload the schedules
                    loadBackupSchedules();
                    
                    // Show success toast message
                    showToast(response.data.message, 'success');
                } else {
                    showToast(response.data.message || 'Failed to save schedule. Please try again.', 'error');
                }
            },
            error: function() {
                // Restore button state
                saveBtn.prop('disabled', false).html(originalBtnText);
                showToast('Network error while saving schedule. Please try again.', 'error');
            }
        });
    }
    
    /**
     * Load backup schedules
     */
    function loadBackupSchedules() {
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_backup_schedules',
                nonce: swsib_backup_restore.backup_nonce
            },
            success: function(response) {
                if (response.success) {
                    renderScheduleList(response.data.schedules);
                } else {
                    $('#siberian-schedule-list-container').html(
                        '<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + 
                        (response.data.message || "Failed to load backup schedules") + '</p></div>'
                    );
                }
            },
            error: function() {
                $('#siberian-schedule-list-container').html(
                    '<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + 
                    "Network error while loading backup schedules" + '</p></div>'
                );
            }
        });
    }
    
    /**
     * Render schedule list with modern UI
     */
    function renderScheduleList(schedules) {
        const container = $('#siberian-schedule-list-container');
        
        // Check if there are any schedules
        if (Object.keys(schedules).length === 0) {
            container.html(
                '<div class="siberian-empty-schedules">' +
                    '<span class="dashicons dashicons-calendar-alt"></span>' +
                    '<h4>No Backup Schedules</h4>' +
                    '<p>Create your first backup schedule to automate your backups.</p>' +
                '</div>'
            );
            return;
        }
        
        // Sort schedules by name
        const sortedIds = Object.keys(schedules).sort(function(a, b) {
            return schedules[a].name.localeCompare(schedules[b].name);
        });
        
        let html = '<div class="siberian-schedule-list">';
        
        sortedIds.forEach(function(id) {
            const schedule = schedules[id];
            let typeIcon = 'dashicons-database';
            let typeName = 'Unknown';
            
            // Determine backup type display
            switch (schedule.type) {
                case 'full':
                    typeIcon = 'dashicons-database-export';
                    typeName = 'Full Backup';
                    break;
                case 'db':
                    typeIcon = 'dashicons-database';
                    typeName = 'Database Only';
                    break;
                case 'files':
                    typeIcon = 'dashicons-media-document';
                    typeName = 'Files Only';
                    break;
            }
            
            // Format interval
            let intervalText = schedule.interval_value + ' ';
            switch (schedule.interval_unit) {
                case 'minutes':
                    intervalText += schedule.interval_value == 1 ? 'Minute' : 'Minutes';
                    break;
                case 'hours':
                    intervalText += schedule.interval_value == 1 ? 'Hour' : 'Hours';
                    break;
                case 'days':
                    intervalText += schedule.interval_value == 1 ? 'Day' : 'Days';
                    break;
                case 'weeks':
                    intervalText += schedule.interval_value == 1 ? 'Week' : 'Weeks';
                    break;
                case 'months':
                    intervalText += schedule.interval_value == 1 ? 'Month' : 'Months';
                    break;
            }
            
            // Format storage locations
            const storageLocations = [];
            if (schedule.storages && Array.isArray(schedule.storages)) {
                schedule.storages.forEach(function(storage) {
                    switch (storage) {
                        case 'local': storageLocations.push('Local'); break;
                        case 'gdrive': storageLocations.push('Google Drive'); break;
                        case 's3': storageLocations.push('Amazon S3'); break;
                        case 'gcs': storageLocations.push('Google Cloud Storage'); break;
                        default: storageLocations.push(storage);
                    }
                });
            } else {
                storageLocations.push('Local');
            }
            
            html += '<div class="siberian-schedule-card' + (!schedule.enabled ? ' disabled' : '') + '" data-id="' + id + '">' +
                '<div class="siberian-schedule-header">' +
                    '<h4 class="siberian-schedule-title">' +
                        '<span class="dashicons ' + typeIcon + '"></span> ' +
                        schedule.name +
                        '<span class="siberian-status-badge ' + (schedule.enabled ? 'enabled' : 'disabled') + '">' +
                            (schedule.enabled ? 'Enabled' : 'Disabled') +
                        '</span>' +
                    '</h4>' +
                    '<div class="siberian-schedule-actions">' +
                        '<button type="button" class="button siberian-edit-schedule" data-id="' + id + '">' +
                            '<span class="dashicons dashicons-edit"></span> Edit' +
                        '</button>' +
                        '<button type="button" class="button siberian-delete-schedule" data-id="' + id + '">' +
                            '<span class="dashicons dashicons-trash"></span> Delete' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="siberian-schedule-content">' +
                    '<p class="siberian-schedule-info">' +
                        '<span>Type:</span> ' + typeName +
                    '</p>' +
                    '<p class="siberian-schedule-info">' +
                        '<span>Frequency:</span> Every ' + intervalText +
                    '</p>' +
                    '<p class="siberian-schedule-info">' +
                        '<span>Storage:</span> ' + storageLocations.join(', ') +
                    '</p>' +
                    '<p class="siberian-schedule-info">' +
                        '<span>Auto-Lock:</span> ' + (schedule.auto_lock ? 'Yes' : 'No') +
                    '</p>' +
                '</div>' +
                '<div class="siberian-schedule-footer">' +
                    '<p class="siberian-schedule-info">' +
                        '<span>Next Run:</span> ' + schedule.next_run_date +
                        ' (' + schedule.next_run_human + ')' +
                    '</p>' +
                    (schedule.last_run && schedule.last_run > 0 ? 
                        '<p class="siberian-schedule-info">' +
                            '<span>Last Run:</span> ' + schedule.last_run_date +
                            ' (' + schedule.last_run_human + ' ago)' +
                        '</p>' : '') +
                '</div>' +
            '</div>';
        });
        
        html += '</div>';
        container.html(html);
        
        // Bind events to the schedule actions
        bindScheduleCardEvents();
    }
    
    /**
     * Bind events to schedule card buttons
     */
    function bindScheduleCardEvents() {
        // Edit schedule button
        $('.siberian-edit-schedule').on('click', function() {
            const scheduleId = $(this).data('id');
            editSchedule(scheduleId);
        });
        
        // Delete schedule button - Fixed issue with ID not being found
        $('.siberian-delete-schedule').on('click', function() {
            const scheduleId = $(this).data('id');
            
            if (!scheduleId) {
                showToast('Schedule ID not found. Please try again.', 'error');
                return;
            }
            
            deleteSchedule(scheduleId);
        });
    }
    
    /**
     * Edit a schedule
     */
    function editSchedule(scheduleId) {
        // Load schedule data
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_backup_schedules',
                nonce: swsib_backup_restore.backup_nonce
            },
            success: function(response) {
                if (response.success && response.data.schedules[scheduleId]) {
                    const schedule = response.data.schedules[scheduleId];
                    
                    // Fill the form with schedule data
                    $('#schedule-id').val(scheduleId);
                    $('#schedule-name').val(schedule.name);
                    $('#schedule-enabled').prop('checked', schedule.enabled);
                    $('#schedule-type').val(schedule.type);
                    $('#schedule-interval-value').val(schedule.interval_value);
                    $('#schedule-interval-unit').val(schedule.interval_unit);
                    $('#schedule-auto-lock').prop('checked', schedule.auto_lock);
                    
                    // Set storage checkboxes
                    $('#schedule-storage-providers input[type="checkbox"]').prop('checked', false);
                    $('#schedule-storage-providers input[value="local"]').prop('checked', true);
                    
                    if (schedule.storages && Array.isArray(schedule.storages)) {
                        schedule.storages.forEach(function(storage) {
                            $('#schedule-storage-providers input[value="' + storage + '"]').prop('checked', true);
                        });
                    }
                    
                    // Set the modal title
                    $('#siberian-schedule-modal-title').text(swsib_backup_restore.edit_schedule);
                    
                    // Show the modal
                    $('#siberian-schedule-modal').fadeIn();
                    
                    // Update the next run preview
                    updateNextRunPreview();
                } else {
                    showToast('Failed to load schedule data. Please try again.', 'error');
                }
            },
            error: function() {
                showToast('Network error while loading schedule data. Please try again.', 'error');
            }
        });
    }
    
    /**
     * Delete a schedule - Fixed to properly handle ID retrieval
     */
    function deleteSchedule(scheduleId) {
        if (!scheduleId) {
            showToast('Schedule ID not provided. Cannot delete schedule.', 'error');
            return;
        }
        
        if (confirm(swsib_backup_restore.confirm_delete_schedule)) {
            // Show loading state for the button
            const button = $('.siberian-delete-schedule[data-id="' + scheduleId + '"]');
            const originalText = button.html();
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Deleting...');
            
            $.ajax({
                url: swsib_backup_restore.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_delete_backup_schedule',
                    nonce: swsib_backup_restore.backup_nonce,
                    id: scheduleId
                },
                success: function(response) {
                    // Restore button state
                    button.prop('disabled', false).html(originalText);
                    
                    if (response.success) {
                        // Reload the schedules
                        loadBackupSchedules();
                        
                        // Show success message
                        showToast(response.data.message, 'success');
                    } else {
                        showToast(response.data.message || 'Failed to delete schedule. Please try again.', 'error');
                    }
                },
                error: function() {
                    // Restore button state
                    button.prop('disabled', false).html(originalText);
                    
                    showToast('Network error while deleting schedule. Please try again.', 'error');
                }
            });
        }
    }

    /**
     * Check if there's a background backup that needs processing
     */
    function checkActiveProcesses() {
        // Only set start time when needed
        state.startTime = Date.now();
        
        // Use Promise for parallel requests
        const backupCheck = $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_backup_progress',
                nonce: swsib_backup_restore.backup_nonce
            }
        });
        
        const restoreCheck = $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_restore_progress',
                nonce: swsib_backup_restore.restore_nonce
            }
        });
        
        // Process both checks in parallel
        Promise.all([backupCheck, restoreCheck])
            .then(([backupResponse, restoreResponse]) => {
                // Handle backup status
                if (backupResponse.success && backupResponse.data && 
                    backupResponse.data.status && 
                    backupResponse.data.status !== 'completed' && 
                    backupResponse.data.status !== 'error') {
                    
                    handleActiveBackup(backupResponse.data);
                }
                
                // Handle restore status - improved check for any active restore
                if (restoreResponse.success && restoreResponse.data &&
                    restoreResponse.data.status === 'processing' &&
                    restoreResponse.data.phase !== 'completed' && 
                    restoreResponse.data.phase !== 'error') {
                    
                    handleActiveRestore(restoreResponse.data);
                }
            })
            .catch(() => {
                // Fall back to checking UI state if AJAX fails
                checkUIElementsState();
            });
    }

    /**
     * Handle an active backup that was detected
     */
    function handleActiveBackup(data) {
        console.log("Found active backup:", data);
        state.currentBackupId = data.id;
        state.lastStatus = data;
        
        // Set initial size tracking
        if (data.total_size) {
            state.lastFileSize = data.total_size;
        }
        if (data.db_size) {
            state.lastDbSize = data.db_size;
        }
        
        // Reset start time if we have a started timestamp
        if (data.started) {
            state.startTime = Date.now() - ((data.elapsed_time || 0) * 1000);
        }
        
        // Show the progress container and hide backup controls
        $('.siberian-backup-controls').hide();
        $('#siberian-backup-progress-container').show();
        
        // Update progress display
        updateBackupProgress(data);
        
        // Start polling
        startBackupPolling();
    }
    
    /**
     * Handle an active restore that was detected
     */
    function handleActiveRestore(data) {
        console.log("Found active restore:", data);
        state.currentRestoreId = data.id;
        state.restoreLastStatus = data;
        
        // Set start time for restore
        if (data.started) {
            state.restoreStartTime = Date.now() - ((data.elapsed_time || 0) * 1000);
        }
        
        // Show restore progress
        $('#siberian-restore-progress-container').show();
        updateRestoreProgress(data);
        
        // Start both polling AND processing
        startRestorePolling();
        
        // If not already processing, restart the processing steps
        // This is critical for page reloads - ensures processing continues
        if (data.status === 'processing' && data.phase !== 'completed' && data.phase !== 'error') {
            processNextRestoreStep();
        }
    }

    /**
     * Check UI elements state as a fallback method - more efficient version
     */
    function checkUIElementsState() {
        // Check if progress containers are visible
        const backupInProgress = $('#siberian-backup-progress-container').is(':visible');
        const restoreInProgress = $('#siberian-restore-progress-container').is(':visible');
        
        if (backupInProgress) {
            console.log("Backup progress container is visible, starting polling");
            startBackupPolling();
        }
        
        if (restoreInProgress) {
            console.log("Restore progress container is visible, starting polling");
            // Also restart processing if restore is visible
            $.ajax({
                url: swsib_backup_restore.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_restore_progress',
                    nonce: swsib_backup_restore.restore_nonce
                },
                success: function(response) {
                    if (response.success && response.data && 
                        response.data.status === 'processing' && 
                        response.data.phase !== 'completed' && 
                        response.data.phase !== 'error') {
                        
                        // Start both polling and processing
                        startRestorePolling();
                        processNextRestoreStep();
                    }
                }
            });
        }
    }

   /**
    * Start a backup process - optimized for better UI responsiveness
    * Updated to include lock option during backup creation
    */
    function startBackup() {
        const button = $(this);
        const originalText = button.html();
        
        // Validate form
        const backupType = $('input[name="backup_type"]:checked').val();
        
        // Get selected storage providers
        const storageProviders = [];
        $('input[name="storage_providers[]"]:checked').each(function() {
            storageProviders.push($(this).val());
        });
        
        // Quick validation
        if (storageProviders.length === 0) {
            showToast('Please select at least one storage location', 'error');
            return;
        }
        
        if (!backupType) {
            showToast('Please select a backup type', 'error');
            return;
        }
        
        // Disable the button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Starting backup...');
        
        // Reset tracking variables
        state.startTime = Date.now();
        state.lastFileSize = 0;
        state.lastDbSize = 0;
        state.speedHistory = [];
        
        // Update UI immediately for better responsiveness
        $('.siberian-backup-controls').hide();
        $('#siberian-backup-progress-container').show();
        $('.siberian-backup-progress-fill').css('width', '5%');
        $('.siberian-backup-status-text').text(swsib_backup_restore.starting_backup);
        $('.siberian-background-info').show();
        
        // Initialize performance metrics
        $('.siberian-backup-performance').html(
            '<div class="performance-metric">Size: <span class="metric-value">0 B</span></div>' +
            '<div class="performance-metric">Speed: <span class="metric-value">0 B/s</span></div>' +
            '<div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>'
        ).show();
        
        // Collect form data
        const formData = {
            action: 'swsib_start_backup',
            nonce: swsib_backup_restore.backup_nonce,
            backup_type: backupType,
            storage_providers: storageProviders,
            include_all_files: $('input[name="include_all_files"]').is(':checked') ? 1 : 0,
            // Add lock backup setting - new feature
            lock_backup: $('input[name="lock_backup"]').is(':checked') ? 1 : 0
        };
        
        // Add custom paths if needed
        if (!formData.include_all_files) {
            formData.include_paths = $('textarea[name="include_paths"]').val();
            formData.exclude_paths = $('textarea[name="exclude_paths"]').val();
        }
        
        // Send AJAX request - no jQuery dependency chain for better performance
        const xhr = new XMLHttpRequest();
        xhr.open('POST', swsib_backup_restore.ajax_url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.timeout = 60000; // 1 minute timeout
        
        xhr.onload = function() {
            // Re-enable button
            button.prop('disabled', false).html(originalText);
            
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        console.log("Backup started successfully:", response.data);
                        state.currentBackupId = response.data.id;
                        state.lastStatus = response.data;
                        state.backgroundMode = true;
                        
                        // Start progress polling
                        updateBackupProgress(response.data);
                        startBackupPolling();
                        
                        // Make sure background message is visible
                        $('.siberian-background-info').show();
                    } else {
                        console.error("Backup start failed:", response.data);
                        showBackupError(response.data.message || "Failed to start backup");
                    }
                } catch (e) {
                    showBackupError("Invalid response from server");
                }
            } else {
                showBackupError("Server returned status: " + xhr.status);
            }
        };
        
        xhr.ontimeout = function() {
            button.prop('disabled', false).html(originalText);
            showBackupError("Request timed out. The server might still be processing your backup. Please check the backup history after a few minutes.");
        };
        
        xhr.onerror = function() {
            button.prop('disabled', false).html(originalText);
            showBackupError("Network error occurred");
        };
        
        // Convert formData to URL-encoded string
        const params = Object.keys(formData).map(key => {
            if (Array.isArray(formData[key])) {
                return formData[key].map(value => 
                    encodeURIComponent(key + '[]') + '=' + encodeURIComponent(value)
                ).join('&');
            }
            return encodeURIComponent(key) + '=' + encodeURIComponent(formData[key]);
        }).join('&');
        
        xhr.send(params);
    }

    /**
     * Cancel a backup with confirmation - optimized version
     */
    function cancelBackup() {
        if (!confirm(swsib_backup_restore.confirm_cancel)) {
            return;
        }
        
        const button = $(this);
        const originalText = button.html();
        
        // Disable button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Canceling...');
        
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_cancel_backup',
                nonce: swsib_backup_restore.backup_nonce
            },
            success: function(response) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                if (response.success) {
                    console.log('Backup canceled successfully');
                    stopBackupPolling();
                    
                    // Show backup form
                    $('#siberian-backup-progress-container').hide();
                    $('.siberian-backup-controls').show();
                    
                    // Reset progress bar
                    $('.siberian-backup-progress-fill').width('0%');
                } else {
                    console.error('Backup cancel failed:', response.data);
                    showToast(response.data.message || "Failed to cancel backup", 'error');
                }
            },
            error: function(xhr, status, error) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                // Extract error message
                let errorMessage = extractErrorMessage(xhr, error);
                console.error('AJAX Error:', errorMessage);
                showToast("Error: " + errorMessage, 'error');
                
                // Still hide the progress and show form since we're canceling anyway
                $('#siberian-backup-progress-container').hide();
                $('.siberian-backup-controls').show();
            }
        });
    }

    /**
     * Cancel a restore with confirmation - optimized version
     */
    function cancelRestore() {
        if (!confirm(swsib_backup_restore.confirm_cancel)) {
            return;
        }
        
        const button = $(this);
        const originalText = button.html();
        
        // Disable button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Canceling...');
        
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_cancel_restore',
                nonce: swsib_backup_restore.restore_nonce
            },
            success: function(response) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                if (response.success) {
                    console.log('Restore canceled successfully');
                    stopRestorePolling();
                    
                    // Hide progress container
                    $('#siberian-restore-progress-container').hide();
                } else {
                    console.error('Restore cancel failed:', response.data);
                    showToast(response.data.message || "Failed to cancel restore", 'error');
                }
            },
            error: function(xhr, status, error) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                // Extract error message
                let errorMessage = extractErrorMessage(xhr, error);
                console.error('AJAX Error:', errorMessage);
                showToast("Error: " + errorMessage, 'error');
                
                // Still hide the progress since we're canceling anyway
                $('#siberian-restore-progress-container').hide();
            }
        });
    }

    /**
     * Start backup progress polling - optimized version
     */
    function startBackupPolling() {
        if (state.pollingActive) {
            return; // Already polling
        }
        
        state.pollingActive = true;
        state.lastProgressUpdate = Date.now();
        
        // Clear any existing intervals
        if (state.pollingInterval) {
            clearInterval(state.pollingInterval);
        }
        
        console.log("Backup polling started - monitoring status");
        
        // Poll immediately, then schedule next polls
        pollBackupProgress();
        
        // Start the elapsed time counter
        state.pollingInterval = setInterval(updateElapsedTime, 1000);
    }

    /**
     * Update elapsed time display every second
     */
    function updateElapsedTime() {
        if (!state.pollingActive) return;
        
        const elapsedMs = Date.now() - state.startTime;
        const elapsedFormatted = formatElapsedTimePrecise(elapsedMs / 1000);
        
        $('.siberian-backup-performance .performance-metric:nth-child(3) .metric-value').text(elapsedFormatted);
    }

    /**
     * Schedule the next polling iteration with dynamic timeout
     */
    function scheduleNextPoll() {
        if (!state.pollingActive) return;
        
        // Clear any existing timeout
        if (state.pollingTimeout) {
            clearTimeout(state.pollingTimeout);
        }
        
        // Calculate how long it's been since the last update
        const timeSinceLastUpdate = Date.now() - state.lastProgressUpdate;
        
        // Adjust polling interval based on activity
        let delay = state.pollingDelay;
        
        if (timeSinceLastUpdate > 30000) { // > 30 seconds
            delay = 2000; // Poll more frequently if no updates in a while
        } else if (timeSinceLastUpdate > 10000) { // > 10 seconds
            delay = 3000; // Normal polling
        } else {
            delay = 5000; // Recently updated, can poll less frequently
        }
        
        state.pollingTimeout = setTimeout(pollBackupProgress, delay);
    }

    /**
     * Stop backup progress polling
     */
    function stopBackupPolling() {
        state.pollingActive = false;
        
        if (state.pollingInterval) {
            clearInterval(state.pollingInterval);
            state.pollingInterval = null;
        }
        
        if (state.pollingTimeout) {
            clearTimeout(state.pollingTimeout);
            state.pollingTimeout = null;
        }
        
        state.backgroundMode = false;
        state.currentBackupId = null;
        state.lastStatus = null;
        
        console.log("Backup polling stopped");
    }

    /**
     * Poll for backup progress - optimized with fetch API for better performance
     */
    function pollBackupProgress() {
    if (!state.pollingActive) {
        return;
    }
    
    console.log("Polling for backup progress...");
    
    // Use fetch API instead of jQuery AJAX for better performance and error handling
    fetch(swsib_backup_restore.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'swsib_backup_progress',
            nonce: swsib_backup_restore.backup_nonce
        })
    })
    .then(response => {
        // Check if response is ok before trying to parse JSON
        if (!response.ok) {
            throw new Error('Server responded with status ' + response.status);
        }
        
        // Try to parse as JSON, but catch parsing errors
        return response.json().catch(err => {
            // If JSON parsing fails, try to get text and throw better error
            return response.text().then(text => {
                throw new Error('Invalid JSON response: ' + text.substring(0, 100) + '...');
            });
        });
    })
    .then(response => {
        if (!state.pollingActive) return; // Check if polling was stopped during request
        
        if (response.success) {
            const data = response.data;
            
            // Only update UI if data has changed to avoid unnecessary DOM updates
            const hasChanged = !state.lastStatus || 
                            JSON.stringify(data) !== JSON.stringify(state.lastStatus);
            
            if (hasChanged) {
                console.log("Received progress update with changes:", data);
                state.lastStatus = data;
            }
            
            // Make sure the progress container is visible and backup controls are hidden
            if (!$('#siberian-backup-progress-container').is(':visible')) {
                $('.siberian-backup-controls').hide();
                $('#siberian-backup-progress-container').show();
            }
            
            // Update progress (even if no changes, to ensure elapsed time updates)
            updateBackupProgress(data);
            
            // Record that we got an update
            state.lastProgressUpdate = Date.now();
            
            // Always show the background notice since processing is happening server-side
            $('.siberian-background-info').show();
            
            // Check status
            if (data.status === 'completed') {
                console.log("Backup completed successfully");
                handleBackupCompletion();
            } else if (data.status === 'error') {
                console.error("Backup error:", data.message);
                showBackupError(data.message || "Backup process encountered an error");
            } else {
                // Continue polling if backup is still in progress
                scheduleNextPoll();
            }
        } else {
            console.error("Error in progress poll:", response.data);
            
            // No active backup, stop polling and reset UI
            if (response.data && response.data.message === "No active backup found") {
                stopBackupPolling();
                $('#siberian-backup-progress-container').hide();
                $('.siberian-backup-controls').show();
                return;
            }
            
            // Continue polling anyway, in case the error is temporary
            scheduleNextPoll();
        }
    })
    .catch(error => {
        if (!state.pollingActive) return; // Check if polling was stopped during request
        
        console.log("Network error in polling:", error);
        
        // For network errors, retry polling after a longer delay
        state.pollingDelay = 5000; // Increase delay after error
        scheduleNextPoll();
    });
}

/**
 * Update the backup progress display with improved formatting
 */
function updateBackupProgress(data) {
    if (!data) return;
    
    // Update progress bar using CSS transitions for better performance
    $('.siberian-backup-progress-fill').css('width', (data.progress || 0) + '%');
    
    // Update status message - use exactly as provided without manipulation
    $('.siberian-backup-status-text').text(data.message || '');
    
    // Track size changes
    let totalSize = 0;
    let fileSize = 0;
    let dbSize = 0;
    
    if (typeof data.total_size === 'number') {
        totalSize = data.total_size;
    } else if (data.backup_type === 'file' && typeof data.file_status?.total_size === 'number') {
        totalSize = data.file_status.total_size;
    }
    
    if (typeof data.files_size === 'number') {
        fileSize = data.files_size;
    } else if (typeof data.file_status?.total_size === 'number') {
        fileSize = data.file_status.total_size;
    }
    
    if (typeof data.db_size === 'number') {
        dbSize = data.db_size;
    } else if (typeof data.db_status?.db_size === 'number') {
        dbSize = data.db_status.db_size;
    }
    
    // Calculate size change
    const sizeChanged = (totalSize > 0 && totalSize !== state.lastFileSize + state.lastDbSize) || 
                    (fileSize > 0 && fileSize !== state.lastFileSize) || 
                    (dbSize > 0 && dbSize !== state.lastDbSize);
    
    // Update performance metrics - create section if it doesn't exist
    if ($('.siberian-backup-performance').length === 0) {
        $('.siberian-backup-stats').after(
            '<div class="siberian-backup-performance">' +
            '<div class="performance-metric">Size: <span class="metric-value">0 B</span></div>' +
            '<div class="performance-metric">Speed: <span class="metric-value">0 B/s</span></div>' +
            '<div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>' +
            '</div>'
        );
    }
    
    // Update size with standardized format
    if (totalSize > 0) {
        $('.siberian-backup-performance .performance-metric:first-child .metric-value').text(formatBytesUniform(totalSize));
    } else if (fileSize > 0 || dbSize > 0) {
        const combinedSize = fileSize + dbSize;
        $('.siberian-backup-performance .performance-metric:first-child .metric-value').text(formatBytesUniform(combinedSize));
    }
    
    // Calculate and update speed
    let speed = 0;
    
    // First try to use server-provided speed
    if (typeof data.bytes_per_second === 'number' && data.bytes_per_second > 0) {
        speed = data.bytes_per_second;
    } else if (typeof data.file_status?.bytes_per_second === 'number' && data.file_status.bytes_per_second > 0) {
        speed = data.file_status.bytes_per_second;
    } else if (typeof data.db_status?.bytes_per_second === 'number' && data.db_status.bytes_per_second > 0) {
        speed = data.db_status.bytes_per_second;
    } 
    // Calculate speed based on size change
    else if (sizeChanged) {
        const sizeDiff = (totalSize - (state.lastFileSize + state.lastDbSize)) || 
                      (fileSize - state.lastFileSize) || 
                      (dbSize - state.lastDbSize);
        
        if (sizeDiff > 0) {
            const timeDiff = (Date.now() - state.lastProgressUpdate) / 1000;
            if (timeDiff > 0) {
                speed = sizeDiff / timeDiff;
            }
        }
    }
    
    // Save current sizes for next comparison
    if (totalSize > 0) {
        state.lastFileSize = fileSize || totalSize;
        state.lastDbSize = dbSize || 0;
    } else {
        if (fileSize > 0) state.lastFileSize = fileSize;
        if (dbSize > 0) state.lastDbSize = dbSize;
    }
    
    // Keep track of speed history for smoothing
    if (speed > 0) {
        // Add to history but limit to last 5 values
        state.speedHistory.unshift(speed);
        if (state.speedHistory.length > 5) {
            state.speedHistory.pop();
        }
        
        // Calculate average speed for smoother display
        const avgSpeed = state.speedHistory.reduce((a, b) => a + b, 0) / state.speedHistory.length;
        
        // Update speed display with standardized format
        $('.siberian-backup-performance .performance-metric:nth-child(2) .metric-value')
            .text(formatBytesUniform(avgSpeed) + '/s');
    }
    
    // Create stats content - structured for consistent display
    let statsHtml = '';
    
    if (data.backup_type === 'db' || (data.backup_type === 'full' && data.current_phase === 'db')) {
        // DB backup or full backup in DB phase
        const dbStats = data.db_status || data;
        
        if (dbStats.processed_tables !== undefined && dbStats.total_tables) {
            statsHtml += '<div>Tables: ' + dbStats.processed_tables + '/' + dbStats.total_tables + '</div>';
        }
        
        if (dbSize > 0) {
            statsHtml += '<div>Database size: ' + formatBytesUniform(dbSize) + '</div>';
        }
        
        // Track current and last processed tables for display
        if (!state.lastProcessedTables) {
            state.lastProcessedTables = [];
        }
        
        // Determine current tables to display
        let currentTables = [];
        
        // First check all possible locations for current tables data
        if (data.current_tables && Array.isArray(data.current_tables) && data.current_tables.length > 0) {
            currentTables = data.current_tables;
            // Update our record of last processed tables
            state.lastProcessedTables = [...data.current_tables];
        } else if (data.db_status && data.db_status.current_tables && 
                  Array.isArray(data.db_status.current_tables) && 
                  data.db_status.current_tables.length > 0) {
            currentTables = data.db_status.current_tables;
            // Update our record of last processed tables
            state.lastProcessedTables = [...data.db_status.current_tables];
        } else if (data.active_table_states && typeof data.active_table_states === 'object') {
            currentTables = Object.keys(data.active_table_states);
            // Update our record of last processed tables
            state.lastProcessedTables = [...currentTables];
        } else if (data.completed_tables && Array.isArray(data.completed_tables) && 
                  data.completed_tables.length > 0) {
            // If there are no current tables but we have a completed tables list,
            // use the last completed table
            const lastCompletedTables = data.completed_tables.slice(-3);
            currentTables = [`${lastCompletedTables[lastCompletedTables.length - 1]} (completed)`];
        } else if (state.lastProcessedTables && state.lastProcessedTables.length > 0) {
            // As a last resort, use the last known tables from our state
            currentTables = state.lastProcessedTables.map(table => `${table} (last active)`);
        }
        
        // Add current tables to stats display if available
        if (currentTables.length > 0) {
            statsHtml += '<div>Current tables: <strong>' + currentTables.join(', ') + '</strong></div>';
        }
    }
    
    if (data.backup_type === 'file' || data.backup_type === 'files' || (data.backup_type === 'full' && data.current_phase === 'files')) {
        // File backup or full backup in file phase
        let fileStats = data.file_status || data;
        
        // Files backed up
        if (typeof fileStats.files_backed_up === 'number') {
            statsHtml += '<div>Files: ' + fileStats.files_backed_up + '</div>';
        } else if (typeof fileStats.current_file_index === 'number' && typeof fileStats.total_files === 'number') {
            statsHtml += '<div>Files: ' + fileStats.current_file_index + '/' + fileStats.total_files + '</div>';
        }
        
        // Directories backed up
        if (typeof fileStats.dirs_backed_up === 'number') {
            statsHtml += '<div>Directories: ' + fileStats.dirs_backed_up + '</div>';
        }
        
        // File size
        if (fileSize > 0) {
            statsHtml += '<div>Files size: ' + formatBytesUniform(fileSize) + '</div>';
        }
        
        // Track last current file so we can still show it if data doesn't include it
        if (data.current_file) {
            state.lastCurrentFile = data.current_file;
        }
        
        // Current file with proper formatting - just show filename, not full path
        let currentFile = '';
        if (data.current_file) {
            currentFile = data.current_file;
        } else if (state.lastCurrentFile) {
            // If no current file in this update, use the last known file
            currentFile = state.lastCurrentFile + ' (last active)';
        }
        
        if (currentFile) {
            const currentFileName = currentFile.split('/').pop();
            statsHtml += '<div>Current file: <strong>' + currentFileName + '</strong></div>';
        }
    }
    
    if (statsHtml) {
        // Update DOM only if content changed - reduces reflows
        if ($('.siberian-backup-stats').html() !== statsHtml) {
            $('.siberian-backup-stats').html(statsHtml);
        }
    }
}

    /**
     * Format bytes to human-readable format with simpler units
     */
    function formatBytesUniform(bytes) {
        if (bytes === 0) return '0 B';
        
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        // Format with 2 decimal places
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    /**
     * Format elapsed time with hours:minutes:seconds
     */
    function formatElapsedTimePrecise(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        
        return [
            hours.toString().padStart(2, '0'),
            minutes.toString().padStart(2, '0'),
            secs.toString().padStart(2, '0')
        ].join(':');
    }
    
    /**
     * Handle backup completion - optimized version
     */
    function handleBackupCompletion() {
        console.log("Handling backup completion");
        
        // Refresh backup history
        refreshBackupHistory(function() {
            // Show success message
            $('.siberian-backup-status-text').html('<span class="dashicons dashicons-yes-alt"></span> Backup completed successfully');
            $('.siberian-backup-progress-fill').width('100%');
            
            // Calculate and show final stats
            const totalTime = (Date.now() - state.startTime) / 1000;
            const totalSize = state.lastFileSize + state.lastDbSize;
            const avgSpeed = totalSize / totalTime;
            
            $('.siberian-backup-performance .performance-metric:nth-child(2) .metric-value')
                .text(formatBytesUniform(avgSpeed) + '/s (avg)');
            
            // Stop polling
            stopBackupPolling();
            
            // Show backup form after delay
            setTimeout(function() {
                $('#siberian-backup-progress-container').hide();
                $('.siberian-backup-controls').show();
                
                // Show success toast
                showToast('Backup completed successfully', 'success');
            }, 3000);
        });
    }

    /**
     * Show backup error
     */
    function showBackupError(message) {
        $('.siberian-backup-status-text').html('<span class="dashicons dashicons-warning"></span> ' + message);
        $('#siberian-backup-progress-container').addClass('error');
        
        // Stop polling
        stopBackupPolling();
        
        // Show backup form after delay
        setTimeout(function() {
            $('#siberian-backup-progress-container').hide().removeClass('error');
            $('.siberian-backup-controls').show();
            
            // Show error toast
            showToast(message, 'error');
        }, 5000);
    }

    /**
     * Refresh backup history - optimized version with caching
     */
    function refreshBackupHistory(callback) {
        const button = $('#siberian-refresh-backup-history');
        const originalText = button.html();
        
        // Disable button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Refreshing...');
        
        const historyList = $('#siberian-backup-history-list');
        
        // Add loading indicator
        historyList.html('<div class="siberian-loading-indicator"><span class="dashicons dashicons-update spinning"></span> Loading backup history...</div>');
        
        // Add timestamp to prevent caching
        const timestamp = new Date().getTime();
        
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_get_backup_history',
                nonce: swsib_backup_restore.backup_nonce,
                ts: timestamp
            },
            success: function(response) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                if (response.success) {
                    renderBackupHistory(response.data.history, historyList);
                } else {
                    historyList.html('<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + 
                                  (response.data.message || "Failed to load backup history") + '</p></div>');
                }
                
                if (typeof callback === 'function') {
                    callback();
                }
            },
            error: function(xhr, status, error) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                let errorMessage = extractErrorMessage(xhr, error);
                historyList.html('<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + errorMessage + '</p></div>');
                
                if (typeof callback === 'function') {
                    callback();
                }
            }
        });
    }
    
function renderBackupHistory(history, historyList) {
    if (Object.keys(history).length === 0) {
        historyList.html('<p class="siberian-no-backups-message">' + swsib_backup_restore.no_backups + '</p>');
        return;
    }
    
    let tableHtml = '<table class="wp-list-table widefat striped">' +
        '<thead>' +
            '<tr>' +
                '<th>Backup</th>' +
                '<th>Type</th>' +
                '<th>Date</th>' +
                '<th>Size</th>' +
                '<th>Storage</th>' +
                '<th>Source</th>' +
                '<th>Actions</th>' +
            '</tr>' +
        '</thead>' +
        '<tbody>';
    
    // Sort by date descending (newest first)
    const sortedIds = Object.keys(history).sort(function(a, b) {
        return history[b].created - history[a].created;
    });
    
    sortedIds.forEach(function(id) {
        const backup = history[id];
        
        // Corrected type mapping
        let typeName = '';
        switch (backup.backup_type) {
            case 'full': typeName = 'Full'; break;
            case 'db': typeName = 'Database'; break;
            case 'file': 
            case 'files': typeName = 'Files'; break;
            default: typeName = backup.backup_type;
        }
        
        // Determine storage display - FIXED to handle all storage types consistently
        let storageDisplay = '';
        
        if (backup.uploaded_to && Array.isArray(backup.uploaded_to) && backup.uploaded_to.length > 0) {
            // Format from uploaded_to array
            let storageNames = [];
            backup.uploaded_to.forEach(function(provider) {
                switch (provider) {
                    case 'local': storageNames.push('Local'); break;
                    case 'gdrive': storageNames.push('Google Drive'); break;
                    case 's3': storageNames.push('Amazon S3'); break;
                    case 'gcs': storageNames.push('Google Cloud Storage'); break;
                    default: storageNames.push(provider);
                }
            });
            storageDisplay = storageNames.join(', ');
        } else if (backup.storage_name) {
            // Use pre-formatted storage name if available
            storageDisplay = backup.storage_name;
        } else {
            // Fallback to single storage
            switch (backup.storage) {
                case 'local': storageDisplay = 'Local'; break;
                case 'gdrive': storageDisplay = 'Google Drive'; break;
                case 's3': storageDisplay = 'Amazon S3'; break;
                case 'gcs': storageDisplay = 'Google Cloud Storage'; break;
                default: storageDisplay = backup.storage;
            }
        }
        
        // Ensure the scheduled flag is properly checked and displayed
        const isScheduled = backup.scheduled === true;
        
        // Determine backup source based on scheduled flag
        const backupSource = isScheduled ? 
            '<span class="backup-source-scheduled" title="Created by scheduled automation">Scheduled</span>' : 
            '<span class="backup-source-manual" title="Created manually">Manual</span>';
        
        // FIXED: Pass the correct provider to the download button based on uploaded_to information
        const primaryProvider = backup.storage || 'local';
        
        tableHtml += '<tr id="siberian-backup-row-' + id + '">' +
            '<td>' + backup.file + 
                (backup.locked ? ' <span class="dashicons dashicons-lock" title="This backup is locked and will not be automatically deleted"></span>' : '') +
            '</td>' +
            '<td>' + typeName + '</td>' +
            '<td>' + backup.date + '</td>' +
            '<td>' + backup.size + '</td>' +
            '<td>' + storageDisplay + '</td>' +
            '<td>' + backupSource + '</td>' +
            '<td class="siberian-backup-actions">' +
                '<button type="button" class="button siberian-download-backup" data-id="' + id + '" data-provider="' + primaryProvider + '">' +
                    '<span class="dashicons dashicons-download"></span> Download' +
                '</button> ' +
                '<button type="button" class="button siberian-restore-backup" data-id="' + id + '" data-type="' + backup.backup_type + '" data-provider="' + primaryProvider + '">' +
                    '<span class="dashicons dashicons-database-import"></span> Restore' +
                '</button> ' +
                '<button type="button" class="button siberian-lock-backup" data-id="' + id + '" data-locked="' + (backup.locked ? '1' : '0') + '">' +
                    '<span class="dashicons dashicons-' + (backup.locked ? 'unlock' : 'lock') + '"></span> ' +
                    (backup.locked ? 'Unlock' : 'Lock') +
                '</button> ' +
                '<button type="button" class="button siberian-delete-backup" data-id="' + id + '" data-provider="all">' +
                    '<span class="dashicons dashicons-trash"></span> Delete' +
                '</button>' +
            '</td>' +
        '</tr>';
    });
    
    tableHtml += '</tbody></table>';
    historyList.html(tableHtml);
    
    // Rebind events for new buttons - deferring this improves rendering performance
    setTimeout(bindBackupHistoryActions, 0);
}

    /**
     * Bind backup history action buttons - optimized with event delegation
     */
    function bindBackupHistoryActions() {
        // Use event delegation for better performance
        const historyList = $('#siberian-backup-history-list');
        
        // Remove all previous event handlers
        historyList.off('click', '.siberian-download-backup');
        historyList.off('click', '.siberian-restore-backup');
        historyList.off('click', '.siberian-lock-backup');
        historyList.off('click', '.siberian-delete-backup');
        
        // Add new event handlers with event delegation
        historyList.on('click', '.siberian-download-backup', function() {
            const button = $(this);
            const originalText = button.html();
            const backupId = button.data('id');
            const provider = button.data('provider') || 'local';
            
            // Disable button and show loading indicator
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Downloading...');
            
            // Create an iframe to handle the download
            $('<iframe>', {
                src: swsib_backup_restore.ajax_url + '?action=swsib_download_backup&backup_id=' + backupId + '&provider=' + provider + '&backup_download_nonce=' + swsib_backup_restore.backup_nonce,
                style: 'display:none'
            }).appendTo('body');
            
            // Re-enable button after a short delay
            setTimeout(function() {
                button.prop('disabled', false).html(originalText);
            }, 2000);
        });
        
        historyList.on('click', '.siberian-restore-backup', function() {
            const backupId = $(this).data('id');
            const backupType = $(this).data('type');
            const provider = $(this).data('provider') || 'local';
            
            if (confirm(swsib_backup_restore.confirm_restore)) {
                startRestore(backupId, backupType, provider);
            }
        });
        
        historyList.on('click', '.siberian-lock-backup', function() {
            const backupId = $(this).data('id');
            const isLocked = $(this).data('locked') === 1;
            const button = $(this);
            const originalText = button.html();
            
            // Disable button and show loading indicator
            button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> ' + (isLocked ? 'Unlocking...' : 'Locking...'));
            
            $.ajax({
                url: swsib_backup_restore.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_lock_backup',
                    nonce: swsib_backup_restore.backup_nonce,
                    backup_id: backupId,
                    locked: isLocked ? 0 : 1
                },
                success: function(response) {
                    // Re-enable button
                    button.prop('disabled', false);
                    
                    if (response.success) {
                        // Update button and icon
                        if (!isLocked) {
                            button.data('locked', 1);
                            button.html('<span class="dashicons dashicons-unlock"></span> Unlock');
                            $('#siberian-backup-row-' + backupId + ' td:first-child').append(' <span class="dashicons dashicons-lock" title="This backup is locked and will not be automatically deleted"></span>');
                        } else {
                            button.data('locked', 0);
                            button.html('<span class="dashicons dashicons-lock"></span> Lock');
                            $('#siberian-backup-row-' + backupId + ' td:first-child .dashicons-lock').remove();
                        }
                        
                        // Show success message
                        showToast(response.data.message || "Backup " + (isLocked ? "unlocked" : "locked") + " successfully", 'success');
                    } else {
                        button.html(originalText);
                        showToast(response.data.message || "Failed to " + (isLocked ? "unlock" : "lock") + " backup", 'error');
                    }
                },
                error: function(xhr, status, error) {
                    // Re-enable button with original text
                    button.prop('disabled', false).html(originalText);
                    
                    let errorMessage = extractErrorMessage(xhr, error);
                    showToast("Error: " + errorMessage, 'error');
                }
            });
        });
        
        historyList.on('click', '.siberian-delete-backup', function() {
            const backupId = $(this).data('id');
            const provider = $(this).data('provider') || 'all';
            const row = $('#siberian-backup-row-' + backupId);
            const button = $(this);
            const originalText = button.html();
            
            if (!backupId) {
                showToast('Backup ID not provided. Cannot delete backup.', 'error');
                return;
            }
            
            if (confirm(swsib_backup_restore.confirm_delete)) {
                // Disable button and show loading indicator
                button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Deleting...');
                
                $.ajax({
                    url: swsib_backup_restore.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'swsib_delete_backup',
                        nonce: swsib_backup_restore.backup_nonce,
                        backup_id: backupId,
                        provider: provider
                    },
                    success: function(response) {
                        // Re-enable button
                        button.prop('disabled', false).html(originalText);
                        
                        if (response.success) {
                            // Remove row with animation
                            row.fadeOut(function() {
                                $(this).remove();
                                
                                // Check if table is empty
                                if ($('#siberian-backup-history-list tbody tr').length === 0) {
                                    $('#siberian-backup-history-list').html('<p class="siberian-no-backups-message">' + swsib_backup_restore.no_backups + '</p>');
                                }
                            });
                            
                            // Show success message
                            showToast(response.data.message || "Backup deleted successfully", 'success');
                        } else {
                            showToast(response.data.message || "Failed to delete backup", 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        // Re-enable button
                        button.prop('disabled', false).html(originalText);
                        
                        let errorMessage = extractErrorMessage(xhr, error);
                        showToast("Error: " + errorMessage, 'error');
                    }
                });
            }
        });
    }

    /**
     * Start restore process
     */
    function startRestore(backupId, backupType, provider) {
        provider = provider || 'local'; // Default to local if not specified
        
        // Reset restore tracking variables
        state.restoreStartTime = Date.now();
        state.restoreLastSize = 0;
        state.restoreSpeedHistory = [];
        state.restoreLastStatus = null;
        
        // Show restore progress container
        $('#siberian-restore-progress-container').show();
        $('.siberian-restore-progress-fill').width('5%');
        $('.siberian-restore-status-text').text(swsib_backup_restore.starting_restore);
        
        // Create performance metrics section for restore
        $('.siberian-restore-performance').html(
            '<div class="performance-metric">Size: <span class="metric-value">0 B</span></div>' +
            '<div class="performance-metric">Speed: <span class="metric-value">0 B/s</span></div>' +
            '<div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>'
        ).show();
        
        // Send AJAX request
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_start_restore',
                nonce: swsib_backup_restore.restore_nonce,
                backup_id: backupId,
                provider: provider
            },
            success: function(response) {
                if (response.success) {
                    state.currentRestoreId = response.data.id;
                    state.restoreLastStatus = response.data;
                    
                    // Update UI with initial progress
                    updateRestoreProgress(response.data);
                    
                    // Start polling for progress
                    startRestorePolling();
                    
                    // Process first step
                    processNextRestoreStep();
                } else {
                    showRestoreError(response.data.message || "Failed to start restore");
                }
            },
            error: function(xhr, status, error) {
                let errorMessage = extractErrorMessage(xhr, error);
                showRestoreError(errorMessage);
            }
        });
    }

    /**
     * Process next restore step
     */
    function processNextRestoreStep() {
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_process_restore_step',
                nonce: swsib_backup_restore.restore_nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update progress
                    updateRestoreProgress(data);
                    
                    // Check completion status
                    if (data.phase === 'completed') {
                        handleRestoreCompletion();
                    } else if (data.phase === 'error') {
                        showRestoreError(data.message || "Error processing restore");
                    } else {
                        // Continue processing after a short delay
                        setTimeout(processNextRestoreStep, 1000);
                    }
                } else {
                    // Check if there's still an active restore despite the error
                    checkRestoreStatusBeforeError(response.data?.message || "Failed to process restore step");
                }
            },
            error: function(xhr, status, error) {
                if (status === "timeout") {
                    // Just continue polling without showing an error
                    setTimeout(processNextRestoreStep, 2000);
                } else {
                    let errorMessage = extractErrorMessage(xhr, error);
                    
                    // Try again once after a longer delay if it was a timeout or server error
                    if (xhr.status === 500 || xhr.status === 503) {
                        setTimeout(processNextRestoreStep, 5000);
                    } else {
                        // Check if there's still an active restore despite the network error
                        checkRestoreStatusBeforeError(errorMessage);
                    }
                }
            }
        });
    }

    function checkRestoreStatusBeforeError(errorMessage) {
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_restore_progress',
                nonce: swsib_backup_restore.restore_nonce
            },
            success: function(response) {
                if (response.success && response.data && 
                    response.data.status === 'processing' && 
                    response.data.phase !== 'completed' && 
                    response.data.phase !== 'error') {
                    
                    // Restore is still active despite the error, continue processing
                    console.log("Restore still active despite error, continuing");
                    setTimeout(processNextRestoreStep, 5000);
                } else {
                    // Show error as restore is no longer active
                    showRestoreError(errorMessage);
                }
            },
            error: function() {
                // If we can't determine status, show the original error
                showRestoreError(errorMessage);
            }
        });
    }

    /**
     * Start restore polling
     */
    function startRestorePolling() {
        if (state.restorePollingActive) {
            return; // Already polling
        }
        
        state.restorePollingActive = true;
        state.lastProgressUpdate = Date.now();
        
        // Use setTimeout for polling with better error handling
        function poll() {
            if (!state.restorePollingActive) return;
            
            $.ajax({
                url: swsib_backup_restore.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_restore_progress', 
                    nonce: swsib_backup_restore.restore_nonce
                },
                success: function(response) {
                    if (!state.restorePollingActive) return;
                    
                    if (response.success) {
                        const data = response.data;
                        
                        // Make sure the restore progress container is visible
                        if (!$('#siberian-restore-progress-container').is(':visible')) {
                            $('#siberian-restore-progress-container').show();
                        }
                        
                        // Update progress
                        updateRestoreProgress(data);
                        
                        // Update last progress update time
                        state.lastProgressUpdate = Date.now();
                        
                        // Check status
                        if (data.phase === 'completed') {
                            handleRestoreCompletion();
                        } else if (data.phase === 'error') {
                            showRestoreError(data.message || "Error during restore");
                        } else {
                            // Continue polling
                            setTimeout(poll, 3000);
                        }
                    } else {
                        // Continue polling anyway, error might be temporary
                        setTimeout(poll, 5000);
                    }
                },
                error: function() {
                    if (!state.restorePollingActive) return;
                    
                    // Continue polling after a longer delay
                    setTimeout(poll, 8000);
                }
            });
        }
        
        // Start polling
        setTimeout(poll, 1000);
        
        // Start elapsed time counter for restore
        if (state.restoreElapsedInterval) {
            clearInterval(state.restoreElapsedInterval);
        }
        
        state.restoreElapsedInterval = setInterval(function() {
            if (!state.restorePollingActive) return;
            
            const elapsedMs = Date.now() - state.restoreStartTime;
            const elapsedFormatted = formatElapsedTimePrecise(elapsedMs / 1000);
            
            $('.siberian-restore-performance .performance-metric:nth-child(3) .metric-value').text(elapsedFormatted);
        }, 1000);
    }

    /**
     * Stop restore polling
     */
    function stopRestorePolling() {
        state.restorePollingActive = false;
        state.currentRestoreId = null;
        
        if (state.restoreElapsedInterval) {
            clearInterval(state.restoreElapsedInterval);
            state.restoreElapsedInterval = null;
        }
    }

    /**
     * Update restore progress display
     */
    function updateRestoreProgress(data) {
        if (!data) return;
        
        // Update progress bar
        $('.siberian-restore-progress-fill').width((data.progress || 0) + '%');
        
        // Update status message with improved formatting
        let statusMessage = data.message || '';
        
        // For database phase, add current table to status message
        if (data.phase === 'database' && data.current_table) {
            if (data.tables_processed !== undefined && data.tables_total && 
                statusMessage.includes('Processing database') && !statusMessage.includes(data.current_table)) {
                statusMessage = statusMessage + ': ' + data.current_table;
            }
        }
        
        // For files phase, add current file to status message
        if (data.phase === 'files' && data.current_file) {
            if (statusMessage.includes('Processing files') && !statusMessage.includes(':')) {
                // Show just the filename, not the full path
                const filename = data.current_file.split('/').pop();
                statusMessage = statusMessage + ': ' + filename;
            }
        }
        
        // Update status message
        $('.siberian-restore-status-text').text(statusMessage);
        
        // Update stats
        let statsHtml = '';
        
        // Database phase stats
        if (data.phase === 'database') {
            if (data.tables_processed && data.tables_total) {
                statsHtml += '<div>Tables: ' + data.tables_processed + '/' + data.tables_total + '</div>';
            }
        }
        
        // Files phase stats
        if (data.phase === 'files') {
            if (data.files_processed && data.files_total) {
                statsHtml += '<div>Files: ' + data.files_processed + '/' + data.files_total + '</div>';
            }
            
            // Add directory count display
            if (data.dirs_processed) {
                statsHtml += '<div>Directories: ' + data.dirs_processed + '</div>';
            }
        }
        
        if (statsHtml) {
            $('.siberian-restore-stats').html(statsHtml);
        }
        
        // Update performance metrics
        if (!$('.siberian-restore-performance').length) {
            $('.siberian-restore-stats').after(
                '<div class="siberian-restore-performance">' +
                '<div class="performance-metric">Size: <span class="metric-value">0 B</span></div>' +
                '<div class="performance-metric">Speed: <span class="metric-value">0 B/s</span></div>' +
                '<div class="performance-metric">Time: <span class="metric-value">00:00:00</span></div>' +
                '</div>'
            );
        }
        
        // Update size - show restored size, not backup size
        if (data.processed_size) {
            $('.siberian-restore-performance .performance-metric:first-child .metric-value').text(formatBytesUniform(data.processed_size));
        }
        
        // Update speed
        let speed = 0;
        
        // Try to use server-provided speed
        if (typeof data.bytes_per_second === 'number' && data.bytes_per_second > 0) {
            speed = data.bytes_per_second;
        } 
        else if (data.processed_size && state.restoreLastSize) {
            const sizeDiff = data.processed_size - state.restoreLastSize;
            if (sizeDiff > 0) {
                const timeDiff = (Date.now() - state.lastProgressUpdate) / 1000;
                if (timeDiff > 0) {
                    speed = sizeDiff / timeDiff;
                }
            }
        }
        
        // Update last size for next comparison
        if (data.processed_size) {
            state.restoreLastSize = data.processed_size;
        }
        
        // Apply speed smoothing
        if (speed > 0) {
            state.restoreSpeedHistory.unshift(speed);
            if (state.restoreSpeedHistory.length > 5) {
                state.restoreSpeedHistory.pop();
            }
            
            // Calculate average speed for smoother display
            const avgSpeed = state.restoreSpeedHistory.reduce((a, b) => a + b, 0) / state.restoreSpeedHistory.length;
            
            $('.siberian-restore-performance .performance-metric:nth-child(2) .metric-value').text(formatBytesUniform(avgSpeed) + '/s');
        }
    }

    /**
     * Handle restore completion
     */
    function handleRestoreCompletion() {
        // Stop polling
        stopRestorePolling();
        
        // Show success message and reload page after delay
        $('.siberian-restore-status-text').html('<span class="dashicons dashicons-yes-alt"></span> Restore completed successfully');
        $('.siberian-restore-progress-fill').width('100%');
        
        // Show success toast before reloading
        showToast('Restore completed successfully. Page will reload...', 'success');
        
        setTimeout(function() {
            window.location.reload();
        }, 2000);
    }

    /**
     * Show restore error
     */
    function showRestoreError(message) {
        $('.siberian-restore-status-text').html('<span class="dashicons dashicons-warning"></span> ' + message);
        $('#siberian-restore-progress-container').addClass('error');
        
        // Stop polling
        stopRestorePolling();
        
        // Show error toast
        showToast(message, 'error');
        
        // Hide progress after delay
        setTimeout(function() {
            $('#siberian-restore-progress-container').hide().removeClass('error');
        }, 5000);
    }

    /**
     * Test storage connection
     */
    function testStorageConnection() {
        const provider = $(this).data('provider');
        const resultElement = $('#siberian-test-result-' + provider);
        const button = $(this);
        const originalText = button.text();
        
        // Disable the button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Testing...');
        
        // Collect the provider settings
        const formData = new FormData();
        formData.append('action', 'swsib_test_storage_connection');
        formData.append('nonce', swsib_backup_restore.nonce);
        formData.append('provider', provider);
        
        // Get all inputs related to this provider
        $('input, select, textarea').each(function() {
            const name = $(this).attr('name');
            if (name && name.includes('backup_restore][storage][' + provider + ']')) {
                formData.append($(this).attr('id'), $(this).val());
            }
        });
        
        // Show loading indicator in result element
        resultElement.html('<span class="dashicons dashicons-update spinning"></span> Testing...').css({
            'opacity': 1,
            'color': '#707070'
        });
        
        // Send AJAX request
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                // Re-enable the button
                button.prop('disabled', false).text(originalText);
                
                if (response.success) {
                    resultElement.html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message).css({
                        'opacity': 1,
                        'color': 'green'
                    });
                    
                    // Show success toast
                    showToast(response.data.message, 'success');
                } else {
                    resultElement.html('<span class="dashicons dashicons-warning"></span> ' + response.data.message).css({
                        'opacity': 1,
                        'color': 'red'
                    });
                    
                    // Show error toast
                    showToast(response.data.message, 'error');
                }
                
                // Hide after a delay
                setTimeout(function() {
                    resultElement.fadeOut(function() {
                        $(this).html('').show().css('color', '');
                    });
                }, 5000);
            },
            error: function(xhr, status, error) {
                // Re-enable the button
                button.prop('disabled', false).text(originalText);
                
                let errorMessage = extractErrorMessage(xhr, error);
                
                resultElement.html('<span class="dashicons dashicons-warning"></span> ' + errorMessage).css({
                    'opacity': 1,
                    'color': 'red'
                });
                
                // Show error toast
                showToast(errorMessage, 'error');
            }
        });
    }

    /**
     * Authentication function for cloud storage providers
     */
    function authenticateStorageProvider(provider) {
        // For Google Drive
        if (provider === 'gdrive') {
            // First save current settings
            const saveButton = $('#siberian-backup-settings-form input[type="submit"]');
            saveButton.click();
            
            // Show a notice that we're redirecting to Google Auth
            const resultElement = $('#siberian-test-result-' + provider);
            resultElement.html('<span class="dashicons dashicons-update spinning"></span> Redirecting to Google authentication...').css({
                'opacity': 1,
                'color': '#006699'
            });
            
            // Get the current URL to return to after authentication
            const returnUrl = encodeURIComponent(window.location.href.split('#')[0] + '&tab_id=backup_restore#settings');
            // Get the site URL (domain part of the current URL)
            const siteUrl = encodeURIComponent(window.location.origin);
            
            // Check if we should use the central API
            const usesCentralApi = $('.siberian-auth-provider-button[data-provider="gdrive"]').data('use-central-api');
            
            if (usesCentralApi) {
                // Use the centralized API endpoint
                const authUrl = '/wp-json/swiftspeed-gdrive-api/v1/auth?site_url=' + siteUrl + '&return_url=' + returnUrl;
                window.location.href = authUrl;
            } else {
                // Use the original AJAX endpoint as fallback
                const authUrl = swsib_backup_restore.ajax_url + 
                             '?action=swsib_gdrive_auth_redirect' +
                             '&nonce=' + swsib_backup_restore.nonce + 
                             '&site_url=' + siteUrl +
                             '&return_url=' + returnUrl;
                window.location.href = authUrl;
            }
        }
    }

    /**
     * Clear restore history
     */
    function clearRestoreHistory() {
        if (!confirm(swsib_backup_restore.confirm_delete_history)) {
            return;
        }
        
        const button = $(this);
        const originalText = button.html();
        
        // Disable button and show loading indicator
        button.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Clearing...');
        
        $.ajax({
            url: swsib_backup_restore.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_delete_restore_history',
                nonce: swsib_backup_restore.nonce
            },
            success: function(response) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                if (response.success) {
                    // Remove the restore history section
                    $('.siberian-restore-history').slideUp(300, function() {
                        $(this).remove();
                    });
                    
                    // Show success toast
                    showToast(response.data.message || "Restore history cleared successfully", 'success');
                } else {
                    showToast(response.data.message || "Failed to clear restore history", 'error');
                }
            },
            error: function(xhr, status, error) {
                // Re-enable button
                button.prop('disabled', false).html(originalText);
                
                let errorMessage = extractErrorMessage(xhr, error);
                showToast("Error: " + errorMessage, 'error');
            }
        });
    }

    /**
     * Extract error message from AJAX response
     */
    function extractErrorMessage(xhr, defaultError) {
        let errorMessage = 'Error: ' + (defaultError || 'Unknown error');
        
        if (xhr.responseText) {
            // Try to extract error message from HTML response
            const htmlErrorMatch = xhr.responseText.match(/<p>(.*?)<\/p>/);
            if (htmlErrorMatch && htmlErrorMatch[1]) {
                errorMessage = 'Server error: ' + htmlErrorMatch[1];
            } else {
                // Try to parse as JSON
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response && response.data && response.data.message) {
                        errorMessage = response.data.message;
                    }
                } catch (e) {
                    // Not JSON, use original error
                }
            }
        }
        
        return errorMessage;
    }
    
    /**
     * Show toast notification
     */
    function showToast(message, type = 'info') {
        // Remove any existing toast
        $('.siberian-toast').remove();
        
        // Create toast container if it doesn't exist
        if ($('#siberian-toast-container').length === 0) {
            $('body').append('<div id="siberian-toast-container"></div>');
        }
        
        // Create toast element
        const toast = $('<div class="siberian-toast siberian-toast-' + type + '"></div>');
        
        // Add icon based on type
        let icon = 'info';
        if (type === 'success') icon = 'yes-alt';
        else if (type === 'error') icon = 'warning';
        else if (type === 'warning') icon = 'warning';
        
        toast.html('<span class="dashicons dashicons-' + icon + '"></span>' + message);
        
        // Add toast to container
        $('#siberian-toast-container').append(toast);
        
        // Animate toast
        setTimeout(function() {
            toast.addClass('show');
            
            // Auto remove after delay
            setTimeout(function() {
                toast.removeClass('show');
                setTimeout(function() {
                    toast.remove();
                }, 300);
            }, 5000);
        }, 100);
    }
    
    /**
     * Load backup history if needed
     */
    function loadBackupHistoryIfNeeded() {
        // Check if backup tab is visible or becomes visible
        if ($('#backup').is(':visible') && $('#siberian-backup-history-list .siberian-loading-indicator').length > 0) {
            // Send AJAX request to load backup history
            $.ajax({
                url: swsib_backup_restore.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_get_backup_history',
                    nonce: swsib_backup_restore.backup_nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Replace loading indicator with backup history
                        var historyList = $('#siberian-backup-history-list');
                        
                        if (Object.keys(response.data.history).length === 0) {
                            historyList.html('<p class="siberian-no-backups-message">' + swsib_backup_restore.no_backups + '</p>');
                        } else {
                            // Render backup history table
                            renderBackupHistory(response.data.history, historyList);
                        }
                    } else {
                        $('#siberian-backup-history-list').html(
                            '<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + 
                            (response.data.message || "Failed to load backup history") + '</p></div>'
                        );
                    }
                },
                error: function() {
                    $('#siberian-backup-history-list').html(
                        '<div class="swsib-notice error"><p><span class="dashicons dashicons-warning"></span> ' + 
                        "Network error while loading backup history" + '</p></div>'
                    );
                }
            });
        }
    }
});