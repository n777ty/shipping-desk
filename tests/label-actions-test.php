<?php
/**
 * Gate for label actions: print, refund and reset, and the lookups they share.
 *
 * Nothing exercised this code before it moved out of the plugin class, and it decides which
 * provider is asked to void a label and whether the request is allowed at all. So this runs the
 * real methods against stubs:
 *
 *   1. which shipment a request is about, and what stops it: the nonce, the order, the label id
 *   2. which provider owns it: an order with no marker is Veeqo's, an incomplete mode marker
 *      falls back to the active mode rather than guessing production
 *   3. which client and reference to use, and the errors when a key is missing
 *   4. the URL to print: the provider's fresh one, then the one saved at purchase, but never a
 *      saved one that would hide a broken key
 *   5. refund and reset: what is written to the order, and what is removed from it
 *   6. the wiring: five actions hooked once, in the class and not in the plugin
 *
 * No WordPress needed. wp_send_json_*() throws here instead of ending the request, which is
 * what lets a check see what the handler said.
 *
 * Run:  php tests/label-actions-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['la_checks']   = 0;
$GLOBALS['la_failures'] = 0;
$GLOBALS['la_nonces']   = array();
$GLOBALS['la_orders']   = array();
$GLOBALS['la_actions']  = array();
$GLOBALS['la_filters']  = array();

function la_check( $condition, $label, $detail = '' ) {
	$GLOBALS['la_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['la_failures']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
}

class La_Json_Exit extends Exception {
	public $ok;
	public $data;
	public $status;
}

function la_json( $ok, $data, $status ) {
	$e         = new La_Json_Exit( $ok ? 'success' : 'error' );
	$e->ok     = $ok;
	$e->data   = $data;
	$e->status = $status;
	throw $e;
}

function wp_send_json_error( $data = null, $status = 200 ) { la_json( false, $data, $status ); }
function wp_send_json_success( $data = null ) { la_json( true, $data, 200 ); }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function __( $text, $domain = '' ) { return $text; }
function add_action( $hook, $callback ) { $GLOBALS['la_actions'][] = $hook; }
function wp_verify_nonce( $nonce, $action ) { return isset( $GLOBALS['la_nonces'][ $action ] ) && $GLOBALS['la_nonces'][ $action ] === $nonce; }
function wc_get_order( $id ) { return isset( $GLOBALS['la_orders'][ $id ] ) ? $GLOBALS['la_orders'][ $id ] : false; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['la_filters'][ $tag ] ) ? $GLOBALS['la_filters'][ $tag ] : $value; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class WC_Order {}

class La_Order extends WC_Order {
	public $id;
	public $meta  = array();
	public $notes = array();
	public $saves = 0;
	public function __construct( $id, array $meta = array() ) { $this->id = $id; $this->meta = $meta; }
	public function get_id() { return $this->id; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function save() { $this->saves++; }
}

require_once dirname( __DIR__ ) . '/includes/helpers.php';
$GLOBALS['la_m'] = Shipping_Desk_Helpers::meta_keys();

class La_Client {
	public $url    = 'https://labels.example/fresh.pdf';
	public $refund = true;
	public $asked  = array();
	public function get_label_url( $ref ) { $this->asked[] = $ref; return $this->url; }
	public function refund_shipment( $ref ) { $this->asked[] = 'refund:' . $ref; return $this->refund; }
}

class La_Labels {
	public $dead_tracking = array();
	public $cost_cleared  = 0;
	public function remove_dead_tracking( $order, $number ) { $this->dead_tracking[] = $number; }
	public function clear_shipping_cost( $order ) { $this->cost_cleared++; }
}

class La_Quotes {
	public $address_cleared = array();
	public $quotes_deleted  = array();
	public function clear_address_validation_cache( $id ) { $this->address_cleared[] = $id; }
	public function delete_quote_record( $id ) { $this->quotes_deleted[] = $id; }
}

/** Just enough of the plugin: the answers the actions ask it for. */
class Shipping_Desk_Plugin {
	public $mode   = 'test';
	public $keys   = array( 'shippo' => array( 'test' => 'st', 'live' => 'sl' ), 'easypost' => array( 'test' => 'et', 'live' => 'el' ) );
	public $client;
	public $labels;
	public $quotes;
	public $api_calls = array();
	public function __construct() { $this->client = new La_Client(); $this->labels = new La_Labels(); $this->quotes = new La_Quotes(); }
	public function normalize_provider( $p, $fallback = 'veeqo' ) { return in_array( $p, array( 'veeqo', 'shippo', 'easypost' ), true ) ? $p : $fallback; }
	public function provider_mode( $p ) { return $this->mode; }
	public function shippo_api_key( $mode ) { return isset( $this->keys['shippo'][ $mode ] ) ? $this->keys['shippo'][ $mode ] : ''; }
	public function easypost_api_key( $mode ) { return isset( $this->keys['easypost'][ $mode ] ) ? $this->keys['easypost'][ $mode ] : ''; }
	public function api( $p = null, $mode = null ) { $this->api_calls[] = array( $p, $mode ); return $this->client; }
	public function require_cap() {}
	public function authorized_order( $order_id, $nonce_action ) {
		$this->require_cap();
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'shipping-desk' ) ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'shipping-desk' ) ), 404 );
		}
		return $order;
	}
	public function labels() { return $this->labels; }
	public function quote_store() { return $this->quotes; }
}

