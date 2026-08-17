# Views — Spreadsheet-Style Project Views

Source: `ProjectBlock_View_Requirements.md` (12 Aug 2026). Section references below (§n) are to
that document.

> **This document covers Slice 1 — the View engine.** Phase 1 of the source spec is roughly six
> tables plus sharing, publishing, magic links and a public page; CLAUDE.md §6 asks for small
> focused changes, so it is being built in three slices. Slices 2 and 3 are scoped at the
> bottom and are **not** built yet.

---

## Requirement

A project can hold several saved, configurable, spreadsheet-style Views of its work items. Each
row is one work item (§7.1); the columns are chosen by the user from work item fields and from
the project's *enabled* features — Epic, Cycle, Module, Estimation, Labels, members and dates
(§9). Columns are split into **Fixed** (pinned left, always visible) and **Scroll** (scroll
horizontally past them), and that split, the order, the widths and the visibility all persist
with the View (§8, §13).

Users with the right permission edit work item data inline in the grid (§11); permission to
change *the View's configuration* is a separate thing from permission to change *the data*
(§14, AC-9).

---

## Decisions taken before building

Recorded here because each one closes an ambiguity or a gap in the source spec.

| # | Topic | Decision |
|---|---|---|
| V1 | Grid engine | ~~Tabulator 6.3.1~~ → **RevoGrid 4.25.2** (`@revolist/revogrid`, MIT, vendored). Superseded — see V1a. |
| V1a | Grid engine (revised) | **RevoGrid**, at the product owner's direction. §6 names Glide Data Grid as the reference; Glide is React-only and this app is Vue-in-Blade, so it was never available. RevoGrid is a web component built for spreadsheets: pinned columns, resize, reorder and virtual scrolling are properties rather than things coaxed out of a list grid, and it measures itself — which removed a run of Chrome-only height bugs caused by Tabulator caching a container height taken before the flex layout had settled. The cost is that the Views grid and the Work Items list now run different engines. That is mitigated, not eliminated: cells are still rendered by the shared helpers in `work-item-ui.js`, so a chip means the same thing in both. |
| V2 | Custom / project fields (§9.1) | **Out of scope, because there is nothing to map.** The application has no custom-field system; §9.1 assumes one exists. The field catalog is built as a registry so custom fields become entries in it later without the grid, the column model or the API changing. |
| V3 | Filters, sorts, groups (§12.2–12.4, `project_view_filters`, `project_view_sorts`) | **Deferred to Slice 2.** Slice 1 ships toolbar Search and per-column sort. Saved multi-condition filters are the same infrastructure the Work Items, Epic, Estimation and Labels screens have each deferred waiting for, so it gets designed once, deliberately, rather than being grown here. **→ That design is now [filters.md](filters.md)**, driven by the Work Items screen. A saved View persists the same `{category: [values]}` shape the filter URL already carries and hands it to the same `FilterSet`; Slice 2 stores filters rather than defining them. |
| V4 | Row loading (§24) | **Server-side paged, fetched on scroll.** Tabulator's progressive load requests the next page as the user scrolls; search and sort are applied in SQL. The existing screens load one capped batch, which would not survive a large project. |
| V5 | Per-user View permissions (§14, §16) | **Slice 2.** Slice 1 derives the four §14 levels from the user's existing project role and the View's owner (see Permission model). No `project_view_permissions` table yet, and the derivation is written so the table becomes an override on top rather than a rewrite. |
| V6 | Publishing and magic links (§17–§20) | **Slice 3.** No `project_view_share_links` table, no public route, no tokens. The Settings toggles that only govern publishing are not rendered until they do something. |
| V7 | Dataset type | Only `work_items` (§6.2). The column is present and validated against a list so Epics/Modules/Cycles datasets (§28 Phase 3) are additive. |
| V8 | Density (§12.5) | Stored on the View and applied as a row height. Cheap, persisted with everything else, and it is the one toolbar control that changes nothing server-side. |

---

## User Roles

Slice 1 derives View permissions from project access; Slice 2 adds explicit per-user grants.

| Project role | Open View | Edit work item data | Edit View configuration | Manage (rename/delete/duplicate) |
|---|---|---|---|---|
| Workspace Owner / Admin | Yes | Yes | Yes | Yes |
| Project Admin | Yes | Yes | Yes | Yes |
| Project Lead | Yes | Yes | Yes | Yes |
| Member / Contributor | Yes | Per project authorization on the work item | Own Views only | Own Views only |
| Guest / read-only | Yes, project Views only | No | No | No |

Two rules hold throughout, from §11.3 and §16:

- **A View never widens access.** Rows are filtered by `WorkItemPolicy::view()` exactly as the
  Work Items list is, so a project set to *assigned work items only* shows a member only their
  own rows here too. A cell edit is authorised against the underlying work item, not against
  the View.
- **A private View is private.** Visibility `private` means only the owner can open it, whatever
  their project role — including workspace admins. Admins can still *delete* one (§14.4
  management), because an abandoned private View otherwise cannot be cleaned up, but deleting
  is not reading.

---

## Database Fields

Two new tables. Both tenant-scoped per CLAUDE.md §7, following `project_pages`.

