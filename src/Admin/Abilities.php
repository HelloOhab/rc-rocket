<?php
declare( strict_types=1 );

namespace RCRocket\Admin;

use RCRocket\Container;
use RCRocket\Media\Lcp;
use RCRocket\Plugin;
use RCRocket\Support\PageOptions;
use RCRocket\Support\Presets;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC Rocket for AI assistants: WordPress Abilities (WordPress 6.9+).
 *
 * Every ability is also an MCP tool once the MCP Adapter plugin is active,
 * so Claude, ChatGPT or Cursor can ask "is the cache working?", read the
 * system check, clear one page or apply a preset. Reading abilities are
 * marked read-only; abilities that change something are marked so, and the
 * one that deletes data is marked destructive, which is what MCP clients
 * use to ask the person before running them.
 *
 * Permissions are the same as in the admin: settings, presets, safe mode
 * and cleanup need manage_options; clearing one page is open to editors.
 * An assistant can never do more than the user it signs in as.
 */
final class Abilities {

	public const CATEGORY = 'rc-rocket';

	/** Lists of structured entries: edited in the admin, where they have a form. */
	private const OBJECT_LISTS = [ 'assets.rules', 'media.hero_preloads', 'media.video.posters', 'media.video.embed_posters' ];

	public function __construct( private Container $container, private RestController $rest ) {}

