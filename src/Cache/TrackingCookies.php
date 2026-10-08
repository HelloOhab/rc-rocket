<?php
/**
 * Keep analytics cookies set from PHP from making pages uncacheable.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

namespace RCRocket\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page that sends Set-Cookie is never stored by Kinsta, WP Engine,
 * Cloudflare or our own cache, so one plugin setting a tracking cookie from
 * PHP leaves every page uncached for every visitor. Meta Pixel for WordPress
 * does exactly that with _fbp and _fbc (Meta's "parameter builder").
 *
 * The same cookies are set in the browser by the tracking script itself, so
 * removing the server's copy on anonymous pages loses nothing a cached page
 * would have kept: a cached page never runs that PHP anyway.
 */
final class TrackingCookies {

	/** @param string[] $names */
	public function __construct( private array $names ) {}

	public function hooks(): void {
		// After HtmlPipeline (1) so this buffer is the innermost and runs
		// first, before the page cache reads the headers it is about to store.
		add_action( 'template_redirect', [ $this, 'start' ], 2 );
	}

	public function start(): void {
		if ( ! self::applies() ) {
			return;
		}

		ob_start(
			function ( string $html ): string {
				$this->strip();

				return $html;
			}
		);
	}

	/** Remove the named Set-Cookie headers, keep every other one. */
	public function strip(): int {
		if ( headers_sent() ) {
			return 0;
		}

		$keep    = [];
		$removed = 0;

		foreach ( headers_list() as $line ) {
			if ( 0 !== stripos( $line, 'set-cookie:' ) ) {
				continue;
			}

			if ( in_array( self::cookie_name( $line ), $this->names, true ) ) {
				++$removed;
			} else {
				$keep[] = $line;
			}
		}

		if ( 0 === $removed ) {
			return 0;
		}

		header_remove( 'Set-Cookie' );

		foreach ( $keep as $line ) {
			header( $line, false );
		}

		return $removed;
	}

	public static function cookie_name( string $line ): string {
		$value = trim( substr( $line, strlen( 'set-cookie:' ) ) );

		return trim( (string) strtok( $value, '=' ) );
	}

	/** Anonymous front-end page views only: what a page cache could store. */
	private static function applies(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore

		return in_array( $method, [ 'GET', 'HEAD' ], true );
	}
}
