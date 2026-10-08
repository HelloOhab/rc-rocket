<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zero-PHP delivery.
 *
 * A cache hit served through advanced-cache.php still costs a PHP process,
 * an opcache lookup and 15–40ms. These snippets let nginx, Apache or a
 * Cloudflare Worker answer from the same files with PHP never starting.
 * No competitor generates these for you; you are expected to know nginx.
 */
final class ServerRules {

	public function __construct( private string $cache_dir ) {}

	public function all(): array {
		return [
			'nginx'      => $this->nginx(),
			'apache'     => $this->apache(),
			'cloudflare' => $this->cloudflare_worker(),
		];
	}

	public function nginx(): string {
		$relative = str_replace( ABSPATH, '', $this->cache_dir );

		return <<<NGINX
# RC Rocket — zero-PHP cache delivery.
# 1. Paste this block inside your `server { }` block, before any location.
# If your host manages nginx for you (Kinsta, WP Engine, Pressable), these
# rules are not applicable — the host already serves pages from its own cache.
# Reload nginx after adding: nginx -t && systemctl reload nginx

set \$rcr_skip 0;
set \$rcr_device "desktop";
set \$rcr_scheme "http";

if (\$scheme = https) { set \$rcr_scheme "https"; }
if (\$http_user_agent ~* "(Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi)") {
    set \$rcr_device "mobile";
}

if (\$request_method != GET) { set \$rcr_skip 1; }
if (\$query_string != "")    { set \$rcr_skip 1; }
if (\$http_cookie ~* "(wordpress_logged_in_|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|edd_items_in_cart)") {
    set \$rcr_skip 1;
}
if (\$uri ~* "^/(wp-admin|wp-login\\.php|wp-json|xmlrpc\\.php)") { set \$rcr_skip 1; }

# RC Rocket writes a flat mirror of cached URLs here for the server to find.
set \$rcr_file "/{$relative}/mirror/\$host/\$rcr_scheme/\$rcr_device\$uri/index.html";
if (\$rcr_skip = 1) { set \$rcr_file "/rcr-no-cache"; }

# Serve pre-compressed bytes when the browser accepts them.
gzip_static on;

# 2. In your EXISTING `location / { }` block, replace its try_files line with:
#
#        try_files \$rcr_file \$uri \$uri/ /index.php?\$args;
#
#    Do not add a second `location /` — nginx refuses to start with two.
NGINX;
	}

	public function apache(): string {
		$relative = ltrim( str_replace( ABSPATH, '', $this->cache_dir ), '/' );

		return <<<APACHE
# RC Rocket — zero-PHP cache delivery.
# Paste into .htaccess ABOVE the "# BEGIN WordPress" block.

<IfModule mod_rewrite.c>
RewriteEngine On

# Only plain HTTPS GETs with no query string.
RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)\$ [OR]
RewriteCond %{HTTPS} !=on [OR]
RewriteCond %{QUERY_STRING} !^\$ [OR]
RewriteCond %{HTTP:Cookie} (wordpress_logged_in_|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_|edd_items_in_cart) [NC]
RewriteRule .* - [S=2]

# Device bucket. Exactly two rules follow, which is what [S=2] above skips;
# skipping more would swallow the first rule of the WordPress block.
RewriteCond %{HTTP_USER_AGENT} (Mobile|Android|Silk/|Kindle|BlackBerry|Opera\\ Mini|Opera\\ Mobi) [NC]
RewriteCond %{DOCUMENT_ROOT}/{$relative}/mirror/%{HTTP_HOST}/https/mobile%{REQUEST_URI}/index.html -f
RewriteRule .* /{$relative}/mirror/%{HTTP_HOST}/https/mobile%{REQUEST_URI}/index.html [L]

RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\\ Mini|Opera\\ Mobi) [NC]
RewriteCond %{DOCUMENT_ROOT}/{$relative}/mirror/%{HTTP_HOST}/https/desktop%{REQUEST_URI}/index.html -f
RewriteRule .* /{$relative}/mirror/%{HTTP_HOST}/https/desktop%{REQUEST_URI}/index.html [L]
</IfModule>

<IfModule mod_headers.c>
<FilesMatch "\\.html\$">
Header set X-RC-Rocket-Cache "SERVER"
</FilesMatch>
</IfModule>
APACHE;
	}

	public function cloudflare_worker(): string {
		return <<<'JS'
// RC Rocket — edge cache worker.
// Deploy on a route matching your site, e.g. example.com/*
// Nothing purges this cache when content changes: copies simply expire
// after EDGE_TTL seconds. Keep it short, or purge in Cloudflare after edits.

const EDGE_TTL = 600;

const BYPASS_COOKIES = [
  'wordpress_logged_in_', 'wordpress_sec_', 'comment_author_', 'wp-postpass_',
  'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_',
  'edd_items_in_cart',
];

const BYPASS_PATHS = /^\/(wp-admin|wp-json|wp-login\.php|wp-cron\.php|xmlrpc\.php|cart|checkout|my-account)(\/|$)/;
const BYPASS_PARAMS = ['preview', 'add-to-cart', 'wc-ajax', 'remove_item', 'edd_action', 'action'];

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    const cookie = request.headers.get('Cookie') || '';
    const isPrivate = BYPASS_COOKIES.some((c) => cookie.includes(c));
    const cacheable =
      request.method === 'GET' &&
      !isPrivate &&
      !BYPASS_PATHS.test(url.pathname) &&
      !BYPASS_PARAMS.some((p) => url.searchParams.has(p));

    if (!cacheable) return fetch(request);

    // Strip tracking params so one page is not cached a hundred times.
    for (const key of [...url.searchParams.keys()]) {
      if (/^(utm_|pk_|mtm_|piwik_|matomo_|_hs|fbclid|gclid|gclsrc|gbraid|wbraid|dclid|gad_|srsltid|msclkid|ttclid|twclid|igshid|mc_cid|mc_eid|_ga|_gl|mkt_tok|sscid|cn-reloaded)/.test(key)) {
        url.searchParams.delete(key);
      }
    }

    const cache = caches.default;
    const cacheKey = new Request(url.toString(), request);

    let response = await cache.match(cacheKey);
    if (response) {
      response = new Response(response.body, response);
      response.headers.set('X-RC-Rocket-Cache', 'EDGE-HIT');
      return response;
    }

    response = await fetch(request);

    // The origin decides: a page it marks private, or anything but HTML, is
    // passed through untouched.
    const control = (response.headers.get('Cache-Control') || '').toLowerCase();
    const type = (response.headers.get('Content-Type') || '').toLowerCase();
    const shareable =
      response.status === 200 &&
      !response.headers.has('Set-Cookie') &&
      type.includes('text/html') &&
      !/(private|no-store|no-cache)/.test(control);

    if (shareable) {
      const cached = new Response(response.body, response);
      cached.headers.set('Cache-Control', 'public, max-age=0, s-maxage=' + EDGE_TTL);
      cached.headers.set('X-RC-Rocket-Cache', 'EDGE-MISS');
      ctx.waitUntil(cache.put(cacheKey, cached.clone()));
      return cached;
    }

    return response;
  },
};
JS;
	}
}
