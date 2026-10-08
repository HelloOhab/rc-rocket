<?php
/** Multi-site concerns: 30 installs, two Divi generations, one config. */
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Assets\Fonts;
use RCRocket\Media\Embeds;
use RCRocket\Support\Logger;
use RCRocket\Support\Settings;

$logger = new Logger( '/tmp/rcr.log', false );

// ============ 19. Config as code: the same file across 30 sites ============

$a = new Settings();
$a->add_defaults( 'js', [ 'defer' => false, 'delay' => false, 'exclusions' => [ 'jquery' ] ] );
$a->add_defaults( 'assets', [ 'rules' => [], 'bloat' => [ 'emojis' => true, 'dashicons' => false ] ] );
$a->merge( [ 'js' => [ 'defer' => true ], 'assets' => [ 'bloat' => [ 'dashicons' => true ] ] ] );
$exported = $a->export();

$b = new Settings();
$b->add_defaults( 'js', [ 'defer' => false, 'delay' => false, 'exclusions' => [ 'jquery' ] ] );
$b->add_defaults( 'assets', [ 'rules' => [], 'bloat' => [ 'emojis' => true, 'dashicons' => false ] ] );
$GLOBALS['options'] = [];
$b->import( $exported );

ok( 'config: a scalar transfers', true === $b->get( 'js.defer' ) );
ok( 'config: a nested map transfers', true === $b->get( 'assets.bloat.dashicons' ) );
ok( 'config: exports are byte-identical after a round trip', $a->export() === $b->export() );
ok( 'config: export is human diffable', str_contains( $exported, "\n" ) && str_contains( $exported, '    ' ) );

// A newer plugin version adds a key. Importing an older file must not wipe it.
$c = new Settings();
$c->add_defaults( 'js', [ 'defer' => false, 'delay' => false, 'exclusions' => [ 'jquery' ], 'lazy_render' => true ] );
$c->add_defaults( 'assets', [ 'rules' => [], 'bloat' => [ 'emojis' => true, 'dashicons' => false ] ] );
$GLOBALS['options'] = [];
$c->import( $exported );
ok( 'config: an older file does not delete newer settings', true === $c->get( 'js.lazy_render' ),
	'importing an old export wiped a setting the file predates' );

// Asset rules are per-site by nature; confirm they survive transfer intact.
$d = new Settings();
$d->add_defaults( 'assets', [ 'rules' => [] ] );
$d->merge( [ 'assets' => [ 'rules' => [
	[ 'handle' => 'wpforms-full', 'kind' => 'style', 'scope' => 'except', 'targets' => [ 'singular:page' ] ],
] ] ] );
$roundtrip = json_decode( $d->export(), true );
ok( 'config: asset rules survive export', 'wpforms-full' === $roundtrip['assets']['rules'][0]['handle'] );
ok( 'config: rule targets survive export', [ 'singular:page' ] === $roundtrip['assets']['rules'][0]['targets'] );

// ============ 20. Fonts ============

$fonts = new Fonts( $logger, [ 'localize' => true, 'preload' => true ] );
ok( 'fonts: directory is under uploads', str_contains( $fonts->directory(), '/uploads/rc-rocket/fonts' ) );
ok( 'fonts: url matches the directory', str_contains( $fonts->directory_url(), '/uploads/rc-rocket/fonts' ) );

// With no network the fetch fails; the page must be returned untouched.
$page = '<html><head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans"></head><body>x</body></html>';
$out  = $fonts->rewrite( $page );
ok( 'fonts: a failed download leaves the page alone', str_contains( $out, 'fonts.googleapis.com' ),
	'the stylesheet was removed even though localization failed' );

$no_fonts = '<html><head></head><body>x</body></html>';
ok( 'fonts: pages without google fonts are untouched', $fonts->rewrite( $no_fonts ) === $no_fonts );

$report = $fonts->report();
ok( 'fonts: report is structured', isset( $report['stylesheets'], $report['files'], $report['directory'] ) );

// ============ 21. Embeds ============

$embeds = new Embeds( $logger );
$conf   = Embeds::defaults() + RCRocket\Media\Video::defaults();
$conf['embed_posters'] = [ [ 'id' => '1210835154', 'url' => 'https://example.com/p.jpg' ] ];

$out = $embeds->rewrite( VIMEO_BACKGROUND, $conf );
ok( 'embeds: placeholder carries the poster', str_contains( $out, 'url(&quot;https://example.com/p.jpg&quot;)' ) );
ok( 'embeds: background iframe is marked', str_contains( $out, 'data-rcr-embed="background" srcdoc=' ) );
ok( 'embeds: player source is withheld', str_contains( $out, 'data-rcr-src="https://player.vimeo.com/' ) && ! str_contains( $out, ' src="https://player.vimeo.com/' ) );
ok( 'embeds: no play button on a background', ! str_contains( $out, '<button type="button" class="rcr-embed__play"' ) );
ok( 'embeds: loader is injected once', substr_count( $out, 'id="rcr-embeds"' ) === 1 );

$twice = $embeds->rewrite( $out, $conf );
ok( 'embeds: rewriting twice is stable', $twice === $out,
	substr_count( $twice, 'data-rcr-embed="background" srcdoc' ) . ' markers' );

// A non-video iframe must never be touched.
$maps = '<body><iframe src="https://www.google.com/maps/embed?pb=x"></iframe></body>';
ok( 'embeds: unrelated iframes are untouched', $embeds->rewrite( $maps, $conf ) === $maps );

// YouTube goes to the cookieless domain.
$yt = '<body><iframe src="https://www.youtube.com/embed/abc123XYZ"></iframe></body>';
$conf['embed_posters'] = [ [ 'id' => 'abc123XYZ', 'url' => 'https://example.com/yt.jpg' ] ];
$yout = $embeds->rewrite( $yt, $conf );
ok( 'embeds: youtube facade is built', str_contains( $yout, 'data-rcr-embed="facade"' ) );
ok( 'embeds: youtube uses the cookieless domain', str_contains( $yout, 'youtube-nocookie.com' ) );
ok( 'embeds: facade has a labelled play button', str_contains( $yout, 'aria-label' ) );

report();
