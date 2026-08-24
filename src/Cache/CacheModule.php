<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviSettings;
use RCRocket\Support\Logger;
use RCRocket\Support\Hosting;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — the cache engine.
 */
final class CacheModule implements Module {

	public const CLEANUP_HOOK = 'rc-rocket/cache/cleanup';

	private ?float $started = null;

	public function id(): string {
		return 'cache';
	}

	public function label(): string {
		return __( 'Cache engine', 'rc-rocket' );
	}

	public function defaults(): array {
		return array_merge(
			Key::config_defaults(),
			[
				'enabled'            => true,
				'server_delivery'    => false,
				'preload_on_purge'   => true,
				'preload_batch_size' => 8,
				'preload_max_urls'   => 500,
				'footer_signature'   => true,
				// Divi-specific.
				'clear_divi_cache'    => true,
				'refresh_form_nonces' => true,
				'bypass_divi_builder' => true,
				'fix_viewport'          => false,
				'hard_clear_divi_cache' => false,
			]
		);
	}

	public function register( Container $container ): void {
		$container->set(
			'hosting',
			static fn(): Hosting => new Hosting()
		);

		$container->set(
			'cache.dir',
			static fn(): string => (string) apply_filters( 'rc-rocket/cache/dir', WP_CONTENT_DIR . '/cache/rc-rocket' )
		);

		$container->set(
			'cache.config',
			static function ( Container $c ): array {
				/** @var Settings $settings */
				$settings = $c->get( 'settings' );

				return (array) $settings->get( 'cache', [] );
			}
		);

		$container->set(
			'cache.store',
			static fn( Container $c ): Store => new Store( $c->get( 'cache.dir' ), $c->get( 'cache.config' ) )
		);

		$container->set(
			'divi',
			static fn( Container $c ): Divi => new Divi( $c->get( 'logger' ), $c->get( 'cache.config' ) )
		);

		$container->set(
			'divi.settings',
			static fn( Container $c ): DiviSettings => new DiviSettings( $c->get( 'divi' ) )
		);

		$container->set(
			'cache.rules',
			static fn( Container $c ): Rules => new Rules( $c->get( 'cache.config' ), $c->get( 'divi' ) )
		);

		$container->set(
			'cache.purge',
			static fn( Container $c ): Purge => new Purge( $c->get( 'cache.store' ), $c->get( 'logger' ), $c->get( 'cache.config' ) )
		);

		$container->set(
			'cache.preloader',
			static fn( Container $c ): Preloader => new Preloader( $c->get( 'cache.store' ), $c->get( 'logger' ), $c->get( 'cache.config' ) )
		);

		$container->set(
			'cache.dropin',
			static fn( Container $c ): Dropin => new Dropin( $c->get( 'cache.dir' ) )
		);

		$container->set(
			'cache.server_rules',
			static fn( Container $c ): ServerRules => new ServerRules( $c->get( 'cache.dir' ) )
		);
	}

