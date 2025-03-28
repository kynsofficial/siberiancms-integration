/**
 * SwiftSpeed Siberian - PE Subscriptions Admin JavaScript
 * 
 * Removed subscription management functionality (moved to manage-subscriptions.js)
 * Contains only general admin functionality for the subscription sections
 */
(function($) {
    'use strict';
    
    // Track the active section
    let activeSection = '';
    
    // Flag to track if we're already processing a toggle action
    let isProcessingToggle = false;
    
    // Document ready
    $(document).ready(function() {
        console.log('PE Subscriptions scripts loaded');

        // Get the active section from URL
        activeSection = getUrlParameter('section') || 'general';
        
        // Set up tab navigation without page reload
        initTabNavigation();

        // Initialize integration toggle
        initIntegrationToggle();

        // If there's an integration conflict, set up the fix button
        if (swsib_subscription.integration_conflict) {
            console.log('Integration conflict detected');
            $('#fix_integration_conflict').on('click', function() {
                if (confirm(swsib_subscription.confirmation_fix_conflict)) {
                    fixIntegrationConflict();
                }
            });
        }
        
        // Initialize test connection button
        initTestConnection();
        
        // Initialize sortable role priority list
        initRolePrioritySort();
        
        // Initialize allowed origins functionality
        initAllowedOriginsFunctionality();
        
        // Initialize subscription plan functionality
        initSubscriptionPlans();
        
        // Initialize tax rules functionality
        initTaxRules();
        
        // Initialize payment gateways functionality
        initPaymentGateways();
        
        // Auto-hide only success & error notices after 15 seconds
        setTimeout(function() {
          $('.swsib-notice.success, .swsib-notice.error').fadeOut(100);
        }, 1500);

        // Initialize checkout page selector
        initCheckoutPageSelector();
    });

    /**
     * Initialize integration toggle functionality
     */
    function initIntegrationToggle() {
        $('#subscription_integration_toggle').on('change', function() {
            // Prevent multiple simultaneous toggle actions
            if (isProcessingToggle) return;
            
            var isEnabled = $(this).prop('checked');
            
            // Set the processing flag
            isProcessingToggle = true;
            
            if (isEnabled) {
                if (confirm(swsib_subscription.confirm_enable)) {
                    toggleSubscriptionIntegration(true);
                } else {
                    $(this).prop('checked', false);
                    isProcessingToggle = false;
                }
            } else {
                if (confirm(swsib_subscription.confirm_disable)) {
                    toggleSubscriptionIntegration(false);
                } else {
                    $(this).prop('checked', true);
                    isProcessingToggle = false;
                }
            }
        });
    }
    
    /**
     * Toggle PE Subscription integration via AJAX
     */
    function toggleSubscriptionIntegration(enable) {
        $('#subscription_toggle_result').html('<span class="loading">' + swsib_subscription.testing_message + '</span>');
        
        $.ajax({
            url: swsib_subscription.ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_toggle_subscription_integration',
                nonce: swsib_subscription.nonce,
                enable: enable
            },
            success: function(response) {
                if (response.success) {
                    $('#subscription_toggle_result').html('<span class="success">' + response.data.message + '</span>');
                    $('.swsib-toggle-text').text(enable ? 'Integration Enabled' : 'Integration Disabled');
                    
                    // Reload page after a short delay to reflect changes
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#subscription_toggle_result').html('<span class="error">' + response.data.message + '</span>');
                    $('#subscription_integration_toggle').prop('checked', !enable);
                    
                    if (response.data.conflict) {
                        alert('WooCommerce integration is currently active. Please disable it first.');
                    }
                    
                    // Reset the processing flag
                    isProcessingToggle = false;
                }
            },
            error: function() {
                $('#subscription_toggle_result').html('<span class="error">Error occurred while updating integration status.</span>');
                $('#subscription_integration_toggle').prop('checked', !enable);
                
                // Reset the processing flag
                isProcessingToggle = false;
            }
        });
    }

    /**
     * Initialize tab navigation without page reload
     */
    function initTabNavigation() {
        $('.swsib-section-tabs a').on('click', function(e) {
            e.preventDefault();
            
            const section = $(this).attr('href').split('section=')[1];
            navigateToSection(section);
            
            // Update URL without page reload
            const newUrl = $(this).attr('href');
            window.history.pushState({section: section}, '', newUrl);
            
            return false;
        });
        
        // Handle browser back/forward buttons
        window.onpopstate = function(event) {
            if (event.state && event.state.section) {
                navigateToSection(event.state.section);
            }
        };
    }
    
    /**
     * Initialize checkout page selector with AJAX saving
     */
    function initCheckoutPageSelector() {
        $('#swsib_options_subscription_checkout_page_id').on('change', function() {
            const checkoutPageId = $(this).val();
            const nonce = swsib_subscription.nonce;
            
            // Show loading status
            showNotification('Saving checkout page setting...', 'info', false);
            
            // Save the checkout page setting via AJAX
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_save_checkout_page',
                    nonce: nonce,
                    checkout_page_id: checkoutPageId
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Checkout page setting saved successfully', 'success');
                    } else {
                        showNotification(response.data.message || 'Error saving checkout page setting', 'error');
                    }
                },
                error: function() {
                    showNotification('Error saving checkout page setting', 'error');
                }
            });
        });
    }
    
    /**
     * Navigate to a specific section without page reload
     */
    function navigateToSection(section) {
        if (section === activeSection) return;
        
        // Update active tab
        $('.swsib-section-tabs a').removeClass('active');
        $(`.swsib-section-tabs a[href*="section=${section}"]`).addClass('active');
        
        // Show loading indicator
        showNotification('Loading section...', 'info', false);
        
        // Load section content via AJAX
        $.ajax({
            url: swsib_subscription.ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_load_subscription_section',
                nonce: swsib_subscription.nonce,
                section: section
            },
            success: function(response) {
                if (response.success && response.data.html) {
                    // Update the main content area
                    $('#swsib-subscription-content').html(response.data.html);
                    
                    // Initialize section-specific functionality
                    initSectionFunctionality(section);
                    
                    // Update active section
                    activeSection = section;
                    
                    // Clear notification
                    hideNotification();
                } else {
                    showNotification('Failed to load section', 'error');
                }
            },
            error: function() {
                showNotification('Error loading section', 'error');
            }
        });
    }
    
    /**
     * Initialize section-specific functionality after AJAX load
     */
    function initSectionFunctionality(section) {
        switch(section) {
            case 'general':
                initTestConnection();
                initRolePrioritySort();
                initAllowedOriginsFunctionality();
                initCheckoutPageSelector();
                break;
            case 'plans':
                initSubscriptionPlans();
                initTaxRules(); 
                break;
            case 'payment':
                initPaymentGateways();
                break;
        }
    }

    /**
     * Show notification instead of alert
     */
    function showNotification(message, type = 'success', autoHide = true) {
        // Remove any existing notification
        hideNotification();
        
        // Create notification element
        const $notification = $('<div class="swsib-notification ' + type + '">' +
            '<span class="swsib-notification-message">' + message + '</span>' +
            '<span class="swsib-notification-close">&times;</span>' +
            '</div>');
        
        // Add to page
        $('body').append($notification);
        
        // Fade in
        setTimeout(function() {
            $notification.addClass('show');
        }, 10);
        
        // Set up close button
        $('.swsib-notification-close').on('click', function() {
            hideNotification();
        });
        
        // Auto hide after 15 seconds if autoHide is true (increased from 4 seconds)
        if (autoHide) {
            setTimeout(function() {
                hideNotification();
            }, 15000);
        }
    }
    
    /**
     * Hide notification
     */
    function hideNotification() {
        const $notification = $('.swsib-notification');
        $notification.removeClass('show');
        setTimeout(function() {
            $notification.remove();
        }, 300);
    }

    /**
     * Fix integration conflict by disabling both PE Subscriptions and WooCommerce
     */
    function fixIntegrationConflict() {
        $.ajax({
            url: swsib_subscription.ajaxurl,
            type: 'POST',
            data: {
                action: 'swsib_fix_integration_conflict',
                nonce: swsib_subscription.nonce
            },
            beforeSend: function() {
                $('#fix_integration_conflict').prop('disabled', true).text('Fixing...');
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showNotification(response.data.message || 'Error occurred while fixing integration conflict', 'error');
                    $('#fix_integration_conflict').prop('disabled', false).text('Fix Integration Conflict');
                }
            },
            error: function() {
                showNotification('Error occurred. Please try again.', 'error');
                $('#fix_integration_conflict').prop('disabled', false).text('Fix Integration Conflict');
            }
        });
    }

    /**
     * Initialize test connection button
     */
    function initTestConnection() {
        $('#test_subscription_db_connection').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            // Save original text if not already stored
            if (!$button.data('originalText')) {
                $button.data('originalText', $button.text());
            }
            var originalText = $button.data('originalText');
            
            var $result = $('#subscription_db_test_result');
            $button.text('Testing...').prop('disabled', true);
            $result.stop(true, true).hide().html('');
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_test_subscription_db',
                    nonce: swsib_subscription.nonce
                },
                success: function(response) {
                    var noticeClass = response.success ? 'success' : 'error';
                    var message = (response.data && response.data.message)
                        ? response.data.message
                        : (response.success ? 'Connection successful!' : 'Connection failed');
                    
                    $result
                        .html('<div class="swsib-notice ' + noticeClass + '"><p>' + message + '</p></div>')
                        .show();
                    
                    $('html, body').animate({
                        scrollTop: $result.offset().top - 100
                    }, 300);
                    
                    setTimeout(function() {
                        $button.text(originalText).prop('disabled', false);
                    }, 1000);
                    
                    // Auto-hide the message after 15 seconds
                    setTimeout(function() {
                        $result.fadeOut(1000);
                    }, 1000);
                },
                error: function() {
                    $result
                        .html('<div class="swsib-notice error"><p>Error occurred during test. Please try again.</p></div>')
                        .show();
                    
                    setTimeout(function() {
                        $button.text(originalText).prop('disabled', false);
                    }, 1000);
                    
                    // Auto-hide the message after 15 seconds
                    setTimeout(function() {
                        $result.fadeOut(1000);
                    }, 15000);
                }
            });
        });
    }
    
    /**
     * Initialize sortable role priority list
     */
    function initRolePrioritySort() {
        if ($.fn.sortable && $('#subscription_role_priority_list').length) {
            $('#subscription_role_priority_list').sortable({
                placeholder: 'swsib-sortable-placeholder',
                update: function() {
                    updateRolePriorityInputs();
                }
            });
        }
    }
    
    /**
     * Update hidden inputs for role priorities
     */
    function updateRolePriorityInputs() {
        $('input[name^="swsib_options[subscription][role_priorities]"]').remove();
        
        $('#subscription_role_priority_list li').each(function() {
            var roleId = $(this).data('role-id');
            $(this).append(
                '<input type="hidden" name="swsib_options[subscription][role_priorities][]" value="' + roleId + '">'
            );
        });
    }
    
    /**
     * Initialize allowed origins functionality
     */
    function initAllowedOriginsFunctionality() {
        // Add origin button click handler
        $('#add_subscription_allowed_origin').on('click', function() {
            var $button = $(this);
            var originalText = $button.text();
            var $input = $('#swsib_options_subscription_allowed_origin_url');
            var originUrl = $input.val().trim();
            
            // Validate URL
            if (!originUrl || !isValidUrl(originUrl)) {
                showNotification('Please enter a valid URL (e.g., https://example.com)', 'error');
                $input.focus();
                return;
            }
            
            $button.text('Adding...').prop('disabled', true);
            
            // AJAX to add origin
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_subscription_add_allowed_origin',
                    nonce: swsib_subscription.nonce,
                    origin_url: originUrl
                },
                success: function(response) {
                    if (response.success) {
                        addOriginToTable(response.data.origin);
                        $input.val('');
                        showNotification('Origin added successfully', 'success');
                    } else {
                        showNotification(response.data.message, 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                },
                complete: function() {
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
        
        // Delete origin click handler - use browser confirmation instead of custom dialog
        $(document).on('click', '.delete-subscription-origin', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var originId = $button.data('origin-id');
            var $row = $button.closest('tr');
            
            if (!originId) {
                console.error('Delete origin button missing origin-id attribute');
                return;
            }
            
            if (confirm('Are you sure you want to delete this origin?')) {
                $button.prop('disabled', true);
                
                // Send AJAX request
                $.ajax({
                    url: swsib_subscription.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'swsib_subscription_delete_allowed_origin',
                        nonce: swsib_subscription.nonce,
                        origin_id: originId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                if ($('#subscription_allowed_origins_tbody tr').length === 0) {
                                    $('#subscription_allowed_origins_tbody').html(
                                        '<tr class="no-origins-row">' +
                                        '<td colspan="2">No origins added yet. Add your first origin above.</td>' +
                                        '</tr>'
                                    );
                                }
                            });
                            showNotification('Origin deleted successfully', 'success');
                        } else {
                            showNotification(response.data.message, 'error');
                            $button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        showNotification('An error occurred. Please try again.', 'error');
                        $button.prop('disabled', false);
                    }
                });
            }
        });
        
        // Press Enter to add
        $('#swsib_options_subscription_allowed_origin_url').keypress(function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#add_subscription_allowed_origin').click();
            }
        });
    }
    
    function isValidUrl(str) {
        try {
            new URL(str);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    function addOriginToTable(origin) {
        $('.no-origins-row').remove();
        
        var newRow = `
            <tr data-origin-id="${origin.id}">
                <td>
                    <a href="${origin.url}" target="_blank" rel="noopener noreferrer">${origin.url}</a>
                </td>
                <td>
                    <button type="button" class="button button-small delete-subscription-origin" data-origin-id="${origin.id}">
                        Delete
                    </button>
                </td>
            </tr>
        `;
        
        $('#subscription_allowed_origins_tbody').append(newRow);
    }

    /**
     * Initialize subscription plans functionality
     */
    function initSubscriptionPlans() {
        // Helper: Disables any SiberianCMS plan that's already mapped to an existing subscription plan
        function refreshSiberianPlanOptions(currentPlanSiberianId) {
            // 1. Gather all used SiberianCMS IDs from the existing plan cards on the page
            const usedIds = [];
            $('.swsib-plan-card .plan-siberian-id').each(function() {
                const id = $(this).data('siberian-id');
                if (id) {
                    usedIds.push(id.toString());
                }
            });
            
            // 2. For each option in the #subscription_plan_siberian_id dropdown,
            // disable it if it's already used & not the current plan's own ID
            $('#subscription_plan_siberian_id option').each(function() {
                const $opt = $(this);
                const val = $opt.val();
                
                // If empty placeholder or no value
                if (!val) {
                    $opt.prop('disabled', false).text($opt.text().replace(/\s*\(already used\)$/, ''));
                    return;
                }
                
                // If the current plan has that same ID, keep it enabled
                if (usedIds.includes(val) && val !== currentPlanSiberianId) {
                    // Append marker if not already present
                    if (!$opt.text().match(/\(already used\)$/)) {
                        $opt.text(`${$opt.text()} (already used)`);
                    }
                    $opt.prop('disabled', true);
                } else {
                    // Otherwise ensure it's enabled and remove any leftover marker
                    $opt.prop('disabled', false).text($opt.text().replace(/\s*\(already used\)$/, ''));
                }
            });
        }

        // Toggle the "Add/Edit" plan form visibility
        $('#add_subscription_plan_button').on('click', function(e) {
            e.preventDefault();
            resetPlanForm();

            // Because this is a brand new plan, we pass null so *all* used IDs get disabled
            refreshSiberianPlanOptions(null);

            $('#subscription_plan_form').slideDown(300);
            $('html, body').animate({
                scrollTop: $('#subscription_plan_form').offset().top - 50
            }, 300);
        });

        // Handle plan form submission
        $('#save_subscription_plan').on('click', function(e) {
            e.preventDefault();

            const $button = $(this);
            const formData = {
                name: $('#subscription_plan_name').val(),
                price: $('#subscription_plan_price').val(),
                billing_frequency: $('#subscription_plan_billing_frequency').val(),
                currency: $('#subscription_plan_currency').val(),
                description: $('#subscription_plan_description').val(),
                app_quantity: $('#subscription_plan_app_quantity').val(),
                siberian_plan_id: $('#subscription_plan_siberian_id').val(),
                role_id: $('#subscription_plan_role_id').val()
            };

            // If editing an existing plan
            const planId = $('#subscription_plan_id').val();
            if (planId) {
                formData.plan_id = planId;
            }

            // Basic validation
            if (!formData.name || !formData.price || !formData.billing_frequency || !formData.app_quantity) {
                showNotification('Please fill in all required fields.', 'error');
                return;
            }

            // Disable button
            $button.prop('disabled', true).html('Saving... <span class="swsib-spinner"></span>');

            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_save_subscription_plan',
                    nonce: swsib_subscription.nonce,
                    ...formData
                },
                success: function(response) {
                    if (response.success) {
                        showNotification(response.data.message, 'success');
                        
                        // Add or update plan in UI without page reload
                        if (planId) {
                            updatePlanInUI(response.data.plan);
                        } else {
                            addPlanToUI(response.data.plan);
                        }
                        
                        // Hide form
                        $('#subscription_plan_form').slideUp(300);
                        resetPlanForm();
                    } else {
                        showNotification(response.data.message, 'error');
                        $button.prop('disabled', false).text('Save Plan');
                    }
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                    $button.prop('disabled', false).text('Save Plan');
                }
            });
        });

        // Edit plan button
        $(document).on('click', '.edit-subscription-plan', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $card = $button.closest('.swsib-plan-card');
            const planId = $button.data('plan-id');
            
            // Retrieve existing plan data from card
            const planName = $card.find('.plan-name').text();
            const planPrice = $card.find('.plan-price').data('price');
            const planCurrency = $card.find('.plan-price').data('currency');
            const planBillingFrequency = $card.find('.plan-billing').data('billing');
            const planDescription = $card.find('.plan-description').text();
            const planAppQuantity = $card.find('.plan-app-quantity').data('quantity');
            const planSiberianId = $card.find('.plan-siberian-id').data('siberian-id').toString();
            const planRoleId = $card.find('.plan-role-id').data('role-id');

            // Populate form
            $('#subscription_plan_id').val(planId);
            $('#subscription_plan_name').val(planName);
            $('#subscription_plan_price').val(planPrice);
            $('#subscription_plan_currency').val(planCurrency);
            $('#subscription_plan_billing_frequency').val(planBillingFrequency);
            $('#subscription_plan_description').val(planDescription);
            $('#subscription_plan_app_quantity').val(planAppQuantity);
            $('#subscription_plan_siberian_id').val(planSiberianId);
            $('#subscription_plan_role_id').val(planRoleId);

            // Now disable already-used IDs in the dropdown *except* for this plan's own ID
            refreshSiberianPlanOptions(planSiberianId);

            $('#subscription_plan_form').slideDown(300);
            $('#subscription_plan_form h4').text('Edit Subscription Plan');
            $('#save_subscription_plan').text('Update Plan');

            $('html, body').animate({
                scrollTop: $('#subscription_plan_form').offset().top - 50
            }, 500);
        });

        // Cancel plan form
        $('#cancel_subscription_plan').on('click', function(e) {
            e.preventDefault();
            $('#subscription_plan_form').slideUp(300);
            resetPlanForm();
        });

        // Delete plan
        $(document).on('click', '.delete-subscription-plan', function(e) {
            e.preventDefault();
            const $button = $(this);
            const planId = $button.data('plan-id');
            const $card = $button.closest('.swsib-plan-card');

            if (confirm(swsib_subscription.confirmation_delete_plan)) {
                $button.prop('disabled', true).html('Deleting... <span class="swsib-spinner"></span>');
                
                $.ajax({
                    url: swsib_subscription.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'swsib_delete_subscription_plan',
                        nonce: swsib_subscription.nonce,
                        plan_id: planId
                    },
                    success: function(response) {
                        if (response.success) {
                            $card.fadeOut(300, function() {
                                $(this).remove();
                                if ($('.swsib-plan-card').length === 0) {
                                    $('.swsib-plan-cards').html(
                                        '<p class="swsib-text-center">No subscription plans defined yet. Add your first plan above.</p>'
                                    );
                                }
                            });
                            showNotification('Subscription plan deleted successfully', 'success');
                        } else {
                            showNotification(response.data.message, 'error');
                            $button.prop('disabled', false).text('Delete');
                        }
                    },
                    error: function() {
                        showNotification('An error occurred. Please try again.', 'error');
                        $button.prop('disabled', false).text('Delete');
                    }
                });
            }
        });

        // Save default currency via AJAX
        $('#save_default_currency').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const defaultCurrency = $('#swsib_options_subscription_default_currency').val();
            
            $button.prop('disabled', true).html('Saving... <span class="swsib-spinner"></span>');
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_save_default_currency',
                    nonce: swsib_subscription.nonce,
                    default_currency: defaultCurrency
                },
                success: function(response) {
                    if (response.success) {
                        showNotification('Default currency updated successfully', 'success');
                        // Update global variable
                        swsib_subscription.default_currency = defaultCurrency;
                    } else {
                        showNotification(response.data.message, 'error');
                    }
                    $button.prop('disabled', false).text('Save Currency Setting');
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                    $button.prop('disabled', false).text('Save Currency Setting');
                }
            });
        });
    }

    /**
     * Reset form fields to default whenever the user cancels or completes adding/editing a plan
     */
    function resetPlanForm() {
        $('#subscription_plan_id').val('');
        $('#subscription_plan_name').val('');
        $('#subscription_plan_price').val('');
        $('#subscription_plan_currency').val(swsib_subscription.default_currency);
        $('#subscription_plan_billing_frequency').val('monthly');
        $('#subscription_plan_description').val('');
        $('#subscription_plan_app_quantity').val('1');
        $('#subscription_plan_siberian_id').val('');
        $('#subscription_plan_role_id').val('2');

        $('#subscription_plan_form h4').text('Add New Subscription Plan');
        $('#save_subscription_plan').text('Save Plan').prop('disabled', false);
    }
    
    function addPlanToUI(plan) {
        // Get billing frequency label
        var billingFrequencyLabel = '';
        switch(plan.billing_frequency) {
            case 'weekly': billingFrequencyLabel = 'Weekly'; break;
            case 'monthly': billingFrequencyLabel = 'Monthly'; break;
            case 'quarterly': billingFrequencyLabel = 'Quarterly (3 months)'; break;
            case 'biannually': billingFrequencyLabel = 'Bi-annually (6 months)'; break;
            case 'annually': billingFrequencyLabel = 'Annually (1 year)'; break;
            default: billingFrequencyLabel = plan.billing_frequency;
        }
        
        // Get role name if available
        var roleName = '';
        if (plan.role_id) {
            roleName = ' (Role ' + plan.role_id + ')';
        }
        
        var planCard = `
            <div class="swsib-plan-card">
                <h3 class="plan-name">${plan.name}</h3>
                <div class="swsib-plan-card-price plan-price" 
                     data-price="${plan.price}"
                     data-currency="${plan.currency}">
                    ${plan.price} ${plan.currency}
                </div>
                <div class="swsib-plan-card-details">
                    <p class="plan-billing" data-billing="${plan.billing_frequency}">
                        <strong>Billing:</strong> 
                        ${billingFrequencyLabel}
                    </p>
                    <p class="plan-app-quantity" data-quantity="${plan.app_quantity}">
                        <strong>Apps:</strong> 
                        ${plan.app_quantity}
                    </p>
                    <p class="plan-description">
                        ${plan.description}
                    </p>
                    <p class="plan-siberian-id" data-siberian-id="${plan.siberian_plan_id}">
                        <strong>SiberianCMS Plan ID:</strong> 
                        ${plan.siberian_plan_id}
                    </p>
                    <p class="plan-role-id" data-role-id="${plan.role_id}">
                        <strong>Role ID:</strong> 
                        ${plan.role_id}${roleName}
                    </p>
                </div>
                <div class="swsib-plan-card-actions">
                    <button type="button" class="button edit-subscription-plan" data-plan-id="${plan.id}">
                        Edit
                    </button>
                    <button type="button" class="button delete-subscription-plan" data-plan-id="${plan.id}">
                        Delete
                    </button>
                </div>
            </div>
        `;
        
        // Check if there's a "no plans" message and remove it
        if ($('.swsib-plan-cards').find('.swsib-text-center').length) {
            $('.swsib-plan-cards').empty();
        }
        
        // Add new plan to the grid
        $('.swsib-plan-cards').append(planCard);
    }
    
    function updatePlanInUI(plan) {
        var $card = $(`.swsib-plan-card .edit-subscription-plan[data-plan-id="${plan.id}"]`).closest('.swsib-plan-card');
        
        if ($card.length) {
            // Get billing frequency label
            var billingFrequencyLabel = '';
            switch(plan.billing_frequency) {
                case 'weekly': billingFrequencyLabel = 'Weekly'; break;
                case 'monthly': billingFrequencyLabel = 'Monthly'; break;
                case 'quarterly': billingFrequencyLabel = 'Quarterly (3 months)'; break;
                case 'biannually': billingFrequencyLabel = 'Bi-annually (6 months)'; break;
                case 'annually': billingFrequencyLabel = 'Annually (1 year)'; break;
                default: billingFrequencyLabel = plan.billing_frequency;
            }
            
            // Get role name if available
            var roleName = '';
            if (plan.role_id) {
                roleName = ' (Role ' + plan.role_id + ')';
            }
            
            // Update card contents
            $card.find('.plan-name').text(plan.name);
            $card.find('.plan-price')
                .attr('data-price', plan.price)
                .attr('data-currency', plan.currency)
                .text(plan.price + ' ' + plan.currency);
            
            $card.find('.plan-billing')
                .attr('data-billing', plan.billing_frequency)
                .html('<strong>Billing:</strong> ' + billingFrequencyLabel);
            
            $card.find('.plan-app-quantity')
                .attr('data-quantity', plan.app_quantity)
                .html('<strong>Apps:</strong> ' + plan.app_quantity);
            
            $card.find('.plan-description').text(plan.description);
            
            $card.find('.plan-siberian-id')
                .attr('data-siberian-id', plan.siberian_plan_id)
                .html('<strong>SiberianCMS Plan ID:</strong> ' + plan.siberian_plan_id);
            
            $card.find('.plan-role-id')
                .attr('data-role-id', plan.role_id)
                .html('<strong>Role ID:</strong> ' + plan.role_id + roleName);
        }
    }

    /**
     * Initialize Tax Rules functionality
     */
    function initTaxRules() {
        // Initialize country and plan selection dropdowns for tax rules
        $('#tax_rule_countries_selection').on('change', function() {
            const selection = $(this).val();
            if (selection === 'selected') {
                $('#tax_rule_countries_container').slideDown(300);
            } else {
                $('#tax_rule_countries_container').slideUp(300);
            }
        });

        $('#tax_rule_plans_selection').on('change', function() {
            const selection = $(this).val();
            if (selection === 'selected') {
                $('#tax_rule_plans_container').slideDown(300);
            } else {
                $('#tax_rule_plans_container').slideUp(300);
            }
        });
        
        // Initialize Add Tax Rule button
        $('#add_tax_rule_button').on('click', function() {
            // Reset form
            $('#tax_rule_id').val('');
            $('#tax_rule_name').val('');
            $('#tax_rule_percentage').val('');
            $('#tax_rule_countries_selection').val('all');
            $('#tax_rule_plans_selection').val('all');
            $('#tax_rule_enabled').prop('checked', true);
            
            // Reset checkboxes
            $('input[name="tax_rule_countries[]"]').prop('checked', false);
            $('input[name="tax_rule_plans[]"]').prop('checked', false);
            
            // Hide checkbox containers
            $('#tax_rule_countries_container').hide();
            $('#tax_rule_plans_container').hide();
            
            // Update form title
            $('#tax_rule_form h4').text('Add New Tax Rule');
            $('#save_tax_rule').text('Save Tax Rule');
            
            // Show form
            $('#tax_rule_form').slideDown(300);
            
            // Scroll to form
            $('html, body').animate({
                scrollTop: $('#tax_rule_form').offset().top - 50
            }, 300);
        });
        
        // Cancel Tax Rule Form
        $('#cancel_tax_rule').on('click', function() {
            $('#tax_rule_form').slideUp(300);
        });
        
        // Save Tax Rule (AJAX - update UI without full page refresh)
        $('#save_tax_rule').on('click', function() {
            var $button = $(this);
            var ruleId = $('#tax_rule_id').val() || 'tax_rule_' + Math.random().toString(36).substr(2, 9);
            
            // Gather form data
            var formData = {
                rule_id: ruleId,
                name: $('#tax_rule_name').val().trim(),
                percentage: parseFloat($('#tax_rule_percentage').val()),
                enabled: $('#tax_rule_enabled').is(':checked')
            };
            
            // Validation
            if (!formData.name) {
                showNotification('Please enter a tax rule name', 'error');
                $('#tax_rule_name').focus();
                return;
            }
            
            if (isNaN(formData.percentage) || formData.percentage < 0 || formData.percentage > 100) {
                showNotification('Please enter a valid tax percentage between 0 and 100', 'error');
                $('#tax_rule_percentage').focus();
                return;
            }
            
            // Get countries selection
            var countriesMode = $('#tax_rule_countries_selection').val();
            if (countriesMode === 'all') {
                formData.countries = ['ALL'];
            } else {
                var selectedCountries = [];
                $('input[name="tax_rule_countries[]"]:checked').each(function() {
                    selectedCountries.push($(this).val());
                });
                
                if (selectedCountries.length === 0) {
                    showNotification('Please select at least one country', 'error');
                    return;
                }
                
                formData.countries = selectedCountries;
            }
            
            // Get plans selection
            var plansMode = $('#tax_rule_plans_selection').val();
            if (plansMode === 'all') {
                formData.plans = ['all'];
            } else {
                var selectedPlans = [];
                $('input[name="tax_rule_plans[]"]:checked').each(function() {
                    selectedPlans.push($(this).val());
                });
                
                if (selectedPlans.length === 0) {
                    showNotification('Please select at least one plan', 'error');
                    return;
                }
                
                formData.plans = selectedPlans;
            }
            
            // Check for conflicts when using "all countries" or "all plans"
            if (formData.countries.includes('ALL') || formData.plans.includes('all')) {
                var isEditing = $('#tax_rule_form h4').text().indexOf('Edit') !== -1;
                if (!isEditing) {
                    var hasConflict = false;
                    
                    // Find all existing rules in the table
                    $('.swsib-tax-rules-table tbody tr').each(function() {
                        var $row = $(this);
                        
                        if ($row.data('rule-id') === ruleId) {
                            return;
                        }
                        
                        var rowCountries = $row.find('td:eq(2)').text().trim();
                        var rowPlans = $row.find('td:eq(3)').text().trim();
                        
                        if (formData.countries.includes('ALL') && rowCountries === 'All Countries') {
                            if (formData.plans.includes('all') && rowPlans === 'All Plans') {
                                hasConflict = true;
                                return false;
                            }
                            if (formData.plans.includes('all') && rowPlans !== 'All Plans') {
                                hasConflict = true;
                                return false;
                            }
                            if (rowPlans === 'All Plans' && !formData.plans.includes('all')) {
                                hasConflict = true;
                                return false;
                            }
                        }
                        
                        if (formData.plans.includes('all') && rowPlans === 'All Plans') {
                            if (formData.countries.includes('ALL') && rowCountries === 'All Countries') {
                                hasConflict = true;
                                return false;
                            }
                            if (formData.countries.includes('ALL') && rowCountries !== 'All Countries') {
                                hasConflict = true;
                                return false;
                            }
                            if (rowCountries === 'All Countries' && !formData.countries.includes('ALL')) {
                                hasConflict = true;
                                return false;
                            }
                        }
                    });
                    
                    if (hasConflict) {
                        showNotification('Conflict detected: You already have a tax rule with overlapping countries and plans. Please delete the existing rule first.', 'error');
                        return;
                    }
                }
            }
            
            // Disable button and save via AJAX
            $button.prop('disabled', true).html('Saving... <span class="swsib-spinner"></span>');
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_save_tax_rule',
                    nonce: swsib_subscription.nonce,
                    tax_data: formData
                },
                success: function(response) {
                    if (response.success) {
                        showNotification(response.data.message, 'success');
                        // Update the Tax Rules UI without a full page reload
                        if (response.data.rule) {
                            updateTaxRuleUI(response.data.rule);
                        }
                        // Hide the form after a short delay
                        $('#tax_rule_form').slideUp(300);
                        $button.prop('disabled', false).html('Save Tax Rule');
                    } else {
                        showNotification(response.data.message, 'error');
                        $button.prop('disabled', false).text('Save Tax Rule');
                    }
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                    $button.prop('disabled', false).text('Save Tax Rule');
                }
            });
        });
        
        // Edit Tax Rule
        $(document).on('click', '.edit-tax-rule', function() {
            var ruleId = $(this).data('rule-id');
            
            // Get rule data via AJAX
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_get_tax_rule',
                    nonce: swsib_subscription.nonce,
                    rule_id: ruleId
                },
                success: function(response) {
                    if (response.success && response.data.rule) {
                        var rule = response.data.rule;
                        
                        // Populate form
                        $('#tax_rule_id').val(rule.id);
                        $('#tax_rule_name').val(rule.name);
                        $('#tax_rule_percentage').val(rule.percentage);
                        $('#tax_rule_enabled').prop('checked', rule.enabled);
                        
                        // Handle countries
                        if (!rule.countries || rule.countries.includes('ALL')) {
                            $('#tax_rule_countries_selection').val('all');
                            $('#tax_rule_countries_container').hide();
                        } else {
                            $('#tax_rule_countries_selection').val('selected');
                            $('#tax_rule_countries_container').show();
                            
                            $('input[name="tax_rule_countries[]"]').prop('checked', false);
                            $.each(rule.countries, function(i, country) {
                                $(`input[name="tax_rule_countries[]"][value="${country}"]`).prop('checked', true);
                            });
                        }
                        
                        // Handle plans
                        if (!rule.plans || rule.plans.includes('all')) {
                            $('#tax_rule_plans_selection').val('all');
                            $('#tax_rule_plans_container').hide();
                        } else {
                            $('#tax_rule_plans_selection').val('selected');
                            $('#tax_rule_plans_container').show();
                            
                            $('input[name="tax_rule_plans[]"]').prop('checked', false);
                            $.each(rule.plans, function(i, plan) {
                                $(`input[name="tax_rule_plans[]"][value="${plan}"]`).prop('checked', true);
                            });
                        }
                        
                        $('#tax_rule_form h4').text('Edit Tax Rule');
                        $('#save_tax_rule').text('Update Tax Rule');
                        
                        $('#tax_rule_form').slideDown(300);
                        
                        $('html, body').animate({
                            scrollTop: $('#tax_rule_form').offset().top - 50
                        }, 300);
                    } else {
                        showNotification(response.data.message || 'Error loading tax rule.', 'error');
                    }
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                }
            });
        });
        
        // Delete Tax Rule
        $(document).on('click', '.delete-tax-rule', function() {
            var ruleId = $(this).data('rule-id');
            var $row = $(this).closest('tr');
            
            if (confirm('Are you sure you want to delete this tax rule?')) {
                var $button = $(this).prop('disabled', true);
                
                $.ajax({
                    url: swsib_subscription.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'swsib_delete_tax_rule',
                        nonce: swsib_subscription.nonce,
                        rule_id: ruleId
                    },
                    success: function(response) {
                        if (response.success) {
                            $row.fadeOut(300, function() {
                                $(this).remove();
                                if ($('.swsib-tax-rules-table tbody tr').length === 0) {
                                    $('.swsib-tax-rules-container').html(
                                        '<p class="swsib-text-center">No tax rules defined yet. Add your first tax rule above.</p>'
                                    );
                                }
                            });
                            showNotification('Tax rule deleted successfully', 'success');
                        } else {
                            showNotification(response.data.message, 'error');
                            $button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        showNotification('An error occurred. Please try again.', 'error');
                        $button.prop('disabled', false);
                    }
                });
            }
        });
        
        // Toggle Tax Rule (Enable/Disable) - Updated version with better UI feedback
        $(document).on('click', '.toggle-tax-rule', function() {
            var ruleId = $(this).data('rule-id');
            var action = $(this).data('action');
            var $button = $(this);
            var $row = $button.closest('tr');
            
            // Store original button text for rollback
            var originalText = $button.text();
            
            // Show loading indication
            $button.prop('disabled', true).text(action === 'enable' ? 'Enabling...' : 'Disabling...');
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: {
                    action: 'swsib_toggle_tax_rule',
                    nonce: swsib_subscription.nonce,
                    rule_id: ruleId,
                    toggle_action: action
                },
                success: function(response) {
                    if (response.success) {
                        // Determine new state values
                        var newStatus = (action === 'enable') ? 'active' : 'inactive';
                        var statusText = (action === 'enable') ? 'Active' : 'Inactive';
                        var newAction = (action === 'enable') ? 'disable' : 'enable';
                        var newButtonText = (action === 'enable') ? 'Disable' : 'Enable';
                        var newButtonClassToAdd = (action === 'enable') ? 'disable-button' : 'enable-button';
                        var newButtonClassToRemove = (action === 'enable') ? 'enable-button' : 'disable-button';
                        
                        // Update the status cell
                        $row.find('.swsib-status')
                            .removeClass('active inactive')
                            .addClass(newStatus)
                            .text(statusText);
                        
                        // Update button text, data attribute and class
                        $button
                            .data('action', newAction)
                            .text(newButtonText)
                            .removeClass(newButtonClassToRemove)
                            .addClass(newButtonClassToAdd);
                        
                        // Update any other buttons in the row (if applicable)
                        $row.find('.toggle-tax-rule')
                            .data('action', newAction)
                            .text(newButtonText)
                            .removeClass(newButtonClassToRemove)
                            .addClass(newButtonClassToAdd);
                            
                        // Save state in localStorage
                        localStorage.setItem('tax_rule_' + ruleId, JSON.stringify({ status: newStatus }));
                        
                        showNotification(response.data.message, 'success');
                    } else {
                        // Revert button state on failure
                        $button.text(originalText);
                        showNotification(response.data.message, 'error');
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    $button.prop('disabled', false).text(originalText);
                    showNotification('An error occurred. Please try again.', 'error');
                }
            });
        });
    }

    $(document).ready(function(){
        $('.toggle-tax-rule').each(function(){
             var ruleId = $(this).data('rule-id');
             var savedState = localStorage.getItem('tax_rule_' + ruleId);
             if (savedState) {
                 try {
                     var parsedState = JSON.parse(savedState);
                     // Check the stored status and update the button accordingly
                     if (parsedState.status === 'active') {
                         $(this).data('action', 'disable')
                                .text('Disable')
                                .removeClass('enable-button')
                                .addClass('disable-button');
                         $(this).closest('tr').find('.swsib-status')
                                .removeClass('active inactive')
                                .addClass('active')
                                .text('Active');
                     } else {
                         $(this).data('action', 'enable')
                                .text('Enable')
                                .removeClass('disable-button')
                                .addClass('enable-button');
                         $(this).closest('tr').find('.swsib-status')
                                .removeClass('active inactive')
                                .addClass('inactive')
                                .text('Inactive');
                     }
                 } catch(e) {
                     console.error('Error parsing saved state for rule ' + ruleId, e);
                 }
             }
        });
    });

    /**
     * Helper function to update or add a Tax Rule row in the UI.
     */
    function updateTaxRuleUI(rule) {
        // Format countries text
        var countriesText = '';
        if (rule.countries && Array.isArray(rule.countries)) {
            if (rule.countries.includes('ALL')) {
                countriesText = 'All Countries';
            } else {
                if (typeof swsib_subscription.countries_mapping !== "undefined") {
                    var names = rule.countries.map(function(code) {
                        return swsib_subscription.countries_mapping[code] || code;
                    });
                    countriesText = names.join(', ');
                } else {
                    countriesText = rule.countries.join(', ');
                }
            }
        }
        // Format plans text
        var plansText = '';
        if (rule.plans && Array.isArray(rule.plans)) {
            if (rule.plans.includes('all')) {
                plansText = 'All Plans';
            } else {
                if (typeof swsib_subscription.plans_mapping !== "undefined") {
                    var names = rule.plans.map(function(planId) {
                        return swsib_subscription.plans_mapping[planId] || planId;
                    });
                    plansText = names.join(', ');
                } else {
                    plansText = rule.plans.join(', ');
                }
            }
        }
        var statusText = rule.enabled ? 'Active' : 'Inactive';
        var statusClass = rule.enabled ? 'active' : 'inactive';
        
        // Determine toggle button attributes
        var toggleAction = rule.enabled ? 'disable' : 'enable';
        var toggleText = rule.enabled ? 'Disable' : 'Enable';
        var toggleClass = rule.enabled ? 'toggle-tax-rule-disable' : 'toggle-tax-rule-enable';
        var toggleStyle = rule.enabled ? 
            'background-color: #dc3545; border-color: #dc3545; color: white;' : 
            'background-color: #28a745; border-color: #28a745; color: white;';
        
        var newRow = `
            <tr data-rule-id="${rule.id}">
                <td>${rule.name}</td>
                <td>${rule.percentage}%</td>
                <td>${countriesText}</td>
                <td>${plansText}</td>
                <td><span class="swsib-status ${statusClass}">${statusText}</span></td>
                <td class="swsib-action-buttons">
                    <button type="button" class="button button-small edit-tax-rule" data-rule-id="${rule.id}">Edit</button>
                    <button type="button" class="button button-small delete-tax-rule" data-rule-id="${rule.id}">Delete</button>
                    <button type="button" class="button button-small toggle-tax-rule ${toggleClass}" 
                        data-rule-id="${rule.id}" 
                        data-action="${toggleAction}"
                        data-status="${statusClass}" 
                        style="${toggleStyle}">
                        ${toggleText}
                    </button>
                </td>
            </tr>
        `;
        
        var $existingRow = $(`tr[data-rule-id="${rule.id}"]`);
        if ($existingRow.length) {
            $existingRow.replaceWith(newRow);
        } else {
            if ($('.swsib-tax-rules-table tbody').length) {
                $('.swsib-tax-rules-table tbody').append(newRow);
            } else {
                // If table is empty, create a full new table
                $('.swsib-tax-rules-container').html(`
                    <table class="widefat swsib-tax-rules-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Rate</th>
                                <th>Countries</th>
                                <th>Applied Plans</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${newRow}
                        </tbody>
                    </table>
                `);
            }
        }
    }
    
    /**
     * Initialize payment gateways functionality
     */
    function initPaymentGateways() {
        // Toggle gateway details - Fixed to work properly with clicks
        $('.swsib-gateway-header').off('click').on('click', function(e) {
            // Don't toggle if clicking on checkbox or label
            if ($(e.target).is('input[type="checkbox"]') || 
                $(e.target).is('label') || 
                $(e.target).closest('label').length) {
                return;
            }
            
            var $gateway = $(this).closest('.swsib-gateway');
            var $container = $gateway.find('.swsib-gateway-content');
            
            $container.slideToggle(300);
        });
        
        // Test mode toggle
        $('.swsib-gateway-test-mode').on('change', function() {
            var $gateway = $(this).closest('.swsib-gateway');
            var isTestMode = $(this).is(':checked');
            if (isTestMode) {
                $gateway.find('.swsib-gateway-test-fields').slideDown();
                $gateway.find('.swsib-gateway-live-fields').slideUp();
            } else {
                $gateway.find('.swsib-gateway-test-fields').slideUp();
                $gateway.find('.swsib-gateway-live-fields').slideDown();
            }
        });
        
        // Enable/disable toggle for payment gateways
        $('#stripe-enabled, #paypal-enabled').on('change', function() {
            var $gateway = $(this).closest('.swsib-gateway');
            var isEnabled = $(this).is(':checked');
            var $container = $gateway.find('.swsib-gateway-content');
            
            if (isEnabled) {
                $gateway.find('.swsib-gateway-status')
                    .removeClass('inactive')
                    .addClass('active')
                    .text('Active');
                
                // Show the settings container
                $container.slideDown(300);
                
                // Update mode tag if needed
                if ($(this).attr('id') === 'stripe-enabled') {
                    var isTestMode = $('#stripe-test-mode').is(':checked');
                    if ($gateway.find('.swsib-gateway-mode').length === 0) {
                        $gateway.find('.swsib-gateway-title').append(
                            '<span class="swsib-gateway-mode ' + (isTestMode ? 'testing' : 'live') + '">' +
                            (isTestMode ? 'Testing' : 'Live') + '</span>'
                        );
                    }
                } else if ($(this).attr('id') === 'paypal-enabled') {
                    var isSandboxMode = $('#paypal-sandbox-mode').is(':checked');
                    if ($gateway.find('.swsib-gateway-mode').length === 0) {
                        $gateway.find('.swsib-gateway-title').append(
                            '<span class="swsib-gateway-mode ' + (isSandboxMode ? 'sandbox' : 'live') + '">' +
                            (isSandboxMode ? 'Sandbox' : 'Live') + '</span>'
                        );
                    }
                }
            } else {
                $gateway.find('.swsib-gateway-status')
                    .removeClass('active')
                    .addClass('inactive')
                    .text('Inactive');
                
                // Hide the settings container
                $container.slideUp(300);
                
                // Remove mode tag
                $gateway.find('.swsib-gateway-mode').remove();
            }
        });
        
        // Form submission via AJAX
        $('#save_payment_settings').on('click', function(e) {
            e.preventDefault();
            
            var $form = $('#payment_settings_form');
            var formData = $form.serialize();
            
            var $button = $(this);
            $button.prop('disabled', true).html('Saving... <span class="swsib-spinner"></span>');
            
            // Ensure both PayPal and Stripe data are included by checking if fields are enabled but not in form
            var paypalEnabled = $('#paypal-enabled').is(':checked');
            var stripeEnabled = $('#stripe-enabled').is(':checked');
            
            // Check if PayPal fields need to be manually added to the form data
            if (paypalEnabled) {
                if (formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Benabled%5D') === -1) {
                    formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Benabled%5D=1';
                }
                
                var paypalSandboxMode = $('#paypal-sandbox-mode').is(':checked');
                if (paypalSandboxMode && formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Bsandbox_mode%5D') === -1) {
                    formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Bsandbox_mode%5D=1';
                }
            }
            
            // Check if Stripe fields need to be manually added to the form data
            if (stripeEnabled) {
                if (formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bstripe%5D%5Benabled%5D') === -1) {
                    formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bstripe%5D%5Benabled%5D=1';
                }
                
                var stripeTestMode = $('#stripe-test-mode').is(':checked');
                if (stripeTestMode && formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bstripe%5D%5Btest_mode%5D') === -1) {
                    formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bstripe%5D%5Btest_mode%5D=1';
                }
            }
            
            $.ajax({
                url: swsib_subscription.ajaxurl,
                type: 'POST',
                data: formData + '&action=swsib_save_payment_settings_ajax',
                success: function(response) {
                    if (response.success) {
                        showNotification('Payment settings saved successfully', 'success');
                    } else {
                        showNotification(response.data.message || 'Error saving payment settings', 'error');
                    }
                    $button.prop('disabled', false).text('Save Payment Settings');
                },
                error: function() {
                    showNotification('An error occurred. Please try again.', 'error');
                    $button.prop('disabled', false).text('Save Payment Settings');
                }
            });
        });
    }
    
    /**
     * Helper function to get URL parameters
     */
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
        var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
        var results = regex.exec(location.search);
        return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }
})(jQuery);