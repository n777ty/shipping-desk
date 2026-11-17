<?php
/**
 * Gate for the two controls that share a row: Box and Signature.
 *
 * They live in adjacent table cells, and the cells are vertically centred, so any
 * difference in content height moves one select above the other - which is exactly what
 * 1.3.6 shipped: 9px, because the signature group is taller than the box column and its
 * select was also 2px shorter. Neither is visible in a diff of the header, so this checks
 * the rule that prevents it: top alignment, and two controls built to the same size.
 *
 * Run:  php tests/row-alignment-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['row_checks']   = 0;
$GLOBALS['row_failures'] = 0;

function row_check( $condition, $label ) {
	$GLOBALS['row_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['row_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * The declarations of one CSS rule.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector, exactly as the file spells it.
 * @return string Body, or '' when the rule is missing.
 */
function row_css_rule( $css, $selector ) {
	$start = strpos( $css, $selector . ' {' );
	if ( false === $start ) {
		return '';
	}
	$open = strpos( $css, '{', $start );
	$end  = strpos( $css, '}', $open );

	return false === $end ? '' : substr( $css, $open + 1, $end - $open - 1 );
}

$css = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/admin/css/admin.css' ) );

echo "Shipping Desk row alignment checks\n";
echo "==================================\n\n";

echo "[the cells start at the same edge]\n";

$top = row_css_rule( $css, ".shipping-desk-table .shipping-desk-box-column,\n.shipping-desk-table .shipping-desk-services-column" );
row_check( '' !== $top, 'the two columns have a rule of their own' );
row_check( false !== strpos( $top, 'vertical-align: top' ), 'and it top-aligns them, so content height cannot shift one above the other' );

echo "\n[the two controls are the same size]\n";

$box = row_css_rule( $css, ".shipping-desk-box-select,\n.shipping-desk-total-weight" );
row_check( '' !== $box && false !== strpos( $box, 'height: 28px' ), 'the box select is 28px' );
row_check( false !== strpos( $box, 'border-radius: 8px' ), 'with an 8px radius' );

$signature = row_css_rule( $css, '.shipping-desk-table .shipping-desk-signature-confirmation' );
row_check( '' !== $signature, 'the signature select has its own rule, scoped to the table' );
row_check( false !== strpos( $signature, 'height: 28px' ), 'at 28px, matching the box select' );
row_check( false !== strpos( $signature, 'border-radius: 8px' ), 'with the box select\'s radius' );
row_check( false !== strpos( $signature, 'font-size: 12px' ), 'and its type size' );

echo "\n[the shared rule is not weakened]\n";

$group = row_css_rule( $css, ".shipping-desk-service-select,\n.shipping-desk-signature-confirmation" );
row_check( '' !== $group, 'the shared service-select rule is still there' );
row_check(
	false === strpos( $group, 'height: 28px' ),
	'and it stays at its own height, so the table rule is what aligns the row'
);

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['row_checks'], $GLOBALS['row_failures'] );
exit( $GLOBALS['row_failures'] > 0 ? 1 : 0 );
