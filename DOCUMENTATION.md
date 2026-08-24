# RC Rocket

**Performance tuning built around Divi.**
Version 0.5.1 · Divi 4 and Divi 5 · WordPress 6.5+ · PHP 8.1+
Author: Abdul

---

## Contents

1. [What it is](#1-what-it-is)
2. [Installation](#2-installation)
3. [Architecture](#3-architecture)
4. [Host awareness](#4-host-awareness)
5. [Divi integration](#5-divi-integration)
6. [The admin interface](#6-the-admin-interface)
7. [Every setting](#7-every-setting)
8. [WP-CLI](#8-wp-cli)
9. [REST API](#9-rest-api)
10. [Hooks and filters](#10-hooks-and-filters)
11. [Data it stores](#11-data-it-stores)
12. [Multi-site workflow](#12-multi-site-workflow)
13. [Troubleshooting](#13-troubleshooting)
14. [Tests](#14-tests)
15. [Limits](#15-limits)

---

## 1. What it is

Most performance plugins are a page cache with features bolted on. On a managed
host that already caches pages at the server level, you are paying for a
feature you cannot use — and on a Divi site, a generic plugin misunderstands
the theme in ways that break pages.

RC Rocket inverts both assumptions. It detects the host and switches its page
cache off where the host owns that layer. It detects the Divi generation and
adapts, because Divi 4 and Divi 5 are different products wearing the same name.

**Five modules:**

| Module | Does |
|---|---|
| **Safety** | Kill switch, settings history and rollback, error beacon |
| **Cache** | Page cache, surrogate-key purging, zero-PHP delivery rules |
| **Assets** | Per-template script and style control, bloat removal, font localization |
| **Media** | Lazy loading, dimensions, LCP hints, background video and embed gating |
| **JavaScript** | Defer, delay, lazy render, Divi animation control |

**Design commitments**

- Nothing leaves your server. No cloud processing, no external API, no per-site fee.
- The kill switch has four independent routes in, one of which works when you are locked out of wp-admin.
- Every rewrite is discarded if it throws or if it loses half the document.
- A feature is switched off automatically when the host or Divi already does it better.

---

## 2. Installation

Upload the zip through **Plugins → Add New → Upload**, activate, open **RC Rocket**.

On first run, go to **System check**. It fetches your home page over HTTP with
no cookies — exactly what a logged-out visitor gets — and reports what actually
happened rather than what the settings claim.

**Requirements:** PHP 8.1+, WordPress 6.5+. The plugin refuses to load below
either and says so in the admin.

**Recommended first settings:** leave everything at defaults, turn
**Automatic rollback off**, and browse your own site logged out for two minutes
to populate the asset inventory.

### Building the admin interface from source

The zip ships a compiled admin app. To rebuild it:

```
npm install
npm run build      # writes assets/admin/app.js and app.css
npm run dev        # watch mode
```

The build is a single esbuild call against `wp.element` and `@wordpress/components`
globals. No webpack, no `@wordpress/scripts`, no 300MB toolchain.

---

## 3. Architecture

```
rc-rocket.php              Bootstrap, requirement guards, lifecycle hooks
src/
  Autoloader.php           PSR-4, no Composer — installs from a zip anywhere
  Container.php            Lazy service factories
  Plugin.php               Kernel: registers services and modules, migrations
  Contracts/Module.php     id, label, defaults, register, boot
  Support/
    Settings.php           One autoloaded option, dot-notation access
    Context.php            Describes the request as tokens
    SafeMode.php           The kill switch
    Hosting.php            Managed host detection and purge forwarding
    Filesystem.php         Atomic writes, recursive delete, measurement
    Logger.php
  Frontend/HtmlPipeline.php  One shared output buffer, priority chain
  Cache/                   Key, Store, Rules, Purge, Preloader, Dropin, ServerRules
  Assets/                  AssetsModule, Registry, Presets, Fonts
  Media/                   MediaModule, Video, Embeds
  Js/JsModule.php
  Safety/                  SafetyModule, History
  Integrations/            Divi, DiviSettings, DiviOptimizer, DiviAnimations
  Admin/                   AdminMenu, RestController, SelfTest
  Cli/Commands.php
dropins/advanced-cache.php
tests/                     Eight suites, run with plain php
```

**Key decisions and why**

| Decision | Reason |
|---|---|
| Own autoloader, no Composer | Must install from a zip on a host with no shell |
| One autoloaded option for all settings | One row, already in `alloptions` — never a settings table |
| Cache key algorithm in a WordPress-free file | The drop-in and the plugin must never disagree about what a key is |
| Direct filesystem calls, not `WP_Filesystem` | The cache writer runs on every uncached view; it cannot afford credential prompts |
| Surrogate keys as an inverted index of empty files | Purge-by-tag becomes a directory listing, not a full scan |
| Temp file plus `rename()` | Atomic — a concurrent reader never sees half a page |
| Purges queued to `shutdown` | An editor saving a post never waits on the filesystem |
| One shared output buffer | Nested buffers with undefined ordering is how plugins rewrite each other's half-finished output |
| Everything through REST | UI, WP-CLI and CI share one API and cannot drift |

### The HTML pipeline

Every module that rewrites markup attaches to one filter, `rc-rocket/html`, in
a fixed order:

| Priority | Does |
|---|---|
| 10 | Media — lazy loading, dimensions, LCP hints |
| 12 | Background video gating |
| 13 | Vimeo and YouTube embeds |
| 15 | Google Fonts localization |
| 20 | Lazy render |
| 22 | Divi animations |
| 30 | Script delay — last, because it rewrites tags the others matched on |
| 40 | Divi form nonce hydration |
| 90 | Error beacon |

Two guards sit around the chain. A rewrite that throws is caught and the
original markup is served. A rewrite whose output is less than half the length
of its input is rejected. Both are logged.

---

## 4. Host awareness

Detected automatically: **Kinsta, WP Engine, SiteGround, Pressable, Flywheel,
Rocket.net, Cloudways**. Anything else is treated as self-managed.

On a host that runs its own page cache:

- RC Rocket's page cache never boots — no buffering, no drop-in, no disk writes
- The `advanced-cache.php` drop-in is never installed, and an existing one is never overwritten
- The **Server rules** tab is hidden, because you cannot edit the host's nginx
- Image conversion is refused on hosts that forbid it
- Only **explicit** purges are forwarded to the host, rate limited to one a minute

That last point matters. Managed hosts already purge their own cache on content
changes, precisely. Forwarding every post save as a complete flush would leave
the cache permanently cold. Divi's asset cache is still invalidated on content
changes, because that part is ours.

To override:

```php
add_filter( 'rc-rocket/host/forward_automatic_purges', '__return_true' );
```

**On a self-managed host** the full cache engine runs: sharded disk store,
gzip twins, ETag and 304 handling, surrogate-key invalidation, throttled
warming, and generated nginx, Apache and Cloudflare Worker rules for serving
cached pages without starting PHP.

---

## 5. Divi integration

### Version awareness

Divi 4 and Divi 5 are branched on, never assumed. `Divi::is_divi_five()` gates
behaviour, and features Divi already performs are switched off rather than
duplicated.

### Never touched

Hard-coded, not settings:

- The Visual Builder and back-end builder (`et_fb`, `et_bfb`, `et_pb_preview`)
- Theme Builder previews
- Divi Leads split tests — both the cookie and the `_et_pb_use_ab_testing` meta
- Divi Library and Theme Builder post types
- Blog and Portfolio AJAX pagination (`et_blog`)

### Theme Builder invalidation

Cached pages are tagged with the layout IDs that rendered them, so editing one
global header clears exactly the pages using it — not the whole cache, and not
nothing.

### Divi's asset cache

Divi keeps generated CSS in `wp-content/et-cache`, and section background
images live in that CSS rather than in the markup. RC Rocket invalidates it
through **Divi's own API**, never by deleting the directory. Deleting it while
a page cache sits in front strips the CSS that draws your backgrounds — this is
the mechanism behind "I cleared the cache and the old design is still showing".

### Form nonces

Divi contact and optin forms embed a nonce that expires in 12 hours, silently
capping every Divi site's usable cache lifetime. RC Rocket strips it at write
time and hydrates a fresh one from a REST endpoint on load.

### Background video

Detected on the attribute signature `autoplay` + `loop`, which only a
decorative video carries — deliberately **not** requiring `muted`, because
Divi 4 applies that from JavaScript and the attribute never appears in the
markup.

The poster resolves automatically from Divi's own section settings: the
shortcode carries both `background_video_mp4` and `background_image`, matched
on filename so cache-busting query args and post-migration URL rewrites do not
break it. Manual posters override it.

**The safety rule:** a video is only ever withheld when a poster exists to take
its place. Without one, every gating setting is ignored and Divi's behaviour is
untouched. A hole where the hero was is worse than a slow hero.

### Animations

Divi holds every animated element at `opacity: 0` until a script reveals it. If
that script is deferred, delayed, blocked or slow, the element never appears —
and because it is invisible rather than absent, nothing looks broken. A CSS
fallback reveals anything the script never reached. Animations can also be
skipped below a breakpoint, and `prefers-reduced-motion` is honoured, which
Divi does not do on its own.

### Reconciliation with Divi's own settings

`DiviSettings` reads Divi's Performance tab and reports overlaps and conflicts
in **System check**: emoji removal set in both places, two systems unloading the
same libraries, Google Fonts handled twice, delay stacked on Divi's deferred
jQuery, or one of Divi's own optimizations left switched off.

Where Divi's Dynamic JavaScript Libraries is on, RC Rocket's builder library
unloader stands down. Divi knows which modules rendered; parsing shortcodes
after the fact is worse information.

### Protected handles

Never dequeued without an explicit `force` flag: `jquery`, `jquery-core`,
`divi-style`, `divi-custom-script`, `et-core-common`,
`et-builder-modules-script`, `et-dynamic-asset-helpers`, `divi-runtime`,
`divi-module-library-script`, `divi-script-library`, `divi-style-dynamic`.

Dropping jQuery on Divi 4 does not slow the site down. It stops it working.

---

## 6. The admin interface

Seven tabs. **Server rules** is hidden on managed hosts.

**Overview** — live counters, host mode, Divi build, purge and warm controls.

**Assets** — the recorded inventory grouped by owning plugin, per-handle rules,
suggested removals, Google Fonts, Divi builder libraries, WordPress bloat.

**Media** — lazy loading, dimensions, LCP priority, background video, Vimeo and
YouTube embeds, Divi hero preloads.

**JavaScript** — defer, delay, exclusions, lazy render, Divi animations, preconnect.

**Cache & Divi** — cache lifetime and buckets, Divi coupling, exclusions, warming.

**Safety** — kill switch, automatic rollback, reported errors, change history.

**System check** — the diagnostic. Fetches the home page as an anonymous
visitor and reports what it can prove: safe mode state, whether rewriting
reached the page, deferred and delayed counts, image and video handling, font
localization, Divi settings reconciliation, cron schedules, and errors from your
own domain.

---

## 7. Every setting

### general

| Key | Default | Does |
|---|---|---|
| `general.safe_mode` | `false` | Master kill switch |
| `general.skip_logged_in` | `true` | Logged-in users see the unoptimized site |
| `general.debug` | `false` | Verbose logging |
| `general.debug_comment` | `true` | Appends the RC Rocket marker to optimized pages |

### safety

| Key | Default | Does |
|---|---|---|
| `safety.enabled` | `true` | |
| `safety.history` | `true` | Record every settings change with a diff |
| `safety.error_beacon` | `true` | Report visitor JavaScript errors |
| `safety.auto_safe_mode` | `true` | Disable optimization when errors spike |
| `safety.error_threshold` | `4` | Distinct new errors in 15 minutes |
| `safety.ignore_third_party` | `true` | Ad-blocked third parties never count |
| `safety.notify_admin` | `true` | Email on automatic rollback |

### cache

| Key | Default | Does |
|---|---|---|
| `cache.enabled` | `true` | Inert on managed hosts |
| `cache.ttl` | `36000` | Ten hours |
| `cache.separate_mobile` | `true` | Separate mobile bucket |
| `cache.cache_logged_in` | `false` | |
| `cache.gzip` | `true` | Write pre-compressed twins |
| `cache.debug_headers` | `true` | `X-RC-Rocket-Cache` |
| `cache.query_whitelist` | `p, page_id, s, paged, lang, currency` | Anything else is uncacheable |
| `cache.excluded_uris` | `[]` | Wildcards, or `#regex#` |
| `cache.excluded_agents` | `facebookexternalhit, ia_archiver` | |
| `cache.vary_cookies` | `[]` | Extra cache buckets |
| `cache.server_delivery` | `false` | Write a server-readable mirror |
| `cache.preload_batch_size` | `8` | URLs per cron batch |
| `cache.preload_max_urls` | `500` | |
| `cache.preload_on_purge` | `true` | |
| `cache.clear_divi_cache` | `true` | Invalidate et-cache with our purge |
| `cache.hard_clear_divi_cache` | `false` | Delete the directory — only safe with no page cache in front |
| `cache.refresh_form_nonces` | `true` | Decouple cache TTL from nonce lifetime |
| `cache.fix_viewport` | `false` | Remove Divi's `user-scalable=no` |

### assets

| Key | Default | Does |
|---|---|---|
| `assets.scan` | `true` | Record what loads per template |
| `assets.rules` | `[]` | Per-handle disable rules |
| `assets.fonts.localize` | `false` | Serve Google Fonts from your own domain |
| `assets.fonts.preload` | `true` | |
| `assets.divi.unload_modules` | `false` | Divi 4 only; off when Divi does it |
| `assets.bloat.emojis` | `true` | |
| `assets.bloat.dashicons` | `true` | Kept when the admin bar shows |
| `assets.bloat.embeds` | `false` | |
| `assets.bloat.jquery_migrate` | `false` | Divi 4 may need it — test |
| `assets.bloat.block_library` | `false` | Turn on only if you use no blocks |
| `assets.bloat.comment_reply` | `true` | |
| `assets.bloat.heartbeat_front` | `true` | |
| `assets.bloat.xmlrpc` · `rsd_link` · `shortlink` · `generator` · `wlwmanifest` · `rest_links` | `true` | Head cleanup |

**Rule shape**

```json
{ "handle": "wpforms-full", "kind": "style", "scope": "except",
  "targets": ["singular:page"], "force": false }
```

`scope` is `everywhere`, `targets` or `except`. Targets are context tokens,
`url:/path`, or `regex:#pattern#`.

**Context tokens:** `site`, `front_page`, `blog_index`, `singular`,
`singular:{post_type}`, `post:{id}`, `archive`, `taxonomy:{tax}`, `term:{id}`,
`author:{id}`, `search`, `not_found`, `woocommerce`, `woocommerce:checkout`.

### media

| Key | Default | Does |
|---|---|---|
| `media.lazy_load` | `true` | |
| `media.skip_first` | `2` | Images loaded eagerly — your LCP candidates |
| `media.lazy_iframes` | `true` | |
| `media.add_dimensions` | `true` | The CLS fix |
| `media.async_decoding` | `true` | |
| `media.lcp_priority` | `true` | `fetchpriority="high"` on the first images |
| `media.hero_preloads` | `[]` | `{template, url, media}` |
| `media.exclusions` | `skip-lazy, no-lazy, et_pb_menu__logo` | |
| `media.video.disable_below` | `980` | Skip background video below this width |
| `media.video.lazy_until_visible` | `true` | |
| `media.video.require_fast_connection` | `true` | Not on 2G or 3G |
| `media.video.respect_save_data` | `true` | |
| `media.video.respect_reduced_motion` | `true` | |
| `media.video.preload_none` | `true` | |
| `media.video.preload_poster` | `true` | |
| `media.video.posters` | `[]` | `{template, url}` — usually automatic on Divi |
| `media.video.gate_background_embeds` | `true` | Vimeo and YouTube backgrounds |
| `media.video.facade_embeds` | `true` | Play button instead of a loaded player |
| `media.video.vimeo_dnt` | `true` | Drops Vimeo's tracking cookies |
| `media.video.vimeo_quality` | `540p` | |
| `media.video.embed_posters` | `[]` | `{id, url}` — otherwise fetched via oEmbed |

### js

| Key | Default | Does |
|---|---|---|
| `js.defer` | `false` | |
| `js.delay` | `false` | **Highest-risk setting in the plugin** |
| `js.delay_timeout` | `6` | Seconds before running anyway |
| `js.exclusions` | 20 entries | Never defer or delay these |
| `js.lazy_render` | `false` | `content-visibility` on offscreen sections |
| `js.lazy_selectors` | 5 entries | Skips the first three Divi sections |
| `js.lazy_intrinsic` | `640px` | Reserved height |
| `js.preconnect` | `[]` | |
| `js.divi_animations.reveal_fallback` | `true` | Reveal what the script never reached |
| `js.divi_animations.reveal_after` | `3` | Seconds |
| `js.divi_animations.disable_on_mobile` | `false` | |
| `js.divi_animations.mobile_breakpoint` | `980` | |
| `js.divi_animations.respect_reduced_motion` | `true` | |
| `js.divi_animations.disable_everywhere` | `false` | |

---

## 8. WP-CLI

```
wp rc-rocket check                     # every diagnostic; exits non-zero on failure
wp rc-rocket status                    # cache size, drop-in, host, Divi, safe mode
wp rc-rocket purge                     # everything
wp rc-rocket purge --url=<url>
wp rc-rocket purge --key=divi-tb-118   # one surrogate key
wp rc-rocket purge --expired
wp rc-rocket preload                   # warm from published URLs
wp rc-rocket preload --stop
wp rc-rocket assets                    # full inventory as a table
wp rc-rocket assets --unused           # handles on only one template
wp rc-rocket safe-mode on|off
wp rc-rocket rules [nginx|apache|cloudflare]
wp rc-rocket config export > site.json
wp rc-rocket config import site.json
```

`check` exiting non-zero is what makes it useful across many sites — it loops in
a shell script and tells you which installs need attention.

---

## 9. REST API

Namespace `rc-rocket/v1`. All routes require `manage_options` except the two
marked public.

| Route | Method | Does |
|---|---|---|
| `/settings` | GET, POST | Read and merge settings |
| `/status` | GET | Cache, drop-in, host, Divi, safe mode |
| `/self-test` | GET | Full diagnostic |
| `/purge` | POST | `scope`: `all`, `url`, `key`, `expired` |
| `/preload` | POST | `action`: `start`, `stop` |
| `/assets` | GET, DELETE | Inventory, suggestions; DELETE also purges fonts |
| `/history` | GET | Change history and safe mode state |
| `/history/restore` | POST | Roll back by `id` |
| `/errors` | GET, DELETE | Grouped error log; DELETE releases safe mode |
| `/server-rules` | GET | nginx, Apache, Cloudflare snippets |
| `/config` | GET, POST | Export and import JSON |
| `/nonces` | GET | **Public** — refreshes Divi form nonces |
| `/beacon` | POST | **Public** — receives visitor error reports |

---

## 10. Hooks and filters

**Actions you can call**

```php
do_action( 'rc-rocket/purge/all' );
do_action( 'rc-rocket/purge/key', 'post-42' );
do_action( 'rc-rocket/purge/url', 'https://example.com/about/' );
```

**Actions you can listen to**

| Hook | Arguments |
|---|---|
| `rc-rocket/booted` | `Container` |
| `rc-rocket/cache/purged` | `string $scope`, `int $entries` |
| `rc-rocket/settings/before_save` | `array $new`, `array $old` |
| `rc-rocket/settings/saved` | `array $settings` |

**Filters**

| Filter | Purpose |
|---|---|
| `rc-rocket/html` | The rewrite chain |
| `rc-rocket/should_optimize` | Final say on whether to touch a request |
| `rc-rocket/cache/bypass_reason` | Return a string to skip caching |
| `rc-rocket/cache/surrogate_keys` | Add invalidation tags |
| `rc-rocket/cache/dir` | Move the cache directory |
| `rc-rocket/context/tokens` | Add targeting tokens |
| `rc-rocket/divi/modules_in_play` | Declare modules rendered dynamically |
| `rc-rocket/host/forward_automatic_purges` | Forward content purges to the host |
| `rc-rocket/host/purge` | Support an unrecognised host |
| `rc-rocket/preload/urls` | Control what gets warmed |
| `rc-rocket/modules` | Add or remove modules |

**Examples**

```php
// Never optimize a specific page.
add_filter( 'rc-rocket/should_optimize', function ( $yes ) {
    return is_page( 'booking' ) ? false : $yes;
} );

// A plugin renders a Divi gallery dynamically; keep the lightbox loaded.
add_filter( 'rc-rocket/divi/modules_in_play', function ( array $modules ) {
    $modules[] = 'et_pb_gallery';
    return $modules;
} );

// Tag cached pages so a custom object can invalidate them.
add_filter( 'rc-rocket/cache/surrogate_keys', function ( array $keys ) {
    $keys[] = 'pricing-table';
    return $keys;
} );
do_action( 'rc-rocket/purge/key', 'pricing-table' );
```

---

## 11. Data it stores

| Option | Autoloaded | Contents |
|---|---|---|
| `rcrocket_settings` | yes | Every setting |
| `rcrocket_history` | no | Change history with diffs |
| `rcrocket_asset_index` | no | Recorded assets per template |
| `rcrocket_error_log` | no | Last 100 reported errors |
| `rcrocket_error_baseline` | no | Errors the site produced unoptimized |
| `rcrocket_auto_safe_mode` | no | Automatic rollback state |
| `rcrocket_font_index` | no | Localized font stylesheets |
| `rcrocket_embed_posters` | no | oEmbed thumbnails |
| `rcrocket_image_dimensions` | no | Measured image sizes |
| `rcrocket_preload_queue` / `_state` | no | Warming progress |

**Files:** `wp-content/cache/rc-rocket/` (pages, keys, mirror, config.json, log)
and `wp-content/uploads/rc-rocket/fonts/`.

**Cron:** `rc-rocket/cache/cleanup` hourly, `rc-rocket/preload/batch` as needed,
`rc-rocket/fonts/refresh` weekly.

Deactivation removes the drop-in, the `WP_CACHE` line it added, cached pages
and scheduled events. Deletion removes everything including options.

**Privacy:** the error beacon sends an error message, the failing file, a
template signature and a page path. No personal data, no third party, no cookies.

---

## 12. Multi-site workflow

Two config files, one per Divi generation, kept in Git.

```bash
# On a proven site
wp rc-rocket config export > configs/divi5-kinsta.json

# On each new site
wp plugin install rc-rocket-0.5.1.zip --activate
wp rc-rocket config import configs/divi5-kinsta.json
wp rc-rocket check
```

Across many installs:

```bash
for site in $(cat sites.txt); do
  ssh "$site" 'cd ~/public && wp rc-rocket check' || echo "NEEDS ATTENTION: $site"
done
```

**Per-site by nature, do not expect a shared config to be enough:** asset rules,
hero preloads and video posters. Import the shared config first, then set those.

**Rollout:** two canaries for a week, then five, then ten, then the rest. The
gate between stages is evidence, not time.

---

## 13. Troubleshooting

**Nothing appears to be happening.** System check → *Rewriting reaching
visitors*. If it fails, safe mode is on or you are looking at a cached copy from
before your change. Remember `skip_logged_in` is on by default: your own browser
is not what visitors see. Test in a private window.

**Something broke.** In order of speed:

1. `?rcr_safe=1` on the URL — if the problem disappears it is RC Rocket, if not it is not
2. Safety → Change history → roll back
3. Safety → Safe mode on
4. `wp rc-rocket safe-mode on`
5. `define( 'RC_ROCKET_SAFE_MODE', true );` in wp-config.php

**A slider, tab or form stopped working.** Almost always script delay. Add the
script's filename to the JavaScript exclusions rather than switching the whole
feature off.

**A section background vanished.** Divi's generated CSS was invalidated while
cached HTML still referenced it. Clear the cache once more and reload. Check
that `hard_clear_divi_cache` is off.

**Content is invisible rather than missing.** A Divi animation whose reveal
script never ran. Enable the animation reveal fallback.

**Fonts fell back to a system stack.** The localizer could not download them.
Check the log at `wp-content/cache/rc-rocket/rc-rocket.log` and switch
`assets.fonts.localize` off until resolved.

**The plugin keeps switching itself off.** Check whether the errors are yours.
Third-party failures and broken images cannot trigger a rollback; only script
failures can. If it still misfires, switch **Switch everything off when errors
spike** off and keep the beacon as a reporting tool.

**Your cache seems permanently cold on a managed host.** Confirm you are on
0.5.1 or later — earlier versions forwarded every content purge as a complete
host flush.

---

## 14. Tests

Eight suites, 233 assertions, no framework and no Composer. A stub WordPress
surface plus real Divi 4 and Divi 5 markup as fixtures.

```
for f in tests/run*.php; do php "$f" | tail -1; done
```

| Suite | Covers |
|---|---|
| run.php | Cache keys, context matching, video and embed rewriting |
| run2.php | Pipeline helpers, settings, safe mode, history, Divi mapping |
| run3.php | Container wiring, media rewriting, script delay, Divi optimizer |
| run4.php | Host detection, server rules, error attribution |
| run5.php | Adversarial markup, pathological input, builder protection |
| run6.php | Failure guards, cache store on a real filesystem |
| run7.php | Config as code, fonts, embeds |
| run8.php | Divi animations, host purge discipline |

Exit status is non-zero on failure, so this drops into CI unchanged.

---

## 15. Limits

**Cannot be fixed by this or any plugin:** multiple redirects, HTTP/2 or HTTP/3
availability, server TTFB on a slow host, the Accessibility category, the SEO
category, Divi 4's DOM nesting, legacy JavaScript in vendor bundles.

**Deliberately not done:** image conversion — banned on several managed hosts,
and a dedicated cloud optimizer does it better. Critical CSS generation — both
Divi generations ship their own. User-agent-conditional HTML for crawlers —
that is cloaking.

**Unproven in production at time of writing:** the Google Fonts localizer, the
oEmbed poster fetch, and Kinsta purge forwarding. Verify each on a canary before
relying on it.

**Highest-risk setting:** script delay. It rewrites every script tag and replays
the page lifecycle afterwards. Enable it last, on one site, and test every
slider, form and widget before it goes anywhere else.
