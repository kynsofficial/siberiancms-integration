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
        var isDbConfigured = swsib_autologin_vars.is_db_configured;
        var ajaxUrl = swsib_autologin_vars.ajax_url;
        var advancedFeaturesUrl = swsib_autologin_vars.advanced_features_url;
        
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
        
        // Display warning when sync existing role is changed
        $('#swsib_options_auto_login_sync_existing_role').on('change', function() {
            if ($(this).is(':checked')) {
                if (!confirm('Warning: This will update the roles of existing Siberian users when they log in. This could have unintended consequences. Are you sure you want to enable this?')) {
                    $(this).prop('checked', false);
                }
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
                $('#api_connection_result').html('<div class="swsib-notice error"><p>Please enter Siberian CMS URL</p></div>').show();
                $('#swsib_options_auto_login_siberian_url').focus();
                return;
            }
            
            if (!apiUser || !apiPassword) {
                $('#api_connection_result').html('<div class="swsib-notice error"><p>Please enter API credentials</p></div>').show();
                if (!apiUser) {
                    $('#swsib_options_auto_login_api_user').focus();
                } else {
                    $('#swsib_options_auto_login_api_password').focus();
                }
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Testing...').prop('disabled', true);
            $('#api_connection_result').html('').hide();
            
            // Make sure any other notifications are hidden
            $('.swsib-notice.settings-error').hide();
            
            // AJAX request to test API connection
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'swsib_test_api',
                    nonce: nonce,
                    url: siberianUrl,
                    user: apiUser,
                    password: apiPassword
                },
                success: function(response) {
                    console.log('API test response', response);
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = response.data && response.data.message ? response.data.message : 
                        (response.success ? 'API connection successful!' : 'API connection failed');
                    
                    // Show notification ONLY after the button
                    $('#api_connection_result').html(
                        '<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>'
                    ).show();
                    
                    // Scroll to make sure the notification is visible
                    $('html, body').animate({
                        scrollTop: $('#api_connection_result').offset().top - 100
                    }, 300);
                },
                error: function() {
                    // Show error notification ONLY after the button
                    $('#api_connection_result').html(
                        '<div class="swsib-notice error"><p>Error occurred during test. Please try again.</p></div>'
                    ).show();
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Setup Default Role ID field
        setupRoleIdField();
        
        // Load Siberian roles if database is configured
        if (isDbConfigured) {
            loadSiberianRoles();
        }
        
        /**
         * Setup the Role ID field based on DB configuration
         */
        function setupRoleIdField() {
            var $roleField = $('#swsib_options_auto_login_default_role_id');
            
            if (!isDbConfigured) {
                // Make the field truly read-only if DB is not configured
                $roleField.prop('readonly', true)
                    .val('2')
                    .addClass('disabled-field');
                
                // Prevent editing by capturing keydown events
                $roleField.on('keydown paste', function(e) {
                    e.preventDefault();
                    return false;
                });
            }
        }
        
        /**
         * Load Siberian roles via AJAX
         */
        function loadSiberianRoles() {
            $('#siberian-roles-loading').show();
            
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'swsib_get_siberian_roles',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.roles) {
                        populateRoleDropdown(response.data.roles);
                    } else {
                        handleRolesError(response.data ? response.data.message : 'Failed to load roles');
                    }
                },
                error: function() {
                    handleRolesError('Error communicating with server');
                },
                complete: function() {
                    $('#siberian-roles-loading').hide();
                }
            });
        }
        
/**
 * Populate role dropdown with fetched roles
 */
function populateRoleDropdown(roles) {
    var $dropdown = $('.siberian-role-dropdown');
    var currentValue = $dropdown.val();
    
    // Clear current options
    $dropdown.empty();
    
    // Track if we find a match for the current value
    var matchFound = false;
    var defaultRoleFound = false;
    
    // Add options for each role
    $.each(roles, function(index, role) {
        // Mark if default role (ID 2) is found
        if (role.role_id == 2) {
            defaultRoleFound = true;
        }
        
        // Prepare option text
        var optionText = 'Role ID ' + role.role_id + ', ' + role.code + ', ' + role.label;
        
        // Add the "(Standard SiberianCMS signup access)" only to role ID 2
        if (role.role_id == 2) {
            optionText += ' (Standard SiberianCMS signup access)';
        }
        
        // Create the option element
        var $option = $('<option>', {
            value: role.role_id,
            text: optionText
        });
        
        // Set as selected if it matches current value
        if (role.role_id == currentValue) {
            $option.prop('selected', true);
            matchFound = true;
        }
        
        $dropdown.append($option);
    });
    
    // If no match was found and current value is not 2, select default
    if (!matchFound && currentValue !== '2') {
        // If we have a default role (ID 2), select it
        if (defaultRoleFound) {
            $dropdown.val('2');
            console.log('Previously selected role ID ' + currentValue + ' not found. Using default (2)');
            
            // Add a notice if the current value is no longer available
            if (currentValue && currentValue !== '') {
                var warningHtml = '<div class="swsib-notice warning" style="margin-top: 10px;">' +
                    '<p><strong>Note:</strong> Your previously selected role ID (' + currentValue + ') is no longer available. ' +
                    'The default signup role (ID 2) has been selected.</p></div>';
                
                // Remove any existing notice first
                $('.siberian-role-warning').remove();
                
                // Add the warning after the dropdown
                $dropdown.after('<div class="siberian-role-warning">' + warningHtml + '</div>');
            }
        }
    }
}

