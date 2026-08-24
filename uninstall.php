<?php
/**
 * Runs on delete, not deactivate. Removes every trace.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rcrocket_settings' );
delete_option( 'rcrocket_preload_queue' );
delete_option( 'rcrocket_preload_state' );

$rcrocket_dir = WP_CONTENT_DIR . '/cache/rc-rocket';

if ( is_dir( $rcrocket_dir ) ) {
	$rcrocket_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $rcrocket_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $rcrocket_items as $rcrocket_item ) {
		$rcrocket_item->isDir() ? @rmdir( $rcrocket_item->getPathname() ) : @unlink( $rcrocket_item->getPathname() ); // phpcs:ignore
	}

	@rmdir( $rcrocket_dir ); // phpcs:ignore
}

$rcrocket_dropin = WP_CONTENT_DIR . '/advanced-cache.php';

if ( file_exists( $rcrocket_dropin ) && str_contains( (string) file_get_contents( $rcrocket_dropin ), 'RC Rocket advanced-cache drop-in' ) ) {
	@unlink( $rcrocket_dropin ); // phpcs:ignore
}
