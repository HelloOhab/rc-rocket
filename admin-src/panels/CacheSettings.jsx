const { ToggleControl, TextControl, TextareaControl, RangeControl, PanelBody } = wp.components;

const listToText = (list) => (Array.isArray(list) ? list.join('\n') : '');
const textToList = (text) =>
  text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);

export default function CacheSettings({ settings, update }) {
  const cache = settings.cache || {};
  const set = (key) => (value) => update('cache', key, value);

  return (
    <>
      <PanelBody title="Caching" initialOpen>
        <ToggleControl
          label="Cache pages"
          help="Stores a static copy of every anonymous page view and serves it before WordPress loads."
          checked={!!cache.enabled}
          onChange={set('enabled')}
        />
        <RangeControl
          label="Keep pages for (hours)"
          value={Math.round((cache.ttl || 36000) / 3600)}
          min={1}
          max={720}
          onChange={(hours) => set('ttl')(hours * 3600)}
          help="Longer is faster. With form-nonce refresh on, days are safe on a Divi site."
        />
        <ToggleControl
          label="Separate cache for mobile"
          help="Turn off if your Divi layout is fully responsive from one markup output — it halves your cache size."
          checked={!!cache.separate_mobile}
          onChange={set('separate_mobile')}
        />
        <ToggleControl
          label="Store gzip copies"
          help="Lets the web server send pre-compressed bytes without re-compressing on every request."
          checked={!!cache.gzip}
          onChange={set('gzip')}
        />
        <ToggleControl
          label="Send debug headers"
          help="Adds X-RC-Rocket-Cache so you can confirm a hit in DevTools. Safe to leave on."
          checked={!!cache.debug_headers}
          onChange={set('debug_headers')}
        />
      </PanelBody>

      <PanelBody title="Divi" initialOpen>
        <ToggleControl
          label="Clear Divi's asset cache too"
          help="Divi keeps its own generated CSS in et-cache. Leaving it behind is why a cleared cache can still show the old design."
          checked={!!cache.clear_divi_cache}
          onChange={set('clear_divi_cache')}
        />
        <ToggleControl
          label="Fix Divi's viewport tag"
          help="Divi outputs user-scalable=no, which fails the Lighthouse accessibility check and blocks pinch-zoom on phones. There is no Divi setting for it."
          checked={!!cache.fix_viewport}
          onChange={set('fix_viewport')}
        />
        <ToggleControl
          label="Refresh Divi form nonces"
          help="Divi contact and optin forms embed a nonce that expires in 12 hours. This fetches a fresh one on load so you can cache for days without silent submission failures."
          checked={!!cache.refresh_form_nonces}
          onChange={set('refresh_form_nonces')}
        />
        <p className="rcr-note">
          The Visual Builder, Theme Builder previews, Divi Leads split tests and
          the Blog module's AJAX pagination are never cached. Those rules are not
          optional and cannot be switched off.
        </p>
        <p className="rcr-note">
          <strong>Troubleshooting:</strong> if a section background or hero video
          stops rendering, switch off <em>Clear Divi's asset cache too</em>,
          clear the cache once, and reload. That isolates Divi's generated CSS
          from anything RC Rocket does to it.
        </p>
      </PanelBody>

      <PanelBody title="Delivery" initialOpen={false}>
        <ToggleControl
          label="Write server-readable copies"
          help="Also writes each page where nginx or Apache can find it, so a cache hit costs no PHP at all. Pair with the rules on the Server rules tab."
          checked={!!cache.server_delivery}
          onChange={set('server_delivery')}
        />
      </PanelBody>

      <PanelBody title="Exclusions" initialOpen={false}>
        <TextareaControl
          label="Never cache these paths"
          help="One per line. Use * as a wildcard, or wrap in # for a regex: #^/go/.+#"
          value={listToText(cache.excluded_uris)}
          onChange={(text) => set('excluded_uris')(textToList(text))}
        />
        <TextareaControl
          label="Query parameters that are safe to cache"
          help="Anything not listed here makes a request uncacheable. Tracking parameters are stripped automatically."
          value={listToText(cache.query_whitelist)}
          onChange={(text) => set('query_whitelist')(textToList(text))}
        />
        <TextControl
          label="Mobile user-agent pattern"
          value={cache.mobile_agents || ''}
          onChange={set('mobile_agents')}
        />
      </PanelBody>

      <PanelBody title="Warming" initialOpen={false}>
        <RangeControl
          label="URLs per batch"
          value={cache.preload_batch_size || 8}
          min={1}
          max={40}
          onChange={set('preload_batch_size')}
          help="Keep this low on shared hosting. Warming should never be the reason your site goes down."
        />
        <RangeControl
          label="Maximum URLs to warm"
          value={cache.preload_max_urls || 500}
          min={10}
          max={5000}
          step={10}
          onChange={set('preload_max_urls')}
        />
        <ToggleControl
          label="Re-warm after a purge"
          checked={!!cache.preload_on_purge}
          onChange={set('preload_on_purge')}
        />
      </PanelBody>
    </>
  );
}
