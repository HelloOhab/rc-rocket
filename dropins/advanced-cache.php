<?php
/**
 * RC Rocket advanced-cache drop-in
 *
 * Loaded by wp-settings.php before plugins, before the theme, before the
 * database is queried. Everything here runs on every single request, so it
 * stays small, allocates little, and bails early.
 *
 * Do not edit: this file is overwritten by the plugin.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

( static function (): void {

	// The kill switch in wp-config.php has to reach cached pages too, or it
	// is not a kill switch.
	if ( defined( 'RC_ROCKET_SAFE_MODE' ) && RC_ROCKET_SAFE_MODE ) {
		return;
	}

	$cache_dir = defined( 'RC_ROCKET_CACHE_DIR' ) ? (string) RC_ROCKET_CACHE_DIR : WP_CONTENT_DIR . '/cache/rc-rocket';

	// A PHP file rather than JSON: it holds the preload secret, and a PHP file
	// requested over HTTP prints nothing.
	$config_file = $cache_dir . '/config.php';

	if ( ! is_readable( $config_file ) ) {
		return;
	}

	$config = include $config_file;

	if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
		return;
	}

	$key_class = rtrim( (string) ( $config['plugin_dir'] ?? '' ), '/' ) . '/src/Cache/Key.php';

	// The stored path is absolute. After a move to another server or folder
	// it points nowhere (or at another install); the usual location is the
	// best guess until settings are saved again.
	if ( ! is_readable( $key_class ) ) {
		$key_class = WP_CONTENT_DIR . '/plugins/rc-rocket/src/Cache/Key.php';
	}

	if ( ! is_readable( $key_class ) ) {
		return;
	}

	// The same applies to the cache directory: this file found the config in
	// $cache_dir, so that is where the cache is.
	$config['cache_dir'] = $cache_dir;

	require_once $key_class;

	$server  = $_SERVER;
	$cookies = $_COOKIE;

	// A preload request wants a fresh render, not the copy it is replacing.
	// Only our own preloader knows the secret; anyone else sending the header
	// is treated as a normal visitor, so it cannot be used to force renders.
	$preload = (string) ( $server['HTTP_X_RC_ROCKET_PRELOAD'] ?? '' );
	$secret  = (string) ( $config['preload_secret'] ?? '' );

	if ( '' !== $preload && '' !== $secret && hash_equals( $secret, $preload ) ) {
		define( 'RCROCKET_SERVE', 'preload' );

		return;
	}

	$reason = RCRocket\Cache\Key::bypass_reason( $server, $cookies, $config );

	if ( null !== $reason ) {
		define( 'RCROCKET_SERVE', 'bypass:' . $reason );

		return;
	}

	$host    = (string) ( $server['HTTP_HOST'] ?? '' );
	$uri     = (string) ( $server['REQUEST_URI'] ?? '/' );
	$variant = RCRocket\Cache\Key::variant( $server, $cookies, $config );
	$hash    = RCRocket\Cache\Key::hash( $host, $uri, $variant, $config );

	if ( null === $hash ) {
		define( 'RCROCKET_SERVE', 'bypass:query' );

		return;
	}

	$paths = RCRocket\Cache\Key::paths( (string) $config['cache_dir'], $hash );

	if ( ! is_readable( $paths['html'] ) || ! is_readable( $paths['meta'] ) ) {
		define( 'RCROCKET_SERVE', 'miss' );

		return;
	}

	$meta = json_decode( (string) file_get_contents( $paths['meta'] ), true );

	if ( ! is_array( $meta ) || ( (int) ( $meta['expires'] ?? 0 ) ) < time() ) {
		define( 'RCROCKET_SERVE', 'expired' );

		return;
	}

	// ---- Hit. From here the request never touches the database. ----

	$modified = (int) ( $meta['created'] ?? time() );
	$etag     = '"' . substr( $hash, 0, 16 ) . '-' . $modified . '"';

	// Replay the headers the page was rendered with (security headers set in
	// PHP, Link hints) so a hit is indistinguishable from a miss.
	// The first line of a name replaces whatever PHP set; later lines of the
	// same name (several Link headers) are added rather than overwriting.
	$replayed = [];

	foreach ( (array) ( $meta['headers'] ?? [] ) as $line ) {
		$name = strtolower( trim( (string) strstr( (string) $line, ':', true ) ) );

		header( (string) $line, ! isset( $replayed[ $name ] ) );

		$replayed[ $name ] = true;
	}

	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
	header( 'Vary: Accept-Encoding' . ( empty( $config['separate_mobile'] ) ? '' : ', User-Agent' ) );

	if ( ! empty( $config['debug_headers'] ) ) {
		header( 'X-RC-Rocket-Cache: HIT' );
		header( 'X-RC-Rocket-Age: ' . ( time() - $modified ) );
	}

	$if_none_match = trim( (string) ( $server['HTTP_IF_NONE_MATCH'] ?? '' ) );

	if ( '' !== $if_none_match && $if_none_match === $etag ) {
		http_response_code( 304 );
		exit;
	}

	if ( 'HEAD' === strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ) ) {
		exit;
	}

	$accepts_gzip = str_contains( strtolower( (string) ( $server['HTTP_ACCEPT_ENCODING'] ?? '' ) ), 'gzip' );

	if ( $accepts_gzip && is_readable( $paths['gz'] ) && ! ini_get( 'zlib.output_compression' ) ) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . (string) filesize( $paths['gz'] ) );
		readfile( $paths['gz'] );
		exit;
	}

	header( 'Content-Length: ' . (string) filesize( $paths['html'] ) );
	readfile( $paths['html'] );
	exit;
} )();
