<?php
declare( strict_types=1 );

namespace RCRocket\Assets;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Integrations\DiviOptimizer;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module E — asset manager and bloat removal.
 *
 * On a Divi site behind a host page cache, this is where the remaining speed
 * is. Render-blocking requests and unused JavaScript are both fixed by the
 * same thing: not loading assets on pages that do not use them.
 */
final class AssetsModule implements Module {

	public function id(): string {
		return 'assets';
	}

	public function label(): string {
		return __( 'Assets', 'rc-rocket' );
	}

	public function defaults(): array {
		return [
			'enabled' => true,
			'scan'    => true,
			'rules'   => [],
			'fonts'   => Fonts::defaults(),
			'divi'    => [
				'unload_modules' => false,
			],
			'bloat'   => [
				'emojis'          => true,
				'embeds'          => false,
				'dashicons'       => true,
				'jquery_migrate'  => false,
				'xmlrpc'          => true,
				'rsd_link'        => true,
				'shortlink'       => true,
				'generator'       => true,
				'wlwmanifest'     => true,
				'rest_links'      => true,
				'heartbeat_front' => true,
				'block_library'   => false,
				'comment_reply'   => true,
			],
		];
	}

	public function register( Container $container ): void {
		$container->set(
			'assets.registry',
			static fn( Container $c ): Registry => new Registry( $c->get( 'context' ) )
		);

		$container->set(
			'assets.fonts',
			static fn( Container $c ): Fonts => new Fonts(
				$c->get( 'logger' ),
				(array) $c->get( 'settings' )->get( 'assets.fonts', [] )
			)
		);

		$container->set(
			'assets.divi',
			static fn( Container $c ): DiviOptimizer => new DiviOptimizer(
				$c->get( 'divi' ),
				$c->get( 'context' ),
				$c->get( 'logger' ),
				(array) $c->get( 'settings' )->get( 'assets.divi', [] ),
				$c->get( 'divi.settings' )
			)
		);
	}

	public function boot( Container $container ): void {
		/** @var SafeMode $safe */
		$safe = $container->get( 'safe_mode' );
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );

		if ( $settings->enabled( 'assets.scan' ) ) {
			// Recording is read-only with respect to the page, so it runs even
			// in safe mode — that is precisely when you want the inventory.
			$container->get( 'assets.registry' )->hooks();
		}

		if ( $safe->is_active() ) {
			return;
		}

		// Head cleanup is all remove_action() calls; no query needed.
		$this->apply_bloat_rules( $settings );

		$container->get( 'assets.fonts' )->hooks();
		$container->get( 'assets.divi' )->hooks();

		// Dequeuing depends on which template this is, so it is gated inside
		// the callbacks — both of which run well after the query is built.
		add_action( 'wp_enqueue_scripts', function () use ( $container, $safe ): void {
			if ( $safe->should_optimize() ) {
				$this->apply_rules( $container );
			}
		}, PHP_INT_MAX );

		add_action( 'wp_print_footer_scripts', function () use ( $container, $safe ): void {
			if ( $safe->should_optimize() ) {
				$this->apply_rules( $container );
			}
		}, 1 );
	}

	// ---------------------------------------------------------------- rules

	private function apply_rules( Container $container ): void {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		/** @var Context $context */
		$context = $container->get( 'context' );
		/** @var Logger $logger */
		$logger = $container->get( 'logger' );

		$rules = (array) $settings->get( 'assets.rules', [] );

		if ( ! $rules ) {
			return;
		}

		$protected = Presets::protected_handles();
		$removed   = [];

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['handle'] ) ) {
				continue;
			}

			$handle  = (string) $rule['handle'];
			$kind    = 'style' === ( $rule['kind'] ?? 'script' ) ? 'style' : 'script';
			$scope   = (string) ( $rule['scope'] ?? 'everywhere' );
			$targets = (array) ( $rule['targets'] ?? [] );

			if ( in_array( $handle, $protected, true ) && empty( $rule['force'] ) ) {
				// Refuse silently in production, loudly in the log. Dropping
				// jQuery on Divi 4 does not slow the site, it stops it.
				$logger->debug( 'Refused to dequeue a protected handle', [ 'handle' => $handle ] );
				continue;
			}

			$applies = match ( $scope ) {
				'targets' => $context->matches( $targets ),
				'except'  => ! $context->matches( $targets ),
				default   => true,
			};

			if ( ! $applies ) {
				continue;
			}

			if ( 'style' === $kind ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			} else {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}

			$removed[] = $kind . ':' . $handle;
		}

		if ( $removed && $settings->enabled( 'general.debug' ) ) {
			$logger->debug( 'Assets removed', [ 'template' => $context->signature(), 'handles' => $removed ] );
		}
	}

	// ---------------------------------------------------------------- bloat

	private function apply_bloat_rules( Settings $settings ): void {
		$bloat = (array) $settings->get( 'assets.bloat', [] );

		if ( ! empty( $bloat['emojis'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'tiny_mce_plugins', static fn( array $p ): array => array_diff( $p, [ 'wpemoji' ] ) );
			add_filter( 'wp_resource_hints', static function ( array $hints, string $relation ): array {
				if ( 'dns-prefetch' !== $relation ) {
					return $hints;
				}

				return array_filter( $hints, static fn( $h ): bool => ! str_contains( (string) $h, 's.w.org' ) );
			}, 10, 2 );
		}

		if ( ! empty( $bloat['embeds'] ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action( 'wp_footer', static fn() => wp_deregister_script( 'wp-embed' ) );
		}

		if ( ! empty( $bloat['dashicons'] ) ) {
			add_action( 'wp_enqueue_scripts', static function (): void {
				if ( ! is_admin_bar_showing() ) {
					wp_dequeue_style( 'dashicons' );
				}
			}, PHP_INT_MAX );
		}

		if ( ! empty( $bloat['jquery_migrate'] ) ) {
			// Divi 4 themes and older third-party modules still rely on this,
			// which is why it defaults to off.
			add_filter( 'wp_default_scripts', static function ( $scripts ): void {
				if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
					$scripts->registered['jquery']->deps = array_diff(
						$scripts->registered['jquery']->deps,
						[ 'jquery-migrate' ]
					);
				}
			} );
		}

		if ( ! empty( $bloat['xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $bloat['rsd_link'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $bloat['wlwmanifest'] ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( ! empty( $bloat['shortlink'] ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( ! empty( $bloat['generator'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( ! empty( $bloat['rest_links'] ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( ! empty( $bloat['comment_reply'] ) ) {
			add_action( 'wp_enqueue_scripts', static function (): void {
				if ( ! is_singular() || ! comments_open() || ! get_option( 'thread_comments' ) ) {
					wp_dequeue_script( 'comment-reply' );
				}
			}, PHP_INT_MAX );
		}

		if ( ! empty( $bloat['block_library'] ) ) {
			add_action( 'wp_enqueue_scripts', static function (): void {
				wp_dequeue_style( 'wp-block-library' );
				wp_dequeue_style( 'wp-block-library-theme' );
				wp_dequeue_style( 'global-styles' );
				wp_dequeue_style( 'classic-theme-styles' );
			}, PHP_INT_MAX );
		}

		if ( ! empty( $bloat['heartbeat_front'] ) ) {
			add_action( 'init', static function (): void {
				global $pagenow;

				if ( ! is_admin() || 'post.php' !== $pagenow ) {
					wp_deregister_script( 'heartbeat' );
				}
			}, 1 );
		}
	}
}
