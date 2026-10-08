<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vimeo and YouTube embeds.
 *
 * A self-hosted background video costs you the video. A Vimeo background costs
 * you the video plus the player: a few hundred kilobytes of JavaScript spread
 * across three origins, each needing its own DNS lookup and TLS handshake
 * before a single frame exists. On a throttled connection the handshakes alone
 * can outweigh the media.
 *
 * Two different problems, handled differently:
 *
 *   Background embeds (autoplay, muted, looping, decorative) are gated the same
 *   way a self-hosted background video is — poster underneath, nothing loaded
 *   until the viewport, connection and motion preference all agree.
 *
 *   Content embeds that a visitor is meant to press play on get a facade: the
 *   poster and a play button, with the real player attached on the first click.
 *   Nobody pays for a player they never start.
 *
 * Posters come from the provider's own oEmbed endpoint, so there is nothing to
 * upload and nothing to keep in sync.
 */
final class Embeds {

	private const POSTER_OPTION = 'rcrocket_embed_posters';

	private const PROVIDERS = [
		'vimeo'   => '#player\.vimeo\.com/video/(\d+)#i',
		'youtube' => '#(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{6,})#i',
	];

	public function __construct( private Logger $logger ) {}

	public function rewrite( string $html, array $config ): string {
		if ( ! str_contains( $html, '<iframe' ) ) {
			return $html;
		}

		// A page that drives its players from JavaScript (a custom Unmute
		// button, a Vimeo or YouTube Player API call) needs the real iframe
		// there from the start. Holding it back breaks those controls, so
		// such pages keep their embeds exactly as rendered.
		if ( self::page_scripts_players( $html ) ) {
			return $html;
		}

		$html = self::keep_divi_controlled( $html );

		$touched = 0;
		$has_bg  = false;
		$fitvids = str_contains( $html, 'et_pb_' ) || str_contains( $html, 'fitvids' ) || str_contains( $html, 'fitVids' );

		$result = preg_replace_callback(
			'#<iframe\b([^>]*)>(.*?)</iframe>#is',
			function ( array $m ) use ( $config, $fitvids, &$touched, &$has_bg ): string {
				$attributes = $m[1];

				if ( str_contains( $attributes, 'data-rcr-embed' ) || self::opted_out( $attributes ) ) {
					return $m[0];
				}

				if ( ! preg_match( '#(?<![\w-])src\s*=\s*["\']([^"\']+)["\']#i', $attributes, $src_match ) ) {
					return $m[0];
				}

				$src      = html_entity_decode( $src_match[1] );
				$provider = $this->provider_for( $src );

				// Not a provider we know, or one being scripted through its API.
				if ( null === $provider || preg_match( '#[?&](api|enablejsapi|player_id)=#i', $src ) ) {
					return $m[0];
				}

				$is_background = $this->looks_like_background( $src, $attributes );

				// A player that starts by itself but is not decoration (a
				// muted showreel with controls) is meant to be seen at once,
				// on every screen. Holding it back or turning it into a
				// click-to-play facade would both change what the page does.
				if ( ! $is_background && self::autoplays( $src ) ) {
					return $m[0];
				}

				if ( $is_background && empty( $config['gate_background_embeds'] ) ) {
					return $m[0];
				}

				if ( ! $is_background && empty( $config['facade_embeds'] ) ) {
					return $m[0];
				}

				$poster = $this->poster( $provider['name'], $provider['id'], $config );

				// Without a poster there is nothing to show in the embed's
				// place, so leave it alone rather than leave a hole.
				if ( '' === $poster ) {
					return $m[0];
				}

				$src = $this->tune( $src, $provider['name'], $config, $is_background );

				++$touched;
				$has_bg = $has_bg || $is_background;

				// The iframe stays exactly where it was, with every attribute
				// it had: same box, same classes, same parent. Wrapping it in
				// anything changes the layout — a responsive video wrapper
				// positions the iframe absolutely, and a wrapper of our own in
				// between collapses the whole block to nothing. Only what loads
				// inside it changes: a srcdoc page with the poster, until the
				// real player is wanted.
				$attributes = (string) preg_replace( '#(?<![\w-])(src|srcdoc|loading)\s*=\s*["\'][^"\']*["\']#i', '', $attributes );

				if ( ! $is_background && ! preg_match( '#(?<![\w-])allow\s*=#i', $attributes ) ) {
					$attributes .= ' allow="autoplay; fullscreen; picture-in-picture"';
				}

				if ( ! $is_background && $fitvids ) {
					$attributes = self::fitvids_style( $attributes );
				}

				$attributes .= sprintf(
					' data-rcr-src="%s" data-rcr-embed="%s" srcdoc="%s"',
					esc_attr( $src ),
					$is_background ? 'background' : 'facade',
					esc_attr( $this->placeholder( $poster, $is_background ? '' : self::with_autoplay( $src ) ) )
				);

				return '<iframe' . $attributes . '>' . $m[2] . '</iframe>';
			},
			$html
		);

		if ( ! is_string( $result ) || 0 === $touched ) {
			return $html;
		}

		// Content embeds need no script at all: the play button inside the
		// placeholder navigates the frame to the player. Only background
		// embeds wait on the viewport and connection checks.
		return $has_bg ? HtmlPipeline::before_body_end( $result, $this->loader( $config ) ) : $result;
	}

