<?php
declare( strict_types=1 );

namespace RCRocket\Js;

use RCRocket\Assets\Presets;
use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviAnimations;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module C — JavaScript delivery and INP.
 *
 * Delaying scripts is the single most effective and most dangerous thing a
 * performance plugin does on a Divi site. The design here reflects that: an
 * exclusion list that already knows every Divi handle that must never be
 * touched, a hard refusal to delay anything jQuery-shaped on Divi 4, and a
 * loader that fires on the first sign of a human rather than a fixed timer.
 */
final class JsModule implements Module {

	public function id(): string {
		return 'js';
	}

	public function label(): string {
		return __( 'JavaScript', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled'            => true,
			'defer'              => false,
			'delay'              => false,
			'delay_timeout'      => 6,
			'exclusions'         => Presets::js_exclusions(),
			'lazy_render'        => false,
			'lazy_selectors'     => Presets::lazy_render_selectors(),
			'lazy_intrinsic'     => '640px',
			'preconnect'         => [],
			'divi_animations'    => DiviAnimations::defaults(),
		];
	}

	public function register( Container $container ): void {}

	public function boot( Container $container ): void {
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		// is_feed(), is_singular() and friends do not exist yet at plugins_loaded.
		// Only the query-free part of the kill switch can be consulted here;
		// the full check happens inside each callback, all of which run at
		// template_redirect or later.
		if ( $safe->is_active() ) {
			return;
		}

		/** @var Settings $settings */
		$settings = $container->get( 'settings' );

		if ( $settings->enabled( 'js.defer' ) ) {
			add_filter( 'script_loader_tag', function ( string $tag, string $handle, string $src ) use ( $settings, $safe ): string {
				return $safe->should_optimize() ? $this->defer_tag( $tag, $handle, $src, $settings ) : $tag;
			}, 10, 3 );
		}

		if ( $settings->enabled( 'js.delay' ) ) {
			add_filter( 'rc-rocket/html', function ( string $html ) use ( $container ): string {
				return $this->delay_scripts( $html, $container );
			}, 30 );
		}

		if ( $settings->enabled( 'js.lazy_render' ) ) {
			add_filter( 'rc-rocket/html', function ( string $html ) use ( $settings ): string {
				return $this->lazy_render( $html, $settings );
			}, 20 );
		}

		$animations = (array) $settings->get( 'js.divi_animations', [] );

		if ( ! empty( $animations['enabled'] ) ) {
			add_filter(
				'rc-rocket/html',
				static function ( string $html ) use ( $container, $animations ): string {
					return ( new DiviAnimations( $container->get( 'divi' ), $animations ) )->rewrite( $html );
				},
				22
			);
		}

		$preconnect = array_filter( (array) $settings->get( 'js.preconnect', [] ) );

		if ( $preconnect ) {
			add_filter( 'wp_resource_hints', static function ( array $hints, string $relation ) use ( $preconnect ): array {
				return 'preconnect' === $relation ? array_merge( $hints, $preconnect ) : $hints;
			}, 10, 2 );
		}
	}

	// ---------------------------------------------------------------- defer