require_once dirname( __DIR__ ) . '/includes/class-label-actions.php';

function la_run( callable $fn ) {
	try {
		$fn();
	} catch ( La_Json_Exit $e ) {
		return $e;
	}
	return null;
}

function la_call( $object, $method, array $args = array() ) {
	return ( new ReflectionMethod( $object, $method ) )->invokeArgs( $object, $args );
}

function la_reset( array $request = array() ) {
	$GLOBALS['la_nonces'] = array();
	$GLOBALS['la_orders'] = array();
	$GLOBALS['la_filters'] = array();
	$_REQUEST             = $request;
	$plugin               = new Shipping_Desk_Plugin();
	return array( $plugin, new Shipping_Desk_Label_Actions( $plugin ) );
}

$m = $GLOBALS['la_m'];

echo "Shipping Desk label action checks\n";
echo "=================================\n";

/* -- Which shipment is this about? ------------------------------------------- */

echo "\n[which shipment, and what stops the request]\n";
list( $plugin, $actions ) = la_reset( array( 'order_id' => '41', '_wpnonce' => 'good' ) );
$GLOBALS['la_nonces']['shipping_desk_order_41'] = 'good';
$GLOBALS['la_orders'][41] = new La_Order( 41, array( $m['remote_id'] => 'rem-1', $m['label_id'] => 'lbl-1', $m['provider'] => 'shippo', $m['provider_mode'] => 'live' ) );

$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( 'shippo' === $ctx['provider'] && 'live' === $ctx['provider_mode'], 'an order carries its own provider and the mode it was bought in' );
la_check( 'lbl-1' === $ctx['label_reference'], 'Shippo and EasyPost are addressed by the label id' );
la_check( 41 === $ctx['order']->get_id(), 'and the order comes back with it' );

$GLOBALS['la_orders'][41]->meta[ $m['provider'] ] = 'veeqo';
$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( 'veeqo' === $ctx['provider'] && '' === $ctx['provider_mode'] && 'rem-1' === $ctx['label_reference'], 'Veeqo is addressed by the remote shipment id and has no mode' );

$GLOBALS['la_orders'][41]->meta[ $m['provider'] ] = '';
$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( 'veeqo' === $ctx['provider'], 'an order shipped before markers existed is Veeqo\'s by definition' );

$GLOBALS['la_orders'][41]->meta[ $m['provider'] ]      = 'easypost';
$GLOBALS['la_orders'][41]->meta[ $m['provider_mode'] ] = 'half-written';
$plugin->mode = 'test';
$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( 'test' === $ctx['provider_mode'], 'an incomplete mode marker falls back to the active mode, never to guessing production' );

echo "\n[and what refuses it]\n";
$_REQUEST['_wpnonce'] = 'forged';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && ! $e->ok && 400 === $e->status, 'a wrong nonce is refused with 400' );

$_REQUEST = array( 'order_id' => '99', '_wpnonce' => 'good' );
$GLOBALS['la_nonces']['shipping_desk_order_99'] = 'good';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && 404 === $e->status, 'an order that does not exist is a 404' );

