<?php
/**
 * Gate for the settings forms: one group per form, and no silently wiped options.
 *
 * WordPress saves a *settings group*, not a form: options.php iterates every option
 * registered to the submitted group and calls update_option( $option, $value ), where
 * $value is null when the field is absent from the POST. Two forms sharing one group
 * therefore wipe each other — which is how saving a checkbox once erased the provider
 * and every API key.
 *
 * This is a source-level check, so it needs no WordPress. It reads class-plugin.php and
 * asserts the invariants that failure violated:
 *
 *   1. each settings group is submitted by exactly one form
 *   2. every registered option is reachable from a field, so no option can be nulled by
 *      a page that has no way to display it (the legacy EasyPost key case)
 *   3. every checkbox has a hidden value="0" companion, so unticking posts an explicit
 *      value instead of nothing
 *
 * Run:  php tests/settings-forms-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

$GLOBALS['form_checks']   = 0;
$GLOBALS['form_failures'] = 0;

function form_check( $condition, $label, $detail = '' ) {
	$GLOBALS['form_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['form_failures']++;
	echo "  FAIL  {$label}" . ( '' !== $detail ? ' - ' . $detail : '' ) . "\n";
}

$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );

echo "Shipping Desk settings form checks\n";
echo "=================================\n";

/* -- Collect the facts ------------------------------------------------------ */

// Registered option => group.
$registered = [];
preg_match_all( '/register_setting\(\s*\'([a-z_]+)\'\s*,\s*\'([a-z_]+)\'/', $source, $reg, PREG_SET_ORDER );
foreach ( $reg as $row ) {
	$registered[ $row[2] ] = $row[1];
}

// Forms, the group each submits, and the fields inside them.
$forms = [];
preg_match_all( '/<form\b[^>]*>(.*?)<\/form>/s', $source, $matches, PREG_SET_ORDER );
foreach ( $matches as $form ) {
	$body = $form[1];
	if ( ! preg_match( '/settings_fields\(\s*\'([a-z_]+)\'\s*\)/', $body, $group ) ) {
		continue; // A plain form (lookup, import upload) that does not save options.
	}

	$names = [];
	preg_match_all( '/name="([a-z_]+)/', $body, $found );
	foreach ( $found[1] as $name ) {
		$names[ $name ] = true;
	}

	$checkboxes = [];
	preg_match_all( '/type="checkbox"[^>]*?name="([a-z_]+)"/s', $body, $found );
	foreach ( $found[1] as $name ) {
		$checkboxes[ $name ] = true;
	}

	$hiddens = [];
	preg_match_all( '/type="hidden"[^>]*?name="([a-z_]+)"[^>]*?value="0"/s', $body, $found );
	foreach ( $found[1] as $name ) {
		$hiddens[ $name ] = true;
	}

	$forms[] = [
		'group'      => $group[1],
		'names'      => $names,
		'checkboxes' => $checkboxes,
		'hiddens'    => $hiddens,
	];
}

echo "\n[one group per form]\n";

$by_group = [];
foreach ( $forms as $form ) {
	$by_group[ $form['group'] ][] = $form;
}

form_check( ! empty( $forms ), 'the settings page has forms that save options' );

foreach ( $by_group as $group => $set ) {
	form_check(
		1 === count( $set ),
		sprintf( '%s is submitted by one form', $group ),
		sprintf( '%d forms share it, so saving one would wipe the others', count( $set ) )
	);
}

/* -- Options that must never be registered ---------------------------------- */

echo "\n[the legacy key stays out of the forms]\n";

// The one-time upgrade key has no field on the page. While it was registered, every save
// of that group nulled it before maybe_migrate_easypost_key() could copy it.
form_check(
	! isset( $registered['shipping_desk_easypost_api_key'] ),
	'the legacy EasyPost key is not registered to any settings group',
	'registered without a field, so any save of that group erases it'
);

form_check(
	false !== strpos( $source, "get_option( 'shipping_desk_easypost_api_key'" ),
	'the legacy key is still read by the upgrade migration'
);

/* -- The origin address saves on its own ------------------------------------ */

echo "\n[the address has its own group]\n";

form_check(
	isset( $registered['shipping_desk_from_address'] ) && 'shipping_desk_from_settings' === $registered['shipping_desk_from_address'],
	'the origin address is registered to its own settings group',
	'shared with the API keys, so saving an address would re-post every key'
);

/* -- Every registered option has a field ----------------------------------- */

echo "\n[registered options have fields]\n";

// Field names as they appear in markup, including array-style names such as
// shipping_desk_from_address[city]. Dynamic rows (the box table) are built in JS, so
// admin.js is scanned too.
$markup = $source . (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
preg_match_all( '/name="([a-z0-9_]+)(?:\[[^"]*)?"/i', $markup, $field_names );
$field_names = array_flip( $field_names[1] );

foreach ( $registered as $option => $group ) {
	form_check(
		isset( $field_names[ $option ] ),
		sprintf( '%s has a field in the page', $option ),
		'registered without a matching field name, so any save of its group stores null'
	);
}

/* -- Password managers stay out of the keys --------------------------------- */

echo "\n[password managers cannot quietly replace a key]\n";

form_check(
	false !== strpos( $source, 'autocomplete="new-password"' ) && false !== strpos( $source, 'data-1p-ignore' ),
	'the key fields ask password managers to stay out',
	'autocomplete="off" alone is ignored on password inputs'
);

form_check(
	substr_count( $source, '$this->api_key_attributes()' ) >= 5,
	'every key field uses those attributes',
	'a field without them is the one a manager fills'
);

$admin_js = (string) file_get_contents( dirname( __DIR__ ) . '/admin/js/admin.js' );
form_check(
	false !== strpos( $admin_js, '.shipping-desk-api-key' ) && false !== strpos( $admin_js, 'password manager' ),
	'a submit that would overwrite an untouched key asks first'
);

/* -- Checkboxes post a value even when unticked ----------------------------- */

echo "\n[checkboxes post zero]\n";

foreach ( $forms as $form ) {
	foreach ( array_keys( $form['checkboxes'] ) as $name ) {
		form_check(
			isset( $form['hiddens'][ $name ] ),
			sprintf( '%s has a hidden zero in its own form', $name ),
			'an unticked box posts nothing, which update_option() stores as null'
		);
	}
}

/* -- The registered options and the forms agree ----------------------------- */

echo "\n[groups and options line up]\n";

foreach ( $forms as $form ) {
	$group   = $form['group'];
	$members = array_keys( array_filter( $registered, function ( $g ) use ( $group ) {
		return $g === $group;
	} ) );

	form_check( ! empty( $members ), sprintf( '%s has registered options', $group ), 'nothing registered, so saving it does nothing' );
}

// Note: options rendered through do_settings_sections() callbacks cannot be attributed to
// a form from the source text, so this check stops at "the group has options". The
// structural rule above - one group per form - is what prevents the wipe.

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['form_checks'], $GLOBALS['form_failures'] );
exit( $GLOBALS['form_failures'] > 0 ? 1 : 0 );
