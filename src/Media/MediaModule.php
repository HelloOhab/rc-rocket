<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Context;
use RCRocket\Support\Hosting;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module D — media delivery.
 *
 * Deliberately does no image conversion. Kinsta and several other managed
 * hosts forbid server-based image optimization outright, and on a host that
 * allows it, a dedicated cloud optimizer will do it better. What is left is
 * the part that actually moves LCP and CLS: telling the browser which image
 * matters, which ones can wait, and how much space to reserve.
 *
 * The Divi-specific problem this solves: Divi renders hero backgrounds as CSS
 * background-image, so the LCP element is invisible to every plugin that only
 * looks at <img> tags. Those heroes are preloaded here by explicit
 * declaration, per template.
 */
final class MediaModule implements Module {

	private const DIMENSION_CACHE = 'rcrocket_image_dimensions';

	public function id(): string {
		return 'media';
	}

	public function label(): string {
		return __( 'Media', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled'         => true,
			'lazy_load'       => true,
			'skip_first'      => 2,
			'lazy_iframes'    => true,
			'add_dimensions'  => true,
			'async_decoding'  => true,
			'lcp_priority'    => true,
			'hero_preloads'   => [],
			'exclusions'      => [ 'skip-lazy', 'no-lazy', 'et_pb_menu__logo' ],
			'video'           => Video::defaults() + Embeds::defaults(),
		];
	}

	public function register( Container $container ): void {
		$container->set(
			'media.embeds',
			static fn( Container $c ): Embeds => new Embeds( $c->get( 'logger' ) )
		);

		$container->set(
			'media.video',
			static fn( Container $c ): Video => new Video( $c->get( 'context' ), $c->get( 'divi' ) )
		);
	}

	public function boot( Container $container ): void {
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		// Query-free check only: conditional tags are not available yet here.
		if ( $safe->is_active() ) {
			return;
		}

		add_filter( 'rc-rocket/html', function ( string $html ) use ( $container ): string {
			return $this->rewrite( $html, $container );
		}, 10 );

		// After images, before scripts are delayed: the video loader must
		// survive the delay pass, which is why it is emitted with an id the
		// delay exclusion list already knows about.
		add_filter( 'rc-rocket/html', function ( string $html ) use ( $container ): string {
			/** @var Settings $settings */
			$settings = $container->get( 'settings' );
			$config   = (array) $settings->get( 'media.video', [] );

			if ( empty( $config['enabled'] ) ) {
				return $html;
			}

			return $container->get( 'media.video' )->rewrite( $html, $config );
		}, 12 );

		// Vimeo and YouTube arrive as iframes, which the video rewriter above
		// does not and should not match.
		add_filter( 'rc-rocket/html', function ( string $html ) use ( $container ): string {
			/** @var Settings $settings */
			$settings = $container->get( 'settings' );
			$config   = (array) $settings->get( 'media.video', [] );

			if ( empty( $config['enabled'] ) ) {
				return $html;
			}

			return $container->get( 'media.embeds' )->rewrite( $html, $config );
		}, 13 );

		// WordPress core lazy-loads by default and gets the hero wrong roughly
		// as often as it gets it right. We take over — but only on the front
		// end, and only once the query tells us this is a real page.
		add_filter(
			'wp_lazy_loading_enabled',
			static fn( bool $default ): bool => $safe->should_optimize() ? false : $default
		);
	}

	private function rewrite( string $html, Container $container ): string {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		/** @var Context $context */
		$context = $container->get( 'context' );

		$config     = (array) $settings->get( 'media', [] );
		$exclusions = array_filter( (array) ( $config['exclusions'] ?? [] ) );
		$skip_first = max( 0, (int) ( $config['skip_first'] ?? 2 ) );
		$boundary   = HtmlPipeline::above_fold_boundary( $html );

		$seen = 0;

		$html = (string) preg_replace_callback(
			'#<img\b([^>]*)>#i',
			function ( array $m ) use ( $config, $exclusions, $skip_first, &$seen, $boundary ): string {
				$attributes = $m[1];
				$whole      = $m[0];

				foreach ( $exclusions as $needle ) {
					if ( str_contains( $attributes, (string) $needle ) ) {
						return $whole;
					}
				}

				++$seen;

				$is_hero = $seen <= $skip_first;

				// The first images in the document are the candidates for LCP.
				// Marking them eager and high priority is worth more than
				// lazy-loading everything below them.
				if ( $is_hero && ! empty( $config['lcp_priority'] ) ) {
					if ( ! preg_match( '#\bfetchpriority\s*=#i', $attributes ) ) {
						$attributes .= ' fetchpriority="high"';
					}

					if ( ! preg_match( '#\bloading\s*=#i', $attributes ) ) {
						$attributes .= ' loading="eager"';
					}
				} elseif ( ! empty( $config['lazy_load'] ) && ! preg_match( '#\bloading\s*=#i', $attributes ) ) {
					$attributes .= ' loading="lazy"';
				}

				if ( ! empty( $config['async_decoding'] ) && ! preg_match( '#\bdecoding\s*=#i', $attributes ) ) {
					$attributes .= $is_hero ? ' decoding="sync"' : ' decoding="async"';
				}

				if ( ! empty( $config['add_dimensions'] ) ) {
					$attributes = $this->ensure_dimensions( $attributes );
				}

				return '<img' . $attributes . '>';
			},
			$html
		);

		if ( ! empty( $config['lazy_iframes'] ) ) {
			$html = (string) preg_replace_callback(
				'#<iframe\b([^>]*)>#i',
				static function ( array $m ): string {
					if ( preg_match( '#\bloading\s*=#i', $m[1] ) ) {
						return $m[0];
					}

					return '<iframe' . $m[1] . ' loading="lazy">';
				},
				$html
			);
		}

		$preload = $this->hero_preload_tags( (array) ( $config['hero_preloads'] ?? [] ), $context );

		if ( '' !== $preload ) {
			$html = HtmlPipeline::after_head_start( $html, $preload );
		}

		return $html;
	}

