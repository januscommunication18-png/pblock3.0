# Wiki Cover Page

Source: *ProjectBlock 3.0 — Wiki Cover Page Requirements v1.0* (`FR-WC-001` … `FR-WC-033`).
Extends [Wiki & Knowledge Management](wiki.md); read that first — this feature adds no access
system of its own and reuses that one's collections, groups, pages and permissions entirely.

Slice 1 is built, plus the landing page out of Slice 3. This file grows a **Delivered** section
per slice the way `wiki.md` does.

## Requirement

Give a collection a **front door**: a configurable landing page carrying a title, a short
description, a search box, and a grid of **section cards** that lead into the collection's
sections and pages. Plus three reading aids the source document bundles with it — **Previous /
Next**, **On this page**, and the content column's **alignment**.

The Cover Page is a presentation layer. It does not replace the editor, the group hierarchy or
the reader; it is what somebody sees *before* they have chosen what to read.

## The one decision that reshapes the source document

The source specifies a **workspace-level** cover: `wiki_cover_settings.workspace_id`, opened by
clicking **Wiki** in the rail, configured at *Wiki → Settings → Cover Page*, with cards pointing
at any collection or page.

**Decided otherwise: a cover belongs to a COLLECTION**, and is configured from a **Cover card
pinned at the top of that collection's Group view**.

Why the placement forces the scope: the Group view is per-collection (`wiki-collection.js`,
`wiki_collection_groups.wiki_collection_id`). A card sitting at the top of it, above that
collection's sections, can only sensibly be editing *that collection's* cover — a workspace-wide
setting reachable from inside every collection is one setting with N front doors, each of which
looks local and is not.

What it buys: a published collection at `/{workspace}/{slug}` gets a real landing page instead
of dropping a stranger straight into whichever page happens to be first. That is the case the
reader (Slice 9) currently has no answer for.

Everything below is the source document re-read under that decision. Departures are listed in
**Decisions** at the foot, each against its `FR-WC-` number.

## User Roles

| Role | Can |
|---|---|
| Workspace Owner / Admin | Configure the cover of any collection |
| Collection creator | Configure their collection's cover |
| Collection member — Edit | Configure the cover |
| Collection member — Read Only | See the cover; see only the cards they can open |
| Workspace Member | See the cover of a public collection |
| Signed-out reader | See the cover of a **published** collection at its public URL |

Configuring uses the **`canEdit`** gate — the one `GroupController::guard()` and
`PageController` already enforce (creator, `manageSettings`, or an `edit` invitation), not the
tighter `canManage` that publishing and access use.

A cover is arrangement, like sections: whoever may add a page may arrange them. It reaches the
public only through a **publish**, which already needs `canManage`, so the loose gate cannot put
anything on the internet by itself.

## Database Fields

### `wiki_covers` — one row per collection, TENANT-SCOPED

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | fk → workspaces | `BelongsToTenant`, like every other Wiki table |
| `wiki_collection_id` | fk, **unique**, cascadeOnDelete | one cover, one collection |
| `is_enabled` | boolean, default `false` | see WCOV-1 |
| `title` | string(100) nullable | required when enabled |
| `short_description` | string(300) nullable | |
| `global_search_enabled` | boolean, default `true` | |
| `previous_next_enabled` | boolean, default `true` | |
| `on_this_page_enabled` | boolean, default `true` | |
| `content_alignment` | string(10), default `center` | `left` / `center` / `right` |
| `card_layout` | string(10), default `auto` | `auto` / `two` / `three` |
| `created_by`, `updated_by` | fk → users nullable | |
| timestamps | | |

