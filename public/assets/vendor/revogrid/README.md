# RevoGrid — vendored

`@revolist/revogrid` **4.25.2**, MIT licensed. The Views spreadsheet grid (`view-grid.js`) is
built on it.

## What is here, and why all of it

The **whole** `dist/revo-grid/` directory, copied verbatim. RevoGrid is a Stencil build: the
entry point `revo-grid.esm.js` registers the custom elements and then lazily `import()`s the
other files in this folder at runtime, resolved relative to its own URL.

So it cannot be reduced to "the one file we use". Copying only the entry gives a grid that
loads, defines `<revo-grid>`, and then fails at the first dynamic import — which presents as an
empty element rather than an error. This is the same lesson the Jodit drop next door records:
a build that fetches its own parts at runtime must be vendored with its parts.

Chunk filenames are content-hashed by Stencil (`app-globals-CA4dvSNd.js`), so they cache-bust
themselves and do not need `pb_asset()`'s `?v=` — only the entry point is referenced from
Blade, and that one does go through `pb_asset()`.

## Updating

Replace the directory wholesale; do not merge:

```bash
npm install @revolist/revogrid@<version>
rm -rf public/assets/vendor/revogrid
mkdir -p public/assets/vendor/revogrid
cp -R node_modules/@revolist/revogrid/dist/revo-grid/. public/assets/vendor/revogrid/
```

Leaving old hashed chunks behind is harmless but accumulates; a stale entry point next to new
chunks is not, because the hashes will not match.

## Attribution

The free build renders a small RevoGrid attribution inside the grid. The `hideAttribution`
property exists, and its own documentation says:

> Please only hide the attribution if you are subscribed to Pro version

So it is deliberately **left on**. If a Pro subscription is bought, set `hideAttribution` on the
element in `view-grid.js` — that is the only change needed.

## No CDN

Nothing here is fetched at runtime from anywhere but this app's own origin, which
`test_no_blade_view_references_a_cdn` enforces for the Blade side.
