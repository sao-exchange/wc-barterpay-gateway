<?php
/**
 * Plugin Name: BarterPay WooCommerce Gateway
 * Description: Start accepting BarterPay payments easy way.
 * Version: 1.6.5
 * Author: BarterPay <info@getbarterpay.com>
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize the gateway after all plugins are loaded.
 */
add_action( 'plugins_loaded', 'init_barterpay_gateway' );
function init_barterpay_gateway() {
    // Check if WooCommerce is active
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return;
    }

    class WC_Gateway_BarterPay extends WC_Payment_Gateway {

        /**
         * Gateway instructions that will be added to the thank you page and emails.
         *
         * @var string
         */
        public $instructions;

        /**
         * Enable for shipping methods.
         *
         * @var array
         */
        public $enable_for_methods;

        /**
         * Enable for virtual products.
         *
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

        /**
         * Whether to enable a custom redirect URL after payment.
         * @var string
         */
        public $enable_custom_redirect;

        /**
         * Custom redirect URL after successful payment.
         * @var string
         */
        public $custom_redirect_url;

        /**
         * Payment flow: 'hosted' (redirect) or 'widget' (embedded iframe).
         * Defaults to 'hosted' so existing sites are unaffected.
         * @var string
         */
        public $payment_flow;

        /**
         * Whether the server-to-server callback verification is enabled.
         * @var string 'yes' or 'no'
         */
        public $enable_callback;

        /**
         * Whether the front-end polling verification is enabled.
         * @var string 'yes' or 'no'
         */
        public $enable_polling;

        /**
         * Whether the server-side fallback poller is enabled.
         * @var string 'yes' or 'no'
         */
        public $enable_server_fallback;

        /**
         * Minutes to delay the server-side fallback check after order creation.
         * @var int
         */
        public $server_fallback_delay_minutes;

        /**
         * Seconds to wait on the iframe success screen for the customer to sign.
         * @var int
         */
        public $signature_countdown_seconds;

        public function __construct() {

            // Setup general properties.
            $this->setup_properties();
            // Load settings.
            $this->init_form_fields();
            $this->init_settings();

            // Retrieve settings values.
            $this->title              = $this->get_option( 'title' );
            $this->description        = $this->get_option( 'description' );
            $this->api_key            = $this->get_option( 'api_key' );
            $this->currency           = $this->get_option( 'currency' );
            $this->sandbox            = $this->get_option( 'sandbox' );
            $this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
            $this->enable_for_virtual = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';
            $this->enable_custom_redirect = $this->get_option( 'enable_custom_redirect', 'no' );
            $this->custom_redirect_url = $this->get_option( 'custom_redirect_url', '' );
            $this->payment_flow = $this->get_option( 'payment_flow', 'hosted' );
            $this->enable_callback              = $this->get_option( 'enable_callback', 'yes' );
            $this->enable_polling               = $this->get_option( 'enable_polling', 'yes' );
            $this->enable_server_fallback       = $this->get_option( 'enable_server_fallback', 'yes' );
            $this->server_fallback_delay_minutes = absint( $this->get_option( 'server_fallback_delay_minutes', '10' ) );
            $this->signature_countdown_seconds  = absint( $this->get_option( 'signature_countdown_seconds', '30' ) );
            // Add block support declaration
            $this->supports = array(
                'products',
                'block_checkout' // Explicit block checkout support
            );
            // Actions.
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        }

        /**
         * Setup general properties for the gateway.
         */
        protected function setup_properties() {

            $this->id                 = 'barterpay';
            $this->icon               = apply_filters( 'woocommerce_barterpay_icon', plugins_url( 'assets/barterpay-logo.svg', __FILE__ ) );
            $this->method_title       = __( 'BarterPay', 'barterpay' );
            $this->method_description = __( 'Accept payments via BarterPay.', 'barterpay' );
            $this->has_fields         = false;
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
                    'default'     => __( 'Credit/Debit Via BarterPay', 'barterpay' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'barterpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Description shown to the user during checkout.', 'barterpay' ),
                    'default'     => __( 'Security verification required: Phone, & Email. Your transaction today will be with BarterPay and your statement will say the same. We accept your BPSK Keys as barter for your order.', 'barterpay' ),
                ),

                // ── API & Environment ───────────────────────────────
                'api_section' => array(
                    'title' => __( 'API & Environment', 'barterpay' ),
                    'type'  => 'title',
                    'description' => __( 'Configure your BarterPay API credentials and environment.', 'barterpay' ),
                ),
                'api_key' => array(
                    'title'       => __( 'API Key', 'barterpay' ),
                    'type'        => 'text',
                    'description' => __( 'Enter your BarterPay API key.', 'barterpay' ),
                    'default'     => '',
                ),
                'currency' => array(
                    'title'       => __( 'Currency', 'barterpay' ),
                    'type'        => 'select',
                    'description' => __( 'Select the currency for payments.', 'barterpay' ),
                    'default'     => 'USD',
                    'options'     => array(
                        'USD' => __( 'USD', 'barterpay' ),
                        'EUR' => __( 'EUR', 'barterpay' ),
                    )
                ),
                'sandbox' => array(
                    'title'   => __( 'Sandbox Mode', 'barterpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Sandbox Mode', 'barterpay' ),
                    'default' => 'yes',
                ),

                // ── Payment Flow ────────────────────────────────────
                'flow_section' => array(
                    'title' => __( 'Payment Flow', 'barterpay' ),
                    'type'  => 'title',
                    'description' => __( 'Choose how customers interact with BarterPay during checkout.', 'barterpay' ),
                ),
                'payment_flow' => array(
                    'title'       => __( 'Payment Flow', 'barterpay' ),
                    'type'        => 'select',
                    'description' => __( 'Hosted Payments redirects customers to BarterPay to complete payment. Widget Payments shows an embedded payment form directly on your checkout page via iframe. Defaults to Hosted Payments — existing sites are unaffected unless you change this.', 'barterpay' ),
                    'default'     => 'hosted',
                    'options'     => array(
                        'hosted' => __( 'Hosted Payments (Redirect to BarterPay)', 'barterpay' ),
                        'widget' => __( 'Widget Payments (Embedded iframe on checkout)', 'barterpay' ),
                    ),
                    'desc_tip'    => true,
                ),
                'signature_countdown_seconds' => array(
                    'title'       => __( 'Signature Countdown (seconds)', 'barterpay' ),
                    'type'        => 'number',
                    'description' => __( 'Widget (iframe) flow only. After payment is confirmed, hold the redirect this long to let the customer complete the BarterPay signature step. 0 = redirect immediately.', 'barterpay' ),
                    'default'     => '30',
                    'custom_attributes' => array( 'min' => '0', 'max' => '120', 'step' => '1' ),
                    'desc_tip'    => true,
                ),

                // ── Verification ────────────────────────────────────
                'verification_section' => array(
                    'title' => __( 'Payment Verification', 'barterpay' ),
                    'type'  => 'title',
                    'description' => __( 'How payment confirmations reach your store. Enable both callback and polling for maximum reliability.', 'barterpay' ),
                ),
                'enable_callback' => array(
                    'title'       => __( 'Callback Verification', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Accept server-to-server callbacks from BarterPay', 'barterpay' ),
                    'description' => __( 'Recommended. BarterPay POSTs payment status to your site. Best paired with polling for redundancy.', 'barterpay' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'enable_polling' => array(
                    'title'       => __( 'Polling Verification', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Poll BarterPay for status from the customer\'s browser', 'barterpay' ),
                    'description' => __( 'Recommended. Front-end polls BarterPay status while the customer is on the thank-you page or in the iframe. Best paired with callbacks for redundancy.', 'barterpay' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'enable_server_fallback' => array(
                    'title'       => __( 'Server-Side Fallback', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Run a one-time server-side status check if neither callback nor polling resolved the order', 'barterpay' ),
                    'description' => __( 'Strongly recommended. Catches transactions even when the customer closes their browser before polling finishes.', 'barterpay' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'server_fallback_delay_minutes' => array(
                    'title'       => __( 'Fallback Delay (minutes)', 'barterpay' ),
                    'type'        => 'number',
                    'description' => __( 'How long after order creation to perform the single server-side check.', 'barterpay' ),
                    'default'     => '10',
                    'custom_attributes' => array( 'min' => '5', 'max' => '25', 'step' => '1' ),
                    'desc_tip'    => true,
                ),

                // ── Order Management ────────────────────────────────
                'orders_section' => array(
                    'title' => __( 'Order Management', 'barterpay' ),
                    'type'  => 'title',
                    'description' => __( 'Control how abandoned and completed orders are handled.', 'barterpay' ),
                ),
                'enable_order_expiration' => array(
                    'title'       => __( 'Order Expiration', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Automatically cancel and trash abandoned orders after 30 minutes', 'barterpay' ),
                    'description' => __( 'Orders that remain on-hold for more than 30 minutes will be automatically cancelled and moved to trash.', 'barterpay' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'enable_custom_redirect' => array(
                    'title'   => __( 'Custom Redirect', 'barterpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable redirect to a custom URL after successful payment.', 'barterpay' ),
                    'default' => 'no',
                ),
                'custom_redirect_url' => array(
                    'title'       => __( 'Custom Redirect URL', 'barterpay' ),
                    'type'        => 'text',
                    'description' => __( 'Enter the URL to redirect to after a successful payment. Use placeholders like {order_key} and {order_id}. Example: /checkout/order-received/{order_id}/?key={order_key}', 'barterpay' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => '/checkout/order-received/{order_id}/?key={order_key}',
                ),

                // ── Advanced ────────────────────────────────────────
                'advanced_section' => array(
                    'title' => __( 'Advanced', 'barterpay' ),
                    'type'  => 'title',
                    'description' => __( 'Shipping restrictions, logging, and other advanced options.', 'barterpay' ),
                ),
                'instructions' => array(
                    'title'       => __( 'Instructions', 'barterpay' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page.', 'barterpay' ),
                    'default'     => __( 'Pay with Cards via BarterPay payments gateway.', 'barterpay' ),
                    'desc_tip'    => true,
                ),
                'enable_for_methods' => array(
                    'title'       => __( 'Shipping Methods', 'barterpay' ),
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
                'enable_for_virtual' => array(
                    'title' => __( 'Virtual Orders', 'barterpay' ),
                    'label' => __( 'Accept BarterPay, if the order is virtual', 'barterpay' ),
                    'type'  => 'checkbox',
                    'default' => 'yes',
                ),
                'enable_logs' => array(
                    'title'       => __( 'Debug Logging', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable log file generation', 'barterpay' ),
                    'default'     => 'yes',
                ),
            );
        }
        
        /**
         * Checks whether the admin settings are being accessed.
         *
         * @return bool
         */
        private function is_accessing_settings() {
            if ( is_admin() ) {
                if ( ! isset( $_REQUEST['page'] ) || 'wc-settings' !== $_REQUEST['page'] ) {
                    return false;
                }
                if ( ! isset( $_REQUEST['tab'] ) || 'checkout' !== $_REQUEST['tab'] ) {
                    return false;
                }
                if ( ! isset( $_REQUEST['section'] ) || 'barterpay' !== $_REQUEST['section'] ) {
                    return false;
                }
                return true;
            }

            // Check for REST requests.
            if ( function_exists( 'Constants::is_true' ) && Constants::is_true( 'REST_REQUEST' ) ) {
                global $wp;
                if ( isset( $wp->query_vars['rest_route'] ) && false !== strpos( $wp->query_vars['rest_route'], '/payment_gateways' ) ) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
         *
         * @param array $chosen_package_rate_ids Rate IDs as generated by shipping methods.
         * @return array $canonical_rate_ids  Rate IDs in a canonical format.
         * @since  3.4.0
         */
        private function get_canonical_package_rate_ids( $chosen_package_rate_ids ) {

            $shipping_packages = WC()->shipping()->get_packages();
            $canonical_rate_ids = array();

            if ( ! empty( $chosen_package_rate_ids ) && is_array( $chosen_package_rate_ids ) ) {
                foreach ( $chosen_package_rate_ids as $package_key => $chosen_package_rate_id ) {
                    if ( ! empty( $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ] ) ) {
                        $chosen_rate = $shipping_packages[ $package_key ]['rates'][ $chosen_package_rate_id ];
                        $canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
                    }
                }
            }
            return $canonical_rate_ids;
        }

        /**
         * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
         *
         * @param array $rate_ids Rate ids to check.
         * @return array
         * @since  3.4.0
         */
        private function get_matching_rates( $rate_ids ) {
            return array_unique( array_merge(
                array_intersect( $this->enable_for_methods, $rate_ids ),
                array_intersect( $this->enable_for_methods, array_unique( array_map( 'wc_get_string_before_colon', $rate_ids ) ) )
            ) );
        }

        /**
         * Loads all of the shipping method options for the enable_for_methods field.
         *
         * @return array
         */
        private function load_shipping_method_options() {
            if ( ! $this->is_accessing_settings() ) {
                return array();
            }

            $data_store = WC_Data_Store::load( 'shipping-zone' );
            $raw_zones = $data_store->get_zones();
            $zones = array();

            foreach ( $raw_zones as $raw_zone ) {
                $zones[] = new WC_Shipping_Zone( $raw_zone );
            }

            $zones[] = new WC_Shipping_Zone( 0 );

            $options = array();
            foreach ( WC()->shipping()->load_shipping_methods() as $method ) {

                $options[ $method->get_method_title() ] = array();

                $options[ $method->get_method_title() ][ $method->id ] = sprintf( __( 'Any &quot;%1$s&quot; method', 'woocommerce' ), $method->get_method_title() );

                foreach ( $zones as $zone ) {

                    $shipping_method_instances = $zone->get_shipping_methods();

                    foreach ( $shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance ) {

                        if ( $shipping_method_instance->id !== $method->id ) {
                            continue;
                        }

                        $option_id = $shipping_method_instance->get_rate_id();
                        $option_instance_title = sprintf( __( '%1$s (#%2$s)', 'woocommerce' ), $shipping_method_instance->get_title(), $shipping_method_instance_id );
                        $option_title = sprintf( __( '%1$s &ndash; %2$s', 'woocommerce' ), $zone->get_id() ? $zone->get_zone_name() : __( 'Other locations', 'woocommerce' ), $option_instance_title );
                        $options[ $method->get_method_title() ][ $option_id ] = $option_title;
                    }
                }
            }
            return $options;
        }

        /**
         * Process the payment: send a deposit request to BarterPay,
         * store transaction data, and return the redirect URL.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            // Choose the endpoint based on sandbox mode.
            if ( 'yes' === $this->sandbox ) {
                $endpoint = 'https://test-api.getbarterpay.com/api/pay/m-api/add-in-deposit-queue';
            } else {
                $endpoint = 'https://api.getbarterpay.com/api/pay/m-api/add-in-deposit-queue';
            }

            // Generate a unique TransactionId.
            $transaction_id = uniqid( 'txn_', true );
            // Save our generated TransactionId in order meta (using WC_Order methods for HPOS compatibility).
            if ( $order ) {
                $order->update_meta_data( '_barterpay_external_txn_id', $transaction_id );
                $order->save();
            } else {
                update_post_meta( $order_id, '_barterpay_external_txn_id', $transaction_id );
            }

            // Build the return URL for BarterPay to redirect customers after payment
            $return_url = add_query_arg(
                array(
                    'externalTransactionId' => $transaction_id,
                ),
                home_url( '/barterpay-return/' )
            );
            
            barterpay_log_callback( sprintf( 'Order %d: Generated return URL: %s', $order_id, $return_url ) );

            // Prepare the payload.
            $payload = array(
                'TransactionId' => $transaction_id,
                'Currency'      => $this->currency,
                'Amount'        => floatval( $order->get_total() ),
                'ReturnUrl'     => $return_url, // Return URL for customer redirect after payment
            );

            // Prepare the request arguments.
            $args = array(
                'body'    => json_encode( $payload ),
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-SAO-Token'  => $this->api_key,
                ),
            );

            barterpay_record_order_event(
                $order,
                sprintf( 'Deposit request sent (%s, flow=%s).', 'yes' === $this->sandbox ? 'sandbox' : 'production', $this->payment_flow ),
                'info'
            );

            // Send the request.
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                barterpay_record_order_event( $order, 'Deposit request failed: ' . $response->get_error_message(), 'error' );
                wc_add_notice( __( 'Payment error: Unable to connect to BarterPay.', 'barterpay' ), 'error' );
                return array( 'result' => 'fail' );
            }

            $body   = wp_remote_retrieve_body( $response );
            $result = json_decode( $body, true );

            if ( isset( $result['redirectUrl'] ) && ! empty( $result['redirectUrl'] ) ) {
                // Store the transaction index returned by BarterPay for later correlation.
                if ( isset( $result['transactionIndex'] ) ) {
                    if ( $order ) {
                        $order->update_meta_data( '_barterpay_api_txn_index', sanitize_text_field( $result['transactionIndex'] ) );
                        $order->save();
                    } else {
                        update_post_meta( $order_id, '_barterpay_api_txn_index', sanitize_text_field( $result['transactionIndex'] ) );
                    }
                }
                // Update the order status to on-hold until payment is confirmed.
                $order->update_status( 'on-hold', __( 'Awaiting BarterPay payment confirmation.', 'barterpay' ) );
                barterpay_record_order_event(
                    $order,
                    sprintf( 'Deposit accepted by BarterPay. Transaction Index: %s. Order set to on-hold.', isset( $result['transactionIndex'] ) ? $result['transactionIndex'] : 'n/a' ),
                    'success'
                );

                // Schedule the server-side fallback check if enabled.
                if ( 'yes' === $this->enable_server_fallback ) {
                    $delay = max( 5, $this->server_fallback_delay_minutes ) * 60;
                    if ( ! wp_next_scheduled( 'barterpay_fallback_check_order', array( $order_id ) ) ) {
                        wp_schedule_single_event( time() + $delay, 'barterpay_fallback_check_order', array( $order_id ) );
                        barterpay_log_callback( sprintf( 'Order %d: server-side fallback scheduled in %d min.', $order_id, $delay / 60 ) );
                    }
                }

                // Widget flow: store the BarterPay redirect URL in order meta so the frontend
                // AJAX handler can retrieve it, then return a hash-only redirect so WooCommerce
                // JS sets window.location.hash (no page reload) and our widget JS intercepts it.
                if ( 'widget' === $this->payment_flow ) {
                    $order->update_meta_data( '_barterpay_widget_url', esc_url_raw( $result['redirectUrl'] ) );
                    $order->save();
                    barterpay_log_callback( sprintf( 'Order %d: Widget flow — stored widget URL, returning hash redirect.', $order_id ) );
                    barterpay_record_order_event( $order, 'Widget flow: stored widget URL, returning hash redirect to open iframe modal.', 'info' );
                    return array(
                        'result'   => 'success',
                        'redirect' => '#barterpay-widget-' . $order_id,
                    );
                }

                // Hosted flow (default): redirect customer to BarterPay hosted page.
                barterpay_record_order_event( $order, 'Hosted flow: redirecting customer to BarterPay hosted page.', 'info' );
                return array(
                    'result'   => 'success',
                    'redirect' => $result['redirectUrl']
                );
            } else {
                barterpay_record_order_event( $order, 'Deposit response missing redirectUrl. Raw body: ' . substr( $body, 0, 500 ), 'error' );
                wc_add_notice( __( 'Payment error: Invalid response from BarterPay.', 'barterpay' ), 'error' );
                return array( 'result' => 'fail' );
            }
        }

        /**
         * Display a message and output JavaScript on the Thank You page
         * that polls for payment status every second.
         *
         * @param int $order_id
         */
        public function thankyou_page( $order_id ) {
            $order = wc_get_order( $order_id );
            
            // If order is already paid, don't show polling message or start polling
            if ( $order->is_paid() || $order->has_status( 'completed' ) || $order->has_status( 'processing' ) ) {
                return;
            }
            
            if ( 'yes' === $this->enable_custom_redirect && ! empty( $this->custom_redirect_url ) ) {
                $redirect_url = str_replace(
                    array( '{order_key}', '{order_id}' ),
                    array( $order->get_order_key(), $order->get_id() ),
                    $this->custom_redirect_url
                );
                ?>
                <style>
                    .barterpay-processing-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(255, 255, 255, 0.95);
                        z-index: 999999;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .barterpay-processing-content {
                        text-align: center;
                        padding: 40px;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                        max-width: 400px;
                    }
                    .barterpay-spinner {
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #3498db;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 20px;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    .barterpay-processing-content h3 {
                        margin: 0 0 10px;
                        font-size: 24px;
                        color: #333;
                    }
                    .barterpay-processing-content p {
                        margin: 0;
                        color: #666;
                        font-size: 16px;
                    }
                </style>
                <div class="barterpay-processing-overlay">
                    <div class="barterpay-processing-content">
                        <div class="barterpay-spinner"></div>
                        <h3><?php _e( 'Processing Payment', 'barterpay' ); ?></h3>
                        <p><?php _e( 'Please wait while we confirm your payment...', 'barterpay' ); ?></p>
                    </div>
                </div>
                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        console.log('BarterPay: Starting payment status polling...');
                        var pollCount = 0;
                        var maxPolls = 120; // Stop after 120 polls (10 minutes at 5 second intervals)
                        
                        var checkStatusInterval = setInterval(function () {
                            pollCount++;
                            console.log('BarterPay: Poll attempt ' + pollCount + '/' + maxPolls);
                            
                            if (pollCount >= maxPolls) {
                                console.log('BarterPay: Max polls reached, stopping...');
                                clearInterval(checkStatusInterval);
                                $('.barterpay-processing-overlay').fadeOut();
                                return;
                            }
                            
                            $.ajax({
                                url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                                method: 'POST',
                                data: {
                                    action: 'barterpay_check_status',
                                    order_id: '<?php echo $order_id; ?>'
                                },
                                success: function (response) {
                                    console.log('BarterPay: AJAX response', response);
                                    if (response.success && response.data.status === 'paid') {
                                        console.log('BarterPay: Payment confirmed! Redirecting...');
                                        clearInterval(checkStatusInterval);
                                        window.location.href = '<?php echo esc_js( $redirect_url ); ?>';
                                    }
                                },
                                error: function (xhr, status, error) {
                                    console.error('BarterPay: AJAX error', status, error);
                                }
                            });
                        }, 5000); // Poll every 5 seconds
                    });
                </script>
                <?php
            } else {
                ?>
                <style>
                    .barterpay-processing-overlay {
                        position: fixed;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: rgba(255, 255, 255, 0.95);
                        z-index: 999999;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .barterpay-processing-content {
                        text-align: center;
                        padding: 40px;
                        background: white;
                        border-radius: 8px;
                        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                        max-width: 400px;
                    }
                    .barterpay-spinner {
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #3498db;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 20px;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                    .barterpay-processing-content h3 {
                        margin: 0 0 10px;
                        font-size: 24px;
                        color: #333;
                    }
                    .barterpay-processing-content p {
                        margin: 0;
                        color: #666;
                        font-size: 16px;
                    }
                </style>
                <div class="barterpay-processing-overlay">
                    <div class="barterpay-processing-content">
                        <div class="barterpay-spinner"></div>
                        <h3><?php _e( 'Processing Payment', 'barterpay' ); ?></h3>
                        <p><?php _e( 'Please wait while we confirm your payment...', 'barterpay' ); ?></p>
                    </div>
                </div>
                <script type="text/javascript">
                    jQuery(document).ready(function ($) {
                        console.log('BarterPay: Starting payment status polling...');
                        var pollCount = 0;
                        var maxPolls = 60; // Stop after 60 polls (5 minutes at 5 second intervals)
                        
                        var checkStatusInterval = setInterval(function () {
                            pollCount++;
                            console.log('BarterPay: Poll attempt ' + pollCount + '/' + maxPolls);
                            
                            if (pollCount >= maxPolls) {
                                console.log('BarterPay: Max polls reached, stopping...');
                                clearInterval(checkStatusInterval);
                                $('.barterpay-processing-overlay').fadeOut();
                                return;
                            }
                            
                            $.ajax({
                                url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                                method: 'POST',
                                data: {
                                    action: 'barterpay_check_status',
                                    order_id: '<?php echo $order_id; ?>'
                                },
                                success: function (response) {
                                    console.log('BarterPay: AJAX response', response);
                                    if (response.success && response.data.status === 'paid') {
                                        console.log('BarterPay: Payment confirmed! Reloading page...');
                                        clearInterval(checkStatusInterval);
                                        // Reload the page so that the updated order status is reflected.
                                        location.reload();
                                    }
                                },
                                error: function (xhr, status, error) {
                                    console.error('BarterPay: AJAX error', status, error);
                                }
                            });
                        }, 5000); // Poll every 5 seconds
                    });
                </script>
                <?php
            }
        }

        /**
         * Override to enforce "at least one verification method enabled".
         */
        public function process_admin_options() {
            parent::process_admin_options();

            $callback = $this->get_option( 'enable_callback' );
            $polling  = $this->get_option( 'enable_polling' );

            if ( 'yes' !== $callback && 'yes' !== $polling ) {
                $this->update_option( 'enable_callback', 'yes' );
                WC_Admin_Settings::add_error(
                    __( 'BarterPay: at least one verification method must be enabled. Callback has been re-enabled automatically.', 'barterpay' )
                );
            }
        }
    }
}

/**
 * Register custom rewrite rule for BarterPay return URL.
 */
add_action( 'init', 'barterpay_add_rewrite_rules' );
function barterpay_add_rewrite_rules() {
    add_rewrite_rule(
        '^barterpay-return/?$',
        'index.php?barterpay_return=1',
        'top'
    );
    add_rewrite_tag( '%barterpay_return%', '([^&]+)' );
}

/**
 * Admin notice to flush rewrite rules if needed.
 */
add_action( 'admin_notices', 'barterpay_rewrite_flush_notice' );
function barterpay_rewrite_flush_notice() {
    // Check if we're on the settings page for this gateway
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'wc-settings' && 
         isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' &&
         isset( $_GET['section'] ) && $_GET['section'] === 'barterpay' ) {
        
        // Check if rewrite rules need flushing
        $rules = get_option( 'rewrite_rules' );
        if ( ! isset( $rules['^barterpay-return/?$'] ) ) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php _e( '<strong>BarterPay Gateway:</strong> The return URL endpoint is not active. Please visit ', 'barterpay' ); ?>
                    <a href="<?php echo admin_url( 'options-permalink.php' ); ?>"><?php _e( 'Settings → Permalinks', 'barterpay' ); ?></a>
                    <?php _e( ' and click "Save Changes" to activate the endpoint.', 'barterpay' ); ?>
                </p>
            </div>
            <?php
        }
    }
}

/**
 * Handle BarterPay return redirect and send to proper thank you page.
 */
add_action( 'template_redirect', 'barterpay_handle_return_redirect' );
function barterpay_handle_return_redirect() {
    // ALWAYS log when this function runs
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : 'N/A';
    barterpay_log_callback( 'template_redirect fired. REQUEST_URI: ' . $request_uri );
    
    // Check if the custom query var is set OR if we're accessing the URL directly (fallback)
    $query_var = get_query_var( 'barterpay_return' );
    $is_direct_access = isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/barterpay-return' ) !== false;
    
    barterpay_log_callback( sprintf( 
        'Query var barterpay_return: %s, Direct access check: %s',
        $query_var ? 'SET' : 'NOT SET',
        $is_direct_access ? 'YES' : 'NO'
    ));
    
    $is_barterpay_return = $query_var || $is_direct_access;
    
    if ( $is_barterpay_return ) {
        barterpay_log_callback( 'BarterPay return endpoint hit. GET params: ' . print_r( $_GET, true ) );
        
        // Get the external transaction ID from the URL
        $external_transaction_id = isset( $_GET['externalTransactionId'] ) ? sanitize_text_field( $_GET['externalTransactionId'] ) : '';
        $transaction_status = isset( $_GET['transactionStatus'] ) ? sanitize_text_field( $_GET['transactionStatus'] ) : '';
        $transaction_id = isset( $_GET['transactionId'] ) ? sanitize_text_field( $_GET['transactionId'] ) : '';
        $transaction_amount = isset( $_GET['transactionAmount'] ) ? sanitize_text_field( $_GET['transactionAmount'] ) : '';
        
        barterpay_log_callback( sprintf(
            'BarterPay return: externalTransactionId=%s, transactionId=%s, status=%s, amount=%s',
            $external_transaction_id, $transaction_id, $transaction_status, $transaction_amount
        ) );
        
        if ( empty( $external_transaction_id ) ) {
            barterpay_log_callback( 'BarterPay return: Missing externalTransactionId' );
            wp_die( __( 'Invalid payment return. Missing transaction ID.', 'barterpay' ) );
        }
        
        // Find the order by external transaction ID
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_barterpay_external_txn_id',
            'meta_value' => $external_transaction_id,
        ) );
        
        if ( empty( $orders ) ) {
            barterpay_log_callback( sprintf( 'BarterPay return: Order not found for externalTransactionId: %s', esc_html( $external_transaction_id ) ) );
            
            // More helpful error message
            wp_die( 
                sprintf( 
                    __( 'Order not found for transaction ID: %s. Please contact support if you believe this is an error.', 'barterpay' ),
                    esc_html( $external_transaction_id )
                ),
                __( 'Payment Return Error', 'barterpay' ),
                array( 'response' => 404 )
            );
        }
        
        $order = $orders[0];
        $order_id = $order->get_id();
        $order_key = $order->get_order_key();

        barterpay_log_callback( sprintf(
            'BarterPay return: Found order %d (key: %s) for externalTransactionId: %s. Order status: %s',
            $order_id,
            $order_key,
            esc_html( $external_transaction_id ),
            $order->get_status()
        ) );
        barterpay_record_order_event(
            $order,
            sprintf( 'Customer returned from BarterPay (status param: %s, txn: %s).', $transaction_status ?: 'none', $transaction_id ?: 'none' ),
            'info'
        );
        
        // Get the gateway instance
        $gateway = new WC_Gateway_BarterPay();
        $enable_polling = 'yes' === $gateway->get_option( 'enable_polling', 'yes' );
        
        barterpay_log_callback( sprintf( 'BarterPay return: enable_polling: %s', $enable_polling ? 'yes' : 'no' ) );
        
        // If polling is enabled, check transaction status via API
        if ( $enable_polling ) {
            barterpay_log_callback( '========== POLLING ENABLED — CHECKING API ==========' );
            
            $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );
            if ( empty( $transaction_index ) && ! empty( $transaction_id ) ) {
                $transaction_index = $transaction_id;
            }
            
            if ( empty( $transaction_index ) ) {
                barterpay_log_callback( sprintf( 'Order %d: cannot poll — no transaction index available.', $order_id ) );
                barterpay_record_event( $order, 'polling_check_ran', 'bad', array( 'reason' => 'no_txn_index' ) );
            } else {
                $api_response = barterpay_call_status_api( $transaction_index, $gateway );
                if ( is_array( $api_response ) ) {
                    $api_status = (string) barterpay_get_field( $api_response, array( 'transactionStatus', 'TransactionStatus', 'status' ) );
                    $api_amount = (float) barterpay_get_field( $api_response, array( 'transactionAmount', 'TransactionAmount', 'amount' ) );
                    barterpay_record_event( $order, 'polling_check_ran', 'ok', array(
                        'last_status' => $api_status,
                        'via'         => 'return_redirect',
                    ) );
                    barterpay_finalize_order_payment( $order, $api_status, $api_amount, 'polling', $transaction_index );
                } else {
                    barterpay_record_event( $order, 'polling_check_ran', 'bad', array( 'reason' => 'api_error' ) );
                }
            }
            barterpay_log_callback( '========== END POLLING CHECK ==========' );
        } else {
            barterpay_log_callback( 'Polling disabled — skipping API check on return redirect.' );
        }
        
        // Check if custom redirect is enabled
        if ( 'yes' === $gateway->get_option( 'enable_custom_redirect' ) && ! empty( $gateway->get_option( 'custom_redirect_url' ) ) ) {
            $redirect_url = str_replace(
                array( '{order_key}', '{order_id}' ),
                array( $order_key, $order_id ),
                $gateway->get_option( 'custom_redirect_url' )
            );
            barterpay_log_callback( sprintf( 'BarterPay return: Using CUSTOM redirect URL: %s', $redirect_url ) );
            barterpay_log_callback( sprintf( 'BarterPay return: WooCommerce native URL would be: %s', $order->get_checkout_order_received_url() ) );
        } else {
            // Use WooCommerce's native function to build the order received URL
            // This handles all permalink structures correctly
            $redirect_url = $order->get_checkout_order_received_url();
            barterpay_log_callback( sprintf( 'BarterPay return: Using WooCommerce native URL: %s', $redirect_url ) );
            
            // Log extensive debugging information
            $checkout_page_id = wc_get_page_id( 'checkout' );
            $checkout_url = wc_get_checkout_url();
            $permalink_structure = get_option( 'permalink_structure' );
            
            barterpay_log_callback( sprintf( 
                'BarterPay return: Debugging URLs - Order ID: %d, Key: %s, Checkout Page ID: %d, Checkout URL: %s, Permalink: %s, Generated URL: %s', 
                $order_id,
                $order_key,
                $checkout_page_id,
                $checkout_url,
                $permalink_structure ? $permalink_structure : 'plain',
                $redirect_url
            ) );
        }
        
        wp_redirect( $redirect_url );
        exit;
    }
}

/**
 * Callback handler for BarterPay asynchronous payment notifications.
 *
 * This function reads the callback (via JSON payload or GET parameters),
 * retrieves the related order via its transaction ID stored in meta,
 * and updates the order status accordingly.
 */
add_action( 'wp_loaded', 'barterpay_legacy_callback_check' );
function barterpay_legacy_callback_check(){
   if ( isset( $_GET['barterpay_callback'] ) && '1' === $_GET['barterpay_callback'] ) {
       barterpay_log_callback( 'Legacy callback URL detected: ?barterpay_callback=1. Processing...' );
       barterpay_handle_callback();
   }
}

/**
 * Callback handler for BarterPay asynchronous payment notifications.
 * This function is now primarily called by `barterpay_legacy_callback_check`.
 */
function barterpay_handle_callback() {
    barterpay_log_callback( 'Callback handler initiated. Method: ' . sanitize_text_field($_SERVER['REQUEST_METHOD']) );

    // Honour the enable_callback toggle.
    $gateway = new WC_Gateway_BarterPay();
    if ( 'yes' !== $gateway->get_option( 'enable_callback', 'yes' ) ) {
        barterpay_log_callback( 'Callback verification disabled in settings — ignoring.' );
        status_header( 200 );
        echo 'OK: Callback disabled';
        exit;
    }

    $raw_input = file_get_contents( 'php://input' );
    barterpay_log_callback( 'Raw callback payload (php://input): ' . $raw_input );
    $data_from_json_payload  = json_decode( $raw_input, true );

    $external_transaction_id = '';
    $api_transaction_index   = '';
    $transaction_status      = '';
    $transaction_amount      = 0;

    if ( $data_from_json_payload && isset( $data_from_json_payload['data'] ) && is_array($data_from_json_payload['data']) ) {
        $callback_data           = $data_from_json_payload['data'];
        $external_transaction_id = (string) barterpay_get_field( $callback_data, array( 'ExternalTransactionId', 'externalTransactionId' ) );
        $api_transaction_index   = (string) barterpay_get_field( $callback_data, array( 'TransactionIndex', 'transactionIndex', 'transactionId' ) );
        $transaction_status      = strtolower( (string) barterpay_get_field( $callback_data, array( 'TransactionStatus', 'transactionStatus', 'status' ) ) );
        $transaction_amount      = (float) barterpay_get_field( $callback_data, array( 'TransactionAmount', 'transactionAmount', 'amount' ) );
        barterpay_log_callback( 'Callback data parsed from JSON payload.' );
    }
    elseif ( isset( $_GET['externalTransactionId'] ) ) {
        barterpay_log_callback( 'Callback: No valid JSON payload found. Using GET parameters.' );
        $external_transaction_id = sanitize_text_field( $_GET['externalTransactionId'] );
        $api_transaction_index   = isset( $_GET['transactionId'] ) ? sanitize_text_field( $_GET['transactionId'] ) : '';
        $transaction_status      = isset( $_GET['transactionStatus'] ) ? strtolower( sanitize_text_field( $_GET['transactionStatus'] ) ) : '';
        $transaction_amount      = isset( $_GET['transactionAmount'] ) ? floatval( $_GET['transactionAmount'] ) : 0;
    } else {
        barterpay_log_callback( 'Callback Error: Invalid or empty data.' );
        echo 'ERROR: Invalid callback data';
        exit;
    }

    barterpay_log_callback( sprintf(
        'Callback Data Parsed: ExternalTxnId: %s, ApiTxnIndex: %s, Status: %s, Amount: %s',
        esc_html($external_transaction_id), esc_html($api_transaction_index), esc_html($transaction_status), esc_html($transaction_amount)
    ) );

    if ( empty( $external_transaction_id ) ) {
        barterpay_log_callback( 'Callback Error: ExternalTransactionId is missing.' );
        echo 'ERROR: ExternalTransactionId missing';
        exit;
    }

    // Widen order lookup — include processing/completed so we can ACK duplicates.
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'meta_key'   => '_barterpay_external_txn_id',
        'meta_value' => $external_transaction_id,
        'status'     => array( 'wc-on-hold', 'wc-pending', 'wc-processing', 'wc-completed', 'wc-failed' ),
    ) );

    if ( empty( $orders ) ) {
        barterpay_log_callback( sprintf( 'Callback: Order not found for ExternalTransactionId: %s.', esc_html($external_transaction_id) ) );
        status_header( 200 );
        echo 'OK: Order not found or already processed';
        exit;
    }

    $order    = $orders[0];
    $order_id = $order->get_id();
    barterpay_log_callback( sprintf( 'Order %d found for ExternalTransactionId: %s.', $order_id, esc_html($external_transaction_id) ) );

    barterpay_record_event( $order, 'callback_received', 'ok', array(
        'raw_status' => $transaction_status,
        'amount'     => $transaction_amount,
        'txn_index'  => $api_transaction_index,
    ) );

    // Use the centralized finalize helper.
    $txn_ref = ! empty( $api_transaction_index ) ? $api_transaction_index : $external_transaction_id;
    barterpay_finalize_order_payment( $order, $transaction_status, $transaction_amount, 'callback', $txn_ref );

    status_header( 200 );
    echo 'OK';
    exit;
}
/**
 * Register the logs page under Settings.
 */
add_action( 'admin_menu', 'barterpay_add_settings_menu' );
function barterpay_add_settings_menu() {
    add_options_page(
        'BarterPay Logs',          // Page title.
        'BarterPay Logs',          // Menu title.
        'manage_options',          // Capability required.
        'barterpay_logs',          // Menu slug.
        'barterpay_logs_page'      // Callback function.
    );
}

/**
 * Render the callback logs page.
 */
function barterpay_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Path to the log file.
    $log_file = plugin_dir_path( __FILE__ ) . 'barterpay-callback.log';
    
    echo '<div class="wrap">';
    echo '<h1>BarterPay Callback Logs</h1>';

    // Option to clear logs.
    if ( isset( $_GET['action'] ) && 'clear' === $_GET['action'] && check_admin_referer( 'barterpay_clear_logs' ) ) {
        file_put_contents( $log_file, '' );
        echo '<div class="updated notice"><p>Logs cleared.</p></div>';
    }

    if ( file_exists( $log_file ) ) {
        $logs = file_get_contents( $log_file );
        // Form for clearing logs.
        echo '<form method="get" style="margin-bottom:1em;">';
        echo '<input type="hidden" name="page" value="barterpay_logs" />';
        wp_nonce_field( 'barterpay_clear_logs' );
        echo '<input type="submit" name="action" value="clear" class="button button-secondary" onclick="return confirm(\'Are you sure you want to clear the logs?\');" />';
        echo '</form>';
        // Display the logs in a scrollable block.
        echo '<div style="overflow:auto; max-height:500px; background:#f4f4f4; padding:10px; border:1px solid #ddd;"><pre>' . esc_html( $logs ) . '</pre></div>';
    } else {
        echo '<p>No logs found.</p>';
    }
    
    echo '</div>';
}

/**
 * Callback logging helper function.
 *
 * @param string $message The message to log.
 */
function barterpay_log_callback( $message ) {
    $gateway_settings = get_option( 'woocommerce_barterpay_settings', array() );
    if ( ! empty( $gateway_settings['enable_logs'] ) && 'yes' === $gateway_settings['enable_logs'] ) {
        $log_file = plugin_dir_path( __FILE__ ) . 'barterpay-callback.log'; // Log file in plugin's root directory
        $timestamp = current_time( 'mysql' );
        $message_to_log = ( is_array( $message ) || is_object( $message ) ) ? print_r( $message, true ) : $message;
        file_put_contents( $log_file, "[$timestamp] " . $message_to_log . PHP_EOL, FILE_APPEND );
    }

}

/**
 * Check if WooCommerce Blocks is loaded and add support for BarterPay.
 */
add_action( 'woocommerce_blocks_loaded', 'barterpay_load_blocks_support' );
function barterpay_load_blocks_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        $blocks_support_file = plugin_dir_path( __FILE__ ) . 'includes/class-barterpay-blocks-support.php';
        if ( file_exists( $blocks_support_file ) ) {
            require_once $blocks_support_file;
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                    if ( class_exists( 'BarterPay_Blocks_Support' ) ) {
                        $payment_method_registry->register( new BarterPay_Blocks_Support() );
                    }
                }
            );
        }
    }
}

