<?php
/**
 * Gate for Shipping_Desk_Update_Checker.
 *
 * Standalone: no Composer, no PHPUnit, no WordPress. WordPress functions are stubbed and
 * the network call is replaced by a subclass, so the checks cover what actually decides an
 * update:
 *
 *   - only `vX.Y.Z`-style tags count as versions
 *   - the package is the release asset named `shipping-desk-<version>.zip`, never a zipball
 *   - a release that is not newer is not offered, and leaves no stale offer behind
 *   - the answer is cached, a failed check keeps the last good answer
 *   - the changelog parser reads `== Changelog ==` and the popup data is per version,
 *     per user, and only for the version that is actually running
 *
 * Run:  php tests/update-checker-test.php
 * Exit: 0 when every check passes, 1 otherwise.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SHIPPING_DESK_VERSION', '1.1.0' );

// A plugin directory of our own, so installed_version() can be tested: the disk copy says
// 1.1.0 at first, matching the constant, and tests change it to model an update landing.
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/shipping-desk-upd-' . getmypid() );

$GLOBALS['upd_checks']    = 0;
$GLOBALS['upd_failures']  = 0;
$GLOBALS['upd_transients'] = [];
$GLOBALS['upd_options']    = [];
$GLOBALS['upd_user_meta']  = [];

/**
 * Record one expectation.
 *
 * @param bool   $condition Result.
 * @param string $label     What was being checked.
 * @return void
 */
function upd_check( $condition, $label ) {
	$GLOBALS['upd_checks']++;
	if ( $condition ) {
		echo "  ok    {$label}\n";
		return;
	}
	$GLOBALS['upd_failures']++;
	echo "  FAIL  {$label}\n";
}

/**
 * Record an equality expectation, showing both sides when they differ.
 *
 * @param mixed  $want  Expected.
 * @param mixed  $got   Actual.
 * @param string $label What was being checked.
 * @return void
 */
function upd_same( $want, $got, $label ) {
	$ok = ( $want === $got );
	if ( ! $ok ) {
		$label .= " (expected " . var_export( $want, true ) . ', got ' . var_export( $got, true ) . ')';
	}
	upd_check( $ok, $label );
}

/**
 * Clear cached state between cases.
 *
 * @return void
 */
function upd_reset() {
	$GLOBALS['upd_transients'] = [];
	$GLOBALS['upd_options']    = [];
	$GLOBALS['upd_user_meta']  = [];
	$GLOBALS['upd_deleted']    = [];
}

/**
 * Write a plugin header with the given version, as an update would leave it on disk.
 *
 * @param string $version Version to claim.
 * @return void
 */
function upd_set_disk_version( $version ) {
	$dir = WP_PLUGIN_DIR . '/shipping-desk';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $dir . '/shipping-desk.php', "<?php\n/**\n * Plugin Name: Shipping Desk\n * Version: {$version}\n */\n" );
}

/**
 * Remove the fake plugin file, so the constant has to be used as the fallback.
 *
 * @return void
 */
