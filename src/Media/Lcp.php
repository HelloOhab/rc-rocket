<?php
declare( strict_types=1 );

namespace RCRocket\Media;

use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Safety\SafetyModule;
use RCRocket\Support\Context;
use RCRocket\Support\PageOptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hero detection: what each page's Largest Contentful Paint really is.
 *
 * Guessing "the first two images" is right on a blog post and wrong on most
 * Divi pages, where the hero is a section background that lives in
 * generated CSS and the first <img> is the logo. So the browsers of real
 * visitors are asked once: a small script reads the LCP entry and reports
 * the element type and URL, for phones and computers separately. From then
 * on the page is served with that image requested first: fetchpriority on
 * an <img>, a preload hint for a background.
 *
 * Measured per page for singular content (each Divi page has its own hero),
 * per template for everything else. A measurement lives 30 days, or until
 * the page is saved. Nothing a visitor reports is trusted further than it
 * has to be: an <img> is only prioritised if the page really contains it,
 * and a background is only preloaded from this site or a host the page
 * already loads from.
 */
final class Lcp {

	public const META     = '_rcr_lcp';
	public const OPTION   = 'rcrocket_lcp';
	public const LIFETIME = 30 * DAY_IN_SECONDS;

	private const DEVICES     = [ 'mobile', 'desktop' ];
	private const TYPES       = [ 'img', 'bg', 'video', 'text' ];
	private const MAX_OPTIONS = 40;

	/** Phones and small tablets; matches the beacon's split. */
	private const MOBILE_MEDIA  = '(max-width: 767px)';
	private const DESKTOP_MEDIA = '(min-width: 768px)';