	private function defer_tag( string $tag, string $handle, string $src, Settings $settings ): string {
		if ( is_admin() || str_contains( $tag, ' defer' ) || str_contains( $tag, ' async' ) ) {
			return $tag;
		}

		// A module script is already deferred by specification.
		if ( str_contains( $tag, 'type="module"' ) ) {
			return $tag;
		}

		if ( $this->excluded( $handle . ' ' . $src, $settings ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	// ---------------------------------------------------------------- delay

	private function delay_scripts( string $html, Container $container ): string {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		/** @var Divi $divi */
		$divi = $container->get( 'divi' );

		$delayed = 0;

		$result = preg_replace_callback(
			'#<script\b([^>]*)>#i',
			function ( array $m ) use ( $settings, $divi, &$delayed ): string {
				$attributes = $m[1];
				$whole      = $m[0];

				// Already handled, or not JavaScript at all.
				if ( preg_match( '#type\s*=\s*["\'](?!text/javascript|application/javascript|module)[^"\']*["\']#i', $attributes ) ) {
					return $whole;
				}

				// Our own scripts are never delayed, regardless of what the
				// user's exclusion list says.
				foreach ( [ 'rcr-no-delay', 'rcr-beacon', 'rcr-bg-video', 'rcr-embeds', 'rcr-delay-loader', 'rcr-divi-nonces' ] as $own ) {
					if ( str_contains( $attributes, $own ) ) {
						return $whole;
					}
				}

				if ( $this->excluded( $attributes, $settings ) ) {
					return $whole;
				}

				// Divi 4 puts inline module bootstrapping directly in the page
				// and it must run in document order with jQuery. Refuse to
				// delay any inline script on Divi 4 — the risk is a dead
				// slider, and the gain is close to nothing.
				if ( ! $divi->is_divi_five() && $divi->is_active() && ! str_contains( $attributes, 'src=' ) ) {
					return $whole;
				}

				++$delayed;

				$attributes = (string) preg_replace( '#\btype\s*=\s*["\'][^"\']*["\']#i', '', $attributes );
				$attributes = (string) preg_replace( '#\bsrc\s*=#i', 'data-rcr-src=', $attributes );

				return '<script type="rcrocket/delayed"' . $attributes . '>';
			},
			$html
		);

		if ( ! is_string( $result ) || 0 === $delayed ) {
			return $html;
		}

		return HtmlPipeline::before_body_end( $result, $this->loader( (int) $settings->get( 'js.delay_timeout', 6 ) ) );
	}

	/**
	 * The loader.
	 *
	 * Scripts are restored in document order, one at a time, waiting for each
	 * external file so dependency order survives. Then the events that
	 * frameworks listen for are replayed, because a script that boots on
	 * DOMContentLoaded will otherwise never boot at all.
	 */
	private function loader( int $timeout ): string {
		$timeout_ms = max( 1, $timeout ) * 1000;

		return <<<HTML
<script id="rcr-delay-loader">
(function () {
  var triggered = false;
  var events = ['keydown', 'mousemove', 'touchstart', 'touchmove', 'wheel', 'scroll', 'click'];

  function run() {
    if (triggered) return;
    triggered = true;
    events.forEach(function (e) { window.removeEventListener(e, run, { passive: true }); });

    var nodes = [].slice.call(document.querySelectorAll('script[type="rcrocket/delayed"]'));

    (function next(i) {
      if (i >= nodes.length) return finish();
      var old = nodes[i];
      var s = document.createElement('script');

      for (var a = 0; a < old.attributes.length; a++) {
        var attr = old.attributes[a];
        if (attr.name === 'type') continue;
        s.setAttribute(attr.name === 'data-rcr-src' ? 'src' : attr.name, attr.value);
      }
      if (!s.src) s.textContent = old.textContent;

      if (s.src) {
        s.onload = s.onerror = function () { next(i + 1); };
        old.parentNode.replaceChild(s, old);
      } else {
        old.parentNode.replaceChild(s, old);
        next(i + 1);
      }
    })(0);
  }

  function finish() {
    // Replay the lifecycle for anything that booted on these.
    try {
      document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
      window.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
      window.dispatchEvent(new Event('load', { bubbles: true }));
      if (window.jQuery) { window.jQuery(document).trigger('ready'); }
    } catch (e) {}
  }

  events.forEach(function (e) { window.addEventListener(e, run, { passive: true }); });
  setTimeout(run, {$timeout_ms});
})();
</script>
HTML;
	}

	// ---------------------------------------------------------- lazy render

	/**
	 * content-visibility tells the browser not to lay out or paint offscreen
	 * sections until they are needed. On a long Divi page with a dozen
	 * sections this is the cheapest INP and TBT win available, and unlike
	 * delaying scripts it cannot break behaviour — only rendering timing.
	 */
	private function lazy_render( string $html, Settings $settings ): string {
		$selectors = array_filter( array_map( 'trim', (array) $settings->get( 'js.lazy_selectors', [] ) ) );

		if ( ! $selectors ) {
			return $html;
		}

		$intrinsic = (string) $settings->get( 'js.lazy_intrinsic', '640px' );
		$intrinsic = (string) preg_replace( '/[^0-9a-z.%]/i', '', $intrinsic );

		$css = sprintf(
			'@media (prefers-reduced-motion: no-preference){%s{content-visibility:auto;contain-intrinsic-size:auto %s;}}',
			implode( ',', array_map( static fn( string $s ): string => wp_strip_all_tags( $s ), $selectors ) ),
			'' === $intrinsic ? '640px' : $intrinsic
		);

		return HtmlPipeline::after_head_start( $html, '<style id="rcr-lazy-render">' . $css . '</style>' );
	}

	// ------------------------------------------------------------ shared

	private function excluded( string $haystack, Settings $settings ): bool {
		$exclusions = (array) $settings->get( 'js.exclusions', [] );

		foreach ( $exclusions as $needle ) {
			$needle = trim( (string) $needle );

			if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
