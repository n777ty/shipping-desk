<?php
/**
 * Gate for the "Manual..." box option.
 *
 * A one-off box is not worth storing in Box Settings, so the Box dropdown gained a Manual
 * entry at the bottom: choosing it reveals Length, Width and Height inputs, the shipment is
 * priced on those dimensions, and the Weight field stays the whole total (there is no tare to
 * add, so it is a plain override). The dimensions are never saved as a box - only onto the
 * order and into the stored quote.
 *
 * The feature is one small surface with several parts that must agree, and all of them live
 * in files read here as text: the three inputs in the markup, the class that reveals them,
 * the sentinel the script matches, and the two server guards. Each is checked so the wiring
 * cannot drift apart silently.
 *
 * No WordPress and no browser: the pieces are read as text, which is enough to catch the
 * regressions that matter - the option moving above the saved boxes, the panel left visible,
 * a guard dropped, or a buy that no longer says which box it is buying.
 *
 * Run:  php tests/manual-box-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['mb_checks']   = 0;
$GLOBALS['mb_failures'] = 0;

function mb_check( $condition, $label ) {
	$GLOBALS['mb_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['mb_failures']++;
	echo "  FAIL  {$label}\n";
}

function mb_same( $expected, $actual, $label ) {
	$detail = ( $expected === $actual ) ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	mb_check( $expected === $actual, $label . $detail );
}

/**
 * The body of one top-level JavaScript function, bounded by the next four-space closing brace.
 *
 * Bounded rather than brace-counted: the bodies contain object literals and callbacks whose
 * braces a naive counter would trip over.
 *
 * @param string $js        File contents.
 * @param string $signature Opening line as written, e.g. "    function getBoxData($row) {".
 * @return string Body, or '' when the signature is absent.
 */
function mb_function_body( $js, $signature ) {
	$start = strpos( $js, $signature );
	if ( false === $start ) {
		return '';
	}
	$end = strpos( $js, "\n    }\n", $start );

	return false === $end ? '' : substr( $js, $start, $end - $start );
}

$root = dirname( __DIR__ );
$read = function ( $path ) use ( $root ) {
	return str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/' . $path ) );
};

$screen = $read( 'includes/class-orders-screen.php' );
$plugin = $read( 'includes/class-plugin.php' );
$labels = $read( 'includes/class-labels.php' );
$js     = $read( 'admin/js/admin.js' );
$css    = $read( 'admin/css/admin.css' );

echo "Shipping Desk manual box checks\n";
echo "===============================\n\n";

echo "[the dropdown gains one entry, at the bottom]\n";
$foreach_pos = strpos( $screen, 'foreach ( $boxes as $idx => $box )' );
$manual_pos  = strpos( $screen, '<option value="manual">' );
mb_same( 1, substr_count( $screen, '<option value="manual">' ), 'the Manual option is rendered exactly once' );
mb_check( false !== $foreach_pos, 'the saved boxes are still looped over' );
mb_check( false !== $manual_pos && $manual_pos > $foreach_pos, 'and the Manual option comes after them, so it sits at the bottom' );

echo "\n[three dimension inputs, hidden until they are needed]\n";
mb_check( false !== strpos( $screen, 'class="shipping-desk-manual-dimensions"' ), 'the panel exists in the box column' );
mb_same( 3, substr_count( $screen, 'class="shipping-desk-manual-dimension"' ), 'with exactly three inputs' );
foreach ( array( 'length', 'width', 'height' ) as $dimension_name ) {
	mb_check( false !== strpos( $screen, 'data-dimension="' . $dimension_name . '"' ), "the {$dimension_name} input names its dimension" );
}

echo "\n[the panel is shown only for Manual]\n";
mb_check(
	false !== strpos( $css, '.shipping-desk-box-column .shipping-desk-manual-dimensions {' )
	&& false !== strpos( $css, 'display: none' ),
	'the panel is hidden by default'
);
mb_check(
	false !== strpos( $css, '.shipping-desk-box-column .shipping-desk-manual-dimensions.is-visible { display: flex; }' ),
	'and .is-visible reveals it'
);
mb_check(
	false !== strpos( $css, '.shipping-desk-screen input[type="number"].shipping-desk-manual-dimension' ),
	'the inputs are styled by name, scoped to the screen, so core cannot outrank them'
);

