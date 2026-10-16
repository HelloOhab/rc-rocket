<?php
/**
 * Link preloading on plain sites and WooCommerce shops.
 *
 * 0.12.0–0.12.6 asked WordPress for the cart, checkout and account page links
 * while modules booted, at plugins_loaded, before $wp_rewrite exists. On a
 * WooCommerce site that is a fatal error, and every wp-admin page showed
 * "There has been a critical error". These tests boot the module the way
 * WordPress does: with no $wp_rewrite, and a get_permalink() that fails
 * exactly like core's does without it.
 */
require __DIR__ . '/stubs.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Container;
use RCRocket\Preload\PreloadModule;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

if ( ! class_exists( 'WP_Rewrite' ) ) {
	class WP_Rewrite {}
}

// Core's get_permalink() for a page ends in $wp_rewrite->get_page_permastruct().
$GLOBALS['permalink_calls'] = 0;
function get_permalink( $id ) {
	$GLOBALS['permalink_calls']++;

	if ( ! ( ( $GLOBALS['wp_rewrite'] ?? null ) instanceof WP_Rewrite ) ) {
		throw new Error( 'Call to a member function get_page_permastruct() on null' );
	}

	if ( ( $GLOBALS['throw_for'] ?? 0 ) === $id ) {
		throw new RuntimeException( 'a post_link filter failed' );
	}

	return home_url( '/' . $GLOBALS['shop_pages'][ $id ] . '/' );
}
function get_page_uri( $id ) { return $GLOBALS['shop_pages'][ $id ] ?? ''; }

$GLOBALS['options']['permalink_structure'] = '/%postname%/';

/** Boot the module as plugins_loaded does: no $wp_rewrite yet. */
function boot_preload( array $preload = [] ): void {
	unset( $GLOBALS['wp_rewrite'] );
	$GLOBALS['filters'] = [];
	$GLOBALS['actions'] = [];

	$module   = new PreloadModule();
	$settings = new Settings();
	$settings->add_defaults( 'preload', $module->defaults() );

	foreach ( $preload as $key => $value ) {
		$settings->set( "preload.$key", $value );
	}

	$c = new Container();
	$c->set( 'settings', fn() => $settings );
	$c->set( 'safe_mode', fn( $c ) => new SafeMode( $c->get( 'settings' ) ) );

	$module->boot( $c );
}

/** What the visitor's page would carry, rendered after WordPress has loaded. */
function footer_rules(): string {
	$GLOBALS['wp_rewrite'] = new WP_Rewrite();
	ob_start();

	foreach ( $GLOBALS['actions']['wp_footer'] ?? [] as $callback ) {
		$callback();
	}

	return (string) ob_get_clean();
}

function booted_without_error( array $preload = [] ): string {
	$GLOBALS['permalink_calls'] = 0;

	try {
		boot_preload( $preload );
	} catch ( Throwable $e ) {
		return $e->getMessage();
	}

	return '';
}

// ============ 1. A plain site (no WooCommerce) ============

$err = booted_without_error();
ok( 'preload: a plain site boots', '' === $err, $err );
ok( 'preload: section 1 really has no WooCommerce', ! function_exists( 'wc_get_page_id' ) );
$html = footer_rules();
ok( 'preload: a plain site prints speculation rules', str_contains( $html, 'speculationrules' ) );
ok( 'preload: a plain site still keeps wp-admin out', str_contains( $html, '/wp-admin/*' ) );
ok( 'preload: a plain site has no shop exclusions', ! str_contains( $html, '/checkout*' ) );
ok( 'preload: a plain site never asks for a permalink', 0 === $GLOBALS['permalink_calls'] );

// ============ 2. A WooCommerce shop ============

$GLOBALS['shop_pages'] = [ 11 => 'cart', 12 => 'checkout', 13 => 'my-account' ];
// Declared inside a block so PHP does not hoist it above section 1.
if ( true ) {
	function wc_get_page_id( $page ) {
		return [ 'cart' => 11, 'checkout' => 12, 'myaccount' => 13 ][ $page ] ?? -1;
	}
}

$err = booted_without_error();
ok( 'preload: a WooCommerce site boots (the 0.12.6 wp-admin crash)', '' === $err, $err );
ok( 'preload: no permalink is built while plugins load', 0 === $GLOBALS['permalink_calls'] );

$err = booted_without_error( [ 'links' => false ] );
ok( 'preload: a shop with link preloading off boots', '' === $err, $err );

