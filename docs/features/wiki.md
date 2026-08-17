# Wiki & Knowledge Management

Source: *ProjectBlock — Wiki Product Detail Page*. A workspace-level knowledge system:
collections of pages, nested pages, manual ordering, public/private access, and archiving.

Built incrementally. This file grows one section per delivered slice; anything not listed under
**Delivered** is not built yet.

## Requirement

Give a workspace a home for knowledge that outlives any one project — policies, handbooks,
product and engineering documentation, SOPs, onboarding — organized into **collections** of
**pages**, with pages able to nest under other pages.

Distinct from Project Pages, which already exist: those document a *project*. Wiki documents the
*organization*. A requirement becomes a standard; a technical decision becomes architecture
documentation. Both use the same editor so nobody learns a second writing tool.

## User Roles

| Role | Can |
|---|---|
| Workspace Owner / Admin | Enable Wiki; create and administer any collection |
| Workspace Member | See and open public collections; see private ones they were invited to |
| Collection member — Edit | Create and update pages in that collection |
| Collection member — Read Only | Open and read pages, no changes |
| Collection member — Comment | *Planned.* Discuss without altering content |
| Guest | No Wiki access by default |

## Business Rules

- **WIKI-D1 — Wiki is a workspace capability, off until switched on.** It can be enabled while
  **creating** the workspace or at any time afterwards from **Settings → Wiki**. Nothing about
  the Wiki appears until it is on.
- **WIKI-D2 — one source of truth for "is Wiki on?"**: `workspace_settings.wiki_enabled`. The
  workspace-creation form sets that same flag rather than recording the chosen apps somewhere
  of its own; two records of one fact are two records free to disagree.
- **WIKI-D3 — Projects cannot be switched off.** It is the default app and the create screen
  shows it locked. An app list that can be emptied is a workspace that can do nothing.
- **WIKI-D5 — archiving a collection revokes access to it.** Only the creator and workspace
  owners/admins can open an archived collection; everybody else's invitation stops working. The
  membership rows are **kept**, so restoring returns access to the same people. Archiving only
  ever narrows access and never widens it — nobody gains anything by archiving something.
- **WIKI-D4 — turning Wiki off hides it, never deletes it.** Same rule the other feature
  toggles in this application already follow: collections, pages and their content survive, and
  switching it back on restores them exactly.

## Database Fields

Nothing new yet — `workspace_settings.wiki_enabled` already exists and is what enabling writes
to. Collections and pages arrive with the slice that builds them.

## Acceptance Criteria

- Wiki can be enabled while creating a workspace, and is off unless chosen.
- Wiki can be enabled and disabled afterwards from Settings → Wiki.
- Projects cannot be deselected at creation.
- An app that is not released cannot be enabled, whatever the form submits.
- A workspace created without Wiki has `wiki_enabled` false; one created with it has it true.

## UI Requirements

- **Create workspace → Enable apps** — Projects locked on; Wiki a real choice; unreleased apps
  shown as Coming Soon and not selectable.
- **Settings → Wiki** — the enable toggle (already built), plus wiki labels.
- Wiki navigation (Home / Collections / Shared / Private / Archived), collection and page
  screens: later slices.

## Real-Time Requirements

None.

## Queue Requirements

None.

## Audit Requirements

To be decided with the slice that builds collections.

---

## Delivered

### Slice 1 — enabling the capability
Wiki is selectable in three places, all reading and writing one flag through
`App\Services\WorkspaceApps`:

- **Create workspace → Enable apps.** `apps[]` was previously collected and **thrown away** —
  no controller read it — so the step looked like a choice and changed nothing.
- **Settings → General → Apps.** Lists what the workspace subscribes to, editable with the
  rest of the workspace's details. A request that says nothing about apps leaves them alone;
  an empty list means "none of the optional ones", or the card could never turn the last one off.
- **Settings → Wiki.** The original toggle, unchanged.

The *Enable apps* markup lived twice — `onboarding/workspace.blade.php` and
`workspace/create.blade.php` — and had already drifted, so releasing Wiki in one left the other
rendering it as a locked default. It is now one partial, `partials/workspace-apps.blade.php`.

