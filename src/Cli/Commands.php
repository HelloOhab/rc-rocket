<?php
declare( strict_types=1 );

namespace RCRocket\Cli;

use RCRocket\Cache\Preloader;
use RCRocket\Cache\ServerRules;
use RCRocket\Cache\Store;
use RCRocket\Container;
use RCRocket\Assets\Registry;
use RCRocket\Integrations\Divi;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI surface.
 *
 * Config-as-code is the point: `wp rc-rocket config export > perf.json`,
 * commit it, `wp rc-rocket config import perf.json` on the next site. Agencies
 * running dozens of Divi builds should not be clicking the same 30 toggles.
 */
final class Commands {

	public function __construct( private Container $container ) {}

	public static function register( Container $container ): void {
		\WP_CLI::add_command( 'rc-rocket', new self( $container ) );
	}

	/**
	 * Clears cached pages.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Clear a single URL.
	 *
	 * [--key=<key>]
	 * : Clear everything tagged with a surrogate key, e.g. divi-tb-42.
	 *
	 * [--expired]
	 * : Clear only entries past their TTL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rc-rocket purge
	 *     wp rc-rocket purge --key=divi-tb-118
	 *
	 * @when after_wp_load
	 */
	public function purge( array $args, array $assoc ): void {
		/** @var Store $store */
		$store = $this->container->get( 'cache.store' );

		if ( ! empty( $assoc['url'] ) ) {
			$count = $this->container->get( 'cache.purge' )->url( (string) $assoc['url'] );
		} elseif ( ! empty( $assoc['key'] ) ) {
			$count = $store->delete_by_key( (string) $assoc['key'] );
		} elseif ( ! empty( $assoc['expired'] ) ) {
			$count = $store->purge_expired();
		} else {
			$count = $store->flush();
			do_action( 'rc-rocket/cache/purged', 'all', $count );
		}

		\WP_CLI::success( sprintf( 'Cleared %d cached %s.', $count, 1 === $count ? 'page' : 'pages' ) );
	}

	/**
	 * Warms the cache by requesting published URLs.
	 *
	 * ## OPTIONS
	 *
	 * [--stop]
	 * : Cancel a preload already in progress.
	 *
	 * @when after_wp_load
	 */
	public function preload( array $args, array $assoc ): void {
		/** @var Preloader $preloader */
		$preloader = $this->container->get( 'cache.preloader' );

		if ( ! empty( $assoc['stop'] ) ) {
			$preloader->stop();
			\WP_CLI::success( 'Preload stopped.' );

			return;
		}

		$queued = $preloader->start();
		\WP_CLI::success( sprintf( 'Queued %d URLs. Batches run on cron.', $queued ) );
	}

	/**
	 * Shows what RC Rocket sees: cache size, drop-in state, Divi build.
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		/** @var Store $store */
		$store = $this->container->get( 'cache.store' );
		/** @var Divi $divi */
		$divi  = $this->container->get( 'divi' );
		$stats = $store->stats();
		$dropin = $this->container->get( 'cache.dropin' )->status();

