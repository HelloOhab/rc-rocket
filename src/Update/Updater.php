<?php
declare( strict_types=1 );

namespace RCRocket\Update;

use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private plugin updates.
 *
 * RC Rocket is not on wordpress.org and never will be, so WordPress has no
 * idea a newer version exists. This teaches it — a new release appears in
 * Dashboard → Updates on every site, updates with one click, and supports
 * WordPress's own automatic updates.
 *
 * Two sources, both plain HTTPS, no third-party library and no Composer:
 *
 *   Manifest — a static JSON file you host anywhere. Simplest and dependency
 *   free; if you can put a file on a URL, you have an update server.
 *
 *   GitHub — reads the latest release of a repository. Private repositories
 *   work with a token, which is downloaded with an auth header rather than
 *   being embedded in a URL that would end up in logs.
 *
 * Configure in wp-config.php:
 *
 *   define( 'RC_ROCKET_UPDATE_URL', 'https://updates.example.com/rc-rocket.json' );
 *
 * or:
 *
 *   define( 'RC_ROCKET_UPDATE_GITHUB', 'youragency/rc-rocket' );
 *   define( 'RC_ROCKET_UPDATE_TOKEN', 'ghp_...' );   // private repos only
 *   define( 'RC_ROCKET_UPDATE_CHANNEL', 'beta' );     // test sites only
 *
 * The beta channel also offers GitHub pre-releases, so a release can reach
 * a few test sites first. Every other site only sees full releases.
 *
 * With neither defined the updater stays completely dormant.
 */
final class Updater {

	private const TRANSIENT = 'rcrocket_update_check';
	private const TTL       = 6 * HOUR_IN_SECONDS;

	public function __construct(
		private string $file,
		private string $version,
		private Logger $logger
	) {}

	public function hooks(): void {
		if ( ! $this->configured() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject' ] );
		add_filter( 'plugins_api', [ $this, 'details' ], 10, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'download_private' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder_name' ], 10, 4 );
		add_action( 'rc-rocket/update/flush', [ $this, 'flush' ] );

		// A release should reach 30 sites without anyone clicking 30 times.
		add_filter( 'auto_update_plugin', [ $this, 'allow_auto_update' ], 10, 2 );
	}

	public function configured(): bool {
		return '' !== $this->manifest_url() || '' !== $this->repository();
	}

	public function slug(): string {
		return dirname( plugin_basename( $this->file ) );
	}

	public function basename(): string {
		return plugin_basename( $this->file );
	}

	private function manifest_url(): string {
		$url = defined( 'RC_ROCKET_UPDATE_URL' ) ? (string) RC_ROCKET_UPDATE_URL : '';

		/** @param string $url */
		return (string) apply_filters( 'rc-rocket/update/manifest_url', $url );
	}

	private function repository(): string {
		$repo = defined( 'RC_ROCKET_UPDATE_GITHUB' ) ? (string) RC_ROCKET_UPDATE_GITHUB : '';

		/** @param string $repo */
		return (string) apply_filters( 'rc-rocket/update/repository', $repo );
	}

	private function token(): string {
		$token = defined( 'RC_ROCKET_UPDATE_TOKEN' ) ? (string) RC_ROCKET_UPDATE_TOKEN : '';

		/** @param string $token */
		return (string) apply_filters( 'rc-rocket/update/token', $token );
	}

	// ------------------------------------------------------------- the check

	/**
	 * @return array{version:string, package:string, requires:string, requires_php:string, tested:string, changelog:string, url:string, description:string}|null
	 */
	public function remote( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_site_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached['version'] ?? false ? $cached : null;
			}
		}

		$release = '' !== $this->repository() ? $this->from_github() : $this->from_manifest();

		// Cache failures too, briefly. Thirty sites polling a broken endpoint
		// every page load in wp-admin is its own outage.
		set_site_transient(
			self::TRANSIENT,
			$release ?? [ 'version' => false ],
			null === $release ? HOUR_IN_SECONDS : self::TTL
		);

		return $release;
	}

