<?php
/**
 * Helpers for orders, weights, payloads.
 */
class Shipping_Desk_Helpers {

    public static function normalize_service_name( $name ) {
        $name = strtolower( (string) $name );
        $name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
        $name = trim( $name );
        $map = [
            'usps priority mail' => 'usps priority',
            'usps ground advantage' => 'usps ground advantage',
            'ups ground' => 'ups ground',
            'fedex ground' => 'fedex ground',
        ];
        return $map[ $name ] ?? $name;
    }

    /**
     * Spacing between provider writes in a bulk run, in microseconds.
     *
     * Shippo's documented limits are per minute and differ ten-fold between keys:
     * a live key allows 500 POSTs a minute per endpoint, a test key 50 — one every
     * 1.2 seconds. Spacing live traffic to the test budget would make every real run
     * several times slower than it needs to be; spacing test traffic to the live
     * budget guarantees 429s. EasyPost publishes no writes-per-second figure (its
     * buy and rate calls sit behind an unpublished load-based limiter, and the fixed
     * 5/s applies to Index reads only), so both of its keys keep one spacing.
     *
     * The mode is read from the key rather than passed alongside it, because the key
     * is the thing that decides it: a test key cannot be paced for production.
     *
     * @param string $provider 'veeqo', 'shippo' or 'easypost'.
     * @param string $api_key  Key in use.
     * @return int Microseconds between purchases.
     */
    public static function bulk_spacing_us( $provider, $api_key ) {
        $key  = strtolower( trim( (string) $api_key ) );
        $test = 0 === strpos( $key, 'shippo_test_' ) || 0 === strpos( $key, 'eztk' );

        // 1.4 s = ~43 writes/minute, under Shippo's 50/minute test ceiling.
        if ( 'shippo' === $provider && $test ) {
            return 1400000;
        }

        return 350000;
    }

    /**
     * Ceiling for adaptive spacing when a provider still answers 429.
     *
     * Deliberately finite: a run that is being throttled should slow down, not
     * crawl to a halt while the operator waits.
     *
     * @return int Microseconds.
     */
    public static function bulk_spacing_cap_us() {
        return 8000000;
    }

