<?php
/**
 * Plugin Name: BarterPay WooCommerce Gateway
 * Description: Start accepting BarterPay payments easy way.
 * Version: 1.3.5
 * Author: BarterPay <info@getbarterpay.com>
 * Requires Plugins: woocommerce
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize the gateway after all plugins are loaded.
 */
add_action( 'plugins_loaded', 'init_barterpay_gateway' );
function init_barterpay_gateway() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return; // WooCommerce is not active
    }

    class WC_Gateway_BarterPay extends WC_Payment_Gateway {

        /**
         * Gateway instructions that will be added to the thank you page and emails.
         * @var string
         */
        public $instructions;

        /**
         * Enable for shipping methods.
         * @var array
         */
        public $enable_for_methods;

        /**
         * Enable for virtual products.
         * @var bool
         */
        public $enable_for_virtual;

        /**
         * API Key to comunicate with endpoint
         * @var string
         */
        public $api_key;
        /**
         * Currency list for gateway
         * @var string
         */
        public $currency;

        /**
         * This setting will define API url for sandbox and production
         * @var string
         */
        public $sandbox;

        public function __construct() {

            // Setup general properties.
            $this->setup_properties();
            // Load settings.
            $this->init_form_fields();
            $this->init_settings();

            // Retrieve settings values.
            $this->title              = $this->get_option( 'title' );
            $this->description        = $this->get_option( 'description' );
            $this->instructions       = $this->get_option( 'instructions' );
            $this->api_key            = $this->get_option( 'api_key' );
            $this->currency           = $this->get_option( 'currency' );
            $this->sandbox            = $this->get_option( 'sandbox' );
            $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
            $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';

            // Add block support declaration
            $this->supports = array(
                'products',
                'block_checkout' // Explicit block checkout support
            );

            // Actions.
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            // Hook for displaying transaction index in admin
            add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_transaction_index_in_admin' ), 10, 1 );

            // Register AJAX actions (can be used by classic checkout or custom block implementations if needed)
            add_action( 'wp_ajax_barterpay_process_payment_ajax', array( $this, 'ajax_process_payment' ) );
            add_action( 'wp_ajax_nopriv_barterpay_process_payment_ajax', array( $this, 'ajax_process_payment' ) );
        }

        /**
         * Setup general properties for the gateway.
         */
        protected function setup_properties() {
            $this->id                 = 'barterpay';
            // The icon can be set by the user in WooCommerce settings.
            // For a static plugin-provided icon in blocks, that's handled in BarterPay_Blocks_Support.
            $this->icon               = apply_filters( 'woocommerce_barterpay_icon', '' );
            $this->method_title       = __( 'BarterPay', 'barterpay' );
            $this->method_description = __( 'You will be redirected to complete your payment.', 'barterpay' );
            $this->has_fields         = false; // No fields on checkout page itself before redirect
        }

        /**
         * Plugin admin form fields
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'barterpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable BarterPay Gateway', 'barterpay' ),
                    'default' => 'no'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'barterpay' ),
                    'type'        => 'text',
                    'description' => __( 'Title shown to the user during checkout.', 'barterpay' ),
                    'default'     => __( 'BarterPay Gateway', 'barterpay' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'barterpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Description shown to the user during checkout.', 'barterpay' ),
                    'default'     => __( 'Pay via BarterPay. You will be redirected to complete your payment.', 'barterpay' ),
                ),
                'api_key' => array(
                    'title'       => __( 'API Key', 'barterpay' ),
                    'type'        => 'text',
                    'description' => __( 'Enter your BarterPay API key.', 'barterpay' ),
                    'default'     => '',
                ),
                'instructions' => array(
                    'title'       => __( 'Instructions', 'barterpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', 'barterpay' ),
                    'default'     => __( 'Your payment is being processed via BarterPay. You will be redirected shortly.', 'barterpay' ),
                    'desc_tip'    => true,
                ),
                'enable_for_methods' => array(
                    'title'       => __( 'Enable for shipping methods', 'barterpay' ),
                    'type'        => 'multiselect',
                    'class'       => 'wc-enhanced-select',
                    'css'         => 'width: 400px;',
                    'default'     => '',
                    'description' => __( 'If BarterPay is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'barterpay' ),
                    'options'     => $this->load_shipping_method_options(),
                    'desc_tip'    => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Select shipping methods', 'barterpay' ),
                    ),
                ),
                'currency' => array(
                    'title'       => __( 'Currency', 'barterpay' ),
                    'type'        => 'select',
                    'description' => __( 'Select the currency for payments.', 'barterpay' ),
                    'default'     => 'USD',
                    'options'     => array(
                        'USD' => __( 'USD', 'barterpay' ),
                        'EUR' => __( 'EUR', 'barterpay' ),
                        // Add other currencies as supported by BarterPay
                    )
                ),
                'sandbox' => array(
                    'title'   => __( 'Sandbox Mode', 'barterpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Sandbox Mode (for testing)', 'barterpay' ),
                    'default' => 'yes',
                    'description' => __( 'When enabled, payments will be processed via the BarterPay test environment.', 'barterpay' ),
                    'desc_tip'    => true,
                ),
                'enable_logs' => array(
                    'title'       => __( 'Enable Logging', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable gateway logging', 'barterpay' ),
                    'default'     => 'no',
                    'description' => sprintf(
                        __( 'Logs events, such as callback processing. Log file: %s', 'barterpay' ),
                        '`' . plugin_dir_path( __FILE__ ) . 'barterpay-callback.log`' // Log file in plugin's root directory
                    ),
                    'desc_tip'    => true,
                ),
                'enable_for_virtual' => array(
                    'title' => __( 'Accept for virtual orders', 'barterpay' ),
                    'label' => __( 'Accept BarterPay, if the order is virtual', 'barterpay' ),
                    'type'  => 'checkbox',
                    'default' => 'yes',
                ),
            );
        }

        /**
         * Checks whether the admin settings are being accessed.
         */
        private function is_accessing_settings() {
            if ( is_admin() ) {
                // phpcs:disable WordPress.Security.NonceVerification.Recommended
                if ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== $_REQUEST['page'] ) { return false; }
                if ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) { return false; }
                if ( ! isset( $_REQUEST['section'] ) || $this->id !== $_REQUEST['section'] ) { return false; }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
                return true;
            }
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                global $wp;
                if ( isset( $wp->query_vars['rest_route'] ) && strpos( $wp->query_vars['rest_route'], '/payment_gateways/' . $this->id ) !== false ) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Loads all of the shipping method options for the enable_for_methods field.
         */
        private function load_shipping_method_options() {
            if ( ! $this->is_accessing_settings() ) { return array(); }
            $data_store = WC_Data_Store::load( 'shipping-zone' );
            $raw_zones = $data_store->get_zones();
            $zones = array();
            foreach ( $raw_zones as $raw_zone ) { $zones[] = new WC_Shipping_Zone( $raw_zone ); }
            $zones[] = new WC_Shipping_Zone( 0 ); // For "Rest of the World"
            $options = array();
            foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
                $options[ $method->get_method_title() ] = array();
                $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%s&quot; method', 'barterpay' ), $method->get_method_title() );
                foreach ( $zones as $zone ) {
                    $shipping_method_instances = $zone->get_shipping_methods();
                    foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {
                        if ( $shipping_method_instance->id !== $method->id ) { continue; }
                        $option_id = $shipping_method_instance->get_rate_id();
                        $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'barterpay' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );
                        $option_title = sprintf( __( '%1$s &ndash; %2$s', 'barterpay' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Locations not covered by your other zones', 'barterpay' ), $option_instance_title );
                        $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                    }
                }
            }
            return $options;
        }

        /**
         * Process the payment and return the result.
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                barterpay_log( sprintf('Error: Could not retrieve order object for order ID %s in process_payment.', $order_id) );
                wc_add_notice( __( 'Error: Could not retrieve order details. Please try again.', 'barterpay' ), 'error' );
                return array( 'result' => 'failure', 'redirect' => wc_get_checkout_url() );
            }

            $endpoint = 'yes' === $this->sandbox ?
                        'https://test-api.getbarterpay.com/api/pay/m-api/add-in-deposit-queue' :
                        'https://api.getbarterpay.com/api/pay/m-api/add-in-deposit-queue';

            // Generate a unique TransactionId, prefixed with order_id for guaranteed uniqueness per order.
            $external_transaction_id = $order_id . '_txn_' . uniqid( '', true );
            $order->update_meta_data( '_barterpay_external_txn_id', $external_transaction_id );

            $payload = array(
                'TransactionId' => $external_transaction_id,
                'Currency'      => $this->currency,
                'Amount'        => floatval( $order->get_total() )
            );
            $args = array(
                'body'    => json_encode( $payload ),
                'timeout' => 45,
                'headers' => array( 'Content-Type' => 'application/json', 'X-SAO-Token'  => $this->api_key ),
            );

            barterpay_log( sprintf('Processing payment for order %d. Payload: %s', $order_id, json_encode($payload)) );
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                barterpay_log( sprintf('Payment error for order %d: %s', $order_id, $response->get_error_message()) );
                wc_add_notice( __( 'Payment error: Unable to connect to BarterPay.', 'barterpay' ), 'error' );
                return array( 'result' => 'failure', 'redirect' => wc_get_checkout_url() );
            }

            $body   = wp_remote_retrieve_body( $response );
            $result = json_decode( $body, true );
            barterpay_log( sprintf('BarterPay API Response for order %d: %s', $order_id, $body) );

            if ( isset( $result['redirectUrl'] ) && ! empty( $result['redirectUrl'] ) ) {
                if ( isset( $result['transactionIndex'] ) ) {
                    // Store the API transaction index from BarterPay
                    $order->update_meta_data( '_barterpay_api_txn_index', sanitize_text_field( $result['transactionIndex'] ) );
                }
                $order->save(); // Save meta data
                $order->update_status( 'on-hold', __( 'Awaiting BarterPay payment confirmation.', 'barterpay' ) );
                return array( 'result' => 'success', 'redirect' => $result['redirectUrl'] );
            } else {
                $error_message = isset($result['message']) ? $result['message'] : __( 'Invalid response from BarterPay.', 'barterpay' );
                barterpay_log( sprintf('Payment error for order %d: Invalid response from BarterPay. Response: %s', $order_id, $body) );
                wc_add_notice( sprintf(__( 'Payment error: %s', 'barterpay' ), esc_html($error_message) ), 'error' );
                return array( 'result' => 'failure', 'redirect' => wc_get_checkout_url() );
            }
        }

        /**
         * Output for the order received page (thank you page).
         */
        public function thankyou_page( $order_id ) {
            if ( $this->instructions ) { echo wpautop( wptexturize( $this->instructions ) ); }
            
            $order = wc_get_order($order_id);
            $should_poll = false;
            if ($order) {
                $current_status = $order->get_status();
                // Use 'wc-' prefix for has_status if that's how they are stored, or without if not.
                // WC_Order::has_status() typically expects status without 'wc-' prefix.
                if ($order->has_status('on-hold') || $order->has_status('pending')) {
                    $should_poll = true;
                }
                // For debugging, output current status to console via PHP if possible (only works if output hasn't started)
                // Or better, log it.
                barterpay_log("Thank you page: Order $order_id status is $current_status. Polling: " . ($should_poll ? 'Yes' : 'No'));
                echo "<script>console.log('BarterPay Thank You Page Debug: Order ID $order_id, Initial Status: $current_status, Should Poll: " . ($should_poll ? 'true' : 'false') . "');</script>";
            } else {
                barterpay_log("Thank you page: Order $order_id not found.");
                 echo "<script>console.log('BarterPay Thank You Page Debug: Order ID $order_id not found.');</script>";
            }

            // Only show polling if order is still on-hold or pending
            if ( $should_poll ) {
            ?>
            <div id="barterpay-payment-processing-notice">
                <p><?php esc_html_e( 'Your payment is being processed by BarterPay. This page will automatically update once payment is confirmed. Please wait...', 'barterpay' ); ?></p>
            </div>
            <script type="text/javascript">
                jQuery(document).ready(function ($) {
                    console.log('BarterPay AJAX Polling: Script started for order <?php echo esc_js( $order_id ); ?>.');
                    var barterpayCheckCount = 0, barterpayMaxChecks = 60, barterpayIntervalTime = 5000; // Poll every 5s for 5 mins
                    var barterpayNonce = '<?php echo esc_js( wp_create_nonce( 'barterpay_check_status_nonce' ) ); ?>';
                    console.log('BarterPay AJAX Polling: Nonce created: ' + barterpayNonce);

                    var checkStatusInterval = setInterval(function () {
                        barterpayCheckCount++;
                        console.log('BarterPay AJAX Polling: Check #' + barterpayCheckCount + ' for order <?php echo esc_js( $order_id ); ?>');

                        if (barterpayCheckCount > barterpayMaxChecks) {
                            clearInterval(checkStatusInterval);
                            $('#barterpay-payment-processing-notice p').text('<?php esc_html_e( "Payment status check timed out. Please check your account or contact us if payment isn't confirmed shortly.", "barterpay" ); ?>');
                            console.log('BarterPay AJAX Polling: Max checks reached for order <?php echo esc_js( $order_id ); ?>.');
                            return;
                        }
                        $.ajax({
                            url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                            method: 'POST',
                            data: { 
                                action: 'barterpay_check_status', 
                                order_id: '<?php echo esc_js( $order_id ); ?>', 
                                nonce: barterpayNonce
                            },
                            success: function (response) {
                                console.log('BarterPay AJAX Polling: Success response for order <?php echo esc_js( $order_id ); ?>:', response);
                                if (response.success && response.data && response.data.status === 'paid') {
                                    clearInterval(checkStatusInterval);
                                    $('#barterpay-payment-processing-notice p').text('<?php esc_html_e( "Payment confirmed! Reloading page...", "barterpay" ); ?>');
                                    console.log('BarterPay AJAX Polling: Payment confirmed for order <?php echo esc_js( $order_id ); ?>. Reloading.');
                                    location.reload();
                                } else if (response.success && response.data && response.data.status === 'failed') {
                                    clearInterval(checkStatusInterval);
                                    $('#barterpay-payment-processing-notice p').text('<?php esc_html_e( "Payment failed. Please try again or contact support.", "barterpay" ); ?>');
                                    console.log('BarterPay AJAX Polling: Payment failed for order <?php echo esc_js( $order_id ); ?>.');
                                } else {
                                    console.log('BarterPay AJAX Polling: Status still pending or unknown for order <?php echo esc_js( $order_id ); ?>. Status: ' + (response.data ? response.data.status : 'N/A'));
                                }
                            },
                            error: function (jqXHR, textStatus, errorThrown) { 
                                console.error('BarterPay AJAX Polling: Error checking payment status for order <?php echo esc_js( $order_id ); ?>:', textStatus, errorThrown, jqXHR.responseText);
                            }
                        });
                    }, barterpayIntervalTime);
                });
            </script>
            <?php
            } // end if ($should_poll)
        }

        /**
         * Display BarterPay Transaction IDs in the admin order details.
         */
        public function display_transaction_index_in_admin( $order ) {
            if ( $this->id === $order->get_payment_method() ) {
                // Get BarterPay's API Transaction Index (new key, with fallback to old if needed)
                $api_transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );
                if ( empty($api_transaction_index) ) {
                    $api_transaction_index = $order->get_meta( '_barterpay_txn_index', true ); // Fallback to old key
                }

                if ( ! empty( $api_transaction_index ) ) {
                    echo '<p><strong>' . esc_html__( 'BarterPay API Transaction Index:', 'barterpay' ) . '</strong> ' . esc_html( $api_transaction_index ) . '</p>';
                }

                // Get Merchant's External Transaction ID (new key, with fallback to old if needed)
                $external_transaction_id = $order->get_meta( '_barterpay_external_txn_id', true );
                if ( empty($external_transaction_id) ) {
                     $external_transaction_id = $order->get_meta( '_barterpay_txn_id', true ); // Fallback to old key
                }

                 if ( ! empty( $external_transaction_id ) ) {
                    echo '<p><strong>' . esc_html__( 'BarterPay Merchant Transaction ID:', 'barterpay' ) . '</strong> ' . esc_html( $external_transaction_id ) . '</p>';
                }
            }
        }

        /**
         * AJAX handler for processing payment.
         */
        public function ajax_process_payment() {
            check_ajax_referer( 'barterpay_process_payment_nonce', 'nonce' );
            if ( ! isset( $_POST['order_id'] ) ) { wp_send_json_error( array( 'message' => __( 'No order ID provided.', 'barterpay' ) ) ); return; }
            $order_id = absint( $_POST['order_id'] );
            $order = wc_get_order( $order_id );
            if ( ! $order ) { wp_send_json_error( array( 'message' => __( 'Invalid order.', 'barterpay' ) ) ); return; }
            if ($order->get_payment_method() !== $this->id) { wp_send_json_error( array( 'message' => __( 'Payment method mismatch.', 'barterpay' ) ) ); return; }
            
            $result = $this->process_payment( $order_id ); // Call the main payment processing method
            
            if ( isset($result['result']) && $result['result'] === 'success' && isset($result['redirect']) ) {
                wp_send_json_success( array( 'redirect' => $result['redirect'] ) );
            } else {
                wp_send_json_error( array( 'message' => __( 'Payment processing failed. Please check notices or try again.', 'barterpay' ) ) );
            }
        }
    } // End WC_Gateway_BarterPay class

    // Add the gateway to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'add_barterpay_gateway_class' );
    function add_barterpay_gateway_class( $gateways ) {
        $gateways[] = 'WC_Gateway_BarterPay';
        return $gateways;
    }

} // End init_barterpay_gateway