	public function __construct( private Context $context ) {}

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );

		// A saved page may have a new hero.
		add_action(
			'save_post',
			static function ( int $post_id ): void {
				if ( ! wp_is_post_revision( $post_id ) ) {
					delete_post_meta( $post_id, self::META );
				}
			}
		);
	}

	// ----------------------------------------------------------- storage

	/** The record key for the page being rendered: "post:42" or "sig:front_page". */
	public function current_key(): string {
		$post_id = PageOptions::current_post_id();

		return $post_id > 0 ? 'post:' . $post_id : 'sig:' . $this->context->signature();
	}

	/** @return array<string, array{type:string, url:string, at:int}> Device => measurement. */
	public static function get( string $key ): array {
		if ( str_starts_with( $key, 'post:' ) ) {
			$stored = get_post_meta( (int) substr( $key, 5 ), self::META, true );
		} else {
			$all    = get_option( self::OPTION, [] );
			$stored = is_array( $all ) ? ( $all[ substr( $key, 4 ) ] ?? [] ) : [];
		}

		$out = [];

		foreach ( self::DEVICES as $device ) {
			$entry = is_array( $stored ) ? ( $stored[ $device ] ?? null ) : null;

			if ( is_array( $entry ) && (int) ( $entry['at'] ?? 0 ) > time() - self::LIFETIME ) {
				$out[ $device ] = [
					'type' => (string) ( $entry['type'] ?? 'text' ),
					'url'  => (string) ( $entry['url'] ?? '' ),
					'at'   => (int) $entry['at'],
				];
			}
		}

		return $out;
	}

	private static function put( string $key, string $device, array $entry ): void {
		if ( str_starts_with( $key, 'post:' ) ) {
			$post_id            = (int) substr( $key, 5 );
			$stored             = get_post_meta( $post_id, self::META, true );
			$stored             = is_array( $stored ) ? $stored : [];
			$stored[ $device ]  = $entry;

			update_post_meta( $post_id, self::META, $stored );

			return;
		}

		$all  = get_option( self::OPTION, [] );
		$all  = is_array( $all ) ? $all : [];
		$name = substr( $key, 4 );

		$all[ $name ]            = is_array( $all[ $name ] ?? null ) ? $all[ $name ] : [];
		$all[ $name ][ $device ] = $entry;

		update_option( self::OPTION, array_slice( $all, -self::MAX_OPTIONS, null, true ), false );
	}

	/** Forget every measurement, so each page is measured again. */
	public static function reset(): int {
		global $wpdb;

		delete_option( self::OPTION );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Everything measured, newest first, for the admin screen and MCP.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function listing( int $limit = 50 ): array {
		global $wpdb;

		$rows = [];

		foreach ( (array) get_option( self::OPTION, [] ) as $name => $devices ) {
			$rows[] = [
				'key'   => 'sig:' . $name,
				'label' => (string) $name,
				'url'   => '',
			] + self::summarise( self::get( 'sig:' . $name ) );
		}

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d", self::META, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $ids as $id ) {
			$rows[] = [
				'key'   => 'post:' . (int) $id,
				'label' => get_the_title( (int) $id ),
				'url'   => (string) get_permalink( (int) $id ),
			] + self::summarise( self::get( 'post:' . (int) $id ) );
		}

		return array_values( array_filter( $rows, static fn( array $r ): bool => $r['mobile'] || $r['desktop'] ) );
	}

	private static function summarise( array $measured ): array {
		return [
			'mobile'  => $measured['mobile'] ?? null,
			'desktop' => $measured['desktop'] ?? null,
		];
	}

	// ------------------------------------------------------------ beacon

	/**
	 * Add the measuring script while a device class is still unmeasured.
	 * Once both are known the script stops appearing as soon as the page is
	 * next rendered, which the first report arranges.
	 */
	public function inject_beacon( string $html ): string {
		$key      = $this->current_key();
		$measured = self::get( $key );
		$need     = array_values( array_diff( self::DEVICES, array_keys( $measured ) ) );

		if ( ! $need ) {
			return $html;
		}

		$endpoint = esc_url_raw( rest_url( 'rc-rocket/v1/lcp' ) );
		$config   = wp_json_encode(
			[
				'e' => $endpoint,
				't' => SafetyModule::token(),
				'k' => $key,
				'n' => $need,
			],
			JSON_UNESCAPED_SLASHES
		);

		$script = <<<HTML
<script id="rcr-lcp">
(function (c) {
  var types = window.PerformanceObserver && PerformanceObserver.supportedEntryTypes;
  if (!types || types.indexOf('largest-contentful-paint') < 0) return;
  // A page opened in a background tab or prerendered reports a meaningless LCP.
  if (document.prerendering || document.visibilityState !== 'visible') return;
  var device = window.innerWidth < 768 ? 'mobile' : 'desktop';
  if (c.n.indexOf(device) < 0) return;
  var last = null, hidden = false, sent = false;
  var po = new PerformanceObserver(function (l) { var e = l.getEntries(); if (e.length) last = e[e.length - 1]; });
  po.observe({ type: 'largest-contentful-paint', buffered: true });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') hidden = true; });
  function send() {
    if (sent) return;
    sent = true;
    try { po.takeRecords().forEach(function (e) { last = e; }); po.disconnect(); } catch (e) {}
    if (!last) return;
    var el = last.element, url = last.url || '', type = 'text';
    if (el && el.tagName === 'IMG') { type = 'img'; url = el.currentSrc || el.src || url; }
    else if (el && el.tagName === 'VIDEO') { type = 'video'; url = url || el.poster || ''; }
    else if (url) { type = 'bg'; }
    if (/^(data|blob):/.test(url)) { type = 'text'; url = ''; }
    var body = JSON.stringify({ token: c.t, key: c.k, device: device, type: type, url: url.slice(0, 600), early: hidden, page: location.pathname.slice(0, 300) });
    try {
      if (navigator.sendBeacon) navigator.sendBeacon(c.e, new Blob([body], { type: 'application/json' }));
      else fetch(c.e, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true });
    } catch (e) {}
  }
  // LCP is final at the first interaction or shortly after load.
  addEventListener('load', function () { setTimeout(send, 2500); });
  addEventListener('pagehide', send);
})({$config});
</script>
HTML;

		return HtmlPipeline::before_body_end( $html, $script );
	}

	public function register_route(): void {
		register_rest_route(
			'rc-rocket/v1',
			'/lcp',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'record' ],
			]
		);
	}

	/**
	 * Public by necessity: the reports come from visitors' browsers. The
	 * page token rules out blind posts, each visitor has a small budget, a
	 * fresh measurement is never overwritten, and what is stored is only
	 * ever acted on within the limits described on the class.
	 */
	public function record( \WP_REST_Request $request ): \WP_REST_Response {
		$response = rest_ensure_response( [ 'recorded' => true ] );
		$response->header( 'Cache-Control', 'no-store' );

		$payload = (array) $request->get_json_params();

		if ( ! SafetyModule::token_valid( (string) ( $payload['token'] ?? '' ) ) ) {
			return $response;
		}

		$key    = (string) ( $payload['key'] ?? '' );
		$device = (string) ( $payload['device'] ?? '' );
		$type   = (string) ( $payload['type'] ?? '' );
		$url    = esc_url_raw( (string) ( $payload['url'] ?? '' ), [ 'http', 'https' ] );

		if ( ! in_array( $device, self::DEVICES, true ) || ! in_array( $type, self::TYPES, true ) || ! self::valid_key( $key ) ) {
			return $response;
		}

		// A page left in a background tab before it finished painting.
		if ( ! empty( $payload['early'] ) ) {
			return $response;
		}

		if ( isset( self::get( $key )[ $device ] ) || ! self::within_budget() ) {
			return $response;
		}

		// Anyone can post here, and what is kept is preloaded by every
		// visitor for a month. Only an image file without a query qualifies:
		// a path like "/" would match every image on the page, and a query
		// string can turn a preload into an action (?add-to-cart=).
		if ( 'text' !== $type ) {
			$url = self::image_url( $url );
		}

		if ( 'text' !== $type && '' === $url ) {
			$type = 'text';
		}

		self::put(
			$key,
			$device,
			[
				'type' => $type,
				'url'  => 'text' === $type ? '' : $url,
				'at'   => time(),
			]
		);

		/**
		 * A page has a new measurement. Its cached copy still carries the
		 * old guess (and the measuring script), so it is refreshed.
		 *
		 * @param string $key    "post:42" or "sig:front_page".
		 * @param string $device "mobile" or "desktop".
		 * @param string $page   The path the report came from.
		 */
		do_action( 'rc-rocket/lcp/measured', $key, $device, sanitize_text_field( (string) ( $payload['page'] ?? '' ) ) );

		return $response;
	}

	/** The URL without query or fragment when it names an image file, else ''. */
	private static function image_url( string $url ): string {
		// With a query (?resize=, ?ver=, CDN format) the file without it is a
		// different download; preloading that would fetch the hero twice.
		if ( str_contains( $url, '?' ) ) {
			return '';
		}

		$url  = (string) strtok( $url, '#' );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( ! preg_match( '#/[^/]+\.(?:jpe?g|png|gif|webp|avif|svg)$#i', $path ) ) {
			return '';
		}

		return $url;
	}

	private static function valid_key( string $key ): bool {
		if ( preg_match( '/^post:(\d+)$/', $key, $m ) ) {
			$post = get_post( (int) $m[1] );

			return $post instanceof \WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );
		}

		return (bool) preg_match( '/^sig:[a-z_]+(:[a-z0-9_-]+)?$/', $key );
	}

	/** Five reports per visitor per ten minutes. */
	private static function within_budget(): bool {
		$key   = 'rcr_lcp_' . SafetyModule::reporter_id();
		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	// ----------------------------------------------------------- applying

	/**
	 * What the rewriter needs: image paths to prioritise, and background
	 * preloads (url => media query, '' for every screen).
	 *
	 * @return array{images: string[], preloads: array<string, string>, measured: bool}
	 */
	public function plan( string $html ): array {
		$measured = self::get( $this->current_key() );
		$plan     = [
			'images'   => [],
			'preloads' => [],
			'measured' => (bool) $measured,
		];

		$same = isset( $measured['mobile'], $measured['desktop'] ) && $measured['mobile']['url'] === $measured['desktop']['url'];

		foreach ( $measured as $device => $entry ) {
			if ( 'text' === $entry['type'] || '' === $entry['url'] ) {
				continue;
			}

			$path = (string) wp_parse_url( $entry['url'], PHP_URL_PATH );

			// An <img> the page really contains: give it priority in place.
			if ( 'img' === $entry['type'] && '' !== $path && str_contains( $html, $path ) ) {
				$plan['images'][] = $path;
				continue;
			}

			if ( ! self::trusted_host( $entry['url'], $html ) ) {
				continue;
			}

			$media = $same || ! isset( $measured['mobile'], $measured['desktop'] ) ? '' : ( 'mobile' === $device ? self::MOBILE_MEDIA : self::DESKTOP_MEDIA );

			$plan['preloads'][ $entry['url'] ] = $media;
		}

		$plan['images'] = array_values( array_unique( $plan['images'] ) );

		return $plan;
	}

	/** This site, or a host the page already loads something from (a CDN). */
	private static function trusted_host( string $url, string $html ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		if ( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ) {
			return true;
		}

		return (bool) preg_match( '#(?:src|href)\s*=\s*["\']?(?:https?:)?//' . preg_quote( $host, '#' ) . '/#i', $html );
	}

	/** @param array<string, string> $preloads url => media */
	public static function preload_tags( array $preloads ): string {
		$out = '';

		foreach ( $preloads as $url => $media ) {
			$out .= sprintf(
				'<link rel="preload" as="image" href="%s" fetchpriority="high"%s>',
				esc_url( (string) $url ),
				'' === $media ? '' : ' media="' . esc_attr( $media ) . '"'
			);
		}

		return $out;
	}
}
