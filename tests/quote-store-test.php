<?php
/**
 * Gate for the quote store: stored quotes and remembered address verdicts.
 *
 * A stored quote is only worth showing while the account that priced it is still the active one
 * and the dispatch day it was priced for has not passed. This pins the behaviour that moved out
 * of the plugin class unchanged:
 *
 *   1. a record round-trips through the transient, stamped with the provider and mode
 *   2. it is retired when the provider or the mode changes, or the dispatch day moves on
 *   3. clearing by prefix touches only this plugin's quote and address transients
 *   4. an address verdict is remembered for a clean result, cached for the rest, and an
 *      outage is never cached
 *
 * No WordPress needed: the transient functions and the plugin are small stubs.
 *
 * Run:  php tests/quote-store-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['qs_checks']   = 0;
$GLOBALS['qs_failures'] = 0;
$GLOBALS['qs_store']    = array();
$GLOBALS['qs_queries']  = array();

function qs_check( $condition, $label, $detail = '' ) {
	$GLOBALS['qs_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['qs_failures']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
}

function get_transient( $key ) {
	return isset( $GLOBALS['qs_store'][ $key ] ) ? $GLOBALS['qs_store'][ $key ]['v'] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['qs_store'][ $key ] = array( 'v' => $value, 'ttl' => $ttl );
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['qs_store'][ $key ] );
	return true;
}

/** Just enough of the plugin: the three answers the store asks it for. */
class Shipping_Desk_Plugin {
	public $provider = 'shippo';
	public $mode     = 'test';
	public $day      = '2026-11-20';
	public function provider() { return $this->provider; }
	public function provider_mode( $provider ) { return $this->mode; }
	public function api( $provider = null, $mode = null ) {
		$day = $this->day;
		return new class( $day ) {
			private $day;
			public function __construct( $day ) { $this->day = $day; }
			public function dispatch_day() { return $this->day; }
		};
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
class WC_Order {}
}

class Qs_Order extends WC_Order {
	public $meta = array();
	public $saves = 0;
	public function get_id() { return 55; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saves++; }
}

class Qs_Client {
	public $calls = 0;
	public $reply = array( 'status' => 'valid', 'address_type' => 'residential' );
	public function validate_address( $address ) { $this->calls++; return $this->reply; }
}

/** A database that records the statements it is asked to run. */
class Qs_Wpdb {
	public $options = 'wp_options';
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function query( $sql ) { $GLOBALS['qs_queries'][] = $sql; return 1; }
}
$GLOBALS['wpdb'] = new Qs_Wpdb();

require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-quote-store.php';

echo "Shipping Desk quote store checks\n";
echo "================================\n";

function qs_fresh() {
	$GLOBALS['qs_store']   = array();
	$GLOBALS['qs_queries'] = array();
	$plugin = new Shipping_Desk_Plugin();
	return array( $plugin, new Shipping_Desk_Quote_Store( $plugin ) );
}

/* -- A stored quote -------------------------------------------------------- */

echo "\n[a stored quote]\n";
list( $plugin, $store ) = qs_fresh();

qs_check( 'shipping_desk_quotes_77' === ( new ReflectionMethod( $store, 'quote_record_key' ) )->invoke( $store, '77abc' ), 'the key is the order id, as an integer, under this plugin\'s prefix' );
qs_check( array() === $store->get_quote_record( 77 ), 'an order with no quotes answers an empty array, never false' );

$store->store_quote_record( 77, array(
	'rates'               => array( array( 'rate_id' => 'r1' ) ),
	'dispatch_day'        => '2026-11-20',
	'provider_expires_at' => time() + 7200,
) );
$record = $store->get_quote_record( 77 );
qs_check( 'shippo' === $record['provider'] && 'test' === $record['mode'], 'the record is stamped with the provider and mode that priced it' );
qs_check( ! isset( $record['provider_expires_at'] ) && isset( $record['expires_at'] ), 'the carrier expiry is folded into expires_at and not stored twice' );
qs_check( $record['expires_at'] <= time() + 7200 + 2, 'and a carrier expiry earlier than the cap wins' );
qs_check( $GLOBALS['qs_store']['shipping_desk_quotes_77']['ttl'] >= 60, 'the transient lives at least a minute, even for a record about to expire' );
qs_check( 72 === Shipping_Desk_Quote_Store::QUOTE_RECORD_CAP_HOURS, 'the hard cap is seventy-two hours' );
qs_check( 43200 === Shipping_Desk_Quote_Store::ADDRESS_VALIDATION_TTL, 'and a verdict is kept twelve hours' );

echo "\n[a quote is retired when it stops being the right one]\n";
qs_check( ! empty( $store->fresh_quote_record( 77 ) ), 'while provider, mode and day match it is offered' );

$plugin->provider = 'easypost';
qs_check( array() === $store->fresh_quote_record( 77 ), 'switching provider retires it: its rate ids belong to the account that priced it' );
$plugin->provider = 'shippo';
$plugin->mode     = 'live';
qs_check( array() === $store->fresh_quote_record( 77 ), 'and so does switching the label mode' );
$plugin->mode = 'test';
$plugin->day  = '2026-11-21';
qs_check( array() === $store->fresh_quote_record( 77 ), 'and so does the dispatch day moving on, since the day is printed on the label' );
$plugin->day = '2026-11-20';
qs_check( ! empty( $store->fresh_quote_record( 77 ) ), 'put back, it is offered again: nothing was deleted by looking' );
qs_check( ! empty( $store->fresh_quote_record( 77, 'shippo' ) ), 'an explicit provider is judged as given' );

$plugin->provider = 'veeqo';
$store->store_quote_record( 78, array( 'rates' => array( array( 'rate_id' => 'v1' ) ) ) );
qs_check( '' === $store->current_dispatch_day( 'veeqo' ), 'Veeqo has no dispatch day, so only the carrier expiry and the cap apply' );
qs_check( '2026-11-20' === $store->current_dispatch_day( 'easypost' ), 'a provider that has one is asked for it' );
$plugin->provider = 'shippo';

$store->delete_quote_record( 77 );
qs_check( array() === $store->get_quote_record( 77 ), 'a spent quote is deleted' );
qs_check( ! empty( $store->get_quote_record( 78 ) ), 'and only that order\'s' );

/* -- Clearing by prefix ------------------------------------------------------ */

echo "\n[clearing by prefix]\n";
list( $plugin, $store ) = qs_fresh();
$store->clear_cached_quotes();
$sql = implode( "\n", $GLOBALS['qs_queries'] );
qs_check( 4 === count( $GLOBALS['qs_queries'] ), 'one statement for each of the two prefixes, for the value and for its timeout row' );
qs_check( 4 === preg_match_all( '/DELETE FROM wp_options WHERE option_name LIKE/', $sql ), 'every statement is a DELETE on the options table' );
$like = function ( $s ) { return $GLOBALS['wpdb']->esc_like( $s ) . '%'; };
qs_check( false !== strpos( $sql, $like( '_transient_shipping_desk_quotes_' ) ) && false !== strpos( $sql, $like( '_transient_shipping_desk_addr_' ) ), 'scoped to this plugin\'s quote and address transients' );
qs_check( false !== strpos( $sql, $like( '_transient_timeout_shipping_desk_quotes_' ) ), 'including the timeout rows, which would otherwise be left behind' );
qs_check( false === strpos( $sql, "LIKE '%" ) && false === strpos( $sql, "LIKE '_transient_%'" ), 'and never a bare wildcard that could reach another plugin' );

$GLOBALS['qs_queries'] = array();
$store->clear_address_validation_cache( 55 );
$sql = implode( "\n", $GLOBALS['qs_queries'] );
qs_check( 2 === count( $GLOBALS['qs_queries'] ) && false !== strpos( $sql, $GLOBALS['wpdb']->esc_like( '_transient_shipping_desk_addr_55_' ) ), 'one order\'s verdicts are cleared by that order\'s prefix' );
$GLOBALS['qs_queries'] = array();
$store->clear_address_validation_cache( 0 );
$store->clear_address_validation_cache( -4 );
qs_check( array() === $GLOBALS['qs_queries'], 'an order id that is not positive clears nothing, rather than every order\'s verdicts' );


/* -- The address verdict ------------------------------------------------------- */

echo "\n[an address verdict]\n";
$address = array( 'line1' => '12 Example Road', 'line2' => '', 'town' => 'Sampletown', 'county' => 'ZZ', 'postcode' => '00000' );
$m       = Shipping_Desk_Helpers::meta_keys();

list( $plugin, $store ) = qs_fresh();
$order  = new Qs_Order();
$client = new Qs_Client();

$first = $store->validate_recipient_address( $order, $address, $client );
qs_check( 1 === $client->calls && 'valid' === $first['status'], 'the first look asks the verifier' );
qs_check( $address === $first['original'], 'and the answer carries the address it was about' );
qs_check( is_array( $order->get_meta( $m['address_checked'] ) ) && 'valid' === $order->get_meta( $m['address_checked'] )['status'], 'a clean verdict is remembered on the order' );
qs_check( 1 === $order->saves, 'and saved once' );

$again = $store->validate_recipient_address( $order, $address, $client );
qs_check( 1 === $client->calls && ! empty( $again['remembered'] ), 'the same address is answered from the order, not by paying again' );

$shouting = $address;
$shouting['line1'] = '  12 EXAMPLE ROAD ';
$store->validate_recipient_address( $order, $shouting, $client );
qs_check( 1 === $client->calls, 'case and stray spaces do not make it a different address' );

$moved = $address;
$moved['postcode'] = '11111';
$store->validate_recipient_address( $order, $moved, $client );
qs_check( 2 === $client->calls, 'a different postcode is a different address and is checked' );

echo "\n[a verdict that is not clean]\n";
list( $plugin, $store ) = qs_fresh();
$order         = new Qs_Order();
$client        = new Qs_Client();
$client->reply = array( 'status' => 'corrected', 'recommended' => array( 'line1' => '12 EXAMPLE RD' ) );
$store->validate_recipient_address( $order, $address, $client );
qs_check( array() === $order->meta, 'a correction is not remembered on the order: a declined one must be able to appear again' );
$store->validate_recipient_address( $order, $address, $client );
qs_check( 1 === $client->calls, 'but it is cached for the day, so a second click is free' );
qs_check( 43200 === array_values( $GLOBALS['qs_store'] )[0]['ttl'], 'for twelve hours' );

list( $plugin, $store ) = qs_fresh();
$order         = new Qs_Order();
$client        = new Qs_Client();
$client->reply = array( 'status' => 'unavailable', 'error' => 'timeout' );
$store->validate_recipient_address( $order, $address, $client );
$store->validate_recipient_address( $order, $address, $client );
qs_check( 2 === $client->calls, 'an outage is never cached: a verifier that was down for one click must not leave the address unchecked for twelve hours' );
qs_check( array() === $GLOBALS['qs_store'] && array() === $order->meta, 'and nothing is stored for it anywhere' );

/* -- The source ----------------------------------------------------------------- */

echo "\n[the source]\n";
$source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-quote-store.php' ) );
$plugin_source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) );

