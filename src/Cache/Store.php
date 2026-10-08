<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Support\Filesystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disk-backed page store.
 *
 * Every entry is three files: the HTML, an optional gzip twin (so the web
 * server can serve pre-compressed bytes without PHP), and a .meta sidecar.
 * Surrogate keys are stored as an inverted index of empty files, which makes
 * "purge everything tagged post-42" a directory listing rather than a scan of
 * the whole cache.
 */
final class Store {

	public function __construct(
		private string $cache_dir,
		private array $config
	) {}

	public function cache_dir(): string {
		return $this->cache_dir;
	}

	/** @var string[] URLs whose entries were deleted during this request. */
	private array $deleted_urls = [];

	/**
	 * @param string[] $surrogate_keys
	 * @param string[] $headers Response headers to replay on a hit.
	 */
	public function put( string $url, string $html, array $surrogate_keys, int $ttl, string $variant, array $headers = [] ): bool {
		$host = Key::host_from_url( $url );
		$uri  = $this->uri_from_url( $url );
		$hash = Key::hash( $host, $uri, $variant, $this->config );

		if ( null === $hash ) {
			return false;
		}

		$paths = Key::paths( $this->cache_dir, $hash );

		if ( ! Filesystem::atomic_write( $paths['html'], $html ) ) {
			return false;
		}

		if ( ! empty( $this->config['gzip'] ) && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );

			if ( is_string( $gz ) ) {
				Filesystem::atomic_write( $paths['gz'], $gz );
			}
		}

		$mirror = ! empty( $this->config['server_delivery'] ) ? $this->write_mirror( $host, $uri, $variant, $html ) : null;

		$meta = [
			'url'     => $url,
			'variant' => $variant,
			'created' => time(),
			'ttl'     => $ttl,
			'expires' => time() + $ttl,
			'bytes'   => strlen( $html ),
			'keys'    => array_values( array_unique( array_merge( $surrogate_keys, [ Key::url_key( $host, $uri ) ] ) ) ),
			'headers' => array_values( $headers ),
			'mirror'  => $mirror,
		];

		Filesystem::atomic_write( $paths['meta'], (string) wp_json_encode( $meta ) );

		foreach ( $meta['keys'] as $key ) {
			$this->index( (string) $key, $hash );
		}

