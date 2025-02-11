( function( wc ) {
    const { registerPaymentMethod } = wc.paymentMethods;

    // Define the BarterPay payment method.
    const barterpayPaymentMethod = {
        name: 'barterpay',
        label: 'BarterPay Gateway',
        content: <p>Pay via BarterPay. You will be redirected to complete your payment.</p>,
        edit: <p>Pay via BarterPay. You will be redirected to complete your payment.</p>,
        canMakePayment: () => true, // Add conditions for availability if needed.
        createOrder: ( order ) => Promise.resolve( order ),
        processPayment: ( paymentData ) => {
            // Simulate processing payment.
            return Promise.resolve({ success: true });
        },
    };

    // Register the payment method with WooCommerce Blocks.
    registerPaymentMethod( barterpayPaymentMethod );
} )( window.wcBlocksRegistry );