	private function from_manifest(): ?array {
		$url = $this->manifest_url();

		// Whatever this returns is installed on every site that polls it.
		// Plain HTTP would let anyone on the path choose the code.
		if ( '' === $url ) {
			return null;
		}

		if ( ! str_starts_with( strtolower( $url ), 'https://' ) ) {
			$this->logger->error( 'Update manifest URL is not HTTPS; update checks are off until it is', [ 'url' => $url ] );

			return null;
		}

		$body = $this->fetch( $url );

		if ( null === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			$this->logger->error( 'Update manifest is missing version or download_url', [ 'url' => $url ] );

			return null;
		}

		if ( ! str_starts_with( strtolower( (string) $data['download_url'] ), 'https://' ) ) {
			$this->logger->error( 'Update package is not served over HTTPS; ignored', [ 'url' => $url ] );

			return null;
		}

		return [
			'version'      => (string) $data['version'],
			'package'      => (string) $data['download_url'],
			'requires'     => (string) ( $data['requires'] ?? '6.5' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '8.1' ),
			'tested'       => (string) ( $data['tested'] ?? '' ),
			'changelog'    => (string) ( $data['changelog'] ?? '' ),
			'url'          => (string) ( $data['homepage'] ?? '' ),
			'description'  => (string) ( $data['description'] ?? '' ),
		];
	}

	public function channel(): string {
		$channel = defined( 'RC_ROCKET_UPDATE_CHANNEL' ) ? (string) RC_ROCKET_UPDATE_CHANNEL : 'stable';

		/** @param string $channel "stable" or "beta". */
		return 'beta' === apply_filters( 'rc-rocket/update/channel', $channel ) ? 'beta' : 'stable';
	}

	private function from_github(): ?array {
		$release = 'beta' === $this->channel() ? $this->newest_release() : $this->latest_release();

		if ( null === $release ) {
			return null;
		}

		// Prefer an attached zip over GitHub's generated source archive: the
		// generated one carries a folder named repo-tag, not the plugin slug.
		$package = '';

		foreach ( (array) ( $release['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) && str_ends_with( (string) ( $asset['name'] ?? '' ), '.zip' ) ) {
				$package = (string) ( '' !== $this->token() ? $asset['url'] : $asset['browser_download_url'] );
				break;
			}
		}

		if ( '' === $package ) {
			$package = (string) ( $release['zipball_url'] ?? '' );
		}

		if ( '' === $package ) {
			return null;
		}

		return [
			'version'      => ltrim( (string) $release['tag_name'], 'v' ),
			'package'      => $package,
			'requires'     => '6.5',
			'requires_php' => '8.1',
			'tested'       => '',
			'changelog'    => (string) ( $release['body'] ?? '' ),
			'url'          => (string) ( $release['html_url'] ?? '' ),
			'description'  => '',
		];
	}

	/** GitHub's "latest": the newest full release, never a pre-release. */
	private function latest_release(): ?array {
		$body = $this->fetch( sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repository() ) );
		$data = null === $body ? null : json_decode( $body, true );

		return is_array( $data ) && ! empty( $data['tag_name'] ) ? $data : null;
	}

	/** The highest version among recent releases, pre-releases included. */
	public function newest_release( ?string $body = null ): ?array {
		$body ??= $this->fetch( sprintf( 'https://api.github.com/repos/%s/releases?per_page=20', $this->repository() ) );
		$list = null === $body ? null : json_decode( $body, true );

		if ( ! is_array( $list ) ) {
			return null;
		}

		$best = null;

		foreach ( $list as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) || empty( $release['tag_name'] ) ) {
				continue;
			}

