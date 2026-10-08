(() => {
  var we = window.RCRocketBoot || {},
    { apiFetch: ke } = wp,
    C = (t, n = {}) => ke({ path: `/rc-rocket/v1${t}`, ...n }),
    k = {
      boot: we,
      getSettings: () => C("/settings"),
      saveSettings: (t) => C("/settings", { method: "POST", data: t }),
      getStatus: () => C("/status"),
      purge: (t = "all", n = "") =>
        C("/purge", { method: "POST", data: { scope: t, value: n } }),
      preload: (t = "start") =>
        C("/preload", { method: "POST", data: { action: t } }),
      serverRules: () => C("/server-rules"),
      exportConfig: () => C("/config"),
      selfTest: () => C("/self-test"),
      getAssets: () => C("/assets"),
      clearAssets: () => C("/assets", { method: "DELETE" }),
      getHistory: () => C("/history"),
      restoreHistory: (t) =>
        C("/history/restore", { method: "POST", data: { id: t } }),
      getErrors: () => C("/errors"),
      clearErrors: (t = !1) =>
        C(`/errors${t ? "?baseline=1" : ""}`, { method: "DELETE" }),
    },
    oe = (t) => {
      if (!t) return "0 B";
      let n = ["B", "KB", "MB", "GB"],
        s = Math.min(Math.floor(Math.log(t) / Math.log(1024)), n.length - 1);
      return `${(t / 1024 ** s).toFixed(s === 0 ? 0 : 1)} ${n[s]}`;
    };
  var { useState: _e } = wp.element,
    { Button: U, Notice: j, Spinner: Ce } = wp.components;
  function F({ status: t, busy: n, onPurge: s, onPreload: a, reload: i }) {
    let [l, c] = _e(null);
    if (!t)
      return wp.element.createElement(
        "div",
        { className: "rcr-loading" },
        wp.element.createElement(Ce, null),
        wp.element.createElement("span", null, "Reading cache state\u2026"),
      );
    let { cache: d, dropin: h, divi: u, preload: f, server: b, hosting: y } = t,
      r = y && y.page_cache,
      e = f && f.running,
      p = async (v, T) => {
        let g = await v();
        (c(T(g)), i());
      };
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      l &&
        wp.element.createElement(
          j,
          { status: "success", onRemove: () => c(null) },
          l,
        ),
      r &&
        wp.element.createElement(
          j,
          { status: "info", isDismissible: !1 },
          wp.element.createElement("strong", null, y.label, " detected."),
          " ",
          y.reason,
          " Everything else in RC Rocket still applies, and it is where your remaining speed is.",
        ),
      !r &&
        h.foreign_dropin &&
        wp.element.createElement(
          j,
          { status: "error", isDismissible: !1 },
          "Another plugin owns ",
          wp.element.createElement("code", null, "advanced-cache.php"),
          ". Deactivate it, then reactivate RC Rocket. Two page caches on one site fight each other.",
        ),
      !r &&
        !h.wp_cache &&
        wp.element.createElement(
          j,
          { status: "warning", isDismissible: !1 },
          wp.element.createElement("code", null, "WP_CACHE"),
          " is off, so cached pages still boot WordPress. Add",
          " ",
          wp.element.createElement("code", null, "define( 'WP_CACHE', true );"),
          " to the top of",
          " ",
          wp.element.createElement("code", null, "wp-config.php"),
          ".",
        ),
      wp.element.createElement(
        "div",
        { className: "rcr-strip" },
        wp.element.createElement(
          "div",
          { className: "rcr-metric" },
          wp.element.createElement(
            "span",
            { className: "rcr-metric__value" },
            r ? y.label : d.files,
          ),
          wp.element.createElement(
            "span",
            { className: "rcr-metric__label" },
            r ? "page cache owner" : "pages cached",
          ),
        ),
        wp.element.createElement(
          "div",
          { className: "rcr-metric" },
          wp.element.createElement(
            "span",
            { className: "rcr-metric__value" },
            r ? "forwarded" : oe(d.bytes),
          ),
          wp.element.createElement(
            "span",
            { className: "rcr-metric__label" },
            r ? "purges" : "on disk",
          ),
        ),
        wp.element.createElement(
          "div",
          { className: "rcr-metric" },
          wp.element.createElement(
            "span",
            {
              className: `rcr-metric__value ${r || h.installed ? "is-on" : "is-off"}`,
            },
            r ? "passthrough" : h.installed ? "active" : "inactive",
          ),
          wp.element.createElement(
            "span",
            { className: "rcr-metric__label" },
            "mode",
          ),
        ),
        wp.element.createElement(
          "div",
          { className: "rcr-metric" },
          wp.element.createElement(
            "span",
            { className: "rcr-metric__value" },
            u.active ? u.version : "\u2014",
          ),
          wp.element.createElement(
            "span",
            { className: "rcr-metric__label" },
            u.active ? u.engine : "Divi not detected",
          ),
        ),
      ),
      wp.element.createElement(
        "div",
        { className: "rcr-actions" },
        wp.element.createElement(
          U,
          {
            variant: "primary",
            disabled: n,
            onClick: () =>
              p(
                () => s("all"),
                () => (r ? `Purge sent to ${y.label}.` : "Cache cleared."),
              ),
          },
          r ? `Clear ${y.label}'s cache` : "Clear all cached pages",
        ),
        !r &&
          wp.element.createElement(
            U,
            {
              variant: "secondary",
              disabled: n,
              onClick: () =>
                p(
                  () => s("expired"),
                  (v) => `Cleared ${v.entries} expired pages.`,
                ),
            },
            "Clear expired only",
          ),
        !r &&
          wp.element.createElement(
            U,
            {
              variant: e ? "secondary" : "primary",
              disabled: n,
              onClick: () =>
                p(
                  () => a(e ? "stop" : "start"),
                  (v) => (e ? "Preload stopped." : `Queued ${v.queued} URLs.`),
                ),
            },
            e ? "Stop warming" : "Warm the cache",
          ),
      ),
      e &&
        wp.element.createElement(
          "p",
          { className: "rcr-progress" },
          "Warming ",
          f.done,
          " of ",
          f.total,
          " URLs. Batches run on cron, a few at a time, so your own server is not the one taking the load.",
        ),
      wp.element.createElement(
        "table",
        { className: "rcr-table" },
        wp.element.createElement(
          "tbody",
          null,
          wp.element.createElement(
            "tr",
            null,
            wp.element.createElement("th", null, "Server"),
            wp.element.createElement(
              "td",
              null,
              b.software || "unknown",
              " \xB7 PHP ",
              b.php,
            ),
          ),
          wp.element.createElement(
            "tr",
            null,
            wp.element.createElement("th", null, "Divi asset cache"),
            wp.element.createElement(
              "td",
              null,
              wp.element.createElement("code", null, u.et_cache_dir),
              " ",
              u.et_cache_writable ? "" : "\u2014 not writable",
            ),
          ),
          wp.element.createElement(
            "tr",
            null,
            wp.element.createElement("th", null, "Theme Builder"),
            wp.element.createElement(
              "td",
              null,
              u.theme_builder
                ? "Detected. Cached pages are tagged with their template, so editing a global header clears only the pages that use it."
                : "Not in use.",
            ),
          ),
        ),
      ),
    );
  }
  var {
      ToggleControl: P,
      TextControl: Ne,
      TextareaControl: ne,
      RangeControl: I,
      PanelBody: D,
    } = wp.components,
    ie = (t) =>
      Array.isArray(t)
        ? t.join(`
`)
        : "",
    re = (t) =>
      t
        .split(
          `
`,
        )
        .map((n) => n.trim())
        .filter(Boolean);
  function K({ settings: t, update: n }) {
    let s = t.cache || {},
      a = (i) => (l) => n("cache", i, l);
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        D,
        { title: "Caching", initialOpen: !0 },
        wp.element.createElement(P, {
          label: "Cache pages",
          help: "Stores a static copy of every anonymous page view and serves it before WordPress loads.",
          checked: !!s.enabled,
          onChange: a("enabled"),
        }),
        wp.element.createElement(I, {
          label: "Keep pages for (hours)",
          value: Math.round((s.ttl || 36e3) / 3600),
          min: 1,
          max: 720,
          onChange: (i) => a("ttl")(i * 3600),
          help: "Longer is faster. With form-nonce refresh on, days are safe on a Divi site.",
        }),
        wp.element.createElement(P, {
          label: "Separate cache for mobile",
          help: "Turn off if your Divi layout is fully responsive from one markup output \u2014 it halves your cache size.",
          checked: !!s.separate_mobile,
          onChange: a("separate_mobile"),
        }),
        wp.element.createElement(P, {
          label: "Store gzip copies",
          help: "Lets the web server send pre-compressed bytes without re-compressing on every request.",
          checked: !!s.gzip,
          onChange: a("gzip"),
        }),
        wp.element.createElement(P, {
          label: "Send debug headers",
          help: "Adds X-RC-Rocket-Cache so you can confirm a hit in DevTools. Safe to leave on.",
          checked: !!s.debug_headers,
          onChange: a("debug_headers"),
        }),
      ),
      wp.element.createElement(
        D,
        { title: "Tracking cookies", initialOpen: !1 },
        wp.element.createElement(P, {
          label: "Don't let tracking cookies block caching",
          help: "A page that sets a cookie from the server is never cached by Kinsta, WP Engine, Cloudflare or RC Rocket. Meta Pixel for WordPress sets _fbp and _fbc this way on every page. This removes those cookies from pages for logged-out visitors; the pixel still sets them in the browser.",
          checked: !!s.strip_tracking_cookies,
          onChange: a("strip_tracking_cookies"),
        }),
        s.strip_tracking_cookies &&
          wp.element.createElement(ne, {
            label: "Cookie names",
            help: "One per line. Only cookies that a tracking script also sets in the browser belong here, never a login, cart or consent cookie.",
            value: ie(s.tracking_cookies),
            onChange: (i) => a("tracking_cookies")(re(i)),
          }),
      ),
      wp.element.createElement(
        D,
        { title: "Divi", initialOpen: !0 },
        wp.element.createElement(P, {
          label: "Clear Divi's asset cache too",
          help: "Divi keeps its own generated CSS in et-cache. Leaving it behind is why a cleared cache can still show the old design.",
          checked: !!s.clear_divi_cache,
          onChange: a("clear_divi_cache"),
        }),
        wp.element.createElement(P, {
          label: "Fix Divi's viewport tag",
          help: "Divi outputs user-scalable=no, which fails the Lighthouse accessibility check and blocks pinch-zoom on phones. There is no Divi setting for it.",
          checked: !!s.fix_viewport,
          onChange: a("fix_viewport"),
        }),
        wp.element.createElement(P, {
          label: "Refresh Divi form nonces",
          help: "Divi contact and optin forms embed a nonce that expires in 12 hours. This fetches a fresh one on load so you can cache for days without silent submission failures.",
          checked: !!s.refresh_form_nonces,
          onChange: a("refresh_form_nonces"),
        }),
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "The Visual Builder, Theme Builder previews, Divi Leads split tests and the Blog module's AJAX pagination are never cached. Those rules are not optional and cannot be switched off.",
        ),
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          wp.element.createElement("strong", null, "Troubleshooting:"),
          " if a section background or hero video stops rendering, switch off ",
          wp.element.createElement("em", null, "Clear Divi's asset cache too"),
          ", clear the cache once, and reload. That isolates Divi's generated CSS from anything RC Rocket does to it.",
        ),
      ),
      wp.element.createElement(
        D,
        { title: "Delivery", initialOpen: !1 },
        wp.element.createElement(P, {
          label: "Write server-readable copies",
          help: "Also writes each page where nginx or Apache can find it, so a cache hit costs no PHP at all. Pair with the rules on the Server rules tab.",
          checked: !!s.server_delivery,
          onChange: a("server_delivery"),
        }),
      ),
      wp.element.createElement(
        D,
        { title: "Exclusions", initialOpen: !1 },
        wp.element.createElement(ne, {
          label: "Never cache these paths",
          help: "One per line. Use * as a wildcard, or wrap in # for a regex: #^/go/.+#",
          value: ie(s.excluded_uris),
          onChange: (i) => a("excluded_uris")(re(i)),
        }),
        wp.element.createElement(ne, {
          label: "Query parameters that are safe to cache",
          help: "Anything not listed here makes a request uncacheable. Tracking parameters are stripped automatically.",
          value: ie(s.query_whitelist),
          onChange: (i) => a("query_whitelist")(re(i)),
        }),
        wp.element.createElement(ne, {
          label: "Extra query parameters to ignore",
          help: "One per line. Removed before the cache is looked up, so every visit with them gets the same cached page. Around 80 tracking parameters (Google Ads, Meta, HubSpot, Matomo, ShareASale and more) are already ignored.",
          value: ie(s.ignored_params),
          onChange: (i) => a("ignored_params")(re(i)),
        }),
        wp.element.createElement(Ne, {
          label: "Mobile user-agent pattern",
          value: s.mobile_agents || "",
          onChange: a("mobile_agents"),
        }),
      ),
      wp.element.createElement(
        D,
        { title: "Warming", initialOpen: !1 },
        wp.element.createElement(I, {
          label: "URLs per batch",
          value: s.preload_batch_size || 8,
          min: 1,
          max: 40,
          onChange: a("preload_batch_size"),
          help: "Keep this low on shared hosting. Warming should never be the reason your site goes down.",
        }),
        wp.element.createElement(I, {
          label: "Maximum URLs to warm",
          value: s.preload_max_urls || 500,
          min: 10,
          max: 5e3,
          step: 10,
          onChange: a("preload_max_urls"),
        }),
        wp.element.createElement(P, {
          label: "Re-warm after a purge",
          help: "Refills exactly what a purge removed. On Kinsta and other managed hosts this warms the host's own cache.",
          checked: !!s.preload_on_purge,
          onChange: a("preload_on_purge"),
        }),
        wp.element.createElement(P, {
          label: "Warm the host cache after publishing",
          help: "Managed hosts only. Kinsta clears a post's pages when you publish or update it; this requests them again a minute later so the next visitor gets a cached copy.",
          checked: !!s.warm_after_publish,
          onChange: a("warm_after_publish"),
        }),
      ),
    );
  }
  var { useEffect: Pe, useState: le } = wp.element,
    { Button: Se, TabPanel: Be, Spinner: xe } = wp.components,
    Te = {
      nginx:
        "Paste into your server block, above the PHP location, then reload nginx.",
      apache: 'Paste into .htaccess above the "# BEGIN WordPress" block.',
      cloudflare: "Deploy as a Worker on a route matching your site.",
    };
  function $() {
    let [t, n] = le(null),
      [s, a] = le(null);
    if (
      (Pe(() => {
        k.serverRules().then(n);
      }, []),
      !t)
    )
      return wp.element.createElement(
        "div",
        { className: "rcr-loading" },
        wp.element.createElement(xe, null),
        wp.element.createElement(
          "span",
          null,
          "Generating rules for this install\u2026",
        ),
      );
    let i = (l) => {
      (navigator.clipboard.writeText(t[l]),
        a(l),
        setTimeout(() => a(null), 2e3));
    };
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        "p",
        { className: "rcr-note" },
        "A cache hit served through PHP still costs a process and 15\u201340ms. These rules let the web server answer from the same files with PHP never starting. Turn on ",
        wp.element.createElement(
          "strong",
          null,
          "Write server-readable copies",
        ),
        " first, or there will be nothing for them to find.",
      ),
      wp.element.createElement(
        Be,
        {
          className: "rcr-rules-tabs",
          tabs: [
            { name: "nginx", title: "nginx" },
            { name: "apache", title: "Apache" },
            { name: "cloudflare", title: "Cloudflare Worker" },
          ],
        },
        (l) =>
          wp.element.createElement(
            "div",
            { className: "rcr-rules" },
            wp.element.createElement(
              "p",
              { className: "rcr-note" },
              Te[l.name],
            ),
            wp.element.createElement(
              "pre",
              { className: "rcr-code" },
              t[l.name],
            ),
            wp.element.createElement(
              Se,
              { variant: "secondary", onClick: () => i(l.name) },
              s === l.name ? "Copied" : "Copy rules",
            ),
          ),
      ),
    );
  }
  var { useEffect: De, useState: ce } = wp.element,
    {
      Button: G,
      PanelBody: R,
      SelectControl: Re,
      Spinner: Ae,
      TextControl: de,
      ToggleControl: A,
      Notice: he,
    } = wp.components,
    Le = [
      { label: "Everywhere", value: "everywhere" },
      { label: "Only on", value: "targets" },
      { label: "Everywhere except", value: "except" },
    ],
    Oe = [
      [
        "emojis",
        "Emoji script",
        "A script and stylesheet on every page so that older browsers can render emoji.",
      ],
      [
        "dashicons",
        "Dashicons on the front end",
        "Admin icon font. Kept automatically when the admin bar is showing.",
      ],
      [
        "embeds",
        "oEmbed discovery",
        "Turn off only if you never embed this site elsewhere.",
      ],
      [
        "block_library",
        "Gutenberg block styles",
        "A Divi-built page renders no blocks. Leave off if you use blocks anywhere.",
      ],
      [
        "comment_reply",
        "Threaded comment script",
        "Loaded even on pages with comments closed.",
      ],
      [
        "heartbeat_front",
        "Heartbeat on the front end",
        "Admin-ajax polling that visitors never benefit from.",
      ],
      [
        "jquery_migrate",
        "jQuery Migrate",
        "Divi 4 and older third-party modules may need this. Test carefully.",
      ],
      ["xmlrpc", "XML-RPC", "Legacy remote publishing endpoint. Jetpack and the WordPress mobile apps use it, so it stays on while Jetpack is active. Not a speed setting: off by default and left alone by the presets."],
      ["shortlink", "Shortlink tag", ""],
      ["generator", "WordPress version tag", ""],
      ["rsd_link", "RSD link", ""],
      ["wlwmanifest", "Windows Live Writer manifest", ""],
      ["rest_links", "REST API discovery links", ""],
    ];
  function V({ settings: t, update: n, status: s }) {
    let [a, i] = ce(null),
      [l, c] = ce(""),
      d = t.assets || {},
      h = d.rules || [],
      u = d.bloat || {},
      f = d.fonts || {},
      b = d.divi || {},
      y = s && s.divi_assets;
    De(() => {
      k.getAssets().then(i);
    }, []);
    let r = (o) => n("assets", "rules", o),
      e = (o, w) => h.find((m) => m.handle === o && m.kind === w),
      p = (o, w, m) => {
        let N = e(o, w);
        if (!N) {
          r([
            ...h,
            { handle: o, kind: w, scope: "everywhere", targets: [], ...m },
          ]);
          return;
        }
        r(h.map((B) => (B === N ? { ...B, ...m } : B)));
      },
      v = (o, w) => r(h.filter((m) => !(m.handle === o && m.kind === w))),
      T = (o) => p(o.handle, o.kind, { scope: o.scope, targets: o.targets });
    if (!a)
      return wp.element.createElement(
        "div",
        { className: "rcr-loading" },
        wp.element.createElement(Ae, null),
        wp.element.createElement(
          "span",
          null,
          "Reading the asset inventory\u2026",
        ),
      );
    let S = a.handles
      .filter(
        (o) =>
          !l ||
          o.handle.toLowerCase().includes(l.toLowerCase()) ||
          o.owner.toLowerCase().includes(l.toLowerCase()),
      )
      .reduce((o, w) => ((o[w.owner] = o[w.owner] || []).push(w), o), {});
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        R,
        { title: "Asset manager", initialOpen: !0 },
        wp.element.createElement(A, {
          label: "Record what loads on each template",
          help: "Builds the list below from real traffic. Keep it on \u2014 it costs one option write per template per day, and it is how the list stays accurate after a plugin update.",
          checked: !!d.scan,
          onChange: (o) => n("assets", "scan", o),
        }),
        a.handles.length === 0
          ? wp.element.createElement(
              he,
              { status: "info", isDismissible: !1 },
              "Nothing recorded yet. Visit a few pages of your site logged out, then come back \u2014 the inventory fills in as pages are viewed.",
            )
          : wp.element.createElement(
              wp.element.Fragment,
              null,
              wp.element.createElement(de, {
                label: "Filter",
                placeholder: "Search by handle or plugin",
                value: l,
                onChange: c,
              }),
              Object.entries(S).map(([o, w]) =>
                wp.element.createElement(
                  "div",
                  { key: o, className: "rcr-group" },
                  wp.element.createElement(
                    "h3",
                    { className: "rcr-group__title" },
                    o,
                  ),
                  w.map((m) => {
                    let N = e(m.handle, m.kind);
                    return wp.element.createElement(
                      "div",
                      { key: m.kind + m.handle, className: "rcr-asset" },
                      wp.element.createElement(
                        "div",
                        { className: "rcr-asset__id" },
                        wp.element.createElement("code", null, m.handle),
                        wp.element.createElement(
                          "span",
                          { className: "rcr-asset__kind" },
                          m.kind,
                        ),
                        m.protected &&
                          wp.element.createElement(
                            "span",
                            { className: "rcr-asset__locked" },
                            "protected",
                          ),
                        wp.element.createElement(
                          "span",
                          { className: "rcr-asset__where" },
                          m.templates.length,
                          " template",
                          m.templates.length === 1 ? "" : "s",
                        ),
                      ),
                      wp.element.createElement(
                        "div",
                        { className: "rcr-asset__controls" },
                        N
                          ? wp.element.createElement(
                              wp.element.Fragment,
                              null,
                              wp.element.createElement(Re, {
                                value: N.scope,
                                options: Le,
                                onChange: (B) =>
                                  p(m.handle, m.kind, { scope: B }),
                              }),
                              N.scope !== "everywhere" &&
                                wp.element.createElement(de, {
                                  placeholder:
                                    "front_page, singular:page, regex:#^/blog/#",
                                  value: (N.targets || []).join(", "),
                                  onChange: (B) =>
                                    p(m.handle, m.kind, {
                                      targets: B.split(",")
                                        .map((ye) => ye.trim())
                                        .filter(Boolean),
                                    }),
                                }),
                              wp.element.createElement(
                                G,
                                {
                                  variant: "tertiary",
                                  isDestructive: !0,
                                  onClick: () => v(m.handle, m.kind),
                                },
                                "Keep",
                              ),
                            )
                          : wp.element.createElement(
                              G,
                              {
                                variant: "secondary",
                                disabled: m.protected,
                                onClick: () => p(m.handle, m.kind, {}),
                              },
                              m.protected ? "Locked" : "Disable",
                            ),
                      ),
                    );
                  }),
                ),
              ),
            ),
      ),
      wp.element.createElement(
        R,
        { title: "Suggested removals", initialOpen: !1 },
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Each of these loads on pages that cannot use it. Applied only when you click, never automatically.",
        ),
        a.suggestions.map((o) =>
          wp.element.createElement(
            "div",
            { key: o.kind + o.handle, className: "rcr-suggestion" },
            wp.element.createElement(
              "div",
              null,
              wp.element.createElement("code", null, o.handle),
              wp.element.createElement(
                "p",
                { className: "rcr-note" },
                o.reason,
              ),
            ),
            wp.element.createElement(
              G,
              {
                variant: "secondary",
                disabled: !!e(o.handle, o.kind),
                onClick: () => T(o),
              },
              e(o.handle, o.kind) ? "Applied" : "Apply",
            ),
          ),
        ),
      ),
      wp.element.createElement(
        R,
        { title: "Google Fonts", initialOpen: !1 },
        wp.element.createElement(A, {
          label: "Serve Google Fonts from this site",
          help: "Downloads the font files to your uploads folder and rewrites the stylesheet. Removes a DNS lookup, a TLS handshake and a render-blocking round trip before the first glyph exists \u2014 and puts the cache lifetime under your control.",
          checked: !!f.localize,
          onChange: (o) => n("assets", "fonts", { ...f, localize: o }),
        }),
        f.localize &&
          wp.element.createElement(A, {
            label: "Preload the font files",
            help: "Requests the fonts alongside the stylesheet instead of after it. Usually slower: the fonts swap in, so text never waits for them, and a preload takes bandwidth from the stylesheets and the hero image. Off by default.",
            checked: !!f.preload,
            onChange: (o) => n("assets", "fonts", { ...f, preload: o }),
          }),
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Files are refreshed weekly on cron. Nothing is re-encoded, so this is safe on hosts that forbid server-side media processing.",
        ),
      ),
      wp.element.createElement(
        R,
        { title: "Divi builder libraries", initialOpen: !1 },
        y && !y.applicable
          ? wp.element.createElement(
              he,
              { status: "info", isDismissible: !1 },
              y.reason,
            )
          : wp.element.createElement(A, {
              label: "Unload unused Divi libraries",
              help: "Divi 4 loads the lightbox, masonry grid and circle-counter libraries on every page regardless of what the page contains. This detects the modules actually rendered \u2014 including those inherited from Theme Builder templates \u2014 and unloads the rest.",
              checked: !!b.unload_modules,
              onChange: (o) => n("assets", "divi", { ...b, unload_modules: o }),
            }),
      ),
      wp.element.createElement(
        R,
        { title: "WordPress bloat", initialOpen: !1 },
        Oe.map(([o, w, m]) =>
          wp.element.createElement(A, {
            key: o,
            label: w,
            help: m,
            checked: !!u[o],
            onChange: (N) => n("assets", "bloat", { ...u, [o]: N }),
          }),
        ),
      ),
    );
  }
  var {
      PanelBody: M,
      RangeControl: pe,
      TextareaControl: J,
      TextControl: Ee,
      ToggleControl: x,
      Notice: ze,
    } = wp.components,
    Q = (t) =>
      Array.isArray(t)
        ? t.join(`
`)
        : "",
    X = (t) =>
      t
        .split(
          `
`,
        )
        .map((n) => n.trim())
        .filter(Boolean);
  function Y({ settings: t, update: n, status: s }) {
    let a = t.js || {},
      i = (h) => (u) => n("js", h, u),
      l = a.divi_animations || {},
      c = (h) => (u) => n("js", "divi_animations", { ...l, [h]: u }),
      d = s && s.divi && s.divi.active && s.divi.major === 4;
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        M,
        { title: "Loading", initialOpen: !0 },
        wp.element.createElement(x, {
          label: "Defer scripts",
          help: "Scripts stop blocking the parser and run in order once the HTML is parsed. Uses WordPress's own loading strategy, so a script an inline snippet or another script depends on is automatically kept blocking. Low risk, and usually the single biggest render-blocking win.",
          checked: !!a.defer,
          onChange: i("defer"),
        }),
        wp.element.createElement(x, {
          label: "Delay scripts until interaction",
          help: "Nothing runs until the visitor moves, scrolls, taps or the timeout expires. The biggest Total Blocking Time win, and the setting most likely to upset a plugin. Divi's own scripts are never delayed, and if visitors start seeing errors the safety net switches everything off and emails you.",
          checked: !!a.delay,
          onChange: i("delay"),
        }),
        a.delay &&
          wp.element.createElement(pe, {
            label: "Run anyway after (seconds)",
            value: a.delay_timeout || 6,
            min: 1,
            max: 20,
            onChange: i("delay_timeout"),
            help: "A safety net for visitors who never interact \u2014 and for Googlebot.",
          }),
        a.delay &&
          d &&
          wp.element.createElement(
            ze,
            { status: "warning", isDismissible: !1 },
            "Divi 4 detected. Inline scripts are never delayed on Divi 4 \u2014 its modules bootstrap inline against jQuery and delaying them breaks sliders, tabs and the mobile menu.",
          ),
        wp.element.createElement(J, {
          label: "Never defer or delay these",
          help: "Substring match against the whole script tag. One per line. The Divi entries are here for a reason \u2014 removing them is how sliders die.",
          value: Q(a.exclusions),
          onChange: (h) => i("exclusions")(X(h)),
          rows: 10,
        }),
      ),
      wp.element.createElement(
        M,
        { title: "Rendering", initialOpen: !1 },
        wp.element.createElement(x, {
          label: "Lazy render offscreen sections",
          help: "Uses content-visibility so the browser skips layout and paint for sections nobody has scrolled to. Cannot break behaviour \u2014 only rendering timing.",
          checked: !!a.lazy_render,
          onChange: i("lazy_render"),
        }),
        a.lazy_render &&
          wp.element.createElement(
            wp.element.Fragment,
            null,
            wp.element.createElement(J, {
              label: "Selectors to defer",
              help: "The defaults skip the first three Divi sections, because one of them is your LCP element.",
              value: Q(a.lazy_selectors),
              onChange: (h) => i("lazy_selectors")(X(h)),
              rows: 6,
            }),
            wp.element.createElement(Ee, {
              label: "Reserved height",
              help: "Space held for a section before it renders. Too small causes layout shift on scroll.",
              value: a.lazy_intrinsic || "640px",
              onChange: i("lazy_intrinsic"),
            }),
          ),
      ),
      wp.element.createElement(
        M,
        { title: "Divi animations", initialOpen: !0 },
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Divi hides every animated element until a script reveals it. That is fine until the script is deferred, delayed or slow \u2014 then the element never appears, and because it is invisible rather than missing, nothing looks broken.",
        ),
        wp.element.createElement(x, {
          label: "Reveal anything the animation script never got to",
          checked: !!l.reveal_fallback,
          onChange: c("reveal_fallback"),
          help: "A pure CSS safety net. Costs nothing when the animation works normally.",
        }),
        wp.element.createElement(x, {
          label: "Skip animations on mobile",
          checked: !!l.disable_on_mobile,
          onChange: c("disable_on_mobile"),
          help: "Where the main thread is scarcest and the effect is least visible, because everything is stacked in one column anyway.",
        }),
        l.disable_on_mobile &&
          wp.element.createElement(pe, {
            label: "Mobile breakpoint (px)",
            value: l.mobile_breakpoint || 980,
            min: 480,
            max: 1200,
            step: 20,
            onChange: c("mobile_breakpoint"),
          }),
        wp.element.createElement(x, {
          label: "Respect reduced motion",
          checked: !!l.respect_reduced_motion,
          onChange: c("respect_reduced_motion"),
          help: "Divi does not honour this browser preference on its own.",
        }),
        wp.element.createElement(x, {
          label: "Switch animations off everywhere",
          checked: !!l.disable_everywhere,
          onChange: c("disable_everywhere"),
          help: "Blunt, but the single biggest main-thread saving on a page with dozens of animated modules.",
        }),
      ),
      wp.element.createElement(
        M,
        { title: "Connections", initialOpen: !1 },
        wp.element.createElement(J, {
          label: "Preconnect to these origins",
          help: "One URL per line, for third parties you know load on every page \u2014 fonts, analytics, review widgets.",
          value: Q(a.preconnect),
          onChange: (h) => i("preconnect")(X(h)),
          rows: 4,
        }),
      ),
    );
  }
  var {
      Button: W,
      Notice: me,
      PanelBody: L,
      RangeControl: ue,
      SelectControl: je,
      TextControl: O,
      TextareaControl: Me,
      ToggleControl: _,
    } = wp.components,
    We = (t) =>
      Array.isArray(t)
        ? t.join(`
`)
        : "",
    qe = (t) =>
      t
        .split(
          `
`,
        )
        .map((n) => n.trim())
        .filter(Boolean);
  function Z({ settings: t, update: n, status: s, api: x }) {
    var b, y;
    let a = t.media || {},
      i = (r) => (e) => n("media", r, e),
      l = a.hero_preloads || [],
      c = a.video || {},
      d = (r) => (e) => n("media", "video", { ...c, [r]: e }),
      h = (r, e) =>
        d("posters")(
          (c.posters || []).map((p, v) => (v === r ? { ...p, ...e } : p)),
        ),
      u = s && s.hosting && !s.hosting.image_conversion_allowed,
      f = (r, e) =>
        i("hero_preloads")(l.map((p, v) => (v === r ? { ...p, ...e } : p)));
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      u &&
        wp.element.createElement(
          me,
          { status: "info", isDismissible: !1 },
          s.hosting.label,
          " does not permit server-based image conversion, so RC Rocket does not attempt it. Compress and convert with a cloud-based optimizer; everything below still applies.",
        ),
      wp.element.createElement(
        L,
        { title: "Loading", initialOpen: !0 },
        wp.element.createElement(_, {
          label: "Lazy load images",
          help: "Images below the fold wait until the visitor scrolls near them.",
          checked: !!a.lazy_load,
          onChange: i("lazy_load"),
        }),
        wp.element.createElement(ue, {
          label: "Load this many images immediately",
          value: (b = a.skip_first) != null ? b : 2,
          min: 0,
          max: 8,
          onChange: i("skip_first"),
          help: "The first images in the document are your LCP candidates. Lazy loading them is the most common cause of a bad LCP score.",
        }),
        wp.element.createElement(_, {
          label: "Lazy load iframes",
          help: "Maps, videos and embeds wait until they are needed.",
          checked: !!a.lazy_iframes,
          onChange: i("lazy_iframes"),
        }),
        wp.element.createElement(_, {
          label: "Prioritise the first images",
          help: "Marks them fetchpriority=high so the browser requests them ahead of scripts and stylesheets.",
          checked: !!a.lcp_priority,
          onChange: i("lcp_priority"),
        }),
        wp.element.createElement(Me, {
          label: "Never touch images matching",
          help: "Substring match against the image tag. One per line.",
          value: We(a.exclusions),
          onChange: (r) => i("exclusions")(qe(r)),
          rows: 4,
        }),
      ),
      window.RCRocketPanels &&
        wp.element.createElement(window.RCRocketPanels.HeroDetection, {
          settings: t,
          update: n,
          status: s,
          api: x,
        }),
      wp.element.createElement(
        L,
        { title: "Layout stability", initialOpen: !0 },
        wp.element.createElement(_, {
          label: "Add missing width and height",
          help: "Reserves space so the page does not jump as images arrive. This is the Cumulative Layout Shift fix.",
          checked: !!a.add_dimensions,
          onChange: i("add_dimensions"),
        }),
        wp.element.createElement(_, {
          label: "Decode images asynchronously",
          help: "Keeps image decoding off the main thread for everything below the fold.",
          checked: !!a.async_decoding,
          onChange: i("async_decoding"),
        }),
      ),
      wp.element.createElement(
        L,
        { title: "Divi background video", initialOpen: !0 },
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Divi gives a background video no poster and no preload hint, so the browser pulls megabytes of video before it paints anything. On a throttled mobile connection that one decision can own your entire LCP.",
        ),
        wp.element.createElement(
          me,
          { status: "warning", isDismissible: !1 },
          "A background video is only ever withheld when a poster exists to take its place. Without a poster, everything below is ignored and the video loads exactly as Divi intended \u2014 a black hole where the hero was is worse than a slow hero.",
        ),
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          wp.element.createElement(
            "strong",
            null,
            "You usually do not need to add one.",
          ),
          " If the section has a Background Image set in Divi alongside the video, RC Rocket finds it and uses it as the poster automatically, per section. Add one below only to override that, or where Divi has no fallback image.",
        ),
        wp.element.createElement(_, {
          label: "Manage background videos",
          checked: !!c.enabled,
          onChange: d("enabled"),
        }),
        c.enabled &&
          wp.element.createElement(
            wp.element.Fragment,
            null,
            (c.posters || []).map((r, e) =>
              wp.element.createElement(
                "div",
                { key: e, className: "rcr-preload" },
                wp.element.createElement(O, {
                  label: "Poster image URL",
                  value: r.url || "",
                  onChange: (p) => h(e, { url: p }),
                  placeholder:
                    "https://example.com/wp-content/uploads/2026/01/hero-poster.jpg",
                  help: "A still frame from the video. This becomes what visitors see first, and on mobile it may be all they ever see \u2014 choose it accordingly.",
                }),
                wp.element.createElement(O, {
                  label: "On which pages",
                  value: r.template || "",
                  onChange: (p) => h(e, { template: p }),
                  placeholder: "front_page",
                }),
                wp.element.createElement(
                  W,
                  {
                    variant: "tertiary",
                    isDestructive: !0,
                    onClick: () =>
                      d("posters")((c.posters || []).filter((p, v) => v !== e)),
                  },
                  "Remove",
                ),
              ),
            ),
            wp.element.createElement(
              W,
              {
                variant: "secondary",
                onClick: () =>
                  d("posters")([
                    ...(c.posters || []),
                    { url: "", template: "" },
                  ]),
              },
              "Add a poster",
            ),
            wp.element.createElement(_, {
              label: "Hold the video back until it can play well",
              help: "Off by default. Shows the poster and only loads the video near the viewport, on a wide screen and a fast connection. Divi sizes background videos to cover their section from the video itself, so on some heroes holding it back leaves a small frame in one corner. Turn on only if the hero still looks right in a private window.",
              checked: !!c.withhold,
              onChange: d("withhold"),
            }),
            c.withhold && wp.element.createElement(ue, {
              label: "Skip the video below this screen width (px)",
              value: (y = c.disable_below) != null ? y : 980,
              min: 0,
              max: 1400,
              step: 20,
              onChange: d("disable_below"),
              help: "Set to 0 to load the video on every device. 980 matches Divi's own tablet breakpoint.",
            }),
            wp.element.createElement(_, {
              label: "Wait until the section is nearly visible",
              checked: !!c.lazy_until_visible,
              onChange: d("lazy_until_visible"),
              help: "Nothing downloads until the visitor scrolls within 200px of it.",
            }),
            wp.element.createElement(_, {
              label: "Skip on slow connections",
              checked: !!c.require_fast_connection,
              onChange: d("require_fast_connection"),
              help: "No video on 2G or 3G. These are the visitors your LCP score is measured against.",
            }),
            wp.element.createElement(_, {
              label: "Respect Save-Data",
              checked: !!c.respect_save_data,
              onChange: d("respect_save_data"),
              help: "Honours the browser setting where someone has asked sites to use less data.",
            }),
            wp.element.createElement(_, {
              label: "Respect reduced motion",
              checked: !!c.respect_reduced_motion,
              onChange: d("respect_reduced_motion"),
              help: "An autoplaying video is exactly what this browser setting exists to prevent.",
            }),
            wp.element.createElement(_, {
              label: "Preload the poster",
              checked: !!c.preload_poster,
              onChange: d("preload_poster"),
              help: "The poster is now your LCP element, so it gets hero treatment.",
            }),
            wp.element.createElement(_, {
              label: "Set preload=none on the video",
              checked: !!c.preload_none,
              onChange: d("preload_none"),
              help: "Stops the video competing with stylesheets for bandwidth during first paint.",
            }),
          ),
      ),
      wp.element.createElement(
        L,
        { title: "Vimeo and YouTube embeds", initialOpen: !0 },
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "A Vimeo background costs you the video ",
          wp.element.createElement("em", null, "and"),
          " the player \u2014 several hundred kilobytes of JavaScript across three extra origins, each needing its own DNS lookup and handshake before a frame exists. Posters are fetched from the provider automatically, so there is nothing to upload.",
        ),
        wp.element.createElement(_, {
          label: "Hold back decorative background embeds",
          checked: !!c.gate_background_embeds,
          onChange: d("gate_background_embeds"),
          help: "Uses the same viewport, connection and reduced-motion rules as self-hosted background video. The poster shows until all of them agree.",
        }),
        wp.element.createElement(_, {
          label: "Replace normal embeds with a play button",
          checked: !!c.facade_embeds,
          onChange: d("facade_embeds"),
          help: "The player loads on the first click. Nobody pays for a video they never start.",
        }),
        wp.element.createElement(_, {
          label: "Ask Vimeo not to track",
          checked: !!c.vimeo_dnt,
          onChange: d("vimeo_dnt"),
          help: "Adds dnt=1, which drops Vimeo's tracking cookies \u2014 one less request and one less consent problem.",
        }),
        wp.element.createElement(je, {
          label: "Cap Vimeo quality",
          value: c.vimeo_quality || "540p",
          options: [
            { label: "Let Vimeo decide", value: "auto" },
            { label: "360p", value: "360p" },
            { label: "540p \u2014 recommended for backgrounds", value: "540p" },
            { label: "720p", value: "720p" },
            { label: "1080p", value: "1080p" },
          ],
          onChange: d("vimeo_quality"),
          help: "A blurred backdrop behind headline text has no use for 1080p.",
        }),
      ),
      wp.element.createElement(
        L,
        { title: "Divi hero backgrounds", initialOpen: !0 },
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Divi renders section backgrounds as CSS, not as an image tag, so the browser only discovers your hero after the stylesheet parses. Naming it here moves it to the front of the queue. This is usually worth a second or more of mobile LCP on a Divi site.",
        ),
        l.map((r, e) =>
          wp.element.createElement(
            "div",
            { key: e, className: "rcr-preload" },
            wp.element.createElement(O, {
              label: "Image URL",
              value: r.url || "",
              onChange: (p) => f(e, { url: p }),
              placeholder:
                "https://example.com/wp-content/uploads/2026/01/hero.jpg",
            }),
            wp.element.createElement(O, {
              label: "On which pages",
              value: r.template || "",
              onChange: (p) => f(e, { template: p }),
              placeholder: "front_page",
              help: "A token such as front_page, singular:page, post:412 \u2014 or leave blank for every page.",
            }),
            wp.element.createElement(O, {
              label: "Media query (optional)",
              value: r.media || "",
              onChange: (p) => f(e, { media: p }),
              placeholder: "(max-width: 980px)",
              help: "Use this when the mobile hero is a different file from the desktop one.",
            }),
            wp.element.createElement(
              W,
              {
                variant: "tertiary",
                isDestructive: !0,
                onClick: () => i("hero_preloads")(l.filter((p, v) => v !== e)),
              },
              "Remove",
            ),
          ),
        ),
        wp.element.createElement(
          W,
          {
            variant: "secondary",
            onClick: () =>
              i("hero_preloads")([...l, { url: "", template: "", media: "" }]),
          },
          "Add a hero image",
        ),
      ),
    );
  }
  var { useEffect: He, useState: ee } = wp.element,
    {
      Button: q,
      Notice: Ue,
      PanelBody: H,
      RangeControl: Fe,
      Spinner: ge,
      ToggleControl: E,
    } = wp.components,
    fe = (t) => new Date(t * 1e3).toLocaleString();
  function te({ settings: t, update: n, reload: s }) {
    let [a, i] = ee(null),
      [l, c] = ee(null),
      [d, h] = ee(!1),
      u = t.safety || {},
      f = t.general || {},
      b = () => {
        (k.getHistory().then(i), k.getErrors().then((e) => c(e.errors)));
      };
    He(b, []);
    let y = async (e) => {
        (h(!0), await k.restoreHistory(e), h(!1), b(), s());
      },
      r = async (e = !1) => {
        (h(!0), await k.clearErrors(e), h(!1), b(), s());
      };
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      a &&
        a.safe_mode.active &&
        wp.element.createElement(
          Ue,
          { status: "warning", isDismissible: !1 },
          "Safe mode is active (",
          a.safe_mode.reason,
          "). RC Rocket is not changing any page output right now.",
          a.safe_mode.auto_reason &&
            wp.element.createElement(
              wp.element.Fragment,
              null,
              " Reason given: ",
              a.safe_mode.auto_reason,
              ".",
            ),
          a.safe_mode.reason === "auto" &&
            wp.element.createElement(
              wp.element.Fragment,
              null,
              " ",
              wp.element.createElement(
                q,
                { variant: "link", onClick: () => r(!1) },
                "Resume optimization now",
              ),
            ),
        ),
      wp.element.createElement(
        H,
        { title: "Kill switch", initialOpen: !0 },
        wp.element.createElement(E, {
          label: "Safe mode",
          help: "Turns off every optimization without deactivating the plugin. Settings are kept.",
          checked: !!f.safe_mode,
          onChange: (e) => n("general", "safe_mode", e),
        }),
        wp.element.createElement(E, {
          label: "Skip optimization for logged-in users",
          help: "Keeps the admin experience identical to an unoptimized site, which makes debugging far easier.",
          checked: !!f.skip_logged_in,
          onChange: (e) => n("general", "skip_logged_in", e),
        }),
        wp.element.createElement(E, {
          label: "Let AI assistants use RC Rocket (MCP)",
          help: "Registers RC Rocket's abilities with WordPress (6.9 or newer). With the MCP Adapter plugin, an assistant such as Claude can check the status, read errors, clear the cache or apply a preset. It can only do what the user it signs in as is allowed to, and it asks before deleting anything.",
          checked: f.abilities !== !1,
          onChange: (e) => n("general", "abilities", e),
        }),
        wp.element.createElement(
          "p",
          { className: "rcr-note" },
          "Two ways in that do not need this screen: add",
          " ",
          wp.element.createElement("code", null, "?rcr_safe=1"),
          " to any URL to bypass optimization for one request, or put",
          " ",
          wp.element.createElement(
            "code",
            null,
            "define( 'RC_ROCKET_SAFE_MODE', true );",
          ),
          " in wp-config.php if you are ever locked out.",
        ),
      ),
      wp.element.createElement(
        H,
        { title: "Automatic rollback", initialOpen: !0 },
        wp.element.createElement(E, {
          label: "Watch for JavaScript errors from real visitors",
          checked: !!u.error_beacon,
          onChange: (e) => n("safety", "error_beacon", e),
          help: "A small script reports uncaught errors and failed resources. No personal data, no third party.",
        }),
        wp.element.createElement(E, {
          label: "Switch everything off when errors spike",
          checked: !!u.auto_safe_mode,
          onChange: (e) => n("safety", "auto_safe_mode", e),
          help: "Safe mode engages for two hours and you get an email. Better to lose the optimization than the site.",
        }),
        wp.element.createElement(Fe, {
          label: "Different errors within 15 minutes before switching off",
          value: u.error_threshold || 3,
          min: 1,
          max: 30,
          onChange: (e) => n("safety", "error_threshold", e),
        }),
        wp.element.createElement(Fe, {
          label: "Visitors who must see an error before it counts",
          help: "A real breakage reproduces for everyone. Requiring more than one visitor stops a single forged report from switching the plugin off.",
          value: u.min_reporters || 2,
          min: 1,
          max: 10,
          onChange: (e) => n("safety", "min_reporters", e),
        }),
        wp.element.createElement(E, {
          label: "Email me when it happens",
          checked: !!u.notify_admin,
          onChange: (e) => n("safety", "notify_admin", e),
        }),
      ),
      wp.element.createElement(
        H,
        { title: "Reported errors", initialOpen: !0 },
        l === null
          ? wp.element.createElement(ge, null)
          : l.length === 0
            ? wp.element.createElement(
                "p",
                { className: "rcr-note" },
                "Nothing reported. That is the result you want.",
              )
            : wp.element.createElement(
                wp.element.Fragment,
                null,
                wp.element.createElement(
                  "table",
                  { className: "rcr-table" },
                  wp.element.createElement(
                    "tbody",
                    null,
                    l
                      .slice(0, 20)
                      .map((e, p) =>
                        wp.element.createElement(
                          "tr",
                          { key: p },
                          wp.element.createElement(
                            "th",
                            null,
                            fe(e.time),
                            e.count > 1 &&
                              wp.element.createElement(
                                "span",
                                { className: "rcr-count" },
                                "\xD7",
                                e.count,
                              ),
                          ),
                          wp.element.createElement(
                            "td",
                            null,
                            wp.element.createElement("strong", null, e.kind),
                            e.own_site &&
                              wp.element.createElement(
                                "span",
                                { className: "rcr-flag rcr-flag--own" },
                                "your site",
                              ),
                            !e.own_site &&
                              e.source &&
                              wp.element.createElement(
                                "span",
                                { className: "rcr-flag" },
                                "third party",
                              ),
                            e.baseline &&
                              wp.element.createElement(
                                "span",
                                { className: "rcr-flag rcr-flag--base" },
                                "already happening",
                              ),
                            !e.baseline &&
                              !e.counts &&
                              wp.element.createElement(
                                "span",
                                { className: "rcr-flag" },
                                "cannot trigger rollback",
                              ),
                            wp.element.createElement("br", null),
                            e.message,
                            e.source &&
                              wp.element.createElement(
                                wp.element.Fragment,
                                null,
                                wp.element.createElement("br", null),
                                wp.element.createElement(
                                  "code",
                                  { className: "rcr-source" },
                                  e.source,
                                ),
                              ),
                            e.pages &&
                              e.pages.length > 0 &&
                              wp.element.createElement(
                                wp.element.Fragment,
                                null,
                                wp.element.createElement("br", null),
                                wp.element.createElement(
                                  "span",
                                  { className: "rcr-note" },
                                  "on ",
                                  e.pages.join(", "),
                                ),
                              ),
                            (e.jquery || e.delayed > 0) &&
                              wp.element.createElement(
                                wp.element.Fragment,
                                null,
                                wp.element.createElement("br", null),
                                wp.element.createElement(
                                  "span",
                                  { className: "rcr-note" },
                                  "at the time: ",
                                  e.jquery === "stand-in"
                                    ? "jQuery was a stand-in (a deferred jQuery had not arrived yet, so .on() and the rest did not exist)"
                                    : e.jquery === "none"
                                      ? "jQuery was not loaded"
                                      : e.jquery
                                        ? "jQuery " + e.jquery
                                        : "",
                                  e.delayed > 0
                                    ? (e.jquery ? " \xB7 " : "") +
                                        e.delayed +
                                        " delayed scripts still waiting for interaction"
                                    : "",
                                ),
                              ),
                          ),
                        ),
                      ),
                  ),
                ),
                wp.element.createElement(
                  "p",
                  { className: "rcr-note" },
                  "Errors flagged ",
                  wp.element.createElement("strong", null, "your site"),
                  " are worth fixing at the source \u2014 a missing image size, a broken template, a file that no longer exists. Only script failures can ever trigger a rollback: a 404ing image cannot be caused by deferring a script, so undoing the optimization would not fix it.",
                ),
                wp.element.createElement(
                  "div",
                  { className: "rcr-actions" },
                  wp.element.createElement(
                    q,
                    { variant: "primary", isBusy: d, onClick: () => r(!1) },
                    "Clear the log and leave safe mode",
                  ),
                  wp.element.createElement(
                    q,
                    { variant: "secondary", isBusy: d, onClick: () => r(!0) },
                    "Also forget the baseline",
                  ),
                ),
                wp.element.createElement(
                  "p",
                  { className: "rcr-note" },
                  "Errors marked ",
                  wp.element.createElement("strong", null, "already happening"),
                  " were recorded while nothing was being optimized, so they are part of how the site normally behaves and never trigger a rollback. Only new, distinct errors count \u2014 and only while defer, delay, lazy render or an asset rule is actually switched on.",
                ),
              ),
      ),
      wp.element.createElement(
        H,
        { title: "Change history", initialOpen: !0 },
        a === null
          ? wp.element.createElement(ge, null)
          : a.entries.length === 0
            ? wp.element.createElement(
                "p",
                { className: "rcr-note" },
                "No changes recorded yet.",
              )
            : a.entries.map((e) =>
                wp.element.createElement(
                  "div",
                  { key: e.id, className: "rcr-history" },
                  wp.element.createElement(
                    "div",
                    null,
                    wp.element.createElement("strong", null, fe(e.time)),
                    " \xB7 ",
                    e.user,
                    wp.element.createElement(
                      "ul",
                      { className: "rcr-diff" },
                      e.changes.map((p, v) =>
                        wp.element.createElement(
                          "li",
                          { key: v },
                          wp.element.createElement("code", null, p.path),
                          " ",
                          p.from,
                          " \u2192 ",
                          p.to,
                        ),
                      ),
                    ),
                  ),
                  wp.element.createElement(
                    q,
                    { variant: "secondary", isBusy: d, onClick: () => y(e.id) },
                    "Roll back to before this",
                  ),
                ),
              ),
      ),
    );
  }
  var { useEffect: Ie, useState: ve } = wp.element,
    { Button: Ke, Notice: $e, Spinner: Ge } = wp.components,
    Ve = { pass: "Pass", warn: "Check", fail: "Fail", info: "Info" };
  function ae() {
    let [t, n] = ve(null),
      [s, a] = ve(!1),
      i = () => {
        (a(!0),
          k.selfTest()
            .then((h) => {
              (n(h), a(!1));
            })
            .catch((h) => {
              // A failed request must not leave the spinner running forever.
              (n({
                checks: [
                  {
                    status: "fail",
                    label: "The system check could not run",
                    detail: String((h && h.message) || h || "Request failed"),
                  },
                ],
                summary: { pass: 0, warn: 0, fail: 1 },
                fetched: !0,
              }),
                a(!1));
            }));
      };
    if ((Ie(i, []), !t))
      return wp.element.createElement(
        "div",
        { className: "rcr-loading" },
        wp.element.createElement(Ge, null),
        wp.element.createElement(
          "span",
          null,
          "Loading your home page the way a visitor would\u2026",
        ),
      );
    let { checks: l, summary: c, fetched: d } = t;
    return wp.element.createElement(
      wp.element.Fragment,
      null,
      wp.element.createElement(
        "div",
        { className: "rcr-actions" },
        wp.element.createElement(
          Ke,
          { variant: "primary", isBusy: s, onClick: i },
          "Run the checks again",
        ),
        wp.element.createElement(
          "span",
          { className: "rcr-note" },
          c.pass,
          " passed \xB7 ",
          c.warn,
          " to look at \xB7 ",
          c.fail,
          " failed",
        ),
      ),
      !d &&
        wp.element.createElement(
          $e,
          { status: "error", isDismissible: !1 },
          "The home page could not be fetched over HTTP, so the checks that read real markup were skipped. Loopback requests may be blocked.",
        ),
      wp.element.createElement(
        "div",
        { className: "rcr-checks" },
        l.map((h, u) =>
          wp.element.createElement(
            "div",
            { key: u, className: `rcr-check is-${h.status}` },
            wp.element.createElement(
              "span",
              { className: "rcr-check__badge" },
              Ve[h.status],
            ),
            wp.element.createElement(
              "div",
              null,
              wp.element.createElement("strong", null, h.label),
              wp.element.createElement(
                "div",
                { className: "rcr-check__detail" },
                h.detail,
              ),
              h.fix &&
                wp.element.createElement(
                  "div",
                  { className: "rcr-check__fix" },
                  h.fix,
                ),
            ),
          ),
        ),
      ),
      wp.element.createElement(
        "p",
        { className: "rcr-note" },
        "These read what your visitors actually received, not what the settings say should happen. If a change is not showing up here, it is not reaching anyone \u2014 clear the cache and run them again.",
      ),
    );
  }
  var { useCallback: Je, useEffect: Qe, useState: z } = wp.element,
    { Button: Xe, TabPanel: Ye, Notice: Ze } = wp.components;
  function se() {
    let [t, n] = z(null),
      [s, a] = z(null),
      [i, l] = z(!1),
      [c, d] = z(!1),
      [h, u] = z(null),
      f = Je(() => {
        k.getStatus()
          .then(a)
          .catch((g) => u(g.message));
      }, []);
    Qe(() => {
      (k
        .getSettings()
        .then((g) => n(g.settings))
        .catch((g) => u(g.message)),
        f());
    }, [f]);
    let b = (g, S, o) => {
        (n((w) => ({ ...w, [g]: { ...w[g], [S]: o } })), l(!0));
      },
      y = async () => {
        d(!0);
        try {
          let g = await k.saveSettings(t);
          (n(g.settings), l(!1), f());
        } catch (g) {
          u(g.message);
        }
        d(!1);
      },
      r = async (g) => {
        d(!0);
        let S = await k.purge(g);
        return (d(!1), S);
      },
      e = async (g) => {
        d(!0);
        let S = await k.preload(g);
        return (d(!1), S);
      },
      p = s && s.hosting && s.hosting.page_cache,
      v = !s || !s.hosting || s.hosting.editable_server_config,
      T = [
        { name: "dashboard", title: "Overview" },
        { name: "assets", title: "Assets" },
        { name: "media", title: "Media" },
        { name: "js", title: "JavaScript" },
        { name: "preload", title: "Preload" },
        { name: "cache", title: p ? "Cache & Divi" : "Cache" },
        { name: "housekeeping", title: "Housekeeping" },
        { name: "safety", title: "Safety" },
        { name: "check", title: "System check" },
      ];
    return (
      v && T.push({ name: "server", title: "Server rules" }),
      wp.element.createElement(
        "div",
        { className: "rcr-app" },
        wp.element.createElement(
          "header",
          { className: "rcr-header" },
          wp.element.createElement(
            "div",
            null,
            wp.element.createElement(
              "h1",
              { className: "rcr-title" },
              "RC Rocket",
            ),
            wp.element.createElement(
              "p",
              { className: "rcr-subtitle" },
              "Performance tuning built around Divi",
            ),
          ),
          wp.element.createElement(
            "div",
            { className: "rcr-header__actions" },
            i &&
              wp.element.createElement(
                "span",
                { className: "rcr-dirty" },
                "Unsaved changes",
              ),
            wp.element.createElement(
              Xe,
              { variant: "primary", isBusy: c, disabled: !i || c, onClick: y },
              "Save changes",
            ),
          ),
        ),
        h &&
          wp.element.createElement(
            Ze,
            { status: "error", onRemove: () => u(null) },
            h,
          ),
        wp.element.createElement(Ye, { className: "rcr-tabs", tabs: T }, (g) =>
          wp.element.createElement(
            "div",
            { className: "rcr-panel" },
            g.name === "dashboard" &&
              t &&
              window.RCRocketPanels &&
              wp.element.createElement(window.RCRocketPanels.PresetBar, {
                settings: t,
                dirty: i,
                api: C,
                onApplied: (S) => {
                  (n(S), l(!1), f());
                },
              }),
            g.name === "dashboard" &&
              wp.element.createElement(F, {
                status: s,
                busy: c,
                onPurge: r,
                onPreload: e,
                reload: f,
              }),
            g.name === "assets" &&
              t &&
              wp.element.createElement(V, {
                settings: t,
                update: b,
                status: s,
              }),
            g.name === "media" &&
              t &&
              wp.element.createElement(Z, {
                settings: t,
                update: b,
                status: s,
                api: C,
              }),
            g.name === "js" &&
              t &&
              wp.element.createElement(Y, {
                settings: t,
                update: b,
                status: s,
              }),
            g.name === "preload" &&
              t &&
              window.RCRocketPanels &&
              wp.element.createElement(window.RCRocketPanels.Preload, {
                settings: t,
                update: b,
                status: s,
              }),
            g.name === "housekeeping" &&
              t &&
              window.RCRocketPanels &&
              wp.element.createElement(window.RCRocketPanels.Housekeeping, {
                settings: t,
                update: b,
                api: C,
              }),
            g.name === "cache" &&
              t &&
              wp.element.createElement(K, { settings: t, update: b }),
            g.name === "safety" &&
              t &&
              wp.element.createElement(te, {
                settings: t,
                update: b,
                reload: f,
              }),
            g.name === "check" && wp.element.createElement(ae, null),
            g.name === "server" && wp.element.createElement($, null),
          ),
        ),
      )
    );
  }
  var { createRoot: et, StrictMode: tt } = wp.element,
    be = document.getElementById("rcrocket-root");
  be &&
    et(be).render(
      wp.element.createElement(tt, null, wp.element.createElement(se, null)),
    );
})();
