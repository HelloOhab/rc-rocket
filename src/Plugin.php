<?php
declare( strict_types=1 );

namespace RCRocket;

use RCRocket\Admin\AdminMenu;
use RCRocket\Admin\RestController;
use RCRocket\Assets\AssetsModule;
use RCRocket\Cache\CacheModule;
use RCRocket\Cache\Dropin;
use RCRocket\Cli\Commands;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Js\JsModule;
use RCRocket\Media\MediaModule;
use RCRocket\Safety\SafetyModule;
use RCRocket\Contracts\Module;
use RCRocket\Support\Filesystem;
use RCRocket\Support\Hosting;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;
use RCRocket\Update\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

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

		// 'weekly' is not one of WordPress's built-in intervals.
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				$schedules['weekly'] ??= [
					'interval' => WEEK_IN_SECONDS,
					'display'  => __( 'Once weekly', 'rc-rocket' ),
				];

				return $schedules;
			}
		);

		if ( is_admin() ) {
			( new AdminMenu( $this->container ) )->hooks();
		}

		( new RestController( $this->container, $this->modules ) )->hooks();

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

		if ( (int) $settings->get( 'general.schema_version', 0 ) >= 3 ) {
			return;
		}

		$until = (int) $settings->get( 'general.auto_safe_mode_until', 0 );

		if ( $until > time() ) {
			update_option(
				\RCRocket\Support\SafeMode::STATE_OPTION,
				[
					'until'  => $until,
					'reason' => (string) $settings->get( 'general.auto_safe_mode_reason', '' ),
					'at'     => time(),
				],
				false
			);
		}

		$settings->set( 'general.auto_safe_mode_until', 0 );
		$settings->set( 'general.auto_safe_mode_reason', '' );
		$settings->set( 'general.schema_version', 3 );
		$settings->save();

		// Whatever the old code already wrote into the history is noise.
		$this->container->get( 'safety.history' )->prune();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'rcrocket', false, dirname( RCROCKET_BASENAME ) . '/languages' );
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
				$dir = (string) apply_filters( 'rc-rocket/cache/dir', WP_CONTENT_DIR . '/cache/rc-rocket' );

				return new Logger(
					$dir . '/rc-rocket.log',
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
				'skip_logged_in'         => true,
				'auto_safe_mode_until'   => 0,
				'auto_safe_mode_reason'  => '',
				'schema_version'         => 2,
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

		$dir = (string) apply_filters( 'rc-rocket/cache/dir', WP_CONTENT_DIR . '/cache/rc-rocket' );

		Filesystem::ensure_dir( $dir . '/pages' );
		Filesystem::ensure_dir( $dir . '/keys' );

		$hosting = new Hosting();
		$dropin  = new Dropin( $dir );

		$dropin->write_config( (array) $settings->get( 'cache', [] ) );
		$dropin->install( $hosting->manages_page_cache() );

		set_transient( 'rcrocket_activated', 1, 60 );
	}

	public static function deactivate(): void {
		$dir    = (string) apply_filters( 'rc-rocket/cache/dir', WP_CONTENT_DIR . '/cache/rc-rocket' );
		$dropin = new Dropin( $dir );

		$dropin->uninstall();

		Filesystem::delete_tree( $dir . '/pages' );
		Filesystem::delete_tree( $dir . '/keys' );
		Filesystem::delete_tree( $dir . '/mirror' );

		wp_clear_scheduled_hook( CacheModule::CLEANUP_HOOK );
		wp_clear_scheduled_hook( \RCRocket\Cache\Preloader::CRON_HOOK );
		wp_clear_scheduled_hook( \RCRocket\Assets\Fonts::CRON_HOOK );
	}
}
