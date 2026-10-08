<?php
declare( strict_types=1 );

namespace RCRocket\Assets;

use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Filesystem;
use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Fonts localizer.
 *
 * Divi requests fonts from fonts.googleapis.com, which costs a DNS lookup, a
 * TLS handshake and a second round trip to fonts.gstatic.com before a single
 * glyph exists — all of it render-blocking, all of it before the browser knows
 * what the font even is. Serving the same files from your own origin collapses
 * that to a request on a connection that is already open.
 *
 * It also fixes the audit nothing else can reach: a font served by Google
 * carries a cache lifetime you do not control. Served locally, it carries
 * yours.
 *
 * Nothing here converts or re-encodes anything, so it is safe on hosts that
 * forbid server-side media processing.
 */
final class Fonts {

	public const CRON_HOOK     = 'rc-rocket/fonts/refresh';
	public const LOCALIZE_HOOK = 'rc-rocket/fonts/localize';

	private const INDEX_OPTION   = 'rcrocket_font_index';
	private const PENDING_OPTION = 'rcrocket_font_pending';

	/** Font files referenced straight from the page: remote URL => file and last sighting. */
	private const INLINE_OPTION  = 'rcrocket_font_inline';
	private const INLINE_PENDING = 'rcrocket_font_inline_pending';

	/** An inline font file not seen on any page for this long is deleted. */
	private const INLINE_FORGET = 60 * DAY_IN_SECONDS;

	/** A stylesheet that failed to download is not retried for this long. */
	private const RETRY_AFTER = DAY_IN_SECONDS;

	public function __construct(
		private Logger $logger,
		private array $config
	) {}

	public function hooks(): void {
		// Background jobs run even if localizing was switched off since they
		// were queued, so nothing is left half done.
		add_action( self::LOCALIZE_HOOK, [ $this, 'localize_pending' ] );
		add_action( self::CRON_HOOK, [ $this, 'refresh' ] );

		if ( empty( $this->config['localize'] ) ) {
			return;
		}

		add_filter( 'rc-rocket/html', [ $this, 'rewrite' ], 15 );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	public function directory(): string {
		$uploads = wp_get_upload_dir();

		return $uploads['basedir'] . '/rc-rocket/fonts';
	}

	public function directory_url(): string {
		$uploads = wp_get_upload_dir();

		return $uploads['baseurl'] . '/rc-rocket/fonts';
	}

	/**
	 * Swap every Google Fonts stylesheet that has already been localized for
	 * the local copy, then preload the font files it references so they are
	 * requested alongside the CSS rather than after it.
	 *
	 * A stylesheet seen for the first time is left pointing at Google and
	 * queued for a background download. A visitor never waits on Google.
	 */
	public function rewrite( string $html ): string {
		$stylesheets = str_contains( $html, 'fonts.googleapis.com' );
		$inline      = str_contains( $html, 'fonts.gstatic.com' );

		if ( ( ! $stylesheets && ! $inline ) || \RCRocket\Support\PageOptions::off( 'fonts' ) ) {
			return $html;
		}

		// Divi's "Improve Google Fonts Loading" (on by default) prints the
		// @font-face rules into the page itself, pointing at fonts.gstatic.com,
		// so there is no stylesheet link to swap. Those font files are
		// localized one by one instead.
		if ( $inline ) {
			$html = $this->rewrite_inline( $html );
		}

		if ( ! $stylesheets ) {
			return $this->drop_google_hints( $html );
		}

		$preloads = [];
		$index    = $this->index();
		$queue    = [];

		$result = preg_replace_callback(
			'#<link\b[^>]*href=["\'](?:https?:)?//fonts\.googleapis\.com/css[^"\']*["\'][^>]*>#i',
			function ( array $m ) use ( &$preloads, &$queue, $index ): string {
				if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $m[0], $href ) ) {
					return $m[0];
				}

				$remote = $this->normalize( html_entity_decode( $href[1] ) );
				$key    = self::key( $remote );
				$entry  = $index[ $key ] ?? null;

				if ( is_array( $entry ) && empty( $entry['failed'] ) && is_readable( $this->directory() . '/' . $key . '.css' ) ) {
					$preloads = array_merge( $preloads, $this->preload_candidates( $key, (array) ( $entry['files'] ?? [] ) ) );

					return sprintf(
						'<link rel="stylesheet" id="rcr-font-%s" href="%s" media="all">',
						esc_attr( $key ),
						esc_url( $this->directory_url() . '/' . $key . '.css?ver=' . (int) ( $entry['time'] ?? 0 ) )
					);
				}

				if ( ! is_array( $entry ) || time() - (int) ( $entry['failed'] ?? 0 ) > self::RETRY_AFTER ) {
					$queue[] = $remote;
				}

				return $m[0];
			},
			$html
		);

