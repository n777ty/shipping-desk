<?php
/**
 * Checks for the helper logic that decides what actually goes on a label.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. Only pure helpers are
 * covered — anything that reads options or WooCommerce is out of scope here and is
 * exercised through the plugin itself.
 *
 * Run:  php tests/helpers-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

require_once dirname( __DIR__ ) . '/includes/helpers.php';

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}

$GLOBALS['helper_checks']   = 0;
$GLOBALS['helper_failures'] = 0;

function helper_same( $expected, $actual, $label ) {
    $GLOBALS['helper_checks']++;
    if ( $expected === $actual ) {
        echo '  ok    ' . $label . PHP_EOL;
        return;
    }
    $GLOBALS['helper_failures']++;
    echo '  FAIL  ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' . PHP_EOL;
}

function helper_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

echo 'Shipping Desk helper checks' . PHP_EOL;
echo '===========================' . PHP_EOL;

/*
 * Phone normalisation. UPS renders a leading "+" as the international "00"
 * prefix, so a plus sign must never reach the carrier: labels printed
 * 0017045551212 instead of 7045551212.
 */
helper_section( 'normalise_phone' );

helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '+1 (704) 555-1212', 'US' ), 'strips punctuation and the +1 country code' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '+17045551212', 'US' ), 'handles an unformatted +1 number' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '17045551212', 'US' ), 'drops a leading 1 from an 11-digit number' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '704.555.1212', 'US' ), 'handles dot separators' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '(704) 555-1212', 'US' ), 'handles parentheses' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '704-555-1212 x12', 'US' ), 'drops an extension' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '704-555-1212 ext. 12', 'US' ), 'drops a written extension' );
helper_same( '7045551212', Shipping_Desk_Helpers::normalise_phone( '+17045551212', 'CA' ), 'treats Canada the same way' );
helper_same( '442079460958', Shipping_Desk_Helpers::normalise_phone( '+44 20 7946 0958', 'GB' ), 'keeps the country code for a non-US number' );
helper_same( '442079460958', Shipping_Desk_Helpers::normalise_phone( '+44 20 7946 0958', 'US' ), 'never drops a non-1 country code' );
helper_same( '', Shipping_Desk_Helpers::normalise_phone( '', 'US' ), 'empty stays empty' );
helper_same( '', Shipping_Desk_Helpers::normalise_phone( '   ', 'US' ), 'whitespace stays empty' );
helper_same( '', Shipping_Desk_Helpers::normalise_phone( 'n/a', 'US' ), 'text with no digits stays empty' );

/* Service names drive the "does this rate match the order's shipping method" match. */
helper_section( 'normalize_service_name' );

helper_same( 'usps priority', Shipping_Desk_Helpers::normalize_service_name( 'USPS Priority Mail' ), 'maps the USPS priority label' );
helper_same( 'ups ground', Shipping_Desk_Helpers::normalize_service_name( 'UPS Ground' ), 'lowercases and keeps UPS Ground' );
helper_same( 'priority mail', Shipping_Desk_Helpers::normalize_service_name( 'Priority  Mail!' ), 'collapses punctuation and spacing' );

/* Provider pacing: the mode is read from the key, not passed beside it. */
helper_section( 'bulk_spacing_us' );

helper_same( 350000, Shipping_Desk_Helpers::bulk_spacing_us( 'shippo', 'shippo_live_abc' ), 'a live Shippo key keeps 350 ms' );
helper_same( 1400000, Shipping_Desk_Helpers::bulk_spacing_us( 'shippo', 'shippo_test_abc' ), 'a test Shippo key is paced to 1.4 s' );
helper_same( 350000, Shipping_Desk_Helpers::bulk_spacing_us( 'easypost', 'EZTK_test' ), 'EasyPost publishes no writes figure, so test keeps 350 ms' );
helper_same( 350000, Shipping_Desk_Helpers::bulk_spacing_us( 'easypost', 'EZAK_live' ), 'and so does live' );
helper_same( 350000, Shipping_Desk_Helpers::bulk_spacing_us( 'veeqo', '' ), 'Veeqo is unchanged' );
helper_same( 8000000, Shipping_Desk_Helpers::bulk_spacing_cap_us(), 'the adaptive ceiling is 8 s' );