**Navigation.** Enabling Wiki adds it to the left rail and opens `/wiki`. It exists because
enabling an app that leads nowhere reads as a setting that did nothing. `/wiki` 404s when the
workspace has Wiki off — hidden from the rail is not the same as unreachable.

Inside `/wiki` the sidebar becomes the Wiki's own: **New page**, then Home / Collections /
Shared / Private / Archived, then the Collections list. "A focused navigation experience" — so
work items, Inbox and Drafts are deliberately absent; this is a different room, not the same
room with extra doors.

Only the sidebar BODY swaps (`partials/wiki-nav.blade.php`). The rail, the workspace header,
the collapse control and the drawer script stay in `partials/app-sidebar.blade.php` rather than
being copied into a second panel that would drift — the mistake the *Enable apps* markup had
already made.

Everything not yet built is rendered plainly unavailable rather than as a link that goes
nowhere: a dead link is a bug report, a marked one is a roadmap.

### Slice 2 — collections
`wiki_collections`, tenant-scoped: name, description, `visibility` (public/private),
`position`, `archived_at`. Created from a modal reachable from the sidebar's **Collections**
row, the **+** beside its heading, and the Home empty state — one modal, every entrance.

- Visibility is a **string, not a boolean**: the requirements already hint at more than two
  answers, and `is_private = false` reads badly at the call site.
- New collections are **appended**, never placed first: §"Control the order of your knowledge"
  says documentation follows a sequence somebody chose, so a new one must not displace it.
- `archived_at` exists from the start — §"Archive instead of delete".
- Creating one repaints the sidebar list directly. A full page reload was the first attempt and
  is what the browser test caught: it throws away the toast and re-fetches a whole screen to
  add one row.

### Slice 3 — the collection detail, and who can open it
`/wiki/collections/{collection}` shows the name and its Public/Private badge, the description,
who has access, the **Add page** / **Link a page** actions, and the page list. Reached from the
sidebar and from the Home list.

`wiki_collection_members` — tenant-scoped: `user_id` + `permission` (`read` / `edit`).
`comment` is named in the requirements as *planned*, so permission is a string, and a request
carrying `comment` is refused rather than granted an access level nothing enforces.

- **The access list appears only on a PRIVATE collection.** On a public one everyone in the
  workspace can already read it, so a row of avatars would answer a question nobody asked and
  imply the list was the limit.
- **The creator is shown but cannot be removed.** Making your own collection private and then
  being locked out of it is a trap, not a permission.
- **Only the creator or a workspace admin changes access.** Being *on* a collection is not the
  same as controlling who else is.
- **Only workspace members can be invited.** Sharing a collection is not a side door into the
  workspace.
- Re-inviting somebody **updates** their permission rather than duplicating the row, and the
  picker stops offering anybody already on the list.
- A **pencil beside the collection name** edits its name, description and visibility, pre-filled
  so a typo is a correction rather than a retype. Behind the same gate as managing access, not
  the looser "can edit pages" one: turning a public collection private closes it to everybody
  not named on it, and the dialog says so before the click rather than after.

### Slice 4 — pages
`wiki_pages`, shaped after `project_pages` deliberately: same columns, same rules, so neither
has to be learned twice. **Add page** takes a name, then lands straight in the editor —
creating a document and then having to find it in a list is a step nobody wants, and it is the
flow Project Pages already use.

The editor is the **same `<pg-editor>`** Project Pages mount, from the shared partial. "Teams
shouldn't need to learn another editor" is the requirement; loading a second one here would
have made a liar of it. The screen matches that one too: breadcrumb and title in a header bar,
the toolbar as a full-width strip pinned above the document rather than glued to a field, and
the body held to the document measure.

- Bodies are **sanitized on the way in** — the editor's output is user input like any other.
- **Access follows the collection.** A page inherits its collection's answer rather than
  carrying one of its own, so a private collection's pages are closed to outsiders and a
  read-only invitation opens pages and changes none of them.
- **Reading a public collection does not grant writing it.** Visibility answers "who can see
  this"; it is not an answer to "who can change it".
- A page reached through the wrong collection's URL is **not found**, not merely refused.

### Slice 5 — the collection's page table
A collection lists its pages as a table: **Page name · Owner · Nested pages · Label · Last
activity · Actions**, with the content area widened to suit it (the 820px reading measure is
right for prose and wrong for six columns — the page editor keeps it).

