<?php
/**
 * Gate for writing a verified address onto the order.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress, no WooCommerce. It stubs the order
 * and the two WordPress functions the helpers need.
 *
 * The bug this exists because of: the correction was never written to the order. The
 * choice was stored at quote time and acted on at purchase, so the operator ticked
 * "also update the address", got correctly priced quotes, bought the label - and the
 * order still read the old address with no note anywhere to say why. Applying it when
 * it is accepted removes the deferred state, and these checks pin both halves:
 *
 *   - the fields the verifier returned are written, and only those
 *   - line 2 survives a recommendation that does not mention it
 *   - a second application is silent (no second save, no second note)
 *   - the billing address is never touched
 *   - the rate handler applies it, and nothing defers it to the purchase
 *
 * Run:  php tests/address-apply-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['apply_checks']   = 0;
$GLOBALS['apply_failures'] = 0;

function apply_check( $condition, $label ) {
	$GLOBALS['apply_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['apply_failures']++;
	echo "  FAIL  {$label}\n";
}

function apply_same( $want, $got, $label ) {
	$GLOBALS['apply_checks']++;
	if ( $want === $got ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['apply_failures']++;
	echo "  FAIL  {$label} (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ")\n";
}

/* -- WordPress stand-ins ---------------------------------------------------- */

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Just enough of an order: the shipping address, a save counter, and billing
 * setters that record a call so a stray write is visible.
 */
class WC_Order {
	public $address = [
		'line1'        => '',
		'line2'        => '',
		'town'         => '',
		'county'       => '',
		'postcode'     => '',
		'country_code' => '',
	];
	public $billing_calls = [];
	public $saves         = 0;

	public function __construct( array $address = [] ) {
		$this->address = array_merge( $this->address, $address );
	}

	public function get_shipping_address_1() {
		return $this->address['line1'];
	}

	public function get_shipping_address_2() {
		return $this->address['line2'];
	}

	public function get_shipping_city() {
		return $this->address['town'];
	}

	public function get_shipping_state() {
		return $this->address['county'];
	}

	public function get_shipping_postcode() {
		return $this->address['postcode'];
	}

	public function get_shipping_country() {
		return $this->address['country_code'];
	}

	public function set_shipping_address_1( $value ) {
		$this->address['line1'] = (string) $value;
		return $this;
	}

	public function set_shipping_address_2( $value ) {
		$this->address['line2'] = (string) $value;
		return $this;
	}

	public function set_shipping_city( $value ) {
		$this->address['town'] = (string) $value;
		return $this;
	}

	public function set_shipping_state( $value ) {
		$this->address['county'] = (string) $value;
		return $this;
	}

	public function set_shipping_postcode( $value ) {
		$this->address['postcode'] = (string) $value;
		return $this;
	}

	public function set_shipping_country( $value ) {
		$this->address['country_code'] = (string) $value;
		return $this;
	}

	public function set_billing_address_1( $value ) {
		$this->billing_calls[] = 'address_1';
		return $this;
	}

	public function set_billing_address_2( $value ) {
		$this->billing_calls[] = 'address_2';
		return $this;
	}

	public function set_billing_city( $value ) {
		$this->billing_calls[] = 'city';
		return $this;
	}

	public function set_billing_state( $value ) {
		$this->billing_calls[] = 'state';
		return $this;
	}

	public function set_billing_postcode( $value ) {
		$this->billing_calls[] = 'postcode';
		return $this;
	}

	public function set_billing_country( $value ) {
		$this->billing_calls[] = 'country';
		return $this;
	}

	public function save() {
		$this->saves++;
		return true;
	}
}

require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';

$plugin_class = new ReflectionClass( 'Shipping_Desk_Plugin' );
$plugin       = $plugin_class->newInstanceWithoutConstructor();
$apply        = $plugin_class->getMethod( 'apply_verified_address' );

echo "Shipping Desk address-apply checks\n";
echo "==================================\n\n";

/* -- the correction lands on the order ----------------------------------- */

$validation = [
	'validator'   => 'easypost',
	'changed'     => [ 'postal_code', 'city_locality' ],
	'original'    => [
		'line1'    => '100 main st',
		'town'     => 'San Fransisco',
		'county'   => 'CA',
		'postcode' => '94103',
	],
	'recommended' => [
		'line1'        => '100 MAIN ST',
		'town'         => 'SAN FRANCISCO',
		'county'       => 'CA',
		'postcode'     => '94103-1234',
		'country_code' => 'US',
	],
];

