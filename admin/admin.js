/**
 * SwiftSpeed Siberian Integration
 * Admin Scripts
 */
(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        console.log('SwiftSpeed Siberian admin JS loaded');

        // Initialize tab navigation
        initTabs();
        
        // Initialize toggle switches
        initToggleSwitches();

        // Initialize color picker
        if ($.fn.wpColorPicker) {
            $('.swsib-color-picker').wpColorPicker({
                change: function(event, ui) {
                    // Use toHexString() to get a valid hex color
                    var color = ui.color.toString();
                    var id = $(this.el).attr('id');
                    
                    if (id === 'swsib_options_auto_login_button_color') {
                        // Store in localStorage for persistence
                        localStorage.setItem('swsib_button_bg_color', color);
                        updateButtonPreview(color);
                    } else if (id === 'swsib_options_auto_login_button_text_color') {
                        localStorage.setItem('swsib_button_text_color', color);
                        updateButtonPreview(undefined, color);
                    } else if (id === 'swsib_options_auto_login_processing_bg_color') {
                        $('#processing-preview-container').css('background-color', color);
                    } else if (id === 'swsib_options_auto_login_processing_text_color') {
                        $('#processing-preview-container').css('color', color);
                    }
                },
                clear: function() {
                    var id = $(this.el).attr('id');
                    if (id === 'swsib_options_auto_login_button_color') {
                        localStorage.setItem('swsib_button_bg_color', '#3a4b79');
                        updateButtonPreview('#3a4b79');
                    } else if (id === 'swsib_options_auto_login_button_text_color') {
                        localStorage.setItem('swsib_button_text_color', '#ffffff');
                        updateButtonPreview(undefined, '#ffffff');
                    }
                }
            });
        }
        
        // Force an initial update of the button preview shortly after load
        setTimeout(function() {
            var storedBgColor = localStorage.getItem('swsib_button_bg_color');
            var initialColor = storedBgColor || $('#swsib_options_auto_login_button_color').val() || '#3a4b79';
            var storedTextColor = localStorage.getItem('swsib_button_text_color');
            var initialTextColor = storedTextColor || $('#swsib_options_auto_login_button_text_color').val() || '#ffffff';
            
            updateButtonPreview(initialColor, initialTextColor);
        }, 100);
        
        // Add periodic refresh to ensure colors don't revert
        setInterval(function() {
            var storedBgColor = localStorage.getItem('swsib_button_bg_color');
            var storedTextColor = localStorage.getItem('swsib_button_text_color');
            updateButtonPreview(storedBgColor, storedTextColor);
        }, 60000); // Check every minute
        
        // Also refresh when tab becomes visible again
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                var storedBgColor = localStorage.getItem('swsib_button_bg_color');
                var storedTextColor = localStorage.getItem('swsib_button_text_color');
                updateButtonPreview(storedBgColor, storedTextColor);
            }
        });
        
        // DB Connect toggle (renamed from Advanced features)
        $('#swsib_options_db_connect_enabled').on('change', function() {
            toggleDbConnect();
        });
        
        // Initialize DB Connect visibility
        toggleDbConnect();
        
        // Test API connection - COMPLETELY REVISED
        $('#test_api_connection').on('click', function(e) {
            e.preventDefault();
            
            var siberianUrl = $('#swsib_options_auto_login_siberian_url').val();
            var apiUser = $('#swsib_options_auto_login_api_user').val();
            var apiPassword = $('#swsib_options_auto_login_api_password').val();
            
            // Show validation errors directly in the #api_connection_result
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
            
            // Hide any existing notices that might be at the top of the page
            $('.swsib-notice.settings-error').hide();
            $('.swsib-header + .swsib-notice').remove();
            
            // AJAX request to test API connection
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_test_api',
                    nonce: swsib_admin.nonce,
                    url: siberianUrl,
                    user: apiUser,
                    password: apiPassword
                },
                success: function(response) {
                    // ONLY show notification in #api_connection_result
                    if (response.success) {
                        $('#api_connection_result').html(
                            '<div class="swsib-notice success"><p><strong>Success:</strong> API connection successful!</p></div>'
                        ).show();
                    } else {
                        $('#api_connection_result').html(
                            '<div class="swsib-notice error"><p><strong>Error:</strong> ' + (response.data ? response.data.message : 'API connection failed') + '</p></div>'
                        ).show();
                    }
                },
                error: function(xhr, status, error) {
                    // ONLY show notification in #api_connection_result
                    $('#api_connection_result').html(
                        '<div class="swsib-notice error"><p><strong>Error:</strong> ' + error + '</p></div>'
                    ).show();
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Form validation override for hidden required fields
        $('.swsib-settings-form').on('submit', function(e) {
            // If DB Connect is disabled, remove required attribute from database fields
            if (!$('#swsib_options_db_connect_enabled').is(':checked')) {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', false);
            }
            
            // Get current tab
            var activeTabId = getActiveTabId();
            
            // If we're not on the DB Connect tab, ensure db_connect fields aren't required
            if (activeTabId !== 'db_connect') {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', false);
            }
            
            // Debug submit
            console.log('Form submitted for tab: ' + activeTabId);
        });
        
        // Test DB connection
        $('#test_connection').on('click', function(e) {
            // Let the form submit normally but add a parameter
            $(this).closest('form').append('<input type="hidden" name="test_connection" value="1">');
        });
        
        // Disable tabs if DB not configured
        if (!swsib_admin.is_db_configured) {
            $('.swsib-tabs a.disabled').on('click', function(e) {
                e.preventDefault();
                showNotice('warning', 'You must configure database connection settings first.');
            });
        }
        
        // Update button preview when text changes
        $('#swsib_options_auto_login_autologin_text').on('input change', function() {
            var text = $(this).val() || 'App Dashboard';
            $('.button-preview .swsib-button').text(text);
        });
        
        // Update login button text preview
        $('#swsib_options_auto_login_login_button_text').on('input change', function() {
            var text = $(this).val() || 'Login';
            $('#login-button-preview').text(text);
        });
        
        // Update login message preview
        $('#swsib_options_auto_login_not_logged_in_message').on('input change', function() {
            var text = $(this).val() || 'You must be logged in to access or create an app.';
            $('.login-message').text(text);
        });
        
        // Toggle auto-authenticate settings visibility
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
        
        // Toggle Siberian configuration settings visibility
        $('#swsib_options_auto_login_enable_siberian_config').on('change', function() {
            if ($(this).is(':checked')) {
                $('#siberian-config-settings').slideDown();
            } else {
                $('#siberian-config-settings').slideUp();
            }
        });
        
        // Initialize button preview with current settings
        var storedBgColor = localStorage.getItem('swsib_button_bg_color');
        var initialColor = storedBgColor || $('#swsib_options_auto_login_button_color').val() || '#3a4b79';
        updateButtonPreview(initialColor);
        
        // Set initial button text
        var buttonText = $('#swsib_options_auto_login_autologin_text').val() || 'App Dashboard';
        $('.button-preview .swsib-button').text(buttonText);
        
        // Set initial login button text
        var loginButtonText = $('#swsib_options_auto_login_login_button_text').val() || 'Login';
        $('#login-button-preview').text(loginButtonText);
        
        // Restore active tab from URL parameter and handle scrolling to sections
        restoreActiveTab();
    });
    
    /**
     * Get active tab ID
     */
    function getActiveTabId() {
        return $('.swsib-tabs a.active').attr('data-tab-id');
    }
    
    /**
     * Restore active tab from URL parameter and handle section scrolling
     */
    function restoreActiveTab() {
        // Get tab ID from URL parameter
        var urlParams = new URLSearchParams(window.location.search);
        var tabFromUrl = urlParams.get('tab_id');
        var sectionTarget = urlParams.get('section');
        
        if (tabFromUrl) {
            // Convert tab_id format to tab selector format (auto_login -> #auto-login-tab)
            var tabSelector = '#' + tabFromUrl.replace(/_/g, '-') + '-tab';
            
            // Activate the tab
            activateTab(tabSelector);
            
            // If there's a section parameter and we're in the auto_login tab, handle scrolling
            if (sectionTarget && tabFromUrl === 'auto_login') {
                // Delay to ensure the tab content is fully visible and loaded
                setTimeout(function() {
                    scrollToSection(sectionTarget);
                }, 1000); // Increased delay to 1000ms for more reliability
            }
        } else {
            // Default to first tab
            activateTab($('.swsib-tabs a:first').attr('href'));
        }
    }
    
    /**
     * Scroll to a specific section and highlight it - IMPROVED
     */
    function scrollToSection(sectionId) {
        var $target = $('#' + sectionId);
        if ($target.length) {
            console.log('Scrolling to section: ' + sectionId);
            
            // Scroll to the target section
            $('html, body').animate({
                scrollTop: $target.offset().top - 50
            }, 800); // Slower animation for more reliability
            
            // Multiple highlight attempts with increasing delays for reliability
            setTimeout(function() {
                highlightSection($target);
            }, 100);
            
            setTimeout(function() {
                highlightSection($target);
            }, 500);
            
            setTimeout(function() {
                highlightSection($target);
            }, 1000);
        } else {
            console.log('Section not found: ' + sectionId);
            
            // Try again with some common section prefixes/suffixes
            var alternateIds = [
                'section-' + sectionId,
                sectionId + '-section',
                sectionId.replace('-section', ''),
                sectionId.replace('section-', '')
            ];
            
            // Try each alternate ID
            for (var i = 0; i < alternateIds.length; i++) {
                $target = $('#' + alternateIds[i]);
                if ($target.length) {
                    console.log('Found alternate section ID: ' + alternateIds[i]);
                    $('html, body').animate({
                        scrollTop: $target.offset().top - 50
                    }, 800);
                    
                    highlightSection($target);
                    break;
                }
            }
        }
    }
    
    /**
     * Helper function to highlight a section
     */
    function highlightSection($target) {
        $target.addClass('swsib-highlight-section');
        setTimeout(function() {
            $target.removeClass('swsib-highlight-section');
        }, 2000);
    }
    
    /**
     * Activate a specific tab
     */
    function activateTab(tabSelector) {
        // Only activate if the tab exists and is not disabled
        var $tab = $('.swsib-tabs a[href="' + tabSelector + '"]');
        if ($tab.length && !$tab.hasClass('disabled')) {
            // Get the tab ID
            var tabId = $tab.attr('data-tab-id');
            
            // Remove active class from all tabs and tab contents
            $('.swsib-tabs a').removeClass('active');
            $('.swsib-tab-content').removeClass('active');
            
            // Add active class to specified tab and its content
            $tab.addClass('active');
            $(tabSelector).addClass('active');
            
            // Update hidden fields in all forms
            $('input[name="tab_id"]').val(tabId);
            
            // Update URL with tab ID while preserving other parameters
            var newUrl = updateQueryStringParameter(window.location.href, 'tab_id', tabId);
            if (window.history && window.history.pushState) {
                window.history.pushState({}, '', newUrl);
            }
            
            // Update required attributes based on active tab
            if (tabSelector === '#db-connect-tab' && $('#swsib_options_db_connect_enabled').is(':checked')) {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', true);
            } else {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', false);
            }
            
            // If the activated tab is Auto Login, update the button preview
            if (tabId === 'auto_login') {
                var storedBgColor = localStorage.getItem('swsib_button_bg_color');
                var storedTextColor = localStorage.getItem('swsib_button_text_color');
                var bgColor = storedBgColor || $('#swsib_options_auto_login_button_color').val() || '#3a4b79';
                var textColor = storedTextColor || $('#swsib_options_auto_login_button_text_color').val() || '#ffffff';
                updateButtonPreview(bgColor, textColor);
            }
            
            console.log('Tab activated: ' + tabId);
        }
    }
    
    /**
     * Initialize tabs functionality
     */
    function initTabs() {
        // Set first tab as active if none is
        if ($('.swsib-tab-content.active').length === 0) {
            $('.swsib-tabs a:first').addClass('active');
            $('.swsib-tab-content:first').addClass('active');
        }
        
        // Handle tab clicks
        $('.swsib-tabs a:not(.disabled)').on('click', function(e) {
            e.preventDefault();
            // Get the target tab
            var target = $(this).attr('href');
            // Activate the tab
            activateTab(target);
        });
    }
    
    /**
     * Helper function to update URL parameters while preserving others
     */
    function updateQueryStringParameter(uri, key, value) {
        var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
        var separator = uri.indexOf('?') !== -1 ? "&" : "?";
        if (uri.match(re)) {
            return uri.replace(re, '$1' + key + "=" + value + '$2');
        } else {
            return uri + separator + key + "=" + value;
        }
    }
    
    /**
     * Initialize toggle switches
     */
    function initToggleSwitches() {
        $('.switch input[type="checkbox"]').each(function() {
            var $switch = $(this).closest('.switch');
            var $slider = $switch.find('.slider');
            if (!$slider.hasClass('round')) {
                $slider.addClass('round');
            }
        });
    }
    
    /**
     * Toggle DB Connect based on checkbox (renamed from toggleAdvancedFeatures)
     */
    function toggleDbConnect() {
        var isEnabled = $('#swsib_options_db_connect_enabled').is(':checked');
        if (isEnabled) {
            $('#advanced-features-config').slideDown();
            if (getActiveTabId() === 'db_connect') {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', true);
            }
        } else {
            $('#advanced-features-config').slideUp();
            $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                .prop('required', false);
        }
    }
    
    /**
     * Show admin notice - FOR DB CONNECT AND OTHER NON-API TEST NOTICES ONLY
     */
    function showNotice(type, message) {
        // Only use this for non-API test notices
        if (!message.includes('API connection')) {
            $('.swsib-notice:not(.info, .warning)').remove();
            var noticeClass = 'swsib-notice ' + type;
            var $notice = $('<div class="' + noticeClass + '">' + message + '</div>');
            $('.swsib-header').after($notice);
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $notice.remove();
                });
            }, 5000);
        }
    }
    
    /**
     * Update button preview with specific color
     * @param {string} bgColor Optional background color
     * @param {string} textColor Optional text color
     */
    function updateButtonPreview(bgColor, textColor) {
        // Get stored values if not provided
        bgColor = bgColor || localStorage.getItem('swsib_button_bg_color') || $('#swsib_options_auto_login_button_color').val() || '#3a4b79';
        textColor = textColor || localStorage.getItem('swsib_button_text_color') || $('#swsib_options_auto_login_button_text_color').val() || '#ffffff';
        
        // Store current values in localStorage
        localStorage.setItem('swsib_button_bg_color', bgColor);
        localStorage.setItem('swsib_button_text_color', textColor);
        
        // Update main shortcode button preview - use attr for highest specificity
        $('.button-preview .swsib-button').attr('style', 
            'background-color: ' + bgColor + ' !important; ' + 
            'color: ' + textColor + ' !important;'
        );
        
        // Update login redirect button preview with all necessary styles
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
        
        // Set the hover color CSS variable
        var hoverColor = adjustColor(bgColor, -20);
        document.documentElement.style.setProperty('--button-hover-color', hoverColor);
        
        console.log('Button preview updated with bg color: ' + bgColor + ', text color: ' + textColor);
    }
    
    /**
     * Adjust color brightness
     */
    function adjustColor(color, amount) {
        return '#' + color.replace(/^#/, '').replace(/../g, function(hex) {
            var colorVal = parseInt(hex, 16);
            colorVal = Math.min(255, Math.max(0, colorVal + amount));
            return ('0' + colorVal.toString(16)).slice(-2);
        });
    }
    
})(jQuery)