`wiki_page_label` joins pages to the labels Settings → Wiki has always managed but nothing
could carry. It has **no `tenant_id`**, unlike every other table here: both sides are already
tenant-scoped, so a row can only join a page and a label from the same workspace, and a third
copy of the tenant is a third place it can disagree with the other two.

Row actions:
- **Edit** — name, what it nests under, and its labels. Separate from the body autosave, which
  writes every second or so and has no business carrying any of this.
- **Copy link** — the absolute URL; a relative one pasted into chat goes nowhere.
- **Remove from collection** — **archives**, never destroys. A page removed by mistake is a
  page somebody wrote.

Nesting is validated: not itself, and not a page from another collection. A page nested under
itself disappears from every tree that tries to draw it.

### Slice 6 — dragging the tree
Pages are reordered and nested by dragging, using **SortableJS 1.15.7** in its nested-list mode
(vendored at `public/assets/vendor/sortable/`, never a CDN — `SelfHostedAssetsTest` enforces it).

The list is a **list of lists, not a `<table>`**. Sortable nests by moving an element between
`<ul>`s, so the tree has to BE nested markup; the columns are a CSS grid (`.pb-wiki-row`) stated
once and reused at every depth, which is what keeps them aligned two levels down. A table could
not have nested; nested lists could not have aligned without the grid.

- An **empty child list is always rendered**. It is the drop target that turns a leaf into a
  parent — without it there is nowhere to drop onto a page that has no children yet.
- The **whole tree is sent** on drop, not "this moved there": one drop renumbers siblings on
  both sides of the move, and reconstructing that from a delta is guesswork the client has
  already done properly.
- The tree is read back **out of the DOM**, because Sortable moved the elements — the document
  is the authority on where things are, not the array that produced it.
- **Cycles are refused** server-side. A → B → A cannot be produced by dragging, but the endpoint
  is reachable without the UI, and a cycle makes every walk of the tree run forever.
- Ids from another collection in the payload are **ignored, not rejected**: the payload is the
  whole tree, and one stray id should not throw away a legitimate reorder of everything else.

The Edit dialog's **Nested under** still exists — searchable, since a collection can hold a lot
of pages — and remains the way to do this with a keyboard.

Rows with children carry a **disclosure chevron**; collapsed rows are remembered per collection
in `localStorage`, so a tidied tree stays tidy across navigations. The children list is hidden
with `v-show`, never `v-if`: the element has to stay in the document or Sortable loses the drop
target that makes the row a parent at all. Rows without children get a spacer instead of a
control, so titles stay aligned and nothing discloses nothing.

The page card is deliberately **not** `overflow-hidden`. It was, to clip the header fill to the
rounded corners, and it clipped the row menus too — the last row's actions opened into nothing.
The header rounds its own corners instead, and the menu flips upward near the foot of the
window.

### Slice 7 — status and the public URL
A collection carries a **status**: Draft (where everything starts), Published, Unpublished.
Published collections are served to anyone at **`/{workspace}/{public-slug}`**, signed out.

- The address is **generated once and never again**. People bookmark it, paste it into
  documents and send it to customers, so a second request is *refused* rather than quietly
  issuing a new one. There is no rename, by design — the UI says so where the decision is made.
- It is derived from the **name**, not a random string: a readable address is the one people
  trust enough to click. A counter is appended only on collision, and uniqueness is per
  workspace, since the path carries the workspace slug too.
- **A private collection cannot be published.** Publishing is a public act and "private" is a
  statement about who may read it; both at once is impossible, so it is refused rather than
  silently resolved one way. Making a *published* collection private takes it off the internet
  immediately, whatever the status column still says.
- Everything not published is a **404, not a 403**. A 403 confirms the address belongs to
  something real — exactly what an unpublished page must not tell the internet.
- Unpublishing **keeps the address**, so republishing restores the same one.

The public route is `/{workspace}/{slug}`, registered **last**, after every other route file —
and `config('workspace.reserved_slugs')` gained the application's own top-level paths (`wiki`,
`projects`, `inbox`, …) so a workspace can never be named after one. A test asserts `/wiki` and
`/settings/general` still belong to the application.

### Slice 8 — List view and Group view
A collection's pages can be looked at two ways, remembered per collection:

