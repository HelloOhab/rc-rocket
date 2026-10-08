<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Integrations\Divi;
use RCRocket\Support\Hosting;
use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge coordination on a host that owns the page cache.
 *
 * Kinsta (and every other managed host) purges a post's URLs by itself when
 * the post changes, and does it surgically. What it cannot see are the changes
 * that alter every page at once without touching a post: a Divi theme option,
 * a Theme Builder header, a global library layout, an RC Rocket setting, an
 * automatic rollback. Those are the only purges forwarded here.
 *
 * Forwarding is debounced rather than dropped. A burst of saves inside a
 * minute becomes one flush at the end of it, never a lost one.
 */
final class HostBridge {

	public const DEFERRED_HOOK = 'rc-rocket/host/deferred-purge';

	/** Reasons that must not wait out the rate limit. */
	private const URGENT = [ 'manual', 'safe-mode' ];

	public function __construct(
		private Hosting $hosting,
		private Divi $divi,
		private Logger $logger
	) {}

	public function hooks(): void {
		add_action( 'rc-rocket/purge/all', [ $this, 'purge_all' ], 10, 1 );
		add_action( 'rc-rocket/purge/key', [ $this, 'purge_key' ], 10, 1 );
		add_action( 'rc-rocket/purge/url', [ $this, 'purge_url' ], 10, 1 );
		add_action( self::DEFERRED_HOOK, [ $this, 'deferred' ], 10, 1 );

		// Global presentation changes the host has no way to notice.
		add_action( 'customize_save_after', fn() => $this->purge_all( 'customizer' ) );
		add_action( 'wp_update_nav_menu', fn() => $this->purge_all( 'menu' ) );
		add_action( 'switch_theme', fn() => $this->purge_all( 'theme' ) );
	}

	/**
	 * @param mixed $reason Why. Free-form, logged, and used to decide urgency.
	 */
	public function purge_all( mixed $reason = 'automatic' ): bool {
		$reason = is_string( $reason ) && '' !== $reason ? $reason : 'automatic';

		if ( ! in_array( $reason, self::URGENT, true ) && ! $this->hosting->can_purge_now() ) {
			if ( ! wp_next_scheduled( self::DEFERRED_HOOK, [ $reason ] ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::DEFERRED_HOOK, [ $reason ] );
			}

			return false;
		}

		return $this->forward( $reason );
	}

	/**
	 * A key purge names content the host already handles, with one exception:
	 * a Theme Builder template renders on pages that never changed.
	 */
	public function purge_key( mixed $key ): void {
		if ( is_string( $key ) && str_starts_with( $key, 'divi-tb' ) ) {
			$this->purge_all( 'divi-theme-builder' );
		}
	}

	/**
	 * One page, on request: "Clear this page" in the admin bar or "Clear
	 * cache" in the post list. Where the host cannot clear a single URL the
	 * whole host cache goes instead, since someone explicitly asked.
	 * Background refreshes ($explicit false) never escalate to that: the
	 * hero beacon is public, and a full flush skips the rate limit.
	 */
	public function purge_url( mixed $url, bool $explicit = true ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		if ( ! $this->hosting->purge_host_url( $url ) ) {
			return $explicit ? $this->purge_all( 'manual' ) : false;
		}

		$this->logger->debug(
			'Forwarded URL purge to host',
			[
				'host' => $this->hosting->id(),
				'url'  => $url,
			]
		);

		do_action( 'rc-rocket/host/purged_url', $url );

		return true;
	}

	public function deferred( string $reason = 'automatic' ): void {
		$this->forward( $reason );
	}

	private function forward( string $reason ): bool {
		$this->hosting->mark_purged();

		// Order matters. Divi's CSS must be invalidated first, then the page
		// cache dropped, so no cached HTML is ever left pointing at a
		// stylesheet that no longer exists.
		if ( $this->divi->is_active() ) {
			$this->divi->clear_et_cache( 'all', 0 );
		}

		$handled = $this->hosting->purge_host_cache();

		$this->logger->debug(
			'Forwarded purge to host',
			[
				'host'    => $this->hosting->id(),
				'handled' => $handled,
				'reason'  => $reason,
			]
		);

		if ( $handled ) {
			do_action( 'rc-rocket/host/purged', $reason );
		}

		return $handled;
	}
}
