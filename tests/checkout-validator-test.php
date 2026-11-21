<?php
/**
 * Checks for the checkout validation endpoint: what it refuses to spend a call
 * on, how it keys its cache, and that it cannot block a sale.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. The pure decisions are
 * public static methods precisely so they can be exercised here, and the
 * structural checks pin the promises that have no runtime to test — no submit
 * hook, no name/email/phone collected, one verifier rule for both surfaces.
 *
 * Run:  php tests/checkout-validator-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = null ) {
        return $text;
    }
}

$GLOBALS['cv_filters'] = [];

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value ) {
        return array_key_exists( $hook, $GLOBALS['cv_filters'] )
            ? $GLOBALS['cv_filters'][ $hook ]
            : $value;
    }
}

require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-checkout-address.php';

$GLOBALS['cv_checks']   = 0;
$GLOBALS['cv_failures'] = 0;

function cv_same( $expected, $actual, $label ) {
    $GLOBALS['cv_checks']++;
    if ( $expected === $actual ) {
        echo '  ok    ' . $label . PHP_EOL;
        return;
    }
    $GLOBALS['cv_failures']++;
    echo '  FAIL  ' . $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' . PHP_EOL;
}

function cv_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

echo 'Shipping Desk checkout validation checks' . PHP_EOL;
echo '=========================================' . PHP_EOL;

$full = [
    'line1'        => '504 Circle Dr',
    'line2'        => '',
    'town'         => 'Wharton',
    'county'       => 'TX',
    'postcode'     => '77488',
    'country_code' => 'US',
];

/*
 * The gate order: each answer here is a paid call that does not happen. It is
 * public so it cannot drift from docs/checkout-validation.md unnoticed.
 */
cv_section( 'skip_reason' );

cv_same( '', Shipping_Desk_Checkout_Address::skip_reason( $full ), 'a complete US address is worth checking' );
cv_same( '', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '1 A St', 'postcode' => '77488', 'country_code' => 'US' ] ), 'a street and a five-digit ZIP is enough' );
cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '1 A St', 'postcode' => '4', 'country_code' => 'US' ] ), 'a half-typed ZIP is not a postcode' );
cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '1 A St', 'town' => 'Wharton', 'county' => 'TX', 'country_code' => 'US' ] ), 'city and state without a ZIP is not enough' );
cv_same( '', Shipping_Desk_Checkout_Address::skip_reason( array_merge( $full, [ 'country_code' => 'PR' ] ) ), 'Puerto Rico is in scope' );
cv_same( '', Shipping_Desk_Checkout_Address::skip_reason( array_merge( $full, [ 'country_code' => 'us' ] ) ), 'the country is case-insensitive' );

cv_same( 'country_not_supported', Shipping_Desk_Checkout_Address::skip_reason( array_merge( $full, [ 'country_code' => 'CA' ] ) ), 'another country is never sent' );
cv_same( 'country_not_supported', Shipping_Desk_Checkout_Address::skip_reason( array_merge( $full, [ 'country_code' => '' ] ) ), 'and an unknown country is not guessed at' );

cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '', 'postcode' => '77488', 'country_code' => 'US' ] ), 'no street, nothing to match' );
cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '   ', 'postcode' => '77488', 'country_code' => 'US' ] ), 'whitespace is not a street' );
cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '1 A St', 'country_code' => 'US' ] ), 'no ZIP and no city/state, nothing to match' );
cv_same( 'not_worth_checking', Shipping_Desk_Checkout_Address::skip_reason( [ 'line1' => '1 A St', 'town' => 'Wharton', 'country_code' => 'US' ] ), 'a city without a state is not enough' );

/*
 * The address book. Two customers typing the same place in different shapes must
 * cost one call, and two neighbours must never share a verdict.
 */
cv_section( 'address_book_key' );

$key = Shipping_Desk_Checkout_Address::address_book_key( $full );

cv_same( true, 0 === strpos( $key, 'shipping_desk_co_addr_' ), 'the key is namespaced apart from the admin cache' );
cv_same( strlen( 'shipping_desk_co_addr_' ) + 32, strlen( $key ), 'the key is a fixed-length hash after the prefix' );

