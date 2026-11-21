<?php
/**
 * Gate for the orders screen's wiring.
 *
 * The markup of the orders screen is checked by the style gates (buttons, inputs, selects, the
 * header, the rate column), which read its source. What none of them can see is how the screen is
 * reached: the menu entry that opens it, the AJAX action it answers, and the two plugin methods
 * it calls back into. When the class moved out of the plugin those were the things that changed,
 * so this pins them:
 *
 *   1. the menu opens the screen's own page method, on the class, not on the plugin
 *   2. the one AJAX action it answers is hooked by the class and only by the class
 *   3. the screen is started once, and the plugin no longer defines any of its methods
 *   4. what the screen asks of the plugin is public, and is still defined once
 *   5. it ships
 *
 * No WordPress needed: the hooks are recorded by a stub and the class is loaded for real.
 *
 * Run:  php tests/orders-screen-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['os_checks']   = 0;
$GLOBALS['os_failures'] = 0;
$GLOBALS['os_hooks']    = array();

function os_check( $condition, $label, $detail = '' ) {
	$GLOBALS['os_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['os_failures']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
}

function add_action( $hook, $callback ) { $GLOBALS['os_hooks'][] = $hook; }

class Shipping_Desk_Plugin {}

require_once dirname( __DIR__ ) . '/includes/class-orders-screen.php';

$read   = function ( $file ) { return str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/' . $file ) ); };
$plugin = $read( 'class-plugin.php' );
$screen = $read( 'class-orders-screen.php' );

echo "Shipping Desk orders screen checks\n";
echo "==================================\n";

echo "\n[the menu opens the screen]\n";
os_check( 1 === substr_count( $plugin, "[ \$this->orders_screen(), 'render_orders_page' ]" ), 'the top-level menu entry calls the screen\'s page method, through the accessor' );
os_check( 0 === substr_count( $plugin, "[ \$this, 'render_orders_page' ]" ), 'and nothing points the menu at a plugin method that no longer exists' );
os_check( ( new ReflectionMethod( 'Shipping_Desk_Orders_Screen', 'render_orders_page' ) )->isPublic(), 'the page method is public, because WordPress calls it from outside the class' );

echo "\n[the AJAX action is hooked once, by the class]\n";
$GLOBALS['os_hooks'] = array();
( new Shipping_Desk_Orders_Screen( new Shipping_Desk_Plugin() ) )->init();
os_check( array( 'wp_ajax_shipping_desk_render_purchase_row' ) === $GLOBALS['os_hooks'], 'init() hooks exactly the purchase-row action' );
os_check( 0 === substr_count( $plugin, "'shipping_desk_render_purchase_row'" ), 'and the plugin\'s own hook list no longer names it, so it is not hooked twice' );
os_check( 1 === substr_count( $screen, 'function shipping_desk_render_purchase_row(' ) && 0 === substr_count( $plugin, 'function shipping_desk_render_purchase_row(' ), 'its handler is defined once, in the class' );
os_check( 1 === substr_count( $plugin, '$this->orders_screen()->init();' ), 'the plugin starts the screen exactly once' );

echo "\n[the plugin no longer defines the screen]\n";
foreach ( array( 'render_orders_page', 'render_bulk_actions', 'render_pagination', 'render_processing_table', 'render_order_row', 'render_completed_table' ) as $method ) {
	os_check( 0 === substr_count( $plugin, "function {$method}(" ) && 1 === substr_count( $screen, "function {$method}(" ), "{$method} is defined once, in the screen" );
}
os_check( 0 === preg_match( '/\$this->render_(order_row|processing_table|completed_table|bulk_actions|pagination)\(/', $plugin ), 'and nothing in the plugin still calls them directly' );

echo "\n[what the screen asks of the plugin]\n";
foreach ( array( 'render_provider_switch', 'render_daily_spend', 'from_address_problems', 'quote_store', 'require_cap', 'provider', 'provider_mode' ) as $method ) {
	$reflection = preg_match( '/(public|protected|private)\s+function\s+' . $method . '\(/', $plugin, $m ) ? $m[1] : 'missing';
	os_check( 'public' === $reflection && 1 === substr_count( $plugin, "function {$method}(" ), "{$method} is public on the plugin, and defined once" );
	os_check( false !== strpos( $screen, "\$this->plugin->{$method}(" ), "the screen reaches {$method} through the plugin, not as its own method" );
}
os_check( 0 === preg_match( '/\$this->(render_provider_switch|render_daily_spend|from_address_problems|require_cap)\(/', $screen ), 'it calls none of them as if they were its own' );

echo "\n[it ships]\n";
$manifest_path = dirname( __DIR__ ) . '/tools/build-public.php';
if ( is_file( $manifest_path ) ) {
	$manifest = str_replace( "\r\n", "\n", (string) file_get_contents( $manifest_path ) );
	os_check( false !== strpos( $manifest, "'includes/class-orders-screen.php'" ) && false !== strpos( $manifest, "'tests/orders-screen-test.php'" ), 'the class and this gate are on the publish list' );
} else {
	echo "  ..    the publish list is checked in the working repository (skipped: the published tree has no build manifest)\n";
}
os_check( is_file( dirname( __DIR__ ) . '/includes/class-orders-screen.php' ), 'the class file is present in this tree' );

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['os_checks'], $GLOBALS['os_failures'] );
exit( $GLOBALS['os_failures'] > 0 ? 1 : 0 );
