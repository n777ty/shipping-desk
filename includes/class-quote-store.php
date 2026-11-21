<?php
/**
 * Stored quotes and remembered address verdicts.
 *
 * A quote is a set of rates priced by one provider account for one dispatch day, kept so a
 * reload or another browser shows the same prices without paying for them again; an address
 * verdict is what the verifier said about one order's address, kept so the same answer is not
 * bought twice. Both are expiring transients, deleted by prefix when the provider or its
 * mode changes.
 *
 * Moved out of Shipping_Desk_Plugin without changing what it does. The provider, its mode and
 * the client are still asked of the plugin, so there is one answer to each.
 */
class Shipping_Desk_Quote_Store {

    /**
     * How long a validation result is reused, in seconds.
     *
     * Addresses do not change while a row is being worked on, and Get Rates is
     * clicked again after every box, signature or insurance change. One paid call
     * per address per half-day.
     */
    const ADDRESS_VALIDATION_TTL = 43200;

    /**
     * Hard cap on how long a stored quote may be reused, in hours.
     *
     * The dispatch day is the real limit and is checked at read time; this cap only
     * stops a quiet weekend from leaving Friday's prices on screen on Tuesday.
     */
    const QUOTE_RECORD_CAP_HOURS = 72;

    /** @var Shipping_Desk_Plugin */
    protected $plugin;

    public function __construct( Shipping_Desk_Plugin $plugin ) {
        $this->plugin = $plugin;
    }

    /**
     * Transient key holding the last set of quotes for an order.
     *
     * @param int $order_id Order ID.
     * @return string
     */
    protected function quote_record_key( $order_id ) {
        return 'shipping_desk_quotes_' . (int) $order_id;
    }

    /**
     * The stored quotes for an order, or [] when there are none.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function get_quote_record( $order_id ) {
        $record = get_transient( $this->quote_record_key( $order_id ) );
        return is_array( $record ) ? $record : [];
    }

    /**
     * The stored quotes, but only while they are still usable.
     *
     * @param int         $order_id Order ID.
     * @param string|null $provider Provider to judge the dispatch day by.
     * @return array Empty when missing, expired or priced for another dispatch day.
     */
    public function fresh_quote_record( $order_id, $provider = null ) {
        $record = $this->get_quote_record( $order_id );
        if ( empty( $record ) ) {
            return [];
        }

        $provider = null === $provider ? $this->plugin->provider() : (string) $provider;

        // Switching provider or label mode must retire every stored quote: the rates and
        // the shipment id in them belong to the account that priced them.
        if ( ! Shipping_Desk_Helpers::quote_record_matches_provider( $record, $provider, $this->plugin->provider_mode( $provider ) ) ) {
            return [];
        }

        return Shipping_Desk_Helpers::quote_record_is_fresh( $record, $this->current_dispatch_day( $provider ) )
            ? $record
            : [];
    }

    /**
     * Drop the stored quotes for an order.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function delete_quote_record( $order_id ) {
        delete_transient( $this->quote_record_key( $order_id ) );
    }

    /**
     * The dispatch day a quote taken right now would carry.
     *
     * Shippo bakes the date into the shipment at quote time and USPS prints it;
     * EasyPost carries it as `options.label_date`. Either way a quote priced for
     * one dispatch day must not be bought on another. Veeqo has no equivalent, so
     * it answers '' and only the carrier expiry and the cap apply.
     *
     * @param string|null $provider Provider to ask.
     * @return string `Y-m-d`, or ''.
     */
    public function current_dispatch_day( $provider = null ) {
        $provider = null === $provider ? $this->plugin->provider() : $provider;
        if ( ! in_array( $provider, [ 'shippo', 'easypost' ], true ) ) {
            return '';
        }

        $client = $this->plugin->api( $provider );
        return method_exists( $client, 'dispatch_day' ) ? (string) $client->dispatch_day() : '';
    }

    /**
     * Persist a set of quotes so a reload, another browser or an order arriving
     * mid-batch does not throw the work away.
     *
     * Stored as an expiring transient rather than order meta: it is a cache with a
     * lifetime measured in hours, not a fact about the order.
     *
     * @param int   $order_id Order ID.
     * @param array $record   Record; `provider_expires_at` is folded into `expires_at`.
     * @return void
     */
    public function store_quote_record( $order_id, array $record ) {
        $now = time();

        // Stamped with the account that priced it, so a provider or mode change retires
        // the record instead of offering its rates under someone else's name.
        $record['provider'] = $this->plugin->provider();
        $record['mode']     = $this->plugin->provider_mode( $record['provider'] );

        $record['expires_at'] = Shipping_Desk_Helpers::quote_record_expiry(
            $now,
            isset( $record['provider_expires_at'] ) ? $record['provider_expires_at'] : null,
            self::QUOTE_RECORD_CAP_HOURS
        );
        unset( $record['provider_expires_at'] );

        $ttl = max( 60, (int) $record['expires_at'] - $now );
        set_transient( $this->quote_record_key( $order_id ), $record, $ttl );
    }

