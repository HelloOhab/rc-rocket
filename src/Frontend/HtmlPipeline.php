<?php
declare( strict_types=1 );

namespace RCRocket\Frontend;

use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One output buffer, shared by every module that rewrites HTML.
 *
 * Plugins that each call ob_start() separately are the reason "turn off the
 * other optimization plugin" is standard support advice: buffers nest, order
 * becomes undefined, and one module's regex runs against another's half-
 * rewritten output. Here there is a single buffer and an explicit priority
 * chain, so media rewriting always sees clean markup and script delaying
 * always runs last.
 *
 * Priorities:
 *   10 — media (lazy loading, dimensions, LCP hints)
 *   20 — lazy render
 *   30 — script delay (must be last: it rewrites tags the others matched on)
 *   40 — Divi nonce hydration
 */
final class HtmlPipeline {

	private bool $started = false;

	private float $began = 0.0;

	public function __construct(
		private SafeMode $safe_mode,
		private Logger $logger,
		private bool $debug_comment = true
	) {}

	public function hooks(): void {
		add_action( 'template_redirect', [ $this, 'start' ], 1 );
	}

	public function start(): void {
		if ( $this->started || ! $this->safe_mode->should_optimize() ) {
			return;
		}

		$this->started = true;
		$this->began   = microtime( true );

		ob_start( [ $this, 'process' ] );
	}

	public function process( string $html ): string {
		// Not an HTML document: JSON endpoints, sitemaps, XML, downloads.
		if ( strlen( $html ) < 255 || ! preg_match( '/<html[\s>]/i', $html ) || ! str_contains( $html, '</body>' ) ) {
			return $html;
		}

		$original = $html;

		try {
			/**
			 * The rewrite chain. Modules attach here instead of opening their
			 * own buffers.
			 *
			 * @param string $html
			 */
			$html = (string) apply_filters( 'rc-rocket/html', $html );
		} catch ( \Throwable $e ) {
			// A rewrite that throws must never take the page with it. Serve the
			// original markup and record why.
			$this->logger->error(
				'HTML pipeline aborted, original markup served',
				[
					'message' => $e->getMessage(),
					'file'    => basename( $e->getFile() ) . ':' . $e->getLine(),
				]
			);

			return $original;
		}

		// A rewrite that loses most of the document is a bug, not an
		// optimization. Refuse the result rather than shipping a broken page.
		if ( strlen( $html ) < ( strlen( $original ) * 0.5 ) ) {
			$this->logger->error(
				'HTML pipeline output rejected: document shrank implausibly',
				[
					'before' => strlen( $original ),
					'after'  => strlen( $html ),
				]
			);

			return $original;
		}

		if ( $this->debug_comment ) {
			$html .= sprintf(
				"\n<!-- RC Rocket · optimized in %.1fms -->",
				( microtime( true ) - $this->began ) * 1000
			);
		}

		return $html;
	}

	/**
	 * Insert markup immediately before </body>, case-insensitively, once.
	 */
	public static function before_body_end( string $html, string $insert ): string {
		$position = strripos( $html, '</body>' );

		if ( false === $position ) {
			return $html . $insert;
		}

		return substr( $html, 0, $position ) . $insert . substr( $html, $position );
	}

	/**
	 * Swap out everything a tag rewrite must not reach — scripts, JSON,
	 * <noscript>, <template>, <textarea>, <style> and comments — for inert
	 * placeholders. An <img> inside a JavaScript string or a JSON value is
	 * text, and adding attributes to it breaks the script. Undo with
	 * unmask(). Returns the input unchanged if the document cannot be read.
	 *
	 * @return array{0:string, 1:array<string, string>}
	 */
	public static function mask( string $html ): array {
		$kept   = [];
		$masked = preg_replace_callback(
			'#<!--.*?-->|<(script|noscript|template|textarea|style)\b[^>]*>.*?</\1\s*>#is',
			static function ( array $m ) use ( &$kept ): string {
				$key          = "\x1ARCR" . count( $kept ) . "\x1A";
				$kept[ $key ] = $m[0];

				return $key;
			},
			$html
		);

		return is_string( $masked ) ? [ $masked, $kept ] : [ $html, [] ];
	}

	/** @param array<string, string> $kept */
	public static function unmask( string $html, array $kept ): string {
		return $kept ? strtr( $html, $kept ) : $html;
	}

	/** Insert markup immediately after the opening <head>. */
	public static function after_head_start( string $html, string $insert ): string {
		$result = (string) preg_replace( '/(<head[^>]*>)/i', '$1' . str_replace( '$', '\$', $insert ), $html, 1 );

		// A fragment with no <head> would otherwise swallow the insertion
		// whole, losing the stylesheet or preload it was carrying.
		if ( $result === $html ) {
			return $insert . $html;
		}

		return $result;
	}

	/** The first ~1500 characters of the body, used to guess above-the-fold. */
	public static function above_fold_boundary( string $html, int $chars = 4000 ): int {
		$body = stripos( $html, '<body' );

		return ( false === $body ? 0 : $body ) + $chars;
	}
}
