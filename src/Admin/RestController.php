<?php
declare( strict_types=1 );

namespace RCRocket\Admin;

use RCRocket\Assets\Presets;
use RCRocket\Assets\Registry;
use RCRocket\Cache\Dropin;
use RCRocket\Cache\Preloader;
use RCRocket\Cache\ServerRules;
use RCRocket\Cache\Store;
use RCRocket\Container;
use RCRocket\Integrations\Divi;
use RCRocket\Media\MediaModule;
use RCRocket\Safety\History;
use RCRocket\Safety\SafetyModule;
use RCRocket\Support\Hosting;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin app talks to the plugin exclusively through these routes, which
 * means WP-CLI, CI pipelines and external dashboards get the same API for free.
 */
final class RestController {

	private const NAMESPACE = 'rc-rocket/v1';

	public function __construct( private Container $container, private array $modules ) {}

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_post_rcrocket_purge_all', [ $this, 'handle_purge_all' ] );
		add_action( 'admin_post_rcrocket_purge_url', [ $this, 'handle_purge_url' ] );
	}

	public function register_routes(): void {
		$manage = [ $this, 'can_manage' ];

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => $manage,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $manage,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/purge',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'purge' ],
				'permission_callback' => $manage,
				'args'                => [
					'scope' => [
						'type'    => 'string',
						'default' => 'all',
						'enum'    => [ 'all', 'url', 'key', 'expired' ],
					],
					'value' => [ 'type' => 'string' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/preload',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preload' ],
				'permission_callback' => $manage,
				'args'                => [
					'action' => [
						'type'    => 'string',
						'default' => 'start',
						'enum'    => [ 'start', 'stop' ],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/server-rules',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'server_rules' ],
				'permission_callback' => $manage,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/config',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'export_config' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'import_config' ],
					'permission_callback' => $manage,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/assets',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_assets' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'clear_assets' ],
					'permission_callback' => $manage,
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/self-test',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'self_test' ],
				'permission_callback' => $manage,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/history',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_history' ],
				'permission_callback' => $manage,
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/history/restore',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'restore_history' ],
				'permission_callback' => $manage,
				'args'                => [
					'id' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/errors',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_errors' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'clear_errors' ],
					'permission_callback' => $manage,
				],
			]
		);

		// Public: refreshes Divi form nonces on cached pages. No capability
		// check by design — a logged-out visitor needs a valid nonce too.
		register_rest_route(
			self::NAMESPACE,
			'/nonces',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'nonces' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'actions' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	public function get_assets(): \WP_REST_Response {
		/** @var Registry $registry */
		$registry = $this->container->get( 'assets.registry' );

		return rest_ensure_response(
			[
				'handles'     => $registry->handles(),
				'templates'   => array_keys( $registry->all() ),
				'suggestions' => Presets::suggestions(),
				'protected'   => Presets::protected_handles(),
			]
		);
	}

	public function clear_assets(): \WP_REST_Response {
		$this->container->get( 'assets.registry' )->clear();
		$this->container->get( 'assets.fonts' )->purge();

		return rest_ensure_response( [ 'cleared' => true ] );
	}

	public function self_test(): \WP_REST_Response {
		return rest_ensure_response( ( new SelfTest( $this->container ) )->run() );
	}

	public function get_history(): \WP_REST_Response {
		/** @var History $history */
		$history = $this->container->get( 'safety.history' );
		/** @var SafeMode $safe */
		$safe = $this->container->get( 'safe_mode' );

		return rest_ensure_response(
			[
				'entries'   => $history->listing(),
				'safe_mode' => [
					'active'      => $safe->is_active(),
					'reason'      => $safe->reason(),
					'auto_reason' => $safe->auto_reason(),
				],
			]
		);
	}

	public function restore_history( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var History $history */
		$history = $this->container->get( 'safety.history' );

		return rest_ensure_response(
			[ 'restored' => $history->restore( (string) $request->get_param( 'id' ) ) ]
		);
	}

	public function get_errors(): \WP_REST_Response {
		$log = get_option( SafetyModule::ERRORS_OPTION, [] );
		$log = is_array( $log ) ? $log : [];

		// One row per distinct problem, newest first, with a count and the
		// pages it happened on. A list of forty identical rows hides the fact
		// that there are only three things wrong.
		$grouped = [];

		foreach ( $log as $entry ) {
			$key = (string) ( $entry['fingerprint'] ?? SafetyModule::fingerprint( (array) $entry ) );

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = $entry + [
					'count' => 0,
					'pages' => [],
					'first' => (int) ( $entry['time'] ?? 0 ),
				];
			}

			++$grouped[ $key ]['count'];
			$grouped[ $key ]['first'] = min( (int) $grouped[ $key ]['first'], (int) ( $entry['time'] ?? 0 ) );

			$page = (string) ( $entry['page'] ?? '' );

			if ( '' !== $page && ! in_array( $page, $grouped[ $key ]['pages'], true ) && count( $grouped[ $key ]['pages'] ) < 5 ) {
				$grouped[ $key ]['pages'][] = $page;
			}
		}

		$baseline = (array) get_option( SafetyModule::BASELINE_OPTION, [] );

		foreach ( $grouped as $key => $entry ) {
			$grouped[ $key ]['baseline'] = isset( $baseline[ $key ] );
			$grouped[ $key ]['own_site'] = self::is_own_site( (string) ( $entry['source'] ?? '' ) );
			$grouped[ $key ]['counts']   = self::counts_toward_rollback( (array) $entry );
		}

		return rest_ensure_response( [ 'errors' => array_values( $grouped ) ] );
	}

	/**
	 * Mirrors SafetyModule::attributable() for display. A row that cannot
	 * trigger a rollback should not look like one that can.
	 */
	private static function counts_toward_rollback( array $entry ): bool {
		$kind = (string) ( $entry['kind'] ?? '' );

		if ( ! empty( $entry['baseline'] ) ) {
			return false;
		}

		if ( in_array( $kind, [ 'js', 'promise' ], true ) ) {
			return true;
		}

		return 'resource' === $kind && str_starts_with( (string) ( $entry['message'] ?? '' ), 'SCRIPT' );
	}

	private static function is_own_site( string $source ): bool {
		if ( '' === $source ) {
			return false;
		}

		$host = (string) ( wp_parse_url( $source, PHP_URL_HOST ) ?: '' );

		return '' !== $host && $host === (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
	}

	public function clear_errors( \WP_REST_Request $request ): \WP_REST_Response {
		delete_option( SafetyModule::ERRORS_OPTION );

		if ( $request->get_param( 'baseline' ) ) {
			delete_option( SafetyModule::BASELINE_OPTION );
		}

		$pruned = $this->container->get( 'safety.history' )->prune();

		/** @var SafeMode $safe */
		$safe = $this->container->get( 'safe_mode' );

		// Clearing the log is also how you say "I fixed it, try again".
		$safe->release();

		return rest_ensure_response(
			[
				'cleared' => true,
				'pruned'  => $pruned,
			]
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function get_settings(): \WP_REST_Response {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		return rest_ensure_response(
			[
				'settings' => $settings->all(),
				'modules'  => array_map(
					static fn( $m ): array => [
						'id'    => $m->id(),
						'label' => $m->label(),
					],
					array_values( $this->modules )
				),
			]
		);
	}

	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		$payload = (array) $request->get_json_params();

		unset( $payload['general']['schema_version'] );

		$settings->merge( $payload );
		$settings->save();

		return rest_ensure_response(
			[
				'saved'    => true,
				'settings' => $settings->all(),
			]
		);
	}

	public function get_status(): \WP_REST_Response {
		/** @var Store $store */
		$store = $this->container->get( 'cache.store' );
		/** @var Dropin $dropin */
		$dropin = $this->container->get( 'cache.dropin' );
		/** @var Preloader $preloader */
		$preloader = $this->container->get( 'cache.preloader' );
		/** @var Divi $divi */
		$divi = $this->container->get( 'divi' );
		/** @var Hosting $hosting */
		$hosting = $this->container->get( 'hosting' );

		return rest_ensure_response(
			[
				'cache'    => $store->stats(),
				'dropin'   => $dropin->status(),
				'preload'  => $preloader->state(),
				'divi'     => $divi->report(),
				'fonts'    => $this->container->get( 'assets.fonts' )->report(),
				'divi_assets' => $this->container->get( 'assets.divi' )->report(),
				'hosting'  => $hosting->report() + [
					'image_conversion_allowed' => MediaModule::conversion_allowed( $hosting ),
				],
				'safe_mode' => [
					'active'      => $this->container->get( 'safe_mode' )->is_active(),
					'reason'      => $this->container->get( 'safe_mode' )->reason(),
					'auto_reason' => $this->container->get( 'safe_mode' )->auto_reason(),
				],
				'server'   => [
					'php'      => PHP_VERSION,
					'software' => sanitize_text_field( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ),
					'gzip'     => function_exists( 'gzencode' ),
				],
			]
		);
	}

	public function purge( \WP_REST_Request $request ): \WP_REST_Response {
		$scope = (string) $request->get_param( 'scope' );
		$value = (string) $request->get_param( 'value' );

		/** @var Store $store */
		$store = $this->container->get( 'cache.store' );
		$purge = $this->container->get( 'cache.purge' );

		$deleted = match ( $scope ) {
			'url'     => $purge->url( esc_url_raw( $value ) ),
			'key'     => $store->delete_by_key( sanitize_text_field( $value ) ),
			'expired' => $store->purge_expired(),
			default   => $store->flush(),
		};

		if ( 'all' === $scope ) {
			do_action( 'rc-rocket/cache/purged', 'all', $deleted );
		}

		return rest_ensure_response(
			[
				'purged'  => true,
				'scope'   => $scope,
				'entries' => $deleted,
			]
		);
	}

	public function preload( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var Preloader $preloader */
		$preloader = $this->container->get( 'cache.preloader' );

		if ( 'stop' === $request->get_param( 'action' ) ) {
			$preloader->stop();

			return rest_ensure_response( [ 'running' => false ] );
		}

		$queued = $preloader->start();

		return rest_ensure_response(
			[
				'running' => true,
				'queued'  => $queued,
			]
		);
	}

	public function server_rules(): \WP_REST_Response {
		/** @var ServerRules $rules */
		$rules = $this->container->get( 'cache.server_rules' );

		return rest_ensure_response( $rules->all() );
	}

	public function export_config(): \WP_REST_Response {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		return rest_ensure_response( [ 'json' => $settings->export() ] );
	}

	public function import_config( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );

		$imported = $settings->import( (string) $request->get_param( 'json' ) );

		return rest_ensure_response( [ 'imported' => $imported ] );
	}

	public function nonces( \WP_REST_Request $request ): \WP_REST_Response {
		$actions = array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $request->get_param( 'actions' ) ) ) );
		$out     = [];

		foreach ( array_slice( $actions, 0, 20 ) as $action ) {
			$out[ $action ] = wp_create_nonce( $action );
		}

		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	// ------------------------------------------------------ admin-bar links

	public function handle_purge_all(): void {
		check_admin_referer( 'rcrocket_purge_all' );

		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You cannot clear the cache.', 'rc-rocket' ) );
		}

		$this->container->get( 'cache.store' )->flush();
		do_action( 'rc-rocket/cache/purged', 'all', 0 );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public function handle_purge_url(): void {
		check_admin_referer( 'rcrocket_purge_url' );

		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You cannot clear the cache.', 'rc-rocket' ) );
		}

		$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['url'] ) ) ) : '';

		if ( '' !== $url ) {
			$this->container->get( 'cache.purge' )->url( $url );
		}

		wp_safe_redirect( $url ?: ( wp_get_referer() ?: admin_url() ) );
		exit;
	}
}
