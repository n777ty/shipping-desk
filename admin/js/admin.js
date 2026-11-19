/* global jQuery, ShippingDesk */
(function ($) {
    'use strict';

    function getBoxData($row) {
        var $sel = $row.find('.shipping-desk-box-select');
        if (!$sel.val()) return null;
        var opt = $sel.find('option:selected');
        return {
            length: parseFloat(opt.data('length')) || 0,
            width: parseFloat(opt.data('width')) || 0,
            height: parseFloat(opt.data('height')) || 0,
            weight: parseFloat(opt.data('weight')) || 0
        };
    }

    function syncSelectAll($table) {
        var $boxes = $table.find('.shipping-desk-order-select');
        var selected = $boxes.filter(':checked').length;
        var total = $boxes.length;
        $table.find('.shipping-desk-select-all')
            .prop('checked', total > 0 && selected === total)
            .prop('indeterminate', selected > 0 && selected < total);
    }

    $(document).on('change', '.shipping-desk-select-all', function () {
        var $table = $(this).closest('table');
        $table.find('.shipping-desk-order-select').prop('checked', this.checked);
        $(this).prop('indeterminate', false);
    });

    $(document).on('change', '.shipping-desk-order-select', function () {
        syncSelectAll($(this).closest('table'));
    });

    function getVasState($row) {
        if (!$row.data('vas')) {
            $row.data('vas', { select: {}, number: {} });
        }
        return $row.data('vas');
    }

    /**
     * Default value for a select-type VAS group: the $0 option, preferring an
     * explicit "no confirmation" style entry so behaviour is deterministic
     * rather than dependent on array order.
     */
    function defaultVasValue(opt) {
        if (!opt || opt.type !== 'select' || !opt.values || !opt.values.length) return null;
        var free = [], explicitNone = null;
        opt.values.forEach(function (v) {
            if (!v) return;
            if (parseFloat(v.price) !== 0) return;
            free.push(v);
            if (!explicitNone && /^no\b/i.test(String(v.label || ''))) explicitNone = v;
        });
        return explicitNone || (free.length ? free[0] : null);
    }

    function vasPrice(opt, value) {
        if (!opt || opt.type !== 'select' || !opt.values) return 0;
        for (var i = 0; i < opt.values.length; i++) {
            if (opt.values[i] && opt.values[i].value === value) {
                return parseFloat(opt.values[i].price) || 0;
            }
        }
        return 0;
    }

    function buildVasFields(rate, vasState) {
        var fields = {};
        if (!rate || !rate.shipping_service_options || !rate.shipping_service_options.length) {
            return fields;
        }
        rate.shipping_service_options.forEach(function (opt) {
            if (!opt || !opt.key) return;
            if (opt.type === 'select') {
                var picked = vasState && vasState.select ? vasState.select[opt.key] : null;
                if (picked === undefined || picked === null) {
                    var def = defaultVasValue(opt);
                    picked = def ? def.value : null;
                }
                if (picked) fields[opt.key] = picked;
            } else if (opt.type === 'number') {
                var amt = vasState && vasState.number ? parseFloat(vasState.number[opt.key]) : NaN;
                fields[opt.key] = isNaN(amt) ? 0 : amt;
            }
        });
        return fields;
    }

    function resetServices($row, message) {
        $row.find('.shipping-desk-services').html(message ? '<em>' + message + '</em>' : '');
    }

    function renderServices($row, rate) {
        var $cell = $row.find('.shipping-desk-services');
        $cell.html('');

        if (!rate || !rate.shipping_service_options || !rate.shipping_service_options.length) {
            $cell.html('<em>None available</em>');
            return;
        }

        var state  = getVasState($row);
        var orderId = $row.data('order-id');

        rate.shipping_service_options.forEach(function (opt, idx) {
            if (!opt || !opt.label || !opt.key) return;

            if (opt.type === 'select' && opt.values && opt.values.length) {
                var $group = $('<div>', { 'class': 'shipping-desk-service-group' });
                $group.append($('<label>', {
                    'class': 'shipping-desk-service-name',
                    for: 'vas-' + orderId + '-' + idx,
                    text: opt.label
                }));

                var current = state.select[opt.key];
                if (current === undefined || current === null) {
                    var def = defaultVasValue(opt);
                    current = def ? def.value : null;
                }
                state.select[opt.key] = current;

                var $sel = $('<select>', {
                    'class': 'shipping-desk-service-select',
                    id: 'vas-' + orderId + '-' + idx
                });
                opt.values.forEach(function (v) {
                    if (!v) return;
                    var p = parseFloat(v.price) || 0;
                    var label = v.label + (p > 0 ? ' (+$' + p.toFixed(2) + ')' : ' (free)');
                    $sel.append($('<option>', { value: v.value, text: label }));
                    if (v.value === current) $sel.val(v.value);
                });
                $sel.data('vas-key', opt.key);
                $group.append($sel);
                $cell.append($group);
            } else if (opt.type === 'number') {
                // Insurance is rendered once, below the service dropdowns, and
                // sent with the rate request, so it is not rendered here.
                return;
            } else {
                var $plain = $('<div>', { 'class': 'shipping-desk-service-group' });
                $plain.append($('<span>', { 'class': 'shipping-desk-service-name', text: opt.label }));
                $plain.append($('<span>', {
                    'class': 'shipping-desk-service-cost',
                    text: 'Not configurable'
                }));
                $cell.append($plain);
            }
        });

        if (!$cell.children().length) {
            $cell.html('<em>None available</em>');
        }
    }

    function syncServices($row) {
        var rate = $row.data('selected-rate');
        if (rate) {
            renderServices($row, rate);
        } else {
            resetServices($row, 'Select a rate');
        }
    }

    /** Selected rate total plus any priced VAS selections. */
    function computedRateTotal($row) {
        var rate = $row.data('selected-rate');
        if (!rate) return null;
        var base = parseFloat(rate.total_charge);
        if (isNaN(base)) base = 0;

        var state = getVasState($row);
        var extra = 0;
        if (rate.shipping_service_options) {
            rate.shipping_service_options.forEach(function (opt) {
                if (!opt || !opt.key || opt.type !== 'select') return;
                extra += vasPrice(opt, state.select[opt.key]);
            });
        }
        return { base: base, extra: extra, total: base + extra };
    }

    /**
     * EasyPost's insurance premium for the value in the Insurance field, in dollars.
     *
     * EasyPost charges one cent per dollar of declared value with a $1.00 minimum, and
     * rounds half up: $249.49 insures for $2.49, $249.50 for $2.50. Whole dollars are
     * counted directly (Math.round(amount) *is* the cent count) so floating-point cents
     * cannot produce 2.49 where EasyPost will charge 2.50.
     *
     * Returns 0 for providers that price cover into the rate (Shippo) or quote it after
     * the rates (Veeqo): for those there is nothing to add here.
     */
    function insurancePremium($row) {
        if (($row.attr('data-provider') || '').toLowerCase() !== 'easypost') {
            return 0;
        }

        var amount = parseFloat($row.find('.shipping-desk-liability-amount').val());
        if (isNaN(amount) || amount <= 0) {
            return 0;
        }

        return Math.max(1, Math.round(amount) / 100);
    }

    /**
     * Say what the Insurance value costs, next to the field itself.
     *
     * The rate column only shows a premium once rates exist, and a blank field means no
     * cover - which looks identical to the feature being broken. This is the line that
     * distinguishes the two, before any quote is fetched.
     */
    function refreshLiabilityEstimate($row) {
        var $out = $row.find('.shipping-desk-liability-estimate');
        if (!$out.length) return;

        var provider = ($row.attr('data-provider') || '').toLowerCase();
        var premium = insurancePremium($row);

        if (premium > 0) {
            $out.text('~$' + premium.toFixed(2) + ' premium');
        } else if (provider === 'easypost') {
            $out.text('No insurance');
        } else if (provider === 'shippo') {
            $out.text('Cover is priced into the rate');
        } else {
            $out.text('');
        }
    }

    /**
     * Rate price, plus anything priced outside the quote.
     *
     * Veeqo prices services after the quotes; EasyPost prices insurance at purchase, so
     * its premium is an estimate added here. Both are shown as a breakdown against the
     * carrier's own base, so the number the operator sees is what the label will cost.
     */
    function refreshRateTotals($row) {
        var $rates = $row.find('.shipping-desk-rate-choice');
        if (!$rates.length) {
            $row.find('.shipping-desk-rate-price').text('');
            return;
        }

        // The premium is the same whichever carrier is chosen - it depends only on the
        // declared value - so every rate row shows it and the rows stay comparable.
        var premium = insurancePremium($row);

        // Recorded on the row so the decision can be inspected rather than guessed at:
        // "no estimate" looks identical whether the provider prices cover into the rate
        // or the Insurance field was empty. Read with
        // jQuery('tr[data-order-id=N]').attr('data-insurance-premium').
        $row.attr('data-insurance-premium', premium.toFixed(2));
        var selected = computedRateTotal($row);

        $rates.each(function () {
            var $radio = $(this);
            var rate = $radio.data('rate') || {};
            var base = parseFloat(rate.total_charge);
            var extra = 0;

            if (this.checked && selected) {
                base = selected.base;
                extra = selected.extra;
            }

            var $price = $radio.closest('.shipping-desk-rate-option').find('.shipping-desk-rate-price');
            if (isNaN(base)) {
                $price.text('');
                return;
            }

            var parts = [];
            if (extra > 0) {
                parts.push('$' + extra.toFixed(2));
            }
            if (premium > 0) {
                // "~$2.50 ins." keeps the column narrow: the tilde is the whole
                // "estimate" caveat, and every operator reads ins. as insurance.
                parts.push('~$' + premium.toFixed(2) + ' ins.');
            }

            var text = '$' + (base + extra + premium).toFixed(2);
            if (parts.length) {
                text += ' ($' + base.toFixed(2) + ' + ' + parts.join(' + ') + ')';
            }
            $price.text(text);
        });
    }

    function updateTotalWeight($row) {
        var itemsWeight = parseFloat($row.data('items-weight')) || 0;
        var box = getBoxData($row);
        var boxWeight = box ? box.weight : 0;
        var totalWeight = itemsWeight + boxWeight;
        $row.find('.shipping-desk-total-weight').val(totalWeight.toFixed(2));
        return totalWeight;
    }

    function resetRates($row, message) {
        $row.removeData('rates');
        $row.removeData('selected-rate');
        $row.find('.shipping-desk-buy-label').prop('disabled', true);
        $row.find('.shipping-desk-rates-container').text(message);
        resetServices($row, 'Get rates first');
    }

    function renderRates($row, rates, matchedRateId) {
        var $container = $row.find('.shipping-desk-rates-container');
        $container.empty();

        if (!rates.length) {
            resetRates($row, 'No rates returned.');
            return;
        }

        var selectedIndex = 0;
        var matchedIndex = -1;
        if (matchedRateId !== null && typeof matchedRateId !== 'undefined') {
            for (var i = 0; i < rates.length; i++) {
                if (String(rates[i].rate_id) === String(matchedRateId)) {
                    selectedIndex = i;
                    matchedIndex = i;
                    break;
                }
            }
        }

        $row.data('rates', rates);
        $row.data('selected-rate', rates[selectedIndex]);
        var orderId = $row.data('order-id');

        rates.forEach(function (rate, index) {
            var service = rate.service_name || rate.rate_id || 'Unnamed rate';
            var price = parseFloat(rate.total_charge);
            var priceText = isNaN(price) ? (rate.total_charge ? String(rate.total_charge) : '') : '$' + price.toFixed(2);

            var $radio = $('<input>', {
                type: 'radio',
                'class': 'shipping-desk-rate-choice',
                name: 'shipping_desk_rate_' + orderId,
                value: rate.rate_id || ''
            });
            $radio.data('rate', rate);
            $radio.prop('checked', index === selectedIndex);

            var $details = $('<span>', { 'class': 'shipping-desk-rate-details' });
            var $primary = $('<span>', { 'class': 'shipping-desk-rate-primary' });
            $primary.append($('<strong>', {
                'class': 'shipping-desk-rate-service',
                text: service,
                title: service
            }));
            if (priceText) {
                $primary.append($('<span>', { 'class': 'shipping-desk-rate-price', text: priceText }));
            }
            $details.append($primary);
            if (rate.delivery_estimate) {
                var delivery = new Date(rate.delivery_estimate);
                if (!isNaN(delivery.getTime())) {
                    $details.append($('<span>', {
                        'class': 'shipping-desk-rate-delivery',
                        text: 'Estimated delivery: ' + delivery.toLocaleDateString()
                    }));
                }
            }
            /*
             * Shippo reports transit as a duration of days, not a delivery date, so
             * show it as a duration rather than inventing a date. duration_terms is
             * the carrier's own wording ("Delivery in 1 to 3 business days") and is
             * used as the tooltip, or as the text when no day count came back.
             */
            var transitDays = parseInt(rate.estimated_days, 10);
            if (!isNaN(transitDays) && transitDays > 0) {
                $details.append($('<span>', {
                    'class': 'shipping-desk-rate-delivery',
                    text: 'Est. ' + transitDays + (transitDays === 1 ? ' day' : ' days'),
                    title: rate.duration_terms || 'Average transit time as given by the carrier; not guaranteed.'
                }));
            } else if (rate.duration_terms) {
                $details.append($('<span>', {
                    'class': 'shipping-desk-rate-delivery',
                    text: String(rate.duration_terms)
                }));
            }
            if (index === matchedIndex) {
                $details.append($('<span>', {
                    'class': 'shipping-desk-rate-match',
                    text: 'Best match'
                }));
            }

            var $label = $('<label>', { 'class': 'shipping-desk-rate-option' });
            $label.append($radio, $details);
            $container.append($label);
        });

        $row.find('.shipping-desk-buy-label').prop('disabled', false);
        syncServices($row);

        /*
         * Rates arrive priced by the carrier alone. EasyPost charges cover at purchase,
         * so its premium exists only in this column — and the Insurance field cannot
         * supply it: the value is normally typed *before* Get Rates, so no change event
         * follows the quote (and the handler ignores a value equal to the one used for
         * the rate run). Restate every row here, premium included, or the operator sees
         * a total that the label will exceed.
         */
        refreshRateTotals($row);
    }

    $(document).on('change', '.shipping-desk-service-select', function () {
        var $row = $(this).closest('tr');
        var state = getVasState($row);
        state.select[$(this).data('vas-key')] = $(this).val();
        refreshRateTotals($row);
    });

    $(document).on('change input', '.shipping-desk-service-amount', function () {
        var $row = $(this).closest('tr');
        var state = getVasState($row);
        state.number[$(this).data('vas-key')] = $(this).val();
    });

    $(document).on('change', '.shipping-desk-box-select', function () {
        var $row = $(this).closest('tr');
        updateTotalWeight($row);
        resetRates($row, 'Box changed. Get rates to refresh quotes.');
    });

    $(document).on('change', '.shipping-desk-total-weight', function () {
        resetRates($(this).closest('tr'), 'Weight changed. Get rates to refresh quotes.');
    });

    /*
     * EasyPost's premium is displayed, not quoted, so it can follow the typing instead of
     * waiting for the field to lose focus. The change handler below stays authoritative:
     * it is the one that decides whether quotes survive. For Shippo and Veeqo nothing
     * happens here - their quotes depend on the value, so resetting them mid-keystroke
     * would clear rates on every digit.
     */
    $(document).on('input', '.shipping-desk-liability-amount', function () {
        var $row = $(this).closest('tr');
        refreshLiabilityEstimate($row);
        if (($row.attr('data-provider') || '').toLowerCase() !== 'easypost') return;
        if (!$row.data('rates')) return;
        refreshRateTotals($row);
    });

    /**
     * Insurance pricing differs by provider, so the reaction to a change differs too.
     *
     * Shippo prices XCover into the quote, so a changed value invalidates the rates (the
     * same rule as box and weight). EasyPost prices cover at purchase, so its quotes are
     * unaffected by it: keep them, and just restate the combined price rather than
     * throwing away quotes the operator is looking at. Nothing happens when the value
     * matches the one used for the last rate run.
     */
    $(document).on('change', '.shipping-desk-liability-amount', function () {
        var $row = $(this).closest('tr');
        refreshLiabilityEstimate($row);
        if (!$row.data('rates')) return;

        var current = parseFloat($(this).val());
        if (isNaN(current)) current = 0;
        var applied = parseFloat($row.data('liability-applied'));
        if (isNaN(applied)) applied = 0;

        if (current === applied) return;

        if (($row.attr('data-provider') || '').toLowerCase() === 'easypost') {
            $row.data('liability-applied', current);
            refreshRateTotals($row);
            return;
        }

        resetRates($row, 'Insurance changed. Get rates to refresh quotes.');
    });

    /**
     * Signature confirmation is priced into the rate (Shippo and EasyPost both),
     * so it is chosen before getting rates and changing it invalidates any loaded
     * quotes (same rule as insurance). The select is rendered for those providers
     * only; Veeqo prices the same choice after the quotes come back.
     */
    $(document).on('change', '.shipping-desk-signature-confirmation', function () {
        resetRates($(this).closest('tr'), 'Signature changed. Get rates to refresh quotes.');
    });

    $(document).on('change', '.shipping-desk-rate-choice', function () {
        if (!this.checked) return;
        var $row = $(this).closest('tr');
        // VAS selections belong to a specific rate; reset when the rate changes.
        $row.removeData('vas');
        $row.data('selected-rate', $(this).data('rate'));
        $row.find('.shipping-desk-buy-label').prop('disabled', false);
        syncServices($row);
        refreshRateTotals($row);
    });

    /**
     * Message for a failed AJAX request.
     *
     * The plugin answers errors with an explicit 5xx status so proxies and the
     * browser treat them as failures — which routes the response to .fail(). The
     * JSON body still carries the real reason, so read it rather than guessing.
     */
    function ajaxErrorMessage(xhr, fallback) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        if (xhr && xhr.status) {
            return fallback + ' (HTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '') + ')';
        }
        return fallback;
    }

    /**
     * The status line beside the provider control.
     *
     * It sits in the header's controls row, not inside the switch: the switch is now a
     * pill-shaped track, and a message inside it would stretch the pill. The switch itself
     * is still the fallback, so markup from before that move keeps working.
     *
     * @param {jQuery} $el Any element inside the switch.
     * @return {jQuery}
     */
    function providerMessage($el) {
        var $row = $el.closest('.shipping-desk-controls').find('.shipping-desk-provider-msg');
        return $row.length ? $row : $el.closest('.shipping-desk-provider-switch').find('.shipping-desk-provider-msg');
    }

    /**
     * Provider switch in the page header.
     *
     * The switch changes where the next rate request and the next purchase go, so
     * loaded quotes belong to the previous provider and must not survive it. Rather
     * than unpick the table by hand, reload: the page then renders the new provider,
     * the test/production chip and the banner from the server, and no stale rate can
     * be bought against the wrong account.
     */
    $(document).on('click', '.shipping-desk-provider-btn', function () {
        var $btn = $(this);
        var $wrap = $btn.closest('.shipping-desk-provider-switch');

        if ($btn.hasClass('is-active') || $wrap.hasClass('is-busy')) {
            return;
        }

        var $msg = providerMessage($btn);
        $wrap.addClass('is-busy').find('.shipping-desk-provider-btn').prop('disabled', true);
        $msg.removeClass('is-error').text('Switching...');

        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_switch_provider',
            nonce: $wrap.attr('data-nonce'),
            provider: $btn.attr('data-provider')
        }, function (response) {
            if (response && response.success) {
                window.location.reload();
                return;
            }
            $wrap.removeClass('is-busy').find('.shipping-desk-provider-btn').prop('disabled', false);
            var reason = (response && response.data && response.data.message)
                ? response.data.message
                : 'Could not switch provider.';
            $msg.addClass('is-error').text(reason);
        }).fail(function (xhr) {
            $wrap.removeClass('is-busy').find('.shipping-desk-provider-btn').prop('disabled', false);
            $msg.addClass('is-error').text(ajaxErrorMessage(xhr, 'Could not switch provider.'));
        });
    });

    /**
     * Test / Production chip in the page header.
     *
     * The chip is the control because the chip is where the mode is shown; the provider
     * buttons stay purely about provider. Switching *to* production buys real labels, so
     * the browser asks first — switching back to test does not, and the server refuses a
     * mode whose key slot is empty either way. Reloading afterwards keeps the banner, the
     * chip and the stored quotes in step with the new mode.
     */
    $(document).on('click', '.shipping-desk-mode-chip', function () {
        var $chip = $(this);
        var $wrap = $chip.closest('.shipping-desk-provider-switch');
        if ($wrap.hasClass('is-busy')) {
            return;
        }

        var $active = $wrap.find('.shipping-desk-provider-btn.is-active');
        var provider = $active.attr('data-provider') || '';
        var name = $.trim($active.text()) || provider;
        var toLive = $chip.hasClass('is-test');

        if (toLive && !window.confirm('Switch ' + name + ' to Production? Labels will be bought with real money.')) {
            return;
        }

        var $msg = providerMessage($chip);
        $wrap.addClass('is-busy').find('.shipping-desk-provider-btn, .shipping-desk-mode-chip').prop('disabled', true);
        $msg.removeClass('is-error').text(toLive ? 'Switching to production...' : 'Switching to test...');

        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_switch_provider',
            nonce: $wrap.attr('data-nonce'),
            provider: provider,
            toggle_mode: 1
        }, function (response) {
            if (response && response.success) {
                window.location.reload();
                return;
            }
            $wrap.removeClass('is-busy').find('.shipping-desk-provider-btn, .shipping-desk-mode-chip').prop('disabled', false);
            var reason = (response && response.data && response.data.message)
                ? response.data.message
                : 'Could not change mode.';
            $msg.addClass('is-error').text(reason);
        }).fail(function (xhr) {
            $wrap.removeClass('is-busy').find('.shipping-desk-provider-btn, .shipping-desk-mode-chip').prop('disabled', false);
            $msg.addClass('is-error').text(ajaxErrorMessage(xhr, 'Could not change mode.'));
        });
    });

    /**
     * The address decision an operator has to make before rates can be quoted.
     *
     * The provider's validator (Shippo) or EasyPost returns a corrected address for
     * orders whose address never went through checkout validation. The quote cannot
     * be fetched first and repaired afterwards: the shipment carries the address, so
     * whatever is chosen here is what the label prints. Hence a gate, not a warning
     * beside the rates.
     */
    function renderAddressChoice($row, validation) {
        var $container = $row.find('.shipping-desk-rates-container');
        if (!$container.length) return;

        resetRates($row, '');

        var hasSuggestion = !!validation.recommended_line;
        var groupName = 'shipping-desk-address-' + ($row.data('order-id') || Math.random().toString(36).slice(2));

        var $panel = $('<div>', { 'class': 'shipping-desk-address-panel' });
        var $head = $('<div>', { 'class': 'shipping-desk-address-head' })
            .append($('<strong>', { text: validation.status === 'invalid' ? 'Address problem' : 'Address check' }));
        if (hasSuggestion && validation.summary) {
            $head.append($('<span>', { 'class': 'shipping-desk-address-changes', text: validation.summary }));
        }
        if (validation.address_type) {
            $head.append($('<span>', { 'class': 'shipping-desk-address-type', text: validation.address_type }));
        }
        $panel.append($head);

        if (validation.reasons && validation.reasons.length) {
            var reasons = [];
            $.each(validation.reasons, function (i, reason) {
                if (reason && reason.description) reasons.push(reason.description);
            });
            if (reasons.length) {
                $panel.append($('<p>', { 'class': 'shipping-desk-address-reason', text: reasons.join(' ') }));
            }
        }

        function option(value, label, line, checked) {
            var $option = $('<label>', { 'class': 'shipping-desk-address-option' });
            $option.append($('<input>', { type: 'radio', name: groupName, value: value }));
            if (checked) $option.find('input').prop('checked', true);
            $option.append($('<span>', { 'class': 'shipping-desk-address-option-name', text: label }));
            $option.append($('<span>', { 'class': 'shipping-desk-address-option-value', text: line }));
            return $option;
        }

        // The suggestion is preselected because it is the reason this panel
        // appeared - but nothing happens until the button is pressed.
        $panel.append(option('original', 'As entered', validation.original_line || '', !hasSuggestion));
        if (hasSuggestion) {
            $panel.append(option('suggested', 'Suggested', validation.recommended_line, true));
        }

        var $updateLabel = $('<label>', { 'class': 'shipping-desk-address-update' });
        var $updateBox = $('<input>', { type: 'checkbox' });
        $updateLabel.append($updateBox).append(document.createTextNode(" Also save this as the order's shipping address"));
        if (hasSuggestion) $panel.append($updateLabel);

        var $apply = $('<button>', {
            type: 'button',
            'class': 'button shipping-desk-address-apply',
            text: hasSuggestion ? 'Use selected address' : 'Quote anyway'
        });
        $panel.append($apply);

        $apply.on('click', function () {
            var choice = hasSuggestion ? $panel.find('input[type=radio]:checked').val() : 'original';
            $row.data('address-choice', choice || 'original');
            if ($updateBox.is(':checked')) {
                $row.data('update-order-address', 1);
            } else {
                $row.removeData('update-order-address');
            }

            // A row skipped by a bulk run rejoins the batch once its address is
            // settled, so the operator does not have to re-tick it by hand.
            var wasFlagged = $row.attr('data-address-flagged') === '1';
            clearAddressFlag($row);
            if (wasFlagged) {
                $row.find('.shipping-desk-order-select').prop('checked', true);
                syncSelectAll($row.closest('table'));
            }

            fetchRatesForRow($row);
        });

        $container.append($panel);
    }

    /**
     * One line above the quotes recording what happened to the address.
     *
     * Shown for every outcome that changed something or could not run: an address
     * that was never checked must not look checked, and a feature that is not
     * running must not look clean. A correction too small to stop a quote for (see
     * validation_needs_decision()) is reported with a one-click offer instead of a
     * panel: nothing is required, but taking it is what leaves the order carrying
     * the same address the label prints.
     */
    function renderAddressBadge($row, validation) {
        var $container = $row.find('.shipping-desk-rates-container');
        if (!$container.length) return;

        var text = '';
        var corrected = false;
        var source = validation.validator === 'easypost' ? 'EasyPost' : 'Shippo';

        if (validation.applied === 'suggested') {
            corrected = true;
            text = 'Address: suggested' + (validation.summary ? ' (' + validation.summary + ')' : '');
            // Applied the moment it was accepted, so say so: the operator needs to
            // know the order itself changed, not just the quote.
            text += validation.order_updated ? ' \u00b7 order address updated' : ' \u00b7 order address left as entered';
        } else if (validation.applied === 'original') {
            text = 'Address: as entered (a correction was offered)' + (validation.summary ? ' - ' + validation.summary : '');
        } else if (validation.status === 'corrected') {
            // Corrected, but not by enough to stop the quote: no panel, nothing
            // required. Silent was wrong - "checked and standardisable" and "never
            // checked" looked identical - and offering it here is the only way an
            // order the customer left in their own spelling ever gains the address
            // the label actually prints.
            text = 'Address: standardised version available'
                + (validation.summary ? ' (' + validation.summary + ')' : '')
                + ' \u00b7 order address as entered';
        } else if (validation.status === 'valid') {
            // "Matched", not "verified": the carrier standardises the address at label
            // time (adds the ZIP+4, drops punctuation) and the verifier does not report
            // that back, so claiming it here would promise what this screen never sees.
            text = 'Address: matched by ' + source;
        } else if (validation.status === 'skipped') {
            text = 'Address: not checked (verification applies to US addresses only)';
        } else if (validation.status === 'unavailable') {
            text = 'Address: not checked (the validator did not answer)';
        }

        if (!text) return;

        // Residential/commercial is worth carrying: it is what explains a UPS
        // address-classification surcharge appearing after the quote.
        if (validation.address_type) {
            text += ' \u00b7 ' + validation.address_type;
        }

        var $badge = $('<div>', {
            'class': 'shipping-desk-address-badge' + (corrected ? ' is-corrected' : ''),
            text: text
        });

        // Carry the underlying reason as a tooltip: "the validator did not
        // answer" is useless on its own when the answer is a 403 or a timeout.
        if (validation.error) {
            $badge.attr('title', validation.error);
        } else if (validation.recommended_line) {
            $badge.attr('title', 'Verified: ' + validation.recommended_line);
        }

        // One click, no panel. The gate did not stop for this change, so nothing is
        // demanded; taking it quotes again on the standardised address and saves it
        // on the order, which is the point - otherwise order and label disagree
        // forever. Absent once a choice has been applied (offered === applied).
        if (validation.status === 'corrected' && !validation.applied && validation.recommended_line) {
            $badge.append($('<button>', {
                type: 'button',
                'class': 'button button-small shipping-desk-use-standardised',
                text: 'Use standardised',
                title: 'Quote again with this address and save it on the order'
            }));
        }

        $container.prepend($badge);
    }

    /**
     * Flag a row that was skipped by the bulk run because it needs a decision.
     *
     * The flag is a button: it reopens the panel, which the server answers from
     * its cached validation, so reviewing costs no API call.
     */
    function flagAddressRow($row) {
        $row.attr('data-address-flagged', '1');
        var $cell = $row.find('td').last();
        if ($cell.length && !$cell.find('.shipping-desk-address-flag').length) {
            $cell.prepend($('<button>', {
                type: 'button',
                'class': 'shipping-desk-address-flag',
                text: 'Address',
                title: 'This address needs a decision before rates can be quoted'
            }));
        }
        refreshReviewButton();
    }

    function clearAddressFlag($row) {
        $row.removeAttr('data-address-flagged');
        $row.find('.shipping-desk-address-flag').remove();
        refreshReviewButton();
    }

    /**
     * Keep the Review flagged button honest.
     *
     * The bulk summary records what the run found; the button carries the live count,
     * and it disappears once every decision has been answered. A stale "3 need a
     * decision" beside rows that no longer do is worse than no count at all.
     */
    function refreshReviewButton() {
        var $review = $('.shipping-desk-review-flagged');
        if (!$review.length) {
            return;
        }

        var remaining = $('.shipping-desk-address-flag').length;
        if (!remaining) {
            $review.remove();
            return;
        }

        $review.text('Review flagged (' + remaining + ')');
    }

    $(document).on('click', '.shipping-desk-address-flag', function () {
        fetchRatesForRow($(this).closest('tr'));
    });

    /**
     * Restore a row from the quotes the last Get Rates stored.
     *
     * The server only emits the record when it still considers it usable (same
     * dispatch day, within the carrier expiry and the hard cap), so this is a
     * convenience, not a second source of truth. The inputs are restored with it, so
     * the row looks exactly as it did when the prices were fetched.
     */
    function hydrateRow($row) {
        var raw = $row.attr('data-quotes');
        if (!raw) return;

        var data;
        try {
            data = JSON.parse(raw);
        } catch (err) {
            return;
        }

        var inputs = data.inputs || {};

        if (inputs.box_key) {
            $row.find('.shipping-desk-box-select').val(String(inputs.box_key));
        }
        if (typeof inputs.weight !== 'undefined' && inputs.weight !== null && inputs.weight !== '') {
            $row.find('.shipping-desk-total-weight').val(parseFloat(inputs.weight).toFixed(2));
        }
        if (typeof inputs.liability !== 'undefined' && parseFloat(inputs.liability) > 0) {
            $row.find('.shipping-desk-liability-amount').val(parseFloat(inputs.liability).toFixed(2));
        }
        if (inputs.signature) {
            $row.find('.shipping-desk-signature-confirmation').val(inputs.signature);
        }
        if (inputs.address_choice) {
            $row.data('address-choice', inputs.address_choice);
        }
        if (inputs.update_order) {
            $row.data('update-order-address', 1);
        }

        var validation = data.address_validation || null;
        var rates = data.rates || [];

        // A pending decision comes back as a decision, not as empty rates: otherwise a
        // reload would look like validation never ran, and the bulk flag would vanish.
        //
        // Whether a correction needed a decision is the server's call (materiality),
        // and it travels with the record. Records written before that flag existed
        // keep the old behaviour for the few hours they can still be hydrated.
        if (validation) {
            var needsDecision = (typeof validation.needs_decision !== 'undefined')
                ? !!validation.needs_decision
                : (validation.status === 'corrected' || validation.status === 'invalid');

            if (!validation.applied && needsDecision) {
                renderAddressChoice($row, validation);
                flagAddressRow($row);
                return;
            }
        }

        if (rates.length) {
            renderRates($row, rates, data.matched_rate_id);
            if (validation && validation.status) {
                renderAddressBadge($row, validation);
            }
        }
    }

    $(function () {
        $('.shipping-desk-table tbody tr[data-quotes]').each(function () {
            hydrateRow($(this));
        });

        // Every row can say what its Insurance value costs, whether or not it has quotes.
        $('.shipping-desk-table tbody tr').each(function () {
            refreshLiabilityEstimate($(this));
        });
    });

    function fetchRatesForRow($row) {
        var def = $.Deferred();
        var $btn = $row.find('.shipping-desk-get-rates');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        var box = getBoxData($row);
        if (!box) {
            def.reject({ orderId: orderId, message: 'Please select a box.' });
            return def.promise();
        }
        var totalWeight = parseFloat($row.find('.shipping-desk-total-weight').val());
        var liability = parseFloat($row.find('.shipping-desk-liability-amount').val());
        var signature = $row.find('.shipping-desk-signature-confirmation').val() || '';
        // Remember the value used for this rate run so changing Insurance back to
        // the same amount does not needlessly discard the quotes.
        $row.data('liability-applied', isNaN(liability) ? 0 : liability);
        resetRates($row, 'Loading rates...');
        $btn.prop('disabled', true).text('Loading...');
        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_get_rates',
            order_id: orderId,
            _wpnonce: nonce,
            box: box,
            total_weight: totalWeight,
            liability_amount: isNaN(liability) ? 0 : liability,
            signature_confirmation: signature,
            // The server never trusts an address from the browser: these say which
            // of the two addresses it offered was chosen, and it rebuilds the
            // address itself from its cached validation.
            address_choice: $row.data('address-choice') || '',
            update_order_address: $row.data('update-order-address') ? 1 : 0,
            // Stored with the quotes so a reload can put the dropdown back exactly
            // as it was when the prices were fetched.
            box_key: $row.find('.shipping-desk-box-select').val() || ''
        }).done(function (resp) {
            $btn.prop('disabled', false).text('Get Rates');
            if (!resp || !resp.success) {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Error getting rates.';
                resetRates($row, msg);
                def.reject({ orderId: orderId, message: msg });
                return;
            }
            if (resp.data.needs_address_choice) {
                renderAddressChoice($row, resp.data.address_validation);
                def.resolve({ orderId: orderId, needsChoice: true });
                return;
            }
            renderRates($row, resp.data.rates || [], resp.data.matched_rate_id);

            // Rates exist, so this row's address question is settled — however it was
            // settled. Clearing the flag only from the panel left rows flagged and
            // unticked when the operator fixed the order's address and simply pressed
            // Get Rates, which is exactly how it gets resolved in practice.
            var wasFlagged = $row.attr('data-address-flagged') === '1';
            clearAddressFlag($row);
            if (wasFlagged) {
                $row.find('.shipping-desk-order-select').prop('checked', true);
                syncSelectAll($row.closest('table'));
            }

            if (resp.data.address_validation && resp.data.address_validation.status) {
                renderAddressBadge($row, resp.data.address_validation);
            }
            def.resolve({ orderId: orderId, count: (resp.data.rates || []).length });
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Get Rates');
            var message = ajaxErrorMessage(xhr, 'Error getting rates.');
            resetRates($row, message);
            def.reject({ orderId: orderId, message: message });
        });
        return def.promise();
    }

    $(document).on('click', '.shipping-desk-get-rates', function () {
        fetchRatesForRow($(this).closest('tr'));
    });

    /**
     * Take the standardised form on a correction that was not big enough to block.
     *
     * Sends exactly what the decision panel sends, so the server rebuilds the
     * address from its own cached validation instead of trusting the browser, and
     * then writes the order the same way. Cleared immediately after the request is
     * built: leaving the choice set would rewrite the order on every later Get
     * Rates press without anyone asking.
     */
    $(document).on('click', '.shipping-desk-use-standardised', function () {
        var $row = $(this).closest('[data-order-id]');
        if (!$row.length) return;

        $row.data('address-choice', 'suggested').data('update-order-address', 1);
        fetchRatesForRow($row);
        $row.removeData('address-choice').removeData('update-order-address');
    });

    function getPurchaseError($row) {
        if (!$row.data('selected-rate')) return 'Please get rates and select a rate first.';
        if (!getBoxData($row)) return 'Please select a box.';
        return '';
    }

    function handlePurchaseSuccess($row, $btn) {
        $row.attr('data-has-label', '1').attr('data-label-refunded', '0');
        $row.removeData('selected-rate');
        $row.find('.shipping-desk-box-select, .shipping-desk-total-weight, .shipping-desk-liability-amount, .shipping-desk-signature-confirmation, .shipping-desk-rate-choice, .shipping-desk-service-select').prop('disabled', true);

        var $actions = $btn.closest('td');
        $actions.empty();
        $actions.append(
            $('<button>', {
                type: 'button',
                'class': 'shipping-desk-print-label',
                text: 'Print'
            }),
            ' ',
            $('<button>', {
                type: 'button',
                'class': 'shipping-desk-refund-label',
                text: 'Refund'
            })
        );
    }

    function purchaseLabel($row) {
        var deferred = $.Deferred();
        var $btn = $row.find('.shipping-desk-buy-label');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        var error = getPurchaseError($row);
        if (error) {
            deferred.reject({ message: error, orderId: orderId });
            return deferred.promise();
        }

        var selectedRate = $row.data('selected-rate');
        var box = getBoxData($row);
        var totalWeight = parseFloat($row.find('.shipping-desk-total-weight').val());
        var liability = parseFloat($row.find('.shipping-desk-liability-amount').val());
        var vasFields = buildVasFields(selectedRate, getVasState($row));
        if (!isNaN(liability) && liability > 0) {
            vasFields.liability_amount = liability;
        }
        $btn.prop('disabled', true).text('Buying...');
        var request = $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_buy_label',
            order_id: orderId,
            _wpnonce: nonce,
            box: box,
            rate_id: selectedRate.rate_id,
            remote_id: selectedRate.remote_shipment_id,
            // Shippo's transaction only gives back a rate ID, so the carrier,
            // service and price have to travel with the request or the order note
            // cannot name them. JSON for the same reason as orders_json below.
            rate: JSON.stringify(selectedRate),
            vas: vasFields,
            total_weight: totalWeight > 0 ? totalWeight : null
        });
        request.done(function (resp) {
            $btn.prop('disabled', false).text('Buy Label');
            if (!resp || !resp.success) {
                deferred.reject({
                    message: 'Error: ' + ((resp && resp.data && resp.data.message) ? resp.data.message : 'Unknown error'),
                    orderId: orderId
                });
                return;
            }
            handlePurchaseSuccess($row, $btn);
            deferred.resolve(resp);
        });
        request.fail(function (xhr) {
            $btn.prop('disabled', false).text('Buy Label');
            var message = ajaxErrorMessage(
                xhr,
                'Error buying label. Check the order and the provider dashboard before retrying.'
            );
            deferred.reject({ message: message, orderId: orderId });
        });
        return deferred.promise();
    }

    /*
     * Buying stays a single click. A confirmation prompt was tried in 1.0.2 and removed
     * in 1.0.5: the insurance premium is already in the rate column, so the prompt was
     * restating something on screen and charging a click per label for it.
     */
    $(document).on('click', '.shipping-desk-buy-label', function () {
        var $row = $(this).closest('tr');
        purchaseLabel($row)
            .done(function (resp) {
                var data = (resp && resp.data) ? resp.data : {};
                var msg = 'Label purchased. Tracking: ' + (data.tracking || '');
                // Insurance is priced at purchase, so this is the first and often only
                // place the cover becomes visible.
                if (data.insurance) {
                    msg += '\nInsured for $' + data.insurance;
                    if (data.insurance_fee) {
                        msg += ' (premium $' + data.insurance_fee + ')';
                    }
                }
                // Cover was asked for and the label came back without it: this is the one
                // moment it can be fixed cheaply, before the parcel moves.
                if (data.insurance_warning) {
                    msg += '\n\n' + data.insurance_warning;
                }
                // A fatal after the label was saved: the purchase stands, something after
                // it broke. Say so rather than letting the label look unbought.
                if (data.warning) {
                    msg += '\n\n' + data.warning;
                }
                alert(msg);
            })
            .fail(function (error) {
                alert((error && error.message) ? error.message : 'Error buying label.');
            });
    });

    function setBulkControls($form, disabled) {
        $form.find('.shipping-desk-bulk-action-select, .shipping-desk-bulk-apply').prop('disabled', disabled);
    }

    /**
     * Open the first flagged row so its address decision can be made in place.
     *
     * One row at a time on purpose: the table stays visible, decisions can be made
     * in any order, and stopping halfway loses nothing. Every flag is a button as
     * well, so this is a convenience rather than the only way in.
     */
    function reviewFlaggedRow($table) {
        var $flags = $table.find('.shipping-desk-address-flag');
        if (!$flags.length) return;

        var $target = $flags.first();
        if ($target.closest('tr').find('.shipping-desk-address-panel').length && $flags.length > 1) {
            $target = $flags.eq(1);
        }

        var $row = $target.closest('tr');
        if ($row.length) {
            $row[0].scrollIntoView({ block: 'center' });
            fetchRatesForRow($row);
        }
    }

    function runBulkGetRates($form, $table, rows, index, result) {
        var $status = $form.find('.shipping-desk-bulk-status');
        if (index >= rows.length) {
            setBulkControls($form, false);
            syncSelectAll($table);
            var message = 'Rates loaded for ' + result.loaded + (result.loaded === 1 ? ' order' : ' orders') + '.';
            if (result.failed) {
                message += ' ' + result.failed + ' failed. ' + result.errors.join(' ');
            }
            if (result.flagged) {
                message += ' ' + result.flagged
                    + (result.flagged === 1 ? ' order needs' : ' orders need')
                    + ' an address decision (flagged in Actions).';
            }
            $status.text(message);
            if (result.flagged) {
                var $review = $('<button>', {
                    type: 'button',
                    'class': 'button shipping-desk-review-flagged',
                    text: 'Review flagged (' + result.flagged + ')'
                });
                $review.on('click', function () {
                    reviewFlaggedRow($table);
                });
                $status.append(' ').append($review);
            }
            return;
        }

        var $row = $(rows[index]).closest('tr');
        var orderId = $row.data('order-id');
        $status.text('Loading rates ' + (index + 1) + ' of ' + rows.length + ' for Order #' + orderId + '...');
        fetchRatesForRow($row)
            .done(function (res) {
                if (res && res.needsChoice) {
                    // Not an error: the row is waiting for a decision. Flag it, drop
                    // the selection so the next bulk buy cannot trip over it, and
                    // carry on with the rest of the run.
                    result.flagged = (result.flagged || 0) + 1;
                    flagAddressRow($row);
                    $row.find('input[type=checkbox]').prop('checked', false);
                    return;
                }
                result.loaded++;
                // Keep the row selected: getting rates is usually followed by
                // buying or printing, so reselecting would just be busywork.
            })
            .fail(function (err) {
                result.failed++;
                result.errors.push('Order #' + orderId + ': ' + ((err && err.message) ? err.message : 'Unknown error'));
            })
            .always(function () {
                // One provider write per row: pace the run with the same rule the server
                // uses, so a test key (Shippo: 50 POSTs/minute) is not flooded.
                var delay = (ShippingDesk && ShippingDesk.bulkDelayMs) ? ShippingDesk.bulkDelayMs : 0;
                window.setTimeout(function () {
                    runBulkGetRates($form, $table, rows, index + 1, result);
                }, delay);
            });
    }

    function runBulkPrint($form, $table, rows, index, result) {
        var $status = $form.find('.shipping-desk-bulk-status');
        if (index >= rows.length) {
            setBulkControls($form, false);
            syncSelectAll($table);
            var message = result.printed + (result.printed === 1 ? ' label printed.' : ' labels printed.');
            if (result.failed) {
                message += ' ' + result.failed + ' failed.' + (result.errors.length ? ' ' + result.errors.join(' ') : '');
            }
            // When chained after a bulk buy, keep the purchase summary visible
            // in front of the print summary.
            $status.text((result.prefix || '') + message);
            return;
        }

        var $row = $(rows[index]).closest('tr');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        var remoteId = $row.data('remote-id') || '';
        $status.text((result.prefix || '') + 'Printing label ' + (index + 1) + ' of ' + rows.length + ' for Order #' + orderId + '...');
        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_print_label',
            order_id: orderId,
            remote_id: remoteId,
            print_node: 1,
            _wpnonce: nonce
        })
            .done(function () {
                result.printed++;
                // Row stays selected so a failed print can be retried without
                // reselecting every checkbox.
            })
            .fail(function (xhr) {
                result.failed++;
                result.errors.push(
                    'Order #' + orderId + ': ' + ajaxErrorMessage(xhr, 'Error printing label.')
                );
            })
            .always(function () {
                runBulkPrint($form, $table, rows, index + 1, result);
            });
    }

    $(document).on('click', '.shipping-desk-bulk-apply', function () {
        var $form = $(this).closest('.shipping-desk-bulk-actions');
        var action = $form.find('.shipping-desk-bulk-action-select').val();
        var $status = $form.find('.shipping-desk-bulk-status');
        if (!action) {
            $status.text('Select a bulk action.');
            return;
        }

        var $table = $form.closest('.shipping-desk-wrap').find('.shipping-desk-table');
        var $selected = $table.find('.shipping-desk-order-select:checked');
        if (!$selected.length) {
            $status.text('Select at least one order.');
            return;
        }
        if (action === 'bulk-get-rates') {
            var rateErrors = [];
            $selected.each(function () {
                var $row = $(this).closest('tr');
                if (!getBoxData($row)) {
                    rateErrors.push('Order #' + $row.data('order-id') + ': select a box first.');
                }
            });
            if (rateErrors.length) {
                $status.text(rateErrors.join(' '));
                return;
            }
            setBulkControls($form, true);
            runBulkGetRates($form, $table, $selected.toArray(), 0, {
                loaded: 0,
                failed: 0,
                errors: []
            });
            return;
        }
        if (action === 'bulk-print') {
            $status.text('Verifying PrintNode configuration...');

            // Check PrintNode config on the server
            $.post(ShippingDesk.ajaxurl, {
                action: 'shipping_desk_printnode_status'
            }, function (statusResp) {
                if (!statusResp || !statusResp.success) {
                    $status.text('PrintNode must be configured (API key and printer) - visit PrintNode Settings.');
                    return;
                }
                if (!statusResp.data || !statusResp.data.configured) {
                    $status.text(statusResp.data.message || 'PrintNode must be configured (API key and printer) - visit PrintNode Settings.');
                    return;
                }

                setBulkControls($form, true);
                runBulkPrint($form, $table, $selected.toArray(), 0, {
                    printed: 0,
                    failed: 0,
                    errors: []
                });
            });
            return;
        }
        if (action !== 'bulk-buy-label' && action !== 'bulk-buy-print') {
            return;
        }
        var printAfter = (action === 'bulk-buy-print');

        // Buy + Print needs PrintNode, otherwise there is no way to print
        // server-side. Fail early rather than booking labels we cannot print.
        if (printAfter) {
            $status.text('Verifying PrintNode configuration...');
            $.post(ShippingDesk.ajaxurl, { action: 'shipping_desk_printnode_status' }, function (statusResp) {
                if (!statusResp || !statusResp.success || !statusResp.data || !statusResp.data.configured) {
                    $status.text((statusResp && statusResp.data && statusResp.data.message)
                        ? statusResp.data.message
                        : 'PrintNode must be configured (API key and printer) - visit PrintNode Settings.');
                    return;
                }
                startBulkBuy();
            });
            return;
        }

        startBulkBuy();

        function startBulkBuy() {
        // A row flagged for an address decision has no quotes, so it cannot be
        // bought. Drop it here rather than failing the whole run: the operator
        // gets told it was skipped, and the rest of the batch proceeds.
        var addressSkipped = 0;
        var $buyable = $selected.filter(function () {
            if ($(this).closest('tr').attr('data-address-flagged') === '1') {
                addressSkipped++;
                return false;
            }
            return true;
        });
        if (!$buyable.length) {
            $status.text('Nothing to buy: every selected order is waiting for an address decision. Use Review flagged or the Address buttons in the Actions column.');
            return;
        }

        var errors = [];
        $buyable.each(function () {
            var $row = $(this).closest('tr');
            var error = getPurchaseError($row);
            if (error) {
                errors.push('Order #' + $row.data('order-id') + ': ' + error);
            }
        });
        if (errors.length) {
            $status.text(errors.join(' '));
            return;
        }

        setBulkControls($form, true);
        $status.text('Booking ' + $buyable.length + ( $buyable.length === 1 ? ' shipment...' : ' shipments...' ) );

        var orders = [];
        var $checkboxes = $buyable;
        $buyable.each(function () {
            var $row = $(this).closest('tr');
            var selectedRate = $row.data('selected-rate');
            var liability = parseFloat($row.find('.shipping-desk-liability-amount').val());
            var vasFields = buildVasFields(selectedRate, getVasState($row));
            if (!isNaN(liability) && liability > 0) {
                vasFields.liability_amount = liability;
            }
            orders.push({
                order_id:     $row.data('order-id'),
                rate_id:      selectedRate ? selectedRate.rate_id : '',
                remote_id:    selectedRate ? (selectedRate.remote_shipment_id || '') : '',
                // Carrier, service and price live on the rate, not on the
                // transaction, so each order carries its own copy.
                rate:         selectedRate || null,
                vas:          vasFields,
                box:          getBoxData($row),
                total_weight: parseFloat($row.find('.shipping-desk-total-weight').val()) || 0
            });
        });

        // Chunk the run: one request per 10 orders keeps each request inside PHP's
        // execution window and gives the operator progress instead of one long
        // spinner. Per-order results accumulate across chunks.
        var chunkSize = (ShippingDesk && ShippingDesk.bulkChunkSize) ? ShippingDesk.bulkChunkSize : 10;
        var totals = { purchased: 0, failed: 0, errors: [], order_ids: [], insurance_warnings: [] };

        function markPurchased() {
            var $bought = $();
            $checkboxes.each(function () {
                var $row = $(this).closest('tr');
                if (totals.order_ids.indexOf($row.data('order-id')) !== -1) {
                    handlePurchaseSuccess($row, $row.find('.shipping-desk-buy-label'));
                    // Keep the row selected so it can be printed straight after buying.
                    $bought = $bought.add($(this));
                }
            });
            syncSelectAll($table);
            return $bought;
        }

        function finish() {
            setBulkControls($form, false);
            var $purchased = markPurchased();
            var message = totals.purchased + (totals.purchased === 1 ? ' label purchased.' : ' labels purchased.');
            if (totals.failed) {
                message += ' ' + totals.failed + ' failed.';
                if (totals.errors.length) {
                    message += ' ' + totals.errors.join(' ');
                }
            }
            if (addressSkipped) {
                message += ' ' + addressSkipped
                    + (addressSkipped === 1 ? ' order skipped' : ' orders skipped')
                    + ' (address decision needed).';
            }
            if (totals.insurance_warnings.length) {
                message += ' INSURANCE: ' + totals.insurance_warnings.join(' ');
            }
            $status.text(message);

            if (printAfter && $purchased.length) {
                runBulkPrint($form, $table, $purchased.toArray(), 0, {
                    printed: 0,
                    failed: 0,
                    errors: [],
                    prefix: message + ' '
                });
            }
        }

        // A chunk failed outright. Labels already bought in earlier chunks are real
        // and are marked, but printing is left to the operator rather than started
        // automatically on a partial batch.
        function stop(reason) {
            setBulkControls($form, false);
            markPurchased();
            var message = reason;
            if (totals.purchased) {
                message += ' ' + totals.purchased
                    + (totals.purchased === 1 ? ' label was purchased' : ' labels were purchased')
                    + ' before the run stopped; ' + (totals.purchased === 1 ? 'it is' : 'they are')
                    + ' marked in the table and ready to print.';
            }
            $status.text(message);
        }

        function buyChunk(offset) {
            var chunk = orders.slice(offset, offset + chunkSize);
            if (!chunk.length) {
                finish();
                return;
            }

            if (orders.length > chunkSize) {
                $status.text('Booking ' + (offset + 1) + ' to ' + (offset + chunk.length)
                    + ' of ' + orders.length + ' shipments...');
            }

            $.post(ShippingDesk.ajaxurl, {
                action: 'shipping_desk_bulk_buy',
                // Send as a single JSON string rather than nested POST arrays:
                // nested array parameters can be rejected by server/WAF rules
                // before PHP runs, which is what produced the misleading error.
                orders_json: JSON.stringify(chunk),
                _wpnonce: (ShippingDesk && ShippingDesk.bulkNonce) ? ShippingDesk.bulkNonce : $form.attr('data-bulk-nonce')
            }, function (resp) {
                if (!resp || !resp.success) {
                    stop((resp && resp.data && resp.data.message) ? resp.data.message : 'Error booking labels.');
                    return;
                }

                var data = resp.data || {};
                totals.purchased += data.purchased || 0;
                totals.failed    += data.failed || 0;
                if (data.errors && data.errors.length) {
                    totals.errors = totals.errors.concat(data.errors);
                }
                if (data.order_ids && data.order_ids.length) {
                    totals.order_ids = totals.order_ids.concat(data.order_ids);
                }
                // A label that came back without the cover it was bought with is a purchase
                // that "succeeded" and still needs the operator's eyes.
                if (data.insurance_warnings && data.insurance_warnings.length) {
                    totals.insurance_warnings = totals.insurance_warnings.concat(data.insurance_warnings);
                }

                buyChunk(offset + chunk.length);
            }).fail(function (xhr) {
                stop(ajaxErrorMessage(xhr, 'Error booking labels. Please try again.'));
            });
        }

        buyChunk(0);
        }
    });

    $(document).on('click', '.shipping-desk-reship', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');

        $btn.prop('disabled', true).text('Loading...');
        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_render_purchase_row',
            order_id: orderId,
            _wpnonce: nonce
        }, function (resp) {
            $btn.prop('disabled', false).text('Reship');
            if (!resp || !resp.success || !resp.data || !resp.data.html) {
                window.alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Could not load the shipping panel.');
                return;
            }

            var $existing = $row.next('.shipping-desk-reship-detail');
            if ($existing.length) {
                $existing.remove();
                return;
            }

            // Insert a full-width detail row beneath the order row (option a):
            // the purchase UI needs different columns than the completed table.
            // The server sends the inner cells plus the row's own data-* attributes
            // (order id, nonce, items weight). Those attributes carry the real
            // items weight for this order, so they must be applied here rather than
            // copied from the completed row, which does not track weight.
            var columns = $row.children('td').length;
            var $detail = $('<tr>', { 'class': 'shipping-desk-reship-detail' });
            var $cell = $('<td>', { colspan: columns, 'class': 'shipping-desk-reship-detail-cell' });

            var $innerRow = $('<tr>');
            if (resp.data.attrs) {
                // Every data-* attribute, not a hand-picked few. The server renders the row
                // exactly as the shipping tab does, and data-provider is what tells the rate
                // column and the Insurance field who is pricing cover — a whitelist of three
                // names dropped it, so a reship could never show an insurance premium.
                var attrPattern = /(data-[a-z0-9-]+)="([^"]*)"/g;
                var attrMatch;
                while ((attrMatch = attrPattern.exec(resp.data.attrs)) !== null) {
                    $innerRow.attr(attrMatch[1], attrMatch[2]);
                }
            }
            // Fallbacks in case attrs is unavailable.
            if ($innerRow.attr('data-order-id') === undefined) {
                $innerRow.attr('data-order-id', $row.data('order-id'));
            }
            if ($innerRow.attr('data-nonce') === undefined) {
                $innerRow.attr('data-nonce', $row.data('nonce'));
            }
            $innerRow.html(resp.data.html);

            var $table = $('<table class="widefat shipping-desk-table shipping-desk-reship-table"><tbody></tbody></table>');
            $table.find('tbody').append($innerRow);
            $cell.append($table);
            $detail.append($cell);
            $row.after($detail);
        }).fail(function (xhr) {
            $btn.prop('disabled', false).text('Reship');
            window.alert(ajaxErrorMessage(xhr, 'Could not load the shipping panel.'));
        });
    });

    $(document).on('click', '.shipping-desk-print-label', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        var remoteId = $row.data('remote-id') || '';
        var originalText = $btn.text();

        /*
         * EasyPost can take longer to produce a label than one request is willing to wait
         * for, so a retryable failure is retried here instead of being handed to the
         * operator: two more attempts, 2.5s apart, with the button saying what it is doing.
         * Only when those are exhausted does the message appear.
         */
        function attempt(triesLeft) {
            $btn.prop('disabled', true).text(triesLeft < 3 ? 'Waiting for label...' : 'Printing...');

            $.post(ShippingDesk.ajaxurl, {
                action: 'shipping_desk_print_label',
                order_id: orderId,
                remote_id: remoteId,
                _wpnonce: nonce
            }, function (resp) {
                // print_url is the 4x6 file (same bytes PrintNode gets); label_url is the
                // carrier's own, which is 4x7 for UPS and a letter page for FedEx.
                var data = (resp && resp.success && resp.data) ? resp.data : {};
                var url = data.print_url || data.label_url || '';

                $btn.prop('disabled', false).text(originalText);

                if (url) {
                    var w = window.open(url, '_blank');
                    if (!w) {
                        // Popup blocked - navigate current tab instead.
                        window.location.href = url;
                    }
                    return;
                }

                if (resp && resp.success) {
                    // PrintNode (server print) succeeded - no popup needed; the disabled
                    // "Printing..." button already confirmed the click.
                    return;
                }

                alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Error printing label.');
            }).fail(function (xhr) {
                var data = (xhr && xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : {};

                if (data.retryable && triesLeft > 1) {
                    window.setTimeout(function () {
                        attempt(triesLeft - 1);
                    }, 2500);
                    return;
                }

                $btn.prop('disabled', false).text(originalText);
                alert(data.message || ajaxErrorMessage(xhr, 'Error printing the label.'));
            });
        }

        attempt(3);
    });

    $(document).on('click', '.shipping-desk-refund-label', function () {
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        var remoteId = $row.data('remote-id') || '';
        var subject = orderId ? 'Order #' + orderId : 'shipment ' + remoteId;
        if (!confirm('Refund shipping label for ' + subject + '?\n\nThe label will no longer be valid.')) return;
        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_refund_label',
            order_id: orderId,
            remote_id: remoteId,
            _wpnonce: nonce
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Error refunding label.');
                return;
            }
            var $actions = $btn.closest('td');
            $row.attr('data-label-refunded', '1');
            $actions.find('.shipping-desk-print-label, .shipping-desk-refund-label').remove();
            if (!$actions.find('.shipping-desk-label-refunded').length) {
                $actions.prepend($('<span>', {
                    'class': 'shipping-desk-label-refunded',
                    text: 'Label refunded.'
                }));
            }
            alert('Label refunded.');
        }).fail(function (xhr) {
            alert(ajaxErrorMessage(xhr, 'Error refunding label.'));
        });
    });

    $(document).on('click', '.shipping-desk-reset-shipping', function () {
        var $row = $(this).closest('tr');
        var orderId = $row.data('order-id');
        var nonce = $row.data('nonce');
        if (!confirm('Reset shipping state for Order #' + orderId + '? This clears stored label/tracking so a new label can be purchased.')) return;
        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_reset_shipping',
            order_id: orderId,
            _wpnonce: nonce
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Error resetting.');
                return;
            }
            alert('Shipping state reset.');
            location.reload();
        }).fail(function (xhr) {
            alert(ajaxErrorMessage(xhr, 'Error resetting.'));
        });
    });
    /*
     * "What's new" popup: once per version, per user, after an update. Closing it records
     * the dismissal, but the popup goes away regardless - if the request fails, showing it
     * again next time is better than trapping someone behind a changelog.
     */
    /*
     * A key field that changed without a keystroke was filled by a password manager.
     *
     * This happened for real: an address edit once committed a replaced API key, because a
     * single save covered both the address and the keys. The forms are separate now, but a
     * manager can still land on the keys form, so a submit that would overwrite a key
     * nobody typed into asks first.
     */
    (function () {
        var initial = {};
        var $keys = $('.shipping-desk-api-key');

        $keys.each(function () {
            initial[this.name] = this.value;
        });

        // A real edit starts with a keystroke; autofill does not produce one.
        $(document).on('keydown', '.shipping-desk-api-key', function () {
            $(this).data('typed', true);
        });

        $keys.closest('form').on('submit', function (event) {
            var replaced = [];

            $(this).find('.shipping-desk-api-key').each(function () {
                if (undefined === initial[this.name]) return;
                if (this.value === initial[this.name]) return;
                if ($(this).data('typed')) return;
                replaced.push(this.name.replace(/^shipping_desk_/, '').replace(/_/g, ' '));
            });

            if (!replaced.length) return;

            var ok = window.confirm(
                'These API keys changed without being typed:\n\n  ' + replaced.join('\n  ') +
                '\n\nA password manager may have filled them. Save anyway?'
            );

            if (!ok) {
                event.preventDefault();
                $(this).find('.shipping-desk-api-key').first().trigger('focus');
            }
        });
    })();

    function closeWhatsNew() {
        var $modal = $('#shipping-desk-whats-new');
        if (!$modal.length) return;

        var version = $modal.data('version');
        var nonce = $modal.data('nonce');
        $modal.remove();

        $.post(ShippingDesk.ajaxurl, {
            action: 'shipping_desk_dismiss_whats_new',
            _wpnonce: nonce,
            version: version
        });
    }

    $(document).on('click', '.shipping-desk-whats-new-close', closeWhatsNew);

    // Clicking the dimmed area closes it too, but clicking the panel must not.
    $(document).on('click', '#shipping-desk-whats-new', function (e) {
        if (e.target === this) closeWhatsNew();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            closeWhatsNew();
        }
    });
})(jQuery);
