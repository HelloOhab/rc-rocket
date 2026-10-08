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
				'unload_modules' => true,
			],
			'bloat'   => [
				'emojis'          => true,
				'embeds'          => true,
				'dashicons'       => true,
				'jquery_migrate'  => true,
				'xmlrpc'          => false,
				'rsd_link'        => true,
				'shortlink'       => true,
				'generator'       => true,
				'wlwmanifest'     => true,
				'rest_links'      => true,
				'block_library'   => false,
				'comment_reply'   => true,
			],
			'heartbeat' => [
				'frontend' => 'disable',
				'backend'  => 'reduce',
				'editor'   => 'reduce',
				'interval' => 120,
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

		// Heartbeat control is admin housekeeping, not output rewriting, so
		// safe mode does not switch it off.
		$this->apply_heartbeat( (array) $settings->get( 'assets.heartbeat', [] ) );

		if ( $settings->enabled( 'assets.scan' ) ) {
			// Recording is read-only with respect to the page, so it runs even
			// in safe mode — that is precisely when you want the inventory.
			$container->get( 'assets.registry' )->hooks();
		}

		if ( $safe->is_active() ) {
			return;
		}

		// Head cleanup is all remove_action() calls; no query needed. Never
		// inside the Divi builder: it runs on the front end, and taking
		// jQuery Migrate or comment-reply away from it is not a speed-up.
		if ( ! \RCRocket\Integrations\Divi::is_builder_request() ) {
			$this->apply_bloat_rules( $settings );
		}

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

		if ( ! $rules || \RCRocket\Support\PageOptions::off( 'asset_rules' ) ) {
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
			// Some older Divi 4 modules still call removed jQuery APIs. If
			// one does, the error beacon sees it and rolls back; the Safe
			// preset keeps Migrate.
			add_filter( 'wp_default_scripts', static function ( $scripts ): void {
				if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
					$scripts->registered['jquery']->deps = array_diff(
						$scripts->registered['jquery']->deps,
						[ 'jquery-migrate' ]
					);
				}
			} );
		}

		// Jetpack and the WordPress mobile apps talk to the site over
		// XML-RPC; switching it off for them is breakage, not speed.
		if ( ! empty( $bloat['xmlrpc'] ) && ! defined( 'JETPACK__VERSION' ) ) {
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

	}

	// ------------------------------------------------------------ heartbeat

	/**
	 * The Heartbeat API polls admin-ajax.php every 15–60 seconds from every
	 * open tab. On a managed host each poll is an uncached PHP request that
	 * counts against the plan, and a forgotten dashboard tab costs as much as
	 * a steady trickle of visitors.
	 *
	 * Three contexts, decided separately. The editor can be slowed but never
	 * stopped: post locking and autosave recovery run on it.
	 */
	private function apply_heartbeat( array $config ): void {
		$interval = max( 15, min( 120, (int) ( $config['interval'] ?? 120 ) ) );

		$mode = static function () use ( $config ): string {
			global $pagenow;

			// The Divi Visual Builder is an editor that happens to run on
			// the front end: post locking, autosave and the "you have been
			// logged out" check all ride on Heartbeat there.
			if ( ! is_admin() && \RCRocket\Integrations\Divi::is_builder_request() ) {
				$editor = (string) ( $config['editor'] ?? 'default' );

				return 'disable' === $editor ? 'reduce' : $editor;
			}

			if ( ! is_admin() ) {
				return (string) ( $config['frontend'] ?? 'default' );
			}

			if ( in_array( $pagenow, [ 'post.php', 'post-new.php', 'site-editor.php' ], true ) ) {
				$editor = (string) ( $config['editor'] ?? 'default' );

				return 'disable' === $editor ? 'reduce' : $editor;
			}

			return (string) ( $config['backend'] ?? 'default' );
		};

		add_filter(
			'heartbeat_settings',
			static function ( array $settings ) use ( $mode, $interval ): array {
				if ( 'reduce' === $mode() ) {
					$settings['interval']        = $interval;
					$settings['minimalInterval'] = $interval;
				}

				return $settings;
			}
		);

		$disable = static function () use ( $mode ): void {
			if ( 'disable' === $mode() ) {
				wp_deregister_script( 'heartbeat' );
			}
		};

		add_action( 'wp_enqueue_scripts', $disable, 1 );
		add_action( 'admin_enqueue_scripts', $disable, 1 );
	}
}
