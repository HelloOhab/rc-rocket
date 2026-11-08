/**
 * The used CSS of one page, measured in a real browser.
 *
 * The page is loaded at phone and desktop size. At each size the selectors
 * of the site's own stylesheets are tested after loading, after scrolling
 * through the page (sticky headers, entrance animations, lazy sections) and
 * after opening the mobile menu, so classes scripts add later count too.
 *
 * `openPage(viewport)` comes from the caller: Puppeteer on Vercel,
 * Playwright in tests. The page needs goto(), evaluate(string) and close().
 */
import { parse, selectorsOf, MATCH_IN_PAGE, trim } from './trim.js';

export const VIEWPORTS = [
  { name: 'mobile', width: 412, height: 823, mobile: true },
  { name: 'desktop', width: 1350, height: 940, mobile: false },
];

/** Buttons that open a mobile menu, in the themes and menu plugins we see. */
const MENU_TOGGLES = [
  'a.dvmm_mobile_menu_toggle', '.dvmm_menu_button', '.mobile_menu_bar', '.et_pb_menu__icon',
  '.et_mobile_nav_menu .mobile_nav', '.menu-toggle', 'button[aria-controls][aria-expanded="false"]',
];

const LINKS_IN_PAGE = `(() => [...document.querySelectorAll('link[rel~="stylesheet"][href]')]
  .filter((l) => !l.hasAttribute('data-no-optimize') && new URL(l.href).host === location.host && !/^(print|speech)$/i.test((l.media || '').trim()))
  .map((l) => ({ href: l.getAttribute('href'), abs: l.href })))()`;

const FETCH_IN_PAGE = `(async (urls) => Promise.all(urls.map((u) => fetch(u, { credentials: 'omit' }).then((r) => r.ok ? r.text() : null).catch(() => null))))`;

const SCROLL_IN_PAGE = `(async () => {
  const step = Math.max(200, Math.floor(innerHeight * 0.8));
  for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
    scrollTo(0, y);
    await new Promise((r) => setTimeout(r, 120));
  }
  await new Promise((r) => setTimeout(r, 600));
  scrollTo(0, 0);
  await new Promise((r) => setTimeout(r, 300));
})()`;

const MENU_IN_PAGE = (toggles) => `(async () => {
  // A toggle that is also a real link must not leave the page.
  addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (a && !/^(#|javascript:)/i.test(a.getAttribute('href') || '')) e.preventDefault();
  }, true);
  for (const sel of ${JSON.stringify(toggles)}) {
    const el = document.querySelector(sel);
    if (!el) continue;
    const box = el.getBoundingClientRect();
    if (box.width === 0 || box.height === 0) continue;
    el.click();
    await new Promise((r) => setTimeout(r, 800));
    return sel;
  }
  return '';
})()`;

/**
 * @param {object}   options
 * @param {function} options.openPage  async (viewport) => page
 * @param {string}   options.url       the page, as the plugin asks for it
 * @param {string[]} [options.safelist]
 * @returns {Promise<{stylesheets: Object<string,string>, stats: object}>}
 */
export async function usedCss({ openPage, url, safelist = [], log = () => {} }) {
  const sheets = new Map(); // href as written in the page => { abs, root, selectors }
  const matched = new Set();
  let selectors = [];

  for (const viewport of VIEWPORTS) {
    const page = await openPage(viewport);

    try {
      await page.goto(url, { waitUntil: 'load', timeout: 30000 });
      await sleep(1500);

      if (sheets.size === 0) {
        const links = await page.evaluate(LINKS_IN_PAGE);
        const texts = await page.evaluate(`${FETCH_IN_PAGE}(${JSON.stringify(links.map((l) => l.abs))})`);

        links.forEach((link, i) => {
          if (typeof texts[i] !== 'string' || sheets.has(link.href)) return;
          try {
            const root = parse(texts[i]);
            sheets.set(link.href, { abs: link.abs, root, bytes: texts[i].length });
          } catch (e) {
            log(`could not parse ${link.abs}: ${e.message}`);
          }
        });

        selectors = [...new Set([...sheets.values()].flatMap((s) => selectorsOf(s.root)))];
      }

      const check = async (moment) => {
        const pending = selectors.filter((s) => !matched.has(s));
        if (pending.length === 0) return;
        const hit = await page.evaluate(`(${MATCH_IN_PAGE})(${JSON.stringify(pending)})`);
        hit.forEach((s) => matched.add(s));
        log(`${viewport.name} ${moment}: ${matched.size}/${selectors.length} selectors used`);
      };

      await check('loaded');
      await page.evaluate(SCROLL_IN_PAGE);
      await check('scrolled');

      if (viewport.mobile) {
        const opened = await page.evaluate(MENU_IN_PAGE(MENU_TOGGLES));
        if (opened) await check(`menu open (${opened})`);
      }
    } finally {
      await page.close();
    }
  }

  const stylesheets = {};
  let before = 0;
  let after = 0;

  for (const [href, sheet] of sheets) {
    const css = trim(sheet.root, matched, safelist, sheet.abs);
    stylesheets[href] = css;
    before += sheet.bytes;
    after += css.length;
  }

  return { stylesheets, stats: { stylesheets: sheets.size, selectors: selectors.length, used: matched.size, before, after } };
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}