function upd_remove_disk() {
	$file = WP_PLUGIN_DIR . '/shipping-desk/shipping-desk.php';
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

upd_set_disk_version( '1.1.0' );

/* -- WordPress stand-ins ---------------------------------------------------- */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		protected $code;
		protected $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_filter() {}
function add_action() {}

function apply_filters( $tag, $value ) {
	return $value;
}

function plugin_basename( $file ) {
	return 'shipping-desk/' . basename( $file );
}

function get_transient( $key ) {
	return isset( $GLOBALS['upd_transients'][ $key ] ) ? $GLOBALS['upd_transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['upd_transients'][ $key ] = $value;
	return true;
}

function delete_site_transient( $key ) {
	$GLOBALS['upd_deleted'][] = $key;
	unset( $GLOBALS['upd_transients'][ $key ] );
	return true;
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['upd_options'] ) ? $GLOBALS['upd_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['upd_options'][ $key ] = $value;
	return true;
}

function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['upd_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['upd_user_meta'][ $user_id ][ $key ] : '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['upd_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function get_current_user_id() {
	return 1;
}

function wp_remote_get() {
	return new WP_Error( 'no_network', 'Not reachable in this test.' );
}

require dirname( __DIR__ ) . '/includes/class-update-checker.php';

/**
 * Checker whose network call returns whatever the case needs.
 */
class Upd_Test_Checker extends Shipping_Desk_Update_Checker {

	/** @var int */
	public $calls = 0;

	/** @var array|null */
	public $release = null;

	protected function fetch_release() {
		$this->calls++;
		return $this->release;
	}
}

/**
 * A release payload shaped like GitHub's.
 *
 * @param string $version Tag version.
 * @param array  $assets  Assets; defaults to the expected zip.
 * @return array
 */
function upd_release( $version, ?array $assets = null ) {
	if ( null === $assets ) {
		$assets = [
			[
				'name'                 => 'shipping-desk-' . $version . '.zip',
				'browser_download_url' => 'https://github.com/n777ty/shipping-desk/releases/download/v' . $version . '/shipping-desk-' . $version . '.zip',
				'digest'               => 'sha256:' . str_repeat( 'a', 64 ),
			],
		];
	}

	return [
		'tag_name' => 'v' . $version,
		'html_url' => 'https://github.com/n777ty/shipping-desk/releases/tag/v' . $version,
		'body'     => "Release notes for {$version}",
		'assets'   => $assets,
	];
}

/* -- Versions -------------------------------------------------------------- */

echo "\n[versions]\n";

upd_same( '1.0.6', Shipping_Desk_Update_Checker::parse_tag( 'v1.0.6' ), 'a v-prefixed tag parses' );
upd_same( '1.0.6', Shipping_Desk_Update_Checker::parse_tag( '1.0.6' ), 'a bare tag parses' );
upd_same( '1.1.0', Shipping_Desk_Update_Checker::parse_tag( 'V1.1.0' ), 'the prefix is case-insensitive' );
upd_same( '', Shipping_Desk_Update_Checker::parse_tag( 'v1.0.6-beta' ), 'a pre-release tag is not a version' );
upd_same( '', Shipping_Desk_Update_Checker::parse_tag( 'latest' ), 'a non-version tag is rejected' );

upd_check( Shipping_Desk_Update_Checker::is_newer( '1.0.6', '1.0.5' ), 'a higher version is newer' );
upd_check( ! Shipping_Desk_Update_Checker::is_newer( '1.0.5', '1.0.5' ), 'the same version is not newer' );
upd_check( ! Shipping_Desk_Update_Checker::is_newer( '1.0.4', '1.0.5' ), 'an older version is not newer' );
upd_check( ! Shipping_Desk_Update_Checker::is_newer( '', '1.0.5' ), 'an empty version is never newer' );

/* -- Asset selection -------------------------------------------------------- */

echo "\n[asset selection]\n";

$picked = Shipping_Desk_Update_Checker::pick_asset( upd_release( '1.2.0' ) );
upd_same( '1.2.0', $picked['version'], 'the version comes from the tag' );
upd_same(
	'https://github.com/n777ty/shipping-desk/releases/download/v1.2.0/shipping-desk-1.2.0.zip',
	$picked['package'],
	'the package is the release asset'
);
upd_same( 'sha256:' . str_repeat( 'a', 64 ), $picked['digest'], 'the digest is carried through' );

$wrong_name = Shipping_Desk_Update_Checker::pick_asset( upd_release( '1.2.0', [
	[ 'name' => 'shipping-desk.zip', 'browser_download_url' => 'https://example.test/x.zip', 'digest' => '' ],
] ) );
upd_check( null === $wrong_name, 'an asset not named after the version is refused' );

$no_assets = Shipping_Desk_Update_Checker::pick_asset( upd_release( '1.2.0', [] ) );
upd_check( null === $no_assets, 'a release without assets offers no update (never a zipball)' );

$bad_tag = Shipping_Desk_Update_Checker::pick_asset( [ 'tag_name' => 'nightly', 'assets' => [] ] );
upd_check( null === $bad_tag, 'a release whose tag is not a version is ignored' );

/* -- Caching ---------------------------------------------------------------- */

echo "\n[caching]\n";

upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = upd_release( '1.2.0' );
$checker->latest_release();
$checker->latest_release();
upd_same( 1, $checker->calls, 'the release is fetched once, then served from cache' );

$checker->latest_release( true );
upd_same( 2, $checker->calls, 'a forced check asks GitHub again' );

upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = upd_release( '1.9.9' );
$checker->latest_release();
$checker->release = null;
$kept             = $checker->latest_release( true );
upd_same( '1.9.9', $kept['release']['version'], 'a failed check keeps the last good answer' );

/* -- The update offer ------------------------------------------------------- */

echo "\n[update offer]\n";

upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = upd_release( '1.2.0' );
$transient        = $checker->filter_update_plugins( (object) [ 'response' => [] ] );
$entry            = $transient->response['shipping-desk/shipping-desk.php'];

upd_same( '1.2.0', $entry->new_version, 'a newer release is offered' );
upd_same( 'shipping-desk', $entry->slug, 'the slug matches the plugin folder' );
upd_same( '7.4', $entry->requires_php, 'requires_php travels with the offer' );
upd_same( '6.0', $entry->requires, 'requires travels with the offer' );

upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = upd_release( '1.1.0' );
$same             = $checker->filter_update_plugins( (object) [
	'response' => [ 'shipping-desk/shipping-desk.php' => (object) [ 'new_version' => '1.1.0' ] ],
] );
upd_check( empty( $same->response ), 'the running version is not offered and leaves no stale entry' );

upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = null;
$offline          = $checker->filter_update_plugins( (object) [ 'response' => [] ] );
upd_check( empty( $offline->response ), 'an unreachable GitHub adds no update' );

/* -- Changelog -------------------------------------------------------------- */

echo "\n[changelog]\n";

$readme = "== Changelog ==\n\n= 1.2.0 =\n* New: something.\n* Fixed: another.\n\n= 1.1.0 =\n* First.\n\n== Upgrade Notice ==\n= 1.2.0 =\nDo not treat this as a change.\n";
$parsed = Shipping_Desk_Update_Checker::parse_changelog( $readme );

upd_same( [ '1.2.0', '1.1.0' ], array_keys( $parsed ), 'versions are read in order' );
upd_same( 2, count( $parsed['1.2.0'] ), 'bullets are read per version' );
upd_check( ! isset( $parsed['Do not treat this as a change.'] ), 'the Upgrade Notice section is not changelog' );

$html = Shipping_Desk_Update_Checker::changelog_html( [ '1.2.0' => [ 'A <b>bold</b> claim' ] ] );
upd_check( false !== strpos( $html, '&lt;b&gt;' ), 'changelog html is escaped' );
upd_check( false !== strpos( $html, '<h4>1.2.0</h4>' ), 'changelog html names the version' );

/*
 * The bug this section exists for: while WordPress offered 1.6.1 the "view version details"
 * modal showed nothing about 1.6.1, because the changelog was built from the readme on disk -
 * which is the *installed* version's readme and cannot contain the version being offered. The
 * release's own notes travel with the offer, so they are the only text that can describe it.
 */
$notes = implode( "\n", [
	'# Shipping Desk 1.9.0',
	'',
	'## One order, one purchase',
	'',
	'A purchase takes a **lock**; see `class-purchase-lock.php`.',
	'',
	'- The second request is refused.',
	'- Nothing is bought **twice**, per `class-purchase-lock.php`.',
	'',
	'## Upgrading',
	'',
	'Install over 1.8.0.',
] );

$bullets = Shipping_Desk_Update_Checker::notes_bullets( $notes );
upd_same( 2, count( $bullets ), 'notes bullets are read from the release body' );
upd_same( 'The second request is refused.', $bullets[0], 'as plain text' );
upd_same( 'Nothing is bought twice, per class-purchase-lock.php.', $bullets[1], 'with emphasis and code markers stripped for the popup' );
upd_check( false === strpos( implode( ' ', $bullets ), '**' ) && false === strpos( implode( ' ', $bullets ), '`' ), 'so the popup never shows raw markdown' );

$notes_html = Shipping_Desk_Update_Checker::notes_html( $notes, '1.9.0' );
upd_check( false !== strpos( $notes_html, '<h4>1.9.0</h4>' ), 'the notes are headed with the version being offered' );
upd_check( false !== strpos( $notes_html, '<h5>One order, one purchase</h5>' ), 'and keep their own headings' );
upd_check( false !== strpos( $notes_html, '<li>The second request is refused.</li>' ), 'bullets become a list' );
upd_check( false !== strpos( $notes_html, '<strong>lock</strong>' ) && false !== strpos( $notes_html, '<code>class-purchase-lock.php</code>' ), 'inline emphasis and code are marked up' );
upd_check( false === strpos( $notes_html, '# Shipping Desk 1.9.0' ), 'the notes\' own title is not repeated' );

$nasty = Shipping_Desk_Update_Checker::notes_html( "## <script>alert(1)</script>\n\n- [click](javascript:alert(1))\n- <img src=x onerror=alert(1)>", '1.9.0' );
upd_check( false === strpos( $nasty, '<script' ) && false === strpos( $nasty, '<img' ), 'markup in notes is escaped, not rendered' );
upd_check( false !== strpos( $nasty, '&lt;script&gt;' ), 'and the angle brackets are entities' );
upd_check( false === strpos( $nasty, '<a ' ) && false !== strpos( $nasty, 'click' ), 'a link with a script URL becomes plain text' );

/* -- The offer's changelog -------------------------------------------------- */

echo "\n[what the offer shows]\n";

$offer = Shipping_Desk_Update_Checker::offered_changelog( [
	'version' => '1.9.0',
	'notes'   => $notes,
] );

upd_check( false !== strpos( $offer, '<h4>1.9.0</h4>' ), 'the version being offered comes first' );
upd_check( false !== strpos( $offer, 'The second request is refused.' ), 'with its own notes' );
upd_check( false !== strpos( $offer, '<hr />' ) && false !== strpos( $offer, '<h4>1.6.0</h4>' ), 'then the installed history, which is all the readme can carry' );
upd_same( 1, substr_count( $offer, '<h4>1.9.0</h4>' ), 'and the offered version is not repeated from the readme' );

$no_notes = Shipping_Desk_Update_Checker::offered_changelog( [ 'version' => '1.6.0', 'notes' => '' ] );
upd_check( 1 === substr_count( $no_notes, '<h4>1.6.0</h4>' ), 'a release without notes still shows its installed section, once' );

// And the filter itself uses it: this is the bug, so it is pinned at the point where it was.
$GLOBALS['upd_transients'][ Shipping_Desk_Update_Checker::TRANSIENT ] = [
	'checked' => time(),
	'release' => [
		'version' => '1.9.0',
		'package' => 'https://example.test/shipping-desk-1.9.0.zip',
		'digest'  => '',
		'url'     => 'https://example.test/release',
		'notes'   => $notes,
	],
];
$info = $checker->filter_plugins_api( false, 'plugin_information', (object) [ 'slug' => 'shipping-desk' ] );
upd_check( is_object( $info ) && isset( $info->sections['changelog'] ), 'the version-details modal answers' );
upd_check( is_object( $info ) && false !== strpos( $info->sections['changelog'], 'The second request is refused.' ), 'with the release\'s own notes: the installed readme cannot describe the version being offered' );
upd_check( is_object( $info ) && false !== strpos( $info->sections['changelog'], '<h4>1.6.0</h4>' ), 'and the older history below them' );
upd_reset();

/* -- What's new ------------------------------------------------------------- */

echo "\n[what's new]\n";

upd_reset();
update_option( Shipping_Desk_Update_Checker::WHATS_NEW_OPTION, [ 'version' => '1.1.0', 'at' => time() ] );

$pending = Shipping_Desk_Update_Checker::pending_whats_new( 5 );
upd_check( is_array( $pending ), 'a freshly updated version has something to show' );
upd_same( '1.1.0', $pending['version'], 'the popup names the version' );

$shipped   = Shipping_Desk_Update_Checker::readme_changelog();
$first_bullet = isset( $shipped['1.1.0'][0] ) ? $shipped['1.1.0'][0] : '';
upd_check( '' !== $first_bullet, 'the installed readme has a 1.1.0 section to show' );
upd_same( $first_bullet, $pending['items'][0], 'the popup lists the installed readme items' );

Shipping_Desk_Update_Checker::dismiss_whats_new( '1.1.0', 5 );
upd_check( null === Shipping_Desk_Update_Checker::pending_whats_new( 5 ), 'a dismissed version is not shown again' );
upd_check( is_array( Shipping_Desk_Update_Checker::pending_whats_new( 6 ) ), 'dismissal is per user' );

// The readme is the normal source; the release's notes are the fallback, and nothing at all is
// the one outcome that is certainly wrong.
upd_reset();
set_transient( Shipping_Desk_Update_Checker::TRANSIENT, [
	'checked' => time(),
	'release' => [ 'version' => '1.9.0', 'notes' => "- A note from the release.\n- Another one." ],
] );
$fallback = Shipping_Desk_Update_Checker::cached_notes_bullets( '1.9.0' );
upd_same( [ 'A note from the release.', 'Another one.' ], $fallback, 'the popup can fall back to the release notes' );
upd_same( array(), Shipping_Desk_Update_Checker::cached_notes_bullets( '1.9.1' ), 'but never for a version the notes do not describe' );
upd_same( array(), Shipping_Desk_Update_Checker::cached_notes_bullets( '1.1.0' ), 'and not when the cached release is a different one' );

// The popup's own entry point, which is what uses the fallback: asked for a version the readme
// does not have, it must still answer.
upd_same( [ 'A note from the release.', 'Another one.' ], Shipping_Desk_Update_Checker::whats_new_items( '1.9.0' ), 'the popup reads the notes when the readme has no section' );
upd_same(
	[ 'Readme bullet.' ],
	Shipping_Desk_Update_Checker::whats_new_items( '1.9.0', [ '1.9.0' => [ 'Readme bullet.' ] ] ),
	'and the readme when it does'
);

// And the popup itself must use that: a readme without a section for the running version still
// shows the release's notes rather than nothing.
upd_reset();
update_option( Shipping_Desk_Update_Checker::WHATS_NEW_OPTION, [ 'version' => SHIPPING_DESK_VERSION, 'at' => time() ] );
set_transient( Shipping_Desk_Update_Checker::TRANSIENT, [
	'checked' => time(),
	'release' => [ 'version' => SHIPPING_DESK_VERSION, 'notes' => "- A note from the release.\n- Another one." ],
] );
$no_section = Shipping_Desk_Update_Checker::pending_whats_new( 8, [] );
upd_check( is_array( $no_section ), 'the popup still has something to show when the readme lost the section' );
upd_same( 'A note from the release.', isset( $no_section['items'][0] ) ? $no_section['items'][0] : '', 'and it is the release notes' );

update_option( Shipping_Desk_Update_Checker::WHATS_NEW_OPTION, [ 'version' => '9.9.9', 'at' => time() ] );
upd_check( null === Shipping_Desk_Update_Checker::pending_whats_new( 7 ), 'a version that is not running is never announced' );

/* -- After an update -------------------------------------------------------- */

echo "\n[after an update]\n";

// The bug this section exists for: WordPress clears the update transient when a plugin is
// upgraded, then re-checks in the same request - where the loaded code is still the old
// one. Reading the version from the constant re-offered the version just installed. The
// header on disk is what got installed, so it decides.
upd_set_disk_version( '1.1.1' );
upd_reset();
$checker          = new Upd_Test_Checker();
$checker->release = upd_release( '1.1.1' );

upd_same( '1.1.1', $checker->installed_version(), 'the installed version comes from the header on disk' );

$just_installed = $checker->filter_update_plugins( (object) [
	'response' => [ 'shipping-desk/shipping-desk.php' => (object) [ 'new_version' => '1.1.1' ] ],
] );
upd_check( empty( $just_installed->response ), 'the version just installed is not offered again' );

upd_reset();
$checker->release = upd_release( '1.1.2' );
$genuinely_newer  = $checker->filter_update_plugins( (object) [ 'response' => [] ] );
upd_same(
	'1.1.2',
	$genuinely_newer->response['shipping-desk/shipping-desk.php']->new_version,
	'a genuinely newer release is still offered after an update'
);

// Fallback: no readable file, so the loaded constant is the best available answer.
upd_remove_disk();
$fallback = new Upd_Test_Checker();
upd_same( '1.1.0', $fallback->installed_version(), 'the constant is the fallback when the file cannot be read' );
upd_set_disk_version( '1.1.1' );

// The upgrade hook records the new version and drops the stale update transient.
upd_reset();
$checker->record_upgrade( null, [ 'type' => 'plugin', 'plugin' => 'shipping-desk/shipping-desk.php' ] );
upd_same(
	'1.1.1',
	isset( $GLOBALS['upd_options'][ Shipping_Desk_Update_Checker::WHATS_NEW_OPTION ]['version'] )
		? $GLOBALS['upd_options'][ Shipping_Desk_Update_Checker::WHATS_NEW_OPTION ]['version']
		: '',
	'the upgrade is recorded for the "what\'s new" popup'
);
upd_check( in_array( 'update_plugins', $GLOBALS['upd_deleted'], true ), 'the stale update transient is dropped' );

upd_reset();
$checker->record_upgrade( null, [ 'type' => 'plugin', 'plugin' => 'other-plugin/other-plugin.php' ] );
upd_check( empty( $GLOBALS['upd_options'] ), 'another plugin being updated records nothing here' );

/* -- Summary ---------------------------------------------------------------- */

// Leave nothing behind: the fake plugin directory exists only for these checks.
$upd_dir = WP_PLUGIN_DIR . '/shipping-desk';
if ( is_dir( $upd_dir ) ) {
	foreach ( glob( $upd_dir . '/*' ) as $upd_file ) {
		@unlink( $upd_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@rmdir( $upd_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
@rmdir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

echo "\n";
printf( "Checks: %d, failures: %d\n", $GLOBALS['upd_checks'], $GLOBALS['upd_failures'] );
exit( $GLOBALS['upd_failures'] > 0 ? 1 : 0 );
