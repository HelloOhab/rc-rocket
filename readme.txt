=== RC Rocket ===
Contributors: abdul
Tags: divi, performance, core web vitals, assets, kinsta
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.12.1
License: GPLv2 or later

Performance tuning built around Divi 4 and Divi 5, including managed hosts that
run their own page cache.

== Description ==

Most performance plugins are page caches with extras bolted on. On a managed
host that already caches pages at the server level, that leaves you paying for
a feature you cannot use. RC Rocket detects the host and becomes the layer that
is actually missing: asset control, media delivery, JavaScript timing, and a
safety net.

= Host aware =

Kinsta, WP Engine, SiteGround, Pressable, Flywheel, Rocket.net and Cloudways are
detected automatically. On those hosts the page cache never boots, purges are
forwarded to the host instead, and features the host forbids — such as
server-based image conversion — are hidden rather than silently failing.

On a self-managed host the full cache engine runs, including generated nginx,
Apache and Cloudflare rules for serving cached pages without starting PHP.

= Divi aware =

* Divi 4 and Divi 5 are detected and handled differently. Divi 5 generates its
  own critical CSS, so RC Rocket does not duplicate that work.
* Theme Builder templates are tracked, so editing a global header invalidates
  exactly the pages that render it.
* Divi's asset cache is invalidated through Divi's own API, never by deleting
  the directory — deleting it while a page cache is in front strips the CSS
  that draws your section backgrounds.
* The Visual Builder, Theme Builder previews, Divi Leads split tests and the
  Blog module's AJAX pagination are never touched.
* Inline scripts are never delayed on Divi 4, because its modules bootstrap
  inline against jQuery.
* Divi hero backgrounds live in CSS, invisible to plugins that only read image
  tags. RC Rocket measures each page's real hero in visitors' browsers
  (separately for phones and computers) and preloads it, whether it is an
  image or a section background. You can also name them yourself.
* Section, row and module background images below the first three sections
  load as the visitor scrolls, not all at once.

= Asset manager =

Records which scripts and styles load on each template, from real traffic,
grouped by the plugin that owns them. Disable any of them everywhere, only on
named templates, or everywhere except named templates. Handles that would break
Divi are locked unless you force them.

= Preloading =

* Link preloading on the Speculation Rules API: pages start loading when a
  visitor hovers a link. Never applied to the Divi builder, cart, checkout,
  account, logout or add-to-cart links.
* Cache warming that follows the cache in front of the site: our own on a
  self-managed host, Kinsta's on Kinsta. After a post is published the
  pages Kinsta just purged are requested again.
* Font preloading and DNS prefetch.

= Housekeeping =

* Database cleanup: old revisions (Divi saves one per builder save),
  auto-drafts, trash, spam, expired transients, fragmented tables. On
  demand or on a schedule.
* Heartbeat control for the front end, the dashboard and the editors.

= Per-page options =

An RC Rocket box in the editor sidebar of every page, post and project:
never cache this page, and switch any optimization off for that page only —
lazy loading of images, iframes and Divi backgrounds, video handling, defer, delay, lazy render, local fonts, Divi
library unloading, asset rules and link preloading. Options switched off
site-wide show greyed out. Pages excluded in WP Rocket's box keep those
choices.

= Safety net =

* Safe mode via a toggle, a `?rcr_safe=1` URL, or a wp-config constant.
* Every settings change is recorded with a readable diff and one-click rollback.
* A beacon reports JavaScript errors from real visitors. If several distinct
  errors are each seen by more than one visitor, RC Rocket disables itself
  for two hours, clears every cache, and emails you.
* A rewrite that throws, or that loses half the document, is discarded and the
  original markup is served.

= AI assistants (MCP) =

On WordPress 6.9 and newer, RC Rocket registers its abilities with the
WordPress Abilities API: get the status, run the system check, read
front-end errors, read and change settings, apply a preset, clear the
whole cache, a URL or a post, set a page's options, switch safe mode,
read and re-measure hero images, and clean the database.

