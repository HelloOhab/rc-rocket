<?php
declare( strict_types=1 );

namespace RCRocket\Admin;

use RCRocket\Container;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * System check.
 *
 * Every settings screen in this category tells you what it has been asked to
 * do. None of them tell you what actually happened. That gap is how a site can
 * sit in safe mode for a day while its owner keeps measuring an unoptimized
 * page and wondering why the numbers will not move.
 *
 * So this fetches the front page the way a logged-out visitor would, reads the
 * markup that came back, and reports what it can prove — not what the settings
 * claim.
 */
final class SelfTest {

	private const PASS = 'pass';
	private const WARN = 'warn';
	private const FAIL = 'fail';
	private const INFO = 'info';

	public function __construct( private Container $container ) {}

	public function run(): array {
		$settings = $this->container->get( 'settings' );
		$markup   = $this->fetch_front_page();

		$checks = array_merge(
			$this->environment_checks(),
			$this->state_checks( $settings ),
			$this->delivery_checks( $markup, $settings ),
			$this->media_checks( $markup, $settings ),
			$this->asset_checks( $markup, $settings ),
			$this->divi_settings_checks( $settings ),
			$this->health_checks()
		);

		$counts = array_count_values( array_column( $checks, 'status' ) );

		return [
			'checks'  => $checks,
			'summary' => [
				'pass' => (int) ( $counts[ self::PASS ] ?? 0 ),
				'warn' => (int) ( $counts[ self::WARN ] ?? 0 ),
				'fail' => (int) ( $counts[ self::FAIL ] ?? 0 ),
			],
			'fetched' => null !== $markup,
			'time'    => time(),
		];
	}

