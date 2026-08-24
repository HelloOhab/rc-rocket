<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Support\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the advanced-cache.php drop-in and the config file it reads.
 *
 * The drop-in is what lets a cached page be answered before WordPress loads.
 * The generated server rules go one better and answer without PHP at all —
 * this is the fallback for hosts where you cannot touch the vhost.
 */
final class Dropin {

	private const SIGNATURE = 'RC Rocket advanced-cache drop-in';

	public function __construct( private string $cache_dir ) {}

	public function config_file(): string {
		return $this->cache_dir . '/config.json';
	}

	public function dropin_file(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	public function source_file(): string {
		return RCROCKET_DIR . 'dropins/advanced-cache.php';
	}

	/**
	 * Persist the subset of settings the drop-in needs. Written on every save,
	 * because a stale config file is a cache that silently stops matching.
	 */
	public function write_config( array $cache_settings ): bool {
		$config = array_merge(
			Key::config_defaults(),
			$cache_settings,
			[
				'cache_dir'  => $this->cache_dir,
				'plugin_dir' => rtrim( RCROCKET_DIR, '/' ),
				'home_url'   => home_url( '/' ),
				'written'    => time(),
			]
		);

		return Filesystem::atomic_write(
			$this->config_file(),
			(string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}

	public function install( bool $host_manages_cache = false ): bool {
		// A managed host's own drop-in is not a conflict to resolve; it is the
		// correct owner. Overwriting it would break the site's real cache.
		if ( $host_manages_cache || $this->has_foreign_dropin() ) {
			return false;
		}

		$source = @file_get_contents( $this->source_file() ); // phpcs:ignore

		if ( ! is_string( $source ) ) {
			return false;
		}

		if ( ! Filesystem::atomic_write( $this->dropin_file(), $source ) ) {
			return false;
		}

		$this->toggle_wp_cache( true );

		return true;
	}

	public function uninstall(): void {
		if ( $this->is_ours() ) {
			@unlink( $this->dropin_file() ); // phpcs:ignore
		}

		$this->toggle_wp_cache( false );
	}

	public function status(): array {
		return [
			'installed'      => $this->is_ours(),
			'foreign_dropin' => $this->has_foreign_dropin(),
			'wp_cache'       => defined( 'WP_CACHE' ) && WP_CACHE,
			'content_writable' => is_writable( WP_CONTENT_DIR ),
			'config_written' => is_readable( $this->config_file() ),
			'wp_config_writable' => (bool) ( $this->wp_config_path() && is_writable( (string) $this->wp_config_path() ) ),
		];
	}

	public function is_ours(): bool {
		$file = $this->dropin_file();

		if ( ! is_readable( $file ) ) {
			return false;
		}

		return str_contains( (string) file_get_contents( $file ), self::SIGNATURE );
	}

	public function has_foreign_dropin(): bool {
		return file_exists( $this->dropin_file() ) && ! $this->is_ours();
	}

	private function wp_config_path(): ?string {
		$candidates = [ ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ];

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * WP_CACHE must be true before wp-settings.php runs or the drop-in is never
	 * included. Editing wp-config.php is invasive, so it is done once, marked,
	 * and cleanly reversed on deactivation.
	 */
	private function toggle_wp_cache( bool $enable ): bool {
		$path = $this->wp_config_path();

		if ( null === $path || ! is_writable( $path ) ) {
			return false;
		}

		$contents = (string) file_get_contents( $path );
		$marker   = "define( 'WP_CACHE', true ); // Added by RC Rocket.\n";

		// Remove any line we previously added, plus any existing WP_CACHE define.
		$contents = (string) preg_replace( '/^.*Added by RC Rocket.*$\R?/m', '', $contents );

		if ( $enable ) {
			if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $contents ) ) {
				return true; // Host or another plugin already defines it.
			}

			$contents = (string) preg_replace( '/^<\?php\s*\R/', "<?php\n" . $marker, $contents, 1 );
		}

		return false !== @file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore
	}
}
