<?php
/**
 * PE Subscription - Checkout Page Template
 * 
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Format price with currency
$price_formatted = isset($plan) && isset($plan['price']) && isset($plan['currency'])
    ? number_format($plan['price'], 2) . ' ' . $plan['currency']
    : '';

// Format billing frequency (not used in this revamp, but left for consistency)
$billing_frequencies = array(
    'weekly'     => __('Weekly', 'swiftspeed-siberian'),
    'monthly'    => __('Monthly', 'swiftspeed-siberian'),
    'quarterly'  => __('Every 3 months', 'swiftspeed-siberian'),
    'biannually' => __('Every 6 months', 'swiftspeed-siberian'),
    'annually'   => __('Yearly', 'swiftspeed-siberian')
);

$billing_frequency = isset($plan) && isset($plan['billing_frequency']) && isset($billing_frequencies[$plan['billing_frequency']])
    ? $billing_frequencies[$plan['billing_frequency']]
    : '';

// Get user data for pre-filling
$user_info = null;
if (is_user_logged_in()) {
    $user_info = get_userdata(get_current_user_id());
}

// Generate nonce for security
$checkout_nonce = wp_create_nonce('swsib_subscription_checkout_nonce');
?>
<div class="swsib-container swsib-checkout">
    <!-- Checkout Header -->
    <div class="swsib-checkout-header">
        <h1 class="swsib-checkout-title"><?php echo esc_html($atts['title']); ?></h1>
        <p class="swsib-checkout-subtitle"><?php _e('Complete your purchase to activate your subscription', 'swiftspeed-siberian'); ?></p>
    </div>
    
    <!-- Message Container -->
    <div id="swsib-message-container" style="display: none;"></div>
    
    <!-- Main Checkout Form -->
    <form id="swsib-checkout-form" class="swsib-checkout-form">
        <!-- Hidden field for nonce -->
        <input type="hidden" id="swsib_checkout_nonce" name="swsib_checkout_nonce" value="<?php echo esc_attr($checkout_nonce); ?>">
        
        <!-- Plan Details Section -->
        <div class="swsib-section">
            <h2 class="swsib-section-title"><?php _e('Subscription Details', 'swiftspeed-siberian'); ?></h2>
            
            <div class="swsib-plan-details">
                <h3 class="swsib-plan-name"><?php echo esc_html($plan['name']); ?></h3>
                <div class="swsib-plan-description">
                    <?php 
                        // Always output the admin-set description (even if empty),
                        // add a full stop, then append the additional message.
                        echo esc_html($plan['description']);
                        echo '. ';
                        echo sprintf(
                            __('With this subscription plan you can create up to <strong style="color: #ff6600;">%d</strong> app(s).', 'swiftspeed-siberian'),
                            $plan['app_quantity']
                        );
                    ?>
                </div>
            </div>
        </div>

        <!-- Customer Information Section -->
        <div class="swsib-section">
            <h2 class="swsib-section-title"><?php _e('Customer Information', 'swiftspeed-siberian'); ?></h2>
            
            <div class="swsib-customer-info">
                <div class="swsib-form-row">
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_first_name">
                                <?php _e('First Name', 'swiftspeed-siberian'); ?> <span class="required">*</span>
                            </label>
                            <input type="text" id="customer_first_name" name="customer_first_name" required
                                value="<?php echo $user_info ? esc_attr($user_info->first_name) : ''; ?>">
                        </div>
                    </div>
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_last_name">
                                <?php _e('Last Name', 'swiftspeed-siberian'); ?> <span class="required">*</span>
                            </label>
                            <input type="text" id="customer_last_name" name="customer_last_name" required
                                value="<?php echo $user_info ? esc_attr($user_info->last_name) : ''; ?>">
                        </div>
                    </div>
                </div>
                
                <div class="swsib-form-row">
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_email">
                                <?php _e('Email Address', 'swiftspeed-siberian'); ?> <span class="required">*</span>
                            </label>
                            <input type="email" id="customer_email" name="customer_email" required
                                value="<?php echo $user_info ? esc_attr($user_info->user_email) : ''; ?>">
                        </div>
                    </div>
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_phone"><?php _e('Phone Number', 'swiftspeed-siberian'); ?></label>
                            <input type="tel" id="customer_phone" name="customer_phone">
                        </div>
                    </div>
                </div>
                
                <div class="swsib-form-row">
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_address"><?php _e('Address', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="customer_address" name="customer_address">
                        </div>
                    </div>
                </div>
                
                <div class="swsib-form-row">
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_city"><?php _e('City', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="customer_city" name="customer_city">
                        </div>
                    </div>
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_state"><?php _e('State/Province', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="customer_state" name="customer_state">
                        </div>
                    </div>
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_zip"><?php _e('Postal Code', 'swiftspeed-siberian'); ?></label>
                            <input type="text" id="customer_zip" name="customer_zip">
                        </div>
                    </div>
                </div>
                
                <div class="swsib-form-row">
                    <div class="swsib-form-column">
                        <div class="swsib-field">
                            <label for="customer_country">
                                <?php _e('Country', 'swiftspeed-siberian'); ?> <span class="required">*</span>
                            </label>
                            <select id="customer_country" name="customer_country" required>
                                <option value=""><?php _e('Select your country', 'swiftspeed-siberian'); ?></option>
                                <?php 
                                    $countries = array(
                                        'US' => __('United States', 'swiftspeed-siberian'),
                                        'CA' => __('Canada', 'swiftspeed-siberian'),
                                        'GB' => __('United Kingdom', 'swiftspeed-siberian'),
                                        'AU' => __('Australia', 'swiftspeed-siberian'),
                                        'DE' => __('Germany', 'swiftspeed-siberian'),
                                        'FR' => __('France', 'swiftspeed-siberian'),
                                        'IN' => __('India', 'swiftspeed-siberian'),
                                        'NG' => __('Nigeria', 'swiftspeed-siberian'),
                                        'ZA' => __('South Africa', 'swiftspeed-siberian'),
                                        'JP' => __('Japan', 'swiftspeed-siberian'),
                                        'BR' => __('Brazil', 'swiftspeed-siberian'),
                                        'ES' => __('Spain', 'swiftspeed-siberian'),
                                        'IT' => __('Italy', 'swiftspeed-siberian'),
                                        'NL' => __('Netherlands', 'swiftspeed-siberian')
                                    );
                                    foreach ($countries as $code => $name) :
                                ?>
                                    <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Order Summary Section -->
        <div class="swsib-section">
            <h2 class="swsib-section-title"><?php _e('Order Summary', 'swiftspeed-siberian'); ?></h2>
            
            <div class="swsib-order-summary">
                <table class="swsib-order-table">
                    <tbody>
                        <tr>
                            <td><?php echo esc_html($plan['name']); ?></td>
                            <td class="swsib-amount"><?php echo esc_html($price_formatted); ?></td>
                        </tr>
                        <tr id="tax-row" style="display: none;">
                            <td><?php _e('Tax', 'swiftspeed-siberian'); ?></td>
                            <td class="swsib-amount" id="tax-amount">0.00 <?php echo esc_html($plan['currency']); ?></td>
                        </tr>
                        <tr class="swsib-total-row">
                            <td><strong><?php _e('Total', 'swiftspeed-siberian'); ?></strong></td>
                            <td class="swsib-amount" id="total-amount" data-base-price="<?php echo esc_attr($plan['price']); ?>" data-currency="<?php echo esc_attr($plan['currency']); ?>">
                                <strong><?php echo esc_html($price_formatted); ?></strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Payment Method Section -->
        <div class="swsib-section">
            <h2 class="swsib-section-title"><?php _e('Choose Options', 'swiftspeed-siberian'); ?></h2>
            
            <div class="swsib-payment-methods">
                <?php 
                    // Assuming active payment gateways are already defined.
                    // If none exist, a warning message is shown.
                    if (isset($active_gateways) && is_array($active_gateways) && count($active_gateways) > 0) : 
                ?>
                    <div class="swsib-payment-method-selector">
                        <?php foreach ($active_gateways as $gateway_id => $gateway) :
                                if (isset($gateway['enabled']) && $gateway['enabled']):
                                    $gateway_image = '';
                                    $gateway_name = '';
                                    if ($gateway_id === 'stripe') {
                                        $gateway_image = SWSIB_PLUGIN_URL . 'admin/includes/subscription/backend/payments/stripe/stripe.png';
                                        $gateway_name = __('Pay with Card', 'swiftspeed-siberian');
                                    } else if ($gateway_id === 'paypal') {
                                        $gateway_image = SWSIB_PLUGIN_URL . 'admin/includes/subscription/backend/payments/paypal/paypal.png';
                                        $gateway_name = __('Pay with PayPal', 'swiftspeed-siberian');
                                    }
                        ?>
                            <div class="swsib-payment-method <?php echo $gateway_id === 'stripe' ? 'active' : ''; ?>" data-method="<?php echo esc_attr($gateway_id); ?>">
                                <?php if (!empty($gateway_image)) : ?>
                                    <img src="<?php echo esc_url($gateway_image); ?>" alt="<?php echo esc_attr($gateway_name); ?>">
                                <?php endif; ?>
                                <span><?php echo esc_html($gateway_name); ?></span>
                            </div>
                        <?php 
                                endif;
                              endforeach; 
                        ?>
                    </div>
                <?php else: ?>
                    <div class="swsib-notice warning">
                        <p><?php _e('No payment methods are available. Please contact the site administrator.', 'swiftspeed-siberian'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Submit Button -->
            <div class="swsib-button-container">
                <button id="swsib-proceed-payment" type="submit" class="swsib-button">
                    <?php _e('Proceed to Payment', 'swiftspeed-siberian'); ?>
                </button>
                
                <div class="swsib-secure-wrapper">
                    <img class="swsib-secure-icon" src="<?php echo esc_url( SWSIB_PLUGIN_URL . 'admin/includes/subscription/public/templates/secured.png' ); ?>" alt="<?php esc_attr_e('Secure Payment', 'swiftspeed-siberian'); ?>" />
                    <span class="swsib-secure-text">
                        <?php _e('Your payment information is secure and encrypted.', 'swiftspeed-siberian'); ?>
                    </span>
                </div>
            </div>
        </div>
    </form>

    <script>
    // Make sure jQuery is fully loaded before executing this script
    document.addEventListener('DOMContentLoaded', function() {
        // Check if jQuery is loaded
        if (typeof jQuery === 'undefined') {
            console.error('jQuery is not loaded. Waiting for it...');
            var checkJQuery = setInterval(function() {
                if (typeof jQuery !== 'undefined') {
                    console.log('jQuery is now available');
                    clearInterval(checkJQuery);
                    initCheckoutForm();
                }
            }, 100);
        } else {
            initCheckoutForm();
        }
    });

    function initCheckoutForm() {
        jQuery(document).ready(function($) {
            console.log('Checkout form initialization...');
            
            // Check for returns from payment gateways
            var urlParams = new URLSearchParams(window.location.search);
            var isReturn = urlParams.has('swsib_checkout_cancel') || urlParams.has('swsib_checkout_success');
            
            // Reset payment method selection to Stripe on return
            if (isReturn) {
                console.log('Detected return from payment gateway, resetting to Stripe');
                $('.swsib-payment-method').removeClass('active');
                $('.swsib-payment-method[data-method="stripe"]').addClass('active');
            }
            
            // Make payment methods clickable
            $('.swsib-payment-method').on('click', function() {
                $('.swsib-payment-method').removeClass('active');
                $(this).addClass('active');
                
                // Recalculate tax when payment method changes
                calculateTax();
            });
            
            // Tax calculation when country changes
            $('#customer_country').on('change', function() {
                calculateTax();
            });
            
            // Handle form submission
            $('#swsib-checkout-form').on('submit', function(e) {
                e.preventDefault();
                
                // Basic validation
                if (!$('#customer_first_name').val() || !$('#customer_last_name').val() || 
                    !$('#customer_email').val() || !$('#customer_country').val()) {
                    
                    showError('<?php _e('Please fill in all required fields.', 'swiftspeed-siberian'); ?>');
                    return false;
                }
                
                // Get proper checkout data
                var checkoutData = window.swsib_subscription_checkout ? 
                                 window.swsib_subscription_checkout.checkout_data : 
                                 <?php echo json_encode($checkout_data); ?>;
                
                // Get the nonce from the form's hidden field
                var nonce = $('#swsib_checkout_nonce').val();
                
                // If we can't get it from the hidden field, try to get from the localized JS object
                if (!nonce && window.swsib_subscription_checkout && window.swsib_subscription_checkout.nonce) {
                    nonce = window.swsib_subscription_checkout.nonce;
                } else if (!nonce && window.swsib_subscription_public && window.swsib_subscription_public.checkout_nonce) {
                    nonce = window.swsib_subscription_public.checkout_nonce;
                }
                
                // If we still can't get the nonce, use the PHP-generated one as a last resort
                if (!nonce) {
                    nonce = '<?php echo esc_js($checkout_nonce); ?>';
                }
                
                // Get customer data
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
                
                // Get payment method
                var paymentMethod = $('.swsib-payment-method.active').data('method') || 'stripe';
                
                // Disable submit button and show loading
                $('#swsib-proceed-payment').prop('disabled', true)
                    .html('<?php _e('Processing...', 'swiftspeed-siberian'); ?> <span class="swsib-loading"></span>');
                
                // Determine AJAX URL
                var ajaxUrl = '';
                if (window.swsib_subscription_checkout && window.swsib_subscription_checkout.ajaxurl) {
                    ajaxUrl = window.swsib_subscription_checkout.ajaxurl;
                } else if (window.swsib_subscription_public && window.swsib_subscription_public.ajaxurl) {
                    ajaxUrl = window.swsib_subscription_public.ajaxurl;
                } else {
                    ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                }
                
                console.log('Sending checkout request with nonce:', nonce);
                console.log('Selected payment method:', paymentMethod);
                
                // Send request to process payment
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
                                // Redirect to payment gateway Checkout page
                                window.location.href = response.data.checkout_url;
                            } else {
                                showSuccess(response.data.message || '<?php _e('Payment processed successfully', 'swiftspeed-siberian'); ?>');
                                
                                // Redirect to success page if URL provided
                                if (response.data.redirect_url) {
                                    setTimeout(function() {
                                        window.location.href = response.data.redirect_url;
                                    }, 1500);
                                }
                            }
                        } else {
                            showError(response.data.message || '<?php _e('An error occurred. Please try again.', 'swiftspeed-siberian'); ?>');
                            $('#swsib-proceed-payment').prop('disabled', false)
                                .text('<?php _e('Proceed to Payment', 'swiftspeed-siberian'); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        console.error('Status:', status);
                        console.error('Response:', xhr.responseText);
                        showError('<?php _e('An error occurred. Please try again.', 'swiftspeed-siberian'); ?>');
                        $('#swsib-proceed-payment').prop('disabled', false)
                            .text('<?php _e('Proceed to Payment', 'swiftspeed-siberian'); ?>');
                    }
                });
            });
            
            // Initial tax calculation
            calculateTax();
            
            function calculateTax() {
                var country = $('#customer_country').val();
                var planPrice = <?php echo esc_js(floatval($plan['price'])); ?>;
                var planCurrency = '<?php echo esc_js($plan['currency']); ?>';
                
                if (!country) {
                    // Reset tax if no country selected
                    $('#tax-row').hide();
                    $('#tax-amount').text('0.00 ' + planCurrency);
                    $('#total-amount').text(planPrice.toFixed(2) + ' ' + planCurrency);
                    return;
                }
                
                // Get customer data to send for tax calculation
                var customerData = {
                    'country': country,
                    'email': $('#customer_email').val()
                };
                
                // Determine AJAX URL
                var ajaxUrl = '';
                if (window.swsib_subscription_checkout && window.swsib_subscription_checkout.ajaxurl) {
                    ajaxUrl = window.swsib_subscription_checkout.ajaxurl;
                } else if (window.swsib_subscription_public && window.swsib_subscription_public.ajaxurl) {
                    ajaxUrl = window.swsib_subscription_public.ajaxurl;
                } else {
                    ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
                }
                
                // Get nonce from form field or JS object
                var nonce = $('#swsib_checkout_nonce').val();
                if (!nonce && window.swsib_subscription_checkout && window.swsib_subscription_checkout.nonce) {
                    nonce = window.swsib_subscription_checkout.nonce;
                } else if (!nonce && window.swsib_subscription_public && window.swsib_subscription_public.checkout_nonce) {
                    nonce = window.swsib_subscription_public.checkout_nonce;
                } else if (!nonce) {
                    nonce = '<?php echo esc_js($checkout_nonce); ?>';
                }
                
                console.log('Sending tax calculation request with nonce:', nonce);
                
                // Send AJAX request to calculate tax
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'swsib_calculate_tax',
                        nonce: nonce,
                        customer_data: customerData,
                        plan_id: '<?php echo esc_js($plan['id']); ?>'
                    },
                    success: function(response) {
                        console.log('Tax calculation response:', response);
                        
                        if (response.success) {
                            var taxAmount = parseFloat(response.data.tax_amount);
                            var totalAmount = planPrice + taxAmount;
                            
                            // Update UI
                            if (taxAmount > 0) {
                                $('#tax-row').show();
                                $('#tax-amount').text(taxAmount.toFixed(2) + ' ' + planCurrency);
                            } else {
                                $('#tax-row').hide();
                            }
                            
                            $('#total-amount').text(totalAmount.toFixed(2) + ' ' + planCurrency);
                        } else {
                            console.error('Tax calculation failed:', response);
                            
                            // Show error if there's a message
                            if (response.data && response.data.message) {
                                showError(response.data.message);
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Tax calculation AJAX error:', error);
                        console.error('Status:', status);
                        console.error('Response:', xhr.responseText);
                    }
                });
            }
            
            // Helper functions for showing messages
            function showSuccess(message) {
                $('#swsib-message-container').html('<div class="swsib-notice success"><p>' + message + '</p></div>')
                    .fadeIn();
            }
            
            function showError(message) {
                $('#swsib-message-container').html('<div class="swsib-notice error"><p>' + message + '</p></div>')
                    .fadeIn();
                
                // Scroll to message
                $('html, body').animate({
                    scrollTop: $('#swsib-message-container').offset().top - 50
                }, 300);
            }
        });
    }
    </script>

    <style>
    /* Payment method selector styles */
    .swsib-payment-method-selector {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
    }
    
