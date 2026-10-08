<?php
require __DIR__ . '/stubs.php';

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Support\Logger;
use RCRocket\Update\Updater;

$logger = new Logger( '/tmp/rcr.log', false );
$file   = '/wp/wp-content/plugins/rc-rocket/rc-rocket.php';

// ============ 24. Updater ============

$u = new Updater( $file, '0.5.1', $logger );
ok( 'update: dormant with no source configured', ! $u->configured() );
ok( 'update: slug is the plugin folder', 'rc-rocket' === $u->slug(), $u->slug() );
ok( 'update: basename is folder/file', 'rc-rocket/rc-rocket.php' === $u->basename(), $u->basename() );

$report = $u->report();
ok( 'update: report says unconfigured', false === $report['configured'] );

// A manifest source, served from a local file through the stubbed fetcher.
define( 'RC_ROCKET_UPDATE_URL', 'https://updates.example.com/rc-rocket.json' );
$u2 = new Updater( $file, '0.5.1', $logger );
ok( 'update: configured once a url exists', $u2->configured() );

// With no network the check must fail quietly, never fatally, and never claim
// an update exists.
$r = $u2->report();
ok( 'update: a failed check reports no update', false === $r['update'] );
ok( 'update: a failed check does not invent a version', null === $r['available'] );

// The transient must inject nothing when there is no release.
$transient = (object) [ 'response' => [], 'no_update' => [] ];
$out = $u2->inject( $transient );
ok( 'update: nothing injected without a release', [] === $out->response );

// Non-objects must pass straight through: WordPress passes false early on.
ok( 'update: a false transient is returned untouched', false === $u2->inject( false ) );

// plugins_api must ignore other plugins entirely.
$other = (object) [ 'slug' => 'some-other-plugin' ];
ok( 'update: details ignores other plugins', 'untouched' === $u2->details( 'untouched', 'plugin_information', $other ) );
ok( 'update: details ignores other actions', 'untouched' === $u2->details( 'untouched', 'query_plugins', (object) [ 'slug' => 'rc-rocket' ] ) );

// Private download must not intercept anything it does not own.
ok( 'update: download filter ignores other packages',
	'reply' === $u2->download_private( 'reply', 'https://example.com/other.zip', null, [ 'plugin' => 'other/other.php' ] ) );

// Folder renaming must not touch another plugin's install.
ok( 'update: folder fix ignores other plugins',
	'/tmp/src/' === $u2->fix_folder_name( '/tmp/src/', '/tmp/', null, [ 'plugin' => 'other/other.php' ] ) );

// Automatic updates follow WordPress's own per-plugin toggle unless forced.
$item = (object) [ 'plugin' => 'rc-rocket/rc-rocket.php' ];
ok( 'update: auto update follows the plugin toggle',
	false === $u2->allow_auto_update( false, $item ) && true === $u2->allow_auto_update( true, $item ) );

$other_item = (object) [ 'plugin' => 'other/other.php' ];
ok( 'update: auto update decision for others is untouched', 'keep' === $u2->allow_auto_update( 'keep', $other_item ) );

// Release channels: stable by default; beta picks the highest version among
// recent releases, pre-releases included, and never a draft.
ok( 'update: channel is stable by default', 'stable' === $u2->channel() );
$releases = json_encode( [
	[ 'tag_name' => 'v0.13.0', 'draft' => true, 'prerelease' => false ],
	[ 'tag_name' => 'v0.12.1', 'draft' => false, 'prerelease' => true ],
	[ 'tag_name' => 'v0.12.0', 'draft' => false, 'prerelease' => false ],
	[ 'tag_name' => 'v0.9.0', 'draft' => false, 'prerelease' => false ],
] );
ok( 'update: beta takes the newest pre-release and skips drafts', 'v0.12.1' === ( $u2->newest_release( $releases )['tag_name'] ?? '' ) );
ok( 'update: beta survives a malformed list', null === $u2->newest_release( '{"message":"Not Found"}' ) );
define( 'RC_ROCKET_UPDATE_CHANNEL', 'beta' );
ok( 'update: channel follows the constant', 'beta' === $u2->channel() );

report();
