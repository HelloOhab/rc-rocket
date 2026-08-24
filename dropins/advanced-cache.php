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

	$config_file = WP_CONTENT_DIR . '/cache/rc-rocket/config.json';

	if ( ! is_readable( $config_file ) ) {
		return;
	}

	$config = json_decode( (string) file_get_contents( $config_file ), true );

	if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
		return;
	}

	$key_class = rtrim( (string) ( $config['plugin_dir'] ?? '' ), '/' ) . '/src/Cache/Key.php';

	if ( ! is_readable( $key_class ) ) {
		return;
	}

	require_once $key_class;

	$server  = $_SERVER;
	$cookies = $_COOKIE;

	// A preload request wants a fresh render, not the copy it is replacing.
	if ( ! empty( $server['HTTP_X_RC_ROCKET_PRELOAD'] ) ) {
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
		define( 'RCROCKET_HASH', $hash );
		define( 'RCROCKET_VARIANT', $variant );

		return;
	}

	$meta = json_decode( (string) file_get_contents( $paths['meta'] ), true );

	if ( ! is_array( $meta ) || ( (int) ( $meta['expires'] ?? 0 ) ) < time() ) {
		define( 'RCROCKET_SERVE', 'expired' );
		define( 'RCROCKET_HASH', $hash );
		define( 'RCROCKET_VARIANT', $variant );

		return;
	}

	// ---- Hit. From here the request never touches the database. ----

	$modified = (int) ( $meta['created'] ?? time() );
	$etag     = '"' . substr( $hash, 0, 16 ) . '-' . $modified . '"';

	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'ETag: ' . $etag );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
	header( 'Vary: Accept-Encoding, User-Agent' );

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

	if ( $accepts_gzip && is_readable( $paths['gz'] ) ) {
		header( 'Content-Encoding: gzip' );
		header( 'Content-Length: ' . (string) filesize( $paths['gz'] ) );
		readfile( $paths['gz'] );
		exit;
	}

	header( 'Content-Length: ' . (string) filesize( $paths['html'] ) );
	readfile( $paths['html'] );
	exit;
} )();
