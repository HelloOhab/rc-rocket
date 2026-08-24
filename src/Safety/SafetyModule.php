<?php
declare( strict_types=1 );

namespace RCRocket\Safety;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Context;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module F — the safety net.
 *
 * This module ships before the ones that can break a page, on purpose. It does
 * three things no competitor does: it keeps a diffable history of every
 * settings change with one-click rollback, it listens for JavaScript errors
 * from real visitors, and it turns everything off by itself when those errors
 * spike.
 */
final class SafetyModule implements Module {

	public const HISTORY_OPTION  = 'rcrocket_history';
	public const ERRORS_OPTION   = 'rcrocket_error_log';
	public const BASELINE_OPTION = 'rcrocket_error_baseline';
	private const HISTORY_MAX    = 25;

	public function id(): string {
		return 'safety';
	}

	public function label(): string {
		return __( 'Safety net', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled'          => true,
			'history'          => true,
			'error_beacon'     => true,
			'auto_safe_mode'   => true,
			'error_threshold'  => 4,
			'ignore_third_party' => true,
			'notify_admin'     => true,
		];
	}

	public function register( Container $container ): void {
		$container->set(
			'safety.history',
			static fn( Container $c ): History => new History( $c->get( 'settings' ) )
		);
	}

	public function boot( Container $container ): void {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );

		if ( $settings->enabled( 'safety.history' ) ) {
			add_action( 'rc-rocket/settings/before_save', [ $container->get( 'safety.history' ), 'snapshot' ], 10, 2 );
		}

		add_action( 'rest_api_init', function () use ( $container ): void {
			$this->register_beacon_route( $container );
		} );

		if ( $settings->enabled( 'safety.error_beacon' ) ) {
			add_filter(
				'rc-rocket/html',
				function ( string $html ) use ( $container ): string {
					return $this->inject_beacon( $html, $container );
				},
				90
			);
		}

