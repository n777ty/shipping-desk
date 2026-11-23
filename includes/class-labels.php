<?php
/**
 * Buying labels: the single and bulk purchase, the fatal-error guard around them, the
 * per-order lock, the insurance notes, and the tracking and cost bookkeeping a purchase
 * writes.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The provider, its mode, the
 * clients and the capability check are still asked of the plugin, so there is one answer to each.
 */
class Shipping_Desk_Labels {

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    /**
     * What was bought, once the purchase is durable.
     *
     * Set the moment the label meta is saved, so a fatal raised afterwards — by an email
     * template, a notification hook, anything that runs on completion — can still report
     * the purchase instead of looking like a failure.
     *
     * @var array
     */
    protected $committed_purchase = [];

    /** @var Shipping_Desk_Purchase_Lock|null */
    protected $_purchase_lock = null;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Register the AJAX actions this class answers.
     *
     * @return void
     */
    public function init() {
        foreach ( [ 'shipping_desk_buy_label', 'shipping_desk_bulk_buy' ] as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, $action ] );
        }
    }

    /**
     * BrikPanel's shipping-cost meta key, or '' when that plugin is not installed.
     *
     * Read from their constant rather than hardcoded: no configuration here, and no
     * chance of writing a key that nothing reads.
     *
     * @return string
     */
    protected function brikpanel_cost_meta() {
        return defined( 'BRIKPANEL_SHIPPING_COST_META' ) ? (string) BRIKPANEL_SHIPPING_COST_META : '';
    }

    /**
     * Record what the label cost, so BrikPanel can use it for profit.
     *
     * Optional by design: with no BrikPanel installed nothing is attempted, written
     * or called. The value is overwritten on every purchase — the carrier's figure is
     * the actual cost, and a reship legitimately costs something new — and a test
     * label writes nothing, because a test label costs nothing.
     *
     * @param WC_Order $order    Order being shipped.
     * @param string   $provider Provider that sold the label.
     * @param string   $mode     Key mode: 'test' or 'live' ('' for Veeqo).
     * @param array    $shipment Bought shipment.
     * @return void
     */
    /**
     * Hand a bought label's tracking to whoever owns tracking on this site, and record the
     * provider reference in a private note.
     *
     * The tracking number deliberately does not appear in that note: AST PRO's EasyPost
     * integration imports carrier + tracking it finds in notes, so repeating it there would
     * add a second item on top of the one we just inserted. The reference id is the part
     * worth keeping for refunds and support.
     *
     * @param WC_Order $order        Order.
     * @param string   $provider     shippo|easypost|veeqo.
     * @param string   $tracking     Tracking number.
     * @param string   $carrier_id   Carrier as the provider reports it.
     * @param string   $tracking_url Carrier tracking URL.
     * @param string   $label_id     Provider label/transaction id.
     * @param string   $remote_id    Remote shipment id.
     * @param string   $insured      Declared value, if any.
     * @param string   $insured_fee  Premium, if any.
     * @return void
     */
    protected function record_label_tracking( WC_Order $order, $provider, $tracking, $carrier_id, $tracking_url, $label_id, $remote_id, $insured = '', $insured_fee = '' ) {
        $reference_label = 'shippo' === $provider
            ? __( 'Shippo Transaction ID:', 'shipping-desk' )
            : ( 'easypost' === $provider
                ? __( 'EasyPost Shipment ID:', 'shipping-desk' )
                : __( 'Veeqo Remote Shipment ID:', 'shipping-desk' ) );

        $reference_value = in_array( $provider, [ 'shippo', 'easypost' ], true ) ? $label_id : $remote_id;

        // A carrier with no known URL leaves tracking_url() returning the number itself;
        // treat anything that is not a URL as "no link" rather than printing a broken one.
        if ( ! preg_match( '#^https?://#i', (string) $tracking_url ) ) {
            $tracking_url = '';
        }

        // The private note goes in before the hand-off so the timeline reads in the order
        // things happened: label bought, tracking added, status changed, email sent.
        $extra = [];
        if ( '' !== (string) $insured ) {
            $cover = sprintf(
                /* translators: %s: insured value, formatted with currency. */
                __( 'Insured for %s', 'shipping-desk' ),
                wc_price( (float) $insured, [ 'currency' => $order->get_currency() ] )
            );
            if ( '' !== (string) $insured_fee ) {
                $cover .= ' ' . sprintf(
                    /* translators: %s: insurance premium, formatted with currency. */
                    __( '(premium %s)', 'shipping-desk' ),
                    wc_price( (float) $insured_fee, [ 'currency' => $order->get_currency() ] )
                );
            }
            $extra[] = $cover . '.';
        }

        $order->add_order_note(
            Shipping_Desk_Tracking_Relay::private_note(
                Shipping_Desk_Tracking_Relay::provider_label( $provider ),
                $reference_label,
                $reference_value,
                $extra
            )
        );

        Shipping_Desk_Tracking_Relay::record(
            $order,
            [
                'number'  => $tracking,
                'carrier' => $carrier_id,
                'url'     => $tracking_url,
            ]
        );
    }

    /**
     * Take a refunded label's tracking number back out of the tracking plugin.
     *
     * Both AST and Shipment Tracking append items, and the customer's email lists
     * every item on the order — so a voided number has to go, or the replacement
     * ships with the dead number displayed beside it.
     *
     * The note names the plugin and never the number: AST PRO's integrations parse
     * order notes for carrier + tracking and would import it straight back.
     *
     * @param WC_Order $order  Order.
     * @param string   $number Tracking number of the refunded label.
     * @return void
     */
    public function remove_dead_tracking( WC_Order $order, $number ) {
        $report = Shipping_Desk_Tracking_Relay::forget( $order, (string) $number );

        if ( $report['removed'] < 1 ) {
            return;
        }

        $order->add_order_note(
            sprintf(
                /* translators: %s: tracking plugin name, e.g. Advanced Shipment Tracking. */
                __( 'Shipping Desk: removed the refunded tracking number from %s.', 'shipping-desk' ),
                $report['label']
            )
        );
    }

    protected function record_shipping_cost( WC_Order $order, $provider, $mode, array $shipment ) {
        $key = $this->brikpanel_cost_meta();
        if ( '' === $key ) {
            return;
        }
        if ( function_exists( 'brikpanel_order_shipping_cost_active' ) && ! brikpanel_order_shipping_cost_active() ) {
            return;
        }
        if ( 'test' === $mode ) {
            return;
        }
        if ( ! function_exists( 'wc_format_decimal' ) ) {
            return;
        }

        $cost = Shipping_Desk_Helpers::label_cost( $provider, $shipment );
        if ( empty( $cost ) ) {
            return;
        }

        $decimals  = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        $formatted = wc_format_decimal( $cost['amount'], $decimals );
        $before    = (string) $order->get_meta( $key );
        $m         = Shipping_Desk_Helpers::meta_keys();

        $order->update_meta_data( $key, $formatted );
        $order->update_meta_data( $m['cost_paid'], [
            'amount'   => $formatted,
            'currency' => $cost['currency'],
            'provider' => $provider,
            'at'       => time(),
        ] );
        $order->save();

        // BrikPanel caches its dashboard payload against order EVENTS, and a label
        // purchase is not one, so without this the new cost would not show until the
        // transient expired. Only on a real change, exactly as their own save does.
        if ( $before !== $formatted && function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    /**
     * Remove the recorded cost after a refund.
     *
     * BrikPanel reads an empty value as "use what was charged", which is the right
     * answer once the carrier has given the money back.
     *
     * @param WC_Order $order Order whose label was refunded.
     * @return void
     */
    public function clear_shipping_cost( WC_Order $order ) {
        $key = $this->brikpanel_cost_meta();
        if ( '' === $key ) {
            return;
        }

        $m        = Shipping_Desk_Helpers::meta_keys();
        $recorded = $order->get_meta( $m['cost_paid'] );
        $had_cost = '' !== (string) $order->get_meta( $key );

        if ( ! $had_cost && ! is_array( $recorded ) ) {
            return;
        }

        $order->delete_meta_data( $key );
        $order->delete_meta_data( $m['cost_paid'] );
        $order->save();

        if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
            brikpanel_bust_data_caches();
        }
    }

    /**
     * Rate object supplied by the client: either an array or a JSON string.
     *
     * The rate is where the carrier, service and price live — a Shippo
     * transaction only returns a rate ID — and it is sent as JSON because nested
     * POST arrays are rejected by some WAF rules before PHP runs.
     *
     * @param mixed $value Raw request value.
     * @return array
     */
    protected function parse_rate_input( $value ) {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        return is_array( $value ) ? $value : [];
    }

    /**
     * Turn a fatal error into a JSON response.
     *
     * A label purchase can outlive the PHP execution limit. Without this the
     * browser only sees a failed request and reports "please try again", while a
     * label may already exist in Shippo — the one case where retrying can
     * double-charge. At least now the operator gets a statement of what happened.
     */
    protected function guard_fatal_errors() {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        register_shutdown_function( function () {
            $error = error_get_last();
            if ( ! is_array( $error ) ) {
                return;
            }
            $fatal = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
            if ( ! in_array( (int) $error['type'], $fatal, true ) || headers_sent() ) {
                return;
            }

            // The JSON answer reaches the browser, but a fatal is exactly when a server-side
            // trace is wanted, so it is written to the PHP error log as well.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: a fatal during a purchase must be logged.
            error_log( 'Shipping Desk: fatal during label purchase - ' . (string) $error['message'] . ' in ' . (string) $error['file'] . ':' . (int) $error['line'] );

            if ( ! empty( $this->committed_purchase ) ) {
                // The label already exists, so this is not a failed purchase: something
                // that runs after it broke. Saying "error" here would send the operator
                // to re-buy a label they own.
                //
                // The lock is released first: wp_send_json_success() ends the request, and PHP does
                // not run the shutdown functions that were registered after one that has exited.
                $this->purchase_lock()->release_all();
                wp_send_json_success(
                    array_merge(
                        $this->committed_purchase,
                        [
                            'warning' => sprintf(
                                /* translators: 1: PHP error message, 2: file name, 3: line number */
                                __( 'The label was bought and its tracking number recorded, but a PHP error interrupted what followed: %1$s (%2$s:%3$d). Check the order - a customer email may not have been sent.', 'shipping-desk' ),
                                $error['message'],
                                basename( (string) $error['file'] ),
                                (int) $error['line']
                            ),
                        ]
                    )
                );
                return;
            }

            // No label is known to exist and none is known not to: the request died somewhere
            // between asking the provider and saving the answer. The lock is deliberately NOT
            // released: it expires by itself, and until it does a second click cannot buy again
            // behind a purchase that may already have gone through. keep_all() says so out loud,
            // so this does not depend on the order shutdown functions happen to run in.
            $this->purchase_lock()->keep_all();
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: 1: PHP error message, 2: file name, 3: line number */
                        __( 'The request was interrupted by a PHP error: %1$s (%2$s:%3$d). Check the Shippo dashboard before buying again.', 'shipping-desk' ),
                        $error['message'],
                        basename( (string) $error['file'] ),
                        (int) $error['line']
                    ),
                ],
                500
            );
        } );
    }

    /**
     * Give a purchase request room to finish.
     *
     * A Shippo transaction can take 30s+ and the reconciliation that follows a
     * timeout needs longer still; with the default 30s limit PHP would kill the
     * request mid-purchase, which is exactly when a retry is dangerous.
     */
    protected function extend_time_limit() {
        @set_time_limit( 180 );
    }

    public function shipping_desk_buy_label() {
        $this->plugin->require_cap();
        $this->extend_time_limit();
        $this->guard_fatal_errors();

        $order_id     = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $rate_id      = isset( $_POST['rate_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rate_id'] ) ) : '';
        $remote_id    = isset( $_POST['remote_id'] ) ? sanitize_text_field( wp_unslash( $_POST['remote_id'] ) ) : '';
        $rate         = isset( $_POST['rate'] ) ? $this->parse_rate_input( wp_unslash( $_POST['rate'] ) ) : [];
        $vas_input    = ( isset( $_POST['vas'] ) && is_array( $_POST['vas'] ) ) ? wp_unslash( $_POST['vas'] ) : [];

        // What the operator asked to insure, kept so the bought shipment can be checked
        // against it. EasyPost accepts the value whether or not the carrier applies it.
        $requested_insurance = isset( $vas_input['liability_amount'] ) ? (float) $vas_input['liability_amount'] : 0.0;
        $total_weight = isset( $_POST['total_weight'] ) ? floatval( $_POST['total_weight'] ) : 0.0;
        $box          = $this->sanitize_box_input( isset( $_POST['box'] ) ? $_POST['box'] : [] );

        // A manual box has no stored dimensions: a missing one means the request did not come
        // from the Manual panel, so refuse rather than buy against a zero-dimension parcel.
        $box_key = isset( $_POST['box_key'] ) ? sanitize_text_field( wp_unslash( $_POST['box_key'] ) ) : '';
        if ( 'manual' === $box_key && $this->box_dimensions_missing( $box ) ) {
            wp_send_json_error( [ 'message' => __( 'Enter the box length, width and height.', 'shipping-desk' ) ], 400 );
        }

        // One purchase per order at a time. "Already purchased" below reads meta that is only
        // written after the provider has answered, which can take half a minute: without this a
        // double click or a second tab passes that check too, and the provider is paid twice.
        //
        // Taken before the order is loaded, not after: the lock never waits, so an order loaded
        // first could be a copy from just before the other purchase saved its label. Loaded
        // after, it is read once the winner has either finished or not started.
        if ( $order_id && ! $this->purchase_lock()->acquire( $order_id ) ) {
            wp_send_json_error( [ 'message' => $this->purchase_in_progress_message() ], 409 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'shipping-desk' ) ], 404 );
        }
        if ( empty( $rate_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate ID missing.', 'shipping-desk' ) ], 400 );
        }

        $m = Shipping_Desk_Helpers::meta_keys();
        if ( $order->get_meta( $m['remote_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Label already purchased.', 'shipping-desk' ) ] );
        }

        // Resolve once: the marker written below must name the provider that
        // actually bought the label, not whatever the setting says later.
        $provider = $this->plugin->provider();
        $mode     = $this->plugin->provider_mode( $provider );

        // A stored quote may only be bought while it is still valid. An expired carrier
        // quote, or one priced for a dispatch day that has since moved on, would fail
        // at the carrier or print the wrong date — ask for fresh rates instead.
        $quote_record = $this->plugin->quote_store()->get_quote_record( $order_id );
        if ( ! empty( $quote_record ) ) {
            if ( ! Shipping_Desk_Helpers::quote_record_matches_provider( $quote_record, $provider, $mode ) ) {
                // Priced by the provider or label mode that has since been switched away
                // from: its rate ids and shipment id cannot be bought by this account.
                $this->plugin->quote_store()->delete_quote_record( $order_id );
                wp_send_json_error( [
                    'message' => __( 'These rates were priced by a different provider or label mode. Get rates again before buying.', 'shipping-desk' ),
                ], 409 );
            }

            if ( ! Shipping_Desk_Helpers::quote_record_is_fresh( $quote_record, $this->plugin->quote_store()->current_dispatch_day( $provider ) ) ) {
                $this->plugin->quote_store()->delete_quote_record( $order_id );
                wp_send_json_error( [
                    'message' => __( 'These rates have expired: the dispatch day has moved on or the carrier quote timed out. Get rates again before buying.', 'shipping-desk' ),
                ], 409 );
            }

            $known_rate = false;
            foreach ( (array) ( isset( $quote_record['rates'] ) ? $quote_record['rates'] : [] ) as $stored_rate ) {
                if ( isset( $stored_rate['rate_id'] ) && (string) $stored_rate['rate_id'] === (string) $rate_id ) {
                    $known_rate = true;
                    break;
                }
            }
            if ( ! $known_rate ) {
                $this->plugin->quote_store()->delete_quote_record( $order_id );
                wp_send_json_error( [
                    'message' => __( 'That rate is no longer part of the stored quotes. Get rates again before buying.', 'shipping-desk' ),
                ], 409 );
            }
        }

        $result = $this->plugin->api( $provider, $mode )->book_shipment( $remote_id, $rate_id, $rate, $order_id, $vas_input );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        $successful = $result['successful'] ?? [];
        if ( empty( $successful ) || ! is_array( $successful ) ) {
            wp_send_json_error( [ 'message' => __( 'The shipping provider did not return a successful shipment.', 'shipping-desk' ) ], 500 );
        }

        $first_key = array_key_first( $successful );
        $shipment  = $successful[ $first_key ];
        $label_id  = $first_key;
        $label_url = $shipment['labels'][0]['url'] ?? '';
        $label_url = Shipping_Desk_Helpers::absolute_url( $label_url );
        $tracking  = $shipment['tracking_number'] ?? '';
        $carrier_id = $shipment['carrier_id'] ?? '';

        // Persist meta via CRUD (HPOS-safe).
        $order->update_meta_data( $m['shipment_id'], $shipment['id'] ?? '' );
        $order->update_meta_data( $m['remote_id'], $remote_id );
        $order->update_meta_data( $m['label_id'], $label_id );
        // The URL the buy response carried. Print asks the provider for a fresh one first -
        // Veeqo and Shippo sign theirs with an expiry - but this is what saves a print when
        // EasyPost has not finished building the file yet.
        if ( '' !== $label_url ) {
            $order->update_meta_data( $m['label_url'], $label_url );
        }
        $order->update_meta_data( $m['tracking'], $tracking );
        $order->update_meta_data( $m['provider'], $provider );
        $order->update_meta_data( $m['provider_mode'], $mode );
        $order->update_meta_data( $m['final_weight'], $total_weight > 0 ? $total_weight : null );
        $order->update_meta_data( $m['box_l'], isset( $box['length'] ) ? floatval( $box['length'] ) : 0 );
        $order->update_meta_data( $m['box_w'], isset( $box['width'] ) ? floatval( $box['width'] ) : 0 );
        $order->update_meta_data( $m['box_h'], isset( $box['height'] ) ? floatval( $box['height'] ) : 0 );
        $order->update_meta_data( $m['box_weight'], isset( $box['weight'] ) ? floatval( $box['weight'] ) : 0 );
        if ( ! empty( $carrier_id ) ) {
            $order->update_meta_data( $m['carrier'], $carrier_id );
        }

        // Insurance actually attached to this label. EasyPost reports the declared
        // value on the shipment and the premium as a fee; storing both is what makes
        // "was this order covered, and what did it cost?" answerable afterwards.
        $insured     = isset( $shipment['insurance'] ) ? trim( (string) $shipment['insurance'] ) : '';
        $insured_fee = isset( $shipment['insurance_fee'] ) ? trim( (string) $shipment['insurance_fee'] ) : '';
        if ( '' !== $insured ) {
            $order->update_meta_data( $m['insurance'], $insured );
        }
        if ( '' !== $insured_fee ) {
            $order->update_meta_data( $m['insurance_fee'], $insured_fee );
        }

        // "Actually insured" is a question only the bought shipment can answer: EasyPost
        // takes the declared value whether or not the carrier applies cover. Compare the
        // two and say so, in the notes and to the operator, rather than leaving a parcel
        // believed covered until it goes missing.
        //
        // EasyPost only: it reports the cover on the shipment, so a missing value there
        // means it really is missing. Shippo prices XCover into the rate and Veeqo quotes
        // it after the quotes, so neither returns a comparable field here.
        $insurance_warning = '';
        if ( 'easypost' === $provider ) {
            if ( Shipping_Desk_Helpers::insurance_shortfall( $requested_insurance, $insured ) ) {
                $insurance_warning = $this->insurance_shortfall_note( $order, $requested_insurance, $insured );
            } elseif ( Shipping_Desk_Helpers::insurance_premium_missing( $insured, $insured_fee, '' !== $label_url ) ) {
                // A declared value with no premium behind it: the shipment carries the
                // value, EasyPost reports no charge for it. Sometimes that is a cover
                // bought without a fee, sometimes a value recorded with no label — the
                // operator can tell which in one glance at the dashboard.
                $insurance_warning = $this->insurance_unpriced_note( $order, $insured );
            }

            if ( '' !== $insurance_warning ) {
                $order->add_order_note( $insurance_warning );
            }
        }

        // No address correction is applied here: an accepted suggestion was written
        // onto the order when it was accepted, so the label was quoted and bought
        // from the same address the order now carries.
        $order->save();

        // From here the label exists. Anything that runs on completion and fatals - a
        // notification hook, a third-party email template - must not be reported as a
        // failed purchase.
        $this->committed_purchase = [
            'label_id'      => $label_id,
            'label_url'     => $label_url,
            'tracking'      => $tracking,
            'insurance'     => $insured,
            'insurance_fee' => $insured_fee,
        ];

        // Whether the order was already Completed *before* this purchase. The relay call
        // below completes it when the setting allows, so asking afterwards would classify
        // every normal purchase as a reship.
        $was_completed = $order->has_status( 'completed' );

        // Tracking goes to whichever plugin owns tracking on this site — AST first, then
        // Shipment Tracking — or to the customer by note when neither is installed.
        //
        // Run even when the provider returned no tracking number: the label exists, and the
        // relay call inside also completes the order (when the setting allows). Skipping it
        // left a numberless label's order in Processing, where the buy guard already reads
        // the label meta as "already purchased" and Refund/Reset are out of reach.
        $this->record_label_tracking(
            $order,
            $provider,
            (string) $tracking,
            $carrier_id,
            Shipping_Desk_Helpers::tracking_url( $carrier_id, (string) $tracking ),
            $label_id,
            $remote_id,
            $insured,
            $insured_fee
        );

        // The quotes are now spent: they must not be reused for a reship or shown
        // next to a row that already has a label.
        $this->plugin->quote_store()->delete_quote_record( $order_id );

        // What the carrier actually charged, for BrikPanel's profit figures. No-op
        // when BrikPanel is absent or the label came from the test account.
        $this->record_shipping_cost( $order, $provider, $mode, $shipment );

        // The day's running total behind the header badge. Deliberately separate from
        // record_shipping_cost(), which is a no-op without BrikPanel.
        Shipping_Desk_Helpers::record_daily_spend( $provider, $shipment, $mode );

        // Reshipping a completed order does not change its status, so the normal
        // status-change notification never fires. Re-trigger the WooCommerce
        // "Completed order" email so the customer receives the new tracking
        // number.
        //
        // The original tracking is deliberately kept: a reship means two parcels
        // really went out and both belong on the order. If the first label was
        // refunded first, its number has already been removed by then.
        //
        // OFF by default: a tracking plugin may email the customer when it sees the new
        // tracking, and two emails is worse than one. That is not a guarantee - a
        // reship never changes the order's status, and Advanced Shipment Tracking sent
        // nothing on a reshipped completed order when tested, even though the tracking
        // reached its widget through ast_insert_tracking_number() - so the setting
        // exists to be switched on when nothing else notifies the customer.
        $reship_notified = false;
        if ( $was_completed && get_option( 'shipping_desk_reship_send_completed_email', 0 ) ) {
            do_action( 'woocommerce_order_status_completed_notification', $order_id, $order );
            $reship_notified = true;
            $order->add_order_note( __( 'Shipping Desk: reshipped completed order; customer "Completed order" email re-triggered.', 'shipping-desk' ) );
        } elseif ( $was_completed ) {
            $order->add_order_note( __( 'Shipping Desk: reshipped completed order. No customer email was sent, because "Completed email on reship" is off in Settings; add a customer note here to tell them about the new tracking.', 'shipping-desk' ) );
        }

        wp_send_json_success( [
            'label_id'  => $label_id,
            'label_url' => $label_url,
            'tracking'  => $tracking,
            // So the confirmation can name the cover rather than leaving the operator
            // to infer it from a total that is larger than the quote.
            'insurance'     => $insured,
            'insurance_fee' => $insured_fee,
            // Set only when cover was asked for and the bought label does not carry it.
            'insurance_warning' => $insurance_warning,
            'reship_notified' => $reship_notified,
        ] );
    }

    /**
     * The note written when a label's cover did not match what was asked for.
     *
     * Shared by single and bulk purchases so the wording cannot drift, and so both can
     * be grepped for afterwards.
     *
     * @param WC_Order $order     Order.
     * @param float    $requested Declared value sent with the buy call.
     * @param string   $applied   Declared value reported on the bought shipment.
     * @return string
     */
    protected function insurance_shortfall_note( WC_Order $order, $requested, $applied ) {
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $order->get_currency() ) : '';

        return sprintf(
            /* translators: 1: amount requested, 2: amount the provider reports as insured */
            __( 'Shipping Desk: insurance for %1$s was requested, but EasyPost reports this label insured for %2$s. It is not covered as ordered - check before shipping.', 'shipping-desk' ),
            $symbol . number_format( (float) $requested, 2, '.', '' ),
            '' !== (string) $applied
                ? $symbol . number_format( (float) $applied, 2, '.', '' )
                : __( 'nothing', 'shipping-desk' )
        );
    }

    /**
     * The note written when cover is recorded but no premium was reported.
     *
     * @param WC_Order $order   Order.
     * @param string   $insured Declared value reported on the shipment.
     * @return string
     */
    protected function insurance_unpriced_note( WC_Order $order, $insured ) {
        $symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $order->get_currency() ) : '';

        return sprintf(
            /* translators: %s: the declared value the label reports */
            __( 'Shipping Desk: this label records insurance for %s, but EasyPost reported no premium for it. Confirm the cover in the EasyPost dashboard before shipping.', 'shipping-desk' ),
            $symbol . number_format( (float) $insured, 2, '.', '' )
        );
    }

    public function shipping_desk_bulk_buy() {
        $this->plugin->require_cap();
        $this->extend_time_limit();
        $this->guard_fatal_errors();
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        $nonce_valid = $nonce && (
            wp_verify_nonce( $nonce, 'shipping_desk_bulk_buy' ) ||
            wp_verify_nonce( $nonce, 'shipping_desk_order_0' )
        );
        // Also accept any per-order nonce supplied with the payload (the same
        // action used by single-order purchases).
        if ( ! $nonce_valid ) {
            $nonce_entries = [];
            if ( isset( $_POST['orders_json'] ) ) {
                $decoded_nonce = json_decode( wp_unslash( $_POST['orders_json'] ), true );
                if ( is_array( $decoded_nonce ) ) {
                    $nonce_entries = $decoded_nonce;
                }
            } elseif ( isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ) {
                $nonce_entries = wp_unslash( $_POST['orders'] );
            }
            foreach ( $nonce_entries as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['order_id'] ) ) {
                    continue;
                }
                if ( $nonce && wp_verify_nonce( $nonce, 'shipping_desk_order_' . intval( $entry['order_id'] ) ) ) {
                    $nonce_valid = true;
                    break;
                }
            }
        }
        if ( ! $nonce_valid ) {
            wp_send_json_error( [
                'message'  => __( 'Invalid nonce.', 'shipping-desk' ),
                'source'   => 'shipping_desk_bulk_buy',
                'received' => $nonce ? 'present' : 'missing',
                'length'   => strlen( (string) $nonce ),
            ], 400 );
        }

        $orders_input = [];
        if ( isset( $_POST['orders_json'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['orders_json'] ), true );
            if ( is_array( $decoded ) ) {
                $orders_input = $decoded;
            }
        }
        if ( empty( $orders_input ) && isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ) {
            $orders_input = wp_unslash( $_POST['orders'] );
        }
        if ( empty( $orders_input ) ) {
            wp_send_json_error( [ 'message' => __( 'No shipments to book.', 'shipping-desk' ) ], 400 );
        }

        $m               = Shipping_Desk_Helpers::meta_keys();
        $shipments       = [];
        $order_meta      = [];
        $skipped         = [];

        foreach ( $orders_input as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $order_id     = isset( $entry['order_id'] ) ? intval( $entry['order_id'] ) : 0;
            $rate_id      = isset( $entry['rate_id'] ) ? sanitize_text_field( $entry['rate_id'] ) : '';
            $remote_id    = isset( $entry['remote_id'] ) ? sanitize_text_field( $entry['remote_id'] ) : '';
            $total_weight = isset( $entry['total_weight'] ) ? floatval( $entry['total_weight'] ) : 0.0;
            $box          = $this->sanitize_box_input( isset( $entry['box'] ) ? $entry['box'] : [] );
            $rate         = isset( $entry['rate'] ) ? $this->parse_rate_input( $entry['rate'] ) : [];

            // A manual box carries its dimensions with the entry; if they are missing the run
            // did not come from the Manual panel, so skip rather than buy a zero-dimension parcel.
            $box_key = isset( $entry['box_key'] ) ? sanitize_text_field( $entry['box_key'] ) : '';
            if ( 'manual' === $box_key && $this->box_dimensions_missing( $box ) ) {
                $skipped[ $order_id ] = __( 'Enter the box length, width and height.', 'shipping-desk' );
                continue;
            }

            // The same lock a single purchase takes, so a batch and a click on one order cannot
            // both buy. Taken before the order is loaded, for the reason given above.
            if ( $order_id && ! $this->purchase_lock()->acquire( $order_id ) ) {
                $skipped[ $order_id ] = $this->purchase_in_progress_message();
                continue;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                $skipped[ $order_id ] = __( 'Order not found.', 'shipping-desk' );
                continue;
            }
            if ( $order->get_meta( $m['remote_id'] ) ) {
                $skipped[ $order_id ] = __( 'Label already purchased.', 'shipping-desk' );
                continue;
            }
            if ( empty( $rate_id ) || empty( $remote_id ) ) {
                $skipped[ $order_id ] = __( 'Rate or shipment ID missing.', 'shipping-desk' );
                continue;
            }

            $vas_fields = [];
            if ( ! empty( $entry['vas'] ) && is_array( $entry['vas'] ) ) {
                foreach ( $entry['vas'] as $vas_key => $vas_val ) {
                    if ( ! is_string( $vas_key ) || $vas_key === '' ) {
                        continue;
                    }
                    $vas_fields[ sanitize_text_field( $vas_key ) ] = is_scalar( $vas_val )
                        ? sanitize_text_field( (string) $vas_val )
                        : '';
                }
            }

            $ship = [
                'remote_shipment_id' => $remote_id,
                'rate_id'            => $rate_id,
                // The rate carries the carrier, service and price: a Shippo
                // transaction returns only a rate ID, so without this the order
                // note cannot name the carrier (and AST cannot complete the order).
                'rate'               => $rate,
                'order_id'           => (string) $order_id,
                'custom_messages'    => [ (string) $order_id ],
            ];
            foreach ( $vas_fields as $key => $val ) {
                $ship[ $key ] = $val;
            }

            $shipments[ $order_id ] = $ship;
            $order_meta[ $order_id ] = [
                'box'          => $box,
                'total_weight' => $total_weight,
            ];
        }

        // TO BE CONTINUED  -  response handling

        $purchased = 0;
        $failed    = 0;
        $errors    = [];
        // One line per order whose label came back without the cover that was asked for.
        $insurance_warnings = [];

        if ( ! empty( $shipments ) ) {
            // One request, one provider: stamp the same marker on every order it
            // buys for, so print and refund can find the right client later.
            $provider = $this->plugin->provider();
            $mode     = $this->plugin->provider_mode( $provider );

            $result = $this->plugin->api( $provider, $mode )->book_shipments( $shipments );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
            }

            $successful = $result['successful'] ?? [];
            foreach ( $successful as $label_id => $shipment ) {
                $order_id = (int) ( $shipment['customer_reference'] ?? 0 );
                if ( ! $order_id || ! isset( $shipments[ $order_id ] ) ) {
                    continue;
                }
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    continue;
                }
                $order_data = $order_meta[ $order_id ];

                $tracking   = $shipment['tracking_number'] ?? '';
                $carrier_id = $shipment['carrier_id'] ?? '';

                // Cover on a bulk-bought label, read exactly as a single purchase reads it:
                // without this the order stays silent about whether it was insured.
                $insured     = isset( $shipment['insurance'] ) ? trim( (string) $shipment['insurance'] ) : '';
                $insured_fee = isset( $shipment['insurance_fee'] ) ? trim( (string) $shipment['insurance_fee'] ) : '';

                $order->update_meta_data( $m['shipment_id'], $shipment['id'] ?? '' );
                $order->update_meta_data( $m['remote_id'],   $shipments[ $order_id ]['remote_shipment_id'] );
                $order->update_meta_data( $m['label_id'],    $label_id );
                $order->update_meta_data( $m['tracking'],    $tracking );
                $order->update_meta_data( $m['provider'],      $provider );
                $order->update_meta_data( $m['provider_mode'], $mode );
                $order->update_meta_data( $m['final_weight'], $order_data['total_weight'] > 0 ? $order_data['total_weight'] : null );
                $box = $order_data['box'];
                $order->update_meta_data( $m['box_l'],       isset( $box['length'] ) ? floatval( $box['length'] ) : 0 );
                $order->update_meta_data( $m['box_w'],       isset( $box['width'] )  ? floatval( $box['width'] )  : 0 );
                $order->update_meta_data( $m['box_h'],       isset( $box['height'] ) ? floatval( $box['height'] ) : 0 );
                $order->update_meta_data( $m['box_weight'],  isset( $box['weight'] ) ? floatval( $box['weight'] ) : 0 );
                if ( ! empty( $carrier_id ) ) {
                    $order->update_meta_data( $m['carrier'], $carrier_id );
                }

                if ( '' !== $insured ) {
                    $order->update_meta_data( $m['insurance'], $insured );
                }
                if ( '' !== $insured_fee ) {
                    $order->update_meta_data( $m['insurance_fee'], $insured_fee );
                }

                // Same question as a single purchase: does the bought label carry the
                // cover that was asked for? The requested value travels with the entry.
                $requested_bulk = isset( $shipments[ $order_id ]['liability_amount'] ) ? (float) $shipments[ $order_id ]['liability_amount'] : 0.0;
                if ( 'easypost' === $provider ) {
                    if ( Shipping_Desk_Helpers::insurance_shortfall( $requested_bulk, $insured ) ) {
                        $warning = $this->insurance_shortfall_note( $order, $requested_bulk, $insured );
                    } elseif ( Shipping_Desk_Helpers::insurance_premium_missing( $insured, $insured_fee, ! empty( $shipment['label_url'] ) ) ) {
                        $warning = $this->insurance_unpriced_note( $order, $insured );
                    }

                    if ( isset( $warning ) && '' !== $warning ) {
                        $order->add_order_note( $warning );
                        $insurance_warnings[] = 'Order #' . $order_id . ': ' . $warning;
                    }
                    unset( $warning );
                }

                // As in a single purchase: the address was corrected when the
                // operator accepted it, so only the label meta is written here.
                $order->save();

                $this->record_label_tracking(
                    $order,
                    $provider,
                    $tracking,
                    $carrier_id,
                    Shipping_Desk_Helpers::tracking_url( $carrier_id, $tracking ),
                    $label_id,
                    $shipments[ $order_id ]['remote_shipment_id'],
                    $insured,
                    $insured_fee
                );
                $this->plugin->quote_store()->delete_quote_record( $order_id );
                $this->record_shipping_cost( $order, $provider, $mode, $shipment );
                Shipping_Desk_Helpers::record_daily_spend( $provider, $shipment, $mode );
                $purchased++;
                unset( $skipped[ $order_id ] );
            }

            $failed_shipments = $result['failed'] ?? [];
            foreach ( $failed_shipments as $failed_remote_id => $failed_data ) {
                $order_id = (int) ( $failed_data['customer_reference'] ?? 0 );
                if ( $order_id && isset( $shipments[ $order_id ] ) ) {
                    $failed++;
                    $error_msg = isset( $failed_data['error_messages'] ) && is_array( $failed_data['error_messages'] )
                        ? implode( '; ', $failed_data['error_messages'] )
                        : __( 'Label purchase failed.', 'shipping-desk' );
                    $errors[]             = $error_msg;
                    $skipped[ $order_id ] = $error_msg;
                }
            }
        }

        wp_send_json_success( [
            'purchased'  => $purchased,
            'failed'     => $failed + count( $skipped ),
            'errors'     => array_merge( $errors, array_values( $skipped ) ),
            'insurance_warnings' => $insurance_warnings,
            'order_ids'   => array_merge(
                array_keys( $shipments ),
                array_keys( $skipped )
            ),
        ] );
    }

    /**
     * Keep only the four box fields a purchase uses, each coerced to a number.
     *
     * The box arrives from the browser and is never trusted as-is: only the dimensions that
     * are stored on the order are read out of it, so an extra key cannot travel any further.
     *
     * @param mixed $input Raw box input, POST or a bulk entry.
     * @return array<string,float>
     */
    protected function sanitize_box_input( $input ) {
        $input = is_array( $input ) ? $input : [];
        $box   = [];

        foreach ( [ 'length', 'width', 'height', 'weight' ] as $key ) {
            $box[ $key ] = isset( $input[ $key ] ) ? floatval( $input[ $key ] ) : 0.0;
        }

        return $box;
    }

    /**
     * True when a box is missing one of the three dimensions a parcel is priced on.
     *
     * Only the manual path can be missing them - a saved box always has all three - so this
     * says whether a Manual purchase really came from the panel with its dimensions filled in.
     *
     * @param array<string,float> $box Box as sanitize_box_input() returns it.
     * @return bool
     */
    protected function box_dimensions_missing( $box ) {
        foreach ( [ 'length', 'width', 'height' ] as $dimension ) {
            if ( ! isset( $box[ $dimension ] ) || (float) $box[ $dimension ] <= 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The per-order lock around buying a label.
     *
     * @return Shipping_Desk_Purchase_Lock
     */
    protected function purchase_lock() {
        if ( ! isset( $this->_purchase_lock ) ) {
            require_once __DIR__ . '/class-purchase-lock.php';
            $this->_purchase_lock = new Shipping_Desk_Purchase_Lock();
        }
        return $this->_purchase_lock;
    }

    /**
     * What to say when another purchase holds the order.
     *
     * Shared by single and bulk purchases so the wording cannot drift. It names the second case
     * on purpose: a purchase that died without an answer keeps its lock until it expires,
     * because whether a label was bought is exactly what nobody knows yet.
     *
     * @return string
     */
    protected function purchase_in_progress_message() {
        return __( 'A purchase for this order is already in progress, or one ended without an answer in the last five minutes. Check the provider dashboard for a label before trying again.', 'shipping-desk' );
    }
}
