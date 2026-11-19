<?php
/**
 * Gate for buttons: one geometry, on every screen, strong enough to apply.
 *
 * Three separate reports came from this one weakness. "Save Boxes" kept square corners
 * beside a rounded "Add Box"; the what's-new dialog's "Got it" kept them too; and the
 * PrintNode screen was unstyled entirely. The cause was the same each time - the plugin
 * styled `.button` by enumerating specific class names under a page wrapper, so anything
 * WordPress's submit_button() rendered (which has no plugin class) fell through to core,
 * and anything rendered outside a wrapper (the dialog, in admin_footer) matched no rule at
 * all, while the PrintNode screen uses a wrapper of its own with no rules behind it.
 *
 * So the gate does not check a list of class names. It checks three things that make the
 * list unnecessary: the rule is scoped to the screen rather than a wrapper, it names the
 * element so it outranks `.wp-core-ui .button`, and every button the plugin renders - in
 * PHP or in JS - carries the `.button` class that rule reaches.
 *
 * Run:  php tests/button-style-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['btn_checks']   = 0;
$GLOBALS['btn_failures'] = 0;

function btn_check( $condition, $label ) {
	$GLOBALS['btn_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['btn_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * The declarations of one CSS rule, found by its first selector line.
 *
 * @param string $css      Stylesheet.
 * @param string $selector Selector text to locate.
 * @return string Body, or '' when the rule is missing.
 */
function btn_css_rule( $css, $selector ) {
	$start = strpos( $css, $selector );
	if ( false === $start ) {
		return '';
	}
	$open = strpos( $css, '{', $start );
	$end  = strpos( $css, '}', $open );

	return false === $end ? '' : substr( $css, $open + 1, $end - $open - 1 );
}

/**
 * Whether the stylesheet reaches a button, given its class attribute.
 *
 * Two legitimate answers: the button carries WordPress's `.button` class, so the shared
 * screen-wide rule applies; or it carries one of the plugin's own classes and there is a
 * rule for it. A button with neither is the defect this gate exists to find - it renders
 * with core's defaults, square corners and all, which is what "Save Boxes" did.
 *
 * PHP inside the attribute is stripped first: class lists like
 * `shipping-desk-provider-btn<?php echo ... ?>` carry their static name at the front.
 *
 * @param string $css        Stylesheet.
 * @param string $class_list Raw class attribute value.
 * @return bool
 */
function btn_is_styled( $css, $class_list ) {
	$clean  = preg_replace( '/<\?php.*?\?>/s', ' ', $class_list );
	$tokens = preg_split( '/\s+/', trim( (string) $clean ) );

	foreach ( $tokens as $token ) {
		if ( ! preg_match( '/^[a-z][a-z0-9-]*$/i', $token ) ) {
			continue;
		}
		if ( 'button' === $token ) {
			return true;
		}
		if ( preg_match( '/[.\s]' . preg_quote( $token, '/' ) . '[\s,:{]/', $css ) ) {
			return true;
		}
	}

	return false;
}

$root = dirname( __DIR__ );
$css  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/css/admin.css' ) );
$php  = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/includes/class-plugin.php' ) );
$js   = str_replace( "\r\n", "\n", (string) file_get_contents( $root . '/admin/js/admin.js' ) );

$selector = ".shipping-desk-screen a.button,\n.shipping-desk-screen input.button,\n.shipping-desk-screen button.button";

echo "Shipping Desk button checks\n";
echo "===========================\n\n";

echo "[one rule, one geometry]\n";

$rule = btn_css_rule( $css, $selector );
btn_check( '' !== $rule, 'a.button, input.button and button.button share a single rule' );
btn_check( false !== strpos( $rule, 'box-sizing: border-box' ), 'border-box, so a bordered button is not 2px taller' );
btn_check( false !== strpos( $rule, 'height: 30px' ), '30px high, the same as the plugin\'s own buttons' );
btn_check( false !== strpos( $rule, 'min-height: 0' ), "with core's min-height neutralised, or it would win" );
btn_check( false !== strpos( $rule, 'line-height: 28px' ), 'text centred in the 28px content box' );
btn_check( false !== strpos( $rule, 'border-radius: 6px' ), '6px corners, not core\'s 3px' );
btn_check( false !== strpos( $rule, 'font-size: 12px' ), 'and 12px type' );
btn_check( false !== strpos( $rule, 'padding: 0 12px' ), 'the same horizontal padding as the action buttons' );


echo "\n[strong enough to beat WordPress core]\n";

btn_check( false !== strpos( $selector, 'input.button' ), 'the element is named, so the rule outranks `.wp-core-ui .button`' );
btn_check( false !== strpos( $selector, '.shipping-desk-screen' ), 'scoped by the body class, which every plugin screen carries' );
btn_check(
	false === strpos( $selector, 'shipping-desk-wrap' ) && false === strpos( $selector, 'shipping-desk-settings' ),
	'and not by a page wrapper - the mistake that left three surfaces unstyled'
);
btn_check(
	'' === btn_css_rule( $css, '.shipping-desk-wrap .button.' ),
	'no wrapper-scoped per-class button rules remain to imply this one is insufficient'
);

