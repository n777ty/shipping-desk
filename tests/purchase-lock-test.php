<?php
/**
 * Gate for the per-order purchase lock.
 *
 * Buying a label spends money and none of the providers takes an idempotency key, so two
 * simultaneous requests for one order must not both reach the provider. This gate pins what the
 * lock promises:
 *
 *   1. one winner per order, and orders do not block each other
 *   2. only the owner releases, so an expired lock that was taken over is not deleted by its
 *      old owner
 *   3. a lock nobody released is abandoned after the TTL, never before, and two takers cannot
 *      both win it
 *   4. the SQL that makes the claim atomic is the SQL that ships
 *   5. both buy handlers take it before they load the order, and the fatal-error guard releases
 *      it when a label exists and keeps it when the outcome is unknown
 *
 * It needs no WordPress. The four database methods are replaced by an in-memory store whose
 * rows are shared by every instance, which is what two requests sharing one database look like.
 *
 * Run:  php tests/purchase-lock-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['pl_checks']   = 0;
$GLOBALS['pl_failures'] = 0;

function pl_check( $condition, $label, $detail = '' ) {
	$GLOBALS['pl_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['pl_failures']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
}

function pl_section( $title ) {
	echo "\n[{$title}]\n";
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-purchase-lock.php';

/** The four database methods, in memory. Rows are static: two instances are two requests. */
class Pl_Fake_Lock extends Shipping_Desk_Purchase_Lock {
	public static $rows  = array();
	public static $time  = 1000000;
	public static $stale = null; // When set, the next read() returns this instead of the row.

	public static function reset() {
		self::$rows  = array();
		self::$time  = 1000000;
		self::$stale = null;
	}

	protected function now() {
		return self::$time;
	}

	protected function insert_if_absent( $name, $value ) {
		if ( isset( self::$rows[ $name ] ) ) {
			return false;
		}
		self::$rows[ $name ] = $value;
		return true;
	}

	protected function read( $name ) {
		if ( null !== self::$stale ) {
			$value       = self::$stale;
			self::$stale = null;
			return $value;
		}
		return isset( self::$rows[ $name ] ) ? self::$rows[ $name ] : null;
	}

	protected function swap( $name, $old, $new ) {
		if ( ! isset( self::$rows[ $name ] ) || self::$rows[ $name ] !== $old ) {
			return false;
		}
		self::$rows[ $name ] = $new;
		return true;
	}

	protected function delete_if( $name, $value ) {
		if ( ! isset( self::$rows[ $name ] ) || self::$rows[ $name ] !== $value ) {
			return false;
		}
		unset( self::$rows[ $name ] );
		return true;
	}
}

echo "Shipping Desk purchase lock checks\n";
echo "==================================\n";

/* -- One winner ------------------------------------------------------------- */

pl_section( 'one winner per order' );
Pl_Fake_Lock::reset();
$a = new Pl_Fake_Lock();
$b = new Pl_Fake_Lock();

pl_check( true === $a->acquire( 101 ), 'the first request takes the lock' );
pl_check( false === $b->acquire( 101 ), 'a second request for the same order is refused' );
pl_check( true === $a->acquire( 101 ), 'the owner asking again is not locked out of its own purchase' );
pl_check( true === $b->acquire( 202 ), 'a different order is not blocked' );
pl_check( $a->is_held( 101 ) && ! $b->is_held( 101 ), 'is_held tells the owner from the loser' );
pl_check( 2 === count( Pl_Fake_Lock::$rows ), 'one row per order, not one per request' );

foreach ( array( 0, -0, '', 'abc', null ) as $bad ) {
	pl_check( false === ( new Pl_Fake_Lock() )->acquire( $bad ), 'an order id of ' . var_export( $bad, true ) . ' never takes a lock' );
}
pl_check( 2 === count( Pl_Fake_Lock::$rows ), 'and none of them wrote a row' );

/* -- Release ---------------------------------------------------------------- */

pl_section( 'release' );
$a->release( 101 );
pl_check( ! $a->is_held( 101 ), 'the owner lets go' );
pl_check( true === $b->acquire( 101 ), 'and the next request can buy' );

Pl_Fake_Lock::reset();
$a = new Pl_Fake_Lock();
$a->acquire( 1 );
$a->acquire( 2 );
$a->acquire( 3 );
$a->release_all();
pl_check( array() === Pl_Fake_Lock::$rows, 'release_all frees every order of a batch' );
$a->release_all();
pl_check( true, 'and may be called again (the guard and the shutdown function both call it)' );

