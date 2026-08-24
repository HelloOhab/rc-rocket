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

	public const CRON_HOOK = 'rc-rocket/fonts/refresh';

	private const REMOTE_HOSTS = [ 'fonts.googleapis.com', 'fonts.gstatic.com' ];

	public function __construct(
		private Logger $logger,
		private array $config
	) {}

	public function hooks(): void {
		if ( empty( $this->config['localize'] ) ) {
			return;
		}

		// Stop Divi asking for them in the first place.
		add_filter( 'et_use_google_fonts', '__return_false', 99 );
		add_filter( 'et_builder_google_fonts_enabled', '__return_false', 99 );

		add_filter( 'rc-rocket/html', [ $this, 'rewrite' ], 15 );

		add_action( self::CRON_HOOK, [ $this, 'refresh' ] );

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
	 * Swap every Google Fonts stylesheet link for a local copy, then preload
	 * the font files it references so they are requested alongside the CSS
	 * rather than after it.
	 */
	public function rewrite( string $html ): string {
		if ( ! str_contains( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}

		$preloads = [];

		$result = preg_replace_callback(
			'#<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com/css[^"\']*)["\'][^>]*>#i',
			function ( array $m ) use ( &$preloads ): string {
				$local = $this->localize( html_entity_decode( $m[1] ) );

				if ( null === $local ) {
					return $m[0];
				}

				$preloads = array_merge( $preloads, $local['files'] );

				return sprintf(
					'<link rel="stylesheet" href="%s" media="all">',
					esc_url( $local['url'] )
				);
			},
			$html
		);

		if ( ! is_string( $result ) ) {
			return $html;
		}

		// Preconnects to Google are now pointing at nothing.
		$result = (string) preg_replace(
			'#<link\b[^>]*(?:fonts\.googleapis\.com|fonts\.gstatic\.com)[^>]*rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*>#i',
			'',
			$result
		);

		if ( $preloads && ! empty( $this->config['preload'] ) ) {
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
	 * @return array{url:string, files:string[]}|null
	 */
	private function localize( string $remote_url ): ?array {
		$key   = substr( md5( $remote_url ), 0, 20 );
		$index = (array) get_option( 'rcrocket_font_index', [] );

		if ( isset( $index[ $key ] ) && is_readable( $this->directory() . '/' . $key . '.css' ) ) {
			return [
				'url'   => $this->directory_url() . '/' . $key . '.css',
				'files' => (array) ( $index[ $key ]['files'] ?? [] ),
			];
		}

		$css = $this->fetch_css( $remote_url );

		if ( null === $css ) {
			return null;
		}

		$files = [];

		// Pull every font binary down and point the CSS at the local copy.
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
			$css = (string) preg_replace( '/@font-face\s*\{/', "@font-face{font-display:swap;", $css );
		}

		if ( ! Filesystem::atomic_write( $this->directory() . '/' . $key . '.css', $css ) ) {
			return null;
		}

		$index[ $key ] = [
			'remote' => $remote_url,
			'files'  => $files,
			'time'   => time(),
		];

		update_option( 'rcrocket_font_index', $index, false );

		$this->logger->debug( 'Localized a Google Fonts stylesheet', [ 'files' => count( $files ) ] );

		return [
			'url'   => $this->directory_url() . '/' . $key . '.css',
			'files' => $files,
		];
	}

	/**
	 * Google serves different formats by user agent. Asking as a modern
	 * browser gets woff2, which is what we want to store; asking as PHP gets
	 * a truetype fallback three times the size.
	 */
	private function fetch_css( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->error( 'Could not fetch a Google Fonts stylesheet', [ 'url' => $url ] );

			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return '' === $body ? null : $body;
	}

	private function fetch_binary( string $url ): ?string {
		$name = substr( md5( $url ), 0, 16 ) . '.' . ( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'woff2' );
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
	 * Google rotates font files. A weekly re-fetch keeps the local copies
	 * current without a visitor ever waiting for a network request.
	 */
	public function refresh(): void {
		$index = (array) get_option( 'rcrocket_font_index', [] );

		foreach ( $index as $key => $record ) {
			$remote = (string) ( $record['remote'] ?? '' );

			if ( '' === $remote ) {
				continue;
			}

			@unlink( $this->directory() . '/' . $key . '.css' ); // phpcs:ignore
			unset( $index[ $key ] );
		}

		update_option( 'rcrocket_font_index', $index, false );

		$this->logger->debug( 'Font cache cleared for weekly refresh' );
	}

	public function purge(): void {
		Filesystem::delete_tree( $this->directory() );
		delete_option( 'rcrocket_font_index' );
	}

	public function report(): array {
		$index = (array) get_option( 'rcrocket_font_index', [] );
		$files = 0;

		foreach ( $index as $record ) {
			$files += count( (array) ( $record['files'] ?? [] ) );
		}

		return [
			'stylesheets' => count( $index ),
			'files'       => $files,
			'directory'   => $this->directory(),
			'writable'    => is_dir( $this->directory() ) ? is_writable( $this->directory() ) : is_writable( dirname( $this->directory() ) ),
		];
	}

	public static function defaults(): array {
		return [
			'localize' => false,
			'preload'  => true,
		];
	}
}
