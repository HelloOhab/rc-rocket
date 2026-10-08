#!/usr/bin/env node
/**
 * RC Rocket A/B check: every page with the plugin's optimizations on, and
 * the same page with ?rcr_safe=1 (everything off), on a throttled phone and
 * on a desktop. Run it against a handful of real sites before a release
 * goes out to the fleet.
 *
 *   node compare.mjs https://example.com/ https://example.com/contact/
 *   node compare.mjs --sites sites.txt --runs 3 --out report
 *
 * Writes report/index.html, report/results.json and the screenshots. The
 * exit status is 1 when any page has a blocking problem (a JavaScript error
 * or failed request that only happens with the plugin on, or an error
 * status), so it can gate a release.
 */
import { createRequire } from 'node:module';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

// ---------------------------------------------------------------- options

const argv = process.argv.slice(2);
const opt = { runs: 3, out: 'report', sites: null, devices: 'mobile,desktop', headed: false };
const urls = [];

for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a === '--runs') opt.runs = Math.max(1, parseInt(argv[++i], 10) || 1);
  else if (a === '--out') opt.out = argv[++i];
  else if (a === '--sites') opt.sites = argv[++i];
  else if (a === '--devices') opt.devices = argv[++i];
  else if (a === '--headed') opt.headed = true;
  else if (a === '-h' || a === '--help') {
    console.log('usage: node compare.mjs [--sites file] [--runs 3] [--out report] [--devices mobile,desktop] [url ...]');
    process.exit(0);
  } else urls.push(a);
}

