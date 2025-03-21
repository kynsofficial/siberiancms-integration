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
                    var color = ui.color.toHexString();
                    updateButtonPreview(color);
                },
                clear: function() {
                    // Update button preview when color is cleared
                    updateButtonPreview('#3a4b79');
                }
            });
        }
        
        // Force an initial update of the button preview shortly after load
        setTimeout(function() {
            var initialColor = $('.swsib-color-picker').val() || '#3a4b79';
            updateButtonPreview(initialColor);
        }, 100);
        
        // Advanced features toggle
        $('#swsib_options_db_connect_enabled').on('change', function() {
            toggleAdvancedFeatures();
        });
        
        // Initialize advanced features visibility
        toggleAdvancedFeatures();
        
        // Test API connection
        $('#test_api_connection').on('click', function(e) {
            e.preventDefault();
            
            var siberianUrl = $('#swsib_options_auto_login_siberian_url').val();
            var apiUser = $('#swsib_options_auto_login_api_user').val();
            var apiPassword = $('#swsib_options_auto_login_api_password').val();
            
            if (!siberianUrl) {
                showNotice('error', 'Please enter Siberian CMS URL');
                $('#swsib_options_auto_login_siberian_url').focus();
                return;
            }
            
            if (!apiUser || !apiPassword) {
                showNotice('error', 'Please enter API credentials');
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
                    nonce: swsib_admin.nonce,
                    url: siberianUrl,
                    user: apiUser,
                    password: apiPassword
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'API connection successful!');
                    } else {
                        showNotice('error', 'API connection failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    showNotice('error', 'Error testing API connection: ' + error);
                },
                complete: function() {
                    $button.text(originalText);
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Form validation override for hidden required fields
        $('.swsib-settings-form').on('submit', function(e) {
            // If advanced features are disabled, remove required attribute from database fields
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
        
        // Initialize button preview with current settings
        updateButtonPreview($('.swsib-color-picker').val() || '#3a4b79');
        
        // Set initial button text
        var buttonText = $('#swsib_options_auto_login_autologin_text').val() || 'App Dashboard';
        $('.button-preview .swsib-button').text(buttonText);
        
        // Restore active tab from URL parameter
        restoreActiveTab();
    });
    
    /**
     * Get active tab ID
     */
    function getActiveTabId() {
        return $('.swsib-tabs a.active').attr('data-tab-id');
    }
    
    /**
     * Restore active tab from URL parameter
     */
    function restoreActiveTab() {
        // Get tab ID from URL parameter
        var urlParams = new URLSearchParams(window.location.search);
        var tabFromUrl = urlParams.get('tab_id');
        
        if (tabFromUrl) {
            // Convert tab_id format to tab selector format (auto_login -> #auto-login-tab)
            var tabSelector = '#' + tabFromUrl.replace(/_/g, '-') + '-tab';
            
            // Activate the tab
            activateTab(tabSelector);
        } else {
            // Default to first tab
            activateTab($('.swsib-tabs a:first').attr('href'));
        }
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
            
            // Update URL with tab ID (without page reload)
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
            
            // NEW: If the activated tab is Auto Login, update the button preview
            if (tabId === 'auto_login') {
                var currentColor = $('#swsib_options_auto_login_button_color').val() || $('.swsib-color-picker').val() || '#3a4b79';
                updateButtonPreview(currentColor);
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
     * Helper function to update URL parameters
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
     * Toggle advanced features based on checkbox
     */
    function toggleAdvancedFeatures() {
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
     * Show admin notice
     */
    function showNotice(type, message) {
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
    
    /**
     * Update button preview with specific color
     */
    function updateButtonPreview(color) {
        color = color || '#3a4b79';
        $('.button-preview .swsib-button').css('background-color', color);
        var hoverColor = adjustColor(color, -20);
        document.documentElement.style.setProperty('--button-hover-color', hoverColor);
        setTimeout(function() {
            $('.button-preview .swsib-button').css('background-color', color);
        }, 100);
        console.log('Button preview updated with color: ' + color);
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
    
})(jQuery);
