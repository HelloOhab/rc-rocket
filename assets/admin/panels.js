/**
 * RC Rocket admin: the Preload and Housekeeping tabs.
 *
 * Plain JavaScript over WordPress's own React and components, like app.js,
 * so there is no build step. app.js renders these through
 * window.RCRocketPanels; this file must load first.
 */
(function () {
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var c = wp.components;

  var lines = function (value) {
    return Array.isArray(value) ? value.join('\n') : '';
  };
  var list = function (text) {
    return text.split('\n').map(function (line) { return line.trim(); }).filter(Boolean);
  };

  // ------------------------------------------------------------- Preload

  function Preload(props) {
    var p = props.settings.preload || {};
    var set = function (key) {
      return function (value) { props.update('preload', key, value); };
    };

    return el(
      Fragment,
      null,
      el(
        c.PanelBody,
        { title: 'Link preloading', initialOpen: true },
        el(c.ToggleControl, {
          label: 'Preload pages before the click',
          help: 'When a visitor hovers or presses on a link, the browser starts fetching that page, so it opens almost instantly. Logged-in users, the cart, checkout, the Divi builder and action links are never preloaded.',
          checked: !!p.links,
          onChange: set('links'),
        }),
        p.links && el(c.SelectControl, {
          label: 'How',
          value: p.links_mode || 'prefetch',
          options: [
            { label: 'Prefetch: download the HTML (recommended)', value: 'prefetch' },
            { label: 'Prerender: download and render the whole page', value: 'prerender' },
          ],
          help: 'Prerender is faster still, but runs the next page\'s scripts in the background. Analytics and chat widgets usually cope; test before using it on a site with a booking or payment flow.',
          onChange: set('links_mode'),
        }),
        p.links && el(c.SelectControl, {
          label: 'When',
          value: p.links_eagerness || 'moderate',
          options: [
            { label: 'On hover (recommended)', value: 'moderate' },
            { label: 'Only on press', value: 'conservative' },
            { label: 'As soon as a link is visible', value: 'eager' },
          ],
          onChange: set('links_eagerness'),
        }),
        p.links && el(c.TextareaControl, {
          label: 'Never preload these',
          help: 'One path per line, * as a wildcard, for example /book-now/* or /go/*.',
          value: lines(p.links_exclusions),
          onChange: function (value) { set('links_exclusions')(list(value)); },
        }),
        el('p', { className: 'rcr-note' },
          'On Kinsta a preloaded page is answered from the edge cache, so preloading costs your server nothing.'
        )
      ),
      el(
        c.PanelBody,
        { title: 'Fonts', initialOpen: true },
        el(c.TextareaControl, {
          label: 'Preload these font files',
          help: 'One URL per line, at most six: the fonts your header and hero headline use. Find them in the browser\'s Network tab, filtered to Font. Preloading fonts the page does not use slows it down.',
          value: lines(p.fonts),
          onChange: function (value) { set('fonts')(list(value)); },
        })
      ),
      el(
        c.PanelBody,
        { title: 'DNS prefetch', initialOpen: false },
        el(c.TextareaControl, {
          label: 'Look these domains up early',
          help: 'One per line. Third parties the page reaches later: analytics, chat, maps, booking widgets. For origins needed during the first paint, use Preconnect on the JavaScript tab instead.',
          value: lines(p.dns_prefetch),
          onChange: function (value) { set('dns_prefetch')(list(value)); },
        })
      )
    );
  }

  // -------------------------------------------------------- Housekeeping

  var ITEMS = [
    { key: 'revisions', label: 'Old revisions', help: 'Divi saves a full copy of the layout on every builder save.' },
    { key: 'auto_drafts', label: 'Auto-drafts older than a week' },
    { key: 'trashed_posts', label: 'Posts in the trash', help: 'Permanently deleted. Off by default: the trash is someone\'s undo button.' },
    { key: 'spam_comments', label: 'Spam comments' },
    { key: 'trashed_comments', label: 'Comments in the trash' },
    { key: 'expired_transients', label: 'Expired transients' },
    { key: 'optimize_tables', label: 'Optimize fragmented tables', help: 'Rebuilds tables with more than 1 MB of reclaimable space. Briefly locks each table; run it overnight on a busy site.' },
  ];

  function Housekeeping(props) {
    var d = props.settings.database || {};
    var hb = (props.settings.assets || {}).heartbeat || {};
    var setDb = function (key) {
      return function (value) { props.update('database', key, value); };
    };
    var setHb = function (key) {
      return function (value) {
        var next = Object.assign({}, hb);
        next[key] = value;
        props.update('assets', 'heartbeat', next);
      };
    };

    var state = useState(null);
    var counts = state[0], setCounts = state[1];
    var busyState = useState(false);
    var busy = busyState[0], setBusy = busyState[1];
    var noticeState = useState(null);
    var notice = noticeState[0], setNotice = noticeState[1];

    var load = function () {
      props.api('/database').then(setCounts).catch(function (e) { setNotice({ status: 'error', text: e.message }); });
    };

    useEffect(load, []);

    var clean = function (items) {
      setBusy(true);
      props.api('/database', { method: 'POST', data: { items: items } })
        .then(function (result) {
          var total = Object.keys(result.removed).reduce(function (sum, k) { return sum + result.removed[k]; }, 0);
          setNotice({ status: 'success', text: total ? 'Removed ' + total + ' rows.' : 'Nothing to remove.' });
          setCounts(Object.assign({}, counts, { counts: result.counts }));
        })
        .catch(function (e) { setNotice({ status: 'error', text: e.message }); })
        .then(function () { setBusy(false); });
    };

    var ticked = ITEMS.filter(function (item) { return !!d[item.key]; }).map(function (item) { return item.key; });

    var heartbeatOptions = [
      { label: 'Leave as is', value: 'default' },
      { label: 'Reduce', value: 'reduce' },
      { label: 'Disable', value: 'disable' },
    ];

    return el(
      Fragment,
      null,
      notice && el(c.Notice, { status: notice.status, onRemove: function () { setNotice(null); } }, notice.text),
      el(
        c.PanelBody,
        { title: 'Database cleanup', initialOpen: true },
        el('p', { className: 'rcr-note' },
          'Ticked items are cleaned on the schedule below, or now with the button. Large backlogs clear 500 rows per item per run. Take a backup first if you have never run a cleanup on this site.'
        ),
        ITEMS.map(function (item) {
          var count = counts && counts.counts ? counts.counts[item.key] : null;
          return el(c.ToggleControl, {
            key: item.key,
            label: item.label + (count === null ? '' : ' (' + count + ')'),
            help: item.help,
            checked: !!d[item.key],
            onChange: setDb(item.key),
          });
        }),
        d.revisions && el(c.RangeControl, {
          label: 'Revisions to keep per post',
          value: typeof d.revisions_keep === 'number' ? d.revisions_keep : 5,
          min: 1,
          max: 50,
          onChange: setDb('revisions_keep'),
        }),
        el(c.SelectControl, {
          label: 'Clean automatically',
          value: d.schedule || 'off',
          options: [
            { label: 'Never', value: 'off' },
            { label: 'Daily', value: 'daily' },
            { label: 'Weekly', value: 'weekly' },
            { label: 'Monthly', value: 'monthly' },
          ],
          onChange: setDb('schedule'),
        }),
        el(
          'div',
          { className: 'rcr-actions' },
          el(c.Button, {
            variant: 'secondary',
            isBusy: busy,
            disabled: busy || !ticked.length,
            onClick: function () { clean(ticked); },
          }, 'Clean ticked items now'),
          busy && el(c.Spinner, null)
        ),
        counts && counts.last_run && counts.last_run.time && el('p', { className: 'rcr-note' },
          'Last run ' + new Date(counts.last_run.time * 1000).toLocaleString() + '.',
          counts.next_run ? ' Next run ' + new Date(counts.next_run * 1000).toLocaleString() + '.' : ''
        ),
        el('p', { className: 'rcr-note' }, 'Settings changes, including the schedule, take effect when you save.')
      ),
      el(
        c.PanelBody,
        { title: 'Heartbeat', initialOpen: true },
        el('p', { className: 'rcr-note' },
          'WordPress polls the server every 15 to 60 seconds from every open tab. On Kinsta each poll is an uncached PHP request that counts against your plan.'
        ),
        el(c.SelectControl, {
          label: 'On the front end',
          value: hb.frontend || 'default',
          options: heartbeatOptions,
          onChange: setHb('frontend'),
        }),
        el(c.SelectControl, {
          label: 'In the dashboard',
          value: hb.backend || 'default',
          options: heartbeatOptions,
          help: 'Disabling it here also removes the "your session expired" login prompt.',
          onChange: setHb('backend'),
        }),
        el(c.SelectControl, {
          label: 'In the post and Divi editors',
          value: hb.editor || 'default',
          options: heartbeatOptions.slice(0, 2),
          help: 'Can be slowed but not stopped: post locking and autosave recovery depend on it.',
          onChange: setHb('editor'),
        }),
        el(c.RangeControl, {
          label: 'Reduced interval (seconds)',
          value: hb.interval || 120,
          min: 15,
          max: 120,
          onChange: setHb('interval'),
        })
      )
    );
  }

  // ---------------------------------------------------------- Presets

  /**
   * One click to a known-good level, on the Overview tab. Applying is a
   * normal save: it shows up in Safety > Change history with a rollback.
   */
  function PresetBar(props) {
    var listState = useState(null);
    var catalogue = listState[0], setCatalogue = listState[1];
    var busyState = useState('');
    var busy = busyState[0], setBusy = busyState[1];
    var errorState = useState(null);
    var error = errorState[0], setError = errorState[1];

    useEffect(function () {
      props.api('/preset').then(function (r) { setCatalogue(r.presets); }).catch(function (e) { setError(e.message); });
    }, []);

    if (!catalogue) {
      return null;
    }

    var current = (props.settings.general || {}).preset || 'custom';

    var apply = function (name) {
      setBusy(name);
      setError(null);
      props.api('/preset', { method: 'POST', data: { name: name } })
        .then(function (r) { props.onApplied(r.settings); })
        .catch(function (e) { setError(e.message); })
        .then(function () { setBusy(''); });
    };

    return el(
      'section',
      { className: 'rcr-presets' },
      el('h2', { className: 'rcr-presets__title' }, 'Optimization level'),
      el('p', { className: 'rcr-note' },
        current === 'custom'
          ? 'Custom: settings have been tuned by hand. Pick a level to reset every speed option to it; exclusions and rules you added are kept.'
          : 'Every speed option follows the level below. Change any setting and this becomes Custom.'
      ),
      error && el(c.Notice, { status: 'error', isDismissible: false }, error),
      props.dirty && el(c.Notice, { status: 'warning', isDismissible: false }, 'Save or discard your unsaved changes before switching level.'),
      el(
        'div',
        { className: 'rcr-presets__grid' },
        Object.keys(catalogue).map(function (name) {
          var active = name === current;
          return el(
            'div',
            { key: name, className: 'rcr-preset' + (active ? ' is-active' : '') },
            el('strong', { className: 'rcr-preset__name' }, catalogue[name].label, active ? ' — active' : ''),
            el('p', { className: 'rcr-preset__text' }, catalogue[name].description),
            el(c.Button, {
              variant: active ? 'secondary' : 'primary',
              isBusy: busy === name,
              disabled: !!busy || props.dirty,
              onClick: function () { apply(name); },
            }, active ? 'Re-apply' : 'Use ' + catalogue[name].label)
          );
        })
      )
    );
  }

  // ------------------------------------------------- Hero detection (Media)

  var DEVICE_TYPES = { img: 'Image', bg: 'Background image', video: 'Video poster', text: 'Text' };

  function describe(m) {
    if (!m) return '—';
    var name = DEVICE_TYPES[m.type] || m.type;
    if (!m.url) return name;
    var file = m.url.split('?')[0].split('/').pop();
    return el('span', { title: m.url }, name + ': ' + file);
  }

  function HeroDetection(props) {
    var m = props.settings.media || {};
    var set = function (key) {
      return function (value) { props.update('media', key, value); };
    };
    var divi = props.status && props.status.divi && props.status.divi.active;

    var itemsState = useState(null);
    var items = itemsState[0], setItems = itemsState[1];
    var busyState = useState(false);
    var busy = busyState[0], setBusy = busyState[1];
    var noticeState = useState(null);
    var notice = noticeState[0], setNotice = noticeState[1];

    var load = function () {
      if (!props.api) return;
      props.api('/lcp/measurements').then(function (r) { setItems(r.items || []); }).catch(function () { setItems([]); });
    };

    useEffect(load, []);

    var reset = function () {
      setBusy(true);
      props.api('/lcp/measurements', { method: 'DELETE' })
        .then(function () { setItems([]); setNotice({ status: 'success', text: 'Measurements cleared and the cache emptied. Pages are measured again as visitors arrive.' }); })
        .catch(function (e) { setNotice({ status: 'error', text: e.message }); })
        .then(function () { setBusy(false); });
    };

    return el(
      c.PanelBody,
      { title: 'Hero image and backgrounds', initialOpen: true },
      notice && el(c.Notice, { status: notice.status, onRemove: function () { setNotice(null); } }, notice.text),
      el(c.ToggleControl, {
        label: 'Detect each page\'s hero automatically',
        help: 'Visitors\' browsers report which element is the Largest Contentful Paint, separately for phones and computers. That image, or Divi section background, is then requested first on every visit. Needs "Prioritise the first images" above.',
        checked: !!m.lcp_detect,
        onChange: set('lcp_detect'),
      }),
      el(c.ToggleControl, {
        label: 'Lazy load Divi background images',
        help: 'Section, row and module backgrounds load as the visitor scrolls near them, not all at once. The first three sections always load immediately.' + (divi ? '' : ' Applies only on Divi sites.'),
        checked: !!m.lazy_backgrounds,
        onChange: set('lazy_backgrounds'),
      }),
      m.lcp_detect && items && el(
        Fragment,
        null,
        items.length
          ? el(
            'table',
            { className: 'widefat striped rcr-lcp-table' },
            el('thead', null, el('tr', null, el('th', null, 'Page'), el('th', null, 'Phone'), el('th', null, 'Computer'))),
            el('tbody', null, items.slice(0, 30).map(function (row) {
              return el('tr', { key: row.key },
                el('td', null, row.url ? el('a', { href: row.url, target: '_blank', rel: 'noopener' }, row.label || row.url) : row.label),
                el('td', null, describe(row.mobile)),
                el('td', null, describe(row.desktop))
              );
            }))
          )
          : el('p', { className: 'rcr-note' }, 'Nothing measured yet. Pages are measured as visitors arrive; most sites have their main pages measured within a day.'),
        el('div', { className: 'rcr-actions' },
          el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: reset }, 'Measure every page again')
        )
      )
    );
  }

  window.RCRocketPanels = { Preload: Preload, Housekeeping: Housekeeping, PresetBar: PresetBar, HeroDetection: HeroDetection };
})();
