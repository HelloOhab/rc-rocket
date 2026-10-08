<?php
declare( strict_types=1 );

namespace RCRocket;

use RCRocket\Admin\AdminMenu;
use RCRocket\Admin\PageOptionsBox;
use RCRocket\Admin\RestController;
use RCRocket\Assets\AssetsModule;
use RCRocket\Cache\CacheModule;
use RCRocket\Cache\Dropin;
use RCRocket\Cli\Commands;
use RCRocket\Database\DatabaseModule;
use RCRocket\Preload\PreloadModule;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Js\JsModule;
use RCRocket\Media\MediaModule;
use RCRocket\Safety\SafetyModule;
use RCRocket\Contracts\Module;
use RCRocket\Support\Filesystem;
use RCRocket\Support\Hosting;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\PageOptions;
use RCRocket\Support\Paths;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;
use RCRocket\Update\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** Every scheduled event the plugin can create. */
	public const CRON_HOOKS = [
		'rc-rocket/cache/cleanup',
		'rc-rocket/preload/batch',
		'rc-rocket/fonts/refresh',
		'rc-rocket/fonts/localize',
		'rc-rocket/database/cleanup',
		'rc-rocket/host/deferred-purge',
		'rc-rocket/safe-mode/expired',
	];

	/** Settings schema version; migrate() brings older options up to it. */
	public const SCHEMA = 8;

	private static ?self $instance = null;

	private Container $container;

	/** @var Module[] */
	private array $modules = [];

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function container(): Container {
		return $this->container;
	}

	public function boot(): void {
		$this->register_core_services();
		$this->register_modules();

		add_action( 'plugins_loaded', [ $this, 'migrate' ], 4 );
		add_action( 'plugins_loaded', [ $this, 'boot_modules' ], 5 );
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Runs whether or not the cache module is on: the drop-in has to hear
		// about the save that switches caching back on, too.
		add_action( 'rc-rocket/settings/saved', [ $this, 'on_settings_saved' ], 10, 1 );

		// Entering or leaving safe mode changes what every page should look
		// like, so every cached copy — ours or the host's — is now wrong.
		add_action( 'rc-rocket/safe-mode/changed', static fn() => do_action( 'rc-rocket/purge/all', 'safe-mode' ) );
		add_action( SafeMode::EXPIRED_HOOK, static fn() => do_action( 'rc-rocket/safe-mode/changed', false, 'expired' ) );

		PageOptions::hooks();

		if ( is_admin() ) {
			( new AdminMenu( $this->container ) )->hooks();
			( new PageOptionsBox( $this->container ) )->hooks();
		}

		$rest = new RestController( $this->container, $this->modules );
		$rest->hooks();

		// Read at plugins_loaded, after settings exist; the Abilities API
		// fires its init hooks later still.
		add_action(
			'plugins_loaded',
			function () use ( $rest ): void {
				if ( $this->container->get( 'settings' )->enabled( 'general.abilities' ) ) {
					( new Admin\Abilities( $this->container, $rest ) )->hooks();
				}
			},
			6
		);

		$this->container->get( 'updater' )->hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register( $this->container );
		}
	}

	/**
	 * Move automatic safe mode out of settings, where earlier versions kept it.
	 * Leaving a stale timestamp behind would hold a site in safe mode with no
	 * visible cause.
	 */
	public function migrate(): void {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );
		$version  = (int) $settings->get( 'general.schema_version', 0 );

		if ( $version >= self::SCHEMA ) {
			return;
		}

		// 3: automatic safe mode moved out of settings, where earlier versions
		// kept it. Leaving a stale timestamp behind would hold a site in safe
		// mode with no visible cause.
		if ( $version < 3 ) {
			$until = (int) $settings->get( 'general.auto_safe_mode_until', 0 );

			if ( $until > time() ) {
				update_option(
					SafeMode::STATE_OPTION,
					[
						'until'  => $until,
						'reason' => (string) $settings->get( 'general.auto_safe_mode_reason', '' ),
						'at'     => time(),
					],
					false
				);
			}

			// Whatever the old code already wrote into the history is noise.
			$this->container->get( 'safety.history' )->prune();
		}

		// 4: the front-end heartbeat switch became per-context heartbeat
		// control. Keep whatever the site had.
		if ( $version < 4 ) {
			$stored = get_option( Settings::OPTION, [] );

			if ( is_array( $stored ) && isset( $stored['assets']['bloat']['heartbeat_front'] ) ) {
				$settings->set( 'assets.heartbeat.frontend', $stored['assets']['bloat']['heartbeat_front'] ? 'disable' : 'default' );
			}

			$settings->remove( 'assets.bloat.heartbeat_front' );
		}

		// 5: presets. A site upgrading keeps the settings it had, which match
		// no preset; a fresh install (schema 0) starts on Recommended.
		if ( $version < 5 && $version >= 2 ) {
			$settings->set( Support\Presets::OPTION_PATH, 'custom' );
		}

		// 6: background videos load as Divi intends. Holding their sources
		// back (and preload=none) broke Divi's cover sizing of hero videos.
		if ( $version < 6 ) {
			$settings->set( 'media.video.withhold', false );
			$settings->set( 'media.video.preload_none', false );
		}

		// 7: Divi background lazy loading arrives switched on, like every
		// speed option, except on a site that chose the Safe preset.
		if ( $version < 7 && $version >= 2 && 'safe' === $settings->get( Support\Presets::OPTION_PATH ) ) {
			$settings->set( 'media.lazy_backgrounds', false );
		}

		// 8: an installed drop-in is a copy made at install time, so its fixes
		// never arrived; and its config holds absolute paths that a site move
		// leaves pointing elsewhere. Refresh both where we own page caching.
		if ( $version < 8 && $version >= 1 && $this->container->has( 'cache.dropin' ) && $this->container->has( 'hosting' ) ) {
			$dropin = $this->container->get( 'cache.dropin' );

			if ( $dropin->is_ours() && ! $this->container->get( 'hosting' )->manages_page_cache() ) {
				$dropin->write_config( (array) $settings->get( 'cache', [] ) );
				$dropin->refresh();
			}
		}

		$settings->remove( 'general.auto_safe_mode_until' );
		$settings->remove( 'general.auto_safe_mode_reason' );
		$settings->set( 'general.schema_version', self::SCHEMA );
		$settings->save();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'rc-rocket', false, dirname( RCROCKET_BASENAME ) . '/languages' );
	}

	/**
	 * Keep the drop-in's view of the world in sync with the option, then drop
	 * every cached page: they were rendered under the old settings.
	 */
	public function on_settings_saved( array $settings ): void {
		$hosting = $this->container->get( 'hosting' );

		if ( $hosting->manages_page_cache() ) {
			// A site moved onto a managed host can still carry our drop-in.
			// The host owns that file; a second page cache in front of it
			// serves stale pages the host cannot purge.
			$dropin = $this->container->get( 'cache.dropin' );

			if ( $dropin->is_ours() ) {
				$dropin->uninstall();
			}
		} else {
			$dropin = $this->container->get( 'cache.dropin' );
			$dropin->write_config( (array) ( $settings['cache'] ?? [] ) );

			// Back on a host where we own page caching (a migration away from
			// a managed host, or a drop-in someone deleted): put it back.
			if ( ! empty( $settings['cache']['enabled'] ) && ! $dropin->is_ours() && ! $dropin->has_foreign_dropin() ) {
				$dropin->install();
			}

			// With the cache module off nothing listens for the purge below,
			// and switching it back on later would serve these stale copies.
			if ( empty( $settings['cache']['enabled'] ) ) {
				$this->container->get( 'cache.store' )->flush();
			}
		}

		do_action( 'rc-rocket/purge/all', 'settings' );
	}

	/**
	 * Purge everything, now, and report how many of our own entries went.
	 * The one path for the button, the admin bar and WP-CLI, so every one of
	 * them reaches the host cache on a managed host.
	 */
	public function purge_everything( string $reason = 'manual' ): int {
		do_action( 'rc-rocket/purge/all', $reason );

		if ( $this->container->get( 'hosting' )->manages_page_cache() ) {
			return 0;
		}

		// Our own flush is normally deferred to shutdown; an explicit purge
		// wants it done before the response says it is.
		return (int) $this->container->get( 'cache.purge' )->run_queue();
	}

	/**
	 * Clear one page from whichever cache serves it: ours, or the host's.
	 */
	public function purge_url( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		if ( $this->container->get( 'hosting' )->manages_page_cache() ) {
			$this->container->get( 'cache.host_bridge' )->purge_url( $url );

			// Kinsta empties the page; ask for it again shortly so the next
			// visitor gets a cached copy rather than paying for the rebuild.
			if ( $this->container->get( 'settings' )->enabled( 'cache.preload_on_purge' ) ) {
				$this->container->get( 'cache.preloader' )->enqueue( [ $url ], 30 );
			}

			return 0;
		}

		return (int) $this->container->get( 'cache.purge' )->url( $url );
	}

	/** Clear a post's own page. Archives and the home page refresh on edit. */
	public function purge_post( int $post_id ): int {
		$url = (string) get_permalink( $post_id );

		return '' === $url ? 0 : $this->purge_url( $url );
	}

	/**
	 * Clearing everything is for administrators, or anyone given the
	 * rcrocket_purge_cache capability (with a role editor plugin, say).
	 */
	public static function can_purge_all(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'rcrocket_purge_cache' );
	}

	/**
	 * Clearing a single page is also open to editors: after fixing a typo
	 * they should not have to wait out the cache or ask an administrator.
	 */
	public static function can_purge_page(): bool {
		/** @param bool $allowed */
		return (bool) apply_filters( 'rc-rocket/can_purge_page', self::can_purge_all() || current_user_can( 'edit_others_posts' ) );
	}

	public function boot_modules(): void {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		// One shared output buffer for every module that rewrites HTML.
		$this->container->get( 'html_pipeline' )->hooks();

		foreach ( $this->modules as $module ) {
			if ( ! $settings->enabled( $module->id() . '.enabled' ) ) {
				continue;
			}

			$module->boot( $this->container );
		}

		do_action( 'rc-rocket/booted', $this->container );
	}

	/** @return Module[] */
	public function modules(): array {
		return $this->modules;
	}

	private function register_core_services(): void {
		$settings = new Settings();

		$this->container->set( 'settings', static fn(): Settings => $settings );

		$this->container->set( 'context', static fn(): Context => new Context() );

		$this->container->set(
			'updater',
			static fn( Container $c ): Updater => new Updater( RCROCKET_FILE, VERSION, $c->get( 'logger' ) )
		);

		$this->container->set(
			'safe_mode',
			static fn( Container $c ): SafeMode => new SafeMode( $c->get( 'settings' ) )
		);

		$this->container->set(
			'html_pipeline',
			static fn( Container $c ): HtmlPipeline => new HtmlPipeline(
				$c->get( 'safe_mode' ),
				$c->get( 'logger' ),
				(bool) $c->get( 'settings' )->get( 'general.debug_comment', true )
			)
		);

		$this->container->set(
			'logger',
			static function ( Container $c ) use ( $settings ): Logger {
				return new Logger(
					Paths::log_file(),
					(bool) $settings->get( 'general.debug', defined( 'WP_DEBUG' ) && WP_DEBUG )
				);
			}
		);
	}

	private function register_modules(): void {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		$settings->add_defaults(
			'general',
			[
				'debug'                  => false,
				'debug_comment'          => true,
				'onboarded'              => false,
				'safe_mode'              => false,
				'abilities'              => true,
				'skip_logged_in'         => true,
				'schema_version'         => 0,
				'preset'                 => 'recommended',
			]
		);

		/**
		 * Modules register here. Later passes add css, js, media, bloat,
		 * safety and rum; the contract does not change.
		 *
		 * @param Module[] $modules
		 */
		$modules = (array) apply_filters(
			'rc-rocket/modules',
			[
				// Order is load order. The safety net is first on purpose: its
				// kill switch has to be listening before anything can rewrite
				// a byte of output.
				new SafetyModule(),
				new CacheModule(),
				new AssetsModule(),
				new MediaModule(),
				new JsModule(),
				new PreloadModule(),
				new DatabaseModule(),
			]
		);

		foreach ( $modules as $module ) {
			if ( ! $module instanceof Module ) {
				continue;
			}

			$settings->add_defaults( $module->id(), $module->defaults() );
			$module->register( $this->container );

			$this->modules[ $module->id() ] = $module;
		}
	}

	// ------------------------------------------------------------- lifecycle

	public static function activate(): void {
		$plugin    = self::instance();
		$container = $plugin->container();

		if ( ! $container->has( 'settings' ) ) {
			$plugin->boot();
		}

		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		$settings->save();

		$dir = Paths::cache_dir();

		Filesystem::ensure_dir( $dir . '/pages' );
		Filesystem::ensure_dir( $dir . '/keys' );

		$hosting = new Hosting();
		$dropin  = new Dropin( $dir );

		$dropin->write_config( (array) $settings->get( 'cache', [] ) );
		$dropin->install( $hosting->manages_page_cache() );

		set_transient( 'rcrocket_activated', 1, 60 );
	}

	public static function deactivate(): void {
		$dir    = Paths::cache_dir();
		$dropin = new Dropin( $dir );

		$dropin->uninstall();

		Filesystem::delete_tree( $dir . '/pages' );
		Filesystem::delete_tree( $dir . '/keys' );
		Filesystem::delete_tree( $dir . '/mirror' );

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