cv_same(
    $key,
    Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'line1' => '504 CIRCLE DRIVE.', 'town' => 'WHARTON' ] ) ),
    'case, punctuation and a spelled-out suffix share one entry'
);
cv_same(
    $key,
    Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'postcode' => '77488-1234' ] ) ),
    'a ZIP+4 shares the entry with its 5-digit base'
);
cv_same(
    $key,
    Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'postcode' => '774881234' ] ) ),
    'and so does the unpunctuated form'
);

// The suite's position is part of the key on purpose: folding it in the key
// would let "Apt 5" and "Apt 6" share one answer. A moved suite costs one
// extra check, once.
cv_same(
    false,
    $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'line2' => 'Apt 5' ] ) ),
    'a suite is keyed separately, so two units never share a verdict'
);
cv_same(
    false,
    Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'line2' => 'Apt 5' ] ) )
        === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'line2' => 'Apt 6' ] ) ),
    'and two different suites are two entries'
);

cv_same( false, $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'line1' => '506 Circle Dr' ] ) ), 'a different house number is a different entry' );
cv_same( false, $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'town' => 'El Campo' ] ) ), 'a different city is a different entry' );
cv_same( false, $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'county' => 'LA' ] ) ), 'a different state is a different entry' );
cv_same( false, $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'postcode' => '77489' ] ) ), 'a different routing base is a different entry' );
cv_same( false, $key === Shipping_Desk_Checkout_Address::address_book_key( array_merge( $full, [ 'country_code' => 'CA' ] ) ), 'a different country is a different entry' );

cv_section( 'address_book_ttl' );

cv_same( 7776000, Shipping_Desk_Checkout_Address::address_book_ttl(), 'ninety days by default' );
$GLOBALS['cv_filters']['shipping_desk_address_book_ttl'] = 3600;
cv_same( 3600, Shipping_Desk_Checkout_Address::address_book_ttl(), 'a store can shorten the retention' );
$GLOBALS['cv_filters']['shipping_desk_address_book_ttl'] = 0;
cv_same( 7776000, Shipping_Desk_Checkout_Address::address_book_ttl(), 'a nonsense value falls back rather than disabling the cache' );
unset( $GLOBALS['cv_filters']['shipping_desk_address_book_ttl'] );

/*
 * Responses. Both "nothing to say" shapes are what the browser keys off, so their
 * vocabulary is part of the contract.
 */
cv_section( 'responses' );

$skipped = Shipping_Desk_Checkout_Address::skipped( 'country_not_supported', $full );
cv_same( 'skipped', $skipped['status'], 'a skipped address says so' );
cv_same( 'country_not_supported', $skipped['reason'], 'and why' );
cv_same( [], $skipped['recommended'], 'with nothing to apply' );
cv_same( false, $skipped['is_valid'], 'and no claim of validity' );
cv_same( false, $skipped['cached'], 'nothing was cached' );
cv_same( true, '' !== $skipped['original_line'], 'the address it was given is echoed back for the record' );

$unavailable = Shipping_Desk_Checkout_Address::unavailable( 'throttled' );
cv_same( 'unavailable', $unavailable['status'], 'a throttled check is unavailable, not an error' );
cv_same( 'throttled', $unavailable['reason'], 'and says so' );
cv_same( [], $unavailable['recommended'], 'with nothing to apply' );

cv_section( 'describe_address_validation' );

$described = Shipping_Desk_Helpers::describe_address_validation(
    [
        'status'      => 'corrected',
        'changed'     => [ 'postal_code' ],
        'original'    => $full,
        'recommended' => array_merge( $full, [ 'postcode' => '77488-1234' ] ),
    ],
    'easypost'
);
cv_same( 'ZIP+4 added', $described['summary'], 'the change is named in words' );
cv_same( 'easypost', $described['validator'], 'and the verifier is recorded' );
cv_same( true, false !== strpos( $described['original_line'], '77488' ), 'the address as entered is formatted' );
cv_same( true, false !== strpos( $described['recommended_line'], '77488-1234' ), 'and so is the recommendation' );

/*
 * Structural promises. These have no runtime a standalone gate can drive, and
 * each one is something the design says must not happen.
 */
cv_section( 'source guarantees' );

