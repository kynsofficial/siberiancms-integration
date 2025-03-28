(function($) {
    'use strict';
    
    $(document).ready(function() {
        $('#test_db_connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            // Use .text() because the button element uses inner text
            var originalText = $button.text();
            var $result = $('#test_connection_result');
            
            // Get form values
            var host = $('#swsib_options_db_connect_host').val();
            var database = $('#swsib_options_db_connect_database').val();
            var username = $('#swsib_options_db_connect_username').val();
            var password = $('#swsib_options_db_connect_password').val();
            var port = $('#swsib_options_db_connect_port').val() || '3306';
            
            // Validate required fields
            if (!host || !database || !username || !password) {
                $result.html(
                    '<div class="swsib-notice error"><p>' + swsib_af_vars.fill_required_fields + '</p></div>'
                ).show();
                return;
            }
            
            // Change button state: update text and disable it
            $button.text(swsib_af_vars.testing_text).prop('disabled', true);
            $result.removeClass('success error').hide().html('');
            
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
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data ? response.data.message : (response.success ? 'Connection successful!' : 'Connection failed!');
                    
                    $result.html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show().delay(3000).fadeOut(300, function() {
                        $button.text(originalText).prop('disabled', false);
                    });
                    
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 300);
                },
                error: function() {
                    $result.html(
                        '<div class="swsib-notice error"><p>' + swsib_af_vars.error_occurred + '</p></div>'
                    ).show().delay(3000).fadeOut(300, function() {
                        $button.text(originalText).prop('disabled', false);
                    });
                },
                complete: function() {
                    // Fallback timer in case fadeOut callback does not fire
                    setTimeout(function() {
                        $button.text(originalText).prop('disabled', false);
                    }, 5000);
                }
            });
        });
        
        // Toggle DB Connect configuration visibility
        $('#swsib_options_db_connect_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#advanced-features-config').slideDown();
            } else {
                $('#advanced-features-config').slideUp();
            }
        });
    });
})(jQuery);