### `wiki_cover_sections` — the cards, TENANT-SCOPED

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | fk → workspaces | |
| `wiki_cover_id` | fk, cascadeOnDelete | |
| `visual_type` | string(10), default `none` | `icon` / `banner` / `none` |
| `icon_key` | string(60) nullable | a name `App\Support\IconRegistry` knows |
| `banner_url` | string nullable | see WCOV-9 |
| `banner_alt_text` | string(150) nullable | |
| `title` | string(80) | required |
| `description` | string(200) nullable | |
| `destination_type` | string(20) | `group` / `page` |
| `destination_id` | unsignedBigInteger | id of a group or page **of this collection** |
| `position` | integer, default 0 | |
| `created_by`, `updated_by` | fk → users nullable | |
| timestamps | | |

No foreign key on `destination_id` — it points at two different tables. Validity is enforced on
write (WCOV-7) and re-checked on read (WCOV-8), because a destination can be deleted from
somewhere that has never heard of covers.

**Naming, against the source:** `position`, not `sort_order` — `wiki_collections`, `wiki_pages`
and `wiki_collection_groups` all order by `position`, and a fourth table ordering by a fifth
name means every query that touches two of them has to remember which is which. `banner_url`,
not `banner_asset_id` — there is no assets table for that key to reference; project and profile
covers already store a URL string.

## Business Rules

- **WCOV-1 — off until switched on, and switching it off deletes nothing.** Default `false`, so
  a collection that never configures one behaves exactly as it does today. Disabling keeps the
  title, description, cards and every toggle; re-enabling restores them untouched. Same rule
  `WIKI-D4` states for the Wiki itself. (`FR-WC-001`)
- **WCOV-2 — a cover cannot be enabled without a title.** Refused at the request, not merely
  discouraged in the form: an enabled cover with no heading is a blank page where the front door
  should be. Titles are trimmed on save. (`FR-WC-002`, §9)
- **WCOV-3 — the cover is the collection's landing page, for readers.** With a cover enabled,
  the reader (`wiki/reader.blade.php`, preview and published alike) opens on the cover; choosing
  a card or a nav link opens a page. Without one, it opens on the first page, as it does now.
  `?page=` still goes straight to that page — a link somebody sent must not be intercepted by a
  landing screen. (`FR-WC-001`, Workflow B)
- **WCOV-4 — the authenticated collection screen does not change.** `/wiki/collections/{id}`
  stays the editing surface: the List and Group views, the tables, the drag-and-drop. The cover
  is what a *reader* sees, and it is reached from here by **Preview**, which already exists.
- **WCOV-5 — cards point inside their own collection.** `destination_type` is `group` or `page`,
  and the id is validated against *this* collection — the same rule `GroupController::assign()`
  and `PageController` already enforce for parents and groups. A cross-collection destination
  has no rendering that makes sense on a collection's own front door.
- **WCOV-6 — `destination_type` is a string, not a boolean or an enum column.** The source
  document's own Future Enhancements list more destinations; `visibility` and `status` on
  `wiki_collections` are strings for exactly this reason.
- **WCOV-7 — a card cannot be saved without a title and a resolvable destination.** (§9)
- **WCOV-8 — a broken destination is flagged to editors and hidden from readers.** If the target
  is deleted, or a page is archived, Settings shows the card with an *Unavailable destination*
  warning and offers Change / Delete; readers do not see the card at all. Never a card that
  leads to a 404. (`FR-WC-012`, `FR-WC-032`)
- **WCOV-9 — a card a viewer cannot open is not rendered, and nothing about it is sent.** The
  filtering happens server-side, before the payload is built — its title, description, icon,
  banner and destination never reach the browser. Client-side hiding would put the name of a
  private page in the page source. (`FR-WC-031`, §11)
  - Within one collection, access is uniform — a reader who can open the collection can open its
    pages (`wiki.md` Slice 4). The rule still holds, because an **archived** page is one nobody
    reads, and because it must survive a future per-page permission without being rewritten.
- **WCOV-10 — deleting a card deletes a card.** The group or page it pointed at is untouched, in
  the spirit of `nullOnDelete` on groups: removing something from a landing page is a decision
  about presentation. (`FR-WC-014`)
