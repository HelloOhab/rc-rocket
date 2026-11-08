<?php
/**
 * Remove unused CSS: each page gets only the rules it uses, inlined, and the
 * full stylesheets wait for the visitor's first interaction.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

namespace RCRocket\Assets;

use RCRocket\Cache\Key;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Integrations\Divi;
use RCRocket\Support\Filesystem;
use RCRocket\Support\Logger;
use RCRocket\Support\PageOptions;
use RCRocket\Support\SafeMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How a page gets its used CSS:
 *
 * 1. A visitor opens the page. Nothing is known about it yet, so it is
 *    served as usual and queued.
 * 2. A background job loads the page once more, with this feature skipped
 *    (?rcr_ucss=1), reads each of the site's own stylesheets from disk and
 *    keeps the rules the page can use (see CssPurger). The page's own
 *    JavaScript files count as part of the page, so classes a script adds
 *    later survive.
 * 3. From then on each of those <link> tags is replaced, where it stood, by
 *    a <style> with its used rules, so the cascade order is unchanged. The
 *    <link> stays, without its href, and the full file loads on the first
 *    scroll, tap or keypress. Nothing that only the full file styles can
 *    stay broken for longer than that.
 *
 * A page whose stylesheets change (a plugin update, Divi rebuilding its
 * files) no longer matches its stored signature and is built again. Saving
 * the post, clearing the cache, and menu, widget, theme and plugin changes
 * throw the stored CSS away.
 *
 * Measured on the beta sites (Divi 4 and 5 home pages, throttled phone):
 * first paint 1.75 s -> 0.34 s and 1.58 s -> 0.99 s, 58-82% of the CSS
 * removed, pages pixel-identical before and after interaction and with the
 * mobile menu open.
 */
final class UsedCss {

	public const HOOK     = 'rc-rocket/unused-css/build';
	public const SKIP_ARG = 'rcr_ucss';

	private const QUEUE      = 'rcrocket_ucss_queue';
	private const INDEX      = 'rcrocket_ucss_index';
	private const MAX_QUEUE  = 100;
	private const BATCH      = 4;
	private const MAX_INLINE = 450 * 1024;
	private const MAX_JS     = 4 * 1024 * 1024;
	private const RETRY      = HOUR_IN_SECONDS;
	private const MAX_AGE    = 10 * DAY_IN_SECONDS;

	/**
	 * Classes scripts add to the page that its own files may not spell out
	 * in a form the scan recognises. A selector containing any of these is
	 * always kept.
	 */
	public const SAFELIST = [
		'et-animated', 'et_animated', 'et-waypoint', 'et_pb_animation_', 'et-pb-active-slide', 'et_pb_active_content',
		'et_pb_tab_active', 'et_pb_toggle_open', 'et_pb_toggle_close', 'et_mobile_menu', 'mobile_nav', 'et_pb_sticky',
		'et-fixed-header', 'et_fixed_nav', 'et_pb_section_sticky', 'mfp-', 'swiper-', 'slick-', 'is-active', 'is-open',
		'dvmm_', 'wpforms-error', 'wpforms-valid', 'rcr-',
	];

	/** @var array<string, mixed> */
	private array $config;

	public function __construct(
		array $config,
		private Divi $divi,
		private SafeMode $safe,
		private Logger $logger
	) {
		$this->config = $config;
	}

	public function hooks(): void {
		// Clearing runs whether the feature is on or off: stored CSS must
		// not outlive the content it was built for, in case it comes back on.
		add_action( self::HOOK, [ $this, 'process_queue' ] );
		add_action( 'rc-rocket/purge/all', [ $this, 'forget_all' ] );
		add_action( 'rc-rocket/purge/url', [ $this, 'forget_url' ] );
		add_action( 'rc-rocket/purge/key', [ $this, 'forget_key' ] );
		add_action( 'save_post', [ $this, 'forget_post' ] );

		foreach ( [ 'wp_update_nav_menu', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'update_option_sidebars_widgets' ] as $hook ) {
			add_action( $hook, [ $this, 'forget_all' ] );
		}

		if ( empty( $this->config['enabled'] ) ) {
			return;
		}

		// After everything else that adds or changes stylesheet links.
		add_filter( 'rc-rocket/html', [ $this, 'rewrite' ], 45 );
	}

	// ------------------------------------------------------------- the page