qs_check( 2 === substr_count( $source, '$wpdb->query(' ) && substr_count( $source, '$wpdb->query(' ) === substr_count( $source, '$wpdb->prepare(' ), 'the store runs two statements, and every one of them is prepared' );
qs_check( false === strpos( $source, 'SELECT' ) && false === strpos( $source, 'UPDATE' ) && false === strpos( $source, 'INSERT' ), 'and none of them reads or writes: they only delete this plugin\'s own transients' );
qs_check( false === strpos( $plugin_source, 'function quote_record_key' ) && false === strpos( $plugin_source, 'function store_quote_record' ), 'the plugin no longer defines the store: one definition, not two' );
qs_check( 0 === preg_match( '/\$this->(get_quote_record|store_quote_record|delete_quote_record|fresh_quote_record|clear_cached_quotes|clear_address_validation_cache|validate_recipient_address|current_dispatch_day)\(/', $plugin_source ), 'and nothing in it still calls them directly' );
// Callers are in the plugin and in the labels class, which took over the purchase code.
$labels_source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-labels.php' ) );
qs_check( 0 === preg_match( '/\$this->(get_quote_record|store_quote_record|delete_quote_record|fresh_quote_record|clear_cached_quotes|clear_address_validation_cache|validate_recipient_address|current_dispatch_day)\(/', $labels_source ), 'nor does the labels class: it asks the store through the plugin' );
// Callers are in the plugin, the labels class and the orders screen (which reads a stored quote
// to draw a row).
$screen_source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-orders-screen.php' ) );
qs_check( 0 === preg_match( '/\$this->(get_quote_record|store_quote_record|delete_quote_record|fresh_quote_record)\(/', $screen_source ), 'nor does the orders screen: it asks the store through the plugin' );
qs_check( preg_match_all( '/quote_store\(\)->/', $plugin_source . $labels_source . $screen_source ) >= 15, 'every caller goes through the one accessor' );
qs_check( 1 === substr_count( $plugin_source, 'new Shipping_Desk_Quote_Store(' ), 'which builds the store once' );

$manifest_path = dirname( __DIR__ ) . '/tools/build-public.php';
if ( is_file( $manifest_path ) ) {
	$manifest = str_replace( "\r\n", "\n", (string) file_get_contents( $manifest_path ) );
	qs_check( false !== strpos( $manifest, "'includes/class-quote-store.php'" ) && false !== strpos( $manifest, "'tests/quote-store-test.php'" ), 'the class and this gate are on the publish list' );
} else {
	echo "  ..    the publish list is checked in the working repository (skipped: the published tree has no build manifest)\n";
}

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['qs_checks'], $GLOBALS['qs_failures'] );
exit( $GLOBALS['qs_failures'] > 0 ? 1 : 0 );