/**
 * AJAX Endpoint for Polling Payment Status
 *
 * This endpoint is used by the JS on the Thank You page to check if
 * the payment callback has updated the order status.
 */
add_action( 'wp_ajax_barterpay_check_status', 'barterpay_check_status_callback' );
add_action( 'wp_ajax_nopriv_barterpay_check_status', 'barterpay_check_status_callback' );
function barterpay_check_status_callback() {
    nocache_headers();

    if ( ! isset( $_POST['order_id'] ) ) {
        wp_send_json_error( array( 'message' => 'No order id provided' ) );
    }

    $order_id = absint( $_POST['order_id'] );

    // Clear any cached order data to ensure we get the latest status.
    wp_cache_delete( 'order-' . $order_id, 'orders' );
    wp_cache_delete( $order_id, 'post_meta' );

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( array( 'message' => 'Invalid order id' ) );
    }

    $gateway = new WC_Gateway_BarterPay();
    $enable_polling = 'yes' === $gateway->get_option( 'enable_polling', 'yes' );

    barterpay_log_callback( sprintf(
        '========== AJAX POLLING CHECK for order %d (polling: %s) ==========',
        $order_id,
        $enable_polling ? 'yes' : 'no'
    ) );

    // If polling is enabled AND order not yet resolved, query the API.
    if ( $enable_polling && ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
        $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );

        if ( ! empty( $transaction_index ) ) {
            $api_response = barterpay_call_status_api( $transaction_index, $gateway );

            if ( is_array( $api_response ) ) {
                $api_status = (string) barterpay_get_field( $api_response, array( 'transactionStatus', 'TransactionStatus', 'status' ) );
                $api_amount = (float) barterpay_get_field( $api_response, array( 'transactionAmount', 'TransactionAmount', 'amount' ) );

                barterpay_record_event( $order, 'ajax_poll_ran', 'ok', array(
                    'last_status' => $api_status,
                    'via'         => 'thankyou_ajax',
                ) );

                barterpay_finalize_order_payment( $order, $api_status, $api_amount, 'ajax_polling', $transaction_index );

                // Reload after finalize.
                $order = wc_get_order( $order_id );
            }
        }
    }

    // Return current order state.
    $is_paid = $order->is_paid();
    $status  = $order->get_status();

    if ( $is_paid || in_array( $status, array( 'completed', 'processing' ), true ) ) {
        wp_send_json_success( array(
            'status'       => 'paid',
            'order_status' => $status,
        ) );
    } elseif ( 'failed' === $status ) {
        wp_send_json_success( array(
            'status'       => 'failed',
            'order_status' => $status,
        ) );
    } else {
        wp_send_json_success( array(
            'status'       => 'pending',
            'order_status' => $status,
        ) );
    }
}