	/**
	 * Divi starts these players itself. A Video module with an image overlay
	 * plays by reading the iframe's src and appending autoplay to it, and the
	 * Video Slider swaps sources the same way; with the src moved aside that
	 * throws, the overlay never clears and the video cannot be played. Such
	 * iframes are marked to be left as Divi rendered them.
	 */
	private static function keep_divi_controlled( string $html ): string {
		$slider = str_contains( $html, 'et_pb_video_slider' );

		if ( ! $slider && ! str_contains( $html, 'et_pb_video_overlay' ) ) {
			return $html;
		}

		$result = preg_replace_callback(
			'#<iframe\b([^>]*)>(.*?)</iframe>#is',
			static function ( array $m ) use ( $html, $slider ): string {
				if ( ! $slider ) {
					// The overlay is the iframe's next sibling but one: look a
					// short way ahead, and never past the next iframe.
					$after = substr( $html, $m[0][1] + strlen( $m[0][0] ), 600 );
					$next  = stripos( $after, '<iframe' );
					$after = false === $next ? $after : substr( $after, 0, $next );

					if ( ! str_contains( $after, 'et_pb_video_overlay' ) ) {
						return $m[0][0];
					}
				}

				return '<iframe' . $m[1][0] . ' data-rcr-skip>' . $m[2][0] . '</iframe>';
			},
			$html,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);

		return is_string( $result ) ? $result : $html;
	}

	/** class="skip-lazy", "no-lazy" or data-no-lazy: leave it alone. */
	private static function opted_out( string $attributes ): bool {
		return (bool) preg_match( '#\b(skip-lazy|no-lazy|data-no-lazy|data-rcr-skip)\b#i', $attributes );
	}

	private static function page_scripts_players( string $html ): bool {
		foreach ( [ 'player.vimeo.com/api/player.js', 'youtube.com/iframe_api', 'new Vimeo.Player', 'new YT.Player', 'Vimeo.Player(' ] as $needle ) {
			if ( str_contains( $html, $needle ) ) {
				return true;
			}
		}

		/** @param bool $scripted Force the answer for pages a check above misses. */
		return (bool) apply_filters( 'rc-rocket/embeds/page_is_scripted', false, $html );
	}

	private static function with_autoplay( string $src ): string {
		return add_query_arg( 'autoplay', '1', $src );
	}

	/**
	 * The page shown inside the iframe until the real player loads: the
	 * poster, covering the frame, and for content embeds a play button that
	 * is a plain link to the player. No script, no layout of its own.
	 */
	private function placeholder( string $poster, string $play_url ): string {
		$css = '*{margin:0;padding:0}html,body{height:100%;overflow:hidden;background:#000}'
			. '.p{position:absolute;inset:0;display:block;background:center/cover no-repeat url("' . str_replace( [ '"', '\\' ], [ '%22', '%5C' ], esc_url_raw( $poster ) ) . '")}'
			. '.b{position:absolute;inset:0;margin:auto;width:68px;height:48px;border-radius:10px;background:rgba(0,0,0,.65)}'
			. '.p:hover .b,.p:focus .b{background:rgba(0,0,0,.85)}'
			. '.b:after{content:"";position:absolute;top:50%;left:50%;transform:translate(-40%,-50%);border-style:solid;border-width:9px 0 9px 15px;border-color:transparent transparent transparent #fff}';

		if ( '' === $play_url ) {
			return '<!doctype html><style>' . $css . '</style><div class="p"></div>';
		}

		return sprintf(
			'<!doctype html><style>%s</style><a class="p" href="%s" aria-label="%s"><span class="b"></span></a>',
			$css,
			esc_url( $play_url ),
			esc_attr__( 'Play video', 'rc-rocket' )
		);
	}

	/** @return array{name:string, id:string}|null */
	private function provider_for( string $src ): ?array {
		foreach ( self::PROVIDERS as $name => $pattern ) {
			if ( preg_match( $pattern, $src, $m ) ) {
				return [
					'name' => $name,
					'id'   => $m[1],
				];
			}
		}

		return null;
	}

	/**
	 * A background embed is decorative: it autoplays, it is muted, it loops,
	 * and nobody is ever going to press anything on it. Vimeo marks these with
	 * background=1; everyone else can be recognised by the combination.
	 */
	private function looks_like_background( string $src, string $attributes ): bool {
		$args = self::query_args( $src );

		// Vimeo's own decorative mode: no controls, no sound, cover-fit.
		if ( '1' === ( $args['background'] ?? '' ) ) {
			return true;
		}

		// Everything else needs every sign of decoration, including hidden
		// controls. An autoplaying, muted, looping player that still shows
		// its controls is a showreel someone may want to watch and unmute.
		$autoplay = '1' === ( $args['autoplay'] ?? '' );
		$muted    = '1' === ( $args['muted'] ?? $args['mute'] ?? '' );
		$loop     = '1' === ( $args['loop'] ?? '' );
		$hidden   = '0' === ( $args['controls'] ?? '' );

		return $autoplay && $muted && $loop && $hidden;
	}

	/** An embed that starts playing by itself and is not decoration. */
	private static function autoplays( string $src ): bool {
		return '1' === ( self::query_args( $src )['autoplay'] ?? '' );
	}

	/** @return array<string, string> */
	private static function query_args( string $src ): array {
		parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $args );

		return array_map( 'strval', array_filter( $args, 'is_scalar' ) );
	}

	/**
	 * Divi sizes video iframes with FitVids, which finds them by their src.
	 * A placeholder has no src yet, so FitVids passes it over and the frame
	 * keeps its fixed height attribute — wrong shape, bands above and below
	 * the video. Give it the shape FitVids would have: full width, the
	 * aspect ratio of its width and height attributes.
	 */
	private static function fitvids_style( string $attributes ): string {
		if ( ! preg_match( '#(?<![\w-])width\s*=\s*["\']?(\d+)#i', $attributes, $w ) || ! preg_match( '#(?<![\w-])height\s*=\s*["\']?(\d+)#i', $attributes, $h ) ) {
			return $attributes;
		}

		if ( (int) $w[1] < 1 || (int) $h[1] < 1 ) {
			return $attributes;
		}

		$shape = sprintf( 'width:100%%;height:auto;aspect-ratio:%d/%d;', (int) $w[1], (int) $h[1] );

		// The site positions or sizes it itself: leave that alone.
		if ( preg_match( '#(?<![\w-])style\s*=\s*["\']([^"\']*)["\']#i', $attributes, $style ) ) {
			if ( preg_match( '#(^|;)\s*(position|height|aspect-ratio)\s*:#i', $style[1] ) ) {
				return $attributes;
			}

			return (string) preg_replace( '#(?<![\w-])style\s*=\s*(["\'])#i', 'style=$1' . $shape, $attributes, 1 );
		}

		return $attributes . ' style="' . $shape . '"';
	}

	/**
	 * Provider parameters worth setting regardless.
	 *
	 * dnt=1 stops Vimeo setting tracking cookies, which removes a request and
	 * a consent problem at the same time. A capped quality matters because a
	 * blurred backdrop behind headline text has no use for 1080p.
	 */
	private function tune( string $src, string $provider, array $config, bool $background ): string {
		$args = [];

		if ( 'vimeo' === $provider ) {
			if ( ! empty( $config['vimeo_dnt'] ) ) {
				$args['dnt'] = '1';
			}

			// Only a decorative backdrop gives up resolution. A video someone
			// pressed play on gets whatever their connection can carry.
			$quality = $background ? (string) ( $config['vimeo_quality'] ?? '' ) : '';

			if ( '' !== $quality && 'auto' !== $quality ) {
				$args['quality'] = $quality;
			}
		}

		if ( 'youtube' === $provider ) {
			$src = str_replace( 'youtube.com/embed', 'youtube-nocookie.com/embed', $src );
		}

		return $args ? add_query_arg( $args, $src ) : $src;
	}

	/**
	 * The provider's own thumbnail, via oEmbed. Cached permanently — a video's
	 * thumbnail does not change, and a failed lookup is cached too so a broken
	 * embed cannot cause a remote request on every render.
	 */
	private function poster( string $provider, string $id, array $config ): string {
		foreach ( (array) ( $config['embed_posters'] ?? [] ) as $override ) {
			if ( is_array( $override ) && (string) ( $override['id'] ?? '' ) === $id && ! empty( $override['url'] ) ) {
				return (string) $override['url'];
			}
		}

		$cache = (array) get_option( self::POSTER_OPTION, [] );
		$key   = $provider . ':' . $id;

		if ( array_key_exists( $key, $cache ) ) {
			return (string) $cache[ $key ];
		}

		$endpoint = 'vimeo' === $provider
			? add_query_arg(
				[
					'url'   => rawurlencode( 'https://vimeo.com/' . $id ),
					'width' => 1280,
				],
				'https://vimeo.com/api/oembed.json'
			)
			: add_query_arg(
				[ 'url' => rawurlencode( 'https://www.youtube.com/watch?v=' . $id ) ],
				'https://www.youtube.com/oembed'
			);

		$response = wp_remote_get( $endpoint, [ 'timeout' => 8 ] );
		$poster   = '';

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( is_array( $body ) && ! empty( $body['thumbnail_url'] ) ) {
				$poster = (string) $body['thumbnail_url'];
			}
		}

		if ( '' === $poster ) {
			$this->logger->debug( 'No oEmbed thumbnail available', [ 'embed' => $key ] );
		}

		$cache[ $key ] = $poster;
		update_option( self::POSTER_OPTION, array_slice( $cache, -200, null, true ), false );

		return $poster;
	}

	private function loader( array $config ): string {
		$min_width = max( 0, (int) ( $config['disable_below'] ?? 980 ) );
		$fast_only = ! empty( $config['require_fast_connection'] ) ? 'true' : 'false';
		$save_data = ! empty( $config['respect_save_data'] ) ? 'true' : 'false';
		$reduced   = ! empty( $config['respect_reduced_motion'] ) ? 'true' : 'false';

		return <<<HTML
<script id="rcr-embeds">
(function () {
  var MIN_WIDTH = {$min_width};
  var FAST_ONLY = {$fast_only};
  var SAVE_DATA = {$save_data};
  var REDUCED   = {$reduced};

  // Background embeds: decoration, and only when the visitor can spare it.
  // Otherwise the poster stays, filling the same box the player would.
  var frames = [].slice.call(document.querySelectorAll('iframe[data-rcr-embed="background"]'));
  if (!frames.length) return;

  function attach(frame) {
    if (frame.dataset.rcrLoaded) return;
    frame.dataset.rcrLoaded = '1';
    var src = frame.getAttribute('data-rcr-src');
    frame.removeAttribute('srcdoc');
    frame.removeAttribute('data-rcr-src');
    frame.setAttribute('src', src);
  }

  function allowed() {
    if (MIN_WIDTH > 0 && window.innerWidth < MIN_WIDTH) return false;
    var c = navigator.connection;
    if (c) {
      if (SAVE_DATA && c.saveData) return false;
      if (FAST_ONLY && /(^|-)(2g|slow-2g|3g)$/.test(c.effectiveType || '')) return false;
    }
    if (REDUCED && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
    return true;
  }

  var started = false;

  function start() {
    if (started || !allowed()) return;
    started = true;

    if (!('IntersectionObserver' in window)) {
      frames.forEach(attach);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          attach(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { rootMargin: '200px 0px' });

    frames.forEach(function (f) { observer.observe(f); });
  }

  if (window.requestIdleCallback) {
    requestIdleCallback(start, { timeout: 2000 });
  } else {
    setTimeout(start, 300);
  }

  var resizing;
  window.addEventListener('resize', function () {
    clearTimeout(resizing);
    resizing = setTimeout(start, 250);
  }, { passive: true });
})();
</script>
HTML;
	}

	public function purge_posters(): void {
		delete_option( self::POSTER_OPTION );
	}

	public static function defaults(): array {
		return [
			'facade_embeds'          => true,
			'gate_background_embeds' => true,
			'vimeo_dnt'              => true,
			'vimeo_quality'          => '540p',
			'embed_posters'          => [],
		];
	}
}