boot_preload();
$html = footer_rules();
ok( 'preload: a shop keeps the cart out of preloading', str_contains( $html, '/cart*' ) );
ok( 'preload: a shop keeps checkout out of preloading', str_contains( $html, '/checkout*' ) );
ok( 'preload: a shop keeps the account page out of preloading', str_contains( $html, '/my-account*' ) );
ok( 'preload: and the built-in exclusions stay', str_contains( $html, '/wp-admin/*' ) && str_contains( $html, 'add-to-cart=' ) );

// A shop page set to the home page would exclude the whole site.
$GLOBALS['shop_pages'][11] = '';
boot_preload();
$html = footer_rules();
$rules    = json_decode( (string) preg_replace( '#^.*?>|<.*$#s', '', $html ), true );
$excluded = $rules['prefetch'][0]['where']['and'][1]['not']['href_matches'] ?? [];
ok( 'preload: a shop page with no path of its own is skipped',
	! array_intersect( [ '/*', '*' ], $excluded ) && in_array( '/checkout*', $excluded, true ), implode( ', ', $excluded ) );
$GLOBALS['shop_pages'][11] = 'cart';

// Asked for early anyway (another plugin calling in at plugins_loaded):
// the page's own path, never a fatal error.
unset( $GLOBALS['wp_rewrite'] );
$early = [];
try {
	$early = PreloadModule::exclusions( [] );
} catch ( Throwable $e ) {
	$early = [ 'error: ' . $e->getMessage() ];
}
ok( 'preload: exclusions asked for early fall back to page paths', in_array( '/checkout*', $early, true ), implode( ', ', $early ) );

// A link filter that throws loses that page, not the site.
$GLOBALS['wp_rewrite'] = new WP_Rewrite();
$GLOBALS['throw_for'] = 13;
$broken = [];
try {
	$broken = PreloadModule::shop_paths();
} catch ( Throwable $e ) {
	$broken = [ 'error: ' . $e->getMessage() ];
}
ok( 'preload: one broken shop link does not stop the others', in_array( '/cart*', $broken, true ) && in_array( '/checkout*', $broken, true ), implode( ', ', $broken ) );
$GLOBALS['throw_for'] = 0;

// Typed-in exclusions, as a path or a full URL.
boot_preload( [ 'links_exclusions' => [ '/quote/*', 'https://example.com/members/*', '' ] ] );
$html = footer_rules();
ok( 'preload: typed-in paths are excluded', str_contains( $html, '/quote/*' ) );
ok( 'preload: typed-in URLs are excluded by path', str_contains( $html, '/members/*' ) );

// ============ 3. WordPress 6.8+: the core Speculation Rules API ============

if ( true ) {
	function wp_get_speculation_rules_configuration() { return [ 'mode' => 'prefetch', 'eagerness' => 'conservative' ]; }
}

$err = booted_without_error();
ok( 'preload: a shop on WordPress 6.8+ boots', '' === $err, $err );
ok( 'preload: and builds no permalink while plugins load', 0 === $GLOBALS['permalink_calls'] );
ok( 'preload: on 6.8+ nothing is printed by hand', empty( $GLOBALS['actions']['wp_footer'] ) );

$GLOBALS['wp_rewrite'] = new WP_Rewrite();
$paths = [];
foreach ( $GLOBALS['filters']['wp_speculation_rules_href_exclude_paths'] ?? [] as $callback ) {
	$paths = $callback( [ '/core-default/*' ] );
}
ok( 'preload: core exclusions are kept', in_array( '/core-default/*', $paths, true ) );
ok( 'preload: shop pages are added to core\'s exclusions', in_array( '/checkout*', $paths, true ) && in_array( '/cart*', $paths, true ) );

$calls = $GLOBALS['permalink_calls'];
foreach ( $GLOBALS['filters']['wp_speculation_rules_href_exclude_paths'] as $callback ) {
	$callback( [] );
}
ok( 'preload: shop links are looked up once per page view', $calls === $GLOBALS['permalink_calls'] );

$config = null;
foreach ( $GLOBALS['filters']['wp_speculation_rules_configuration'] ?? [] as $callback ) {
	$config = $callback( [ 'mode' => 'prefetch', 'eagerness' => 'conservative' ] );
}
ok( 'preload: eagerness is turned up to moderate', 'moderate' === ( $config['eagerness'] ?? '' ) );

report();
