/**
 * POST /api/used-css
 *
 *   Authorization: Bearer <one of RCR_CSS_KEYS>
 *   { "url": "https://example.com/page/?rcr_ucss=1", "safelist": ["my-popup"] }
 *
 * 200 { "stylesheets": { "<href as written in the page>": "<used CSS>" }, "stats": {...} }
 *
 * RC Rocket calls this from WordPress's background job, stores the result on
 * the site and inlines it. If this service fails, the plugin builds the CSS
 * itself, so nothing here is on a visitor's path.
 */
import chromium from '@sparticuz/chromium';
import puppeteer from 'puppeteer-core';
import { authorised, publicUrl } from '../lib/guard.js';
import { usedCss } from '../lib/used-css.js';

export const config = { maxDuration: 120 };

const UA_MOBILE = 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36 RCRocket-UsedCSS';
const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 RCRocket-UsedCSS';

export default async function handler(req, res) {
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'POST only' });
  }
  if (!authorised(req.headers.authorization)) {
    return res.status(401).json({ error: 'missing or wrong key' });
  }

  const body = typeof req.body === 'string' ? safeJson(req.body) : req.body || {};
  const target = await publicUrl(body.url);
  if (!target.ok) {
    return res.status(400).json({ error: target.reason });
  }

  const safelist = Array.isArray(body.safelist) ? body.safelist.map(String).filter(Boolean).slice(0, 200) : [];
  const started = Date.now();
  let browser;

  try {
    browser = await puppeteer.launch({
      args: chromium.args,
      executablePath: await chromium.executablePath(),
      headless: true,
    });

    const result = await usedCss({
      url: target.url.href,
      safelist,
      openPage: async (vp) => {
        const page = await browser.newPage();
        await page.setUserAgent(vp.mobile ? UA_MOBILE : UA_DESKTOP);
        await page.setViewport({ width: vp.width, height: vp.height, isMobile: vp.mobile, hasTouch: vp.mobile, deviceScaleFactor: 1 });
        return page;
      },
    });

    return res.status(200).json({ ...result, ms: Date.now() - started });
  } catch (e) {
    return res.status(502).json({ error: `the page could not be measured: ${e.message}` });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch (e) {
    return {};
  }
}