/* Tracking links are built for the note and the order screen. */
helper_section( 'tracking_url' );

helper_same( 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111', Shipping_Desk_Helpers::tracking_url( 'USPS', '9400111' ), 'builds a USPS link' );
helper_same( 'https://www.ups.com/track?loc=en_US&tracknum=1Z999', Shipping_Desk_Helpers::tracking_url( 'ups', '1Z999' ), 'builds a UPS link from a lowercase carrier' );
helper_same( 'https://www.fedex.com/fedextrack/?tracknumbers=123', Shipping_Desk_Helpers::tracking_url( 'FedEx', '123' ), 'builds a FedEx link' );
helper_same( 'https://www.dhl.com/global-en/home/tracking.html?tracking-id=JD01', Shipping_Desk_Helpers::tracking_url( 'DHL', 'JD01' ), 'builds a DHL link' );
helper_same( 'https://fallback.example/track', Shipping_Desk_Helpers::tracking_url( 'Pigeon Post', 'ABC', 'https://fallback.example/track' ), 'uses the fallback for an unknown carrier' );
helper_same( 'ABC', Shipping_Desk_Helpers::tracking_url( 'Pigeon Post', 'ABC' ), 'falls back to the number itself' );

/*
 * The ZIP+4 test decides whether a paid validation call is made at all. The
 * checkout validator writes the +4 route code back when an address matches a
 * delivery point, so its absence means the customer either ignored the
 * suggestion or the address never matched.
 */
helper_section( 'has_zip_plus_four' );

helper_same( true, Shipping_Desk_Helpers::has_zip_plus_four( '33434-5844' ), 'recognises a hyphenated ZIP+4' );
helper_same( true, Shipping_Desk_Helpers::has_zip_plus_four( '334345844' ), 'recognises an unpunctuated ZIP+4' );
helper_same( true, Shipping_Desk_Helpers::has_zip_plus_four( ' 33434-5844 ' ), 'ignores surrounding whitespace' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '33434' ), 'a bare 5-digit ZIP has no +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '3343' ), 'a truncated ZIP has no +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '33434-' ), 'a dangling hyphen is not a +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '33434-584' ), 'a short route code is not a +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '33434 5844' ), 'a spaced ZIP is not a +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( '' ), 'an empty postcode has no +4' );
helper_same( false, Shipping_Desk_Helpers::has_zip_plus_four( null ), 'a null postcode has no +4' );

/*
 * A ZIP+4 is a fact about a string, not about the address. There is no
 * "already validated" shortcut any more (issue #2): the routing base is used to
 * judge whether a correction is material, and for nothing else.
 */
helper_section( 'postcode_route_base' );

helper_same( '33434', Shipping_Desk_Helpers::postcode_route_base( '33434' ), 'reads a bare 5-digit ZIP' );
helper_same( '33434', Shipping_Desk_Helpers::postcode_route_base( '33434-5844' ), 'reads through a hyphenated ZIP+4' );
helper_same( '33434', Shipping_Desk_Helpers::postcode_route_base( '334345844' ), 'reads through an unpunctuated ZIP+4' );
helper_same( '33434', Shipping_Desk_Helpers::postcode_route_base( ' 33434-5844 ' ), 'ignores surrounding whitespace' );
helper_same( '', Shipping_Desk_Helpers::postcode_route_base( '3343' ), 'a truncated ZIP has no routing base' );
helper_same( '', Shipping_Desk_Helpers::postcode_route_base( 'K1A 0B1' ), 'a non-US postcode has no US routing base' );
helper_same( '', Shipping_Desk_Helpers::postcode_route_base( '' ), 'an empty postcode has no routing base' );
helper_same( '', Shipping_Desk_Helpers::postcode_route_base( null ), 'a null postcode has no routing base' );

helper_section( 'normalise_address_text' );