		return true;
	}

	public function has( string $hash ): bool {
		return is_readable( Key::paths( $this->cache_dir, $hash )['html'] );
	}

	public function meta( string $hash ): ?array {
		$file = Key::paths( $this->cache_dir, $hash )['meta'];

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	public function delete( string $hash ): bool {
		$paths   = Key::paths( $this->cache_dir, $hash );
		$meta    = $this->meta( $hash );
		$deleted = false;

		foreach ( [ 'html', 'gz', 'meta' ] as $part ) {
			if ( file_exists( $paths[ $part ] ) && @unlink( $paths[ $part ] ) ) { // phpcs:ignore
				$deleted = true;
			}
		}

		// The web server serves the mirror without asking PHP, so an entry
		// that leaves its mirror behind is never actually invalidated.
		$mirror = is_array( $meta ) ? (string) ( $meta['mirror'] ?? '' ) : '';

		if ( '' !== $mirror && str_starts_with( $mirror, $this->cache_dir . '/mirror/' ) ) {
			@unlink( $mirror . '/index.html' ); // phpcs:ignore
			@unlink( $mirror . '/index.html.gz' ); // phpcs:ignore
		}

		if ( $deleted && is_array( $meta ) && ! empty( $meta['url'] ) ) {
			$this->deleted_urls[] = (string) $meta['url'];
		}

		return $deleted;
	}

	/**
	 * URLs that lost their cache entry since the last call. The preloader uses
	 * this to re-warm exactly what a purge removed.
	 *
	 * @return string[]
	 */
	public function take_deleted_urls(): array {
		$urls               = array_values( array_unique( $this->deleted_urls ) );
		$this->deleted_urls = [];

		return $urls;
	}

	/**
	 * Delete every entry tagged with a surrogate key. This is the primitive
	 * that makes granular invalidation possible — no globbing, no full scan.
	 */
	public function delete_by_key( string $key ): int {
		$dir = $this->key_dir( $key );

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted = 0;

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || 'index.html' === $entry ) {
				continue;
			}

			if ( $this->delete( (string) $entry ) ) {
				++$deleted;
			}

			@unlink( $dir . '/' . $entry ); // phpcs:ignore
		}

		@rmdir( $dir ); // phpcs:ignore

		return $deleted;
	}

	public function flush(): int {
		$deleted = Filesystem::delete_tree( $this->cache_dir . '/pages' );

		Filesystem::delete_tree( $this->cache_dir . '/keys' );
		Filesystem::delete_tree( $this->cache_dir . '/mirror' );

		return $deleted;
	}

	/** @return array{files:int, bytes:int, oldest:?int} */
	public function stats(): array {
		$measured = Filesystem::measure( $this->cache_dir . '/pages' );

		return $measured + [ 'oldest' => null ];
	}

	/**
	 * Drop entries whose TTL has elapsed. Called from cron so that a cold cache
	 * never grows without bound on sites with millions of URLs.
	 */
	public function purge_expired(): int {
		$root = $this->cache_dir . '/pages';

		if ( ! is_dir( $root ) ) {
			return 0;
		}

		$deleted  = 0;
		$now      = time();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			if ( ! $item->isFile() || 'meta' !== $item->getExtension() ) {
				continue;
			}

			$meta = json_decode( (string) file_get_contents( $item->getPathname() ), true );

			if ( is_array( $meta ) && isset( $meta['expires'] ) && (int) $meta['expires'] < $now ) {
				$this->delete( $item->getBasename( '.meta' ) );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Flat mirror used by the generated nginx/Apache rules.
	 *
	 * The sharded hash layout is unreadable to a web server, so cached pages are
	 * also written to a path the server can resolve with try_files alone. Costs
	 * one extra write; buys a response that never starts PHP.
	 */
	private function write_mirror( string $host, string $uri, string $variant, string $html ): ?string {
		$parts  = explode( '|', $variant );
		$scheme = $parts[0] ?? 'https';
		$device = in_array( 'mobile', $parts, true ) ? 'mobile' : 'desktop';

		// Only anonymous, query-free pages are safe to hand to the web server:
		// it cannot evaluate the rest of our rules. Every bucket after the
		// scheme must be a device or "anon" — a logged-in user's bucket or a
		// cookie bucket (currency, language) is one visitor's page.
		foreach ( array_slice( $parts, 1 ) as $part ) {
			if ( ! in_array( $part, [ 'mobile', 'desktop', 'anon' ], true ) ) {
				return null;
			}
		}

		if ( str_contains( $uri, '?' ) ) {
			return null;
		}

		$path = trim( Key::path_only( $uri ), '/' );
		$dir  = $this->cache_dir . '/mirror/' . $this->safe_segment( $host ) . '/' . $scheme . '/' . $device;
		$dir .= '' === $path ? '' : '/' . $this->safe_path( $path );

		Filesystem::atomic_write( $dir . '/index.html', $html );

		if ( ! empty( $this->config['gzip'] ) && function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );

			if ( is_string( $gz ) ) {
				Filesystem::atomic_write( $dir . '/index.html.gz', $gz );
			}
		}

		return $dir;
	}

	private function safe_segment( string $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $value );
	}

	private function safe_path( string $path ): string {
		$segments = array_map( [ $this, 'safe_segment' ], explode( '/', $path ) );

		// "." and ".." would step out of the mirror directory.
		return implode( '/', array_filter( $segments, static fn( string $s ): bool => '' !== $s && '.' !== $s && '..' !== $s ) );
	}

	private function index( string $key, string $hash ): void {
		$dir = $this->key_dir( $key );

		if ( ! Filesystem::ensure_dir( $dir ) ) {
			return;
		}

		$marker = $dir . '/' . $hash;

		if ( ! file_exists( $marker ) ) {
			@touch( $marker ); // phpcs:ignore
		}
	}

	private function key_dir( string $key ): string {
		return $this->cache_dir . '/keys/' . md5( $key );
	}

	private function uri_from_url( string $url ): string {
		$parts = (array) wp_parse_url( $url );
		$uri   = (string) ( $parts['path'] ?? '/' );

		if ( ! empty( $parts['query'] ) ) {
			$uri .= '?' . $parts['query'];
		}

		return $uri;
	}
}