	public function hooks(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register' ] );
	}

	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'RC Rocket', 'rc-rocket' ),
				'description' => __( 'Page cache, speed optimizations and diagnostics for Divi sites.', 'rc-rocket' ),
			]
		);
	}

	public function register(): void {
		$manage = static fn(): bool => current_user_can( 'manage_options' );

		// --------------------------------------------------------- reading

		$this->ability(
			'get-status',
			__( 'Get RC Rocket status', 'rc-rocket' ),
			__( 'A summary of RC Rocket on this site: version, host and who serves the page cache, the active preset, which optimizations are on, safe mode, cached page count, recent front-end errors and how many pages have a measured hero image. Start here before changing anything.', 'rc-rocket' ),
			null,
			fn() => $this->status(),
			$manage,
			[ 'readonly' => true ]
		);

		$this->ability(
			'run-system-check',
			__( 'Run the system check', 'rc-rocket' ),
			__( 'Runs RC Rocket\'s health checks (cache loader, host cache, file permissions, Divi, scheduled tasks) and returns each check with its result and advice.', 'rc-rocket' ),
			null,
			fn() => ( new SelfTest( $this->container ) )->run(),
			$manage,
			[ 'readonly' => true ]
		);

		$this->ability(
			'get-errors',
			__( 'Get front-end JavaScript errors', 'rc-rocket' ),
			__( 'JavaScript errors visitors\' browsers reported, grouped by problem with a count, the pages it happened on, and whether it counts toward the automatic rollback. Use it to find out whether an optimization broke something.', 'rc-rocket' ),
			null,
			fn() => [ 'errors' => array_slice( (array) ( $this->rest->get_errors()->get_data()['errors'] ?? [] ), 0, 25 ) ],
			$manage,
			[ 'readonly' => true ]
		);

		$this->ability(
			'get-settings',
			__( 'Get RC Rocket settings', 'rc-rocket' ),
			__( 'Current settings, or one section of them (cache, media, js, assets, preload, database, safety, general).', 'rc-rocket' ),
			[
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'section' => [
						'type'        => 'string',
						'description' => __( 'Only this section. Omit for everything.', 'rc-rocket' ),
					],
				],
			],
			function ( $input ): array {
				$all     = $this->settings()->all();
				$section = (string) ( $input['section'] ?? '' );

				return '' === $section ? $all : [ $section => $all[ $section ] ?? null ];
			},
			$manage,
			[ 'readonly' => true ]
		);

		$this->ability(
			'get-hero-measurements',
			__( 'Get measured hero images', 'rc-rocket' ),
			__( 'For each measured page, the element that is its Largest Contentful Paint on phones and on computers (an image, a Divi background, a video poster or text) and its URL. RC Rocket loads these first.', 'rc-rocket' ),
			null,
			static fn() => [ 'pages' => Lcp::listing() ],
			$manage,
			[ 'readonly' => true ]
		);

		$this->ability(
			'get-page-options',
			__( 'Get a page\'s RC Rocket options', 'rc-rocket' ),
			__( 'Whether one post or page is excluded from the cache, and which optimizations are switched off for it alone.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				],
			],
			static function ( $input ): array {
				$options = PageOptions::for_post( (int) $input['post_id'] );

				return [
					'post_id'     => (int) $input['post_id'],
					'never_cache' => $options['never_cache'],
					'disabled'    => $options['off'],
					'available'   => array_keys( PageOptions::features() ),
				];
			},
			static fn( $input ): bool => current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ),
			[ 'readonly' => true ]
		);

		// -------------------------------------------------------- changing

		$this->ability(
			'clear-cache',
			__( 'Clear the cache', 'rc-rocket' ),
			__( 'Clears cached pages: the whole site, one URL, or one post. On Kinsta it clears Kinsta\'s cache. Pages are warmed again afterwards. Use after content changes that do not show up.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'default'    => [ 'scope' => 'all' ],
				'properties' => [
					'scope'   => [
						'type'    => 'string',
						'enum'    => [ 'all', 'url', 'post' ],
						'default' => 'all',
					],
					'url'     => [
						'type'        => 'string',
						'description' => __( 'For scope "url": a URL on this site.', 'rc-rocket' ),
					],
					'post_id' => [
						'type'        => 'integer',
						'description' => __( 'For scope "post".', 'rc-rocket' ),
					],
				],
			],
			fn( $input ) => $this->clear_cache( (array) $input ),
			static function ( $input ): bool {
				$scope = (string) ( $input['scope'] ?? 'all' );

				if ( 'all' === $scope ) {
					return Plugin::can_purge_all();
				}

				if ( 'post' === $scope ) {
					return Plugin::can_purge_page() && current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) );
				}

				return Plugin::can_purge_page();
			},
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'apply-preset',
			__( 'Apply an optimization preset', 'rc-rocket' ),
			__( 'Switches the speed options to a preset: "safe" (nothing that can change how a page behaves), "recommended" (the default), or "maximum". Exclusions and other typed-in settings are kept, and the change can be rolled back from the settings history.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'required'   => [ 'preset' ],
				'properties' => [
					'preset' => [
						'type' => 'string',
						'enum' => array_keys( Presets::catalogue() ),
					],
				],
			],
			function ( $input ) {
				$name = (string) $input['preset'];

				if ( ! Presets::apply( $this->settings(), $name ) ) {
					return new \WP_Error( 'rcrocket_unknown_preset', __( 'Unknown preset.', 'rc-rocket' ) );
				}

				return [ 'applied' => $name ];
			},
			$manage,
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'update-settings',
			__( 'Change RC Rocket settings', 'rc-rocket' ),
			__( 'Changes individual settings by dotted path, for example {"js.delay": false, "media.skip_first": 3}. Only existing settings can be changed, and each value must have the same type as the current one. Read the settings first. The change is recorded in the settings history and can be rolled back there.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'required'   => [ 'changes' ],
				'properties' => [
					'changes' => [
						'type'                 => 'object',
						'description'          => __( 'Setting path => new value.', 'rc-rocket' ),
						'additionalProperties' => true,
					],
				],
			],
			fn( $input ) => $this->update_settings( (array) ( $input['changes'] ?? [] ) ),
			$manage,
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'set-page-options',
			__( 'Set a page\'s RC Rocket options', 'rc-rocket' ),
			__( 'Excludes one post or page from the cache, or switches individual optimizations off for it alone, without touching the rest of the site. Use get-page-options to see the names of the optimizations.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'required'   => [ 'post_id' ],
				'properties' => [
					'post_id'     => [ 'type' => 'integer', 'minimum' => 1 ],
					'never_cache' => [ 'type' => 'boolean' ],
					'disabled'    => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => array_keys( PageOptions::features() ),
						],
						'description' => __( 'The optimizations to switch off on this page. Everything not listed is on.', 'rc-rocket' ),
					],
				],
			],
			static function ( $input ): array {
				$post_id = (int) $input['post_id'];
				$current = PageOptions::for_post( $post_id );
				$never   = array_key_exists( 'never_cache', $input ) ? (bool) $input['never_cache'] : $current['never_cache'];
				$off     = array_key_exists( 'disabled', $input ) ? (array) $input['disabled'] : $current['off'];

				PageOptions::save( $post_id, $never, $off );
				Plugin::instance()->purge_post( $post_id );

				return [ 'post_id' => $post_id ] + PageOptions::for_post( $post_id );
			},
			static fn( $input ): bool => current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) ),
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'set-safe-mode',
			__( 'Switch safe mode on or off', 'rc-rocket' ),
			__( 'Safe mode stops every optimization (the page cache keeps working) and clears cached pages. Switch it on to check whether RC Rocket causes a problem; switching it off also ends an automatic rollback.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'required'   => [ 'enabled' ],
				'properties' => [
					'enabled' => [ 'type' => 'boolean' ],
				],
			],
			function ( $input ): array {
				$settings = $this->settings();
				$enabled  = (bool) $input['enabled'];

				$settings->set( 'general.safe_mode', $enabled );
				$settings->save();

				if ( ! $enabled ) {
					$this->container->get( 'safe_mode' )->release();
				}

				return [ 'safe_mode' => $enabled ];
			},
			$manage,
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'remeasure-heroes',
			__( 'Measure hero images again', 'rc-rocket' ),
			__( 'Forgets every measured hero image and clears the cache, so each page is measured again as visitors arrive. Use after a redesign.', 'rc-rocket' ),
			null,
			fn() => $this->rest->reset_lcp()->get_data(),
			$manage,
			[
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			]
		);

		$this->ability(
			'clean-database',
			__( 'Clean up the database', 'rc-rocket' ),
			__( 'Permanently deletes old revisions, auto-drafts, spam and trashed comments, and expired transients; optionally trashed posts and table optimization. Without "items" it cleans what is ticked in the settings. This cannot be undone: ask the person first.', 'rc-rocket' ),
			[
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'items' => [
						'type'  => 'array',
						'items' => [
							'type' => 'string',
							'enum' => [ 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'optimize_tables' ],
						],
					],
				],
			],
			function ( $input ): array {
				$settings = $this->settings();
				$database = $this->container->get( 'database' );
				$only     = isset( $input['items'] ) ? array_map( 'strval', (array) $input['items'] ) : null;

				return [
					'removed' => $database->run( (array) $settings->get( 'database', [] ), $this->container->get( 'logger' ), $only ),
				];
			},
			$manage,
			[
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			]
		);
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param array<string, mixed>|null $input_schema
	 * @param array<string, bool>       $annotations
	 */
	private function ability( string $name, string $label, string $description, ?array $input_schema, callable $execute, callable $permission, array $annotations ): void {
		$args = [
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'execute_callback'    => $execute,
			'permission_callback' => $permission,
			'meta'                => [
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				// The MCP Adapter exposes abilities marked public as tools.
				'mcp'          => [
					'public' => true,
					'type'   => 'tool',
				],
			],
		];

		if ( null !== $input_schema ) {
			$args['input_schema'] = $input_schema;
		}

		wp_register_ability( 'rc-rocket/' . $name, $args );
	}

	private function settings(): Settings {
		return $this->container->get( 'settings' );
	}

	private function status(): array {
		$settings = $this->settings();
		$hosting  = $this->container->get( 'hosting' );
		$safe     = $this->container->get( 'safe_mode' );
		$divi     = $this->container->get( 'divi' );
		$managed  = $hosting->manages_page_cache();
		$errors   = (array) ( $this->rest->get_errors()->get_data()['errors'] ?? [] );

		$features = [];

		foreach ( [ 'cache.enabled', 'js.defer', 'js.delay', 'js.lazy_render', 'media.lazy_load', 'media.lazy_iframes', 'media.lazy_backgrounds', 'media.lcp_detect', 'media.video.enabled', 'assets.fonts.localize', 'assets.divi.unload_modules', 'preload.links', 'database.enabled', 'safety.auto_safe_mode' ] as $path ) {
			$features[ $path ] = $settings->enabled( $path );
		}

		return [
			'version'          => \RCRocket\VERSION,
			'host'             => $hosting->report(),
			'page_cache'       => $managed ? 'host' : 'rc-rocket',
			'cached_files'     => $managed ? null : ( $this->container->get( 'cache.store' )->stats()['files'] ?? null ),
			'preset'           => (string) $settings->get( Presets::OPTION_PATH, 'custom' ),
			'features'         => $features,
			'safe_mode'        => [
				'active' => $safe->is_active(),
				'reason' => $safe->reason(),
			],
			'divi'             => $divi->report(),
			'front_end_errors' => count( $errors ),
			'measured_heroes'  => count( Lcp::listing() ),
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	private function clear_cache( array $input ) {
		$plugin = Plugin::instance();
		$scope  = (string) ( $input['scope'] ?? 'all' );

		if ( 'url' === $scope ) {
			$url = esc_url_raw( (string) ( $input['url'] ?? '' ) );

			if ( '' === $url || wp_validate_redirect( $url, '' ) !== $url ) {
				return new \WP_Error( 'rcrocket_bad_url', __( 'Give a URL on this site.', 'rc-rocket' ) );
			}

			$plugin->purge_url( $url );

			return [ 'cleared' => $url ];
		}

		if ( 'post' === $scope ) {
			$post_id = (int) ( $input['post_id'] ?? 0 );

			if ( ! get_post( $post_id ) ) {
				return new \WP_Error( 'rcrocket_bad_post', __( 'No such post.', 'rc-rocket' ) );
			}

			$plugin->purge_post( $post_id );

			return [ 'cleared' => get_permalink( $post_id ) ];
		}

		$plugin->purge_everything( 'manual' );

		return [ 'cleared' => 'all' ];
	}

	/**
	 * Apply dotted-path changes, refusing anything that is not an existing
	 * setting or does not keep the setting's type. An assistant that guesses
	 * a name must get told so, not silently create a setting nobody reads.
	 *
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function update_settings( array $changes ): array {
		$settings = $this->settings();
		$applied  = [];
		$rejected = [];

		foreach ( $changes as $path => $value ) {
			$path    = (string) $path;
			$current = $settings->get( $path, null );

			if ( null === $current || str_starts_with( $path, 'general.schema_version' ) || Presets::OPTION_PATH === $path ) {
				$rejected[ $path ] = 'unknown setting';
				continue;
			}

			foreach ( self::OBJECT_LISTS as $list ) {
				if ( $path === $list || str_starts_with( $path, $list . '.' ) ) {
					$rejected[ $path ] = 'edit this in the RC Rocket admin';
					continue 2;
				}
			}

			$value = self::coerce( $current, $value );

			if ( null === $value ) {
				$rejected[ $path ] = 'expected ' . gettype( $current );
				continue;
			}

			$settings->set( $path, $value );
			$applied[ $path ] = $value;
		}

		if ( $applied ) {
			$settings->set( Presets::OPTION_PATH, 'custom' );
			$settings->save();
		}

		return [
			'applied'  => $applied,
			'rejected' => $rejected,
		];
	}

	/** The new value in the current value's type, or null if it cannot be. */
	private static function coerce( mixed $current, mixed $value ): mixed {
		if ( is_bool( $current ) ) {
			return is_bool( $value ) ? $value : null;
		}

		if ( is_int( $current ) ) {
			return is_numeric( $value ) ? (int) $value : null;
		}

		if ( is_float( $current ) ) {
			return is_numeric( $value ) ? (float) $value : null;
		}

		if ( is_string( $current ) ) {
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
		}

		if ( is_array( $current ) ) {
			// Lists of strings (exclusions, URLs); nested objects are edited
			// by path, one leaf at a time.
			if ( ! is_array( $value ) || ( $current && ( ! array_is_list( $current ) || ! is_scalar( reset( $current ) ) ) ) ) {
				return null;
			}

			return array_values( array_map( static fn( $v ): string => sanitize_text_field( (string) $v ), array_filter( $value, 'is_scalar' ) ) );
		}

		return null;
	}
}
