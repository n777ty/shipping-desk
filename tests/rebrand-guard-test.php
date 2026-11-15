<?php
/**
 * Guard against the rebrand leaking backwards.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. It reads the shipped files as text
 * and fails if the pre-rebrand names reappear, if the version disagrees with itself, or
 * if a stored key escapes the plugin's namespace.
 *
 * The distinction that matters: `veeqo` is still legitimate as a *provider* — the slug
 * `'veeqo'`, `api.veeqo.com`, the `woo_veeqo_*` options of a predecessor plugin, and
 * prose naming the carrier. What must never return is `veeqo` as the *plugin's*
 * identity: `Veeqo_Shipping_*`, `veeqo_shipping_*`, `_veeqo_*`, `veeqo-shipping`.
 *
 * Run:  php tests/rebrand-guard-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 *
 * @package ShippingDesk
 */

$GLOBALS['guard_checks']   = 0;
$GLOBALS['guard_failures'] = 0;

/**
 * Record one check.
 *
 * @param bool   $passed Whether it passed.
 * @param string $label  What was checked.
 * @param string $detail Detail shown on failure.
 * @return void
 */
function guard_check( $passed, $label, $detail = '' ) {
    $GLOBALS['guard_checks']++;

    if ( $passed ) {
        echo '  ok    ' . $label . PHP_EOL;
        return;
    }

    $GLOBALS['guard_failures']++;
    echo '  FAIL  ' . $label . ( '' !== $detail ? ' — ' . $detail : '' ) . PHP_EOL;
}

/**
 * Compare two values.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    What was checked.
 * @return void
 */
function guard_same( $expected, $actual, $label ) {
    guard_check(
        $expected === $actual,
        $label,
        'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
    );
}

/**
 * Section heading.
 *
 * @param string $title Title.
 * @return void
 */
function guard_section( $title ) {
    echo PHP_EOL . '[' . $title . ']' . PHP_EOL;
}

$guard_root = dirname( __DIR__ );

/**
 * Files that ship in the release zip — the only files these checks police.
 *
 * @return string[] Absolute paths.
 */
function guard_shipped_files() {
    global $guard_root;

    return array_merge(
        [ $guard_root . '/shipping-desk.php', $guard_root . '/uninstall.php' ],
        glob( $guard_root . '/includes/*.php' ),
        glob( $guard_root . '/admin/js/*.js' ),
        glob( $guard_root . '/admin/css/*.css' )
    );
}

/**
 * Every occurrence of a pattern in the shipped files, as "file:line  text".
 *
 * @param string   $pattern PCRE without delimiters.
 * @param string[] $files   Files to scan; defaults to the shipped set.
 * @return string[]
 */
function guard_find( $pattern, $files = null ) {
    $hits = [];

    foreach ( null === $files ? guard_shipped_files() : $files as $file ) {
        foreach ( file( $file ) as $number => $line ) {
            if ( preg_match( '#' . $pattern . '#', $line ) ) {
                $hits[] = basename( $file ) . ':' . ( $number + 1 ) . '  ' . trim( $line );
            }
        }
    }

    return $hits;
}

/**
 * Files that go into the public repository: the shipped set plus the tests.
 *
 * The tests are not in the release zip, but they are published, so a stale fixture
 * naming an old option reads as a leftover to anyone reading the repo.
 *
 * @return string[]
 */
function guard_publishable_files() {
    global $guard_root;

    $files = array_merge( guard_shipped_files(), glob( $guard_root . '/tests/*.php' ) );

    // This file is the one place allowed to name what it forbids: its patterns are the
    // subject, not a use. Everything else is scanned.
    return array_values( array_filter( $files, function ( $file ) {
        return 'rebrand-guard-test.php' !== basename( $file );
    } ) );
}

/**
 * Content of one shipped file.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function guard_read( $relative ) {
    global $guard_root;

    return (string) file_get_contents( $guard_root . '/' . $relative );
}

echo 'Shipping Desk rebrand guard' . PHP_EOL;
echo '===========================' . PHP_EOL;

/* -- The old identity ------------------------------------------------ */

guard_section( 'old identity is gone' );

$hits = guard_find( 'Veeqo_Shipping_', guard_publishable_files() );
guard_same( [], $hits, 'no Veeqo_Shipping_ class or interface prefix (code and tests)' );

$hits = guard_find( 'veeqo_shipping_', guard_publishable_files() );
guard_same( [], $hits, 'no veeqo_shipping_ option or transient prefix (code and tests)' );

// Quoted meta literals only: the predecessor plugin's `'woo_veeqo_*'` names are history
// that must stay, and they never start with a quote-then-underscore.
$hits = guard_find( "'_veeqo_", guard_publishable_files() );
guard_same( [], $hits, 'no _veeqo_ order meta key (code and tests)' );

$hits = guard_find( 'veeqo-shipping', guard_publishable_files() );
guard_same( [], $hits, 'no veeqo-shipping slug, handle, class prefix or text domain' );

