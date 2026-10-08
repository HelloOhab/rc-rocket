# RC Rocket comparison

Loads every page twice: once as visitors get it, and once with `?rcr_safe=1`,
which switches every RC Rocket optimization off for that one request. Both
are loaded on a throttled phone (slow 4G, 4× slower CPU) and on a desktop,
three times each, and the medians are compared.

## Run it

    cd tools/compare
    npm install
    npx playwright install chromium
    npm run compare                     # pages from sites.txt
    node compare.mjs https://example.com/contact/ --runs 5

Open `report/index.html`. The exit status is 1 when any page fails, so it
can gate a release.

## What it checks

**Fail (blocks the release)**

- a JavaScript error, failed request or broken image that only happens with
  RC Rocket on
- an error status or a page that does not load
- jQuery still a stand-in after the visitor interacts (a delayed script was
  never released)

**Check (look at the screenshots)**

- LCP more than 10% slower, or more layout shift, with RC Rocket on
- page height differs by more than 3% (a section missing, collapsed or
  resized)
- more than 5% of the first screen looks different (sliders and
  animations also cause this)
- no page-cache header on the response

The tool scrolls through the page and moves the pointer after measuring LCP,
because delayed scripts only run once a visitor interacts, and that is when
their errors appear.

Also listed per page: Core Web Vitals and page weight before and after
interaction, what RC Rocket changed (delayed scripts, video posters, embed
facades, lazy backgrounds, preloads), the cache headers, and the LCP element.
