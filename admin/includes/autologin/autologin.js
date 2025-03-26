/**
 * SwiftSpeed Siberian Integration - Auto Login JavaScript
 */
(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        // Get the PHP variables that were localized for us
        var currentBgColor = swsib_autologin_vars.button_color;
        var currentTextColor = swsib_autologin_vars.button_text_color;
        var nonce = swsib_autologin_vars.nonce;
        
        // Store in sessionStorage right away
        sessionStorage.setItem('swsib_button_bg_color', currentBgColor);
        sessionStorage.setItem('swsib_button_text_color', currentTextColor);
        
        // Force immediate update with multiple techniques
        forceButtonColorUpdate(currentBgColor, currentTextColor);
        
        // EXTREMELY AGGRESSIVE: Apply colors multiple times with increasing delays
        for (var i = 1; i <= 5; i++) {
            (function(delay) {
                setTimeout(function() {
                    forceButtonColorUpdate(currentBgColor, currentTextColor);
                }, delay * 100); // 100ms, 200ms, 300ms, 400ms, 500ms
            })(i);
        }
        
        // Update button preview when text changes
        $('#swsib_options_auto_login_autologin_text').on('input change', function() {
            var text = $(this).val() || 'App Dashboard';
            $('.button-preview .swsib-button').text(text);
        });
        
        // Update button preview when color changes
        $('.swsib-color-picker').wpColorPicker({
            change: function(event, ui) {
                var color = ui.color.toString();
                var id = $(this.el).attr('id');
                
                if (id === 'swsib_options_auto_login_button_color') {
                    sessionStorage.setItem('swsib_button_bg_color', color);
                    var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
                    forceButtonColorUpdate(color, textColor);
                } else if (id === 'swsib_options_auto_login_button_text_color') {
                    sessionStorage.setItem('swsib_button_text_color', color);
                    var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
                    forceButtonColorUpdate(bgColor, color);
                } else if (id === 'swsib_options_auto_login_processing_bg_color') {
                    $('#processing-preview-container').css('background-color', color);
                } else if (id === 'swsib_options_auto_login_processing_text_color') {
                    $('#processing-preview-container').css('color', color);
                }
            }
        });
        
        // Add event listeners to force style updates on any interaction
        $('.button-preview .swsib-button, #login-button-preview').on('mouseenter mouseleave mousemove focus blur', function() {
            var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
            var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
            forceButtonColorUpdate(bgColor, textColor);
        });
        
        // Check every 5 seconds to ensure colors don't revert
        setInterval(function() {
            var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
            var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
            forceButtonColorUpdate(bgColor, textColor);
        }, 5000);
        
        // Also refresh when tab becomes visible again
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
                var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
                forceButtonColorUpdate(bgColor, textColor);
            }
        });
        
        // Super aggressive function to force button color updates
        function forceButtonColorUpdate(bgColor, textColor) {
            var previewButton = $('.button-preview .swsib-button')[0];
            var loginButton = $('#login-button-preview')[0];
            
            if (!previewButton || !loginButton) return;
            
            // METHOD 1: Direct inline style attribute (highest priority)
            $('.button-preview .swsib-button').attr('style', 
                'background-color: ' + bgColor + ' !important; ' + 
                'color: ' + textColor + ' !important'
            );
            
            $('#login-button-preview').attr('style', 
                'background-color: ' + bgColor + ' !important; ' + 
                'color: ' + textColor + ' !important; ' + 
                'display: inline-block; ' + 
                'padding: 10px 20px; ' + 
                'border-radius: 4px; ' + 
                'text-decoration: none; ' + 
                'font-weight: 600; ' + 
                'transition: all 0.3s ease; ' + 
                'box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05); ' + 
                'border: none; ' + 
                'cursor: pointer;'
            );
            
            // METHOD 2: Direct style property manipulation
            previewButton.style.setProperty('background-color', bgColor, 'important');
            previewButton.style.setProperty('color', textColor, 'important');
            
            loginButton.style.setProperty('background-color', bgColor, 'important');
            loginButton.style.setProperty('color', textColor, 'important');
            
            // METHOD 3: Add custom style tag with high specificity
            var styleId = 'swsib-button-custom-styles';
            var existingStyle = document.getElementById(styleId);
            
            if (existingStyle) {
                document.head.removeChild(existingStyle);
            }
            
            var style = document.createElement('style');
            style.id = styleId;
            style.innerHTML = `
                .swsib-button, #login-button-preview {
                    background-color: ${bgColor} !important;
                    color: ${textColor} !important;
                }
                .swsib-button:hover, #login-button-preview:hover {
                    background-color: ${adjustColor(bgColor, -20)} !important;
                }
            `;
            document.head.appendChild(style);
            
            // METHOD 4: Force repaint by minimal DOM manipulation
            previewButton.classList.add('swsib-force-repaint');
            loginButton.classList.add('swsib-force-repaint');
            setTimeout(function() {
                previewButton.classList.remove('swsib-force-repaint');
                loginButton.classList.remove('swsib-force-repaint');
            }, 10);
            
            // Set the hover color CSS variable
            var hoverColor = adjustColor(bgColor, -20);
            document.documentElement.style.setProperty('--button-hover-color', hoverColor);
        }
        
        // Function to adjust color brightness
        function adjustColor(color, amount) {
            return '#' + color.replace(/^#/, '').replace(/../g, function(hex) {
                var colorVal = parseInt(hex, 16);
                colorVal = Math.min(255, Math.max(0, colorVal + amount));
                return ('0' + colorVal.toString(16)).slice(-2);
            });
        }
        
        // Toggle Siberian configuration settings visibility
        $('#swsib_options_auto_login_enable_siberian_config').on('change', function() {
            if ($(this).is(':checked')) {
                $('#siberian-config-settings').slideDown();
            } else {
                $('#siberian-config-settings').slideUp();
            }
        });
        
        // Toggle processing screen settings visibility
        $('#swsib_options_auto_login_auto_authenticate').on('change', function() {
            if ($(this).is(':checked')) {
                $('#auto-authenticate-settings').slideDown();
            } else {
                $('#auto-authenticate-settings').slideUp();
            }
        });
        
        // Toggle login redirect settings visibility
        $('#swsib_options_auto_login_enable_login_redirect').on('change', function() {
            if ($(this).is(':checked')) {
                $('#login-redirect-settings').slideDown();
            } else {
                $('#login-redirect-settings').slideUp();
            }
        });
        
        // Update processing text preview
        $('#swsib_options_auto_login_processing_text').on('input change', function() {
            var text = $(this).val() || 'Processing...';
            $('.processing-text').text(text);
        });
        
        // Update login message preview
        $('#swsib_options_auto_login_not_logged_in_message').on('input change', function() {
            var text = $(this).val() || 'You must be logged in to access or create an app.';
            $('.login-message').text(text);
        });
        
        // Update login button text preview
        $('#swsib_options_auto_login_login_button_text').on('input change', function() {
            var text = $(this).val() || 'Login';
            $('#login-button-preview').text(text);
        });
        
        // Test API connection
        $('#test_api_connection').on('click', function(e) {
            e.preventDefault();
            
            var siberianUrl = $('#swsib_options_auto_login_siberian_url').val();
            var apiUser = $('#swsib_options_auto_login_api_user').val();
            var apiPassword = $('#swsib_options_auto_login_api_password').val();
            
            if (!siberianUrl) {
                alert('Please enter Siberian CMS URL');
                $('#swsib_options_auto_login_siberian_url').focus();
                return;
            }
            
            if (!apiUser || !apiPassword) {
                alert('Please enter API credentials');
                if (!apiUser) {
                    $('#swsib_options_auto_login_api_user').focus();
                } else {
                    $('#swsib_options_auto_login_api_password').focus();
                }
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Testing...');
            $button.prop('disabled', true);
            
            // AJAX request to test API connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_test_api',
                    nonce: nonce,
                    url: siberianUrl,
                    user: apiUser,
                    password: apiPassword
                },
                success: function(response) {
                    if (response.success) {
                        alert('API connection successful!');
                    } else {
                        alert('API connection failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error testing API connection: ' + error);
                },
                complete: function() {
                    $button.text(originalText);
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Force immediate update after everything is loaded
        $(window).on('load', function() {
            setTimeout(function() {
                var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
                var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
                forceButtonColorUpdate(bgColor, textColor);
            }, 500);
        });
    });
})(jQuery);