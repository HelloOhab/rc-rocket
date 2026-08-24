<?php
declare( strict_types=1 );

namespace RCRocket\Integrations;

use RCRocket\Support\Filesystem;
use RCRocket\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi integration.
 *
 * Divi has its own asset cache (wp-content/et-cache) that is *not* a page
 * cache, and a Visual Builder that must never see a cached response. A generic
 * caching plugin gets both of these wrong in the same two ways: it serves the
 * builder a stale page, and it leaves Divi's stale static CSS behind after a
 * purge so the site loads the old design from a new cache entry.
 *
 * Supports Divi 4 (shortcode engine, sunsetting) and Divi 5 (React engine,
 * stable since 26 February 2026), which behave differently enough that the
 * version must be branched on rather than assumed.
 */
final class Divi {

	/** Query args that mean "this is the builder, not the site". */
	private const BUILDER_ARGS = [
		'et_fb',
		'et_bfb',
		'et_pb_preview',
		'et_block_layout_preview',
		'et_theme_builder_preview',
		'PageSpeed',
		'vb',
	];

	/** Divi's own post types: layouts, not pages. */
	private const LAYOUT_TYPES = [
		'et_pb_layout',
		'et_template',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
		'et_theme_builder',
	];

	public function __construct( private Logger $logger, private array $config ) {}

	// ---------------------------------------------------------------- detect

	public function is_active(): bool {
		return defined( 'ET_CORE_VERSION' ) || defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' );
	}

	public function version(): ?string {
		// Memoized: is_divi_five() resolves through here, and the script
		// delayer asks it once per script tag. Without this, a page with forty
		// scripts performs forty theme lookups.
		static $resolved = false;
		static $version  = null;

		if ( $resolved ) {
			return $version;
		}

		$resolved = true;
		$version  = $this->resolve_version();

		return $version;
	}

	private function resolve_version(): ?string {
		if ( defined( 'ET_BUILDER_PRODUCT_VERSION' ) ) {
			return (string) ET_BUILDER_PRODUCT_VERSION;
		}

		$theme = wp_get_theme();

		if ( in_array( $theme->get( 'Name' ), [ 'Divi', 'Extra' ], true ) ) {
			return (string) $theme->get( 'Version' );
		}

		$parent = $theme->parent();

		if ( $parent && in_array( $parent->get( 'Name' ), [ 'Divi', 'Extra' ], true ) ) {
			return (string) $parent->get( 'Version' );
		}

		return null;
	}

	public function major(): int {
		$version = $this->version();

		return null === $version ? 0 : (int) explode( '.', $version )[0];
	}

	public function is_divi_five(): bool {
		return $this->major() >= 5;
	}

	/** Divi 5 generates its own critical CSS; ours would be redundant work. */
	public function handles_own_critical_css(): bool {
		return $this->is_divi_five();
	}

	public function et_cache_dir(): string {
		return defined( 'ET_CORE_CACHE_DIR' ) ? (string) ET_CORE_CACHE_DIR : WP_CONTENT_DIR . '/et-cache';
	}

	// ---------------------------------------------------------------- bypass

	/**
	 * Reasons a Divi site must never serve or store a cached response.
	 */
	public function bypass_reason(): ?string {
		foreach ( self::BUILDER_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return 'divi-builder';
			}
		}

		foreach ( array_keys( $_COOKIE ) as $cookie ) { // phpcs:ignore
			$cookie = (string) $cookie;

			// Divi Leads split testing needs a unique impression per visitor.
			if ( str_starts_with( $cookie, 'et_pb_ab_' ) || str_starts_with( $cookie, 'et_bfb_settings' ) ) {
				return 'divi-split-testing';
			}
		}

		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return 'divi-visual-builder';
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			if ( in_array( (string) get_post_type( $post_id ), self::LAYOUT_TYPES, true ) ) {
				return 'divi-layout-post-type';
			}

