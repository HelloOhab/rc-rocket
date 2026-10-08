<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One answer to "where does RC Rocket keep its files".
 *
 * The drop-in runs before plugins load and cannot see filters, so the only
 * override both sides can agree on is the RC_ROCKET_CACHE_DIR constant. The
 * filter is kept for code that only runs inside WordPress.
 */
final class Paths {

	public static function cache_dir(): string {
		$default = defined( 'RC_ROCKET_CACHE_DIR' ) ? (string) RC_ROCKET_CACHE_DIR : WP_CONTENT_DIR . '/cache/rc-rocket';

		return rtrim( (string) apply_filters( 'rc-rocket/cache/dir', $default ), '/' );
	}

	/**
	 * The log lives beside the cache, so it needs a name nobody can guess:
	 * on nginx hosts such as Kinsta no deny rule protects that directory.
	 */
	public static function log_file(): string {
		// Pluggable functions such as wp_salt() do not exist yet when the
		// logger is built, so the name comes from a stored random key.
		$key = (string) get_option( 'rcrocket_log_key', '' );

		if ( ! preg_match( '/^[a-f0-9]{12}$/', $key ) ) {
			$key = bin2hex( random_bytes( 6 ) );
			update_option( 'rcrocket_log_key', $key, true );
		}

		return self::cache_dir() . '/rc-rocket-' . $key . '.log';
	}
}
