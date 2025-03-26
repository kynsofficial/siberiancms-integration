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
    
    /**
     * Show overlay notification
     */
    function showNotification(message) {
        // Remove any existing overlay to prevent duplicates
        $('.swsib-notification-overlay').remove();
        
        // Create overlay
        var $overlay = $('<div class="swsib-notification-overlay"></div>');
        var $message = $('<div class="swsib-notification-message">' + message + '</div>');
        var $spinner = $('<div class="swsib-notification-spinner"></div>');
        
        $overlay.append($message).append($spinner);
        $('body').append($overlay);
        
        // Force render and ensure overlay is visible
        $overlay.css({
            'position': 'fixed',
            'top': 0,
            'left': 0,
            'width': '100%',
            'height': '100%',
            'background-color': 'rgba(0, 0, 0, 0.8)',
            'z-index': 999999,
            'display': 'flex',
            'align-items': 'center',
            'justify-content': 'center',
            'flex-direction': 'column',
            'opacity': 0
        });
        
        $message.css({
            'color': '#ffffff',
            'font-size': '20px',
            'text-align': 'center',
            'margin-bottom': '20px',
            'max-width': '80%',
            'line-height': '1.4'
        });
        
        $spinner.css({
            'width': '40px',
            'height': '40px',
            'border': '4px solid rgba(255, 255, 255, 0.3)',
            'border-radius': '50%',
            'border-top-color': '#ffffff',
            'animation': 'swsib-spin 1s ease-in-out infinite'
        });
        
        // Add the keyframe animation if it doesn't exist
        if ($('#swsib-spinner-keyframes').length === 0) {
            $('head').append(
                '<style id="swsib-spinner-keyframes">@keyframes swsib-spin { to { transform: rotate(360deg); } }</style>'
            );
        }
        
        // Force reflow
        $overlay[0].offsetHeight;
        
        // Animate in
        $overlay.animate({ opacity: 1 }, 300);
    }
    
})(jQuery);