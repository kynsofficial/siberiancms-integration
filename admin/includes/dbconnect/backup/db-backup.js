/**
 * SwiftSpeed Siberian Integration
 * DB Backup Scripts
 */
jQuery(document).ready(function($) {
    'use strict';

    // Update progress UI using returned backup data
    function updateProgressUI(data) {
        $('.backup-progress-fill').width(data.progress + '%');
        var statusText = data.message;
        if (data.estimated_remaining) {
            statusText += '<br/><span class="estimated-time">' + data.estimated_remaining + '</span>';
        }
        $('.backup-status-text').html(statusText);
        var statsText = '<div>Tables: ' + data.processed_tables + '/' + data.total_tables +
            ' (' + Math.round((data.processed_tables / data.total_tables) * 100) + '%)</div>';
        if (data.elapsed_time) {
            statsText += '<div>Elapsed time: ' + formatElapsedTime(data.elapsed_time) + '</div>';
        }
        $('.backup-stats').html(statsText);
    }

    // Format elapsed time into a friendly string
    function formatElapsedTime(seconds) {
        if (seconds < 60) {
            return seconds + ' sec';
        } else if (seconds < 3600) {
            var m = Math.floor(seconds / 60);
            var s = seconds % 60;
            return m + ' min ' + s + ' sec';
        } else {
            var h = Math.floor(seconds / 3600);
            var m = Math.floor((seconds % 3600) / 60);
            return h + ' hr ' + m + ' min';
        }
    }

    // Recursively process the next table
    function processNextTable() {
        $.ajax({
            url: swsib_db_backup.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'swsib_process_next_table',
                nonce: swsib_db_backup.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateProgressUI(response.data);
                    if (response.data.status === 'completed') {
                        $('.backup-status-text').html('Backup completed successfully! Reloading...');
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else if (response.data.status === 'error') {
                        $('.backup-status-text').html('Error: ' + response.data.message);
                        $('#start-db-backup').prop('disabled', false);
                    } else {
                        // Process next table after a short delay
                        setTimeout(processNextTable, 1000);
                    }
                } else {
                    $('.backup-status-text').html('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $('#start-db-backup').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('.backup-status-text').html('AJAX error: ' + error);
                $('#start-db-backup').prop('disabled', false);
            }
        });
    }

    // Start the backup process
    function startBackup() {
        $('#start-db-backup').prop('disabled', true);
        $('#backup-progress-container').show();
        $('.backup-progress-fill').width('5%');
        $('.backup-status-text').text(swsib_db_backup.starting_backup || 'Starting backup...');
        $.ajax({
            url: swsib_db_backup.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'swsib_start_backup',
                nonce: swsib_db_backup.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Begin processing tables
                    processNextTable();
                } else {
                    $('.backup-status-text').html('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $('#start-db-backup').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                $('.backup-status-text').html('AJAX error: ' + error);
                $('#start-db-backup').prop('disabled', false);
            }
        });
    }

    // Button event bindings
    $('#start-db-backup').on('click', function(e) {
        e.preventDefault();
        startBackup();
    });

    $('#cancel-db-backup').on('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to cancel the current backup process?')) {
            $.ajax({
                url: swsib_db_backup.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'swsib_cancel_backup',
                    nonce: swsib_db_backup.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Backup canceled successfully');
                        $('#backup-progress-container').hide();
                        $('#start-db-backup').prop('disabled', false);
                    } else {
                        alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('AJAX error: ' + error);
                }
            });
        }
    });
});
