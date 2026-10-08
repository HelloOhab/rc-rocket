<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviSettings;
use RCRocket\Support\Hosting;
use RCRocket\Support\Paths;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module A — the cache engine.
 *
 * Two modes. On a self-managed host it is a full page cache. On a host that
 * runs its own (Kinsta, WP Engine and the rest) it never caches a byte and
 * becomes the bridge instead: it tells the host when a change it cannot see
 * — a Divi theme option, a Theme Builder template, an RC Rocket setting — has
 * made every cached page wrong, and then warms the host cache back up.
 */
final class CacheModule implements Module {

	public const CLEANUP_HOOK = 'rc-rocket/cache/cleanup';

	/** Response headers worth replaying on a cache hit. */
	private const REPLAY_HEADERS = [
		'content-security-policy',
		'content-security-policy-report-only',
		'x-frame-options',
		'x-content-type-options',
		'referrer-policy',
		'permissions-policy',
		'strict-transport-security',
		'cross-origin-opener-policy',
		'cross-origin-embedder-policy',
		'cross-origin-resource-policy',
		'link',
	];

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
				'warm_after_publish' => true,
				'footer_signature'   => true,
				// Analytics cookies set from PHP make every page uncacheable.
				'strip_tracking_cookies' => true,
				'tracking_cookies'       => [ '_fbp', '_fbc' ],
				// Divi-specific.
				'clear_divi_cache'      => true,
				'refresh_form_nonces'   => true,
				'bypass_divi_builder'   => true,
				'fix_viewport'          => true,
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
			static fn(): string => Paths::cache_dir()
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
			static fn( Container $c ): Preloader => new Preloader(
				$c->get( 'logger' ),
				$c->get( 'cache.config' ),
				$c->get( 'hosting' )->manages_page_cache()
			)
		);

		$container->set(
			'cache.host_bridge',
			static fn( Container $c ): HostBridge => new HostBridge( $c->get( 'hosting' ), $c->get( 'divi' ), $c->get( 'logger' ) )
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

		/** @var Divi $divi */
		$divi = $container->get( 'divi' );

		$divi->when_active( [ $divi, 'hooks' ] );

		// Warming runs in both modes: on a managed host it is the host's
		// cache that gets warmed.
		$container->get( 'cache.preloader' )->hooks();

		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 100 );

		// Whoever caches the page, a tracking cookie sent from PHP stops it.
		$cache_config = (array) $container->get( 'cache.config' );

		if ( ! empty( $cache_config['strip_tracking_cookies'] ) ) {
			$names = array_values( array_filter( array_map( 'strval', (array) ( $cache_config['tracking_cookies'] ?? [] ) ) ) );

			if ( $names ) {
				( new TrackingCookies( $names ) )->hooks();
			}
		}

		// On a host with server-level page caching, ours must not run at all.
		if ( $hosting->manages_page_cache() ) {
			$container->get( 'cache.host_bridge' )->hooks();

			return;
		}

		$container->get( 'cache.purge' )->hooks();

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
	}

	private function start_buffer( Container $container ): void {
		// Cheap pre-check. The authoritative one runs at flush time, when the
		// full query context and the real status code are known.
		if ( null !== Key::bypass_reason( $_SERVER, $_COOKIE, $container->get( 'cache.config' ) ) ) { // phpcs:ignore
			return;
		}

		$rules         = $container->get( 'cache.rules' );
		$this->started = microtime( true );

		ob_start(
			function ( string $html, int $phase = PHP_OUTPUT_HANDLER_FINAL ) use ( $container, $rules ): string {
				// Something flushed the buffer part way through the page: this
				// call sees a fragment, and caching the last fragment would
				// store the end of the page as the whole page.
				$whole = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL );

				return $whole ? $this->capture( $html, $container, $rules ) : $html;
			}
		);
	}

	private function capture( string $html, Container $container, Rules $rules ): string {
		if ( strlen( $html ) < 255 || ! str_contains( $html, '</html>' ) || ! preg_match( '/<html[\s>]/i', $html ) ) {
			return $html;
		}

		// A 200 that is not a page (a feed, a download, JSON) is never replayed
		// as text/html.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $html;
			}
		}

		$reason = $rules->bypass_reason();

		if ( null !== $reason ) {
			return $this->maybe_sign( $html, 'BYPASS ' . $reason, $container );
		}

		/** @var Store $store */
		$store   = $container->get( 'cache.store' );
		$config  = (array) $container->get( 'cache.config' );
		$variant = Key::variant( $_SERVER, $_COOKIE, $config ); // phpcs:ignore

		/**
		 * Last chance to rewrite markup before it is frozen into the cache.
		 *
		 * @param string $html
		 */
		$html = (string) apply_filters( 'rc-rocket/cache/html', $html );

		$signed = $this->maybe_sign( $html, 'MISS', $container );

		$store->put(
			$this->current_url(),
			$signed,
			$rules->surrogate_keys(),
			(int) ( $config['ttl'] ?? 36000 ),
			$variant,
			$this->replayable_headers()
		);

		if ( ! headers_sent() && ! empty( $config['debug_headers'] ) ) {
			header( 'X-RC-Rocket-Cache: MISS' );
		}

		return $signed;
	}

	/** @return string[] */
	private function replayable_headers(): array {
		$out = [];

		foreach ( headers_list() as $line ) {
			$name = strtolower( trim( strstr( $line, ':', true ) ?: '' ) );

			if ( in_array( $name, self::REPLAY_HEADERS, true ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * The URL being cached, built from the request exactly as the drop-in sees
	 * it — host including any port, path including any subdirectory.
	 */
	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = (string) preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
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
		$all  = \RCRocket\Plugin::can_purge_all();
		$page = ! is_admin() && \RCRocket\Plugin::can_purge_page();

		if ( ! $all && ! $page ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => 'rcrocket',
				'title' => 'RC Rocket',
				'href'  => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . \RCRocket\Admin\AdminMenu::SLUG ) : false,
			]
		);

		if ( $all ) {
			$bar->add_node(
				[
					'id'     => 'rcrocket-purge-all',
					'parent' => 'rcrocket',
					'title'  => __( 'Clear all cached pages', 'rc-rocket' ),
					'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=rcrocket_purge_all' ), 'rcrocket_purge_all' ),
				]
			);
		}

		if ( $page ) {
			$bar->add_node(
				[
					'id'     => 'rcrocket-purge-url',
					'parent' => 'rcrocket',
					'title'  => __( 'Clear this page', 'rc-rocket' ),
					'href'   => wp_nonce_url(
						add_query_arg(
							[
								'action' => 'rcrocket_purge_url',
								'url'    => rawurlencode( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ), // phpcs:ignore
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
