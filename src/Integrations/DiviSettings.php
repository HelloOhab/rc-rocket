<?php
declare( strict_types=1 );

namespace RCRocket\Integrations;

use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi's own performance settings, read back and reconciled.
 *
 * Divi 4.10 added a performance tab that does a great deal of what a
 * performance plugin does: critical CSS, dynamic CSS and icons, per-page
 * JavaScript libraries, deferred jQuery, emoji removal. Divi 5 does more of it
 * again, and without a switch.
 *
 * A plugin that ignores all this ends up doing one of two unhelpful things:
 * duplicating work that is already done, or quietly fighting it. So RC Rocket
 * reads those settings and reports where its own features are redundant, where
 * they conflict, and where Divi has left something switched off that it should
 * not have.
 *
 * The option keys are Divi internals and are not part of any public contract,
 * so every read is a guess against a list of candidates and an unresolved key
 * is reported as unknown rather than assumed to be off.
 */
final class DiviSettings {

	/**
	 * @var array<string, array{label:string, keys:string[], good:string}>
	 */
	private const FEATURES = [
		'static_css'        => [
			'label' => 'Static CSS file generation',
			'keys'  => [ 'et_pb_static_css_file' ],
			'good'  => 'on',
		],
		'critical_css'      => [
			'label' => 'Critical CSS',
			'keys'  => [ 'et_pb_critical_css', 'critical_css_enabled', 'et_pb_critical_css_enabled' ],
			'good'  => 'on',
		],
		'dynamic_css'       => [
			'label' => 'Dynamic CSS',
			'keys'  => [ 'et_pb_dynamic_css', 'et_pb_css_dynamic' ],
			'good'  => 'on',
		],
		'dynamic_framework' => [
			'label' => 'Dynamic Module Framework',
			'keys'  => [ 'et_pb_dynamic_module_framework', 'dynamic_module_framework' ],
			'good'  => 'on',
		],
		'dynamic_icons'     => [
			'label' => 'Dynamic Icons',
			'keys'  => [ 'et_pb_dynamic_icons', 'dynamic_icons' ],
			'good'  => 'on',
		],
		'dynamic_js'        => [
			'label' => 'Dynamic JavaScript Libraries',
			'keys'  => [ 'et_pb_dynamic_js_libraries', 'dynamic_js_libraries' ],
			'good'  => 'on',
		],
		'inline_css'        => [
			'label' => 'Load Dynamic Stylesheet In-line',
			'keys'  => [ 'et_pb_css_in_line', 'et_pb_dynamic_css_inline' ],
			'good'  => 'on',
		],
		'disable_emojis'    => [
			'label' => 'Disable WordPress Emojis',
			'keys'  => [ 'et_pb_disable_emojis', 'disable_emojis' ],
			'good'  => 'on',
		],
		'defer_block_css'   => [
			'label' => 'Defer Gutenberg Block CSS',
			'keys'  => [ 'et_pb_defer_block_css', 'defer_block_css' ],
			'good'  => 'on',
		],
		'google_fonts'      => [
			'label' => 'Improve Google Fonts Loading',
			'keys'  => [ 'et_pb_improve_google_fonts_loading', 'improve_google_fonts_loading', 'et_pb_google_fonts_improved' ],
			'good'  => 'on',
		],
		'defer_jquery'      => [
			'label' => 'Defer jQuery And jQuery Migrate',
			'keys'  => [ 'et_pb_defer_jquery_and_migrate', 'defer_jquery_and_migrate' ],
			'good'  => 'on',
		],
		'defer_third_party' => [
			'label' => 'Defer Additional Third Party Scripts',
			'keys'  => [ 'et_pb_defer_third_party_scripts', 'defer_third_party_scripts' ],
			'good'  => 'on',
		],
	];

	public function __construct( private Divi $divi ) {}

	/**
	 * @return array<string, array{label:string, state:string}> state: on, off, unknown
	 */
	public function all(): array {
		$out = [];

		foreach ( self::FEATURES as $id => $feature ) {
			$out[ $id ] = [
				'label' => $feature['label'],
				'state' => $this->state( $feature['keys'] ),
			];
		}

		return $out;
	}

	public function is_on( string $id ): bool {
		return 'on' === ( $this->all()[ $id ]['state'] ?? 'unknown' );
	}