if (opt.sites) {
  const text = await readFile(opt.sites, 'utf8');
  for (const line of text.split('\n')) {
    const url = line.replace(/#.*/, '').trim();
    if (url) urls.push(url);
  }
}

if (!urls.length) {
  console.error('No URLs. Pass them as arguments or with --sites sites.txt');
  process.exit(2);
}

// Lighthouse's mobile profile: Moto G Power on slow 4G, CPU slowed 4x.
const DEVICES = {
  mobile: {
    viewport: { width: 412, height: 823 },
    deviceScaleFactor: 1.75,
    isMobile: true,
    hasTouch: true,
    userAgent:
      'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
    network: { latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 },
    cpu: 4,
  },
  desktop: {
    viewport: { width: 1350, height: 940 },
    deviceScaleFactor: 1,
    isMobile: false,
    hasTouch: false,
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    network: null,
    cpu: 1,
  },
};

const VARIANTS = ['on', 'off'];
const MARKERS = [
  ['delayed scripts', /type=["']rcrocket\/delayed/g],
  ['delay loader', /id=["']rcr-delay-loader/g],
  ['video poster gate', /data-rcr-video/g],
  ['embed facade', /data-rcr-embed/g],
  ['lazy backgrounds', /id=["']rcr-bg-lazy["']/g],
  ['lazy render', /id=["']rcr-lazy-render/g],
  ['hero beacon', /id=["']rcr-lcp/g],
  ['error beacon', /id=["']rcr-beacon/g],
  ['fetchpriority=high', /fetchpriority=["']high/g],
  ['preload hints', /rel=["']preload/g],
  ['loading=lazy', /loading=["']lazy/g],
  ['Google Fonts (remote)', /fonts\.(googleapis|gstatic)\.com/g],
];
const CACHE_HEADERS = ['x-rc-rocket-cache', 'x-rc-rocket-age', 'x-kinsta-cache', 'cf-cache-status', 'x-cache', 'x-proxy-cache', 'age', 'cache-control', 'server'];

// Collected in the page from the first byte.
const OBSERVERS = `
(() => {
  const m = window.__rcr = { lcp: 0, lcpEl: '', cls: 0, tbt: 0, longTasks: 0 };
  try {
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) {
        m.lcp = e.startTime;
        const el = e.element;
        m.lcpEl = el ? (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).slice(0, 3).join('.') : '')) : '';
        m.lcpUrl = e.url || '';
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) if (!e.hadRecentInput) m.cls += e.value;
    }).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver((l) => {
      for (const e of l.getEntries()) { m.longTasks++; m.tbt += Math.max(0, e.duration - 50); }
    }).observe({ type: 'longtask', buffered: true });
  } catch (e) {}
})();
`;

// ---------------------------------------------------------------- helpers

const median = (xs) => {
  const v = xs.filter((x) => typeof x === 'number' && !Number.isNaN(x)).sort((a, b) => a - b);
  if (!v.length) return null;
  const mid = Math.floor(v.length / 2);
  return v.length % 2 ? v[mid] : (v[mid - 1] + v[mid]) / 2;
};

const withSafe = (url, variant) => {
  const u = new URL(url);
  if (variant === 'off') u.searchParams.set('rcr_safe', '1');
  return u.toString();
};

const slug = (url) =>
  url.replace(/^https?:\/\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 80) || 'page';

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

// Normalise messages so the same error on both variants compares equal.
const norm = (s) => String(s).replace(/rcr_safe=1&?/g, '').replace(/[?&]ver=[^\s&'")]+/g, '').replace(/:\d+:\d+/g, '').slice(0, 300);

async function scrollThrough(page) {
  await page.evaluate(async () => {
    const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 120));
    }
    window.scrollTo(0, 0);
  });
}

// ---------------------------------------------------------------- one load

async function load(browser, url, deviceName, variant, run, shotDir) {
  const d = DEVICES[deviceName];
  const context = await browser.newContext({
    viewport: d.viewport,
    deviceScaleFactor: d.deviceScaleFactor,
    isMobile: d.isMobile,
    hasTouch: d.hasTouch,
    userAgent: d.userAgent,
    ignoreHTTPSErrors: false,
  });
  await context.addInitScript(OBSERVERS);
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  if (d.network) await cdp.send('Network.emulateNetworkConditions', { offline: false, ...d.network });
  if (d.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: d.cpu });

  const r = { url, device: deviceName, variant, run, pageErrors: [], consoleErrors: [], failed: [], bytes: 0, requests: 0, byType: {} };
  const typeOf = new Map();

  cdp.on('Network.responseReceived', (e) => typeOf.set(e.requestId, e.type));
  cdp.on('Network.loadingFinished', (e) => {
    r.bytes += e.encodedDataLength;
    r.requests++;
    const t = typeOf.get(e.requestId) || 'Other';
    r.byType[t] = (r.byType[t] || 0) + e.encodedDataLength;
  });
  page.on('pageerror', (e) => r.pageErrors.push(norm(e.message)));
  page.on('console', (m) => { if (m.type() === 'error') r.consoleErrors.push(norm(m.text())); });
  page.on('response', (res) => {
    if (res.status() >= 400) r.failed.push(`${res.status()} ${norm(res.url())}`);
  });
  page.on('requestfailed', (req) => {
    const f = req.failure()?.errorText || 'failed';
    if (!/ERR_ABORTED/.test(f)) r.failed.push(`${f} ${norm(req.url())}`);
  });

  const target = withSafe(url, variant);
  const t0 = Date.now();
  let response;
  try {
    response = await page.goto(target, { waitUntil: 'load', timeout: 90_000 });
  } catch (e) {
    r.navError = e.message.split('\n')[0];
    await context.close();
    return r;
  }
  r.loadMs = Date.now() - t0;
  r.status = response?.status() ?? 0;
  const headers = response ? await response.allHeaders() : {};
  r.headers = Object.fromEntries(CACHE_HEADERS.filter((h) => headers[h] !== undefined).map((h) => [h, headers[h]]));

  // Quiet period before anyone touches the page: this is what Lighthouse
  // and Core Web Vitals see for LCP.
  await page.waitForTimeout(3000);
  const before = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0] || {};
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    return {
      ttfb: nav.responseStart,
      fcp: fcp ? fcp.startTime : null,
      dcl: nav.domContentLoadedEventEnd,
      load: nav.loadEventEnd,
      lcp: window.__rcr.lcp,
      lcpEl: window.__rcr.lcpEl,
      lcpUrl: window.__rcr.lcpUrl,
      tbt: window.__rcr.tbt,
    };
  });
  Object.assign(r, before);
  r.bytesBeforeInteraction = r.bytes;

  // Now behave like a visitor: this releases delayed scripts, and their
  // errors only show up from here on.
  await page.mouse.move(d.viewport.width / 2, d.viewport.height / 2);
  if (d.hasTouch) await page.touchscreen.tap(5, d.viewport.height - 5).catch(() => {});
  await page.mouse.wheel(0, 300);
  await page.waitForTimeout(1500);
  await scrollThrough(page);
  await page.waitForTimeout(2500);

  r.cls = await page.evaluate(() => window.__rcr.cls);
  r.height = await page.evaluate(() => document.documentElement.scrollHeight);
  r.jquery = await page.evaluate(() => (window.jQuery && window.jQuery.fn && window.jQuery.fn.jquery) || (window.jQuery ? 'stand-in' : 'none'));
  r.brokenImages = await page.evaluate(() =>
    [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.currentSrc && !i.currentSrc.startsWith('data:')).map((i) => i.currentSrc).slice(0, 10),
  );

  if (variant === 'on') {
    const html = response ? await response.text().catch(() => '') : '';
    r.markers = Object.fromEntries(MARKERS.map(([name, re]) => [name, (html.match(re) || []).length]));
  }

  // Screenshots only on the first run of each variant.
  if (run === 0) {
    const base = `${slug(url)}--${deviceName}--${variant}`;
    await page.screenshot({ path: path.join(shotDir, `${base}-fold.png`), animations: 'disabled' });
    await page.screenshot({ path: path.join(shotDir, `${base}-full.png`), fullPage: true, animations: 'disabled' }).catch(() => {});
    r.shotFold = `shots/${base}-fold.png`;
    r.shotFull = `shots/${base}-full.png`;
  }

  await context.close();
  return r;
}

// Pixel difference between two screenshots, computed in the browser so the
// tool needs nothing beyond Playwright.
async function visualDiff(browser, aPath, bPath, outPath) {
  const [a, b] = await Promise.all([readFile(aPath), readFile(bPath)]);
  const page = await browser.newPage();
  const result = await page.evaluate(
    async ({ a, b }) => {
      const img = (src) => new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = no; i.src = src; });
      const [ia, ib] = await Promise.all([img(a), img(b)]);
      const w = Math.min(ia.width, ib.width), h = Math.min(ia.height, ib.height);
      const ctx = (i) => { const c = document.createElement('canvas'); c.width = w; c.height = h; const x = c.getContext('2d'); x.drawImage(i, 0, 0); return x; };
      const da = ctx(ia).getImageData(0, 0, w, h), db = ctx(ib).getImageData(0, 0, w, h);
      const out = document.createElement('canvas'); out.width = w; out.height = h;
      const ox = out.getContext('2d'); ox.drawImage(ia, 0, 0); ox.fillStyle = 'rgba(255,255,255,0.7)'; ox.fillRect(0, 0, w, h);
      const od = ox.getImageData(0, 0, w, h);
      let diff = 0;
      for (let p = 0; p < da.data.length; p += 4) {
        const dd = Math.abs(da.data[p] - db.data[p]) + Math.abs(da.data[p + 1] - db.data[p + 1]) + Math.abs(da.data[p + 2] - db.data[p + 2]);
        if (dd > 60) { diff++; od.data[p] = 230; od.data[p + 1] = 0; od.data[p + 2] = 0; od.data[p + 3] = 255; }
      }
      ox.putImageData(od, 0, 0);
      return { pct: (diff / (w * h)) * 100, heightA: ia.height, heightB: ib.height, png: out.toDataURL('image/png') };
    },
    { a: 'data:image/png;base64,' + a.toString('base64'), b: 'data:image/png;base64,' + b.toString('base64') },
  );
  await page.close();
  await writeFile(outPath, Buffer.from(result.png.split(',')[1], 'base64'));
  return { pct: result.pct, heightOn: result.heightA, heightOff: result.heightB };
}

// ---------------------------------------------------------------- run

const outDir = path.resolve(opt.out);
const shotDir = path.join(outDir, 'shots');
await mkdir(shotDir, { recursive: true });

const browser = await chromium.launch({ headless: !opt.headed });
const devices = opt.devices.split(',').map((s) => s.trim()).filter((s) => DEVICES[s]);
const results = [];

for (const url of urls) {
  for (const device of devices) {
    console.log(`\n${url} [${device}]`);
    const runs = { on: [], off: [] };

    // Warm whatever page cache sits in front of the site, both variants.
    for (const v of VARIANTS) {
      const ctx = await browser.newContext({ userAgent: DEVICES[device].userAgent });
      const p = await ctx.newPage();
      await p.goto(withSafe(url, v), { waitUntil: 'domcontentloaded', timeout: 60_000 }).catch(() => {});
      await ctx.close();
    }

    // Interleave on/off so a slow moment on the server hits both alike.
    for (let i = 0; i < opt.runs; i++) {
      for (const v of VARIANTS) {
        const r = await load(browser, url, device, v, i, shotDir);
        runs[v].push(r);
        console.log(`  ${v.padEnd(3)} run ${i + 1}: ${r.navError ? 'ERROR ' + r.navError : `LCP ${Math.round(r.lcp)}ms, ${(r.bytes / 1024).toFixed(0)} KB, ${r.pageErrors.length} JS errors`}`);
      }
    }

    const summarise = (rs) => {
      const ok = rs.filter((r) => !r.navError);
      const first = ok[0] || rs[0];
      const uniq = (k) => [...new Set(ok.flatMap((r) => r[k]))];
      return {
        status: first.status,
        navError: ok.length ? null : first.navError,
        headers: first.headers,
        markers: first.markers,
        jquery: first.jquery,
        lcpEl: first.lcpEl,
        lcpUrl: first.lcpUrl,
        ttfb: median(ok.map((r) => r.ttfb)),
        fcp: median(ok.map((r) => r.fcp)),
        lcp: median(ok.map((r) => r.lcp)),
        tbt: median(ok.map((r) => r.tbt)),
        cls: median(ok.map((r) => r.cls)),
        load: median(ok.map((r) => r.load)),
        kbInitial: median(ok.map((r) => r.bytesBeforeInteraction / 1024)),
        kbTotal: median(ok.map((r) => r.bytes / 1024)),
        requests: median(ok.map((r) => r.requests)),
        height: median(ok.map((r) => r.height)),
        pageErrors: uniq('pageErrors'),
        consoleErrors: uniq('consoleErrors'),
        failed: uniq('failed'),
        brokenImages: uniq('brokenImages'),
        shotFold: first.shotFold,
        shotFull: first.shotFull,
      };
    };

    const on = summarise(runs.on);
    const off = summarise(runs.off);
    const row = { url, device, on, off, issues: [], warnings: [] };

    if (on.navError) row.issues.push(`Page did not load with RC Rocket on: ${on.navError}`);
    if (on.status && on.status !== 200) row.issues.push(`HTTP ${on.status} with RC Rocket on`);

    const onlyOn = (k) => on[k].filter((e) => !off[k].includes(e));
    for (const e of onlyOn('pageErrors')) row.issues.push(`JS error only with RC Rocket on: ${e}`);
    for (const e of onlyOn('failed')) row.issues.push(`Request failing only with RC Rocket on: ${e}`);
    for (const e of onlyOn('brokenImages')) row.issues.push(`Image broken only with RC Rocket on: ${e}`);
    for (const e of onlyOn('consoleErrors')) row.warnings.push(`Console error only with RC Rocket on: ${e}`);
    if (on.jquery === 'stand-in') row.issues.push('jQuery is still a stand-in after interaction: a delayed script never released');

    if (on.lcp && off.lcp && on.lcp > off.lcp * 1.1 + 100) row.warnings.push(`LCP slower with RC Rocket on (${Math.round(on.lcp)} vs ${Math.round(off.lcp)} ms)`);
    if (on.cls != null && off.cls != null && on.cls > off.cls + 0.05) row.warnings.push(`More layout shift with RC Rocket on (CLS ${on.cls.toFixed(3)} vs ${off.cls.toFixed(3)})`);
    if (on.height && off.height && Math.abs(on.height - off.height) / off.height > 0.03)
      row.warnings.push(`Page height differs (${on.height}px on vs ${off.height}px off): a section may be missing, collapsed or resized`);
    if (on.markers && !on.headers?.['x-rc-rocket-cache'] && !on.headers?.['x-kinsta-cache'] && !on.headers?.['cf-cache-status'] && !on.headers?.['x-cache'])
      row.warnings.push('No page-cache header on the response: the page may not be cached');

    if (on.shotFold && off.shotFold) {
      for (const kind of ['fold', 'full']) {
        const a = path.join(outDir, on[kind === 'fold' ? 'shotFold' : 'shotFull']);
        const b = path.join(outDir, off[kind === 'fold' ? 'shotFold' : 'shotFull']);
        const diffRel = `shots/${slug(url)}--${device}--diff-${kind}.png`;
        try {
          row[`diff_${kind}`] = { ...(await visualDiff(browser, a, b, path.join(outDir, diffRel))), image: diffRel };
        } catch {
          /* a missing full-page shot is not worth failing over */
        }
      }
      const fold = row.diff_fold?.pct ?? 0;
      if (fold > 5) row.warnings.push(`Above the fold looks different with RC Rocket on (${fold.toFixed(1)}% of pixels): check the screenshots (sliders and animations also cause this)`);
    }

    row.verdict = row.issues.length ? 'FAIL' : row.warnings.length ? 'CHECK' : 'PASS';
    console.log(`  => ${row.verdict}${row.issues.length ? '\n     ' + row.issues.join('\n     ') : ''}`);
    results.push(row);
  }
}

await browser.close();

// ---------------------------------------------------------------- report

const fmt = (v, unit = 'ms', digits = 0) => (v == null ? '–' : `${Number(v).toFixed(digits)}${unit}`);
const delta = (on, off, lowerIsBetter = true, unit = 'ms', digits = 0) => {
  if (on == null || off == null) return '';
  const d = on - off;
  if (Math.abs(d) < (unit === '' ? 0.005 : 1)) return '<span class="d">±0</span>';
  const good = lowerIsBetter ? d < 0 : d > 0;
  return `<span class="d ${good ? 'good' : 'bad'}">${d > 0 ? '+' : ''}${d.toFixed(digits)}${unit}</span>`;
};

const metric = (label, k, unit = 'ms', digits = 0) =>
  (row) => `<tr><th>${label}</th><td>${fmt(row.on[k], unit, digits)}</td><td>${fmt(row.off[k], unit, digits)}</td><td>${delta(row.on[k], row.off[k], true, unit, digits)}</td></tr>`;

const METRICS = [
  metric('Time to first byte', 'ttfb'),
  metric('First contentful paint', 'fcp'),
  metric('Largest contentful paint', 'lcp'),
  metric('Total blocking time', 'tbt'),
  metric('Cumulative layout shift', 'cls', '', 3),
  metric('Load event', 'load'),
  metric('Bytes before interaction', 'kbInitial', ' KB'),
  metric('Bytes after interaction', 'kbTotal', ' KB'),
  metric('Requests', 'requests', ''),
];

const list = (xs) => (xs?.length ? `<ul>${xs.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : '<p class="muted">None</p>');
const counts = { PASS: 0, CHECK: 0, FAIL: 0 };
results.forEach((r) => counts[r.verdict]++);

const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RC Rocket Comparison</title>
<style>
:root{--bg:#fff;--fg:#1d2327;--muted:#646970;--line:#dcdcde;--card:#f6f7f7;--pass:#00a32a;--check:#dba617;--fail:#d63638}
@media (prefers-color-scheme:dark){:root{--bg:#1d2327;--fg:#f0f0f1;--muted:#a7aaad;--line:#3c434a;--card:#2c3338}}
body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:1200px;margin:0 auto;padding:24px 16px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:0;word-break:break-all}h3{font-size:14px;margin:16px 0 6px}
.muted{color:var(--muted)}.sum{display:flex;gap:12px;margin:16px 0 24px;flex-wrap:wrap}
.pill{padding:6px 12px;border-radius:999px;background:var(--card);font-weight:600}
.v{display:inline-block;padding:2px 10px;border-radius:4px;color:#fff;font-weight:700;font-size:12px}
.PASS{background:var(--pass)}.CHECK{background:var(--check);color:#1d2327}.FAIL{background:var(--fail)}
section{border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:20px}
header{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
table{border-collapse:collapse;width:100%;max-width:560px}td,th{padding:4px 8px;border-bottom:1px solid var(--line);text-align:right}th{text-align:left;font-weight:500}
thead th{text-align:right}thead th:first-child{text-align:left}
.d.good{color:var(--pass)}.d.bad{color:var(--fail)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
.shots{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.shots figure{margin:0}.shots img{width:100%;border:1px solid var(--line)}
figcaption{font-size:12px;color:var(--muted)}ul{margin:4px 0;padding-left:18px}li{word-break:break-all}
.issues li{color:var(--fail)}.warnings li{color:var(--check)}
code{font-size:12px}
@media (max-width:640px){.shots{grid-template-columns:1fr}}
</style></head><body><main>
<h1>RC Rocket Comparison</h1>
<p class="muted">Each page loaded ${opt.runs}× with RC Rocket on and ${opt.runs}× with <code>?rcr_safe=1</code> (optimizations off), median shown. Mobile is throttled to slow 4G and a 4× slower CPU. ${new Date().toISOString()}</p>
<div class="sum"><span class="pill">${results.length} checks</span><span class="pill" style="color:var(--pass)">${counts.PASS} pass</span><span class="pill" style="color:var(--check)">${counts.CHECK} to check</span><span class="pill" style="color:var(--fail)">${counts.FAIL} fail</span></div>
${results.map((r) => `
<section>
<header><span class="v ${r.verdict}">${r.verdict}</span><h2>${esc(r.url)}</h2><span class="muted">${r.device}</span></header>
${r.issues.length ? `<h3>Blocking</h3><ul class="issues">${r.issues.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}
${r.warnings.length ? `<h3>To check</h3><ul class="warnings">${r.warnings.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}
<div class="grid">
<div><h3>Metrics</h3><table><thead><tr><th></th><th>On</th><th>Off</th><th>Δ</th></tr></thead><tbody>${METRICS.map((m) => m(r)).join('')}</tbody></table>
<p class="muted">LCP element (on): <code>${esc(r.on.lcpEl || '–')}</code>${r.on.lcpUrl ? `<br><code>${esc(r.on.lcpUrl)}</code>` : ''}<br>LCP element (off): <code>${esc(r.off.lcpEl || '–')}</code></p></div>
<div><h3>What RC Rocket changed</h3>${r.on.markers ? `<table><tbody>${Object.entries(r.on.markers).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${v}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">–</p>'}
<h3>Response headers (on)</h3>${r.on.headers && Object.keys(r.on.headers).length ? `<table><tbody>${Object.entries(r.on.headers).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">None</p>'}
<p class="muted">jQuery after interaction: on ${esc(r.on.jquery)}, off ${esc(r.off.jquery)}</p></div>
</div>
<div class="grid">
<div><h3>JS errors (on)</h3>${list(r.on.pageErrors)}</div>
<div><h3>JS errors (off)</h3>${list(r.off.pageErrors)}</div>
<div><h3>Failed requests (on)</h3>${list(r.on.failed)}</div>
</div>
${r.on.shotFold ? `<h3>Above the fold${r.diff_fold ? ` · ${r.diff_fold.pct.toFixed(1)}% different` : ''}</h3>
<div class="shots"><figure><a href="${r.on.shotFold}"><img loading="lazy" src="${r.on.shotFold}" alt="On"></a><figcaption>On</figcaption></figure>
<figure><a href="${r.off.shotFold}"><img loading="lazy" src="${r.off.shotFold}" alt="Off"></a><figcaption>Off</figcaption></figure>
${r.diff_fold ? `<figure><a href="${r.diff_fold.image}"><img loading="lazy" src="${r.diff_fold.image}" alt="Difference"></a><figcaption>Difference (red)</figcaption></figure>` : ''}</div>
<p class="muted">Full page: <a href="${r.on.shotFull}">on</a> · <a href="${r.off.shotFull}">off</a>${r.diff_full ? ` · <a href="${r.diff_full.image}">difference</a> (${r.diff_full.pct.toFixed(1)}%, ${r.diff_full.heightOn}px vs ${r.diff_full.heightOff}px tall)` : ''}</p>` : ''}
</section>`).join('')}
</main></body></html>`;

await writeFile(path.join(outDir, 'results.json'), JSON.stringify({ when: new Date().toISOString(), runs: opt.runs, results }, null, 2));
await writeFile(path.join(outDir, 'index.html'), html);

console.log(`\n${counts.PASS} pass, ${counts.CHECK} to check, ${counts.FAIL} fail → ${path.join(outDir, 'index.html')}`);
process.exit(counts.FAIL ? 1 : 0);
