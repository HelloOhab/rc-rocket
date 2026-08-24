<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache warming.
 *
 * Preloading is throttled on purpose. Firing 5,000 concurrent requests at your
 * own origin to "warm the cache" is a self-inflicted denial of service, which
 * is exactly how several popular plugins behave on shared hosting.
 */
final class Preloader {

	public const CRON_HOOK  = 'rc-rocket/preload/batch';
	public const QUEUE_OPT  = 'rcrocket_preload_queue';
	public const STATE_OPT  = 'rcrocket_preload_state';

	public function __construct(
		private Store $store,
		private Logger $logger,
		private array $config
	) {}

	public function hooks(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_batch' ] );
		add_action( 'rc-rocket/cache/purged', [ $this, 'maybe_rewarm' ], 10, 2 );
	}

	/** Seed the queue from the WordPress core sitemap. */
	public function start(): int {
		$urls = $this->discover_urls();

		update_option( self::QUEUE_OPT, $urls, false );
		update_option(
			self::STATE_OPT,
			[
				'total'     => count( $urls ),
				'done'      => 0,
				'started'   => time(),
				'finished'  => null,
				'running'   => true,
			],
			false
		);

		$this->schedule_next( 5 );

		return count( $urls );
	}

	public function stop(): void {
		delete_option( self::QUEUE_OPT );
		$state            = $this->state();
		$state['running'] = false;
		update_option( self::STATE_OPT, $state, false );

		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function state(): array {
		$state = get_option( self::STATE_OPT, [] );

		return is_array( $state ) ? $state : [];
	}

	public function run_batch(): void {
		$queue = (array) get_option( self::QUEUE_OPT, [] );

		if ( ! $queue ) {
			$state             = $this->state();
			$state['running']  = false;
			$state['finished'] = time();
			update_option( self::STATE_OPT, $state, false );

			return;
		}

		$size  = max( 1, (int) ( $this->config['preload_batch_size'] ?? 8 ) );
		$batch = array_splice( $queue, 0, $size );

		update_option( self::QUEUE_OPT, $queue, false );

		foreach ( $batch as $url ) {
			$this->fetch( (string) $url );
			usleep( 150000 ); // 150ms between hits: polite to shared hosting.
		}

		$state          = $this->state();
		$state['done']  = (int) ( $state['done'] ?? 0 ) + count( $batch );
		update_option( self::STATE_OPT, $state, false );

		$this->schedule_next( 30 );
	}

	/** Re-warm only what was just invalidated, not the whole site. */
	public function maybe_rewarm( string $scope, int $entries ): void {
		if ( empty( $this->config['preload_on_purge'] ) || 'keys' !== $scope || $entries < 1 ) {
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule_next( 20 );
		}
	}

	private function fetch( string $url ): void {
		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 10,
				'blocking'    => true,
				'sslverify'   => false,
				'user-agent'  => 'RC Rocket/Preloader',
				'headers'     => [ 'X-RC-Rocket-Preload' => '1' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->debug( 'Preload failed', [ 'url' => $url, 'error' => $response->get_error_message() ] );
		}
	}

	private function schedule_next( int $delay ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/** @return string[] */
	private function discover_urls(): array {
		$urls = [ home_url( '/' ) ];

		$posts = get_posts(
			[
				// Attachment pages are public but almost never worth a cache slot.
				'post_type'        => array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) ),
				'post_status'      => 'publish',
				'numberposts'      => (int) ( $this->config['preload_max_urls'] ?? 500 ),
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);

		foreach ( $posts as $post_id ) {
			$permalink = get_permalink( (int) $post_id );

			if ( is_string( $permalink ) ) {
				$urls[] = $permalink;
			}
		}

		/** @param string[] $urls */
		return array_values( array_unique( (array) apply_filters( 'rc-rocket/preload/urls', $urls ) ) );
	}
}