$_REQUEST = array( 'order_id' => '41', '_wpnonce' => 'good' );
$GLOBALS['la_orders'][41]->meta[ $m['remote_id'] ] = '';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && 400 === $e->status, 'an order with no shipment id is refused: there is nothing to act on' );

$_REQUEST = array( 'order_id' => '41', '_wpnonce' => 'good' );
$GLOBALS['la_nonces']['shipping_desk_order_41'] = 'for-another-order';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && 400 === $e->status, 'a nonce is only good for the order it was made for' );


// The lookup nonce names its shipment: a nonce minted for one id cannot act on another,
// and a lookup that names a shipment with no order here is refused.
list( $plugin, $actions ) = la_reset( array( 'remote_id' => 'rem-9', 'order_id' => '41', '_wpnonce' => 'good' ) );
$GLOBALS['la_nonces']['shipping_desk_lookup_rem-9'] = 'good';
$GLOBALS['la_orders'][41] = new La_Order( 41, array( $m['remote_id'] => 'rem-9', $m['provider'] => 'shippo' ) );
$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( 41 === $ctx['order']->get_id() && 'rem-9' === $ctx['remote_id'], 'a lookup for an id this store owns still comes back with its order' );

$GLOBALS['la_nonces']['shipping_desk_lookup_rem-9'] = 'for-another-shipment';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && ! $e->ok && 400 === $e->status && 'Invalid nonce.' === $e->data['message'], 'a lookup nonce is only good for the shipment id it names' );

list( $plugin, $actions ) = la_reset( array( 'remote_id' => 'rem-foreign', '_wpnonce' => 'good' ) );
$GLOBALS['la_nonces']['shipping_desk_lookup_rem-foreign'] = 'good';
$e = la_run( function () use ( $actions ) { la_call( $actions, 'get_shipment_action_context' ); } );
la_check( $e && ! $e->ok && 403 === $e->status && false !== strpos( (string) $e->data['message'], 'not an order in this store' ), 'a shipment with no order in this store is refused with 403' );

$GLOBALS['la_filters']['shipping_desk_lookup_allow_foreign_shipment'] = true;
$ctx = la_call( $actions, 'get_shipment_action_context' );
la_check( null === $ctx['order'] && 'rem-foreign' === $ctx['remote_id'], 'unless the store opts out with the filter, and then there is no order to act on' );
$GLOBALS['la_filters'] = array();

/* -- Which client, and what if the key is missing ------------------------------ */

echo "\n[which client answers for a label]\n";
list( $plugin, $actions ) = la_reset();
$ctx = array( 'order' => null, 'remote_id' => 'r', 'provider' => 'shippo', 'provider_mode' => 'live', 'label_reference' => 'lbl-9' );
$target = la_call( $actions, 'label_client', array( $ctx ) );
la_check( is_array( $target ) && 'lbl-9' === $target['reference'] && $plugin->client === $target['client'], 'a label is handled by the client that bought it, with its own reference' );
la_check( array( array( 'shippo', 'live' ) ) === $plugin->api_calls, 'asked for in the mode it was bought in, not the active one' );

$plugin->keys['shippo']['live'] = '';
$err = la_call( $actions, 'label_client', array( $ctx ) );
la_check( is_wp_error( $err ) && 'shippo_key_missing' === $err->get_error_code(), 'a Shippo label with no key for its mode says so' );
la_check( false !== strpos( $err->get_error_message(), 'Production' ), 'and names the mode that is missing' );

$ctx['provider']      = 'easypost';
$ctx['provider_mode'] = 'test';
$plugin->keys['easypost']['test'] = '';
$err = la_call( $actions, 'label_client', array( $ctx ) );
la_check( is_wp_error( $err ) && 'easypost_key_missing' === $err->get_error_code() && false !== strpos( $err->get_error_message(), 'Test' ), 'and so does an EasyPost one' );

$ctx['provider']      = 'veeqo';
$ctx['provider_mode'] = '';
$ok = la_call( $actions, 'label_client', array( $ctx ) );
la_check( is_array( $ok ), 'Veeqo has no per-mode key to be missing' );
$ctx['label_reference'] = '';
$err = la_call( $actions, 'label_client', array( $ctx ) );
la_check( is_wp_error( $err ) && 'label_reference_missing' === $err->get_error_code(), 'an order with no stored label id is an error, not a call with an empty id' );

/* -- The URL to print ---------------------------------------------------------- */

