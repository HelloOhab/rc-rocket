=== RC Rocket ===
Contributors: abdul
Tags: divi, performance, core web vitals, assets, kinsta
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.3
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
  tags. You can name them and have them preloaded.

= Asset manager =

Records which scripts and styles load on each template, from real traffic,
grouped by the plugin that owns them. Disable any of them everywhere, only on
named templates, or everywhere except named templates. Handles that would break
Divi are locked unless you force them.

= Safety net =

* Safe mode via a toggle, a `?rcr_safe=1` URL, or a wp-config constant.
* Every settings change is recorded with a readable diff and one-click rollback.
* A beacon reports JavaScript errors from real visitors. If they spike, RC
  Rocket disables itself for two hours and emails you.
* A rewrite that throws, or that loses half the document, is discarded and the
  original markup is served.

== Changelog ==

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
