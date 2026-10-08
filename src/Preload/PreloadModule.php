<?php
declare( strict_types=1 );

namespace RCRocket\Preload;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module G — preloading.
 *
 * Making the next page fast before anyone asks for it:
 *
 *   Link preloading. When a visitor hovers or presses on a link, the browser
 *   starts fetching that page, so by the time the click lands the HTML is
 *   already there. Built on the Speculation Rules API — WordPress 6.8 ships
 *   it switched to its most timid setting; this turns it up and teaches it
 *   which Divi and WooCommerce URLs must never be fetched speculatively. On
 *   Kinsta those fetches are answered from the edge cache, so they cost the
 *   origin nothing.
 *
 *   Font preloading. The fonts a Divi header renders in are discovered only
 *   after the stylesheet that names them has downloaded and parsed. Naming
 *   them up front moves them to the start of the waterfall.
 *
 *   DNS prefetch. A lookup started early for origins the page will reach
 *   later (analytics, a chat widget, a booking embed).
 */
final class PreloadModule implements Module {

	/** Never fetched speculatively: actions, private pages, the builder. */
	private const ALWAYS_EXCLUDE = [
		'/wp-admin/*',
		'/wp-login.php*',
		'/*\\?*et_fb=*',
		'/*\\?*add-to-cart=*',
		'/*\\?*add_to_wishlist=*',
		'/*\\?*remove_from_wishlist=*',
		'/*\\?*edd_action=*',
		'/*\\?*remove_item=*',
		'/*\\?*action=logout*',
		'/*\\?*customer-logout*',
		'/*/feed/*',
		// Downloads. Prefetching a 20 MB brochure because someone's pointer
		// rested on the link costs them data and gains nothing.
		'/*.pdf',
		'/*.zip',
		'/*.doc',
		'/*.docx',
		'/*.xls',
		'/*.xlsx',
		'/*.ppt',
		'/*.pptx',
		'/*.csv',
		'/*.ics',
		'/*.mp3',
		'/*.mp4',
		'/*.PDF',
	];

	public function id(): string {
		return 'preload';
	}

	public function label(): string {
		return __( 'Preloading', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled'          => true,
			'links'            => true,
			'links_mode'       => 'prefetch',
			'links_eagerness'  => 'moderate',
			'links_exclusions' => [],
			'fonts'            => [],
			'dns_prefetch'     => [],
		];
	}

	public function register( Container $container ): void {}

	public function boot( Container $container ): void {
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		if ( $safe->is_active() ) {
			return;
		}

		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		$config   = (array) $settings->get( 'preload', [] );

		if ( ! empty( $config['links'] ) ) {
			$this->link_preloading( $config, $safe );
		}

		$fonts = array_values( array_filter( array_map( 'trim', (array) ( $config['fonts'] ?? [] ) ) ) );

		if ( $fonts ) {
			add_action(
				'wp_head',
				static function () use ( $fonts, $safe ): void {
					if ( ! $safe->should_optimize() ) {
						return;
					}

					foreach ( array_slice( $fonts, 0, 6 ) as $font ) {
						printf(
							'<link rel="preload" as="font" type="%s" href="%s" crossorigin>' . "\n",
							esc_attr( self::font_type( $font ) ),
							esc_url( str_starts_with( $font, '/' ) && ! str_starts_with( $font, '//' ) ? home_url( $font ) : $font )
						);
					}
				},
				1
			);
		}

		$origins = array_values( array_filter( array_map( [ self::class, 'origin' ], (array) ( $config['dns_prefetch'] ?? [] ) ) ) );

		if ( $origins ) {
			add_filter(
				'wp_resource_hints',
				static function ( array $hints, string $relation ) use ( $origins ): array {
					return 'dns-prefetch' === $relation ? array_values( array_unique( array_merge( $hints, $origins ) ) ) : $hints;
				},
				10,
				2
			);
		}
	}

	// ------------------------------------------------------- link preloading

