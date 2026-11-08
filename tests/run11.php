<?php
require __DIR__ . '/stubs.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Assets\CssPurger;
use RCRocket\Assets\UsedCss;

// ============ 30. Remove unused CSS: which rules survive ============

$page = '<html><body class="home et_pb_pagebuilder_layout"><div id="main" class="et_pb_row hero">'
	. '<a class="btn md:flex" href="#">Get a quote</a><svg></svg></div>'
	. '<script>el.classList.add("et-animated")</script></body></html>';

$purger = new CssPurger( CssPurger::words( $page ), [ 'keep-me' ] );
$out    = $purger->purge(
	CssPurger::absolutize_urls(
		'/* c */ @charset "utf-8"; :root{--a:1} html,body{margin:0} .hero .btn:hover{color:red} .unused{color:blue}'
		. ' .hero,.nope{padding:1px} #main>a::before{content:"{;}"} #other{x:y} table td{a:b} .et-animated{opacity:1}'
		. ' @media (max-width:980px){.hero{a:b}.gone{c:d}} @media print{.gone{c:d}} @supports (display:grid){@media screen{.btn{x:1}}}'
		. ' @keyframes fade{from{opacity:0}to{opacity:1}} @keyframes spin{to{transform:rotate(1turn)}} .hero{animation:fade 1s}'
		. ' .btn:is(.a,.b){q:1} [data-x]{z:1} .md\\:flex{display:flex} .keep-me-please{k:1}'
		. ' @font-face{font-family:X;src:url(../fonts/x.woff2)} .bg{background:url("img/a.png")}',
		'https://example.com/wp-content/themes/Divi/css/style.css?ver=1'
	)
);

ok( 'ucss: rules for elements on the page stay', str_contains( $out, '.hero .btn:hover{color:red}' ) );
ok( 'ucss: rules for absent classes go', ! str_contains( $out, '.unused' ) && ! str_contains( $out, '#other' ) && ! str_contains( $out, 'table td' ) );
ok( 'ucss: a selector list keeps only its matching selectors', str_contains( $out, '.hero{padding:1px}' ) && ! str_contains( $out, '.nope' ) );
ok( 'ucss: braces inside strings do not end a rule', str_contains( $out, 'content:"{;}"' ) );
ok( 'ucss: classes a script adds are kept', str_contains( $out, '.et-animated{opacity:1}' ) );
ok( 'ucss: media queries keep their matching rules', str_contains( $out, '@media (max-width:980px){.hero{a:b}}' ) );
ok( 'ucss: an emptied media query disappears', ! str_contains( $out, '@media print' ) );
ok( 'ucss: nested at-rules survive', str_contains( $out, '@supports (display:grid){@media screen{.btn{x:1}}}' ) );
ok( 'ucss: used keyframes stay, unused go', str_contains( $out, '@keyframes fade' ) && ! str_contains( $out, 'spin' ) );
ok( 'ucss: functional and attribute selectors are kept when unsure', str_contains( $out, '.btn:is(.a,.b)' ) && str_contains( $out, '[data-x]{z:1}' ) );
ok( 'ucss: escaped class names match', str_contains( $out, '.md\\:flex{display:flex}' ) );
ok( 'ucss: the safelist keeps a selector', str_contains( $out, '.keep-me-please' ) );
ok( 'ucss: fonts and variables stay', str_contains( $out, '@font-face' ) && str_contains( $out, ':root{--a:1}' ) );
ok( 'ucss: relative font addresses become absolute', str_contains( $out, 'url(https://example.com/wp-content/themes/Divi/fonts/x.woff2)' ) );
ok( 'ucss: comments are removed', ! str_contains( $out, '/* c */' ) );

// Malformed CSS never loses the rest of the file.
$broken = ( new CssPurger( CssPurger::words( '<div class="a b">' ) ) )->purge( '.a{x:1} .b{y:2' );
ok( 'ucss: an unclosed rule keeps the remainder', str_contains( $broken, '.a{x:1}' ) && str_contains( $broken, '.b{y:2' ) );

// ============ 31. Remove unused CSS: which stylesheets are handled ============