	/**
	 * Divi hero backgrounds live in generated CSS, so the browser only
	 * discovers them after the stylesheet parses — which on mobile is most of
	 * a second too late. A preload hint moves the request to the very start of
	 * the waterfall.
	 *
	 * @param array<int, array{template:string, url:string, media?:string}> $preloads
	 */
	private function hero_preload_tags( array $preloads, Context $context ): string {
		$out = '';

		foreach ( $preloads as $preload ) {
			if ( ! is_array( $preload ) || empty( $preload['url'] ) ) {
				continue;
			}

			$template = (string) ( $preload['template'] ?? '' );

			if ( '' !== $template && ! $context->matches( [ $template ] ) ) {
				continue;
			}

			$media = trim( (string) ( $preload['media'] ?? '' ) );

			$out .= sprintf(
				'<link rel="preload" as="image" href="%s" fetchpriority="high"%s>',
				esc_url( (string) $preload['url'] ),
				'' === $media ? '' : ' media="' . esc_attr( $media ) . '"'
			);
		}

		return $out;
	}

	/**
	 * Fill in missing width and height so the browser can reserve space.
	 * This is the CLS fix. Results are cached because getimagesize() hits the
	 * disk and a gallery page would otherwise stat forty files per render.
	 */
	private function ensure_dimensions( string $attributes ): string {
		if ( preg_match( '#\bwidth\s*=#i', $attributes ) && preg_match( '#\bheight\s*=#i', $attributes ) ) {
			return $attributes;
		}

		if ( ! preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attributes, $m ) ) {
			return $attributes;
		}

		$size = $this->dimensions_for( $m[1] );

		if ( null === $size ) {
			return $attributes;
		}

		if ( ! preg_match( '#\bwidth\s*=#i', $attributes ) ) {
			$attributes .= ' width="' . (int) $size[0] . '"';
		}

		if ( ! preg_match( '#\bheight\s*=#i', $attributes ) ) {
			$attributes .= ' height="' . (int) $size[1] . '"';
		}

		return $attributes;
	}

	/** @return array{0:int,1:int}|null */
	private function dimensions_for( string $url ): ?array {
		static $cache = null;

		if ( null === $cache ) {
			$cache = get_option( self::DIMENSION_CACHE, [] );
			$cache = is_array( $cache ) ? $cache : [];
		}

		$key = md5( $url );

		if ( array_key_exists( $key, $cache ) ) {
			return is_array( $cache[ $key ] ) ? $cache[ $key ] : null;
		}

		$uploads = wp_get_upload_dir();
		$path    = null;

		if ( str_starts_with( $url, $uploads['baseurl'] ) ) {
			$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		} elseif ( str_starts_with( $url, '/wp-content/' ) ) {
			$path = WP_CONTENT_DIR . substr( $url, strlen( '/wp-content' ) );
		}

		$size = null;

		if ( null !== $path && is_readable( $path ) ) {
			$measured = @getimagesize( $path ); // phpcs:ignore

			if ( is_array( $measured ) && isset( $measured[0], $measured[1] ) ) {
				$size = [ (int) $measured[0], (int) $measured[1] ];
			}
		}

		$cache[ $key ] = $size ?? false;

		if ( count( $cache ) > 500 ) {
			$cache = array_slice( $cache, -400, null, true );
		}

		update_option( self::DIMENSION_CACHE, $cache, false );

		return $size;
	}

	/** Host policy, surfaced in the UI so the missing feature is explained. */
	public static function conversion_allowed( Hosting $hosting ): bool {
		return ! in_array( $hosting->id(), [ 'kinsta', 'wpengine', 'pressable', 'flywheel' ], true );
	}
}