	private function link_preloading( array $config, SafeMode $safe ): void {
		$mode      = in_array( $config['links_mode'] ?? '', [ 'prefetch', 'prerender' ], true ) ? (string) $config['links_mode'] : 'prefetch';
		$eagerness = in_array( $config['links_eagerness'] ?? '', [ 'conservative', 'moderate', 'eager' ], true ) ? (string) $config['links_eagerness'] : 'moderate';
		$exclude   = $this->exclusions( (array) ( $config['links_exclusions'] ?? [] ) );

		// WordPress 6.8+ ships the API: configure it rather than add a second
		// set of rules beside it.
		if ( function_exists( 'wp_get_speculation_rules_configuration' ) ) {
			add_filter(
				'wp_speculation_rules_configuration',
				static function ( $current ) use ( $mode, $eagerness, $safe ) {
					// Core turns it off for logged-in users and plain
					// permalinks. Respect both, and our own kill switch.
					if ( null === $current || ! $safe->should_optimize() || \RCRocket\Support\PageOptions::off( 'preload_links' ) ) {
						return $current;
					}

					return [
						'mode'      => $mode,
						'eagerness' => $eagerness,
					];
				},
				20
			);

			add_filter(
				'wp_speculation_rules_href_exclude_paths',
				static fn( array $paths ): array => array_values( array_unique( array_merge( $paths, $exclude ) ) ),
				20
			);

			return;
		}

		// WordPress 6.5–6.7: emit the same rules ourselves.
		add_action(
			'wp_footer',
			static function () use ( $mode, $eagerness, $exclude, $safe ): void {
				if ( ! $safe->should_optimize() || ! get_option( 'permalink_structure' ) || \RCRocket\Support\PageOptions::off( 'preload_links' ) ) {
					return;
				}

				$base  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
				$base  = '/' === $base ? '' : untrailingslashit( $base );
				$paths = array_map( static fn( string $p ): string => $base . $p, array_merge( [ '/wp-content/*', '/wp-includes/*', '/*\\?(.+)' ], $exclude ) );

				$rules = [
					$mode => [
						[
							'source'    => 'document',
							'where'     => [
								'and' => [
									[ 'href_matches' => $base . '/*' ],
									[ 'not' => [ 'href_matches' => $paths ] ],
									[ 'not' => [ 'selector_matches' => 'a[rel~="nofollow"]' ] ],
									[ 'not' => [ 'selector_matches' => ".no-{$mode}, .no-{$mode} a" ] ],
								],
							],
							'eagerness' => $eagerness,
						],
					],
				];

				printf( '<script type="speculationrules">%s</script>' . "\n", wp_json_encode( $rules, JSON_UNESCAPED_SLASHES ) );
			},
			20
		);
	}

	/**
	 * Built-in exclusions, the shop's private pages, and the user's own.
	 *
	 * @param string[] $user
	 * @return string[]
	 */
	private function exclusions( array $user ): array {
		$paths = self::ALWAYS_EXCLUDE;

		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
				$id = (int) wc_get_page_id( $page );

				if ( $id > 0 ) {
					$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );

					if ( '' !== $path && '/' !== $path ) {
						$paths[] = untrailingslashit( $path ) . '*';
					}
				}
			}
		}

		foreach ( $user as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			// Accept a full URL as well as a path.
			$path = (string) ( wp_parse_url( $pattern, PHP_URL_PATH ) ?? '' );

			$paths[] = '/' . ltrim( '' === $path ? $pattern : $path, '/' );
		}

		return array_values( array_unique( $paths ) );
	}

	// -------------------------------------------------------------- helpers

	private static function font_type( string $url ): string {
		$extension = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return match ( $extension ) {
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			default => 'font/woff2',
		};
	}

	/** "https://www.googletagmanager.com/gtm.js" becomes "//www.googletagmanager.com". */
	public static function origin( mixed $value ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$host = (string) wp_parse_url( str_contains( $value, '//' ) ? $value : '//' . $value, PHP_URL_HOST );

		return '' === $host ? '' : '//' . $host;
	}
}
