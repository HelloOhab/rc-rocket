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
 * Works against whichever cache is in front of the site. On a self-managed
 * host it renders pages into our own cache; on Kinsta and the other managed
 * hosts it requests them as an ordinary visitor would, which is what fills the
 * host's page cache. Either way the first real visitor after a purge gets a
 * cached page instead of a cold render.
 *
 * Preloading is throttled on purpose. Firing 5,000 concurrent requests at your
 * own origin to "warm the cache" is a self-inflicted denial of service, which
 * is exactly how several popular plugins behave on shared hosting.
 */
final class Preloader {

	public const CRON_HOOK = 'rc-rocket/preload/batch';
	public const QUEUE_OPT = 'rcrocket_preload_queue';
	public const STATE_OPT = 'rcrocket_preload_state';

	/** Hard ceiling on the queue, whatever feeds it. */
	private const QUEUE_MAX = 2000;

	public function __construct(
		private Logger $logger,
		private array $config,
		private bool $host_cache = false
	) {}

	public function hooks(): void {
		add_action( self::CRON_HOOK, [ $this, 'run_batch' ] );

		// Our own cache: re-warm exactly what a purge removed.
		add_action( 'rc-rocket/cache/purged', [ $this, 'on_own_purge' ], 10, 3 );

		// A host cache: we are told when it was flushed, and when content
		// changed that the host purges by itself.
		add_action( 'rc-rocket/host/purged', [ $this, 'on_host_purge' ], 10, 1 );

		if ( $this->host_cache && ! empty( $this->config['warm_after_publish'] ) ) {
			add_action( 'transition_post_status', [ $this, 'on_publish' ], 20, 3 );
		}
	}

	/** Seed the queue from every public URL worth caching. */
	public function start(): int {
		$urls = $this->discover_urls();

		update_option( self::QUEUE_OPT, $urls, false );
		update_option(
			self::STATE_OPT,
			[
				'total'    => count( $urls ),
				'done'     => 0,
				'started'  => time(),
				'finished' => null,
				'running'  => true,
				'target'   => $this->host_cache ? 'host' : 'own',
			],
			false
		);

		$this->schedule_next( 5 );

		return count( $urls );
	}

	/**
	 * Add URLs to the front of the queue without discarding a full warm that
	 * is already running.
	 *
	 * @param string[] $urls
	 */
	public function enqueue( array $urls, int $delay = 20 ): int {
		$urls = array_values( array_filter( array_map( 'strval', $urls ), [ $this, 'is_warmable' ] ) );

		if ( ! $urls ) {
			return 0;
		}

		$queue = array_values( array_unique( array_merge( $urls, (array) get_option( self::QUEUE_OPT, [] ) ) ) );
		$queue = array_slice( $queue, 0, self::QUEUE_MAX );

		update_option( self::QUEUE_OPT, $queue, false );

		$state             = $this->state();
		$state['running']  = true;
		$state['total']    = (int) ( $state['total'] ?? 0 ) + count( $urls );
		$state['done']     = (int) ( $state['done'] ?? 0 );
		$state['finished'] = null;
		$state['started']  = $state['started'] ?? time();
		update_option( self::STATE_OPT, $state, false );

		$this->schedule_next( $delay );

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

		$size = max( 1, min( 50, (int) ( $this->config['preload_batch_size'] ?? 8 ) ) );

		// On Kinsta every warming request is a full render on one of a
		// handful of PHP workers, while this batch holds another. Smaller
		// batches and a real pause leave the workers to visitors.
		if ( $this->host_cache ) {
			$size = min( $size, 4 );
		}

		$batch = array_splice( $queue, 0, $size );

		update_option( self::QUEUE_OPT, $queue, false );

		foreach ( $batch as $url ) {
			$this->fetch( (string) $url );
			usleep( $this->host_cache ? 750000 : 150000 );
		}

		$state         = $this->state();
		$state['done'] = (int) ( $state['done'] ?? 0 ) + count( $batch );
		update_option( self::STATE_OPT, $state, false );

		$this->schedule_next( 30 );
	}

	/** @param string[] $urls */
	public function on_own_purge( string $scope, int $entries, array $urls = [] ): void {
		if ( empty( $this->config['preload_on_purge'] ) ) {
			return;
		}

		if ( 'all' === $scope ) {
			$this->start();

			return;
		}

		$this->enqueue( $urls );
	}

	public function on_host_purge( string $reason = '' ): void {
		if ( ! empty( $this->config['preload_on_purge'] ) ) {
			// Give the host a moment to finish its own purge first.
			$this->start();
			$this->reschedule( 60 );
		}
	}

	/**
	 * The host purges a post's URLs by itself when it is published or
	 * updated. Follow behind it and refill exactly those.
	 */
	public function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! $type || ! $type->public ) {
			return;
		}

		$urls = [ home_url( '/' ), (string) get_permalink( $post ) ];

		// For posts the "archive" is the home page or the posts page, both
		// handled here already.
		$archive = 'post' === $post->post_type ? false : get_post_type_archive_link( $post->post_type );

		if ( is_string( $archive ) ) {
			$urls[] = $archive;
		}

		if ( 'post' === $post->post_type && 'page' === get_option( 'show_on_front' ) && get_option( 'page_for_posts' ) ) {
			$urls[] = (string) get_permalink( (int) get_option( 'page_for_posts' ) );
		}

		foreach ( (array) get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			foreach ( (array) get_the_terms( $post, $taxonomy->name ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					$link = get_term_link( $term );

					if ( is_string( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		// The host purges asynchronously; warming before it has finished
		// would just re-cache the old page.
		$this->enqueue( $urls, 90 );
	}

	private function fetch( string $url ): void {
		$headers = [ 'Accept' => 'text/html' ];

		// Our own cache needs the secret to get a fresh render. A host cache
		// needs the opposite: a plain anonymous request it will store.
		if ( ! $this->host_cache ) {
			$headers['X-RC-Rocket-Preload'] = Dropin::preload_secret();
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 10,
				'blocking'   => true,
				'sslverify'  => (bool) apply_filters( 'rc-rocket/preload/sslverify', ! $this->is_local( $url ) ),
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 RC-Rocket-Preloader',
				'headers'    => $headers,
				'cookies'    => [],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->logger->debug( 'Preload failed', [ 'url' => $url, 'error' => $response->get_error_message() ] );
		}
	}

	private function is_local( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.test' );
	}

	/** Only our own front-end URLs, never admin, feeds or query strings. */
	private function is_warmable( string $url ): bool {
		if ( '' === $url || ! str_starts_with( $url, home_url() ) ) {
			return false;
		}

		return ! preg_match( '#/(wp-admin|wp-login\.php|wp-json|feed)(/|$)|\?#', $url );
	}

	private function schedule_next( int $delay ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	private function reschedule( int $delay ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
	}

	/** @return string[] */
	private function discover_urls(): array {
		$urls = [ home_url( '/' ) ];

		$posts = get_posts(
			[
				// Attachment pages are public but almost never worth a cache slot.
				'post_type'        => array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) ),
				'post_status'      => 'publish',
				'numberposts'      => max( 1, (int) ( $this->config['preload_max_urls'] ?? 500 ) ),
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'has_password'     => false,
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
		$urls = (array) apply_filters( 'rc-rocket/preload/urls', $urls );

		return array_slice( array_values( array_unique( array_filter( array_map( 'strval', $urls ), [ $this, 'is_warmable' ] ) ) ), 0, self::QUEUE_MAX );
	}
}
