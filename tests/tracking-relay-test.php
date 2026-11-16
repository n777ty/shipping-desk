<?php
/**
 * Gate for Shipping_Desk_Tracking_Relay.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress, no WooCommerce. Covers the decisions
 * that cannot be checked by eye:
 *
 *   - AST wins over Shipment Tracking, which wins over the customer note
 *   - carrier account ids map to the names those plugins resolve (`UPSDAP` -> `UPS`)
 *   - the customer note includes a link when there is one, and never a broken one
 *   - the private note carries the provider reference and no tracking number
 *   - a refunded label's number is removed from whichever plugin holds it, and only
 *     that number
 *
 * `record()` itself is not covered here: it calls the tracking plugins and needs a real
 * order. Its branches are exercised on staging by installing each plugin in turn.
 *
 * Run:  php tests/tracking-relay-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['relay_checks']   = 0;
$GLOBALS['relay_failures'] = 0;

function relay_same( $want, $got, $label ) {
	$GLOBALS['relay_checks']++;
	if ( $want === $got ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['relay_failures']++;
	echo "  FAIL  {$label} (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ")\n";
}

function relay_check( $condition, $label ) {
	$GLOBALS['relay_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['relay_failures']++;
	echo "  FAIL  {$label}\n";
}

/* -- WordPress stand-ins ---------------------------------------------------- */

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function apply_filters( $tag, $value ) {
	return $value;
}

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	return true;
}

/* -- WooCommerce and tracking plugin stand-ins ------------------------------ */

/**
 * Just enough of an order for the relay: an id and order meta.
 */
class WC_Order {
	public $meta = [];
	protected $id;