To use them from Claude, ChatGPT, Cursor or another MCP client, install the
MCP Adapter plugin (github.com/WordPress/mcp-adapter) and connect the client
with an application password. Each ability runs with the permissions of the
user the client signs in as: settings need an administrator, and clearing
one page is open to editors. Reading abilities are marked read-only and the
database cleanup is marked destructive, so clients ask before running it.
Switch it off under Safety.

== Changelog ==

= 0.12.1 =
From testing on a Divi 4 and a Divi 5 site on Kinsta.
* New: "Don't let tracking cookies block caching" (Cache tab, on by
  default). A page that sets a cookie from the server is never cached by
  Kinsta, WP Engine, Cloudflare or RC Rocket, and Meta Pixel for WordPress
  sets _fbp and _fbc that way on every page, so every visitor got an
  uncached page. Those cookies are now removed from pages for logged-out
  visitors; the pixel still sets them in the browser. With pages cached,
  the plugin's server-side (Conversions API) events only fire on cache
  misses; browser pixel events are unaffected. The cookie list is editable.
* System check: "Home page served from cache" no longer names Cloudflare's
  __cf_bm cookie as the cause of a bypass. Cloudflare adds it at its edge,
  after the host has decided.

= 0.12.0 =
Fixes from a full review before rolling out to every site.

Security
* The public error beacon can no longer be used to switch a site's
  optimizations off. It exists only while the beacon setting is on, rejects
  oversized reports and caps every field, never counts an error with no
  source, and switches safe mode on automatically at most once a day.
* The hero-image beacon only accepts the URL of an image file with no query
  string. A forged report could mark every image as the hero or make every
  visitor's browser request any URL on the site for a month. A hero whose
  URL carries a query (?resize=, ?ver=) is no longer preloaded, rather than
  preloading a different file.
* "Update URI" added to the plugin header: WordPress no longer asks
  wordpress.org about a plugin named rc-rocket (the name is not reserved
  there), and any such entry is dropped. Update manifests and packages must
  be served over HTTPS (an http:// manifest is logged and ignored: check
  RC_ROCKET_UPDATE_URL on every site before upgrading). The update check
  gives up after 10 seconds, not 20.
* Settings arriving from the admin, an imported file, a rollback or a
  preset keep only values of the expected type. A malformed file could
  previously fatal every page. Importing the same file twice no longer
  reports a failure, and an import keeps the site's schema version so
  migrations do not run again.

Defaults (sites with saved settings keep theirs)
* Database cleanup is opt-in: nothing is scheduled and revisions, spam and
  trashed comments are not ticked until someone chooses to. Presets no
  longer change these. "Keep N revisions" can no longer be set below 1.
* Lazy rendering of offscreen sections is off by default and moved to the
  Maximum preset. content-visibility clips anything that overlaps a section
  edge and re-anchors fixed-position elements to the section.

Page cache (self-managed hosts)
* Switching from WP Rocket now works: its emptied advanced-cache.php and
  its WP_CACHE false line are taken over instead of treated as another
  cache plugin. Installation reports failure when WP_CACHE cannot be set;
  a WP_CACHE defined by an expression (getenv()) is left alone.
* A request for //about-us/ could be cached as the home page. Paths that do
  not start with a single slash, or contain //, are never cached.
* Divi Theme Builder templates and layouts, and Divi Theme Options, now
  clear the cache. The hooks used before are not fired by Divi 4.27 or 5.
* The web-server mirror only ever receives anonymous pages; a logged-in or
  cookie bucket could reach it with some settings. ".." path segments are
  dropped from mirror paths.
* A page flushed part way through is not cached as a fragment, and a
  response that is not text/html is never cached.
* Repeated response headers (several Link headers) survive a cache hit.
* The drop-in finds its plugin files and cache folder after a site moves to
  another path. Installed drop-ins are refreshed on upgrade.