$html = '<head>'
	. '<link rel="stylesheet" id="divi-css" href="https://example.com/wp-content/themes/Divi/style.css?ver=4.27" media="all">'
	. '<link rel=\'stylesheet\' href="/wp-content/plugins/wpforms/full.min.css?ver=2">'
	. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
	. '<link rel="stylesheet" href="https://example.com/print.css" media="print">'
	. '<link rel="stylesheet" href="https://example.com/wide.css" media=\'(min-width: 601px)\'>'
	. '<link rel="stylesheet" data-no-optimize="1" href="https://example.com/keep.css">'
	. '<link rel="preload" as="style" href="https://example.com/async.css">'
	. '</head>';
$links = UsedCss::stylesheets( $html );
$hrefs = array_column( $links, 'href' );

ok( 'ucss: own stylesheets are found', in_array( 'https://example.com/wp-content/themes/Divi/style.css?ver=4.27', $hrefs, true ) );
ok( 'ucss: root-relative stylesheets count as own', in_array( '/wp-content/plugins/wpforms/full.min.css?ver=2', $hrefs, true ) );
ok( 'ucss: other hosts are left alone', ! in_array( 'https://fonts.googleapis.com/css2?family=Inter', $hrefs, true ) );
ok( 'ucss: print stylesheets are left alone', ! in_array( 'https://example.com/print.css', $hrefs, true ) );
ok( 'ucss: data-no-optimize is respected', ! in_array( 'https://example.com/keep.css', $hrefs, true ) );
ok( 'ucss: a stylesheet for some screen sizes is included', in_array( 'https://example.com/wide.css', $hrefs, true ) );
ok( 'ucss: preloads are not stylesheets', 3 === count( $links ), count( $links ) . ' links' );
ok( 'ucss: offsets point at the tags', substr( $html, $links[0]['offset'], strlen( $links[0]['tag'] ) ) === $links[0]['tag'] );

// ============ 32. Remove unused CSS: first visit, build, later visits ============

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $p = '/' ) { return 'https://example.com' . $p; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://example.com/services/'; }
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $id ) { return false; }
}

$GLOBALS['upload_basedir'] = sys_get_temp_dir() . '/rcr-ucss-' . getmypid();
$GLOBALS['options']        = [];
$GLOBALS['ctx']            = [ 'singular' => true, 'object_id' => 7, 'post_type' => 'page' ];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/services/';

$settings = new RCRocket\Support\Settings();
$logger   = new RCRocket\Support\Logger( '/tmp/rcr.log', false );
$ucss     = new UsedCss( [ 'enabled' => true, 'safelist' => [] ], new RCRocket\Integrations\Divi( $logger, [] ), new RCRocket\Support\SafeMode( $settings ), $logger );

$theme  = 'https://example.com/wp-content/themes/t/style.css?ver=1';
$plugin = '/wp-content/plugins/p/p.css?ver=3';
$visit  = static fn( string $ver ) => '<html><head>'
	. '<link rel="stylesheet" id="t-css" href="' . str_replace( 'ver=1', 'ver=' . $ver, $theme ) . '" media="all">'
	. '<style>.inline{x:1}</style>'
	. '<link rel="stylesheet" id="p-css" href="' . $plugin . '">'
	. '</head><body><div class="hero"><a class="btn">Quote</a></div>'
	. '<script>document.body.classList.add("menu-open")</script></body></html>';
$page = $visit( '1' );

$GLOBALS['http'] = [
	'https://example.com/services/?rcr_ucss=1' => [ 'body' => $page ],
	$theme                                     => [ 'body' => '.hero{a:1}.unused-theme{b:2}.menu-open .hero{c:3}', 'headers' => [ 'content-type' => 'text/css' ] ],
	'https://example.com' . $plugin           => [ 'body' => '.btn{d:4}.unused-plugin{e:5}', 'headers' => [ 'content-type' => 'text/css' ] ],
];

$first = $ucss->rewrite( $page );
ok( 'ucss: a page with nothing built is served as it is', $first === $page );
ok( 'ucss: and queued for building', isset( array_values( (array) get_option( 'rcrocket_ucss_queue', [] ) )[0] ) && 'https://example.com/services/' === array_values( get_option( 'rcrocket_ucss_queue' ) )[0] );

$ucss->process_queue();
ok( 'ucss: the build loads the page with the feature skipped', in_array( 'https://example.com/services/?rcr_ucss=1', $GLOBALS['http_log'] ?? [], true ) );
ok( 'ucss: the queue is emptied', [] === get_option( 'rcrocket_ucss_queue', [] ) );