		\WP_CLI\Utils\format_items(
			'table',
			[
				[ 'item' => 'Cached pages', 'value' => (string) $stats['files'] ],
				[ 'item' => 'Cache size', 'value' => size_format( $stats['bytes'] ) ],
				[ 'item' => 'Drop-in installed', 'value' => $dropin['installed'] ? 'yes' : 'no' ],
				[ 'item' => 'WP_CACHE', 'value' => $dropin['wp_cache'] ? 'on' : 'off' ],
				[ 'item' => 'Divi', 'value' => $divi->is_active() ? (string) $divi->version() . ' — ' . $divi->report()['engine'] : 'not detected' ],
				[ 'item' => 'Host', 'value' => $this->container->get( 'hosting' )->detect()['label'] ],
				[ 'item' => 'Safe mode', 'value' => $this->container->get( 'safe_mode' )->is_active() ? 'ON (' . $this->container->get( 'safe_mode' )->reason() . ')' : 'off' ],
			],
			[ 'item', 'value' ]
		);
	}

	/**
	 * Prints server rules for zero-PHP delivery.
	 *
	 * ## OPTIONS
	 *
	 * [<target>]
	 * : nginx, apache or cloudflare. Default nginx.
	 *
	 * @when after_wp_load
	 */
	public function rules( array $args ): void {
		/** @var ServerRules $rules */
		$rules  = $this->container->get( 'cache.server_rules' );
		$target = $args[0] ?? 'nginx';
		$all    = $rules->all();

		if ( ! isset( $all[ $target ] ) ) {
			\WP_CLI::error( 'Unknown target. Use nginx, apache or cloudflare.' );
		}

		\WP_CLI::line( $all[ $target ] );
	}

	/**
	 * Checks for a newer release and reports what this site is running.
	 *
	 * ## OPTIONS
	 *
	 * [--flush]
	 * : Discard the cached result and ask the source again.
	 *
	 * @subcommand version
	 * @when after_wp_load
	 */
	public function version_check( array $args, array $assoc ): void {
		$updater = $this->container->get( 'updater' );

		if ( ! empty( $assoc['flush'] ) ) {
			$updater->flush();
		}

		$report = $updater->report();

		if ( ! $report['configured'] ) {
			\WP_CLI::warning( 'No update source configured. Define RC_ROCKET_UPDATE_URL or RC_ROCKET_UPDATE_GITHUB in wp-config.php.' );

			return;
		}

		\WP_CLI::line( 'Source:    ' . $report['source'] );
		\WP_CLI::line( 'Installed: ' . $report['installed'] );
		\WP_CLI::line( 'Available: ' . ( $report['available'] ?? 'unknown' ) );

		if ( $report['update'] ) {
			\WP_CLI::warning( 'An update is available. Run: wp plugin update rc-rocket' );

			return;
		}

		\WP_CLI::success( 'Up to date.' );
	}

	/**
	 * Runs every diagnostic and reports what is actually happening.
	 *
	 * @subcommand check
	 * @when after_wp_load
	 */
	public function check(): void {
		$result = ( new \RCRocket\Admin\SelfTest( $this->container ) )->run();

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( array $c ): array => [
					'status' => strtoupper( $c['status'] ),
					'check'  => $c['label'],
					'detail' => $c['detail'],
				],
				$result['checks']
			),
			[ 'status', 'check', 'detail' ]
		);

		foreach ( $result['checks'] as $c ) {
			if ( '' !== $c['fix'] && in_array( $c['status'], [ 'fail', 'warn' ], true ) ) {
				\WP_CLI::line( sprintf( '  %s: %s', $c['label'], $c['fix'] ) );
			}
		}

		if ( $result['summary']['fail'] > 0 ) {
			\WP_CLI::error( sprintf( '%d checks failed.', $result['summary']['fail'] ) );
		}

		\WP_CLI::success( sprintf( '%d passed, %d warnings.', $result['summary']['pass'], $result['summary']['warn'] ) );
	}

	/**
	 * Lists every script and style recorded across your templates.
	 *
	 * ## OPTIONS
	 *
	 * [--unused]
	 * : Only show handles that appear on a single template.
	 *
	 * @when after_wp_load
	 */
	public function assets( array $args, array $assoc ): void {
		/** @var Registry $registry */
		$registry = $this->container->get( 'assets.registry' );
		$handles  = $registry->handles();

		if ( ! $handles ) {
			\WP_CLI::warning( 'Nothing recorded yet. Visit a few pages logged out first.' );

			return;
		}

		if ( ! empty( $assoc['unused'] ) ) {
			$handles = array_filter( $handles, static fn( array $h ): bool => count( $h['templates'] ) <= 1 );
		}

		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( array $h ): array => [
					'handle'    => $h['handle'],
					'kind'      => $h['kind'],
					'owner'     => $h['owner'],
					'templates' => (string) count( $h['templates'] ),
					'protected' => $h['protected'] ? 'yes' : '',
				],
				array_values( $handles )
			),
			[ 'handle', 'kind', 'owner', 'templates', 'protected' ]
		);
	}

	/**
	 * Turns every optimization off, or back on.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 *
	 * @subcommand safe-mode
	 * @when after_wp_load
	 */
	public function safe_mode( array $args ): void {
		$state    = strtolower( (string) ( $args[0] ?? '' ) );
		$settings = $this->container->get( 'settings' );

		if ( ! in_array( $state, [ 'on', 'off' ], true ) ) {
			\WP_CLI::error( 'Use: wp rc-rocket safe-mode on|off' );
		}

		$settings->set( 'general.safe_mode', 'on' === $state );
		$settings->set( 'general.auto_safe_mode_until', 0 );
		$settings->save();

		\WP_CLI::success( 'on' === $state ? 'Safe mode on. Nothing is being optimized.' : 'Safe mode off.' );
	}

	/**
	 * Exports or imports settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : export or import.
	 *
	 * [<file>]
	 * : File to read when importing. Reads STDIN when omitted.
	 *
	 * @subcommand config
	 * @when after_wp_load
	 */
	public function config( array $args ): void {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );
		$action   = $args[0] ?? 'export';

		if ( 'export' === $action ) {
			\WP_CLI::line( $settings->export() );

			return;
		}

		$json = isset( $args[1] ) ? (string) file_get_contents( $args[1] ) : (string) file_get_contents( 'php://stdin' );

		if ( ! $settings->import( $json ) ) {
			\WP_CLI::error( 'Could not import: the file is not valid JSON.' );
		}

		\WP_CLI::success( 'Settings imported.' );
	}
}