	public function boot( Container $container ): void {
		/** @var Hosting $hosting */
		$hosting = $container->get( 'hosting' );

		// On a host with server-level page caching, ours must not run at all.
		// Purges still matter, so they are forwarded rather than dropped.
		if ( $hosting->manages_page_cache() ) {
			$this->boot_passthrough( $container, $hosting );

			return;
		}

		/** @var Purge $purge */
		$purge = $container->get( 'cache.purge' );
		$purge->hooks();

		/** @var Preloader $preloader */
		$preloader = $container->get( 'cache.preloader' );
		$preloader->hooks();

		/** @var Divi $divi */
		$divi = $container->get( 'divi' );

		if ( $divi->is_active() ) {
			$divi->hooks();
		}

		add_action(
			'template_redirect',
			function () use ( $container ): void {
				$this->start_buffer( $container );
			},
			0
		);

		add_action( self::CLEANUP_HOOK, static fn() => $container->get( 'cache.store' )->purge_expired() );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK );
		}

		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 100 );

		// Keep the drop-in's view of the world in sync with the option.
		add_action(
			'rc-rocket/settings/saved',
			static function ( array $settings ) use ( $container ): void {
				$container->get( 'cache.dropin' )->write_config( (array) ( $settings['cache'] ?? [] ) );
				$container->get( 'cache.purge' )->all();
			}
		);
	}

	/**
	 * Managed-host mode: no buffering, no drop-in, no disk writes. RC Rocket
	 * becomes the optimization layer and the purge coordinator, which is the
	 * only configuration these hosts permit.
	 */
	private function boot_passthrough( Container $container, Hosting $hosting ): void {
		/** @var Divi $divi */
		$divi = $container->get( 'divi' );

		if ( $divi->is_active() ) {
			$divi->hooks();
		}

		$forward = static function ( bool $explicit ) use ( $container, $hosting ): void {
			// Automatic content purges are the host's job and it already does
			// them precisely. Ours is a full flush, so forwarding every post
			// save would keep the cache permanently cold.
			if ( ! $explicit && ! $hosting->should_forward_automatic_purges() ) {
				return;
			}

			if ( ! $hosting->can_purge_now() ) {
				return;
			}

			/** @var Divi $divi */
			$divi = $container->get( 'divi' );

			// Order matters. Divi's CSS must be invalidated first, then the page
			// cache dropped, so no cached HTML is ever left pointing at a
			// stylesheet that no longer exists.
			if ( $divi->is_active() ) {
				$divi->clear_et_cache( 'all', 0 );
			}

			$purged = $hosting->purge_host_cache();

			$container->get( 'logger' )->debug(
				'Forwarded purge to host',
				[
					'host'     => $hosting->id(),
					'handled'  => $purged,
					'explicit' => $explicit,
				]
			);
		};

		// Only an explicit purge reaches the host. Divi's asset cache is still
		// invalidated on content changes, because that part is ours to own.
		add_action( 'rc-rocket/purge/all', static fn() => $forward( true ), 10, 0 );

		foreach ( [ 'rc-rocket/purge/key', 'rc-rocket/purge/url' ] as $hook ) {
			add_action(
				$hook,
				static function () use ( $container ): void {
					/** @var Divi $divi */
					$divi = $container->get( 'divi' );

					if ( $divi->is_active() ) {
						$divi->clear_et_cache( 'all', 0 );
					}
				},
				10,
				0
			);
		}

		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 100 );
	}

	private function start_buffer( Container $container ): void {
		/** @var Rules $rules */
		$rules = $container->get( 'cache.rules' );

		// Cheap pre-check. The authoritative one runs at flush time, when the
		// full query context and the real status code are known.
		if ( null !== Key::bypass_reason( $_SERVER, $_COOKIE, $container->get( 'cache.config' ) ) ) { // phpcs:ignore
			return;
		}

		$this->started = microtime( true );

		ob_start(
			function ( string $html ) use ( $container, $rules ): string {
				return $this->capture( $html, $container, $rules );
			}
		);
	}

	private function capture( string $html, Container $container, Rules $rules ): string {
		if ( strlen( $html ) < 255 || ! str_contains( $html, '</html>' ) ) {
			return $html;
		}

		$reason = $rules->bypass_reason();

		if ( null !== $reason ) {
			return $this->maybe_sign( $html, 'BYPASS ' . $reason, $container );
		}

		/** @var Store $store */
		$store   = $container->get( 'cache.store' );
		$config  = (array) $container->get( 'cache.config' );
		$variant = Key::variant( $_SERVER, $_COOKIE, $config ); // phpcs:ignore
		$url     = $this->current_url();

		/**
		 * Last chance to rewrite markup before it is frozen into the cache.
		 * The Divi integration hooks this to decouple form nonces from TTL.
		 *
		 * @param string $html
		 */
		$html = (string) apply_filters( 'rc-rocket/cache/html', $html );

		$signed = $this->maybe_sign( $html, 'MISS', $container );

		$store->put(
			$url,
			$signed,
			$rules->surrogate_keys(),
			(int) ( $config['ttl'] ?? 36000 ),
			$variant
		);

		if ( ! headers_sent() && ! empty( $config['debug_headers'] ) ) {
			header( 'X-RC-Rocket-Cache: MISS' );
		}

		return $signed;
	}

	/**
	 * The URL being cached.
	 *
	 * home_url( add_query_arg( [] ) ) looks right and is wrong: REQUEST_URI
	 * already contains the subdirectory, so home_url() doubles it on any install
	 * that is not at the domain root. Build it from the request instead, which is
	 * also what the drop-in does — and the two must agree exactly.
	 */
	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
		$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

		return $scheme . $host . $uri;
	}

	private function maybe_sign( string $html, string $state, Container $container ): string {
		$config = (array) $container->get( 'cache.config' );

		if ( empty( $config['footer_signature'] ) ) {
			return $html;
		}

		$ms = null === $this->started ? 0.0 : ( microtime( true ) - $this->started ) * 1000;

		$comment = sprintf(
			"\n<!-- RC Rocket %s | rendered in %.1fms | %s -->",
			$state,
			$ms,
			gmdate( 'Y-m-d H:i:s' )
		);

		return $html . $comment;
	}

	public function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => 'rcrocket',
				'title' => 'RC Rocket',
				'href'  => admin_url( 'admin.php?page=rcrocket' ),
			]
		);

		$bar->add_node(
			[
				'id'     => 'rcrocket-purge-all',
				'parent' => 'rcrocket',
				'title'  => __( 'Clear all cached pages', 'rc-rocket' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=rcrocket_purge_all' ), 'rcrocket_purge_all' ),
			]
		);

		if ( ! is_admin() ) {
			$bar->add_node(
				[
					'id'     => 'rcrocket-purge-url',
					'parent' => 'rcrocket',
					'title'  => __( 'Clear this page', 'rc-rocket' ),
					'href'   => wp_nonce_url(
						add_query_arg(
							[
								'action' => 'rcrocket_purge_url',
								'url'    => rawurlencode( home_url( add_query_arg( [] ) ) ),
							],
							admin_url( 'admin-post.php' )
						),
						'rcrocket_purge_url'
					),
				]
			);
		}
	}
}
