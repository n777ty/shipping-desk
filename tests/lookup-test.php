<?php
/**
 * Gate for Shipment Lookup's provider and mode.
 *
 * The page used to ask whichever provider the *settings* said, in whatever mode that
 * provider was in, so an older label bought elsewhere - or in the other mode - read as
 * "id not found". It now resolves per lookup: what the operator chose, then what the
 * order recorded when the label was bought, then the setting. That preference order and
 * the things it must never do are what this gate pins.
 *
 * Source checks, because WooCommerce and the providers are not installed here; the same
 * approach as the rate-payload gate for behaviour that lives inside the plugin class.
 *
 * Run:  php tests/lookup-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 *
 * @package ShippingDesk
 */

error_reporting( E_ALL );

$GLOBALS['lookup_checks']   = 0;
$GLOBALS['lookup_failures'] = 0;

function lookup_check( $condition, $label ) {
	$GLOBALS['lookup_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['lookup_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * Body of one method in the plugin class.
 *
 * @param string $source   File contents.
 * @param string $signature Opening line, without indentation.
 * @return string
 */
function lookup_body( $source, $signature ) {
	$start = strpos( $source, $signature );
	if ( false === $start ) {
		return '';
	}

	$end = strpos( $source, "\n    }\n", $start );

	return false === $end ? '' : substr( $source, $start, $end - $start );
}

// Normalised: the file is checked out with CRLF on Windows and the method
// boundaries below are written against bare newlines, as in the other gates.
$source = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ) );

echo "Shipping Desk shipment lookup checks\n";
echo "====================================\n\n";

echo "[the form asks where to look]\n";

lookup_check( 1 === substr_count( $source, 'name="lookup_provider"' ), 'the provider value travels in one hidden field' );
lookup_check( 1 === substr_count( $source, 'name="lookup_mode"' ), 'and the mode value in another' );
lookup_check( false !== strpos( $source, "'auto' => __( 'Auto', 'shipping-desk' ), 'easypost' => 'EasyPost'" ), 'offering Auto and all three providers' );
lookup_check( false !== strpos( $source, 'class="shipping-desk-provider-btn shipping-desk-lookup-chip' ), 'the provider choices are the same buttons as the provider switch' );
lookup_check( false !== strpos( $source, 'class="shipping-desk-mode-chip is-' ), 'the mode chip takes the current mode as its colour' );
lookup_check( false !== strpos( $source, 'data-target="lookup_provider"' ), 'the provider chips name the field they fill' );
lookup_check( 1 === substr_count( $source, 'shipping-desk-lookup-mode' ), 'the mode is a single chip, not a row of three' );
lookup_check( false !== strpos( $source, 'data-mode="' ), 'which carries the current mode' );
lookup_check( false !== strpos( $source, 'data-labels="' ), 'and its translated labels' );
lookup_check( false !== strpos( $source, 'data-titles="' ), 'and the sentence explaining what a click does' );
lookup_check( 2 <= substr_count( $source, "' is-active' : ''" ), 'the resolved provider starts highlighted' );
lookup_check( false !== strpos( $source, 'None of this changes which provider buys the next label.' ), 'and says none of it is the label setting' );

$js = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' ) );

// The header's switch handlers must not act on these chips: same classes, different job.
lookup_check( false !== strpos( $js, "if (!\$(this).attr('data-provider'))" ), 'the label-provider handler ignores a chip with no provider' );
lookup_check( false !== strpos( $js, "hasClass('shipping-desk-lookup-mode')" ), 'and the mode handler ignores the lookup one' );
lookup_check( false !== strpos( $js, "'.shipping-desk-lookup-chip'" ), 'the lookup chips have their own handler' );
lookup_check( false !== strpos( $js, '.shipping-desk-lookup-chip[data-target="' ), 'scoped to the field being fed' );
lookup_check( false !== strpos( $js, "$('#' + target).val(" ), 'writing the value into the hidden field' );
lookup_check( false !== strpos( $js, "'.shipping-desk-lookup-mode'" ), 'and the mode chip has a cycling handler' );
lookup_check( false !== strpos( $js, "['auto', 'test', 'live']" ), 'that cycles Auto, Test, Live' );
lookup_check( false !== strpos( $js, ".attr('data-mode', next)" ), 'keeping the chip in step with the value' );