/**
 * Add our BarterPay gateway to WooCommerce.
 */
add_filter( 'woocommerce_payment_gateways', 'add_barterpay_gateway' );
function add_barterpay_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_BarterPay';
    return $gateways;
}

/**
 * One-time settings migration to v1.5.0.
 *
 * Translates the old `verification_method` dropdown into two checkboxes,
 * forcing both ON so existing installs gain redundancy automatically.
 */
add_action( 'woocommerce_init', 'barterpay_migrate_settings_v15' );
function barterpay_migrate_settings_v15() {
    if ( get_option( 'barterpay_settings_migrated_v15' ) ) {
        return;
    }

    $settings = get_option( 'woocommerce_barterpay_settings', array() );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    // Save legacy value for forensic reference.
    if ( isset( $settings['verification_method'] ) ) {
        update_option( 'barterpay_legacy_verification_method', $settings['verification_method'], false );
    }

    // Force both verifications on.
    $settings['enable_callback']               = 'yes';
    $settings['enable_polling']                = 'yes';
    $settings['enable_server_fallback']        = isset( $settings['enable_server_fallback'] ) ? $settings['enable_server_fallback'] : 'yes';
    $settings['server_fallback_delay_minutes'] = isset( $settings['server_fallback_delay_minutes'] ) ? $settings['server_fallback_delay_minutes'] : '10';
    $settings['signature_countdown_seconds']   = isset( $settings['signature_countdown_seconds'] ) ? $settings['signature_countdown_seconds'] : '30';

    // Drop old key so it can't be read by accident.
    unset( $settings['verification_method'] );

    update_option( 'woocommerce_barterpay_settings', $settings );

    // Schedule the +N min fallback for any in-flight on-hold orders < 30 min old.
    if ( function_exists( 'wc_get_orders' ) ) {
        $delay_minutes = absint( $settings['server_fallback_delay_minutes'] );
        $orders = wc_get_orders( array(
            'limit'          => -1,
            'status'         => 'on-hold',
            'payment_method' => 'barterpay',
            'date_created'   => '>' . ( time() - 1800 ),
        ) );
        foreach ( $orders as $order ) {
            $created  = $order->get_date_created()->getTimestamp();
            $fire_at  = max( time() + 60, $created + ( $delay_minutes * 60 ) );
            if ( ! wp_next_scheduled( 'barterpay_fallback_check_order', array( $order->get_id() ) ) ) {
                wp_schedule_single_event( $fire_at, 'barterpay_fallback_check_order', array( $order->get_id() ) );
            }
        }
        barterpay_log_callback( sprintf( 'Settings migration v1.5.0 complete. Scheduled fallback for %d in-flight orders.', count( $orders ) ) );
    }

    update_option( 'barterpay_settings_migrated_v15', 1 );
}

