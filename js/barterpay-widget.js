/**
 * BarterPay Widget Payment Flow
 *
 * When the payment_flow setting is "widget", WooCommerce's checkout AJAX returns
 * a hash-only redirect (#barterpay-widget-{orderId}). Setting window.location to a
 * same-page hash does not trigger a page reload, so the hashchange event fires and
 * we open an iframe modal here instead of navigating away.
 *
 * Once the iframe is open we poll the server every 2 seconds:
 *  - success  → close modal, redirect to the order confirmation page
 *  - failed   → close modal, show an inline error, redirect to the order-pay page
 *               so the customer can retry without losing their order
 */
(function ($) {
    'use strict';

    var $overlay        = null;
    var statusInterval  = null;
    var POLL_INTERVAL   = 2000;   // 2 seconds
    var MAX_POLLS       = 150;    // 5 minutes

    /* ---------------------------------------------------------------
     * Modal creation
     * ------------------------------------------------------------- */
    function buildModal() {
        var spinStyle =
            '<style id="bp-widget-spin-style">' +
            '@keyframes bp-spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}' +
            /* Desktop: centred card */
            '#barterpay-widget-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;' +
                'background:rgba(0,0,0,0.75);z-index:999999;align-items:center;justify-content:center;}' +
            '#bp-widget-modal{background:#fff;width:92%;max-width:520px;height:82vh;border-radius:8px;' +
                'display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.3);}' +
            /* Mobile: full-screen, no border-radius, no gap */
            '@media(max-width:600px){' +
                '#barterpay-widget-overlay{align-items:stretch;justify-content:stretch;}' +
                '#bp-widget-modal{width:100%;max-width:100%;height:100%;border-radius:0;box-shadow:none;}' +
            '}' +
            '</style>';

        var html =
            '<div id="barterpay-widget-overlay">' +
                '<div id="bp-widget-modal">' +

                    /* header */
                    '<div style="padding:12px 16px;background:#f7f7f7;border-bottom:1px solid #ddd;' +
                    'display:flex;align-items:center;flex-shrink:0;">' +
                        '<span style="font-weight:600;font-size:14px;flex:1;">' +
                            barterpayWidget.i18n.processing +
                        '</span>' +
                    '</div>' +

                    /* loading spinner (shown while iframe loads) */
                    '<div id="bp-widget-loading" style="display:flex;flex-direction:column;' +
                    'align-items:center;justify-content:center;flex:1;padding:20px;">' +
                        '<div style="border:4px solid #f0f0f0;border-top:4px solid #3498db;' +
                        'border-radius:50%;width:44px;height:44px;' +
                        'animation:bp-spin 0.9s linear infinite;margin-bottom:16px;"></div>' +
                        '<p style="margin:0;color:#555;font-size:14px;">' +
                            barterpayWidget.i18n.pleaseWait +
                        '</p>' +
                    '</div>' +

                    /* iframe */
                    '<iframe id="bp-widget-iframe" ' +
                    'style="flex:1;border:none;display:none;width:100%;" ' +
                    'allow="payment" allowfullscreen></iframe>' +

                '</div>' +
            '</div>';

        if (!$('#bp-widget-spin-style').length) {
            $('head').append(spinStyle);
        }
        $('body').append(html);
        $overlay = $('#barterpay-widget-overlay');
    }

    /* ---------------------------------------------------------------
     * Open / close helpers
     * ------------------------------------------------------------- */
    function openModal(widgetUrl, orderId, confirmationUrl, payUrl) {
        if (!$overlay || !$overlay.length) {
            buildModal();
        }

        // Reset state
        $('#bp-widget-iframe').attr('src', '').hide();
        $('#bp-widget-loading').show();

        $overlay.css('display', 'flex');

        // Load the iframe; hide spinner once content is ready
        $('#bp-widget-iframe')
            .one('load', function () {
                $('#bp-widget-loading').hide();
                $(this).show();
            })
            .attr('src', widgetUrl);

        startPolling(orderId, confirmationUrl, payUrl);
    }

    function closeModal() {
        stopPolling();
        if ($overlay) {
            $overlay.hide();
            $('#bp-widget-iframe').attr('src', '').hide();
            $('#bp-widget-loading').show();
        }
    }

    /* ---------------------------------------------------------------
     * Status polling (every 2 s)
     * ------------------------------------------------------------- */
    function startPolling(orderId, confirmationUrl, payUrl) {
        stopPolling();

        var pollCount = 0;

        statusInterval = setInterval(function () {
            pollCount++;

            if (pollCount >= MAX_POLLS) {
                stopPolling();
                closeModal();
                return;
            }

            $.ajax({
                url:    barterpayWidget.ajaxUrl,
                method: 'POST',
                data: {
                    action:   'barterpay_widget_status',
                    order_id: orderId,
                    nonce:    barterpayWidget.nonce,
                },
                success: function (response) {
                    if (!response || !response.success) { return; }

                    var status = response.data.status;

                    if (status === 'paid') {
                        stopPolling();
                        closeModal();
                        window.location.href = confirmationUrl;
                    } else if (status === 'failed') {
                        stopPolling();
                        closeModal();
                        showDeclinedNotice(payUrl);
                    }
                },
            });
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (statusInterval) {
            clearInterval(statusInterval);
            statusInterval = null;
        }
    }

    /* ---------------------------------------------------------------
     * Inline declined notice + redirect to order-pay for retry
     * ------------------------------------------------------------- */
    function showDeclinedNotice(payUrl) {
        // Remove any earlier notice we may have added
        $('.barterpay-declined-notice').remove();

        var $target = $('form.checkout, form#order_review, .woocommerce');
        var notice  =
            '<div class="barterpay-declined-notice woocommerce-error" ' +
            'style="margin-bottom:20px;">' +
                '<strong>' + barterpayWidget.i18n.declined + ':</strong> ' +
                barterpayWidget.i18n.declinedMsg +
            '</div>';

        $target.first().prepend(notice);
        $('html, body').animate({ scrollTop: 0 }, 300);

        // After a short pause redirect to the "pay for order" page so the customer
        // can click Pay again on the same order without re-adding items to the cart.
        setTimeout(function () {
            window.location.href = payUrl;
        }, 2500);
    }

    /* ---------------------------------------------------------------
     * Hash-change intercept
     *
     * WooCommerce checkout JS does:
     *   window.location = result.redirect;
     *
     * When result.redirect is "#barterpay-widget-123" that sets only the
     * URL hash — no page reload occurs — and the hashchange event fires.
     * ------------------------------------------------------------- */
    $(window).on('hashchange', function () {
        var hash   = window.location.hash;
        var prefix = '#barterpay-widget-';

        if (hash.indexOf(prefix) !== 0) { return; }

        var orderId = hash.substring(prefix.length);
        if (!orderId) { return; }

        // Clean up the hash immediately so a back-button press does not re-trigger
        if (window.history && window.history.replaceState) {
            window.history.replaceState(
                null, '',
                window.location.pathname + window.location.search
            );
        }

        // Fetch the stored widget URL + redirect URLs via AJAX
        $.ajax({
            url:    barterpayWidget.ajaxUrl,
            method: 'POST',
            data: {
                action:   'barterpay_get_widget_data',
                order_id: orderId,
                nonce:    barterpayWidget.nonce,
            },
            success: function (response) {
                if (response && response.success && response.data.widget_url) {
                    openModal(
                        response.data.widget_url,
                        orderId,
                        response.data.confirmation_url,
                        response.data.pay_url
                    );
                }
            },
        });
    });

})(jQuery);