echo "\n[the script reads a sentinel, not a stored box]\n";
mb_check( false !== strpos( $js, "var MANUAL_BOX = 'manual';" ), "the sentinel is the string 'manual'" );
$get_box = mb_function_body( $js, '    function getBoxData($row) {' );
mb_check( '' !== $get_box, 'getBoxData was found' );
mb_check( false !== strpos( $get_box, 'MANUAL_BOX' ), 'getBoxData branches on the sentinel' );
mb_check( false !== strpos( $get_box, 'weight: 0' ), 'and gives a manual box no tare, so Weight stays the whole total' );
$dims = mb_function_body( $js, '    function getManualDimensions($row) {' );
mb_check( '' !== $dims && false !== strpos( $dims, 'return complete ? dims : null;' ), 'an incomplete box yields null, so it cannot be quoted or bought' );

echo "\n[selecting or editing the box clears stale quotes]\n";
$select_handler = strpos( $js, "'.shipping-desk-box-select', function () {" );
mb_check( false !== $select_handler, 'the box dropdown change handler is present' );
mb_check(
	false !== $select_handler && false !== strpos( substr( $js, $select_handler, 200 ), 'toggleManualDimensions(' ),
	'and it reveals or hides the panel'
);
mb_check( false !== strpos( $js, "'.shipping-desk-manual-dimension', function () {" ), 'typing a dimension is a box change too' );
mb_check( substr_count( $js, "'Box changed. Get rates to refresh quotes.'" ) >= 2, 'and both paths discard the quotes priced for the old box' );

echo "\n[a reload puts the panel and its numbers back]\n";
$hydrate = mb_function_body( $js, '    function hydrateRow($row) {' );
mb_check( '' !== $hydrate, 'hydrateRow was found' );
mb_check( false !== strpos( $hydrate, 'toggleManualDimensions($row);' ), 'a hydrated row re-derives the panel' );
mb_check( false !== strpos( $hydrate, 'fillManualDimensions($row, inputs.box);' ), 'and refills the dimensions stored with the quote' );

echo "\n[a bought row locks the dimensions with the rest]\n";
mb_check(
	false !== strpos( $js, '.shipping-desk-box-select, .shipping-desk-manual-dimension, .shipping-desk-total-weight' ),
	'the manual inputs join the controls disabled after a purchase'
);

echo "\n[the request says which box it is buying]\n";
$purchase = mb_function_body( $js, '    function purchaseLabel($row) {' );
mb_check( '' !== $purchase && false !== strpos( $purchase, 'box_key:' ), 'the single buy sends the box key' );
mb_check( false !== strpos( $js, "box_key:      \$row.find('.shipping-desk-box-select').val() || ''," ), 'so does each bulk entry' );

echo "\n[the server refuses a manual box with no dimensions]\n";
mb_check( false !== strpos( $plugin, "'manual' === \$box_key" ), 'get rates guards on the manual key' );
mb_check( false !== strpos( $plugin, 'Enter the box length, width and height before getting rates.' ), 'with a message naming the three dimensions' );
mb_check( false !== strpos( $labels, 'function box_dimensions_missing(' ), 'the labels class has one missing-dimension test' );
mb_same( 2, substr_count( $labels, '$this->box_dimensions_missing( $box )' ), 'used by both the single buy and the bulk buy' );
mb_same( 2, substr_count( $labels, "'manual' === \$box_key" ), 'each guarded on the manual key' );

echo "\n[it ships]\n";
$manifest_path = $root . '/tools/build-public.php';
if ( is_file( $manifest_path ) ) {
	mb_check( false !== strpos( $read( 'tools/build-public.php' ), "'tests/manual-box-test.php'" ), 'this gate is on the publish list' );
} else {
	echo "  ..    the publish list is checked in the working repository (skipped: the published tree has no build manifest)\n";
}

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['mb_checks'], $GLOBALS['mb_failures'] );
exit( $GLOBALS['mb_failures'] > 0 ? 1 : 0 );