$class_source   = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-checkout-address.php' );
// The plugin and the settings class together: the verification field and its registration moved.
$plugin_source  = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' )
	. "\n" . (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-settings.php' );
$uninstall      = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
// tools/ is internal and is never copied into the published tree, so the manifest
// checks run only where a manifest exists. In the built tree the files are there
// by definition — the file_exists() checks are what matter there.
$manifest_path  = dirname( __DIR__ ) . '/tools/build-public.php';
$build_manifest = is_file( $manifest_path ) ? (string) file_get_contents( $manifest_path ) : '';

$manifest_same = function ( $needle, $label ) use ( $build_manifest ) {
    if ( '' === $build_manifest ) {
        echo '  ..    ' . $label . ' (skipped: published tree has no build manifest)' . PHP_EOL;
        return;
    }

    cv_same( 1, substr_count( $build_manifest, $needle ), $label );
};

cv_same( 1, substr_count( $class_source, 'wp_ajax_shipping_desk_validate_checkout_address' ), 'registered for logged-in customers' );
cv_same( 1, substr_count( $class_source, 'wp_ajax_nopriv_shipping_desk_validate_checkout_address' ), 'and for guests, which is the normal checkout' );
cv_same( false, strpos( $class_source, 'woocommerce_after_checkout_validation' ), 'never hooks checkout validation' );
cv_same( false, strpos( $class_source, '$errors->add' ), 'and never adds a checkout error' );
cv_same( false, strpos( $class_source, "'phone'" ), 'never collects a phone number' );
cv_same( false, strpos( $class_source, "'email'" ), 'never collects an email address' );
cv_same( true, false !== strpos( $class_source, 'Shipping_Desk_Helpers::describe_address_validation' ), 'renders through the same wording as the admin screen' );
cv_same( true, false !== strpos( $plugin_source, 'Shipping_Desk_Helpers::describe_address_validation' ), 'and so does the admin screen' );

cv_same( false, strpos( $plugin_source, 'function address_validator' ), 'one verifier rule, not two' );
cv_same( true, substr_count( $plugin_source, 'validator_client' ) >= 3, 'the rule is defined once and called by both surfaces' );
cv_same( 1, substr_count( $plugin_source, "register_setting( 'shipping_desk_api_settings', 'shipping_desk_checkout_validation'" ), 'the switch saves with the provider form' );
cv_same( true, substr_count( $plugin_source, "'shipping_desk_verifier'" ) >= 2, 'the verifier choice is registered and read' );

cv_same( 1, substr_count( $uninstall, "'shipping_desk_checkout_validation'" ), 'uninstall removes the switch' );
cv_same( 1, substr_count( $uninstall, "'shipping_desk_verifier'" ), 'and the verifier choice' );

$manifest_same( "'includes/class-checkout-address.php'", 'the class ships' );
$manifest_same( "'tests/checkout-validator-test.php'", 'and so does this gate' );

/*
 * What the browser is handed to write. The fold rule has to reach the client, or
 * accepting a suggestion prints the suite twice — the bug the admin path fixed in
 * 1.3.12 must not come back through a second door.
 */
cv_section( 'apply payload' );

$folded = Shipping_Desk_Helpers::describe_address_validation(
    [
        'status'      => 'corrected',
        'changed'     => [ 'address_line_1', 'address_line_2' ],
        'original'    => [
            'line1'        => '123 Main St',
            'line2'        => 'Apt 5',
            'town'         => 'Wharton',
            'county'       => 'TX',
            'postcode'     => '77488',
            'country_code' => 'US',
        ],
        'recommended' => [
            'line1'        => '123 MAIN ST APT 5',
            'town'         => 'WHARTON',
            'county'       => 'TX',
            'postcode'     => '77488-1234',
            'country_code' => 'US',
        ],
    ],
    'easypost'
);
cv_same( '', $folded['apply']['line2'], 'the browser is told to clear line 2 when the suite moved into line 1' );
cv_same( '77488-1234', $folded['apply']['postcode'], 'and given the standardised postcode' );
cv_same( [ 'street format corrected', 'suite folded into line 1' ], $folded['changes'], 'the change list travels as phrases, not a joined string' );

// The other direction: a verifier that moves the suite out to line 2. The field
// name is the same either way, so the wording has to follow what actually
// happened, not the name.
$moved = Shipping_Desk_Helpers::describe_address_validation(
    [
        'status'      => 'corrected',
        'changed'     => [ 'address_line_1', 'address_line_2' ],
        'original'    => [
            'line1'        => '100 Example Street Apt 4B',
            'line2'        => '',
            'town'         => 'Springfield',
            'county'       => 'IL',
            'postcode'     => '62701',
            'country_code' => 'US',
        ],
        'recommended' => [
            'line1'        => '100 EXAMPLE ST',
            'line2'        => 'APT 4B',
            'town'         => 'SPRINGFIELD',
            'county'       => 'IL',
            'postcode'     => '62701-1234',
            'country_code' => 'US',
        ],
    ],
    'easypost'
);
cv_same( [ 'street format corrected', 'suite moved to line 2' ], $moved['changes'], 'a suite moved out to line 2 is described as that' );
cv_same( 'APT 4B', $moved['apply']['line2'], 'and applied there' );

$nothing = Shipping_Desk_Helpers::describe_address_validation( [ 'status' => 'invalid', 'original' => $full, 'recommended' => [] ] );
cv_same( [], $nothing['apply'], 'an invalid address offers nothing to apply' );
cv_same( '', $nothing['recommended_line'], 'and shows no suggestion line' );

/*
 * The browser half. Structural, because a standalone gate cannot run a checkout —
 * but each assertion here is a decision from the design, not a style preference.
 */
cv_section( 'the browser half' );

$script = dirname( __DIR__ ) . '/public/js/checkout-address.js';
$style  = dirname( __DIR__ ) . '/public/css/checkout-address.css';

cv_same( true, file_exists( $script ), 'the script exists where the enqueue points' );
cv_same( true, file_exists( $style ), 'and so does the stylesheet' );
$manifest_same( "'public/js/checkout-address.js'", 'the script ships' );
$manifest_same( "'public/css/checkout-address.css'", 'the stylesheet ships' );
cv_same( 1, substr_count( $class_source, "add_action( 'wp_enqueue_scripts', [ \$this, 'enqueue' ] )" ), 'the browser half is enqueued, once' );
cv_same( 1, substr_count( $class_source, 'is_wc_endpoint_url' ), 'and not on the order-received page' );

$js  = (string) file_get_contents( $script );
$css = (string) file_get_contents( $style );

cv_same( false, strpos( $js, 'innerHTML' ), 'never builds markup from data' );
cv_same( 1, substr_count( $js, ".on('change', SELECTOR, schedule)" ), 'one change handler, bound once' );
cv_same( 1, substr_count( $js, ".on('updated_checkout', restore)" ), 'updated_checkout restores the panel, it never re-binds' );
cv_same( true, false !== strpos( $js, "trigger('update_checkout')" ), 'accepting and undoing both re-price the shipping' );
cv_same( false, strpos( $js, 'after_checkout_validation' ), 'never touches the submit path' );
cv_same( true, false !== strpos( $js, 'aria-live' ), 'announces itself without stealing focus' );
cv_same( true, false !== strpos( $js, '.text(' ), 'renders addresses as text' );
cv_same( true, false !== strpos( $js, 'shipping-desk-checkout-address__undo' ), 'accepting can be undone' );
cv_same( true, false !== strpos( $js, 'state.confirmed' ), 'and the confirmation is redrawn after the re-render its own accept caused' );

cv_same( true, false !== strpos( $css, '#dba617' ), 'the suggested card uses the admin attention palette' );
cv_same( false, strpos( $css, '#fdecea' ), 'the original card is not tinted like an error' );
cv_same( false, strpos( $css, 'color: red' ), 'and nothing here is literal red' );

/*
 * The book stores what a verifier said about a place; the verdict belongs to the
 * question. Replaying a stored answer verbatim let one customer's spelling decide
 * what the next customer was told — an address came back "valid" for a spelling
 * that plainly differed from the match.
 */
cv_section( 'address_changes_between' );

$typed = [
    'line1'        => '501 east maple avenue',
    'line2'        => '',
    'town'         => 'MONROVIA',
    'county'       => 'CA',
    'postcode'     => '91016',
    'country_code' => 'US',
];

cv_same(
    [],
    Shipping_Desk_Helpers::address_changes_between( $typed, [ 'line1' => '501 EAST MAPLE AVENUE', 'town' => 'MONROVIA', 'county' => 'CA', 'postcode' => '91016' ] ),
    'case alone is not a change'
);
cv_same(
    [ 'address_line_1', 'postal_code' ],
    Shipping_Desk_Helpers::address_changes_between( $typed, [ 'line1' => '501 E MAPLE AVE', 'postcode' => '91016-3619' ] ),
    'a standardised street and an added ZIP+4 are changes against the verbose spelling'
);
cv_same( [], Shipping_Desk_Helpers::address_changes_between( $typed, [] ), 'an empty recommendation changes nothing' );
cv_same(
    [],
    Shipping_Desk_Helpers::address_changes_between(
        [ 'line1' => '123 Main St', 'line2' => 'Apt 5', 'town' => 'Wharton', 'county' => 'TX', 'postcode' => '77488', 'country_code' => 'US' ],
        [ 'line1' => '123 MAIN ST', 'town' => 'WHARTON', 'county' => 'TX', 'postcode' => '77488', 'country_code' => 'US' ]
    ),
    'a field the verifier did not return is not a change'
);

/*
 * The checkout offers every correction, including the ones too small to stop a
 * quote for. That is deliberate and it is not the materiality rule: a ZIP+4 or a
 * folded suite still makes a better stored address, and the order is written from
 * the fields the customer accepted. Materiality only decides whether the *admin*
 * gate demands a click before it will quote.
 */
$minor = Shipping_Desk_Helpers::describe_address_validation(
    [
        'status'      => 'corrected',
        'recommended' => [ 'line1' => '123 MAIN ST APT 5', 'line2' => '', 'postcode' => '77488-1234' ],
        'changed'     => [ 'address_line_1', 'address_line_2', 'postal_code' ],
    ],
    'easypost'
);

cv_same( '123 MAIN ST APT 5', $minor['apply']['line1'], 'a folded suite is put into line 1' );
cv_same( '', $minor['apply']['line2'], 'and cleared from line 2, so the label cannot print it twice' );
cv_same( '77488-1234', $minor['apply']['postcode'], 'a ZIP+4 is offered like any other change' );
cv_same( true, '' !== $minor['recommended_line'], 'so the customer is shown a suggestion for it' );
cv_same(
    false,
    Shipping_Desk_Helpers::address_change_is_material(
        [ 'line1' => '123 Main St', 'line2' => 'Apt 5', 'town' => 'Wharton', 'county' => 'TX', 'postcode' => '77488', 'country_code' => 'US' ],
        [ 'line1' => '123 MAIN ST APT 5', 'line2' => '', 'postcode' => '77488-1234' ]
    ),
    'even though the admin gate would not stop the quote for it'
);

cv_section( 'cache replay' );

$replay = new ReflectionMethod( 'Shipping_Desk_Checkout_Address', 'replay_cached' );
$replay->setAccessible( true );

// Exactly the shape that produced the bug: written from an already standardised
// form, so its own "changed" list is empty.
$stored = [
    'status'           => 'valid',
    'is_valid'         => true,
    'recommended'      => [ 'line1' => '501 E MAPLE AVE', 'line2' => '', 'town' => 'MONROVIA', 'county' => 'CA', 'postcode' => '91016-3619', 'country_code' => 'US' ],
    'changed'          => [],
    'reasons'          => [],
    'address_type'     => 'residential',
    'confidence'       => [],
    'error'            => '',
    'validator'        => 'easypost',
    'original'         => [ 'line1' => '501 E MAPLE AVE', 'postcode' => '91016-3619', 'country_code' => 'US' ],
    'summary'          => '',
    'original_line'    => '501 E MAPLE AVE, MONROVIA, CA 91016-3619',
    'recommended_line' => '501 E MAPLE AVE, MONROVIA, CA 91016-3619',
    'apply'            => [ 'line1' => '501 E MAPLE AVE', 'postcode' => '91016-3619' ],
    'cached'           => false,
];

$hit = $replay->invoke( null, $stored, $typed );
cv_same( 'corrected', $hit['status'], 'a cache hit is re-judged against the address in hand' );
cv_same( true, in_array( 'address_line_1', $hit['changed'], true ), 'so the verbose spelling is reported as a change' );
cv_same( '501 east maple avenue', $hit['original']['line1'], "and the answer describes the caller's own input" );
cv_same( '501 E MAPLE AVE', $hit['apply']['line1'], 'the correction still comes from the stored match' );
cv_same( true, $hit['cached'], 'and it is still known to be a cache hit' );

$clean = $replay->invoke( null, $stored, [ 'line1' => '501 E MAPLE AVE', 'line2' => '', 'town' => 'MONROVIA', 'county' => 'CA', 'postcode' => '91016-3619', 'country_code' => 'US' ] );
cv_same( 'valid', $clean['status'], 'the already-standardised form comes back valid, as it should' );
cv_same( [], $clean['changed'], 'with nothing to change' );

$invalid_hit = $replay->invoke( null, [ 'status' => 'invalid', 'recommended' => [], 'reasons' => [ [ 'code' => 'E.ADDRESS.NOT_FOUND' ] ], 'validator' => 'easypost' ], $typed );
cv_same( 'invalid', $invalid_hit['status'], 'an invalid verdict is replayed as it stands' );
cv_same( true, $invalid_hit['cached'], 'and marked as a hit' );

cv_same( 2, substr_count( $class_source, 'replay_cached' ), 'the endpoint replays a cache hit, it never returns it verbatim' );

/*
 * Settings placement and wording. The block governs the admin gate and checkout
 * whichever provider buys labels, so it must not sit inside the EasyPost block;
 * and the two controls must not contradict each other on screen.
 */
cv_section( 'settings placement' );

cv_same( 2, substr_count( $plugin_source, "'shipping_desk_verification_section'" ), 'verification has its own section, with the field in it' );
cv_same( false, strpos( $plugin_source, "'render_checkout_validation_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section'" ), 'nothing registers it into the EasyPost block any more' );
cv_same( true, false !== strpos( $plugin_source, 'Off — no address is checked anywhere' ), 'Off says it covers everything, not just checkout' );
cv_same( true, false !== strpos( $plugin_source, 'Also offer suggestions on the checkout page' ), 'the checkbox is about where, not whether' );
cv_same( true, false !== strpos( $plugin_source, 'disabled( $disabled )' ), 'and it is disabled while Off, so it cannot be ticked and inert' );

cv_same( false, strpos( $plugin_source, 'Veeqo API Settings' ), 'Veeqo is not singled out under a heading of its own' );
cv_same( true, false !== strpos( $plugin_source, "'render_api_key_field' ], 'shipping_desk_api_settings', 'shipping_desk_provider_section'" ), 'its key sits at the same level as the Shippo and EasyPost keys' );

cv_same( true, false !== strpos( $js, 'sessionStorage' ), 'the undo is remembered across a reload' );
cv_same( true, false !== strpos( $js, 'shippingDeskAddressUndo' ), 'under its own key' );
cv_same( true, false !== strpos( $js, 'restoreUndo();' ), 'and restored when the page loads' );
cv_same( 2, substr_count( $js, 'fingerprint(saved.applied)' ), 'a change event that leaves the applied address alone does not discard it' );
cv_same( true, false !== strpos( $js, 'dropStaleSuggestion' ), 'and a stale suggestion is cleared without taking the confirmation with it' );
cv_same( true, substr_count( $js, 'dropStaleSuggestion' ) >= 4, 'stale suggestions are dropped wherever the target can change' );
cv_same( true, substr_count( $js, 'ship-to-different-address-checkbox' ) >= 2, 'ticking ship-to-a-different-address re-evaluates which address is the destination' );
cv_same( true, substr_count( $js, "is(':visible')" ) >= 2, 'and a restored bar is never drawn into a hidden address block' );
cv_same( true, false !== strpos( $js, 'appendAddress' ), 'the suggestion is rendered field by field' );
cv_same( true, false !== strpos( $js, "\$('<strong>')" ), 'so the changed parts can be emphasised' );

cv_same( true, false !== strpos( $js, 'renderUnconfirmed' ), 'an unmatchable address gets a line of its own' );
cv_same( true, false !== strpos( $js, 'is-unconfirmed' ), 'in the same amber, not an error state' );
cv_same( 1, substr_count( $js, 'shipping-desk-checkout-address__accept' ), 'and there is still exactly one place that offers to accept' );
cv_same( true, false !== strpos( $class_source, "'unconfirmed'" ), 'the wording is translated, not hard-coded in the script' );
cv_same( true, false !== strpos( $css, '.is-unconfirmed' ), 'and the note is styled' );

echo PHP_EOL . 'Checks: ' . $GLOBALS['cv_checks'] . ', failures: ' . $GLOBALS['cv_failures'] . PHP_EOL;
exit( 0 === $GLOBALS['cv_failures'] ? 0 : 1 );
