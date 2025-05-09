<?php
/**
 * BarterPay WooCommerce Gateway Blocks Support
 *
 * @package BarterPay
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * BarterPay Blocks integration
 *
 * @since 1.3.1
 */
class BarterPay_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name/id, must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'barterpay'; // This should match your gateway's ID.

    /**
     * Gateway instance.
     *
     * @var WC_Gateway_BarterPay
     */
    private $gateway;

    /**
     * Constructor
     */
    public function __construct() {
        $gateways = WC()->payment_gateways->payment_gateways();
        if (isset($gateways[$this->name])) {
            $this->gateway = $gateways[$this->name];
        } else {
            if (function_exists('barterpay_log')) {
                barterpay_log('Error: Main BarterPay gateway class not found in BarterPay_Blocks_Support constructor.');
            }
        }
    }

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        if ($this->gateway) {
             $this->settings = $this->gateway->settings;
        } else {
            $this->settings = array();
        }
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $main_plugin_file_path = dirname( __FILE__, 2 ) . '/barterpay-gateway.php'; // Ensure this is your main plugin file name.

        $script_path = plugins_url( 'assets/js/frontend/blocks.js', $main_plugin_file_path );
        $script_asset_path = plugin_dir_path( $main_plugin_file_path ) . 'assets/js/frontend/blocks.asset.php';
        
        $script_asset      = file_exists( $script_asset_path )
            ? require( $script_asset_path )
            : array(
                'dependencies' => array('wp-blocks', 'wp-element', 'wp-html-entities', 'wp-i18n', 'wc-blocks-registry', 'wc-settings'),
                'version'      => $this->gateway && method_exists($this->gateway, 'get_plugin_version') ? $this->gateway->get_plugin_version() : '1.3.1', // Updated version
            );

        wp_register_script(
            'wc-barterpay-blocks-integration',
            $script_path,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations(
                'wc-barterpay-blocks-integration',
                'barterpay',
                plugin_dir_path( $main_plugin_file_path ) . 'languages/'
            );
        }

        return array( 'wc-barterpay-blocks-integration' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if (!$this->gateway) {
            return array(
                'title' => __('BarterPay (Error: Not Configured)', 'barterpay'),
                'description' => __('There was an issue loading BarterPay. Please ensure it is configured correctly.', 'barterpay'),
                'supports' => [],
                'iconsrc' => null,
                'staticIconSrc' => null,
            );
        }

        // Define the path to your static logo within the plugin's assets directory.
        $static_logo_path_relative_to_plugin = 'assets/images/barterpay-logo.png';
        $main_plugin_file_path = dirname( __FILE__, 2 ) . '/barterpay-gateway.php';
        $static_icon_url = plugins_url( $static_logo_path_relative_to_plugin, $main_plugin_file_path );

        // Check if the static logo file actually exists to prevent broken image URLs.
        // plugin_dir_path($main_plugin_file_path) gives the filesystem path to the plugin root.
        if ( !file_exists( plugin_dir_path($main_plugin_file_path) . $static_logo_path_relative_to_plugin ) ) {
            $static_icon_url = null;
            if (function_exists('barterpay_log')) {
                barterpay_log('BarterPay static logo not found at: ' . plugin_dir_path($main_plugin_file_path) . $static_logo_path_relative_to_plugin);
            }
        }


        return array(
            'title'       => $this->gateway->get_title(),
            'description' => $this->gateway->get_description(),
            'supports'    => array_filter( $this->gateway->supports, array( $this, 'filter_gateway_supports' ) ),
            'iconsrc'     => $this->gateway->icon ? esc_url($this->gateway->icon) : null,
            'staticIconSrc' => $static_icon_url,
        );
    }

    /**
     * Filters the supported features of the gateway.
     *
     * @param string $feature Feature name.
     * @return bool
     */
    protected function filter_gateway_supports( $feature ) {
        return 'block_checkout' !== $feature;
    }
}
