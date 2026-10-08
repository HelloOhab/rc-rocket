<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Integrations\Divi;
use RCRocket\Support\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi background video manager.
 *
 * Divi drops a bare <video autoplay muted loop> into the section with no
 * poster and no preload hint. The browser therefore starts pulling several
 * megabytes of video before it has painted anything, and on a throttled mobile
 * connection that single decision can own the entire LCP. There is no Divi
 * setting for it — the responsive controls swap which video plays, not whether
 * one plays at all.
 *
 * What this does instead:
 *
 *   1. Gives the video a poster, so something paints immediately. The poster
 *      becomes the LCP element and it is a normal image.
 *   2. Sets preload="none" so the video stops competing with critical CSS.
 *   3. Detaches the sources and reattaches them from JavaScript only when the
 *      viewport is wide enough, the connection is fast enough, Save-Data is
 *      off, and the visitor has not asked for reduced motion.
 *   4. Waits for the section to be near the viewport before loading at all.
 *
 * The safety rule that governs all of it: a background video is only ever
 * withheld when a poster exists to take its place. A black hole where the hero
 * used to be is a worse outcome than a slow hero, so without a poster the
 * video loads exactly as Divi intended.
 */
final class Video {

	/**
	 * Divi's background video markup, across both engines.
	 *
	 * Divi 4 wraps it in .et_pb_section_video_bg; Divi 5 uses its own
	 * container. Rather than depend on either class surviving a release, the
	 * match is on the attribute signature that only a background video has:
	 * autoplay together with muted and loop. A visitor-controlled video never
	 * carries all three.
	 */
	private const SIGNATURE = '#<video\b([^>]*)>(.*?)</video>#is';

	public function __construct( private Context $context, private ?Divi $divi = null ) {}

	public function rewrite( string $html, array $config ): string {
		if ( ! str_contains( $html, '<video' ) ) {
			return $html;
		}

		$poster    = $this->poster_for( (array) ( $config['posters'] ?? [] ) );
		$divi_map  = $this->divi instanceof Divi && $this->divi->is_active()
			? $this->divi->background_media_map()
			: [];
		$touched      = 0;
		$has_gate     = false;
		$first_poster = '';

		$result = preg_replace_callback(
			self::SIGNATURE,
			function ( array $m ) use ( $config, $poster, $divi_map, $html, &$touched, &$has_gate, &$first_poster ): string {
				$attributes = $m[1];
				$inner      = $m[2];
				$offset     = (int) strpos( $html, $m[0] );

				$in_divi_bg = $this->in_divi_wrapper( $html, $offset );

				// A video with controls is meant to be watched, whatever else it
				// does. Never withhold it, on any screen. Divi 4 section
				// backgrounds are the exception: they come from WordPress's
				// video shortcode, which always emits controls="controls", and
				// Divi hides them.
				if ( ! $in_divi_bg && preg_match( '#(?<![\w-])controls\b#i', $attributes ) ) {
					return $m[0];
				}

				if ( ! $this->is_background_video( $attributes ) && ! $in_divi_bg ) {
					return $m[0];
				}

				// Already handled, or explicitly left alone by the site.
				if ( str_contains( $attributes, 'data-rcr-video' ) || preg_match( '#\b(skip-lazy|no-lazy|data-no-lazy|data-rcr-skip)\b#i', $attributes ) ) {
					return $m[0];
				}

				++$touched;

				// Poster first: everything else depends on having a fallback.
				$has_poster = (bool) preg_match( '#\bposter\s*=\s*["\'][^"\']+["\']#i', $attributes );

				// Divi already knows the fallback image for this video, because
				// whoever built the page chose one. Prefer that over anything
				// configured globally: it is per-section and always correct.
				$section  = $this->divi_fallback( $attributes . $inner, $divi_map );
				$fallback = '' !== $section ? $section : $poster;

				if ( ! $has_poster && '' !== $fallback ) {
					$attributes .= sprintf( ' poster="%s"', esc_url( $fallback ) );
					$has_poster  = true;
				}

				if ( 1 === $touched && preg_match( '#\bposter\s*=\s*["\']([^"\']+)["\']#i', $attributes, $p ) ) {
					$first_poster = html_entity_decode( $p[1] );
				}

				if ( ! empty( $config['preload_none'] ) ) {
					$attributes = (string) preg_replace( '#\bpreload\s*=\s*["\'][^"\']*["\']#i', '', $attributes );
					$attributes .= ' preload="none"';
				}

				// Withholding the video is only safe when a poster can stand in
				// for it — and only when the site has asked for it. Divi sizes
				// a background video to cover its section from the video's
				// own dimensions; with the sources held back there are none,
				// and the hero shrinks to a small frame in one corner. By
				// default the video therefore loads exactly as Divi intends,
				// and only gains a poster so something paints at once.
				if ( ! $has_poster || empty( $config['withhold'] ) ) {
					return '<video' . $attributes . '>' . $inner . '</video>';
				}

				$has_gate    = true;
				$attributes .= ' data-rcr-video="1"';

				// Detach the sources. The browser cannot request what it cannot
				// see, and JavaScript decides later whether it ever should.
				$inner = (string) preg_replace(
					'#(<source\b[^>]*?)\bsrc\s*=#i',
					'$1data-rcr-src=',
					$inner
				);

				// Some Divi builds put the source on the video element itself.
				$attributes = (string) preg_replace( '#\bsrc\s*=#i', 'data-rcr-src=', $attributes );

				return '<video' . $attributes . '>' . $inner . '</video>';
			},
			$html
		);

		if ( ! is_string( $result ) || 0 === $touched ) {
			return $html;
		}

		// A poster that is about to become the LCP element deserves the same
		// treatment as any other hero image — but only the first video's, and
		// only one this page actually uses.
		if ( '' !== $first_poster && ! empty( $config['preload_poster'] ) ) {
			$result = HtmlPipeline::after_head_start(
				$result,
				sprintf( '<link rel="preload" as="image" href="%s" fetchpriority="high">', esc_url( $first_poster ) )
			);
		}

		if ( $has_gate ) {
			$result = HtmlPipeline::before_body_end( $result, $this->loader( $config ) );
		}

		return $result;
	}

