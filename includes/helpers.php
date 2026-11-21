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
     * Does this postcode look like a US ZIP+4?
     *
     * A test of the string, never of the address. Nine digits in a USPS shape do
     * not mean a verifier produced them: customers type them, autofill supplies
     * them from map data that is not CASS-certified, and a street edited after
     * validation keeps the old route code. Kept for display ("already carries a
     * +4") and for comparing routing bases — never as a reason to skip
     * verification. That skip is what issue #2 removed.
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
     * The 5-digit routing base of a postcode, or '' when there isn't one.
     *
     * `33434`, `33434-5844` and `334345844` all answer `33434`. That is the part
     * a verifier can disagree about; the +4 only refines the same place, so an
     * added or revised route code is not a correction worth interrupting anyone
     * for.
     *
     * @param string $postcode Postcode as entered.
     * @return string
     */
    public static function postcode_route_base( $postcode ) {
        $digits = preg_replace( '/\D+/', '', (string) $postcode );
        if ( ! is_string( $digits ) || strlen( $digits ) < 5 ) {
            return '';
        }

        return substr( $digits, 0, 5 );
    }

    /**
     * Comparable form of an address line.
     *
     * Folds only the differences a verifier makes for its own reasons — case,
     * punctuation, whitespace and the usual street/unit abbreviations — so that
     * "123 Main St." and "123 MAIN STREET" compare equal.
     *
     * Deliberately leaves numbers alone: "Apt 5" and "Apt 50" must never fold
     * together, and neither must "Unit 4" and "Unit 4B".
     *
     * @param string $text Address line or locality.
     * @return string
     */
    public static function normalise_address_text( $text ) {
        $text = strtoupper( (string) $text );

        // "#5" and "Apt 5" are the same unit; US customers use both.
        $text  = preg_replace( '/\s*#\s*/', ' APT ', $text );
        $text  = preg_replace( '/[^A-Z0-9]+/', ' ', (string) $text );
        $words = preg_split( '/\s+/', trim( (string) $text ) );

        if ( ! is_array( $words ) ) {
            return '';
        }

        $map = [
            'STREET'      => 'ST',
            'ROAD'        => 'RD',
            'AVENUE'      => 'AVE',
            'AV'          => 'AVE',
            'BOULEVARD'   => 'BLVD',
            'DRIVE'       => 'DR',
            'LANE'        => 'LN',
            'COURT'       => 'CT',
            'PLACE'       => 'PL',
            'TERRACE'     => 'TER',
            'PARKWAY'     => 'PKWY',
            'HIGHWAY'     => 'HWY',
            'CIRCLE'      => 'CIR',
            'SQUARE'      => 'SQ',
            'TRAIL'       => 'TRL',
            'NORTH'       => 'N',
            'SOUTH'       => 'S',
            'EAST'        => 'E',
            'WEST'        => 'W',
            'NORTHEAST'   => 'NE',
            'NORTHWEST'   => 'NW',
            'SOUTHEAST'   => 'SE',
            'SOUTHWEST'   => 'SW',
            'APARTMENT'   => 'APT',
            'SUITE'       => 'STE',
            'FLOOR'       => 'FL',
            'BUILDING'    => 'BLDG',
            'ROUTE'       => 'RTE',
        ];

        $normalised = [];
        foreach ( $words as $word ) {
            if ( '' === $word ) {
                continue;
            }
            $normalised[] = isset( $map[ $word ] ) ? $map[ $word ] : $word;
        }

        return implode( ' ', $normalised );
    }

    /**
     * Which fields differ between what was entered and what a verifier matched.
     *
     * Uses the same vocabulary as the provider clients (`address_line_1`,
     * `postal_code`, …) so the operator and the customer read the same phrases.
     *
     * Case-insensitive, because a change of case is not a correction. A field the
     * verifier did not return is not a change either: blanking a value it said
     * nothing about would delete real data.
     *
     * Needed because the checkout's address book stores what the verifier said
     * about a *place*; the verdict and the wording belong to the question. Judging
     * a cached answer against the address in hand is what keeps one customer's
     * spelling from deciding what the next customer is told.
     *
     * @param array $original    Address as entered.
     * @param array $recommended Address the verifier matched.
     * @return string[]
     */
    public static function address_changes_between( array $original, array $recommended ) {
        $map = [
            'line1'        => 'address_line_1',
            'line2'        => 'address_line_2',
            'town'         => 'city_locality',
            'county'       => 'state_province',
            'postcode'     => 'postal_code',
            'country_code' => 'country_code',
        ];

        $changes = [];
        foreach ( $map as $field => $label ) {
            $before = isset( $original[ $field ] ) && is_scalar( $original[ $field ] )
                ? trim( (string) $original[ $field ] )
                : '';
            $after  = isset( $recommended[ $field ] ) && is_scalar( $recommended[ $field ] )
                ? trim( (string) $recommended[ $field ] )
                : '';

            if ( '' === $after || 0 === strcasecmp( $before, $after ) ) {
                continue;
            }

            $changes[] = $label;
        }

        return $changes;
    }

    /**
     * Would ignoring this correction send the parcel somewhere else?
     *
     * The admin gate and the checkout suggestion both ask this question, so the
     * two surfaces cannot disagree about what counts as a real change.
     *
     * A field the verifier did not return is not a change — that is the same
     * rule apply_address_correction() follows, and blanking a field because it
     * came back empty would delete real data.
     *
     * Line 2 never qualifies on its own. A suite folded into line 1 or moved
     * between lines is worth reporting, but not worth blocking a quote for: the
     * label prints what was quoted either way.
     *
     * @param array $original    Address as it stands.
     * @param array $recommended Fields the verifier returned.
     * @return bool
     */
    public static function address_change_is_material( array $original, array $recommended ) {
        $field = function ( array $source, $key ) {
            return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
                ? trim( (string) $source[ $key ] )
                : '';
        };

        // Country decides which postal system every other field belongs to.
        $before = strtoupper( $field( $original, 'country_code' ) );
        $after  = strtoupper( $field( $recommended, 'country_code' ) );
        if ( '' !== $after && $before !== $after ) {
            return true;
        }

        // A different routing base is a different place; a different +4 is not.
        $before = $field( $original, 'postcode' );
        $after  = $field( $recommended, 'postcode' );
        if ( '' !== $after && 0 !== strcasecmp( $before, $after ) ) {
            $base_before = self::postcode_route_base( $before );
            $base_after  = self::postcode_route_base( $after );

            if ( '' === $base_before || '' === $base_after ) {
                // A postcode we cannot reduce to a US base (a non-US one, or an
                // incomplete entry being filled in): any change is material.
                return true;
            }
            if ( $base_before !== $base_after ) {
                return true;
            }
        }

        foreach ( [ 'town', 'county' ] as $key ) {
            $before = self::normalise_address_text( $field( $original, $key ) );
            $after  = self::normalise_address_text( $field( $recommended, $key ) );
            if ( '' !== $after && $before !== $after ) {
                return true;
            }
        }

        // Line 1, with any suite carried into or out of it folded away first.
        // "123 Main St" plus "Apt 5" and "123 Main St APT 5" describe the same
        // building; only the suite's position differs, and position is not
        // material.
        $before = self::normalise_address_text( $field( $original, 'line1' ) );
        $after  = self::normalise_address_text( $field( $recommended, 'line1' ) );

        $units_before = $field( $original, 'line2' );
        $units_after  = $field( $recommended, 'line2' );

        $before = self::strip_secondary( $before, $units_before, $units_after );
        $after  = self::strip_secondary( $after, $units_before, $units_after );

        if ( '' !== $after && $before !== $after ) {
            return true;
        }

        return false;
    }

    /**
     * Remove a secondary-address fragment from a normalised line.
     *
     * A verifier moves a suite between lines (see address_line_carried()); the
     * building is the same either way. Comparing the two shapes without this
     * would report every fold as a different street.
     *
     * @param string $line  Normalised first line.
     * @param string ...$units Secondary lines to remove, in either shape.
     * @return string
     */
    private static function strip_secondary( $line, ...$units ) {
        foreach ( $units as $unit ) {
            $unit = self::normalise_address_text( $unit );
            if ( '' === $unit || '' === $line ) {
                continue;
            }

            // The designator travels with the unit. The customer typed "2H" on line 2
            // and the fold came back as "APT 2H", so stripping the identifier alone
            // left a dangling "APT": "123 example st apt" then compared as a different
            // street from "123 example st", which made a plain fold look material - a
            // hard panel on the orders screen, and a block-checkout order skipped by
            // design because material changes are left for the operator.
            $line = preg_replace(
                '/(^|\s)(?:(?:' . self::unit_designators() . ')\s*)?' . preg_quote( $unit, '/' ) . '($|\s)/i',
                ' ',
                $line
            );
            $line = trim( (string) preg_replace( '/\s+/', ' ', (string) $line ) );
        }

        return $line;
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
            'address_line_1' => __( 'street format corrected', 'shipping-desk' ),
            'address_line_2' => __( 'suite moved to line 2', 'shipping-desk' ),
            'address_line_2_folded' => __( 'suite folded into line 1', 'shipping-desk' ),
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
     * Add the display strings an address panel needs.
     *
     * Formatting is done once, server-side, so neither the operator's screen nor
     * the customer's has anything to reconstruct, and both read the same words.
     *
     * @param array  $validation Normalised validator result.
     * @param string $source     Verifier that answered: 'easypost' or 'shippo'.
     * @return array
     */
    public static function describe_address_validation( array $validation, $source = 'shippo' ) {
        $changed     = isset( $validation['changed'] ) && is_array( $validation['changed'] ) ? $validation['changed'] : [];
        $original    = isset( $validation['original'] ) && is_array( $validation['original'] ) ? $validation['original'] : [];
        $recommended = isset( $validation['recommended'] ) && is_array( $validation['recommended'] ) ? $validation['recommended'] : [];

        // What to write if the customer accepts, computed here with the same rule
        // the admin gate uses: a suite the verifier folded into line 1 must clear
        // line 2, or the browser would print the apartment twice. Empty when there
        // is nothing to apply, so a caller can never write a half-answer.
        $effective = empty( $recommended ) ? [] : self::apply_address_correction( $original, $recommended );

        /*
         * The verifiers disagree about which way a suite travels: EasyPost folds
         * line 2 into line 1, Shippo can move it out to line 2. The field name only
         * says "line 2 changed", so the direction has to be read from the result —
         * otherwise a fold is announced as "suite moved to line 2" while the
         * suggestion plainly shows nothing on line 2.
         */
        $line2_before = isset( $original['line2'] ) && is_scalar( $original['line2'] ) ? trim( (string) $original['line2'] ) : '';
        $line2_after  = isset( $effective['line2'] ) && is_scalar( $effective['line2'] ) ? trim( (string) $effective['line2'] ) : '';
        $line1_after  = isset( $effective['line1'] ) && is_scalar( $effective['line1'] ) ? (string) $effective['line1'] : '';

        $position = array_search( 'address_line_2', $changed, true );
        if ( false !== $position
            && '' !== $line2_before
            && '' === $line2_after
            && self::address_line_carried( $line1_after, $line2_before )
        ) {
            // Same word the order note uses, so the two surfaces cannot describe
            // the same correction differently.
            $changed[ $position ] = 'address_line_2_folded';
        }

        $phrases = self::describe_address_changes( $changed );

        $validation['summary']          = implode( ', ', $phrases );
        $validation['changes']          = $phrases;
        $validation['apply']            = $effective;
        $validation['original_line']    = self::format_address( $original );
        $validation['recommended_line'] = empty( $effective ) ? '' : self::format_address( $effective );

        // Which verifier answered. Two can be configured, and "test mode has no
        // validator" and "the address is clean" would otherwise look identical.
        $validation['validator'] = 'easypost' === $source ? 'easypost' : 'shippo';

        return $validation;
    }

    /**
     * Whether a second address line has been carried into the first.
     *
     * Verifiers move a suite or apartment into line 1 rather than keeping it separate:
     * "123 Main St" plus "Apt 5" comes back as "123 Main St APT 5" with no second line
     * at all. Writing that line 1 while keeping line 2 unchanged prints the apartment
     * twice, which is worse than the drop it replaced.
     *
     * Matched token-wise rather than as a plain substring, so "Suite 5" is not treated
     * as carried into "Suite 500". Case and punctuation are ignored, because both
     * verifiers standardise them - the comparison is about the words, not the spelling.
     *
     * The words themselves are not enough: a verifier renames the designator as it
     * folds, so the customer's "Unit 5" comes back inside the corrected line 1 as
     * "APT 5". Compared literally those are two different suites, line 2 is kept,
     * and the label prints the unit twice - once in each style. When the literal
     * match fails, the designator is therefore stripped from line 2 and the
     * identifier is looked for after a designator in line 1. A line 2 that is not a
     * unit at all never matches, because a wrong match here deletes a real suite.
     *
     * @param string $line1 First address line, as it will be written.
     * @param string $line2 Second address line, as it stands.
     * @return bool
     */
    public static function address_line_carried( $line1, $line2 ) {
        $fold = function ( $text ) {
            $text = strtolower( (string) $text );
            return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
        };

        $first  = $fold( $line1 );
        $second = $fold( $line2 );

        if ( '' === $first || '' === $second ) {
            return false;
        }

        if ( 1 === preg_match( '/(^|[^a-z0-9])' . preg_quote( $second, '/' ) . '($|[^a-z0-9])/', $first ) ) {
            return true;
        }

        $unit = self::unit_designator_identifier( $second );
        if ( '' === $unit ) {
            return false;
        }

        return 1 === preg_match(
            '/(?:^|[^a-z0-9])(?:' . self::unit_designators() . ')\s*' . preg_quote( $unit, '/' ) . '($|[^a-z0-9])/',
            $first
        );
    }

    /**
     * Words that introduce a suite, flat, floor or bay in a secondary line.
     *
     * One list, used both to read a unit out of line 2 and to find it in line 1,
     * so the two halves of the comparison cannot disagree.
     *
     * @return string Alternation for use inside a pattern.
     */
    private static function unit_designators() {
        return 'apt|apartment|unit|ste|suite|fl|floor|flat|rm|room|bldg|building|dept|lot|spc|space|trlr|trailer';
    }

    /**
     * The identifying part of a secondary-address line.
     *
     * "unit 5", "apt 5", "ste 5", "#5" and a bare "5" all name the same suite; the
     * designator is a spelling, not information. Returns '' for a line that is not
     * a unit ("rear entrance", "c/o Jane"), which is what keeps a real second line
     * from being cleared.
     *
     * @param string $folded Lowercased, punctuation-stripped line.
     * @return string
     */
    private static function unit_designator_identifier( $folded ) {
        if ( 1 === preg_match( '/^(?:' . self::unit_designators() . ')\s*([a-z0-9][a-z0-9-]*)$/', $folded, $m ) ) {
            return $m[1];
        }

        // A bare identifier: "#5" folds to "5", and "5" is what line 1 would say.
        if ( 1 === preg_match( '/^([a-z0-9][a-z0-9-]*)$/', $folded ) ) {
            return $folded;
        }

        return '';
    }

    /**
     * The address to use once a correction is accepted.
     *
     * A field the verifier did not return keeps its current value: blanking a line it said
     * nothing about would delete a real suite number. Line 2 is the exception, because
     * verifiers fold it into line 1 - "123 Example St" plus "Apt 4B" comes back as
     * "123 EXAMPLE ST APT 4B" - and writing that line 1 while keeping the old line 2
     * prints the apartment twice.
     *
     * Deliberately shared by the rate payload and the order itself. The label is bought on
     * the shipment built from the payload, so applying the fold to only one of them printed
     * the apartment on both lines of a UPS label while the order looked correct.
     *
     * @param array $current     Address as it stands: line1, line2, town, county, postcode, country_code.
     * @param array $recommended Fields returned by the verifier, same keys.
     * @return array The address to use.
     */
    public static function apply_address_correction( array $current, array $recommended ) {
        $fields = [ 'line1', 'line2', 'town', 'county', 'postcode', 'country_code' ];

        $target = $current;
        foreach ( $fields as $field ) {
            $value = isset( $recommended[ $field ] ) ? trim( (string) $recommended[ $field ] ) : '';
            if ( '' !== $value ) {
                $target[ $field ] = $value;
            }
        }

        // The fold test needs strings, and callers pass arrays of unknown completeness.
        foreach ( $fields as $field ) {
            if ( ! isset( $target[ $field ] ) || ! is_scalar( $target[ $field ] ) ) {
                $target[ $field ] = '';
            }
        }

        if ( '' !== $target['line2'] && self::address_line_carried( $target['line1'], $target['line2'] ) ) {
            $target['line2'] = '';
        }

        return $target;
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
     * Is cover recorded without the premium that should accompany it?
     *
     * EasyPost charges insurance as its own fee, so a labelled shipment carrying a value
     * with no `InsuranceFee` deserves a look.
     *
     * It only counts when there is a label: a shipment that carries a declared value but
     * never produced one is a different problem, and EasyPost's test mode can return a
     * bought shipment without populating the fee fields at all. Requiring the label keeps
     * this honest instead of noisy.
     *
     * @param string $insured   Declared value reported on the shipment.
     * @param string $fee       Premium reported as a fee, or ''.
     * @param bool   $has_label Whether a label was actually produced.
     * @return bool
     */
    public static function insurance_premium_missing( $insured, $fee, $has_label ) {
        if ( round( (float) $insured, 2 ) <= 0 ) {
            return false;
        }

        if ( '' !== trim( (string) $fee ) ) {
            return false;
        }

        return (bool) $has_label;
    }

    /**
     * Was this stored quote priced by the provider (and mode) now selected?
     *
     * Rates are only meaningful to the account that quoted them: the record also holds
     * the provider's shipment id, and that id cannot be bought by another provider. A
     * record written before the stamp existed cannot be trusted either — its ids belong
     * to whichever provider was active when it was written.
     *
     * @param array  $record   Stored record.
     * @param string $provider Provider now selected.
     * @param string $mode     Label mode now selected.
     * @return bool
     */
    public static function quote_record_matches_provider( array $record, $provider, $mode ) {
        $stored_provider = isset( $record['provider'] ) ? (string) $record['provider'] : '';
        $stored_mode     = isset( $record['mode'] ) ? (string) $record['mode'] : '';

        if ( '' === $stored_provider ) {
            return false;
        }

        if ( $stored_provider !== (string) $provider ) {
            return false;
        }

        return $stored_mode === (string) $mode;
    }

    /**
     * Did the bought label come back with the cover that was asked for?
     *
     * EasyPost accepts an `insurance` value whether or not the carrier applies it, so a
     * successful buy is not evidence that the parcel is covered. The answer has to come
     * from the bought shipment itself.
     *
     * One cent of tolerance absorbs the currency round-trip; anything larger is a real
     * shortfall, and the operator needs to know before the parcel leaves.
     *
     * @param float  $requested Declared value sent with the buy call.
     * @param string $applied   Declared value reported on the bought shipment.
     * @return bool True when cover was asked for and is missing or short.
     */
    public static function insurance_shortfall( $requested, $applied ) {
        $requested = round( (float) $requested, 2 );
        if ( $requested <= 0 ) {
            return false;
        }

        return round( (float) $applied, 2 ) + 0.01 < $requested;
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

    /**
     * Which country this order is actually going to.
     *
     * Shipping address first, then billing (a virtual order has no shipping
     * address), then the store's base country. Never a constant: a worldwide
     * store must not have its orders described as US ones, and a US-only plugin
     * must say so rather than invent an address that quotes cleanly.
     *
     * @param WC_Order $order Order being quoted.
     * @return string Upper-case ISO country code, or 'US' when nothing else is known.
     */
    public static function destination_country( WC_Order $order ) {
        foreach ( [ $order->get_shipping_country(), $order->get_billing_country() ] as $candidate ) {
            $candidate = strtoupper( trim( (string) $candidate ) );
            if ( '' !== $candidate ) {
                return $candidate;
            }
        }

        $base = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) );
        $base = strtoupper( trim( (string) reset( $base ) ) );

        return '' !== $base ? $base : 'US';
    }

    /**
     * Is this destination within the plugin's shipping scope?
     *
     * One list, one place. The rate payload, the admin address gate and the
     * checkout validator all ask this, so "supported" cannot mean three
     * different things on three screens.
     *
     * The territories belong here: they carry US ZIP codes, USPS prices them as
     * domestic, WooCommerce lists them as US states, and EasyPost states that
     * "U.S. territories are treated as domestic for verification purposes".
     * Refusing them would have been a regression, not a safety measure.
     *
     * @param string $country_code ISO country code.
     * @return bool
     */
    public static function is_supported_destination( $country_code ) {
        $supported = [ 'US', 'PR', 'GU', 'VI', 'AS', 'MP' ];

        return in_array( strtoupper( trim( (string) $country_code ) ), $supported, true );
    }

    public static function build_shipping_desk_rate_payload( WC_Order $order, array $box, $total_weight_oz, $liability_amount = 0 ) {
        // The destination, not a constant. Callers gate on
        // is_supported_destination() before quoting, so a non-US order never
        // reaches a provider dressed as a US one (issue #1).
        $country = self::destination_country( $order );

        $shipping_address = [
            'name'         => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'        => self::normalise_phone( self::recipient_phone( $order ), $country ),
            'email'        => $order->get_billing_email(),
            'line1'        => $order->get_shipping_address_1(),
            // Line 2 was never sent before, so a suite or apartment number lived
            // only on the admin screen and was missing from every label. Shippo's
            // own validator hands corrections back with a second line, which
            // would have been thrown away in the same place.
            'line2'        => $order->get_shipping_address_2(),
            'town'         => $order->get_shipping_city(),
            'postcode'     => $order->get_shipping_postcode(),
            'country_code' => $country,
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
            // Verdict for a specific address, keyed by its hash. Get Rates is
            // clicked again after every box, signature or insurance change, and
            // validation is billed per call. Scoped to this order and this exact
            // address: editing the address changes the hash and earns a new
            // verdict, so a stale one can never follow the parcel.
            'address_checked' => '_shipping_desk_address_checked',
            // What the label cost, as recorded at purchase. Kept for the refund rule
            // (only a value we wrote is cleared) and for support questions.
            'cost_paid' => '_shipping_desk_cost_paid',
            // Insurance bought with the current label: the declared value and the
            // premium EasyPost charged for it. Separate from cost_paid because the
            // order needs to show *what was covered*, which a combined total hides —
            // and because "was this shipment insured?" was previously unanswerable
            // once the purchase screen was gone.
            'insurance'     => '_shipping_desk_insurance',
            'insurance_fee' => '_shipping_desk_insurance_fee',
        ];
    }

    /**
     * Nonce action the Lookup page's label buttons are bound to.
     *
     * One action per shipment id, so a nonce minted for one id cannot be used
     * for another. The Lookup page's search form keeps its own account-wide
     * nonce; this is only for the Print, Refund and print-PDF requests made for
     * a shipment the page has drawn.
     *
     * @param string $remote_id Shipment id the nonce is bound to.
     * @return string
     */
    public static function lookup_nonce_action( $remote_id ) {
        return 'shipping_desk_lookup_' . (string) $remote_id;
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

    /** Where the running daily total lives. One option, rewritten per purchase. */
    const OPTION_DAILY_SPEND = 'shipping_desk_daily_spend';

    /**
     * Today's date in the site's timezone, which is what "calendar day" means here.
     *
     * @return string Y-m-d.
     */
    protected static function site_date() {
        return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : date_i18n( 'Y-m-d' );
    }

    /**
     * Add one purchase to a daily total. Pure: state in, state out.
     *
     * A different date starts again from zero, so the counter resets at midnight without a
     * cron job or a scheduled cleanup - the first purchase of the new day overwrites the
     * previous one. Currencies are kept apart rather than summed, because adding dollars to
     * euros would be worse than showing nothing.
     *
     * @param array  $state    Current state: date + totals per currency.
     * @param string $date     Y-m-d of the purchase.
     * @param string $currency Currency code.
     * @param float  $amount   Amount charged.
     * @return array
     */
    public static function accumulate_daily( array $state, $date, $currency, $amount ) {
        $date = (string) $date;

        if ( ! isset( $state['date'] ) || (string) $state['date'] !== $date ) {
            $state = [ 'date' => $date, 'totals' => [] ];
        }
        if ( ! isset( $state['totals'] ) || ! is_array( $state['totals'] ) ) {
            $state['totals'] = [];
        }

        $currency = strtoupper( (string) $currency );
        $current  = isset( $state['totals'][ $currency ] ) && is_array( $state['totals'][ $currency ] )
            ? $state['totals'][ $currency ]
            : [ 'amount' => 0.0, 'labels' => 0 ];

        $state['totals'][ $currency ] = [
            'amount' => round( (float) $current['amount'] + (float) $amount, 2 ),
            'labels' => (int) $current['labels'] + 1,
        ];

        return $state;
    }

    /**
     * One currency's total for a date, or null when there is nothing from that day.
     *
     * @param array  $state    State from accumulate_daily().
     * @param string $date     Y-m-d to read.
     * @param string $currency Currency code.
     * @return array{amount:float,labels:int}|null
     */
    public static function daily_total( array $state, $date, $currency ) {
        if ( ! isset( $state['date'] ) || (string) $state['date'] !== (string) $date ) {
            return null;
        }

        $currency = strtoupper( (string) $currency );
        $totals   = isset( $state['totals'] ) && is_array( $state['totals'] ) ? $state['totals'] : [];

        if ( ! isset( $totals[ $currency ] ) || ! is_array( $totals[ $currency ] ) ) {
            return null;
        }

        $amount = isset( $totals[ $currency ]['amount'] ) ? (float) $totals[ $currency ]['amount'] : 0.0;
        $labels = isset( $totals[ $currency ]['labels'] ) ? (int) $totals[ $currency ]['labels'] : 0;

        return ( $amount > 0 || $labels > 0 ) ? [ 'amount' => $amount, 'labels' => $labels ] : null;
    }

    /**
     * Average postage per label for one day's total.
     *
     * The badge shows a total and a count; the average is the number an operator actually
     * compares between days, because it is not skewed by how busy the day was.
     *
     * @param array $total amount and labels, as daily_total() returns.
     * @return float|null Null when there is nothing to divide by.
     */
    public static function daily_average( array $total ) {
        $labels = isset( $total['labels'] ) ? (int) $total['labels'] : 0;
        if ( $labels < 1 ) {
            return null;
        }

        $amount = isset( $total['amount'] ) ? (float) $total['amount'] : 0.0;

        return round( $amount / $labels, 2 );
    }

    /**
     * Record what a purchase actually charged against today's total.
     *
     * Test-mode labels are skipped: they cost nothing, so a counter that moved for them
     * would be lying. Runs on every purchase, whether or not BrikPanel is installed.
     *
     * @param string $provider Provider slug.
     * @param array  $shipment Bought shipment.
     * @param string $mode     test|live.
     * @param string $date     Y-m-d override, for tests.
     * @return void
     */
    public static function record_daily_spend( $provider, array $shipment, $mode, $date = '' ) {
        if ( 'test' === $mode ) {
            return;
        }

        $cost = self::label_cost( $provider, $shipment );
        if ( empty( $cost ) || empty( $cost['amount'] ) || ! is_numeric( $cost['amount'] ) || (float) $cost['amount'] <= 0 ) {
            return;
        }

        $currency = ! empty( $cost['currency'] ) ? strtoupper( (string) $cost['currency'] ) : 'USD';
        $state    = get_option( self::OPTION_DAILY_SPEND, [] );
        $state    = self::accumulate_daily(
            is_array( $state ) ? $state : [],
            '' !== $date ? $date : self::site_date(),
            $currency,
            (float) $cost['amount']
        );

        // Not autoloaded: only the plugin's own screens read it.
        update_option( self::OPTION_DAILY_SPEND, $state, false );
    }

    /**
     * Today's spend, for the badge. Null when nothing was bought today.
     *
     * A stale state (yesterday's totals) is treated as empty rather than deleted: the next
     * purchase overwrites it, so a quiet day never needs a write of its own.
     *
     * @param string $preferred Preferred currency; falls back to the store currency.
     * @param string $date      Y-m-d override, for tests.
     * @return array{amount:float,labels:int,currency:string}|null
     */
    public static function daily_spend( $preferred = '', $date = '' ) {
        $state = get_option( self::OPTION_DAILY_SPEND, [] );
        if ( ! is_array( $state ) || empty( $state['totals'] ) || ! is_array( $state['totals'] ) ) {
            return null;
        }

        $date   = '' !== $date ? $date : self::site_date();
        $store  = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
        $wanted = [];

        foreach ( [ $preferred, $store ] as $candidate ) {
            $candidate = strtoupper( trim( (string) $candidate ) );
            if ( '' !== $candidate && ! in_array( $candidate, $wanted, true ) ) {
                $wanted[] = $candidate;
            }
        }

        // Then whatever else has a total, so the badge is not blank when the provider
        // charged in a currency the store setting does not mention.
        foreach ( array_keys( $state['totals'] ) as $code ) {
            $code = strtoupper( (string) $code );
            if ( ! in_array( $code, $wanted, true ) ) {
                $wanted[] = $code;
            }
        }

        foreach ( $wanted as $code ) {
            $total = self::daily_total( $state, $date, $code );
            if ( null !== $total ) {
                return [
                    'amount'   => $total['amount'],
                    'labels'   => $total['labels'],
                    'currency' => $code,
                ];
            }
        }

        return null;
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