			// A page running a split test renders differently on every view.
			if ( 'on' === get_post_meta( $post_id, '_et_pb_use_ab_testing', true ) ) {
				return 'divi-ab-test';
			}
		}

		// Divi's Blog and Portfolio modules paginate over admin-ajax.
		if ( isset( $_GET['et_blog'] ) || isset( $_GET['et_load_builder_modules'] ) ) { // phpcs:ignore
			return 'divi-module-ajax';
		}

		return null;
	}

	// -------------------------------------------------------- surrogate keys

	/**
	 * Theme Builder templates are the single biggest cache-correctness gap on
	 * Divi sites: editing one global header should invalidate every page that
	 * renders it, and nothing on the market knows how to do that. Tagging each
	 * cached page with its template layout ids makes it a one-key purge.
	 *
	 * @return string[]
	 */
	public function surrogate_keys(): array {
		$keys = [ 'divi' ];

		if ( ! function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			return $keys;
		}

		$layouts = (array) et_theme_builder_get_template_layouts();

		foreach ( $layouts as $type => $layout ) {
			if ( ! is_array( $layout ) || empty( $layout['id'] ) ) {
				continue;
			}

			$keys[] = 'divi-tb-' . (int) $layout['id'];
			$keys[] = 'divi-tb-type-' . preg_replace( '/[^a-z_]/', '', (string) $type );
		}

		if ( ! empty( $layouts['template_id'] ) ) {
			$keys[] = 'divi-tb-template-' . (int) $layouts['template_id'];
		}

		return array_values( array_unique( $keys ) );
	}

	// ----------------------------------------------------------------- purge

	public function hooks(): void {
		if ( ! empty( $this->config['fix_viewport'] ) ) {
			// Divi emits user-scalable=no, which fails the Lighthouse
			// accessibility audit and stops people pinch-zooming on a phone.
			// Swap Divi's printer for ours rather than buffering wp_head — a
			// buffer left open by an early exit truncates the whole document.
			add_action(
				'wp_head',
				static function (): void {
					remove_action( 'wp_head', 'et_add_viewport_meta' );
					echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
				},
				0
			);
		}

		// Divi saved a layout: our cached HTML for it is now wrong.
		add_action( 'et_save_post', [ $this, 'on_layout_save' ], 10, 1 );
		add_action( 'et_builder_ajax_save_post', [ $this, 'on_layout_save' ], 10, 1 );

		foreach ( [ 'et_theme_builder_after_save_template', 'et_theme_builder_template_saved', 'et_theme_builder_after_save_layout' ] as $hook ) {
			add_action( $hook, [ $this, 'on_theme_builder_save' ], 10, 1 );
		}

		// Divi settings that regenerate global styles.
		foreach ( [ 'et_after_update_options', 'update_option_et_divi', 'et_core_page_resource_auto_clear' ] as $hook ) {
			add_action( $hook, static fn() => do_action( 'rc-rocket/purge/all' ) );
		}

		// Our purge should take Divi's stale static CSS with it.
		add_action( 'rc-rocket/cache/purged', [ $this, 'clear_et_cache' ], 10, 2 );

		// Runs last in the rewrite chain, after scripts have been delayed.
		add_filter( 'rc-rocket/html', [ $this, 'strip_nonces' ], 40, 1 );
	}

	public function on_layout_save( mixed $post_id ): void {
		$post_id = (int) ( is_array( $post_id ) ? ( $post_id['post_id'] ?? 0 ) : $post_id );

		if ( $post_id > 0 ) {
			do_action( 'rc-rocket/purge/key', 'post-' . $post_id );
		}

		// A Divi Library layout can be embedded anywhere; there is no cheap
		// way to know where, so this is the one case that warrants a full flush.
		if ( $post_id > 0 && 'et_pb_layout' === get_post_type( $post_id ) ) {
			do_action( 'rc-rocket/purge/all' );
		}
	}

	public function on_theme_builder_save( mixed $template ): void {
		$id = 0;

		if ( is_numeric( $template ) ) {
			$id = (int) $template;
		} elseif ( is_array( $template ) && isset( $template['id'] ) ) {
			$id = (int) $template['id'];
		}

		if ( $id > 0 ) {
			do_action( 'rc-rocket/purge/key', 'divi-tb-' . $id );
			do_action( 'rc-rocket/purge/key', 'divi-tb-template-' . $id );

			$this->logger->debug( 'Divi Theme Builder purge', [ 'template' => $id ] );

			return;
		}

		do_action( 'rc-rocket/purge/all' );
	}

	/**
	 * Remove Divi's generated static CSS so it is rebuilt alongside our pages.
	 * Skipping this is why "I cleared the cache and the old design is still
	 * there" is the most common Divi caching complaint.
	 */
	public function clear_et_cache( string $scope = 'all', int $entries = 0 ): void {
		if ( empty( $this->config['clear_divi_cache'] ) || 'all' !== $scope ) {
			return;
		}

		// Divi's own API invalidates its generated CSS and lets Divi rebuild it
		// on the next request. Use it and nothing else.
		//
		// Earlier versions also deleted the et-cache directory outright. That
		// is destructive on any site with a page cache in front of it: the
		// cached HTML still links to the stylesheet that was just unlinked, the
		// request 404s, and every Divi section background — which lives in that
		// generated CSS, not in the markup — stops rendering. On a page whose
		// hero is a background image or video, that is the LCP element gone.
		if ( class_exists( '\ET_Core_PageResource' ) && method_exists( '\ET_Core_PageResource', 'remove_static_resources' ) ) {
			\ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
		}

		if ( function_exists( 'et_core_clear_wp_cache' ) ) {
			et_core_clear_wp_cache();
		}

		if ( ! empty( $this->config['hard_clear_divi_cache'] ) ) {
			// Opt-in only, and only sane on a site with no page cache in front.
			Filesystem::delete_tree( $this->et_cache_dir() );

			$this->logger->debug( 'Hard-cleared Divi asset cache', [ 'dir' => $this->et_cache_dir() ] );

			return;
		}

		$this->logger->debug( 'Invalidated Divi static resources via Divi API' );
	}

	// --------------------------------------------------- background media map

	/**
	 * Pair every background video with the fallback image sitting next to it
	 * in the same section.
	 *
	 * On Divi 4 the section shortcode already carries both — the person who
	 * built the page chose a fallback image, Divi just never puts it on the
	 * video element as a poster. Reading it here means the poster is correct by
	 * default and nobody has to configure anything.
	 *
	 * @return array<string, string> Video file basename to fallback image URL.
	 */
	public function background_media_map(): array {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map     = [];
		$sources = [];

		if ( is_singular() ) {
			$sources[] = get_queried_object_id();
		}

		if ( function_exists( 'et_theme_builder_get_template_layouts' ) ) {
			foreach ( (array) et_theme_builder_get_template_layouts() as $layout ) {
				if ( is_array( $layout ) && ! empty( $layout['id'] ) && ! empty( $layout['enabled'] ) ) {
					$sources[] = (int) $layout['id'];
				}
			}
		}

		foreach ( array_unique( $sources ) as $post_id ) {
			$post = get_post( (int) $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$map += $this->parse_background_media( $post->post_content );
		}

		return $map;
	}

	/** @return array<string, string> */
	private function parse_background_media( string $content ): array {
		$map = [];

		// Every opening builder tag that can carry a background.
		if ( ! preg_match_all( '/\[et_pb_(?:section|row|column|fullwidth_header|slide)\b([^\]]*)\]/i', $content, $tags ) ) {
			return $map;
		}

		foreach ( $tags[1] as $attributes ) {
			$image = $this->shortcode_attribute( $attributes, 'background_image' );

			if ( '' === $image ) {
				continue;
			}

			foreach ( [ 'background_video_mp4', 'background_video_webm' ] as $key ) {
				$video = $this->shortcode_attribute( $attributes, $key );

				if ( '' === $video ) {
					continue;
				}

				$map[ self::media_key( $video ) ] = $image;
			}
		}

		return $map;
	}

	private function shortcode_attribute( string $attributes, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*["\']([^"\']+)["\']/i', $attributes, $m ) ) {
			return html_entity_decode( trim( $m[1] ) );
		}

		return '';
	}

	/**
	 * Divi appends cache-busting query args and rewrites hosts on migration,
	 * so videos are matched on filename rather than on the full URL.
	 */
	public static function media_key( string $url ): string {
		$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: $url );

		return strtolower( basename( $path ) );
	}

	// ------------------------------------------------------ nonce hydration

	/**
	 * Divi contact and optin forms embed a nonce in the markup. Cache that HTML
	 * for longer than the 12-hour nonce lifetime and every submission fails
	 * validation — silently, with the form just resetting. Stripping the nonce
	 * at write time and fetching a fresh one on load decouples cache TTL from
	 * nonce TTL, which is what lets a Divi site cache for days instead of hours.
	 */
	public function strip_nonces( string $html ): string {
		if ( empty( $this->config['refresh_form_nonces'] ) || ( ! str_contains( $html, 'et_pb_contact_form' ) && ! str_contains( $html, 'et_pb_signup' ) ) ) {
			return $html;
		}

		$pattern = '/<input\s+type=["\']hidden["\']\s+id=["\']([^"\']*(?:_wpnonce|et_pb_[a-z_]*nonce)[^"\']*)["\']\s+name=["\']([^"\']+)["\']\s+value=["\']([^"\']*)["\']\s*\/?>/i';

		$replaced = preg_replace_callback(
			$pattern,
			static function ( array $m ): string {
				return sprintf(
					'<input type="hidden" id="%1$s" name="%2$s" value="" data-rcr-nonce="%3$s" />',
					esc_attr( $m[1] ),
					esc_attr( $m[2] ),
					esc_attr( self::nonce_action_for( $m[2] ) )
				);
			},
			$html
		);

		if ( ! is_string( $replaced ) || $replaced === $html ) {
			return $html;
		}

		return str_replace( '</body>', $this->hydration_script() . '</body>', $replaced );
	}

	private static function nonce_action_for( string $field_name ): string {
		// Divi names the field after the action it verifies.
		return str_replace( '_wpnonce-', '', $field_name );
	}

	private function hydration_script(): string {
		$endpoint = esc_url_raw( rest_url( 'rc-rocket/v1/nonces' ) );

		return <<<HTML
<script id="rcr-divi-nonces">
(function () {
  var fields = document.querySelectorAll('input[data-rcr-nonce]');
  if (!fields.length) return;
  var actions = [].map.call(fields, function (f) { return f.dataset.rcrNonce; });
  fetch('{$endpoint}?actions=' + encodeURIComponent(actions.join(',')), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      [].forEach.call(fields, function (f) {
        if (data[f.dataset.rcrNonce]) f.value = data[f.dataset.rcrNonce];
      });
    })
    .catch(function () { /* Form falls back to a normal reload-and-retry. */ });
})();
</script>
HTML;
	}

	// -------------------------------------------------------------- reporting

	/** Surfaced in the admin so the user can see what we detected. */
	public function report(): array {
		return [
			'active'              => $this->is_active(),
			'version'             => $this->version(),
			'major'               => $this->major(),
			'engine'              => $this->is_divi_five() ? 'React (Divi 5)' : 'Shortcode (Divi 4)',
			'own_critical_css'    => $this->handles_own_critical_css(),
			'theme_builder'       => function_exists( 'et_theme_builder_get_template_layouts' ),
			'et_cache_dir'        => $this->et_cache_dir(),
			'et_cache_writable'   => is_dir( $this->et_cache_dir() ) ? is_writable( $this->et_cache_dir() ) : is_writable( WP_CONTENT_DIR ),
			'static_css_enabled'  => 'on' === get_option( 'et_pb_static_css_file', 'on' ),
		];
	}
}