$order = new WC_Order( [
	'line1'        => '100 main st',
	'line2'        => 'Suite 5',
	'town'         => 'San Fransisco',
	'county'       => 'CA',
	'postcode'     => '94103',
	'country_code' => 'US',
] );

$note = $apply->invoke( $plugin, $order, $validation );

echo "[writing the address]\n";
apply_check( '' !== $note, 'an accepted suggestion returns a note' );
apply_same( '100 MAIN ST', $order->address['line1'], 'the street is the verified one' );
apply_same( 'SAN FRANCISCO', $order->address['town'], 'the city is the verified one' );
apply_same( '94103-1234', $order->address['postcode'], 'the postcode is the verified one' );
apply_same( 'US', $order->address['country_code'], 'a returned country is written too' );
apply_same( 'Suite 5', $order->address['line2'], 'line 2 survives a recommendation that omits it' );
apply_same( 'CA', $order->address['county'], 'a field the verifier did not change is left alone' );
apply_same( 1, $order->saves, 'the order is saved once' );
apply_check( [] === $order->billing_calls, 'the billing address is never touched' );

echo "\n[the note]\n";
apply_check( false !== strpos( $note, 'EasyPost' ), 'it names the verifier that answered' );
apply_check( false !== strpos( $note, '100 main st' ), 'it records the address as it was' );
apply_check( false !== strpos( $note, '94103-1234' ), 'and the address as verified' );
apply_check( false !== strpos( $note, 'ZIP+4 added' ), 'and what changed' );

echo "\n[applying twice]\n";
apply_same( '', $apply->invoke( $plugin, $order, $validation ), 'a second application reports nothing' );
apply_same( 1, $order->saves, 'and does not save the order again' );

echo "\n[nothing to apply]\n";
$empty = new WC_Order();
apply_same( '', $apply->invoke( $plugin, $empty, [ 'recommended' => [] ] ), 'an empty recommendation returns no note' );
apply_same( 0, $empty->saves, 'and writes nothing' );

$shippo = $apply->invoke( $plugin, new WC_Order( [ 'line1' => '1 A St' ] ), [
	'validator'   => 'shippo',
	'changed'     => [ 'address_line_1' ],
	'recommended' => [ 'line1' => '1 A ST' ],
] );
apply_check( false !== strpos( $shippo, 'Shippo' ), 'the note names Shippo when Shippo answered' );

echo "\n[the wiring]\n";

// Normalised: the files are checked out with CRLF on Windows.
$plugin_php = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) );
$helpers    = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/helpers.php' ) );
$js         = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' ) );

apply_check(
	false !== strpos( $plugin_php, '$address_note = $this->apply_verified_address( $order, $address_validation );' ),
	'the rate request applies the address when it is accepted'
);
apply_check(
	false === strpos( $plugin_php, 'store_address_decision' ) && false === strpos( $plugin_php, 'apply_address_decision' ),
	'and nothing is deferred to the purchase any more'
);
apply_check(
	false === strpos( $helpers, 'address_decision' ),
	'the decision meta key is gone, so no stale intent is left on any order'
);
apply_check(
	false !== strpos( $plugin_php, "\$address_validation['order_updated'] = true;" ),
	'the answer tells the browser the order itself changed'
);
apply_check(
	false !== strpos( $js, 'validation.order_updated' ),
	'and the row badge says so'
);
apply_check(
	false !== strpos( $js, "update_order_address: \$row.data('update-order-address') ? 1 : 0" ),
	'the checkbox is posted with the rate request'
);
apply_check(
	false === strpos( $js, 'when the label is bought' ),
	'the checkbox no longer promises a later update'
);
// One quoting action means a reship panel and an ordinary row cannot diverge: both
// reach the address decision through the same request, so what was proved on a reship
// holds for a first purchase.
apply_same(
	1,
	substr_count( $js, "action: 'shipping_desk_get_rates'" ),
	'there is a single quoting request in the admin JS'
);
apply_check(
	1 === preg_match( '/if \(resp\.data\.needs_address_choice\) \{\s+renderAddressChoice\(/', $js ),
	'the address decision arrives on that request, not on a path of its own'
);

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['apply_checks'], $GLOBALS['apply_failures'] );
exit( $GLOBALS['apply_failures'] > 0 ? 1 : 0 );