/**
 * Ensure cron is scheduled on plugin load (automatic scheduling).
 */
add_action( 'plugins_loaded', 'barterpay_ensure_cron_scheduled' );
function barterpay_ensure_cron_scheduled() {
    if ( ! wp_next_scheduled( 'barterpay_check_abandoned_orders' ) ) {
        wp_schedule_event( time(), 'hourly', 'barterpay_check_abandoned_orders' );
    }
}

/**
 * Activation hook to flush rewrite rules and schedule cron.
 */
register_activation_hook( __FILE__, 'barterpay_activate' );
function barterpay_activate() {
    barterpay_add_rewrite_rules();
    flush_rewrite_rules();
    
    // Schedule cron job for order expiration check
    if ( ! wp_next_scheduled( 'barterpay_check_abandoned_orders' ) ) {
        wp_schedule_event( time(), 'hourly', 'barterpay_check_abandoned_orders' );
    }
}

/**
 * Deactivation hook to flush rewrite rules and clear cron.
 */
register_deactivation_hook( __FILE__, 'barterpay_deactivate' );
function barterpay_deactivate() {
    flush_rewrite_rules();
    
    // Clear scheduled cron job
    $timestamp = wp_next_scheduled( 'barterpay_check_abandoned_orders' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'barterpay_check_abandoned_orders' );
    }

    // Clear any pending fallback single events.
    wp_unschedule_hook( 'barterpay_fallback_check_order' );
}