$later = $ucss->rewrite( $page );
ok( 'ucss: later visits get the used rules inlined', str_contains( $later, '<style id="rcr-used-css-' ) && str_contains( $later, '.hero{a:1}' ) && str_contains( $later, '.btn{d:4}' ) );
ok( 'ucss: unused rules are gone', ! str_contains( $later, 'unused-theme' ) && ! str_contains( $later, 'unused-plugin' ) );
ok( 'ucss: a class added by an inline script keeps its rule', str_contains( $later, '.menu-open .hero{c:3}' ) );
ok( 'ucss: the full stylesheet waits for an interaction', str_contains( $later, 'id="t-css" data-rcr-href="' . $theme . '"' ) );
ok( 'ucss: without JavaScript the full stylesheet still loads', str_contains( $later, '<noscript><link rel="stylesheet" id="t-css" href="' ) );
ok( 'ucss: the order of styles is kept', strpos( $later, '.hero{a:1}' ) < strpos( $later, '.inline{x:1}' ) && strpos( $later, '.inline{x:1}' ) < strpos( $later, '.btn{d:4}' ) );
ok( 'ucss: one loader', 1 === substr_count( $later, 'id="rcr-css-loader"' ) );
ok( 'ucss: a report counts the page', 1 === $ucss->report()['pages'] && $ucss->report()['after'] < $ucss->report()['before'] );

ok( 'ucss: a stylesheet for all screens gets a plain style', (bool) preg_match( '#<style id="rcr-used-css-\d+">\.hero\{a:1\}#', $later ) );

// A stylesheet for some screens only keeps that condition when inlined.
$narrow = str_replace( '<link rel="stylesheet" id="p-css" href="' . $plugin . '">', '<link rel="stylesheet" id="p-css" href="' . $plugin . '" media="screen and (max-width: 980px)">', $page );
$GLOBALS['http']['https://example.com/services/?rcr_ucss=1'] = [ 'body' => $narrow ];
$ucss->build( 'https://example.com/services/' );
ok( 'ucss: a media-limited stylesheet keeps its media', str_contains( $ucss->rewrite( $narrow ), 'media="screen and (max-width: 980px)">.btn{d:4}' ) );
$wide = str_replace( '<link rel="stylesheet" id="p-css" href="' . $plugin . '">', "<link rel='stylesheet' id='p-css' href='" . $plugin . "' media='(min-width: 601px)' />", $page );
$GLOBALS['http']['https://example.com/services/?rcr_ucss=1'] = [ 'body' => $wide ];
$ucss->build( 'https://example.com/services/' );
ok( 'ucss: a min-width stylesheet (WPForms layout) is inlined with its condition', str_contains( $ucss->rewrite( $wide ), 'media="(min-width: 601px)">.btn{d:4}' ) );
$GLOBALS['http']['https://example.com/services/?rcr_ucss=1'] = [ 'body' => $page ];
$ucss->build( 'https://example.com/services/' );

$changed = $visit( '2' );
ok( 'ucss: a changed stylesheet version serves the page as it is', $ucss->rewrite( $changed ) === $changed );
ok( 'ucss: and builds it again', 1 === count( (array) get_option( 'rcrocket_ucss_queue', [] ) ) );

$GLOBALS['ctx']['logged_in'] = true;
ok( 'ucss: logged-in visitors are never served trimmed CSS', $ucss->rewrite( $page ) === $page );
$GLOBALS['ctx']['logged_in'] = false;

$_SERVER['REQUEST_URI'] = '/services/?color=blue';
ok( 'ucss: an address with its own query is left alone', $ucss->rewrite( $page ) === $page );
$_SERVER['REQUEST_URI'] = '/services/?utm_source=ad';
ok( 'ucss: tracking parameters do not count', str_contains( $ucss->rewrite( $page ), 'rcr-used-css' ) );
$_SERVER['REQUEST_URI'] = '/services/';

$ucss->forget_post( 7 );
ok( 'ucss: saving the post forgets its CSS', $ucss->rewrite( $page ) === $page );

// ============ 33. Remove unused CSS: built by the RC Rocket CSS service ============

$service_url = 'https://rcr-css.example.app/api/used-css';
$with_service = new UsedCss(
	[ 'enabled' => true, 'safelist' => [ 'my-popup' ], 'service_url' => $service_url, 'service_key' => 'k3y' ],
	new RCRocket\Integrations\Divi( $logger, [] ),
	new RCRocket\Support\SafeMode( $settings ),
	$logger
);

