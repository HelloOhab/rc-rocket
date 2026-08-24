const { Button, Notice, PanelBody, RangeControl, SelectControl, TextControl, TextareaControl, ToggleControl } = wp.components;

const lines = (v) => (Array.isArray(v) ? v.join('\n') : '');
const toList = (t) => t.split('\n').map((l) => l.trim()).filter(Boolean);

export default function MediaPanel({ settings, update, status }) {
  const media = settings.media || {};
  const set = (key) => (value) => update('media', key, value);
  const preloads = media.hero_preloads || [];
  const video = media.video || {};
  const setVideo = (key) => (value) => update('media', 'video', { ...video, [key]: value });
  const setPoster = (i, patch) =>
    setVideo('posters')((video.posters || []).map((p, idx) => (idx === i ? { ...p, ...patch } : p)));
  const conversionBlocked = status && status.hosting && !status.hosting.image_conversion_allowed;

  const setPreload = (i, patch) =>
    set('hero_preloads')(preloads.map((p, idx) => (idx === i ? { ...p, ...patch } : p)));

  return (
    <>
      {conversionBlocked && (
        <Notice status="info" isDismissible={false}>
          {status.hosting.label} does not permit server-based image conversion,
          so RC Rocket does not attempt it. Compress and convert with a
          cloud-based optimizer; everything below still applies.
        </Notice>
      )}

      <PanelBody title="Loading" initialOpen>
        <ToggleControl
          label="Lazy load images"
          help="Images below the fold wait until the visitor scrolls near them."
          checked={!!media.lazy_load}
          onChange={set('lazy_load')}
        />
        <RangeControl
          label="Load this many images immediately"
          value={media.skip_first ?? 2}
          min={0}
          max={8}
          onChange={set('skip_first')}
          help="The first images in the document are your LCP candidates. Lazy loading them is the most common cause of a bad LCP score."
        />
        <ToggleControl
          label="Lazy load iframes"
          help="Maps, videos and embeds wait until they are needed."
          checked={!!media.lazy_iframes}
          onChange={set('lazy_iframes')}
        />
        <ToggleControl
          label="Prioritise the first images"
          help="Marks them fetchpriority=high so the browser requests them ahead of scripts and stylesheets."
          checked={!!media.lcp_priority}
          onChange={set('lcp_priority')}
        />
        <TextareaControl
          label="Never touch images matching"
          help="Substring match against the image tag. One per line."
          value={lines(media.exclusions)}
          onChange={(t) => set('exclusions')(toList(t))}
          rows={4}
        />
      </PanelBody>

      <PanelBody title="Layout stability" initialOpen>
        <ToggleControl
          label="Add missing width and height"
          help="Reserves space so the page does not jump as images arrive. This is the Cumulative Layout Shift fix."
          checked={!!media.add_dimensions}
          onChange={set('add_dimensions')}
        />
        <ToggleControl
          label="Decode images asynchronously"
          help="Keeps image decoding off the main thread for everything below the fold."
          checked={!!media.async_decoding}
          onChange={set('async_decoding')}
        />
      </PanelBody>

      <PanelBody title="Divi background video" initialOpen>
        <p className="rcr-note">
          Divi gives a background video no poster and no preload hint, so the
          browser pulls megabytes of video before it paints anything. On a
          throttled mobile connection that one decision can own your entire LCP.
        </p>

        <Notice status="warning" isDismissible={false}>
          A background video is only ever withheld when a poster exists to take
          its place. Without a poster, everything below is ignored and the video
          loads exactly as Divi intended — a black hole where the hero was is
          worse than a slow hero.
        </Notice>

        <p className="rcr-note">
          <strong>You usually do not need to add one.</strong> If the section
          has a Background Image set in Divi alongside the video, RC Rocket
          finds it and uses it as the poster automatically, per section. Add one
          below only to override that, or where Divi has no fallback image.
        </p>

        <ToggleControl
          label="Manage background videos"
          checked={!!video.enabled}
          onChange={setVideo('enabled')}
        />

        {video.enabled && (
          <>
            {(video.posters || []).map((p, i) => (
              <div key={i} className="rcr-preload">
                <TextControl
                  label="Poster image URL"
                  value={p.url || ''}
                  onChange={(url) => setPoster(i, { url })}
                  placeholder="https://example.com/wp-content/uploads/2026/01/hero-poster.jpg"
                  help="A still frame from the video. This becomes what visitors see first, and on mobile it may be all they ever see — choose it accordingly."
                />
                <TextControl
                  label="On which pages"
                  value={p.template || ''}
                  onChange={(template) => setPoster(i, { template })}
                  placeholder="front_page"
                />
                <Button
                  variant="tertiary"
                  isDestructive
                  onClick={() => setVideo('posters')((video.posters || []).filter((_, idx) => idx !== i))}
                >
                  Remove
                </Button>
              </div>
            ))}

            <Button
              variant="secondary"
              onClick={() => setVideo('posters')([...(video.posters || []), { url: '', template: '' }])}
            >
              Add a poster
            </Button>

            <RangeControl
              label="Skip the video below this screen width (px)"
              value={video.disable_below ?? 980}
              min={0}
              max={1400}
              step={20}
              onChange={setVideo('disable_below')}
              help="Set to 0 to load the video on every device. 980 matches Divi's own tablet breakpoint."
            />
            <ToggleControl
              label="Wait until the section is nearly visible"
              checked={!!video.lazy_until_visible}
              onChange={setVideo('lazy_until_visible')}
              help="Nothing downloads until the visitor scrolls within 200px of it."
            />
            <ToggleControl
              label="Skip on slow connections"
              checked={!!video.require_fast_connection}
              onChange={setVideo('require_fast_connection')}
              help="No video on 2G or 3G. These are the visitors your LCP score is measured against."
            />
            <ToggleControl
              label="Respect Save-Data"
              checked={!!video.respect_save_data}
              onChange={setVideo('respect_save_data')}
              help="Honours the browser setting where someone has asked sites to use less data."
            />
            <ToggleControl
              label="Respect reduced motion"
              checked={!!video.respect_reduced_motion}
              onChange={setVideo('respect_reduced_motion')}
              help="An autoplaying video is exactly what this browser setting exists to prevent."
            />
            <ToggleControl
              label="Preload the poster"
              checked={!!video.preload_poster}
              onChange={setVideo('preload_poster')}
              help="The poster is now your LCP element, so it gets hero treatment."
            />
            <ToggleControl
              label="Set preload=none on the video"
              checked={!!video.preload_none}
              onChange={setVideo('preload_none')}
              help="Stops the video competing with stylesheets for bandwidth during first paint."
            />
          </>
        )}
      </PanelBody>

      <PanelBody title="Vimeo and YouTube embeds" initialOpen>
        <p className="rcr-note">
          A Vimeo background costs you the video <em>and</em> the player —
          several hundred kilobytes of JavaScript across three extra origins,
          each needing its own DNS lookup and handshake before a frame exists.
          Posters are fetched from the provider automatically, so there is
          nothing to upload.
        </p>
        <ToggleControl
          label="Hold back decorative background embeds"
          checked={!!video.gate_background_embeds}
          onChange={setVideo('gate_background_embeds')}
          help="Uses the same viewport, connection and reduced-motion rules as self-hosted background video. The poster shows until all of them agree."
        />
        <ToggleControl
          label="Replace normal embeds with a play button"
          checked={!!video.facade_embeds}
          onChange={setVideo('facade_embeds')}
          help="The player loads on the first click. Nobody pays for a video they never start."
        />
        <ToggleControl
          label="Ask Vimeo not to track"
          checked={!!video.vimeo_dnt}
          onChange={setVideo('vimeo_dnt')}
          help="Adds dnt=1, which drops Vimeo's tracking cookies — one less request and one less consent problem."
        />
        <SelectControl
          label="Cap Vimeo quality"
          value={video.vimeo_quality || '540p'}
          options={[
            { label: 'Let Vimeo decide', value: 'auto' },
            { label: '360p', value: '360p' },
            { label: '540p — recommended for backgrounds', value: '540p' },
            { label: '720p', value: '720p' },
            { label: '1080p', value: '1080p' },
          ]}
          onChange={setVideo('vimeo_quality')}
          help="A blurred backdrop behind headline text has no use for 1080p."
        />
      </PanelBody>

      <PanelBody title="Divi hero backgrounds" initialOpen>
        <p className="rcr-note">
          Divi renders section backgrounds as CSS, not as an image tag, so the
          browser only discovers your hero after the stylesheet parses. Naming
          it here moves it to the front of the queue. This is usually worth a
          second or more of mobile LCP on a Divi site.
        </p>

        {preloads.map((p, i) => (
          <div key={i} className="rcr-preload">
            <TextControl
              label="Image URL"
              value={p.url || ''}
              onChange={(url) => setPreload(i, { url })}
              placeholder="https://example.com/wp-content/uploads/2026/01/hero.jpg"
            />
            <TextControl
              label="On which pages"
              value={p.template || ''}
              onChange={(template) => setPreload(i, { template })}
              placeholder="front_page"
              help="A token such as front_page, singular:page, post:412 — or leave blank for every page."
            />
            <TextControl
              label="Media query (optional)"
              value={p.media || ''}
              onChange={(m) => setPreload(i, { media: m })}
              placeholder="(max-width: 980px)"
              help="Use this when the mobile hero is a different file from the desktop one."
            />
            <Button
              variant="tertiary"
              isDestructive
              onClick={() => set('hero_preloads')(preloads.filter((_, idx) => idx !== i))}
            >
              Remove
            </Button>
          </div>
        ))}

        <Button
          variant="secondary"
          onClick={() => set('hero_preloads')([...preloads, { url: '', template: '', media: '' }])}
        >
          Add a hero image
        </Button>
      </PanelBody>
    </>
  );
}