/**
 * Handle errors when loading roles
 */
function handleRolesError(errorMessage) {
    var $roleField = $('#swsib_options_auto_login_default_role_id');
    
    if ($roleField.is('select')) {
        // It's a dropdown, clear it and add default option
        $roleField.empty().append(
            $('<option>', {
                value: '2',
                text: 'Role ID 2 (Standard SiberianCMS signup access)',
                selected: true
            })
        );
        
        // Show error message
        $roleField.after(
            '<div class="swsib-notice error" style="margin-top: 5px;">' +
            '<p>Error loading roles: ' + errorMessage + '</p>' +
            '<p>Using default signup role (ID 2).</p>' +
            '</div>'
        );
    } else {
        // It's an input field, make sure it's set to 2
        $roleField.val('2').prop('readonly', true).addClass('disabled-field');
    }
}
        
        /**
         * Super aggressive function to force button color updates
         */
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
        
        /**
         * Function to adjust color brightness
         */
        function adjustColor(color, amount) {
            return '#' + color.replace(/^#/, '').replace(/../g, function(hex) {
                var colorVal = parseInt(hex, 16);
                colorVal = Math.min(255, Math.max(0, colorVal + amount));
                return ('0' + colorVal.toString(16)).slice(-2);
            });
        }
        
        // Force immediate update after everything is loaded
        $(window).on('load', function() {
            setTimeout(function() {
                var bgColor = sessionStorage.getItem('swsib_button_bg_color') || currentBgColor;
                var textColor = sessionStorage.getItem('swsib_button_text_color') || currentTextColor;
                forceButtonColorUpdate(bgColor, textColor);
            }, 500);
            
            // Check if URL contains a section parameter for scrolling
            var urlParams = new URLSearchParams(window.location.search);
            var sectionTarget = urlParams.get('section');
            
            if (sectionTarget) {
                // Log for debugging
                console.log('Section target found in URL:', sectionTarget);
                
                // Try multiple methods to find the target section
                var $target = $('#' + sectionTarget);
                
                // If not found directly, try variations or fallbacks
                if (!$target.length) {
                    // Try without "-section" suffix if it's there
                    if (sectionTarget.endsWith('-section')) {
                        var altTarget = sectionTarget.replace(/-section$/, '');
                        $target = $('#' + altTarget);
                        console.log('Trying alternative target:', altTarget);
                    }
                    
                    // If still not found, look for headings or sections with this text
                    if (!$target.length) {
                        // Try to find section by heading text
                        var targetText = sectionTarget.replace(/-/g, ' ').replace(/section$/, '').trim();
                        console.log('Looking for heading with text:', targetText);
                        
                        // Find headings that contain this text
                        var $headings = $('h3').filter(function() {
                            return $(this).text().toLowerCase().indexOf(targetText.toLowerCase()) !== -1;
                        });
                        
                        if ($headings.length) {
                            $target = $headings.first();
                            console.log('Found heading by text:', $target.text());
                        }
                    }
                }
                
                // If we found a target using any method, scroll to it
                if ($target && $target.length) {
                    console.log('Target found, scrolling to:', $target);
                    
                    // Delay slightly to ensure page is fully loaded
                    setTimeout(function() {
                        // Scroll to the target section with animation
                        $('html, body').animate({
                            scrollTop: $target.offset().top - 50
                        }, 500);
                        
                        // Add highlight class to draw attention
                        $target.addClass('swsib-highlight-section');
                        setTimeout(function() {
                            $target.removeClass('swsib-highlight-section');
                        }, 2000);
                    }, 300);
                } else {
                    console.log('Target section not found in DOM:', sectionTarget);
                }
            }
        });
    });
})(jQuery);