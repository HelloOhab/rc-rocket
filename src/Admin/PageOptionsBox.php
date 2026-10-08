<?php
declare( strict_types=1 );

namespace RCRocket\Admin;

use RCRocket\Container;
use RCRocket\Support\PageOptions;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "RC Rocket" box in the editor sidebar of every public post type.
 *
 * Works in the block editor (as a sidebar meta box), the classic editor and
 * alongside the Divi Builder. Only administrators see it: switching
 * optimizations off is a site-wide performance decision, not an editorial
 * one.
 */
final class PageOptionsBox {

	private const NONCE = 'rcrocket_page_options';

	public function __construct( private Container $container ) {}

	public function hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$types = array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] );

		/** @param string[] $types Post types that get the box. */
		$types = (array) apply_filters( 'rc-rocket/page_options/post_types', $types );

		foreach ( $types as $type ) {
			add_meta_box( 'rcrocket-page-options', __( 'RC Rocket', 'rc-rocket' ), [ $this, 'render' ], $type, 'side', 'default' );
		}
	}

	public function render( \WP_Post $post ): void {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );
		$options  = PageOptions::for_post( (int) $post->ID );
		$managed  = $this->container->get( 'hosting' )->manages_page_cache();

		wp_nonce_field( self::NONCE, '_rcrocket_page_nonce' );
		echo '<input type="hidden" name="rcrocket_page_options_present" value="1">';

		printf(
			'<p><label><input type="checkbox" name="rcrocket_never_cache" value="1"%s> %s</label></p>',
			checked( $options['never_cache'], true, false ),
			esc_html__( 'Never cache this page', 'rc-rocket' )
		);

		if ( $managed ) {
			printf(
				'<p class="description" style="margin-top:-6px">%s</p>',
				esc_html__( 'The page tells your host\'s cache not to store it. If it is still served from cache, add the URL to the host\'s cache exclusions.', 'rc-rocket' )
			);
		}

		printf( '<p style="margin-bottom:6px"><strong>%s</strong></p>', esc_html__( 'Use these optimizations on this page:', 'rc-rocket' ) );

		foreach ( PageOptions::features() as $key => $feature ) {
			$available = $settings->enabled( $feature['setting'] );
			$active    = $available && ! in_array( $key, $options['off'], true );

			printf(
				'<label style="display:block;margin:0 0 4px%s"%s><input type="checkbox" name="rcrocket_on[]" value="%s"%s%s> %s</label>',
				$available ? '' : ';opacity:.55',
				$available ? '' : ' title="' . esc_attr__( 'Switched off in the RC Rocket settings for the whole site.', 'rc-rocket' ) . '"',
				esc_attr( $key ),
				checked( $active, true, false ),
				disabled( ! $available, true, false ),
				esc_html( $feature['label'] )
			);

			// A greyed-out box is not submitted; remember the page's choice
			// so switching the feature back on site-wide does not reset it.
			if ( ! $available && in_array( $key, $options['off'], true ) ) {
				printf( '<input type="hidden" name="rcrocket_keep_off[]" value="%s">', esc_attr( $key ) );
			}
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Untick anything that misbehaves on this page only. Greyed-out options are switched off for the whole site in RC Rocket.', 'rc-rocket' )
		);
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['rcrocket_page_options_present'], $_POST['_rcrocket_page_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_rcrocket_page_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		$on   = array_map( 'sanitize_key', (array) wp_unslash( $_POST['rcrocket_on'] ?? [] ) );
		$keep = array_map( 'sanitize_key', (array) wp_unslash( $_POST['rcrocket_keep_off'] ?? [] ) );
		$off  = [];

		foreach ( PageOptions::features() as $key => $feature ) {
			// Unavailable features cannot be ticked; only an explicit
			// earlier choice is kept for them.
			if ( ! $settings->enabled( $feature['setting'] ) ) {
				if ( in_array( $key, $keep, true ) ) {
					$off[] = $key;
				}
				continue;
			}

			if ( ! in_array( $key, $on, true ) ) {
				$off[] = $key;
			}
		}

		PageOptions::save( $post_id, ! empty( $_POST['rcrocket_never_cache'] ), $off );
	}
}
