# Feature: Project Workspace — Work Items (Phase 5, slice 1)

> Feature spec + build record (CLAUDE.md §4/§15).
> Source requirement: *ProjectBlock 3.0 — Project Workspace / Work Items Requirements*
> (August 10, 2026), referenced below as **§n**. Design reference: `html/work-items.html`.
> Screen: `http://127.0.0.1:8000/projects/{project}/work-items`.
> Status: **slice 1 built and verified** (2026-08-10). Later slices listed under
> [Not in this slice](#not-in-this-slice).

## Requirement

Selecting a project must open that project's **Project Workspace** rather than a generic
detail screen. The workspace shows six tabs — Overview, Work Items, Cycles, Modules, Views,
Pages — of which only **Work Items** is functional this phase; the rest stay visible and show
a Coming Soon state so the planned information architecture is legible (§1, §3).

This slice delivers the workspace shell plus the two things that make Work Items usable:

1. the **state-grouped list** (Tabulator, matching the POC's data-table and chip style), and
2. **Create work item**, with automatic project-sequential IDs (`TESTI-1`, `TESTI-2`).

## User Roles

Two layers, both enforced server-side (§7), never only hidden in the UI:

- **Reach** — a user may only touch work items of a project they can already open, so every
  ability defers to `ProjectPolicy@view`. An inaccessible project **404s** (never 403), so the
  response cannot confirm that the project exists (§12).
- **Contribute vs. read-only** — workspace **Owner / Admin / Member** can create work items;
  **Viewer** and **Guest** can open the list but cannot mutate anything. This mirrors
  `ProjectPolicy@create`'s split, so "who can add work" reads the same across the app.

## Database Fields

Four migrations; the state and label vocabularies already existed from Phase 4.

| Table | Key fields | Notes |
|---|---|---|
| `tenants` (altered) | `work_item_sequence` | Per-**workspace** counter backing the unique ID number. |
| `work_items` | `id, tenant_id, project_id, sequence_no, identifier, title, description, state_id, priority, start_date, due_date, parent_id, created_by, archived_at` | Tenant-scoped **and** project-scoped. `UNIQUE(tenant_id, sequence_no)` and `UNIQUE(tenant_id, identifier)`. |
| `work_item_assignees` | `work_item_id, user_id` | Many-to-many per §8, though the POC row shows one avatar. |
| `work_item_labels` | `work_item_id, label_id` | Against the project's own `project_item_labels`. |
| `work_item_activity` | `id, tenant_id, work_item_id, actor_id, event, field, old_value, new_value, meta, created_at` | The audit feed (§6/§8). One table serves Activity / Transition / History — they are different reads of the same events. |

Reused as-is: **`project_item_states`** (per-project work-item states, with the stable `group`
key) and **`project_item_labels`**, both shipped in Phase 4.

Deliberately **not** created yet: `work_item_relations`, `work_item_links`,
`work_item_page_links`, `work_item_comments`, `work_item_votes`, `work_item_subscribers`,
and the cycle/module joins. They belong to later slices, and building
empty tables now would be schema no one reads.

## Business Rules

- **Opening a project enters its workspace.** `GET /projects/{project}` redirects to
  `…/work-items` (§11.1). Kept as a redirect, not a moved route, so every existing
  `projects.show` link — cards, sidebar, post-create redirect — keeps working.
- **The six tabs render in the required order** from `config('projects.workspace_tabs')`.
  The five non-MVP tabs resolve to a Coming Soon page that names the feature and links back
  to Work Items; none of them dead-ends (§12).
- **The header's `⋯` menu** offers Add to favorites · Archives · **Settings** · Leave project.
  Settings is the live action this phase and is only rendered for users with manage rights
  (the settings screen re-checks server-side); the other three are marked *Soon* rather than
  rendered as dead controls. Built with `<details>` so it works on both workspace pages
  without either owning a Vue root. Two constraints keep it actually openable, both covered
  by a regression test:
  - The header **row must not scroll**. `overflow-x-auto` there makes it a clipping container
    and the menu — positioned below a 48px row — is silently swallowed. Only the tab `<nav>`
    scrolls on narrow screens.
  - The `<summary>` must keep its **default display**: `display:grid`/`flex` on a summary
    stops WebKit toggling the disclosure at all, so the sizing lives on an inner `<span>`.
- **The ID is a plain unique number.** `1`, `2`, `3` … with no project prefix, drawn from one
  counter per **workspace**, so a number names exactly one work item anywhere in the
  workspace. (Superseded the original `<PROJECT>-<n>` form — see Decisions, revision below.)
- **IDs are unique per workspace and never reused.** Deleting a work item does not free its
  number: the counter only moves forward, so an ID always refers to one work item for the
  life of the workspace — a reused ID would break links, mentions and history. Numbering is
  *shared* across a workspace's projects (`1` in project AAA, `2` in project BBB) and starts
  from 1 again in a different workspace, so one tenant's volume is not inferable from
  another's.
- **IDs are sequential and gap-free.** `WorkItemCreator` reads `tenants.work_item_sequence`
  under `lockForUpdate()` inside the same transaction that inserts the row, so concurrent
  creates serialize on the tenant row instead of racing on `MAX(sequence_no) + 1`.
  `UNIQUE(tenant_id, sequence_no)` backs it at the DB level. The counter is read through the
  query builder, not the `Workspace` model, because stancl's VirtualColumn rewrites the
  `data` overflow column on every save. The identifier is stored denormalized and never
  recomputed — moving or renaming a project must not renumber existing work items.
- **States are seeded on first use.** `project_item_states` shipped in Phase 4 but nothing
  ever seeded it, so every project had an empty state set and nothing to group by.
  `ProjectItemStateProvisioner` seeds Backlog / Todo / In Progress / Done / Cancelled the
  first time Work Items is opened — idempotent, and it never touches a project already
  customized in Project Settings → States. Lazy rather than in `ProjectCreator`, so projects
  created before Phase 5 are covered without a backfill migration.
- **A new work item lands in the project's default state**, or in the state whose group `+`
  button was used (§4.2 / §11.2).
- **"Create more" starts each item clean.** The toggle keeps the modal open after a save, but
  every chip resets to the state the modal was *opened* in — title, description, State,
  Priority, Assignees, Labels, both dates and Parent. It resets to the opening seed rather
  than the last-used values, so a modal opened from a state group's `+` keeps creating in
  that group while a manual change never carries into the next item.
- **All three create entry points work** (§4.3): the toolbar's *Add work item*, a state
  group's `+`, and the sidebar's global *New work item*. The sidebar button is a real link to
  a project's Work Items with `?create=1` — the screen auto-opens the modal on that flag, so
  the action works from any screen and without JS. On the Work Items screen itself the click
  is intercepted and opens the modal in place instead of reloading. It is hidden from
  Viewers/Guests, who cannot create work items anyway.
- **Title is required.**
- **The due date must fall strictly after the start date** (§4.3) — same-day is rejected.
  Enforced in two places that agree by construction:
  - *Server*: `'due_date' => [..., 'after:start_date']` (not `after_or_equal`).
  - *Picker*: the bounds are **exclusive** on both sides, and they drive navigation, not just
    the day grid — a due-date picker only ever offers dates forward of the start date:
    - Days at or before the start date are struck through and unclickable.
    - Quick options (Today / Tomorrow / +3 / +5) are disabled when they land in that range.
    - **Months and years with nothing selectable are removed from the dropdowns.** With a
      start date of 09/10/2026 the due picker lists only Sep–Dec for 2026 and years
      2026–2035. A start date on the last day of a month drops that month entirely (09/30
      leaves nothing in September), and the picker opens on October instead.
    - The prev/next arrows stop at the edge of the range rather than paging into a fully
      disabled month, and choosing a year snaps the visible month back into range.
    - The start-date calendar applies all of the above mirrored — only dates *before* the due
      date — so the pair can never be made equal or inverted from either end.
    - With only one of the two dates set there are no bounds: all 12 months and 2015–2035.
  - Either date **on its own** carries no ordering constraint — a due date with no start
    date, or vice versa, is valid.
- **Nothing may be borrowed from another project.** State, labels and parent are all
  validated with `Rule::exists(...)->where('project_id', $projectId)`, and assignees against
  active memberships of *this* workspace — so a crafted payload cannot reach across tenants
  or projects (§7).
- **Every work item is created with an activity entry** (§6). `WorkItemActivityRecorder` is
  the single funnel for audit events, and it is called *inside* `WorkItemCreator`'s
  transaction — a work item can never exist without its creation entry, and activity cannot
  be reconstructed after the fact. The entry stores a snapshot of the properties the item was
  created with, resolved to display values **at write time**, so renaming or deleting a state
  or label later does not silently rewrite history.
- **The feed is readable at** `GET /projects/{project}/work-items/{workItem}/activity`,
  oldest first so it reads as a story. It is reachable only through the item's own project
  URL, and only by someone who can open the item (404 otherwise, never 403) — a read-only
  Viewer may read history, since the feed exposes nothing the item itself does not.
- **Archived items drop out of the default list** but keep their data (`archived_at`).
- Grouping is by the **state**, not by the group key, so two states sharing a lifecycle group
  stay distinct bands; the group's stable `group` key only picks the icon, which keeps icons
  correct after a state is renamed.

## Acceptance Criteria

Covered by `tests/Feature/Project/WorkItemsTest.php` — **22 tests, 141 assertions, all
passing**. Mapped to the MVP checklist in §13:

- ☑ Selecting a project opens that exact project's Project Workspace (redirect asserted).
- ☑ Six tabs render in the required order.
- ☑ Only Work Items is functional; the other five show Coming Soon.
- ☑ Work items are isolated to the selected project *and* workspace (another project's items
  never appear; archived items are excluded).
- ☑ A permitted user can create a work item with Title plus every supported optional property
  (description, state, priority, assignees, labels, start/due date, parent).
- ☑ A created work item receives a unique sequential ID number (`1`, `2`, `3`); the sequence
  is shared across a workspace's projects and independent between workspaces.
- ☑ Opening Work Items seeds the project's five default states.
- ☑ Required title missing → 422; a due date before **or equal to** the start date → 422 on
  `due_date`; one day later → 201; either date alone → 201.
- ☑ A state, label or parent from another project → 422.
- ☑ The sidebar's global **New work item** points at a project's Work Items with `?create=1`
  from every screen, and is hidden from read-only members.
- ☑ The header `⋯` menu lists all four actions on every workspace tab; **Settings** links to
  the project settings screen, is hidden without manage rights, and every section
  registered in `settings_nav` renders (an unknown section still 404s).
- ☑ **Every new work item writes a `created` activity entry** with the actor and an initial
  property snapshot; a failed create rolls back the item, the ID counter *and* the entry;
  the snapshot is unaffected by renaming the state it referenced.
- ☑ The activity feed 404s through another project's URL or for a user who cannot open
  the item, and is readable by a read-only Viewer of a project they can see.
- ☑ A read-only (Viewer) member can open the list but gets 403 on create, and nothing is
  written.
- ☑ An outsider gets **404** on both the list and a Coming Soon tab of a private project.

Not yet claimed from §13 (see [Not in this slice](#not-in-this-slice)): row click → detail,
sub-work items, external links, Blocked By, subscribe/vote, archive/delete from the UI, and
activity/history records.

## UI Requirements

Hybrid Vue-in-Blade (CLAUDE.md §14), Tailwind + POC tokens, following `html/work-items.html`.

- **Data table** — **Tabulator v6.3.1**, the same engine and CDN build the Members listing
  already uses, with the POC's grid skin ported to `public/assets/css/work-items.css`
  (scoped to `#wi-table` so the Members skin is untouched). Header-less, 44px rows, grouped
  by state with a collapsible band showing the state icon, name, count and a `+` that creates
  an item already in that state.
- **Chip style** — two sizes, both taken from the POC:
  - *Row chips* (display): `h-6 px-2 rounded border border-line bg-white text-[12px] gap-1.5`.
    Rows render a right-aligned cluster of state · priority · dates · assignees · labels, with
    the wider chips dropping out at `lg`/`xl` exactly as the POC does.
  - *Modal attribute chips* (interactive): `h-8 px-2.5 rounded-md border border-stroke
    text-[13px] text-ink hover:bg-hover gap-1.5`, with their popovers opening upward
    (`bottom-full mb-1`) and outlined `outline outline-1 outline-black/5` — not a ring.
    Widths follow the POC: state `w-48`, priority `w-44`, assignees/labels `w-64`,
    dates `w-[300px]`.
  - Dates are labelled **MM/DD/YYYY** in both places, as the POC does.
- **Create modal** — title, description, and the POC's chip row: State, Priority, Assignees,
  Labels, Start date, Due date, Add parent. Plus the POC's "Create more" toggle, which keeps
  the modal open and re-seeds the same state after a save. The modal body is
  `overflow-visible` (as in the POC) — load-bearing, since a scrolling body would clip the
  upward-opening popovers.
- **Add parent** — opens the POC's **ParentSearchModal**, not a popover: a `max-w-[720px]`,
  `max-h-[70vh]` panel above the create modal (`z-[95]`) with a search icon + borderless
  "Type to search" input in the header, and `h-11` result rows showing the item's state dot,
  ID and title. Search filters on ID *or* title, the field autofocuses, Escape closes the
  panel before the modal, and an empty result reads "No work items found". Two deliberate
  differences from the POC: a **Remove parent** row (the POC offers no way to unpick, which
  is a dead end), and the header shows the project identifier instead of the POC's
  *Workspace level* toggle — see below.
- **Calendar** — a port of the POC's `datePicker()`, as a local `wi-calendar` component used
  by both date chips: an anchored popover that opens on **quick options** (Today / Tomorrow /
  Next 3 days / Next 5 days → divider → Custom Date), then switches to a month grid with
  Back, prev/next arrows, and month + year (2015–2035) dropdowns whose rows highlight
  `hover:bg-brand hover:text-white` with a tick on the current value. One addition over the
  POC: out-of-range days and quick options are struck through/disabled, so the start/due
  ordering rule is visible in the picker instead of surfacing as a 422.
- **Mobile** — below `sm` the grid is replaced by state-grouped cards (§4.1), each with the
  state and priority chips, and the group `+` action preserved.
- **Rows are editable in place** (§4.2). Every chip — State, Priority, Start, Due, Assignees,
  Labels — is a button that opens its own picker anchored to the row and PATCHes just that
  property, without navigating away. Assignees and Labels are multi-select and keep their
  popover open. The date pickers reuse the same `wi-calendar` component (and the same
  start/due ordering bounds) as the create modal.
- **Each row has a `⋯` action menu** (§4.4): Edit · Make a copy · Open in new tab · Copy link
  · Archive · Delete, with a typed confirmation on Delete. "Edit" currently renames the item
  in place — the full detail drawer is still a later slice, and every other property is
  already editable from its chip.
- **Read-only roles get no controls at all**: a Commenter or Guest sees plain chips with no
  buttons and no kebab, matching the server, which refuses their PATCH/duplicate/archive/
  delete with 403.
- The Board/Display/Analytics placeholder buttons from the POC toolbar are still not rendered
  (§13: no non-MVP surface may look production-ready).
- Escaping: Tabulator formatters return raw HTML, so every interpolated title, identifier,
  label and assignee name goes through `wiEsc()`.

## Real-Time Requirements

None in this slice. When the Phase 1 notification system lands (CLAUDE.md §8–§13), work item
creation and state changes are the natural events to broadcast on
`private-tenant.{tenantId}` so other members' lists update live. §6's subscribe/unsubscribe
control is the intended trigger for per-item notifications — noted, not built.

## Queue Requirements

None. Creating a work item is one small transaction.

## Audit Requirements

**Creation and every property change are logged.** Inline row editing routes through
`WorkItemUpdater`, which diffs each field and writes its own activity row in the same
transaction — so a property can never change without a matching history entry, and
re-sending an unchanged value writes nothing. That fills all three of §6's system feeds:
**Activity** (all rows), **Transition** (`field = 'state'`, carrying the from/to state names
resolved at write time) and **History** (rows with `old_value` / `new_value`). Archive and
restore are logged too. Comments remain a later slice.

**Creation is logged.** `work_item_activity` and `WorkItemActivityRecorder` are in place, and
every new work item writes a `created` entry with the actor and a snapshot of its initial
properties.

The `field` / `old_value` / `new_value` columns exist for property-level before/after
records, but nothing writes them yet because nothing mutates a work item after creation in
this slice. When inline row editing and the detail drawer land, every mutation routes through
`WorkItemActivityRecorder::record()` — the one hook point — which fills in the remaining §6
feeds: **Activity** (all rows), **Transition** (`field = 'state'`), and **History**
(rows with before/after). Comments get their own table.

## Not in this slice

Deferred to the next slices, in the order they are most useful:

1. **Detail drawer** (§4.4) — the side peek itself: full title/description editing,
   upvote/downvote and subscribe. Its More-menu actions and inline property editing are
   already built and live on the row.
2. **Sub-work items and relations** (§5) — add/link children with cycle prevention, Blocked By
   with an extensible relation model, external links.
3. **Activity & collaboration** (§6) — comments, activity, transition and history feeds.

**The parent panel's "Workspace level" toggle** is not built. In the POC it is cosmetic — it
restyles itself and does not change the result list. Making it real means letting a work item
parent one in a *different* project, which contradicts §7 ("every Work Item belongs to exactly
one Project") and the `parent_id` validation, which requires the same `project_id`. Wiring a
toggle that would 422 is worse than omitting it, so the header shows the project identifier
instead, making the search scope explicit. Cross-project parents need a product decision plus
an API change.

Deferred beyond this phase by the requirements themselves (§14): Board/Timeline layouts,
Display and Analytics configuration, estimates, time tracking, attachments, custom
properties, drafts, bulk operations and export. **Cycle and Module fields** are also not in
the create modal: §4.5 marks them "Limited — can assign existing values", and there are no
cycle or module records to assign yet.

---

## Planning & Reasoning (Claude Code)

Followed CLAUDE.md §4: read the requirement and the POC first, checked what already existed,
then built the smallest slice that is genuinely usable.

**1. Confirmed the "data table plugin" before choosing one.** The requirement's closing note
asks to follow `work-items.html` for the data-table and chip style and to "use the data table
plugin". The POC loads **Tabulator v6** — and the app already ships Tabulator on the Members
listing with a shared `tabulator-skin.css`. So this is an established pattern, not a new
dependency, and the grid skin was ported rather than reinvented.

**2. Found the Phase 4 groundwork and reused it.** `project_item_states` (with a `group`
column that maps exactly onto Backlog/Todo/In Progress/Done/Cancelled) and
`project_item_labels` already existed, tenant-scoped and per-project. Building parallel
work-item state/label tables would have duplicated the Project Settings → States/Labels
editors. The gap was that **nothing seeded them**, which `ProjectItemStateProvisioner` fixes
lazily.

**3. Made the ID allocation the part that is actually hard.** §9 asks for "atomic sequence
generation". `MAX(sequence_no) + 1` races under concurrency and produces duplicate IDs; the
counter lives on the workspace row and is read under `lockForUpdate()` inside the insert's
transaction instead, with a UNIQUE constraint as the backstop.

**3b. Revision (2026-08-19) — the ID format became a plain unique number.** The original
`<PROJECT IDENTIFIER>-<n>` form (TESTI-1) was replaced on the product owner's call with a
bare number, unique across the workspace rather than the project. That moves the counter from
`projects.work_item_sequence` to `tenants.work_item_sequence`, and the uniqueness constraints
from `(project_id, …)` to `(tenant_id, …)`. Two consequences worth recording:

- **Existing rows were renumbered**, per workspace, in creation order (`id` ASC) —
  `2026_08_19_000002`, which also parks the high-water mark on the tenant row so the next
  create continues rather than collides. The per-item URL is keyed on the primary key, not
  the identifier, so renumbering does not break links.
- **`down()` re-adds a standalone `tenant_id` index before dropping the two uniques.** They
  are the only remaining tenant_id-leading indexes, and MySQL refuses to drop the last index
  covering a foreign key (errno 1553). Caught by running the rollback for real, not in review.

**4. Grouped by state, not by group key.** §4.2 says "grouping is by State" while also naming
five groups. A project can configure two states in one lifecycle group, so grouping by the
group key would silently merge them. The list groups by `state_id` and uses the stable
`group` only to select the icon — which also means renaming a state does not change its icon.

**5. Chose the URL shape deliberately.** §3 *recommends*
`/workspace/{workspace}/projects/{project}/work-items`, but every existing route in this app
is `/projects/…` with the workspace resolved from tenancy by the `workspace.tenancy`
middleware. Confirmed with the product owner and kept the app's convention:
`/projects/{project}/work-items`. The `{tab}` route is whitelisted to the five Coming Soon
keys so it can never shadow `/projects/{project}/settings`.

**5b. Enabling Settings meant repairing the whole Project Settings screen.** Wiring the `⋯`
menu's Settings action exposed that the screen had *never* been reachable — three
independent gaps, each of which alone was fatal:

| Gap | Symptom |
|---|---|
| `ProjectPolicy` had no `manage()` (see §6 below) | 403 for everyone, including owners |
| `config('projects.settings_nav')` was never defined | 404 for every section — the controller resolves sections from that list, so it is the registry of settings screens, not just labels |
| `Project` had no `states()` / `labels()` relations | 500 on those two sections |

All three are fixed, so General, Members, States and Labels now render and their existing
mutation endpoints (which were already routed) are reachable. **Features** turned out to be
genuinely unbuilt — `bootstrapFor()` calls `$project->featureFlags()` and `toggleFeature()`
writes a `projects.features` column, but neither the column, the accessor, nor a
`config('projects.features')` registry exists. Rather than ship a 500, it is marked
`status => 'soon'` alongside Estimates and Automations and renders the shared placeholder.
Building it needs a migration + a feature registry — a separate, scoped change.

**6. Fixed a second missing policy ability.** `ManagesProjectController::guardManage()` calls
`can('manage', $project)`, but `ProjectPolicy` had no `manage()` method — and Laravel denies
an ability whose policy method is missing, so **every Project Settings section was returning
403, including for workspace owners**. Same class of bug as the missing `delete()` found
earlier. Added `manage()` as an alias of the shared `canManage()` check.

**7. Verified rather than assumed.** 12 feature tests (all passing) plus a
`@vue/compiler-dom` compile of the component template — a malformed template string blanks
the page with no PHP error.

## Slice 2 — Detail view, single assignee, assignment email

Reference: `html/work-items.html` (the `#wi-drawer` panel and its `.pb-page` variant).

### Detail view (§4.4)

- **Clicking a work item opens a drawer** — right-hand panel, 80% width, backdrop, over the
  list. Grid rows and mobile cards use the same entry point; a click on a row's own chip or
  ⋯ button edits in place and does *not* open the panel behind it.
- **The per-item URL renders the same detail as a full page.** `GET
  /projects/{project}/work-items/{workItem}` used to redirect back to the list; it now returns
  the Work Items screen with `bootstrap.pageItemId` set, and the client hides the list and its
  toolbar and renders the panel statically with a breadcrumb — the reference's `.pb-page`
  mode. That is what makes "Open in new tab" and "Copy link" land somewhere real.
- **One implementation, two framings.** The drawer and the page are the same markup; only the
  positioning classes and the toolbar differ. There is no second detail component to keep in
  sync.
- **Properties reuse the row pickers.** Every control in the panel opens the same popover the
  grid row opens and goes through the same `PATCH`, so "change a property" has one
  implementation and one audit path.
- Title and description are drafts saved on blur — free text should not PATCH per keystroke.
  Emptying the title reverts rather than sending an invalid request.
- The panel loads the item's **activity feed** from the existing endpoint and phrases each
  entry from the display values frozen at write time.
- The detail page can render an **archived** item, or one past the list's page size: the
  controller appends the page item to the payload when the live list does not contain it.
- Deep links still work: `?item=<ID>` opens the drawer on the list.

Deferred (each needs its own tables): votes, subscribe, sub-work items, dependencies,
relations, links, attachments, linked pages, comments, cycles and modules.

### One assignee per work item (§4.3, revised)

A work item has exactly **one** assignee. Previously the picker was multi-select and adding a
second person left the first in place.

- `assignee_ids` is capped at `max:1` in both the store and update requests, so the rule holds
  however the request is shaped — the UI is never the authority.
- The picker replaces rather than accumulates: choosing a member swaps whoever held it, and
  choosing the current one clears it.
- The pivot table is unchanged. Capping in validation is reversible if the product later wants
  co-assignees; a column would not be.

### Assignment email (§4.3 / CLAUDE.md §9)

`WorkItemAssignmentNotifier` mails the person who has just been given the work item, from both
the create and update paths.

- Only the **newly** added assignee is mailed — re-saving the same person sends nothing.
- **Assigning yourself sends nothing.** You already know.
- Registered with `DB::afterCommit`, because assignment happens inside the updater's
  transaction: if that rolls back, the assignment never happened and the mail must not go out.
- Queued (§11), scalars only (a worker has no tenancy context), and delivery failures are
  logged rather than thrown — a mail server being down must not fail the edit.
- Mail-only for now. When the Phase 1 notification stack lands (database + broadcast + Reverb
  bell, CLAUDE.md §8/§9), this becomes the mail channel of a Notification.

### Rich-text editing (Quill)

Rich text is edited with **Quill** (snow theme) — the work item description in the create
modal and detail view, and the comment and update composers. It replaced SunEditor, which was
the first choice here; the wrapper component, the sanitizer allowlist and the read-view CSS
all moved with it.

- **Loaded from CDN alongside Tabulator** on the Work Items screen, wrapped as a `wi-editor`
  Vue component so it drops in like an `<input>`. The instance is created on mount and
  dropped on unmount, which matters for the drawer: opening a different work item builds a
  fresh editor rather than leaving a detached one holding the previous item's content.
- **The model is HTML, not a Quill Delta.** Everything else in this app stores and renders
  these fields as HTML; keeping Delta alongside it would be two representations of one text.
- **Saves on blur, not per keystroke** — the caller already saves on blur, and a
  per-keystroke sync would be one update per character.
- **Images upload rather than inline.** Quill's default handler base64-encodes the file into
  the document; the toolbar's image button is overridden to open a picker that uploads through
  the project's media endpoint, or inserts something already in the project's gallery.
- **The toolbar and the server's allowlist are the same list.** If a button cannot produce a
  tag, that tag has no reason to survive sanitizing. Quill formats two ways and both are
  covered: `class` for alignment, indentation and code blocks, `style` for colour — plus
  `data-list`, which is how it marks a bullet item, and without which every bullet list would
  come back numbered.

**The description is now user-controlled HTML rendered into other people's pages, so it is
sanitized on the way in** — `App\Services\RichTextSanitizer`, built on
`symfony/html-sanitizer` (added as a dependency for this). Sanitizing on write, once, rather
than escaping on every read:

- Allowed: the structural and inline tags the toolbar writes, links (`http`/`https`/`mailto`/
  `tel` only), images (`http`/`https`), tables.
- Dropped: everything else — `<script>`, event handlers, `javascript:` URLs.
- `style` is allowed on the elements the toolbar styles (alignment and colour are most of the
  point of a WYSIWYG), but each value passes a guard that strips `expression(`, `javascript:`,
  `behavior:`, `@import` and `url(`.
- An empty editor stores `null`, not its own `<p><br></p>` scaffolding, so "has a description"
  stays a meaningful question.

Two consequences worth recording:

- **Existing descriptions were converted** (`2026_08_21_000001`). Everything written before
  this was literal plain text rendered escaped; rendering those same rows as HTML would lose
  their line breaks and start interpreting angle brackets their authors typed. Each is escaped,
  line breaks turned into markup, wrapped in paragraphs — and rows that already look like
  markup are skipped, so the migration is safe to re-run.
- **History stores an excerpt, not the markup.** A description edit used to log both sides
  verbatim; at up to 20k of HTML per side that is a feed full of tags nobody reads. The audit
  row now keeps a 200-character plain-text excerpt, and the activity line reads "updated the
  description".

Read-side styling lives in `.wi-rich` (`assets/css/work-items.css`): Tailwind's preflight
strips list markers, heading sizes and blockquote indentation, so stored markup would
otherwise render as an undifferentiated wall of text.

### Editor media — image upload, gallery, video embed

The rich-text editor carries **image**, **image gallery** and **video** controls. Each needed something behind it:

- **Images upload; they are not inlined.** The editor's default image handler base64-encodes the
  file straight into the content, which would put whole images inside the `description`
  column. `POST /projects/{project}/work-items/media` accepts the editor's `file-N` fields and
  answers in that shape — `{"result":[{url,name,size}]}`, or `{"errorMessage":"…"}`.
- **Files go to the PRIVATE disk and stream through an authorized route.** A public-disk URL
  works for anyone who receives it, regardless of workspace; `work_item_media` exists so a
  file can be resolved back to its project and checked against the same ability that gates
  the project (CLAUDE.md §7). A foreign project's media 404s rather than 403s (§12).
- **The gallery is project-scoped**, so the picker can never show another project's uploads.
- **Video is an embed, not an upload** — this app does not host video. `<iframe>` is a page
  inside the page, so it survives sanitizing only from named providers (YouTube, Vimeo); any
  other host loses its `src`. Uploaded images pass because the media host allowlist also
  contains this application's own origin.
- Uploading requires the *create work items* ability, so a Viewer or Guest is refused (403 in
  the editor's error shape) rather than silently failing.

One bug this surfaced, worth recording: the sanitizer's "is this blank?" check tested text
only, so a description containing nothing but an image, an embed, a table or a rule was
treated as empty and discarded. It now treats those as content.

| Setting | Default |
|---|---|
| `projects.media.max_kb` | 5120 |
| `projects.media.mimes` | jpg, jpeg, png, webp, gif |
| `projects.media.gallery_size` | 60 |

Deferred: attaching files to a work item as *attachments* (§4.4's paperclip) is a different
feature from images inside a description, and media is not garbage-collected when an image is
removed from the text — an orphan sweep belongs with attachments.

## Slice 3 — Structure: sub-tasks, dependencies, relations, links

Source: *ProjectBlock 3.0 — Work Item Collaboration & Relationship Requirements* (§ refs
below are to that document). UI follows `html/work-items.html`'s action row and sections.
This is the first of two slices from that spec; comments and Updates (§3–§18) follow.

### Data model

| Table | Notes |
|---|---|
| `work_item_relations` | Tenant-scoped. `blocking` \| `related` \| `duplicate_of`, stored in ONE canonical direction. |
| `work_item_links` | Tenant-scoped external URLs, optional title. |

Three modelling decisions worth keeping:

1. **`blocked_by` is not stored.** It is a `blocking` row read from the other end (§52).
   Storing both directions lets the halves drift, and removing one side has to remember the
   other. Adding a "blocked by" therefore writes a `blocking` row the other way round.
2. **Sub-tasks are `work_items.parent_id`,** not a relation row. The list, the create modal
   and the detail panel already read that column; a second source of truth for the same fact
   is how they end up disagreeing.
3. **`related` is symmetric and stored once,** from whichever side asked. Asking again from
   the other side is a no-op, not a mirror row.

### Rules (all enforced server-side, §55)

- **Sub-tasks** (§25): no self-parenting; no circular parenting — the check walks up from the
  intended parent looking for the child; adding the same child twice is a no-op, not an error.
- **Relations** (§31/§36): no self-relation; duplicates ignored; the same pair may not block
  in both directions at once — that one is refused with a message naming both items.
- **Links** (§39): `url:http,https`, so `javascript:alert(1)` is rejected rather than stored
  as a clickable script. No title falls back to the link's domain (§38).
- **Isolation** (§56): both ends of a relation must be in the same workspace — the tenant
  scope makes cross-workspace impossible, and the service turns that silence into a message.
  Cross-*project* is allowed, which is what §57's search toggle offers.
- A relation id from elsewhere in the workspace cannot be deleted through this work item's
  URL, even though the id is real.
- **Every change writes activity on BOTH items** (§36/§45) — each panel can explain itself
  without the other.

### Sub-task progress (§24)

Complete is decided by the state's stable `group`, never by a state name — names are
user-editable, so a project that renamed "Done" would otherwise report 0% forever. Cancelled
sub-tasks leave the denominator rather than counting as incomplete, so abandoning work does
not make a parent look stuck.

### API

One read (`GET …/structure`) returning all four sections, because the panel draws them
together and four round trips to fill one panel is three too many. Writes stay separate and
each returns the whole refreshed structure, so the client never merges a partial response.
Plus `GET …/search` for the picker, scoped to the project unless `all_projects=1`.

### UI

The action row from §66 sits under the description — **Add sub-task**, **Dependency ▾**
(Blocked by / Blocking), **Relation ▾** (Related to / Duplicate of), **Link**, and **Pages**
rendered visibly inert with a *Soon* chip (§42/§43). Sections appear only when they have
content. One picker dialog serves sub-tasks and every relation type — multi-select, search by
ID or title, and the workspace-wide toggle — because only the title and the submit target
differ between them.

### Deferred to the next slice

Comments (§3–§12), Work Item Updates (§13–§18), the All/Activity/Comments/Updates/Transition/
History tab split (§45–§51), activity filtering and sorting (§47/§48), attachments on the
work item itself, real-time updates (§54, needs the Reverb stack), and Pages (§42, Coming
Soon by design).

## Slice 4 — Collaboration tabs: All · Activity · Comments · Updates · Worklogs · Transition · History

Source: *ProjectBlock 3.0 — Work Item Activity, Collaboration & Audit Requirements*. Built to
its own priority order (§27): Priority 1, 3 and 4, so every one of the seven tabs has real
data behind it. Priority 2's extras and Priority 5 are deferred — see below.

### The distinction the spec insists on (§12/§29)

The tabs are seven **questions about one work item**, not seven copies of a list. That is
enforced in `WorkItemFeedBuilder`, so it cannot drift in the UI:

| Tab | Source | Rule |
|---|---|---|
| All | comments + activity + updates + worklogs | Merged by timestamp (§5.4) — never comments first, events after. |
| Activity | `work_item_activity` | System events only; comments are not activity rows to begin with (§6.1). |
| Comments | `work_item_comments` | Conversation, one level of replies. |
| Updates | `work_item_updates` | On Track / At Risk / Off Track with a frozen progress snapshot. |
| Worklogs | `work_item_worklogs` | Time, stored as minutes. |
| Transition | `work_item_transitions` | State movement only, with time in state. |
| History | `work_item_activity`, filtered | Only rows carrying a value change, read as before → after (§11.1). |

### Decisions worth keeping

- **Transitions are their own table**, not a view over activity, because they answer a
  different question: how did this move, and how long did each step take (§10.4)? State
  **names are copied in** alongside the ids — states are renameable and deletable, and a
  workflow history that changes meaning when someone edits a column is not history.
- **Creation writes the first transition** (§10.5), from nothing into the initial state, so
  time in that first state is computable and the tab does not begin mid-story.
- **Update progress is frozen at write time** (§8.6). An update is a statement about how
  things stood that day; recomputing it later silently rewrites what someone reported. An
  item with no sub-tasks stores no snapshot rather than "0 / 0".
- **Worklog duration is one minute count** (§9.9). Hours-and-minutes is a display format;
  two columns would put rounding bugs into every sum and report. Tracked time is summed from
  the rows, never accumulated into a total that can drift (§9.8).
- **Comments are soft-deleted** (§7.7): the conversation loses it, the audit keeps it.
  Replying to a reply attaches to its parent instead of nesting deeper (§7.8).
- **Activity, History and Transition have no write endpoint at all** (§22.4/§6.5/§11.9).
  They are produced by the services that perform the change. Immutability is not a UI
  decision here — there is nothing to call.
- Comment bodies go through `RichTextSanitizer`, same as descriptions (§22.1).

### Permissions (§20)

Editing a comment is the author's alone; deleting is the author's or a project manager's.
Same for updates and worklogs. Every write re-checks the work item against the project and
the user against the ability — a work item id in a URL proves nothing (§21).

### UI

Seven tabs in the required order, **All** default (§4.2), `aria-selected` on the active one,
horizontally scrollable in the drawer (§4.5), and the choice mirrored into `?tab=` so a link
can point at one (§4.4). Skeleton rows while loading, per-tab empty states in the spec's own
words (§17), and a retry on failure that does not discard what was typed (§16.3). Tracked
time sits in the tab bar on Worklogs.

### Deferred

Priority 2's collaboration extras — @mentions, reactions, comment deep links, update comments
— and Priority 5 — real-time sync (needs Reverb), optimistic UI, infinite scroll beyond the
25-record page, advanced filtering and sorting controls. Also: the Worklogs tab is meant to
follow a **Project Settings → Features → Time Tracking** toggle (§9.2); no such setting
exists yet, so it is on for every project and `bootstrap.timeTracking` is the single line
that changes when it ships.

### Detail panel: defaults and performance (follow-up)

- **Dependencies and Relations open collapsed.** They are reference material about *other*
  work items; expanded by default they pushed the description and the conversation below the
  fold on every open. Sub-work items and Links stay open.
- **The description editor is raised by ⋯ → Edit**, not mounted with the panel. Reading a
  description is far more common than writing one, and an always-present rich-text editor
  pays its initialisation on every open. The read view shows the stored markup; an empty
  description offers "Add a description…". Save and Cancel sit under the editor.
- **Inline edits now confirm.** `patchItem` only ever toasted on failure, so a successful
  chip edit was silent — nothing distinguished "saved" from "the click missed". Each toast
  names what changed ("Priority updated.", "Due date cleared.").
- **The create modal sits above the drawer** (`z-100` vs `z-85`). "Create new sub-task"
  opened it from inside the drawer, where it rendered *behind* the drawer's panel: open, but
  invisible.

Three fixes for the slowness on update, measured rather than guessed:

| | Before | After |
|---|---:|---:|
| `structureFor()` queries | 26 | 9 |
| `feed()` queries | 14 | 11 |

- **Relations were read six times** — once per group, each dragging four eager loads — to
  fill a panel that is usually mostly empty. They are now one query, with the direction
  decided in PHP: the same row is "blocking" from one end and "blocked by" from the other.
- **History and tracked time were fetched twice** per feed build. History is a filter over
  the activity rows already in hand.
- **The grid stopped rebuilding itself on every chip edit.** `replaceData()` regenerates and
  regroups the whole table; a changed priority now updates that one row, and the full rebuild
  is reserved for when a row has to move between state groups. The forced post-refresh
  `redraw(true)` was also firing on every refresh of a one-item list, not just the first
  time the grid became visible.

## Files Changed

| File | Change |
|---|---|
| `database/migrations/2026_08_16_000001..4` | **New** — `projects.work_item_sequence` (later dropped), `work_items`, `work_item_assignees`, `work_item_labels`. |
| `database/migrations/2026_08_19_000001..2` | **New** — `tenants.work_item_sequence`; work item IDs switched to workspace-unique numbers, existing rows renumbered. |
| `config/projects.php` | **New keys** — `workspace_tabs`, `default_item_states`, `work_item_priorities`, title/description caps, list page size. |
| `app/Models/WorkItem.php` | **New** — tenant-scoped model with `state`/`parent`/`children`/`assignees`/`labels` relations and `forProject`/`active` scopes. |
| `app/Services/ProjectItemStateProvisioner.php` | **New** — idempotent lazy seeding of a project's work-item states + default-state lookup. |
| `app/Services/WorkItemCreator.php` | **New** — atomic workspace-unique ID number allocation + create + assignee/label sync. |
| `app/Http/Requests/Project/StoreWorkItemRequest.php` | **New** — create validation; every referenced record checked against this project/workspace. |
| `app/Http/Controllers/Project/WorkItemController.php` | **New** — list (bootstrap payload) + create. |
| `app/Http/Controllers/Project/ProjectWorkspaceController.php` | **New** — Coming Soon page for the five non-MVP tabs. |
| `app/Policies/WorkItemPolicy.php` | **New** — reach (project view) + contributor/read-only split. |
| `app/Policies/ProjectPolicy.php` | Added the missing `manage()` ability (see reasoning §6). |
| `app/Http/Controllers/Project/ProjectController.php` | `show()` now redirects into the Project Workspace at Work Items. |
| `routes/project.php` | `projects.work-items`, `projects.work-items.store`, `projects.workspace.tab`. |
| `resources/views/partials/project-tabs.blade.php` | **New** — project header + six-tab bar. |
| `resources/views/projects/work-items.blade.php` | **New** — Work Items shell (Tabulator + Vue mount). |
| `resources/views/projects/coming-soon.blade.php` | **New** — Coming Soon tab page. |
| `public/assets/css/work-items.css` | **New** — POC grid skin, scoped to `#wi-table`. |
| `public/assets/js/projects/work-items.js` | **New** — Tabulator list grouped by state, Create work item modal, and the `wi-calendar` component ported from the POC's `datePicker()`. |
| `app/Services/ProjectNavigation.php` | **New** — the sidebar's project list, extracted from `ProjectController::navProjects()` so the workspace screens render the same list (and gained `work_items_url`, needed because `projects.show` redirects and would drop `?create=1`). |
| `resources/views/partials/app-sidebar.blade.php` | The dead **New work item** button became a working link (global create action, §4.3), gated on create rights. |
| `resources/views/partials/project-tabs.blade.php` | **New** — project header, six-tab bar, and the `⋯` project-actions menu. |
| `config/projects.php` | Added the missing `settings_nav` registry (Project Settings was 404ing for every section); Features marked Coming Soon. |
| `app/Models/Project.php` | Added the missing `states()` / `labels()` relations (Project Settings → States/Labels were 500ing) plus `workItems()`. |
| `app/Models/WorkItemActivity.php`, `app/Services/WorkItemActivityRecorder.php` | **New** — the append-only activity feed and the single funnel that writes it. |
| `tests/Feature/Project/WorkItemsTest.php` | **New** — 22 feature tests. |
| `ProjectAccessTest`, `ProjectVisibilityLifecycleTest`, `TopbarPresenceTest` | "Can open a project" assertions now use `followingRedirects()`, since opening a project enters the workspace. Intent unchanged, and they now cover both gates. |

`resources/views/projects/show.blade.php` is now unreferenced — left in place rather than
deleted, since its content is the natural starting point for the **Overview** tab.

## Pre-existing issues (NOT fixed — out of scope)

`php artisan test tests/Feature` → **149 tests, 119 passed, 6 failed**. All 6 predate this
work and are already logged in `docs/features/project-card-actions.md`:

1. `ProjectController@store` returns 200 where `CreateProjectTest` expects **201** (5 tests).
2. `CreateProjectTest::test_owner_can_open_the_projects_screen` asserts `assertSee('Add
   Project')` against what is now a Vue shell.

Also still open: the ~24 tests erroring on `ProjectTestCase::makeProject()` being called with
swapped arguments, and the `UpdateProjectRequest` visibility rule comparing against config
*labels* instead of keys (see `docs/features/project-edit-modal.md`).

## How to verify locally

```bash
php artisan migrate
php artisan test tests/Feature/Project/WorkItemsTest.php   # 22 passed

# Vue template syntax (a broken template blanks the page with no PHP error)
node -e "global.PB={boot:(n,c)=>global.__C=c}; require('./public/assets/js/projects/work-items.js'); \
  require('@vue/compiler-dom').compile(global.__C.template, {onError:e=>{throw e}}); \
  console.log('template OK')"
```

Then open `http://127.0.0.1:8000/projects` and click any project: it lands on Work Items with
the six tabs above it. **Add work item** (or a state group's `+`) opens the create modal; the
saved item appears in its state group immediately with the next ID number. The other five tabs
show Coming Soon. Assets are cache-busted by `pb_asset()`, so no hard refresh is needed.

## The Parent property became editable (§5 / §25)

Parent was display-only in the drawer: the only way to set one was to create the item from a
parent's sub-task panel. It is now a chip that opens the **same** search modal sub-tasks and
relations already use — the question ("which work item?") is identical, only the arity differs,
so `picker.mode` carries that rather than a second modal that looks almost like the first. One
parent per item, so picking replaces rather than adds, the footer reads "Set parent", and a
separate ✕ clears it.

Making it editable is what forced two server changes:

- **Loop prevention.** `Rule::notIn([$item->id])` stopped an item parenting *itself*, but
  A → B → A is just as circular. The walk that catches this already existed in
  `WorkItemRelationManager::assertCanParent`, reachable only through the sub-task route; it is
  now split into `parentRefusal()` and consulted by `App\Rules\ParentAssignable` on the PATCH
  route too. Both directions ask the same question, so they share one answer.
- **The picker hides what the rule would refuse.** `?for=parent` drops the item and every
  descendant from the search results. A row you are allowed to click and not allowed to keep is
  a worse answer than a row that is not there.

The row payload also gained a `parent` object (id, identifier, title). Resolving the label from
the loaded list only worked while the parent happened to be on screen — a parent in another
project, or one filtered out, rendered as "None".

---

## Structure writes return the rows they changed

**Symptom reported:** work item 10 was blocked by work item 8, but the list showed no
**Blocked** chip until the page was reloaded.

**Why.** `blocked_by_count` is a row property, and the relation endpoints answered with only
`structure` — the drawer's own sections. So the drawer was right and the grid was stale. The
asymmetry is what made it easy to miss: the chip belongs to the item being **blocked**, which
is normally *not* the item the request was made against, so even re-fetching the open item's
row would not have fixed it.

**Change.** `WorkItemStructureController::payload()` now also returns `cards` — the rows the
write affected, in the same shape `WorkItemScreenPayload` renders the list with, both ends of
the relation included. Client-side, `applyStructure()` hands them to a new `mergeCards()`,
which replaces matching rows in `items` and redraws just those rows. Rows not currently in the
list are skipped, not appended: the list is filtered and paged, so an absent item was left out
deliberately. The drawer needs no separate update — `drawerItem` is computed from `items`.

Covered by `test_a_relation_write_returns_the_rows_it_changed`, which asserts on the *blocked*
item's count rather than the requested item's, because that is the case that was broken.

**Still not real-time.** This makes *your own* actions show immediately. A change made by
someone else in another browser still needs a reload — nothing in the app broadcasts yet
(`BROADCAST_CONNECTION=log`, no events, no `config/reverb.php`, Echo never booted on the
page). See CLAUDE.md §8/§12; that pipeline is unbuilt.
