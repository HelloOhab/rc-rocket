/**
 * Work out which CSS a rendered page uses, and trim the stylesheets to it.
 *
 * Two halves, so the browser part works with Puppeteer (on Vercel) and with
 * Playwright (in tests):
 *
 *   selectorsOf(css)                 every selector in a stylesheet (Node)
 *   matchIn(page, selectors)         which of them match the page (browser)
 *   trim(css, keep, safelist, base)  the stylesheet with only those (Node)
 *
 * The original CSS text is trimmed, never re-serialised from the browser, so
 * vendor prefixes and rules for other browsers survive as written.
 */
import postcss from 'postcss';

/** At-rules kept whole: they hold no selectors to test. */
const KEEP_WHOLE = new Set(['font-face', 'page', 'property', 'counter-style', 'font-feature-values', 'font-palette-values', 'view-transition', 'viewport', '-ms-viewport']);

export function parse(css) {
  return postcss.parse(css);
}

/** Every distinct selector of every style rule (keyframe steps excluded). */
export function selectorsOf(root) {
  const out = new Set();
  root.walkRules((rule) => {
    if (insideKeyframes(rule)) return;
    for (const s of rule.selectors) out.add(s.trim());
  });
  return [...out];
}

/**
 * Runs in the page. For each selector: drop pseudo-classes and -elements
 * (a :hover style counts when the element exists), then ask the browser
 * whether anything matches. A selector the browser cannot parse counts as
 * matching: unsure means keep.
 *
 * Passed to page.evaluate as a string, so it must stay self-contained.
 */
export const MATCH_IN_PAGE = `(selectors) => {
  const strip = (sel) => {
    let out = '';
    for (let i = 0; i < sel.length; i++) {
      const c = sel[i];
      if (c === '\\\\') { out += c + (sel[i + 1] || ''); i++; continue; }
      if (c === '"' || c === "'") {
        const end = sel.indexOf(c, i + 1);
        const j = end === -1 ? sel.length - 1 : end;
        out += sel.slice(i, j + 1); i = j; continue;
      }
      if (c === '[') {
        const end = sel.indexOf(']', i);
        const j = end === -1 ? sel.length - 1 : end;
        out += sel.slice(i, j + 1); i = j; continue;
      }
      if (c === ':') {
        let j = i + 1;
        if (sel[j] === ':') j++;
        while (j < sel.length && /[\\w-]/.test(sel[j])) j++;
        if (sel[j] === '(') {
          let depth = 0;
          for (; j < sel.length; j++) {
            if (sel[j] === '(') depth++;
            else if (sel[j] === ')' && --depth === 0) { j++; break; }
          }
        }
        const prev = out.slice(-1);
        if (out === '' || /[\\s>+~(,]/.test(prev)) out += '*';
        i = j - 1; continue;
      }
      out += c;
    }
    return out.trim() || '*';
  };
  const hit = [];
  for (const sel of selectors) {
    try {
      if (document.querySelector(strip(sel))) hit.push(sel);
    } catch (e) {
      hit.push(sel);
    }
  }
  return hit;
}`;

/**
 * The stylesheet with only the selectors in `keep` (or containing a safelist
 * entry), empty at-rules removed, unused @keyframes dropped, and url()
 * made absolute against `base` so the CSS still works inlined in a page.
 */
export function trim(root, keep, safelist, base) {
  const keepSet = keep instanceof Set ? keep : new Set(keep);
  const safe = (sel) => safelist.some((s) => s && sel.includes(s));

  root.walkRules((rule) => {
    if (insideKeyframes(rule)) return;
    const kept = rule.selectors.filter((s) => keepSet.has(s.trim()) || safe(s));
    if (kept.length === 0) rule.remove();
    else if (kept.length !== rule.selectors.length) rule.selectors = kept;
  });

  root.walkAtRules((at) => {
    const name = at.name.toLowerCase();
    if (name === 'charset') at.remove();
  });

  // Empty containers, innermost first, until nothing changes.
  let changed = true;
  while (changed) {
    changed = false;
    root.walkAtRules((at) => {
      const name = at.name.toLowerCase().replace(/^-[a-z]+-/, '');
      if (KEEP_WHOLE.has(name) || name === 'keyframes' || name === 'import' || name === 'namespace' || name === 'layer' && !at.nodes) return;
      if (at.nodes && at.nodes.length === 0) { at.remove(); changed = true; }
    });
  }

  // @keyframes survive only when a kept rule animates with them.
  const used = new Set();
  root.walkDecls(/^(-[a-z]+-)?animation(-name)?$/i, (d) => {
    for (const word of d.value.split(/[\s,]+/)) used.add(word.replace(/^["']|["']$/g, ''));
  });
  root.walkAtRules(/^(-[a-z]+-)?keyframes$/i, (at) => {
    if (!used.has(at.params.trim().replace(/^["']|["']$/g, ''))) at.remove();
  });

  if (base) {
    root.walkDecls((d) => {
      if (!/url\(/i.test(d.value)) return;
      d.value = d.value.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/gi, (m, q, url) => {
        const u = url.trim();
        if (/^(data:|[a-z][a-z0-9+.-]*:|\/\/|#)/i.test(u)) return m;
        try { return `url(${q}${new URL(u, base).href}${q})`; } catch (e) { return m; }
      });
    });
    root.walkAtRules('import', (at) => {
      at.params = at.params.replace(/(url\(\s*['"]?|^['"])([^'")]+)/i, (m, pre, url) => {
        try { return pre + new URL(url, base).href; } catch (e) { return m; }
      });
    });
  }

  root.walkComments((c) => c.remove());

  return root.toString().replace(/\n\s*\n/g, '\n').trim();
}

function insideKeyframes(node) {
  for (let p = node.parent; p; p = p.parent) {
    if (p.type === 'atrule' && /keyframes$/i.test(p.name)) return true;
  }
  return false;
}
