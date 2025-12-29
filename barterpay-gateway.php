<?php
/**
 * Plugin Name: BarterPay WooCommerce Gateway
 * Description: Start accepting BarterPay payments easy way.
 * Version: 1.3.9
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
            $this->icon               = apply_filters( 'woocommerce_cod_icon', '' );
            $this->method_title       = __( 'BarterPay Gateway', 'barterpay' );
            $this->method_description = __( 'Security verification required: Phone, & Email.
Your transaction today was with Barterpay and your statement will say the same. We accept your BPSK Keys as barter for your order.', 'barterpay' );
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
                    'description' => __( 'Instructions that will be added to the thank you page.', 'barterpay' ),
                    'default'     => __( 'Pay with Cards via BarterPay payments gateway.', 'barterpay' ),
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
                    )
                ),
                'sandbox' => array(
                    'title'   => __( 'Sandbox Mode', 'barterpay' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Sandbox Mode', 'barterpay' ),
                    'default' => 'yes',
                ),
                'enable_logs' => array(
                    'title'       => __( 'Enable Logs', 'barterpay' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable log file generation', 'barterpay' ),
                    'default'     => 'yes',
                ),
                'enable_for_virtual' => array(
                    'title' => __( 'Accept for virtual orders', 'barterpay' ),
                    'label' => __( 'Accept BarterPay, if the order is virtual', 'barterpay' ),
                    'type'  => 'checkbox',
                    'default' => 'yes',
                ),
                'enable_custom_redirect' => array(
                    'title'   => __( 'Enable Custom Redirect', 'barterpay' ),
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

            // Send the request.
            $response = wp_remote_post( $endpoint, $args );

            if ( is_wp_error( $response ) ) {
                wc_add_notice( __( 'Payment error: Unable to connect to BarterPay.', 'barterpay' ), 'error' );
                return;
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
                // Optionally, update the order status to on-hold until the callback confirms the payment.
                $order->update_status( 'on-hold', __( 'Awaiting BarterPay payment confirmation.', 'barterpay' ) );
                return array(
                    'result'   => 'success',
                    'redirect' => $result['redirectUrl']
                );
            } else {
                wc_add_notice( __( 'Payment error: Invalid response from BarterPay.', 'barterpay' ), 'error' );
                return;
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
                        var checkStatusInterval = setInterval(function () {
                            $.ajax({
                                url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                                method: 'POST',
                                data: {
                                    action: 'barterpay_check_status',
                                    order_id: '<?php echo $order_id; ?>'
                                },
                                success: function (response) {
                                    if (response.success && response.data.status === 'paid') {
                                        clearInterval(checkStatusInterval);
                                        window.location.href = '<?php echo esc_js( $redirect_url ); ?>';
                                    }
                                },
                                error: function () {
                                    // Optionally handle any errors.
                                }
                            });
                        }, 1000); // Poll every 1 second.
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
                        var checkStatusInterval = setInterval(function () {
                            $.ajax({
                                url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                                method: 'POST',
                                data: {
                                    action: 'barterpay_check_status',
                                    order_id: '<?php echo $order_id; ?>'
                                },
                                success: function (response) {
                                    if (response.success && response.data.status === 'paid') {
                                        clearInterval(checkStatusInterval);
                                        // Reload the page so that the updated order status is reflected.
                                        location.reload();
                                    }
                                },
                                error: function () {
                                    // Optionally handle any errors.
                                }
                            });
                        }, 1000); // Poll every 1 second.
                    });
                </script>
                <?php
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
        
        // Get the gateway instance to check custom redirect settings
        $gateway = new WC_Gateway_BarterPay();
        
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
    barterpay_log_callback( 'Callback handler initiated. Method: ' . sanitize_text_field($_SERVER['REQUEST_METHOD']) . 
                   '. GET: ' . print_r($_GET, true) . 
                   '. POST: ' . print_r($_POST, true) );

    $raw_input = file_get_contents( 'php://input' );
    barterpay_log_callback( 'Raw callback payload (php://input): ' . $raw_input );
    $data_from_json_payload  = json_decode( $raw_input, true );

    $external_transaction_id = '';
    $api_transaction_index   = '';
    $transaction_status      = '';
    $transaction_amount      = 0;

    if ( $data_from_json_payload && isset( $data_from_json_payload['data'] ) && is_array($data_from_json_payload['data']) ) {
        $callback_data           = $data_from_json_payload['data'];
        $external_transaction_id = isset( $callback_data['ExternalTransactionId'] ) ? sanitize_text_field( $callback_data['ExternalTransactionId'] ) : '';
        $api_transaction_index   = isset( $callback_data['TransactionIndex'] ) ? sanitize_text_field( $callback_data['TransactionIndex'] ) : '';
        $transaction_status      = isset( $callback_data['TransactionStatus'] ) ? strtolower( sanitize_text_field( $callback_data['TransactionStatus'] ) ) : '';
        $transaction_amount      = isset( $callback_data['TransactionAmount'] ) ? floatval( $callback_data['TransactionAmount'] ) : 0;
        barterpay_log_callback( 'Callback data parsed from JSON payload.' );
    } 
    elseif ( isset( $_GET['externalTransactionId'] ) ) { 
        barterpay_log_callback( 'Callback: No valid JSON payload found or "data" key missing. Attempting to use GET parameters.' );
        $external_transaction_id = sanitize_text_field( $_GET['externalTransactionId'] );
        $api_transaction_index   = isset( $_GET['transactionId'] ) ? sanitize_text_field( $_GET['transactionId'] ) : ''; // Assuming 'transactionId' in GET is the API index
        $transaction_status      = isset( $_GET['transactionStatus'] ) ? strtolower( sanitize_text_field( $_GET['transactionStatus'] ) ) : '';
        $transaction_amount      = isset( $_GET['transactionAmount'] ) ? floatval( $_GET['transactionAmount'] ) : 0;
    } else {
        barterpay_log_callback( 'Callback Error: Invalid or empty data. Neither valid JSON payload with "data" key nor "externalTransactionId" in GET parameters found.' );
        echo 'ERROR: Invalid callback data';
        exit; 
    }

    barterpay_log_callback( sprintf(
        'Callback Data Parsed: ExternalTransactionId: %s, ApiTransactionIndex: %s, Status: %s, Amount: %s',
        esc_html($external_transaction_id), esc_html($api_transaction_index), esc_html($transaction_status), esc_html($transaction_amount)
    ) );

    if ( empty( $external_transaction_id ) ) {
        barterpay_log_callback( 'Callback Error: ExternalTransactionId is missing after parsing attempts.' );
        echo 'ERROR: ExternalTransactionId missing';
        exit;
    }

    $orders = wc_get_orders( array(
        'limit'      => 1,
        'meta_key'   => '_barterpay_external_txn_id', 
        'meta_value' => $external_transaction_id,
        'status'     => array( 'wc-on-hold', 'wc-pending' )
    ) );

    if ( empty( $orders ) ) {
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_barterpay_external_txn_id', // Old key
            'meta_value' => $external_transaction_id,
            'status'     => array( 'wc-on-hold', 'wc-pending' )
        ));
        if ( !empty($orders) ) { 
            barterpay_log_callback( sprintf('Order found using legacy _barterpay_external_txn_id for ExternalTransactionId: %s', esc_html($external_transaction_id)) );
        }
    }

    if ( empty( $orders ) ) {
        barterpay_log_callback( sprintf( 'Callback Error: Order not found for ExternalTransactionId: %s. Searched states: on-hold, pending.', esc_html($external_transaction_id) ) );
        status_header(200); 
        echo 'OK: Order not found or already processed'; 
        exit;
    }

    $order = $orders[0];
    $order_id = $order->get_id();
    barterpay_log_callback( sprintf( 'Order %d found for ExternalTransactionId: %s.', $order_id, esc_html($external_transaction_id) ) );

    $stored_api_txn_index = $order->get_meta( '_barterpay_api_txn_index', true );
    if (empty($stored_api_txn_index)) { // Fallback for old key
        $stored_api_txn_index = $order->get_meta( '_barterpay_txn_index', true );
    }

    if ( !empty( $api_transaction_index ) && !empty( $stored_api_txn_index ) && $stored_api_txn_index !== $api_transaction_index ) {
        barterpay_log_callback( sprintf(
            "Callback Warning for Order %d: API TransactionIndex mismatch. Received: '%s', Stored: '%s'. ExternalTransactionId: %s. Processing cautiously.",
            $order_id, esc_html($api_transaction_index), esc_html($stored_api_txn_index), esc_html($external_transaction_id)
        ));
    }

    if ( ($order->is_paid() || $order->has_status( 'completed' ) || $order->has_status( 'processing' )) && 'success' === $transaction_status ) {
        barterpay_log_callback( sprintf( 'Order %d already processed as paid. Current status: %s. Callback status: %s. Ignoring duplicate success callback.', $order_id, $order->get_status(), esc_html($transaction_status) ) );
        echo 'OK: Already processed as paid'; 
        exit;
    }
     if ( $order->has_status( 'failed' ) && in_array($transaction_status, array('failed', 'cancelled', 'expired'), true) ) {
        barterpay_log_callback( sprintf( 'Order %d already marked as failed. Current status: %s. Callback status: %s. Ignoring duplicate failure/cancelled/expired callback.', $order_id, $order->get_status(), esc_html($transaction_status) ) );
        echo 'OK: Already processed as failed';
        exit;
    }

    if ( 'success' === $transaction_status ) {
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
        barterpay_log_callback( sprintf( 'Order %d payment completed. Amount: %s. API Index: %s.', $order_id, esc_html($transaction_amount), esc_html($api_transaction_index) ) );
    } elseif ( in_array($transaction_status, array('failed', 'cancelled', 'expired'), true) ) {
        $order->update_status( 'failed', sprintf( __( 'Payment %s via BarterPay callback. Status: %s. API Index: %s.', 'barterpay' ), $transaction_status, $transaction_status, esc_html($api_transaction_index) ) );
        barterpay_log_callback( sprintf( 'Order %d payment %s. Status: %s. API Index: %s.', $order_id, $transaction_status, esc_html($transaction_status), esc_html($api_transaction_index) ) );
    } else {
        barterpay_log_callback( sprintf( 'Order %d received unknown payment status via callback: %s. API Index: %s. No action taken.', $order_id, esc_html($transaction_status), esc_html($api_transaction_index) ) );
    }

    // Get the gateway instance to access settings.
    $gateway = new WC_Gateway_BarterPay();

    if ( 'yes' === $gateway->get_option( 'enable_custom_redirect' ) && ! empty( $gateway->get_option( 'custom_redirect_url' ) ) ) {
        $redirect_url = str_replace(
            array( '{order_key}', '{order_id}' ),
            array( $order->get_order_key(), $order->get_id() ),
            $gateway->get_option( 'custom_redirect_url' )
        );
        barterpay_log_callback( 'Redirecting to custom URL: ' . $redirect_url );
        wp_redirect( $redirect_url );
    } else {
        $redirect_url = $order->get_checkout_order_received_url();
        barterpay_log_callback( 'Redirecting to standard thank you page: ' . $redirect_url );
        // Redirect the user to the thank you page.
        wp_redirect( $redirect_url );
    }
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
    if ( ! isset( $_POST['order_id'] ) ) {
        wp_send_json_error( array( 'message' => 'No order id provided' ) );
    }

    $order_id = absint( $_POST['order_id'] );
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        wp_send_json_error( array( 'message' => 'Invalid order id' ) );
    }

    if ( $order->is_paid() ) {
        wp_send_json_success( array( 'status' => 'paid' ) );
    } else {
        wp_send_json_success( array( 'status' => 'pending' ) );
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
 * Activation hook to flush rewrite rules.
 */
register_activation_hook( __FILE__, 'barterpay_activate' );
function barterpay_activate() {
    barterpay_add_rewrite_rules();
    flush_rewrite_rules();
}

/**
 * Deactivation hook to flush rewrite rules.
 */
register_deactivation_hook( __FILE__, 'barterpay_deactivate' );
function barterpay_deactivate() {
    flush_rewrite_rules();
}
