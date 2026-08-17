# SortableJS 1.15.7 — vendored

Drag-and-drop for the Wiki's page tree (docs/features/wiki.md): pages are reordered and nested
under one another by dragging, using Sortable's nested-list mode.

Vendored rather than fetched, like every other dependency here — nothing is loaded from a CDN
at runtime, and `tests/Feature/SelfHostedAssetsTest.php` fails the build if anything is.

## Updating

```
npm install --save-dev sortablejs@<version>
cp node_modules/sortablejs/Sortable.min.js public/assets/vendor/sortable/Sortable.min.js
```

The devDependency exists only so the copy above is reproducible; nothing bundles it, and the
served file is the one in this directory.

## Why nested lists and not a table

Sortable nests by moving a dragged element between `<ul>`s, so the tree has to BE nested
markup. The page list still reads as a table — the columns are a CSS grid on each row — but it
is a list of lists underneath, which is what makes "drop this page onto that one" possible at
all.
