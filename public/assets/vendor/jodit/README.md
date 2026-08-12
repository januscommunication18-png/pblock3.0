# Jodit Pro — vendored build

The Pages editor uses **Jodit Pro** (`jodit-pro` on npm, licensed — see the package's
LICENSE.md). The built files here are copied from `node_modules/jodit-pro`.

    node_modules/jodit-pro/es2021.en/jodit.fat.min.js  →  jodit.fat.min.js
    node_modules/jodit-pro/es2021.en/jodit.fat.min.css →  jodit.fat.min.css

## Why the *fat* build, and why es2021.en

The slim build (`jodit.min.js`) keeps its **25 Pro plugins as separate files** under a
`plugins/` directory and loads them at runtime from a path relative to the script. Vendor the
slim build on its own and every Pro feature silently fails to appear — no error, just an
editor with none of the plugins you paid for. The fat build inlines them, which also satisfies
this project's no-CDN rule: nothing is fetched at runtime.

`es2021.en` is the modern, English-only build — the smallest of the three that still targets
current browsers. The CSS contains no external `url()` references, so there are no further
files to vendor.

## The licence key

`Jodit.defaultOptions.license` defaults to `''`, and without a key the Pro plugins register
but stay inert. Set it in the environment:

    JODIT_LICENSE=your-key

It travels as `editorLicense` in the Pages payload and is passed to `Jodit.make()`. If the Pro
features are missing, check this before anything else — it fails quietly by design.

## Re-vendoring after an upgrade

    npm install jodit-pro@latest
    cp node_modules/jodit-pro/es2021.en/jodit.fat.min.{js,css} public/assets/vendor/jodit/

`resources/views/projects/pages.blade.php` loads these only when `jodit.fat.min.js` exists, and
`pages.js` falls back to `<wi-editor>` (Quill) when `window.Jodit` is undefined — so a checkout
without the licensed package still works.

## Document view

The Pages editor runs in Jodit's **document view** — `iframe: true` plus an extended
`iframeStyle` — so the text is laid out on an A4 sheet (794×1123px at 96dpi) with page margins,
on a grey desk.

Two things follow from the mode that are easy to trip over:

- **The app's CSS cannot reach the document, and the document's cannot leak out.** That is the
  point, but it means the document's typography lives in `pageStyle()` inside
  `public/assets/js/projects/page-editor.js`, not in `pages.css`. Styling a heading in
  `pages.css` will appear to do nothing.
- **`iframeStyle` is appended to, never replaced.** Jodit's default carries the editor's base
  rules and the `page-break` plugin appends to the same option; overwriting it would drop both.

Turn it off per instance with `:document-view="false"`, which falls back to an ordinary
in-page editing area held to the usual column measure.