### `project_views`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | string, FK `tenants` | `BelongsToTenant` |
| `project_id` | FK `projects`, cascade | §4.6 — a View belongs to one project |
| `name` | string(120) | §6.1 |
| `dataset_type` | string(32), default `work_items` | V7 |
| `visibility` | string(16) — `private` \| `project` | §6.3 |
| `owner_user_id` | FK `users`, nullOnDelete | §5.2 owner; a deleted user must not delete the View |
| `density` | string(16), default `standard` | §12.5 |
| `is_active` | boolean, default true | §22.1 |
| `created_by`, `updated_by` | FK `users`, nullOnDelete | |
| timestamps, softDeletes | | §4.2 — disabling View keeps everything |

Index: `(project_id, visibility, updated_at)` — the listing query.

### `project_view_columns`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | string, FK `tenants` | |
| `project_view_id` | FK `project_views`, cascade | |
| `source_type` | string(32) | `work_item` \| `epic` \| `cycle` \| `module` \| `estimate` \| `label` \| `member` |
| `source_field` | string(64) | the catalog key |
| `display_name` | string(120), nullable | §10 alias; null means use the catalog label |
| `position_type` | string(8) — `fixed` \| `scroll` | §8 |
| `sort_order` | unsigned int | within its position type |
| `width` | unsigned int, nullable | §10; null means the catalog default |
| `is_visible` | boolean, default true | §10 |
| `is_editable` | boolean, default true | §10 — a *ceiling*, never a grant (see below) |
| `formatting_json` | json, nullable | §10, unused in Slice 1 |
| timestamps | | |

Unique: `(project_view_id, source_type, source_field)` — §8.3, a column appears once per View.
Index: `(project_view_id, position_type, sort_order)` — the render order.

**No `is_available` column.** §9.3 requires that a column whose feature is later disabled keeps
its configuration and comes back on re-enable. Storing availability would mean writing to every
affected row on each feature toggle, and going stale if that write were ever missed. Instead
availability is *derived* at read time from `ProjectFeatureState`, so it is always current and
a disable writes nothing.

### `project_view_favorites`

`(id, tenant_id, project_view_id, user_id, timestamps)`, unique on `(project_view_id, user_id)`
— §5.2's favourite indicator, which is per user and so cannot live on the View.

---

## Business Rules

1. **Feature gate.** `views` joins the feature registry in `config/projects.php`, default off,
   `confirm_disable` true. The Views tab and every route go through `ProjectFeatureState`, so
   disabled-with-history opens read-only and disabled-never-used hides the tab — the behaviour
   every other feature already has (§4.2 "existing Views shall remain stored").
2. **Settings (§4.2).** Slice 1 renders *Enable View*, *Allow Project Views* and *Allow Private
   Views*. The three publishing settings arrive with Slice 3; a toggle that governs nothing is
   worse than an absent one. Turning off *Allow Private Views* does not convert or delete
   existing private Views — it stops new ones (same non-destructive instinct as §9.3).
3. **Default columns (§6.4).** A new View is seeded with Fixed = Identifier, Title, Status and
   Scroll = Priority, Assignee, Start date, Due date, plus Epic / Module / Cycle / Estimate /
   Labels **for whichever of those features the project has enabled at that moment**.
4. **Field catalog (§9).** One registry, `ViewFieldCatalog`, is the single answer to "what can
   be a column?" — label, source, data type, the feature it requires, whether it is editable,
   default width, and how it renders. The Add Column selector, column validation, the row
   payload and the inline-edit endpoint all read it, so a field cannot exist in one and not
   another.
5. **Feature-aware availability (§9.2, §9.3).** A field whose feature is off cannot be *added*.
   A column already saved for that field is **kept, in order, with its settings**, and marked
   unavailable; the grid renders it greyed with an em dash and it is not editable. Re-enabling
   restores it with nothing to migrate.
6. **`is_editable` is a ceiling, not a grant (§11.3).** A column marked editable is still
   subject to: the field being editable at all in the catalog, the feature being on, the user
   holding data-edit permission, and `WorkItemPolicy::update()` on that row. All six §11.3
   checks run server-side on every cell write; a manipulated client changes nothing.
7. **Read-only fields (§11.2).** Identifier, created at/by, updated at/by and anything derived
   are catalog-level read-only and cannot be made editable by configuration.
8. **Deleted related records (§25).** A work item pointing at a deleted Epic/Cycle/Module shows
   an empty cell, never an error and never a broken grid.
9. **Duplicate (§5.3).** Copies the View and all its columns; the copy is owned by whoever
   duplicated it, is named "<name> (copy)", and is always created **private** regardless of the
   source's visibility — sharing is an act, not something inherited by accident.
10. **Inline edits are ordinary edits (§11.4).** They go through the existing `WorkItemUpdater`,
    so activity history, notifications and every validation rule apply unchanged. The View adds
    no second write path.

---

## API