		add_action( 'admin_notices', function () use ( $container ): void {
			$this->safe_mode_notice( $container );
		} );
	}

	// -------------------------------------------------------------- beacon

	private function inject_beacon( string $html, Container $container ): string {
		/** @var Context $context */
		$context  = $container->get( 'context' );
		$endpoint = esc_url_raw( rest_url( 'rc-rocket/v1/beacon' ) );
		$sig      = esc_js( $context->signature() );

		$script = <<<HTML
<script id="rcr-beacon">
(function () {
  var sent = 0;
  function report(kind, message, source) {
    if (sent >= 3) return;            // Never flood our own endpoint.
    sent++;
    try {
      var body = JSON.stringify({
        kind: kind,
        message: String(message).slice(0, 300),
        source: String(source || '').slice(0, 300),
        signature: '{$sig}',
        page: location.pathname.slice(0, 200)
      });
      if (navigator.sendBeacon) navigator.sendBeacon('{$endpoint}', new Blob([body], { type: 'application/json' }));
      else fetch('{$endpoint}', { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true });
    } catch (e) {}
  }
  window.addEventListener('error', function (e) {
    if (e.target && e.target.tagName && /^(SCRIPT|LINK|IMG)$/.test(e.target.tagName)) {
      report('resource', e.target.tagName + ' failed to load', e.target.src || e.target.href);
      return;
    }
    report('js', e.message, e.filename);
  }, true);
  window.addEventListener('unhandledrejection', function (e) {
    report('promise', (e.reason && e.reason.message) || e.reason, '');
  });
})();
</script>
HTML;

		return HtmlPipeline::before_body_end( $html, $script );
	}

	private function register_beacon_route( Container $container ): void {
		register_rest_route(
			'rc-rocket/v1',
			'/beacon',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( \WP_REST_Request $request ) use ( $container ) {
					return $this->record_error( $request, $container );
				},
			]
		);
	}

	private function record_error( \WP_REST_Request $request, Container $container ): \WP_REST_Response {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );

		$payload = (array) $request->get_json_params();

		$entry = [
			'kind'      => sanitize_text_field( (string) ( $payload['kind'] ?? 'js' ) ),
			'message'   => sanitize_text_field( (string) ( $payload['message'] ?? '' ) ),
			'source'    => esc_url_raw( (string) ( $payload['source'] ?? '' ) ),
			'signature' => sanitize_text_field( (string) ( $payload['signature'] ?? 'unknown' ) ),
			'page'      => sanitize_text_field( (string) ( $payload['page'] ?? '' ) ),
			'time'      => time(),
		];

		$entry['fingerprint'] = self::fingerprint( $entry );

		// A site with no optimizations running cannot be broken by them. Every
		// error seen in that state is how the site already behaves, so it is
		// recorded as baseline and never counts as evidence later. Without
		// this, any site that already logs a console error — which is most of
		// them — would disarm the plugin the moment it was installed.
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		// While safe mode is on, no optimization is reaching anyone, so every
		// error arriving is by definition how the site behaves on its own.
		// Recording these as baseline is what lets the plugin learn its way out
		// of a rollback instead of re-tripping on the same errors forever.
		$armed = ! $safe->is_active() && self::risky_optimizations_active( $settings );

		$baseline = (array) get_option( self::BASELINE_OPTION, [] );

		if ( ! $armed ) {
			$baseline[ $entry['fingerprint'] ] = [
				'message' => $entry['message'],
				'source'  => $entry['source'],
				'seen'    => time(),
			];

			update_option( self::BASELINE_OPTION, array_slice( $baseline, -80, null, true ), false );
		}

		$entry['baseline'] = isset( $baseline[ $entry['fingerprint'] ] );

		$log = (array) get_option( self::ERRORS_OPTION, [] );
		array_unshift( $log, $entry );
		update_option( self::ERRORS_OPTION, array_slice( $log, 0, 100 ), false );

		if ( ! $armed || ! $settings->enabled( 'safety.auto_safe_mode' ) ) {
			return $this->beacon_response();
		}

		// Count distinct new problems, not repeats of one. A single broken
		// script viewed by twenty people is one problem, not twenty.
		$window = time() - ( 15 * MINUTE_IN_SECONDS );
		$novel  = [];

		foreach ( $log as $line ) {
			if ( (int) ( $line['time'] ?? 0 ) <= $window || ! empty( $line['baseline'] ) ) {
				continue;
			}

			if ( ! self::attributable( (array) $line ) ) {
				continue;
			}

			if ( $settings->enabled( 'safety.ignore_third_party' ) ) {
				if ( self::is_third_party( (string) ( $line['source'] ?? '' ) ) ) {
					continue;
				}

				// "Script error." with no source is the browser withholding the
				// detail of a cross-origin failure. It is always a third party
				// and it can never be acted on, so it never counts.
				if ( '' === (string) ( $line['source'] ?? '' ) && str_starts_with( (string) ( $line['message'] ?? '' ), 'Script error' ) ) {
					continue;
				}
			}

			$novel[ (string) ( $line['fingerprint'] ?? '' ) ] = true;
		}

		$threshold = max( 2, (int) $settings->get( 'safety.error_threshold', 4 ) );

		if ( count( $novel ) >= $threshold && ! $safe->is_active() ) {
				if ( ! $safe->trip( sprintf( '%d new JavaScript errors in 15 minutes', count( $novel ) ), 120 ) ) {
				return $this->beacon_response();
			}

			if ( $settings->enabled( 'safety.notify_admin' ) ) {
				$this->notify( $entry, count( $novel ) );
			}

			$container->get( 'logger' )->error( 'Auto safe mode engaged', [ 'new_errors' => count( $novel ) ] );
		}

		return $this->beacon_response();
	}

	private function beacon_response(): \WP_REST_Response {
		$response = rest_ensure_response( [ 'recorded' => true ] );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Two errors are the same problem when the message and the failing file
	 * match. Line numbers and query strings move between builds; the pair does
	 * not.
	 */
	public static function fingerprint( array $entry ): string {
		$message = strtolower( (string) ( $entry['message'] ?? '' ) );
		$message = (string) preg_replace( '/\d+/', 'N', $message );

		$source = (string) ( $entry['source'] ?? '' );
		$source = (string) ( wp_parse_url( $source, PHP_URL_PATH ) ?: $source );

		return substr( md5( $message . '|' . $source ), 0, 16 );
	}

	/**
	 * Is anything running that could plausibly have broken the page?
	 *
	 * Head cleanup and lazy loading are not on this list. Neither can produce a
	 * JavaScript error, so neither should ever be rolled back because of one.
	 */
	public static function risky_optimizations_active( Settings $settings ): bool {
		if ( $settings->enabled( 'js.defer' ) || $settings->enabled( 'js.delay' ) ) {
			return true;
		}

		if ( $settings->enabled( 'js.lazy_render' ) ) {
			return true;
		}

		return (bool) array_filter( (array) $settings->get( 'assets.rules', [] ) );
	}

	/**
	 * Could this error plausibly have been caused by an optimization?
	 *
	 * Only script execution failures qualify. A missing image, a stylesheet
	 * that 404s, a blocked tracking pixel — none of these can be produced by
	 * deferring a script, dequeuing a handle or adding a loading attribute.
	 * They are real bugs worth fixing, but rolling back an optimization will
	 * never fix them, and a rollback that cannot help is just downtime for the
	 * optimization.
	 */
	private static function attributable( array $entry ): bool {
		$kind = (string) ( $entry['kind'] ?? '' );

		if ( in_array( $kind, [ 'js', 'promise' ], true ) ) {
			return true;
		}

		// A script that fails to load is the one resource error we might own,
		// since delaying rewrites script tags.
		if ( 'resource' === $kind && str_starts_with( (string) ( $entry['message'] ?? '' ), 'SCRIPT' ) ) {
			return true;
		}

		return false;
	}

	/** Blocked analytics and ad-blocked third parties are not our doing. */
	private static function is_third_party( string $source ): bool {
		if ( '' === $source ) {
			return false;
		}

		$host = (string) ( wp_parse_url( $source, PHP_URL_HOST ) ?: '' );

		if ( '' === $host ) {
			return false;
		}

		return $host !== (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
	}

	private function notify( array $entry, int $count ): void {
		$sent = get_transient( 'rcrocket_notified' );

		if ( $sent ) {
			return;
		}

		set_transient( 'rcrocket_notified', 1, 6 * HOUR_IN_SECONDS );

		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf( '[%s] RC Rocket switched itself off', (string) get_bloginfo( 'name' ) ),
			sprintf(
				"RC Rocket detected %d JavaScript errors from real visitors in 15 minutes and disabled its optimizations for two hours.\n\nMost recent error:\n%s\n%s\nOn: %s\n\nReview: %s",
				$count,
				$entry['message'],
				$entry['source'],
				$entry['signature'],
				admin_url( 'admin.php?page=rc-rocket' )
			)
		);
	}

	private function safe_mode_notice( Container $container ): void {
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		if ( ! $safe->is_active() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reasons = [
			'wp-config' => __( 'Safe mode is on via wp-config.php. Remove the RC_ROCKET_SAFE_MODE constant to resume.', 'rc-rocket' ),
			'url'       => __( 'Safe mode is on for this request only, because of ?rcr_safe=1 in the URL.', 'rc-rocket' ),
			'setting'   => __( 'Safe mode is on. RC Rocket is not changing any page output.', 'rc-rocket' ),
			'auto'      => __( 'RC Rocket switched itself off after JavaScript errors from real visitors. Check the Safety tab.', 'rc-rocket' ),
		];

		printf(
			'<div class="notice notice-warning"><p><strong>RC Rocket:</strong> %s</p></div>',
			esc_html( $reasons[ $safe->reason() ] ?? $reasons['setting'] )
		);
	}
}
