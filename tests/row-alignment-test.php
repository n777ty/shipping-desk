<?php
/**
 * Gate for the row's form controls: one style, and strong enough to apply.
 *
 * Two bugs in a row came from here. First the Box and Signature selects sat at different
 * heights, because the cells are vertically centred and their contents differ. Then they
 * looked like different controls - one square, one rounded - because every plugin rule was
 * a single class, and WordPress core styles these with `.wp-core-ui select` and
 * `input[type="number"]`, which are one class plus an element and therefore outrank them.
 * The plugin's radius, height and type never applied; only the one rule that happened to be
 * two classes did.
 *
 * So this checks the declarations *and* the selectors' strength: scoped to the page
 * wrapper, qualified with the element, all four controls identical.
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

function row_same( $want, $got, $label ) {
	$GLOBALS['row_checks']++;
	if ( $want === $got ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['row_failures']++;
	echo "  FAIL  {$label} (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ")\n";
}

/**
 * The declarations of one CSS rule, found by its first selector line.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector text, exactly as the file spells it.
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

echo "Shipping Desk row control checks\n";
echo "================================\n\n";

echo "[the selects are one control]\n";

$select_rule = row_css_rule( $css, ".shipping-desk-wrap select.shipping-desk-box-select,\n.shipping-desk-wrap select.shipping-desk-service-select,\n.shipping-desk-wrap select.shipping-desk-signature-confirmation" );
row_check( '' !== $select_rule, 'box, service and signature selects share one rule' );
row_check( false !== strpos( $select_rule, 'border-radius: 6px' ), 'at 6px, the slightly-rounded edge' );
row_check( false !== strpos( $select_rule, 'height: 30px' ), '30px high' );
row_check( false !== strpos( $select_rule, 'min-height: 0' ), 'with core\'s min-height neutralised, or it would win' );
row_check( false !== strpos( $select_rule, 'font-size: 12px' ), 'and 12px type' );
row_check( false !== strpos( $select_rule, 'border: 1px solid #d1d5db' ), 'one border colour' );

echo "\n[the number inputs match]\n";

$input_rule = row_css_rule( $css, ".shipping-desk-wrap input[type=\"number\"].shipping-desk-total-weight,\n.shipping-desk-wrap input[type=\"number\"].shipping-desk-liability-amount" );
row_check( '' !== $input_rule, 'weight and insurance inputs share one rule' );
row_check( false !== strpos( $input_rule, 'border-radius: 6px' ), 'the same 6px radius as the selects' );
row_check( false !== strpos( $input_rule, 'height: 30px' ), 'the same height' );
row_check( false !== strpos( $input_rule, 'font-size: 12px' ), 'and the same type size' );
row_check( false !== strpos( $input_rule, 'min-height: 0' ), 'with core\'s min-height neutralised here too' );

echo "\n[strong enough to beat WordPress core]\n";

row_check(
	false !== strpos( $css, '.shipping-desk-wrap select.shipping-desk-box-select' ),
	'the select rule is scoped to the page wrapper and qualified with the element'
);
row_check(
	false !== strpos( $css, '.shipping-desk-wrap input[type="number"].shipping-desk-total-weight' ),
	'and so is the input rule, which needs an attribute selector to outrank core'
);
row_same(
	0,
	preg_match( '/^\.shipping-desk-(box-select|total-weight|liability-amount|service-select|signature-confirmation) \{/m', $css ),
	'no single-class control rule is left to be silently outranked'
);

echo "\n[the columns still line up]\n";

$top = row_css_rule( $css, ".shipping-desk-table .shipping-desk-box-column,\n.shipping-desk-table .shipping-desk-services-column" );
row_check( '' !== $top, 'the two columns have a rule of their own' );
row_check( false !== strpos( $top, 'vertical-align: top' ), 'and it top-aligns them, so content height cannot shift one above the other' );

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['row_checks'], $GLOBALS['row_failures'] );
exit( $GLOBALS['row_failures'] > 0 ? 1 : 0 );