    public static function get_order_items_weight_oz( WC_Order $order ) {
        $weight_oz = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $product_weight = (float) $product->get_weight();
            $qty            = (float) $item->get_quantity();
            $weight_oz     += $product_weight * $qty;
        }
        return $weight_oz;
    }

    public static function get_order_shipping_method_label( WC_Order $order ) {
        $methods = $order->get_shipping_methods();
        if ( empty( $methods ) ) {
            return '-';
        }
        $method = reset( $methods );
        return $method->get_method_title();
    }

    /**
     * Phone used as the shipment's from-address.
     *
     * Both carriers expect a phone here — USPS rejects a label without one
     * (`Attribute "address_from.phone" must not be empty`), while UPS accepted a
     * blank value in test mode but treats phone as required too, so a blank is
     * never safe. The legacy `admin_phone` option is checked first because that is
     * what older installs already use, then a store phone if something set one.
     */
    public static function store_phone() {
        $phone = trim( (string) get_option( 'admin_phone', '' ) );
        if ( '' === $phone ) {
            $phone = trim( (string) get_option( 'woocommerce_store_phone', '' ) );
        }
        return $phone;
    }

    /**
     * Email used as the shipment's from-address.
     *
     * Required by USPS only (`Attribute "address_from.email" must not be empty`);
     * UPS accepts a label without one, and an empty value fails only on a USPS
     * attempt — which is exactly how this was discovered.
     */
    public static function store_email() {
        $email = trim( (string) get_option( 'admin_email', '' ) );
        if ( '' === $email || ! is_email( $email ) ) {
            $email = trim( (string) get_option( 'woocommerce_email_from_address', '' ) );
        }
        return $email;
    }

    public static function get_default_from_address() {
        return [
            'name'         => get_bloginfo( 'name' ),
            'company'      => '',
            'phone'        => self::store_phone(),
            'email'        => self::store_email(),
            'line1'        => WC()->countries->get_base_address(),
            'town'         => WC()->countries->get_base_city(),
            'postcode'     => WC()->countries->get_base_postcode(),
            'country_code' => WC()->countries->get_base_country(),
            'county'       => WC()->countries->get_base_state(),
        ];
    }

    /**
     * Digits-only phone number for a carrier label.
     *
     * UPS renders a leading "+" as the international "00" prefix, so a billing
     * phone entered as "+1 (704) 555-1212" printed on the label as
     * "0017045551212". Carriers only need digits, and for a US/CA number the
     * leading country code is dropped as well.
     *
     * @param string $phone        Number as entered.
     * @param string $country_code ISO country of the address.
     * @return string Digits only, or '' when nothing usable remains.
     */
    public static function normalise_phone( $phone, $country_code = 'US' ) {
        // Drop an extension: it is not part of the dialable label number.
        $phone  = preg_replace( '/\s*(?:x|ext\.?|extension)\s*\d+\s*$/i', '', (string) $phone );
        $digits = preg_replace( '/\D+/', '', (string) $phone );
        if ( ! is_string( $digits ) || '' === $digits ) {
            return '';
        }

        $country_code = strtoupper( (string) $country_code );
        if ( in_array( $country_code, [ 'US', 'CA' ], true ) && 11 === strlen( $digits ) && '1' === $digits[0] ) {
            $digits = substr( $digits, 1 );
        }

        return $digits;
    }

    /**
     * Does this postcode carry a US ZIP+4 route code?
     *
     * The checkout already validates addresses and writes back the +4 segment
     * (`33434` -> `33434-5844`), and a `+4` only comes back when the house number
     * and street matched a delivery point. So the presence of +4 is a free,
     * local signal that an address has already been standardised — which is what
     * decides whether Shippo's paid validation call is worth making.
     *
     * Both spellings count: `33434-5844` and the unpunctuated `334345844`.
     *
     * @param string $postcode Postcode as entered.
     * @return bool
     */
    public static function has_zip_plus_four( $postcode ) {
        $postcode = trim( (string) $postcode );
        return (bool) preg_match( '/^\d{5}-\d{4}$/', $postcode )
            || (bool) preg_match( '/^\d{9}$/', $postcode );
    }

    /**
     * Is this address a candidate for validation before rate shopping?
     *
     * Only addresses without a ZIP+4 are interesting: anything else has already
     * been through the checkout validator. PO boxes and rural routes legitimately
     * have no +4, so they are validated every time — the right side to err on.
     *
     * @param string $postcode Postcode as entered.
     * @return bool
     */
    public static function needs_address_validation( $postcode ) {
        return ! self::has_zip_plus_four( $postcode );
    }

    /**
     * Human wording for the attributes a validator changed.
     *
     * Shippo names the fields it touched (`postal_code`, `address_line_2`), which
     * means nothing to an operator. These are the phrases shown next to the
     * suggestion.
     *
     * @param array $changed Field names as returned by the validator.
     * @return array Short phrases, de-duplicated, in the order given.
     */
    public static function describe_address_changes( array $changed ) {
        $labels = [
            'postal_code'    => __( 'ZIP+4 added', 'shipping-desk' ),
            'address_line_1' => __( 'street standardised', 'shipping-desk' ),
            'address_line_2' => __( 'suite moved to line 2', 'shipping-desk' ),
            'city_locality'  => __( 'city corrected', 'shipping-desk' ),
            'state_province' => __( 'state corrected', 'shipping-desk' ),
            'country_code'   => __( 'country corrected', 'shipping-desk' ),
            'name'           => __( 'name standardised', 'shipping-desk' ),
            'company'        => __( 'company standardised', 'shipping-desk' ),
            'phone'          => __( 'phone formatted', 'shipping-desk' ),
            'email'          => __( 'email formatted', 'shipping-desk' ),
        ];

        $phrases = [];
        foreach ( $changed as $field ) {
            $key = strtolower( trim( (string) $field ) );
            if ( '' === $key ) {
                continue;
            }
            $phrases[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
        }

        return array_values( array_unique( $phrases ) );
    }

    /**
     * Single-line rendering of a plugin-shaped address, for the choice panel.
     *
     * @param array $address Address array (line1, line2, town, county, postcode).
     * @return string
     */
    public static function format_address( array $address ) {
        $line1    = isset( $address['line1'] ) ? trim( (string) $address['line1'] ) : '';
        $line2    = isset( $address['line2'] ) ? trim( (string) $address['line2'] ) : '';
        $town     = isset( $address['town'] ) ? trim( (string) $address['town'] ) : '';
        $county   = isset( $address['county'] ) ? trim( (string) $address['county'] ) : '';
        $postcode = isset( $address['postcode'] ) ? trim( (string) $address['postcode'] ) : '';

        $street = $line1;
        if ( '' !== $line2 ) {
            $street = '' === $street ? $line2 : $street . ', ' . $line2;
        }

        // City and state belong together; an empty town must not leave a
        // dangling ", FL".
        $region = '' !== $town && '' !== $county
            ? $town . ', ' . $county
            : ( '' !== $town ? $town : $county );

        // The ZIP joins the region with a space, as on an address line:
        // "San Francisco, CA 94103", not "San Francisco, CA, 94103".
        if ( '' !== $postcode ) {
            $region = '' === $region ? $postcode : $region . ' ' . $postcode;
        }

        $parts = [];
        foreach ( [ $street, $region ] as $part ) {
            if ( '' !== $part ) {
                $parts[] = $part;
            }
        }

        return implode( ', ', $parts );
    }

    /**
     * Fingerprint of everything that determines a quoted price.
     *
     * A stored quote may only be reused or bought while this fingerprint still
     * matches, because it is the only way to tell "same inputs" from "the operator
     * changed the box ten minutes ago". Case-insensitive on the address, since a
     * capitalisation difference cannot change a price.
     *
     * @param array  $payload  Rate payload as sent to the provider.
     * @param string $provider 'veeqo' or 'shippo'.
     * @param string $mode     Provider key mode, or '' when it has none.
     * @return string
     */
    public static function rate_inputs_fingerprint( array $payload, $provider, $mode = '' ) {
        $to = isset( $payload['to_address'] ) && is_array( $payload['to_address'] ) ? $payload['to_address'] : [];

        $address = [];
        foreach ( [ 'line1', 'line2', 'town', 'county', 'postcode', 'country_code' ] as $field ) {
            $address[] = strtolower( trim( (string) ( isset( $to[ $field ] ) ? $to[ $field ] : '' ) ) );
        }

        $parts = [
            'provider'  => strtolower( (string) $provider ),
            'mode'      => strtolower( (string) $mode ),
            'address'   => implode( '|', $address ),
            // json_encode, not wp_json_encode: this helper stays free of WordPress so
            // the fingerprint can be tested on its own.
            'parcels'   => (string) json_encode( isset( $payload['parcels'] ) ? $payload['parcels'] : [] ),
            'liability' => number_format( (float) ( isset( $payload['liability_amount'] ) ? $payload['liability_amount'] : 0 ), 2, '.', '' ),
            'signature' => strtoupper( trim( (string) ( isset( $payload['signature_confirmation'] ) ? $payload['signature_confirmation'] : '' ) ) ),
            'value'     => (string) ( isset( $payload['estimated_value'] ) ? $payload['estimated_value'] : '' ),
            'currency'  => strtoupper( trim( (string) ( isset( $payload['currency_code'] ) ? $payload['currency_code'] : '' ) ) ),
            'reference' => (string) ( isset( $payload['customer_reference'] ) ? $payload['customer_reference'] : '' ),
        ];

        return md5( implode( '#', $parts ) );
    }

    /**
     * When a stored quote stops being usable.
     *
     * Three limits, whichever comes first:
     *  - the carrier's own expiry, when it tells us (Veeqo does),
     *  - a hard cap, so a weekend cannot leave a week-old price on screen,
     *  - the dispatch day, which is enforced separately at read time because it is
     *    a comparison against "now", not a fixed timestamp.
     *
     * @param int         $now             Current Unix time.
     * @param int|null    $provider_expiry Unix time the carrier gave, or null.
     * @param int         $cap_hours       Hard cap in hours.
     * @return int
     */
    public static function quote_record_expiry( $now, $provider_expiry = null, $cap_hours = 72 ) {
        $now  = (int) $now;
        $cap  = $now + ( max( 1, (int) $cap_hours ) * 3600 );
        $hard = null === $provider_expiry ? $cap : min( $cap, (int) $provider_expiry );

        // Never return a moment that has already passed; an immediately stale record
        // is worse than none, because it would render as if it were current.
        return max( $now, $hard );
    }

    /**
     * Is a stored quote still valid right now?
     *
     * @param array  $record                Stored record.
     * @param string $current_dispatch_day  Dispatch day that a quote taken now would
     *                                      carry, or '' when the provider has none.
     * @param int    $now                   Current Unix time.
     * @return bool
     */
    public static function quote_record_is_fresh( array $record, $current_dispatch_day, $now = null ) {
        if ( empty( $record['rates'] ) && empty( $record['address_validation'] ) ) {
            return false;
        }

        $now = null === $now ? time() : (int) $now;

        if ( ! empty( $record['expires_at'] ) && $now >= (int) $record['expires_at'] ) {
            return false;
        }

        // The dispatch day is baked into the shipment at quote time and USPS honours
        // it, so a quote priced for one day must not be bought on another.
        $stored_day = isset( $record['dispatch_day'] ) ? (string) $record['dispatch_day'] : '';
        if ( '' !== $stored_day && $stored_day !== (string) $current_dispatch_day ) {
            return false;
        }

        return true;
    }

    /**
     * What a label actually cost, as reported by the provider that sold it.
     *
     * Shippo answers with the rate amount as a string on the bought transaction;
     * Veeqo answers with `total_charge` as a `{value, unit}` object, or with a
     * `charges[]` list. Anything non-numeric or non-positive returns [] so a caller
     * can never store a misleading zero.
     *
     * Insurance needs no special handling: XCover is priced into the rate, so the
     * amount here is the cost of shipping.
     *
     * @param string $provider 'veeqo' or 'shippo'.
     * @param array  $shipment Bought shipment as returned by book_shipment().
     * @return array `amount` (float) and `currency`, or [] when unknown.
     */
    public static function label_cost( $provider, array $shipment ) {
        $amount   = null;
        $currency = '';

        if ( 'veeqo' === $provider ) {
            $charge = isset( $shipment['total_charge'] ) ? $shipment['total_charge'] : null;

            if ( is_array( $charge ) ) {
                if ( isset( $charge['value'] ) && is_numeric( $charge['value'] ) ) {
                    $amount = (float) $charge['value'];
                }
                if ( ! empty( $charge['unit'] ) ) {
                    $currency = (string) $charge['unit'];
                }
            } elseif ( is_numeric( $charge ) ) {
                $amount = (float) $charge;
            }

            // Fall back to the itemised charges, which add up to the same total.
            if ( null === $amount && ! empty( $shipment['charges'] ) && is_array( $shipment['charges'] ) ) {
                $sum = 0.0;
                $seen = false;
                foreach ( $shipment['charges'] as $line ) {
                    if ( is_array( $line ) && isset( $line['value'] ) && is_numeric( $line['value'] ) ) {
                        $sum += (float) $line['value'];
                        $seen = true;
                    }
                }
                if ( $seen ) {
                    $amount = $sum;
                }
            }
        } else {
            $charge = isset( $shipment['total_charge'] ) ? $shipment['total_charge'] : null;
            if ( is_array( $charge ) && isset( $charge['value'] ) && is_numeric( $charge['value'] ) ) {
                $amount = (float) $charge['value'];
            } elseif ( is_numeric( $charge ) ) {
                $amount = (float) $charge;
            } elseif ( is_string( $charge ) && is_numeric( trim( $charge ) ) ) {
                $amount = (float) trim( $charge );
            }
            if ( ! empty( $shipment['currency'] ) ) {
                $currency = (string) $shipment['currency'];
            }
        }

        if ( null === $amount || $amount <= 0 ) {
            return [];
        }

        return [
            'amount'   => round( $amount, 2 ),
            'currency' => strtoupper( trim( $currency ) ),
        ];
    }

    public static function get_from_address() {
        $from_address = self::get_default_from_address();
        $custom       = get_option( 'shipping_desk_from_address', [] );
        if ( is_array( $custom ) ) {
            foreach ( $from_address as $key => $default ) {
                if ( isset( $custom[ $key ] ) && trim( (string) $custom[ $key ] ) !== '' ) {
                    $from_address[ $key ] = (string) $custom[ $key ];
                }
            }
        }

        $from_address['phone'] = self::normalise_phone(
            isset( $from_address['phone'] ) ? $from_address['phone'] : '',
            isset( $from_address['country_code'] ) ? $from_address['country_code'] : 'US'
        );

        return $from_address;
    }

    /**
     * Recipient phone for the shipment.
     *
     * Billing phone first; a store that requires it at checkout always has one.
     * The remaining fallbacks exist for stores where it is optional, because
     * carriers still want a number on the label.
     */
    public static function recipient_phone( WC_Order $order ) {
        $candidates = [
            $order->get_billing_phone(),
            $order->get_shipping_phone(),
            self::store_phone(),
        ];

        foreach ( $candidates as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' !== $candidate ) {
                return $candidate;
            }
        }

        return '';
    }

    public static function build_shipping_desk_rate_payload( WC_Order $order, array $box, $total_weight_oz, $liability_amount = 0 ) {
        $shipping_address = [
            'name'         => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'        => self::normalise_phone( self::recipient_phone( $order ), 'US' ),
            'email'        => $order->get_billing_email(),
            'line1'        => $order->get_shipping_address_1(),
            // Line 2 was never sent before, so a suite or apartment number lived
            // only on the admin screen and was missing from every label. Shippo's
            // own validator hands corrections back with a second line, which
            // would have been thrown away in the same place.
            'line2'        => $order->get_shipping_address_2(),
            'town'         => $order->get_shipping_city(),
            'postcode'     => $order->get_shipping_postcode(),
            'country_code' => 'US',
            'county'       => $order->get_shipping_state(),
        ];

        $from_address = self::get_from_address();

        $payload = [
            'to_address'           => $shipping_address,
            'from_address'         => $from_address,
            'parcels'              => [
                [
                    'weight'         => round( (float) $total_weight_oz, 2 ),
                    'weight_unit'    => 'oz',
                    'height'         => (float) ( $box['height'] ?? 0 ),
                    'width'          => (float) ( $box['width'] ?? 0 ),
                    'length'         => (float) ( $box['length'] ?? 0 ),
                    'dimension_unit' => 'in',
                ],
            ],
            'is_amazon_order'      => false,
            'customer_reference'   => (string) $order->get_order_number(),
            'estimated_value'      => (string) $order->get_total(),
            'currency_code'        => $order->get_currency(),
            'seller_display_name'  => get_bloginfo( 'name' ),
        ];

        // Declared value for insurance. estimated_value is for customs only and
        // does not insure the shipment, so liability_amount must be sent for the
        // carrier to quote (and later attach) coverage. Omit when not requested.
        $liability = round( (float) $liability_amount, 2 );
        if ( $liability > 0 ) {
            $payload['liability_amount'] = $liability;
        }

        return $payload;
    }

    public static function meta_keys() {
        return [
            'shipment_id'  => '_shipping_desk_shipment_id',
            'remote_id'    => '_shipping_desk_remote_shipment_id',
            'label_id'     => '_shipping_desk_label_id',
            'label_url'    => '_shipping_desk_label_url',
            'tracking'     => '_shipping_desk_tracking_number',
            'carrier'      => '_shipping_desk_carrier',
            // Which provider bought the current label, and (for Shippo) which
            // key mode it was bought with. Needed because print and refund must
            // reach the provider that owns the label, not the one selected now.
            'provider'      => '_shipping_desk_provider',
            'provider_mode' => '_shipping_desk_mode',
            'final_weight' => '_shipping_desk_final_weight_used_oz',
            'box_l'        => '_shipping_desk_box_length',
            'box_w'        => '_shipping_desk_box_width',
            'box_h'        => '_shipping_desk_box_height',
            'box_weight'   => '_shipping_desk_box_weight_oz',
            'refunded'     => '_shipping_desk_label_refunded',
            // The address decision behind the current quotes: which address the
            // operator accepted, and whether the order's own address should be
            // updated when the label is bought. Written at quote time, acted on at
            // purchase, so an abandoned quote changes nothing.
            'address_decision' => '_shipping_desk_address_decision',
            // Verdict for a specific address, keyed by its hash. A clean address
            // never gains a ZIP+4, so without this the same order would be checked
            // again (and billed again) every day it stayed unshipped.
            'address_checked' => '_shipping_desk_address_checked',
            // What the label cost, as recorded at purchase. Kept for the refund rule
            // (only a value we wrote is cleared) and for support questions.
            'cost_paid' => '_shipping_desk_cost_paid',
        ];
    }

    public static function clear_shipping_meta( WC_Order $order ) {
        foreach ( self::meta_keys() as $key ) {
            $order->delete_meta_data( $key );
        }
        $order->save();
    }

    public static function tracking_url( $carrier, $number, $fallback = '' ) {
        $lc = strtolower( (string) $carrier );
        if ( strpos( $lc, 'usps' ) !== false ) {
            return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode( $number );
        }
        if ( strpos( $lc, 'ups' ) !== false ) {
            return 'https://www.ups.com/track?loc=en_US&tracknum=' . urlencode( $number );
        }
        if ( strpos( $lc, 'fedex' ) !== false ) {
            return 'https://www.fedex.com/fedextrack/?tracknumbers=' . urlencode( $number );
        }
        if ( strpos( $lc, 'dhl' ) !== false ) {
            return 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=' . urlencode( $number );
        }
        return $fallback ?: (string) $number;
    }
    public static function absolute_url( $url ) {
        $url = trim( (string) $url );
        if ( $url === '' ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return $url;
        }
        // Relative path - resolve against Veeqo base URL.
        if ( strpos( $url, '/' ) === 0 ) {
            return Shipping_Desk_Veeqo_Client::BASE_URL . $url;
        }
        return Shipping_Desk_Veeqo_Client::BASE_URL . '/' . $url;
    }

}