echo "\n[every button the screen renders reaches the rule]\n";


// Tags are not matched with one regex: every <button> in this file embeds PHP in an
// attribute, and the ">" of a closing tag ends a naive match early. Each "<button" is
// followed by a short window in which its class attribute must appear.
$button_classes = array();
$offset         = 0;
while ( false !== ( $at = strpos( $php, '<button', $offset ) ) ) {
	if ( preg_match( '/class="([^"]+)"/', substr( $php, $at, 320 ), $found ) ) {
		$button_classes[] = $found[1];
	} else {
		$button_classes[] = '(no class attribute)';
	}
	$offset = $at + 7;
}

btn_check( count( $button_classes ) >= 4, 'the markup renders the buttons to check (' . count( $button_classes ) . ' found)' );
foreach ( array_unique( $button_classes ) as $class_list ) {
	btn_check(
		btn_is_styled( $css, $class_list ),
		"<button class=\"{$class_list}\"> is .button, or has a rule of its own"
	);
}

/*
 * Anchors too. The Export control is an <a class="button">, and it was the one control this
 * section could not see: the scans above look for <button> tags and for JS-built buttons.
 * The rule named input.button and button.button, so the anchor fell through to core and kept
 * its 3px corners beside 30px buttons on the same table. The element is named in the rule
 * now, and this scan keeps it named.
 */
$anchor_classes = array();
$offset         = 0;
while ( false !== ( $at = strpos( $php, '<a ', $offset ) ) ) {
	// PHP blocks are blanked first: the PHP closing tag ends with a ">", so a tag whose
	// attributes carry inline PHP would otherwise be cut off before its class was reached.
	$window = (string) preg_replace( '/<\?php.*?\?>/s', ' ', substr( $php, $at, 320 ) );
	$end    = strpos( $window, '>' );
	$tag    = false === $end ? $window : substr( $window, 0, $end );

	if ( preg_match( '/class="([^"]+)"/', $tag, $found ) ) {
		$anchor_classes[] = $found[1];
	}
	$offset = $at + 3;
}

$anchor_buttons = array();
foreach ( $anchor_classes as $class_list ) {
	$clean = trim( (string) preg_replace( '/<\?php.*?\?>/s', ' ', $class_list ) );

	if ( preg_match( '/(^|\s)button(\s|$)/', $clean ) ) {
		$anchor_buttons[] = $clean;
	}
}

btn_check( array() !== $anchor_buttons, 'the markup renders an anchor carrying .button (' . count( $anchor_buttons ) . ' found)' );
btn_check(
	false !== strpos( $selector, 'a.button' ),
	'and the shared rule names a.button, or that anchor keeps core geometry'
);

$submit_calls = substr_count( $php, 'submit_button(' );

echo "\n[buttons built in JavaScript carry .button too]\n";

// The pill and the label/refund actions are deliberately not .button: they have rules of
// their own (a 9px pill, and the coloured action buttons). Everything else must opt in.
$js_classes = array();
$offset     = 0;
while ( false !== ( $at = strpos( $js, "\$('<button>'", $offset ) ) ) {
	if ( preg_match( "/'class':\\s*'([^']+)'/", substr( $js, $at, 300 ), $found ) ) {
		$js_classes[] = $found[1];
	} else {
		$js_classes[] = '(no class)';
	}
	$offset = $at + 10;
}

btn_check( count( $js_classes ) >= 4, 'the script builds the buttons to check (' . count( $js_classes ) . ' found)' );
foreach ( array_unique( $js_classes ) as $class_list ) {
	btn_check(
		btn_is_styled( $css, $class_list ),
		"JS button class=\"{$class_list}\" is .button, or has a rule of its own"
	);
}

echo "\n[the body class exists, on every plugin screen]\n";

btn_check( false !== strpos( $php, "add_filter( 'admin_body_class'" ), 'admin_body_class is hooked' );
btn_check( false !== strpos( $php, 'function add_screen_body_class' ), 'and its callback is defined' );
btn_check( false !== strpos( $php, "' shipping-desk-screen'" ), 'the callback appends the class the rule is scoped to' );
btn_check(
	(bool) preg_match( '/function add_screen_body_class.*?strpos\( \$page, \'shipping-desk\' \).*?\}/s', $php ),
	"guarded by the same 'shipping-desk' page test the stylesheet enqueue uses"
);

echo "\n";

$failures = (int) $GLOBALS['btn_failures'];
$checks   = (int) $GLOBALS['btn_checks'];

echo "Checks: {$checks}, failures: {$failures}\n";
exit( 0 === $failures ? 0 : 1 );

btn_check( $submit_calls >= 9, "submit_button() renders the rest ({$submit_calls} calls), and WordPress gives each .button" );
