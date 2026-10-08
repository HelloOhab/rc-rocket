<?php
require __DIR__ . '/stubs.php';
require __DIR__ . '/fixtures.php';
define( 'ET_CORE_VERSION', '4.27.4' );

$src = __DIR__ . '/../src';
require "$src/Autoloader.php";
RCRocket\Autoloader::register( 'RCRocket', $src );

use RCRocket\Integrations\Divi;
use RCRocket\Integrations\DiviAnimations;
use RCRocket\Support\Hosting;
use RCRocket\Support\Logger;

$divi = new Divi( new Logger( '/tmp/rcr.log', false ), [] );

// ============ 22. Divi animations ============

$page = '<html><head></head><body>'
	. '<div class="et_pb_section et-waypoint et_pb_animation_fade_in">a</div>'
	. '<div class="et_pb_module et-waypoint et_pb_animation_slide">b</div>'
	. '</body></html>';

ok( 'animations: counted', 2 === DiviAnimations::count_animated( $page ), (string) DiviAnimations::count_animated( $page ) );

$a   = new DiviAnimations( $divi, DiviAnimations::defaults() );
$out = $a->rewrite( $page );
ok( 'animations: stylesheet is injected', str_contains( $out, 'rcr-divi-animations' ) );
ok( 'animations: reveal fallback is present', str_contains( $out, 'et-waypoint:not(.et-animated)' ) );
ok( 'animations: reduced motion is honoured', str_contains( $out, 'prefers-reduced-motion' ) );
ok( 'animations: mobile is untouched by default', ! str_contains( $out, 'max-width:980px' ) );

$mobile = new DiviAnimations( $divi, DiviAnimations::defaults() + [] );
$conf   = DiviAnimations::defaults();
$conf['disable_on_mobile'] = true;
$conf['mobile_breakpoint'] = 767;
$m = ( new DiviAnimations( $divi, $conf ) )->rewrite( $page );
ok( 'animations: mobile breakpoint is applied', str_contains( $m, 'max-width:767px' ), 'breakpoint missing' );

$off = DiviAnimations::defaults();
$off['reveal_fallback'] = false;
$off['respect_reduced_motion'] = false;
ok( 'animations: nothing enabled means no stylesheet',
	! str_contains( ( new DiviAnimations( $divi, $off ) )->rewrite( $page ), 'rcr-divi-animations' ) );

// Without Divi the module must do nothing at all.
$nodivi = new DiviAnimations( new Divi( new Logger( '/tmp/x.log', false ), [] ), DiviAnimations::defaults() );
ok( 'animations: applicable only with Divi', $nodivi->applicable() );

// ============ 23. Host purge discipline ============

define( 'KINSTAMU_VERSION', '2.3.4' );
$kinsta = new Hosting();

ok( 'purge: automatic purges are not forwarded by default',
	! $kinsta->should_forward_automatic_purges(),
	'every post save would flush the entire host cache' );

ok( 'purge: an explicit purge is allowed once', $kinsta->can_purge_now() );
$kinsta->mark_purged();
ok( 'purge: a second purge inside the window is refused', ! $kinsta->can_purge_now(),
	'a purge loop could keep the host cache permanently cold' );

report();
