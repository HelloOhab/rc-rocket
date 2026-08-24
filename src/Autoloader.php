<?php
declare( strict_types=1 );

namespace RCRocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal PSR-4 autoloader. No Composer dependency: this plugin must be
 * installable from a zip on a host with no shell access.
 */
final class Autoloader {

	public static function register( string $prefix, string $base_dir ): void {
		$prefix   = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class, $prefix ) ) {
					return;
				}

				$relative = substr( $class, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
