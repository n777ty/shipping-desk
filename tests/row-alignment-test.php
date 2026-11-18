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
 * Deliberately tolerant of the selector group spanning several lines: it looks for the
 * selector, then the next "{" after it.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector text to locate.
 * @return string Body, or '' when the rule is missing.
 */
function row_css_rule( $css, $selector ) {
	$start = strpos( $css, $selector );
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

echo "\n[every select is covered]\n";

// Each <select> the plugin renders has a static class, so every one can be required to
// appear in the shared rule by name. Tags are not parsed with a regex: every one of them
// embeds PHP in an attribute, and the ">" of that closing tag ends a naive match early -
// which is exactly how the bulk-actions select was missed. Instead each "<select" is
// followed by a short window in which its class attribute must appear.
$php = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) );

$select_classes = array();
$offset         = 0;
while ( false !== ( $at = strpos( $php, '<select', $offset ) ) ) {
	if ( preg_match( '/class="([^"]+)"/', substr( $php, $at, 320 ), $found ) ) {
		$select_classes[] = $found[1];
	}
	$offset = $at + 7;
}
$select_classes = array_unique( $select_classes );

row_check( count( $select_classes ) >= 3, 'the markup renders the selects to check (' . count( $select_classes ) . ' found: ' . implode( ', ', $select_classes ) . ')' );
foreach ( $select_classes as $select_class ) {
	row_check(
		false !== strpos( $css, 'select.' . $select_class . ',' ) || false !== strpos( $css, 'select.' . $select_class . ' {' ),
		"{$select_class} is in the shared select rule, so WordPress core cannot override it"
	);
}

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

echo "\n[every button is the same shape]\n";

/*
 * A rule that sets both the 30px height and the 6px radius is the shape. Every plugin
 * button has to appear in one of those: a button outside them is wearing WordPress core's
 * geometry, which is how the controls drifted apart in the first place.
 */
$uniform = array();
if ( preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
	foreach ( $rules as $rule ) {
		if ( false !== strpos( $rule[2], 'height: 30px' ) && false !== strpos( $rule[2], 'border-radius: 6px' ) ) {
			preg_match_all( '/\.(shipping-desk-[a-z0-9-]+)/', $rule[1], $names );
			foreach ( $names[1] as $name ) {
				$uniform[ $name ] = true;
			}
		}
	}
}
row_check( ! empty( $uniform ), 'the 30px / 6px button rules are found (' . count( $uniform ) . ' classes)' );

// Pills by design: the provider segments and the live/test chip sit inside a track.
$pills = array( 'shipping-desk-provider-btn', 'shipping-desk-mode-chip' );

$button_classes = array();
$with_button    = array();
$offset         = 0;
while ( false !== ( $at = strpos( $php, '<button', $offset ) ) ) {
	if ( preg_match( '/class="([^"]+)"/', substr( $php, $at, 320 ), $found ) ) {
		$attribute = $found[1];
		$is_core   = (bool) preg_match( '/(^|\s)button(\s|$)/', preg_replace( '/<\?php.*?\?>/s', ' ', $attribute ) );
		foreach ( preg_split( '/\s+/', trim( $attribute ) ) as $name ) {
			// Some class attributes have PHP appended to them (the provider button adds
			// its active state), so keep only the leading class token.
			if ( preg_match( '/^(shipping-desk-[a-z0-9-]+)/', $name, $token ) ) {
				$button_classes[ $token[1] ] = true;
				if ( $is_core ) {
					$with_button[ $token[1] ] = true;
				}
			}
		}
	}
	$offset = $at + 7;
}
// Rendered by JavaScript rather than in the markup.
foreach ( array( 'shipping-desk-address-apply', 'shipping-desk-review-flagged' ) as $js_button ) {
	$button_classes[ $js_button ] = true;
	$with_button[ $js_button ]    = true;
}

/*
 * Two ways for a button to be the right shape, and 1.3.13 created the second one. Per-class
 * rules could not cover submit_button() markup (no plugin class of ours) or the what's-new
 * dialog (printed outside every page wrapper), so those buttons are covered by one rule
 * scoped to the screen body class instead. A button is correct if its own class carries the
 * rule, or if it carries .button and the screen-wide rule provides it.
 */
$shared_rule = row_css_rule( $css, ".shipping-desk-screen input.button,\n.shipping-desk-screen button.button" );
$shared_ok   = false !== strpos( $shared_rule, 'height: 30px' ) && false !== strpos( $shared_rule, 'border-radius: 6px' );
row_check( $shared_ok, 'the screen-wide .button rule provides the same 30px / 6px shape' );

$checked = 0;
$via_screen = 0;
foreach ( array_keys( $button_classes ) as $button_class ) {
	if ( in_array( $button_class, $pills, true ) ) {
		continue;
	}
	$checked++;
	$by_own_rule = isset( $uniform[ $button_class ] );
	$by_screen   = isset( $with_button[ $button_class ] ) && $shared_ok;
	if ( $by_screen && ! $by_own_rule ) {
		$via_screen++;
	}
	row_check(
		$by_own_rule || $by_screen,
		"{$button_class} is 30px high with 6px corners" . ( ( $by_screen && ! $by_own_rule ) ? ' (via the screen-wide .button rule)' : '' )
	);
}
row_check( $checked >= 6, 'at least six plugin buttons were checked (' . $checked . ')' );
row_check( $via_screen > 0, "the screen-wide rule is what covers {$via_screen} of them, not a per-class rule" );

/*
 * The bug behind "Buy Label sits two pixels lower than Get Rates": a 30px height with a
 * 1px border and the default content-box measures 32px, so a bordered button beside a
 * borderless one is 2px taller and baseline alignment turns that into an offset. Every
 * rule that fixes a height and draws a border must therefore be border-box.
 */
$not_border_box = array();
if ( preg_match_all( '/([^{}]+)\{([^}]*)\}/', $css, $border_rules, PREG_SET_ORDER ) ) {
	foreach ( $border_rules as $rule ) {
		if ( false === strpos( $rule[2], 'height: 30px' ) || false === strpos( $rule[2], 'border: 1px' ) ) {
			continue;
		}
		if ( false === strpos( $rule[2], 'box-sizing: border-box' ) ) {
			$not_border_box[] = trim( preg_replace( '/\s+/', ' ', substr( $rule[1], 0, 70 ) ) );
		}
	}
}
row_check(
	empty( $not_border_box ),
	'a fixed 30px height with a 1px border is always border-box' . ( empty( $not_border_box ) ? '' : ' — without it: ' . implode( ' | ', $not_border_box ) )
);

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['row_checks'], $GLOBALS['row_failures'] );
exit( $GLOBALS['row_failures'] > 0 ? 1 : 0 );