helper_same( '123 MAIN ST', Shipping_Desk_Helpers::normalise_address_text( '123 Main St.' ), 'folds case and punctuation' );
helper_same( '123 MAIN ST', Shipping_Desk_Helpers::normalise_address_text( '123 main street' ), 'expands a spelled-out suffix' );
helper_same( '123 MAIN ST APT 5', Shipping_Desk_Helpers::normalise_address_text( '123 Main St, Apartment 5' ), 'expands a unit word' );
helper_same( '123 MAIN ST APT 5', Shipping_Desk_Helpers::normalise_address_text( '123 MAIN STREET #5' ), 'drops a hash and keeps the number' );
helper_same( '123 MAIN ST APT 50', Shipping_Desk_Helpers::normalise_address_text( '123 Main St Apt 50' ), 'never folds two unit numbers together' );
helper_same( '', Shipping_Desk_Helpers::normalise_address_text( '   ' ), 'whitespace normalises to nothing' );
helper_same( '', Shipping_Desk_Helpers::normalise_address_text( null ), 'null normalises to nothing' );

/*
 * The one rule both surfaces use to decide whether to interrupt someone. Tiers
 * are chosen by consequence, not by which field the verifier touched: a
 * formatting change is free to ignore, a different place is not.
 */
helper_section( 'address_change_is_material' );

$address = [
    'line1'        => '504 Circle Dr',
    'line2'        => '',
    'town'         => 'Wharton',
    'county'       => 'TX',
    'postcode'     => '77488',
    'country_code' => 'US',
];

helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'postcode' => '77488-1234' ] ), 'adding a ZIP+4 is not material' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( [ 'postcode' => '77488-1234' ], [ 'postcode' => '77488' ] ), 'dropping a ZIP+4 is not material' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '504 CIRCLE DR', 'town' => 'WHARTON', 'county' => 'TX' ] ), 'case-only changes are not material' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '504 Circle Drive' ] ), 'a spelled-out street suffix is not material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '504 Circle Street' ] ), 'a different street type is material' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line2' => 'Apt 5' ] ), 'a suite appearing on line 2 alone is not material' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [] ), 'an empty recommendation changes nothing' );
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '504 CIRCLE DR', 'postcode' => '77488-1234', 'country_code' => 'US' ] ), 'a full standardisation of the same place is not material' );

$suite = [
    'line1'        => '123 Main St',
    'line2'        => 'Apt 5',
    'town'         => 'Wharton',
    'county'       => 'TX',
    'postcode'     => '77488',
    'country_code' => 'US',
];
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( $suite, [ 'line1' => '123 MAIN ST APT 5', 'line2' => '' ] ), 'a suite folded into line 1 is not material' );

/*
 * The reported shape: nothing on line 1, a bare identifier on line 2.
 *
 * "2H" is what the customer typed; the fold comes back as "APT 2H". Stripping the
 * identifier alone left "123 example st apt" against "123 example st", which read
 * as a different street - so a plain fold demanded a decision on the orders screen
 * and was skipped on the block checkout.
 */
$bare = [
	'line1'        => '123 Example St',
	'line2'        => '2H',
	'town'         => 'Wharton',
	'county'       => 'TX',
	'postcode'     => '77488',
	'country_code' => 'US',
];
helper_same(
	false,
	Shipping_Desk_Helpers::address_change_is_material( $bare, [ 'line1' => '123 EXAMPLE ST APT 2H', 'line2' => '', 'postcode' => '77488-1234' ] ),
	'a bare identifier carried into line 1 is not material'
);
helper_same(
	false,
	Shipping_Desk_Helpers::address_change_is_material( $bare, [ 'line1' => '123 EXAMPLE ST UNIT 2H', 'line2' => '' ] ),
	'and it stays immaterial whatever designator the verifier chose'
);
helper_same(
	true,
	Shipping_Desk_Helpers::address_change_is_material( [ 'line1' => '123 Example St', 'line2' => '', 'town' => 'Wharton', 'county' => 'TX', 'postcode' => '77488', 'country_code' => 'US' ], [ 'line1' => '123 EXAMPLE ST APT 9', 'line2' => '' ] ),
	'but a unit the customer never mentioned is still material'
);
helper_same( false, Shipping_Desk_Helpers::address_change_is_material( [ 'line1' => '123 Main St', 'line2' => '', 'postcode' => '77488' ], [ 'line2' => 'Apt 5' ] ), 'a suite moved onto line 2 is not material' );

helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'postcode' => '77489' ] ), 'a different routing base is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'town' => 'El Campo' ] ), 'a different city is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'county' => 'LA' ] ), 'a different state is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '506 Circle Dr' ] ), 'a different street number is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'line1' => '504 Circle Dr Apt 5' ] ), 'a suite the verifier added is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( $address, [ 'country_code' => 'CA' ] ), 'a different country is material' );
helper_same( true, Shipping_Desk_Helpers::address_change_is_material( [ 'postcode' => '' ], [ 'postcode' => '77488' ] ), 'filling in a missing postcode is material' );

/*
 * Wording shown next to a validator suggestion. Shippo names the fields it
 * touched; the operator needs to know what actually changed.
 */
helper_section( 'describe_address_changes' );

helper_same(
    [ 'ZIP+4 added', 'street format corrected', 'suite moved to line 2' ],
    Shipping_Desk_Helpers::describe_address_changes( [ 'postal_code', 'address_line_1', 'address_line_2' ] ),
    'maps Shippo field names to wording'
);
helper_same( [ 'ZIP+4 added' ], Shipping_Desk_Helpers::describe_address_changes( [ 'postal_code', 'postal_code' ] ), 'de-duplicates repeated fields' );
helper_same( [ 'ZIP+4 added' ], Shipping_Desk_Helpers::describe_address_changes( [ '  POSTAL_CODE ' ] ), 'ignores case and padding' );
helper_same( [ 'unknown_field' ], Shipping_Desk_Helpers::describe_address_changes( [ 'unknown_field' ] ), 'passes an unknown field through rather than hiding it' );
helper_same( [], Shipping_Desk_Helpers::describe_address_changes( [] ), 'nothing changed, nothing shown' );

helper_section( 'format_address' );

helper_same(
    '965 Mission St, San Francisco, CA 94103',
    Shipping_Desk_Helpers::format_address( [ 'line1' => '965 Mission St', 'town' => 'San Francisco', 'county' => 'CA', 'postcode' => '94103' ] ),
    'builds one line from the parts'
);
helper_same(
    '965 Mission St, Suite 200, San Francisco, CA 94103',
    Shipping_Desk_Helpers::format_address( [ 'line1' => '965 Mission St', 'line2' => 'Suite 200', 'town' => 'San Francisco', 'county' => 'CA', 'postcode' => '94103' ] ),
    'includes the second line'
);
helper_same(
    'San Francisco, CA 94103',
    Shipping_Desk_Helpers::format_address( [ 'town' => 'San Francisco', 'county' => 'CA', 'postcode' => '94103' ] ),
    'omits a missing street without leaving a stray comma'
);
helper_same( 'Boca Raton 33434', Shipping_Desk_Helpers::format_address( [ 'town' => 'Boca Raton', 'postcode' => '33434' ] ), 'joins a ZIP to the town when there is no state' );
helper_same( 'FL', Shipping_Desk_Helpers::format_address( [ 'county' => 'FL' ] ), 'survives a state on its own' );
helper_same( '', Shipping_Desk_Helpers::format_address( [] ), 'an empty address is an empty string' );
helper_same( 'Suite 200', Shipping_Desk_Helpers::format_address( [ 'line2' => '  Suite 200  ' ] ), 'trims each part' );

/*
 * Stored quotes are only reused while their inputs and dispatch day still match, so
 * the fingerprint and the freshness rule are worth pinning down.
 */
helper_section( 'rate_inputs_fingerprint' );

$fp_payload = [
    'to_address'         => [ 'line1' => '504 Circle Dr.', 'town' => 'Wharton', 'county' => 'TX', 'postcode' => '77488', 'country_code' => 'US' ],
    'parcels'            => [ [ 'weight' => 12, 'weight_unit' => 'oz' ] ],
    'estimated_value'    => '42.00',
    'currency_code'      => 'USD',
    'customer_reference' => '1001',
];

$fp = Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_payload, 'shippo', 'test' );
helper_same( 32, strlen( $fp ), 'returns a fixed-length hash' );
helper_same( $fp, Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_payload, 'shippo', 'test' ), 'is stable for identical inputs' );
helper_same( $fp, Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_payload, 'shippo', 'TEST' ), 'ignores the case of the mode' );

$fp_case = $fp_payload;
$fp_case['to_address']['town']  = 'WHARTON';
$fp_case['to_address']['line1'] = '504 CIRCLE DR.';
helper_same( $fp, Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_case, 'shippo', 'test' ), 'a capitalisation difference is not an input change' );

$fp_weight = $fp_payload;
$fp_weight['parcels'][0]['weight'] = 13;
helper_same( false, $fp === Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_weight, 'shippo', 'test' ), 'a different weight changes the fingerprint' );

$fp_liability = $fp_payload;
$fp_liability['liability_amount'] = 100;
helper_same( false, $fp === Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_liability, 'shippo', 'test' ), 'insurance changes the fingerprint' );

$fp_signature = $fp_payload;
$fp_signature['signature_confirmation'] = 'ADULT';
helper_same( false, $fp === Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_signature, 'shippo', 'test' ), 'signature changes the fingerprint' );

helper_same( false, $fp === Shipping_Desk_Helpers::rate_inputs_fingerprint( $fp_payload, 'veeqo', 'test' ), 'the provider is part of the fingerprint' );

helper_section( 'quote_record_expiry' );

$now = 1700000000;
helper_same( $now + ( 72 * 3600 ), Shipping_Desk_Helpers::quote_record_expiry( $now ), 'no carrier expiry means the 72 hour cap' );
helper_same( $now + 3600, Shipping_Desk_Helpers::quote_record_expiry( $now, $now + 3600 ), 'an earlier carrier expiry wins' );
helper_same( $now + ( 72 * 3600 ), Shipping_Desk_Helpers::quote_record_expiry( $now, $now + ( 200 * 3600 ) ), 'a later carrier expiry is capped' );
helper_same( $now, Shipping_Desk_Helpers::quote_record_expiry( $now, $now - 60 ), 'an expiry in the past never returns a moment before now' );
helper_same( $now + 3600, Shipping_Desk_Helpers::quote_record_expiry( $now, null, 1 ), 'the cap is configurable' );

helper_section( 'quote_record_is_fresh' );

$fresh_now = 1700000000;
$record    = [
    'rates'        => [ [ 'rate_id' => 'r1', 'total_charge' => '9.99' ] ],
    'expires_at'   => $fresh_now + 3600,
    'dispatch_day' => '2026-11-17',
];

helper_same( true, Shipping_Desk_Helpers::quote_record_is_fresh( $record, '2026-11-17', $fresh_now ), 'fresh while the day and expiry hold' );
helper_same( false, Shipping_Desk_Helpers::quote_record_is_fresh( $record, '2026-11-18', $fresh_now ), 'stale once the dispatch day moves on' );
helper_same( false, Shipping_Desk_Helpers::quote_record_is_fresh( $record, '2026-11-17', $fresh_now + 3600 ), 'stale at the carrier expiry' );
helper_same( false, Shipping_Desk_Helpers::quote_record_is_fresh( [], '2026-11-17', $fresh_now ), 'an empty record is never fresh' );

$pending = [
    'rates'              => [],
    'address_validation' => [ 'status' => 'corrected' ],
    'expires_at'         => $fresh_now + 3600,
];
helper_same( true, Shipping_Desk_Helpers::quote_record_is_fresh( $pending, '', $fresh_now ), 'a pending address decision is a usable record' );

$no_day = [ 'rates' => $record['rates'], 'expires_at' => $fresh_now + 3600 ];
helper_same( true, Shipping_Desk_Helpers::quote_record_is_fresh( $no_day, 'anything', $fresh_now ), 'a provider without a dispatch day only relies on the expiry' );

/*
 * Label cost, as reported by the provider that sold it. Anything unparseable must
 * come back empty rather than 0, because BrikPanel treats a stored 0 as "this order
 * cost nothing" and an empty value as "use what was charged".
 */
helper_section( 'label_cost' );