- **WCOV-11 — search returns only what the searcher may read.** It runs inside one collection,
  and it is refused outright to anyone who could not open that collection — so a snippet cannot
  quote a private document to a stranger. On the published page it searches that collection only.
  (`FR-WC-017`, §11)
- **WCOV-12 — Previous / Next uses the order the navigation already draws.** That order is
  `WikiReader::sections()` flattened, which `WikiReader::current()` already computes to resolve
  `?page=`. A second traversal written for the footer is a second answer to "what comes next",
  and the two would disagree the first time somebody dragged a page. (`FR-WC-020`)
- **WCOV-13 — the toggles govern display, never data.** `on_this_page_enabled` hides the column
  the reader already renders; the ids stay written into the HTML by `WikiReader::outline()`, so a
  `#anchor` somebody bookmarked keeps working with the column switched off. (`FR-WC-021`)
- **WCOV-14 — banners are validated on the way in.** Same constraints as project covers, read
  from config rather than repeated: type and size. (`FR-WC-008`, §9)
- **WCOV-15 — a failed save loses nothing the administrator typed.** The panel keeps its state
  and shows the field errors; it does not reload itself from the server. (§9)

## Acceptance Criteria

**Configuration**
- The Group view shows a **Cover** card above the sections, for every collection.
- An editor can enable and disable the cover; disabling and re-enabling returns every saved
  value unchanged.
- Enabling without a title is refused (422) and the field is marked.
- Title over 100 / description over 300 characters is refused.
- A read-only member gets 403 from every cover endpoint, and sees no editing controls.
- A user of another workspace gets 404 for a cover of a collection they cannot see.
- A workspace with Wiki disabled gets 404, as with every other Wiki route.

**Cards**
- An editor can add, edit, reorder (drag, and Move up / Move down) and delete cards.
- Order survives a reload and is the order the cover renders.
- A card with no title is refused; a card with no destination is refused.
- A card pointing at another collection's group or page is refused (422).
- Deleting a card leaves its group or page in place.
- An `icon_key` unknown to `IconRegistry` is refused — a typo must not ship as an invisible card.
- A banner over the size limit, or of an unlisted type, is refused with a recoverable message.

**The cover as a reader sees it**
- Preview and the published URL both open on the cover when it is enabled.
- Both open on the first page when it is disabled — today's behaviour.
- `?page=` opens that page directly, cover or no cover.
- A card whose destination was deleted is absent for readers and flagged for editors.
- Enabled with no cards: title, description and search render, editors are offered *Add your
  first section*, readers see an intentional empty state rather than an empty grid.
- The grid honours `card_layout`, and collapses to one column on a narrow viewport.

**Search**
- Hidden when `global_search_enabled` is false; every other route still works.
- Finds a page by title, by body text, and a section by name.
- Never returns a page from another collection.
- On the published page, a signed-out visitor searching an unpublished collection gets 404.
- Returns a stated no-results message, and is fully keyboard operable.

**Reading aids**
- Previous / Next appear at the foot of a page when enabled, name their destination, and follow
  the navigation's order across sections and sub-sections.
- The first page has no Previous; the last has no Next.
- On this page is hidden when disabled, and heading anchors still resolve.
- `content_alignment` moves the document column left, centre or right without changing the
  measure.

## UI Requirements

### The Cover card — Group view, pinned first

```text
┌──────────────────────────────────────────────────────────┐
│ ▣ Cover                        [Preview]  [ On ]  [ ⌄ ]  │
│   The landing page readers see before they choose a page │
└──────────────────────────────────────────────────────────┘
   ☰ Getting started            2 pages      [+] [✎] [🗑]
   ☰ Reference                  7 pages      [+] [✎] [🗑]
```

Above `topGroups()`, visually distinct from the section cards beneath it — it is not a section,
and a card identical to its neighbours invites somebody to drag it into the middle of them. It
does not drag, and it does not delete.