echo "\n[the URL to print]\n";
list( $plugin, $actions ) = la_reset();
$order  = new La_Order( 5, array( $m['label_url'] => 'https://labels.example/saved.pdf' ) );
$target = array( 'client' => $plugin->client, 'reference' => 'x', 'order' => $order );
la_check( 'https://labels.example/fresh.pdf' === la_call( $actions, 'printable_label_url', array( $target ) ), 'the provider\'s fresh URL is used when it has one' );

$plugin->client->url = new WP_Error( 'easypost_label_missing', 'not built yet' );
la_check( 'https://labels.example/saved.pdf' === la_call( $actions, 'printable_label_url', array( $target ) ), 'while the file is still being built, the URL saved at purchase is used' );
$plugin->client->url = new WP_Error( 'shippo_no_label', 'none' );
la_check( 'https://labels.example/saved.pdf' === la_call( $actions, 'printable_label_url', array( $target ) ), 'and so for Shippo\'s equivalent' );

$plugin->client->url = new WP_Error( 'easypost_auth', 'bad key' );
$got = la_call( $actions, 'printable_label_url', array( $target ) );
la_check( is_wp_error( $got ) && 'easypost_auth' === $got->get_error_code(), 'any other error passes through: a saved URL must not hide a broken key' );

$plugin->client->url = '';
la_check( 'https://labels.example/saved.pdf' === la_call( $actions, 'printable_label_url', array( $target ) ), 'an empty answer falls back too' );
$target['order'] = new La_Order( 6 );
la_check( '' === la_call( $actions, 'printable_label_url', array( $target ) ), 'and with nothing saved, the empty answer is returned as it is' );


/* -- Refund ----------------------------------------------------------------------- */

echo "\n[refund]\n";
function la_refund_setup( $provider, $refund ) {
	global $m;
	list( $plugin, $actions ) = la_reset( array( 'order_id' => '70', '_wpnonce' => 'n' ) );
	$GLOBALS['la_nonces']['shipping_desk_order_70'] = 'n';
	$order = new La_Order( 70, array( $m['remote_id'] => 'rem', $m['label_id'] => 'lbl', $m['provider'] => $provider, $m['provider_mode'] => 'test', $m['tracking'] => 'TRK123' ) );
	$GLOBALS['la_orders'][70] = $order;
	$plugin->client->refund   = $refund;
	return array( $plugin, $actions, $order );
}

list( $plugin, $actions, $order ) = la_refund_setup( 'shippo', true );
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_refund_label(); } );
la_check( $e && $e->ok && array( 'refunded' => true ) === $e->data, 'a refund that the provider accepts answers success' );
la_check( 1 === $order->meta[ $m['refunded'] ], 'and marks the order refunded, so the button stops offering it' );
la_check( 1 === $plugin->labels->cost_cleared, 'the recorded shipping cost is cleared: the carrier gave the money back' );
la_check( array( 'TRK123' ) === $plugin->labels->dead_tracking, 'and the dead tracking number comes off the order' );
la_check( 1 === count( $order->notes ) && false !== strpos( $order->notes[0], 'Shippo' ), 'with a note that names the provider' );
la_check( in_array( 'refund:lbl', $plugin->client->asked, true ), 'the provider is asked about the label id, not the order id' );

foreach ( array( 'easypost' => 'EasyPost', 'veeqo' => 'Veeqo' ) as $slug => $name ) {
	list( $plugin, $actions, $order ) = la_refund_setup( $slug, true );
	la_run( function () use ( $actions ) { $actions->shipping_desk_refund_label(); } );
	la_check( false !== strpos( $order->notes[0], $name ), "the note names {$name} when it is {$name}'s label" );
}

echo "\n[a refund the provider refuses]\n";
list( $plugin, $actions, $order ) = la_refund_setup( 'shippo', new WP_Error( 'shippo_already_refunded', 'Already refunded.' ) );
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_refund_label(); } );
la_check( $e && ! $e->ok && 500 === $e->status, 'an error from the provider is reported as one' );
la_check( 1 === $order->meta[ $m['refunded'] ], 'but "already refunded" is recorded: the provider knows something the order does not' );
la_check( array( 'TRK123' ) === $plugin->labels->dead_tracking, 'and the number of a label that is already void comes off too' );
la_check( 0 === $plugin->labels->cost_cleared && array() === $order->notes, 'while the cost and the timeline are left alone, because nothing was refunded now' );

