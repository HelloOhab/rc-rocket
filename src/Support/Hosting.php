<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Managed-host detection.
 *
 * Some hosts run a page cache at the server level and forbid PHP ones outright.
 * Kinsta is the strictest: its MU plugin owns advanced-cache.php and blocks
 * caching plugins at install time. Fighting that is not a fixable bug, it is a
 * category error — on those hosts RC Rocket must switch off its own page cache
 * and become the tuning layer instead, forwarding purges to the host.
 *
 * This is exactly what WP Rocket does to stay off Kinsta's banned list, and it
 * is the only reason it is allowed there.
 */
final class Hosting {

	private ?array $detected = null;

	/**
	 * @return array{id:string, label:string, page_cache:bool, object_cache:bool, editable_server_config:bool}
	 */
	public function detect(): array {
		if ( null !== $this->detected ) {
			return $this->detected;
		}

		$this->detected = $this->resolve();

		return $this->detected;
	}

	public function id(): string {
		return $this->detect()['id'];
	}

	/** True when the host already caches pages and ours must stand down. */
	public function manages_page_cache(): bool {
		return $this->detect()['page_cache'];
	}

	public function can_edit_server_config(): bool {
		return $this->detect()['editable_server_config'];
	}

	private function resolve(): array {
		$known = [
			[
				'id'    => 'kinsta',
				'label' => 'Kinsta',
				'test'  => static fn(): bool => defined( 'KINSTAMU_VERSION' ) || class_exists( '\Kinsta\Cache' ) || ! empty( $_SERVER['KINSTA_CACHE_ZONE'] ),
				'page_cache'   => true,
				'object_cache' => true,
				'editable'     => false,
			],
			[
				'id'    => 'wpengine',
				'label' => 'WP Engine',
				'test'  => static fn(): bool => class_exists( '\WpeCommon' ) || ! empty( $_SERVER['IS_WPE'] ),
				'page_cache'   => true,
				'object_cache' => true,
				'editable'     => false,
			],
			[
				'id'    => 'siteground',
				'label' => 'SiteGround',
				'test'  => static fn(): bool => defined( 'SiteGround_Optimizer\VERSION' ) || function_exists( 'sg_cachepress_purge_cache' ),
				'page_cache'   => true,
				'object_cache' => false,
				'editable'     => false,
			],
			[
				'id'    => 'pressable',
				'label' => 'Pressable',
				'test'  => static fn(): bool => defined( 'IS_PRESSABLE' ),
				'page_cache'   => true,
				'object_cache' => true,
				'editable'     => false,
			],
			[
				'id'    => 'flywheel',
				'label' => 'Flywheel',
				'test'  => static fn(): bool => defined( 'FLYWHEEL_CONFIG_DIR' ),
				'page_cache'   => true,
				'object_cache' => false,
				'editable'     => false,
			],
			[
				'id'    => 'rocketnet',
				'label' => 'Rocket.net',
				'test'  => static fn(): bool => defined( 'ROCKET_CDN_URL' ) || ! empty( $_SERVER['HTTP_X_ROCKET_CDN'] ),
				'page_cache'   => true,
				'object_cache' => true,
				'editable'     => false,
			],
			[
				'id'    => 'cloudways',
				'label' => 'Cloudways',
				'test'  => static fn(): bool => defined( 'BREEZE_VERSION' ) || is_dir( '/home/master/applications' ),
				'page_cache'   => true,
				'object_cache' => false,
				'editable'     => true,
			],
		];

		foreach ( $known as $host ) {
			if ( ( $host['test'] )() ) {
				return [
					'id'                     => $host['id'],
					'label'                  => $host['label'],
					'page_cache'             => $host['page_cache'],
					'object_cache'           => $host['object_cache'],
					'editable_server_config' => $host['editable'],
				];
			}
		}

		return [
			'id'                     => 'generic',
			'label'                  => 'Self-managed or unrecognised host',
			'page_cache'             => false,
			'object_cache'           => wp_using_ext_object_cache(),
			'editable_server_config' => true,
		];
	}

	/**
	 * Should an automatic, content-driven purge be forwarded to the host?
	 *
	 * No — and this matters more than it sounds. Managed hosts already purge
	 * their own cache when content changes, and they do it surgically. Our
	 * forwarding is a complete flush, so passing every post save, comment and
	 * term edit through it wipes the entire site cache several times a day.
	 * The result is a cache that is permanently cold and a TTFB that looks
	 * like the host is broken.
	 *
	 * Only an explicit purge — the button, or WP-CLI — is worth forwarding.
	 */
	public function should_forward_automatic_purges(): bool {
		/** @param bool $forward */
		return (bool) apply_filters( 'rc-rocket/host/forward_automatic_purges', false, $this->id() );
	}

	/**
	 * Rate limit: even explicit purges should not be able to flush a
	 * production cache in a loop.
	 */
	public function can_purge_now(): bool {
		$last = (int) get_transient( 'rcrocket_host_purge_at' );

		if ( $last > 0 ) {
			return false;
		}

		set_transient( 'rcrocket_host_purge_at', time(), 60 );

		return true;
	}

	/**
	 * Forward a purge to whatever the host uses.
	 *
	 * Every branch is guarded, because these are undocumented internals that
	 * can change without notice. A failed forward is logged, never fatal.
	 */
	public function purge_host_cache(): bool {
		$id = $this->id();

		switch ( $id ) {
			case 'kinsta':
				global $kinsta_cache;

				if ( isset( $kinsta_cache->kinsta_cache_purge ) && method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
					$kinsta_cache->kinsta_cache_purge->purge_complete_caches();

					return true;
				}

				// The MU plugin also listens on its own hook in newer versions.
				do_action( 'kinsta_cache_purge' );

				return has_action( 'kinsta_cache_purge' ) > 0;

			case 'wpengine':
				if ( class_exists( '\WpeCommon' ) && method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();

					return true;
				}

				return false;

			case 'siteground':
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();

					return true;
				}

				return false;

			case 'cloudways':
				do_action( 'breeze_clear_all_cache' );

				return true;

			case 'pressable':
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();

					return true;
				}

				return false;
		}

		/**
		 * Escape hatch for hosts not covered above.
		 *
		 * @param bool   $handled
		 * @param string $host_id
		 */
		return (bool) apply_filters( 'rc-rocket/host/purge', false, $id );
	}

	public function report(): array {
		$detected = $this->detect();

		return $detected + [
			'our_page_cache_disabled' => $detected['page_cache'],
			'reason'                  => $detected['page_cache']
				? sprintf(
					/* translators: %s: host name */
					__( '%s runs its own server-level page cache, which is faster than any PHP cache and does not allow a second one. RC Rocket has switched its page cache off and now forwards purges to the host instead.', 'rc-rocket' ),
					$detected['label']
				)
				: '',
		];
	}
}
