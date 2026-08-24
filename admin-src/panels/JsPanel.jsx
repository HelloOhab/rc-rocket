const { PanelBody, RangeControl, TextareaControl, TextControl, ToggleControl, Notice } = wp.components;

const lines = (v) => (Array.isArray(v) ? v.join('\n') : '');
const toList = (t) => t.split('\n').map((l) => l.trim()).filter(Boolean);

export default function JsPanel({ settings, update, status }) {
  const js = settings.js || {};
  const set = (key) => (value) => update('js', key, value);
  const anim = js.divi_animations || {};
  const setAnim = (key) => (value) => update('js', 'divi_animations', { ...anim, [key]: value });
  const diviFour = status && status.divi && status.divi.active && status.divi.major === 4;

  return (
    <>
      <PanelBody title="Loading" initialOpen>
        <ToggleControl
          label="Defer scripts"
          help="Scripts stop blocking the parser and run in order once the HTML is parsed. Low risk, and usually the single biggest render-blocking win."
          checked={!!js.defer}
          onChange={set('defer')}
        />
        <ToggleControl
          label="Delay scripts until interaction"
          help="Nothing runs until the visitor moves, scrolls, taps or the timeout expires. Big Total Blocking Time win, highest risk setting in the plugin. Turn it on last and test the site properly."
          checked={!!js.delay}
          onChange={set('delay')}
        />
        {js.delay && (
          <RangeControl
            label="Run anyway after (seconds)"
            value={js.delay_timeout || 6}
            min={1}
            max={20}
            onChange={set('delay_timeout')}
            help="A safety net for visitors who never interact — and for Googlebot."
          />
        )}
        {js.delay && diviFour && (
          <Notice status="warning" isDismissible={false}>
            Divi 4 detected. Inline scripts are never delayed on Divi 4 — its
            modules bootstrap inline against jQuery and delaying them breaks
            sliders, tabs and the mobile menu.
          </Notice>
        )}
        <TextareaControl
          label="Never defer or delay these"
          help="Substring match against the whole script tag. One per line. The Divi entries are here for a reason — removing them is how sliders die."
          value={lines(js.exclusions)}
          onChange={(t) => set('exclusions')(toList(t))}
          rows={10}
        />
      </PanelBody>

      <PanelBody title="Rendering" initialOpen={false}>
        <ToggleControl
          label="Lazy render offscreen sections"
          help="Uses content-visibility so the browser skips layout and paint for sections nobody has scrolled to. Cannot break behaviour — only rendering timing."
          checked={!!js.lazy_render}
          onChange={set('lazy_render')}
        />
        {js.lazy_render && (
          <>
            <TextareaControl
              label="Selectors to defer"
              help="The defaults skip the first three Divi sections, because one of them is your LCP element."
              value={lines(js.lazy_selectors)}
              onChange={(t) => set('lazy_selectors')(toList(t))}
              rows={6}
            />
            <TextControl
              label="Reserved height"
              help="Space held for a section before it renders. Too small causes layout shift on scroll."
              value={js.lazy_intrinsic || '640px'}
              onChange={set('lazy_intrinsic')}
            />
          </>
        )}
      </PanelBody>

      <PanelBody title="Divi animations" initialOpen>
        <p className="rcr-note">
          Divi hides every animated element until a script reveals it. That is
          fine until the script is deferred, delayed or slow — then the element
          never appears, and because it is invisible rather than missing,
          nothing looks broken.
        </p>
        <ToggleControl
          label="Reveal anything the animation script never got to"
          checked={!!anim.reveal_fallback}
          onChange={setAnim('reveal_fallback')}
          help="A pure CSS safety net. Costs nothing when the animation works normally."
        />
        <ToggleControl
          label="Skip animations on mobile"
          checked={!!anim.disable_on_mobile}
          onChange={setAnim('disable_on_mobile')}
          help="Where the main thread is scarcest and the effect is least visible, because everything is stacked in one column anyway."
        />
        {anim.disable_on_mobile && (
          <RangeControl
            label="Mobile breakpoint (px)"
            value={anim.mobile_breakpoint || 980}
            min={480}
            max={1200}
            step={20}
            onChange={setAnim('mobile_breakpoint')}
          />
        )}
        <ToggleControl
          label="Respect reduced motion"
          checked={!!anim.respect_reduced_motion}
          onChange={setAnim('respect_reduced_motion')}
          help="Divi does not honour this browser preference on its own."
        />
        <ToggleControl
          label="Switch animations off everywhere"
          checked={!!anim.disable_everywhere}
          onChange={setAnim('disable_everywhere')}
          help="Blunt, but the single biggest main-thread saving on a page with dozens of animated modules."
        />
      </PanelBody>

      <PanelBody title="Connections" initialOpen={false}>
        <TextareaControl
          label="Preconnect to these origins"
          help="One URL per line, for third parties you know load on every page — fonts, analytics, review widgets."
          value={lines(js.preconnect)}
          onChange={(t) => set('preconnect')(toList(t))}
          rows={4}
        />
      </PanelBody>
    </>
  );
}