.swsib-payment-method-selector {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;     /* Centers items horizontally */
    align-items: center;         /* Centers items vertically within the container height */
    gap: 15px;
    margin-bottom: 20px;
    min-height: 100px;           /* Optional: Ensures there's height to allow vertical centering */
}
 
    .swsib-secure-wrapper {
        margin-top: 40px;
        text-align: center;
        display: flex;
        flex-direction: row;
        align-items: center;
        justify-content: center;
        gap: 5px;
    }

    .swsib-secure-icon {
        width: 25px;
        height: 25px;
        object-fit: contain;
    }

    .swsib-secure-text {
        font-size: 13px;
        color: #555;
    }

    .swsib-payment-method img {
        height: 24px;
        margin-right: 10px;
    }
    
    .swsib-payment-method.active {
        border-color: #3a4b79;
        background-color: #f0f4ff;
    }
    
    .swsib-payment-method:hover {
        border-color: #3a4b79;
        background-color: #f5f8ff;
    }
    
    @media (max-width: 600px) {
        .swsib-payment-method {
            width: 100%;
        }
    }
    
    /* Enhanced error styling */
    .swsib-notice {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 5px;
        color: #333;
    }
    
    .swsib-notice.error {
        background-color: #ffebee;
        border-left: 4px solid #f44336;
    }
    
    .swsib-notice.success {
        background-color: #e8f5e9;
        border-left: 4px solid #4caf50;
    }
    
    /* Improved loading indicator */
    .swsib-loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        margin-left: 10px;
        border: 2px solid rgba(255,255,255,0.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 0.8s ease-in-out infinite;
        vertical-align: middle;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    </style>
</div>