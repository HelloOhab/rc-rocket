<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

/**
 * The cache key algorithm.
 *
 * This file is deliberately free of every WordPress function and constant.
 * It is loaded twice: once by the plugin inside a booted WordPress, and once
 * by the advanced-cache.php drop-in before WordPress exists at all. If the two
 * ever disagree about what a key is, the cache silently stops hitting — so the
 * rule is: nothing WordPress-specific goes in here.
 */
final class Key {

	/** Query args that identify a marketing source, never a different page. */
	public const TRACKING_PARAMS = [
		// Google Analytics and Ads.
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_expid',
		'utm_reader',
		'utm_referrer',
		'utm_name',
		'utm_place',
		'utm_pubreferrer',
		'utm_swu',
		'utm_viz_id',
		'gclid',
		'gclsrc',
		'gbraid',
		'wbraid',
		'dclid',
		'gad_source',
		'gad_campaignid',
		'srsltid',
		'_ga',
		'_gl',
		// Social and ad networks.
		'fbclid',
		'msclkid',
		'ttclid',
		'twclid',
		'igshid',
		'li_fat_id',
		'epik',
		'rdt_cid',
		'yclid',
		's_kwcid',
		'ef_id',
		'irclickid',
		'sscid',
		// Email and marketing automation.
		'mc_cid',
		'mc_eid',
		'_hsenc',
		'_hsmi',
		'__hssc',
		'__hstc',
		'__hsfp',
		'hsCtaTracking',
		'mkt_tok',
		'_kx',
		'vero_id',
		'vero_conv',
		'oly_anon_id',
		'oly_enc_id',
		'wickedid',
		// Matomo / Piwik.
		'pk_campaign',
		'pk_kwd',
		'pk_keyword',
		'pk_source',
		'pk_medium',
		'pk_content',
		'pk_cid',
		'mtm_campaign',
		'mtm_kwd',
		'mtm_keyword',
		'mtm_source',
		'mtm_medium',
		'mtm_content',
		'mtm_cid',
		'mtm_group',
		'mtm_placement',
		'piwik_campaign',
		'piwik_kwd',
		'piwik_keyword',
		'matomo_campaign',
		'matomo_keyword',
		// Everything else that never changes the page.
		'ref',
		'age-verified',
		'usqp',
		'cn-reloaded',
		'_branch_match_id',
	];

	/** Presence of any of these cookies means the response is personalised. */
	public const PRIVATE_COOKIES = [
		'wordpress_logged_in_',
		'comment_author_',
		'wp-postpass_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
		'wptouch_switch_toggle',
	];

	/**
	 * Everything the drop-in needs to make the same decision the plugin would.
	 * Written to config.php on every settings save.
	 */
	public static function config_defaults(): array {
		return [
			'enabled'         => false,
			'cache_dir'       => '',
			'plugin_dir'      => '',
			'ttl'             => 36000, // 10 hours.
			'separate_mobile' => true,
			'cache_logged_in' => false,
			'gzip'            => true,
			'debug_headers'   => true,
			'query_whitelist' => [ 'p', 'page_id', 's', 'paged', 'lang', 'currency' ],
			'ignored_params'  => [],
			'excluded_uris'   => [],
			'excluded_agents' => [ 'facebookexternalhit', 'ia_archiver' ],
			'vary_cookies'    => [],
			'mobile_agents'   => 'Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi',
		];
	}

	/**
	 * @param array<string, string> $server  $_SERVER
	 * @param array<string, string> $cookies $_COOKIE
	 *
	 * @return string|null Reason for bypass, or null when the request is cacheable.
	 */
	public static function bypass_reason( array $server, array $cookies, array $config ): ?string {
		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return 'method:' . $method;
		}

		$uri = (string) ( $server['REQUEST_URI'] ?? '/' );

		// "//about-us/" is rendered by WordPress as the About page, but
		// parse_url() reads "about-us" as a host and the path as "/": cached,
		// it would become the home page. Only plain paths are cached.
		$path = self::path_only( $uri );

		if ( ! str_starts_with( $path, '/' ) || str_contains( $path, '//' ) ) {
			return 'malformed-uri';
		}

		foreach ( [ '/wp-admin', '/wp-login.php', '/wp-cron.php', '/xmlrpc.php', '/wp-json/', '/index.php?rest_route' ] as $needle ) {
			if ( str_contains( $uri, $needle ) ) {
				return 'reserved-path';
			}
		}

		foreach ( self::strings( $config['excluded_uris'] ?? [] ) as $pattern ) {
			if ( '' !== $pattern && self::matches( (string) $pattern, self::path_only( $uri ) ) ) {
				return 'excluded-uri';
			}
		}

