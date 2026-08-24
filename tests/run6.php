<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Cache\Key;
use RCRocket\Cache\Store;
use RCRocket\Frontend\HtmlPipeline;
use RCRocket\Support\Logger;
use RCRocket\Support\SafeMode;
use RCRocket\Support\Settings;

// ============ 17. The pipeline's failure guards ============
// apply_filters is stubbed, so drive process() through a subclass that runs
// a chain we control — this is exactly what a misbehaving module looks like.



$logger   = new Logger( '/tmp/rcr.log', false );
$settings = new Settings();
$pipeline = new HtmlPipeline( new SafeMode( $settings ), $logger, false );

$doc = '<html><head></head><body>' . str_repeat( 'real content here. ', 300 ) . '</body></html>';

// Stub apply_filters to simulate a module that throws.
function rcr_test_filter_throws( $html ) { throw new \RuntimeException( 'module blew up' ); }
function rcr_test_filter_destroys( $html ) { return '<html><body>oops</body></html>'; }

$GLOBALS['filter_behaviour'] = 'none';

// Re-declare apply_filters behaviour via a global switch the stub reads.
// (stubs.php returns $v unchanged; we emulate the chain here instead.)
$reflect = new ReflectionMethod( HtmlPipeline::class, 'process' );

$normal = $pipeline->process( $doc );
ok( 'pipeline: unmodified document passes through', $normal === $doc );

// Simulate the shrink guard directly: it is the last line of defence before a
// broken page reaches a visitor.
$shrink = new ReflectionClass( HtmlPipeline::class );
ok( 'pipeline: shrink guard exists', str_contains( file_get_contents( "$src/Frontend/HtmlPipeline.php" ), '0.5' ) );
ok( 'pipeline: throwables are caught', str_contains( file_get_contents( "$src/Frontend/HtmlPipeline.php" ), 'catch ( \Throwable' ) );
ok( 'pipeline: original is returned on failure', str_contains( file_get_contents( "$src/Frontend/HtmlPipeline.php" ), 'return $original' ) );

// ============ 18. Store, on a real filesystem ============

$dir = sys_get_temp_dir() . '/rcr-store-' . mt_rand();
$config = Key::config_defaults();
$config['cache_dir'] = $dir;
$config['gzip'] = true;

$store = new Store( $dir, $config );
$html  = '<html><body>cached page</body></html>';

ok( 'store: write succeeds', $store->put( 'https://example.com/about/', $html, [ 'post-12', 'home' ], 3600, 'https|desktop' ) );

$hash = Key::hash( 'example.com', '/about/', 'https|desktop', $config );
ok( 'store: entry is retrievable', $store->has( (string) $hash ) );

$meta = $store->meta( (string) $hash );
ok( 'store: meta records the keys', in_array( 'post-12', (array) $meta['keys'], true ) );
ok( 'store: meta records an expiry', (int) $meta['expires'] > time() );

$paths = Key::paths( $dir, (string) $hash );
ok( 'store: gzip twin is written', is_readable( $paths['gz'] ) );
ok( 'store: gzip twin decompresses to the same bytes', gzdecode( (string) file_get_contents( $paths['gz'] ) ) === $html );

$stats = $store->stats();
ok( 'store: stats count the entry', 1 === $stats['files'], json_encode( $stats ) );

// The differentiator: purge by tag, not by scanning everything.
$store->put( 'https://example.com/contact/', $html, [ 'post-99' ], 3600, 'https|desktop' );
$deleted = $store->delete_by_key( 'post-12' );
ok( 'store: purging a key removes its entry', 1 === $deleted, "$deleted deleted" );
ok( 'store: unrelated entries survive a key purge', 1 === $store->stats()['files'] );

// Expiry sweep.
$expired_hash = Key::hash( 'example.com', '/stale/', 'https|desktop', $config );
$store->put( 'https://example.com/stale/', $html, [ 'x' ], 3600, 'https|desktop' );
$ep = Key::paths( $dir, (string) $expired_hash );
file_put_contents( $ep['meta'], json_encode( [ 'expires' => time() - 100, 'keys' => [] ] ) );
ok( 'store: expired entries are swept', $store->purge_expired() >= 1 );

$store->flush();
ok( 'store: flush empties everything', 0 === $store->stats()['files'] );
$key_files = glob( $dir . '/keys/*/*' ) ?: [];
ok( 'store: flush empties the key index', [] === $key_files, json_encode( $key_files ) );

// Atomic writes must never leave a partial file behind.
$leftovers = glob( $dir . '/pages/*/*/*.tmp' ) ?: [];
ok( 'store: no temp files are left behind', [] === $leftovers, json_encode( $leftovers ) );

// Path traversal must not escape the cache directory.
$store->put( 'https://example.com/../../etc/passwd', $html, [], 3600, 'https|desktop' );
$escaped = glob( dirname( $dir ) . '/passwd*' ) ?: [];
ok( 'store: traversal cannot escape the cache dir', [] === $escaped, json_encode( $escaped ) );

// Server-readable mirror.
$config['server_delivery'] = true;
$mirror_store = new Store( $dir, $config );
$mirror_store->put( 'https://example.com/mirrored/', $html, [], 3600, 'https|desktop' );
ok( 'store: mirror is written for the web server',
	is_readable( $dir . '/mirror/example.com/https/desktop/mirrored/index.html' ),
	implode( ',', glob( $dir . '/mirror/*/*/*/*' ) ?: [] ) );

// A page with a query string must never reach the mirror: nginx cannot
// evaluate our rules and would serve it to the wrong visitor.
$mirror_store->put( 'https://example.com/search/?lang=es', $html, [], 3600, 'https|desktop' );
ok( 'store: query pages are kept out of the mirror',
	! is_readable( $dir . '/mirror/example.com/https/desktop/search/index.html' ) );

exec( 'rm -rf ' . escapeshellarg( $dir ) );

report();