		if ( ! is_string( $result ) ) {
			return $html;
		}

		if ( $queue ) {
			$this->queue( $queue );
		}

		$result = $this->drop_google_hints( $result );

		if ( ! $preloads ) {
			return $result;
		}

		if ( ! empty( $this->config['preload'] ) ) {
			$tags = '';

			foreach ( array_slice( array_unique( $preloads ), 0, 4 ) as $file ) {
				$tags .= sprintf(
					'<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>',
					esc_url( $file )
				);
			}

			$result = HtmlPipeline::after_head_start( $result, $tags );
		}

		return $result;
	}

	/**
	 * The files worth preloading: the faces that cover basic Latin, upright
	 * before italic. Google lists cyrillic-ext, cyrillic, greek-ext and greek
	 * first, so the first four files are the ones an English page never uses,
	 * fetched at high priority ahead of the hero image.
	 *
	 * @param string[] $files Every file of the stylesheet, in its order.
	 * @return string[]
	 */
	private function preload_candidates( string $key, array $files ): array {
		$css = @file_get_contents( $this->directory() . '/' . $key . '.css' ); // phpcs:ignore

		if ( ! is_string( $css ) || ! preg_match_all( '#@font-face\s*\{([^}]*)\}#i', $css, $faces ) ) {
			return [];
		}

		$upright = [];
		$italic  = [];

		foreach ( $faces[1] as $face ) {
			if ( ! preg_match( '#url\(\s*["\']?([^"\')\s]+\.woff2)#i', $face, $url ) ) {
				continue;
			}

			// No unicode-range means the face covers everything.
			if ( preg_match( '#unicode-range\s*:\s*([^;]+)#i', $face, $range ) && ! self::covers_basic_latin( $range[1] ) ) {
				continue;
			}

			if ( preg_match( '#font-style\s*:\s*(italic|oblique)#i', $face ) ) {
				$italic[] = $url[1];
			} else {
				$upright[] = $url[1];
			}
		}

		// Only files this stylesheet really points at, as the index knows them.
		$known = array_flip( array_map( 'basename', $files ) );

		return array_values(
			array_filter(
				array_merge( $upright, $italic ),
				static fn( string $file ): bool => isset( $known[ basename( $file ) ] )
			)
		);
	}

	/** True when a unicode-range includes the letters A to z (U+0041-007A). */
	private static function covers_basic_latin( string $ranges ): bool {
		foreach ( explode( ',', $ranges ) as $range ) {
			if ( ! preg_match( '#U\+([0-9A-F?]+)(?:-([0-9A-F]+))?#i', trim( $range ), $r ) ) {
				continue;
			}

			$start = hexdec( str_replace( '?', '0', $r[1] ) );
			$end   = isset( $r[2] ) && '' !== $r[2] ? hexdec( $r[2] ) : hexdec( str_replace( '?', 'F', $r[1] ) );

			if ( $start <= 0x41 && $end >= 0x7A ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Nothing on the page talks to Google any more, so its preconnects are a
	 * wasted handshake.
	 */
	private function drop_google_hints( string $html ): string {
		$hint = '#<link\b(?=[^>]*(?:fonts\.googleapis\.com|fonts\.gstatic\.com))(?=[^>]*rel=["\']?(?:preconnect|dns-prefetch))[^>]*>\s*#i';

		$without = (string) preg_replace( $hint, '', $html );

		if ( preg_match( '#fonts\.googleapis\.com/css|fonts\.gstatic\.com/#i', $without ) ) {
			return $html;
		}

		return $without;
	}

	/**
	 * Point font files referenced from inline <style> blocks and preload
	 * hints at local copies. Files not downloaded yet stay on Google and are
	 * queued; the page is cleared once they are on disk.
	 */
	private function rewrite_inline( string $html ): string {
		$map   = $this->inline_map();
		$queue = [];
		$dirty = false;

		$local = function ( string $raw ) use ( &$map, &$queue, &$dirty ): ?string {
			$remote = $this->normalize( html_entity_decode( $raw ) );

			if ( ! str_starts_with( $remote, 'https://fonts.gstatic.com/' ) ) {
				return null;
			}

			$name = self::file_name( $remote );

			if ( ! is_readable( $this->directory() . '/' . $name ) ) {
				$queue[] = $remote;

				return null;
			}

			// Remember it is still in use, at most once a week per file.
			if ( (int) ( $map[ $remote ]['seen'] ?? 0 ) < time() - WEEK_IN_SECONDS ) {
				$map[ $remote ] = [
					'file' => $name,
					'seen' => time(),
				];
				$dirty          = true;
			}

			return $this->directory_url() . '/' . $name;
		};

		$html = (string) preg_replace_callback(
			'#<style\b[^>]*>.*?</style>#is',
			static function ( array $m ) use ( $local ): string {
				if ( ! str_contains( $m[0], 'fonts.gstatic.com' ) ) {
					return $m[0];
				}

				return (string) preg_replace_callback(
					'#url\(\s*([\'"]?)((?:https?:)?//fonts\.gstatic\.com/[^)\'"\s]+)\1\s*\)#i',
					static function ( array $u ) use ( $local ): string {
						$url = $local( $u[2] );

						return null === $url ? $u[0] : 'url(' . $url . ')';
					},
					$m[0]
				);
			},
			$html
		);

		// <link rel="preload" as="font" href="https://fonts.gstatic.com/...">
		$html = (string) preg_replace_callback(
			'#<link\b(?=[^>]*rel=["\']?preload)[^>]*href=["\']((?:https?:)?//fonts\.gstatic\.com/[^"\']+)["\'][^>]*>#i',
			static function ( array $m ) use ( $local ): string {
				$url = $local( $m[1] );

				return null === $url ? $m[0] : str_replace( $m[1], esc_url( $url ), $m[0] );
			},
			$html
		);

		if ( $dirty ) {
			update_option( self::INLINE_OPTION, $map, false );
		}

		if ( $queue ) {
			$pending = (array) get_option( self::INLINE_PENDING, [] );
			$merged  = array_slice( array_values( array_unique( array_merge( $pending, $queue ) ) ), 0, 60 );

			if ( $merged !== $pending ) {
				update_option( self::INLINE_PENDING, $merged, false );
			}

			if ( ! wp_next_scheduled( self::LOCALIZE_HOOK ) ) {
				wp_schedule_single_event( time() + 5, self::LOCALIZE_HOOK );
			}
		}

		return $html;
	}

	/** @return array<string, array{file:string, seen:int}> */
	private function inline_map(): array {
		$map = get_option( self::INLINE_OPTION, [] );

		return is_array( $map ) ? $map : [];
	}

	/** @param string[] $urls */
	private function queue( array $urls ): void {
		$pending = (array) get_option( self::PENDING_OPTION, [] );
		$merged  = array_values( array_unique( array_merge( $pending, $urls ) ) );

		// Only write when something is actually new: this runs on page views.
		if ( $merged !== $pending ) {
			update_option( self::PENDING_OPTION, array_slice( $merged, 0, 20 ), false );
		}

		if ( ! wp_next_scheduled( self::LOCALIZE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::LOCALIZE_HOOK );
		}
	}

	/** Background: download everything that was queued. */
	public function localize_pending(): void {
		$pending = (array) get_option( self::PENDING_OPTION, [] );

		delete_option( self::PENDING_OPTION );

		$localized = 0;

		foreach ( $pending as $remote ) {
			if ( $this->localize( (string) $remote ) ) {
				++$localized;
			}
		}

		$files = (array) get_option( self::INLINE_PENDING, [] );

		delete_option( self::INLINE_PENDING );

		if ( $files ) {
			$map = $this->inline_map();

			foreach ( array_slice( $files, 0, 60 ) as $remote ) {
				$remote = (string) $remote;

				if ( str_starts_with( $remote, 'https://fonts.gstatic.com/' ) && null !== $this->fetch_binary( $remote ) ) {
					$map[ $remote ] = [
						'file' => self::file_name( $remote ),
						'seen' => time(),
					];
					++$localized;
				}
			}

			update_option( self::INLINE_OPTION, $map, false );
		}

		// Pages already cached still point at Google. Let them re-render.
		if ( $localized > 0 ) {
			do_action( 'rc-rocket/purge/all', 'fonts-localized' );
		}
	}

	/**
	 * Download one stylesheet and every font file it references, then point
	 * the CSS at the local copies. The CSS is only replaced once everything
	 * it needs is on disk, so a refresh never leaves a page half-styled.
	 */
	private function localize( string $remote_url ): bool {
		$key   = self::key( $remote_url );
		$index = $this->index();
		$css   = $this->fetch_css( $remote_url );

		if ( null === $css ) {
			$index[ $key ] = [
				'remote' => $remote_url,
				'files'  => [],
				'failed' => time(),
			];
			update_option( self::INDEX_OPTION, $index, false );

			return false;
		}

		$files = [];

		$css = (string) preg_replace_callback(
			'#url\(\s*[\'"]?(https://fonts\.gstatic\.com/[^\)\'"]+)[\'"]?\s*\)#i',
			function ( array $m ) use ( &$files ): string {
				$local = $this->fetch_binary( $m[1] );

				if ( null === $local ) {
					return $m[0];
				}

				$files[] = $local;

				return 'url(' . $local . ')';
			},
			$css
		);

		// Never let a webfont cause invisible text.
		if ( ! str_contains( $css, 'font-display' ) ) {
			$css = (string) preg_replace( '/@font-face\s*\{/', '@font-face{font-display:swap;', $css );
		}

		if ( ! Filesystem::atomic_write( $this->directory() . '/' . $key . '.css', $css ) ) {
			return false;
		}

		$index[ $key ] = [
			'remote' => $remote_url,
			'files'  => $files,
			'time'   => time(),
		];

		update_option( self::INDEX_OPTION, $index, false );

		$this->logger->debug( 'Localized a Google Fonts stylesheet', [ 'files' => count( $files ) ] );

		return true;
	}

	/** Same stylesheet, same key, however the URL was escaped or prefixed. */
	private function normalize( string $url ): string {
		return str_starts_with( $url, '//' ) ? 'https:' . $url : (string) preg_replace( '#^http:#', 'https:', $url );
	}

	/** The local name of a downloaded font file: stable for the same URL. */
	private static function file_name( string $url ): string {
		$extension = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$extension = in_array( $extension, [ 'woff2', 'woff', 'ttf', 'otf', 'eot' ], true ) ? $extension : 'woff2';

		return substr( md5( $url ), 0, 16 ) . '.' . $extension;
	}

	private static function key( string $remote_url ): string {
		return substr( md5( $remote_url ), 0, 20 );
	}

	private function index(): array {
		return (array) get_option( self::INDEX_OPTION, [] );
	}

	/**
	 * Google serves different formats by user agent. Asking as a modern
	 * browser gets woff2, which is what we want to store; asking as PHP gets
	 * a truetype fallback three times the size.
	 */
	private function fetch_css( string $url ): ?string {
		if ( ! str_starts_with( $url, 'https://fonts.googleapis.com/css' ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->error( 'Could not fetch a Google Fonts stylesheet', [ 'url' => $url ] );

			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return '' === $body || ! str_contains( $body, '@font-face' ) ? null : $body;
	}

	private function fetch_binary( string $url ): ?string {
		$name = self::file_name( $url );
		$path = $this->directory() . '/' . $name;

		if ( is_readable( $path ) ) {
			return $this->directory_url() . '/' . $name;
		}

		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || ! Filesystem::atomic_write( $path, $body ) ) {
			return null;
		}

		return $this->directory_url() . '/' . $name;
	}

	/**
	 * Google rotates font files. Weekly, re-download every stylesheet in the
	 * background and swap it in place, then delete font files nothing
	 * references any more.
	 */
	public function refresh(): void {
		foreach ( $this->index() as $record ) {
			$remote = (string) ( $record['remote'] ?? '' );

			if ( '' !== $remote ) {
				$this->localize( $remote );
			}
		}

		// Inline font files no page has used for a while.
		$map = array_filter(
			$this->inline_map(),
			static fn( $entry ): bool => is_array( $entry ) && (int) ( $entry['seen'] ?? 0 ) > time() - self::INLINE_FORGET
		);
		update_option( self::INLINE_OPTION, $map, false );

		$this->remove_orphans();

		$this->logger->debug( 'Weekly font refresh complete' );
	}

	private function remove_orphans(): void {
		$keep = [];

		foreach ( $this->index() as $key => $record ) {
			$keep[ $key . '.css' ] = true;

			foreach ( (array) ( $record['files'] ?? [] ) as $file ) {
				$keep[ basename( (string) $file ) ] = true;
			}
		}

		foreach ( $this->inline_map() as $entry ) {
			$keep[ (string) ( $entry['file'] ?? '' ) ] = true;
		}

		foreach ( (array) glob( $this->directory() . '/*' ) as $path ) {
			$name = basename( (string) $path );

			if ( 'index.html' !== $name && ! isset( $keep[ $name ] ) && is_file( (string) $path ) ) {
				@unlink( (string) $path ); // phpcs:ignore
			}
		}
	}

	public function purge(): void {
		Filesystem::delete_tree( $this->directory() );
		delete_option( self::INDEX_OPTION );
		delete_option( self::PENDING_OPTION );
		delete_option( self::INLINE_OPTION );
		delete_option( self::INLINE_PENDING );
	}

	public function report(): array {
		$index = $this->index();
		$files = 0;

		foreach ( $index as $record ) {
			$files += count( (array) ( $record['files'] ?? [] ) );
		}

		return [
			'stylesheets' => count( $index ),
			'files'       => $files + count( $this->inline_map() ),
			'directory'   => $this->directory(),
			'writable'    => is_dir( $this->directory() ) ? is_writable( $this->directory() ) : is_writable( dirname( $this->directory() ) ),
		];
	}

	public static function defaults(): array {
		return [
			'localize' => true,
			'preload'  => true,
		];
	}
}
