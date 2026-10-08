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

	public const SECRET_OPTION = 'rcrocket_preload_secret';

	public function config_file(): string {
		return $this->cache_dir . '/config.php';
	}

	/**
	 * Shared between the preloader and the drop-in, so a preload request can
	 * prove it is ours. Without it the bypass header is a free cache-buster
	 * for anyone who reads the source.
	 */
	public static function preload_secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
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
		$defaults = Key::config_defaults();

		// Only what the drop-in reads. Everything else stays in the database.
		$config = array_merge(
			$defaults,
			array_intersect_key( $cache_settings, $defaults ),
			[
				'cache_dir'      => $this->cache_dir,
				'plugin_dir'     => rtrim( RCROCKET_DIR, '/' ),
				'preload_secret' => self::preload_secret(),
				'written'        => time(),
			]
		);

		$php = "<?php\n// RC Rocket drop-in configuration. Generated; do not edit.\ndefined( 'ABSPATH' ) || exit;\n\nreturn "
			. var_export( $config, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			. ";\n";

		$written = Filesystem::atomic_write( $this->config_file(), $php );

		$this->protect_directory();

		// Earlier versions wrote a world-readable JSON copy.
		if ( $written && file_exists( $this->cache_dir . '/config.json' ) ) {
			@unlink( $this->cache_dir . '/config.json' ); // phpcs:ignore
		}

		if ( $written && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $this->config_file(), true ); // phpcs:ignore
		}

		return $written;
	}

	/**
	 * Apache only: refuse the bookkeeping files outright. Cached HTML has to
	 * stay reachable because the generated rewrite rules serve it directly.
	 * nginx ignores this file, which is why nothing secret is stored in a
	 * format nginx would print.
	 */
	private function protect_directory(): void {
		$file = $this->cache_dir . '/.htaccess';

		if ( file_exists( $file ) ) {
			return;
		}

		Filesystem::atomic_write(
			$file,
			"# RC Rocket\n<FilesMatch \"\\.(log|meta|php|json)\$\">\n\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n</FilesMatch>\n"
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

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $this->dropin_file(), true ); // phpcs:ignore
		}

		// Without WP_CACHE the drop-in is never loaded: report it, rather
		// than writing cache files nothing will ever serve.
		return $this->toggle_wp_cache( true );
	}

	/**
	 * The drop-in is copied once, at install. Bring an installed copy up to
	 * date with the one this version ships.
	 */
	public function refresh(): bool {
		if ( ! $this->is_ours() ) {
			return false;
		}

		$source = @file_get_contents( $this->source_file() ); // phpcs:ignore

		if ( ! is_string( $source ) || $source === @file_get_contents( $this->dropin_file() ) ) { // phpcs:ignore
			return false;
		}

		if ( ! Filesystem::atomic_write( $this->dropin_file(), $source ) ) {
			return false;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $this->dropin_file(), true ); // phpcs:ignore
		}

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
		return file_exists( $this->dropin_file() ) && ! $this->is_ours() && ! $this->is_leftover();
	}

	/**
	 * A drop-in nobody is using. WP Rocket empties its drop-in on
	 * deactivation instead of deleting it, and an inactive WP Rocket's file
	 * serves nothing. Treating either as "another plugin's cache" would leave
	 * a site that switched from WP Rocket with no page cache at all.
	 */
	private function is_leftover(): bool {
		$contents = @file_get_contents( $this->dropin_file() ); // phpcs:ignore

		if ( ! is_string( $contents ) ) {
			return false;
		}

		if ( '' === trim( (string) preg_replace( '/^<\?php\s*$/m', '', $contents ) ) ) {
			return true;
		}

		return ! defined( 'WP_ROCKET_VERSION' ) && 1 === preg_match( '/WP[ _-]?Rocket/i', $contents );
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
			// Already on for this request: whatever turns it on stays in charge.
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return true;
			}

			// Live (uncommented) defines only.
			$live = '/^[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(\w+)\s*\)\s*;.*$/mi';

			// A define whose value is an expression (getenv(), a cast) is not
			// ours to rewrite, and a second define would only raise a warning.
			if ( ! preg_match( $live, $contents ) && preg_match( '/^[ \t]*define\s*\(\s*[\'"]WP_CACHE[\'"]/mi', $contents ) ) {
				return false;
			}

			if ( preg_match( $live, $contents, $define ) ) {
				if ( 'false' !== strtolower( $define[1] ) ) {
					return true; // Host or another plugin already turns it on.
				}

				// WP Rocket switches the cache off on its way out. That one is
				// ours to turn back on; anyone else's "false" is deliberate.
				if ( ! preg_match( '/WP[ _-]?Rocket/i', $define[0] ) ) {
					return false;
				}

				$contents = (string) preg_replace( $live, rtrim( $marker ), $contents, 1 );

				return false !== @file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore
			}

			// After the opening tag, whether or not something shares its line.
			$updated = (string) preg_replace( '/^<\?php\b[ \t]*\R?/', "<?php\n" . $marker, $contents, 1 );

			if ( $updated === $contents ) {
				return false;
			}

			$contents = $updated;
		}

		return false !== @file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore
	}
}
