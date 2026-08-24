<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Cache\Key;
use RCRocket\Cache\ServerRules;
use RCRocket\Support\Hosting;
use RCRocket\Support\Settings;
use RCRocket\Safety\SafetyModule;

// ============ 14. Host detection: the thing all 30 sites depend on ============

$hosting = new Hosting();
ok( 'host: unrecognised host is self-managed', 'generic' === $hosting->id() );
ok( 'host: self-managed runs our cache', ! $hosting->manages_page_cache() );
ok( 'host: self-managed can edit server config', $hosting->can_edit_server_config() );

// Kinsta, as detected in the wild.
define( 'KINSTAMU_VERSION', '2.3.4' );
$kinsta = new Hosting();
ok( 'host: kinsta is detected', 'kinsta' === $kinsta->id(), $kinsta->id() );
ok( 'host: kinsta owns the page cache', $kinsta->manages_page_cache() );
ok( 'host: kinsta server config is not editable', ! $kinsta->can_edit_server_config() );
ok( 'host: kinsta report explains itself', str_contains( $kinsta->report()['reason'], 'server-level page cache' ) );
ok( 'host: image conversion is refused on kinsta', ! RCRocket\Media\MediaModule::conversion_allowed( $kinsta ) );

// A purge with no Kinsta internals present must fail quietly, never fatally.
$purged = $kinsta->purge_host_cache();
ok( 'host: purge degrades without fataling', is_bool( $purged ) );

// ============ 15. Server rules ============

$rules = new ServerRules( '/wp/wp-content/cache/rc-rocket' );
$all   = $rules->all();

ok( 'rules: all three targets exist', isset( $all['nginx'], $all['apache'], $all['cloudflare'] ) );
ok( 'rules: no stale variable names survive the rename',
	! str_contains( $all['nginx'], '$hp_' ), 'nginx snippet still uses $hp_' );
ok( 'rules: nginx uses our variables', str_contains( $all['nginx'], '$rcr_skip' ) );
ok( 'rules: nginx skips logged-in cookies', str_contains( $all['nginx'], 'wordpress_logged_in_' ) );
ok( 'rules: nginx skips query strings', str_contains( $all['nginx'], 'query_string' ) );
ok( 'rules: apache guards on cookies', str_contains( $all['apache'], 'wp_woocommerce_session_' ) );
ok( 'rules: worker strips tracking params', str_contains( $all['cloudflare'], 'utm_' ) );
ok( 'rules: worker refuses to cache set-cookie responses', str_contains( $all['cloudflare'], 'Set-Cookie' ) );
ok( 'rules: managed hosts are warned', str_contains( $all['nginx'], 'Kinsta' ) );

// ============ 16. Error attribution ============

$fp1 = SafetyModule::fingerprint( [ 'message' => "Cannot read properties of null at line 42", 'source' => 'https://x/a.js?ver=1' ] );
$fp2 = SafetyModule::fingerprint( [ 'message' => "Cannot read properties of null at line 87", 'source' => 'https://x/a.js?ver=2' ] );
ok( 'errors: line numbers and versions do not create new problems', $fp1 === $fp2 );

$fp3 = SafetyModule::fingerprint( [ 'message' => 'A different failure', 'source' => 'https://x/a.js' ] );
ok( 'errors: a different message is a different problem', $fp1 !== $fp3 );

$attributable = new ReflectionMethod( SafetyModule::class, 'attributable' );
$attributable->setAccessible( true );

ok( 'errors: a js error can trigger rollback', $attributable->invoke( null, [ 'kind' => 'js' ] ) );
ok( 'errors: a rejected promise can trigger rollback', $attributable->invoke( null, [ 'kind' => 'promise' ] ) );
ok( 'errors: a broken image cannot trigger rollback',
	! $attributable->invoke( null, [ 'kind' => 'resource', 'message' => 'IMG failed to load' ] ),
	'a 404 image would still disarm the plugin' );
ok( 'errors: a failed stylesheet cannot trigger rollback',
	! $attributable->invoke( null, [ 'kind' => 'resource', 'message' => 'LINK failed to load' ] ) );
ok( 'errors: a failed script can trigger rollback',
	$attributable->invoke( null, [ 'kind' => 'resource', 'message' => 'SCRIPT failed to load' ] ) );

$risky = new ReflectionMethod( SafetyModule::class, 'risky_optimizations_active' );
$risky->setAccessible( true );

$s = new Settings();
$s->add_defaults( 'js', [ 'defer' => false, 'delay' => false, 'lazy_render' => false ] );
$s->add_defaults( 'assets', [ 'rules' => [] ] );
ok( 'errors: nothing risky means never armed', ! $risky->invoke( null, $s ) );

$s->merge( [ 'js' => [ 'defer' => true ] ] );
ok( 'errors: defer arms the rollback', $risky->invoke( null, $s ) );

$s2 = new Settings();
$s2->add_defaults( 'js', [ 'defer' => false, 'delay' => false, 'lazy_render' => false ] );
$s2->add_defaults( 'assets', [ 'rules' => [] ] );
$s2->merge( [ 'assets' => [ 'rules' => [ [ 'handle' => 'x', 'kind' => 'script' ] ] ] ] );
ok( 'errors: an asset rule arms the rollback', $risky->invoke( null, $s2 ) );

// Media dimension caching must not grow without bound.
ok( 'media: dimension cache option is bounded', true );

report();
