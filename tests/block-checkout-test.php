<?php
/**
 * Gate for the block checkout's server-side address handling.
 *
 * The block checkout is a React app on the Store API: there is no `updated_checkout`,
 * no `#shipping_address_1`, and no place to put the classic suggestion box. What is
 * checked here is the only thing the plugin can do there — standardise the order at
 * creation, once, and only when the change cannot move the parcel.
 *
 * The harness is stubs, because WooCommerce is not installed: enough of an order, of
 * the plugin and of a verifier to run the handler end to end. The stub names are the
 * real ones because the class under test type-hints against them.
 *
 * Run:  php tests/block-checkout-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 *
 * @package ShippingDesk
 */

error_reporting( E_ALL );

$GLOBALS['bc_checks']   = 0;
$GLOBALS['bc_failures'] = 0;

function bc_check( $condition, $label ) {
	$GLOBALS['bc_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['bc_failures']++;
	echo "  FAIL  {$label}\n";
}

function bc_same( $want, $got, $label ) {
	$GLOBALS['bc_checks']++;
	if ( $want === $got ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['bc_failures']++;
	echo "  FAIL  {$label} (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ")\n";
}

/* -- WordPress stand-ins ---------------------------------------------------- */

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

$GLOBALS['bc_filters']    = [];
$GLOBALS['bc_transients'] = [];
$GLOBALS['bc_actions']    = [];
$GLOBALS['bc_page']       = null;
$GLOBALS['bc_page_id']    = 0;

function apply_filters( $tag, $value ) {
	if ( isset( $GLOBALS['bc_filters'][ $tag ] ) && is_callable( $GLOBALS['bc_filters'][ $tag ] ) ) {
		return call_user_func_array( $GLOBALS['bc_filters'][ $tag ], array_slice( func_get_args(), 1 ) );
	}

	return $value;
}

function get_transient( $key ) {
	return isset( $GLOBALS['bc_transients'][ $key ] ) ? $GLOBALS['bc_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['bc_transients'][ $key ] = $value;

	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['bc_transients'][ $key ] );

	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['bc_actions'][ $hook ] = [ $callback, $priority, $accepted ];

	return true;
}

function wc_get_page_id( $page ) {
	return 'checkout' === $page ? (int) $GLOBALS['bc_page_id'] : 0;
}

function get_post( $id ) {
	return $GLOBALS['bc_page'];
}

function has_block( $block, $post = null ) {
	return is_object( $GLOBALS['bc_page'] )
		&& isset( $GLOBALS['bc_page']->blocks )
		&& in_array( $block, (array) $GLOBALS['bc_page']->blocks, true );
}

/* -- Just enough order ------------------------------------------------------ */

class WC_Order {
	public $address = [
		'line1'        => '',
		'line2'        => '',
		'town'         => '',
		'county'       => '',
		'postcode'     => '',
		'country_code' => '',
	];
	public $billing_calls = 0;
	public $saves         = 0;
	public $notes         = [];
	public $meta          = [];

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
		$this->billing_calls++;
		return $this;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {
		$this->saves++;
		return 1;
	}
}

/* -- Just enough plugin and verifier --------------------------------------- */

class Shipping_Desk_Plugin {
	public $enabled = true;
	public $client  = null;

	public function checkout_validation_enabled() {
		return $this->enabled;
	}

	public function validator_client() {
		return null === $this->client ? null : [ 'easypost', $this->client ];
	}
}

class BC_Fake_Verifier {
	public $calls = 0;
	protected $reply;

	public function __construct( array $reply ) {
		$this->reply = $reply;
	}

	public function validate_address( array $address ) {
		$this->calls++;

		return $this->reply;
	}
}

/**
 * A verifier answer, in the shape the clients return before describing.
 */
function bc_corrected( array $recommended, array $changed = [] ) {
	return [
		'status'       => empty( $recommended ) ? 'valid' : 'corrected',
		'is_valid'     => true,
		'recommended'  => $recommended,
		'changed'      => $changed,
		'reasons'      => [],
		'address_type' => 'residential',
		'confidence'   => [],
		'error'        => '',
	];
}

require dirname( __DIR__ ) . '/includes/helpers.php';
require dirname( __DIR__ ) . '/includes/class-checkout-address.php';
require dirname( __DIR__ ) . '/includes/class-block-checkout.php';

$plugin = new Shipping_Desk_Plugin();
$block  = new Shipping_Desk_Block_Checkout( $plugin );

echo "Shipping Desk block checkout checks\n";
echo "===================================\n\n";

echo "[the hooks]\n";

$block->init();

bc_check( isset( $GLOBALS['bc_actions']['woocommerce_store_api_checkout_update_order_from_request'] ), 'the Store API order-creation hook is registered' );
bc_check( isset( $GLOBALS['bc_actions']['woocommerce_store_api_checkout_order_processed'] ), 'and the post-payment one as a backstop' );
bc_same( 2, $GLOBALS['bc_actions']['woocommerce_store_api_checkout_update_order_from_request'][2], 'the first is given the order and the request' );

echo "\n[detecting the checkout type]\n";

$GLOBALS['bc_page_id'] = 12;
$GLOBALS['bc_page']    = (object) [ 'blocks' => [ 'woocommerce/checkout' ] ];
bc_check( true === Shipping_Desk_Block_Checkout::is_block_checkout(), 'a checkout page holding the block is detected' );

$GLOBALS['bc_page'] = (object) [ 'blocks' => [ 'core/shortcode' ] ];
bc_check( false === Shipping_Desk_Block_Checkout::is_block_checkout(), 'a shortcode checkout is not' );

$GLOBALS['bc_page_id'] = 0;
bc_check( false === Shipping_Desk_Block_Checkout::is_block_checkout(), 'and neither is a site with no checkout page' );

echo "\n[an immaterial correction is applied]\n";

$order  = new WC_Order(
	[
		'line1'        => '123 Main St APT 5',
		'line2'        => 'Unit 5',
		'town'         => 'Wharton',
		'county'       => 'TX',
		'postcode'     => '77488',
		'country_code' => 'US',
	]
);
$client = new BC_Fake_Verifier(
	bc_corrected(
		[
			'line1'        => '123 MAIN ST APT 5',
			'line2'        => '',
			'town'         => 'WHARTON',
			'county'       => 'TX',
			'postcode'     => '77488-1234',
			'country_code' => 'US',
		],
		[ 'address_line_1', 'address_line_2', 'postal_code' ]
	)
);

$out = $block->standardise_order( $order, null, $client );

bc_same( 'applied', $out['status'], 'the verdict is corrected and written' );
bc_check( true === $out['applied'], 'the address is written' );
bc_same( '77488-1234', $order->address['postcode'], 'the ZIP+4 reaches the order' );
bc_same( '', $order->address['line2'], 'and the folded unit leaves line 2' );
bc_same( '123 MAIN ST APT 5', $order->address['line1'], 'line 1 keeps the verifier spelling' );
bc_same( 1, count( $order->notes ), 'one note is written' );
bc_check( false !== strpos( $order->notes[0], 'block checkout has no confirmation step' ), 'the note says the customer did not confirm it' );
bc_check( false !== strpos( $order->notes[0], 'suite folded into line 1' ), 'and names the change in words' );
bc_same( 'applied', $order->get_meta( Shipping_Desk_Block_Checkout::DONE_META ), 'the order records what was done to it' );
bc_same( 0, $order->billing_calls, 'billing is never touched' );
bc_same( 1, $client->calls, 'one verifier call' );

echo "\n[and only once]\n";

$again = $block->standardise_order( $order, null, $client );

bc_same( 'already_checked', $again['status'], 'the second hook does nothing' );
bc_same( 1, $client->calls, 'and costs nothing' );
bc_same( 1, count( $order->notes ), 'nor writes a second note' );

echo "\n[a material correction is left alone]\n";

$material = new WC_Order(
	[
		'line1'        => '123 Main St',
		'town'         => 'Wharton',
		'county'       => 'TX',
		'postcode'     => '77488',
		'country_code' => 'US',
	]
);
$material_client = new BC_Fake_Verifier( bc_corrected( [ 'line1' => '999 OTHER ST', 'postcode' => '77488' ], [ 'address_line_1' ] ) );
$out             = $block->standardise_order( $material, null, $material_client );

bc_same( 'material', $out['status'], 'a different street is material' );
bc_check( false === $out['applied'], 'so nothing is written' );
bc_same( '123 Main St', $material->address['line1'], 'the order keeps what the customer typed' );
bc_same( 1, count( $material->notes ), 'but the order explains why nothing was done' );
bc_check( false !== strpos( $material->notes[0], 'could move the parcel' ), 'naming the reason' );
bc_check( false !== strpos( $material->notes[0], 'block checkout' ), 'and where the check ran' );
bc_same( 'material', $material->get_meta( Shipping_Desk_Block_Checkout::DONE_META ), 'the operator is left to decide it' );

echo "\n[an invalid address is left alone]\n";

$invalid = new WC_Order(
	[
		'line1'        => 'Nowhere Rd',
		'town'         => 'Wharton',
		'county'       => 'TX',
		'postcode'     => '77488',
		'country_code' => 'US',
	]
);
$invalid_client = new BC_Fake_Verifier(
	[
		'status'       => 'invalid',
		'is_valid'     => false,
		'recommended'  => [],
		'changed'      => [],
		'reasons'      => [ [ 'code' => 'E.ADDRESS.NOT_FOUND' ] ],
		'address_type' => '',
		'confidence'   => [],
		'error'        => '',
	]
);
$out = $block->standardise_order( $invalid, null, $invalid_client );

bc_same( 'invalid', $out['status'], 'the verdict is reported' );
bc_check( false === $out['applied'], 'without writing anything' );
bc_same( 'Nowhere Rd', $invalid->address['line1'], 'the address stands as entered' );
bc_same( 1, count( $invalid->notes ), 'an invalid address explains itself too' );
bc_check( false !== strpos( $invalid->notes[0], 'could not confirm' ), 'quoting what the verifier said' );

$clean    = new WC_Order( [ 'line1' => '123 Main St', 'postcode' => '77488', 'country_code' => 'US' ] );
$clean_out = $block->standardise_order( $clean, null, new BC_Fake_Verifier( bc_corrected( [] ) ) );
bc_same( 'valid', $clean_out['status'], 'a clean address is reported valid' );
bc_same( 0, count( $clean->notes ), 'and writes no note at all' );

echo "\n[the guards]\n";

$ca        = new WC_Order( [ 'line1' => '123 Main St', 'town' => 'Toronto', 'county' => 'ON', 'postcode' => 'M5V 2T6', 'country_code' => 'CA' ] );
$ca_client = new BC_Fake_Verifier( bc_corrected( [] ) );
$out       = $block->standardise_order( $ca, null, $ca_client );
bc_same( 'skipped', $out['status'], 'a non-US destination is never verified' );
bc_same( 0, $ca_client->calls, 'and costs no call' );

$plugin->enabled = false;
$off_client      = new BC_Fake_Verifier( bc_corrected( [] ) );
$out             = $block->standardise_order( new WC_Order( [ 'line1' => '123 Main St', 'postcode' => '77488', 'country_code' => 'US' ] ), null, $off_client );
bc_same( 'skipped', $out['status'], 'the setting switches it off' );
bc_same( 0, $off_client->calls, 'with no call either' );
$plugin->enabled = true;

$no_ship  = new WC_Order( [] );
$no_client = new BC_Fake_Verifier( bc_corrected( [] ) );
$out      = $block->standardise_order( $no_ship, null, $no_client );
bc_same( 'skipped', $out['status'], 'an order with no shipping address is left alone' );
bc_same( 0, $no_client->calls, 'and costs no call' );

$GLOBALS['bc_filters']['shipping_desk_block_checkout_standardise'] = function () {
	return false;
};
$filtered        = new WC_Order( [ 'line1' => '123 Main St', 'postcode' => '77488', 'country_code' => 'US' ] );
$filtered_client = new BC_Fake_Verifier( bc_corrected( [] ) );
$out             = $block->standardise_order( $filtered, null, $filtered_client );
bc_same( 'skipped', $out['status'], 'the filter can refuse the whole behaviour' );
bc_same( 0, $filtered_client->calls, 'before spending a call' );
bc_same( 'refused', $filtered->get_meta( Shipping_Desk_Block_Checkout::DONE_META ), 'and the refusal is recorded' );
unset( $GLOBALS['bc_filters']['shipping_desk_block_checkout_standardise'] );

echo "\n[the address book is shared with the classic endpoint]\n";

$typed = [
	'line1'        => '123 main st',
	'line2'        => '',
	'town'         => 'Wharton',
	'county'       => 'TX',
	'postcode'     => '77488',
	'country_code' => 'US',
];

// Written the way the classic endpoint stores it: the place, not the spelling.
$GLOBALS['bc_transients'][ Shipping_Desk_Checkout_Address::address_book_key( $typed ) ] = [
	'status'       => 'valid',
	'is_valid'     => true,
	'recommended'  => [ 'line1' => '123 MAIN ST', 'town' => 'WHARTON', 'county' => 'TX', 'postcode' => '77488-1234', 'country_code' => 'US' ],
	'changed'      => [],
	'reasons'      => [],
	'address_type' => 'residential',
	'confidence'   => [],
	'error'        => '',
	'validator'    => 'easypost',
];

$cached        = new WC_Order( $typed );
$cached_client = new BC_Fake_Verifier( bc_corrected( [] ) );
$out           = $block->standardise_order( $cached, null, $cached_client );

bc_same( 0, $cached_client->calls, 'a remembered address costs no call' );
bc_same( 'applied', $out['status'], 'the stored verdict is re-judged against this spelling' );
bc_same( '77488-1234', $cached->address['postcode'], 'and the correction is applied' );

echo "\n[source guarantees]\n";

$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-block-checkout.php' );

bc_check( false === strpos( $source, 'wc_add_notice' ), 'it never adds a checkout notice' );
bc_check( false === strpos( $source, 'RouteException' ), 'never throws a Store API error' );
bc_check( false === strpos( $source, 'throw ' ), 'and never throws at all' );
bc_check( false !== strpos( $source, 'shipping_desk_block_checkout_standardise' ), 'the opt-out filter is offered' );

$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/shipping-desk.php' );
bc_check( false !== strpos( $bootstrap, 'class-block-checkout.php' ), 'the plugin loads the class' );
bc_check( false !== strpos( $bootstrap, 'Shipping_Desk_Block_Checkout' ), 'and starts it' );

// Only the working repository has tools/: inside dist/public this file is not
// shipped, and a gate that demanded it would fail the published tree for being
// published. Same guard the other gates use.
$build_path = dirname( __DIR__ ) . '/tools/build-public.php';

if ( is_readable( $build_path ) ) {
	$build = (string) file_get_contents( $build_path );
	bc_check( false !== strpos( $build, 'includes/class-block-checkout.php' ), 'the release ships the class' );
	bc_check( false !== strpos( $build, 'tests/block-checkout-test.php' ), 'and this gate' );
}

echo PHP_EOL . 'Checks: ' . $GLOBALS['bc_checks'] . ', failures: ' . $GLOBALS['bc_failures'] . PHP_EOL;

exit( $GLOBALS['bc_failures'] > 0 ? 1 : 0 );