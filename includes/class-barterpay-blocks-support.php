<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class BarterPay_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Unique identifier for this payment method.
     *
     * @var string
     */
    protected $name = 'barterpay';

    /**
     * Initialize the payment method integration.
     */
    public function initialize() {
        // Custom initialization logic if needed.
    }

    /**
     * Determine if the payment method should be active in the current context.
     *
     * @return bool
     */
    public function is_active() {
        $gateway = new WC_Gateway_BarterPay();
        return $gateway->is_available();
    }

    /**
     * Get the script handles for the payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path       = '/js/barterpay-blocks.js';
        $script_asset_path = dirname( __FILE__, 2 ) . '/js/barterpay-blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => '1.3.0',
            );
        $script_url = plugins_url( $script_path, dirname( __FILE__ ) );

        wp_register_script(
            'barterpay-blocks',
            $script_url,
            array_merge( $script_asset['dependencies'], array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ) ),
            $script_asset['version'],
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'barterpay-blocks', 'barterpay' );
        }

        return array( 'barterpay-blocks' );
    }

    /**
     * Get the payment method configuration.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $gateway = new WC_Gateway_BarterPay();
        return [
            'title'       => $gateway->get_option( 'title' ),
            'description' => $gateway->get_option( 'description' ),
            'supports'    => array_filter( $gateway->supports, function( $feature ) {
                return $feature !== 'block_checkout';
            } ),
        ];
    }
}