/**
 * Server-side fallback: single-fire check scheduled from process_payment.
 * Catches transactions that were confirmed while the customer had already closed their browser.
 */
add_action( 'barterpay_fallback_check_order', 'barterpay_fallback_check_order_handler' );
function barterpay_fallback_check_order_handler( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Only act on unresolved orders.
    if ( $order->is_paid() || $order->has_status( array( 'completed', 'processing', 'failed', 'cancelled', 'refunded' ) ) ) {
        barterpay_log_callback( sprintf( 'Fallback: order %d already resolved (%s). Skipping.', $order_id, $order->get_status() ) );
        return;
    }

    $gateway = new WC_Gateway_BarterPay();
    if ( 'yes' !== $gateway->get_option( 'enable_server_fallback', 'yes' ) ) {
        return;
    }

    $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );
    if ( empty( $transaction_index ) ) {
        barterpay_record_event( $order, 'fallback_ran', 'bad', array( 'reason' => 'no_txn_index' ) );
        return;
    }

    barterpay_log_callback( sprintf( 'Fallback: checking order %d (txn_index: %s)', $order_id, $transaction_index ) );

    $api_response = barterpay_call_status_api( $transaction_index, $gateway );
    if ( is_array( $api_response ) ) {
        $api_status = (string) barterpay_get_field( $api_response, array( 'transactionStatus', 'TransactionStatus', 'status' ) );
        $api_amount = (float) barterpay_get_field( $api_response, array( 'transactionAmount', 'TransactionAmount', 'amount' ) );

        barterpay_record_event( $order, 'fallback_ran', 'ok', array(
            'last_status' => $api_status,
        ) );

        barterpay_finalize_order_payment( $order, $api_status, $api_amount, 'server_fallback', $transaction_index );
    } else {
        barterpay_record_event( $order, 'fallback_ran', 'bad', array( 'reason' => 'api_error' ) );
    }
}

/**
 * Check and expire abandoned BarterPay orders.
 * Orders that are on-hold for more than 2 minutes will be set to failed status.
 */
add_action( 'barterpay_check_abandoned_orders', 'barterpay_expire_abandoned_orders' );
function barterpay_expire_abandoned_orders() {
    // Store the last run time
    update_option( 'barterpay_cron_last_run', time() );
    
    // Check if order expiration feature is enabled
    $gateway = new WC_Gateway_BarterPay();
    $enabled = $gateway->get_option( 'enable_order_expiration', 'yes' );
    
    if ( 'yes' !== $enabled ) {
        barterpay_log_callback( 'Order expiration is disabled - skipping check.' );
        return;
    }
    
    barterpay_log_callback( 'Checking for abandoned BarterPay orders...' );

    $now = time();

    // Only look at orders created between 30 minutes and 24 hours ago.
    // Anything older than 24 h that is still on-hold is stale and gets
    // cancelled without an API call.
    $min_age_seconds = 1800;       // 30 minutes
    $max_age_seconds = 86400;      // 24 hours

    // --- 1. Recent window: 30 min – 24 h.  Query API before cancelling. ---
    $recent_orders = wc_get_orders( array(
        'limit'          => 50,
        'status'         => 'on-hold',
        'payment_method' => 'barterpay',
        'date_created'   => ( $now - $max_age_seconds ) . '...' . ( $now - $min_age_seconds ),
        'orderby'        => 'date',
        'order'          => 'ASC',
    ) );

    // --- 2. Stale orders older than 24 h — cancel immediately. ---
    $stale_orders = wc_get_orders( array(
        'limit'          => 100,
        'status'         => 'on-hold',
        'payment_method' => 'barterpay',
        'date_created'   => '<' . ( $now - $max_age_seconds ),
        'orderby'        => 'date',
        'order'          => 'ASC',
    ) );

    if ( empty( $recent_orders ) && empty( $stale_orders ) ) {
        barterpay_log_callback( 'No abandoned orders found.' );
        return;
    }

    barterpay_log_callback( sprintf( 'Found %d recent + %d stale abandoned orders', count( $recent_orders ), count( $stale_orders ) ) );

    // Process recent orders: one last API check before cancelling.
    foreach ( $recent_orders as $order ) {
        $order_id = $order->get_id();
        $order_date = $order->get_date_created();
        $time_diff = $now - $order_date->getTimestamp();
        
        barterpay_log_callback( sprintf(
            'Processing abandoned order %d (created %d minutes ago)',
            $order_id,
            floor( $time_diff / 60 )
        ) );

        // Belt-and-suspenders: query the API one last time before trashing.
        $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );
        if ( ! empty( $transaction_index ) ) {
            $api_response = barterpay_call_status_api( $transaction_index, $gateway );
            if ( is_array( $api_response ) ) {
                $api_status = (string) barterpay_get_field( $api_response, array( 'transactionStatus', 'TransactionStatus', 'status' ) );
                $api_amount = (float) barterpay_get_field( $api_response, array( 'transactionAmount', 'TransactionAmount', 'amount' ) );
                $normalised = barterpay_normalize_api_status( $api_status );

                barterpay_record_event( $order, 'abandonment_check_ran', 'ok', array( 'last_status' => $api_status ) );

                if ( 'paid' === $normalised ) {
                    barterpay_finalize_order_payment( $order, $api_status, $api_amount, 'abandonment_cron', $transaction_index );
                    barterpay_log_callback( sprintf( 'Order %d rescued by abandonment API check — now paid.', $order_id ) );
                    continue; // Do NOT trash a paid order.
                }
            }
        }
        
        // Set order to cancelled and move to trash
        $order->update_status( 'cancelled', __( 'Order expired: Payment was not completed within 30 minutes.', 'barterpay' ) );
        wp_trash_post( $order_id );
        
        barterpay_log_callback( sprintf( 'Order %d marked as cancelled and moved to trash due to expiration', $order_id ) );
    }

    // Cancel stale orders immediately — no API call for months-old orders.
    foreach ( $stale_orders as $order ) {
        $order_id = $order->get_id();
        $order->update_status( 'cancelled', __( 'Order expired: Stale on-hold order cleaned up by cron.', 'barterpay' ) );
        wp_trash_post( $order_id );
        barterpay_log_callback( sprintf( 'Stale order %d cancelled and trashed (no API check).', $order_id ) );
    }
    
    barterpay_log_callback( sprintf( 'Finished processing %d abandoned orders', count( $recent_orders ) + count( $stale_orders ) ) );
}

