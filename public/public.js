/**
 * SwiftSpeed Siberian Integration
 * Public Scripts - Direct form submission version
 */
jQuery(document).ready(function($) {
    console.log('Siberian button script loaded (direct form submission version)');
    
    // Get custom notification text from localized variable
    var notificationText = (typeof swsib_vars !== 'undefined' && swsib_vars.notification_text) 
                          ? swsib_vars.notification_text 
                          : 'Connecting to your app dashboard. Please wait...';
    
    // Ensure button color stays applied after page loads fully
    function preserveButtonStyles() {
        $('.swsib-button').each(function() {
            // Store the inline background color if it exists
            var $button = $(this);
            var inlineColor = $button.attr('style');
            
            if (inlineColor && inlineColor.indexOf('background-color') !== -1) {
                // Extract just the background color value
                var bgColorMatch = inlineColor.match(/background-color:\s*([^;]+)/i);
                if (bgColorMatch && bgColorMatch[1]) {
                    var bgColor = bgColorMatch[1].trim();
                    
                    // Apply it again to ensure it sticks
                    setTimeout(function() {
                        $button.css('background-color', bgColor);
                    }, 50);
                }
            }
        });
    }
    
    // Run once when page loads
    preserveButtonStyles();
    
    // Also run after a short delay to catch any post-load changes
    setTimeout(preserveButtonStyles, 100);
    
    // Handle auto-login button clicks
    $('.swsib-button').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $container = $button.parent();
        
        // Remove any existing notifications
        $('.swsib-notification').remove();
        
        // Show processing notification with custom text
        var $notification = $('<div class="swsib-notification swsib-processing">' + notificationText + '</div>');
        $container.append($notification);
        
        // Track original button state without changing it
        setTimeout(function() {
            // Create and submit a form to the button's href
            var $form = $('<form action="' + $button.attr('href') + '" method="post"></form>');
            $('body').append($form);
            $form.submit();
        }, 1000); // Short delay to show the notification
    });
});