$hits = guard_find( 'VeeqoShipping', guard_publishable_files() );
guard_same( [], $hits, 'no VeeqoShipping JS global' );

$hits = guard_find( 'VEEQO_SHIPPING_VERSION', guard_publishable_files() );
guard_same( [], $hits, 'no VEEQO_SHIPPING_VERSION constant' );

/* -- The new identity ------------------------------------------------ */

guard_section( 'new identity is in place' );

$header = guard_read( 'shipping-desk.php' );
guard_check( false !== strpos( $header, 'Plugin Name: Shipping Desk' ), 'the header names Shipping Desk' );
guard_check( false !== strpos( $header, 'Text Domain: shipping-desk' ), 'the header declares the shipping-desk text domain' );
guard_check( false !== strpos( $header, 'License:     GPL-2.0-or-later' ), 'the header declares GPL-2.0-or-later' );
guard_check( file_exists( $guard_root . '/LICENSE' ), 'LICENSE sits at the plugin root' );
guard_check( file_exists( $guard_root . '/readme.txt' ), 'readme.txt sits at the plugin root' );
guard_check( ! file_exists( $guard_root . '/veeqo-shipping.php' ), 'the old plugin file is gone' );

// The header's Domain Path is /languages, so a .pot anywhere else is invisible to
// WordPress — and to wp.org, which reads it from there.
$pot = $guard_root . '/languages/shipping-desk.pot';
guard_check( file_exists( $pot ), 'the template lives at languages/shipping-desk.pot' );
guard_check( file_exists( $pot ) && false !== strpos( (string) file_get_contents( $pot ), 'X-Domain: shipping-desk' ), 'the template declares the shipping-desk domain' );
guard_check( ! file_exists( $guard_root . '/shipping-desk.pot' ), 'no stray .pot at the plugin root' );

/* -- Version consistency --------------------------------------------- */

guard_section( 'version agrees with itself' );

$header_version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $m ) ? $m[1] : '';
$constant       = preg_match( "/SHIPPING_DESK_VERSION',\s*'([^']+)'/", $header, $m ) ? $m[1] : '';

$plugin = guard_read( 'includes/class-plugin.php' );
$style  = preg_match( "/admin\.css',\s*\[\],\s*'([^']+)'/", $plugin, $m ) ? $m[1] : '';
$script = preg_match( "/admin\.js',\s*\[\s*'jquery'\s*\],\s*'([^']+)'/", $plugin, $m ) ? $m[1] : '';

guard_check( '' !== $header_version, 'the header carries a version' );
guard_same( $header_version, $constant, 'the version constant matches the header' );
guard_same( $header_version, $style, 'the stylesheet enqueue version matches the header' );
guard_same( $header_version, $script, 'the script enqueue version matches' );

/* -- Stored keys ----------------------------------------------------- */

guard_section( 'stored keys belong to Shipping Desk' );

$helpers = guard_read( 'includes/helpers.php' );
preg_match_all( "/=> '(_shipping_desk_[a-z_]+)'/", $helpers, $meta );
$meta = array_unique( $meta[1] );
guard_check( count( $meta ) >= 17, 'meta_keys() returns the full set', count( $meta ) . ' keys' );

$options = [];
foreach ( guard_shipped_files() as $file ) {
    preg_match_all( "/(?:get|update|add|delete)_option\(\s*'([a-z0-9_]+)'/", (string) file_get_contents( $file ), $found );
    $options = array_merge( $options, $found[1] );
}
$options = array_unique( $options );

$foreign = [];
foreach ( $options as $option ) {
    // WooCommerce's own options and the two legacy store settings are not ours to rename.
    if ( 0 === strpos( $option, 'shipping_desk_' ) || 0 === strpos( $option, 'woocommerce_' ) || in_array( $option, [ 'admin_email', 'admin_phone' ], true ) ) {
        continue;
    }
    $foreign[] = $option;
}
guard_same( [], $foreign, 'every option the plugin touches is namespaced or WooCommerce own' );

/* -- Actions and nonces ---------------------------------------------- */

guard_section( 'actions and nonces' );

$hits = guard_find( "'veeqo_shipping" );
guard_same( [], $hits, 'no veeqo_shipping action or nonce string' );

$js_actions = [];
preg_match_all( "/action:\s*'([a-z_]+)'/", guard_read( 'admin/js/admin.js' ), $found );
foreach ( array_unique( $found[1] ) as $action ) {
    if ( 0 !== strpos( $action, 'shipping_desk_' ) ) {
        $js_actions[] = $action;
    }
}
guard_same( [], $js_actions, 'every JS action value is shipping_desk_*' );

/* -- Summary --------------------------------------------------------- */

echo PHP_EOL . 'Checks: ' . $GLOBALS['guard_checks'] . ', failures: ' . $GLOBALS['guard_failures'] . PHP_EOL;

exit( 0 === $GLOBALS['guard_failures'] ? 0 : 1 );