    /**
     * Validate a recipient address, reusing a recent answer.
     *
     * The result is cached per order and address: Get Rates is clicked again after
     * every box, signature and insurance change, and validation is billed per call.
     * An outage is deliberately not cached — a validator that was down for one
     * click should not leave the address unchecked for twelve hours.
     *
     * @param WC_Order $order   Order being quoted.
     * @param array    $address Recipient address from the payload.
     * @param object   $client  Provider client implementing the validator contract.
     * @return array Normalised result plus the original address.
     */
    public function validate_recipient_address( WC_Order $order, array $address, $client ) {
        $m    = Shipping_Desk_Helpers::meta_keys();
        $hash = md5( implode( '|', [
            strtolower( trim( (string) ( isset( $address['line1'] ) ? $address['line1'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['line2'] ) ? $address['line2'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['town'] ) ? $address['town'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['county'] ) ? $address['county'] : '' ) ) ),
            strtolower( trim( (string) ( isset( $address['postcode'] ) ? $address['postcode'] : '' ) ) ),
        ] ) );
        $key = 'shipping_desk_addr_' . (int) $order->get_id() . '_' . $hash;

        // Already verified as clean at this exact address: a valid address never
        // gains a ZIP+4, so the "no +4" trigger would otherwise keep paying for the
        // same answer every day the order sits unshipped. Only clean verdicts are
        // remembered — a correction the operator declined should be able to appear
        // again rather than being suppressed for good.
        $verdict = $order->get_meta( $m['address_checked'] );
        if ( is_array( $verdict )
            && isset( $verdict['hash'], $verdict['status'] )
            && $verdict['hash'] === $hash
            && 'valid' === $verdict['status'] ) {
            return [
                'status'       => 'valid',
                'is_valid'     => true,
                'recommended'  => [],
                'changed'      => [],
                'reasons'      => [],
                'address_type' => isset( $verdict['address_type'] ) ? (string) $verdict['address_type'] : '',
                'confidence'   => [],
                'error'        => '',
                'original'     => $address,
                'remembered'   => true,
            ];
        }

        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            return $cached;
        }

        $result             = $client->validate_address( $address );
        $result['original'] = $address;

        if ( 'unavailable' !== ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
            set_transient( $key, $result, self::ADDRESS_VALIDATION_TTL );
        }

        // Clean verdict: remember it against this address so the next quote is free.
        if ( 'valid' === ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
            $order->update_meta_data( $m['address_checked'], [
                'hash'         => $hash,
                'status'       => 'valid',
                'address_type' => isset( $result['address_type'] ) ? (string) $result['address_type'] : '',
            ] );
            $order->save();
        }

        return $result;
    }

    /**
     * Drop every stored quote, and the address verdicts behind them.
     *
     * Called when the provider or its label mode changes. Rates are quoted by one
     * account and carry that account's shipment id, so none of them can be bought by
     * the provider just selected — and a row that still shows them looks current.
     * Deleting a cache is cheap; a stale rate that looks buyable is not.
     *
     * Direct SQL by prefix, as for the address cache: there is no core API for wildcard
     * transient deletion, and the names are this plugin's own.
     *
     * @return void
     */
    public function clear_cached_quotes() {
        global $wpdb;

        foreach ( [ '_transient_', '_transient_timeout_' ] as $prefix ) {
            foreach ( [ 'shipping_desk_quotes_', 'shipping_desk_addr_' ] as $name ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $wpdb->esc_like( $prefix . $name ) . '%'
                    )
                );
            }
        }
    }

    /**
     * Drop the cached address validation results for one order.
     *
     * The transient name is `shipping_desk_addr_<order>_<address hash>`, so the
     * order's entries are removed by prefix. Direct SQL is the only way to do that
     * — there is no core API for wildcard transient deletion — and it is scoped to
     * this plugin's option names only.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function clear_address_validation_cache( $order_id ) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        foreach ( [ '_transient_', '_transient_timeout_' ] as $prefix ) {
            $like = $prefix . 'shipping_desk_addr_' . $order_id . '_%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( $like )
                )
            );
        }
    }
}
