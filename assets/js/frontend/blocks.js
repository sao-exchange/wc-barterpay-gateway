/**
 * WordPress and WooCommerce Global Variables.
 */
const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { decodeEntities } = wp.htmlEntities;
const { __ } = wp.i18n;
const { createElement, Fragment } = wp.element;

console.log('BarterPay Blocks JS Loaded');

/**
 * Payment method specific "settings" passed from PHP.
 */
const settings = getSetting( 'barterpay_data', {} );
console.log('BarterPay Settings from PHP:', settings);
/**
 * Default title for the payment method.
 */
const defaultTitle = __( 'BarterPay', 'barterpay' );

/**
 * The title for the payment method, decoded to handle any HTML entities.
 */
const title = decodeEntities( settings.title || defaultTitle );

/**
 * Content/description displayed below the payment method title.
 */
const Content = () => {
    const description = decodeEntities( settings.description || '' );
    if (description) {
        return createElement( 'div', { dangerouslySetInnerHTML: { __html: description } } );
    }
    return null;
};

/**
 * Label for the payment method. This can include an icon/logo.
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components; 
    
    console.log('BarterPay Label component props:', props); 
    console.log('BarterPay settings.staticIconSrc:', settings.staticIconSrc); 
    console.log('BarterPay settings.iconsrc (from WC settings):', settings.iconsrc); 

    const iconUrl = settings.staticIconSrc || settings.iconsrc; 
    console.log('BarterPay determined iconUrl:', iconUrl); 

    let iconElement = null;
    if (iconUrl) {
        console.log('BarterPay: Attempting to create icon element with URL:', iconUrl);
        iconElement = createElement( 'img', { 
            src: iconUrl, 
            alt: decodeEntities(title) + ' ' + __( 'logo', 'barterpay' ),
            style: { 
                marginRight: '10px', 
                maxHeight: '24px',
                verticalAlign: 'middle'
            },
            // Add onerror for debugging image load failures
            onerror: function() { console.error('BarterPay: Image failed to load - ' + iconUrl); }
          });
    } else {
        console.log('BarterPay: No iconUrl provided, iconElement will be null.'); 
    }
    
    console.log('BarterPay iconElement created:', iconElement);

    return createElement( PaymentMethodLabel, { 
        text: decodeEntities(title),
        icon: iconElement 
    });
};

/**
 * BarterPay payment method config object.
 */
const barterPayPaymentMethod = {
    name: "barterpay",
    label: createElement( Label, { components: window.wc.blocksCheckout } ), 
    content: createElement( Content, null ),
    edit: createElement( Content, null ),
    canMakePayment: () => true,
    ariaLabel: decodeEntities(title),
    supports: {
        features: settings.supports || [],
    },
};

console.log('BarterPay: Registering payment method object:', barterPayPaymentMethod); 
registerPaymentMethod( barterPayPaymentMethod );
