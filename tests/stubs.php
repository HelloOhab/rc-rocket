<?php
/**
 * Minimal WordPress surface, enough to execute RC Rocket's pure logic.
 * Anything a test needs to control is a global the test can set.
 */
define( 'ABSPATH', '/wp/' );
define( 'WP_CONTENT_DIR', '/wp/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'HYPERPERF_DIR', '/wp/wp-content/plugins/rc-rocket/' );
define( 'RCROCKET_DIR', '/wp/wp-content/plugins/rc-rocket/' );
define( 'RCROCKET_URL', 'https://example.com/wp-content/plugins/rc-rocket/' );
define( 'RCROCKET_BASENAME', 'rc-rocket/rc-rocket.php' );

$GLOBALS['options']   = [];
$GLOBALS['post_meta'] = [];
$GLOBALS['ctx']       = [];
$GLOBALS['posts']     = [];
$GLOBALS['filters']   = [];

function add_filter( $h, $c, $p = 10, $a = 1 ) { $GLOBALS['filters'][ $h ][] = $c; return true; }
function add_action( $h, $c, $p = 10, $a = 1 ) { return true; }
function remove_action( $h, $c, $p = 10 ) { return true; }
function do_action( $h, ...$a ) { return null; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function has_action( $h ) { return 0; }
function did_action( $h ) { return 0; }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_url_raw( $u ) { return (string) $u; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_js( $v ) { return addslashes( (string) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_attr__( $v, $d = '' ) { return $v; }
function __( $v, $d = '' ) { return $v; }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) { return $url; }
	$parts = parse_url( $url );
	parse_str( $parts['query'] ?? '', $q );
	$q = array_merge( $q, $args );
	return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ) . '?' . http_build_query( $q );
}
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['post_meta'][ $id ][ $k ] ?? ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['post_meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['post_meta'][ $id ][ $k ] ); return true; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function wp_get_current_user() { return (object) [ 'display_name' => 'tester' ]; }
function wp_generate_password( $l = 12, $s = true ) { return substr( md5( (string) mt_rand() ), 0, $l ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_using_ext_object_cache() { return false; }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) { return 0; }
function content_url( $p = '' ) { return 'https://example.com/wp-content' . ( $p ? '/' . ltrim( $p, '/' ) : '' ); }
function is_ssl() { return true; }
function size_format( $b, $d = 0 ) { return $b . 'B'; }
function human_time_diff( $a, $b ) { return '1 hour'; }
function wp_get_upload_dir() {
	return [ 'basedir' => '/wp/wp-content/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ];
}

// Conditional tags driven by $GLOBALS['ctx'].
function ctx( $k, $d = false ) { return $GLOBALS['ctx'][ $k ] ?? $d; }
function is_front_page() { return (bool) ctx( 'front_page' ); }
function is_home() { return (bool) ctx( 'home' ); }
function is_singular( $t = '' ) { return (bool) ctx( 'singular' ); }
function is_archive() { return (bool) ctx( 'archive' ); }
function is_category() { return (bool) ctx( 'category' ); }
function is_tag() { return (bool) ctx( 'tag' ); }
function is_tax() { return (bool) ctx( 'tax' ); }
function is_author() { return (bool) ctx( 'author' ); }
function is_search() { return (bool) ctx( 'search' ); }
function is_404() { return (bool) ctx( 'is404' ); }
function is_feed() { return (bool) ctx( 'feed' ); }
function is_date() { return false; }
function is_post_type_archive() { return false; }
function is_robots() { return false; }
function is_trackback() { return false; }
function is_preview() { return false; }
function is_customize_preview() { return false; }
function is_admin() { return (bool) ctx( 'admin' ); }
function is_user_logged_in() { return (bool) ctx( 'logged_in' ); }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function post_password_required() { return false; }
function get_queried_object_id() { return (int) ctx( 'object_id', 0 ); }
function get_queried_object() { return ctx( 'object', null ); }
function get_post_type( $id = 0 ) { return (string) ctx( 'post_type', 'page' ); }
function get_post_field( $f, $id ) { return 1; }
function get_object_taxonomies( $t ) { return []; }
function get_the_terms( $id, $tax ) { return []; }
function get_post_types( $a = [] ) { return [ 'post', 'page' ]; }
function get_bloginfo( $k ) { return 'Test'; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_mail( ...$a ) { return true; }
$GLOBALS['transients'] = [];
function get_transient( $k ) {
	$t = $GLOBALS['transients'][ $k ] ?? null;
	if ( null === $t || $t['expires'] < time() ) { return false; }
	return $t['value'];
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['transients'][ $k ] = [ 'value' => $v, 'expires' => time() + (int) $ttl ];
	return true;
}
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function current_user_can( $c ) { return true; }
function wp_remote_get( $u, $a = [] ) { return new WP_Error( 'offline', 'no network in tests' ); }
function wp_remote_retrieve_body( $r ) { return ''; }
function wp_remote_retrieve_response_code( $r ) { return 0; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_scripts() { return (object) [ 'done' => [], 'registered' => [] ]; }
function wp_styles() { return (object) [ 'done' => [], 'registered' => [] ]; }
function wp_dequeue_script( $h ) { $GLOBALS['dequeued'][] = 'script:' . $h; }
function wp_dequeue_style( $h ) { $GLOBALS['dequeued'][] = 'style:' . $h; }
function wp_deregister_script( $h ) {}
function wp_deregister_style( $h ) {}
function is_admin_bar_showing() { return false; }
function plugin_basename( $f ) { return ltrim( str_replace( '/wp/wp-content/plugins/', '', $f ), '/' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function get_site_transient( $k ) { return get_transient( $k ); }
function set_site_transient( $k, $v, $t = 0 ) { return set_transient( $k, $v, (int) $t ); }
function delete_site_transient( $k ) { return delete_transient( $k ); }
function wp_tempnam( $n = '' ) { return tempnam( sys_get_temp_dir(), 'rcr' ); }
function wpautop( $t ) { return '<p>' . $t . '</p>'; }
function wp_get_theme( $s = null ) {
	return new class {
		public function get( $k ) { return 'Divi' === $k || 'Name' === $k ? ( ctx( 'theme_name', 'Divi' ) ) : ctx( 'theme_version', '4.27.4' ); }
		public function parent() { return false; }
	};
}
function comments_open() { return false; }

class WP_Error { public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
class WP_Term { public $term_id = 0; public $taxonomy = ''; }
class WP_Post { public $ID = 0; public $post_content = ''; public $post_author = 1; public $post_status = 'publish'; public $post_type = 'page'; }

// --- tiny test framework -------------------------------------------------
$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0; $GLOBALS['failures'] = [];
function ok( string $name, bool $condition, string $note = '' ): void {
	if ( $condition ) { $GLOBALS['pass']++; return; }
	$GLOBALS['fail']++;
	$GLOBALS['failures'][] = $name . ( '' !== $note ? "  ->  $note" : '' );
}
function report(): void {
	echo "\n";
	foreach ( $GLOBALS['failures'] as $f ) { echo "FAIL  $f\n"; }
	printf( "\n%d passed, %d failed\n", $GLOBALS['pass'], $GLOBALS['fail'] );
	exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
}
