<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click optimization levels.
 *
 * A preset only names speed options. It never touches exclusions, rules,
 * posters, the error threshold or anything else someone typed in, and it
 * never switches on a cleanup that deletes content (emptying the trash,
 * rebuilding tables). Applying one is a normal settings save, so it lands in
 * the change history with a one-click rollback.
 *
 * Recommended is also what a fresh install starts with.
 */
final class Presets {

	public const OPTION_PATH = 'general.preset';

	/** @return array<string, array{label:string, description:string}> */
	public static function catalogue(): array {
		return [
			'safe'        => [
				'label'       => 'Safe',
				'description' => 'Everything that cannot change how a page behaves: lazy loading, image hints and hero detection, local fonts, deferring through WordPress\'s own safe mechanism, link preloading on press, housekeeping. Start here on a site with unusual plugins.',
			],
			'recommended' => [
				'label'       => 'Recommended',
				'description' => 'Safe, plus delaying scripts until interaction, lazy loading of Divi background images, unloading unused Divi libraries and jQuery Migrate, and preloading links on hover. The safety net rolls back automatically if visitors start seeing errors.',
			],
			'maximum'     => [
				'label'       => 'Maximum',
				'description' => 'Recommended, plus lazy rendering of offscreen sections, removing the block editor\'s stylesheet, prerendering the next page, and skipping Divi entrance animations on phones. Check overlapping sections, sticky and popup elements, posts written in the block editor and your mobile design after switching.',
			],
		];
	}

	/** @return array<string, mixed>|null Partial settings, or null for an unknown name. */
	public static function settings( string $name ): ?array {
		$safe = [
			'js'       => [
				'enabled'         => true,
				'defer'           => true,
				'delay'           => false,
				'lazy_render'     => false,
				'divi_animations' => [
					'enabled'                => true,
					'reveal_fallback'        => true,
					'respect_reduced_motion' => true,
					'disable_on_mobile'      => false,
				],
			],
			'assets'   => [
				'enabled'   => true,
				'scan'      => true,
				'fonts'     => [
					'localize' => true,
					'preload'  => false,
				],
				'divi'      => [ 'unload_modules' => false ],
				'bloat'     => [
					'emojis'         => true,
					'embeds'         => false,
					'dashicons'      => true,
					'jquery_migrate' => false,
					'rsd_link'       => true,
					'shortlink'      => true,
					'generator'      => true,
					'wlwmanifest'    => true,
					'rest_links'     => true,
					'block_library'  => false,
					'comment_reply'  => true,
				],
				'heartbeat' => [
					'frontend' => 'disable',
					'backend'  => 'reduce',
					'editor'   => 'reduce',
				],
			],
			'media'    => [
				'enabled'        => true,
				'lazy_load'      => true,
				'lazy_iframes'   => true,
				'add_dimensions' => true,
				'async_decoding' => true,
				'lcp_priority'   => true,
				'lcp_detect'     => true,
				'lazy_backgrounds' => false,
				'video'          => [
					'enabled'                => true,
					'withhold'               => false,
					'preload_none'           => false,
					'preload_poster'         => true,
					'lazy_until_visible'     => true,
					'facade_embeds'          => true,
					'gate_background_embeds' => true,
				],
			],
			'preload'  => [
				'enabled'         => true,
				'links'           => true,
				'links_mode'      => 'prefetch',
				'links_eagerness' => 'conservative',
			],
			'cache'    => [
				'enabled'             => true,
				'preload_on_purge'    => true,
				'warm_after_publish'  => true,
				'fix_viewport'        => true,
				'refresh_form_nonces' => true,
				'clear_divi_cache'    => true,
			],
			// Housekeeping that deletes nothing anyone would want back. Revisions,
			// comments and the schedule stay whatever the user chose.
			'database' => [
				'enabled'            => true,
				'auto_drafts'        => true,
				'expired_transients' => true,
			],
			'safety'   => [
				'enabled'        => true,
				'history'        => true,
				'error_beacon'   => true,
				'auto_safe_mode' => true,
			],
		];

		$recommended = array_replace_recursive(
			$safe,
			[
				'js'      => [
					'delay' => true,
				],
				'assets'  => [
					'divi'  => [ 'unload_modules' => true ],
					'bloat' => [
						'embeds'         => true,
						'jquery_migrate' => true,
					],
				],
				'media'   => [ 'lazy_backgrounds' => true ],
				'preload' => [ 'links_eagerness' => 'moderate' ],
			]
		);

		$maximum = array_replace_recursive(
			$recommended,
			[
				'js'      => [
					'lazy_render'     => true,
					'divi_animations' => [ 'disable_on_mobile' => true ],
				],
				'assets'  => [ 'bloat' => [ 'block_library' => true ] ],
				'preload' => [ 'links_mode' => 'prerender' ],
			]
		);

		/** @param array<string, array> $presets */
		$presets = (array) apply_filters(
			'rc-rocket/presets',
			[
				'safe'        => $safe,
				'recommended' => $recommended,
				'maximum'     => $maximum,
			]
		);

		return isset( $presets[ $name ] ) && is_array( $presets[ $name ] ) ? $presets[ $name ] : null;
	}

	public static function apply( Settings $settings, string $name ): bool {
		$values = self::settings( $name );

		if ( null === $values ) {
			return false;
		}

		$settings->merge( $values );
		$settings->set( self::OPTION_PATH, $name );
		$settings->save();

		return true;
	}
}