/**
 * Display admin notice showing cron job status.
 * This helps diagnose if the cron job is properly scheduled.
 */
add_action( 'admin_notices', 'barterpay_cron_status_notice' );
function barterpay_cron_status_notice() {
    // Only show to administrators on the gateway settings page
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    // Only show on BarterPay settings page
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-settings' ||
         ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'checkout' ||
         ! isset( $_GET['section'] ) || $_GET['section'] !== 'barterpay' ) {
        return;
    }
    
    $next_run = wp_next_scheduled( 'barterpay_check_abandoned_orders' );
    $last_run = get_option( 'barterpay_cron_last_run' );
    
    if ( $next_run ) {
        echo '<div class="notice notice-info"><p>';
        echo '<strong>BarterPay Order Expiration Cron:</strong> Active. ';
        
        if ( $last_run ) {
            echo 'Last run: <code>' . date( 'Y-m-d H:i:s', $last_run ) . '</code> | ';
        } else {
            echo 'Last run: <code>Never</code> | ';
        }
        
        echo 'Next run: <code>' . date( 'Y-m-d H:i:s', $next_run ) . '</code>';
        echo ' | <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay&barterpay_expire_now=1' ) . '" onclick="return confirm(\'Run order expiration check now?\')" class="button button-small">Run Now</a>';
        echo ' | <a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay&barterpay_reschedule_cron=1' ) . '" onclick="return confirm(\'Reschedule cron to run in 1 minute?\')" class="button button-small">Test (Run in 1 min)</a>';
        echo '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BarterPay Order Expiration Cron:</strong> NOT SCHEDULED! ';
        echo '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay&barterpay_schedule_cron=1' ) . '" class="button button-primary button-small">Schedule Now</a> ';
        echo 'or deactivate and reactivate the plugin.';
        echo '</p></div>';
    }
}

/**
 * Manual trigger for order expiration check.
 * Allows administrators to manually run the expiration check.
 */
