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

	/** Headers from the second front-page request, when the cache is warm. */
	private array $front_headers = [];

	public function __construct( private Container $container ) {}

	public function run(): array {
		$settings = $this->container->get( 'settings' );
		$markup   = $this->fetch_front_page();

		$checks = array_merge(
			$this->environment_checks(),
			$this->host_cache_checks(),
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
		$args = [
			'timeout'    => 20,
			'sslverify'  => (bool) apply_filters( 'rc-rocket/preload/sslverify', true ),
			'cookies'    => [],
			'user-agent' => 'Mozilla/5.0 (compatible; RCRocketSelfTest/1.0)',
			'headers'    => [ 'Accept' => 'text/html' ],
		];

		// Twice: the first request may be the one that fills the cache, so
		// only the second says whether pages are being served from it.
		$response = wp_remote_get( home_url( '/' ), $args );

		if ( ! is_wp_error( $response ) ) {
			$second   = wp_remote_get( home_url( '/' ), $args );
			$response = is_wp_error( $second ) ? $response : $second;
		}

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$this->front_headers = array_change_key_case( (array) wp_remote_retrieve_headers( $response )->getAll(), CASE_LOWER );

		$body = (string) wp_remote_retrieve_body( $response );

		return '' === $body ? null : $body;
	}

	/**
	 * Is the cache in front of this site actually answering? On a managed
	 * host that is the host's cache, read from the header it adds.
	 */
	private function host_cache_checks(): array {
		$hosting = $this->container->get( 'hosting' );
		$report  = $hosting->report();
		$checks  = [];

		if ( 'kinsta' === $report['id'] ) {
			$checks[] = $this->check(
				'kinsta_purge',
				'Kinsta purge connection',
				$report['purge_api'] ? self::PASS : self::FAIL,
				$report['purge_api']
					? 'Divi Theme Options, Theme Builder templates and RC Rocket settings clear the Kinsta cache automatically.'
					: 'The Kinsta MU plugin was not found, so global changes cannot clear the Kinsta cache.',
				$report['purge_api'] ? '' : 'Clear the cache from MyKinsta after design changes, and ask Kinsta support to check the MU plugin.'
			);
		}

		$header = $this->front_headers['x-kinsta-cache'] ?? $this->front_headers['x-rc-rocket-cache'] ?? $this->front_headers['x-cache'] ?? '';
		$header = is_array( $header ) ? (string) reset( $header ) : (string) $header;

		if ( '' === $header ) {
			return $checks;
		}

		$state = strtoupper( $header );

		// Name the thing that made the host skip its cache.
		$why = [];

		foreach ( (array) ( $this->front_headers['set-cookie'] ?? [] ) as $cookie ) {
			$name = trim( (string) strtok( (string) $cookie, '=' ) );

			if ( '' !== $name ) {
				$why[] = 'sets the cookie ' . $name;
			}
		}

		$control = $this->front_headers['cache-control'] ?? '';
		$control = is_array( $control ) ? implode( ', ', $control ) : (string) $control;

		if ( preg_match( '#no-cache|no-store|private|max-age=0#i', $control ) ) {
			$why[] = 'sends Cache-Control: ' . $control;
		}

		$checks[] = $this->check(
			'page_cache_hit',
			'Home page served from cache',
			str_contains( $state, 'HIT' ) || 'SERVER' === $state ? self::PASS : ( str_contains( $state, 'BYPASS' ) ? self::WARN : self::INFO ),
			sprintf( 'The page cache answered %s on a repeat request.', $state ),
			str_contains( $state, 'BYPASS' )
				? ( $why
					? 'The home page ' . implode( ' and ', array_unique( $why ) ) . '. Find the plugin responsible (a session, a consent banner, a form plugin) and stop it doing that for visitors who are not logged in.'
					: 'Nothing in the response explains it. Kinsta also skips its cache when caching is switched off for the environment: staging sites often have it off. Check MyKinsta → Caching for this environment.' )
				: ''
		);

		return $checks;
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

		$jquery = $this->jquery_check( $markup );

		if ( null !== $jquery ) {
			$checks[] = $jquery;
		}

		return $checks;
	}

	/**
	 * "jQuery(...).on is not a function" means code ran while jQuery was a
	 * stand-in or not there yet: jQuery was deferred (by Divi's "Defer
	 * jQuery And jQuery Migrate", another plugin, or a JavaScript exclusion
	 * removed here) while code in the page calls it during loading. Read
	 * the page as visitors get it and say which.
	 */
	private function jquery_check( string $markup ): ?array {
		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $markup, $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tag      = null;
		$position = 0;
		$inline   = [];

		foreach ( $scripts as $script ) {
			$attributes = $script[1][0];
			$offset     = $script[0][1];

			if ( null === $tag && preg_match( '#(?:src|data-rcr-src)\s*=\s*["\'][^"\']*/jquery(?:\.min)?\.js#i', $attributes ) ) {
				$tag      = $attributes;
				$position = $offset;
				continue;
			}

			// Inline JavaScript only: not data blocks, not our own.
			if ( preg_match( '#(?<![\w-])src\s*=#i', $attributes ) || preg_match( '#type\s*=\s*["\']?(?!text/javascript|module)[^"\'\s>]+#i', $attributes ) || str_contains( $attributes, 'rcr-' ) ) {
				continue;
			}

			$code = $script[2][0];

			// Already waits for the page: a ready handler or DOMContentLoaded
			// runs after a deferred jQuery has arrived.
			if ( preg_match( '#(?:jQuery|\$)\s*\(\s*(?:function|\(|document\s*\)\s*\.ready)|addEventListener\s*\(\s*["\'](?:DOMContentLoaded|load)#', $code ) ) {
				continue;
			}

			// Calls that need real jQuery while the page is loading: anything
			// but handing a function to jQuery() or .ready(), which a
			// stand-in can queue.
			if ( preg_match( '#(?:jQuery|\$)\s*\(\s*(?!function|\(\s*\)\s*=>|\(\s*\$\s*\)\s*=>)[^)]*\)\s*\.(?!ready\b)\w+\s*\(#', $code ) ) {
				$inline[] = $offset;
			}
		}

		if ( null === $tag ) {
			return $inline
				? $this->check( 'jquery', 'jQuery loading', self::WARN, sprintf( 'jQuery is not loaded on the home page, but %d inline scripts call it.', count( $inline ) ), 'Something removed jQuery from this page. Check RC Rocket\'s asset rules and Divi\'s performance options.' )
				: $this->check( 'jquery', 'jQuery loading', self::PASS, 'The home page does not load jQuery and nothing on it needs it.' );
		}

		$delayed  = str_contains( $tag, 'rcrocket/delayed' );
		$deferred = (bool) preg_match( '#(?<![\w-])(defer|async)(?![\w-])#i', $tag );
		$by_wp    = str_contains( $tag, 'data-wp-strategy' );

		if ( $delayed ) {
			return $this->check( 'jquery', 'jQuery loading', self::FAIL, 'jQuery is held back by Delay JavaScript, so everything that uses it waits or fails.', 'Add jquery.min.js back to the JavaScript exclusions (JavaScript tab), or reset the exclusions list.' );
		}

		if ( $deferred ) {
			// Inline code anywhere runs during parsing, before a deferred jQuery.
			$early = count( $inline );
			$who   = $by_wp ? 'by RC Rocket or WordPress\'s script strategy' : 'by Divi ("Defer jQuery And jQuery Migrate") or another plugin';

			if ( 0 === $early ) {
				return $this->check( 'jquery', 'jQuery loading', self::PASS, sprintf( 'jQuery is deferred %s, and no inline code calls it during loading.', $who ) );
			}

			return $this->check(
				'jquery',
				'jQuery loading',
				self::WARN,
				sprintf( 'jQuery is deferred %s, but %d inline scripts call jQuery while the page is still loading. They get a stand-in without .on(), .hasClass() and the rest: "jQuery(...).on is not a function".', $who, $early ),
				$by_wp
					? 'Add jquery.min.js to the JavaScript exclusions (JavaScript tab) so it loads normally.'
					: 'In Divi → Theme Options → Performance, switch off "Defer jQuery And jQuery Migrate". RC Rocket already defers everything that can safely wait.'
			);
		}

		$before = count( array_filter( $inline, static fn( int $offset ): bool => $offset < $position ) );

		if ( $before > 0 ) {
			return $this->check( 'jquery', 'jQuery loading', self::WARN, sprintf( '%d inline scripts call jQuery before jQuery is loaded.', $before ), 'A plugin or code snippet prints jQuery code in the page head while jQuery loads in the footer. Move the snippet to the footer, or load it on DOMContentLoaded.' );
		}

		return $this->check( 'jquery', 'jQuery loading', self::PASS, 'jQuery loads normally, before every inline script that uses it.' );
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
