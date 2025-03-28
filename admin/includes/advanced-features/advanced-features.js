/**
 * SwiftSpeed Siberian Integration - DB Connect Features
 */
(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        console.log('DB Connect script loaded');
        
        // Test DB connection
        $('#test_db_connection').on('click', function(e) {
            // Prevent any form submission
            e.preventDefault();
            
            console.log('Test DB Connection button clicked');
            
            // Get form values
            var host = $('#swsib_options_db_connect_host').val();
            var database = $('#swsib_options_db_connect_database').val();
            var username = $('#swsib_options_db_connect_username').val();
            var password = $('#swsib_options_db_connect_password').val();
            var port = $('#swsib_options_db_connect_port').val() || '3306';
            
            // Validate required fields
            if (!host || !database || !username || !password) {
                $('#test_connection_result').html(
                    '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                ).show();
                return;
            }
            
            // Change button state
            var $button = $(this);
            var originalText = $button.val();
            $button.val(swsib_af_vars.testing_text).prop('disabled', true);
            
            // Remove previous notices
            $('#test_connection_result').html('').hide();
            
            // Make AJAX request
            $.ajax({
                url: swsib_af_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'swsib_test_db_connection',
                    nonce: swsib_af_vars.nonce,
                    host: host,
                    database: database,
                    username: username,
                    password: password,
                    port: port
                },
                success: function(response) {
                    console.log('AJAX response received', response);
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data ? response.data.message : (response.success ? 'Connection successful!' : 'Connection failed!');
                    
                    $('#test_connection_result').html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show();
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    $('#test_connection_result').html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.error_occurred + '</p></div>'
                    ).show();
                },
                complete: function() {
                    // Restore button state
                    $button.val(originalText).prop('disabled', false);
                }
            });
        });
        
        // Toggle DB Connect config visibility
        $('#swsib_options_db_connect_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#advanced-features-config').slideDown();
            } else {
                $('#advanced-features-config').slideUp();
            }
        });
    });
})(jQuery);