/**
 * PE Subscription – Frontend JavaScript (Enhanced for Stripe Subscriptions)
 */
(function($) {
    'use strict';
    
    // Initialize on document ready
    $(document).ready(function() {
        console.log('PE Subscriptions frontend script loaded');
        
        // Initialize checkout form if present
        initCheckoutForm();
        
        // Initialize subscription management and sidebar filtering
        initSubscriptionManagement();
        initSidebarFiltering();
        
        // Handle popup close
        initPopupHandlers();
        
        // Initialize Stripe portal button with enhanced error handling
        initStripePortalButton();
    });
    
    /**
     * Initialize the checkout form with proper Stripe subscription handling
     */
    function initCheckoutForm() {
        if ($('#swsib-checkout-form').length) {
            // Check for returns from payment gateways
            var urlParams = new URLSearchParams(window.location.search);
            var isReturn = urlParams.has('swsib_checkout_cancel') || urlParams.has('swsib_checkout_success');
            
            // Reset payment method selection to Stripe on return
            if (isReturn) {
                console.log('Detected return from payment gateway, resetting to Stripe');
                $('.swsib-payment-method').removeClass('active');
                $('.swsib-payment-method[data-method="stripe"]').addClass('active');
            }
            
            // Payment method selection
            $('.swsib-payment-method').on('click', function() {
                $('.swsib-payment-method').removeClass('active');
                $(this).addClass('active');
                
                // Recalculate tax when changing payment method
                calculateTax();
            });
            
            // Tax calculation on country change
            $('#customer_country').on('change', function() {
                calculateTax();
            });
            
            // Form submission handling
            $('#swsib-checkout-form').on('submit', function(e) {
                e.preventDefault();
                
                // Basic form validation
                if (!validateCheckoutForm()) {
                    return false;
                }
                
                // Get nonce from multiple potential sources
                var nonce = '';
                
                // First try to get from form field
                if ($('#swsib_checkout_nonce').length) {
                    nonce = $('#swsib_checkout_nonce').val();
                }
                
                // Then try the JS objects
                if (!nonce && typeof swsib_subscription_checkout !== 'undefined' && swsib_subscription_checkout.nonce) {
                    nonce = swsib_subscription_checkout.nonce;
                } else if (!nonce && typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.checkout_nonce) {
                    nonce = swsib_subscription_public.checkout_nonce;
                } else if (!nonce && typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.nonce) {
                    nonce = swsib_subscription_public.nonce;
                }
                
                // Collect customer data from form
                var customerData = {
                    'first_name': $('#customer_first_name').val(),
                    'last_name': $('#customer_last_name').val(),
                    'email': $('#customer_email').val(),
                    'phone': $('#customer_phone').val(),
                    'address': $('#customer_address').val(),
                    'city': $('#customer_city').val(),
                    'state': $('#customer_state').val(),
                    'zip': $('#customer_zip').val(),
                    'country': $('#customer_country').val()
                };
                
                // Get selected payment method
                var paymentMethod = $('.swsib-payment-method.active').data('method') || 'stripe';
                console.log('Using payment method:', paymentMethod);
                
                // Disable submit button and show loading indicator
                $('#swsib-proceed-payment').prop('disabled', true)
                    .html('Processing... <span class="swsib-loading"></span>');
                
                // Get checkout data
                var checkoutData = {};
                if (typeof swsib_subscription_checkout !== 'undefined' && swsib_subscription_checkout.checkout_data) {
                    checkoutData = swsib_subscription_checkout.checkout_data;
                }
                
                // Get AJAX URL
                var ajaxUrl = '';
                if (typeof swsib_subscription_checkout !== 'undefined' && swsib_subscription_checkout.ajaxurl) {
                    ajaxUrl = swsib_subscription_checkout.ajaxurl;
                } else if (typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.ajaxurl) {
                    ajaxUrl = swsib_subscription_public.ajaxurl;
                } else {
                    ajaxUrl = '/wp-admin/admin-ajax.php';
                }
                
                console.log('Processing payment with nonce:', nonce);
                
                // Process payment via AJAX
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'swsib_process_payment',
                        nonce: nonce,
                        payment_method: paymentMethod,
                        payment_data: {},
                        checkout_data: checkoutData,
                        customer_data: customerData
                    },
                    success: function(response) {
                        console.log('Payment response:', response);
                        if (response.success) {
                            if (response.data.checkout_url) {
                                // Redirect to Stripe Checkout
                                window.location.href = response.data.checkout_url;
                            } else {
                                showSuccess(response.data.message || 'Payment processed successfully.');
                                if (response.data.redirect_url) {
                                    setTimeout(function() {
                                        window.location.href = response.data.redirect_url;
                                    }, 1500);
                                }
                            }
                        } else {
                            showError(response.data.message || 'Payment processing failed.');
                            $('#swsib-proceed-payment').prop('disabled', false)
                                .text('Proceed to Payment');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', error);
                        console.error('Status:', status);
                        console.error('Response:', xhr.responseText);
                        showError('An error occurred. Please try again.');
                        $('#swsib-proceed-payment').prop('disabled', false)
                            .text('Proceed to Payment');
                    }
                });
            });
            
            // Initial tax calculation - run this even on return from payment gateway
            calculateTax();
        }
    }
    
    /**
     * Validate the checkout form
     */
    function validateCheckoutForm() {
        var isValid = true;
        var errorMessage = '';
        
        // Required fields validation
        var requiredFields = [
            { id: 'customer_first_name', name: 'First Name' },
            { id: 'customer_last_name', name: 'Last Name' },
            { id: 'customer_email', name: 'Email' },
            { id: 'customer_country', name: 'Country' }
        ];
        
        var missingFields = [];
        
        requiredFields.forEach(function(field) {
            if (!$('#' + field.id).val()) {
                missingFields.push(field.name);
                isValid = false;
            }
        });
        
        if (missingFields.length > 0) {
            errorMessage = 'Please fill in the following required fields: ' + missingFields.join(', ');
            showError(errorMessage);
            return false;
        }
        
        // Email validation
        var emailField = $('#customer_email');
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (emailField.val() && !emailPattern.test(emailField.val())) {
            showError('Please enter a valid email address.');
            return false;
        }
        
        return isValid;
    }
    
    /**
     * Calculate tax based on country - improved nonce handling
     */
    function calculateTax() {
        if (!$('#customer_country').length) return;
        
        var country = $('#customer_country').val();
        var planPrice = parseFloat($('#total-amount').data('base-price') || 0);
        var planCurrency = $('#total-amount').data('currency') || '';
        
        if (!country || !planPrice) {
            $('#tax-row').hide();
            $('#tax-amount').text('0.00 ' + planCurrency);
            $('#total-amount').text(planPrice.toFixed(2) + ' ' + planCurrency);
            return;
        }
        
        var customerData = {
            'country': country,
            'email': $('#customer_email').val()
        };
        
        // Get plan ID from checkout data or data attribute
        var planId = '';
        if (typeof swsib_subscription_checkout !== 'undefined' && 
            swsib_subscription_checkout.plan && 
            swsib_subscription_checkout.plan.id) {
            planId = swsib_subscription_checkout.plan.id;
        }
        
        // Get nonce from multiple potential sources for tax calculation
        var nonce = '';
        
        // First try to get from form field
        if ($('#swsib_checkout_nonce').length) {
            nonce = $('#swsib_checkout_nonce').val();
        }
        
        // Then try the JS objects
        if (!nonce && typeof swsib_subscription_checkout !== 'undefined' && swsib_subscription_checkout.nonce) {
            nonce = swsib_subscription_checkout.nonce;
        } else if (!nonce && typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.checkout_nonce) {
            nonce = swsib_subscription_public.checkout_nonce;
        } else if (!nonce && typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.nonce) {
            nonce = swsib_subscription_public.nonce;
        }
        
        // Get AJAX URL
        var ajaxUrl = '';
        if (typeof swsib_subscription_checkout !== 'undefined' && swsib_subscription_checkout.ajaxurl) {
            ajaxUrl = swsib_subscription_checkout.ajaxurl;
        } else if (typeof swsib_subscription_public !== 'undefined' && swsib_subscription_public.ajaxurl) {
            ajaxUrl = swsib_subscription_public.ajaxurl;
        } else {
            ajaxUrl = '/wp-admin/admin-ajax.php';
        }
        
        console.log('Calculating tax with nonce:', nonce);
        
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'swsib_calculate_tax',
                nonce: nonce,
                customer_data: customerData,
                plan_id: planId
            },
            success: function(response) {
                console.log('Tax calculation response:', response);
                if (response.success) {
                    var taxAmount = parseFloat(response.data.tax_amount);
                    var totalAmount = planPrice + taxAmount;
                    
                    if (taxAmount > 0) {
                        $('#tax-row').show();
                        $('#tax-amount').text(taxAmount.toFixed(2) + ' ' + planCurrency);
                    } else {
                        $('#tax-row').hide();
                    }
                    
                    $('#total-amount').text(totalAmount.toFixed(2) + ' ' + planCurrency);
                } else if (response.data && response.data.message) {
                    console.error('Tax calculation error:', response.data.message);
                    // Only show error if it's not a security error, to avoid confusing users
                    if (response.data.message.indexOf('Security') === -1) {
                        showError(response.data.message);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Error calculating tax:', error);
                console.error('Status:', status);
                console.error('Response:', xhr.responseText);
            }
        });
    }
    
    /**
     * Initialize popup handlers
     */
    function initPopupHandlers() {
        $('.swsib-success-overlay').on('click', function() {
            $('.swsib-success-popup, .swsib-success-overlay').fadeOut();
        });
    }
    
    /**
     * Show success message
     */
    function showSuccess(message) {
        const $container = $('#swsib-message-container');
        if ($container.length) {
            $container.html('<div class="swsib-notice success"><p>' + message + '</p></div>')
                     .fadeIn()
                     .delay(3000)
                     .fadeOut();
        } else {
            alert(message);
        }
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        const $container = $('#swsib-message-container');
        if ($container.length) {
            $container.html('<div class="swsib-notice error"><p>' + message + '</p></div>')
                     .fadeIn();
            $('html, body').animate({
                scrollTop: $container.offset().top - 50
            }, 300);
        } else {
            alert(message);
        }
    }
    
    /**
     * Initialize subscription management functionality
     * - Enhanced for better Stripe subscription handling and state management
     */
    function initSubscriptionManagement() {
        // Cancel subscription button with off/on binding to avoid duplicates
        $('.swsib-cancel-btn').off('click').on('click', function(e) {
            e.preventDefault();
            
            var subscriptionId = $(this).data('subscription-id');
            var $button = $(this);
            
            // Using localized text if defined in swsib_subscription_public.translations; fallback if not
            var cancelConfirmText = (typeof swsib_subscription_public !== 'undefined' && 
                                  swsib_subscription_public.translations && 
                                  swsib_subscription_public.translations.cancelConfirmText) 
                || 'Are you sure you want to cancel this subscription? Your subscription will continue until the end of the current billing period.';
            
            if (!confirm(cancelConfirmText)) {
                return;
            }
            
            // Show loading overlay
            $('#swsib-loading-overlay').fadeIn();
            
            var originalText = $button.text();
            $button.prop('disabled', true).html('Processing... <span class="swsib-loading"></span>');
            
            // Get nonce for the operation
            var nonce = typeof swsib_subscription_public !== 'undefined' ? 
                      (swsib_subscription_public.frontend_nonce || swsib_subscription_public.nonce) : '';
            
            $.ajax({
                url: typeof swsib_subscription_public !== 'undefined' ? swsib_subscription_public.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'swsib_set_pending_cancellation',
                    nonce: nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        var successMessage = (typeof swsib_subscription_public !== 'undefined' && 
                                          swsib_subscription_public.translations && 
                                          swsib_subscription_public.translations.cancelSuccessMessage)
                            || 'Your subscription has been set to cancel at the end of the current billing period.';
                            
                        showSuccess(successMessage);
                        
                        // Reload page after showing the success message for a moment
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        // Hide loading overlay
                        $('#swsib-loading-overlay').fadeOut();
                        
                        showError(response.data.message || 'An error occurred. Please try again.');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loading overlay
                    $('#swsib-loading-overlay').fadeOut();
                    
                    console.error('AJAX error:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                    
                    showError('An error occurred. Please try again.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Initialize existing uncancel buttons
        $('.swsib-uncancel-btn').each(function() {
            initUncancelButton($(this));
        });
        
        // Initialize renew buttons
        $('.swsib-renew-btn').off('click').on('click', function(e) {
            e.preventDefault();
            
            var subscriptionId = $(this).data('subscription-id');
            var $button = $(this);
            
            var renewConfirmText = (typeof swsib_subscription_public !== 'undefined' && 
                                 swsib_subscription_public.translations && 
                                 swsib_subscription_public.translations.renewConfirmText)
                || 'Are you sure you want to renew this subscription? You will be redirected to payment.';
                
            if (!confirm(renewConfirmText)) {
                return;
            }
            
            // Show loading overlay
            $('#swsib-loading-overlay').fadeIn();
            
            $button.prop('disabled', true).html('Processing... <span class="swsib-loading"></span>');
            
            // Get nonce for the operation
            var nonce = typeof swsib_subscription_public !== 'undefined' ? 
                      (swsib_subscription_public.frontend_nonce || swsib_subscription_public.nonce) : '';
            
            $.ajax({
                url: typeof swsib_subscription_public !== 'undefined' ? swsib_subscription_public.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'swsib_renew_subscription',
                    nonce: nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.checkout_url) {
                            // Redirect to Stripe Checkout
                            window.location.href = response.data.checkout_url;
                        } else {
                            // Hide loading overlay
                            $('#swsib-loading-overlay').fadeOut();
                            
                            showSuccess(response.data.message || 'Renewal request processed successfully');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        }
                    } else {
                        // Hide loading overlay
                        $('#swsib-loading-overlay').fadeOut();
                        
                        showError(response.data.message || 'Failed to process renewal request.');
                        $button.prop('disabled', false).text('Renew Subscription');
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loading overlay
                    $('#swsib-loading-overlay').fadeOut();
                    
                    console.error('AJAX error:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                    
                    showError('An error occurred. Please try again.');
                    $button.prop('disabled', false).text('Renew Subscription');
                }
            });
        });
    }
    
    /**
     * Initialize uncancel button functionality with simplified approach - just reload on success
     */
    function initUncancelButton($button) {
        $button.off('click').on('click', function(e) {
            e.preventDefault();
            
            var resumeConfirmText = (typeof swsib_subscription_public !== 'undefined' && 
                                  swsib_subscription_public.translations && 
                                  swsib_subscription_public.translations.resumeConfirmText)
                || 'Are you sure you want to resume this subscription? This will prevent it from being cancelled at the end of the current billing period.';
                
            if (!confirm(resumeConfirmText)) {
                return;
            }
            
            var subscriptionId = $(this).data('subscription-id');
            var $btn = $(this);
            
            // Show loading overlay
            $('#swsib-loading-overlay').fadeIn();
            
            $btn.prop('disabled', true).html('Processing... <span class="swsib-loading"></span>');
            
            // Get nonce for the operation
            var nonce = typeof swsib_subscription_public !== 'undefined' ? 
                      (swsib_subscription_public.frontend_nonce || swsib_subscription_public.nonce) : '';
            
            $.ajax({
                url: typeof swsib_subscription_public !== 'undefined' ? swsib_subscription_public.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'swsib_uncancel_subscription',
                    nonce: nonce,
                    subscription_id: subscriptionId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        showSuccess(response.data.message || 'Subscription resumed successfully.');
                        
                        // Reload page after showing the success message
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        // Hide loading overlay
                        $('#swsib-loading-overlay').fadeOut();
                        
                        showError(response.data.message || 'An error occurred. Please try again.');
                        
                        // Reload if there's an error to ensure UI is in sync with server
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                },
                error: function(xhr, status, error) {
                    // Hide loading overlay
                    $('#swsib-loading-overlay').fadeOut();
                    
                    console.error('AJAX error:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                    
                    showError('An error occurred. Please try again.');
                    
                    // Reload if there's an error to ensure UI is in sync with server
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            });
        });
    }
    
    /**
     * Initialize Stripe portal button with enhanced error handling and loading overlay
     */
    function initStripePortalButton() {
        $('.swsib-stripe-portal-btn').off('click').on('click', function(e) {
            e.preventDefault();
            
            var subscriptionId = $(this).data('subscription-id');
            var $button = $(this);
            
            // Disable the button and show loading state
            $button.prop('disabled', true).html('Connecting... <span class="swsib-loading"></span>');
            
            // Show the loading overlay
            $('#swsib-loading-overlay').fadeIn();
            
            // Get the current page URL for the return URL
            var returnUrl = window.location.href;
            // Remove any existing portal return parameters
            returnUrl = returnUrl.replace(/[?&]swsib_stripe_portal_return=1/, '');
            
            // Get nonce for the operation
            var nonce = typeof swsib_subscription_public !== 'undefined' ? 
                      (swsib_subscription_public.frontend_nonce || swsib_subscription_public.nonce) : '';
            
            $.ajax({
                url: typeof swsib_subscription_public !== 'undefined' ? swsib_subscription_public.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'swsib_get_stripe_portal',
                    nonce: nonce,
                    subscription_id: subscriptionId,
                    return_url: returnUrl // Send the current URL as the return URL
                },
                success: function(response) {
                    if (response.success && response.data.portal_url) {
                        // Redirect to Stripe customer portal
                        window.location.href = response.data.portal_url;
                    } else {
                        // Hide the loading overlay
                        $('#swsib-loading-overlay').fadeOut();
                        
                        // Check for special portal not configured error
                        if (response.data && response.data.portal_not_configured) {
                            // Show special error for unconfigured portal
                            var errorMessage = response.data.message || 'The Stripe Customer Portal has not been configured by the site administrator.';
                            
                            // If current user is admin, show admin instructions
                            if (response.data.admin_message) {
                                errorMessage += '<br><br><strong>Admin Note:</strong> ' + response.data.admin_message;
                            }
                            
                            showError(errorMessage);
                        } else {
                            // Show regular error message
                            if (response.data && response.data.message) {
                                showError(response.data.message);
                            } else {
                                showError('Could not connect to Stripe at this time. Please try again later or contact support.');
                            }
                        }
                        
                        // Reset the button
                        $button.prop('disabled', false).text('Manage in Stripe');
                    }
                },
                error: function(xhr, status, error) {
                    // Hide the loading overlay
                    $('#swsib-loading-overlay').fadeOut();
                    
                    console.error('Error connecting to Stripe portal:', error);
                    console.error('Status:', status);
                    console.error('Response:', xhr.responseText);
                    
                    showError('Connection error. Please try again later or contact support if the problem persists.');
                    
                    // Reset the button
                    $button.prop('disabled', false).text('Manage in Stripe');
                },
                timeout: 25000 // 25 second timeout since Stripe operations can take time
            });
        });
    }
    
    /**
     * Update sidebar count values
     */
    function updateSidebarCounts(status, change) {
        var $item = $('.swsib-summary-item[data-filter="' + status + '"]');
        if ($item.length) {
            var currentCount = parseInt($item.find('.swsib-summary-value').text(), 10);
            if (!isNaN(currentCount)) {
                $item.find('.swsib-summary-value').text(currentCount + change);
                
                // Update empty class for styling
                if (currentCount + change === 0) {
                    $item.addClass('empty');
                } else if (currentCount === 0 && change > 0) {
                    $item.removeClass('empty');
                }
            }
        }
    }
    
    /**
     * Initialize sidebar filtering functionality
     */
    function initSidebarFiltering() {
        // Click on summary items to filter
        $('.swsib-summary-item.clickable').on('click', function() {
            var status = $(this).data('filter');
            
            // If already selected, deselect
            if ($(this).hasClass('active')) {
                $(this).removeClass('active');
                $('#subscription-status-filter').val('all').trigger('change');
            } else {
                // Deselect other items
                $('.swsib-summary-item.clickable').removeClass('active');
                $(this).addClass('active');
                
                // Set filter dropdown and trigger change
                $('#subscription-status-filter').val(status).trigger('change');
            }
        });
        
        // Filter dropdown changes
        $('#subscription-status-filter').on('change', function() {
            var status = $(this).val();
            
            if (status === 'all') {
                $('.swsib-subscription-item').show();
                $('.swsib-summary-item.clickable').removeClass('active');
            } else {
                $('.swsib-subscription-item').hide();
                $('.swsib-subscription-item[data-status="' + status + '"]').show();
                
                // Highlight corresponding summary card
                $('.swsib-summary-item.clickable').removeClass('active');
                $('.swsib-summary-item[data-filter="' + status + '"]').addClass('active');
            }
        });
    }
})(jQuery);