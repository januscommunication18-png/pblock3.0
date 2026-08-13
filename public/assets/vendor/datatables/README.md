# DataTables — vendored

`datatables.net` **3.0.1**, MIT licensed. The Views spreadsheet grid (`view-grid.js`) is built
on it.

## What is here

| File | Source |
|---|---|
| `datatables.min.js` | `node_modules/datatables.net/js/dataTables.min.js` — the core library |
| `datatables.min.css` | `node_modules/datatables.net-dt/css/dataTables.dataTables.min.css` — the default styling |

Two files, and that is genuinely all of it: **DataTables v3 is dependency-free.** jQuery was
required up to v2 and is not required here. The UMD wrapper falls through to
`window.DataTable`, so it loads as a plain `<script>` — no module, no import map, nothing
fetched at runtime.

The `datatables.net-dt` **JavaScript** is deliberately *not* vendored. Its whole body is
`return t` — a no-op that exists to satisfy the module graph in a bundler. Only its stylesheet
carries anything.

The stylesheet contains no `url()` references, so there are no fonts or images to chase.

## What is deliberately NOT used

**DataTables Plus** (Editor, CardView) — commercial, $219/developer plus $88/year. Editor is
the in-cell editing UI, and this app must not have one: a Views cell opens the *app's* picker,
which writes through the work item endpoint behind all six of the permission checks in
Views §11.3. An editable grid cell would be a second, weaker path to the same data.

No extensions are vendored either. FixedColumns, ColReorder and Scroller are MIT, but the grid
does not need them — pinned columns are `position: sticky`, reordering belongs to the Columns
panel, and paging is server-side. Every extension is another file to keep in step with the
core for a behaviour already built.

## Updating

```bash
npm install --no-save datatables.net@<version> datatables.net-dt@<version>
cp node_modules/datatables.net/js/dataTables.min.js        public/assets/vendor/datatables/datatables.min.js
cp node_modules/datatables.net-dt/css/dataTables.dataTables.min.css public/assets/vendor/datatables/datatables.min.css
```

Then re-check `public/assets/css/views.css`: it is loaded **after** this stylesheet and relies
on that order rather than on out-specifying it. `ProjectViewTest` asserts the order.
