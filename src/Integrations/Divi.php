<?php
declare( strict_types=1 );

namespace RCRocket\Integrations;

use RCRocket\Frontend\HtmlPipeline;
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

	/** @var array<string, string>|null Per-request cache of background_media_map(). */
	private ?array $background_media = null;

	/** Set when this response was rendered while Divi was rebuilding its CSS. */
	private bool $cold_render = false;

	public function __construct( private Logger $logger, private array $config ) {}

	// ---------------------------------------------------------------- detect

	public function is_active(): bool {
		return defined( 'ET_CORE_VERSION' ) || defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_theme' );
	}

	/**
	 * Run $setup once it is known whether Divi is there, and only if it is.
	 *
	 * Modules boot at plugins_loaded, and Divi is a theme: WordPress loads
	 * the theme after every plugin, so is_active() is false at that point on
	 * every real site (the Divi Builder plugin aside). Asking then switched
	 * off everything Divi-specific without a trace.
	 */
	public function when_active( callable $setup ): void {
		if ( $this->is_active() ) {
			$setup();

			return;
		}

		if ( did_action( 'after_setup_theme' ) ) {
			return;
		}

		add_action(
			'after_setup_theme',
			function () use ( $setup ): void {
				if ( $this->is_active() ) {
					$setup();
				}
			},
			0
		);
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

	/**
	 * A Visual Builder, Theme Builder or preview request. Answerable from the
	 * URL alone, so it works at plugins_loaded, before the query exists.
	 */
	public static function is_builder_request(): bool {
		foreach ( [ 'et_fb', 'et_bfb', 'et_pb_preview', 'et_theme_builder_preview', 'et_tb', 'vb' ] as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return true;
			}
		}

		return function_exists( 'et_core_is_fb_enabled' ) && did_action( 'wp' ) && et_core_is_fb_enabled();
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
		if ( $this->cold_render ) {
			return 'divi-css-rebuilding';
		}

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

		// Divi Theme Options saved: global styles changed on every page. The
		// raw et_divi option is deliberately not watched — Divi writes it for
		// bookkeeping far more often than anyone changes a design.
		add_action( 'et_after_update_options', static fn() => do_action( 'rc-rocket/purge/all', 'divi-options' ) );

		// The hooks above are not fired by Divi 4.27 or Divi 5 (checked in
		// their source). What does happen: Theme Builder templates and their
		// header/body/footer layouts are posts that get saved, and Theme
		// Options fire et_epanel_changing_options. A global header or footer
		// renders on every page, so its edit clears everything.
		foreach ( [ 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_theme_builder' ] as $post_type ) {
			add_action( 'save_post_' . $post_type, [ $this, 'on_theme_builder_post' ], 10, 1 );
		}

		add_action( 'et_epanel_changing_options', static fn() => do_action( 'rc-rocket/purge/all', 'divi-options' ) );
		add_action( 'et_core_page_resource_auto_clear', static fn() => do_action( 'rc-rocket/purge/all', 'divi-static-css' ) );

		// Our purge should take Divi's stale static CSS with it.
		add_action( 'rc-rocket/cache/purged', [ $this, 'clear_et_cache' ], 10, 2 );

		// Runs first: decides whether this response may be cached at all.
		add_filter( 'rc-rocket/html', [ $this, 'guard_cold_css' ], 1, 1 );

		// Runs last in the rewrite chain, after scripts have been delayed.
		add_filter( 'rc-rocket/html', [ $this, 'strip_nonces' ], 40, 1 );
	}

	// ------------------------------------------------------ cold CSS renders

	/**
	 * Keep a page Divi rendered mid-rebuild out of every page cache.
	 *
	 * With Critical CSS on, Divi splits a page's styles in two: the above-the-
	 * fold part inlined as `<style id="et-critical-inline-css">`, the rest in
	 * an `et-core-unified-…-deferred-….min.css` file loaded late. Both are
	 * generated on the first request after Divi's CSS was cleared — which
	 * happens on every plugin update, theme option save and cache purge.
	 * That first request gets neither: no critical block, and the deferred
	 * styles printed inline as `…-deferred-…-cached-inline-styles`, so the
	 * page paints unstyled and then jumps (CLS 0.2–0.7 measured on three
	 * Divi 4 and Divi 5 sites). The very next request is fine.
	 *
	 * The page itself is still served — it is only this one copy that must not
	 * be stored, or the host serves it to everyone for hours. Kinsta, RC
	 * Rocket's own cache and every other page cache honour no-cache headers.
	 */
	public function guard_cold_css( string $html ): string {
		if ( ! self::is_cold_render( $html ) ) {
			return $html;
		}

		$this->cold_render = true;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
			header( 'X-RC-Rocket-Divi: css-rebuilding' );
		}

		$this->logger->debug( 'Divi CSS was rebuilding: this response is not cached' );

		return $html;
	}

	/**
	 * A page that uses Divi's deferred stylesheet but has no critical CSS
	 * block was rendered before Divi finished generating its CSS.
	 *
	 * Pages without the deferred stylesheet (Critical CSS off, archives,
	 * non-Divi templates) never match, so they keep being cached as before.
	 */
	public static function is_cold_render( string $html ): bool {
		// The deferred styles are a preloaded <link> on a good render and an
		// inline <style> on a cold one, which Divi may print in the footer.
		if ( ! preg_match( '/et-core-unified-[a-z0-9-]*deferred/i', $html ) ) {
			return false;
		}

		return ! preg_match( '/<style[^>]+id=["\']et-critical-inline-css["\']/i', $html );
	}

	public function rendered_cold(): bool {
		return $this->cold_render;
	}

	public function on_layout_save( mixed $post_id ): void {
		$post_id = (int) ( is_array( $post_id ) ? ( $post_id['post_id'] ?? 0 ) : $post_id );

		if ( $post_id > 0 ) {
			do_action( 'rc-rocket/purge/key', 'post-' . $post_id );
		}

		// A Divi Library layout can be embedded anywhere; there is no cheap
		// way to know where, so this is the one case that warrants a full flush.
		if ( $post_id > 0 && 'et_pb_layout' === get_post_type( $post_id ) ) {
			do_action( 'rc-rocket/purge/all', 'divi-library' );
		}
	}

	public function on_theme_builder_post( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		do_action( 'rc-rocket/purge/all', 'divi-theme-builder' );
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

		do_action( 'rc-rocket/purge/all', 'divi-theme-builder' );
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
	 * On Divi 4 the section shortcode already carries both, and on Divi 5 the
	 * section block's JSON does — the person who
	 * built the page chose a fallback image, Divi just never puts it on the
	 * video element as a poster. Reading it here means the poster is correct by
	 * default and nobody has to configure anything.
	 *
	 * @return array<string, string> Video file basename to fallback image URL.
	 */
	public function background_media_map(): array {
		if ( null !== $this->background_media ) {
			return $this->background_media;
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

		return $this->background_media = $map;
	}

	/** @return array<string, string> */
	private function parse_background_media( string $content ): array {
		// Divi 5 saves layouts as block markup; Divi 4 as shortcodes. A site
		// mid-migration can hold both, so both are read.
		$map = $this->parse_divi5_background_media( $content );

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

	/**
	 * Divi 5 keeps a module's background in the block comment's JSON:
	 *
	 *     <!-- wp:divi/section {"module":{"decoration":{"background":{
	 *         "desktop":{"value":{"video":{"mp4":"…"},"image":{"url":"…"}}},
	 *         "phone":{"value":{"video":{"mp4":""}}}}}}} -->
	 *
	 * Each breakpoint inherits from desktop, so a video set on one breakpoint
	 * is paired with that breakpoint's image, else the desktop image.
	 *
	 * @return array<string, string>
	 */
	private function parse_divi5_background_media( string $content ): array {
		$map = [];

		if ( ! str_contains( $content, '<!-- wp:divi/' ) ) {
			return $map;
		}

		// WordPress escapes "--" inside block JSON, so the first " -->" after
		// the opening brace always closes the comment.
		if ( ! preg_match_all( '#<!--\s+wp:divi/[a-z0-9-]+\s+(\{.*?\})\s+/?-->#s', $content, $blocks ) ) {
			return $map;
		}

		foreach ( $blocks[1] as $json ) {
			if ( ! str_contains( $json, '"video"' ) ) {
				continue;
			}

			$attributes = json_decode( $json, true );
			$background = is_array( $attributes ) ? ( $attributes['module']['decoration']['background'] ?? null ) : null;

			if ( ! is_array( $background ) ) {
				continue;
			}

			$desktop_image = self::divi5_value( $background, 'desktop', [ 'image', 'url' ] );

			foreach ( $background as $breakpoint => $state ) {
				if ( ! is_string( $breakpoint ) || ! is_array( $state ) ) {
					continue;
				}

				$image = self::divi5_value( $background, $breakpoint, [ 'image', 'url' ] );
				$image = '' !== $image ? $image : $desktop_image;

				if ( '' === $image ) {
					continue;
				}

				foreach ( [ 'mp4', 'webm' ] as $format ) {
					$video = self::divi5_value( $background, $breakpoint, [ 'video', $format ] );

					if ( '' !== $video && ! isset( $map[ self::media_key( $video ) ] ) ) {
						$map[ self::media_key( $video ) ] = $image;
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Read one value from a Divi 5 breakpoint, e.g. desktop → value → image → url.
	 * Divi 5 also nests a "hover"/"sticky" state beside "value"; only the
	 * resting state paints first, so only "value" is read.
	 *
	 * @param array<mixed>  $background
	 * @param array<string> $path
	 */
	private static function divi5_value( array $background, string $breakpoint, array $path ): string {
		$node = $background[ $breakpoint ]['value'] ?? null;

		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return '';
			}

			$node = $node[ $key ];
		}

		return is_string( $node ) ? html_entity_decode( trim( $node ) ) : '';
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

		// A logged-in visitor's nonces belong to their session; the endpoint
		// can only mint anonymous ones.
		if ( is_user_logged_in() ) {
			return $html;
		}

		$map     = self::nonce_actions();
		$pattern = '/<input\s+type=["\']hidden["\']\s+id=["\']([^"\']+)["\']\s+name=["\']([^"\']+)["\']\s+value=["\']([^"\']*)["\']\s*\/?>/i';

		$replaced = preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $map ): string {
				$action = self::nonce_action_for( $m[2], $map );

				// A field whose action we cannot name is left exactly as it
				// is. Blanking it would break the form outright, which is
				// worse than a nonce that expires.
				if ( null === $action ) {
					return $m[0];
				}

				return sprintf(
					'<input type="hidden" id="%1$s" name="%2$s" value="%3$s" data-rcr-nonce="%4$s" />',
					esc_attr( $m[1] ),
					esc_attr( $m[2] ),
					esc_attr( $m[3] ),
					esc_attr( $action )
				);
			},
			$html
		);

		if ( ! is_string( $replaced ) ) {
			return $html;
		}

		// The optin module reads its nonce from Divi's localized script data
		// rather than a field, so it needs refreshing too.
		$signup = str_contains( $html, 'et_pb_signup' ) && str_contains( $html, 'et_frontend_nonce' );

		if ( $replaced === $html && ! $signup ) {
			return $html;
		}

		return HtmlPipeline::before_body_end( $replaced, $this->hydration_script( $signup ) );
	}

	/**
	 * Field name prefix to the nonce action Divi verifies it against. Divi
	 * names its contact form fields after the form, not after the action, so
	 * the action cannot be derived from the name — it has to be known.
	 *
	 * @return array<string, string>
	 */
	public static function nonce_actions(): array {
		/** @param array<string, string> $map */
		return (array) apply_filters(
			'rc-rocket/divi/nonce_actions',
			[
				'_wpnonce-et-pb-contact-form-submitted' => 'et-pb-contact-form-submit',
			]
		);
	}

	/**
	 * Every action the public nonce endpoint may sign. Anything else is
	 * refused, so the endpoint cannot be used to mint nonces for arbitrary
	 * plugin actions.
	 *
	 * @return string[]
	 */
	public static function refreshable_actions(): array {
		return array_values( array_unique( array_merge( array_values( self::nonce_actions() ), [ 'et_frontend_nonce' ] ) ) );
	}

	/** @param array<string, string> $map */
	private static function nonce_action_for( string $field_name, array $map ): ?string {
		foreach ( $map as $prefix => $action ) {
			if ( str_starts_with( $field_name, $prefix ) ) {
				return $action;
			}
		}

		return null;
	}

	private function hydration_script( bool $signup ): string {
		$endpoint = esc_url_raw( rest_url( 'rc-rocket/v1/nonces' ) );
		$signup   = $signup ? 'true' : 'false';

		return <<<HTML
<script id="rcr-divi-nonces">
(function () {
  var fields = document.querySelectorAll('input[data-rcr-nonce]');
  var SIGNUP = {$signup} && window.et_pb_custom;
  if (!fields.length && !SIGNUP) return;
  var actions = [].map.call(fields, function (f) { return f.dataset.rcrNonce; });
  if (SIGNUP) actions.push('et_frontend_nonce');
  fetch('{$endpoint}?actions=' + encodeURIComponent(actions.join(',')), { credentials: 'omit' })
    .then(function (r) { return r.ok ? r.json() : {}; })
    .then(function (data) {
      [].forEach.call(fields, function (f) {
        if (data[f.dataset.rcrNonce]) f.value = data[f.dataset.rcrNonce];
      });
      if (SIGNUP && data.et_frontend_nonce) window.et_pb_custom.et_frontend_nonce = data.et_frontend_nonce;
    })
    .catch(function () { /* The rendered nonce stays; it is valid for a day. */ });
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
