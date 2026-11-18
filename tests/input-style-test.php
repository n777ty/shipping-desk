<?php
/**
 * Gate for text fields: one geometry, on every screen, and only where it belongs.
 *
 * The buttons had been brought into line in 1.3.13 while the fields beside them stayed
 * square, because the only styled inputs in the plugin were the four that happen to carry a
 * plugin class (weight, insurance, the two in-table selects). Everything a settings page
 * renders comes from WordPress markup with `.regular-text` and nothing of ours, so it fell
 * through to core - the same shape of bug as "Save Boxes", one layer over.
 *
 * Two things make this gate worth more than a screenshot: it requires every text-entry type
 * the plugin actually renders to be named in the rule, so a new field type cannot arrive
 * unstyled; and it requires the types that must stay native - checkbox, radio, hidden, file -
 * to be absent from it, because a fixed height and a border on a checkbox is a defect, not a
 * style.
 *
 * Run:  php tests/input-style-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['in_checks']   = 0;
$GLOBALS['in_failures'] = 0;

function in_check( $condition, $label ) {
	$GLOBALS['in_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['in_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * The declarations of one CSS rule, found by its first selector line.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector text to locate.
 * @return string Body, or '' when the rule is missing.
 */
function in_css_rule( $css, $selector ) {
	$start = strpos( $css, $selector );
	if ( false === $start ) {
		return '';
	}
	$open = strpos( $css, '{', $start );
	$end  = strpos( $css, '}', $open );

	return false === $end ? '' : substr( $css, $open + 1, $end - $open - 1 );
}

$root = dirname( __DIR__ );
$css  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/css/admin.css' ) );
$php  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/includes/class-plugin.php' ) );
$js   = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/js/admin.js' ) );

$selector = '.shipping-desk-screen input[type="text"],';
$rule     = in_css_rule( $css, $selector );

echo "Shipping Desk text field checks\n";
echo "===============================\n\n";

echo "[one rule for the fields]\n";

in_check( '' !== $rule, 'the text field rule is found' );
in_check( false !== strpos( $rule, 'box-sizing: border-box' ), 'border-box, so 30px plus a 1px border stays 30px' );
in_check( false !== strpos( $rule, 'height: 30px' ), '30px high, the same as the buttons beside it' );
in_check( false !== strpos( $rule, 'min-height: 0' ), "with core's min-height neutralised, or it would win" );
in_check( false !== strpos( $rule, 'border-radius: 6px' ), '6px corners, not core\'s square ones' );
in_check( false !== strpos( $rule, 'border: 1px solid #d1d5db' ), 'the same border colour as the selects' );
in_check( false !== strpos( $rule, 'font-size: 12px' ), 'and 12px type' );
in_check( false !== strpos( $rule, 'line-height: 28px' ), 'text centred in the 28px content box' );
in_check( false !== strpos( $rule, 'padding: 0 8px' ), 'the same horizontal padding as the in-table inputs' );
in_check( false === strpos( $rule, 'width' ), 'no width is set, so .regular-text and the table cells keep their own' );

echo "\n[strong enough to beat WordPress core]\n";

in_check( false !== strpos( $selector, 'input[type=' ), 'the element and attribute are named, so the rule outranks core\'s `input[type="text"]`' );
in_check( false !== strpos( $selector, '.shipping-desk-screen' ), 'scoped by the body class every plugin screen carries, not by a page wrapper' );
in_check(
	false === strpos( $selector, 'shipping-desk-wrap' ) && false === strpos( $selector, 'shipping-desk-settings' ),
	'and not by a page wrapper - which is what left the settings pages square'
);
in_check(
	false !== strpos( $css, '.shipping-desk-screen input[type="text"]:focus' ),
	'a focus state matches it: one border colour, one ring'
);


echo "\n[every field type the plugin renders is named]\n";

/*
 * The invariant that stops this recurring: collect the input types the markup actually uses,
 * and require each text-entry one to appear in the rule. A new `type="date"` field would fail
 * here rather than shipping square.
 */
$types  = array();
$offset = 0;
while ( false !== ( $at = strpos( $php, '<input', $offset ) ) ) {
	if ( preg_match( '/type="([a-z]+)"/', substr( $php, $at, 300 ), $found ) ) {
		$types[ $found[1] ] = true;
	}
	$offset = $at + 6;
}

in_check( count( $types ) >= 4, 'the markup renders the input types to check (' . implode( ', ', array_keys( $types ) ) . ')' );

$text_like = array( 'text', 'password', 'number', 'search', 'email', 'url', 'tel' );
$native    = array( 'checkbox', 'radio', 'hidden', 'file', 'submit', 'button', 'reset' );

foreach ( array_keys( $types ) as $type ) {
	if ( in_array( $type, $text_like, true ) ) {
		in_check(
			false !== strpos( $css, '.shipping-desk-screen input[type="' . $type . '"]' ),
			"type=\"{$type}\" is named in the rule"
		);
	} elseif ( in_array( $type, $native, true ) ) {
		in_check(
			false === strpos( $css, '.shipping-desk-screen input[type="' . $type . '"]' ),
			"type=\"{$type}\" is left exactly as WordPress renders it"
		);
	} else {
		in_check( false, "type=\"{$type}\" is a new input type - decide whether it is text-like, then name it" );
	}
}

echo "\n[the JavaScript-built inputs need nothing]\n";

$js_types = array();
$offset   = 0;
while ( false !== ( $at = strpos( $js, "\$('<input>'", $offset ) ) ) {
	if ( preg_match( "/type:\\s*'([a-z]+)'/", substr( $js, $at, 160 ), $found ) ) {
		$js_types[ $found[1] ] = true;
	}
	$offset = $at + 10;
}

in_check( ! empty( $js_types ), 'the script builds the inputs to check (' . implode( ', ', array_keys( $js_types ) ) . ')' );
foreach ( array_keys( $js_types ) as $type ) {
	in_check( in_array( $type, $native, true ), "JS-built type=\"{$type}\" is native, so no rule is expected" );
}

echo "\n[multi-line fields keep their own height]\n";

$textarea = in_css_rule( $css, '.shipping-desk-screen textarea {' );
in_check( '' !== $textarea, 'a textarea rule exists, so a future one is not square' );
in_check( false !== strpos( $textarea, 'border-radius: 6px' ), 'with the same 6px corners' );
in_check( ! preg_match( '/(?<!-)height:/', $textarea ), 'and no fixed height, which would collapse it to one line' );

echo "\n";

$failures = (int) $GLOBALS['in_failures'];
$checks   = (int) $GLOBALS['in_checks'];

echo "Checks: {$checks}, failures: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );
