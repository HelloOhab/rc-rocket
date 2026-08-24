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

		$touched = 0;

		$result = preg_replace_callback(
			'#<iframe\b([^>]*)>(.*?)</iframe>#is',
			function ( array $m ) use ( $config, &$touched ): string {
				$attributes = $m[1];

				if ( str_contains( $attributes, 'data-rcr-embed' ) ) {
					return $m[0];
				}

				if ( ! preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attributes, $src_match ) ) {
					return $m[0];
				}

				$src      = html_entity_decode( $src_match[1] );
				$provider = $this->provider_for( $src );

				if ( null === $provider ) {
					return $m[0];
				}

				$id     = $provider['id'];
				$name   = $provider['name'];
				$poster = $this->poster( $name, $id, $config );

				// Without a poster there is nothing to show in the embed's
				// place, so leave it alone rather than leave a hole.
				if ( '' === $poster ) {
					return $m[0];
				}

				$src = $this->tune( $src, $name, $config );

				$is_background = $this->looks_like_background( $src, $attributes );

				if ( $is_background && empty( $config['gate_background_embeds'] ) ) {
					return $m[0];
				}

				if ( ! $is_background && empty( $config['facade_embeds'] ) ) {
					return $m[0];
				}

				++$touched;

				$attributes = (string) preg_replace( '#\bsrc\s*=\s*["\'][^"\']*["\']#i', '', $attributes );
				$attributes = (string) preg_replace( '#\bloading\s*=\s*["\'][^"\']*["\']#i', '', $attributes );

				$attributes .= sprintf(
					' data-rcr-src="%s" data-rcr-embed="%s" loading="lazy"',
					esc_attr( $src ),
					$is_background ? 'background' : 'facade'
				);

				return sprintf(
					'<div class="rcr-embed%s" style="background-image:url(%s)">%s<iframe%s></iframe></div>',
					$is_background ? ' rcr-embed--bg' : '',
					esc_url( $poster ),
					$is_background ? '' : '<button type="button" class="rcr-embed__play" aria-label="' . esc_attr__( 'Play video', 'rc-rocket' ) . '"></button>',
					$attributes
				);
			},
			$html
		);

		if ( ! is_string( $result ) || 0 === $touched ) {
			return $html;
		}

		$result = HtmlPipeline::after_head_start( $result, $this->styles() );

		return HtmlPipeline::before_body_end( $result, $this->loader( $config ) );
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
		if ( str_contains( $src, 'background=1' ) ) {
			return true;
		}

		$autoplay = str_contains( $src, 'autoplay=1' );
		$muted    = str_contains( $src, 'muted=1' ) || str_contains( $src, 'mute=1' );
		$loop     = str_contains( $src, 'loop=1' );

		if ( $autoplay && $muted && $loop ) {
			return true;
		}

		return (bool) preg_match( '#class=["\'][^"\']*(background|hero|bg-video)[^"\']*["\']#i', $attributes );
	}

	/**
	 * Provider parameters worth setting regardless.
	 *
	 * dnt=1 stops Vimeo setting tracking cookies, which removes a request and
	 * a consent problem at the same time. A capped quality matters because a
	 * blurred backdrop behind headline text has no use for 1080p.
	 */
	private function tune( string $src, string $provider, array $config ): string {
		$args = [];

		if ( 'vimeo' === $provider ) {
			if ( ! empty( $config['vimeo_dnt'] ) ) {
				$args['dnt'] = '1';
			}

			$quality = (string) ( $config['vimeo_quality'] ?? '' );

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

	private function styles(): string {
		return '<style id="rcr-embed-css">'
			. '.rcr-embed{position:relative;background-size:cover;background-position:center;overflow:hidden}'
			. '.rcr-embed iframe{position:relative;z-index:1;border:0}'
			. '.rcr-embed--bg,.rcr-embed--bg iframe{position:absolute;inset:0;width:100%;height:100%}'
			. '.rcr-embed__play{position:absolute;inset:0;margin:auto;width:68px;height:48px;z-index:2;border:0;cursor:pointer;'
			. 'border-radius:10px;background:rgba(0,0,0,.65)}'
			. '.rcr-embed__play::after{content:"";position:absolute;top:50%;left:50%;transform:translate(-40%,-50%);'
			. 'border-style:solid;border-width:9px 0 9px 15px;border-color:transparent transparent transparent #fff}'
			. '.rcr-embed__play:hover{background:rgba(0,0,0,.85)}'
			. '.rcr-embed__play:focus-visible{outline:3px solid #fff;outline-offset:2px}'
			. '</style>';
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

  function attach(frame) {
    if (frame.dataset.rcrLoaded) return;
    frame.dataset.rcrLoaded = '1';
    frame.setAttribute('src', frame.getAttribute('data-rcr-src'));
    frame.removeAttribute('data-rcr-src');
  }

  // Content embeds: the player arrives on the first click, and plays.
  document.querySelectorAll('.rcr-embed:not(.rcr-embed--bg)').forEach(function (wrap) {
    var frame = wrap.querySelector('iframe[data-rcr-embed]');
    if (!frame) return;
    var button = wrap.querySelector('.rcr-embed__play');

    function go() {
      var src = frame.getAttribute('data-rcr-src') || '';
      frame.setAttribute('data-rcr-src', src + (src.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1');
      attach(frame);
      if (button) button.remove();
    }

    if (button) button.addEventListener('click', go, { once: true });
    wrap.addEventListener('click', go, { once: true });
  });

  // Background embeds: decoration, and only when the visitor can spare it.
  var backgrounds = document.querySelectorAll('.rcr-embed--bg iframe[data-rcr-embed]');
  if (!backgrounds.length) return;

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

  function start() {
    if (!allowed()) return;   // Poster stays. Nothing is requested.

    if (!('IntersectionObserver' in window)) {
      backgrounds.forEach(attach);
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

    backgrounds.forEach(function (f) { observer.observe(f); });
  }

  if (window.requestIdleCallback) {
    requestIdleCallback(start, { timeout: 2000 });
  } else {
    setTimeout(start, 300);
  }
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
