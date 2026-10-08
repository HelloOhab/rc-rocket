<?php
/**
 * Runs on delete, not deactivate. Removes every trace: options, transients,
 * post meta, cached pages, localized fonts and the drop-in.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Every option and transient the plugin writes is prefixed rcrocket_.
$rcrocket_like = $wpdb->esc_like( 'rcrocket_' ) . '%';

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $rcrocket_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_' . $rcrocket_like, '\_transient\_timeout\_' . $rcrocket_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_site\_transient\_' . $rcrocket_like, '\_site\_transient\_timeout\_' . $rcrocket_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_rcr\_beacon\_%', '\_transient\_timeout\_rcr\_beacon\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s )", '_rcr_divi_modules', '_rcr_lcp', '_rcr_page_options' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '\_transient\_rcr\_lcp\_%', '\_transient\_timeout\_rcr\_lcp\_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

wp_cache_flush();

foreach ( [ 'rc-rocket/cache/cleanup', 'rc-rocket/preload/batch', 'rc-rocket/fonts/refresh', 'rc-rocket/fonts/localize', 'rc-rocket/database/cleanup', 'rc-rocket/host/deferred-purge', 'rc-rocket/safe-mode/expired' ] as $rcrocket_hook ) {
	wp_unschedule_hook( $rcrocket_hook );
}

$rcrocket_delete_tree = static function ( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore
	}

	@rmdir( $dir ); // phpcs:ignore
};

$rcrocket_delete_tree( defined( 'RC_ROCKET_CACHE_DIR' ) ? (string) RC_ROCKET_CACHE_DIR : WP_CONTENT_DIR . '/cache/rc-rocket' );

$rcrocket_uploads = wp_get_upload_dir();
$rcrocket_delete_tree( $rcrocket_uploads['basedir'] . '/rc-rocket' );

$rcrocket_dropin = WP_CONTENT_DIR . '/advanced-cache.php';

if ( file_exists( $rcrocket_dropin ) && str_contains( (string) file_get_contents( $rcrocket_dropin ), 'RC Rocket advanced-cache drop-in' ) ) {
	@unlink( $rcrocket_dropin ); // phpcs:ignore
}
