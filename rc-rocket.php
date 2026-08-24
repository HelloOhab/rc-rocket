<?php
/**
 * Plugin Name:       RC Rocket
 * Plugin URI:        https://rhythmco.com/
 * Description:       Performance tuning built around Divi. Asset control, media delivery, JavaScript timing and a safety net that switches itself off when something breaks.
 * Version:           0.6.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Abdul
 * License:           GPL-2.0-or-later
 * Text Domain:       rc-rocket
 *
 * @package RCRocket
 */

declare( strict_types=1 );

namespace RCRocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '0.6.3';
const MIN_PHP  = '8.1';
const MIN_WP   = '6.5';

define( 'RCROCKET_FILE', __FILE__ );
define( 'RCROCKET_DIR', plugin_dir_path( __FILE__ ) );
define( 'RCROCKET_URL', plugin_dir_url( __FILE__ ) );
define( 'RCROCKET_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Fail loudly and early rather than fataling on an unsupported stack.
 */
function requirements_met(): bool {
	global $wp_version;

	return version_compare( PHP_VERSION, MIN_PHP, '>=' )
		&& version_compare( $wp_version, MIN_WP, '>=' );
}

if ( ! requirements_met() ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WP version */
						__( 'RC Rocket needs PHP %1$s and WordPress %2$s or newer. It has not been loaded.', 'rc-rocket' ),
						MIN_PHP,
						MIN_WP
					)
				)
			);
		}
	);

	return;
}

require_once __DIR__ . '/src/Autoloader.php';
Autoloader::register( __NAMESPACE__, __DIR__ . '/src' );

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::instance()->boot();