- **List** — the table with its columns and the nested drag-and-drop tree.
- **Group** — sections, each with a **name**, an optional **label**, a **short description**
  (one line, under the name) and a **long description** (the explanation beneath it).

`wiki_collection_groups`, plus `wiki_pages.wiki_group_id`. A page is in **one group or none**,
which is why the group hangs off the page rather than a pivot — grouping is a second way of
LOOKING at the same pages, not a second place to keep them.

- **Removing a group leaves its pages alone.** `nullOnDelete`, never cascade: removing a section
  is a decision about arrangement, and taking the pages with it would make an organising action
  destructive.
- **Ungrouped is always shown when it has anything in it.** A page filed nowhere must not
  disappear because the view changed.
- A page cannot be filed under **another collection's** group.
- Same write gate as pages: whoever may add a page may arrange them. Grouping is editorial, not
  administrative, so it does not need the tighter gate publishing and access use.

Both levels are draggable — **sections reorder among themselves, pages reorder within a section
and move between them** — and each section **expands and collapses**, remembered per collection.
A collapsed section keeps its list in the DOM (`v-show`, never `v-if`), so it stays a drop
target: filing a page into a section you are not currently reading is the case that needs it.

The "which group" control is the app's **searchable combo**, not a native `<select>`.

`pages/reorder` carries an optional `group_id`. A payload that omits it — the List view's —
leaves grouping untouched; a missing key must not read as "ungroup everything it touched".

> **Assumption worth confirming:** "Label Name" is implemented as free text on the group — a
> short tag like *Internal* or *v2* — rather than a reference to the workspace `wiki_labels`
> that pages carry. If it was meant to be the same label vocabulary, say so and it becomes a
> foreign key.

### Slice 9 — the reading layout
**Preview document** on a collection opens it in a new tab as a reader would see it: a
full-width header, then the navigation down the left and the document on the right. Shaped
after a documentation site (Mintlify): active page highlighted, and a filter box over the
navigation.

The navigation is a **fixed 240px** — the same width the application's own sidebar uses, so the
two feel like one product. A viewport fraction made it a 430px column of short titles on a wide
screen, which is space the document wanted. The viewport IS the page: header pinned, navigation
and document scrolling **independently**, because navigation is only useful if it is still
there when you are halfway down a long page.

**Preview and the published page render the same template** (`wiki/reader.blade.php`, fed by
`App\Services\WikiReader`). What you preview is what gets published, rather than an
approximation of it — and this feature has already been bitten twice by near-identical markup
living in two files and quietly drifting.

- The banner is the only difference, and it says whether the collection is published.
- Preview opens in a **new tab**: it is something you look at *beside* the collection you are
  editing.
- Preview works on a **draft** — deciding whether to publish is the whole point of it.
- Preview is closed to anyone who could not open the collection anyway.
- The nav filter is plain JS over markup already on the page; a round trip to hide four links
  would be theatre. A section whose pages are all filtered out hides with them.
- Ungrouped pages appear **last**, headed *Pages* or *More*. A reader does not care how the
  authors filed things.

**On this page** lists the document's headings, indented by level and in reading order. The ids
are written **server-side** (`WikiReader::outline`): an anchor has to work on the first paint,
and a link to `#timings` that only becomes real once a script has run fails exactly when
somebody follows it from elsewhere. Repeated headings — ordinary in documentation — get
distinct anchors, and a heading an author anchored deliberately keeps the id it already has.

H1 **and** H2, though only H1 was asked for: the editor's Heading buttons produce H2 for most
authors, so an H1-only contents would be empty on nearly every page that has headings, which
reads as broken rather than strict. The column is hidden entirely when there is nothing to
list.

> **Since extended to H1–H4** — see `wiki-cover-page.md` C10. H5 and H6 stay out: they are below
> the level anybody navigates by, and a column that lists them is a second copy of the document.

### Slice 10 — adding a page under a page, and the Access card

*"please add + button / With a drop i can sub-level too."*

Every row in the page table carries a **+** beside its **⋯**, opening a two-item menu: **Add
page** and **Add sub-page**. Both open the same naming modal; the second sends `parent_id`, and
the modal retitles itself and says which page the new one will sit underneath — the nesting is
visible before it is committed, not discovered afterwards in the table.

