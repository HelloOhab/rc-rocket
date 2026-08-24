const { useEffect, useState } = wp.element;
const { Button, PanelBody, SelectControl, Spinner, TextControl, ToggleControl, Notice } = wp.components;
import { api } from '../lib/api';

const SCOPES = [
  { label: 'Everywhere', value: 'everywhere' },
  { label: 'Only on', value: 'targets' },
  { label: 'Everywhere except', value: 'except' },
];

const BLOAT = [
  ['emojis', 'Emoji script', 'A script and stylesheet on every page so that older browsers can render emoji.'],
  ['dashicons', 'Dashicons on the front end', 'Admin icon font. Kept automatically when the admin bar is showing.'],
  ['embeds', 'oEmbed discovery', 'Turn off only if you never embed this site elsewhere.'],
  ['block_library', 'Gutenberg block styles', 'A Divi-built page renders no blocks. Leave off if you use blocks anywhere.'],
  ['comment_reply', 'Threaded comment script', 'Loaded even on pages with comments closed.'],
  ['heartbeat_front', 'Heartbeat on the front end', 'Admin-ajax polling that visitors never benefit from.'],
  ['jquery_migrate', 'jQuery Migrate', 'Divi 4 and older third-party modules may need this. Test carefully.'],
  ['xmlrpc', 'XML-RPC', 'Legacy remote publishing endpoint.'],
  ['shortlink', 'Shortlink tag', ''],
  ['generator', 'WordPress version tag', ''],
  ['rsd_link', 'RSD link', ''],
  ['wlwmanifest', 'Windows Live Writer manifest', ''],
  ['rest_links', 'REST API discovery links', ''],
];

