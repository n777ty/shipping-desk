<?php
/**
 * Gate for the rate column's price rendering.
 *
 * admin/js/admin.js is jQuery glue with no module boundary, so it cannot be loaded
 * here. What this checks is the wiring that already went wrong once: the totals pass
 * must run when the rates are rendered, not only when the Insurance field changes.
 *
 * A source check cannot prove the browser executes it. It does catch the call being
 * dropped, which is the regression that hid EasyPost's insurance premium: renderRates()
 * wrote the carrier's price alone, and the field's change handler ignores a value that
 * has not moved since the quote — so the normal order of work (type the value, then
 * press Get Rates) never showed a premium.
 *
 * Run:  php tests/rate-column-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['rate_checks']   = 0;
$GLOBALS['rate_failures'] = 0;

function rate_check( $condition, $label ) {
	$GLOBALS['rate_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['rate_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * Text of one top-level function inside the admin IIFE.
 *
 * Bounded by the next four-space closing brace rather than by brace counting: the
 * bodies here contain strings and comments that a naive counter would trip over.
 *
 * @param string $js        File contents.
 * @param string $signature Opening line, as written in the file.
 * @return string Body, or '' when the signature is not found.
 */
function rate_function_body( $js, $signature ) {
	$start = strpos( $js, $signature );
	if ( false === $start ) {
		return '';
	}

	$end = strpos( $js, "\n    }\n", $start );

	return false === $end ? '' : substr( $js, $start, $end - $start );
}

$js_path = dirname( __DIR__ ) . '/admin/js/admin.js';
// Normalised: the file is checked out with CRLF on Windows, and the boundaries below
// are written against bare newlines.
$js = str_replace( "\r\n", "\n", (string) file_get_contents( $js_path ) );

echo "Shipping Desk rate column checks\n";
echo "================================\n\n";

echo "[the file]\n";
rate_check( false !== $js && '' !== $js, 'admin/js/admin.js is readable' );

echo "\n[rendering rates]\n";

$render = rate_function_body( $js, 'function renderRates($row, rates, matchedRateId) {' );
rate_check( '' !== $render, 'renderRates() is present' );
rate_check( false !== strpos( $render, 'shipping-desk-rate-price' ), 'it writes the price element' );
rate_check(
	false !== strpos( $render, 'refreshRateTotals($row)' ),
	'it restates the totals through refreshRateTotals(), so the premium is included'
);

echo "\n[the standardised-address offer]\n";

$badge = rate_function_body( $js, 'function renderAddressBadge($row, validation) {' );
rate_check( '' !== $badge, 'renderAddressBadge() is present' );
rate_check(
	false !== strpos( $badge, "validation.status === 'corrected'" ),
	'a correction below the materiality bar is reported rather than silent'
);
rate_check(
	false !== strpos( $badge, 'shipping-desk-use-standardised' ),
	'and it carries a one-click way to take it'
);
rate_check(
	false !== strpos( $badge, '!validation.applied' ),
	'the offer is withdrawn once a choice has been applied'
);
rate_check(
	false !== strpos( $js, "'.shipping-desk-use-standardised'" ),
	'the button is wired to a handler'
);
rate_check(
	false !== strpos( $js, "data('address-choice', 'suggested')" ),
	'which sends the same address_choice the decision panel sends'
);
rate_check(
	false !== strpos( $js, "removeData('address-choice')" ),
	'and clears it, so a later quote does not rewrite the order unasked'
);

echo "\n[the insurance premium]\n";

$premium = rate_function_body( $js, 'function insurancePremium($row) {' );
rate_check( '' !== $premium, 'insurancePremium() is present' );
rate_check( false !== strpos( $premium, "!== 'easypost'" ), 'only EasyPost charges cover after the quote' );
rate_check( false !== strpos( $premium, 'Math.max(1, Math.round(amount) / 100)' ), 'one cent per dollar, one dollar minimum' );
rate_check( false !== strpos( $premium, 'liability-amount' ), 'the declared value comes from the Insurance field' );

echo "\n[the Insurance field]\n";

$handler = rate_function_body( $js, "$(document).on('change', '.shipping-desk-liability-amount', function () {" );
rate_check( '' !== $handler, 'the field has a change handler' );
rate_check( false !== strpos( $handler, 'refreshRateTotals($row)' ), 'a changed value restates the totals' );
rate_check( false !== strpos( $handler, 'resetRates(' ), 'and invalidates the quotes where the provider prices cover into them' );

echo "\n[what the premium was based on]\n";

$totals = rate_function_body( $js, 'function refreshRateTotals($row) {' );
rate_check( '' !== $totals, 'refreshRateTotals() is present' );
rate_check(
	false !== strpos( $totals, 'data-insurance-premium' ),
	'it records the premium on the row, so a missing estimate can be traced to its cause'
);

$typing = rate_function_body( $js, "$(document).on('input', '.shipping-desk-liability-amount', function () {" );
rate_check( '' !== $typing, 'typing in the field updates the totals' );
rate_check( false !== strpos( $typing, "!== 'easypost'" ), 'and only where cover is priced after the quote' );
rate_check( false !== strpos( $typing, 'refreshLiabilityEstimate($row)' ), 'and restates the estimate beside the field' );

echo "\n[the estimate beside the field]\n";

$estimate = rate_function_body( $js, 'function refreshLiabilityEstimate($row) {' );
rate_check( '' !== $estimate, 'refreshLiabilityEstimate() is present' );
rate_check( false !== strpos( $estimate, 'No insurance' ), 'an EasyPost row says when nothing is insured' );
rate_check( false !== strpos( $estimate, 'priced into the rate' ), 'a Shippo row says why there is no figure to add' );
rate_check(
	false !== strpos( (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' ), 'shipping-desk-liability-estimate' ),
	'the field is rendered with somewhere to put that sentence'
);

echo "\n[the reship panel]\n";

rate_check(
	false !== strpos( $js, 'attrPattern.exec(resp.data.attrs)' ),
	'the panel copies every data-* attribute from the rendered row, not a hand-picked few'
);

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['rate_checks'], $GLOBALS['rate_failures'] );
exit( $GLOBALS['rate_failures'] > 0 ? 1 : 0 );
