<?php
declare( strict_types=1 );

namespace RCRocket\Js;

use RCRocket\Assets\Presets;
use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviAnimations;
use RCRocket\Support\PageOptions;
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

	/**
	 * Scripts a visitor interacts with straight away, matched against the
	 * script tag: header and mega menus, and form plugins. The first tap or
	 * keystroke is what releases delayed scripts, so delayed code arrives too
	 * late to handle it — a menu that ignores the first tap, a form that loses
	 * the first characters typed, upload fields that never start.
	 */
	private const INTERACTIVE = [
		'/plugins/divi-mad-menu/',
		'/plugins/divi-menu-pro/',
		'/plugins/divi-mega-pro/',
		'/plugins/megamenu/',
		'/plugins/megamenu-pro/',
		'/plugins/ubermenu/',
		'/plugins/responsive-menu/',
		'/plugins/wpforms/',
		'/plugins/wpforms-lite/',
		'/plugins/gravityforms/',
		'/plugins/contact-form-7/',
		'/plugins/ninja-forms/',
		'/plugins/fluentform/',
		'/plugins/fluentformpro/',
		'/plugins/formidable/',
		'/plugins/forminator/',
	];

	/** Properties every browser window already has. */
	private const BROWSER_GLOBALS = [
		'location', 'name', 'status', 'document', 'navigator', 'history', 'screen', 'parent', 'self', 'opener',
		'frames', 'origin', 'console', 'performance', 'localStorage', 'sessionStorage', 'open', 'close', 'print',
		'alert', 'confirm', 'prompt', 'fetch', 'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval',
		'requestAnimationFrame', 'addEventListener', 'removeEventListener', 'dispatchEvent', 'innerWidth',
		'innerHeight', 'outerWidth', 'outerHeight', 'scrollX', 'scrollY', 'pageXOffset', 'pageYOffset',
		'devicePixelRatio', 'event', 'scrollTo', 'scrollBy', 'getComputedStyle', 'matchMedia', 'window',
	];

	public function id(): string {
		return 'js';
	}

	public function label(): string {
		return __( 'JavaScript', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled'            => true,
			'defer'              => true,
			'delay'              => true,
			'delay_timeout'      => 6,
			'exclusions'         => Presets::js_exclusions(),
			// content-visibility clips anything that overlaps a section edge and
			// re-anchors position:fixed children to the section. Opt-in.
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
			$defer = function () use ( $settings, $safe ): void {
				if ( $safe->should_optimize() && ! PageOptions::off( 'defer' ) ) {
					$this->apply_defer_strategy( $settings );
				}
			};

			// Late, after everything is enqueued; and again just before the
			// footer prints, for scripts Divi enqueues while rendering modules.
			add_action( 'wp_enqueue_scripts', $defer, PHP_INT_MAX );
			add_action( 'wp_print_footer_scripts', $defer, 1 );
		}

		$delay = $settings->enabled( 'js.delay' );

		if ( $delay || $settings->enabled( 'js.defer' ) ) {
			add_filter( 'rc-rocket/html', function ( string $html ) use ( $container, $delay ): string {
				return $delay && ! PageOptions::off( 'delay' ) && ! self::commerce_page() ? $this->delay_scripts( $html, $container ) : $this->keep_dependencies_on_time( $html );
			}, 30 );
		}

		if ( $settings->enabled( 'js.lazy_render' ) ) {
			add_filter( 'rc-rocket/html', function ( string $html ) use ( $settings ): string {
				return PageOptions::off( 'lazy_render' ) || self::commerce_page() ? $html : $this->lazy_render( $html, $settings );
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

	/**
	 * Cart, checkout and account pages: payment and address scripts have to
	 * be ready when the visitor is, not after their first scroll.
	 */
	private static function commerce_page(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );
	}

	// ---------------------------------------------------------------- defer

	/**
	 * Mark scripts for deferring through WordPress's own loading strategy
	 * rather than by rewriting tags.
	 *
	 * Rewriting the tag is what breaks sites: an inline script printed right
	 * after a deferred one runs before it and finds nothing there ("jQuery is
	 * not defined"). Core's strategy knows about inline scripts and about
	 * dependents. When a script cannot safely be deferred — an inline script
	 * relies on it, or a blocking script depends on it — WordPress quietly
	 * keeps it blocking. Deferring as much as possible, never more.
	 */
	private function apply_defer_strategy( Settings $settings ): void {
		$scripts = wp_scripts();

		foreach ( $scripts->registered as $handle => $script ) {
			if ( ! is_string( $script->src ) || '' === $script->src ) {
				continue;
			}

			// Already decided by its author, or one of ours.
			if ( $scripts->get_data( $handle, 'strategy' ) || str_starts_with( (string) $handle, 'rcrocket' ) ) {
				continue;
			}

			if ( $this->excluded( $handle . ' ' . $script->src, $settings ) ) {
				continue;
			}

			$scripts->add_data( $handle, 'strategy', 'defer' );
		}
	}

	// ---------------------------------------------------------------- delay

	private function delay_scripts( string $html, Container $container ): string {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		/** @var Divi $divi */
		$divi = $container->get( 'divi' );

		// Whole <script> elements, so a tag-shaped string inside a script or a
		// JSON block is never mistaken for a tag of its own.
		$scripts = self::script_elements( $html );

		if ( null === $scripts ) {
			return $html;
		}

		// On a Divi site inline scripts always run on time: Divi 4 and Divi 5
		// both start their modules from inline snippets and data. A library
		// whose inline "after" snippet runs on time must then run on time
		// too, or the snippet calls into something that is not there yet.
		$inline_on_time = $divi->is_active();
		$has_after      = [];

		foreach ( $scripts as $script ) {
			if ( $inline_on_time && 'after' === $script['part'] ) {
				$has_after[ $script['handle'] ] = true;
			}
		}

		$inline = $inline_on_time ? self::inline_from( $scripts ) : [];
		$plan   = [];

		foreach ( $scripts as $i => $script ) {
			$plan[ $i ] = $this->decide( $script, $settings, $has_after, $inline_on_time, $inline );
		}

		$this->respect_dependencies( $scripts, $plan );

		// Elsewhere inline scripts are delayed with everything else, in order.
		// A file that stays on time needs its inline data and setup on time
		// as well (wpforms_settings, wpcf7): run without them, it fails.
		if ( ! $inline_on_time ) {
			$on_time = [];

			foreach ( $scripts as $i => $script ) {
				if ( '' !== $script['handle'] && 'file' === $script['part'] && 'delay' !== $plan[ $i ] ) {
					$on_time[ $script['handle'] ] = true;
				}
			}

			foreach ( $scripts as $i => $script ) {
				if ( 'delay' === $plan[ $i ] && 'file' !== $script['part'] && '' !== $script['part'] && isset( $on_time[ $script['handle'] ] ) ) {
					$plan[ $i ] = 'keep';
				}
			}
		}

		$delayed = 0;
		$origins = [];
		$result  = $html;

		// Rewrite from the end, so earlier offsets stay valid.
		foreach ( array_reverse( $scripts, true ) as $i => $script ) {
			if ( 'keep' === $plan[ $i ] ) {
				continue;
			}

			$tag = $script['tag'];

			if ( 'undefer' === $plan[ $i ] ) {
				$new = self::without_defer( $tag );
			} else {
				++$delayed;
				$attributes = $script['attributes'];
				$origin     = self::third_party_origin( $attributes );

				if ( '' !== $origin ) {
					$origins[ $origin ] = true;
				}

				// Remember a module script's type: restored without it, every
				// import statement in it is a syntax error.
				$is_module = (bool) preg_match( '#(?<![\w-])type\s*=\s*["\']?module#i', $attributes );

				$attributes = (string) preg_replace( '#(?<![\w-])type\s*=\s*["\']?[^"\'\s>]*["\']?#i', '', $attributes );
				$attributes = (string) preg_replace( '#(?<![\w-])src\s*=#i', 'data-rcr-src=', $attributes );

				$new = '<script type="rcrocket/delayed"' . ( $is_module ? ' data-rcr-type="module"' : '' ) . $attributes . '>';
			}

			$result = substr_replace( $result, $new, $script['offset'], strlen( $tag ) );
		}

		if ( 0 === $delayed ) {
			return $result;
		}

		$result = HtmlPipeline::before_body_end( $result, $this->loader( (int) $settings->get( 'js.delay_timeout', 6 ) ) );

		return $origins ? HtmlPipeline::after_head_start( $result, self::dns_prefetch( array_keys( $origins ) ) ) : $result;
	}

	/**
	 * keep, undefer (run in document order, on time) or delay.
	 *
	 * @param array<string, mixed>               $script
	 * @param array<string, bool>                $has_after
	 * @param array<int, array{0:int, 1:string}> $inline
	 */
	private function decide( array $script, Settings $settings, array $has_after, bool $inline_on_time, array $inline ): string {
		$attributes = $script['attributes'];

		// Already handled, or not JavaScript at all (JSON-LD, templates).
		if ( preg_match( '#(?<![\w-])type\s*=\s*["\']?(?!text/javascript|application/javascript|module)[^"\'\s>]+#i', $attributes ) ) {
			return 'keep';
		}

		// Our own scripts are never delayed, regardless of what the
		// user's exclusion list says.
		foreach ( [ 'rcr-no-delay', 'rcr-beacon', 'rcr-bg-video', 'rcr-embeds', 'rcr-delay-loader', 'rcr-divi-nonces', 'rcr-lcp', 'rcr-bg-lazy' ] as $own ) {
			if ( str_contains( $attributes, $own ) ) {
				return 'keep';
			}
		}

		// A nomodule script is skipped by every browser that supports modules,
		// and never fires load or error when added later: nothing to gain.
		// document.write() run after the page has loaded replaces the page.
		if ( preg_match( '#(?<![\w-])nomodule\b#i', $attributes ) || str_contains( $script['code'], 'document.write' ) ) {
			return 'keep';
		}

		// Menus and forms load normally: see INTERACTIVE.
		foreach ( self::INTERACTIVE as $interactive ) {
			if ( str_contains( $attributes, $interactive ) ) {
				return 'keep';
			}
		}

		// An inline script further down calls into this file, or loads
		// it itself. Plugins that print their own inline snippet (the
		// official Facebook pixel does) carry no "-js-after" id, so the
		// check below cannot see them.
		if ( $inline && $script['src'] && $this->needed_on_time( $attributes, $script['offset'], $inline ) ) {
			return 'undefer';
		}

		if ( $this->excluded( $attributes, $settings ) ) {
			return 'keep';
		}

		if ( 'file' === $script['part'] && isset( $has_after[ $script['handle'] ] ) ) {
			return 'keep';
		}

		// Divi puts module bootstrapping and configuration directly in
		// the page, and it must run in document order. Refuse to delay
		// any inline script on a Divi site — the risk is a dead hero,
		// slider or animation, and the gain is close to nothing.
		if ( $inline_on_time && ! $script['src'] ) {
			return 'keep';
		}

		// On a Divi site, delay third parties only. The theme, Divi's
		// own files and WordPress core render the page; delaying them
		// is how a hero or a menu ends up waiting for a mouse move.
		if ( $inline_on_time && self::first_party( $attributes ) ) {
			return 'keep';
		}

		return 'delay';
	}

	/**
	 * A file kept on time needs the files it depends on to be there first.
	 * Core only let a library be deferred because its dependents were
	 * deferred too: once a dependent runs on time, so must the library, or
	 * the dependent runs into a name that is not defined yet.
	 *
	 * @param array<int, array<string, mixed>> $scripts
	 * @param array<int, string>               $plan
	 */
	private function respect_dependencies( array $scripts, array &$plan ): void {
		if ( ! function_exists( 'wp_scripts' ) ) {
			return;
		}

		$registered = wp_scripts()->registered;
		$files      = [];

		foreach ( $scripts as $i => $script ) {
			if ( 'file' === $script['part'] && $script['src'] ) {
				$files[ $script['handle'] ] = $i;
			}
		}

		// Each pass can only promote a script (delay → keep → undefer), so
		// this ends; the cap guards against a malformed dependency graph.
		for ( $pass = 0; $pass < 20; $pass++ ) {
			$changed = false;

			foreach ( $files as $handle => $i ) {
				if ( 'delay' === $plan[ $i ] || ! isset( $registered[ $handle ] ) ) {
					continue;
				}

				foreach ( self::file_dependencies( $handle, $registered, $files ) as $dep ) {
					$j    = $files[ $dep ];
					$need = 'undefer' === $plan[ $i ] && preg_match( '#(?<![\w-])defer(?=[\s/>=])#i', $scripts[ $j ]['tag'] ) ? 'undefer' : 'keep';

					if ( 'delay' === $plan[ $j ] || ( 'undefer' === $need && 'keep' === $plan[ $j ] ) ) {
						$plan[ $j ] = $need;
						$changed    = true;
					}
				}
			}

			if ( ! $changed ) {
				return;
			}
		}
	}

	/**
	 * The dependencies of a handle that have a script element on the page,
	 * looking through handles that have none of their own ("jquery" is only
	 * a name for jquery-core and jquery-migrate).
	 *
	 * @param array<string, object> $registered
	 * @param array<string, int>    $files
	 * @return string[]
	 */
	private static function file_dependencies( string $handle, array $registered, array $files ): array {
		$found = [];
		$queue = (array) ( $registered[ $handle ]->deps ?? [] );
		$seen  = [];

		while ( $queue ) {
			$dep = (string) array_shift( $queue );

			if ( isset( $seen[ $dep ] ) ) {
				continue;
			}

			$seen[ $dep ] = true;

			if ( isset( $files[ $dep ] ) ) {
				$found[] = $dep;
			} elseif ( isset( $registered[ $dep ] ) ) {
				$queue = array_merge( $queue, (array) $registered[ $dep ]->deps );
			}
		}

		return $found;
	}

	/**
	 * Every <script> element: its opening tag, where it starts, whether it
	 * loads a file, and the WordPress handle it belongs to ("file" for
	 * handle-js, "extra"/"before"/"after"/"translations" for the inline
	 * parts core prints around it). Null when the document cannot be read.
	 *
	 * @return array<int, array{offset:int, tag:string, attributes:string, code:string, src:bool, handle:string, part:string}>|null
	 */
	private static function script_elements( string $html ): ?array {
		if ( false === preg_match_all( '#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$scripts = [];

		foreach ( $found as $element ) {
			$attributes = $element[1][0];
			$handle     = '';
			$part       = '';

			if ( preg_match( '#(?<![\w-])id\s*=\s*["\']([^"\']+?)-js(?:-(extra|before|after|translations))?["\']#i', $attributes, $id ) ) {
				$handle = $id[1];
				$part   = isset( $id[2] ) && '' !== $id[2] ? $id[2] : 'file';
			}

			$tag = '<script' . $attributes . '>';

			$scripts[] = [
				'offset'     => (int) $element[0][1],
				'tag'        => $tag,
				'attributes' => $attributes,
				'code'       => $element[2][0],
				'src'        => (bool) preg_match( '#(?<![\w-])src\s*=#i', $attributes ),
				'handle'     => $handle,
				'part'       => $part,
			];
		}

		return $scripts;
	}

	/**
	 * Inline JavaScript blocks, with where each starts.
	 *
	 * @param array<int, array<string, mixed>> $scripts
	 * @return array<int, array{0:int, 1:string}>
	 */
	private static function inline_from( array $scripts ): array {
		$inline = [];

		foreach ( $scripts as $script ) {
			if ( $script['src'] || '' === trim( $script['code'] ) ) {
				continue;
			}

			if ( preg_match( '#(?<![\w-])type\s*=\s*["\']?(?!text/javascript|application/javascript|module)[^"\'\s>]+#i', $script['attributes'] ) ) {
				continue;
			}

			$inline[] = [ $script['offset'], $script['code'] ];
		}

		return $inline;
	}

	/**
	 * A delayed script is invisible to the browser's preload scanner, so the
	 * DNS lookup for its server would otherwise start only after the first
	 * interaction. Starting it now costs a few hundred bytes and no bandwidth,
	 * and takes the lookup out of the wait when the scripts do run.
	 *
	 * @param string[] $origins
	 */
	private static function dns_prefetch( array $origins ): string {
		$out = '';

		foreach ( array_slice( $origins, 0, 8 ) as $origin ) {
			$out .= sprintf( '<link rel="dns-prefetch" href="%s">', esc_attr( $origin ) );
		}

		return $out;
	}

	/** "//www.googletagmanager.com" for a script on another server, else "". */
	private static function third_party_origin( string $attributes ): string {
		if ( ! preg_match( '#(?<![\w-])src\s*=\s*["\']?([^"\'\s>]+)#i', $attributes, $src ) ) {
			return '';
		}

		$host = strtolower( (string) wp_parse_url( html_entity_decode( $src[1] ), PHP_URL_HOST ) );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return '' === $host || $host === $own ? '' : '//' . $host;
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
  var events = ['keydown', 'mousemove', 'mousedown', 'pointerdown', 'touchstart', 'touchmove', 'wheel', 'scroll', 'click'];

  function run() {
    if (triggered) return;
    triggered = true;
    events.forEach(function (e) { window.removeEventListener(e, run, { passive: true }); });
    document.addEventListener = capture(document, docAdd);
    window.addEventListener = capture(window, winAdd);
    // Only when the page had finished loading before the scripts were put
    // back is the browser certain not to call these handlers itself.
    before = { done: document.readyState === 'complete', onload: window.onload, onready: document.onreadystatechange };

    var nodes = [].slice.call(document.querySelectorAll('script[type="rcrocket/delayed"]'));

    (function next(i) {
      if (i >= nodes.length) return finish();
      var old = nodes[i];
      var s = document.createElement('script');

      for (var a = 0; a < old.attributes.length; a++) {
        var attr = old.attributes[a];
        if (attr.name === 'type') continue;
        if (attr.name === 'data-rcr-type') { s.type = attr.value; continue; }
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

  // Handlers attached by the delayed scripts themselves, and only those, get
  // the lifecycle events they missed. Re-firing 'load' at everything would
  // initialise sliders and counters that already ran a second time.
  var late = { DOMContentLoaded: [], load: [], readystatechange: [] };
  var docAdd = document.addEventListener, winAdd = window.addEventListener;
  var before = {};

  function capture(target, original) {
    return function (type, fn, opts) {
      // Before the real event has happened, attach normally: it will fire.
      var missed = type === 'DOMContentLoaded' ? document.readyState !== 'loading' : document.readyState === 'complete';
      if (triggered && late[type] && missed && typeof fn === 'function') { late[type].push(fn); return; }
      return original.call(target, type, fn, opts);
    };
  }

  function call(fn, self, e) { try { fn.call(self, e); } catch (err) { setTimeout(function () { throw err; }); } }

  function finish() {
    document.addEventListener = docAdd;
    window.addEventListener = winAdd;
    var ready = new Event('DOMContentLoaded');
    var change = new Event('readystatechange');
    var load = new Event('load');
    late.DOMContentLoaded.forEach(function (fn) { call(fn, document, ready); });
    late.readystatechange.forEach(function (fn) { call(fn, document, change); });
    late.load.forEach(function (fn) { call(fn, window, load); });
    // "window.onload = ..." in a delayed script: the event it waits for is
    // long gone, so call it once, as the browser would have.
    if (before.done) {
      if (typeof document.onreadystatechange === 'function' && document.onreadystatechange !== before.onready) call(document.onreadystatechange, document, change);
      if (typeof window.onload === 'function' && window.onload !== before.onload) call(window.onload, window, load);
    }
  }

  events.forEach(function (e) { window.addEventListener(e, run, { passive: true }); });
  setTimeout(run, {$timeout_ms});
})();
</script>
HTML;
	}

	/** The theme, Divi, the Divi builder plugin, or WordPress core. */
	private static function first_party( string $attributes ): bool {
		if ( ! preg_match( '#(?<![\w-])src\s*=\s*["\']?([^"\'\s>]+)#i', $attributes, $src ) ) {
			return false;
		}

		$src = html_entity_decode( $src[1] );

		if ( preg_match( '#/wp-content/themes/|/wp-includes/|/plugins/divi-builder/|/et-cache/#i', $src ) ) {
			return true;
		}

		return (bool) preg_match( '#(?<![\w-])id\s*=\s*["\']?(et[-_]|divi)#i', $attributes );
	}

	// ---------------------------------------------------- inline dependents

	/**
	 * With delay off, deferred scripts still need the same protection: core
	 * only knows about inline snippets added through wp_add_inline_script(),
	 * not ones a plugin echoes itself, so it defers a library whose snippet
	 * then runs first.
	 */
	private function keep_dependencies_on_time( string $html ): string {
		$scripts = self::script_elements( $html );

		if ( null === $scripts ) {
			return $html;
		}

		$inline = self::inline_from( $scripts );

		if ( ! $inline ) {
			return $html;
		}

		$plan = [];

		foreach ( $scripts as $i => $script ) {
			$deferred   = (bool) preg_match( '#(?<![\w-])defer(?=[\s/>=])#i', $script['tag'] );
			$plan[ $i ] = $script['src'] && $deferred && $this->needed_on_time( $script['attributes'], $script['offset'], $inline ) ? 'undefer' : 'keep';
		}

		// Nothing here is delayed; respect_dependencies() only ever turns a
		// deferred library into an on-time one when its dependent is.
		$this->respect_dependencies( $scripts, $plan );

		$result = $html;

		foreach ( array_reverse( $scripts, true ) as $i => $script ) {
			if ( 'undefer' === $plan[ $i ] ) {
				$result = substr_replace( $result, self::without_defer( $script['tag'] ), $script['offset'], strlen( $script['tag'] ) );
			}
		}

		return $result;
	}

	/**
	 * True when an inline script runs code from this file, or loads this
	 * file itself. Either way, holding the file back breaks the page or
	 * fetches it twice.
	 *
	 * @param array<int, array{0:int, 1:string}> $inline
	 */
	private function needed_on_time( string $attributes, int $offset, array $inline ): bool {
		if ( ! preg_match( '#(?<![\w-])src\s*=\s*["\']?([^"\'\s>]+)#i', $attributes, $src ) ) {
			return false;
		}

		$url = (string) strtok( html_entity_decode( $src[1] ), '?#' );

		// The address without its scheme, as a loader snippet would write it.
		$bare = (string) preg_replace( '#^(?:https?:)?//#i', '', $url );

		if ( strlen( $bare ) > 8 ) {
			foreach ( $inline as [ , $code ] ) {
				if ( str_contains( $code, $bare ) ) {
					return true;
				}
			}
		}

		$globals = $this->script_globals( $url );

		if ( ! $globals ) {
			return false;
		}

		$pattern = '#(?<![\w$.])(?:window\.)?(?:' . implode( '|', array_map( static fn( string $g ): string => preg_quote( $g, '#' ), $globals ) ) . ')\s*[.(\[]#';

		foreach ( $inline as [ $start, $code ] ) {
			if ( $start > $offset && preg_match( $pattern, $code ) ) {
				return true;
			}
		}

		return false;
	}

	/** Same tag, run in document order. */
	private static function without_defer( string $tag ): string {
		return (string) preg_replace( '#\s+defer(?:\s*=\s*(["\']?)defer\1)?(?=[\s/>])#i', '', $tag );
	}

	/**
	 * Names a local script assigns on window or declares at the top level.
	 * Only files under wp-content are read, and the answer is kept until the
	 * file changes.
	 *
	 * @return string[]
	 */
	private function script_globals( string $url ): array {
		$path = self::local_script_path( $url );

		if ( null === $path ) {
			return [];
		}

		$size = (int) @filesize( $path ); // phpcs:ignore

		if ( $size < 1 || $size > 1048576 ) {
			return [];
		}

		static $memo = [];

		$key = 'rcrocket_jsg_' . md5( $path . '|' . (int) @filemtime( $path ) . '|' . $size ); // phpcs:ignore

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $memo[ $key ] = $cached;
		}

		$code    = (string) @file_get_contents( $path ); // phpcs:ignore
		$globals = [];

		if ( preg_match_all( '#(?<![\w$.])window\.([A-Za-z_$][\w$]*)\s*=(?!=)#', $code, $m ) ) {
			$globals = $m[1];
		}

		if ( preg_match_all( '#^(?:var|let|const|function|class)\s+([A-Za-z_$][\w$]*)#m', $code, $m ) ) {
			$globals = array_merge( $globals, $m[1] );
		}

		// Short names are minifier output, and would match half the page.
		// Assigning a browser property (window.location = url, window.onscroll
		// = fn) uses it; it does not define it.
		$globals = array_values(
			array_unique(
				array_filter(
					$globals,
					static fn( string $g ): bool => strlen( $g ) >= 4 && ! in_array( $g, self::BROWSER_GLOBALS, true ) && ! preg_match( '/^on[a-z]+$/', $g )
				)
			)
		);

		set_transient( $key, $globals, WEEK_IN_SECONDS );

		return $memo[ $key ] = $globals;
	}

	private static function local_script_path( string $url ): ?string {
		$root = (string) realpath( WP_CONTENT_DIR );

		if ( '' === $root || ! str_ends_with( strtolower( $url ), '.js' ) ) {
			return null;
		}

		foreach ( [ content_url(), (string) preg_replace( '#^https?:#', '', content_url() ), '/wp-content' ] as $prefix ) {
			if ( '' !== $prefix && str_starts_with( $url, $prefix . '/' ) ) {
				$path = (string) realpath( WP_CONTENT_DIR . rawurldecode( substr( $url, strlen( $prefix ) ) ) );

				return '' !== $path && str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) && is_readable( $path ) ? $path : null;
			}
		}

		return null;
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