list( $plugin, $actions, $order ) = la_refund_setup( 'easypost', new WP_Error( 'easypost_refund_pending', 'Pending.' ) );
la_run( function () use ( $actions ) { $actions->shipping_desk_refund_label(); } );
la_check( 1 === $order->meta[ $m['refunded'] ], 'a refund that is already pending is recorded too' );

list( $plugin, $actions, $order ) = la_refund_setup( 'shippo', new WP_Error( 'shippo_network', 'Timed out.' ) );
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_refund_label(); } );
la_check( $e && 500 === $e->status && ! isset( $order->meta[ $m['refunded'] ] ), 'any other failure leaves the order unrefunded, so it can be tried again' );
la_check( array() === $plugin->labels->dead_tracking, 'and removes no tracking' );


/* -- Reset ------------------------------------------------------------------------- */

echo "\n[reset]\n";
function la_reset_setup( $refunded ) {
	global $m;
	list( $plugin, $actions ) = la_reset( array() );
	$_POST = array( 'order_id' => '80', '_wpnonce' => 'n' );
	$GLOBALS['la_nonces']['shipping_desk_order_80'] = 'n';
	$meta = array( $m['remote_id'] => 'rem', $m['tracking'] => 'TRK80' );
	if ( $refunded ) {
		$meta[ $m['refunded'] ] = 1;
	}
	$order = new La_Order( 80, $meta );
	$GLOBALS['la_orders'][80] = $order;
	return array( $plugin, $actions, $order );
}

list( $plugin, $actions, $order ) = la_reset_setup( false );
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_reset_shipping(); } );
la_check( $e && $e->ok && array( 'reset' => true ) === $e->data, 'a reset answers success' );
la_check( array() === $order->meta, 'and clears the shipping meta, so a new label can be bought' );
la_check( array( 80 ) === $plugin->quotes->address_cleared && array( 80 ) === $plugin->quotes->quotes_deleted, 'and the order\'s remembered address check and stored quotes go with it' );
la_check( array() === $plugin->labels->dead_tracking, 'a live label\'s tracking is left alone: that parcel may still be moving' );

list( $plugin, $actions, $order ) = la_reset_setup( true );
la_run( function () use ( $actions ) { $actions->shipping_desk_reset_shipping(); } );
la_check( array( 'TRK80' ) === $plugin->labels->dead_tracking, 'but a refunded label\'s number is removed, read before the meta was cleared' );

list( $plugin, $actions, $order ) = la_reset_setup( false );
$_POST['_wpnonce'] = 'forged';
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_reset_shipping(); } );
la_check( $e && 403 === $e->status && array() !== $order->meta, 'a reset with a wrong nonce changes nothing' );
$_POST = array( 'order_id' => '81', '_wpnonce' => 'n' );
$GLOBALS['la_nonces']['shipping_desk_order_81'] = 'n';
$e = la_run( function () use ( $actions ) { $actions->shipping_desk_reset_shipping(); } );
la_check( $e && 404 === $e->status, 'and one for an order that does not exist is a 404' );

/* -- Wiring ------------------------------------------------------------------------- */

echo "\n[the five actions are hooked once, here]\n";
$GLOBALS['la_actions'] = array();
list( $plugin, $actions ) = la_reset();
$actions->init();
$hooked = $GLOBALS['la_actions'];
sort( $hooked );
$expected = array(
	'wp_ajax_shipping_desk_print_label', 'wp_ajax_shipping_desk_print_pdf', 'wp_ajax_shipping_desk_printnode_print_label',
	'wp_ajax_shipping_desk_refund_label', 'wp_ajax_shipping_desk_reset_shipping',
);
sort( $expected );
la_check( $expected === $hooked, 'init() hooks exactly print, print PDF, PrintNode print, refund and reset' );

