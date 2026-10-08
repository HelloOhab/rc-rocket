<?php
declare( strict_types=1 );

namespace RCRocket\Integrations;

use RCRocket\Support\Context;
use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi 4 builder asset unloading.
 *
 * Divi 4 enqueues the supporting library for every module type on every page:
 * the lightbox, the masonry grid script, the circle-counter renderer, the
 * viewport helper. A page with a single text module downloads all of it.
 *
 * This detects which modules a page actually renders — including the ones
 * inherited from its Theme Builder header, body and footer templates, which is
 * the part naive implementations get wrong and then break global headers — and
 * unloads the libraries nothing on the page can use.
 *
 * On Divi 5 this does nothing, deliberately. Divi 5 already loads only what a
 * page uses; that is where its 94% CSS reduction comes from. Running a second
 * layer of guesswork on top of it would add risk and subtract nothing.
 */
final class DiviOptimizer {

	private const META_KEY = '_rcr_divi_modules';

	/**
	 * Library handle to the modules that need it.
	 *
	 * Conservative on purpose: a handle is only listed when its dependency is
	 * well understood. An unlisted handle is never touched.
	 *
	 * @var array<string, array{kind:string, modules:string[], label:string}>
	 */
	private const LIBRARIES = [
		'magnific-popup' => [
			'kind'    => 'both',
			'label'   => 'Lightbox',
			'modules' => [ 'et_pb_gallery', 'et_pb_image', 'et_pb_portfolio', 'et_pb_filterable_portfolio', 'et_pb_fullwidth_image', 'et_pb_video', 'et_pb_blog', 'et_pb_shop' ],
		],
		'salvattore'     => [
			'kind'    => 'script',
			'label'   => 'Masonry grid',
			'modules' => [ 'et_pb_blog', 'et_pb_portfolio', 'et_pb_filterable_portfolio', 'et_pb_fullwidth_post_slider' ],
		],
		'easypiechart'   => [
			'kind'    => 'script',
			'label'   => 'Circle counter',
			'modules' => [ 'et_pb_circle_counter', 'et_pb_number_counter' ],
		],
		'et-jquery-visible-viewport' => [
			'kind'    => 'script',
			'label'   => 'Viewport detection',
			'modules' => [ 'et_pb_circle_counter', 'et_pb_number_counter', 'et_pb_counters', 'et_pb_countdown_timer' ],
		],
		'hashchange'     => [
			'kind'    => 'script',
			'label'   => 'Tab and accordion anchors',
			'modules' => [ 'et_pb_tabs', 'et_pb_accordion', 'et_pb_toggle' ],
		],
		'jquery-fitvids' => [
			'kind'    => 'script',
			'label'   => 'Responsive video wrapper',
			'modules' => [ 'et_pb_video', 'et_pb_video_slider', 'et_pb_code', 'et_pb_fullwidth_code' ],
		],
		'wp-mediaelement' => [
			'kind'    => 'both',
			'label'   => 'Media player',
			'modules' => [ 'et_pb_video', 'et_pb_video_slider', 'et_pb_audio' ],
		],
	];

	public function __construct(
		private Divi $divi,
		private Context $context,
		private Logger $logger,
		private array $config,
		private ?DiviSettings $divi_settings = null
	) {}

	/**
	 * Only applicable when Divi is not already doing this itself.
	 *
	 * Divi 4.10's Dynamic JavaScript Libraries loads exactly the libraries a
	 * page's modules need, using the builder's own knowledge of what it
	 * rendered. That is strictly better information than parsing shortcodes
	 * after the fact, so when it is on, this stands down.
	 */
	public function applicable(): bool {
		if ( ! $this->divi->is_active() || $this->divi->is_divi_five() ) {
			return false;
		}

		return ! ( $this->divi_settings instanceof DiviSettings && $this->divi_settings->is_on( 'dynamic_js' ) );
	}

	public function hooks(): void {
		if ( empty( $this->config['unload_modules'] ) ) {
			return;
		}

		// Divi is a theme and loads after plugins: decide once it has.
		if ( ! $this->divi->is_active() && ! did_action( 'after_setup_theme' ) ) {
			add_action( 'after_setup_theme', [ $this, 'hooks' ], 0 );

			return;
		}

		if ( ! $this->applicable() ) {
			return;
		}

		// Late enough that Divi has enqueued everything it intends to.
		add_action( 'wp_enqueue_scripts', [ $this, 'unload' ], PHP_INT_MAX - 5 );

		// The cached module list is only valid until the content changes.
		add_action( 'save_post', [ $this, 'forget' ], 10, 1 );
		add_action( 'et_save_post', [ $this, 'forget' ], 10, 1 );
	}