helper_same(
    [ 'amount' => 12.34, 'currency' => 'USD' ],
    Shipping_Desk_Helpers::label_cost( 'shippo', [ 'total_charge' => '12.34', 'currency' => 'usd' ] ),
    'reads a Shippo rate amount and upper-cases its currency'
);
helper_same(
    [ 'amount' => 9.5, 'currency' => 'USD' ],
    Shipping_Desk_Helpers::label_cost( 'veeqo', [ 'total_charge' => [ 'value' => 9.5, 'unit' => 'USD' ] ] ),
    'reads Veeqo total_charge.value and .unit'
);
helper_same(
    [ 'amount' => 12.45, 'currency' => '' ],
    Shipping_Desk_Helpers::label_cost( 'veeqo', [ 'charges' => [ [ 'value' => 12.45 ], [ 'value' => 0 ] ] ] ),
    'falls back to summing itemised charges'
);
helper_same(
    [ 'amount' => 7.0, 'currency' => '' ],
    Shipping_Desk_Helpers::label_cost( 'veeqo', [ 'total_charge' => '7.00' ] ),
    'accepts a plain numeric total_charge'
);
helper_same( [], Shipping_Desk_Helpers::label_cost( 'shippo', [] ), 'no charge means no value, not zero' );
helper_same( [], Shipping_Desk_Helpers::label_cost( 'shippo', [ 'total_charge' => '0.00' ] ), 'a zero charge records nothing' );
helper_same( [], Shipping_Desk_Helpers::label_cost( 'shippo', [ 'total_charge' => 'abc' ] ), 'an unparseable charge records nothing' );
helper_same( [], Shipping_Desk_Helpers::label_cost( 'veeqo', [ 'total_charge' => [ 'value' => -3 ] ] ), 'a negative charge records nothing' );

helper_section( 'daily spend' );

$spend_one = Shipping_Desk_Helpers::accumulate_daily( [], '2026-11-16', 'USD', 8.20 );
helper_same( 8.20, $spend_one['totals']['USD']['amount'], 'a first purchase starts the day' );
helper_same( 1, $spend_one['totals']['USD']['labels'], 'and counts one label' );

$spend_two = Shipping_Desk_Helpers::accumulate_daily( $spend_one, '2026-11-16', 'USD', 2.50 );
helper_same( 10.70, $spend_two['totals']['USD']['amount'], 'a second purchase adds to it' );
helper_same( 2, $spend_two['totals']['USD']['labels'], 'and counts a second label' );

$spend_cents = Shipping_Desk_Helpers::accumulate_daily(
    Shipping_Desk_Helpers::accumulate_daily( [], '2026-11-16', 'USD', 0.10 ),
    '2026-11-16',
    'USD',
    0.20
);
helper_same( 0.3, $spend_cents['totals']['USD']['amount'], 'cents are rounded, so 0.10 + 0.20 is 0.30' );

$spend_next_day = Shipping_Desk_Helpers::accumulate_daily( $spend_two, '2026-11-17', 'USD', 5.00 );
helper_same( '2026-11-17', $spend_next_day['date'], 'a purchase on a new day replaces the date' );
helper_same( 5.00, $spend_next_day['totals']['USD']['amount'], 'and the total starts again: that is the midnight reset' );
helper_same( 1, $spend_next_day['totals']['USD']['labels'], 'with a fresh label count' );

$spend_mixed = Shipping_Desk_Helpers::accumulate_daily( $spend_two, '2026-11-16', 'EUR', 4.00 );
helper_same( 10.70, $spend_mixed['totals']['USD']['amount'], 'another currency leaves the first total alone' );
helper_same( 4.00, $spend_mixed['totals']['EUR']['amount'], 'and keeps its own' );

helper_same( null, Shipping_Desk_Helpers::daily_total( $spend_two, '2026-11-18', 'USD' ), 'a stale date reads as nothing' );
helper_same( null, Shipping_Desk_Helpers::daily_total( $spend_two, '2026-11-16', 'EUR' ), 'a currency with no total reads as nothing' );

$spend_read = Shipping_Desk_Helpers::daily_total( $spend_two, '2026-11-16', 'usd' );
helper_same( 10.70, $spend_read['amount'], 'the currency is case-insensitive' );
helper_same( 2, $spend_read['labels'], 'and the label count comes back with the total' );