- Nesting is decided **while naming**. The tree could already be built by dragging, but that is
  create-then-move for something the author knew at the moment they clicked.
- A sub-page **inherits its parent's group**. Leaving it ungrouped would file a child away from
  its own parent, and Group view would show a heading its children were missing from.
- A parent from **another collection** is refused (422). A tree spanning two collections has no
  rendering — whichever one you opened would show half of it.
- Both controls live in **one grid cell**. `.pb-wiki-row` is a six-column grid; a seventh child
  starts an implicit column and knocks every row out of alignment. The cell was widened to fit.

Each **group** carries its own **+ Add page**, and an empty group offers *add one* in place of
the "drag one here" line — advice that is no help when there is nothing anywhere to drag. The
page arrives already filed, and the modal names the section before the title is typed.

- Group view is where somebody is thinking in **sections**. A page added from a section's own
  header landing at the foot of *Ungrouped*, to be dragged back up to where they were already
  looking, is the app undoing the thing it was asked to do.
- `group_id` is validated against **this** collection, same as `parent_id`.
- A **parent wins over an explicit group**: a child in a different section from its own parent
  would appear under a heading its parent is absent from.

**Access** now sits in its own bordered card, matching the Public URL card below the table. It
was previously loose markup under the header, so the one section on the screen that answers
*who can read this* looked less deliberate than the one that answers *where can they read it*.

### Slice 11 — the collection's own ⋯

The pencil beside the collection name is gone; in its place, at the end of the header, is the
same **⋯** the page rows already carry: **Edit collection · Archive collection · Delete
collection**. Two ways to reach Edit — one of them hidden inside a menu holding the other two —
read as two different actions.

All three are behind `canManage`, not the looser page-editing gate. Renaming a collection,
retiring it and destroying it are decisions about what the collection IS.

**Archive asks first, and names what it costs.** Not *"are you sure?"* — the dialog says the
collection leaves Collections for Archived, that its pages, groups and settings are all kept, and
that **everyone invited to it loses access until it is restored**. That last part is the half
nobody expects from a word as gentle as "archive", and it is the reason the confirmation exists at
all. A published collection is told that its public URL goes down immediately.

**Restoring does not ask.** It only ever gives access back, and warning somebody before an
additive change is how people learn to click through warnings without reading them.

**Archive** is `archived_at`, and the same control **restores** it. The Archived view is still
not built, so a one-way archive would be the last thing anybody could ever do to a collection —
its own page stays open to the people on it, which is how somebody gets back to undo it. An
**Archived** badge sits beside the name, because a collection out of every list otherwise looks
identical to one that is merely unlisted.

**Delete** is the one destructive action in the Wiki, and the only red thing on the screen:
pages, groups, members and the cover follow by foreign key and none of it returns. The dialog
names what goes, offers **archive it instead**, and asks for the collection's name to be typed —
the same gesture deleting a project already asks for. A page removed by mistake is a page
somebody wrote; a collection is all of them at once.

### Slice 12 — Shared, Private and Archived, and what archiving costs

The three rows that carried a **Soon** pill are screens. All four list screens — Home, Shared,
Private, Archived — are **one controller action, one route, one view**: they answer the same
question (*which collections should I be looking at?*) from the same builder, differing by a
where clause. `/wiki/{section}` enumerates its segment, so `/wiki/nonsense` is a 404 rather than
an empty list under a heading nobody wrote.

- **Private** is private collections **you made**; **Shared** is private ones **somebody else**
  made and put you on. A public collection is in neither: visible to everyone is not shared with
  you, because nobody made a decision about *you*.
- **A lens, never a move.** A private collection listed under Private is still under Collections.
- Archived reads **most recently retired first**. `position` arranges the things you navigate,
  and these have left navigation.
- Shared spells out its `created_by IS NULL` branch: SQL drops NULL rows from `!= $id`, and a
  collection whose creator's account was deleted would vanish from the one list still carrying it.
- Shared and Archived offer **no create button** — you cannot create your way into being invited.
  Private's does exist and opens the modal **already set to Private**, or the screen would be
  contradicting itself.

