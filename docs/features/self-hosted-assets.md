# Self-hosted front-end assets (no CDN)

Every asset the browser loads is served from `public/assets`. Nothing is fetched from a third
party at runtime.

## What moved

| Was | Now |
|---|---|
| `cdn.tailwindcss.com` (Play CDN, compiled in the browser) | `public/assets/css/tailwind.css`, built by `npm run build:css` |
| `fonts.googleapis.com` + `fonts.gstatic.com` (Inter) | `public/assets/css/inter.css` + `public/assets/fonts/inter/*.woff2` |
| `unpkg.com/tabulator-tables@6.3.1` | `public/assets/vendor/tabulator/` |
| `cdn.jsdelivr.net/npm/quill@2.0.3` | `public/assets/vendor/quill/` |

Two reasons this matters beyond tidiness: a Dev/UAT site sitting behind the access gate should
not be announcing itself to four CDNs on every page load, and the app should render with no
outbound network at all.

## Tailwind is version 3, deliberately

`package.json` declared Tailwind 4, but the entire UI was authored against the Play CDN, which
serves **3**. Compiling with 4 would have been a silent restyle of the whole app:

- `shadow-sm`, `rounded-sm` and `blur-sm` name a different step in v4 — 34 sites,
- bare `border` changes from grey to `currentColor` — 92 sites,
- `ring` and `outline` defaults change — 124 and 182 sites.

So the build uses `tailwindcss@3.4`, the compiled output matches what the app has always
looked like, and `@tailwindcss/vite` was removed rather than left declaring a version nothing
uses. Moving to v4 is a separate job with a visual sweep behind it.

## The build

```bash
npm run build:css     # one-off, minified → public/assets/css/tailwind.css
npm run watch:css     # rebuild on change while working on the UI
```

`tailwind.config.js` at the project root holds the theme — the same tokens that used to live
in `public/assets/js/tailwind.config.js` as the CDN's runtime config, moved unchanged.

**The content globs are the load-bearing part:**

```js
content: ['./resources/views/**/*.blade.php', './public/assets/js/**/*.js']
```

The JS glob matters as much as the Blade one. This is a hybrid Vue-in-Blade app whose
components are string templates, so most classes only ever appear inside
`public/assets/js/**`. Drop that line and the app loads nearly unstyled.

Scanning JS as plain text is safe here because **every class in this codebase is a literal** —
nothing builds a class name by concatenation, which is the one pattern Tailwind cannot see. If
that ever changes, the new class needs a `safelist` entry.

**Rebuild after adding classes.** The Play CDN compiled on every page load, so a new class
worked immediately; a built stylesheet does not. `npm run watch:css` while working on the UI.

## Regenerating the font

`public/assets/css/inter.css` is Google's own stylesheet with the `src` URLs pointed at local
files. The `unicode-range` on each block is load-bearing — it is why the browser downloads
only the subsets a page needs, and why there are several small files rather than one large
one. To refresh:

```bash
curl -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" -o inter.css
# download each woff2 it references into public/assets/fonts/inter/,
# then rewrite the URLs to ../fonts/inter/<file>.woff2
```

The user agent matters: without it Google serves the older `woff` format.

## Stylesheet order

`tailwind.css` → `inter.css` → `styles.css`, framework first and the app's own CSS last.

The Play CDN injected its `<style>` after everything, so this is technically a change — but
the only element-level collision is `html { font-family }`, and Tailwind's preflight emits
`Inter, sans-serif` from the theme config, exactly what `styles.css` sets. `styles.css` uses
no `!important` and no rule that relied on beating a utility.

Grid pages then add `partials/work-item-assets`, whose own internal order is load-bearing for
a different reason — see the comment in that file.

## Upgrading a vendored library

```bash
npm install tabulator-tables@<version>          # or quill@<version>
cp node_modules/tabulator-tables/dist/js/tabulator.min.js  public/assets/vendor/tabulator/
cp node_modules/tabulator-tables/dist/css/tabulator.min.css public/assets/vendor/tabulator/
```

Both are committed to `public/` on purpose: the app loads them as plain `<script>`/`<link>`
tags, not through a bundler, so a deploy must not depend on `node_modules` being present.

## Test

`tests/Feature/SelfHostedAssetsTest.php` fails if any Blade view mentions a CDN host, if the
signup page renders one, or if a built asset is missing. A convention would not have held —
one pasted `<link>` undoes this silently.