	/**
	 * Request the home page with no cookies, so WordPress sees an anonymous
	 * visitor rather than an administrator with optimization skipped.
	 */
	private function fetch_front_page(): ?string {
		$response = wp_remote_get(
			home_url( '/' ),
			[
				'timeout'    => 20,
				'sslverify'  => false,
				'cookies'    => [],
				'user-agent' => 'Mozilla/5.0 (compatible; RCRocketSelfTest/1.0)',
				'headers'    => [ 'Accept' => 'text/html' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return '' === $body ? null : $body;
	}

	// ------------------------------------------------------------ environment

	private function environment_checks(): array {
		$hosting = $this->container->get( 'hosting' );
		$divi    = $this->container->get( 'divi' );
		$host    = $hosting->detect();

		$checks = [];

		$checks[] = $this->check(
			'php',
			'PHP version',
			version_compare( PHP_VERSION, '8.1', '>=' ) ? self::PASS : self::FAIL,
			PHP_VERSION
		);

		$checks[] = $this->check(
			'host',
			'Hosting',
			self::INFO,
			$host['label'] . ( $host['page_cache'] ? ' — runs its own page cache, so ours stays off' : '' )
		);

		if ( ! $divi->is_active() ) {
			$checks[] = $this->check(
				'divi',
				'Divi',
				self::WARN,
				'Not detected. RC Rocket still works, but every Divi-specific feature is inert.'
			);
		} else {
			$checks[] = $this->check(
				'divi',
				'Divi',
				self::PASS,
				sprintf( '%s — %s', (string) $divi->version(), $divi->report()['engine'] )
			);
		}

		$update = $this->container->get( 'updater' )->report();

		if ( ! $update['configured'] ) {
			$checks[] = $this->check(
				'updates',
				'Updates',
				self::WARN,
				'No update source configured, so this install will never be told a new version exists.',
				'Define RC_ROCKET_UPDATE_URL or RC_ROCKET_UPDATE_GITHUB in wp-config.php.'
			);
		} else {
			$checks[] = $this->check(
				'updates',
				'Updates',
				$update['update'] ? self::WARN : self::PASS,
				$update['update']
					? sprintf( 'Version %s is available. This site is on %s.', (string) $update['available'], $update['installed'] )
					: sprintf( 'On %s, the latest available.', $update['installed'] )
			);
		}

		$uploads = wp_get_upload_dir();

		$checks[] = $this->check(
			'uploads',
			'Uploads folder writable',
			is_writable( $uploads['basedir'] ) ? self::PASS : self::FAIL,
			$uploads['basedir'],
			'Font localization and any file the plugin writes need this.'
		);

		return $checks;
	}

	// ------------------------------------------------------------------ state

	private function state_checks( Settings $settings ): array {
		$safe   = $this->container->get( 'safe_mode' );
		$checks = [];

		if ( $safe->is_active() ) {
			$reasons = [
				'wp-config' => 'The RC_ROCKET_SAFE_MODE constant is set in wp-config.php.',
				'setting'   => 'The safe mode toggle is switched on.',
				'auto'      => 'RC Rocket switched itself off after JavaScript errors.',
				'url'       => 'This request carried ?rcr_safe=1.',
			];

			$checks[] = $this->check(
				'safe_mode',
				'Safe mode',
				self::FAIL,
				$reasons[ $safe->reason() ] ?? 'Safe mode is on.',
				'Nothing below this line is being applied to your visitors. Turn it off on the Safety tab.'
			);
		} else {
			$checks[] = $this->check( 'safe_mode', 'Safe mode', self::PASS, 'Off. Optimization is running.' );
		}

		$enabled = [];

		foreach ( [ 'assets', 'media', 'js', 'safety', 'cache' ] as $module ) {
			if ( $settings->enabled( $module . '.enabled' ) ) {
				$enabled[] = $module;
			}
		}

		$checks[] = $this->check(
			'modules',
			'Modules enabled',
			$enabled ? self::PASS : self::FAIL,
			$enabled ? implode( ', ', $enabled ) : 'None'
		);

		if ( $settings->enabled( 'general.skip_logged_in' ) ) {
			$checks[] = $this->check(
				'logged_in',
				'Logged-in users',
				self::INFO,
				'Optimization is skipped while you are logged in. What you see in your own browser is not what visitors get — always test in a private window.'
			);
		}

		return $checks;
	}

	// --------------------------------------------------------------- delivery

	private function delivery_checks( ?string $markup, Settings $settings ): array {
		if ( null === $markup ) {
			return [
				$this->check(
					'fetch',
					'Front page fetch',
					self::FAIL,
					'Could not load the home page over HTTP.',
					'Loopback requests may be blocked. Without this, none of the checks below can run.'
				),
			];
		}

		$checks = [];

		$optimized = str_contains( $markup, 'RC Rocket · optimized' );

		$checks[] = $this->check(
			'pipeline',
			'Rewriting reaching visitors',
			$optimized ? self::PASS : self::FAIL,
			$optimized
				? 'The home page came back with RC Rocket\'s marker in it.'
				: 'The home page came back untouched.',
			$optimized ? '' : 'Either safe mode is on, or a cached copy predates your last change. Clear the cache and retest.'
		);

		$beacon = str_contains( $markup, 'rcr-beacon' );

		$checks[] = $this->check(
			'beacon',
			'Error beacon',
			$settings->enabled( 'safety.error_beacon' ) ? ( $beacon ? self::PASS : self::WARN ) : self::INFO,
			$settings->enabled( 'safety.error_beacon' )
				? ( $beacon ? 'Present on the page.' : 'Switched on but not found in the markup.' )
				: 'Switched off.'
		);

		$animated = \RCRocket\Integrations\DiviAnimations::count_animated( $markup );

		if ( $animated > 0 ) {
			$checks[] = $this->check(
				'animations',
				'Divi entrance animations',
				$animated > 20 ? self::WARN : self::INFO,
				sprintf( '%d animated elements on the home page.', $animated ),
				$animated > 20
					? 'Each one runs on the main thread and starts invisible until a script reveals it. Consider switching them off below the mobile breakpoint on the JavaScript tab.'
					: ''
			);
		}

		if ( $settings->enabled( 'js.defer' ) ) {
			$deferred = substr_count( $markup, ' defer src=' ) + substr_count( $markup, ' defer ' );

			$checks[] = $this->check(
				'defer',
				'Deferred scripts',
				$deferred > 0 ? self::PASS : self::WARN,
				sprintf( '%d script tags carry defer.', $deferred )
			);
		}

		if ( $settings->enabled( 'js.delay' ) ) {
			$delayed = substr_count( $markup, 'rcrocket/delayed' );

			$checks[] = $this->check(
				'delay',
				'Delayed scripts',
				$delayed > 0 ? self::PASS : self::WARN,
				sprintf( '%d scripts held until interaction.', $delayed )
			);
		}

		return $checks;
	}

	// ------------------------------------------------------------------ media

	private function media_checks( ?string $markup, Settings $settings ): array {
		if ( null === $markup ) {
			return [];
		}

		$checks = [];

		$images    = substr_count( $markup, '<img' );
		$lazy      = substr_count( $markup, 'loading="lazy"' );
		$priority  = substr_count( $markup, 'fetchpriority="high"' );

		$checks[] = $this->check(
			'lazy',
			'Image loading',
			$images > 0 && $lazy > 0 ? self::PASS : ( 0 === $images ? self::INFO : self::WARN ),
			sprintf( '%d images, %d lazy loaded, %d marked high priority.', $images, $lazy, $priority )
		);

		if ( $images > 0 && 0 === $priority && $settings->enabled( 'media.lcp_priority' ) ) {
			$checks[] = $this->check(
				'lcp',
				'LCP candidate',
				self::WARN,
				'No image was marked high priority.',
				'Your LCP element is probably not an image tag — on Divi that usually means a CSS background or a video.'
			);
		}

		// Background video, and the two ways it commonly goes wrong.
		if ( preg_match_all( '#<video\b[^>]*>#i', $markup, $videos ) ) {
			$gated    = substr_count( $markup, 'data-rcr-video' );
			$postered = 0;
			$foreign  = [];
			$home     = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );

			foreach ( $videos[0] as $tag ) {
				if ( preg_match( '#poster\s*=\s*["\'][^"\']+["\']#i', $tag ) ) {
					++$postered;
				}
			}

			if ( preg_match_all( '#<source\b[^>]*(?:data-rcr-)?src\s*=\s*["\']([^"\']+)["\']#i', $markup, $sources ) ) {
				foreach ( $sources[1] as $src ) {
					$host = (string) ( wp_parse_url( $src, PHP_URL_HOST ) ?: '' );

					if ( '' !== $host && $host !== $home ) {
						$foreign[] = $src;
					}
				}
			}

			$divi = $this->container->get( 'divi' );
			$map  = $divi->is_active() ? $divi->background_media_map() : [];

			if ( 0 === $postered && ! $map ) {
				$checks[] = $this->check(
					'video_poster',
					'Background video fallback image',
					self::WARN,
					'No fallback image found for this video.',
					'On Divi 4, set a Background Image on the same section as the video — RC Rocket will use it as the poster automatically. On Divi 5, add one on the Media tab.'
				);
			}

			$checks[] = $this->check(
				'video',
				'Background video',
				$gated > 0 ? self::PASS : self::WARN,
				sprintf( '%d video elements, %d with a poster, %d held back.', count( $videos[0] ), $postered, $gated ),
				$gated > 0 ? '' : 'A video is only ever held back when a poster exists to replace it. Add one on the Media tab.'
			);

			foreach ( array_slice( array_unique( $foreign ), 0, 2 ) as $src ) {
				$signed = str_contains( $src, 'signature=' ) || str_contains( $src, 'progressive_redirect' );

				$checks[] = $this->check(
					'video_source',
					'Video hosted elsewhere',
					$signed ? self::FAIL : self::WARN,
					sprintf( '%s…', substr( $src, 0, 90 ) ),
					$signed
						? 'This is a signed delivery URL, not a permanent asset. Those can expire without warning, and when one does the hero simply disappears. Download the file, compress it, and serve it from your own Media Library.'
						: 'Serving the video from another domain costs a DNS lookup and a TLS handshake before playback can start.'
				);
			}
		}

		return $checks;
	}

	// ----------------------------------------------------------------- assets

	private function asset_checks( ?string $markup, Settings $settings ): array {
		$checks   = [];
		$registry = $this->container->get( 'assets.registry' );
		$recorded = count( $registry->all() );
		$rules    = count( array_filter( (array) $settings->get( 'assets.rules', [] ) ) );

		$checks[] = $this->check(
			'inventory',
			'Asset inventory',
			$recorded > 0 ? self::PASS : self::WARN,
			sprintf( '%d templates recorded, %d rules active.', $recorded, $rules ),
			$recorded > 0 ? '' : 'Visit a few pages of your site logged out to build the inventory.'
		);

		if ( null !== $markup && $settings->enabled( 'assets.fonts.localize' ) ) {
			$remote = str_contains( $markup, 'fonts.googleapis.com' );
			$report = $this->container->get( 'assets.fonts' )->report();

			$checks[] = $this->check(
				'fonts',
				'Google Fonts',
				$remote ? self::WARN : self::PASS,
				$remote
					? 'Still requesting fonts from Google.'
					: sprintf( '%d stylesheets and %d files served locally.', $report['stylesheets'], $report['files'] ),
				$remote ? 'The download may have failed. Check the log for details.' : ''
			);
		}

		return $checks;
	}

	// ----------------------------------------------------------------- health

	private function health_checks(): array {
		$checks = [];

		$errors = (array) get_option( \RCRocket\Safety\SafetyModule::ERRORS_OPTION, [] );
		$recent = array_filter(
			$errors,
			static fn( $e ): bool => is_array( $e ) && (int) ( $e['time'] ?? 0 ) > ( time() - DAY_IN_SECONDS )
		);

		$own = array_filter(
			$recent,
			static function ( array $e ): bool {
				$host = (string) ( wp_parse_url( (string) ( $e['source'] ?? '' ), PHP_URL_HOST ) ?: '' );

				return '' !== $host && $host === (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
			}
		);

		$checks[] = $this->check(
			'errors',
			'Errors in the last day',
			$own ? self::WARN : self::PASS,
			sprintf( '%d reported, %d from your own domain.', count( $recent ), count( $own ) ),
			$own ? 'Errors from your own domain are real site bugs worth fixing at the source. See the Safety tab.' : ''
		);

		foreach ( [
			\RCRocket\Cache\CacheModule::CLEANUP_HOOK => 'Cache cleanup',
			\RCRocket\Assets\Fonts::CRON_HOOK         => 'Font refresh',
		] as $hook => $label ) {
			$next = wp_next_scheduled( $hook );

			$checks[] = $this->check(
				'cron_' . md5( $hook ),
				$label . ' schedule',
				$next ? self::PASS : self::INFO,
				$next ? 'Next run ' . human_time_diff( time(), (int) $next ) . ' from now.' : 'Not scheduled.'
			);
		}

		return $checks;
	}

	/**
	 * Divi's own performance tab, read back and reconciled against ours.
	 */
	private function divi_settings_checks( Settings $settings ): array {
		$divi = $this->container->get( 'divi' );

		if ( ! $divi->is_active() ) {
			return [];
		}

		/** @var \RCRocket\Integrations\DiviSettings $divi_settings */
		$divi_settings = $this->container->get( 'divi.settings' );
		$options       = $divi_settings->all();

		$on      = 0;
		$off     = [];
		$unknown = 0;

		foreach ( $options as $option ) {
			match ( $option['state'] ) {
				'on'    => $on++,
				'off'   => $off[] = $option['label'],
				default => $unknown++,
			};
		}

		$checks = [
			$this->check(
				'divi_perf',
				'Divi performance settings',
				$off ? self::WARN : self::PASS,
				sprintf(
					'%d on, %d off%s.%s',
					$on,
					count( $off ),
					$unknown > 0 ? sprintf( ', %d unreadable', $unknown ) : '',
					$off ? ' Off: ' . implode( ', ', $off ) : ''
				),
				$off ? 'These are Divi\'s own optimizations, in Divi > Theme Options > Performance. Divi is better placed to do them than any plugin.' : ''
			),
		];

		foreach ( $divi_settings->conflicts( $settings ) as $conflict ) {
			$checks[] = $this->check(
				'divi_conflict',
				$conflict['label'],
				'warn' === $conflict['severity'] ? self::WARN : self::INFO,
				$conflict['detail']
			);
		}

		return $checks;
	}

	private function check( string $id, string $label, string $status, string $detail, string $fix = '' ): array {
		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'fix'    => $fix,
		];
	}
}