Collapsed it is one row: name, the enable switch, Preview. Expanded it is the settings panel —
inline, not a modal, because the settings are long and half of what they configure is on the
screen behind them.

The panel, in the source document's order (§7): Title · Short description · Sections (the card
list, with *+ Add section*) · Page features (three switches) · Content area layout · Section
card layout · **Save changes**.

- The switch saves on its own; the rest saves on **Save changes**, with a dirty state and a
  warning before navigating away. (`FR-WC-028`)
- Everything is disabled and the switch is absent for a viewer without `canEdit` — the card
  itself still shows, so they know the collection has a front door.
- Card rows: drag handle, visual, title, destination, Edit, Delete, and **Move up / Move down**
  for the keyboard. (§10)
- The card editor is a modal: Visual (Icon / Banner / None) → the icon picker or the banner
  upload → Title → Description → Destination type → Destination, the searchable `pb-combo` the
  group picker already uses.
- Vue in Blade, mounted into `wiki-collection.js` beside the existing Group view. FlyonUI first;
  custom CSS keeps the `_moretogether` prefix. Icons through `pb_icon()`. Drag through the
  vendored SortableJS already loaded on this screen — never a CDN.

### The cover, in the reader

```text
┌─────────────┬──────────────────────────────────────────────┐
│ Nav (240px) │            Product Knowledge Base            │
│             │   Everything your team needs to understand   │
│ Getting st. │                                              │
│ Reference   │        [ Search this collection… ]           │
│             │                                              │
│             │   [▣ Getting started] [▣ Reference      ]    │
│             │   [ Description     ] [ Description     ]    │
│             │   [ Explore →       ] [ Explore →       ]    │
└─────────────┴──────────────────────────────────────────────┘
```

The existing 240px navigation and header stay; only the document column is replaced. The whole
card is the link, one `<a>` with the Explore affordance inside it — not a nested anchor, which
is invalid and unreachable by keyboard. Banners are `loading="lazy"` with a stated aspect ratio,
and a card whose banner fails to load still shows title, description and Explore.

Previous / Next render at the foot of a page as two named links. On this page keeps the column
Slice 9 built; it moves above the content on a narrow viewport rather than squeezing the
measure.

## Real-Time Requirements

None. A landing page edited by one person is read by others on their next visit; a broadcast
would be machinery in service of nothing.

## Queue Requirements

None. The only slow operation is the banner upload, which is synchronous and small, exactly like
the project cover upload it copies.

## Audit Requirements

`created_by` / `updated_by` on both tables. No activity feed — consistent with the rest of the
Wiki, which records who and when, not a history of edits.

---

## Delivered

### Slice 1 — the Cover card and its settings

`wiki_covers`, tenant-scoped, **one row per collection** and written on the first save rather
than the first read: opening a collection is not a decision to give it a cover, and a table
holding a row for every collection nobody has configured says the opposite. A collection without
one is described by an unsaved `WikiCover` carrying the model's own defaults, so the screen reads
`toCard()` either way.

The **Cover card sits above the sections in Group view** — no drag handle, no delete, a quieter
fill. A card identical to its neighbours invites somebody to drop it into the middle of them, and
it is not a section. Collapsed it is one row: name, On/Off, Preview. Expanded it is the settings,
**inline rather than in a modal**, because half of what they configure is on the screen behind
them and a dialog would cover the thing being described.

- **The switch saves on its own**; everything else waits for **Save changes**. Turning the front
  door off should be one click, not a click and then a Save.
- The switch posts the **whole panel**, so "may this be turned on?" is answered against the title
  in front of the person turning it on rather than the last saved one. A refused enable puts the
  switch back where it was.
- **Enabling without a title is refused** (WCOV-2), and whitespace is not a title — it passes
  `required` and renders as an empty heading, which is the one outcome the rule exists to
  prevent. Turning it on with the field empty **opens the panel first**: an error naming a field
  that is not on the screen is not an error anybody can act on.
