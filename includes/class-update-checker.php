<?php
/**
 * Update notices and one-click updates straight from GitHub Releases.
 *
 * Deliberately small and deliberately paranoid:
 *
 *  - **Releases only.** The check reads `/releases/latest`, and the package is a release
 *    asset named `shipping-desk-<version>.zip`. It never uses a branch or a tag zipball,
 *    because those unpack to `shipping-desk-1.1.0/` and WordPress would install a second
 *    copy of the plugin beside the real one.
 *  - **The hash decides.** GitHub reports a `sha256:` digest for each asset; that digest
 *    is remembered with the release and the downloaded file must match it before the
 *    upgrader is allowed to touch the site.
 *  - **Quiet when offline.** A failed check keeps the previous answer and tries again in
 *    an hour. Nothing about a shop's day should change because GitHub was unreachable.
 *  - **No vendor tree.** The plugin ships 24 files; this is one of them.
 *
 * Pure helpers (parse_tag, is_newer, pick_asset, changelog_for_version) hold no state and
 * are covered by tests/update-checker-test.php without WordPress.
 *
 * @package ShippingDesk
 */

defined( 'ABSPATH' ) || exit;

class Shipping_Desk_Update_Checker {

	/** Owner/repo on GitHub. Change this and the plugin follows a different repository. */
	const REPO = 'n777ty/shipping-desk';

	/** Plugin basename, as WordPress keys it in the update transient. */
	const SLUG = 'shipping-desk';

	/** Declared compatibility, repeated in the update offer so WordPress can refuse it. */
	const REQUIRES_WP  = '6.0';
	const REQUIRES_PHP = '7.4';
	const TESTED_WP    = '6.7';

	/** Where the answer is cached. */
	const TRANSIENT = 'shipping_desk_github_release';

	/** How long a good answer is reused. Fifteen half-seconds a day is nothing. */
	const TTL = 12 * HOUR_IN_SECONDS;

	/** How long a failure is remembered, so an outage is not retried on every page load. */
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/** Option holding the version whose changelog the operator has not seen yet. */
	const WHATS_NEW_OPTION = 'shipping_desk_whats_new';

	/** User meta recording that this person has seen a given version's changelog. */
	const WHATS_NEW_SEEN = 'shipping_desk_whats_new_seen';

	/** @var Shipping_Desk_Update_Checker|null */
	protected static $instance = null;

	/**
	 * Attach the hooks. Called once, from the main plugin file.
	 *
	 * The update filters run outside admin too: management tools and WP-CLI ask WordPress
	 * about updates without loading a dashboard page.
	 *
	 * @return Shipping_Desk_Update_Checker
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'filter_update_plugins' ] );
		add_filter( 'plugins_api', [ $this, 'filter_plugins_api' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'filter_pre_download' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'record_upgrade' ], 10, 2 );
	}

	/**
	 * Updates can be switched off entirely, for a site that pins its own version.
	 *
	 * @return bool
	 */
	public function is_disabled() {
		$disabled = defined( 'SHIPPING_DESK_DISABLE_UPDATES' ) && SHIPPING_DESK_DISABLE_UPDATES;
		return (bool) apply_filters( 'shipping_desk_disable_updates', $disabled );
	}

	/**
	 * Plugin basename, e.g. `shipping-desk/shipping-desk.php`.
	 *
	 * @return string
	 */
	public function plugin_basename() {
		return plugin_basename( dirname( __DIR__ ) . '/shipping-desk.php' );
	}

	/* -- Pure helpers ---------------------------------------------------------- */

	/**
	 * `v1.0.6` or `1.0.6` to `1.0.6`; anything else is not a version we will install.
	 *
	 * @param string $tag Release tag.
	 * @return string Empty when the tag is not a version.
	 */
	public static function parse_tag( $tag ) {
		$tag = trim( (string) $tag );
		$tag = preg_replace( '/^v/i', '', $tag );

		return preg_match( '/^\d+(?:\.\d+)*$/', $tag ) ? $tag : '';
	}

	/**
	 * Is the candidate worth offering over the installed version?
	 *
	 * @param string $candidate Version from the release.
	 * @param string $current   Installed version.
	 * @return bool
	 */
	public static function is_newer( $candidate, $current ) {
		if ( '' === (string) $candidate || '' === (string) $current ) {
			return false;
		}
		return version_compare( $candidate, $current, '>' );
	}

	/**
	 * The release asset to install: `shipping-desk-<version>.zip` and nothing else.
	 *
	 * A tag zipball or a differently named asset would install the plugin under the wrong
	 * directory, so a release without the expected asset simply does not offer an update.
	 *
	 * @param array $release Decoded GitHub release.
	 * @return array{version:string,package:string,digest:string,url:string,notes:string}|null
	 */
	public static function pick_asset( array $release ) {
		$version = self::parse_tag( isset( $release['tag_name'] ) ? $release['tag_name'] : '' );
		if ( '' === $version ) {
			return null;
		}

		$expected = 'shipping-desk-' . $version . '.zip';
		$assets   = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}
			if ( $expected !== $asset['name'] ) {
				continue;
			}