	/**
	 * A background video autoplays and loops. Nothing a visitor is meant to
	 * control does both.
	 *
	 * `muted` used to be required here as well, which quietly excluded every
	 * Divi 4 site: Divi 4 renders background video through WordPress's video
	 * shortcode, which emits autoplay and loop but applies muted from
	 * JavaScript afterwards. The attribute is never in the markup we see, so
	 * requiring it meant no poster and a black hole where the fallback should
	 * have been.
	 */
	private function is_background_video( string $attributes ): bool {
		$autoplay = (bool) preg_match( '#\bautoplay\b#i', $attributes );
		$loop     = (bool) preg_match( '#\bloop\b#i', $attributes );

		return $autoplay && $loop;
	}

	/** Divi 4 wraps background video in a span whose class ends in _video_bg. */
	private function in_divi_wrapper( string $html, int $offset ): bool {
		$before = substr( $html, max( 0, $offset - 400 ), min( 400, $offset ) );

		return (bool) preg_match( '#class=["\'][^"\']*_video_bg[^"\']*["\']#i', $before );
	}

	/**
	 * Find the fallback image Divi already associated with this video.
	 *
	 * @param array<string, string> $map
	 */
	private function divi_fallback( string $haystack, array $map ): string {
		if ( ! $map ) {
			return '';
		}

		if ( ! preg_match_all( '#(?:data-rcr-src|src)\s*=\s*["\']([^"\']+)["\']#i', $haystack, $matches ) ) {
			return '';
		}

		foreach ( $matches[1] as $src ) {
			$key = Divi::media_key( html_entity_decode( $src ) );

			if ( isset( $map[ $key ] ) ) {
				return (string) $map[ $key ];
			}
		}

		return '';
	}