			if ( null === $best || version_compare( ltrim( (string) $release['tag_name'], 'v' ), ltrim( (string) $best['tag_name'], 'v' ), '>' ) ) {
				$best = $release;
			}
		}

		return $best;
	}

	private function fetch( string $url, bool $binary = false ): ?string {
		$args = [
			'timeout'    => 10,
			'user-agent' => 'RCRocket/' . $this->version . '; ' . home_url( '/' ),
			'headers'    => [ 'Accept' => $binary ? 'application/octet-stream' : 'application/json' ],
		];

		// The token goes to GitHub and nowhere else, however the URL is dressed.
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		$authorized = '' !== $this->token() && in_array( $host, [ 'api.github.com', 'github.com' ], true );

		if ( $authorized ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token();

			// GitHub answers an asset download with a redirect to signed
			// storage, which rejects a request carrying a second credential.
			// Follow it by hand, without the token.
			$args['redirection'] = 0;
		}

		$response = wp_remote_get( $url, $args );

		if ( $authorized && ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), [ 301, 302, 303, 307, 308 ], true ) ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			unset( $args['headers']['Authorization'], $args['redirection'] );

			$response = '' === $location ? $response : wp_remote_get( $location, $args );
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Update check failed', [ 'error' => $response->get_error_message() ] );

			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->logger->error( 'Update check returned an unexpected status', [ 'status' => $code, 'url' => (string) strtok( $url, '?' ) ] );

			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	// ------------------------------------------------------- WordPress glue

	public function inject( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Updates come from us only. The slug is not reserved on
		// wordpress.org, so an entry from there would be someone else's
		// plugin of the same name. "Update URI" in the header already stops
		// core asking; this covers sites where something re-adds it.
		if ( isset( $transient->response ) && is_array( $transient->response ) ) {
			unset( $transient->response[ $this->basename() ] );
		}

		$release = $this->remote();

		if ( null === $release ) {
			return $transient;
		}

		$item = (object) [
			'id'           => $this->slug(),
			'slug'         => $this->slug(),
			'plugin'       => $this->basename(),
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
			'icons'        => [],
			'banners'      => [],
		];

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename() ] = $item;
		} else {
			// Listing it here as well keeps "Check again" honest and lets
			// WordPress show the plugin as up to date rather than unknown.
			$transient->no_update[ $this->basename() ] = $item;
		}

		return $transient;
	}

	public function details( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug() ) {
			return $result;
		}

		$release = $this->remote();

		if ( null === $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'RC Rocket',
			'slug'          => $this->slug(),
			'version'       => $release['version'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'download_link' => $release['package'],
			'sections'      => [
				'description' => ! empty( $release['description'] )
					? wpautop( esc_html( $release['description'] ) )
					: __( 'Performance tuning built around Divi. Asset control, media delivery, JavaScript timing and a safety net that switches itself off when something breaks.', 'rc-rocket' ),
				'changelog'   => wpautop( esc_html( $release['changelog'] ?? '' ) ),
			],
		];
	}

	/**
	 * A private GitHub asset cannot be fetched by URL alone, so download it
	 * here with the auth header and hand WordPress a local file.
	 */
	public function download_private( mixed $reply, string $package, mixed $upgrader, array $hook_extra = [] ): mixed {
		if ( '' === $this->token() || 'api.github.com' !== strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) ) ) {
			return $reply;
		}

		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->basename() ) {
			return $reply;
		}

		$body = $this->fetch( $package, true );

		if ( null === $body ) {
			return new \WP_Error( 'rc_rocket_download_failed', __( 'Could not download the RC Rocket update.', 'rc-rocket' ) );
		}

		$temp = wp_tempnam( 'rc-rocket.zip' );

		if ( ! $temp || false === file_put_contents( $temp, $body ) ) { // phpcs:ignore
			return new \WP_Error( 'rc_rocket_download_failed', __( 'Could not write the RC Rocket update to disk.', 'rc-rocket' ) );
		}

		return $temp;
	}

	/**
	 * GitHub's generated archives unpack to repo-name-tag. Installing that
	 * would leave a second, deactivated copy of the plugin beside the first.
	 */
	public function fix_folder_name( mixed $source, mixed $remote_source, mixed $upgrader, array $hook_extra = [] ): mixed {
		if ( ! is_string( $source ) || ( $hook_extra['plugin'] ?? '' ) !== $this->basename() ) {
			return $source;
		}

		$desired = trailingslashit( (string) $remote_source ) . $this->slug();

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	public function allow_auto_update( mixed $update, mixed $item ): mixed {
		if ( ! is_object( $item ) || ( $item->plugin ?? '' ) !== $this->basename() ) {
			return $update;
		}

		/**
		 * The per-plugin toggle on the Plugins screen decides, as it does for
		 * every other plugin; it is off until someone turns it on. Return a
		 * bool here to force it either way across a fleet.
		 *
		 * @param bool|null $enabled
		 */
		$forced = apply_filters( 'rc-rocket/update/auto', null );

		return null === $forced ? $update : (bool) $forced;
	}

	public function flush(): void {
		delete_site_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
	}

	/** For the admin and WP-CLI. */
	public function report(): array {
		$release = $this->remote();

		return [
			'configured' => $this->configured(),
			'source'     => '' !== $this->repository() ? 'github:' . $this->repository() : $this->manifest_url(),
			'channel'    => $this->channel(),
			'installed'  => $this->version,
			'available'  => $release['version'] ?? null,
			'update'     => null !== $release && version_compare( $release['version'], $this->version, '>' ),
		];
	}
}