/**
 * Logging helper function for BarterPay.
 */
function barterpay_log( $message ) {
    $gateway_settings = get_option( 'woocommerce_barterpay_settings', array() );
    if ( ! empty( $gateway_settings['enable_logs'] ) && 'yes' === $gateway_settings['enable_logs'] ) {
        $log_file = plugin_dir_path( __FILE__ ) . 'barterpay-callback.log'; // Log file in plugin's root directory
        $timestamp = current_time( 'mysql' );
        $message_to_log = ( is_array( $message ) || is_object( $message ) ) ? print_r( $message, true ) : $message;
        file_put_contents( $log_file, "[$timestamp] " . $message_to_log . PHP_EOL, FILE_APPEND );
    }
}

/**
 * Check for the legacy callback parameter (`?barterpay_callback=1`) and handle it.
 * This function is hooked to 'wp_loaded' to ensure WordPress is fully loaded.
 */
add_action( 'wp_loaded', 'barterpay_legacy_callback_check' );
function barterpay_legacy_callback_check(){
   // Check if our specific query parameter is set.
   // Nonce verification is not typically used for webhook endpoints like this,
   // as the request originates from an external service. Security is handled by other means (e.g. API keys, IP whitelisting if applicable).
   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
   if ( isset( $_GET['barterpay_callback'] ) && '1' === $_GET['barterpay_callback'] ) {
       barterpay_log( 'Legacy callback URL detected: ?barterpay_callback=1. Processing...' );
       barterpay_handle_callback(); // Call the main handler function
   }
}