	private function state( array $keys ): string {
		if ( ! function_exists( 'et_get_option' ) ) {
			return 'unknown';
		}

		foreach ( $keys as $key ) {
			$value = et_get_option( $key, 'rcr-missing' );

			if ( 'rcr-missing' === $value ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				return $value ? 'on' : 'off';
			}

			$value = strtolower( trim( (string) $value ) );

			if ( in_array( $value, [ 'on', 'yes', '1', 'true', 'enabled' ], true ) ) {
				return 'on';
			}

			if ( in_array( $value, [ 'off', 'no', '0', 'false', 'disabled' ], true ) ) {
				return 'off';
			}
		}

		return 'unknown';
	}

	/**
	 * Where RC Rocket and Divi are about to do the same job, or fight over one.
	 *
	 * @return array<int, array{severity:string, label:string, detail:string}>
	 */
	public function conflicts( Settings $settings ): array {
		$divi     = $this->all();
		$findings = [];

		$on = fn( string $id ): bool => 'on' === ( $divi[ $id ]['state'] ?? 'unknown' );

		// Redundancy: harmless, but worth knowing so nobody credits the wrong
		// setting for a result.
		if ( $on( 'disable_emojis' ) && $settings->enabled( 'assets.bloat.emojis' ) ) {
			$findings[] = [
				'severity' => 'info',
				'label'    => 'Emoji removal is set in both places',
				'detail'   => 'Divi already removes the emoji script. RC Rocket\'s toggle changes nothing here — leave either one on.',
			];
		}

		if ( $on( 'dynamic_js' ) && $settings->enabled( 'assets.divi.unload_modules' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Two systems unloading the same libraries',
				'detail'   => 'Divi\'s Dynamic JavaScript Libraries already loads only what a page\'s modules need. RC Rocket\'s unloader is doing the same job with less information. Switch ours off.',
			];
		}

		if ( $on( 'defer_third_party' ) && $settings->enabled( 'js.defer' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Third-party deferral set in both places',
				'detail'   => 'Divi and RC Rocket are both adding defer. Pick one owner — ours gives you an exclusion list, Divi\'s does not.',
			];
		}

		// Real conflict: our localizer switches Divi's font loading off, so if
		// the download ever fails there is no fallback path.
		if ( $settings->enabled( 'assets.fonts.localize' ) && $on( 'google_fonts' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Google Fonts handled twice',
				'detail'   => 'RC Rocket stops Divi requesting Google Fonts and serves local copies instead. Divi\'s own font option is then doing nothing. Turn Divi\'s off, or turn ours off.',
			];
		}

		if ( ! $settings->enabled( 'assets.fonts.localize' ) && 'off' === ( $divi['google_fonts']['state'] ?? '' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Google Fonts are unoptimized',
				'detail'   => 'Divi\'s Improve Google Fonts Loading is off and RC Rocket is not localizing them either, so fonts are render-blocking with no font-display. Turn on one of the two.',
			];
		}

		if ( $on( 'defer_jquery' ) && $settings->enabled( 'js.delay' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Delay on top of Divi\'s deferred jQuery',
				'detail'   => 'Divi is already deferring jQuery and running a compatibility script to fix load order. Delaying scripts on top of that is the riskiest combination on a Divi 4 site — test sliders, tabs and forms carefully.',
			];
		}

		if ( 'off' === ( $divi['static_css']['state'] ?? '' ) ) {
			$findings[] = [
				'severity' => 'warn',
				'label'    => 'Divi static CSS generation is off',
				'detail'   => 'Divi will rebuild its stylesheet on every request instead of serving a cached file. Turn it on in Divi > Theme Options > Performance.',
			];
		}

		foreach ( [ 'critical_css', 'dynamic_css', 'dynamic_framework' ] as $id ) {
			if ( 'off' === ( $divi[ $id ]['state'] ?? '' ) ) {
				$findings[] = [
					'severity' => 'warn',
					'label'    => $divi[ $id ]['label'] . ' is off in Divi',
					'detail'   => 'This is Divi\'s own optimization and it is better placed to do it than any plugin. Turn it on in Divi > Theme Options > Performance.',
				];
			}
		}

		return $findings;
	}
}