	public function __construct( $id = 0 ) {
		$this->id = (int) $id;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

/** Shipment Tracking's class, as its own admin screen would reach it. */
class WC_Shipment_Tracking_Actions {
	public static $deleted = [];
	protected static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function delete_tracking_item( $order_id, $tracking_id ) {
		self::$deleted[] = $tracking_id;
		return true;
	}
}

$GLOBALS['ast_items']    = [];
$GLOBALS['ast_deleted']  = [];
$GLOBALS['ast_inserted'] = [];

function ast_insert_tracking_number( $order_id, $tracking_number, $carrier = '', $date = null, $status = 0 ) {
	$GLOBALS['ast_inserted'][] = [ $order_id, $tracking_number, $carrier, $status ];
	return true;
}

function ast_get_tracking_items( $order_id ) {
	return $GLOBALS['ast_items'];
}

/** AST's actions class, as its own templates reach it. */
class WC_Advanced_Shipment_Tracking_Actions {
	public static function get_instance() {
		return new self();
	}

	public function delete_tracking_item( $order_id, $tracking_id ) {
		if ( ! empty( $GLOBALS['ast_delete_noop'] ) ) {
			// A build whose internals changed: reports success, changes nothing.
			return true;
		}

		$GLOBALS['ast_deleted'][] = $tracking_id;
		$GLOBALS['ast_items']     = array_values(
			array_filter(
				$GLOBALS['ast_items'],
				function ( $item ) use ( $tracking_id ) {
					return $item['tracking_id'] !== $tracking_id;
				}
			)
		);

		return true;
	}
}

require dirname( __DIR__ ) . '/includes/class-tracking-relay.php';

echo "Shipping Desk tracking relay checks\n";
echo "==================================\n";

/* -- Which handler wins ----------------------------------------------------- */

echo "\n[handler priority]\n";

relay_same( 'ast', Shipping_Desk_Tracking_Relay::detect( true, true ), 'both installed: Advanced Shipment Tracking wins' );
relay_same( 'ast', Shipping_Desk_Tracking_Relay::detect( true, false ), 'AST alone is used' );
relay_same( 'st', Shipping_Desk_Tracking_Relay::detect( false, true ), 'Shipment Tracking alone is used' );
relay_same( 'note', Shipping_Desk_Tracking_Relay::detect( false, false ), 'neither installed: the customer gets a note' );

/* -- Carrier names ---------------------------------------------------------- */

echo "\n[carrier names]\n";

relay_same( 'USPS', Shipping_Desk_Tracking_Relay::carrier_name( 'USPS' ), 'a plain USPS id is kept' );
relay_same( 'USPS', Shipping_Desk_Tracking_Relay::carrier_name( 'usps' ), 'case does not matter' );
relay_same( 'UPS', Shipping_Desk_Tracking_Relay::carrier_name( 'UPSDAP' ), 'EasyPost carrier accounts map to UPS' );
relay_same( 'UPS', Shipping_Desk_Tracking_Relay::carrier_name( 'ups' ), 'UPS stays UPS' );
relay_same( 'FedEx', Shipping_Desk_Tracking_Relay::carrier_name( 'FedexAccount' ), 'FedEx accounts map to FedEx' );
relay_same( 'DHL', Shipping_Desk_Tracking_Relay::carrier_name( 'DHLExpress' ), 'DHL variants map to DHL' );
relay_same( 'Carrier', Shipping_Desk_Tracking_Relay::carrier_name( '' ), 'an empty carrier becomes a neutral word' );
relay_same( 'OnTrac', Shipping_Desk_Tracking_Relay::carrier_name( 'OnTrac' ), 'an unknown carrier is passed through for the plugin to match' );

/* -- Provider labels -------------------------------------------------------- */

echo "\n[provider labels]\n";

relay_same( 'EasyPost', Shipping_Desk_Tracking_Relay::provider_label( 'easypost' ), 'the slug becomes a readable name' );
relay_same( 'Shippo', Shipping_Desk_Tracking_Relay::provider_label( 'shippo' ), 'and so does Shippo' );
relay_same( 'Veeqo', Shipping_Desk_Tracking_Relay::provider_label( 'veeqo' ), 'and Veeqo' );

/* -- The customer note ------------------------------------------------------ */

echo "\n[customer note]\n";

$with_link = Shipping_Desk_Tracking_Relay::public_note( '9400111899223197428490', 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223197428490' );
relay_check( false !== strpos( $with_link, '<a href="https://tools.usps.com/' ), 'with a URL, the note links to the carrier' );
relay_check( false !== strpos( $with_link, '9400111899223197428490' ), 'the number is printed' );
relay_check( false !== strpos( $with_link, 'Your order has shipped via USPS' ), 'the wording is customer-facing' );

$no_link = Shipping_Desk_Tracking_Relay::public_note( '1Z999', 'UPS', '' );
relay_check( false === strpos( $no_link, '<a ' ), 'without a URL, no link is printed' );
relay_check( false !== strpos( $no_link, '1Z999' ), 'the number is still shown' );

$hostile = Shipping_Desk_Tracking_Relay::public_note( '<script>x</script>', 'USPS', 'javascript:alert(1)' );
relay_check( false === strpos( $hostile, '<script>' ), 'the tracking number is escaped' );
relay_check( false === strpos( $hostile, 'href="javascript:' ), 'a javascript: URL is not turned into a link' );

/* -- The private note ------------------------------------------------------- */

echo "\n[private note]\n";

$private = Shipping_Desk_Tracking_Relay::private_note( 'EasyPost', 'EasyPost Shipment ID:', 'shp_abc123' );
relay_check( false !== strpos( $private, 'Label bought via EasyPost.' ), 'the provider is named' );
relay_check( false !== strpos( $private, 'shp_abc123' ), 'the reference id is kept' );

$with_extra = Shipping_Desk_Tracking_Relay::private_note( 'EasyPost', 'EasyPost Shipment ID:', 'shp_abc123', [ 'Insured for $249.99 (premium $2.50).' ] );
relay_check( false !== strpos( $with_extra, 'Insured for $249.99' ), 'extra lines such as insurance are appended' );
relay_same( 1, substr_count( $with_extra, 'shp_abc123' ), 'the reference appears once' );

$blank_reference = Shipping_Desk_Tracking_Relay::private_note( 'Veeqo', 'Veeqo Remote Shipment ID:', '' );
relay_check( false === strpos( $blank_reference, '<strong>' ), 'an empty reference is omitted rather than printed blank' );

/* -- The shipping note's email ---------------------------------------------- */

echo "\n[shipping note email]\n";

$default_subject = 'A note has been added to your order';

Shipping_Desk_Tracking_Relay::mark_shipping_note( false );
relay_same( $default_subject, Shipping_Desk_Tracking_Relay::filter_note_subject( $default_subject ), 'another customer note keeps its normal subject' );
relay_same( 'Custom heading', Shipping_Desk_Tracking_Relay::filter_note_heading( 'Custom heading' ), 'and its normal heading' );

Shipping_Desk_Tracking_Relay::mark_shipping_note( true );
relay_same( 'Your order has shipped', Shipping_Desk_Tracking_Relay::filter_note_subject( $default_subject ), 'our shipping note is renamed' );
relay_same( 'Good news: your order is on its way', Shipping_Desk_Tracking_Relay::filter_note_heading( 'Custom heading' ), 'and gets a shipping heading' );

Shipping_Desk_Tracking_Relay::mark_shipping_note( false );
relay_same( $default_subject, Shipping_Desk_Tracking_Relay::filter_note_subject( $default_subject ), 'the rename stops as soon as our note is saved' );

/* -- Removing a dead number -------------------------------------------------- */

echo "\n[removing a dead number]\n";

$order                                                = new WC_Order( 501 );
$order->meta[ Shipping_Desk_Tracking_Relay::ST_META ] = [
	[
		'tracking_id'     => 'st-1',
		'tracking_number' => '9400111899223197428490',
	],
	[
		'tracking_id'     => 'st-2',
		'tracking_number' => '1Z999',
	],
];

$GLOBALS['ast_items'] = [
	[
		'tracking_id'     => 'ast-a',
		'tracking_number' => '9400111899223197428490',
	],
	[
		'tracking_id'     => 'ast-b',
		'tracking_number' => '1Z999',
	],
];

$GLOBALS['ast_deleted'] = [];

$report = Shipping_Desk_Tracking_Relay::forget( $order, '9400111899223197428490', 'ast' );
relay_same( 1, $report['removed'], 'AST: the refunded number is removed' );
relay_same( [ 'ast-a' ], $GLOBALS['ast_deleted'], 'AST: only that item is deleted' );
relay_same( 'Advanced Shipment Tracking', $report['label'], 'AST: the report names the plugin' );
relay_same( 1, count( $GLOBALS['ast_items'] ), 'AST: the other shipment stays on the order' );

$GLOBALS['ast_deleted'] = [];
$GLOBALS['ast_items'][] = [
	'tracking_id'     => 'ast-a',
	'tracking_number' => '9400111899223197428490',
];
$report                 = Shipping_Desk_Tracking_Relay::forget( $order, ' 9400111899223197428490 ', 'ast' );
relay_same( 1, $report['removed'], 'AST: surrounding whitespace still matches' );

$report = Shipping_Desk_Tracking_Relay::forget( $order, '9400111899223197428490', 'ast' );
relay_same( 0, $report['removed'], 'AST: removing the same number twice is a no-op the second time' );

$GLOBALS['ast_deleted'] = [];
$report                 = Shipping_Desk_Tracking_Relay::forget( $order, 'PROBE0003', 'ast' );
relay_same( 0, $report['removed'], 'AST: a number that is not on the order removes nothing' );
relay_same( [], $GLOBALS['ast_deleted'], 'AST: and deletes nothing' );

$report = Shipping_Desk_Tracking_Relay::forget( $order, '', 'ast' );
relay_same( 0, $report['removed'], 'an empty number is a no-op' );

$report = Shipping_Desk_Tracking_Relay::forget( $order, '1Z999', 'st' );
relay_same( 1, $report['removed'], 'ST: the refunded number is removed' );
relay_same( [ 'st-2' ], WC_Shipment_Tracking_Actions::$deleted, 'ST: through their own delete method' );
relay_same( 'WooCommerce Shipment Tracking', $report['label'], 'ST: the report names the plugin' );
relay_same( 0, Shipping_Desk_Tracking_Relay::forget( $order, 'PROBE0003', 'st' )['removed'], 'ST: a number that is not on the order removes nothing' );

$report = Shipping_Desk_Tracking_Relay::forget( $order, '1Z999', 'note' );
relay_same( 0, $report['removed'], 'no tracking plugin: nothing to remove, the note cannot be edited' );
relay_same( '', $report['label'], 'and no plugin is named' );

// A delete that claims success but leaves the item is reported honestly, because the
// number is read back through AST's own getter.
$GLOBALS['ast_delete_noop'] = true;
$report                     = Shipping_Desk_Tracking_Relay::forget( $order, '1Z999', 'ast' );
relay_same( 0, $report['removed'], 'AST: a delete that does not take effect is reported as nothing removed' );
relay_same( 1, count( $GLOBALS['ast_items'] ), 'AST: and the order is left as it was' );
$GLOBALS['ast_delete_noop'] = false;

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['relay_checks'], $GLOBALS['relay_failures'] );
exit( $GLOBALS['relay_failures'] > 0 ? 1 : 0 );


exit( $GLOBALS['relay_failures'] > 0 ? 1 : 0 );