$GLOBALS['http_posts'] = [];
$GLOBALS['http_post']  = [
	$service_url => [ 'body' => json_encode( [ 'stylesheets' => [ $theme => '.hero{from:service}', $plugin => '.btn{from:service}' ] ] ) ],
];
$with_service->build( 'https://example.com/services/' );
$sent = $GLOBALS['http_posts'][0] ?? [];
ok( 'service: the page address is sent, with the feature skipped', str_contains( (string) ( $sent['args']['body'] ?? '' ), '"url":"https:\/\/example.com\/services\/?rcr_ucss=1"' ) );
ok( 'service: with the key', 'Bearer k3y' === ( $sent['args']['headers']['Authorization'] ?? '' ) );
ok( 'service: and the safelist', str_contains( (string) ( $sent['args']['body'] ?? '' ), 'my-popup' ) );
$served = $with_service->rewrite( $page );
ok( 'service: its CSS is what the page gets', str_contains( $served, '.hero{from:service}' ) && str_contains( $served, '.btn{from:service}' ) );
ok( 'service: the report says who built it', 1 === $with_service->report()['service'] && true === $with_service->report()['service_on'] );

// The service down: the site builds the CSS itself, nothing is lost.
$GLOBALS['http_post'] = [ $service_url => [ 'code' => 504, 'body' => 'timeout' ] ];
$with_service->build( 'https://example.com/services/' );
$fallback = $with_service->rewrite( $page );
ok( 'service: when it fails the site builds the CSS', str_contains( $fallback, '.hero{a:1}' ) && ! str_contains( $fallback, 'from:service' ) );
ok( 'service: and the report says so', 0 === $with_service->report()['service'] );

// Only over HTTPS: a key is never sent in the clear.
ok( 'service: an http:// address is ignored', '' === UsedCss::service_url( [ 'service_url' => 'http://rcr-css.example.app/api/used-css' ] ) );

// The service key never leaves the site in an export, and survives an import.
$cfg = new RCRocket\Support\Settings();
$cfg->add_defaults( 'assets', [ 'unused_css' => [ 'enabled' => false, 'safelist' => [], 'service_url' => '', 'service_key' => '' ] ] );
$cfg->merge( [ 'assets' => [ 'unused_css' => [ 'service_url' => $service_url, 'service_key' => 's3cret' ] ] ] );
$exported = $cfg->export();
ok( 'service: an export leaves the key out', ! str_contains( $exported, 's3cret' ) && str_contains( $exported, 'rcr-css.example.app' ) );
$cfg->import( $exported );
ok( 'service: importing it keeps the site\'s key', 's3cret' === $cfg->get( 'assets.unused_css.service_key' ) );

// ============ 34. Remove unused CSS: as WP Rocket manages it ============

// Stored gzipped.
$ucss->build( 'https://example.com/services/' );
$files_now = glob( $ucss->directory() . '/*.json*' );
ok( 'ucss: prepared CSS is stored gzipped', 1 === count( $files_now ) && str_ends_with( $files_now[0], '.json.gz' ) && false !== gzdecode( file_get_contents( $files_now[0] ) ) );

// A file written by an earlier build, before gzip, is still read.
$gz     = $files_now[0];
$legacy = substr( $gz, 0, -3 );
file_put_contents( $legacy, gzdecode( file_get_contents( $gz ) ) );
unlink( $gz );
ok( 'ucss: an older uncompressed file is still used', str_contains( $ucss->rewrite( $page ), 'rcr-used-css' ) );
$ucss->forget_url( 'https://example.com/services/' );
ok( 'ucss: forgetting a page removes either file', [] === glob( $ucss->directory() . '/*.json*' ) );

// Password-protected pages are left alone.
$ucss->build( 'https://example.com/services/' );
$GLOBALS['ctx']['password_required'] = true;
ok( 'ucss: a password-protected page is never trimmed', $ucss->rewrite( $page ) === $page );
$GLOBALS['ctx']['password_required'] = false;

