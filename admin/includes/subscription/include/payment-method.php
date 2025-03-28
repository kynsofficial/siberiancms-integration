<?php
/**
 * PE Subscription - Payment Methods Tab Content
 *
 * @package SwiftSpeed_Siberian
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get options
$options               = get_option( 'swsib_options', array() );
$subscription_options  = isset( $options['subscription'] ) ? $options['subscription'] : array();
$payment_gateways      = isset( $subscription_options['payment_gateways'] ) ? $subscription_options['payment_gateways'] : array();

// Ensure Stripe settings exist
if ( ! isset( $payment_gateways['stripe'] ) ) {
    $payment_gateways['stripe'] = array(
        'enabled'              => false,
        'test_mode'            => true,
        'test_publishable_key' => '',
        'test_secret_key'      => '',
        'live_publishable_key' => '',
        'live_secret_key'      => '',
        'webhook_secret'       => '',
    );
}

// Ensure PayPal settings exist
if ( ! isset( $payment_gateways['paypal'] ) ) {
    $payment_gateways['paypal'] = array(
        'enabled'              => false,
        'sandbox_mode'         => true,
        'sandbox_client_id'    => '',
        'sandbox_client_secret' => '',
        'live_client_id'       => '',
        'live_client_secret'   => '',
        'webhook_id'           => '',
    );
}

// Extract Stripe settings
$stripe_enabled             = filter_var( $payment_gateways['stripe']['enabled'], FILTER_VALIDATE_BOOLEAN );
$stripe_test_mode           = filter_var( $payment_gateways['stripe']['test_mode'], FILTER_VALIDATE_BOOLEAN );
$stripe_test_publishable    = $payment_gateways['stripe']['test_publishable_key'];
$stripe_test_secret         = $payment_gateways['stripe']['test_secret_key'];
$stripe_live_publishable    = $payment_gateways['stripe']['live_publishable_key'];
$stripe_live_secret         = $payment_gateways['stripe']['live_secret_key'];
$stripe_webhook_secret      = $payment_gateways['stripe']['webhook_secret'];

// Extract PayPal settings
$paypal_enabled             = filter_var( $payment_gateways['paypal']['enabled'], FILTER_VALIDATE_BOOLEAN );
$paypal_sandbox_mode        = filter_var( $payment_gateways['paypal']['sandbox_mode'], FILTER_VALIDATE_BOOLEAN );
$paypal_sandbox_client_id   = $payment_gateways['paypal']['sandbox_client_id'];
$paypal_sandbox_client_secret = $payment_gateways['paypal']['sandbox_client_secret'];
$paypal_live_client_id      = $payment_gateways['paypal']['live_client_id'];
$paypal_live_client_secret  = $payment_gateways['paypal']['live_client_secret'];
$paypal_webhook_id          = $payment_gateways['paypal']['webhook_id'];

// Notices
if ( isset( $_GET['updated'], $_GET['section'] ) && $_GET['updated'] === 'true' && $_GET['section'] === 'payment' ) {
    echo '<div class="swsib-notice success"><p>' . esc_html__( 'Payment methods updated successfully.', 'swiftspeed-siberian' ) . '</p></div>';
}
if ( isset( $_GET['error'], $_GET['section'] ) && $_GET['error'] === 'nonce_failed' && $_GET['section'] === 'payment' ) {
    echo '<div class="swsib-notice error"><p>' . esc_html__( 'Security check failed. Please try again.', 'swiftspeed-siberian' ) . '</p></div>';
}
?>

<div class="swsib-notice info">
    <p>
        <strong><?php esc_html_e( 'Important:', 'swiftspeed-siberian' ); ?></strong>
        <?php esc_html_e( 'Configure payment gateways for processing subscription payments. At least one payment gateway must be enabled for subscriptions to work.', 'swiftspeed-siberian' ); ?>
    </p>
</div>

<?php if ( ! swsib()->is_db_configured() ) : ?>
    <div class="swsib-notice warning">
        <p><strong><?php esc_html_e( 'Database Connection Required', 'swiftspeed-siberian' ); ?></strong></p>
        <p><?php esc_html_e( 'You must configure the database connection in the DB Connect tab before configuring payment methods.', 'swiftspeed-siberian' ); ?></p>
        <p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=swsib-integration&tab_id=db_connect' ) ); ?>" class="button">
                <?php esc_html_e( 'Configure Database', 'swiftspeed-siberian' ); ?>
            </a>
        </p>
    </div>
<?php else : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="swsib-settings-form" id="payment_settings_form">
        <?php wp_nonce_field( 'swsib_subscription_payments_nonce', '_wpnonce_swsib_subscription_payments' ); ?>
        <input type="hidden" name="action" value="swsib_save_subscription_payments">
        <input type="hidden" name="tab_id" value="subscription">
        <input type="hidden" name="section" value="payment">

        <div class="swsib-section">
            <h3><?php esc_html_e( 'Payment Gateways', 'swiftspeed-siberian' ); ?></h3>

            <!-- Stripe Gateway -->
            <div class="swsib-gateway">
                <div class="swsib-gateway-header">
                    <div class="swsib-gateway-title">
                        <img src="<?php echo esc_url( SWSIB_PLUGIN_URL . 'admin/includes/subscription/backend/payments/stripe/stripe.png' ); ?>"
                             alt="Stripe"
                             class="swsib-gateway-icon">
                        <span><?php esc_html_e( 'Stripe', 'swiftspeed-siberian' ); ?></span>

                        <!-- Active/Inactive tag -->
                        <span class="swsib-gateway-status <?php echo $stripe_enabled ? 'active' : 'inactive'; ?>">
                            <?php echo $stripe_enabled
                                ? esc_html__( 'Active', 'swiftspeed-siberian' )
                                : esc_html__( 'Inactive', 'swiftspeed-siberian' ); ?>
                        </span>

                        <!-- Live/Testing tag -->
                        <?php if ( $stripe_enabled ) : ?>
                            <span class="swsib-gateway-mode <?php echo $stripe_test_mode ? 'testing' : 'live'; ?>">
                                <?php echo $stripe_test_mode
                                    ? esc_html__( 'Testing', 'swiftspeed-siberian' )
                                    : esc_html__( 'Live', 'swiftspeed-siberian' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="swsib-gateway-toggle">
                        <label class="swsib-checkbox-label" for="stripe-enabled">
                            <input type="checkbox"
                                   id="stripe-enabled"
                                   name="swsib_options[subscription][payment_gateways][stripe][enabled]"
                                   value="1"
                                   <?php checked( $stripe_enabled ); ?>>
                            <?php esc_html_e( 'Enable Stripe Gateway', 'swiftspeed-siberian' ); ?>
                        </label>
                    </div>
                </div>

                <div id="stripe-settings-container" class="swsib-gateway-content" style="<?php echo $stripe_enabled ? '' : 'display:none;'; ?>">
                    <div class="swsib-field">
                        <label class="swsib-checkbox-label" for="stripe-test-mode">
                            <input type="checkbox"
                                   id="stripe-test-mode"
                                   name="swsib_options[subscription][payment_gateways][stripe][test_mode]"
                                   value="1"
                                   <?php checked( $stripe_test_mode ); ?>>
                            <?php esc_html_e( 'Enable Test Mode', 'swiftspeed-siberian' ); ?>
                        </label>
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'When enabled, Stripe will use your Test API keys and no real charges will occur.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <h4><?php esc_html_e( 'Test API Keys', 'swiftspeed-siberian' ); ?></h4>
                    <div class="swsib-field">
                        <label for="stripe-test-pub"><?php esc_html_e( 'Test Publishable Key', 'swiftspeed-siberian' ); ?></label>
                        <input type="text"
                               id="stripe-test-pub"
                               name="swsib_options[subscription][payment_gateways][stripe][test_publishable_key]"
                               value="<?php echo esc_attr( $stripe_test_publishable ); ?>"
                               class="regular-text">
                    </div>
                    <div class="swsib-field">
                        <label for="stripe-test-secret"><?php esc_html_e( 'Test Secret Key', 'swiftspeed-siberian' ); ?></label>
                        <input type="password"
                               id="stripe-test-secret"
                               name="swsib_options[subscription][payment_gateways][stripe][test_secret_key]"
                               value="<?php echo esc_attr( $stripe_test_secret ); ?>"
                               class="regular-text">
                    </div>

                    <h4><?php esc_html_e( 'Live API Keys', 'swiftspeed-siberian' ); ?></h4>
                    <div class="swsib-field">
                        <label for="stripe-live-pub"><?php esc_html_e( 'Live Publishable Key', 'swiftspeed-siberian' ); ?></label>
                        <input type="text"
                               id="stripe-live-pub"
                               name="swsib_options[subscription][payment_gateways][stripe][live_publishable_key]"
                               value="<?php echo esc_attr( $stripe_live_publishable ); ?>"
                               class="regular-text">
                    </div>
                    <div class="swsib-field">
                        <label for="stripe-live-secret"><?php esc_html_e( 'Live Secret Key', 'swiftspeed-siberian' ); ?></label>
                        <input type="password"
                               id="stripe-live-secret"
                               name="swsib_options[subscription][payment_gateways][stripe][live_secret_key]"
                               value="<?php echo esc_attr( $stripe_live_secret ); ?>"
                               class="regular-text">
                    </div>

                    <div class="swsib-field">
                        <label for="stripe-webhook-secret"><?php esc_html_e( 'Webhook Secret', 'swiftspeed-siberian' ); ?></label>
                        <input type="password"
                               id="stripe-webhook-secret"
                               name="swsib_options[subscription][payment_gateways][stripe][webhook_secret]"
                               value="<?php echo esc_attr( $stripe_webhook_secret ); ?>"
                               class="regular-text">
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'Used to validate incoming Stripe webhook events. This is required for proper subscription status synchronization.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <div class="swsib-field">
                        <label><?php esc_html_e( 'Webhook URL', 'swiftspeed-siberian' ); ?></label>
                        <code><?php echo esc_url( home_url( '/?swsib_stripe_webhook=1' ) ); ?></code>
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'Add this URL under Developers > Webhooks in your Stripe Dashboard.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <div class="swsib-notice info">
                        <p><strong><?php esc_html_e( 'Required webhook events:', 'swiftspeed-siberian' ); ?></strong></p>
                        <ul>
                            <li><?php esc_html_e( 'checkout.session.completed', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'checkout.session.expired', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'customer.subscription.created', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'customer.subscription.updated', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'customer.subscription.deleted', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'invoice.payment_succeeded', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'invoice.payment_failed', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'charge.succeeded', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'charge.failed', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'charge.refunded', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'billing_portal.session.created', 'swiftspeed-siberian' ); ?></li>
                        </ul>
                    </div>

                    <div class="swsib-notice warning">
                        <p><strong><?php esc_html_e('Important:', 'swiftspeed-siberian'); ?></strong> <?php esc_html_e('Webhooks are required for proper subscription status synchronization. After setting up webhooks in your Stripe Dashboard, make sure to add the Webhook Secret key above.', 'swiftspeed-siberian'); ?></p>
                    </div>
                </div>
            </div>

            <!-- PayPal Gateway -->
            <div class="swsib-gateway">
                <div class="swsib-gateway-header">
                    <div class="swsib-gateway-title">
                        <img src="<?php echo esc_url( SWSIB_PLUGIN_URL . 'admin/includes/subscription/backend/payments/paypal/paypal.png' ); ?>"
                             alt="PayPal"
                             class="swsib-gateway-icon">
                        <span><?php esc_html_e( 'PayPal', 'swiftspeed-siberian' ); ?></span>

                        <!-- Active/Inactive tag -->
                        <span class="swsib-gateway-status <?php echo $paypal_enabled ? 'active' : 'inactive'; ?>">
                            <?php echo $paypal_enabled
                                ? esc_html__( 'Active', 'swiftspeed-siberian' )
                                : esc_html__( 'Inactive', 'swiftspeed-siberian' ); ?>
                        </span>

                        <!-- Sandbox/Live tag -->
                        <?php if ( $paypal_enabled ) : ?>
                            <span class="swsib-gateway-mode <?php echo $paypal_sandbox_mode ? 'testing' : 'live'; ?>">
                                <?php echo $paypal_sandbox_mode
                                    ? esc_html__( 'Sandbox', 'swiftspeed-siberian' )
                                    : esc_html__( 'Live', 'swiftspeed-siberian' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="swsib-gateway-toggle">
                        <label class="swsib-checkbox-label" for="paypal-enabled">
                            <input type="checkbox"
                                   id="paypal-enabled"
                                   name="swsib_options[subscription][payment_gateways][paypal][enabled]"
                                   value="1"
                                   <?php checked( $paypal_enabled ); ?>>
                            <?php esc_html_e( 'Enable PayPal Gateway', 'swiftspeed-siberian' ); ?>
                        </label>
                    </div>
                </div>

                <div id="paypal-settings-container" class="swsib-gateway-content" style="<?php echo $paypal_enabled ? '' : 'display:none;'; ?>">
                    <div class="swsib-field">
                        <label class="swsib-checkbox-label" for="paypal-sandbox-mode">
                            <input type="checkbox"
                                   id="paypal-sandbox-mode"
                                   name="swsib_options[subscription][payment_gateways][paypal][sandbox_mode]"
                                   value="1"
                                   <?php checked( $paypal_sandbox_mode ); ?>>
                            <?php esc_html_e( 'Enable Sandbox Mode', 'swiftspeed-siberian' ); ?>
                        </label>
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'When enabled, PayPal will use your Sandbox API credentials and no real charges will occur.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <h4><?php esc_html_e( 'Sandbox API Credentials', 'swiftspeed-siberian' ); ?></h4>
                    <div class="swsib-field">
                        <label for="paypal-sandbox-client-id"><?php esc_html_e( 'Sandbox Client ID', 'swiftspeed-siberian' ); ?></label>
                        <input type="text"
                               id="paypal-sandbox-client-id"
                               name="swsib_options[subscription][payment_gateways][paypal][sandbox_client_id]"
                               value="<?php echo esc_attr( $paypal_sandbox_client_id ); ?>"
                               class="regular-text">
                    </div>
                    <div class="swsib-field">
                        <label for="paypal-sandbox-client-secret"><?php esc_html_e( 'Sandbox Client Secret', 'swiftspeed-siberian' ); ?></label>
                        <input type="password"
                               id="paypal-sandbox-client-secret"
                               name="swsib_options[subscription][payment_gateways][paypal][sandbox_client_secret]"
                               value="<?php echo esc_attr( $paypal_sandbox_client_secret ); ?>"
                               class="regular-text">
                    </div>

                    <h4><?php esc_html_e( 'Live API Credentials', 'swiftspeed-siberian' ); ?></h4>
                    <div class="swsib-field">
                        <label for="paypal-live-client-id"><?php esc_html_e( 'Live Client ID', 'swiftspeed-siberian' ); ?></label>
                        <input type="text"
                               id="paypal-live-client-id"
                               name="swsib_options[subscription][payment_gateways][paypal][live_client_id]"
                               value="<?php echo esc_attr( $paypal_live_client_id ); ?>"
                               class="regular-text">
                    </div>
                    <div class="swsib-field">
                        <label for="paypal-live-client-secret"><?php esc_html_e( 'Live Client Secret', 'swiftspeed-siberian' ); ?></label>
                        <input type="password"
                               id="paypal-live-client-secret"
                               name="swsib_options[subscription][payment_gateways][paypal][live_client_secret]"
                               value="<?php echo esc_attr( $paypal_live_client_secret ); ?>"
                               class="regular-text">
                    </div>

                    <div class="swsib-field">
                        <label for="paypal-webhook-id"><?php esc_html_e( 'Webhook ID', 'swiftspeed-siberian' ); ?></label>
                        <input type="text"
                               id="paypal-webhook-id"
                               name="swsib_options[subscription][payment_gateways][paypal][webhook_id]"
                               value="<?php echo esc_attr( $paypal_webhook_id ); ?>"
                               class="regular-text">
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'Used to validate incoming PayPal webhook events.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <div class="swsib-field">
                        <label><?php esc_html_e( 'Webhook URL', 'swiftspeed-siberian' ); ?></label>
                        <code><?php echo esc_url( home_url( '/?swsib_paypal_webhook=1' ) ); ?></code>
                        <p class="swsib-field-note">
                            <?php esc_html_e( 'Add this URL in your PayPal Developer Dashboard under Webhooks.', 'swiftspeed-siberian' ); ?>
                        </p>
                    </div>

                    <div class="swsib-notice info">
                        <p><?php esc_html_e( 'Required webhook events:', 'swiftspeed-siberian' ); ?></p>
                        <ul>
                            <li><?php esc_html_e( 'PAYMENT.CAPTURE.COMPLETED', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'PAYMENT.CAPTURE.DENIED', 'swiftspeed-siberian' ); ?></li>
                            <li><?php esc_html_e( 'PAYMENT.CAPTURE.REFUNDED', 'swiftspeed-siberian' ); ?></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="swsib-notice info swsib-mt-20">
                <p><?php esc_html_e( 'More payment gateways will be added in future updates.', 'swiftspeed-siberian' ); ?></p>
            </div>
        </div>

        <div class="swsib-actions">
            <button type="button" id="save_payment_settings" class="button button-primary">
                <?php esc_html_e( 'Save Payment Settings', 'swiftspeed-siberian' ); ?>
            </button>
        </div>
    </form>

    <style>
    .swsib-gateway { border:1px solid #ddd; border-radius:4px; margin-bottom:20px; background:#fff; }
    .swsib-gateway-header { display:flex; height:30px; justify-content:space-between; align-items:center; padding:15px; background:#f8f8f8; border-bottom:1px solid #ddd; cursor:pointer; }
    .swsib-gateway-title { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .swsib-gateway-icon { width:40px; height:auto; }
    .swsib-gateway-status, .swsib-gateway-mode {
        display:inline-block; padding:3px 8px; border-radius:3px; font-size:12px; margin-left:6px;
    }
    .swsib-gateway-status.active { background:#d4edda; color:#155724; }
    .swsib-gateway-status.inactive { background:#f8d7da; color:#721c24; }
    .swsib-gateway-mode.testing, .swsib-gateway-mode.sandbox { background:#fff3cd; color:#856404; }
    .swsib-gateway-mode.live    { background:#cce5ff; color:#004085; }
    .swsib-gateway-content { padding:20px; }
    .swsib-field { margin-bottom:20px; }
    .swsib-field-note { font-size:12px; color:#666; margin-top:4px; }
    .swsib-checkbox-label { display:flex; align-items:center; font-weight:600; cursor:pointer; }
    .swsib-checkbox-label input { margin-right:8px; }
    @media (max-width:782px) {
        .swsib-gateway-header { flex-direction:column; align-items:flex-start; gap:10px; }
    }
    </style>

    <script type="text/javascript">
    jQuery(function($){
    // Stripe gateway
    var $stripeEnable = $('#stripe-enabled'),
        $stripeTestMode = $('#stripe-test-mode'),
        $stripeContainer = $('#stripe-settings-container'),
        $stripeStatus = $('.swsib-gateway-title:first .swsib-gateway-status'),
        $stripeModeTag = $('.swsib-gateway-title:first .swsib-gateway-mode');

    // Toggle Stripe gateway settings
    $stripeEnable.on('change', function(){
        var on = $(this).is(':checked');
        $stripeStatus.toggleClass('active inactive').text(on ? 'Active' : 'Inactive');
        if(on){
            if(!$stripeModeTag.length){
                $('.swsib-gateway-title:first').append(
                    '<span class="swsib-gateway-mode"></span>'
                );
                $stripeModeTag = $('.swsib-gateway-title:first .swsib-gateway-mode');
            }
            updateStripeMode();
            $stripeContainer.slideDown();
        } else {
            $stripeContainer.slideUp();
            $stripeModeTag.remove();
        }
    });

    // Update Stripe Live/Testing tag
    function updateStripeMode(){
        var testing = $stripeTestMode.is(':checked');
        $stripeModeTag
            .removeClass('testing live')
            .addClass(testing ? 'testing' : 'live')
            .text(testing ? 'Testing' : 'Live');
    }

    $stripeTestMode.on('change', updateStripeMode);

    // PayPal gateway
    var $paypalEnable = $('#paypal-enabled'),
        $paypalSandboxMode = $('#paypal-sandbox-mode'),
        $paypalContainer = $('#paypal-settings-container'),
        $paypalStatus = $('.swsib-gateway-title:eq(1) .swsib-gateway-status'),
        $paypalModeTag = $('.swsib-gateway-title:eq(1) .swsib-gateway-mode');

    // Toggle PayPal gateway settings
    $paypalEnable.on('change', function(){
        var on = $(this).is(':checked');
        $paypalStatus.toggleClass('active inactive').text(on ? 'Active' : 'Inactive');
        if(on){
            if(!$paypalModeTag.length){
                $('.swsib-gateway-title:eq(1)').append(
                    '<span class="swsib-gateway-mode"></span>'
                );
                $paypalModeTag = $('.swsib-gateway-title:eq(1) .swsib-gateway-mode');
            }
            updatePayPalMode();
            $paypalContainer.slideDown();
        } else {
            $paypalContainer.slideUp();
            $paypalModeTag.remove();
        }
    });

    // Update PayPal Sandbox/Live tag
    function updatePayPalMode(){
        var sandbox = $paypalSandboxMode.is(':checked');
        $paypalModeTag
            .removeClass('sandbox live testing')
            .addClass(sandbox ? 'sandbox' : 'live')
            .text(sandbox ? 'Sandbox' : 'Live');
    }

    $paypalSandboxMode.on('change', updatePayPalMode);

    // Gateway header click to toggle settings visibility - Fixed to work better with clicks only
    $('.swsib-gateway-header').on('click', function(e) {
        // Don't toggle if clicking on checkbox or label
        if ($(e.target).is('input') || $(e.target).is('label') || $(e.target).closest('label').length) {
            return;
        }
        
        var $gateway = $(this).closest('.swsib-gateway');
        var $container = $gateway.find('.swsib-gateway-content');
        
        $container.slideToggle(300);
    });

    // AJAX form submission with improved handling for all form fields
    $('#save_payment_settings').on('click', function(e){
        e.preventDefault();
        var $btn = $(this).prop('disabled', true).text('Saving...');
        
        // Get all form data
        var formData = $('#payment_settings_form').serialize();
        
        // Ensure PayPal data is included by checking if fields are not in serialized string
        var paypalEnabled = $('#paypal-enabled').is(':checked');
        if (paypalEnabled) {
            // Add hidden field to form to ensure PayPal is enabled in case checkbox isn't captured
            if (formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Benabled%5D') === -1) {
                formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Benabled%5D=1';
            }
            
            // Also ensure sandbox mode is included if checked
            var paypalSandboxMode = $('#paypal-sandbox-mode').is(':checked');
            if (paypalSandboxMode && formData.indexOf('swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Bsandbox_mode%5D') === -1) {
                formData += '&swsib_options%5Bsubscription%5D%5Bpayment_gateways%5D%5Bpaypal%5D%5Bsandbox_mode%5D=1';
            }
        }

        // Similar check for Stripe settings
        var stripeEnabled = $('#stripe-enabled').is(':checked');
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
            success: function(response){
                var msgType = response.success ? 'success' : 'error';
                var msg = response.data && response.data.message 
                    ? response.data.message 
                    : (response.success ? '<?php esc_html_e( 'Payment settings saved successfully', 'swiftspeed-siberian' ); ?>' : '<?php esc_html_e( 'Error saving payment settings', 'swiftspeed-siberian' ); ?>');
                
                var $notice = $('<div class="swsib-notice '+ msgType +'"><p>'+ msg +'</p></div>');

                $('.swsib-section').first().before($notice);
                
                // Update interface to reflect saved state
                if(response.success) {
                    // Update Stripe status
                    var stripeEnabled = $('#stripe-enabled').is(':checked');
                    $stripeStatus.removeClass('active inactive').addClass(stripeEnabled ? 'active' : 'inactive');
                    
                    // Update Stripe mode
                    if(stripeEnabled) {
                        var testMode = $('#stripe-test-mode').is(':checked');
                        $stripeModeTag.removeClass('testing live').addClass(testMode ? 'testing' : 'live');
                    }
                    
                    // Update PayPal status
                    $paypalStatus.removeClass('active inactive').addClass(paypalEnabled ? 'active' : 'inactive');
                    
                    // Update PayPal mode
                    if(paypalEnabled) {
                        var sandboxMode = $('#paypal-sandbox-mode').is(':checked');
                        $paypalModeTag.removeClass('sandbox live').addClass(sandboxMode ? 'sandbox' : 'live');
                    }
                }
                
                setTimeout(function(){ 
                    $notice.fadeOut(500, function(){ $(this).remove(); }); 
                }, 15000);

                $btn.prop('disabled', false).text('<?php esc_html_e( 'Save Payment Settings', 'swiftspeed-siberian' ); ?>');
            },
            error: function(xhr, status, error){
                console.error("AJAX Error:", status, error);
                var $err = $('<div class="swsib-notice error"><p><?php esc_html_e( 'An error occurred. Please try again.', 'swiftspeed-siberian' ); ?></p></div>');
                $('.swsib-section').first().before($err);
                setTimeout(function(){ $err.fadeOut(500, function(){ $(this).remove(); }); }, 15000);
                $btn.prop('disabled', false).text('<?php esc_html_e( 'Save Payment Settings', 'swiftspeed-siberian' ); ?>');
            }
        });
    });
});
    </script>
<?php endif; ?>