			return [
				'version' => $version,
				'package' => (string) $asset['browser_download_url'],
				'digest'  => isset( $asset['digest'] ) ? (string) $asset['digest'] : '',
				'url'     => isset( $release['html_url'] ) ? (string) $release['html_url'] : '',
				'notes'   => isset( $release['body'] ) ? (string) $release['body'] : '',
			];
		}

		return null;
	}

	/* -- The check ------------------------------------------------------------- */

	/**
	 * The release to work from, cached. A null release means "nothing usable right now".
	 *
	 * A failed check keeps the last good answer and retries in an hour, so an outage never
	 * leaves a site unable to update, and never turns into a request per page load.
	 *
	 * @param bool $force Skip the cache.
	 * @return array{checked:int,release:array|null}
	 */
	public function latest_release( $force = false ) {
		if ( $this->is_disabled() ) {
			return [ 'checked' => 0, 'release' => null ];
		}

		$cached = get_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) && isset( $cached['checked'] ) ) {
			return $cached;
		}

		$raw     = $this->fetch_release();
		$release = is_array( $raw ) ? self::pick_asset( $raw ) : null;

		if ( null === $release ) {
			$keep = ( is_array( $cached ) && ! empty( $cached['release'] ) ) ? $cached : [ 'checked' => time(), 'release' => null ];
			set_transient( self::TRANSIENT, $keep, self::FAILURE_TTL );
			return $keep;
		}

		$fresh = [ 'checked' => time(), 'release' => $release ];
		set_transient( self::TRANSIENT, $fresh, self::TTL );

		return $fresh;
	}

	/**
	 * One GET to GitHub. Returns the decoded release payload, or null.
	 *
	 * Deliberately returns GitHub's shape rather than the picked one: the picking (and the
	 * "is this a usable release?" decision) stays in latest_release(), where a test can
	 * feed it a payload like the API's.
	 *
	 * @return array|null
	 */
	protected function fetch_release() {
		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', self::REPO ),
			[
				'timeout' => 15,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					// GitHub rejects requests without a User-Agent.
					'User-Agent' => 'shipping-desk/' . ( defined( 'SHIPPING_DESK_VERSION' ) ? SHIPPING_DESK_VERSION : 'unknown' ),
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Offer the release in Plugins → Installed Plugins.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || $this->is_disabled() ) {
			return $transient;
		}

		$cached  = $this->latest_release();
		$release = isset( $cached['release'] ) ? $cached['release'] : null;
		if ( empty( $release ) ) {
			return $transient;
		}

		$basename = $this->plugin_basename();
		$current  = defined( 'SHIPPING_DESK_VERSION' ) ? SHIPPING_DESK_VERSION : '';

		if ( ! self::is_newer( $release['version'], $current ) ) {
			// Installed is current: leave nothing behind that would keep WordPress asking.
			if ( isset( $transient->response[ $basename ] ) ) {
				unset( $transient->response[ $basename ] );
			}
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$transient->response[ $basename ] = (object) [
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => self::REQUIRES_WP,
			'requires_php' => self::REQUIRES_PHP,
			'tested'       => self::TESTED_WP,
			'icons'        => [],
			'banners'      => [],
		];

		return $transient;
	}

	/**
	 * Fill the "View version details" modal, changelog included.
	 *
	 * @param mixed  $result False, or an existing answer.
	 * @param string $action API action.
	 * @param mixed  $args   Request arguments.
	 * @return mixed
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$cached  = $this->latest_release();
		$release = isset( $cached['release'] ) ? $cached['release'] : null;
		if ( empty( $release ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Shipping Desk',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="' . esc_url( 'https://github.com/' . self::REPO ) . '">Shipping Desk</a>',
			'homepage'      => $release['url'],
			'requires'      => self::REQUIRES_WP,
			'requires_php'  => self::REQUIRES_PHP,
			'tested'        => self::TESTED_WP,
			'download_link' => $release['package'],
			'sections'      => [
				'description' => '<p>' . esc_html__( 'Buy, print and refund shipping labels from WooCommerce orders.', 'shipping-desk' ) . '</p>',
				'changelog'   => self::changelog_html( self::readme_changelog() ),
			],
		];
	}

	/**
	 * Refuse to hand the upgrader a file that does not match the published digest.
	 *
	 * Applies only to this plugin's own release asset; every other update is untouched.
	 *
	 * @param mixed  $reply      False, or a file path / WP_Error from another filter.
	 * @param string $package    Package URL.
	 * @param mixed  $upgrader   Upgrader instance (unused).
	 * @param array  $hook_extra Extra arguments.
	 * @return mixed
	 */
	public function filter_pre_download( $reply, $package, $upgrader = null, $hook_extra = [] ) {
		if ( false !== $reply ) {
			return $reply;
		}

		$cached  = $this->latest_release();
		$release = isset( $cached['release'] ) ? $cached['release'] : null;
		if ( empty( $release ) || (string) $package !== (string) $release['package'] ) {
			return $reply;
		}

		$expected = strtolower( (string) preg_replace( '/^sha256:/i', '', (string) $release['digest'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			// No digest published: let WordPress download normally rather than block updates.
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! hash_equals( $expected, strtolower( (string) hash_file( 'sha256', $file ) ) ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error(
				'shipping_desk_digest_mismatch',
				__( 'The update package did not match the checksum published with the release, so it was not installed. Try again later, or install the zip from the release page.', 'shipping-desk' )
			);
		}

		return $file;
	}

	/**
	 * Remember that a new version landed, so its changelog can be shown once.
	 *
	 * The code that just finished updating still holds the old version in memory, so the
	 * version is read from the file on disk.
	 *
	 * @param mixed $upgrader   Upgrader instance (unused).
	 * @param array $hook_extra What was updated.
	 * @return void
	 */
	public function record_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		$basename = $this->plugin_basename();
		$ours     = ( ! empty( $hook_extra['plugin'] ) && $basename === $hook_extra['plugin'] )
			|| ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $basename, $hook_extra['plugins'], true ) );

		if ( ! $ours ) {
			return;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
		$version = ! empty( $data['Version'] ) ? (string) $data['Version'] : '';

		if ( '' === $version ) {
			return;
		}

		update_option( self::WHATS_NEW_OPTION, [ 'version' => $version, 'at' => time() ], false );
	}

	/* -- Changelog ------------------------------------------------------------- */

	/**
	 * The shipped readme's changelog, as version => list of entries.
	 *
	 * The readme travels with the plugin, so this works for a manual zip upload or a
	 * WP-CLI install too - not only for updates that came through GitHub.
	 *
	 * @return array<string,string[]>
	 */
	public static function readme_changelog() {
		$file = dirname( __DIR__ ) . '/readme.txt';
		if ( ! is_readable( $file ) ) {
			return [];
		}

		return self::parse_changelog( (string) file_get_contents( $file ) );
	}

	/**
	 * `== Changelog ==` blocks: `= 1.1.0 =` headings and `* item` bullets.
	 *
	 * Paragraphs are ignored on purpose; a changelog here is a list of changes.
	 *
	 * @param string $readme readme.txt contents.
	 * @return array<string,string[]>
	 */
	public static function parse_changelog( $readme ) {
		$entries = [];
		$current = null;
		$text    = str_replace( "\r\n", "\n", (string) $readme );

		if ( ! preg_match( '/==\s*Changelog\s*==(.*?)(?:\n==\s|\z)/is', $text, $block ) ) {
			return $entries;
		}

		foreach ( explode( "\n", $block[1] ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^=\s*([0-9][0-9A-Za-z.\-]*)\s*=$/', $line, $heading ) ) {
				$current             = $heading[1];
				$entries[ $current ] = [];
				continue;
			}

			if ( null !== $current && preg_match( '/^\*\s*(.+)$/', $line, $item ) ) {
				$entries[ $current ][] = trim( $item[1] );
			}
		}

		return $entries;
	}

	/**
	 * Render a parsed changelog as lists, in the order given.
	 *
	 * @param array<string,string[]> $entries Version => items.
	 * @return string Safe HTML.
	 */
	public static function changelog_html( array $entries ) {
		$html = '';

		foreach ( $entries as $version => $items ) {
			if ( empty( $items ) ) {
				continue;
			}
			$html .= '<h4>' . esc_html( $version ) . '</h4><ul>';
			foreach ( $items as $item ) {
				$html .= '<li>' . esc_html( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}

	/* -- What's new ------------------------------------------------------------ */

	/**
	 * The changelog worth showing this person, if any.
	 *
	 * Null unless a new version landed and this user has not dismissed it. The version is
	 * also required to match the code that is running, so an update that failed halfway
	 * cannot announce a release the site does not have.
	 *
	 * @param int $user_id User to check; 0 means the current user.
	 * @return array{version:string,items:string[]}|null
	 */
	public static function pending_whats_new( $user_id = 0 ) {
		$recorded = get_option( self::WHATS_NEW_OPTION );
		if ( ! is_array( $recorded ) || empty( $recorded['version'] ) ) {
			return null;
		}

		$version = (string) $recorded['version'];

		if ( defined( 'SHIPPING_DESK_VERSION' ) && $version !== SHIPPING_DESK_VERSION ) {
			return null;
		}

		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || $version === (string) get_user_meta( $user_id, self::WHATS_NEW_SEEN, true ) ) {
			return null;
		}

		$entries = self::readme_changelog();
		$items   = isset( $entries[ $version ] ) ? $entries[ $version ] : [];

		if ( empty( $items ) ) {
			return null;
		}

		return [ 'version' => $version, 'items' => $items ];
	}

	/**
	 * Mark a version's changelog as seen for a user.
	 *
	 * @param string $version Version dismissed.
	 * @param int    $user_id User; 0 means the current user.
	 * @return void
	 */
	public static function dismiss_whats_new( $version, $user_id = 0 ) {
		$version = trim( (string) $version );
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( '' === $version || ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::WHATS_NEW_SEEN, $version );
	}
}
