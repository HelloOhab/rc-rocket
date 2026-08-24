<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';
define( 'ET_CORE_VERSION', '4.27.4' );

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Container;
use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviOptimizer;
use RCRocket\Media\MediaModule;
use RCRocket\Js\JsModule;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

// Build a real container the way Plugin does, so wiring is exercised too.
$settings = new Settings();
$logger   = new Logger( '/tmp/rcr.log', false );

$c = new Container();
$c->set( 'settings', fn() => $settings );
$c->set( 'logger', fn() => $logger );
$c->set( 'context', fn() => new Context() );
$c->set( 'safe_mode', fn( $c ) => new SafeMode( $c->get( 'settings' ) ) );
$c->set( 'divi', fn( $c ) => new Divi( $c->get( 'logger' ), [] ) );

$media = new MediaModule();
$js    = new JsModule();
$settings->add_defaults( 'media', $media->defaults() );
$settings->add_defaults( 'js', $js->defaults() );
$media->register( $c );
$js->register( $c );

ok( 'container: lazy service resolves', $c->get( 'media.video' ) instanceof RCRocket\Media\Video );
ok( 'container: same instance is reused', $c->get( 'media.video' ) === $c->get( 'media.video' ) );
ok( 'container: unknown service throws', (function () use ( $c ) {
	try { $c->get( 'nope' ); return false; } catch ( \RuntimeException $e ) { return true; }
})() );

// ==================== 11. Media rewriting ====================

$reflect = new ReflectionMethod( MediaModule::class, 'rewrite' );
$reflect->setAccessible( true );
$GLOBALS['ctx'] = [ 'front_page' => true ];
$out = $reflect->invoke( $media, IMAGE_MARKUP, $c );

preg_match_all( '#<img\b[^>]*>#i', $out, $imgs );
$imgs = $imgs[0];

ok( 'media: first image is high priority', str_contains( $imgs[0], 'fetchpriority="high"' ), $imgs[0] );
ok( 'media: first image is not lazy', ! str_contains( $imgs[0], 'loading="lazy"' ) );
ok( 'media: second image is also eager', str_contains( $imgs[1], 'fetchpriority="high"' ) );
ok( 'media: excluded image is untouched', ! str_contains( $imgs[2], 'loading=' ), $imgs[2] );
ok( 'media: later image is lazy', str_contains( $imgs[3], 'loading="lazy"' ), $imgs[3] );
ok( 'media: existing dimensions are kept once', substr_count( $imgs[3], 'width=' ) === 1 );
ok( 'media: existing loading attribute is respected', ! str_contains( $imgs[4], 'loading="lazy"' ), $imgs[4] );
ok( 'media: iframes are lazy', str_contains( $out, '<iframe' ) && str_contains( $out, 'loading="lazy"' ) );
ok( 'media: decoding is set', str_contains( $out, 'decoding=' ) );

// Idempotence.
$twice = $reflect->invoke( $media, $out, $c );
ok( 'media: rewriting twice adds nothing', substr_count( $twice, 'fetchpriority="high"' ) === substr_count( $out, 'fetchpriority="high"' ),
	substr_count( $out, 'high' ) . ' vs ' . substr_count( $twice, 'high' ) );

// Malformed markup must not destroy the document.
$broken = '<body><img src="a.jpg" <img src="b.jpg"></body>';
$safe_out = $reflect->invoke( $media, $broken, $c );
ok( 'media: malformed markup survives', strlen( $safe_out ) >= strlen( $broken ) - 5 );

// ==================== 12. Script delay ====================

$settings->merge( [ 'js' => [ 'delay' => true, 'exclusions' => RCRocket\Assets\Presets::js_exclusions() ] ] );
$delay = new ReflectionMethod( JsModule::class, 'delay_scripts' );
$delay->setAccessible( true );
$dout = $delay->invoke( $js, SCRIPT_MARKUP, $c );

ok( 'delay: jquery is never delayed', ! preg_match( '#<script type="rcrocket/delayed"[^>]*jquery\.min#', $dout ), 'jquery was delayed' );
ok( 'delay: divi custom script is never delayed', ! preg_match( '#rcrocket/delayed[^>]*divi-custom-script#', $dout ) );
ok( 'delay: our own beacon is never delayed', ! preg_match( '#rcrocket/delayed[^>]*rcr-beacon#', $dout ) );
ok( 'delay: json-ld is never delayed', str_contains( $dout, 'application/ld+json' ) );
ok( 'delay: a third-party script is delayed', str_contains( $dout, 'rcrocket/delayed' ), 'nothing was delayed at all' );
ok( 'delay: delayed src is detached', ! preg_match( '#rcrocket/delayed[^>]*\ssrc=#', $dout ) );
ok( 'delay: loader is emitted once', substr_count( $dout, 'rcr-delay-loader' ) === 1 );

// Divi 4 must never have inline scripts delayed.
ok( 'delay: divi 4 inline script is left alone',
	preg_match( '#<script>var inline#', $dout ) === 1, 'inline script was delayed on Divi 4' );

// ==================== 13. Divi optimizer ====================

$GLOBALS['ctx'] = [ 'singular' => true, 'object_id' => 5, 'post_type' => 'page' ];
$post = new WP_Post(); $post->ID = 5; $post->post_content = DIVI4_SHORTCODE;
$GLOBALS['posts'][5] = $post;

$divi = $c->get( 'divi' );
$opt  = new DiviOptimizer( $divi, new Context(), $logger, [ 'unload_modules' => true ] );

$modules = $opt->modules_in_play();
ok( 'optimizer: gallery module is detected', in_array( 'et_pb_gallery', $modules, true ), json_encode( $modules ) );
ok( 'optimizer: toggle module is detected', in_array( 'et_pb_toggle', $modules, true ) );
ok( 'optimizer: absent module is absent', ! in_array( 'et_pb_circle_counter', $modules, true ) );

$GLOBALS['dequeued'] = [];
$opt->unload();
ok( 'optimizer: unused circle counter library is dropped', in_array( 'script:easypiechart', $GLOBALS['dequeued'], true ),
	json_encode( $GLOBALS['dequeued'] ) );
ok( 'optimizer: lightbox is kept because a gallery is present', ! in_array( 'script:magnific-popup', $GLOBALS['dequeued'], true ) );
ok( 'optimizer: hashchange is kept because a toggle is present', ! in_array( 'script:hashchange', $GLOBALS['dequeued'], true ) );

// Failed detection must unload nothing at all.
$GLOBALS['posts'][5]->post_content = 'plain content, no builder';
$GLOBALS['post_meta'] = [];
$opt2 = new DiviOptimizer( $divi, new Context(), $logger, [ 'unload_modules' => true ] );
$GLOBALS['dequeued'] = [];
$opt2->unload();
ok( 'optimizer: no detection means no unloading', [] === $GLOBALS['dequeued'],
	'unloaded on an empty detection: ' . json_encode( $GLOBALS['dequeued'] ) );

report();
