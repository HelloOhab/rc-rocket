# RC Rocket CSS service

Measures which CSS a page uses, in headless Chrome, for RC Rocket's
**Remove unused CSS**. Like WP Rocket's own service: this only *builds* the
trimmed CSS. Each WordPress site stores it and serves it inlined, so
visitors never wait on this service, and if it is down the site trims the
CSS itself.

How a page is measured:

1. Loaded at phone size and at desktop size.
2. At each size the selectors of the site's own stylesheets are tested
   against the rendered page after loading, after scrolling through it, and
   (on the phone) with the mobile menu open, so classes scripts add later
   count too. Hover and focus styles count when their element exists.
3. The original CSS text is trimmed to those selectors with PostCSS, so
   vendor prefixes and other browsers' rules survive exactly as written.

On the beta sites' home pages this removed 93% (Divi 4) and 71% (Divi 5) of
the CSS, against 79% and 58% when the site does it with text matching, and
the pages were pixel-identical before any interaction, with the full
stylesheets blocked.

## Deploy on Vercel (once)

1. In Vercel: **Add New → Project**, import the `rc-rocket` repository and
   set **Root Directory** to `service/used-css`. No build settings needed.
2. **Settings → Environment Variables**: add `RCR_CSS_KEYS` with a long
   random key, for example the output of `openssl rand -hex 32`. Several
   keys, comma separated, let you replace one without downtime.
3. Deploy. The address is `https://<project>.vercel.app/api/used-css`.

Client sites are commercial use, so the project needs a paid Vercel plan
(Hobby is for personal projects). Each page takes about 20 seconds of
function time, once per page until it changes.

## Connect a site

In WordPress: **RC Rocket → Advanced → Assets → Unused CSS**, switch on
**Remove unused CSS**, and fill in **Service address** and **Service key**.
Or, for every site at once, in `wp-config.php`:

```php
define( 'RC_ROCKET_CSS_SERVICE', 'https://<project>.vercel.app/api/used-css' );
define( 'RC_ROCKET_CSS_KEY', '<one of RCR_CSS_KEYS>' );
```

System check then says how many pages the service measured.

## The API

```
POST /api/used-css
Authorization: Bearer <key>
{ "url": "https://example.com/page/?rcr_ucss=1", "safelist": ["my-popup"] }

200 { "stylesheets": { "<href as written in the page>": "<used CSS>" },
      "stats": { "stylesheets", "selectors", "used", "before", "after" }, "ms" }
401 wrong key · 400 not a public http(s) address · 502 the page could not be measured
```

Only public addresses on ports 80 and 443 are accepted, so the service
cannot be pointed at a private network.

## Develop

```
npm install
npm test
```

Chrome and Puppeteer are pinned together (`@sparticuz/chromium` 153 with
`puppeteer-core` 25.11, the release built for Chrome 153). Upgrade them as a
pair.