	/**
	 * @param array<int, array{template?:string, url?:string}> $posters
	 */
	private function poster_for( array $posters ): string {
		foreach ( $posters as $poster ) {
			if ( ! is_array( $poster ) || empty( $poster['url'] ) ) {
				continue;
			}

			$template = trim( (string) ( $poster['template'] ?? '' ) );

			if ( '' === $template || $this->context->matches( [ $template ] ) ) {
				return (string) $poster['url'];
			}
		}

		return '';
	}

	/**
	 * The gate.
	 *
	 * Conditions are checked before a single byte is requested, and the video
	 * is only attached when the section is close to the viewport. If any check
	 * fails the poster simply stays, which is a complete and reasonable hero.
	 */
	private function loader( array $config ): string {
		$min_width   = max( 0, (int) ( $config['disable_below'] ?? 980 ) );
		$save_data   = ! empty( $config['respect_save_data'] ) ? 'true' : 'false';
		$fast_only   = ! empty( $config['require_fast_connection'] ) ? 'true' : 'false';
		$reduced     = ! empty( $config['respect_reduced_motion'] ) ? 'true' : 'false';
		$lazy        = ! empty( $config['lazy_until_visible'] ) ? 'true' : 'false';

		return <<<HTML
<script id="rcr-bg-video">
(function () {
  var videos = document.querySelectorAll('video[data-rcr-video]');
  if (!videos.length) return;

  var MIN_WIDTH = {$min_width};
  var SAVE_DATA = {$save_data};
  var FAST_ONLY = {$fast_only};
  var REDUCED   = {$reduced};
  var LAZY      = {$lazy};

  function connection() {
    return navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
  }

  function allowed() {
    // Viewport: a background video on a phone is decoration nobody asked for.
    if (MIN_WIDTH > 0 && window.innerWidth < MIN_WIDTH) return false;

    var c = connection();
    if (c) {
      if (SAVE_DATA && c.saveData) return false;
      if (FAST_ONLY && /(^|-)(2g|slow-2g|3g)$/.test(c.effectiveType || '')) return false;
    }

    if (REDUCED && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;

    return true;
  }

  function activate(video) {
    if (video.dataset.rcrLoaded) return;
    video.dataset.rcrLoaded = '1';

    if (video.hasAttribute('data-rcr-src')) {
      video.setAttribute('src', video.getAttribute('data-rcr-src'));
      video.removeAttribute('data-rcr-src');
    }

    var sources = video.querySelectorAll('source[data-rcr-src]');
    for (var i = 0; i < sources.length; i++) {
      sources[i].setAttribute('src', sources[i].getAttribute('data-rcr-src'));
      sources[i].removeAttribute('data-rcr-src');
    }

    // Divi 4 applies muted from JavaScript after the fact, and a browser will
    // not autoplay a video it believes has sound.
    video.muted = true;
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');
    video.setAttribute('preload', 'auto');
    video.load();

    var playing = video.play();
    if (playing && playing.catch) {
      // Autoplay refusal is normal and not an error worth reporting.
      playing.catch(function () {});
    }
  }

  var started = false;

  function start() {
    if (started || !allowed()) return;   // Poster stays. Nothing is downloaded.
    started = true;

    if (!LAZY || !('IntersectionObserver' in window)) {
      for (var i = 0; i < videos.length; i++) activate(videos[i]);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          activate(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '200px 0px' });

    for (var j = 0; j < videos.length; j++) observer.observe(videos[j]);
  }

  // Wait for first paint so the poster is never delayed by this decision.
  if (window.requestIdleCallback) {
    requestIdleCallback(start, { timeout: 1500 });
  } else {
    setTimeout(start, 200);
  }

  // A visitor who rotates a phone into landscape crosses the width threshold.
  var resizing;
  window.addEventListener('resize', function () {
    clearTimeout(resizing);
    resizing = setTimeout(start, 250);
  }, { passive: true });
})();
</script>
HTML;
	}

	public static function defaults(): array {
		return [
			'enabled'                 => true,
			'withhold'                => false,
			'preload_none'            => false,
			'preload_poster'          => true,
			'lazy_until_visible'      => true,
			'disable_below'           => 980,
			'respect_save_data'       => true,
			'require_fast_connection' => true,
			'respect_reduced_motion'  => true,
			'posters'                 => [],
		];
	}
}