- A **failed save keeps what was typed**. The fields are not reloaded from the server.
- **Editing uses `canEdit`** — the gate `GroupController` already enforces. A read-only member
  sees the card, without the switch or the Save button, and is refused by the endpoint too.
- `content_alignment` and `card_layout` are **strings**, and `two` / `three` rather than
  `2_column`: a value that cannot be a constant name reads badly at every call site.

Defaulted **off**, against the source document's recommendation of on. Every collection that
exists today was written without a cover, and defaulting to enabled would change what readers see
on deploy for collections whose authors were never asked.

### The landing page (brought forward from Slice 3)

Preview and the published URL both **open on the cover** when one is enabled, and on the first
page when it is not — today's behaviour, untouched.

- **`?page=` is never intercepted.** A link somebody pasted into a chat must not land on a
  screen that is not what they meant to send.
- The navigation carries **the way back to the cover**, or the front door would be reachable only
  by deleting the query string out of the address bar.
**The cards are derived from the collection, not configured.** `WikiReader::coverCards()`:

- **A card per section** — its name, its short description, its page count including its
  sub-sections, leading to the first page under that heading.
- **A card per page** when nobody has made sections yet, described by the opening of the page
  itself. Every tag becomes a space when that excerpt is taken; `strip_tags()` alone welds the
  last word of a heading to the first word of the paragraph under it.
- **A section with no pages gets no card.** A card leading nowhere is a dead end, and an empty
  section is a heading somebody made in advance rather than a destination.
- Ungrouped pages keep the place and the name `sections()` already gives them (*Pages* / *More*).
- The **whole card is the link** — one anchor with the Explore affordance inside it. An anchor
  inside an anchor is invalid markup and unreachable by keyboard.
- With nothing to draw at all, **Start reading** points at the first page, and a collection with
  no pages says so (FR-WC-033).
- The cover column is **980px**, not the 760px the pages are held to. Three columns inside a
  reading measure is three columns of broken words.

This is a **departure from FR-WC-004/005**, which have every card configured by hand. A cover
whose cards all start empty is a heading over an empty grid, which reads as a broken feature
rather than a new one — so the collection's own arrangement is the grid until Slice 2 builds
configured cards, which will then take over from these.
- **`on_this_page_enabled` hides the contents column**; the heading ids are still written by
  `WikiReader::outline()`, so a `#anchor` somebody bookmarked keeps working with the column off.
- **Alignment and the contents toggle apply only when a cover is enabled.** A `center` default
  sitting on a row nobody configured would quietly restyle every collection written before this
  feature existed — so a collection without a cover keeps reading flush left, as it always has.
- `content_alignment` moves the column via `mr-auto` / `mx-auto` / `ml-auto` on the wrapper the
  document and the cover share. **`npm run build:css` is not optional**: `public/assets/css/`
  `tailwind.css` is a compiled artifact, and the first cut of this shipped with `mr-auto`,
  `max-w-[980px]` and every `grid-cols` variant absent from it. The markup was right and nothing
  moved, because the classes did not exist. Anything new in a Blade or a JS string template needs
  that rebuild before it means anything.
- The **global search toggle renders nothing yet** — the Wiki has no search at all (`wiki.md` →
  Not built yet), and a search box that cannot search is a lie told in a prominent position. The
  setting saves and waits for Slice 4.

**Files:** `2026_09_25_000008_create_wiki_covers_table.php` · `App\Models\WikiCover` ·
`App\Http\Requests\Wiki\UpdateCoverRequest` · `App\Http\Controllers\Wiki\CoverController` ·
`routes/wiki.php` · `CollectionController::payload()` and `::preview()` ·
`PublicCollectionController::show()` · `wiki/reader.blade.php` · `wiki-collection.js` ·
`tests/Feature/Wiki/CoverTest.php` (21 tests).

