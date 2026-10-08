<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Context;
use RCRocket\Support\Hosting;
use RCRocket\Support\PageOptions;
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
			'lcp_detect'      => true,
			'lazy_backgrounds' => true,
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
			'media.lcp',
			static fn( Container $c ): Lcp => new Lcp( $c->get( 'context' ) )
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

			if ( empty( $config['enabled'] ) || PageOptions::off( 'video' ) ) {
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

			if ( empty( $config['enabled'] ) || PageOptions::off( 'video' ) ) {
				return $html;
			}

			return $container->get( 'media.embeds' )->rewrite( $html, $config );
		}, 13 );

		// WordPress core lazy-loads by default and gets the hero wrong roughly
		// as often as it gets it right. We take over — but only on the front
		// end, only once the query tells us this is a real page, and only when
		// our own lazy loading is on. Switching ours off must not leave the
		// site with none at all.
		$settings = $container->get( 'settings' );

		if ( $settings->enabled( 'media.lazy_load' ) || $settings->enabled( 'media.lazy_iframes' ) ) {
			add_filter(
				'wp_lazy_loading_enabled',
				static function ( bool $default, string $tag ) use ( $safe, $settings ): bool {
					$ours = 'iframe' === $tag
						? $settings->enabled( 'media.lazy_iframes' ) && ! PageOptions::off( 'lazyload_iframes' )
						: $settings->enabled( 'media.lazy_load' ) && ! PageOptions::off( 'lazyload' );

					return $ours && $safe->should_optimize() ? false : $default;
				},
				10,
				2
			);
		}

		if ( $settings->enabled( 'media.lazy_backgrounds' ) ) {
			$container->get( 'divi' )->when_active(
				static function (): void {
					add_filter(
						'rc-rocket/html',
						static function ( string $html ): string {
							return PageOptions::off( 'lazy_backgrounds' ) ? $html : ( new Backgrounds() )->rewrite( $html );
						},
						14
					);
				}
			);
		}

		if ( $settings->enabled( 'media.lcp_detect' ) ) {
			$this->hero_detection( $container );
		}

		add_action( 'shutdown', [ $this, 'persist_dimensions' ] );
	}

	private function hero_detection( Container $container ): void {
		/** @var Lcp $lcp */
		$lcp = $container->get( 'media.lcp' );
		$lcp->hooks();

		// After scripts are delayed, so the measuring script is never held
		// back until an interaction that ends the measurement.
		add_filter(
			'rc-rocket/html',
			static function ( string $html ) use ( $lcp ): string {
				// Logged-in pages have the admin bar and are never cached:
				// measure what visitors see.
				return is_user_logged_in() || is_404() || is_search() ? $html : $lcp->inject_beacon( $html );
			},
			40
		);

		// The cached copy still has the old guess: refresh that page. The
		// report comes from a visitor, so this never clears the whole host
		// cache; where the host cannot clear one page the copy just expires.
		add_action(
			'rc-rocket/lcp/measured',
			static function ( string $key, string $device, string $page ): void {
				if ( str_starts_with( $key, 'post:' ) ) {
					\RCRocket\Plugin::instance()->purge_post( (int) substr( $key, 5 ), false );
				} elseif ( '' !== $page && str_starts_with( $page, '/' ) ) {
					\RCRocket\Plugin::instance()->purge_url( home_url( $page ), false );
				}
			},
			10,
			3
		);
	}

	/** Class conventions that only count as a whole class name. */
	private const CLASS_EXCLUSIONS = [ 'skip-lazy', 'no-lazy' ];

	/**
	 * An exclusion matches anywhere in the tag (a file name, part of a
	 * URL), except the skip-lazy/no-lazy conventions: those are class names,
	 * and as plain text they also match other plugins' classes, such as
	 * Divi Supreme's dsm-skip-lazyload, leaving hundreds of images with no
	 * lazy loading at all, since WordPress's own is switched off here.
	 *
	 * @param array<int, mixed> $exclusions
	 */
	public static function excluded( string $attributes, array $exclusions ): bool {
		foreach ( $exclusions as $needle ) {
			$needle = (string) $needle;

			if ( '' === $needle ) {
				continue;
			}

			$hit = in_array( $needle, self::CLASS_EXCLUSIONS, true )
				? (bool) preg_match( '#(?<![\w-])' . preg_quote( $needle, '#' ) . '(?![\w-])#i', $attributes )
				: str_contains( $attributes, $needle );

			if ( $hit ) {
				return true;
			}
		}

		return false;
	}

	/** @var array<string, mixed>|null */
	private ?array $dimension_cache = null;

	private bool $dimensions_dirty = false;

	private function rewrite( string $html, Container $container ): string {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		/** @var Context $context */
		$context = $container->get( 'context' );

		$config     = (array) $settings->get( 'media', [] );

		// Switched off for this page in the editor box.
		if ( PageOptions::off( 'lazyload' ) ) {
			$config['lazy_load'] = false;
		}

		if ( PageOptions::off( 'lazyload_iframes' ) ) {
			$config['lazy_iframes'] = false;
		}
		$exclusions = array_filter( (array) ( $config['exclusions'] ?? [] ) );
		$skip_first = max( 0, (int) ( $config['skip_first'] ?? 2 ) );

		// Measured heroes, when there are any. Without a measurement the
		// first images in the document stand in for the hero.
		$plan = ! empty( $config['lcp_detect'] ) && ! empty( $config['lcp_priority'] )
			? $container->get( 'media.lcp' )->plan( $html )
			: [ 'images' => [], 'preloads' => [], 'measured' => false ];

		$heroes   = $plan['images'];
		$measured = $plan['measured'];

		$preload = $this->hero_preload_tags( (array) ( $config['hero_preloads'] ?? [] ), $context ) . Lcp::preload_tags( $plan['preloads'] );

		// The hero is a background being preloaded at high priority. An
		// image marked high priority as well (core marks the first one, which
		// on Divi is usually the logo) only competes with it for bandwidth.
		$background_hero = '' !== $preload;

		$seen = 0;

		// Only real tags: an <img> inside a script, JSON or <noscript> is
		// text, and the pixel <img> in a <noscript> is not a hero candidate.
		[ $html, $kept ] = HtmlPipeline::mask( $html );

		$html = (string) preg_replace_callback(
			'#<img\b([^>]*)>#i',
			function ( array $m ) use ( $config, $exclusions, $skip_first, $heroes, $measured, $background_hero, &$seen ): string {
				$attributes = $m[1];
				$whole      = $m[0];

				// Keep a self-closing slash at the end, where it belongs:
				// attributes appended after it are not part of valid markup.
				$close = '';

				if ( preg_match( '#\s*(?<=["\'\s])/\s*$#', $attributes, $slash ) ) {
					$close      = ' /';
					$attributes = substr( $attributes, 0, -strlen( $slash[0] ) );
				}

				if ( self::excluded( $attributes, $exclusions ) ) {
					return $whole;
				}

				++$seen;

				$measured_hero = false;

				foreach ( $heroes as $path ) {
					if ( str_contains( $attributes, $path ) ) {
						$measured_hero = true;
						break;
					}
				}

				$is_hero = $measured_hero || $seen <= $skip_first;

				// With a measurement, only the real hero is high priority (none
				// at all when the hero is a background or text); the other
				// early images still load straight away. Without one, the
				// first images in the document are the candidates.
				$priority = $measured ? $measured_hero : ( $is_hero && ! $background_hero );

				if ( $background_hero && ! $measured_hero && ! empty( $config['lcp_priority'] ) ) {
					$attributes = (string) preg_replace( '#\sfetchpriority\s*=\s*["\']?high["\']?#i', '', $attributes );
				}

				if ( $is_hero && ! empty( $config['lcp_priority'] ) ) {
					if ( $priority && ! preg_match( '#\bfetchpriority\s*=#i', $attributes ) ) {
						$attributes .= ' fetchpriority="high"';
					}

					// A measured hero that a theme or core marked lazy is the
					// classic LCP mistake: undo it.
					if ( $measured_hero ) {
						$attributes = (string) preg_replace( '#\sloading\s*=\s*["\']?lazy["\']?#i', '', $attributes );
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

				return '<img' . $attributes . $close . '>';
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

		$html = HtmlPipeline::unmask( $html, $kept );

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
		$has_width  = (bool) preg_match( '#(?<![\w-])width\s*=\s*["\']?(\d+)#i', $attributes, $w );
		$has_height = (bool) preg_match( '#(?<![\w-])height\s*=\s*["\']?(\d+)#i', $attributes, $h );

		if ( $has_width && $has_height ) {
			return $attributes;
		}

		if ( ! preg_match( '#(?<![\w-])src\s*=\s*["\']([^"\']+)["\']#i', $attributes, $m ) ) {
			return $attributes;
		}

		$size = $this->dimensions_for( html_entity_decode( $m[1] ) );

		if ( null === $size || $size[0] < 1 || $size[1] < 1 ) {
			return $attributes;
		}

		// Keep the aspect ratio of whichever side the markup already set. The
		// intrinsic height next to a smaller declared width distorts the image.
		if ( $has_width ) {
			return $attributes . ' height="' . (int) round( (int) $w[1] * $size[1] / $size[0] ) . '"';
		}

		if ( $has_height ) {
			return $attributes . ' width="' . (int) round( (int) $h[1] * $size[0] / $size[1] ) . '"';
		}

		return $attributes . ' width="' . (int) $size[0] . '" height="' . (int) $size[1] . '"';
	}

	/** @return array{0:int,1:int}|null */
	private function dimensions_for( string $url ): ?array {
		if ( null === $this->dimension_cache ) {
			$cache                 = get_option( self::DIMENSION_CACHE, [] );
			$this->dimension_cache = is_array( $cache ) ? $cache : [];
		}

		$key = md5( $url );

		if ( array_key_exists( $key, $this->dimension_cache ) ) {
			return is_array( $this->dimension_cache[ $key ] ) ? $this->dimension_cache[ $key ] : null;
		}

		$path = $this->local_path( $url );
		$size = null;

		if ( null !== $path && is_readable( $path ) ) {
			$measured = @getimagesize( $path ); // phpcs:ignore

			if ( is_array( $measured ) && isset( $measured[0], $measured[1] ) ) {
				$size = [ (int) $measured[0], (int) $measured[1] ];
			}
		}

		$this->dimension_cache[ $key ] = $size ?? false;
		$this->dimensions_dirty        = true;

		return $size;
	}

	/** Uploads and theme files only, and never outside wp-content. */
	private function local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();
		$url     = (string) strtok( $url, '?#' );

		foreach ( [ $uploads['baseurl'] => $uploads['basedir'], content_url() => WP_CONTENT_DIR, '/wp-content' => WP_CONTENT_DIR ] as $prefix => $dir ) {
			foreach ( [ $prefix, (string) preg_replace( '#^https?:#', '', $prefix ) ] as $candidate ) {
				if ( '' !== $candidate && str_starts_with( $url, $candidate ) ) {
					$path = (string) realpath( $dir . rawurldecode( substr( $url, strlen( $candidate ) ) ) );

					return '' !== $path && str_starts_with( $path, (string) realpath( WP_CONTENT_DIR ) ) ? $path : null;
				}
			}
		}

		return null;
	}

	/** One write per request, not one per image. */
	public function persist_dimensions(): void {
		if ( ! $this->dimensions_dirty || null === $this->dimension_cache ) {
			return;
		}

		update_option( self::DIMENSION_CACHE, array_slice( $this->dimension_cache, -1000, null, true ), false );
		$this->dimensions_dirty = false;
	}

	/** Host policy, surfaced in the UI so the missing feature is explained. */
	public static function conversion_allowed( Hosting $hosting ): bool {
		return ! in_array( $hosting->id(), [ 'kinsta', 'wpengine', 'pressable', 'flywheel' ], true );
	}
}