// The plugin for the hook list; the orders screen for the Completed row, which is where the
// refunded-label wording and the Reship button live.
$plugin_only = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) );
$plugin_src  = $plugin_only . "\n" . str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-orders-screen.php' ) );
$actions_src = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-label-actions.php' ) );
foreach ( array( 'shipping_desk_print_label', 'shipping_desk_print_pdf', 'shipping_desk_refund_label', 'shipping_desk_reset_shipping', 'shipping_desk_printnode_print_label' ) as $action ) {
	la_check( 0 === substr_count( $plugin_only, "function {$action}(" ) && 1 === substr_count( $actions_src, "function {$action}(" ), "{$action} is defined once, in the actions class" );
	la_check( 0 === substr_count( $plugin_only, "'{$action}'" ), "and the plugin's own hook list no longer names it, so it is not hooked twice" );
}
la_check( 1 === substr_count( $plugin_only, '$this->label_actions()->init();' ), 'the plugin starts the class exactly once' );
la_check( 0 === preg_match( '/function (get_shipment_action_context|label_client|printable_label_url)\(/', $plugin_only ), 'and the three shared lookups live only in the class' );

echo "\n[the guards stay in front of the work]\n";
$print_pdf = substr( $actions_src, strpos( $actions_src, 'function shipping_desk_print_pdf(' ) );
$print_pdf = substr( $print_pdf, 0, strpos( $print_pdf, "\n    }\n" ) );
$cap_at = strpos( $print_pdf, "if ( ! current_user_can( 'manage_woocommerce' ) ) {" );
$ctx_at = strpos( $print_pdf, 'get_shipment_action_context()' );
la_check( false !== $cap_at && false !== $ctx_at && $cap_at < $ctx_at, 'the PDF endpoint checks the capability before it reads the request' );
la_check( false !== strpos( $print_pdf, "'response' => 403" ), 'and refuses with a 403' );
foreach ( array( 'shipping_desk_print_label', 'shipping_desk_refund_label', 'shipping_desk_printnode_print_label' ) as $action ) {
	$body = substr( $actions_src, strpos( $actions_src, "function {$action}(" ) );
	$body = substr( $body, 0, strpos( $body, "\n    }\n" ) );
	$inside = ltrim( substr( $body, (int) strpos( $body, '{' ) + 1 ) );
	la_check( false !== strpos( $body, '{' ) && 0 === strpos( $inside, '$this->plugin->require_cap();' ), "{$action} checks the capability first" );
}

echo "\n[after a refund the screen says what to do next]\n";
// A refunded label cannot be replaced until the order is reset, and nothing else on the row says
// so: Print and Refund disappear and Reset Shipping is the only button left. The sentence is on
// the server-rendered row (what a reload shows) and in the script (what the click shows).
$next_step = 'To buy a replacement, click Reset Shipping, then Reship.';
$js_src    = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' ) );
la_check( 1 === substr_count( $plugin_src, "'Label refunded. {$next_step}'" ), 'the Completed row says what to do next, from the server, so it survives a reload' );
la_check( 1 === substr_count( $js_src, "text: 'Label refunded. {$next_step}'" ), 'the script puts the same sentence on the row at the moment of refund' );
la_check( 1 === substr_count( $js_src, "alert('Label refunded. {$next_step}')" ), 'and in the confirmation' );
$row_at    = strpos( $plugin_src, "'Label refunded. {$next_step}'" );
$print_at  = strpos( $plugin_src, "<?php if ( \$has_label && ! \$refunded ) : ?>" );
la_check( false !== $row_at && false !== $print_at && $row_at < $print_at, 'it is shown when the order is refunded, above the buttons, not inside the branch that hides them' );
la_check( false !== strpos( substr( $plugin_src, max( 0, $row_at - 400 ), 400 ), '<?php if ( $refunded ) : ?>' ), 'and only for a refunded order' );
la_check( 0 === substr_count( $plugin_src, 'class="shipping-desk-reship"' ) - 1, 'Reship is still one button, still hidden while shipping data exists: this is wording, not a new way to buy' );

$manifest_path = dirname( __DIR__ ) . '/tools/build-public.php';
if ( is_file( $manifest_path ) ) {
	$manifest = str_replace( "\r\n", "\n", (string) file_get_contents( $manifest_path ) );
	la_check( false !== strpos( $manifest, "'includes/class-label-actions.php'" ) && false !== strpos( $manifest, "'tests/label-actions-test.php'" ), 'the class and this gate are on the publish list' );
} else {
	echo "  ..    the publish list is checked in the working repository (skipped: the published tree has no build manifest)\n";
}

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['la_checks'], $GLOBALS['la_failures'] );
exit( $GLOBALS['la_failures'] > 0 ? 1 : 0 );

