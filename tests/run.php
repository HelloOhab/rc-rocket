<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Cache\Key;
use RCRocket\Support\Context;
use RCRocket\Media\Video;
use RCRocket\Media\Embeds;
use RCRocket\Support\Logger;

// ============================ 1. Cache key ============================

$config = Key::config_defaults();
$config['query_whitelist'] = [ 'p', 'page_id', 'lang' ];

$server = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/about/', 'HTTP_HOST' => 'example.com', 'HTTPS' => 'on' ];

ok( 'key: plain GET is cacheable', null === Key::bypass_reason( $server, [], $config ) );
ok( 'key: POST bypasses', null !== Key::bypass_reason( [ 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/' ], [], $config ) );
$admin_req = array_merge( $server, [ 'REQUEST_URI' => '/wp-admin/edit.php' ] );
ok( 'key: wp-admin bypasses', 'reserved-path' === Key::bypass_reason( $admin_req, [], $config ), (string) Key::bypass_reason( $admin_req, [], $config ) );
ok( 'key: logged-in cookie bypasses', 'private-cookie' === Key::bypass_reason( $server, [ 'wordpress_logged_in_abc' => '1' ], $config ) );
ok( 'key: woo cart cookie bypasses', 'private-cookie' === Key::bypass_reason( $server, [ 'woocommerce_items_in_cart' => '1' ], $config ) );

// Tracking parameters must not fragment the cache.
$a = Key::hash( 'example.com', '/about/', 'https|desktop', $config );
$b = Key::hash( 'example.com', '/about/?utm_source=fb&fbclid=xyz', 'https|desktop', $config );
ok( 'key: tracking params do not change the key', $a === $b, "$a vs $b" );

$c = Key::hash( 'example.com', '/about/?lang=es', 'https|desktop', $config );
ok( 'key: whitelisted param does change the key', $a !== $c );
ok( 'key: unknown param is uncacheable', null === Key::hash( 'example.com', '/about/?sessionid=9', 'https|desktop', $config ) );

$trailing  = Key::hash( 'example.com', '/about', 'https|desktop', $config );
ok( 'key: trailing slash is the same page', $a === $trailing );

ok( 'key: mobile is a separate bucket',
	Key::variant( array_merge( $server, [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; Mobile)' ] ), [], $config )
	!== Key::variant( array_merge( $server, [ 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows)' ] ), [], $config ) );

$paths = Key::paths( '/cache', 'abcdef0123456789' );
ok( 'key: paths are sharded', str_contains( $paths['html'], '/pages/ab/cd/' ), $paths['html'] );
ok( 'key: wildcard exclusion matches', Key::matches( '/go/*', '/go/offer' ) );
ok( 'key: wildcard exclusion does not overmatch', ! Key::matches( '/go/*', '/gone' ) );
ok( 'key: regex exclusion matches', Key::matches( '#^/shop/.+#', '/shop/thing' ) );

// ============================ 2. Context ============================

$GLOBALS['ctx'] = [ 'front_page' => true, 'singular' => true, 'object_id' => 12, 'post_type' => 'page' ];
$context = new Context();
$tokens  = $context->tokens();

ok( 'context: front page token', in_array( 'front_page', $tokens, true ) );
ok( 'context: post token', in_array( 'post:12', $tokens, true ) );
ok( 'context: post type token', in_array( 'singular:page', $tokens, true ) );
ok( 'context: signature prefers front page', 'front_page' === $context->signature() );
ok( 'context: matches a token', $context->matches( [ 'singular:page' ] ) );
ok( 'context: does not match an absent token', ! $context->matches( [ 'singular:post' ] ) );

$_SERVER['REQUEST_URI'] = '/blog/hello/';
$GLOBALS['ctx'] = [ 'singular' => true, 'object_id' => 40, 'post_type' => 'post' ];
$context2 = new Context();
ok( 'context: url target matches', $context2->matches( [ 'url:/blog/hello' ] ) );
ok( 'context: regex target matches', $context2->matches( [ 'regex:#^/blog/#' ] ) );
ok( 'context: signature collapses posts', 'singular:post' === $context2->signature() );

// ============================ 3. Video ============================

$video   = new Video( $context );
$vconfig = Video::defaults();
$vconfig['withhold'] = true; $vconfig['preload_none'] = true;
$vconfig['posters'] = [ [ 'template' => '', 'url' => 'https://example.com/poster.jpg' ] ];

$out4 = $video->rewrite( DIVI4_VIDEO, $vconfig );
ok( 'video: Divi 4 background is detected', str_contains( $out4, 'data-rcr-video' ), 'Divi 4 markup was not matched' );
ok( 'video: Divi 4 gets a poster', str_contains( $out4, 'poster="https://example.com/poster.jpg"' ) );
ok( 'video: Divi 4 source is detached', str_contains( $out4, 'data-rcr-src=' ) && ! preg_match( '#<source[^>]*\ssrc=#', $out4 ) );
ok( 'video: Divi 4 gets preload none', str_contains( $out4, 'preload="none"' ) );
ok( 'video: loader is emitted', str_contains( $out4, 'rcr-bg-video' ) );

$out5 = $video->rewrite( DIVI5_VIDEO, $vconfig );
ok( 'video: Divi 5 background is detected', str_contains( $out5, 'data-rcr-video' ) );
ok( 'video: Divi 5 source is detached', ! preg_match( '#<source[^>]*\ssrc=#', $out5 ) );

$outc = $video->rewrite( CONTENT_VIDEO, $vconfig );
ok( 'video: a controlled video is left alone', ! str_contains( $outc, 'data-rcr-video' ), 'content video was gated' );

// The safety rule: no poster means no gating.
$noposter = Video::defaults();
$out_np   = $video->rewrite( DIVI5_VIDEO, $noposter );
ok( 'video: without a poster nothing is withheld', ! str_contains( $out_np, 'data-rcr-video' ), 'gated with no fallback image' );
ok( 'video: without a poster sources stay attached', preg_match( '#<source[^>]*\ssrc=#', $out_np ) === 1 );

// Idempotence: the pipeline must survive being run twice.
$twice = $video->rewrite( $video->rewrite( DIVI4_VIDEO, $vconfig ), $vconfig );
ok( 'video: rewriting twice adds one marker', substr_count( $twice, 'data-rcr-video="1"' ) === 1, substr_count( $twice, 'data-rcr-video="1"' ) . ' markers' );
ok( 'video: rewriting twice adds one loader', substr_count( $twice, '<script id="rcr-bg-video">' ) === 1, substr_count( $twice, '<script id="rcr-bg-video">' ) . ' loaders' );

// ============================ 4. Embeds ============================

$logger = new Logger( '/tmp/rcr.log', false );
$embeds = new Embeds( $logger );
$econf  = Embeds::defaults() + Video::defaults();
$econf['embed_posters'] = [ [ 'id' => '1210835154', 'url' => 'https://example.com/vimeo-poster.jpg' ] ];

$outv = $embeds->rewrite( VIMEO_BACKGROUND, $econf );
ok( 'embeds: vimeo background is detected', str_contains( $outv, 'data-rcr-embed="background"' ), 'not matched' );
ok( 'embeds: vimeo src is detached', str_contains( $outv, 'data-rcr-src' ) && ! preg_match( '#<iframe[^>]*\ssrc=#', $outv ) );
ok( 'embeds: dnt is added', str_contains( $outv, 'dnt=1' ) );
ok( 'embeds: quality is capped', str_contains( $outv, 'quality=540p' ) );

// No poster available and none configured: leave it completely alone.
$econf2 = Embeds::defaults() + Video::defaults();
$outv2  = $embeds->rewrite( VIMEO_CONTENT, $econf2 );
ok( 'embeds: no poster means no facade', ! str_contains( $outv2, 'data-rcr-embed' ), 'faded with no poster available' );

// A malformed list in the drop-in config (an array from a bad import) must
// be dropped, not fatal every cached request before WordPress loads.
$badcfg = Key::config_defaults();
$badcfg['vary_cookies']  = [ [ 'x' ], 'lang' ];
$badcfg['excluded_uris'] = [ [ 'y' ] ];
ok( 'key: nested vary cookie is ignored', str_ends_with( Key::variant( [ 'HTTP_USER_AGENT' => 'x' ], [ 'lang' => 'en' ], $badcfg ), '|lang=' . substr( md5( 'en' ), 0, 8 ) ) );
ok( 'key: nested exclusion is ignored', null === Key::bypass_reason( [ 'REQUEST_URI' => '/a/', 'REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => 'x' ], [], $badcfg ) );

// Section markup inside a script is not a section and takes no eager slot.
$bg = '<html><head></head><body><script>var t = \'<div class="et_pb_section x">\';</script>';
for ( $i = 0; $i < 5; $i++ ) {
	$bg .= '<div class="et_pb_section s' . $i . '"></div>';
}
$bgout = ( new RCRocket\Media\Backgrounds() )->rewrite( $bg . '</body></html>' );
ok( 'backgrounds: scripts are left alone', str_contains( $bgout, 'et_pb_section x">' ) );
ok( 'backgrounds: the first three real sections are eager', str_contains( $bgout, 's2 rcr-bg-in' ) && ! str_contains( $bgout, 's3 rcr-bg-in' ) );

// Tracking cookies are matched by exact name, never by prefix.
require_once __DIR__ . '/../src/Cache/TrackingCookies.php';
ok( 'cookies: name is read from a Set-Cookie line', '_fbp' === RCRocket\Cache\TrackingCookies::cookie_name( 'Set-Cookie: _fbp=fb.1.1; expires=x; path=/' ) );
ok( 'cookies: a similar name is a different cookie', '_fbp_consent' === RCRocket\Cache\TrackingCookies::cookie_name( 'set-cookie: _fbp_consent=1' ) );

// Lazy-load exclusions: skip-lazy is a class name, not a substring.
require_once __DIR__ . '/../src/Media/MediaModule.php';
$ex = [ 'skip-lazy', 'no-lazy', 'et_pb_menu__logo', 'hero.jpg' ];
ok( 'exclusions: skip-lazy class is excluded', RCRocket\Media\MediaModule::excluded( ' class="a skip-lazy b" src="x.jpg"', $ex ) );
ok( 'exclusions: Divi Supreme dsm-skip-lazyload is not', ! RCRocket\Media\MediaModule::excluded( ' class="dsm-skip-lazyload" src="x.jpg"', $ex ) );
ok( 'exclusions: no-lazy inside another class is not', ! RCRocket\Media\MediaModule::excluded( ' class="has-no-lazyness" src="x.jpg"', $ex ) );
ok( 'exclusions: file names still match anywhere', RCRocket\Media\MediaModule::excluded( ' src="/uploads/my-hero.jpg"', $ex ) );
ok( 'exclusions: Divi menu logo class still matches', RCRocket\Media\MediaModule::excluded( ' class="et_pb_menu__logo-img"', $ex ) );

report();