---

## Build plan

~~**Slice 1 — the Cover card and its settings.**~~ Delivered.

**Slice 2 — section cards.** `WikiCoverSection`, CRUD + reorder endpoints, the card list, the
card editor modal, icon picker, destination picker, drag and Move up / Move down.

**Slice 3 — the cards in the reader.** The landing page itself is delivered; what remains is the
grid on it: viewer-safe filtering, broken-destination removal, and `card_layout`, which has
nothing to lay out until Slice 2 exists.

**Slice 4 — search.** The endpoint, scoped to the collection and gated; the input, the debounce,
the categorised results, keyboard traversal, the empty state.

**Slice 5 — the reading aids.** Previous / Next off `WikiReader`'s existing order; the On this
page toggle wired to the column that already exists.

Banner upload rides with Slice 2 if `FR-WC-008` is confirmed as Phase 1; it is the one piece
that could be deferred without leaving a hole.

## Decisions

| # | Against | Decision | Why |
|---|---|---|---|
| C1 | `FR-WC-001`, §13 | A cover belongs to a **collection**, not the workspace | Follows from configuring it in the per-collection Group view; gives the published reader a landing page, which is where the gap actually is |
| C2 | §3 | Configured from a **Cover card at the top of Group view**, not *Wiki → Settings → Cover Page* | Owner's instruction; it also puts the setting beside the sections it arranges |
| C3 | `FR-WC-012` | Destinations are **group or page**, not collection or page | A collection's own front door pointing away from itself has no sensible rendering |
| C4 | §13 | `position`, not `sort_order`; `banner_url`, not `banner_asset_id` | Matches the four Wiki tables that already order by `position`; there is no assets table to hold an id |
| C5 | §13 | `card_layout` values `auto` / `two` / `three` | `2_column` cannot be a PHP constant name and reads badly at the call site |
| C6 | `FR-WC-030` | Configuring uses **`canEdit`**, not a new Wiki-admin permission | The gate `GroupController` already uses for arranging sections; publishing still needs `canManage` |
| C7 | `FR-WC-020` | Previous / Next reuses `WikiReader::sections()` order | One answer to "what comes next", already written and already used by `?page=` |
| C8 | `FR-WC-017` | Search is scoped to **one collection** | A per-collection cover cannot host a workspace-wide search without leaking the existence of collections the searcher was never shown |
| C9 | `FR-WC-004`, `FR-WC-005` | Cards are **generated from the collection's sections and pages** until they can be configured | A cover whose cards all start empty is a heading over an empty grid — a new feature that reads as a broken one. Configured cards replace these in Slice 2 |
| C10 | `FR-WC-021` | "On this page" lists **H1–H4**, not H2–H4 | The rule exists because H1 is the page title — but the title is rendered from the page record and never appears in the body, so an H1 there is a section heading like any other. Excluding it would empty the column on every page written before this feature. H5/H6 stay out: below the level anybody navigates by |

## Open questions for the owner

1. ~~**`FR-WC-021` says H2/H3/H4; Slice 9 built H1+H2.**~~ **Resolved: H1–H4.** See C10.
2. **`FR-WC-029` Preview reflecting unsaved settings.** Preview already opens in a new tab
   against saved data. Rendering unsaved settings means posting the draft config through — real
   work for a screen the inline panel mostly already shows. Proposal: Preview shows **saved**
   state, and the panel says so. Confirm, or it becomes a slice of its own.
3. **`FR-WC-016` global search when the cover is disabled.** The toggle lives on the cover, so
   with the cover off there is nowhere for the search box to be. Assumed acceptable; the Wiki has
   no search at all today (`wiki.md` → Not built yet), so this feature would be introducing it.
4. **Default.** The source recommends **Enabled** by default (`FR-WC-001`); this spec defaults to
   **disabled** so no existing collection changes behaviour on deploy. New collections could
   default to enabled instead — say which.
