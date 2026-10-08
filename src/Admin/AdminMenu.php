<?php
declare( strict_types=1 );

namespace RCRocket\Admin;

use RCRocket\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {

	public const SLUG = 'rc-rocket';

	public function __construct( private Container $container ) {}

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_filter( 'plugin_action_links_' . RCROCKET_BASENAME, [ $this, 'action_links' ] );
	}

	public function register_page(): void {
		$hook = add_menu_page(
			__( 'RC Rocket', 'rc-rocket' ),
			__( 'RC Rocket', 'rc-rocket' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-performance',
			66
		);

		add_action( 'load-' . $hook, [ $this, 'enqueue_on_load' ] );
	}

	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				esc_html__( 'Settings', 'rc-rocket' )
			)
		);

		return $links;
	}

	public function enqueue_on_load(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$script = RCROCKET_DIR . 'assets/admin/app.js';

		if ( ! file_exists( $script ) ) {
			return;
		}

		wp_enqueue_script(
			'rcrocket-admin-panels',
			RCROCKET_URL . 'assets/admin/panels.js',
			[ 'wp-element', 'wp-components' ],
			(string) filemtime( RCROCKET_DIR . 'assets/admin/panels.js' ),
			true
		);

		wp_enqueue_script(
			'rcrocket-admin',
			RCROCKET_URL . 'assets/admin/app.js',
			[ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'rcrocket-admin-panels' ],
			(string) filemtime( $script ),
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_style(
			'rcrocket-admin',
			RCROCKET_URL . 'assets/admin/app.css',
			[ 'wp-components' ],
			(string) \RCRocket\VERSION
		);

		wp_add_inline_script(
			'rcrocket-admin',
			'window.RCRocketBoot = ' . wp_json_encode(
				[
					'root'    => esc_url_raw( rest_url( 'rc-rocket/v1' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'version' => \RCRocket\VERSION,
					'siteUrl' => home_url( '/' ),
				]
			) . ';',
			'before'
		);
	}

	public function render(): void {
		if ( ! file_exists( RCROCKET_DIR . 'assets/admin/app.js' ) ) {
			printf(
				'<div class="wrap"><h1>RC Rocket</h1><div class="notice notice-error"><p>%s</p></div></div>',
				esc_html__( 'The admin interface files are missing. Reinstall the plugin from its release zip.', 'rc-rocket' )
			);

			return;
		}

		echo '<div class="wrap"><div id="rcrocket-root"></div></div>';
	}
}
