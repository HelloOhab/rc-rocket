<?php
declare( strict_types=1 );

namespace RCRocket\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What we know about handles before the user tells us anything.
 *
 * Two lists matter. Protected handles can break a Divi site instantly and are
 * refused unless the user explicitly forces them. Suggestions are the safe,
 * high-value disables that a Divi site almost always benefits from, so the
 * first run of the asset manager is not a blank page of 90 unfamiliar handles.
 */
final class Presets {

	/**
	 * Never dequeue these without an explicit override.
	 *
	 * Divi 4 renders every module through jQuery — dropping it does not slow
	 * the site down, it stops the site working. Divi 5's runtime is the same
	 * story with different names.
	 */
	public static function protected_handles(): array {
		return [
			// WordPress core.
			'jquery',
			'jquery-core',
			// Divi 4.
			'divi-style',
			'divi-style-parent',
			'divi-custom-script',
			'et-core-common',
			'et-builder-modules-script',
			'et-builder-modules-global-functions-script',
			'et-dynamic-asset-helpers',
			// Divi 5.
			'divi-runtime',
			'divi-module-library-script',
			'divi-script-library',
			'divi-style-dynamic',
		];
	}

	/**
	 * Handle prefix to human owner. Used to group the manager by plugin, since
	 * "wpforms-full" means nothing to most people and "WPForms" means a lot.
	 *
	 * @return array<string, string>
	 */
	public static function owners(): array {
		return [
			'wpforms'          => 'WPForms',
			'trustindex'       => 'Trustindex reviews',
			'dsm-'             => 'Divi Supreme',
			'divi-supreme'     => 'Divi Supreme',
			'pac-'             => 'Pee-Aye Creative',
			'divi-gear'        => 'DiviGear',
			'dg-'              => 'DiviGear',
			'rank-math'        => 'Rank Math',
			'wc-'              => 'WooCommerce',
			'woocommerce'      => 'WooCommerce',
			'contact-form-7'   => 'Contact Form 7',
			'elementor'        => 'Elementor',
			'et-'              => 'Divi',
			'divi'             => 'Divi',
			'wp-block'         => 'WordPress blocks',
			'jquery'           => 'WordPress core',
			'monarch'          => 'Monarch',
			'bloom'            => 'Bloom',
		];
	}

	public static function owner_for( string $handle, string $src ): string {
		foreach ( self::owners() as $prefix => $owner ) {
			if ( str_starts_with( $handle, $prefix ) ) {
				return $owner;
			}
		}

		if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $src, $m ) ) {
			return ucwords( str_replace( [ '-', '_' ], ' ', $m[1] ) );
		}

		if ( preg_match( '#/wp-content/themes/([^/]+)/#', $src, $m ) ) {
			return ucwords( str_replace( [ '-', '_' ], ' ', $m[1] ) ) . ' (theme)';
		}

		if ( str_contains( $src, '/wp-includes/' ) ) {
			return 'WordPress core';
		}

		return 'Unknown';
	}

	/**
	 * Safe starting rules for a typical Divi marketing site.
	 *
	 * Each one is a load that provably does nothing on pages without the
	 * relevant feature. They are proposed, never applied automatically.
	 *
	 * @return array<int, array{handle:string, kind:string, reason:string, scope:string, targets:string[]}>
	 */
	public static function suggestions(): array {
		return [
			[
				'handle'  => 'wpforms-full',
				'kind'    => 'style',
				'reason'  => 'WPForms styles load on every page, including pages with no form.',
				'scope'   => 'except',
				'targets' => [ 'singular:page' ],
			],
			[
				'handle'  => 'wpforms-modern-full',
				'kind'    => 'style',
				'reason'  => 'Same as above, for the modern markup mode.',
				'scope'   => 'except',
				'targets' => [ 'singular:page' ],
			],
			[
				'handle'  => 'wp-block-library',
				'kind'    => 'style',
				'reason'  => 'Gutenberg block styles. A Divi-built page renders none of them.',
				'scope'   => 'everywhere',
				'targets' => [],
			],
			[
				'handle'  => 'classic-theme-styles',
				'kind'    => 'style',
				'reason'  => 'Core fallback styles that Divi overrides entirely.',
				'scope'   => 'everywhere',
				'targets' => [],
			],
			[
				'handle'  => 'global-styles',
				'kind'    => 'style',
				'reason'  => 'Block theme.json variables, unused by Divi layouts.',
				'scope'   => 'everywhere',
				'targets' => [],
			],
			[
				'handle'  => 'wc-cart-fragments',
				'kind'    => 'script',
				'reason'  => 'WooCommerce cart polling. Uncacheable AJAX on every page view.',
				'scope'   => 'except',
				'targets' => [ 'woocommerce', 'woocommerce:checkout' ],
			],
			[
				'handle'  => 'trustindex-widget',
				'kind'    => 'script',
				'reason'  => 'Review widget script. Only needed where the widget appears.',
				'scope'   => 'except',
				'targets' => [ 'front_page' ],
			],
		];
	}

	/**
	 * Substrings that must never be deferred or delayed on a Divi site. These
	 * are the ones that produce "my slider stopped working" reports.
	 */
	public static function js_exclusions(): array {
		return [
			'jquery.js',
			'jquery.min.js',
			'jquery-migrate',
			'divi-custom-script',
			'et-core-common',
			'et-builder-modules-script',
			'divi-runtime',
			'divi-module-library',
			'et_pb_custom',
			'et-frontend-builder',
			'salvattore',
			'easypiechart',
			'magnific-popup',
			'et-jquery-visible-viewport',
			'wp-emoji-release',
			'rcr-bg-video',
			'rcr-embeds',
			'rcr-beacon',
			'gtm.js',
			'/recaptcha/',
		];
	}

	/**
	 * Divi selectors that are reliably below the fold and safe to defer
	 * rendering. Deliberately conservative — the first two sections are never
	 * touched, because one of them is the LCP element.
	 */
	public static function lazy_render_selectors(): array {
		return [
			'.et_pb_section:nth-of-type(n+4)',
			'.et_pb_row:nth-of-type(n+8)',
			'#main-footer',
			'.et_pb_section_video_bg',
			'.dsm_card_carousel',
		];
	}
}