helper_section( 'insurance shortfall' );

helper_same( false, Shipping_Desk_Helpers::insurance_shortfall( 0, '' ), 'no cover requested is never a shortfall' );
helper_same( false, Shipping_Desk_Helpers::insurance_shortfall( 250, '250.00' ), 'the requested cover applied in full is fine' );
helper_same( false, Shipping_Desk_Helpers::insurance_shortfall( 250, '249.99' ), 'a cent of rounding is not a shortfall' );
helper_same( true, Shipping_Desk_Helpers::insurance_shortfall( 250, '' ), 'cover requested and none applied is a shortfall' );
helper_same( true, Shipping_Desk_Helpers::insurance_shortfall( 250, '100.00' ), 'cover applied for less than asked is a shortfall' );
helper_same( false, Shipping_Desk_Helpers::insurance_shortfall( 250, '2500' ), 'more cover than asked is not a shortfall' );

helper_same( true, Shipping_Desk_Helpers::insurance_premium_missing( '250.00', '', true ), 'a labelled shipment with cover and no premium is flagged' );
helper_same( false, Shipping_Desk_Helpers::insurance_premium_missing( '250.00', '2.50', true ), 'cover with a premium is not' );
helper_same( false, Shipping_Desk_Helpers::insurance_premium_missing( '250.00', '', false ), 'cover with no label behind it is not - test mode omits fees' );
helper_same( false, Shipping_Desk_Helpers::insurance_premium_missing( '', '', true ), 'no cover at all is not flagged' );

helper_section( 'a stored quote belongs to the account that priced it' );

$quote_easypost = [ 'provider' => 'easypost', 'mode' => 'test', 'rates' => [ [ 'rate_id' => 'rate_1' ] ] ];

helper_same( true, Shipping_Desk_Helpers::quote_record_matches_provider( $quote_easypost, 'easypost', 'test' ), 'the same provider and mode still matches' );
helper_same( false, Shipping_Desk_Helpers::quote_record_matches_provider( $quote_easypost, 'shippo', 'test' ), 'another provider does not' );
helper_same( false, Shipping_Desk_Helpers::quote_record_matches_provider( $quote_easypost, 'easypost', 'live' ), 'nor does the other label mode' );
helper_same( false, Shipping_Desk_Helpers::quote_record_matches_provider( [ 'rates' => [ [ 'rate_id' => 'rate_1' ] ] ], 'easypost', 'test' ), 'a record with no stamp cannot be trusted' );

helper_same( null, Shipping_Desk_Helpers::daily_average( [ 'amount' => 0.0, 'labels' => 0 ] ), 'no labels means no average' );
helper_same( 42.1, Shipping_Desk_Helpers::daily_average( [ 'amount' => 42.10, 'labels' => 1 ] ), 'one label averages the total' );
helper_same( 6.01, Shipping_Desk_Helpers::daily_average( [ 'amount' => 42.10, 'labels' => 7 ] ), 'the average divides the day by its labels' );
helper_same( 6.67, Shipping_Desk_Helpers::daily_average( [ 'amount' => 20.0, 'labels' => 3 ] ), 'and rounds to cents' );
helper_same( null, Shipping_Desk_Helpers::daily_average( [] ), 'a missing record has no average' );

/* The Lookup page's label buttons are bound to one shipment id. */
helper_section( 'the lookup nonce is bound to its shipment' );

helper_same( 'shipping_desk_lookup_rem-9', Shipping_Desk_Helpers::lookup_nonce_action( 'rem-9' ), 'lookup_nonce_action() names the shipment id it is minted for' );
helper_same( true, Shipping_Desk_Helpers::lookup_nonce_action( 'rem-9' ) !== Shipping_Desk_Helpers::lookup_nonce_action( 'rem-10' ), 'and two different shipment ids give two different actions' );

echo PHP_EOL . 'Checks: ' . $GLOBALS['helper_checks'] . ', failures: ' . $GLOBALS['helper_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['helper_failures'] ? 0 : 1 );
