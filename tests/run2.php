<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Assets\Presets;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Integrations\Divi;
use RCRocket\Safety\History;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

// ==================== 5. HtmlPipeline safety guards ====================

$settings = new Settings();
$safe     = new SafeMode( $settings );
$logger   = new Logger( '/tmp/rcr.log', false );
$pipeline = new HtmlPipeline( $safe, $logger, true );

$doc = '<html><head><title>x</title></head><body>' . str_repeat( 'content ', 200 ) . '</body></html>';

$GLOBALS['filters'] = [];
ok( 'pipeline: non-html passes through', $pipeline->process( '{"json":true}' ) === '{"json":true}' );
ok( 'pipeline: short input passes through', $pipeline->process( '<html><body>hi</body></html>' ) === '<html><body>hi</body></html>' );

$signed = $pipeline->process( $doc );
ok( 'pipeline: marker is appended', str_contains( $signed, 'RC Rocket · optimized' ) );

// Insertion helpers.
ok( 'pipeline: inserts before body end', str_contains( HtmlPipeline::before_body_end( $doc, '<!--X-->' ), '<!--X--></body>' ) );
ok( 'pipeline: inserts after head start', preg_match( '#<head><!--Y-->#', HtmlPipeline::after_head_start( $doc, '<!--Y-->' ) ) === 1 );
ok( 'pipeline: dollar signs survive insertion',
	str_contains( HtmlPipeline::after_head_start( $doc, '<script>var a="$1$2";</script>' ), 'var a="$1$2"' ) );

// ==================== 6. Settings ====================

$settings->add_defaults( 'js', [ 'enabled' => true, 'defer' => false, 'exclusions' => [ 'a', 'b' ] ] );
ok( 'settings: default is readable', false === $settings->get( 'js.defer' ) );
ok( 'settings: dot path missing returns fallback', 'x' === $settings->get( 'js.nope', 'x' ) );

$settings->merge( [ 'js' => [ 'defer' => true ] ] );
ok( 'settings: merge sets a scalar', true === $settings->get( 'js.defer' ) );
ok( 'settings: merge preserves siblings', true === $settings->get( 'js.enabled' ) );

$settings->merge( [ 'js' => [ 'exclusions' => [ 'only' ] ] ] );
ok( 'settings: lists are replaced not merged', [ 'only' ] === $settings->get( 'js.exclusions' ),
	json_encode( $settings->get( 'js.exclusions' ) ) );

$settings->set( 'js.deep.nested.value', 7 );
ok( 'settings: deep set creates the path', 7 === $settings->get( 'js.deep.nested.value' ) );

$json = $settings->export();
ok( 'settings: export is valid json', is_array( json_decode( $json, true ) ) );
ok( 'settings: import rejects rubbish', false === $settings->import( 'not json' ) );

// ==================== 7. Safe mode ====================

$GLOBALS['options'] = [];
$s2   = new Settings();
$s2->add_defaults( 'general', [ 'safe_mode' => false, 'skip_logged_in' => true ] );
$sm   = new SafeMode( $s2 );

ok( 'safe mode: off by default', ! $sm->is_active() );

update_option( SafeMode::STATE_OPTION, [ 'until' => time() + 600, 'reason' => 'test' ] );
$sm2 = new SafeMode( $s2 );
ok( 'safe mode: auto state activates it', $sm2->is_active() );
ok( 'safe mode: reports the auto reason', 'auto' === $sm2->reason() );
ok( 'safe mode: exposes why', 'test' === $sm2->auto_reason() );

ok( 'safe mode: will not trip while already tripped', false === $sm2->trip( 'again', 60 ) );

$sm2->release();
$sm3 = new SafeMode( $s2 );
ok( 'safe mode: release clears it', ! $sm3->is_active() );
ok( 'safe mode: trip returns true when it fires', true === $sm3->trip( 'first', 60 ) );

// Critically: tripping must not write to settings, or history fills with noise.
$GLOBALS['options']['rcrocket_settings'] = [ 'general' => [ 'safe_mode' => false ] ];
$before = $GLOBALS['options']['rcrocket_settings'];
$sm4    = new SafeMode( $s2 );
$sm4->release();
$sm4->trip( 'noise check', 60 );
ok( 'safe mode: tripping does not touch settings', $before === $GLOBALS['options']['rcrocket_settings'],
	'settings were written by a trip' );

// URL escape hatch.
$_GET['rcr_safe'] = '1';
$sm5 = new SafeMode( $s2 );
ok( 'safe mode: ?rcr_safe=1 works', $sm5->is_active() && 'url' === $sm5->reason() );
unset( $_GET['rcr_safe'] );

// ==================== 8. History ====================

$GLOBALS['options'] = [];
$s3 = new Settings();
$h  = new History( $s3 );

$h->snapshot(
	[ 'js' => [ 'defer' => true ], 'general' => [ 'auto_safe_mode_until' => 99 ] ],
	[ 'js' => [ 'defer' => false ], 'general' => [ 'auto_safe_mode_until' => 0 ] ]
);
$entries = $h->listing();
ok( 'history: records a real change', 1 === count( $entries ) );

$paths = array_column( $entries[0]['changes'], 'path' );
ok( 'history: diff names the path', in_array( 'js.defer', $paths, true ) );
ok( 'history: booleans read as on and off', 'off' === $entries[0]['changes'][0]['from'] && 'on' === $entries[0]['changes'][0]['to'] );
ok( 'history: runtime state is excluded', ! in_array( 'general.auto_safe_mode_until', $paths, true ),
	'auto safe mode leaked into history' );

// A save that only changes runtime state must record nothing at all.
$h->snapshot( [ 'general' => [ 'auto_safe_mode_until' => 100 ] ], [ 'general' => [ 'auto_safe_mode_until' => 99 ] ] );
ok( 'history: a runtime-only save records nothing', 1 === count( $h->listing() ),
	count( $h->listing() ) . ' entries' );

// Pruning legacy noise.
update_option( 'rcrocket_history', array_merge(
	$h->all(),
	[ [ 'id' => 'x', 'time' => 1, 'user' => 'system', 'changes' => [ [ 'path' => 'general.auto_safe_mode_until', 'from' => '1', 'to' => '2' ] ], 'state' => [] ] ]
) );
ok( 'history: prune removes machine entries', 1 === ( new History( $s3 ) )->prune() );

// ==================== 9. Divi ====================

$GLOBALS['ctx'] = [ 'singular' => true, 'object_id' => 5, 'post_type' => 'page' ];
$post               = new WP_Post();
$post->ID           = 5;
$post->post_content = DIVI4_SHORTCODE;
$GLOBALS['posts'][5] = $post;

// Divi::is_active() gates the whole integration, so the test must look like
// a site with Divi installed.
define( 'ET_CORE_VERSION', '4.27.4' );

$divi = new Divi( $logger, [] );
$map  = $divi->background_media_map();

ok( 'divi: fallback image is paired with the video', 
	( $map['hero.mp4'] ?? '' ) === 'https://example.com/wp-content/uploads/2026/01/hero-fallback.jpg',
	json_encode( $map ) );
ok( 'divi: media key ignores query args', 'hero.mp4' === Divi::media_key( 'https://cdn.example.com/a/b/hero.mp4?_=1' ) );

// And the payoff: the poster resolves with nothing configured by the user.
$video    = new RCRocket\Media\Video( new Context(), $divi );
$auto     = $video->rewrite( DIVI4_VIDEO, RCRocket\Media\Video::defaults() );
ok( 'divi: poster resolves from Divi with no configuration',
	str_contains( $auto, 'poster="https://example.com/wp-content/uploads/2026/01/hero-fallback.jpg"' ),
	'automatic poster did not resolve' );
// Withholding the video is opt-in; by default it only gains the poster.
ok( 'divi: gating is off by default', ! str_contains( $auto, 'data-rcr-video' ) );
$gated = $video->rewrite( DIVI4_VIDEO, [ 'withhold' => true ] + RCRocket\Media\Video::defaults() );
ok( 'divi: and gating applies when asked for', str_contains( $gated, 'data-rcr-video' ) );

// ==================== 10. Presets ====================

ok( 'presets: jquery is protected', in_array( 'jquery', Presets::protected_handles(), true ) );
ok( 'presets: divi custom script is protected', in_array( 'divi-custom-script', Presets::protected_handles(), true ) );
ok( 'presets: divi 5 runtime is protected', in_array( 'divi-runtime', Presets::protected_handles(), true ) );
ok( 'presets: our own scripts are never delayed',
	in_array( 'rcr-bg-video', Presets::js_exclusions(), true ) && in_array( 'rcr-beacon', Presets::js_exclusions(), true ) );
ok( 'presets: owner is derived from a plugin path',
	'Divi Supreme' === Presets::owner_for( 'dsm-scripts', 'https://x/wp-content/plugins/divi-supreme/js/a.js' ) );
ok( 'presets: unknown handle falls back to its folder',
	str_contains( Presets::owner_for( 'zzz', 'https://x/wp-content/plugins/some-plugin/a.js' ), 'Some Plugin' ) );
ok( 'presets: lazy render skips the first sections',
	str_contains( implode( ' ', Presets::lazy_render_selectors() ), 'n+4' ) );

report();
