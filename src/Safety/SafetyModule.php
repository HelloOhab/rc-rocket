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
	private const TRIPPED_TODAY  = 'rcrocket_auto_tripped';

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
			'error_threshold'  => 3,
			'min_reporters'    => 2,
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

		if ( $settings->enabled( 'safety.error_beacon' ) ) {
			// A public endpoint exists only while something on the page posts
			// to it. Switching the beacon off has to close the door as well.
			add_action( 'rest_api_init', function () use ( $container ): void {
				$this->register_beacon_route( $container );
			} );

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
		$token    = self::token();

		$script = <<<HTML
<script id="rcr-beacon">
(function () {
  var sent = 0;
  // What jQuery was when the error happened: a version, a stand-in that
  // only queues ready handlers (a deferred jQuery not arrived yet), or none.
  function jq() {
    var j = window.jQuery;
    if (!j) return 'none';
    return j.fn && j.fn.jquery ? String(j.fn.jquery).slice(0, 12) : 'stand-in';
  }
  function report(kind, message, source) {
    if (sent >= 3) return;            // Never flood our own endpoint.
    sent++;
    try {
      var body = JSON.stringify({
        kind: kind,
        message: String(message).slice(0, 300),
        source: String(source || '').slice(0, 300),
        signature: '{$sig}',
        token: '{$token}',
        page: location.pathname.slice(0, 200),
        jquery: jq(),
        delayed: document.querySelectorAll('script[type="rcrocket/delayed"]').length
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

		// First thing in <head>: an error thrown while the page is still
		// loading (the "jQuery(...).on is not a function" kind) happens
		// before anything placed at the end of the page is listening.
		return HtmlPipeline::after_head_start( $html, $script );
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

		// The page sends a few hundred bytes. Anything far larger is not ours.
		if ( strlen( (string) $request->get_body() ) > 4096 ) {
			return $this->beacon_response();
		}

		$payload  = (array) $request->get_json_params();
		$reporter = self::reporter_id();

		// The endpoint is public by necessity — visitors report the errors —
		// so nothing it receives is trusted. A report must carry the token the
		// page was rendered with, each visitor gets a small budget, and no
		// single visitor can ever be the whole case for switching off.
		if ( ! self::token_valid( (string) ( $payload['token'] ?? '' ) ) || ! self::within_budget( $reporter ) ) {
			return $this->beacon_response();
		}

		// The page truncates these too; the server cannot rely on it.
		$entry = [
			'kind'      => mb_substr( sanitize_text_field( (string) ( $payload['kind'] ?? 'js' ) ), 0, 20 ),
			'message'   => mb_substr( sanitize_text_field( (string) ( $payload['message'] ?? '' ) ), 0, 300 ),
			'source'    => mb_substr( esc_url_raw( (string) ( $payload['source'] ?? '' ) ), 0, 300 ),
			'signature' => mb_substr( sanitize_text_field( (string) ( $payload['signature'] ?? 'unknown' ) ), 0, 100 ),
			'page'      => mb_substr( sanitize_text_field( (string) ( $payload['page'] ?? '' ) ), 0, 200 ),
			'jquery'    => substr( sanitize_text_field( (string) ( $payload['jquery'] ?? '' ) ), 0, 12 ),
			'delayed'   => max( 0, min( 999, (int) ( $payload['delayed'] ?? 0 ) ) ),
			'reporter'  => $reporter,
			'time'      => time(),
		];

		$entry['fingerprint'] = self::fingerprint( $entry );

		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );

		// A site with no optimizations running cannot be broken by them. Every
		// error seen in that state is how the site already behaves, so it is
		// recorded as baseline and never counts as evidence later. Without
		// this, any site that already logs a console error — which is most of
		// them — would disarm the plugin the moment it was installed.
		//
		// While safe mode is on, no optimization is reaching anyone, so every
		// error arriving is by definition how the site behaves on its own.
		// Recording these as baseline is what lets the plugin learn its way out
		// of a rollback instead of re-tripping on the same errors forever.
		$armed = ! $safe->is_active() && self::risky_optimizations_active( $settings );

		$baseline = (array) get_option( self::BASELINE_OPTION, [] );

		if ( ! $armed ) {
			$known     = (array) ( $baseline[ $entry['fingerprint'] ] ?? [] );
			$reporters = array_slice( array_values( array_unique( array_merge( (array) ( $known['reporters'] ?? [] ), [ $reporter ] ) ) ), -5 );

			$baseline[ $entry['fingerprint'] ] = [
				'message'   => $entry['message'],
				'source'    => $entry['source'],
				'seen'      => time(),
				'reporters' => $reporters,
			];

			update_option( self::BASELINE_OPTION, array_slice( $baseline, -80, null, true ), false );
		}

		$entry['baseline'] = self::is_baseline( $baseline, $entry['fingerprint'] );

		$log = (array) get_option( self::ERRORS_OPTION, [] );
		array_unshift( $log, $entry );
		update_option( self::ERRORS_OPTION, array_slice( $log, 0, 100 ), false );

		if ( ! $armed || ! $settings->enabled( 'safety.auto_safe_mode' ) ) {
			return $this->beacon_response();
		}

		// Count distinct new problems, not repeats of one. A single broken
		// script viewed by twenty people is one problem, not twenty. And a
		// problem only counts once more than one visitor has seen it: a real
		// regression reproduces for everyone, a forged report does not.
		$window        = time() - ( 15 * MINUTE_IN_SECONDS );
		$min_reporters = max( 1, (int) $settings->get( 'safety.min_reporters', 2 ) );
		$novel         = [];

		foreach ( $log as $line ) {
			if ( (int) ( $line['time'] ?? 0 ) <= $window || ! empty( $line['baseline'] ) ) {
				continue;
			}

			if ( ! self::attributable( (array) $line ) ) {
				continue;
			}

			// No source, no evidence: nothing ties the error to a script we
			// touched, and it is the cheapest report to forge.
			if ( '' === (string) ( $line['source'] ?? '' ) ) {
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

			$novel[ (string) ( $line['fingerprint'] ?? '' ) ][ (string) ( $line['reporter'] ?? '' ) ] = true;
		}

		$confirmed = count( array_filter( $novel, static fn( array $reporters ): bool => count( $reporters ) >= $min_reporters ) );
		$threshold = max( 1, (int) $settings->get( 'safety.error_threshold', 3 ) );

		if ( $confirmed >= $threshold && ! $safe->is_active() ) {
			// Once a day at most. Reports can be forged, and a switch that can
			// be flipped again the moment it resets is an outage on repeat.
			// The first trip makes the point; after that, a person decides.
			if ( get_transient( self::TRIPPED_TODAY ) ) {
				return $this->beacon_response();
			}

			if ( ! $safe->trip( sprintf( '%d new JavaScript errors in 15 minutes', $confirmed ), 120 ) ) {
				return $this->beacon_response();
			}

			set_transient( self::TRIPPED_TODAY, time(), DAY_IN_SECONDS );

			if ( $settings->enabled( 'safety.notify_admin' ) ) {
				$this->notify( $entry, $confirmed );
			}

			$container->get( 'logger' )->error( 'Auto safe mode engaged', [ 'new_errors' => $confirmed ] );
		}

		return $this->beacon_response();
	}

	/**
	 * A fingerprint is baseline once more than one visitor has reported it
	 * while nothing risky was running. One report is not enough: otherwise a
	 * single forged request could pre-excuse a real future failure.
	 */
	public static function is_baseline( array $baseline, string $fingerprint ): bool {
		if ( ! isset( $baseline[ $fingerprint ] ) ) {
			return false;
		}

		$reporters = $baseline[ $fingerprint ]['reporters'] ?? null;

		// Entries written before reporters were tracked were trusted then.
		return null === $reporters || count( (array) $reporters ) >= 2;
	}

	/**
	 * A visitor, coarsely: the address, or its /64 for IPv6 where one
	 * subscriber holds billions. Hashed, never stored raw.
	 */
	public static function reporter_id(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore

		if ( str_contains( $ip, ':' ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore
			$ip     = false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) );
		}

		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 12 );
	}

	/** Ten reports per visitor per ten minutes, then silence. */
	private static function within_budget( string $reporter ): bool {
		$key   = 'rcr_beacon_' . $reporter;
		$count = (int) get_transient( $key );

		if ( $count >= 10 ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * A daily token baked into the page. It proves the report came from a
	 * page this site rendered recently, which rules out blind scripted
	 * reports; the per-visitor rules above deal with everything else.
	 */
	public static function token( int $days_ago = 0 ): string {
		$day = gmdate( 'Ymd', time() - ( $days_ago * DAY_IN_SECONDS ) );

		return substr( hash_hmac( 'sha256', 'rcr-beacon|' . $day, wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function token_valid( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		// Cached pages carry the token they were rendered with.
		for ( $days = 0; $days <= 7; $days++ ) {
			if ( hash_equals( self::token( $days ), $token ) ) {
				return true;
			}
		}

		return false;
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
