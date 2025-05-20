/**
 * SwiftSpeed Siberian Integration
 * Admin Scripts
 */
(function($) {
    'use strict';

    // Store loaded tab content
    var loadedTabs = {};
    // Track tab loading state to prevent double-clicks
    var isTabLoading = false;
    // Track the current URL's tab to prevent infinite loops
    var currentUrlTabId = null;

    // Initialize when document is ready
    $(document).ready(function() {
        console.log('SwiftSpeed Siberian admin JS loaded');

        // Get the tab from URL immediately to prevent loops
        var urlParams = new URLSearchParams(window.location.search);
        currentUrlTabId = urlParams.get('tab_id');
        console.log('Current URL tab ID: ' + currentUrlTabId);

        // Initialize tab navigation
        initTabs();
        
        // Handle any section parameter in the URL
        var sectionTarget = urlParams.get('section');
        if (sectionTarget && currentUrlTabId === 'auto_login') {
            // Delay to ensure the tab content is fully visible and loaded
            setTimeout(function() {
                scrollToSection(sectionTarget);
            }, 1000);
        }
    });

    /**
     * Get active tab ID
     */
    function getActiveTabId() {
        return $('.swsib-tabs a.active').attr('data-tab-id');
    }
    
    /**
     * Scroll to a specific section and highlight it
     */
    function scrollToSection(sectionId) {
        var $target = $('#' + sectionId);
        if ($target.length) {
            console.log('Scrolling to section: ' + sectionId);
            
            // Scroll to the target section
            $('html, body').animate({
                scrollTop: $target.offset().top - 50
            }, 800);
            
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
     * Load tab content via AJAX
     */
    function loadTabContent(tabId, callback) {
        if (isTabLoading) {
            console.log('Tab loading already in progress, skipping redundant load request');
            return;
        }
        
        isTabLoading = true;
        
        // Check if content is already loaded and cached
        if (loadedTabs[tabId]) {
            console.log('Using cached content for tab: ' + tabId);
            
            // Show loading indicator briefly for consistency
            var $tabContent = $('#' + tabId.replace(/_/g, '-') + '-tab');
            $tabContent.html(
                '<div class="swsib-loading-placeholder">' +
                '<span class="spinner is-active"></span>' +
                '<p>' + swsib_admin.loading_text + '</p>' +
                '</div>'
            );
            
            // Short delay then apply cached content
            setTimeout(function() {
                $tabContent.html(loadedTabs[tabId]);
                $tabContent.addClass('content-loaded');
                
                if (typeof callback === 'function') {
                    callback(loadedTabs[tabId]);
                }
                
                isTabLoading = false;
            }, 200);
            
            return;
        }
        
        console.log('Loading content for tab: ' + tabId);
        
        // Show loading indicator
        $('#' + tabId.replace(/_/g, '-') + '-tab').html(
            '<div class="swsib-loading-placeholder">' +
            '<span class="spinner is-active"></span>' +
            '<p>' + swsib_admin.loading_text + '</p>' +
            '</div>'
        );
        
        // Make AJAX request to load tab content
        $.ajax({
            url: swsib_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'swsib_load_tab_content',
                nonce: swsib_admin.nonce,
                tab_id: tabId
            },
            success: function(response) {
                if (response.success) {
                    // Check if we need to do a full page reload for this tab
                    if (response.data.reload_required) {
                        console.log('Tab requires full page reload. Redirecting to: ' + response.data.url);
                        
                        // CRITICAL FIX: Only navigate if we're not already on this tab
                        if (currentUrlTabId !== tabId) {
                            window.location.href = response.data.url;
                            return;
                        } else {
                            console.log('Already on tab ' + tabId + ', no need to reload');
                            isTabLoading = false;
                        }
                    } else {
                        // Store in cache
                        loadedTabs[tabId] = response.data.content;
                        
                        // Update tab content
                        $('#' + tabId.replace(/_/g, '-') + '-tab').html(response.data.content);
                        $('#' + tabId.replace(/_/g, '-') + '-tab').addClass('content-loaded');
                        
                        if (typeof callback === 'function') {
                            callback(response.data.content);
                        }
                        
                        isTabLoading = false;
                    }
                } else {
                    $('#' + tabId.replace(/_/g, '-') + '-tab').html(
                        '<div class="swsib-notice error">' +
                        '<p>Error loading tab content. Please refresh the page and try again.</p>' +
                        '<button class="button retry-tab-load" data-tab-id="' + tabId + '">Retry Loading</button>' +
                        '</div>'
                    );
                    
                    // Add retry button handler
                    $('.retry-tab-load').on('click', function() {
                        var retryTabId = $(this).data('tab-id');
                        isTabLoading = false; // Reset loading state
                        loadTabContent(retryTabId, callback);
                    });
                    
                    console.error('Error loading tab content:', response);
                    isTabLoading = false;
                }
            },
            error: function(xhr, status, error) {
                $('#' + tabId.replace(/_/g, '-') + '-tab').html(
                    '<div class="swsib-notice error">' +
                    '<p>Error loading tab content: ' + error + '</p>' +
                    '<button class="button retry-tab-load" data-tab-id="' + tabId + '">Retry Loading</button>' +
                    '</div>'
                );
                
                // Add retry button handler
                $('.retry-tab-load').on('click', function() {
                    var retryTabId = $(this).data('tab-id');
                    isTabLoading = false; // Reset loading state
                    loadTabContent(retryTabId, callback);
                });
                
                console.error('AJAX error loading tab content:', error);
                isTabLoading = false;
            }
        });
    }
    
    /**
     * Check if a tab requires a full page reload
     */
    function doesTabRequireFullReload(tabId) {
        // Get the tabs requiring full reload array
        var tabsRequiringReload = [];
        
        try {
            tabsRequiringReload = JSON.parse(swsib_admin.tabs_requiring_full_reload);
        } catch (e) {
            console.error('Error parsing tabs requiring reload:', e);
            // Fallback to a default list
            tabsRequiringReload = ['db_connect', 'advanced_autologin', 'automate', 'clean', 'backup_restore'];
        }
        
        return tabsRequiringReload.indexOf(tabId) !== -1;
    }
    
    /**
     * Directly navigate to tab
     */
    function navigateToTab(tabId) {
        // CRITICAL FIX: Only navigate if we're not already on this tab
        if (currentUrlTabId !== tabId) {
            window.location.href = updateQueryStringParameter(window.location.href, 'tab_id', tabId);
        } else {
            console.log('Already on tab ' + tabId + ', no need to navigate');
            
            // Still update the UI to show this tab as active
            var tabSelector = '#' + tabId.replace(/_/g, '-') + '-tab';
            
            // Remove active class from all tabs and tab contents
            $('.swsib-tabs a').removeClass('active');
            $('.swsib-tab-content').removeClass('active');
            
            // Add active class to specified tab and its content
            $('.swsib-tabs a[href="' + tabSelector + '"]').addClass('active');
            $(tabSelector).addClass('active').addClass('content-loaded');
        }
    }
    
    /**
     * Activate a specific tab
     */
    function activateTab(tabSelector) {
        if (isTabLoading) {
            console.log('Tab loading in progress, ignoring tab activation request');
            return;
        }
        
        // Only activate if the tab exists and is not disabled
        var $tab = $('.swsib-tabs a[href="' + tabSelector + '"]');
        if ($tab.length && !$tab.hasClass('disabled')) {
            // Get the tab ID
            var tabId = $tab.attr('data-tab-id');
            
            // Debug
            console.log('Activating tab: ' + tabId + ' (selector: ' + tabSelector + ')');
            
            // Check if this tab requires a full page reload
            if (doesTabRequireFullReload(tabId)) {
                console.log('Tab ' + tabId + ' requires full page reload');
                navigateToTab(tabId);
                return;
            }
            
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
            if (typeof wcNavigation !== 'undefined' && typeof wcNavigation.updateHistory === 'function') {
                wcNavigation.updateHistory(newUrl);
            } else if (window.history && window.history.pushState) {
                window.history.pushState({}, '', newUrl);
                // Update our tracking variable
                currentUrlTabId = tabId;
            }
            
            // Update required attributes based on active tab
            if (tabSelector === '#db-connect-tab' && $('#swsib_options_db_connect_enabled').is(':checked')) {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', true);
            } else {
                $('#swsib_options_db_connect_host, #swsib_options_db_connect_database, #swsib_options_db_connect_username, #swsib_options_db_connect_password')
                    .prop('required', false);
            }
            
            // Load tab content via AJAX if not already loaded
            if (swsib_admin.lazy_loading_enabled && !$(tabSelector).hasClass('content-loaded')) {
                loadTabContent(tabId, function() {
                    $(tabSelector).addClass('content-loaded');
                });
            }
            
            console.log('Tab activated: ' + tabId);
        } else {
            console.error('Tab not found or disabled: ' + tabSelector);
        }
    }
    
    /**
     * Initialize tabs functionality
     */
    function initTabs() {
        // Log tab initialization
        console.log('Initializing tabs');
        
        console.log('Active tab from URL: ' + currentUrlTabId);
        
        // Set first tab as active if none is
        if ($('.swsib-tab-content.active').length === 0) {
            console.log('No active tab found, setting first tab as active');
            $('.swsib-tabs a:first').addClass('active');
            $('.swsib-tab-content:first').addClass('active');
        } else {
            console.log('Active tab found: ' + $('.swsib-tab-content.active').attr('id'));
            // Mark the active tab as content-loaded
            $('.swsib-tab-content.active').addClass('content-loaded');
        }
        
        // Handle tab clicks
        $('.swsib-tabs a:not(.disabled)').off('click').on('click', function(e) {
            e.preventDefault();
            // Get the target tab
            var target = $(this).attr('href');
            var tabId = $(this).attr('data-tab-id');
            
            // Don't process if it's already the active tab
            if (tabId === currentUrlTabId && doesTabRequireFullReload(tabId)) {
                console.log('Already on tab ' + tabId + ', ignoring click');
                return;
            }
            
            console.log('Tab clicked: ' + target);
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
     * Toggle DB Connect based on checkbox
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
        
        // Set the hover color CSS variable by slightly darkening the background color
        var hoverColor = adjustColor(bgColor, -20);
        document.documentElement.style.setProperty('--button-hover-color', hoverColor);
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