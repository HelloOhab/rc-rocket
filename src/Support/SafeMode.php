<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The kill switch.
 *
 * Every module asks this before touching a single byte of output. It exists
 * because the failure mode of a performance plugin is a broken page you cannot
 * see from the admin, and the recovery has to be faster than deactivating a
 * plugin over FTP.
 *
 * Three ways in, in order of precedence:
 *   1. define( 'RC_ROCKET_SAFE_MODE', true ) in wp-config.php — survives a
 *      broken database and a locked-out admin.
 *   2. ?rcr_safe=1 on any URL — for checking whether RC Rocket is the cause of
 *      something without changing any setting.
 *   3. The master toggle in the admin.
 */
final class SafeMode {

	/**
	 * Automatic safe mode is runtime state, not configuration.
	 *
	 * It used to live in the settings option, which meant every trip called
	 * save(), which fired the history snapshot, which recorded the trip. A
	 * site tripping repeatedly buried every real settings change under dozens
	 * of machine-written entries and made rollback — the thing the history
	 * exists for — useless. Runtime state gets its own option and never
	 * touches settings.
	 */
	public const STATE_OPTION = 'rcrocket_auto_safe_mode';

	public const EXPIRED_HOOK = 'rc-rocket/safe-mode/expired';

	private ?bool $active = null;

	public function __construct( private Settings $settings ) {}

	public function is_active(): bool {
		if ( null !== $this->active ) {
			return $this->active;
		}

		$this->active = $this->resolve();

		return $this->active;
	}

	/** Should this request be optimized at all? */
	public function should_optimize(): bool {
		if ( $this->is_active() ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() ) {
			return false;
		}

		// AMP allows no custom script and one stylesheet; anything we add
		// fails validation. Only answerable once the query has been parsed.
		if ( did_action( 'wp' ) && function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}

		// Never touch the Divi builders.
		foreach ( [ 'et_fb', 'et_bfb', 'et_pb_preview', 'et_theme_builder_preview', 'vb' ] as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return false;
			}
		}

		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return false;
		}

		if ( is_user_logged_in() && $this->settings->enabled( 'general.skip_logged_in' ) ) {
			return false;
		}

		/** @param bool $optimize */
		return (bool) apply_filters( 'rc-rocket/should_optimize', true );
	}

	public function reason(): string {
		if ( defined( 'RC_ROCKET_SAFE_MODE' ) && RC_ROCKET_SAFE_MODE ) {
			return 'wp-config';
		}

		if ( isset( $_GET['rcr_safe'] ) && '0' !== (string) $_GET['rcr_safe'] ) { // phpcs:ignore
			return 'url';
		}

		if ( $this->settings->enabled( 'general.safe_mode' ) ) {
			return 'setting';
		}

		if ( $this->auto_until() > time() ) {
			return 'auto';
		}

		return '';
	}

	public function auto_state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return is_array( $state ) ? $state : [];
	}

	public function auto_until(): int {
		return (int) ( $this->auto_state()['until'] ?? 0 );
	}

	public function auto_reason(): string {
		return (string) ( $this->auto_state()['reason'] ?? '' );
	}

	/** Cancel an automatic rollback and let optimization resume. */
	public function release(): void {
		$was_tripped = $this->auto_until() > time();

		delete_option( self::STATE_OPTION );

		$this->active = null;

		if ( $was_tripped ) {
			do_action( 'rc-rocket/safe-mode/changed', false, 'released' );
		}
	}

	/**
	 * Trip the switch automatically. Called by the error beacon when real
	 * visitors start throwing JavaScript errors that were not there before.
	 *
	 * @return bool True when this call is the one that tripped it, false when
	 *              it was already tripped. Concurrent beacon requests would
	 *              otherwise each fire an email and each write state.
	 */
	public function trip( string $why, int $minutes = 60 ): bool {
		if ( $this->auto_until() > time() ) {
			return false;
		}

		$updated = update_option(
			self::STATE_OPTION,
			[
				'until'  => time() + ( $minutes * MINUTE_IN_SECONDS ),
				'reason' => $why,
				'at'     => time(),
			],
			false
		);

		$this->active = true;

		if ( $updated ) {
			// When it lapses on its own, the unoptimized copies cached in the
			// meantime need to go too.
			wp_schedule_single_event( time() + ( $minutes * MINUTE_IN_SECONDS ) + 5, self::EXPIRED_HOOK );

			// Cached pages still carry the optimization that broke them. Safe
			// mode that only reaches uncached pages is not safe mode.
			do_action( 'rc-rocket/safe-mode/changed', true, $why );
		}

		return (bool) $updated;
	}

	private function resolve(): bool {
		if ( defined( 'RC_ROCKET_SAFE_MODE' ) && RC_ROCKET_SAFE_MODE ) {
			return true;
		}

		if ( isset( $_GET['rcr_safe'] ) && '0' !== (string) $_GET['rcr_safe'] ) { // phpcs:ignore
			return true;
		}

		if ( $this->settings->enabled( 'general.safe_mode' ) ) {
			return true;
		}

		return $this->auto_until() > time();
	}
}