	public function unload(): void {
		if ( ! is_singular() && ! is_home() && ! is_archive() ) {
			return;
		}

		if ( \RCRocket\Support\PageOptions::off( 'divi_unload' ) ) {
			return;
		}

		$modules = $this->modules_in_play();

		// An empty result means detection failed, not that the page is empty.
		// Unloading on a failed read is how a global header loses its lightbox.
		if ( ! $modules ) {
			return;
		}

		$unloaded = [];

		foreach ( self::LIBRARIES as $handle => $library ) {
			if ( array_intersect( $library['modules'], $modules ) ) {
				continue;
			}

			if ( in_array( $library['kind'], [ 'script', 'both' ], true ) ) {
				wp_dequeue_script( $handle );
			}

			if ( in_array( $library['kind'], [ 'style', 'both' ], true ) ) {
				wp_dequeue_style( $handle );
			}

			$unloaded[] = $handle;
		}

		if ( $unloaded ) {
			$this->logger->debug(
				'Divi libraries unloaded',
				[
					'template' => $this->context->signature(),
					'handles'  => $unloaded,
				]
			);
		}
	}

	/**
	 * Every Divi module rendered by this request.
	 *
	 * The post's own content is only part of the answer. A Theme Builder header
	 * can contain a gallery, a footer can contain a contact form, and unloading
	 * their libraries because the post body has neither is exactly the bug that
	 * makes people distrust this category of feature.
	 *
	 * @return string[]
	 */
	public function modules_in_play(): array {
		$modules = [];

		if ( is_singular() ) {
			$modules = array_merge( $modules, $this->modules_for_post( get_queried_object_id() ) );
		}

		foreach ( $this->theme_builder_layout_ids() as $layout_id ) {
			$modules = array_merge( $modules, $this->modules_for_post( $layout_id ) );
		}

		/**
		 * Add module slugs a third-party integration renders dynamically.
		 *
		 * @param string[] $modules
		 */
		return array_values( array_unique( (array) apply_filters( 'rc-rocket/divi/modules_in_play', $modules ) ) );
	}

	/** @return int[] */
	private function theme_builder_layout_ids(): array {
		if ( ! function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			return [];
		}

		$ids = [];

		foreach ( (array) et_theme_builder_get_template_layouts() as $layout ) {
			if ( is_array( $layout ) && ! empty( $layout['id'] ) && ! empty( $layout['enabled'] ) ) {
				$ids[] = (int) $layout['id'];
			}
		}

		return $ids;
	}

	/**
	 * Parse a post's shortcodes once and remember the answer. Regex over
	 * post_content on every page view would cost more than the scripts saved.
	 *
	 * @return string[]
	 */
	private function modules_for_post( int $post_id ): array {
		if ( $post_id < 1 ) {
			return [];
		}

		$cached = get_post_meta( $post_id, self::META_KEY, true );

		if ( is_array( $cached ) && isset( $cached['modules'], $cached['hash'] ) ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && $cached['hash'] === md5( $post->post_content ) ) {
				return (array) $cached['modules'];
			}
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		$found = [];

		if ( preg_match_all( '/\[(et_pb_[a-z0-9_]+)/i', $post->post_content, $matches ) ) {
			$found = array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
		}

		// A layout that embeds a library layout inherits its modules too.
		if ( preg_match_all( '/\[et_pb_section[^\]]*global_module="(\d+)"/i', $post->post_content, $globals ) ) {
			foreach ( $globals[1] as $global_id ) {
				$found = array_merge( $found, $this->modules_for_post( (int) $global_id ) );
			}
		}

		update_post_meta(
			$post_id,
			self::META_KEY,
			[
				'hash'    => md5( $post->post_content ),
				'modules' => $found,
			]
		);

		return $found;
	}

	public function forget( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}

	private function reason(): string {
		if ( ! $this->divi->is_active() ) {
			return __( 'Divi was not detected.', 'rc-rocket' );
		}

		if ( $this->divi->is_divi_five() ) {
			return __( 'Divi 5 already loads only the assets a page uses. Nothing here to improve, so this is switched off.', 'rc-rocket' );
		}

		if ( $this->divi_settings instanceof DiviSettings && $this->divi_settings->is_on( 'dynamic_js' ) ) {
			return __( 'Divi\'s own Dynamic JavaScript Libraries setting is on, and it knows better than we do which modules rendered. Switched off to avoid doing the same job twice.', 'rc-rocket' );
		}

		return '';
	}

	/** What the admin screen shows so the feature is not a black box. */
	public function report(): array {
		return [
			'applicable' => $this->applicable(),
			'reason'     => $this->reason(),
			'libraries'  => array_map(
				static fn( array $l ): array => [
					'label'   => $l['label'],
					'modules' => count( $l['modules'] ),
				],
				self::LIBRARIES
			),
		];
	}
}