**Archiving revokes access (WIKI-D5).** Until now an archived collection was fully open to
everyone on it and had merely left the lists — that is hiding, not archiving. It is now closed to
its members entirely; only the creator and workspace owners/admins can open it. **The membership
rows survive**, so restoring hands the collection back to exactly the same people — the whole
difference between archiving and deleting. It narrows **who**, not what they may do: whoever can
still open it can still write to it.

The rule is one line in `WikiCollection::openableBy()`, and the `&&` in it is load-bearing.
`archived || manageableBy` would let a workspace admin who is *not* on a private collection
archive it and thereby be able to **read** it — archiving would become a way into something you
were never invited to. `writableBy()` delegates to `openableBy()` rather than restating it, so the
rule is written once and every guard inherits it.

That collapsed the duplication it was written into: `readableBy()` answered half the question and
the other half was copy-pasted into five guards, three of them byte-identical. There are now four
statements of access on the model — `manageableBy`, `openableBy`, `writableBy`, and the query
scope `visibleTo` — and each guard is two lines.

**Every list is permission-filtered.** `visibleTo()` is the query twin of `openableBy()`, and it
closed a live leak: the sidebar and Wiki Home listed *every* active collection in the workspace,
so a private collection you could not open was named to you with a lock icon and 403'd on click.
Naming it had already told you it existed, which is the one thing "private" was there to prevent.
The scope is applied on **every** branch, including the ones where it is provably a no-op —
uniform application is what makes "every list is filtered" a property of the code rather than a
claim about it.

Two boundaries held deliberately:
- A workspace admin still **does not see private collections they were never invited to**. The
  visibility dialog's own copy promises that. Admins can *administer* a collection they cannot
  read — archive, delete, manage access — which is the ordinary shape of administration; if they
  need to read it they add themselves, an act visible in the access list rather than a silent one.
- The sidebar's collection list stays **active-only**. An archived collection you are reading is
  not highlighted there; the Archived row above is. Putting it back would undo the one thing
  archiving does.

**New page works from anywhere in the Wiki**, and is no longer the disabled button it had been
since Slice 1. It opens a modal asking for a **page name** and a **collection** — and the
collection field is the reason this can be a global action at all: a page needs one, and the
sidebar does not know which. Both fields are required and Create stays down until both are
answered, so *which collection?* cannot be settled by accident.

- The picker asks **`writableBy`**, not merely what is visible. Offering a collection the create
  would refuse is a trap; `visibleTo` narrows it in SQL and `writableBy` answers per row, which is
  a query per collection and fine for a list somebody scrolls.
- With **one** writable collection it is pre-chosen — a list of one is a question with no
  information in it. With **none**, the field is replaced by *"There is no collection you can add
  a page to yet. Create one first"*, wired to the collection modal, rather than an empty combo
  that reads as a bug.
- Creating lands **straight in the editor**, the flow Project Pages already use.
- It reuses `POST /wiki/collections/{collection}/pages` unchanged. The collection is in the path,
  so the client is handed the shape and fills in whichever was chosen — the same `__ID__`
  convention the collection screen's endpoints already use. No new endpoint.

**The Collections "+" works from anywhere in the Wiki.** It is an `<a href="/wiki?create=1">` now,
not a button — the create modal is mounted by `wiki.js`, which only loads on the four list
screens, so on a collection or a page it was a control that did nothing. Where the modal exists
the click is intercepted and it opens in place; where it does not, the browser goes to Wiki home
and it opens on arrival, which also makes it work without JavaScript. `create` is taken back out
of the address on arrival, or a refresh reopens a dialog nobody asked for. The same shape the
Projects "+" in `app-sidebar.blade.php` has always used.

The sidebar's inline `WikiCollection::query()` moved into `App\Services\WikiNavigation`, bound by
a **view composer** — the partial rides along with the shared sidebar on every Wiki screen across
four controllers, and a permission filter each of them has to remember to apply is a filter that
will eventually be forgotten. Same reasoning, and the same shape, as the workspace switcher's.

## Not built yet

**Link a page**; search; page version history — Project Pages have it, these do not.

Known and deferred: archiving a **published** collection takes its public URL dark immediately
(`->active()`) while `status` stays `published`, so the collection screen still shows a Published
badge beside a URL that now 404s. Pre-existing. The fix is to refuse publishing while archived and
to have the screen read *Published · Archived — not live*.
