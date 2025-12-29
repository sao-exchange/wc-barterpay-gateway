(function() {
    'use strict';
    
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { createElement } = window.wp.element;
    const { decodeEntities } = window.wp.htmlEntities;
    const { getSetting } = window.wc.wcSettings;

    const settings = getSetting( 'barterpay_data', {} );

    const defaultLabel = decodeEntities( settings.title ) || 'BarterPay Gateway';
    const label = createElement(
        'span',
        { style: { width: '100%' } },
        defaultLabel
    );

    const Content = () => {
        return createElement(
            'div',
            null,
            decodeEntities( settings.description || 'Pay via BarterPay. You will be redirected to complete your payment.' )
        );
    };

    const BarterPayPaymentMethod = {
        name: 'barterpay',
        label: label,
        content: createElement( Content ),
        edit: createElement( Content ),
        canMakePayment: () => true,
        ariaLabel: defaultLabel,
        supports: {
            features: settings.supports || ['products'],
        },
    };

    registerPaymentMethod( BarterPayPaymentMethod );
})();
