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
		add_action( 'admin_post_rcrocket_purge_post', [ $this, 'handle_purge_post' ] );
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'purged_notice' ] );
		add_filter( 'removable_query_args', static fn( array $args ): array => array_merge( $args, [ 'rcrocket_purged' ] ) );
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

		register_rest_route(
			self::NAMESPACE,
			'/preset',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_presets' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'apply_preset' ],
					'permission_callback' => $manage,
					'args'                => [
						'name' => [
							'type'     => 'string',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/database',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_database' ],
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'clean_database' ],
					'permission_callback' => $manage,
					'args'                => [
						'items' => [
							'type'     => 'array',
							'items'    => [
								'type' => 'string',
								'enum' => [ 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'optimize_tables' ],
							],
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/lcp/measurements',
			[
				[
					'methods'             => 'GET',
					'callback'            => static fn() => rest_ensure_response( [ 'items' => \RCRocket\Media\Lcp::listing() ] ),
					'permission_callback' => $manage,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'reset_lcp' ],
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

	public function get_presets(): \WP_REST_Response {
		return rest_ensure_response(
			[
				'presets' => \RCRocket\Support\Presets::catalogue(),
				'current' => (string) $this->container->get( 'settings' )->get( \RCRocket\Support\Presets::OPTION_PATH, '' ),
			]
		);
	}

	public function apply_preset( \WP_REST_Request $request ) {
		/** @var Settings $settings */
		$settings = $this->container->get( 'settings' );
		$name     = (string) $request->get_param( 'name' );

		if ( ! \RCRocket\Support\Presets::apply( $settings, $name ) ) {
			return new \WP_Error( 'rcrocket_unknown_preset', __( 'Unknown preset.', 'rc-rocket' ), [ 'status' => 400 ] );
		}

		return rest_ensure_response(
			[
				'applied'  => $name,
				'settings' => $settings->all(),
			]
		);
	}

	public function get_database(): \WP_REST_Response {
		/** @var \RCRocket\Database\DatabaseModule $database */
		$database = $this->container->get( 'database' );

		return rest_ensure_response(
			[
				'counts'   => $database->counts( (int) $this->container->get( 'settings' )->get( 'database.revisions_keep', 5 ) ),
				'last_run' => $database->last_run(),
				'next_run' => wp_next_scheduled( \RCRocket\Database\DatabaseModule::CRON_HOOK ) ?: null,
			]
		);
	}

	public function clean_database( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var \RCRocket\Database\DatabaseModule $database */
		$database = $this->container->get( 'database' );
		$settings = $this->container->get( 'settings' );

		$removed = $database->run(
			(array) $settings->get( 'database', [] ),
			$this->container->get( 'logger' ),
			array_map( 'strval', (array) $request->get_param( 'items' ) )
		);

		return rest_ensure_response(
			[
				'removed' => $removed,
				'counts'  => $database->counts( (int) $settings->get( 'database.revisions_keep', 5 ) ),
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
			$grouped[ $key ]['baseline'] = SafetyModule::is_baseline( $baseline, (string) $key );
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

	/**
	 * Forget every hero measurement. Cached pages no longer carry the
	 * measuring script, so they are cleared for it to come back.
	 */
	public function reset_lcp(): \WP_REST_Response {
		$removed = \RCRocket\Media\Lcp::reset();

		\RCRocket\Plugin::instance()->purge_everything( 'manual' );

		return rest_ensure_response( [ 'reset' => true, 'pages' => $removed ] );
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

		// Hand-tuned settings are no longer any preset.
		$before = $settings->all();

		$settings->merge( $payload );

		if ( $settings->all() !== $before ) {
			$settings->set( \RCRocket\Support\Presets::OPTION_PATH, 'custom' );
		}

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
			'url'     => \RCRocket\Plugin::instance()->purge_url( esc_url_raw( $value ) ),
			'key'     => $store->delete_by_key( sanitize_text_field( $value ) ),
			'expired' => $store->purge_expired(),
			default   => \RCRocket\Plugin::instance()->purge_everything( 'manual' ),
		};

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
		$allowed = Divi::refreshable_actions();
		$actions = array_intersect( array_map( 'sanitize_text_field', explode( ',', (string) $request->get_param( 'actions' ) ) ), $allowed );
		$out     = [];

		foreach ( array_slice( array_unique( $actions ), 0, 20 ) as $action ) {
			$out[ $action ] = wp_create_nonce( $action );
		}

		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	// ------------------------------------------------------ admin-bar links

	public function handle_purge_all(): void {
		check_admin_referer( 'rcrocket_purge_all' );

		if ( ! \RCRocket\Plugin::can_purge_all() ) {
			wp_die( esc_html__( 'You cannot clear the cache.', 'rc-rocket' ) );
		}

		\RCRocket\Plugin::instance()->purge_everything( 'manual' );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public function handle_purge_url(): void {
		check_admin_referer( 'rcrocket_purge_url' );

		if ( ! \RCRocket\Plugin::can_purge_page() ) {
			wp_die( esc_html__( 'You cannot clear the cache.', 'rc-rocket' ) );
		}

		$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_GET['url'] ) ) ) : '';

		// Only this site's pages: the link is built from the current request,
		// and anything else is someone editing the query string.
		if ( '' !== $url && wp_validate_redirect( $url, '' ) !== $url ) {
			$url = '';
		}

		if ( '' !== $url ) {
			\RCRocket\Plugin::instance()->purge_url( $url );
		}

		wp_safe_redirect( $url ?: ( wp_get_referer() ?: admin_url() ) );
		exit;
	}

	public function handle_purge_post(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		check_admin_referer( 'rcrocket_purge_post_' . $post_id );

		if ( ! $post_id || ! \RCRocket\Plugin::can_purge_page() || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You cannot clear the cache.', 'rc-rocket' ) );
		}

		\RCRocket\Plugin::instance()->purge_post( $post_id );

		wp_safe_redirect( add_query_arg( 'rcrocket_purged', $post_id, wp_get_referer() ?: admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * "Clear cache" under each published post in the list, next to "View".
	 *
	 * @param array<string, string> $actions
	 */
	public function row_action( array $actions, \WP_Post $post ): array {
		if ( 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return $actions;
		}

		if ( ! \RCRocket\Plugin::can_purge_page() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['rcrocket_purge'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						[
							'action' => 'rcrocket_purge_post',
							'post'   => $post->ID,
						],
						admin_url( 'admin-post.php' )
					),
					'rcrocket_purge_post_' . $post->ID
				)
			),
			esc_html__( 'Clear cache', 'rc-rocket' )
		);

		return $actions;
	}

	public function purged_notice(): void {
		$post_id = isset( $_GET['rcrocket_purged'] ) ? absint( $_GET['rcrocket_purged'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: post title */
					__( 'Cache cleared for "%s".', 'rc-rocket' ),
					get_the_title( $post_id )
				)
			)
		);
	}
}