* The generated Cloudflare Worker no longer claims a purge that does not
  exist: copies expire after 10 minutes, the origin's private/no-store is
  respected, only HTML is cached, and WooCommerce/EDD cookies, login, cart,
  checkout and account pages bypass it.

Front end
* Script delay looks at whole <script> elements: a tag inside a JavaScript
  string or a JSON block is never rewritten.
* A script kept on time takes the scripts it depends on with it, including
  through alias handles such as "jquery", and on sites without Divi its
  inline data (wpforms_settings, wpcf7) as well.
* Lazy loading and image hints skip scripts, JSON, <noscript>, <template>,
  <textarea>, styles and comments. The pixel image inside <noscript> no
  longer counts as the page's first image, which pushed the real hero out
  of the eager slots.
* Google Fonts: only the faces covering Latin are preloaded, upright first.
  The first four files of Google's stylesheet are Cyrillic and Greek.
* Divi Video modules with an image overlay, and the Video Slider, keep
  their real iframe: Divi starts those players by reading its src.
* nomodule scripts (which never report back when added late) and inline
  document.write() (which replaces the page when run late) are never
  delayed.
* AMP pages are left alone; script delay and lazy render skip WooCommerce
  cart, checkout and account pages.
* Link prefetching skips wishlist removal and EDD action links.
* The System check tab shows an error instead of spinning forever.

= 0.11.3 =
Fixes from speed testing the staging site.
* A script is no longer delayed or deferred when an inline script further
  down the page calls into it. Plugins that print their own inline snippet
  instead of using wp_add_inline_script() were missed before: the official
  Facebook pixel threw "FacebookSignal is not defined" on every page view
  and its tracking never started. The same check runs when delay is off and
  only defer is on.
* A script is no longer delayed when an inline script on the page loads
  that same file itself. Trustindex's fallback loader was downloading its
  loader a second time.
* Header and mega menu plugins (Divi Mad Menu, Divi Menu Pro, Max Mega
  Menu, UberMenu and others) are never delayed. On phones the first tap on
  the menu button did nothing, because that tap is what released the
  delayed scripts and the menu's code arrived too late to see it.
* Form plugins (WPForms, Gravity Forms, Contact Form 7, Ninja Forms, Fluent
  Forms, Formidable, Forminator) are never delayed. A WPForms form with a
  file upload or a dropdown never started when its scripts were delayed:
  no upload box, no dropdown, no validation. They are still deferred.
* Browser properties a script assigns (window.location, window.onscroll)
  are not mistaken for globals the script defines.
* Image attributes are now added before a self-closing slash, not after it.
* When the hero is a preloaded background, other images (usually the logo)
  lose fetchpriority="high" so they no longer compete with it.