$css = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/admin/css/admin.css' ) );
lookup_check( false !== strpos( $css, '.shipping-desk-lookup-mode.is-auto' ), 'and Auto gets a neutral chip of its own' );

echo "\n[resolution order]\n";

$resolve = lookup_body( $source, 'protected function resolve_lookup_target( $wanted_provider, $wanted_mode, $order_id = 0 ) {' );

lookup_check( '' !== $resolve, 'resolve_lookup_target() is present' );

$chosen   = strpos( $resolve, 'in_array( $wanted_provider, $valid, true )' );
$recorded = strpos( $resolve, 'in_array( $recorded_provider, $valid, true )' );
$setting  = strpos( $resolve, '$this->provider()' );

lookup_check( false !== $chosen && false !== $recorded && false !== $setting, 'it considers all three sources' );
lookup_check( $chosen < $recorded, 'a chosen provider wins over the record' );
lookup_check( $recorded < $setting, 'the record wins over the setting' );
lookup_check(
	false !== strpos( $resolve, "'order' === \$source && in_array( \$recorded_mode" ),
	'and the mode follows the order only when the provider did'
);
lookup_check(
	false !== strpos( $resolve, "return [ \$provider, '', \$source ]" ),
	'Veeqo has no modes, so it is never asked for one'
);
lookup_check( false === strpos( $resolve, 'update_option' ), 'nothing here writes the provider setting' );

echo "\n[the query follows the resolution]\n";

$page = lookup_body( $source, 'public function render_lookup_page() {' );

lookup_check( '' !== $page, 'render_lookup_page() is present' );
lookup_check( 1 === substr_count( $page, 'class="shipping-desk-provider-switch"' ), 'the page draws one track, the same pill as the provider switch' );
lookup_check( false !== strpos( $page, 'manage_woocommerce' ), 'still needs the WooCommerce capability' );

$find  = strpos( $page, '$this->find_order_for_shipment(' );
$ask   = strpos( $page, '$this->lookup_shipment(' );
lookup_check( false !== $find && false !== $ask, 'it finds the order and then asks a provider' );
lookup_check( $find < $ask, 'the order is resolved before the provider is asked' );
lookup_check( false !== strpos( $page, "'' === \$mode_key" ), 'and a missing key for that mode is explained, not just "not found"' );

$lookup = lookup_body( $source, 'protected function lookup_shipment( $provider, $mode, $shipment_id ) {' );
lookup_check( '' !== $lookup, 'lookup_shipment() is present' );
lookup_check( false !== strpos( $lookup, "api( \$provider, 'veeqo' === \$provider ? null : \$mode )" ), 'it builds the client for the resolved provider and mode' );
lookup_check( false !== strpos( $lookup, 'get_transaction' ), 'Shippo is still asked for the transaction first' );
lookup_check( false !== strpos( $lookup, "'shippo_not_found'" ), 'and still falls back to the shipment id' );

echo "\n[the page says where the answer came from]\n";

lookup_check( false !== strpos( $source, 'recorded on order #%d' ), 'the order is named when it decided' );
lookup_check( false !== strpos( $source, 'chosen for this lookup.' ), 'a manual choice is labelled as such' );
lookup_check( false !== strpos( $source, 'from the provider setting.' ), 'and so is the fallback' );

echo "\n[the sentence above the form]\n";