/**
 * Callback handler for BarterPay asynchronous payment notifications.
 * This function is now primarily called by `barterpay_legacy_callback_check`.
 */
function barterpay_handle_callback() {
    barterpay_log( 'Callback handler initiated. Method: ' . sanitize_text_field($_SERVER['REQUEST_METHOD']) . 
                   '. GET: ' . print_r($_GET, true) . 
                   '. POST: ' . print_r($_POST, true) ); // Log both GET and POST for debugging

    $raw_input = file_get_contents( 'php://input' );
    barterpay_log( 'Raw callback payload (php://input): ' . $raw_input );
    $data_from_json_payload  = json_decode( $raw_input, true );

    $external_transaction_id = '';
    $api_transaction_index   = '';
    $transaction_status      = '';
    $transaction_amount      = 0;

    // Prioritize JSON payload from POST body (standard for webhooks)
    if ( $data_from_json_payload && isset( $data_from_json_payload['data'] ) && is_array($data_from_json_payload['data']) ) {
        $callback_data           = $data_from_json_payload['data'];
        $external_transaction_id = isset( $callback_data['ExternalTransactionId'] ) ? sanitize_text_field( $callback_data['ExternalTransactionId'] ) : '';
        $api_transaction_index   = isset( $callback_data['TransactionIndex'] ) ? sanitize_text_field( $callback_data['TransactionIndex'] ) : '';
        $transaction_status      = isset( $callback_data['TransactionStatus'] ) ? strtolower( sanitize_text_field( $callback_data['TransactionStatus'] ) ) : '';
        $transaction_amount      = isset( $callback_data['TransactionAmount'] ) ? floatval( $callback_data['TransactionAmount'] ) : 0;
        barterpay_log( 'Callback data parsed from JSON payload.' );
    } 
    // Fallback to GET parameters if JSON is not present or invalid (for legacy compatibility)
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Webhook endpoint.
    elseif ( isset( $_GET['externalTransactionId'] ) ) { 
        barterpay_log( 'Callback: No valid JSON payload found or "data" key missing. Attempting to use GET parameters.' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $external_transaction_id = sanitize_text_field( $_GET['externalTransactionId'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $api_transaction_index   = isset( $_GET['transactionId'] ) ? sanitize_text_field( $_GET['transactionId'] ) : ''; // Assuming 'transactionId' in GET is the API index
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $transaction_status      = isset( $_GET['transactionStatus'] ) ? strtolower( sanitize_text_field( $_GET['transactionStatus'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $transaction_amount      = isset( $_GET['transactionAmount'] ) ? floatval( $_GET['transactionAmount'] ) : 0;
    } else {
        barterpay_log( 'Callback Error: Invalid or empty data. Neither valid JSON payload with "data" key nor "externalTransactionId" in GET parameters found.' );
        echo 'ERROR: Invalid callback data'; // Respond with an error message
        exit; // Stop further processing
    }

    barterpay_log( sprintf(
        'Callback Data Parsed: ExternalTransactionId: %s, ApiTransactionIndex: %s, Status: %s, Amount: %s',
        esc_html($external_transaction_id), esc_html($api_transaction_index), esc_html($transaction_status), esc_html($transaction_amount)
    ) );

    if ( empty( $external_transaction_id ) ) {
        barterpay_log( 'Callback Error: ExternalTransactionId is missing after parsing attempts.' );
        echo 'ERROR: ExternalTransactionId missing';
        exit;
    }

    // Retrieve the order using our stored external transaction ID.
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'meta_key'   => '_barterpay_external_txn_id', // New primary key
        'meta_value' => $external_transaction_id,
        'status'     => array( 'wc-on-hold', 'wc-pending' ) // Only process orders awaiting payment
    ) );

    if ( empty( $orders ) ) {
        // Try with old key if migrating and new key not found
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_barterpay_txn_id', // Old key
            'meta_value' => $external_transaction_id,
            'status'     => array( 'wc-on-hold', 'wc-pending' )
        ));
        if ( !empty($orders) ) { 
            barterpay_log( sprintf('Order found using legacy _barterpay_txn_id for ExternalTransactionId: %s', esc_html($external_transaction_id)) );
        }
    }

    if ( empty( $orders ) ) {
        barterpay_log( sprintf( 'Callback Error: Order not found for ExternalTransactionId: %s. Searched states: on-hold, pending.', esc_html($external_transaction_id) ) );
        // Respond OK to prevent BarterPay from retrying if the transaction is genuinely not found or already processed.
        status_header(200); 
        echo 'OK: Order not found or already processed'; 
        exit;
    }

    $order = $orders[0];
    $order_id = $order->get_id();
    barterpay_log( sprintf( 'Order %d found for ExternalTransactionId: %s.', $order_id, esc_html($external_transaction_id) ) );

    // Verification Step: Compare API Transaction Index if available from callback and stored in order
    $stored_api_txn_index = $order->get_meta( '_barterpay_api_txn_index', true );
    if (empty($stored_api_txn_index)) { // Fallback for old key
        $stored_api_txn_index = $order->get_meta( '_barterpay_txn_index', true );
    }

    if ( !empty( $api_transaction_index ) && !empty( $stored_api_txn_index ) && $stored_api_txn_index !== $api_transaction_index ) {
        barterpay_log( sprintf(
            "Callback Warning for Order %d: API TransactionIndex mismatch. Received: '%s', Stored: '%s'. ExternalTransactionId: %s. Processing cautiously.",
            $order_id, esc_html($api_transaction_index), esc_html($stored_api_txn_index), esc_html($external_transaction_id)
        ));
        // Policy: Log and proceed. Could be made stricter if required.
    }

    // Check if order is already processed to prevent duplicate updates
    if ( ($order->is_paid() || $order->has_status( 'completed' ) || $order->has_status( 'processing' )) && 'success' === $transaction_status ) {
        barterpay_log( sprintf( 'Order %d already processed as paid. Current status: %s. Callback status: %s. Ignoring duplicate success callback.', $order_id, $order->get_status(), esc_html($transaction_status) ) );
        echo 'OK: Already processed as paid'; 
        exit;
    }
     if ( $order->has_status( 'failed' ) && in_array($transaction_status, array('failed', 'cancelled', 'expired'), true) ) {
        barterpay_log( sprintf( 'Order %d already marked as failed. Current status: %s. Callback status: %s. Ignoring duplicate failure/cancelled/expired callback.', $order_id, $order->get_status(), esc_html($transaction_status) ) );
        echo 'OK: Already processed as failed';
        exit;
    }

    // Process payment status
    if ( 'success' === $transaction_status ) {
        // Use BarterPay's API transaction index if available, otherwise use our external ID
        $payment_transaction_id_for_wc = !empty($api_transaction_index) ? $api_transaction_index : $external_transaction_id;
        $order->payment_complete( $payment_transaction_id_for_wc );
        $order->add_order_note(
            sprintf(
                __( 'Payment of %s confirmed via BarterPay callback. BarterPay Transaction Index: %s. Merchant Transaction ID: %s.', 'barterpay' ),
                wc_price( $transaction_amount, array('currency' => $order->get_currency()) ),
                esc_html( $api_transaction_index ), // Log what was received
                esc_html( $external_transaction_id )  // Log our ID
            )
        );
        barterpay_log( sprintf( 'Order %d payment completed. Amount: %s. API Index: %s.', $order_id, esc_html($transaction_amount), esc_html($api_transaction_index) ) );
    } elseif ( in_array($transaction_status, array('failed', 'cancelled', 'expired'), true) ) {
        $order->update_status( 'failed', sprintf( __( 'Payment %s via BarterPay callback. Status: %s. API Index: %s.', 'barterpay' ), $transaction_status, $transaction_status, esc_html($api_transaction_index) ) );
        barterpay_log( sprintf( 'Order %d payment %s. Status: %s. API Index: %s.', $order_id, $transaction_status, esc_html($transaction_status), esc_html($api_transaction_index) ) );
    } else {
        // Unknown status, log it and don't change order status
        barterpay_log( sprintf( 'Order %d received unknown payment status via callback: %s. API Index: %s. No action taken.', $order_id, esc_html($transaction_status), esc_html($api_transaction_index) ) );
    }
    echo 'OK'; // Acknowledge receipt to BarterPay
    exit;
}


/**
 * Add the BarterPay Logs page under the main "Settings" menu in WordPress admin.
 */
add_action( 'admin_menu', 'barterpay_add_logs_menu_page_under_settings' );
function barterpay_add_logs_menu_page_under_settings() {
    add_submenu_page(
        'options-general.php',             // Parent slug: Use 'options-general.php' for the main Settings menu.
        __( 'BarterPay Logs', 'barterpay' ), // Page title that appears in <title> tag
        __( 'BarterPay Logs', 'barterpay' ), // Menu title that appears in the admin menu
        'manage_options',                  // Capability required to access this menu item (admin level)
        'barterpay_logs',                  // Menu slug (unique identifier for this menu page)
        'barterpay_render_logs_page'       // Callback function that renders the page content
    );
}

/**
 * Render the callback logs page.
 */
function barterpay_render_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) { // Capability check to match menu registration
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
    }

    $log_file = plugin_dir_path( __FILE__ ) . 'barterpay-callback.log'; 
    
    echo '<div class="wrap">'; // Standard WordPress admin page wrapper
    echo '<h1>' . esc_html__( 'BarterPay Gateway Logs', 'barterpay' ) . '</h1>';

    // Clear logs action
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['action'] ) && 'clear_barterpay_logs' === $_GET['action'] && isset($_GET['_wpnonce']) && wp_verify_nonce( sanitize_key($_GET['_wpnonce']), 'barterpay_clear_logs_nonce' ) ) {
    // phpcs:enable
        if ( file_put_contents( $log_file, '' ) !== false ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'BarterPay logs cleared.', 'barterpay' ) . '</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not clear BarterPay logs. Check file permissions.', 'barterpay' ) . '</p></div>';
        }
    }

    if ( file_exists( $log_file ) && filesize( $log_file ) > 0 ) {
        // Link to clear logs
        $clear_logs_url = wp_nonce_url( add_query_arg( 'action', 'clear_barterpay_logs', admin_url( 'options-general.php?page=barterpay_logs' ) ), 'barterpay_clear_logs_nonce' );
        echo '<p><a href="' . esc_url( $clear_logs_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to clear all BarterPay logs?', 'barterpay' ) ) . '\');">' . esc_html__( 'Clear Logs', 'barterpay' ) . '</a></p>';
        
        // Display log content
        echo '<h2>' . esc_html__( 'Log Content:', 'barterpay' ) . '</h2>';
        echo '<div style="overflow-x:auto; overflow-y: scroll; max-height:500px; background:#fff; padding:10px; border:1px solid #ddd; white-space: pre-wrap; word-wrap: break-word;">';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading own log file
        echo nl2br( esc_html( file_get_contents( $log_file ) ) );
        echo '</div>';
    } else {
        echo '<p>' . esc_html__( 'No logs found or log file is empty.', 'barterpay' ) . '</p>';
    }
    echo '</div>'; 
}


