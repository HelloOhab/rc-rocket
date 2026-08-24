<?php
declare( strict_types=1 );

namespace RCRocket\Integrations;

use RCRocket\Frontend\HtmlPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi entrance animations.
 *
 * Divi's animation options are applied per module and cost more than they look
 * like they cost. Each animated element starts at opacity zero, waits for a
 * JavaScript waypoint to fire, then runs a keyframe animation that usually
 * touches properties the compositor cannot handle on its own. A page with
 * forty-six of them spends real time on layout and paint, produces long tasks,
 * and shifts as elements arrive.
 *
 * Two problems, one of which is a genuine hazard:
 *
 *   1. Cost. Non-composited animations run on the main thread. On a mid-range
 *      phone this is measurable in Total Blocking Time and Interaction to Next
 *      Paint, and it contributes to layout shift.
 *
 *   2. Invisible content. Divi hides an element until its waypoint fires. If
 *      the script that fires waypoints is deferred, delayed, blocked by an
 *      extension or simply slow, the element never becomes visible — and
 *      because it is invisible rather than absent, nothing looks broken. It is
 *      just missing. Any plugin that defers scripts on a Divi site makes this
 *      more likely, so a plugin that defers scripts owes the site a safety net.
 */
final class DiviAnimations {

	public function __construct( private Divi $divi, private array $config ) {}

	public function applicable(): bool {
		return $this->divi->is_active();
	}

	public function rewrite( string $html ): string {
		if ( ! $this->applicable() ) {
			return $html;
		}

		$css = $this->css();

		if ( '' === $css ) {
			return $html;
		}

		return HtmlPipeline::after_head_start( $html, '<style id="rcr-divi-animations">' . $css . '</style>' );
	}

	private function css(): string {
		$rules = [];

		// The safety net. Always on when the module is: an element that never
		// becomes visible is a worse failure than an animation that does not
		// play, and this costs nothing when the waypoint does fire.
		if ( ! empty( $this->config['reveal_fallback'] ) ) {
			$delay = max( 1, (int) ( $this->config['reveal_after'] ?? 3 ) );

			$rules[] = sprintf(
				'.et_pb_section.et-waypoint:not(.et-animated),.et_pb_row.et-waypoint:not(.et-animated),'
				. '.et_pb_module.et-waypoint:not(.et-animated){opacity:1!important;animation-delay:0s!important}'
				. '@keyframes rcr-reveal{to{opacity:1}}'
				. '.et-waypoint:not(.et-animated){animation:rcr-reveal 0.01s linear %ds forwards}',
				$delay
			);
		}

		$disable = 'html body .et_pb_section,html body .et_pb_row,html body .et_pb_module';
		$neutral = '{animation:none!important;opacity:1!important;transform:none!important;transition:none!important}';

		if ( ! empty( $this->config['disable_on_mobile'] ) ) {
			$breakpoint = max( 320, (int) ( $this->config['mobile_breakpoint'] ?? 980 ) );

			// Where the main thread is scarcest and the animation is least
			// visible anyway, because everything is stacked in one column.
			$rules[] = sprintf( '@media (max-width:%dpx){%s%s}', $breakpoint, $disable, $neutral );
		}

		if ( ! empty( $this->config['respect_reduced_motion'] ) ) {
			// Divi does not honour this preference on its own.
			$rules[] = sprintf( '@media (prefers-reduced-motion:reduce){%s%s}', $disable, $neutral );
		}

		if ( ! empty( $this->config['disable_everywhere'] ) ) {
			$rules[] = $disable . $neutral;
		}

		return implode( '', $rules );
	}

	/**
	 * Count what a page is actually asking for, so the admin can see the scale
	 * of it rather than take our word for it.
	 */
	public static function count_animated( string $html ): int {
		return (int) preg_match_all( '#class=["\'][^"\']*(?:et_animated|et-waypoint|et_pb_animation_)[^"\']*["\']#i', $html );
	}

	public static function defaults(): array {
		return [
			'enabled'                => true,
			'reveal_fallback'        => true,
			'reveal_after'           => 3,
			'disable_on_mobile'      => false,
			'mobile_breakpoint'      => 980,
			'respect_reduced_motion' => true,
			'disable_everywhere'     => false,
		];
	}
}
