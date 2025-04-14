<?php
/**
 * Plugin Name: BarterPay WooCommerce Gateway
 * Description: Start accepting BarterPay payments easy way.
 * Version: 1.2
 * Author: BarterPay <info@getbarterpay.com>
 * Requires Plugins: woocommerce
 */

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\OrderStatus;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize the gateway after all plugins are loaded.
 */
add_action( 'plugins_loaded', 'init_barterpay_gateway' );
function init_barterpay_gateway() {

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
            $this->method_description = __( 'Pay via BarterPay. You will be redirected to complete your payment.', 'barterpay' );
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
                $order->update_meta_data( '_barterpay_txn_id', $transaction_id );
                $order->save();
            } else {
                update_post_meta( $order_id, '_barterpay_txn_id', $transaction_id );
            }

            // Prepare the payload.
            $payload = array(
                'TransactionId' => $transaction_id,
                'Currency'      => $this->currency,
                'Amount'        => floatval( $order->get_total() )
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
                        $order->update_meta_data( '_barterpay_txn_index', sanitize_text_field( $result['transactionIndex'] ) );
                        $order->save();
                    } else {
                        update_post_meta( $order_id, '_barterpay_txn_index', sanitize_text_field( $result['transactionIndex'] ) );
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
            ?>
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
            <p><?php _e( 'Payment is processing. Please wait...', 'barterpay' ); ?></p>
            <?php
        }
    }
} // End init_barterpay_gateway

/**
 * Callback handler for BarterPay asynchronous payment notifications.
 *
 * This function reads the callback (via JSON payload or GET parameters),
 * retrieves the related order via its transaction ID stored in meta,
 * and updates the order status accordingly.
 */
add_action( 'init', 'barterpay_callback_handler' );
function barterpay_callback_handler() {
    if ( isset( $_GET['barterpay_callback'] ) ) {

        // Attempt to read JSON payload.
        $input = file_get_contents( 'php://input' );
        $data  = json_decode( $input, true );

        if ( $data && isset( $data['data'] ) ) {
            $callback_data           = $data['data'];
            $external_transaction_id = isset( $callback_data['ExternalTransactionId'] ) ? sanitize_text_field( $callback_data['ExternalTransactionId'] ) : '';
            $transaction_index       = isset( $callback_data['TransactionIndex'] ) ? sanitize_text_field( $callback_data['TransactionIndex'] ) : '';
            $transaction_status      = isset( $callback_data['TransactionStatus'] ) ? sanitize_text_field( $callback_data['TransactionStatus'] ) : '';
            $transaction_amount      = isset( $callback_data['TransactionAmount'] ) ? floatval( $callback_data['TransactionAmount'] ) : 0;
        } elseif ( isset( $_GET['externalTransactionId'] ) ) {
            // Fallback: Data is sent via GET parameters.
            $external_transaction_id = sanitize_text_field( $_GET['externalTransactionId'] );
            $transaction_index       = isset( $_GET['transactionId'] ) ? sanitize_text_field( $_GET['transactionId'] ) : '';
            $transaction_status      = isset( $_GET['transactionStatus'] ) ? sanitize_text_field( $_GET['transactionStatus'] ) : '';
            $transaction_amount      = isset( $_GET['transactionAmount'] ) ? floatval( $_GET['transactionAmount'] ) : 0;
        } else {
            echo 'Invalid callback data';
            exit;
        }

        // Debug: Log the received values.
        error_log( 'Callback ExternalTransactionId: ' . $external_transaction_id );
        error_log( 'Callback TransactionIndex: ' . $transaction_index );

        // Retrieve the order using wc_get_orders (HPOS-compatible) based on our stored transaction ID.
        $orders = wc_get_orders( array(
            'limit'      => 1,
            'meta_key'   => '_barterpay_txn_id',
            'meta_value' => $external_transaction_id,
            'status'     => array( 'wc-pending', 'wc-on-hold', 'wc-draft', 'wc-processing' )
        ) );

        if ( empty( $orders ) ) {
            error_log( 'Order not found for TransactionIndex: ' . $transaction_index . ' or ExternalTransactionId: ' . $external_transaction_id );
            echo 'Order not found';
            exit;
        }

        $order   = $orders[0];
        $order_id = $order->get_id();

        if ( 'success' === $transaction_status ) {
            // Mark the order as paid.
            $order->payment_complete( $external_transaction_id );
            $order->add_order_note( sprintf( __( 'Payment of %s confirmed via BarterPay callback.', 'barterpay' ), wc_price( $transaction_amount ) ) );
        } else {
            // Mark the order as failed.
            $order->update_status( 'failed', __( 'Payment failed via BarterPay callback.', 'barterpay' ) );
        }
        echo 'OK';
        exit;
    }
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
  if ( 'yes' === $this->get_option('enable_logs') ) {
    $log_file = plugin_dir_path( __FILE__ ) . 'barterpay-callback.log';
    $timestamp = date( 'Y-m-d H:i:s' );
    file_put_contents( $log_file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND );
  }

}

/**
 * Check if WooCommerce Blocks is loaded and add support for BarterPay.
 */
add_action( 'woocommerce_blocks_loaded', 'barterpay_load_blocks_support' );
function barterpay_load_blocks_support() {
    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-barterpay-blocks-support.php';
        $barterpay_blocks = new BarterPay_Blocks_Support();
        $barterpay_blocks->initialize();
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