/**
 * Check if WooCommerce Blocks is loaded and add support for BarterPay.
 */
add_action( 'woocommerce_blocks_loaded', 'barterpay_load_blocks_integration' );
function barterpay_load_blocks_integration() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        $blocks_support_file = plugin_dir_path( __FILE__ ) . 'includes/class-barterpay-blocks-support.php';
        if ( file_exists( $blocks_support_file ) ) {
            require_once $blocks_support_file;
            if ( class_exists( 'BarterPay_Blocks_Support' ) ) { 
                \Automattic\WooCommerce\Blocks\Package::container()->get(
                    \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class
                )->register( new BarterPay_Blocks_Support() ); 
                 barterpay_log('BarterPay_Blocks_Support class registered with PaymentMethodRegistry.');
            } else {
                barterpay_log('Error: BarterPay_Blocks_Support class not found after requiring file.');
            }
        } else {
            barterpay_log('Error: class-barterpay-blocks-support.php not found in includes directory.');
        }
    } else {
         barterpay_log('Error: AbstractPaymentMethodType class not found. WooCommerce Blocks may not be fully active or installed.');
    }
}

/**
 * AJAX Endpoint for Polling Payment Status on Thank You Page.
 */
add_action( 'wp_ajax_barterpay_check_status', 'barterpay_ajax_check_status_callback' );
add_action( 'wp_ajax_nopriv_barterpay_check_status', 'barterpay_ajax_check_status_callback' );
function barterpay_ajax_check_status_callback() {
    check_ajax_referer( 'barterpay_check_status_nonce', 'nonce' ); // Verify nonce

    if ( ! isset( $_POST['order_id'] ) ) { 
        wp_send_json_error( array( 'message' => __( 'No order ID provided.', 'barterpay' ) ) ); 
        return; 
    }
    $order_id = absint( $_POST['order_id'] );
    $order = wc_get_order( $order_id );
    if ( ! $order ) { 
        wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'barterpay' ) ) ); 
        return; 
    }

    // Check payment status
    if ( $order->is_paid() || $order->has_status( array( 'completed', 'processing' ) ) ) {
        wp_send_json_success( array( 'status' => 'paid' ) );
    } elseif ( $order->has_status( 'failed' ) ) {
        wp_send_json_success( array( 'status' => 'failed' ) );
    } else {
        wp_send_json_success( array( 'status' => 'pending' ) ); // Still pending or on-hold
    }
}

/**
 * Add settings link on plugin page.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'barterpay_add_plugin_settings_link' );
function barterpay_add_plugin_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay' ) . '">' . __( 'Settings', 'barterpay' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

?>