Pl_Fake_Lock::reset();
$a = new Pl_Fake_Lock();
$a->acquire( 7 );
$a->keep_all();
$a->release_all();
pl_check( 1 === count( Pl_Fake_Lock::$rows ), 'keep_all leaves the lock in place: an unknown outcome must keep blocking' );
pl_check( false === ( new Pl_Fake_Lock() )->acquire( 7 ), 'and a second click stays refused' );

/* -- Only the owner releases ------------------------------------------------- */

pl_section( 'only the owner releases' );
Pl_Fake_Lock::reset();
$old = new Pl_Fake_Lock();
$new = new Pl_Fake_Lock();
$old->acquire( 9 );
Pl_Fake_Lock::$time += Shipping_Desk_Purchase_Lock::TTL;
pl_check( true === $new->acquire( 9 ), 'a lock that outlived the TTL is taken over' );
$old->release( 9 );
pl_check( 1 === count( Pl_Fake_Lock::$rows ), 'the old owner cannot delete the new owner\'s lock' );
pl_check( false === ( new Pl_Fake_Lock() )->acquire( 9 ), 'so a third request is still refused' );
$new->release( 9 );
pl_check( array() === Pl_Fake_Lock::$rows, 'and the new owner can' );

/* -- Expiry ----------------------------------------------------------------- */

pl_section( 'a lock nobody released' );
Pl_Fake_Lock::reset();
$a = new Pl_Fake_Lock();
$a->acquire( 50 );

Pl_Fake_Lock::$time += Shipping_Desk_Purchase_Lock::TTL - 1;
pl_check( false === ( new Pl_Fake_Lock() )->acquire( 50 ), 'one second before the TTL it still blocks' );
Pl_Fake_Lock::$time += 1;
pl_check( true === ( new Pl_Fake_Lock() )->acquire( 50 ), 'at the TTL it is abandoned and can be taken over' );

pl_check(
	Shipping_Desk_Purchase_Lock::TTL > 180,
	'the TTL is longer than the 180 seconds a purchase asks PHP for, so a slow purchase is never taken over',
	'TTL is ' . Shipping_Desk_Purchase_Lock::TTL
);

Pl_Fake_Lock::reset();
Pl_Fake_Lock::$rows['shipping_desk_buy_lock_60'] = 'garbage';
pl_check( true === ( new Pl_Fake_Lock() )->acquire( 60 ), 'a value nobody can date counts as abandoned, not as blocking the order forever' );
Pl_Fake_Lock::reset();
Pl_Fake_Lock::$rows['shipping_desk_buy_lock_61'] = '0:abc';
pl_check( true === ( new Pl_Fake_Lock() )->acquire( 61 ), 'and so does a timestamp of zero' );

pl_section( 'two takers, one lock' );
Pl_Fake_Lock::reset();
$dead = new Pl_Fake_Lock();
$dead->acquire( 70 );
Pl_Fake_Lock::$time += Shipping_Desk_Purchase_Lock::TTL;
$taker_one = new Pl_Fake_Lock();
$taker_two = new Pl_Fake_Lock();
pl_check( true === $taker_one->acquire( 70 ), 'the first taker wins the abandoned lock' );
pl_check( false === $taker_two->acquire( 70 ), 'the second sees a fresh lock and is refused' );

// The race itself: the second taker read the old value before the first swapped it.
Pl_Fake_Lock::reset();
$dead = new Pl_Fake_Lock();
$dead->acquire( 71 );
$stale_value = Pl_Fake_Lock::$rows['shipping_desk_buy_lock_71'];
Pl_Fake_Lock::$time += Shipping_Desk_Purchase_Lock::TTL;
$winner = new Pl_Fake_Lock();
$loser  = new Pl_Fake_Lock();
$winner->acquire( 71 );
Pl_Fake_Lock::$stale = $stale_value; // What the loser read, before the winner swapped.
pl_check( false === $loser->acquire( 71 ), 'a taker holding a stale read loses the compare-and-swap' );
pl_check( $winner->is_held( 71 ) && ! $loser->is_held( 71 ), 'and only one request believes it owns the order' );