All under `/projects/{project}/views`, `auth` + `workspace.tenancy`, authorised by
`ProjectViewPolicy`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/views` | Listing screen (§5) |
| POST | `/views` | Create (§6) |
| GET | `/views/{view}` | Open a View (grid screen) |
| PATCH | `/views/{view}` | Rename, visibility, density (§5.3) |
| POST | `/views/{view}/duplicate` | §5.3 |
| DELETE | `/views/{view}` | Soft delete (§5.3) |
| POST | `/views/{view}/favorite` | Toggle (§5.2) |
| GET | `/views/{view}/rows` | **Paged rows** — `?page=`, `?q=`, `?sort=`, `?dir=` (V4) |
| GET | `/views/{view}/fields` | The catalog, filtered to enabled features (§9) |
| POST | `/views/{view}/columns` | Add a column (§9) |
| PATCH | `/views/{view}/columns/{column}` | Width, alias, visibility, editability (§10) |
| DELETE | `/views/{view}/columns/{column}` | Remove from View (§8.1/§8.2) |
| PUT | `/views/{view}/columns/order` | The whole Fixed/Scroll layout after a drag (§8.3) |
| PATCH | `/views/{view}/rows/{workItem}` | **Inline cell edit** (§11) |

`columns/order` takes the entire arrangement in one request rather than a message per moved
column: a drag between cards changes one column's `position_type` *and* the `sort_order` of
everything around it in both cards, and sending that as several requests invites a half-applied
layout if one fails.

---

## UI Requirements

### Views tab (§4.1)

`config/projects.php` already reserves a `views` tab at `status => 'soon'`; it becomes active
and feature-gated like Epics, Cycles and Modules.

### Listing screen (§5)

A table of the user's available Views: name, visibility, owner, dataset, last modified,
favourite star, and a `⋯` menu (Open · Favorite · Rename · Duplicate · Delete — Share and
Publish appear with their slices). Empty state per §25: what a View is, and a Create action.

### Create dialog (§6)

Name, Dataset (Work Items, the only option, shown so the choice is visible rather than
implied), Visibility (Private / Project, each offered only if the project setting allows it).

### The grid (§7)

Tabulator with headers visible, `frozen: true` on every Fixed column, `movableColumns`,
`resizableColumns`, progressive row loading, and row height from the View's density. Column
resize and column move persist immediately — the grid is the configuration surface, so a drag
that did not stick would read as a bug.

Chips render exactly as the Work Items list renders them, from the same `work-item-ui.js`
helpers, so status, priority, assignee, labels and estimate look identical in both places.
Unavailable columns (rule 5) render greyed with a tooltip naming the disabled feature.

### Column configuration (§8) — the two-card model

A panel with **Fixed Columns** and **Scroll Columns** cards, drag within and between them, a
remove control, a settings control per column, and **+ Add Column** opening the searchable
field selector grouped by source (§9.1) with disabled-feature groups shown but not selectable
and saying why.

### Inline editing (§11)

Click a cell in an editable column to edit in place, reusing the Work Items pickers (state,
priority, assignee, labels, cycle, epic, module, estimate, dates). Saving state, then success
or a revert-with-message on rejection (§11.4).

### Toolbar (§12)

Slice 1: Search, Columns (opens the config panel), Density, Refresh. Filter, Sort, Group,
Export, Share and Publish arrive with their slices rather than as dead controls.

---

## Real-Time Requirements

None in this slice. Inline edits update the editing user's own grid from the response, exactly
as the Work Items list does. Live updates from *other* users are the subject of
`realtime-work-items.md`, which is specified and awaiting approval; if it is built, the Views
grid becomes a second subscriber to the same channel with no change here.

---

## Queue Requirements

None. Every operation is a small synchronous read or write. Notifications raised as a
side-effect of an inline edit queue exactly as they already do — the View adds no new write
path (rule 10).

---

## Audit Requirements

§21's inline-edit half is already satisfied: edits go through `WorkItemUpdater`, so work item
activity and history record them with no extra work.

The View-level audit events in §21 (created, renamed, duplicated, configuration changed,
deleted) are **not** implemented in Slice 1 — the events that most need auditing are the
sharing and publishing ones, which land in Slices 2 and 3, and the log is better built once
against the full set than grown twice.

---

## Acceptance Criteria

Numbered against §29 where they correspond.

**Configuration (§27, AC-1..5, AC-10)**

1. A permitted user creates a Work Item View; it appears in the listing. *(AC-1)*
2. A new View arrives with the §6.4 default columns, including only the feature columns the
   project has enabled.
3. Add Column offers a field, adds it, and it appears in the grid. *(AC-2)*
4. A column moves Fixed → Scroll and Scroll → Fixed and stays there after reload. *(AC-3)*
5. Fixed columns remain visible while scrolling horizontally. *(AC-4)*
6. Column order and width survive closing and reopening the View. *(AC-5)*
7. Rename, duplicate and delete behave per §5.3; a duplicate is private and owned by the
   duplicator.
8. Several Views coexist in one project, each with its own configuration. *(AC-10)*

**Feature mapping (§27, AC-6, AC-17)**

9. Epic / Cycle / Module / Estimation fields are offered only when that feature is enabled.
   *(AC-6)*
10. Disabling a feature that a saved column uses **keeps** the column, its position, order and
    width, marks it unavailable, and re-enabling restores it. *(AC-17)*
11. A work item whose Epic was deleted shows an empty cell, not an error. *(§25)*

**Editing and permissions (§27, AC-7..9, AC-18)**

12. An authorised user edits Title, Status, Priority, Assignee, dates, Labels, Epic, Module,
    Cycle and Estimate inline, and it persists. *(AC-7)*
13. A read-only user's cell edit is rejected by the server, and the cell reverts. *(AC-8)*
14. A user with data-edit but not config-edit permission can change a cell and **cannot** add a
    column, and vice versa. *(AC-9)*
15. Editing a column whose feature is disabled is rejected server-side even when the request is
    made directly. *(§11.3, AC-18)*
16. A cell edit on a work item the user cannot update is rejected even though they can open the
    View. *(§11.3)*
17. An inline edit appears in the work item's activity history. *(§11.4)*
18. A private View is invisible to another project member, including a workspace admin.
19. Rows honour *assigned work items only*: a member sees only their assigned rows.

**Feature gate and loading**

20. With `views` disabled and no Views ever created, the tab is hidden; disabled with Views
    stored, the tab opens read-only and every stored View survives. *(§4.2)*
21. The rows endpoint pages: a project with more items than one page returns the first page and
    the next page on request, with search and sort applied in SQL. *(§24)*

Existing suite must stay at its current 415 passing / 7 known pre-existing failures.

---

## Files

| File | Change |
|---|---|
| `database/migrations/*_create_project_views_table.php` | new |
| `database/migrations/*_create_project_view_columns_table.php` | new |
| `database/migrations/*_create_project_view_favorites_table.php` | new |
| `app/Models/ProjectView.php`, `ProjectViewColumn.php` | new |
| `app/Policies/ProjectViewPolicy.php` | new |
| `app/Services/ViewFieldCatalog.php` | new — the §9 registry |
| `app/Services/ViewGridPayload.php` | new — paged rows in the configured shape |
| `app/Services/ViewColumnLayout.php` | new — defaults, reorder, availability |
| `app/Http/Controllers/Project/ProjectViewController.php` | new |
| `app/Http/Controllers/Project/ProjectViewGridController.php` | new — rows + inline edit |
| `app/Http/Requests/Project/*` | new — store/update View, add/update column, cell edit |
| `config/projects.php` | `views` feature, settings nav entry, tab → active |
| `routes/project.php` | the route block |
| `resources/views/projects/views.blade.php` | new — listing + grid host |
| `resources/views/projects/settings/views.blade.php` | new — Settings → View |
| `public/assets/js/projects/views.js` | new — the screen |
| `public/assets/js/projects/view-grid.js` | new — the Tabulator wrapper |
| `public/assets/css/views.css` | new |
| `tests/Feature/Project/ProjectViewTest.php` | new — configuration, feature mapping, gate |
| `tests/Feature/Project/ProjectViewEditingTest.php` | new — inline editing and permissions |

---

## Slices 2 and 3 (not built)

**Slice 2 — filters, sorts and sharing.** `project_view_filters`, `project_view_sorts`,
`project_view_permissions`; the Filter / Sort / Group toolbar controls (§12.2–12.4); the Share
dialog (§16) with Can View / Can Edit Data / Can Edit View / Manage, capped so a View
permission can never exceed the recipient's project access. The filter engine is designed to
serve the Work Items, Epic, Cycle and Estimation screens too, which have all been waiting on it.

**Slice 3 — publishing and magic links.** `project_view_share_links`; publish options (§17),
cryptographically strong hashed tokens (§18.1, §20), the public read-only page (§18.3),
password protection with rate limiting (§18.4), expiry (§18.5), link management and regenerate
(§19), the remaining Settings toggles (§4.2), and the §21 audit log covering the whole set.

---

## Build notes — what the implementation taught us

Recorded per CLAUDE.md §4. Each of these is a place the code differs from the plan above, or a
bug the tests caught.

**Labels is seeded into a new View's default columns.** The spec's §6.4 list treats Labels like
Epic/Cycle/Module — "if enabled". It always is: Labels is the one optional feature whose catalog
default is `true`, so every project gets the column. The rule is unchanged (seed a feature
column only when its feature is on); the outcome differs because the feature is on.

**`LIKE` needs an explicit `ESCAPE` clause, and its absence is invisible on MySQL.** Search
escapes `%` and `_` so a user typing "100%" searches for a per-cent sign rather than matching
everything. MySQL treats backslash as LIKE's escape character by default, so that worked in
development. SQLite — which the suite runs on — has *no* default escape character, so `\%` was
a literal backslash followed by a wildcard and matched nothing. Naming the escape character
explicitly is portable across MySQL, SQLite and Postgres, and is the query-level version of
what CLAUDE.md §6 asks of migrations. Pinned by `test_search_treats_wildcards_as_text`.

**The cell-edit column check compares against the keys the CLIENT sent.** §11.3 check 5 requires
that the payload match the named column exactly. Comparing the *post-validation* payload broke
an ordinary date edit: `UpdateWorkItemRequest::prepareForValidation()` legitimately merges the
stored half of a date pair so `after:start_date` compares against reality. So the submitted keys
are captured before the parent's hook runs.

**Tabulator is the vendored build, via `partials/work-item-assets`.** The first draft loaded it
from unpkg; `test_no_blade_view_references_a_cdn` refused it, correctly — this app vendors its
front-end and fetches nothing at runtime. Reusing the existing partial also inherits the one
stylesheet order that works, which that partial exists to guarantee.

**The bootstrap is a PROP.** `PB.boot` passes the server payload as a prop, not on
`$root.$options`. Reading it from the wrong place yields an empty object and every client-side
default wins — which presented as a Views screen with no data and no buttons rather than as any
kind of error. Worth knowing for the next screen.

**Four icons were missing from the registry** (`columns`, `grip-vertical`, `chevron-up`,
`arrow-right`) and are now in `IconRegistry`. `wiIcon` warns and returns an empty string for an
unknown name, so a missing icon quietly costs a control its glyph rather than failing loudly.

**Project roles are `admin` / `contributor` / `commenter` / `guest`.** `member` is a *workspace*
role; using it as a project role produces someone who cannot edit work items, which is a
confusing way to fail a permission test.

### Verifying by hand

Views is off by default. **Project → Settings → View → enable it**, then the Views tab appears.
Create a view, and check: columns drag between the Fixed and Scroll cards and stay put after a
reload; the fixed ones stay visible while scrolling right; Add Column greys out Epic/Cycle/
Module/Estimation groups until you enable those features; a cell in an editable column opens its
picker and saves; switching a feature off leaves its column in place, greyed, and switching it
back on restores it.

### The visibility sub-settings were being cleared by the dependent cascade

Reported as "Visibility — I don't see any value" on the create dialog, and it was not a
rendering problem: the project had `view_private: false, view_project: false` stored, so there
was genuinely nothing to offer.

`view_project` and `view_private` arrived as `requires => views` sub-features, the same shape
`parallel_cycles` uses under Cycles. But `ProjectSettingsController::toggleFeature` forced
**every** dependent to `false` whenever its prerequisite was switched off — and Views defaults
to off, so any project that toggled it lost both. Views ended up switched on with no visibility
a new view could legally use: the dialog offered nothing, and the server refused every value it
was sent. The feature was on and unusable.

The distinction the cascade was missing:

- A dependent that **defaults OFF** is an *extra* the user opted into — parallel cycles.
  Clearing it is honest, because turning it back on is a deliberate act.
- A dependent that **defaults ON** is a *permission its parent grants*. Clearing it disables
  the parent from the inside. Its stored value is unreachable while the parent is off anyway
  (every check reads both), so there was nothing dishonest to render and nothing to clear.

So the cascade now clears only dependents that default OFF. Both halves are pinned:
`test_toggling_views_off_and_on_leaves_both_visibilities_allowed` and
`test_an_opt_in_sub_feature_is_still_cleared_when_its_prerequisite_goes_off`.

Projects already carrying the wrong values are fixed by
`2026_09_18_000004_reset_view_visibility_flags_on_projects`, which **removes** the two keys
rather than setting them true — `featureFlags()` merges catalog defaults for anything absent,
so removing the key is what restores the default, and it will not overwrite a project that
deliberately turns one off later.

The dialog also now says so when it happens: with neither visibility allowed it explains where
to turn one on, instead of rendering an empty radio group.

### The dialogs use the app's shared components

The first draft hand-rolled its own backdrop, panel, header and footer. They are now
`<pb-modal>` (create, rename) and `<pb-confirm>` (delete), with `.pb-input` on the fields — the
same components every other dialog in the app uses, so they inherit the standard header,
padding, footer row, Escape-and-backdrop closing, and the teleport to `<body>` that stops a
scrolled grid clipping them.

### The column sort direction was reversed, and it was not a cosmetic bug

`ProjectView::columns()` ordered `orderByDesc('position_type')`. `'fixed' < 'scroll'`
alphabetically, so **descending put every Scroll column ahead of every Fixed one**.

That is not merely a wrong order. §7.2 derives the grid's frozen count from the number of Fixed
columns, and the boundary is *positional* — Tabulator pins the first N columns of whatever it
was sent. So the grid pinned the first three **Scroll** columns and left the real Fixed ones
(Identifier, Title, Status) scrolling off to the right, while the config panel's two cards
showed the correct arrangement. That mismatch is also why dragging a column between cards
"didn't reflect in the grid": both were right about different things.

Fixed by sorting ascending. Pinned by `test_fixed_columns_always_come_before_scroll_columns`,
which asserts every fixed column precedes every scroll one *and* that `frozen` matches the
leading run — the second half is what makes it a test about the boundary rather than about
sorting.

### Grid chrome

- **One hairline, never two.** Tabulator draws borders on cells, header cells *and* rows, and
  this file was drawing them again. Two 1px lines a pixel apart read as a gap, which is the
  "1 pixel spacing between the borders" that showed up throughout. Tabulator's are now off and
  each cell owns its right and bottom edge only, so adjacent cells share one line.
- **The frozen edge is a shadow, not a second border.** The pinned cell already has a right
  border; adding a border *and* a shadow is what produced the doubled edge. The last pinned
  column is marked by the grid with `vg-frozen-edge` rather than found in CSS with
  `:last-of-type` — Tabulator renders frozen cells into their own wrapper, and that element
  order is its business, not something to style against.
- **The last row casts a shadow**, so the grid ends deliberately instead of stopping wherever
  the data ran out against the scrollbar.
- **The Columns panel reads as a panel**, with a left border *and* a shadow. A border alone was
  lost among the grid's own column lines.
- **Column changes use `setColumns()`** instead of destroying and rebuilding the table. The
  rebuild threw away the scroll position on every drag and gave itself a chance to race the
  `$nextTick` that started it. Full rebuild remains the fallback.

### External (full-page) view

`GET /projects/{project}/views/{view}/external` renders the same screen and the same bootstrap
with the application chrome removed — no topbar, no workspace rail, no sidebar, no project tabs.
Just a slim header carrying Back, Search and Close, and grid below it. Reached from the toolbar.

It is a separate Blade host rather than a flag on `projects/views.blade.php`, because the
difference is entirely *which chrome partials are included*, and expressing that as `@if`
around four includes is how a layout ends up with two half-maintained states. The Vue screen is
the same file; only `external` differs.

**"External" is about chrome, not access.** The route is behind the same auth and the same
policy as any other — `test_the_external_page_renders_the_view_without_the_app_chrome` asserts
a private view still 404s for someone else there. Anonymous access is a published link with a
token, which is Slice 3 — and that page will start from this layout rather than inventing one,
since §18.3 describes the same shape.

Close falls back to the View's own URL: `window.close()` only works on a window that a script
opened, so a page cannot close a tab the user opened themselves.

### Row height matches the work items list

Standard density is **44px**, which is what `work-item-list.js` sets `rowHeight` to. Both grids
render the same rows from the same chip helpers in `work-item-ui.js`, so a work item that
changed height depending on which screen you opened it from would give away that they are two
grids — and the point of sharing the renderers is that it should not be visible. Compact (36)
and Comfortable (56) are steps either side of that rather than an independent scale.

`test_the_standard_density_matches_the_work_items_list_row_height` reads the number out of
`work-item-list.js` rather than hard-coding 44, so changing one grid's row height either moves
the other or fails.

### The scrollbar was landing in the middle of the grid

Reported as "the dark color at the third row". It was the horizontal scrollbar: Tabulator sized
its table holder to the **content**, so with three rows in a tall grid the scrollbar was drawn
immediately under the last row — a thick grey band across the middle of the screen with empty
white below it, which reads as a stripe *inside* the table.

`.vg-grid.tabulator` is now a flex column with the holder as the growing child, so the scrollbar
sits at the bottom edge of the grid however few rows there are. `min-height: 0` on the holder is
the half that is easy to miss: without it a flex child refuses to shrink below its content and
the holder pushes the scrollbar off the bottom instead. Note `.vg-grid.tabulator` rather than a
descendant — Tabulator adds its class to the element it is handed.

Two things follow from the fix. The last row's shadow now has somewhere to fall (the holder is
taller than the rows), so the grid ends deliberately instead of stopping wherever the data ran
out. And the scrollbar itself is restyled: macOS draws these as a heavy dark bar when "always
show scrollbars" is on, which against a white grid was the loudest thing on screen — and sat
exactly where the eye goes looking for the end of the data.

### Opening the Columns panel re-formatted the table

Tabulator writes pixel widths into the DOM once and does not reflow when its **container**
changes — it watches the window, not the element. The Columns panel takes 300px off the grid
beside it, so the header stayed laid out for the old width while the body used the new one. The
two desync, and the table looks as though it has re-formatted itself.

`<view-grid>` now keeps a `ResizeObserver` on its own box and redraws, debounced. An observer
rather than a watcher on `config.open` in the screen, because the panel is not the only thing
that resizes this box — collapsing the app sidebar and resizing the window do too, and the grid
should not need to be told about any of them.

`redraw()`, not `redraw(true)`: the full form re-runs the layout from scratch and would recompute
widths the user set. The plain form re-measures the container and re-aligns the header with the
body, which is the part that actually broke.

### The grid sized to its content on first paint

`<view-grid>` nested the Tabulator element inside a wrapper and sized it with `h-full`. A
percentage height on a flex item is resolved against a height the flex layout has not settled
yet, so on first paint the grid took its content's height and only filled the screen once
something else forced a reflow — opening the Columns panel, or resizing the window.

`<wi-list>` does not have this problem because its template root *is* the Tabulator element, and
the host hands it `flex-1 min-h-0`. `<view-grid>` now matches: the Tabulator element is itself
the growing flex child. `flex-1` asks for the space rather than measuring for it, so the grid is
full height on the first frame.

The Views toolbar is also `h-12` now, the same as the Work Items toolbar, so the two screens
line up rather than differing by 8px for no reason.

### Resizing a column moved the header but not the rows

Two separate causes, both on the resize path.

**The body was never re-rendered.** Tabulator resizes the *header* cell live as you drag, but
the rows come from its virtual DOM and keep the width they were last rendered at — so the
header ended up a different width from the rows under it. `columnResized` now calls `redraw()`,
which re-renders the rows at the column's current width. Plain `redraw()`, not `redraw(true)`:
the full form re-runs layout and would recompute the width just dragged to.

**And the grid was rebuilding itself mid-drag.** The resize persisted the width, the server
answered with the whole column set, and the screen assigned it to `columns` — where a deep
watcher tore the columns down and rebuilt them from a value the grid already had, while the
pointer was still on the edge.

So the grid now watches a `layoutKey` — id, position, visibility, availability, editability,
label, type — rather than the columns deeply. Width is deliberately not in it: **Tabulator owns
the width while you are dragging it; everything else about a column is the server's.** And
`setColumnWidth` no longer applies the response back, it just updates that one column's width in
place, restoring the previous value and saying so if the server refuses (the bounds in
`UpdateViewColumnRequest`).

### Chrome came up short on load; Safari did not

Same page, two browsers, different result — and opening and closing the Columns panel "fixed"
it, which is the tell that a measurement was stale rather than a style being wrong.

Two things, both about *when* the height is decided.

**Tabulator writes a pixel `height` onto its table holder** — the height it measured when it was
constructed. The holder was `flex: 1 1 auto`, and a flex item with an `auto` basis takes its
basis *from the height property*, so that stale measurement won and the holder stayed short: the
horizontal scrollbar sat under the last row with empty white below it. With `flex-basis: 0` the
inline height is ignored for sizing and the holder grows into whatever is left, correct on the
first frame whatever Tabulator measured. This is why it was Chrome-only in practice — Safari
happened to lay out before the measurement was taken, so the number written there was already
right.

**And the measurement itself was taken too early.** `mounted()` runs before Chrome has settled
the flex layout. `<view-grid>` now re-measures in `settle()`: two animation frames (the first
lets the browser run layout, the second measures what layout produced) and again after
`document.fonts.ready`, because a font swap changes text metrics and therefore row heights, and
Chrome will happily paint before the webfonts arrive.

`redraw(true)` is right in both places — startup and container resize — because nothing is being
dragged then, and the widths it re-applies are the persisted ones. It stays wrong on the
column-resize path, where the width being dragged is the one thing that must not be recomputed.

---

## The grid moved from Tabulator to RevoGrid

At the product owner's direction. Decision V1 is superseded by V1a above; this records what
changed and what deliberately did not.

**`@revolist/revogrid` 4.25.2, MIT, vendored** in `public/assets/vendor/revogrid/`. It is a web
component, so pinned columns, resize, reorder and virtual scrolling are properties rather than
behaviour coaxed out of a list grid — and it measures itself, which is what removed the run of
Chrome-only height bugs above. Those all had one root cause: Tabulator caches the container
height it measured at construction, and Chrome runs `mounted()` before the flex layout settles.
Every workaround on that list — the two-frame `settle()`, the `ResizeObserver`, the
`flex-basis: 0` on the table holder — is gone with the engine that needed them.

**What did not change:**

- **The cell vocabulary.** Chips still come from `work-item-ui.js`, the same helpers the Work
  Items list uses, injected as `innerHTML` from RevoGrid's cell templates. A status or a
  priority means the same thing wherever you see it. This is the mitigation for now running two
  grid engines, so `test_the_views_screen_loads_the_grid_and_the_shared_chips` pins it.
- **The component contract.** `<view-grid>`'s props, events and `updateRow()` are identical, so
  `views.js` barely moved — the sort handler stopped being a document-level listener (a
  Tabulator workaround, because its header was rebuilt on every column change) and became an
  ordinary event.
- **Every server-side rule.** No controller, policy, request or migration changed.

**What is better as a result:** columns and rows are now *computed properties* handed to the
element, so there is no "apply the change to the grid" step to forget — which is what allowed
the old version to rebuild itself in the middle of a resize drag. Pinning is per column
(`pin: 'colPinStart'`) rather than a boundary index the grid is told about separately, so a
mis-ordered column set can no longer pin the wrong ones.

### Things worth knowing

**Vendor the whole directory.** RevoGrid is a Stencil build: the entry registers the custom
elements and then lazily imports the other files beside it at runtime. Vendoring only the entry
gives a grid that loads, defines `<revo-grid>`, and then fails at the first dynamic import —
presenting as an *empty element*, not an error. That is the same failure the Jodit drop hit, and
`test_the_views_grid_bundle_is_vendored_with_its_chunks` now guards it. Chunk names are
content-hashed, so they cache-bust themselves; only the entry goes through `pb_asset()`.

**No shadow DOM.** Every RevoGrid component is flagged non-shadow, which is why the page's own
CSS reaches inside and why delegated `closest()` listeners work. Worth re-checking on an upgrade
— if that ever changes, the theme and the cell click handling both break at once.

**Vue must be told it is a custom element.** `app.config.compilerOptions.isCustomElement` now
matches `revo-*` / `revogr-*` in `PB.boot`. Without it Vue tries to resolve `<revo-grid>` as a
component and fights the element's own rendering.

**Properties, not attributes.** `columns` and `source` are arrays of objects carrying functions
(the cell templates); an attribute can only ever be a string, and Vue binds `:prop` on an
unknown element as an attribute. They are assigned to the element by hand.

**The attribution stays.** The free build renders a RevoGrid credit inside the grid, and the
`hideAttribution` property's own documentation says to hide it only with a Pro subscription.
It is left on and toned down with CSS. If a Pro licence is bought, that one property flips.

**Tabulator is still the Work Items list**, and is no longer loaded on the Views screen at all —
the chip vocabulary moved into its own `partials/work-item-chips` partial so a screen can reuse
the chips without taking a grid engine it does not use.

### ~~The row palette is quoted from the work items list~~ (superseded)

Superseded by the section below: the grid now runs RevoGrid's own `default` theme, so there is
no hand-matched palette and nothing to keep in step. The test that pinned it has been removed
along with the rules it described. Kept here only so the reasoning is not lost — if the app's
colours are wanted in the grid again, do it with the theme's public tokens rather than a second
set of rules.

<details><summary>The original note</summary>


Now that the two grids run different engines, nothing makes them agree except saying the same
thing twice — and the point of the swap is that the seam should not show. A row that shaded
differently depending on which screen you opened it from is exactly that seam.

So `views.css` names the list's values as variables, so they read as a quotation rather than as
a choice made twice:

| Variable | Value | Quoted from |
|---|---|---|
| `--vg-row` | `#fff` | `.wi-grid .tabulator-row` |
| `--vg-row-hover` | `#f8f9fa` | `.wi-grid .tabulator-row:hover` |
| `--vg-divider` | `#f1f2f4` | the row's `border-bottom` |
| `--vg-band` | `#f6f7f8` | the group-header band, reused for the column header |
| `--vg-band-hover` | `#eef0f2` | that band's hover |

Two details fall out of matching properly. The cell dividers use the row divider's `#f1f2f4` on
**both** axes, so the grid reads as one weight rather than heavy verticals against light
horizontals — the only line left at the stronger `#e5e7eb` is the header/body separation, which
is the one edge in the grid dividing two different *kinds* of thing. And there is no row
striping: the work item list does not stripe, and with column dividers already drawn a banded
background is two grid systems arguing.

`test_the_row_palette_matches_the_work_items_list` reads the hover and divider colours out of
`work-items.css` and asserts the variables still match, so changing one grid either moves the
other or fails. Verified by temporarily drifting one value and watching it fail.
</details>

### The grid uses RevoGrid's own `default` theme

At the product owner's direction: the look on rv-grid.com/demo, not an app-matched one.

The previous version restyled almost everything — header band, cell borders, row hover, pinned
edge, scrollbars, focus ring — against the work item list's palette. Every one of those rules
was `.vg-grid .rgCell`-shaped, which outranks the theme's own selectors, so the theme was being
loaded and then painted over. Those rules are gone.

`theme="default"` is named on the element rather than left off, so it reads as a decision on the
page instead of whatever the package happens to ship as its fallback.

**What `views.css` still carries**, because the theme cannot know about it:

- `.vg-cell` — the layout of *our* cell content. The theme paints the cell; this lays out the
  chips inside it.
- `.vg-editable` — the "this one can be edited" affordance, drawn from the theme's **own**
  tokens (`--revo-grid-row-hover`, `--revo-grid-border`) rather than a colour of ours, so it
  stays correct if the theme changes.
- `.vg-config` and `.vg-external` — the panel and the full-page chrome, which are not the grid.

**Row height is still the View's density** (§12.5), not the theme's default. That is a per-view
setting the user chose, and Standard is deliberately the work item list's 44px — dropping it
would have silently undone an earlier request. Everything else about how a row *looks* is the
theme's.

**If the app's colours are wanted here again**, the way to do it is the theme's public tokens —
`--revo-grid-header-bg`, `--revo-grid-row-hover`, `--revo-grid-cell-border` and the rest, set
once on `.vg-grid` — not a second set of rules fighting the first. That is the mistake this
change undoes.

### Keeping rows white

RevoGrid never paints an ordinary row — rows are transparent over the grid surface, which is
`--revo-grid-background` and already white in the `default` theme. What was shading them is the
**focused row**: `canFocus` is on for keyboard navigation (§7.1, §26), and clicking a cell to
open its picker left that row sitting at the theme's `rgba(233, 234, 237, .5)` afterwards.

So `--revo-grid-focused-bg` is set to `transparent` and the row stays white. Focus is not lost —
the focused *cell* still gets its ring, which is the part that says where you are, and keyboard
navigation is untouched.

Both values are set as **theme tokens** on `.vg-grid`, not as rules overriding the theme's own
selectors. That is the point of the previous section: a `.vg-grid .rgRow`-shaped rule would have
worked too, and would have started the same slide back into painting over the theme.

### Header and cell text on the same left edge

The `default` theme pads both the header cell and the data cell by `0 15px`. But our cell
content is a custom span (`.vg-cell`) that does its own padding, so a themed cell padding was
applied **twice** — while the header, which has no such span, was padded once. The result was
the header text sitting further in than the values under it.

Fixed with tokens, not overrides: `--revo-grid-cell-padding: 0` so `.vg-cell` is the only thing
that pads, and `--revo-grid-header-padding: 0 10px` to match it exactly. One number, in one
place, for both.

### If the grid still looks like the old one

`pb_asset()` cache-busts on file mtime, so `views.css` and `view-grid.js` update themselves —
but the vendored RevoGrid entry is a `type="module"` script, and a module already in the
browser's cache is not re-fetched by a soft reload. **Hard-refresh (Cmd/Ctrl + Shift + R)** after
an engine or theme change.

The quick way to tell which engine is actually rendering: the old grid drew `.tabulator-*`
elements, the current one draws `<revogr-data>` and `.rgCell`. If Elements shows `tabulator`,
the page is cached, not broken.
