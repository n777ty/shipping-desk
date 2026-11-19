/**
 * Address suggestions on the classic checkout.
 *
 * Advisory only: this can offer a standardised address and apply it when the
 * customer says yes. It never blocks a checkout, never touches the submit path,
 * and never asks the server about an address the server would refuse to check.
 *
 * The event wiring is bound exactly once. Re-binding on `updated_checkout` — the
 * obvious approach, and the one the plugin this replaces used — adds a handler
 * per refresh, so the third refresh spends three calls per keystroke.
 */
(function ($) {
    'use strict';

    if (typeof ShippingDeskCheckout === 'undefined' || !ShippingDeskCheckout.ajaxUrl) {
        return;
    }

    var config = ShippingDeskCheckout;

    var FIELDS = ['address_1', 'address_2', 'city', 'state', 'postcode', 'country'];

    var SELECTOR = (function () {
        var parts = [];
        $.each(['billing', 'shipping'], function (i, type) {
            $.each(FIELDS, function (j, field) {
                parts.push('#' + type + '_' + field);
            });
        });
        return parts.join(', ');
    }());

    var state = {
        timer: null,
        pending: false,
        applying: false,
        key: '',
        result: null,
        declined: '',
        accepted: '',
        confirmed: null
    };

    /* -- Reading the page -------------------------------------------------- */

    /**
     * Which address will actually ship.
     *
     * Shipping when the customer is sending somewhere else, billing otherwise.
     * Recomputing on every read, rather than at load, is what makes the
     * "ship to a different address" toggle work without re-binding anything.
     */
    function targetType() {
        var $toggle  = $('#ship-to-different-address-checkbox');
        var shipping = $('#shipping_address_1');

        if ($toggle.length && $toggle.is(':checked') && shipping.length && shipping.is(':visible')) {
            return 'shipping';
        }

        if ($('#billing_address_1').length) {
            return 'billing';
        }

        return shipping.length ? 'shipping' : '';
    }

    function fieldValue(type, field) {
        var $field = $('#' + type + '_' + field);

        return $field.length ? String($field.val() || '').replace(/^\s+|\s+$/g, '') : '';
    }

    function readAddress(type) {
        return {
            line1: fieldValue(type, 'address_1'),
            line2: fieldValue(type, 'address_2'),
            town: fieldValue(type, 'city'),
            county: fieldValue(type, 'state'),
            postcode: fieldValue(type, 'postcode'),
            country_code: fieldValue(type, 'country').toUpperCase()
        };
    }

    function isSupportedCountry(code) {
        return $.inArray(code, config.countries) !== -1;
    }

    /**
     * The same "worth asking" test the server applies, so the two cannot
     * disagree about what a complete address is.
     */
    function isComplete(address) {
        if (!address.line1) {
            return false;
        }

        // Five digits, not one. A half-typed ZIP is not a postcode, and asking
        // about it spent a call and raised a panel while the customer was still
        // on the field.
        return address.postcode.replace(/\D/g, '').length >= 5;
    }

    /**
     * Cheap local fingerprint, used only to avoid asking twice about the same
     * address. The authoritative cache is the server's address book.
     */
    function fingerprint(address) {
        return [
            address.line1, address.line2, address.town,
            address.county, address.postcode, address.country_code
        ].join('|').toLowerCase();
    }

    /* -- Undo that survives a reload --------------------------------------- */

    var UNDO_KEY = 'shippingDeskAddressUndo';
    var UNDO_TTL = 30 * 60 * 1000;

    function saveUndo(previous, applied, type) {
        try {
            window.sessionStorage.setItem(UNDO_KEY, JSON.stringify({
                previous: previous,
                applied: applied,
                type: type || '',
                at: new Date().getTime()
            }));
        } catch (e) {
            // Storage can be unavailable (private mode, quota). The in-page Undo
            // still works; only surviving a reload is lost.
        }
    }

    function clearUndo() {
        try {
            window.sessionStorage.removeItem(UNDO_KEY);
        } catch (e) {
            // Nothing to do: the entry simply stays until it ages out.
        }
    }

    function loadUndo() {
        try {
            var raw = window.sessionStorage.getItem(UNDO_KEY);
            if (!raw) {
                return null;
            }

            var saved = JSON.parse(raw);
            if (!saved || !saved.previous || !saved.applied) {
                return null;
            }

            if (new Date().getTime() - Number(saved.at) > UNDO_TTL) {
                clearUndo();
                return null;
            }

            return saved;
        } catch (e) {
            return null;
        }
    }

    /**
     * Bring the Undo back after a reload.
     *
     * The accepted values survive a refresh — WooCommerce keeps the checkout
     * fields — but the previous text lived only in memory, so a reload during a
     * payment retry took away the only way back. Restored only when the fields
     * still match what was applied: if the customer has edited since, the bar
     * would describe something that is no longer on screen.
     */
    function restoreUndo() {
        var saved = loadUndo();
        if (!saved) {
            return;
        }

        // The block it was accepted in, but only while that block is the one on
        // screen. WooCommerce renders the "ship to a different address" checkbox
        // from the store's own setting rather than from what the customer ticked,
        // so a reload can put the destination back to billing and hide the shipping
        // fields — and a bar restored into a hidden block is a bar nobody can see.
        var type = saved.type;
        if (!type || !$('#' + type + '_address_1').is(':visible')) {
            type = targetType();
        }

        if (!type) {
            return;
        }

        if (fingerprint(readAddress(type)) !== fingerprint(saved.applied)) {
            clearUndo();
            return;
        }

        state.confirmed = { previous: saved.previous };
        showConfirmation(saved.previous, type);
    }

    /* -- Asking ------------------------------------------------------------ */

    function schedule() {
        if (state.applying) {
            return;
        }

        // A new edit makes any "address updated" line stale. The remembered undo is
        // only discarded when the address has actually moved away from what was
        // applied: WooCommerce re-renders the fields after update_checkout and fires
        // change on them, which arrives here a moment after accepting, and clearing
        // unconditionally threw the undo away before it could ever survive a reload.
        state.confirmed = null;

        var saved = loadUndo();
        var type  = targetType();

        if (!saved || !type || fingerprint(readAddress(type)) !== fingerprint(saved.applied)) {
            clearUndo();
            $('.shipping-desk-checkout-address.is-accepted').remove();
        }

        window.clearTimeout(state.timer);
        state.timer = window.setTimeout(check, 400);
    }

    function check() {
        var type = targetType();
        if (!type) {
            return;
        }

        var address = readAddress(type);

        if (!isSupportedCountry(address.country_code) || !isComplete(address)) {
            // A stale suggestion, but not the "address updated" line: that records
            // something the customer did, and switching to a shipping address they
            // have not filled in yet is not an edit of the billing one.
            dropStaleSuggestion();
            return;
        }

        var key = fingerprint(address);

        if (key === state.key) {
            if (state.pending) {
                return;
            }
            if (state.result) {
                render(state.result, type);
                return;
            }
        }

        if (key === state.declined || key === state.accepted) {
            return;
        }

        state.key    = key;
        state.pending = true;

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: config.action,
                nonce: config.nonce,
                line1: address.line1,
                line2: address.line2,
                town: address.town,
                county: address.county,
                postcode: address.postcode,
                country_code: address.country_code
            }
        }).done(function (response) {
            state.pending = false;

            if (!response || !response.success || !response.data) {
                return;
            }

            state.result = response.data;
            render(response.data, type);
        }).fail(function () {
            // A failed request is not worth telling the customer about: the
            // address they typed is used as entered. Clearing the key lets the
            // next edit try again.
            state.pending = false;
            state.key = '';
        });
    }

    /* -- Drawing ----------------------------------------------------------- */

    function clearPanel() {
        $('.shipping-desk-checkout-address').remove();
    }

    /**
     * Remove a suggestion that no longer applies, but leave "address updated" alone.
     *
     * The confirmation is the record of something the customer just did. It has to
     * outlive the change events WooCommerce fires when it re-renders the form, and
     * the quiet "nothing to change" answer that follows an accepted address; only
     * an actual edit should remove it.
     */
    function dropStaleSuggestion() {
        $('.shipping-desk-checkout-address').not('.is-accepted').remove();
    }

    function panelAnchor(type) {
        // The field wrapper, not one of its rows. WooCommerce replaces the rows on
        // update_checkout, and anchoring to the postcode row put the panel in one
        // place before a reload and another after it.
        var $wrapper = $('#' + type + '_address_1').closest('.woocommerce-' + type + '-fields__field-wrapper');
        if ($wrapper.length) {
            return $wrapper;
        }

        var $anchor = $('#' + type + '_postcode').closest('.form-row');

        if (!$anchor.length) {
            $anchor = $('#' + type + '_address_1').closest('.form-row');
        }

        return $anchor;
    }

    /**
     * Render an address as text, emphasising the fields that changed.
     *
     * Mirrors Shipping_Desk_Helpers::format_address() — street, then city, state
     * and postcode — so both cards are laid out identically. The emphasis is what
     * makes "6904royce ct" versus "6904 ROYCE CT" visible at a glance; a list of
     * change phrases alone is jargon to a customer, and two near-identical lines
     * look like nothing happened.
     */
    function appendAddress($target, address, changed) {
        function isChanged(field) {
            return $.inArray(field, changed) !== -1;
        }

        function addText(text) {
            if (text) {
                $target.append(document.createTextNode(text));
            }
        }

        function addValue(text, field) {
            if (!text) {
                return;
            }

            if (isChanged(field)) {
                $target.append($('<strong>').text(text));
            } else {
                addText(text);
            }
        }

        var hasStreet = !!(address.line1 || address.line2);
        var hasRegion = !!(address.town || address.county || address.postcode);

        if (address.line1) {
            addValue(address.line1, 'address_line_1');
        }

        if (address.line2) {
            if (address.line1) {
                addText(', ');
            }
            addValue(address.line2, 'address_line_2');
        }

        if (hasStreet && hasRegion) {
            addText(', ');
        }

        if (address.town && address.county) {
            addValue(address.town, 'city_locality');
            addText(', ');
            addValue(address.county, 'state_province');
        } else if (address.town) {
            addValue(address.town, 'city_locality');
        } else if (address.county) {
            addValue(address.county, 'state_province');
        }

        if (address.postcode) {
            if (address.town || address.county) {
                addText(' ');
            }
            addValue(address.postcode, 'postal_code');
        }
    }

    /**
     * One calm line when the verifier could not match the address.
     *
     * No buttons: there is nothing to accept, and the address may be perfectly
     * good — a new build, a rural route, a PO box the database has not caught up
     * with. The point is that the customer hears about it while they can still fix
     * it, rather than the operator discovering it at Get Rates.
     */
    function renderUnconfirmed(result, type) {
        var $anchor = panelAnchor(type);
        if (!$anchor.length) {
            return;
        }

        var $panel = $('<div>', {
            'class': 'shipping-desk-checkout-address is-unconfirmed',
            role: 'status',
            'aria-live': 'polite'
        });

        $panel.append(
            $('<p>', { 'class': 'shipping-desk-checkout-address__title' }).text(config.messages.unconfirmed)
        );

        $anchor.after($panel);
    }

    /**
     * The offer, as two cards: what they typed, and what the verifier matched.
     *
     * The suggested card carries the attention treatment the admin panel already
     * uses for the same decision — a warm border, not a verdict. The customer's
     * own address is never coloured: it is not an error, and red at the payment
     * step reads as one. Every cue is also text ("As you entered" / "Suggested"),
     * so colour is decoration rather than the only signal.
     */
    function render(result, type) {
        if (!result) {
            return;
        }

        // A verifier that could not match the address is the case a customer most
        // needs to hear about — silence there means an undeliverable parcel and a
        // discovery made on the operator's screen days later. It is still only a
        // note: nothing is blocked, there is nothing to accept, and the address may
        // well be right.
        if (result.status === 'invalid') {
            clearPanel();
            renderUnconfirmed(result, type);
            return;
        }

        if (result.status !== 'corrected' || !result.apply) {
            dropStaleSuggestion();
            return;
        }

        if (!result.recommended_line || result.recommended_line === result.original_line) {
            dropStaleSuggestion();
            return;
        }

        clearPanel();

        var $anchor = panelAnchor(type);
        if (!$anchor.length) {
            return;
        }

        var $panel = $('<div>', {
            'class': 'shipping-desk-checkout-address',
            role: 'status',
            'aria-live': 'polite'
        });

        $panel.append(
            $('<p>', { 'class': 'shipping-desk-checkout-address__title' })
                .text(config.messages.title)
        );

        var $compare = $('<div>', { 'class': 'shipping-desk-checkout-address__compare' });

        $compare.append(
            $('<div>', { 'class': 'shipping-desk-checkout-address__original' })
                .append($('<span>', { 'class': 'shipping-desk-checkout-address__label' }).text(config.messages.original))
                .append($('<span>', { 'class': 'shipping-desk-checkout-address__value' }).text(result.original_line || ''))
        );

        var $suggestedValue = $('<span>', { 'class': 'shipping-desk-checkout-address__value' });
        appendAddress($suggestedValue, result.apply || {}, result.changed || []);

        $compare.append(
            $('<div>', { 'class': 'shipping-desk-checkout-address__suggested' })
                .append($('<span>', { 'class': 'shipping-desk-checkout-address__label' }).text(config.messages.suggested))
                .append($suggestedValue)
        );

        $panel.append($compare);

        if (Array.isArray(result.changes) && result.changes.length) {
            var $changes = $('<ul>', { 'class': 'shipping-desk-checkout-address__changes' });

            $.each(result.changes, function (i, phrase) {
                if (phrase) {
                    $changes.append($('<li>').text(phrase));
                }
            });

            if ($changes.children().length) {
                $panel.append($changes);
            }
        }

        var $actions = $('<div>', { 'class': 'shipping-desk-checkout-address__actions' });

        $actions.append(
            $('<button>', { type: 'button', 'class': 'shipping-desk-checkout-address__accept button' })
                .text(config.messages.accept)
                .on('click', function () {
                    accept(result, type);
                })
        );

        $actions.append(
            $('<button>', { type: 'button', 'class': 'shipping-desk-checkout-address__keep' })
                .text(config.messages.keep)
                .on('click', function () {
                    state.declined = fingerprint(readAddress(type));
                    clearPanel();
                })
        );

        $panel.append($actions);
        $anchor.after($panel);
    }

    /* -- Acting ------------------------------------------------------------ */

    function writeAddress(type, address) {
        var map = { line1: 'address_1', line2: 'address_2', town: 'city', county: 'state', postcode: 'postcode' };

        $.each(map, function (from, field) {
            if (typeof address[from] === 'undefined' || address[from] === null) {
                return;
            }

            var $field = $('#' + type + '_' + field);
            if ($field.length) {
                $field.val(address[from]).trigger('change');
            }
        });

        // The country is deliberately never written: verification is US-only, and
        // changing it re-renders the state list, which would wipe the fields that
        // were just written.
    }

    function accept(result, type) {
        var previous = readAddress(type);

        state.applying = true;
        state.accepted = state.key;
        state.confirmed = { previous: previous };

        writeAddress(type, result.apply || {});

        // Snapshot what the fields now hold, not what was asked for: a field the
        // verifier did not return keeps its current value, and the restored Undo
        // compares against the real state after a reload.
        saveUndo(previous, readAddress(type), type);

        window.setTimeout(function () {
            state.applying = false;
        }, 150);

        showConfirmation(previous, type);
        $(document.body).trigger('update_checkout');
    }

    function showConfirmation(previous, type) {
        clearPanel();

        var $anchor = panelAnchor(type);
        if (!$anchor.length) {
            return;
        }

        var $panel = $('<div>', {
            'class': 'shipping-desk-checkout-address is-accepted',
            role: 'status',
            'aria-live': 'polite'
        });

        $panel.append(
            $('<p>', { 'class': 'shipping-desk-checkout-address__title' }).text(config.messages.updated)
        );

        $panel.append(
            $('<button>', { type: 'button', 'class': 'shipping-desk-checkout-address__undo' })
                .text(config.messages.undo)
                .on('click', function () {
                    state.applying = true;
                    state.declined = fingerprint(previous);
                    state.confirmed = null;
                    clearUndo();
                    state.key = '';
                    state.result = null;

                    writeAddress(type, previous);

                    window.setTimeout(function () {
                        state.applying = false;
                    }, 150);

                    clearPanel();
                    $(document.body).trigger('update_checkout');
                })
        );

        $anchor.after($panel);
    }

    /**
     * Put the offer back after WooCommerce re-renders the form.
     *
     * `update_checkout` replaces the field markup, which takes the panel with it.
     * Re-drawing from the answer already held costs nothing; asking again would
     * spend a call for an address the server has just answered.
     */
    function restore() {
        if (state.applying) {
            return;
        }

        var type = targetType();
        if (!type) {
            return;
        }

        // Accepting triggers the re-render that removes the confirmation, so it is
        // drawn again from memory. Without this the Undo disappears in the same
        // moment the customer might want it.
        if (state.confirmed && !$('.shipping-desk-checkout-address.is-accepted').length) {
            showConfirmation(state.confirmed.previous, type);
            return;
        }

        var key = fingerprint(readAddress(type));

        // The destination can change while a panel is on screen: ticking "ship to
        // a different address" leaves a suggestion about the billing address
        // describing somewhere that is no longer shipping anything.
        if (!state.result || key !== state.key || key === state.declined) {
            dropStaleSuggestion();
            return;
        }

        if ($('.shipping-desk-checkout-address').length) {
            return;
        }

        render(state.result, type);
    }

    /* -- Wiring ------------------------------------------------------------ */

    $(function () {
        $(document.body).on('change', SELECTOR, schedule);

        // Deliberately not part of SELECTOR. Ticking it changes which address is the
        // destination, and WooCommerce reveals the shipping fields a moment later,
        // so the re-evaluation waits for them to exist before deciding.
        $(document.body).on('change', '#ship-to-different-address-checkbox', function () {
            window.setTimeout(schedule, 100);
        });

        $(document.body).on('updated_checkout', restore);
        restoreUndo();
    });

}(jQuery));
