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

		if ( '' === $url ) {
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

	private function from_github(): ?array {
		$body = $this->fetch( sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repository() ) );

		if ( null === $body ) {
			return null;
		}

		$release = json_decode( $body, true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
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

	private function fetch( string $url, bool $binary = false ): ?string {
		$args = [
			'timeout'    => 20,
			'user-agent' => 'RCRocket/' . $this->version . '; ' . home_url( '/' ),
			'headers'    => [ 'Accept' => $binary ? 'application/octet-stream' : 'application/json' ],
		];

		if ( '' !== $this->token() && str_contains( $url, 'github.com' ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $this->token();
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'Update check failed', [ 'error' => $response->get_error_message() ] );

			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->logger->error( 'Update check returned an unexpected status', [ 'status' => $code, 'url' => $url ] );

			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	// ------------------------------------------------------- WordPress glue

	public function inject( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
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
				'description' => '' !== $release['description']
					? wpautop( esc_html( $release['description'] ) )
					: __( 'Performance tuning built around Divi. Asset control, media delivery, JavaScript timing and a safety net that switches itself off when something breaks.', 'rc-rocket' ),
				'changelog'   => wpautop( esc_html( $release['changelog'] ) ),
			],
		];
	}

	/**
	 * A private GitHub asset cannot be fetched by URL alone, so download it
	 * here with the auth header and hand WordPress a local file.
	 */
	public function download_private( mixed $reply, string $package, mixed $upgrader, array $hook_extra = [] ): mixed {
		if ( '' === $this->token() || ! str_contains( $package, 'api.github.com' ) ) {
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
		 * Off by default. A plugin that rewrites every page on 30 client sites
		 * should reach them because someone decided it should, not because a
		 * tag was pushed.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'rc-rocket/update/auto', false );
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
			'installed'  => $this->version,
			'available'  => $release['version'] ?? null,
			'update'     => null !== $release && version_compare( $release['version'], $this->version, '>' ),
		];
	}
}