// An ordinary cache clear keeps the prepared CSS; a Theme Builder save does not.
$ucss->forget_on_purge( 'manual' );
ok( 'ucss: clearing the page cache keeps the prepared CSS', str_contains( $ucss->rewrite( $page ), 'rcr-used-css' ) );
$ucss->forget_on_purge( 'divi-theme-builder' );
ok( 'ucss: a Theme Builder save prepares every page again', $ucss->rewrite( $page ) === $page );

// A changed safelist prepares every page again; other settings do not.
$ucss->build( 'https://example.com/services/' );
$ucss->forget_on_safelist_change( [ 'assets' => [ 'unused_css' => [ 'safelist' => [ 'a' ] ] ], 'js' => [ 'x' => 1 ] ], [ 'assets' => [ 'unused_css' => [ 'safelist' => [ 'a' ] ] ], 'js' => [ 'x' => 2 ] ] );
ok( 'ucss: other setting changes keep the prepared CSS', str_contains( $ucss->rewrite( $page ), 'rcr-used-css' ) );
$ucss->forget_on_safelist_change( [ 'assets' => [ 'unused_css' => [ 'safelist' => [ 'a', 'b' ] ] ] ], [ 'assets' => [ 'unused_css' => [ 'safelist' => [ 'a' ] ] ] ] );
ok( 'ucss: a safelist change prepares every page again', $ucss->rewrite( $page ) === $page );

// The button: everything prepared again.
$ucss->build( 'https://example.com/services/' );
$ucss->clear();
ok( 'ucss: "Clear unused CSS" forgets every page', 0 === $ucss->report()['pages'] && [] === glob( $ucss->directory() . '/*.json*' ) );

// Failed builds wait 1, 2, 5, 10, 20 and 25 minutes, then three days.
$GLOBALS['http']['https://example.com/services/?rcr_ucss=1'] = [ 'code' => 500, 'body' => 'down' ];
$ucss->build( 'https://example.com/services/' );
$GLOBALS['options']['rcrocket_ucss_queue'] = [];
ok( 'ucss: a failed page is not retried on the next visit', $ucss->rewrite( $page ) === $page && [] === get_option( 'rcrocket_ucss_queue', [] ) );
ok( 'ucss: it is being retried, not failed yet', 1 === $ucss->report()['retrying'] && 0 === $ucss->report()['failed'] );
for ( $n = 0; $n < 6; $n++ ) {
	$ucss->build( 'https://example.com/services/' );
}
$report = $ucss->report();
ok( 'ucss: after 7 attempts the page counts as failed', 1 === $report['failed'] && 0 === $report['retrying'] );
ok( 'ucss: with its address and reason', 'https://example.com/services/' === ( $report['failures'][0]['url'] ?? '' ) && 'the page did not load' === ( $report['failures'][0]['reason'] ?? '' ) );
ok( 'ucss: and is tried again in three days', abs( ( $report['failures'][0]['next_retry'] ?? 0 ) - ( time() + 3 * DAY_IN_SECONDS ) ) < 5 );
$GLOBALS['http']['https://example.com/services/?rcr_ucss=1'] = [ 'body' => $page ];
$ucss->build( 'https://example.com/services/' );
ok( 'ucss: a later success clears the failure', 0 === $ucss->report()['failed'] && 1 === $ucss->report()['pages'] );

// Pages nobody opened for a month are cleaned up.
$index = get_option( 'rcrocket_ucss_index' );
foreach ( $index as $k => $e ) { $index[ $k ]['seen'] = time() - 31 * DAY_IN_SECONDS; }
update_option( 'rcrocket_ucss_index', $index );
foreach ( glob( $ucss->directory() . '/*.json*' ) as $f ) { touch( $f, time() - 31 * DAY_IN_SECONDS ); }
ok( 'ucss: the monthly clean-up removes unused pages', 1 === $ucss->cleanup() && 0 === $ucss->report()['pages'] && [] === glob( $ucss->directory() . '/*.json*' ) );

$ucss->build( 'https://example.com/services/' );
ok( 'ucss: recently used pages survive the clean-up', 0 === $ucss->cleanup() && 1 === $ucss->report()['pages'] );

$ucss->forget_all();
ok( 'ucss: clearing everything empties the folder', [] === glob( $ucss->directory() . '/*.json*' ) );
@unlink( $ucss->directory() . '/index.html' );
@rmdir( $ucss->directory() );
@rmdir( dirname( $ucss->directory() ) );
@rmdir( $GLOBALS['upload_basedir'] );

report();
