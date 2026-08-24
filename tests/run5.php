<?php
/**
 * Adversarial pass. Everything here is a shape of input that has broken a
 * performance plugin on somebody's production site.
 */
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';
define( 'ET_CORE_VERSION', '5.11.1' );

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Container;
use RCRocket\Integrations\Divi;
use RCRocket\Media\MediaModule;
use RCRocket\Media\Video;
use RCRocket\Js\JsModule;
use RCRocket\Support\Context;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

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

$rewrite = new ReflectionMethod( MediaModule::class, 'rewrite' );
$rewrite->setAccessible( true );
$delay = new ReflectionMethod( JsModule::class, 'delay_scripts' );
$delay->setAccessible( true );
$GLOBALS['ctx'] = [ 'front_page' => true ];

// --- Inputs that must not cause a fatal, an infinite loop, or data loss ---

$hostile = [
	'empty string'                => '',
	'only whitespace'             => "   \n\t  ",
	'unclosed img'                => '<body><img src="a.jpg"',
	'img inside a comment'        => '<body><!-- <img src="a.jpg"> --></body>',
	'attribute containing gt'     => '<body><img src="a.jpg" alt="a > b"></body>',
	'single quoted attributes'    => "<body><img src='a.jpg' alt='x'></body>",
	'no quotes at all'            => '<body><img src=a.jpg></body>',
	'nested video tags'           => '<body><video autoplay loop><video autoplay loop></video></video></body>',
	'script with html inside'     => '<body><script>var s = "</scr" + "ipt>";</script></body>',
	'utf8 and emoji'              => '<body><img src="ü.jpg" alt="café 🎉"></body>',
	'very long attribute'         => '<body><img src="' . str_repeat( 'a', 20000 ) . '.jpg"></body>',
	'many images'                 => '<body>' . str_repeat( '<img src="x.jpg">', 500 ) . '</body>',
	'srcset only, no src'         => '<body><img srcset="a.jpg 1x, b.jpg 2x"></body>',
	'data uri image'              => '<body><img src="data:image/gif;base64,R0lGOD"></body>',
	'protocol relative'           => '<body><img src="//cdn.example.com/a.jpg"></body>',
	'video with no source'        => '<body><video autoplay loop poster="p.jpg"></video></body>',
	'malformed vimeo iframe'      => '<body><iframe src="https://player.vimeo.com/video/"></iframe></body>',
];

foreach ( $hostile as $label => $input ) {
	$failed = false;
	$out    = $input;

	try {
		$out = $rewrite->invoke( $media, $input, $c );
		$out = $delay->invoke( $js, $out, $c );
	} catch ( \Throwable $e ) {
		$failed = true;
		$note   = get_class( $e ) . ': ' . $e->getMessage();
	}

	ok( "hostile: survives $label", ! $failed, $note ?? '' );
	ok( "hostile: does not lose content on $label", strlen( $out ) >= (int) ( strlen( $input ) * 0.9 ),
		sprintf( '%d in, %d out', strlen( $input ), strlen( $out ) ) );
}

// --- Catastrophic backtracking: a regex that hangs is a downed site ---

$pathological = '<body>' . str_repeat( '<video autoplay loop ', 200 ) . '>' . str_repeat( '</video>', 200 ) . '</body>';
$start = microtime( true );
$video = new Video( new Context(), $c->get( 'divi' ) );
$video->rewrite( $pathological, Video::defaults() + [ 'posters' => [ [ 'url' => 'p.jpg' ] ] ] );
$elapsed = ( microtime( true ) - $start ) * 1000;
ok( 'hostile: video regex does not backtrack', $elapsed < 500, sprintf( '%.0fms', $elapsed ) );

$big = '<body>' . str_repeat( '<script src="https://third.party/a.js"></script>', 400 ) . '</body>';
$start = microtime( true );
$delay->invoke( $js, $big, $c );
$elapsed = ( microtime( true ) - $start ) * 1000;
ok( 'hostile: delay regex is fast on many scripts', $elapsed < 500, sprintf( '%.0fms', $elapsed ) );

$start = microtime( true );
$rewrite->invoke( $media, '<body>' . str_repeat( '<img src="x.jpg">', 2000 ) . '</body>', $c );
$elapsed = ( microtime( true ) - $start ) * 1000;
ok( 'hostile: image rewrite is fast on 2000 images', $elapsed < 1000, sprintf( '%.0fms', $elapsed ) );

// --- Never rewrite the Divi builder ---

$_GET['et_fb'] = '1';
$safe = new SafeMode( $settings );
ok( 'builder: visual builder is never optimized', ! $safe->should_optimize() );
unset( $_GET['et_fb'] );

$_GET['et_bfb'] = '1';
ok( 'builder: back-end builder is never optimized', ! ( new SafeMode( $settings ) )->should_optimize() );
unset( $_GET['et_bfb'] );

$GLOBALS['ctx'] = [ 'feed' => true ];
ok( 'builder: feeds are never optimized', ! ( new SafeMode( $settings ) )->should_optimize() );

$GLOBALS['ctx'] = [ 'admin' => true ];
ok( 'builder: admin is never optimized', ! ( new SafeMode( $settings ) )->should_optimize() );

$GLOBALS['ctx'] = [ 'logged_in' => true ];
$settings->add_defaults( 'general', [ 'skip_logged_in' => true, 'safe_mode' => false ] );
ok( 'builder: logged-in users are skipped by default', ! ( new SafeMode( $settings ) )->should_optimize() );

report();
