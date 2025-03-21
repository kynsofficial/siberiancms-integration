/**
 * SwiftSpeed Siberian Integration
 * Password Sync Form Scripts - Improved version
 */
jQuery(document).ready(function($) {
    console.log('Siberian Password Sync script loaded (improved version)');
    
    // Timer variables
    let timerInterval;
    let remainingSeconds = 30; // Changed to 5 seconds for testing
    
    // Initialize timer if on OTP page
    initTimer();
    
    /**
     * Function to initialize the timer
     */
    function initTimer() {
        if ($('#swsib-step-otp').length > 0) {
            console.log('OTP step found, starting timer');
            startResendTimer();
        } else {
            console.log('OTP step not found');
        }
    }
    
    /**
     * Start the resend timer
     */
    function startResendTimer() {
        // Clear any existing interval
        if (timerInterval) {
            clearInterval(timerInterval);
        }
        
        // Reset timer to 5 seconds for testing
        remainingSeconds = 5;
        updateTimerDisplay();
        
        // Hide resend container, show timer
        $('#swsib-resend-container').hide();
        $('#swsib-resend-timer').parent().show();
        
        // Start countdown
        timerInterval = setInterval(function() {
            remainingSeconds--;
            updateTimerDisplay();
            
            if (remainingSeconds <= 0) {
                // Timer finished
                clearInterval(timerInterval);
                $('#swsib-resend-timer').parent().hide();
                $('#swsib-resend-container').show();
            }
        }, 1000);
    }
    
    /**
     * Update the timer display
     */
    function updateTimerDisplay() {
        $('#swsib-resend-timer').text(remainingSeconds);
    }
    
    /**
     * Show a message with specified type (error, success, info)
     */
    function showMessage(message, type = 'error') {
        const messageArea = $('#swsib-form-messages');
        messageArea.html(`<div class="swsib-message ${type}">${message}</div>`);
        
        // Scroll to message
        $('html, body').animate({
            scrollTop: messageArea.offset().top - 100
        }, 300);
    }
    
    // Apply styling to resend OTP link
    $('#swsib-resend-otp').css({
        'color': '#3a4b79',
        'text-decoration': 'none',
        'font-weight': '500',
        'cursor': 'pointer'
    });
    
    // Email verification form submission
    $('#swsib-email-form').on('submit', function() {
        var $button = $('#swsib-verify-email');
        $button.text('Processing...');
        $button.addClass('loading');
    });
    
    // OTP verification form submission
    $('#swsib-otp-form').on('submit', function(e) {
        const otp = $('#swsib-otp').val().trim();
        
        if (!otp || otp.length !== 6 || !/^\d+$/.test(otp)) {
            e.preventDefault();
            showMessage('Please enter a valid 6-digit verification code.', 'error');
            return false;
        }
        
        var $button = $('#swsib-verify-otp');
        $button.text('Processing...');
        $button.addClass('loading');
        return true;
    });
    
    // Password update form submission
    $('#swsib-password-form').on('submit', function(e) {
        const password = $('#swsib-password').val();
        const confirmPassword = $('#swsib-confirm-password').val();
        
        if (!password || password.length < 6) {
            e.preventDefault();
            showMessage('Password must be at least 6 characters long.', 'error');
            return false;
        }
        
        if (password !== confirmPassword) {
            e.preventDefault();
            showMessage('Passwords do not match.', 'error');
            return false;
        }
        
        var $button = $('#swsib-update-password');
        $button.text('Processing...');
        $button.addClass('loading');
        return true;
    });
    
    // Resend OTP click handler - Reverted to original working approach
    $('#swsib-resend-otp').on('click', function(e) {
        e.preventDefault();
        var $link = $(this);
        var originalText = $link.text();
        
        // Show sending state
        $link.text('Sending...');
        
        // Get form data
        var formData = $('#swsib-resend-form').serialize();
        
        // Add the action to the form data
        formData += '&swsib_form_action=resend_otp';
        
        // Submit the form via AJAX
        $.post(window.location.href, formData, function(response) {
            // Show success message
            showMessage('Verification code resent. Please check your email.', 'success');
        }).always(function() {
            // Reset the text after request completes
            setTimeout(function() {
                $link.text(originalText);
            }, 1000);
        });
        
        return false;
    });
    
    // Password validation feedback
    $('#swsib-password').on('input', function() {
        const password = $(this).val();
        const $requirements = $('.swsib-password-requirements p');
        
        if (password.length >= 6) {
            $requirements.css('color', '#34a853');
        } else {
            $requirements.css('color', '#ea4335');
        }
    });
    
    // Check if password and confirm password match
    $('#swsib-confirm-password').on('input', function() {
        const password = $('#swsib-password').val();
        const confirmPassword = $(this).val();
        
        if (confirmPassword && password !== confirmPassword) {
            $(this).css('border-color', '#ea4335');
        } else {
            $(this).css('border-color', '');
        }
    });
});