<?php
/**
 * Gate for the orders-screen header: two rows, one shape language, a title that leads.
 *
 * admin/css/admin.css and the markup are what decide this, and both are easy to "tidy" back
 * into the old arrangement without noticing. The regression this exists for: the title
 * inherited core's 23px/400 while the badge's total was 22px/700, so the number was the
 * strongest thing on the page and the provider buttons were rectangles beside a pill.
 *
 * Run:  php tests/header-layout-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['header_checks']   = 0;
$GLOBALS['header_failures'] = 0;

function header_check( $condition, $label ) {
	$GLOBALS['header_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['header_failures']++;
	echo "  FAIL  {$label}\n";
}

function header_same( $want, $got, $label ) {
	$GLOBALS['header_checks']++;
	if ( $want === $got ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['header_failures']++;
	echo "  FAIL  {$label} (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ")\n";
}

/**
 * The declarations of one CSS rule, as written.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector, exactly as the file spells it.
 * @return string Body, or '' when the rule is missing.
 */
function header_css_rule( $css, $selector ) {
	$start = strpos( $css, $selector . ' {' );
	if ( false === $start ) {
		return '';
	}
	$open = strpos( $css, '{', $start );
	$end  = strpos( $css, '}', $open );

	return false === $end ? '' : substr( $css, $open + 1, $end - $open - 1 );
}

// Normalised: the files are checked out with CRLF on Windows.
$root = dirname( __DIR__ );
$php  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/includes/class-plugin.php' ) );
$css  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/css/admin.css' ) );
$js   = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/js/admin.js' ) );

echo "Shipping Desk header checks\n";
echo "===========================\n\n";
echo "[the markup: two rows]\n";

$header_at   = strpos( $php, '<div class="shipping-desk-header">' );
$title_at    = false === $header_at ? false : strpos( $php, '<h1>', $header_at );
$controls_at = false === $header_at ? false : strpos( $php, '<div class="shipping-desk-controls">', $header_at );

header_check( false !== $header_at, 'the header element is still there' );
header_check( false !== $title_at, 'it contains the title' );
header_check( false !== $controls_at && $controls_at > $title_at, 'and the controls row comes after the title, on its own line' );

$switch_at = false === $controls_at ? false : strpos( $php, 'render_provider_switch()', $controls_at );
$spend_at  = false === $controls_at ? false : strpos( $php, 'render_daily_spend()', $controls_at );

header_check( false !== $switch_at, 'the provider control is inside that row' );
header_check( false !== $spend_at, 'and so is today\'s spend' );
header_check( false !== $switch_at && false !== $spend_at && $spend_at > $switch_at, 'with the spend on the far side' );

echo "\n[the status line]\n";

$msg_at = strpos( $php, 'shipping-desk-provider-msg' );
header_same( 1, substr_count( $php, 'shipping-desk-provider-msg' ), 'the message is rendered once' );
header_check( false !== $msg_at && false !== $controls_at && $msg_at > $controls_at, 'and it lives in the controls row, not inside the pill' );

echo "\n[the title leads]\n";

$title_rule = header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-header h1' );
header_check( '' !== $title_rule, 'the title has its own rule' );
header_check( false !== strpos( $title_rule, 'font-size: 24px' ), 'at 24px, larger than the badge\'s 22px total' );
header_check( false !== strpos( $title_rule, 'font-weight: 600' ), 'and at 600, so it is not the lightest thing on the page' );
header_check( false !== strpos( $title_rule, 'margin: 0' ), 'core\'s 17px margins do not set the row height' );

echo "\n[one shape language]\n";

$header_rule = header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-header' );
header_check( false !== strpos( $header_rule, 'flex-direction: column' ), 'the header stacks title over controls' );

$controls_rule = header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-controls' );
header_check( false !== strpos( $controls_rule, 'justify-content: space-between' ), 'the controls row puts its ends at the ends' );
$switch_rule = header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-provider-switch' );
header_check( false !== strpos( $switch_rule, 'border-radius: 999px' ), 'the provider control is a pill, like the badge' );
header_check( false !== strpos( $switch_rule, 'background: #f6f7f7' ), 'on the badge\'s own surface' );
header_check( false !== strpos( $switch_rule, 'border: 1px solid #dcdcde' ), 'with the badge\'s own border' );

$btn_rule = header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-provider-btn' );
header_check( false !== strpos( $btn_rule, 'border: 0' ), 'the segments carry no border of their own' );
header_check( false !== strpos( $btn_rule, 'border-radius: 999px' ), 'and are pills inside the track' );
header_check( false !== strpos( $btn_rule, 'background: transparent' ), 'so the track shows through' );

header_check(
	false !== strpos( header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-provider-btn.is-active' ), 'background: #2271b1' ),
	'the active provider is the one filled segment'
);
header_check(
	false !== strpos( header_css_rule( $css, '.shipping-desk-wrap .shipping-desk-mode-chip' ), 'border-radius: 999px' ),
	'and the test/live chip is a pill too'
);

echo "\n[the badge is untouched]\n";

header_check(
	false !== strpos( header_css_rule( $css, '.shipping-desk-spend--stacked strong' ), 'font-size: 22px' ),
	'its total is still 22px'
);
header_check( false !== strpos( $php, 'shipping-desk-spend-average' ), 'the average is still rendered' );
header_check( false !== strpos( $php, 'shipping-desk-spend-count' ), 'and the label count' );

echo "\n[the admin font]\n";

header_same( 0, substr_count( $css, 'font-family' ), 'the stylesheet sets no font-family: the admin stack is used on purpose' );

echo "\n[the switch still works]\n";

header_check( false !== strpos( $js, "closest('.shipping-desk-controls')" ), 'the status line is found in the controls row' );
header_same( 3, substr_count( $js, 'providerMessage(' ), 'both handlers use that lookup (definition plus two calls)' );

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['header_checks'], $GLOBALS['header_failures'] );
exit( $GLOBALS['header_failures'] > 0 ? 1 : 0 );
