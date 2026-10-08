<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page overrides: "never cache this page" and switching individual
 * optimizations off for one page, from a box in the editor sidebar.
 *
 * Every optimization asks here before touching a page. Nothing is stored
 * unless someone unticks something, so a page with no box saved costs one
 * post meta read per request.
 *
 * Sites moving from WP Rocket keep their per-page choices: a page where
 * lazy loading, deferring or delaying was switched off in WP Rocket's box
 * stays off here until the box is saved again.
 */
final class PageOptions {

	public const META = '_rcr_page_options';

	/** Per-request memo: post id => options. */
	private static array $cache = [];

	/**
	 * The optimizations a page can switch off, with the global setting each
	 * depends on and the equivalent WP Rocket meta key.
	 *
	 * @return array<string, array{label:string, setting:string, wp_rocket:string}>
	 */
	public static function features(): array {
		return [
			'lazyload'         => [
				'label'     => __( 'Lazy load images', 'rc-rocket' ),
				'setting'   => 'media.lazy_load',
				'wp_rocket' => 'lazyload',
			],
			'lazyload_iframes' => [
				'label'     => __( 'Lazy load iframes and videos', 'rc-rocket' ),
				'setting'   => 'media.lazy_iframes',
				'wp_rocket' => 'lazyload_iframes',
			],
			'lazy_backgrounds' => [
				'label'     => __( 'Lazy load Divi background images', 'rc-rocket' ),
				'setting'   => 'media.lazy_backgrounds',
				'wp_rocket' => 'lazyload',
			],
			'video'            => [
				'label'     => __( 'Video posters and click-to-play embeds', 'rc-rocket' ),
				'setting'   => 'media.video.enabled',
				'wp_rocket' => '',
			],
			'defer'            => [
				'label'     => __( 'Defer JS', 'rc-rocket' ),
				'setting'   => 'js.defer',
				'wp_rocket' => 'defer_all_js',
			],
			'delay'            => [
				'label'     => __( 'Delay JavaScript execution', 'rc-rocket' ),
				'setting'   => 'js.delay',
				'wp_rocket' => 'delay_js',
			],
			'lazy_render'      => [
				'label'     => __( 'Lazy render offscreen sections', 'rc-rocket' ),
				'setting'   => 'js.lazy_render',
				'wp_rocket' => '',
			],
			'fonts'            => [
				'label'     => __( 'Serve Google Fonts locally', 'rc-rocket' ),
				'setting'   => 'assets.fonts.localize',
				'wp_rocket' => '',
			],
			'divi_unload'      => [
				'label'     => __( 'Unload unused Divi libraries', 'rc-rocket' ),
				'setting'   => 'assets.divi.unload_modules',
				'wp_rocket' => '',
			],
			'asset_rules'      => [
				'label'     => __( 'Asset manager rules', 'rc-rocket' ),
				'setting'   => 'assets.rules',
				'wp_rocket' => '',
			],
			'preload_links'    => [
				'label'     => __( 'Preload links on this page', 'rc-rocket' ),
				'setting'   => 'preload.links',
				'wp_rocket' => '',
			],
		];
	}

	/**
	 * The page the current front-end request is about, or 0.
	 */
	public static function current_post_id(): int {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		// The posts page is a page with its own editor box, even though the
		// request is not singular.
		if ( is_home() && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_for_posts' );
		}

		return 0;
	}

	/** @return array{never_cache:bool, off:string[]} */
	public static function for_post( int $post_id ): array {
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$options = [
			'never_cache' => false,
			'off'         => [],
		];

		if ( $post_id > 0 ) {
			$stored = get_post_meta( $post_id, self::META, true );

			if ( is_array( $stored ) ) {
				$options['never_cache'] = ! empty( $stored['never_cache'] );
				$options['off']         = array_values( array_intersect( (array) ( $stored['off'] ?? [] ), array_keys( self::features() ) ) );
			} else {
				// Never saved here: inherit what WP Rocket's box said.
				foreach ( self::features() as $key => $feature ) {
					if ( '' !== $feature['wp_rocket'] && get_post_meta( $post_id, '_rocket_exclude_' . $feature['wp_rocket'], true ) ) {
						$options['off'][] = $key;
					}
				}
			}
		}

		self::$cache[ $post_id ] = $options;

		return $options;
	}

	/** Is this optimization switched off for the page being rendered? */
	public static function off( string $feature ): bool {
		if ( is_admin() ) {
			return false;
		}

		return in_array( $feature, self::for_post( self::current_post_id() )['off'], true );
	}

	public static function never_cache(): bool {
		return ! is_admin() && self::for_post( self::current_post_id() )['never_cache'];
	}

	public static function save( int $post_id, bool $never_cache, array $off ): void {
		$off = array_values( array_intersect( array_map( 'strval', $off ), array_keys( self::features() ) ) );

		unset( self::$cache[ $post_id ] );

		// Stored even when everything is back on: the record that the box
		// was saved stops a leftover WP Rocket choice from coming back.
		update_post_meta(
			$post_id,
			self::META,
			[
				'never_cache' => $never_cache,
				'off'         => $off,
			]
		);
	}

	/** Send the signals every page cache understands, ours and the host's. */
	public static function hooks(): void {
		add_action(
			'template_redirect',
			static function (): void {
				if ( ! self::never_cache() ) {
					return;
				}

				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				}

				nocache_headers();
			},
			-1
		);
	}
}