= 0.11.2 =
Diagnostics for the errors and the cache bypass seen on the staging site.
* New System check: "jQuery loading". It reads the home page as visitors get
  it and says whether jQuery is deferred and by whom (RC Rocket/WordPress,
  or Divi's "Defer jQuery And jQuery Migrate" or another plugin), and
  whether code in the page calls jQuery while it is still a stand-in: the
  cause of "jQuery(...).on is not a function" and "$(...).on is not a
  function". It names the fix.
* Each reported error now records what jQuery was at that moment (its
  version, a stand-in, or not loaded) and how many delayed scripts were
  still waiting. The Safety tab shows it under the error.
* The error beacon now starts listening at the top of the page. Errors
  thrown while the page was still loading were missed before.
* "Home page served from cache" now says why Kinsta bypassed it: the
  cookie the page sets or its Cache-Control header, or that nothing in the
  response explains it (caching switched off for the environment, common
  on staging).

= 0.11.1 =
Fixes from a full code review.
* Fixed: the Divi Visual Builder lost Heartbeat when front-end Heartbeat
  was switched off (the default). The builder runs on the front end, and
  post locking, autosave and the logged-out check use Heartbeat. It now
  follows the editor setting, which never fully disables it.
* Fixed: head cleanup (jQuery Migrate, emojis, comment-reply and the rest)
  no longer applies inside the Divi builder.
* Fixed: "Serve Google Fonts locally" missed Divi's default font loading.
  With "Improve Google Fonts Loading" on, Divi prints the @font-face rules
  into the page instead of linking a Google stylesheet. Those font files
  are now downloaded in the background and served from the site, and the
  preconnect to Google is dropped once nothing uses it.
* Changed: disabling XML-RPC is off by default and no longer part of any
  preset; it is a security choice, not a speed one. It never applies while
  Jetpack is active, since Jetpack and the WordPress mobile apps need it.
* Kinsta: cache warming uses batches of at most 4 pages with a 0.75-second
  pause, so warming never holds more than one PHP worker besides the page
  it requests.
* WP_CACHE is added to wp-config.php even when the file's first line has
  code after the opening tag.
* Video placeholder posters are quoted safely inside their CSS.

= 0.11.0 =
* New: automatic hero detection. Visitors' browsers report each page's
  Largest Contentful Paint element, for phones and computers separately,
  and from then on that image is requested first. For an image that means
  fetchpriority="high", and loading="lazy" is removed if a theme added it.
  For a Divi section background it means a preload hint (one per device
  when they differ). Measured per page for pages and posts, per template
  for archives. Measurements last 30 days or until the page is saved. The
  results, and a "Measure every page again" button, are on the Media tab.
* New: lazy loading for Divi background images. Backgrounds of sections,
  rows, columns and modules below the first three sections load when the
  visitor scrolls near them. Off in the Safe preset, and switchable per
  page.
* New: RC Rocket for AI assistants. Thirteen abilities through the
  WordPress Abilities API, available as MCP tools with the MCP Adapter
  plugin.
* The hero beacon accepts reports only with the page's daily token and
  limits each visitor. It never prioritises an image the page does not
  contain, and never preloads from a host the page does not already use.


= 0.10.0 =
* Fixed: on Kinsta, "Clear this page" in the admin bar now clears Kinsta's
  cached copy of the page. It used to clear only RC Rocket's own cache,
  which Kinsta sites do not use, so the old page kept being served. The
  page is warmed again 30 seconds later.
* New: "Clear cache" link under each published post and page in the
  admin lists.
* New: editors can clear single pages (admin bar and post lists). Clearing
  everything stays with administrators, or any role given the
  rcrocket_purge_cache capability.
* New: about 60 more tracking parameters are ignored by the cache, among
  them Google's srsltid, gad_source and gad_campaignid, HubSpot, Matomo,
  Piwik, ShareASale, Instagram, X and Reddit. Visits from ads, newsletters
  and Google Shopping now get the cached page. Add your own under Cache,
  Exclusions.
* New: link preloading never fetches file downloads (PDF, Word, Excel, zip,
  audio and video).
* Delay JavaScript: loading starts on the first press, not only on release.
  Scripts that set window.onload or listen for readystatechange now run
  once the page is already loaded. The browser looks up the servers of
  delayed scripts in advance (dns-prefetch).
* The debug log is capped at 1 MB.

= 0.9.0 =
* New: per-page options in the editor sidebar, like WP Rocket's: "Never
  cache this page" and a checkbox for each optimization, so one page that
  misbehaves can opt out without changing the whole site. "Never cache"
  also sends no-cache headers for a host's page cache.
* Sites moving from WP Rocket keep their per-page exclusions for lazy
  loading, deferring and delaying until the page's RC Rocket box is saved.

= 0.8.3 =
* Fixed: a Divi hero with a background video could show a small frame in
  one corner with the rest of the hero empty. RC Rocket held the video's
  sources back, and Divi sizes a background video to cover its section from
  the video itself. Background videos now load exactly as Divi intends and
  only gain a poster; holding them back is a separate opt-in setting.
* Fixed: on Divi 5, Delay JS held back Divi's inline module setup and
  configuration, so hero content and animations waited for a mouse move or
  failed. On any Divi site inline scripts now always run on time, and only
  third-party and plugin scripts are delayed — never the theme, Divi or
  WordPress core.

= 0.8.2 =
* Fixed: an autoplaying, muted, looping Vimeo or YouTube player with its
  controls showing (a showreel) was treated as a decorative background, so
  it never loaded on phones. Such players are now left exactly as they are.
  Only true backgrounds — Vimeo background=1, or autoplay, muted and loop
  with controls hidden — wait for the viewport and connection checks.
* Fixed: click-to-play placeholders were skipped by Divi's FitVids, which
  finds video iframes by their src, leaving the frame at its fixed height
  with bands above and below the video. Placeholders on Divi pages now get
  the shape FitVids would give them.
* A self-hosted video with controls is never held back.

= 0.8.1 =
* Fixed: Vimeo and YouTube embeds could disappear for logged-out visitors.
  RC Rocket wrapped the iframe in its own element, and for background
  embeds positioned that element absolutely, so a box sized by its video
  collapsed to nothing. The iframe now keeps its exact place, size and
  attributes; the poster and play button are shown inside it until the
  player loads.
* Embeds controlled by a script (the Vimeo or YouTube Player API, or an
  api/enablejsapi URL, as custom Unmute buttons use) are left untouched.
* Any video or iframe with the class skip-lazy or no-lazy, or a
  data-no-lazy attribute, is left exactly as rendered.

= 0.8.0 =
* Every speed option is on out of the box, as with WP Rocket: script
  deferring and delaying, lazy rendering, local Google Fonts, Divi library
  unloading, jQuery Migrate and embed removal, the viewport fix, link
  preloading on hover, and a weekly database cleanup. Cleanups that delete
  content (emptying the trash, rebuilding tables) stay opt-in.
* Optimization levels on the Overview tab: Safe, Recommended (the default)
  and Maximum, one click each. A preset only changes speed options, never
  your exclusions or rules, and every switch can be rolled back from the
  change history. Sites upgrading keep their settings, shown as Custom.
* Deferring now uses WordPress's own script loading strategy, so a script
  that an inline snippet or a blocking script depends on stays blocking
  instead of breaking.
* Fixed: on Divi 4, delaying a library whose inline "after" snippet runs on
  time left the snippet calling into nothing. Such libraries are no longer
  delayed.

= 0.7.0 =
* Security: the public error beacon could be used by anyone to switch the
  plugin off. Reports now need the page's token, each visitor has a budget,
  and an error only counts once two visitors have reported it.
* Fixed: Divi contact forms failed on optimized pages, because the refreshed
  nonce was minted for the wrong action. The public nonce endpoint now only
  signs Divi's actions.
* Fixed: safe mode, manual or automatic, did not reach cached pages. Every
  cache is now cleared when it trips, is released, or expires.
* Fixed: the generated Apache rules skipped the first rule of the WordPress
  block, breaking Application Passwords on CGI hosts.
* Fixed: on Kinsta the Clear cache button, RC Rocket settings changes, Divi
  Theme Options and Theme Builder saves never reached Kinsta's cache. They
  are forwarded now, debounced to one flush per burst.
* Fixed: pages served by the web server rules were never invalidated.
* Fixed: the cache never hit on a non-default port.
* Fixed: the preload header could be used by anyone to bypass the cache.
* Fixed: Heartbeat was removed on post-new.php and every admin screen.
* Fixed: visitors waited on Google while fonts were localized; delayed
  module scripts broke; load events were fired twice; added image heights
  ignored the declared width; background videos did not autoplay on Divi 4;
  reduced-motion rules removed Divi Transform settings.
* New: link preloading, font preloading, DNS prefetch.
* New: database cleanup and per-context heartbeat control.
* New: warming the Kinsta cache after a post is published; re-warming
  exactly what a purge removed.
* New: the system check reports whether the page cache (Kinsta's or ours)
  is serving the home page.
* The admin app ships as readable source with no build step.

= 0.6.4 =
* Fixed the Plugin Details modal showing a blank description or a stale
  version number when the cached update-check result predates a plugin
  update (defensive check against a missing/legacy cache shape).

= 0.6.3 =
* Tested up to WordPress 7.1.

= 0.6.2 =
* Plugin URI updated to https://rhythmco.com/.
* The self-hosted update manifest can now supply its own description for the
  Plugin Details modal, instead of a hardcoded one-liner.

= 0.6.1 =
* GitHub Actions release workflow. Tagging a version lints every file, runs all
  nine test suites, builds the admin app and attaches the zip to the release.
  A failing test publishes nothing.


= 0.6.0 =
* Self-hosted updates. Point every site at one JSON manifest or a GitHub
  repository and new releases appear in Dashboard > Updates like any other
  plugin. Private repositories are supported with a token sent as an auth
  header rather than embedded in a URL.
* Automatic updates are off by default: a plugin that rewrites every page on
  thirty client sites should reach them because someone decided it should.
* System check reports the installed and available versions.
* wp rc-rocket version [--flush]


= 0.5.1 =
* Fixed: on a managed host, every automatic content purge was forwarded as a
  complete flush of the host's page cache. A post save, a comment or a term
  edit would empty the whole site cache, leaving it permanently cold and TTFB
  looking like a hosting fault. Only explicit purges are forwarded now, rate
  limited to one a minute. Divi's asset cache is still invalidated on content
  changes, because that part is ours to own.
* Added: Divi animation manager. Divi hides every animated element until a
  script reveals it, so a deferred or blocked script leaves content invisible
  rather than missing. A CSS safety net reveals anything the script never got
  to, animations can be skipped below a breakpoint, and the reduced-motion
  preference is finally honoured.
* System check reports how many animated elements a page carries.


= 0.5.0 =
* First release with an executable test suite: 222 assertions across seven
  suites, run against real Divi 4 and Divi 5 markup. Ships in /tests.
* Fixed: importing settings was a silent no-op. save() reloaded from the
  database over the imported values, so config-as-code never worked.
* Fixed: cache statistics counted the empty index.html guard files as cached
  pages, inflating the figure on every dashboard.
* Fixed: Divi version lookup ran once per script tag during script delaying,
  performing dozens of theme lookups per page render.
* Fixed: head insertion silently discarded its payload on a document with no
  <head>, losing injected styles and preloads.
* Automatic safe mode moved out of settings into its own option, so trips no
  longer write to the change history.
* Only script failures can trigger an automatic rollback. A 404ing image
  cannot be caused by deferring a script, so it no longer disarms the plugin.


= 0.3.0 =
* Google Fonts localizer: downloads fonts to your uploads folder, adds
  font-display swap, preloads them, and removes the Google preconnects.
* Divi 4 builder library unloading, aware of Theme Builder templates. Switched
  off automatically on Divi 5, which already does this.
* Error beacon reworked. It now learns a baseline of errors your site already
  produced, counts distinct problems rather than repeat events, ignores
  third-party failures, and only arms itself when a risky optimization is
  actually enabled.

= 0.2.1 =
* Divi background video manager: poster fallback, preload=none, source
  detachment, and connection, viewport and reduced-motion gating. A video is
  only ever withheld when a poster exists to replace it.

= 0.2.0 =
* Asset manager with automatic per-template recording (Module E).
* Media delivery: LCP prioritisation, lazy loading, dimension injection, Divi
  hero preloads (Module D).
* JavaScript: defer, delay-until-interaction, lazy render (Module C).
* Safety net: kill switch, settings history and rollback, error beacon with
  automatic safe mode (Module F).
* One shared HTML pipeline instead of one buffer per feature.

= 0.1.3 =
* Divi asset cache is invalidated through Divi's API rather than deleted.
* Purge order fixed so cached HTML never points at a removed stylesheet.
* Viewport fix no longer buffers wp_head.

= 0.1.2 =
* Managed host detection and passthrough mode.

= 0.1.0 =
* Cache engine and Divi integration.
