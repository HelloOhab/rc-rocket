<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invalidation. Editing one post should clear that post, its archives and the
 * home page — not 40,000 cached URLs. Everything here resolves to surrogate
 * keys and defers the actual unlinking to shutdown so an editor saving a post
 * never waits on the filesystem.
 */
final class Purge {

	/** @var string[] */
	private array $queued_keys = [];

	private bool $queued_all = false;

	public function __construct(
		private Store $store,
		private Logger $logger,
		private array $config
	) {}

	public function hooks(): void {
		foreach ( [ 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ $this, 'on_post_change' ], 10, 1 );
		}

		add_action( 'comment_post', [ $this, 'on_comment' ], 10, 3 );
		add_action( 'edit_comment', [ $this, 'on_comment' ], 10, 1 );
		add_action( 'wp_set_comment_status', [ $this, 'on_comment' ], 10, 1 );
		add_action( 'edited_term', [ $this, 'on_term_change' ], 10, 1 );
		add_action( 'delete_term', [ $this, 'on_term_change' ], 10, 1 );

		foreach ( [ 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_permalink_structure', 'activated_plugin', 'deactivated_plugin' ] as $hook ) {
			add_action( $hook, [ $this, 'all' ] );
		}

		add_action( 'woocommerce_product_set_stock', [ $this, 'on_product' ], 10, 1 );
		add_action( 'woocommerce_variation_set_stock', [ $this, 'on_product' ], 10, 1 );

		// Public API: do_action( 'rc-rocket/purge/key', 'post-42' ).
		add_action( 'rc-rocket/purge/key', [ $this, 'key' ], 10, 1 );
		add_action( 'rc-rocket/purge/url', [ $this, 'url' ], 10, 1 );
		add_action( 'rc-rocket/purge/all', [ $this, 'all' ] );

		add_action( 'shutdown', [ $this, 'run_queue' ], PHP_INT_MAX );
	}

	public function on_post_change( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}

		$post_type = get_post_type_object( $post->post_type );

		if ( $post_type && ! $post_type->public ) {
			return;
		}

		$this->queue( 'post-' . $post_id );
		$this->queue( 'author-' . (int) $post->post_author );
		$this->queue( 'type-' . $post->post_type );
		$this->queue( 'home' );
		$this->queue( 'archive' );
		$this->queue( 'feed' );

		foreach ( (array) get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			foreach ( (array) get_the_terms( $post_id, (string) $taxonomy ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					$this->queue( 'term-' . $term->term_id );
				}
			}
		}
	}

	public function on_comment( int|string $comment_id, mixed $approved = null, mixed $data = null ): void {
		$comment = get_comment( (int) $comment_id );

		if ( $comment instanceof \WP_Comment ) {
			$this->queue( 'post-' . (int) $comment->comment_post_ID );
		}
	}

	public function on_term_change( int $term_id ): void {
		$this->queue( 'term-' . $term_id );
		$this->queue( 'archive' );
	}

	public function on_product( mixed $product ): void {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;

		if ( $id > 0 ) {
			$this->queue( 'post-' . $id );
			$this->queue( 'type-product' );
		}
	}

	public function key( string $key ): void {
		$this->queue( $key );
	}

	public function url( string $url ): int {
		$host    = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$uri     = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );
		$deleted = 0;

		// A URL may exist in several variants; clear every bucket we generate.
		foreach ( $this->variants() as $variant ) {
			$hash = Key::hash( $host, $uri, $variant, $this->config );

			if ( null !== $hash && $this->store->delete( $hash ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	public function all(): void {
		$this->queued_all = true;
	}

	public function run_queue(): int {
		if ( $this->queued_all ) {
			$deleted          = $this->store->flush();
			$this->queued_all = false;

			$this->logger->debug( 'Full cache flush', [ 'entries' => $deleted ] );
			do_action( 'rc-rocket/cache/purged', 'all', $deleted );

			return $deleted;
		}

		if ( ! $this->queued_keys ) {
			return 0;
		}

		$deleted = 0;

		foreach ( array_unique( $this->queued_keys ) as $key ) {
			$deleted += $this->store->delete_by_key( $key );
		}

		$this->logger->debug( 'Key purge', [ 'keys' => array_values( array_unique( $this->queued_keys ) ), 'entries' => $deleted ] );

		$this->queued_keys = [];

		do_action( 'rc-rocket/cache/purged', 'keys', $deleted );

		return $deleted;
	}

	private function queue( string $key ): void {
		$this->queued_keys[] = $key;
	}

	/**
	 * Every variant string this install can produce, so a URL purge is total.
	 *
	 * @return string[]
	 */
	private function variants(): array {
		$schemes = [ is_ssl() ? 'https' : 'http' ];
		$devices = empty( $this->config['separate_mobile'] ) ? [ '' ] : [ 'desktop', 'mobile' ];
		$out     = [];

		foreach ( $schemes as $scheme ) {
			foreach ( $devices as $device ) {
				$parts = array_filter( [ $scheme, $device ] );

				if ( ! empty( $this->config['cache_logged_in'] ) ) {
					$parts[] = 'anon';
				}

				$out[] = implode( '|', $parts );
			}
		}

		return $out;
	}
}