	public function rewrite( string $html ): string {
		if ( ! $this->applies() || $this->divi->rendered_cold() ) {
			return $html;
		}

		$url = self::request_url();

		if ( null === $url ) {
			return $html;
		}

		[ $masked, $kept ] = HtmlPipeline::mask( $html );
		$links             = self::stylesheets( $masked );

		if ( ! $links ) {
			return $html;
		}

		$stored = $this->load( $url );

		if ( null === $stored || ( $stored['signature'] ?? '' ) !== self::signature( $links ) ) {
			// Not built yet, or built for other stylesheets. A failed build
			// is retried after a while, not on every visit.
			if ( null === $stored || empty( $stored['failed'] ) || (int) $stored['built'] < time() - self::RETRY ) {
				$this->queue( $url );
			}

			return $html;
		}

		if ( (int) $stored['built'] < time() - self::MAX_AGE ) {
			$this->queue( $url );
		}

		$files = (array) ( $stored['files'] ?? [] );

		if ( ! $files ) {
			return $html;
		}

		$replaced = 0;

		// From the end, so earlier offsets stay valid.
		foreach ( array_reverse( $links ) as $link ) {
			if ( ! isset( $files[ $link['href'] ] ) ) {
				continue;
			}

			$css      = str_ireplace( '</style', '<\/style', (string) $files[ $link['href'] ] );
			$deferred = (string) preg_replace( '#(?<![\w-])href\s*=#i', 'data-rcr-href=', $link['tag'], 1 );
			// A stylesheet for some screens only keeps that condition.
			$media    = preg_match( '#(?<![\w-])media\s*=\s*["\']([^"\']+)["\']#i', $link['tag'], $m ) && 'all' !== strtolower( trim( $m[1] ) )
				? ' media="' . esc_attr( $m[1] ) . '"'
				: '';
			$new      = '<style id="rcr-used-css-' . $replaced . '"' . $media . '>' . $css . '</style>'
				. $deferred
				. '<noscript>' . $link['tag'] . '</noscript>';

			$masked = substr_replace( $masked, $new, $link['offset'], strlen( $link['tag'] ) );
			++$replaced;
		}

		if ( 0 === $replaced ) {
			return $html;
		}

		$masked = HtmlPipeline::before_body_end( $masked, self::loader() );

		return HtmlPipeline::unmask( $masked, $kept );
	}

