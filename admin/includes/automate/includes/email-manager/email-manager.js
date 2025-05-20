/**
 * Email Manager JavaScript - Handles SMTP settings
 */
jQuery(document).ready(function($) {
    
    // Toggle SMTP settings visibility based on checkbox
    $('input[name="use_smtp"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('#smtp-settings-fields').slideDown();
        } else {
            $('#smtp-settings-fields').slideUp();
        }
    });
    
    // Toggle SMTP authentication fields visibility
    $('input[name="smtp_auth"]').on('change', function() {
        if ($(this).is(':checked')) {
            $('#smtp-auth-fields').slideDown();
        } else {
            $('#smtp-auth-fields').slideUp();
        }
    });
    
    // Handle saving SMTP settings
    $('.save-smtp-settings').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('Saving...').prop('disabled', true);
        
        var formData = $('#smtp-settings-form').serialize();
        
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_save_smtp_settings',
                nonce: swsib_automate.nonce,
                settings: formData
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice('success', response.data.message);
                } else {
                    // Show error message
                    showNotice('error', response.data.message);
                }
                
                // Restore button state
                $button.text(originalText).prop('disabled', false);
            },
            error: function() {
                // Show error message
                showNotice('error', 'An error occurred while saving settings.');
                
                // Restore button state
                $button.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Handle test email
    $('.smtp-test-button').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('Sending...').prop('disabled', true);
        
        // Get test email address or use placeholder
        var testEmail = $('#test_email').val() || $('#test_email').attr('placeholder');
        
        // Validate that we have necessary SMTP settings
        if ($('input[name="use_smtp"]').is(':checked')) {
            var smtpHost = $('#smtp_host').val();
            var smtpPort = $('#smtp_port').val();
            
            if (!smtpHost || !smtpPort) {
                showNotice('error', 'Please fill in the SMTP Host and Port fields.');
                $button.text(originalText).prop('disabled', false);
                return;
            }
            
            if ($('input[name="smtp_auth"]').is(':checked')) {
                var smtpUsername = $('#smtp_username').val();
                var smtpPassword = $('#smtp_password').val();
                
                if (!smtpUsername || !smtpPassword) {
                    showNotice('error', 'Please fill in the SMTP Username and Password fields.');
                    $button.text(originalText).prop('disabled', false);
                    return;
                }
            }
        }
        
        $.ajax({
            url: swsib_automate.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_test_smtp_settings',
                nonce: swsib_automate.nonce,
                test_email: testEmail
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice('success', response.data.message);
                } else {
                    // Show error message
                    showNotice('error', response.data.message);
                }
                
                // Restore button state
                $button.text(originalText).prop('disabled', false);
            },
            error: function() {
                // Show error message
                showNotice('error', 'An error occurred while testing SMTP settings.');
                
                // Restore button state
                $button.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Helper function to show notices
    function showNotice(type, message) {
        // Remove any existing notices
        $('.smtp-notice').remove();
        
        // Create notice element
        var $notice = $('<div class="smtp-notice notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        
        // Insert notice before the form
        $('#smtp-settings-form').before($notice);
        
        // Add dismiss button
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        
        // Handle dismiss click
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
});