		$agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );

		foreach ( self::strings( $config['excluded_agents'] ?? [] ) as $needle ) {
			if ( '' !== $needle && false !== stripos( $agent, (string) $needle ) ) {
				return 'excluded-agent';
			}
		}

		if ( ! empty( $config['cache_logged_in'] ) ) {
			$private = array_values(
				array_filter(
					self::PRIVATE_COOKIES,
					static fn( string $c ): bool => 'wordpress_logged_in_' !== $c
				)
			);
		} else {
			$private = self::PRIVATE_COOKIES;
		}

		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( $private as $prefix ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					return 'private-cookie';
				}
			}
		}

		if ( null === self::normalize_query( $uri, $config ) ) {
			return 'unknown-query-arg';
		}

		return null;
	}

	/**
	 * Strip tracking noise, drop anything not explicitly whitelisted, sort what
	 * remains. Returns null when a non-whitelisted arg survives — that request
	 * is dynamic and must not be cached.
	 */
	public static function normalize_query( string $uri, array $config ): ?string {
		$raw = (string) ( parse_url( $uri, PHP_URL_QUERY ) ?? '' );

		if ( '' === $raw ) {
			return '';
		}

		parse_str( $raw, $args );

		foreach ( array_merge( self::TRACKING_PARAMS, self::strings( $config['ignored_params'] ?? [] ) ) as $param ) {
			unset( $args[ (string) $param ] );
		}

		if ( ! $args ) {
			return '';
		}

		$whitelist = self::strings( $config['query_whitelist'] ?? [] );

		foreach ( array_keys( $args ) as $name ) {
			if ( ! in_array( (string) $name, $whitelist, true ) ) {
				return null;
			}
		}

		ksort( $args );

		return http_build_query( $args );
	}

	/**
	 * The host part of a URL exactly as it would arrive in HTTP_HOST,
	 * including a non-default port. The drop-in hashes HTTP_HOST verbatim, so
	 * dropping the port here means a page cached on :8080 is never served.
	 */
	public static function host_from_url( string $url ): string {
		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
		$port = parse_url( $url, PHP_URL_PORT );

		return null === $port || '' === $host ? $host : $host . ':' . $port;
	}

	/**
	 * Surrogate key shared by every variant of one URL (scheme, device,
	 * cookie buckets), so a URL purge is total without enumerating variants.
	 */
	public static function url_key( string $host, string $uri ): string {
		$path = rtrim( self::path_only( $uri ), '/' );

		return 'url-' . md5( strtolower( $host ) . '#' . ( '' === $path ? '/' : $path ) );
	}

	/** The path of a request URI: everything before the query or fragment. */
	public static function path_only( string $uri ): string {
		$path = (string) strtok( $uri, '?#' );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Buckets that legitimately need their own copy of the page.
	 */
	public static function variant( array $server, array $cookies, array $config ): string {
		$parts = [];

		$parts[] = self::is_https( $server ) ? 'https' : 'http';

		if ( ! empty( $config['separate_mobile'] ) ) {
			$parts[] = self::is_mobile( (string) ( $server['HTTP_USER_AGENT'] ?? '' ), $config ) ? 'mobile' : 'desktop';
		}

		if ( ! empty( $config['cache_logged_in'] ) ) {
			$role = '';

			foreach ( $cookies as $name => $value ) {
				if ( str_starts_with( (string) $name, 'wordpress_logged_in_' ) ) {
					$role = 'user-' . substr( md5( (string) $value ), 0, 8 );
					break;
				}
			}

			$parts[] = '' === $role ? 'anon' : $role;
		}

		foreach ( self::strings( $config['vary_cookies'] ?? [] ) as $cookie ) {
			$parts[] = $cookie . '=' . substr( md5( (string) ( $cookies[ $cookie ] ?? '' ) ), 0, 8 );
		}

		return implode( '|', $parts );
	}

	/**
	 * A config list as strings. The drop-in runs before WordPress, so a
	 * malformed entry (an array from a bad import) must be dropped here
	 * rather than fatal every cached request.
	 *
	 * @return string[]
	 */
	private static function strings( mixed $list ): array {
		return array_values( array_map( 'strval', array_filter( (array) $list, 'is_scalar' ) ) );
	}

	public static function is_mobile( string $agent, array $config ): bool {
		$pattern = (string) ( $config['mobile_agents'] ?? '' );

		if ( '' === $pattern || '' === $agent ) {
			return false;
		}

		return 1 === preg_match( '#(' . $pattern . ')#i', $agent );
	}

	public static function is_https( array $server ): bool {
		if ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) {
			return true;
		}

		if ( 'https' === strtolower( (string) ( $server['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) ) {
			return true;
		}

		return '443' === (string) ( $server['SERVER_PORT'] ?? '' );
	}

	/**
	 * The identity of a cached response.
	 */
	public static function hash( string $host, string $uri, string $variant, array $config ): ?string {
		$query = self::normalize_query( $uri, $config );

		if ( null === $query ) {
			return null;
		}

		$path = rtrim( self::path_only( $uri ), '/' );
		$path = '' === $path ? '/' : $path;

		return md5( strtolower( $host ) . '#' . $path . '#' . $query . '#' . $variant );
	}

	/**
	 * Two levels of sharding keeps directory entry counts sane on sites with
	 * six-figure URL counts, where a flat directory melts ext4.
	 *
	 * @return array{dir:string, html:string, gz:string, meta:string}
	 */
	public static function paths( string $cache_dir, string $hash ): array {
		$dir = rtrim( $cache_dir, '/' ) . '/pages/' . substr( $hash, 0, 2 ) . '/' . substr( $hash, 2, 2 );

		return [
			'dir'  => $dir,
			'html' => $dir . '/' . $hash . '.html',
			'gz'   => $dir . '/' . $hash . '.html.gz',
			'meta' => $dir . '/' . $hash . '.meta',
		];
	}

	/** Wildcard match, or a real regex when the pattern is delimited with #. */
	public static function matches( string $pattern, string $subject ): bool {
		if ( str_starts_with( $pattern, '#' ) && str_ends_with( $pattern, '#' ) ) {
			return 1 === @preg_match( $pattern, $subject );
		}

		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

		return 1 === preg_match( $regex, $subject );
	}
}

