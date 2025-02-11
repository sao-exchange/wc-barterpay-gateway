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
        wp_register_script(
            'barterpay-blocks',
            plugins_url('assets/js/barterpay-blocks.js', dirname(__FILE__)),
            ['wc-blocks-registry'],
            '1.0.0',
            true
        );
        return ['barterpay-blocks'];
    }

    /**
     * Get the payment method configuration.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $gateway = new WC_Gateway_BarterPay();
        return [
            'title'       => $gateway->title,
            'description' => $gateway->description,
            'supports'    => $gateway->supports,
        ];
    }
}