pl_section( 'released between the insert and the read' );
Pl_Fake_Lock::reset();
$holder = new Pl_Fake_Lock();
$holder->acquire( 80 );
$vanish = new class() extends Pl_Fake_Lock {
	protected function read( $name ) {
		unset( Pl_Fake_Lock::$rows[ $name ] );
		return null;
	}
};
pl_check( true === $vanish->acquire( 80 ), 'a lock that was released in that gap is simply taken' );


/* -- The SQL that ships ----------------------------------------------------- */

/** PHP source with its comments removed, so a check looks at code and not at what a comment says. */
function pl_code( $source ) {
	$out = '';
	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}

pl_section( 'the claim is one atomic statement' );
$source = pl_code( str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-purchase-lock.php' ) ) );

pl_check( false !== strpos( $source, 'INSERT IGNORE INTO' ), 'the claim is INSERT IGNORE: the unique option_name key decides the winner' );
pl_check( false === strpos( $source, 'add_option(' ), 'add_option() is not called: it reads first and can let two requests both succeed' );
pl_check( false === strpos( $source, 'get_option(' ) && false === strpos( $source, 'update_option(' ), 'nothing goes through the options cache, which is per request and can be stale' );
pl_check( (bool) preg_match( "/VALUES \\( %s, %s, 'no' \\)/", $source ), 'the row is stored with autoload off, so it is not read on every page of the site' );
pl_check( (bool) preg_match( '/UPDATE `\{\$wpdb->options\}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s/', $source ), 'takeover is a compare-and-swap on the value that was read' );
pl_check( (bool) preg_match( '/DELETE FROM `\{\$wpdb->options\}` WHERE `option_name` = %s AND `option_value` = %s/', $source ), 'release deletes only a row that still holds our value' );
pl_check( 4 === preg_match_all( '/\$wpdb->prepare\(/', $source ), 'every statement is prepared: four of them, none concatenated' );
pl_check( false !== strpos( $source, "register_shutdown_function( [ \$this, 'release_all' ] )" ), 'release is registered to run however the request ends' );
pl_check( 1 === substr_count( $source, 'register_shutdown_function(' ), 'once per lock object, not once per order' );

/* -- Both buy paths take it ------------------------------------------------- */

pl_section( 'the buy handlers take it before they load the order' );
// The buy handlers and the fatal-error guard live in the labels class, which also owns the lock.
$plugin = pl_code( str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-labels.php' ) ) );
$plugin_main = pl_code( str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) ) );