	private function applies(): bool {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) || isset( $_GET[ self::SKIP_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		if ( Divi::is_builder_request() || ! $this->safe->should_optimize() || PageOptions::off( 'unused_css' ) ) {
			return false;
		}

		// Searches, feeds and previews have endless addresses or no fixed
		// markup; carts and checkouts change with every visitor.
		if ( is_search() || is_feed() || is_preview() || is_404() ) {
			return false;
		}

		return ! ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) );
	}

	/** The JavaScript that brings the full stylesheets back. */
	private static function loader(): string {
		return '<script id="rcr-css-loader">(function(){var d=false,e=["keydown","mousemove","mousedown","pointerdown","touchstart","wheel","scroll","click"];'
			. 'function go(){if(d)return;d=true;e.forEach(function(x){removeEventListener(x,go,{passive:true})});'
			. '[].forEach.call(document.querySelectorAll("link[data-rcr-href]"),function(l){l.href=l.getAttribute("data-rcr-href");l.removeAttribute("data-rcr-href")})}'
			. 'e.forEach(function(x){addEventListener(x,go,{passive:true})})})();</script>';
	}

	// ------------------------------------------------------------- building

	private function queue( string $url ): void {
		$queue = get_option( self::QUEUE, [] );
		$queue = is_array( $queue ) ? $queue : [];
		$key   = self::key( $url );

		if ( isset( $queue[ $key ] ) || count( $queue ) >= self::MAX_QUEUE ) {
			return;
		}

		$queue[ $key ] = $url;
		update_option( self::QUEUE, $queue, false );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK );
		}
	}

	/** A few pages per run, so a cron request never runs for long. */
	public function process_queue(): void {
		$queue = get_option( self::QUEUE, [] );
		$queue = is_array( $queue ) ? $queue : [];
		// A page measured by the service takes up to half a minute.
		$batch = array_slice( $queue, 0, '' !== self::service_url( $this->config ) ? 2 : self::BATCH, true );

		update_option( self::QUEUE, array_diff_key( $queue, $batch ), false );

		foreach ( $batch as $url ) {
			if ( is_string( $url ) ) {
				$this->build( $url );
			}
		}

		if ( count( $queue ) > count( $batch ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 15, self::HOOK );
		}
	}

	/** Build and store the used CSS for one page. True when stored. */
	public function build( string $url ): bool {
		$response = wp_remote_get(
			add_query_arg( [ self::SKIP_ARG => '1' ], $url ),
			[
				'timeout'     => 20,
				'redirection' => 0,
				'user-agent'  => 'RCRocket used-css',
				'headers'     => [ 'Accept' => 'text/html' ],
			]
		);

		$html = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! str_contains( $html, '</html>' ) ) {
			return $this->failed( $url, 'the page did not load' );
		}

		// Never learn from a page Divi rendered while rebuilding its CSS:
		// its stylesheets are not the ones visitors normally get.
		if ( Divi::is_cold_render( $html, $this->divi->critical_css_on() ) ) {
			return $this->failed( $url, 'Divi was rebuilding its CSS' );
		}

		[ $masked ] = HtmlPipeline::mask( $html );
		$links      = self::stylesheets( $masked );

		if ( ! $links ) {
			return $this->failed( $url, 'no stylesheets of this site on the page' );
		}

		// The service measures in a real browser; what it does not return
		// (or everything, when it is not set up or fails) is trimmed here.
		$remote  = $this->remote( $url );
		$purger  = null;
		$files   = [];
		$before  = 0;
		$after   = 0;
		$service = 0;

		foreach ( $links as $link ) {
			$css = self::read_local( $link['href'], 'css' );

			if ( isset( $remote[ $link['href'] ] ) ) {
				$used = $remote[ $link['href'] ];
				++$service;
			} elseif ( null === $css ) {
				// Unreadable: that stylesheet stays exactly as it is.
				continue;
			} else {
				$purger ??= new CssPurger(
					CssPurger::words( $html . "\n" . $this->scripts_text( $html ) ),
					array_merge( self::SAFELIST, $this->safelist() )
				);
				$used     = $purger->purge( CssPurger::absolutize_urls( $css, $link['href'] ) );
			}

			$files[ $link['href'] ]  = $used;
			$before                 += strlen( (string) $css );
			$after                  += strlen( $used );
		}

		// Inlining that much would make the page itself slow to arrive.
		if ( $after > self::MAX_INLINE ) {
			$this->logger->debug( 'Used CSS too large to inline, page left as it is', [ 'url' => $url, 'bytes' => $after ] );
			$files = [];
		}

		$record = [
			'url'       => $url,
			'signature' => self::signature( $links ),
			'built'     => time(),
			'before'    => $before,
			'after'     => $after,
			'source'    => $service > 0 ? 'service' : 'site',
			'files'     => $files,
		];

		if ( ! Filesystem::ensure_dir( $this->directory() ) || ! Filesystem::atomic_write( $this->file( $url ), (string) wp_json_encode( $record ) ) ) {
			return false;
		}

		$this->index( $url, $files ? [ 'before' => $before, 'after' => $after, 'built' => time(), 'source' => $record['source'] ] : null );

		return true;
	}

	/**
	 * The used CSS of each stylesheet, measured by the RC Rocket CSS service
	 * (service/used-css in the plugin's repository, deployed on Vercel), or
	 * null when it is not set up or does not answer.
	 *
	 * @return array<string, string>|null
	 */
	private function remote( string $url ): ?array {
		$endpoint = self::service_url( $this->config );
		$key      = self::service_key( $this->config );

		if ( '' === $endpoint || '' === $key ) {
			return null;
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 110,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				],
				'body'    => (string) wp_json_encode(
					[
						'url'      => add_query_arg( [ self::SKIP_ARG => '1' ], $url ),
						'safelist' => array_values( $this->safelist() ),
					]
				),
			]
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;

		if ( ! is_array( $data ) || ! is_array( $data['stylesheets'] ?? null ) ) {
			$this->logger->error(
				'Used CSS service did not answer; built on the site instead',
				[
					'url'   => $url,
					'error' => is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code,
				]
			);

			return null;
		}

		return array_filter( $data['stylesheets'], 'is_string' );
	}

	/** Set in wp-config.php (wins) or on the Assets tab. HTTPS only. */
	public static function service_url( array $config ): string {
		$url = trim( (string) ( defined( 'RC_ROCKET_CSS_SERVICE' ) ? RC_ROCKET_CSS_SERVICE : ( $config['service_url'] ?? '' ) ) );

		return str_starts_with( strtolower( $url ), 'https://' ) ? $url : '';
	}

	public static function service_key( array $config ): string {
		return trim( (string) ( defined( 'RC_ROCKET_CSS_KEY' ) ? RC_ROCKET_CSS_KEY : ( $config['service_key'] ?? '' ) ) );
	}

	/** @return string[] */
	private function safelist(): array {
		return array_values( array_filter( array_map( 'strval', (array) ( $this->config['safelist'] ?? [] ) ) ) );
	}

	private function failed( string $url, string $why ): bool {
		$this->logger->debug( 'Used CSS not built', [ 'url' => $url, 'reason' => $why ] );

		if ( Filesystem::ensure_dir( $this->directory() ) ) {
			Filesystem::atomic_write( $this->file( $url ), (string) wp_json_encode( [ 'url' => $url, 'signature' => '', 'built' => time(), 'failed' => $why, 'files' => [] ] ) );
		}

		return false;
	}

	/**
	 * The page's own script files, as text to find class names in. Capped:
	 * a page with megabytes of script gains nothing from more.
	 */
	private function scripts_text( string $html ): string {
		preg_match_all( '#<script\b[^>]*?(?<![\w-])(?:src|data-rcr-src)\s*=\s*["\']([^"\']+)["\']#i', $html, $m );

		$text = '';

		foreach ( array_unique( $m[1] ) as $src ) {
			$js = self::read_local( html_entity_decode( $src ), 'js' );

			if ( null !== $js ) {
				$text .= "\n" . $js;
			}

			if ( strlen( $text ) > self::MAX_JS ) {
				break;
			}
		}

		return $text;
	}

	// ------------------------------------------------------------- forgetting

	public function forget_all(): void {
		Filesystem::delete_tree( $this->directory() );
		delete_option( self::QUEUE );
		delete_option( self::INDEX );
	}

	public function forget_url( mixed $url ): void {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}

		@unlink( $this->file( $url ) ); // phpcs:ignore
		$this->index( $url, null );
	}

	/** "post-42" names one post; a Theme Builder key can change every page. */
	public function forget_key( mixed $key ): void {
		if ( ! is_string( $key ) ) {
			return;
		}

		if ( preg_match( '/^post-(\d+)$/', $key, $m ) ) {
			$this->forget_post( (int) $m[1] );
		} elseif ( str_starts_with( $key, 'divi-tb' ) ) {
			$this->forget_all();
		}
	}

	public function forget_post( mixed $post_id ): void {
		$post_id = (int) $post_id;

		if ( $post_id > 0 && ! wp_is_post_revision( $post_id ) ) {
			$this->forget_url( (string) get_permalink( $post_id ) );
		}
	}

	// ------------------------------------------------------------- storage

	public function directory(): string {
		$uploads = wp_get_upload_dir();

		return $uploads['basedir'] . '/rc-rocket/used-css';
	}

	private function file( string $url ): string {
		return $this->directory() . '/' . self::key( $url ) . '.json';
	}

	/** @return array<string, mixed>|null */
	private function load( string $url ): ?array {
		$raw = @file_get_contents( $this->file( $url ) ); // phpcs:ignore

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/** A small summary of what has been built, for the admin and System check. */
	private function index( string $url, ?array $entry ): void {
		$index = get_option( self::INDEX, [] );
		$index = is_array( $index ) ? $index : [];
		$key   = self::key( $url );

		if ( null === $entry ) {
			if ( ! isset( $index[ $key ] ) ) {
				return;
			}

			unset( $index[ $key ] );
		} else {
			$index[ $key ] = [ 'url' => $url ] + $entry;
			$index         = array_slice( $index, -1000, null, true );
		}

		update_option( self::INDEX, $index, false );
	}

	/** @return array{pages:int, service:int, service_on:bool, before:int, after:int, queued:int} */
	public function report(): array {
		$index = get_option( self::INDEX, [] );
		$index = is_array( $index ) ? $index : [];
		$queue = get_option( self::QUEUE, [] );

		return [
			'pages'   => count( $index ),
			'service' => count( array_filter( $index, static fn( $e ): bool => is_array( $e ) && 'service' === ( $e['source'] ?? '' ) ) ),
			'service_on' => '' !== self::service_url( $this->config ) && '' !== self::service_key( $this->config ),
			'before' => (int) array_sum( array_column( $index, 'before' ) ),
			'after'  => (int) array_sum( array_column( $index, 'after' ) ),
			'queued' => is_array( $queue ) ? count( $queue ) : 0,
		];
	}

	// ------------------------------------------------------------- helpers

	/**
	 * The site's own stylesheets in the page: <link rel="stylesheet"> on
	 * this host, not for print. Links marked data-no-optimize are left
	 * alone, as their plugins ask.
	 *
	 * @return array<int, array{tag:string, href:string, offset:int}>
	 */
	public static function stylesheets( string $masked ): array {
		preg_match_all( '#<link\b[^>]*>#i', $masked, $m, PREG_OFFSET_CAPTURE );

		$host  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$links = [];

		foreach ( $m[0] as [ $tag, $offset ] ) {
			if ( ! preg_match( '#(?<![\w-])rel\s*=\s*["\']?stylesheet["\'\s>/]#i', $tag ) || preg_match( '#data-no-optimize|data-rcr-href#i', $tag ) ) {
				continue;
			}

			// Print and speech stylesheets play no part in the first paint.
			// Any other condition (min-width: 601px) is copied onto the
			// inlined <style>, so it applies exactly where it did.
			if ( preg_match( '#(?<![\w-])media\s*=\s*["\']?([^"\'>]+)#i', $tag, $media ) && preg_match( '#^\s*(only\s+)?(print|speech)\s*$#i', $media[1] ) ) {
				continue;
			}

			if ( ! preg_match( '#(?<![\w-])href\s*=\s*["\']([^"\']+)["\']#i', $tag, $href ) ) {
				continue;
			}

			$url       = html_entity_decode( $href[1] );
			$url_host  = strtolower( (string) wp_parse_url( str_starts_with( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST ) );

			if ( '' !== $url_host && $url_host !== $host ) {
				continue;
			}

			$links[] = [ 'tag' => $tag, 'href' => $url, 'offset' => (int) $offset ];
		}

		return $links;
	}

	/** @param array<int, array{href:string}> $links */
	private static function signature( array $links ): string {
		return md5( implode( "\n", array_column( $links, 'href' ) ) );
	}

	private static function key( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return md5( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . '/' . trim( $path, '/' ) );
	}

	/**
	 * This request's address on the home URL's host, or null when the
	 * request carries anything but tracking parameters.
	 */
	private static function request_url(): ?string {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '' === $path || '/' !== $path[0] || str_contains( $path, '..' ) || str_contains( $path, '//' ) ) {
			return null;
		}

		// '' when the query holds tracking parameters at most; null when
		// anything else is left (a filter, a page number, a search).
		if ( '' !== Key::normalize_query( $uri, [ 'query_whitelist' => [] ] ) ) {
			return null;
		}

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return home_url( '/' . ltrim( substr( $path, strlen( rtrim( $home, '/' ) ) ), '/' ) );
	}

	/**
	 * A stylesheet or script of this site, from disk when it is a file under
	 * the WordPress folder, over HTTP from this host otherwise (a stylesheet
	 * some plugin prints through PHP).
	 */
	private static function read_local( string $url, string $extension ): ?string {
		$url  = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$roots = [
			(string) wp_parse_url( content_url( '/' ), PHP_URL_PATH ) => WP_CONTENT_DIR . '/',
			(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH )    => ABSPATH,
		];

		foreach ( $roots as $prefix => $dir ) {
			if ( '' === $prefix || ! str_starts_with( $path, $prefix ) ) {
				continue;
			}

			$file  = realpath( $dir . rawurldecode( substr( $path, strlen( $prefix ) ) ) );
			$roots = array_filter( [ realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) ] );
			$known = false !== $file && (bool) array_filter( $roots, static fn( string $root ): bool => str_starts_with( (string) $file, $root . DIRECTORY_SEPARATOR ) );

			if ( $known && str_ends_with( strtolower( (string) $file ), '.' . $extension ) && is_readable( (string) $file ) ) {
				$text = file_get_contents( $file ); // phpcs:ignore

				return is_string( $text ) ? $text : null;
			}
		}

		if ( 'css' !== $extension ) {
			return null;
		}

		$absolute = str_starts_with( $url, '/' ) ? home_url( $url ) : $url;
		$response = wp_remote_get( $absolute, [ 'timeout' => 10, 'redirection' => 0 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		return '' === $type || str_contains( $type, 'css' ) ? (string) wp_remote_retrieve_body( $response ) : null;
	}
}