add_action( 'admin_init', 'barterpay_manual_expire_check' );
function barterpay_manual_expire_check() {
    // Reschedule cron to run in 1 minute (for testing)
    if ( isset( $_GET['barterpay_reschedule_cron'] ) && current_user_can( 'manage_options' ) ) {
        $timestamp = wp_next_scheduled( 'barterpay_check_abandoned_orders' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'barterpay_check_abandoned_orders' );
        }
        wp_schedule_event( time() + 60, 'hourly', 'barterpay_check_abandoned_orders' );
        barterpay_log_callback( 'Cron rescheduled to run in 1 minute by admin (testing).' );
        wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay' ) );
        exit;
    }
    
    // Schedule cron if requested
    if ( isset( $_GET['barterpay_schedule_cron'] ) && current_user_can( 'manage_options' ) ) {
        if ( ! wp_next_scheduled( 'barterpay_check_abandoned_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'barterpay_check_abandoned_orders' );
            barterpay_log_callback( 'Cron job scheduled manually by admin.' );
        }
        wp_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=barterpay' ) );
        exit;
    }
    
    if ( isset( $_GET['barterpay_expire_now'] ) && current_user_can( 'manage_options' ) ) {
        barterpay_log_callback( 'Manual order expiration check triggered by admin.' );
        barterpay_expire_abandoned_orders();
        
        // Redirect back to settings page with success message
        wp_redirect( add_query_arg(
            array(
                'page' => 'wc-settings',
                'tab' => 'checkout',
                'section' => 'barterpay',
                'barterpay_expired' => '1'
            ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }
    
    // Show success message after manual expiration
    if ( isset( $_GET['barterpay_expired'] ) && current_user_can( 'manage_options' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>BarterPay:</strong> Order expiration check completed. Check logs for details.';
            echo '</p></div>';
        } );
    }
}

/**
 * Register transaction details meta box on order edit page.
 */
add_action( 'add_meta_boxes', 'barterpay_add_order_meta_box', 10, 2 );
function barterpay_add_order_meta_box( $screen_id = null, $post_or_order = null ) {
    // Classic posts table (legacy order storage).
    add_meta_box(
        'barterpay_transaction_details',
        __( 'BarterPay Transaction Details', 'barterpay' ),
        'barterpay_order_meta_box_content',
        'shop_order',
        'side',
        'default'
    );

    // HPOS — the screen ID varies between WC versions
    // (woocommerce_page_wc-orders, wc-orders, etc.). Register on whatever
    // the current screen actually is when it is an order edit screen.
    $hpos_screens = array( 'woocommerce_page_wc-orders' );
    if ( function_exists( 'wc_get_page_screen_id' ) ) {
        $hpos_screens[] = wc_get_page_screen_id( 'shop-order' );
    }
    if ( ! empty( $screen_id ) ) {
        $hpos_screens[] = $screen_id;
    }

    foreach ( array_unique( array_filter( $hpos_screens ) ) as $screen ) {
        add_meta_box(
            'barterpay_transaction_details',
            __( 'BarterPay Transaction Details', 'barterpay' ),
            'barterpay_order_meta_box_content',
            $screen,
            'side',
            'default'
        );
    }
}

/**
 * Display BarterPay transaction details in meta box.
 */
function barterpay_order_meta_box_content( $post_or_order ) {
    // Support both post objects and order objects (for HPOS)
    $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;

    if ( ! $order || $order->get_payment_method() !== 'barterpay' ) {
        echo '<p>' . __( 'Not a BarterPay order.', 'barterpay' ) . '</p>';
        return;
    }

    $order_key         = $order->get_order_key();
    $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );
    $external_txn_id   = $order->get_meta( '_barterpay_external_txn_id', true );
    $last_api_status   = $order->get_meta( '_barterpay_last_api_status', true );
    $payment_flow      = $order->get_meta( '_barterpay_payment_flow', true );

    // Per-order debug timeline.
    $events = $order->get_meta( '_barterpay_events', true );
    if ( ! is_array( $events ) ) {
        $events = array();
    }

    // Section map for grouping events.
    $section_map = array(
        'deposit'      => array( 'deposit_request', 'deposit_accepted', 'deposit_failed' ),
        'customer'     => array( 'widget_js_', 'iframe_opened', 'iframe_closed', 'iframe_return_detected', 'postmessage_paid' ),
        'verification' => array( 'callback_received', 'polling_check_ran', 'polling_paid', 'polling_failed', 'ajax_poll_ran', 'widget_poll_ran', 'fallback_ran', 'abandonment_check_ran' ),
        'resolution'   => array( 'payment_finalised', 'payment_already_resolved', 'legacy_event' ),
    );
    $section_labels = array(
        'deposit'      => __( '1. Deposit', 'barterpay' ),
        'customer'     => __( '2. Customer Journey', 'barterpay' ),
        'verification' => __( '3. Verification', 'barterpay' ),
        'resolution'   => __( '4. Resolution', 'barterpay' ),
    );

    // Icon config: level → (bg color, symbol).
    $icon_map = array(
        'ok'   => array( '#00a32a', '✓' ),
        'bad'  => array( '#d63638', '✗' ),
        'wait' => array( '#dba617', '…' ),
        'skip' => array( '#c3c4c7', '–' ),
        'info' => array( '#2271b1', 'i' ),
        // Legacy support.
        'success' => array( '#00a32a', '✓' ),
        'error'   => array( '#d63638', '✗' ),
        'warn'    => array( '#dba617', '…' ),
    );

    // Classify events into sections.
    $sections = array( 'deposit' => array(), 'customer' => array(), 'verification' => array(), 'resolution' => array(), 'other' => array() );
    foreach ( $events as $event ) {
        $type   = isset( $event['type'] ) ? $event['type'] : ( isset( $event['tag'] ) ? $event['tag'] : 'unknown' );
        $placed = false;
        foreach ( $section_map as $section => $prefixes ) {
            foreach ( $prefixes as $prefix ) {
                if ( strpos( $type, $prefix ) === 0 || $type === $prefix ) {
                    $sections[ $section ][] = $event;
                    $placed = true;
                    break 2;
                }
            }
        }
        if ( ! $placed ) {
            $sections['other'][] = $event;
        }
    }

    // Flow description.
    $flow_label = $payment_flow ? esc_html( $payment_flow ) . ' flow' : '';
    $enabled    = array();
    $settings   = get_option( 'woocommerce_barterpay_settings', array() );
    if ( ! empty( $settings['enable_callback'] ) && $settings['enable_callback'] === 'yes' ) {
        $enabled[] = 'callback';
    }
    if ( ! empty( $settings['enable_polling'] ) && $settings['enable_polling'] === 'yes' ) {
        $enabled[] = 'polling';
    }
    if ( ! empty( $settings['enable_server_fallback'] ) && $settings['enable_server_fallback'] === 'yes' ) {
        $enabled[] = 'server fallback';
    }
    $verifications = ! empty( $enabled ) ? implode( ' + ', $enabled ) : 'none';

    // --- Render ---
    ?>
    <style>
        .bp-debug{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:12px;color:#1d2327}
        .bp-debug .bp-sub{color:#646970;font-size:11px;margin:0 0 8px}
        .bp-debug .bp-sect{border-top:1px solid #f0f0f1;padding:10px 0 2px}
        .bp-debug .bp-sect:first-of-type{border-top:none;padding-top:4px}
        .bp-debug .bp-sect-title{margin:0 0 6px;font-weight:600;font-size:12px;color:#1d2327}
        .bp-debug .bp-ev{display:flex;align-items:flex-start;gap:8px;padding:3px 0}
        .bp-debug .bp-ic{width:16px;height:16px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;flex-shrink:0;margin-top:1px;line-height:1}
        .bp-debug .bp-ev .bp-ll{flex:1}
        .bp-debug .bp-ev .bp-ll .bp-nm{color:#1d2327}
        .bp-debug .bp-ev .bp-ll .bp-mt{color:#646970;font-size:11px;margin-top:1px}
        .bp-debug .bp-ft{background:#f6f7f7;padding:6px 8px;border-radius:3px;margin-top:10px;font-family:ui-monospace,SFMono-Regular,monospace;font-size:11px;color:#50575e}
        .bp-debug .bp-empty{color:#888;margin:4px 0;font-size:11px}
    </style>
    <div class="bp-debug">
        <p class="bp-sub">
            <?php echo esc_html( sprintf( 'Order #%s', $order->get_id() ) ); ?>
            <?php if ( $flow_label ) echo ' · ' . esc_html( $flow_label ); ?>
            <?php echo ' · ' . esc_html( $verifications ); ?>
        </p>
        <?php
        $section_num = 0;
        foreach ( $sections as $section_key => $section_events ) {
            $label = isset( $section_labels[ $section_key ] ) ? $section_labels[ $section_key ] : __( 'Other', 'barterpay' );
            ?>
            <div class="bp-sect">
                <div class="bp-sect-title"><?php echo esc_html( $label ); ?></div>
                <?php if ( empty( $section_events ) ) : ?>
                    <p class="bp-empty"><?php _e( 'No events.', 'barterpay' ); ?></p>
                <?php else : ?>
                    <?php foreach ( $section_events as $event ) :
                        // Parse event data.
                        if ( isset( $event['ts'] ) ) {
                            $time  = date( 'H:i:s', $event['ts'] );
                            $type  = isset( $event['type'] ) ? $event['type'] : '';
                            $level = isset( $event['level'] ) ? $event['level'] : 'info';
                            $meta  = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
                            // Build readable name from type.
                            $name = ucfirst( str_replace( '_', ' ', $type ) );
                            // Build meta line.
                            $meta_parts = array( $time );
                            foreach ( $meta as $k => $v ) {
                                $meta_parts[] = $k . ': ' . $v;
                            }
                            $meta_line = implode( ' · ', $meta_parts );
                        } else {
                            // Legacy format.
                            $time      = isset( $event['time'] ) ? $event['time'] : '';
                            $name      = isset( $event['msg'] ) ? $event['msg'] : ( isset( $event['tag'] ) ? $event['tag'] : 'unknown' );
                            $level     = isset( $event['tag'] ) ? $event['tag'] : 'info';
                            $meta_line = $time;
                        }
                        $ic = isset( $icon_map[ $level ] ) ? $icon_map[ $level ] : array( '#2271b1', 'i' );
                        ?>
                        <div class="bp-ev">
                            <span class="bp-ic" style="background:<?php echo esc_attr( $ic[0] ); ?>;"><?php echo esc_html( $ic[1] ); ?></span>
                            <div class="bp-ll">
                                <div class="bp-nm"><?php echo esc_html( $name ); ?></div>
                                <div class="bp-mt"><?php echo esc_html( $meta_line ); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>

        <div class="bp-ft">
            <?php if ( $external_txn_id ) : ?>
                <strong><?php _e( 'External Txn ID:', 'barterpay' ); ?></strong> <?php echo esc_html( $external_txn_id ); ?><br>
            <?php endif; ?>
            <?php if ( $transaction_index ) : ?>
                <strong><?php _e( 'API Index:', 'barterpay' ); ?></strong> <?php echo esc_html( $transaction_index ); ?><br>
            <?php endif; ?>
            <strong><?php _e( 'Order Key:', 'barterpay' ); ?></strong> <?php echo esc_html( $order_key ); ?>
            <?php if ( $last_api_status ) : ?>
                <br><strong><?php _e( 'Last raw API status:', 'barterpay' ); ?></strong> "<?php echo esc_html( $last_api_status ); ?>" (mapped to: <?php echo esc_html( barterpay_normalize_api_status( $last_api_status ) ); ?>)
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Record a lifecycle event for an order. Stores in _barterpay_events postmeta.
 *
 * @param WC_Order|int|null $order  Order object, ID, or null (no-op).
 * @param string            $type   Event type (e.g., 'callback_received', 'iframe_opened').
 * @param string            $level  One of 'ok', 'bad', 'wait', 'skip', 'info'. Default 'info'.
 * @param array             $meta   Optional structured metadata stored with the event.
 */
function barterpay_record_event( $order, $type, $level = 'info', $meta = array() ) {
    if ( empty( $order ) ) {
        return;
    }
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order ) {
        return;
    }

    $allowed_levels = array( 'ok', 'bad', 'wait', 'skip', 'info' );
    if ( ! in_array( $level, $allowed_levels, true ) ) {
        $level = 'info';
    }

    $existing = $order->get_meta( '_barterpay_events', true );
    if ( ! is_array( $existing ) ) {
        $existing = array();
    }

    $existing[] = array(
        'ts'    => time(),
        'type'  => sanitize_key( $type ),
        'level' => $level,
        'meta'  => is_array( $meta ) ? $meta : array(),
    );

    if ( count( $existing ) > 50 ) {
        $existing = array_slice( $existing, -50 );
    }

    $order->update_meta_data( '_barterpay_events', $existing );
    $order->save();
}

/**
 * Legacy wrapper — maps old barterpay_record_order_event calls to the new structured format.
 *
 * @param WC_Order|int|null $order_or_id Order, ID, or null.
 * @param string            $msg         Human-readable message.
 * @param string            $tag         One of: info | success | warn | error.
 */
function barterpay_record_order_event( $order_or_id, $msg, $tag = 'info' ) {
    $level_map = array(
        'info'    => 'info',
        'success' => 'ok',
        'warn'    => 'wait',
        'error'   => 'bad',
    );
    $level = isset( $level_map[ $tag ] ) ? $level_map[ $tag ] : 'info';
    barterpay_record_event( $order_or_id, 'legacy_event', $level, array( 'msg' => $msg ) );
}

/**
 * Normalize an API/callback status string to one of: paid, failed, pending, unknown.
 *
 * @param string $raw Raw status from API or callback.
 * @return string Canonical status.
 */
function barterpay_normalize_api_status( $raw ) {
    $raw = strtolower( trim( (string) $raw ) );

    $success = array( 'success', 'completed', 'complete', 'paid', 'approved', 'authorized', 'settled' );
    $failure = array( 'failed', 'failure', 'cancelled', 'canceled', 'expired', 'declined', 'rejected', 'error' );
    $pending = array( 'pending', 'processing', 'waiting', 'inprogress', 'in_progress', 'init' );

    if ( in_array( $raw, $success, true ) ) {
        return 'paid';
    }
    if ( in_array( $raw, $failure, true ) ) {
        return 'failed';
    }
    if ( in_array( $raw, $pending, true ) ) {
        return 'pending';
    }
    return 'unknown';
}

/**
 * Return the first non-empty value found among the given keys in $arr.
 *
 * @param array $arr  Source array.
 * @param array $keys List of candidate key names, in priority order.
 * @return mixed|null
 */
function barterpay_get_field( $arr, $keys ) {
    if ( ! is_array( $arr ) ) {
        return null;
    }
    foreach ( $keys as $key ) {
        if ( isset( $arr[ $key ] ) && '' !== $arr[ $key ] && null !== $arr[ $key ] ) {
            return $arr[ $key ];
        }
    }
    return null;
}

/**
 * Call the BarterPay check-transaction-status endpoint. Returns parsed JSON or null on failure.
 *
 * @param string                $transaction_index
 * @param WC_Gateway_BarterPay  $gateway
 * @return array|null
 */
function barterpay_call_status_api( $transaction_index, $gateway ) {
    $api_key  = $gateway->get_option( 'api_key' );
    $sandbox  = $gateway->get_option( 'sandbox' );
    $endpoint = 'yes' === $sandbox
        ? 'https://test-api.getbarterpay.com/api/pay/m-api/check-transaction-status'
        : 'https://api.getbarterpay.com/api/pay/m-api/check-transaction-status';

    $url = add_query_arg( 'TransactionIndex', $transaction_index, $endpoint );
    $response = wp_remote_get( $url, array(
        'timeout' => 15,
        'headers' => array( 'X-SAO-Token' => $api_key ),
    ) );

    if ( is_wp_error( $response ) ) {
        barterpay_log_callback( sprintf( 'Status API error: %s', $response->get_error_message() ) );
        return null;
    }

    $body   = wp_remote_retrieve_body( $response );
    $code   = wp_remote_retrieve_response_code( $response );
    $result = json_decode( $body, true );

    if ( ! is_array( $result ) ) {
        barterpay_log_callback( sprintf(
            'Status API: unparseable response (HTTP %d). Body (first 2KB): %s',
            $code,
            substr( $body, 0, 2048 )
        ) );
        return null;
    }

    if ( null === barterpay_get_field( $result, array( 'transactionStatus', 'TransactionStatus', 'status' ) ) ) {
        barterpay_log_callback( sprintf(
            'Status API: no status field. Available keys: %s. Body (first 2KB): %s',
            implode( ', ', array_keys( $result ) ),
            substr( $body, 0, 2048 )
        ) );
    }

    return $result;
}

/**
 * Centralized order resolution. Single place that calls payment_complete() / update_status('failed').
 *
 * Idempotent — if the order is already paid/failed, records a duplicate event and returns
 * 'already_resolved'. Acquires a transient lock so two parallel callers cannot double-process.
 *
 * @param WC_Order $order
 * @param string   $api_status_raw  Raw status string from API or callback.
 * @param float    $api_amount      Amount reported by API/callback.
 * @param string   $source          One of 'callback', 'polling', 'widget_polling', 'server_fallback'.
 * @param string   $api_txn_index   Optional API transaction index for the order note.
 * @return string  One of 'paid', 'failed', 'pending', 'unknown', 'already_resolved'.
 */
function barterpay_finalize_order_payment( $order, $api_status_raw, $api_amount, $source, $api_txn_index = '' ) {
    if ( ! $order instanceof WC_Order ) {
        return 'unknown';
    }

    $order_id = $order->get_id();
    $lock_key = 'barterpay_lock_' . $order_id;

    if ( get_transient( $lock_key ) ) {
        barterpay_log_callback( sprintf( 'Finalize: another caller holds lock for order %d (source=%s)', $order_id, $source ) );
        return 'already_resolved';
    }
    set_transient( $lock_key, 1, 10 );

    try {
        $canonical = barterpay_normalize_api_status( $api_status_raw );

        // Already-resolved short-circuit.
        if ( $order->is_paid() || $order->has_status( 'completed' ) || $order->has_status( 'processing' ) ) {
            if ( 'paid' === $canonical ) {
                barterpay_record_event( $order, 'duplicate_finalize_paid', 'info', array(
                    'source'     => $source,
                    'raw_status' => $api_status_raw,
                ) );
            }
            barterpay_log_callback( sprintf( 'Finalize: order %d already paid (source=%s, raw=%s)', $order_id, $source, $api_status_raw ) );
            return 'already_resolved';
        }
        if ( $order->has_status( 'failed' ) || $order->has_status( 'cancelled' ) ) {
            if ( 'failed' === $canonical ) {
                barterpay_record_event( $order, 'duplicate_finalize_failed', 'info', array(
                    'source'     => $source,
                    'raw_status' => $api_status_raw,
                ) );
            }
            barterpay_log_callback( sprintf( 'Finalize: order %d already failed/cancelled (source=%s, raw=%s)', $order_id, $source, $api_status_raw ) );
            return 'already_resolved';
        }

        if ( 'unknown' === $canonical ) {
            barterpay_record_event( $order, 'unmatched_api_status', 'bad', array(
                'raw_value' => $api_status_raw,
                'source'    => $source,
            ) );
            barterpay_log_callback( sprintf( 'Finalize: unmatched status "%s" for order %d (source=%s)', $api_status_raw, $order_id, $source ) );
            return 'unknown';
        }

        if ( 'pending' === $canonical ) {
            return 'pending';
        }

        if ( 'paid' === $canonical ) {
            $payment_txn_id = ! empty( $api_txn_index ) ? $api_txn_index : $order->get_meta( '_barterpay_api_txn_index', true );
            $previous_status = $order->get_status();
            $order->payment_complete( $payment_txn_id );
            $order->add_order_note( sprintf(
                __( 'Payment of %s confirmed via BarterPay (%s). Transaction Index: %s', 'barterpay' ),
                wc_price( $api_amount, array( 'currency' => $order->get_currency() ) ),
                $source,
                esc_html( $payment_txn_id )
            ) );
            barterpay_record_event( $order, 'payment_complete_called', 'ok', array( 'via' => $source ) );
            barterpay_record_event( $order, 'order_status_changed', 'info', array(
                'from' => $previous_status,
                'to'   => $order->get_status(),
            ) );
            barterpay_log_callback( sprintf( 'Finalize: order %d marked PAID (source=%s)', $order_id, $source ) );
            return 'paid';
        }

        if ( 'failed' === $canonical ) {
            $previous_status = $order->get_status();
            $order->update_status( 'failed', sprintf(
                __( 'Payment %s via BarterPay (%s). Raw status: %s', 'barterpay' ),
                $canonical,
                $source,
                esc_html( $api_status_raw )
            ) );
            barterpay_record_event( $order, 'payment_complete_called', 'bad', array(
                'via'        => $source,
                'raw_status' => $api_status_raw,
            ) );
            barterpay_record_event( $order, 'order_status_changed', 'info', array(
                'from' => $previous_status,
                'to'   => 'failed',
            ) );
            barterpay_log_callback( sprintf( 'Finalize: order %d marked FAILED (source=%s, raw=%s)', $order_id, $source, $api_status_raw ) );
            return 'failed';
        }

        return 'unknown';
    } finally {
        delete_transient( $lock_key );
    }
}

// ============================================================
// Widget Payment Flow — frontend script + AJAX handlers
// ============================================================

/**
 * Enqueue the widget JS on checkout and order-pay pages when widget flow is active.
 */
add_action( 'wp_enqueue_scripts', 'barterpay_enqueue_widget_scripts' );
function barterpay_enqueue_widget_scripts() {
    if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
        return;
    }

    $gateway = new WC_Gateway_BarterPay();
    if ( 'widget' !== $gateway->get_option( 'payment_flow', 'hosted' ) ) {
        return;
    }

    wp_enqueue_script(
        'barterpay-widget',
        plugins_url( 'js/barterpay-widget.js', __FILE__ ),
        array( 'jquery' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'js/barterpay-widget.js' ),
        true
    );

    $gateway = new WC_Gateway_BarterPay();

    wp_localize_script( 'barterpay-widget', 'barterpayWidget', array(
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'barterpay_widget' ),
        'countdownSeconds' => absint( $gateway->get_option( 'signature_countdown_seconds', '30' ) ),
        'siteOrigin'       => home_url(),
        'i18n'    => array(
            'processing'  => __( 'Processing Payment', 'barterpay' ),
            'pleaseWait'  => __( 'Please wait while we process your payment...', 'barterpay' ),
            'approved'    => __( 'Payment approved!', 'barterpay' ),
            'redirectIn'  => __( 'Redirecting you to your order in %d seconds...', 'barterpay' ),
            'declined'    => __( 'Payment Declined', 'barterpay' ),
            'declinedMsg' => __( 'Your payment was declined. Page will reload in 10 seconds.', 'barterpay' ),
            'tryAgain'    => __( 'Click here to try again now.', 'barterpay' ),
        ),
    ) );
}

/**
 * AJAX: Record a client-side widget event into order meta.
 */
add_action( 'wp_ajax_barterpay_record_event', 'barterpay_record_event_ajax' );
add_action( 'wp_ajax_nopriv_barterpay_record_event', 'barterpay_record_event_ajax' );
function barterpay_record_event_ajax() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'barterpay_widget' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }
    $order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    $event_type = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
    $order      = wc_get_order( $order_id );
    if ( $order && $event_type ) {
        barterpay_record_event( $order, 'widget_js_' . $event_type, 'ok', array( 'via' => 'client_ajax' ) );
    }
    wp_send_json_success();
}

/**
 * AJAX: Return the stored BarterPay widget URL and redirect URLs for a widget-flow order.
 */
add_action( 'wp_ajax_barterpay_get_widget_data', 'barterpay_get_widget_data_callback' );
add_action( 'wp_ajax_nopriv_barterpay_get_widget_data', 'barterpay_get_widget_data_callback' );
function barterpay_get_widget_data_callback() {
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'barterpay_widget' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }

    if ( ! isset( $_POST['order_id'] ) ) {
        wp_send_json_error( array( 'message' => 'Missing order_id.' ) );
    }

    $order_id = absint( $_POST['order_id'] );
    $order    = wc_get_order( $order_id );

    if ( ! $order || $order->get_payment_method() !== 'barterpay' ) {
        wp_send_json_error( array( 'message' => 'Invalid order.' ) );
    }

    $widget_url = $order->get_meta( '_barterpay_widget_url', true );
    if ( empty( $widget_url ) ) {
        wp_send_json_error( array( 'message' => 'Widget URL not found for this order.' ) );
    }

    // Build confirmation (success) URL — respects custom redirect setting.
    $gateway = new WC_Gateway_BarterPay();
    if ( 'yes' === $gateway->get_option( 'enable_custom_redirect' ) && ! empty( $gateway->get_option( 'custom_redirect_url' ) ) ) {
        $confirmation_url = str_replace(
            array( '{order_key}', '{order_id}' ),
            array( $order->get_order_key(), $order_id ),
            $gateway->get_option( 'custom_redirect_url' )
        );
    } else {
        $confirmation_url = $order->get_checkout_order_received_url();
    }

    // The WooCommerce "pay for order" URL lets the customer retry on the same order.
    $pay_url = $order->get_checkout_payment_url( true );

    barterpay_log_callback( sprintf( 'Widget: get_widget_data for order %d. Widget URL: %s', $order_id, $widget_url ) );

    wp_send_json_success( array(
        'widget_url'       => $widget_url,
        'confirmation_url' => $confirmation_url,
        'pay_url'          => $pay_url,
    ) );
}

/**
 * AJAX: Poll transaction status for the widget flow (called every 2 seconds by frontend JS).
 * Supports both callback and polling verification methods.
 */
add_action( 'wp_ajax_barterpay_widget_status', 'barterpay_widget_status_callback' );
add_action( 'wp_ajax_nopriv_barterpay_widget_status', 'barterpay_widget_status_callback' );
function barterpay_widget_status_callback() {
    nocache_headers();

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'barterpay_widget' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed.' ) );
    }

    if ( ! isset( $_POST['order_id'] ) ) {
        wp_send_json_error( array( 'message' => 'Missing order_id.' ) );
    }

    $order_id = absint( $_POST['order_id'] );

    // Clear caches so we always read the latest status.
    wp_cache_delete( 'order-' . $order_id, 'orders' );
    wp_cache_delete( $order_id, 'post_meta' );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => 'Invalid order.' ) );
    }

    $gateway        = new WC_Gateway_BarterPay();
    $enable_polling = 'yes' === $gateway->get_option( 'enable_polling', 'yes' );

    barterpay_log_callback( sprintf( 'Widget status poll: order %d, polling=%s', $order_id, $enable_polling ? 'yes' : 'no' ) );

    // If polling is enabled and order not yet resolved, query the API.
    if ( $enable_polling && ! $order->is_paid() && ! $order->has_status( 'failed' ) ) {
        $transaction_index = $order->get_meta( '_barterpay_api_txn_index', true );

        if ( ! empty( $transaction_index ) ) {
            $api_response = barterpay_call_status_api( $transaction_index, $gateway );

            if ( is_array( $api_response ) ) {
                $api_status = (string) barterpay_get_field( $api_response, array( 'transactionStatus', 'TransactionStatus', 'status' ) );
                $api_amount = (float) barterpay_get_field( $api_response, array( 'transactionAmount', 'TransactionAmount', 'amount' ) );

                barterpay_record_event( $order, 'widget_poll_ran', 'ok', array(
                    'last_status' => $api_status,
                    'via'         => 'widget_ajax',
                ) );

                barterpay_finalize_order_payment( $order, $api_status, $api_amount, 'widget_polling', $transaction_index );

                // Reload after finalize.
                $order = wc_get_order( $order_id );
            }
        }
    }

    // Return current order state.
    $order_status = $order->get_status();

    if ( $order->is_paid() || in_array( $order_status, array( 'completed', 'processing' ), true ) ) {
        barterpay_log_callback( sprintf( 'Widget status poll: Order %d is PAID (status: %s).', $order_id, $order_status ) );
        wp_send_json_success( array( 'status' => 'paid' ) );
    } elseif ( in_array( $order_status, array( 'failed', 'cancelled' ), true ) ) {
        barterpay_log_callback( sprintf( 'Widget status poll: Order %d is FAILED (status: %s).', $order_id, $order_status ) );
        wp_send_json_success( array( 'status' => 'failed' ) );
    } else {
        wp_send_json_success( array( 'status' => 'pending' ) );
    }
}
