/**
 * SwiftSpeed Siberian Integration
 * Public Scripts
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        console.log('SwiftSpeed Siberian public JS loaded');
        
        // Debug: Log the notification variables to ensure they're being passed correctly
        if (typeof swsib_vars !== 'undefined') {
            console.log('Notification text:', swsib_vars.notification_text);
            console.log('Login notification text:', swsib_vars.login_notification_text);
        } else {
            console.error('swsib_vars is not defined. Script localization may have failed.');
        }
        
        // Handle login button click (for non-logged-in users)
        $(document).on('click', '.swsib-login-required .swsib-button', function(e) {
            e.preventDefault();
            
            var href = $(this).attr('href');
            
            // Make sure we use the login notification text
            var message = (typeof swsib_vars !== 'undefined' && swsib_vars.login_notification_text) 
                ? swsib_vars.login_notification_text 
                : 'You are being redirected to login page. Please wait...';
            
            console.log('Login button clicked, showing message:', message);
            
            // Show notification overlay with login redirect text
            showLoadingOverlay(message);
            
            // Redirect after a short delay
            setTimeout(function() {
                window.location.href = href;
            }, 1000);
        });
        
        // Handle main auth button click (for logged-in users)
        $(document).on('click', '.swsib-button:not(.swsib-login-required .swsib-button, .swsib-stripe-portal-btn, .swsib-cancel-btn, .swsib-uncancel-btn, .swsib-renew-btn)', function(e) {
            if ($(this).attr('href')) {
                e.preventDefault();
                
                var href = $(this).attr('href');
                
                // Make sure we use the main notification text
                var message = (typeof swsib_vars !== 'undefined' && swsib_vars.notification_text) 
                    ? swsib_vars.notification_text 
                    : 'Connecting to Siberian. Please wait...';
                
                console.log('Auth button clicked, showing message:', message);
                
                // Show notification overlay with main auth text
                showLoadingOverlay(message);
                
                // Redirect after a short delay
                setTimeout(function() {
                    window.location.href = href;
                }, 1000);
            }
        });
        
        // Initialize any loading overlay functions if they exist
        if (typeof initStripePortalButton === 'function') {
            initStripePortalButton();
        }
        
        if (typeof initSubscriptionManagement === 'function') {
            initSubscriptionManagement();
        }
    });
    
    /**
     * Show the loading overlay with a custom message
     * This matches the structure in frontend-shortcode.php
     */
    function showLoadingOverlay(message) {
        // Remove any existing overlay to prevent duplicates
        $('.swsib-loading-overlay').remove();
        
        // Create the overlay with the same structure as in frontend-shortcode.php
        var $overlay = $('<div class="swsib-loading-overlay"></div>');
        var $spinner = $('<div class="swsib-loading-spinner"></div>');
        var $message = $('<div class="swsib-loading-message">' + message + '</div>');
        
        // Append elements to the overlay and then to the body
        $overlay.append($spinner).append($message);
        $('body').append($overlay);
        
        // Show the overlay
        $overlay.fadeIn(300);
        
        return $overlay;
    }
    
    /**
     * Hide the loading overlay
     */
    function hideLoadingOverlay() {
        $('.swsib-loading-overlay').fadeOut(300, function() {
            $(this).remove();
        });
    }
    
    // Make these functions globally available
    window.swsib = window.swsib || {};
    window.swsib.showLoadingOverlay = showLoadingOverlay;
    window.swsib.hideLoadingOverlay = hideLoadingOverlay;
    
})(jQuery);/**
 * SwiftSpeed Siberian Integration
 * Public Scripts
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        console.log('SwiftSpeed Siberian public JS loaded');
        
        // Debug: Log the notification variables to ensure they're being passed correctly
        if (typeof swsib_vars !== 'undefined') {
            console.log('Notification text:', swsib_vars.notification_text);
            console.log('Login notification text:', swsib_vars.login_notification_text);
        } else {
            console.error('swsib_vars is not defined. Script localization may have failed.');
        }
        
        // Handle login button click (for non-logged-in users)
        $(document).on('click', '.swsib-login-required .swsib-button', function(e) {
            e.preventDefault();
            
            var href = $(this).attr('href');
            
            // Make sure we use the login notification text
            var message = (typeof swsib_vars !== 'undefined' && swsib_vars.login_notification_text) 
                ? swsib_vars.login_notification_text 
                : 'You are being redirected to login page. Please wait...';
            
            console.log('Login button clicked, showing message:', message);
            
            // Show notification overlay with login redirect text
            showNotification(message);
            
            // Redirect after a short delay
            setTimeout(function() {
                window.location.href = href;
            }, 1000);
        });
        
        // Handle main auth button click (for logged-in users)
        $(document).on('click', '.swsib-button:not(.swsib-login-required .swsib-button)', function(e) {
            e.preventDefault();
            
            var href = $(this).attr('href');
            
            // Make sure we use the main notification text
            var message = (typeof swsib_vars !== 'undefined' && swsib_vars.notification_text) 
                ? swsib_vars.notification_text 
                : 'Connecting to Siberian. Please wait...';
            
            console.log('Auth button clicked, showing message:', message);
            
            // Show notification overlay with main auth text
            showNotification(message);
            
            // Redirect after a short delay
            setTimeout(function() {
                window.location.href = href;
            }, 1000);
        });
    });
    
  
    
})(jQuery);