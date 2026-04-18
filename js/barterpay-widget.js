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
    var MAX_POLLS       = 600;    // 20 minutes
    var currentOrderId  = null;
    var currentConfUrl  = null;

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

                    /* success banner with countdown (shown when payment is approved) */
                    '<div id="bp-widget-success" style="display:none;padding:14px 16px;' +
                    'background:#e7f7ed;border-bottom:1px solid #b6e3c5;color:#1d6b3a;' +
                    'font-size:14px;flex-shrink:0;text-align:center;">' +
                        '<strong>' + barterpayWidget.i18n.approved + '</strong> ' +
                        '<span id="bp-widget-redirect-msg"></span>' +
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
        $('#bp-widget-success').hide();

        $overlay.css('display', 'flex');

        // Load the iframe; hide spinner once content is ready.
        $('#bp-widget-iframe')
            .off('load.bp')
            .on('load.bp', function () {
                $('#bp-widget-loading').hide();
                $(this).show();
            })
            .attr('src', widgetUrl);

        currentOrderId  = orderId;
        currentConfUrl  = confirmationUrl;

        // Start a fast interval that checks whether the iframe has
        // navigated back to the merchant's origin.  While the iframe
        // shows the BarterPay (cross-origin) page, reading
        // contentWindow.location throws — we catch and ignore that.
        // The moment BarterPay redirects back to any page on the
        // merchant domain the read succeeds and we trigger the
        // countdown + redirect.
        startIframeOriginWatch(orderId, confirmationUrl);

        startPolling(orderId, confirmationUrl, payUrl);
    }

    var iframeWatchInterval = null;
    var iframeWentCrossOrigin = false;   // true once we've seen the cross-origin page

    function startIframeOriginWatch(orderId, confirmationUrl) {
        stopIframeOriginWatch();
        iframeWentCrossOrigin = false;

        iframeWatchInterval = setInterval(function () {
            try {
                var iframe = document.getElementById('bp-widget-iframe');
                if (!iframe || !iframe.contentWindow) { return; }

                // This line throws when the iframe is cross-origin.
                var href = iframe.contentWindow.location.href;

                // Ignore about:blank / empty — iframe hasn't navigated yet.
                if (!href || href === 'about:blank') { return; }

                // We can read the URL, so it's same-origin.
                // But we must only act AFTER the iframe was cross-origin first
                // (i.e. BarterPay's page loaded, then redirected back).
                if (!iframeWentCrossOrigin) { return; }

                // Iframe came back to our domain — redirect NOW.
                stopIframeOriginWatch();
                stopPolling();
                recordEvent(orderId, 'iframe_return_detected');
                closeModal();
                window.location.href = confirmationUrl;
            } catch (e) {
                // Cross-origin — BarterPay's domain. Mark that we saw it.
                iframeWentCrossOrigin = true;
            }
        }, 500);
    }

    function stopIframeOriginWatch() {
        if (iframeWatchInterval) {
            clearInterval(iframeWatchInterval);
            iframeWatchInterval = null;
        }
    }

    function closeModal() {
        stopPolling();
        stopIframeOriginWatch();
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
                        var countdown = barterpayWidget.countdownSeconds || 30;
                        showApprovedAndCountdown(confirmationUrl, countdown);
                        recordEvent(orderId, 'polling_paid');
                    } else if (status === 'failed') {
                        stopPolling();
                        showDeclinedNotice();
                        recordEvent(orderId, 'polling_failed');
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
     * Approved banner + visible countdown
     * ------------------------------------------------------------- */
    function showApprovedAndCountdown(confirmationUrl, seconds) {
        var $banner = $('#bp-widget-success');
        var $msg    = $('#bp-widget-redirect-msg');
        var remaining = seconds;

        function render() {
            $msg.text(
                barterpayWidget.i18n.redirectIn.replace('%d', remaining)
            );
        }

        $banner.show();
        render();

        var countdown = setInterval(function () {
            remaining--;
            if (remaining <= 0) {
                clearInterval(countdown);
                closeModal();
                window.location.href = confirmationUrl;
                return;
            }
            render();
        }, 1000);
    }

    /* ---------------------------------------------------------------
     * Inline declined notice + redirect to order-pay for retry
     * ------------------------------------------------------------- */
    function showDeclinedNotice() {
        closeModal();

        // Remove any earlier notice we may have added
        $('.barterpay-declined-notice').remove();

        var tryAgainLabel = barterpayWidget.i18n.tryAgain || 'Click here to try again';
        var $target = $('form.checkout, form#order_review, .woocommerce');
        var notice  =
            '<div class="barterpay-declined-notice woocommerce-error" ' +
            'style="margin-bottom:20px;">' +
                '<strong>' + barterpayWidget.i18n.declined + ':</strong> ' +
                barterpayWidget.i18n.declinedMsg + ' ' +
                '<a href="#" onclick="location.reload();return false;" style="text-decoration:underline;">' +
                    tryAgainLabel +
                '</a>' +
            '</div>';

        $target.first().prepend(notice);
        $('html, body').animate({ scrollTop: 0 }, 300);

        // Auto-reload after 10 seconds so the customer gets a fresh checkout.
        setTimeout(function () { location.reload(); }, 10000);
    }

    /* ---------------------------------------------------------------
     * postMessage listener — BarterPay iframe may post status updates
     * when loaded on the same origin (e.g. via in_iframe=1 return page).
     * ------------------------------------------------------------- */
    $(window).on('message', function (e) {
        var evt = e.originalEvent;
        // Only accept messages from our own site origin.
        if ( !barterpayWidget.siteOrigin || evt.origin !== barterpayWidget.siteOrigin ) {
            return;
        }
        var data = evt.data;
        if ( !data || data.source !== 'barterpay' ) { return; }

        if ( data.event === 'payment_complete' && currentOrderId ) {
            stopPolling();
            var countdown = barterpayWidget.countdownSeconds || 30;
            showApprovedAndCountdown(currentConfUrl, countdown);
            recordEvent(currentOrderId, 'postmessage_paid');
        }
    });

    /* ---------------------------------------------------------------
     * Event recorder — fire-and-forget AJAX to log widget events.
     * ------------------------------------------------------------- */
    function recordEvent(orderId, eventType) {
        $.ajax({
            url:    barterpayWidget.ajaxUrl,
            method: 'POST',
            data: {
                action:     'barterpay_record_event',
                order_id:   orderId,
                event_type: eventType,
                nonce:      barterpayWidget.nonce,
            },
        });
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
