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

  // ------------------------------------------------------------- Easy view
  //
  // One page for people who manage a site but do not build it: is the site
  // healthy, how hard should RC Rocket work, clear the cache, and what to do
  // when something looks wrong. Every technical option is still one click
  // away under Advanced.

  var LEVELS = [
    { name: 'safe', title: 'Careful', text: 'Speeds up images, fonts and caching, and never changes how a page behaves. Choose this if the site uses many plugins or something stopped working on a faster level.' },
    { name: 'recommended', title: 'Recommended', text: 'The right choice for almost every Divi site. Also waits to load scripts until a visitor scrolls or taps. If visitors start seeing errors, RC Rocket switches itself back automatically and emails you.' },
    { name: 'maximum', title: 'Fastest', text: 'Everything above and a few extra tricks. After switching, look through your main pages on a phone: popups, sticky headers and overlapping sections are the things to check.' }
  ];

  var STATUS_WORDS = { fail: 'Needs fixing', warn: 'Worth a look', pass: 'OK', info: 'Info' };

  // What each check means for someone who does not build websites. The
  // check's own text stays available under "Technical details".
  var PLAIN = {
    page_cache_hit: ['Pages are not being cached', 'Your host normally keeps a ready-made copy of each page so it opens instantly. Right now every visitor waits for the page to be built from scratch, which is slow.', 'Ask whoever looks after the site to check the technical details below. Often a tracking or cookie-banner plugin causes it.'],
    kinsta_purge: ['RC Rocket cannot clear Kinsta\'s cache', 'After you change the design, visitors may keep seeing the old version for a while.', 'After design changes, clear the cache in MyKinsta as well, and ask Kinsta support to check their "MU plugin".'],
    divi: ['Divi was not found', 'RC Rocket works best with the Divi theme. Without it, the Divi-specific speed features stay off.', 'Nothing to do if this site does not use Divi.'],
    updates: ['Plugin updates', 'A newer version of RC Rocket may be available.', 'Update RC Rocket from Dashboard → Updates.'],
    uploads: ['RC Rocket cannot save files', 'The uploads folder cannot be written to, so fonts and some speed features cannot work.', 'Ask your host to fix the permissions of the wp-content/uploads folder.'],
    safe_mode: ['Safe mode is on', 'RC Rocket is not speeding up the site for visitors right now.', 'Once the problem that led to it is sorted, turn RC Rocket back on below.'],
    modules: ['All speed features are off', 'Nothing is being optimized.', 'Choose a level below, such as Recommended.'],
    fetch: ['RC Rocket cannot open your home page', 'To run these checks, RC Rocket visits your own home page, and that visit failed. Your site itself may be fine.', 'Ask your host whether "loopback requests" are blocked.'],
    pipeline: ['Visitors are not getting the faster version', 'The home page came back without RC Rocket\'s changes.', 'Clear the whole cache below, then press "Check again".'],
    beacon: ['Error reporting is not running', 'RC Rocket cannot notice if a page breaks for visitors, so the automatic safety switch cannot help.', 'Clear the whole cache below, then press "Check again".'],
    animations: ['Many animations on the home page', 'Each animated element starts hidden until it plays, which makes the page feel slower, especially on phones.', 'Consider the Fastest level, or remove animations you do not need in Divi.'],
    defer: ['Scripts are not being deferred', 'Scripts load in a way that holds up the page.', 'Choose the Recommended level below.'],
    delay: ['Scripts are not being delayed', 'Scripts such as chat widgets and trackers load before the page is shown.', 'Choose the Recommended level below.'],
    jquery: ['A common script is loading in the wrong order', 'jQuery, a script most plugins need, is not ready when the page expects it. This can break buttons, sliders or forms.', 'Pass the technical details below to whoever looks after the site.'],
    lazy: ['Images load all at once', 'Images further down the page are downloaded before anyone scrolls to them.', 'Choose the Recommended level below.'],
    lcp: ['The main image is not marked as most important', 'Browsers cannot tell which picture to load first, so the top of the page appears later.', 'Usually nothing to do on Divi: the main picture is often a section background, which RC Rocket handles separately.'],
    video_poster: ['A background video has no still image', 'Until the video starts there is an empty space, and phones on slow connections may show nothing at all.', 'In Divi, give the same section a Background Image. RC Rocket uses it automatically.'],
    video: ['A background video loads straight away', 'The video downloads before the rest of the page, which is heavy on phones.', 'In Divi, give the same section a Background Image. RC Rocket can then show it first.'],
    video_source: ['A video is stored on another website', 'If that website changes or removes the file, the video disappears from your page.', 'Upload the video to your own Media Library and use that copy.'],
    inventory: ['RC Rocket is still learning your pages', 'It records which files each page uses. This fills in by itself as people visit.', 'Nothing to do. It completes within a day of normal traffic.'],
    fonts: ['Fonts still come from Google', 'Copying them to your own site failed, so every visit asks Google first.', 'Press "Check again" tomorrow. If it persists, pass the details on.'],
    errors: ['Visitors saw errors in the last day', 'Something on the site is not working for some visitors.', 'Pass the technical details below to whoever looks after the site. The full list is under Advanced → Safety.'],
    divi_perf: ['Some of Divi\'s speed options are off', 'Divi has its own speed settings, and they work well alongside RC Rocket.', 'In Divi → Theme Options → Performance, switch on the options listed below.'],
  };

  function Health(props) {
    var resultState = useState(null);
    var result = resultState[0], setResult = resultState[1];
    var errorState = useState(null);
    var error = errorState[0], setError = errorState[1];
    var busyState = useState(false);
    var busy = busyState[0], setBusy = busyState[1];

    var run = function () {
      setBusy(true);
      setError(null);
      props.api('/self-test')
        .then(function (r) { setResult(r); })
        .catch(function (e) { setError(e.message); })
        .then(function () { setBusy(false); });
    };

    useEffect(run, []);

    var open = result ? result.checks.filter(function (x) { return x.status === 'fail' || x.status === 'warn'; }) : [];
    open.sort(function (x, y) { return (x.status === 'fail' ? 0 : 1) - (y.status === 'fail' ? 0 : 1); });

    var headline = !result ? 'Checking your site…'
      : result.summary.fail ? 'Something needs fixing'
      : result.summary.warn ? 'Your site is fast, with ' + result.summary.warn + (result.summary.warn === 1 ? ' thing' : ' things') + ' worth a look'
      : 'Everything is working';

    return el('section', { className: 'rcr-easy__card rcr-easy__health is-' + (!result ? 'pending' : result.summary.fail ? 'fail' : result.summary.warn ? 'warn' : 'pass') },
      el('div', { className: 'rcr-easy__head' },
        el('h2', null, headline),
        el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: run }, busy ? 'Checking…' : 'Check again')
      ),
      error && el(c.Notice, { status: 'error', isDismissible: false }, 'The check could not run: ' + error),
      result && el('p', { className: 'rcr-note' }, result.summary.pass + ' checks passed. ' + (open.length ? 'Here is what to look at, most important first:' : 'Nothing to do here.')),
      open.length > 0 && el('ul', { className: 'rcr-easy__issues' },
        open.slice(0, 6).map(function (x) {
          var plain = PLAIN[x.id];

          return el('li', { key: x.id + x.label, className: 'is-' + x.status },
            el('span', { className: 'rcr-easy__tag' }, STATUS_WORDS[x.status]),
            el('strong', null, plain ? plain[0] : x.label),
            el('p', null, plain ? plain[1] : x.detail),
            (plain ? plain[2] : x.fix) && el('p', { className: 'rcr-easy__fix' }, el('b', null, 'What to do: '), plain ? plain[2] : x.fix),
            plain && el('details', { className: 'rcr-easy__details' },
              el('summary', null, 'Technical details'),
              el('p', null, x.label + ': ' + x.detail),
              x.fix && el('p', null, x.fix)
            )
          );
        })
      ),
      open.length > 6 && el('p', { className: 'rcr-note' }, (open.length - 6) + ' more under System check.')
    );
  }

  function EasyHome(props) {
    var current = (props.settings.general || {}).preset || 'custom';
    var safeMode = !!(props.settings.general || {}).safe_mode;
    var busyState = useState('');
    var busy = busyState[0], setBusy = busyState[1];
    var messageState = useState(null);
    var message = messageState[0], setMessage = messageState[1];
    var home = (window.RCRocketBoot || {}).siteUrl || '/';
    var compare = home + (home.indexOf('?') === -1 ? '?' : '&') + 'rcr_safe=1';

    var done = function (text) { setMessage({ status: 'success', text: text }); };
    var failed = function (e) { setMessage({ status: 'error', text: e.message }); };

    var level = function (name) {
      setBusy('level-' + name);
      props.api('/preset', { method: 'POST', data: { name: name } })
        .then(function (r) { props.onApplied(r.settings); done('Done. RC Rocket now uses the ' + LEVELS.filter(function (l) { return l.name === name; })[0].title + ' level. Visitors see the change on their next page.'); })
        .catch(failed)
        .then(function () { setBusy(''); });
    };

    var clear = function () {
      setBusy('clear');
      Promise.resolve(props.onPurge('all'))
        .then(function () { done('The cache is cleared. The next visit to each page builds a fresh copy.'); })
        .catch(failed)
        .then(function () { setBusy(''); });
    };

    var safe = function (on) {
      setBusy('safe');
      props.api('/settings', { method: 'POST', data: { general: { safe_mode: on } } })
        .then(function (r) {
          props.onApplied(r.settings);
          done(on ? 'Safe mode is on: visitors get your site without any RC Rocket speed features. Turn it off again once the problem is sorted.' : 'Safe mode is off: RC Rocket is speeding up your site again.');
        })
        .catch(failed)
        .then(function () { setBusy(''); });
    };

    return el('div', { className: 'rcr-easy' },
      message && el(c.Notice, { status: message.status, onRemove: function () { setMessage(null); } }, message.text),
      safeMode && el(c.Notice, { status: 'warning', isDismissible: false }, 'Safe mode is on, so RC Rocket is not speeding up your site right now.'),

      el(Health, { api: props.api }),

      el('section', { className: 'rcr-easy__card' },
        el('h2', null, 'How hard should RC Rocket work?'),
        current === 'custom' && el('p', { className: 'rcr-note' }, 'Someone has fine-tuned the settings by hand, so none of these is selected. Choosing one replaces those changes (your exclusions are kept).'),
        props.dirty && el(c.Notice, { status: 'warning', isDismissible: false }, 'You have unsaved changes in Advanced. Save or discard them first.'),
        el('div', { className: 'rcr-easy__levels' },
          LEVELS.map(function (l) {
            var active = l.name === current;
            return el('div', { key: l.name, className: 'rcr-easy__level' + (active ? ' is-active' : '') },
              el('strong', null, l.title, active ? ' (in use)' : ''),
              el('p', null, l.text),
              !active && el(c.Button, { variant: l.name === 'recommended' ? 'primary' : 'secondary', isBusy: busy === 'level-' + l.name, disabled: !!busy || props.dirty, onClick: function () { level(l.name); } }, 'Use ' + l.title)
            );
          })
        )
      ),

      el('section', { className: 'rcr-easy__card' },
        el('h2', null, 'Clear the cache'),
        el('p', null, 'RC Rocket keeps ready-made copies of your pages so they open instantly. It refreshes a page by itself when you save it. Clear everything after changing something that appears on every page, such as the menu, header, footer or Divi Theme Options.'),
        el(c.Button, { variant: 'primary', isBusy: busy === 'clear', disabled: !!busy, onClick: clear }, 'Clear the whole cache')
      ),

      el('section', { className: 'rcr-easy__card' },
        el('h2', null, 'Something looks wrong on the site?'),
        el('ol', { className: 'rcr-easy__steps' },
          el('li', null,
            el('strong', null, 'Find out whether RC Rocket is the cause. '),
            'Open your home page with RC Rocket switched off, just for you: ',
            el('a', { href: compare, target: '_blank', rel: 'noopener noreferrer' }, 'open without RC Rocket'),
            '. To check another page, add ', el('code', null, '?rcr_safe=1'), ' to the end of its address. If it looks right that way, RC Rocket is involved.'
          ),
          el('li', null,
            el('strong', null, 'Switch the speed features off for everyone while it gets fixed. '),
            'Visitors then get the site exactly as it is without RC Rocket. Nothing is deleted, and you can switch back at any time.',
            el('div', { className: 'rcr-easy__action' },
              el(c.Button, { variant: safeMode ? 'primary' : 'secondary', isDestructive: !safeMode, isBusy: busy === 'safe', disabled: !!busy, onClick: function () { safe(!safeMode); } }, safeMode ? 'Turn RC Rocket back on' : 'Turn on Safe mode')
            )
          ),
          el('li', null,
            el('strong', null, 'Tell whoever looks after the site '),
            'which page it was and what looked wrong. They will find the details under Advanced, in Safety.'
          )
        )
      ),

      el('p', { className: 'rcr-easy__more' },
        'Every individual setting is under ',
        el(c.Button, { variant: 'link', onClick: props.openAdvanced }, 'Advanced view'),
        '.'
      )
    );
  }

  window.RCRocketPanels = { Preload: Preload, Housekeeping: Housekeeping, PresetBar: PresetBar, HeroDetection: HeroDetection, EasyHome: EasyHome };
})();