export default function AssetsPanel({ settings, update, status }) {
  const [data, setData] = useState(null);
  const [filter, setFilter] = useState('');
  const assets = settings.assets || {};
  const rules = assets.rules || [];
  const bloat = assets.bloat || {};
  const fonts = assets.fonts || {};
  const diviCfg = assets.divi || {};
  const diviAssets = status && status.divi_assets;

  useEffect(() => {
    api.getAssets().then(setData);
  }, []);

  const setRules = (next) => update('assets', 'rules', next);

  const ruleFor = (handle, kind) =>
    rules.find((r) => r.handle === handle && r.kind === kind);

  const upsert = (handle, kind, patch) => {
    const existing = ruleFor(handle, kind);
    if (!existing) {
      setRules([...rules, { handle, kind, scope: 'everywhere', targets: [], ...patch }]);
      return;
    }
    setRules(rules.map((r) => (r === existing ? { ...r, ...patch } : r)));
  };

  const remove = (handle, kind) =>
    setRules(rules.filter((r) => !(r.handle === handle && r.kind === kind)));

  const applySuggestion = (s) => upsert(s.handle, s.kind, { scope: s.scope, targets: s.targets });

  if (!data) {
    return (
      <div className="rcr-loading">
        <Spinner />
        <span>Reading the asset inventory…</span>
      </div>
    );
  }

  const visible = data.handles.filter(
    (h) =>
      !filter ||
      h.handle.toLowerCase().includes(filter.toLowerCase()) ||
      h.owner.toLowerCase().includes(filter.toLowerCase())
  );

  const grouped = visible.reduce((acc, h) => {
    (acc[h.owner] = acc[h.owner] || []).push(h);
    return acc;
  }, {});

  return (
    <>
      <PanelBody title="Asset manager" initialOpen>
        <ToggleControl
          label="Record what loads on each template"
          help="Builds the list below from real traffic. Keep it on — it costs one option write per template per day, and it is how the list stays accurate after a plugin update."
          checked={!!assets.scan}
          onChange={(v) => update('assets', 'scan', v)}
        />

        {data.handles.length === 0 ? (
          <Notice status="info" isDismissible={false}>
            Nothing recorded yet. Visit a few pages of your site logged out, then
            come back — the inventory fills in as pages are viewed.
          </Notice>
        ) : (
          <>
            <TextControl
              label="Filter"
              placeholder="Search by handle or plugin"
              value={filter}
              onChange={setFilter}
            />

            {Object.entries(grouped).map(([owner, items]) => (
              <div key={owner} className="rcr-group">
                <h3 className="rcr-group__title">{owner}</h3>
                {items.map((h) => {
                  const rule = ruleFor(h.handle, h.kind);
                  return (
                    <div key={h.kind + h.handle} className="rcr-asset">
                      <div className="rcr-asset__id">
                        <code>{h.handle}</code>
                        <span className="rcr-asset__kind">{h.kind}</span>
                        {h.protected && <span className="rcr-asset__locked">protected</span>}
                        <span className="rcr-asset__where">
                          {h.templates.length} template{h.templates.length === 1 ? '' : 's'}
                        </span>
                      </div>
                      <div className="rcr-asset__controls">
                        {!rule ? (
                          <Button
                            variant="secondary"
                            disabled={h.protected}
                            onClick={() => upsert(h.handle, h.kind, {})}
                          >
                            {h.protected ? 'Locked' : 'Disable'}
                          </Button>
                        ) : (
                          <>
                            <SelectControl
                              value={rule.scope}
                              options={SCOPES}
                              onChange={(scope) => upsert(h.handle, h.kind, { scope })}
                            />
                            {rule.scope !== 'everywhere' && (
                              <TextControl
                                placeholder="front_page, singular:page, regex:#^/blog/#"
                                value={(rule.targets || []).join(', ')}
                                onChange={(v) =>
                                  upsert(h.handle, h.kind, {
                                    targets: v.split(',').map((t) => t.trim()).filter(Boolean),
                                  })
                                }
                              />
                            )}
                            <Button variant="tertiary" isDestructive onClick={() => remove(h.handle, h.kind)}>
                              Keep
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            ))}
          </>
        )}
      </PanelBody>

      <PanelBody title="Suggested removals" initialOpen={false}>
        <p className="rcr-note">
          Each of these loads on pages that cannot use it. Applied only when you
          click, never automatically.
        </p>
        {data.suggestions.map((s) => (
          <div key={s.kind + s.handle} className="rcr-suggestion">
            <div>
              <code>{s.handle}</code>
              <p className="rcr-note">{s.reason}</p>
            </div>
            <Button
              variant="secondary"
              disabled={!!ruleFor(s.handle, s.kind)}
              onClick={() => applySuggestion(s)}
            >
              {ruleFor(s.handle, s.kind) ? 'Applied' : 'Apply'}
            </Button>
          </div>
        ))}
      </PanelBody>

      <PanelBody title="Google Fonts" initialOpen={false}>
        <ToggleControl
          label="Serve Google Fonts from this site"
          help="Downloads the font files to your uploads folder and rewrites the stylesheet. Removes a DNS lookup, a TLS handshake and a render-blocking round trip before the first glyph exists — and puts the cache lifetime under your control."
          checked={!!fonts.localize}
          onChange={(v) => update('assets', 'fonts', { ...fonts, localize: v })}
        />
        {fonts.localize && (
          <ToggleControl
            label="Preload the font files"
            help="Requests the fonts alongside the stylesheet instead of after it."
            checked={!!fonts.preload}
            onChange={(v) => update('assets', 'fonts', { ...fonts, preload: v })}
          />
        )}
        <p className="rcr-note">
          Files are refreshed weekly on cron. Nothing is re-encoded, so this is
          safe on hosts that forbid server-side media processing.
        </p>
      </PanelBody>

      <PanelBody title="Divi builder libraries" initialOpen={false}>
        {diviAssets && !diviAssets.applicable ? (
          <Notice status="info" isDismissible={false}>
            {diviAssets.reason}
          </Notice>
        ) : (
          <ToggleControl
            label="Unload unused Divi libraries"
            help="Divi 4 loads the lightbox, masonry grid and circle-counter libraries on every page regardless of what the page contains. This detects the modules actually rendered — including those inherited from Theme Builder templates — and unloads the rest."
            checked={!!diviCfg.unload_modules}
            onChange={(v) => update('assets', 'divi', { ...diviCfg, unload_modules: v })}
          />
        )}
      </PanelBody>

      <PanelBody title="WordPress bloat" initialOpen={false}>
        {BLOAT.map(([key, label, help]) => (
          <ToggleControl
            key={key}
            label={label}
            help={help}
            checked={!!bloat[key]}
            onChange={(v) => update('assets', 'bloat', { ...bloat, [key]: v })}
          />
        ))}
      </PanelBody>
    </>
  );
}