/** The body of one method: from its signature to the next method at the same indent. */
function pl_method( $source, $name ) {
	$start = strpos( $source, 'function ' . $name . '(' );
	if ( false === $start ) {
		return '';
	}
	$next = preg_match( '/\n    (?:public|protected|private) function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10 ) ? $m[0][1] : strlen( $source );
	return substr( $source, $start, $next - $start );
}

$single = pl_method( $plugin, 'shipping_desk_buy_label' );
$bulk   = pl_method( $plugin, 'shipping_desk_bulk_buy' );

pl_check( '' !== $single && '' !== $bulk, 'both handlers were found' );
pl_check( 1 === substr_count( $single, 'purchase_lock()->acquire(' ), 'a single purchase takes the lock' );
pl_check( 1 === substr_count( $bulk, 'purchase_lock()->acquire(' ), 'a bulk purchase takes it too, per order' );

$lock_at   = strpos( $single, 'purchase_lock()->acquire(' );
$order_at  = strpos( $single, 'wc_get_order(' );
$remote_at = strpos( $single, "get_meta( \$m['remote_id'] )" );
pl_check( false !== $lock_at && false !== $order_at && $lock_at < $order_at, 'a single purchase takes it before loading the order, so the order is read after the winner finished' );
pl_check( false !== $remote_at && $order_at < $remote_at, 'and the "already purchased" check comes after both' );
pl_check( false !== strpos( $single, 'purchase_in_progress_message() ], 409 )' ), 'a refused purchase answers 409 with the shared message' );


$lock_at   = strpos( $bulk, 'purchase_lock()->acquire(' );
$order_at  = strpos( $bulk, 'wc_get_order( $order_id )' );
$remote_at = strpos( $bulk, "get_meta( \$m['remote_id'] )" );
pl_check( false !== $lock_at && $lock_at < $order_at && $order_at < $remote_at, 'bulk takes it, then loads the order, then checks for a label' );
pl_check( false !== strpos( $bulk, '$skipped[ $order_id ] = $this->purchase_in_progress_message();' ), 'a bulk order that is locked is skipped with the message, not bought' );
pl_check( false === strpos( $single . $bulk, 'release_all' ) && false === strpos( $single . $bulk, '->release(' ), 'the handlers never release by hand: the shutdown function does, on every path' );

$call_single = strpos( $single, '->book_shipment(' );
$call_bulk   = strpos( $bulk, '->book_shipments(' );
pl_check( false !== $call_single && strpos( $single, 'purchase_lock()->acquire(' ) < $call_single, 'the lock is taken before the provider is called (single)' );
pl_check( false !== $call_bulk && strpos( $bulk, 'purchase_lock()->acquire(' ) < $call_bulk, 'and before the provider is called (bulk)' );

// RW-7: recording the label also completes the order. It used to be wrapped in `if ( $tracking )`,
// so a label the provider returned without a tracking number was never handed to the relay and the
// order was left in Processing with its label meta (buy blocked, Refund/Reset out of reach).
pl_check( 0 === preg_match( '/if \\( \\$tracking \\) \\{\\s*\\$this->record_label_tracking\\(/', $single ), 'a single purchase records the label even when there is no tracking number' );
pl_check( false !== strpos( $single, '$this->record_label_tracking(' ), 'and it does hand the label over' );

pl_check( 0 === substr_count( $plugin_main, 'function shipping_desk_buy_label' ) && 0 === substr_count( $plugin_main, 'function shipping_desk_bulk_buy' ), 'the plugin class no longer defines the buy handlers: one definition, not two' );
pl_check( 0 === substr_count( $plugin_main, 'purchase_lock()->acquire(' ), 'and takes no lock of its own' );
pl_check( false !== strpos( $plugin, "'wp_ajax_' . \$action" ) && false !== strpos( $plugin, "'shipping_desk_buy_label', 'shipping_desk_bulk_buy'" ), 'the labels class hooks both purchase actions itself' );
pl_check( false === strpos( $plugin_main, "'shipping_desk_buy_label'" ) && false === strpos( $plugin_main, "'shipping_desk_bulk_buy', 'shipping_desk_switch_provider'" ), 'and the plugin\'s own hook list no longer names them, so neither is hooked twice' );
pl_check( 1 === substr_count( $plugin_main, '$this->labels()->init();' ), 'the plugin starts the labels class exactly once' );

pl_section( 'the fatal-error guard' );
$guard        = pl_method( $plugin, 'guard_fatal_errors' );
$committed_at = strpos( $guard, 'committed_purchase' );
$release_at   = strpos( $guard, 'purchase_lock()->release_all()' );
$success_at   = strpos( $guard, 'wp_send_json_success(' );
$keep_at      = strpos( $guard, 'purchase_lock()->keep_all()' );
$error_at     = strpos( $guard, 'wp_send_json_error(' );

pl_check( false !== $release_at && $release_at < $success_at, 'a committed purchase releases the lock BEFORE answering: answering ends the request, and the shutdown functions after it never run' );
pl_check( false !== $keep_at && $keep_at < $error_at, 'an unknown outcome keeps the lock, and says so before it answers' );
pl_check( $committed_at < $release_at && $success_at < $keep_at, 'the two cases are the two branches, in that order' );

/* -- Shipped ------------------------------------------------------------------ */

pl_section( 'it ships' );
// tools/ is internal and is never copied into the published tree, so the manifest is only
// there to read in the working repository. In the built tree the class file existing is what
// matters, and it is checked here too.
$manifest_path = dirname( __DIR__ ) . '/tools/build-public.php';
if ( is_file( $manifest_path ) ) {
	$manifest = str_replace( "\r\n", "\n", (string) file_get_contents( $manifest_path ) );
	pl_check( false !== strpos( $manifest, "'includes/class-purchase-lock.php'" ), 'the class is on the publish list' );
	pl_check( false !== strpos( $manifest, "'tests/purchase-lock-test.php'" ), 'and so is this gate' );
} else {
	echo "  ..    the publish list is checked in the working repository (skipped: the published tree has no build manifest)\n";
}
pl_check( is_file( dirname( __DIR__ ) . '/includes/class-purchase-lock.php' ), 'the class file is present in this tree' );
pl_check( false === strpos( $source, '__(' ), 'the lock has no translatable strings, so the template list needs no entry' );

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['pl_checks'], $GLOBALS['pl_failures'] );
exit( $GLOBALS['pl_failures'] > 0 ? 1 : 0 );