// It used to read the label setting aloud before an id had arrived, so it said
// "Looking up on EasyPost" no matter what was chosen, and never changed.
lookup_check( 1 === substr_count( $page, 'id="lookup-target-line"' ), 'one line above the form, addressable by the script' );
lookup_check( false !== strpos( $source, "'Will look up on Auto" ), 'before a lookup Auto is described, not resolved to a provider' );
lookup_check( false !== strpos( $source, 'Looked up on %1$s (%2$s)' ), 'after one it reports the provider that answered' );
lookup_check( false !== strpos( $source, 'Tried %1$s (%2$s)' ), 'and a failed one says what it tried instead' );
lookup_check( false !== strpos( $source, "'Paste the shipment or transaction id from the order note." ), 'the hint on Auto describes Auto rather than naming a provider' );
lookup_check( false !== strpos( $source, "'easypost' === \$wanted_provider" ), 'the other hints follow the chosen chip, not the setting' );
lookup_check( false !== strpos( $source, 'This page starts on Auto every time' ), 'and it says plainly that the choice is not remembered' );
lookup_check( false !== strpos( $source, 'data-default-provider=' ), 'the track carries the setting, so the script can name it' );
lookup_check( false !== strpos( $source, 'data-default-mode=' ), 'and the mode it is in' );
lookup_check( false !== strpos( $source, 'data-mode-test=' ), 'plus the words the server uses for each mode' );
lookup_check( false !== strpos( $source, 'data-mode-live=' ), 'both of them' );
lookup_check( false !== strpos( $js, "'veeqo' === value" ), 'Veeqo is never given a mode it does not have' );

// Veeqo has one account, so the mode chip is pinned to Live and disabled rather than
// cycling through modes that cannot be chosen.
lookup_check( 4 <= substr_count( $source, '$mode_locked' ), 'Veeqo pins the mode chip instead of cycling it' );
lookup_check( false !== strpos( $source, '$mode_locked ? \'live\' : $wanted_mode' ), 'and the pin is one expression, shared by the field and the chip' );
lookup_check( false !== strpos( $source, 'disabled="disabled"' ), 'the pinned chip is a disabled button, so no click can reach it' );
lookup_check( false !== strpos( $source, 'data-locked-title=' ), 'it carries its reason, translated' );
lookup_check( false !== strpos( $source, 'Veeqo has one account, so its chip pins to Live' ), 'and the same reason is visible under the pill, not only on hover' );

lookup_check( false !== strpos( $js, 'function syncLookupModeChip(' ), 'the script pins and unpins the chip as the provider changes' );
lookup_check( false !== strpos( $js, 'syncLookupModeChip($chip.data(\'value\'))' ), 'from the provider chip that was clicked' );
lookup_check( false !== strpos( $js, '$chip.prop(\'disabled\')' ), 'a pinned chip refuses even a programmatic cycle' );
lookup_check( 2 <= substr_count( $js, 'unlockedMode' ), 'and the mode chosen before the detour is put back afterwards' );

lookup_check( false !== strpos( $css, '.shipping-desk-lookup-mode:disabled' ), 'the pinned chip is styled as pinned, not as broken' );
lookup_check( 1 === substr_count( $page, 'id="shipping-desk-lookup-track"' ), 'the track itself is addressable' );

lookup_check( false !== strpos( $js, "'#lookup-target-line'" ), 'the script rewrites the sentence when the chips move' );
lookup_check( false !== strpos( $js, "'#shipping-desk-lookup-track'" ), 'reading the chips it is about' );
lookup_check( false !== strpos( $js, "'.shipping-desk-lookup-chip, .shipping-desk-lookup-mode'" ), 'on both the provider and the mode' );
lookup_check( false !== strpos( $js, '\u2014 the provider and mode recorded on the order' ), 'and keeps the Auto case qualified' );

echo PHP_EOL . 'Checks: ' . $GLOBALS['lookup_checks'] . ', failures: ' . $GLOBALS['lookup_failures'] . PHP_EOL;

exit( $GLOBALS['lookup_failures'] > 0 ? 1 : 0 );