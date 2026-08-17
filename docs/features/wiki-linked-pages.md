# Wiki — Linked Pages

Source: *ProjectBlock 3.0 — Wiki Linked Pages Requirements*. Extends
[Wiki & Knowledge Management](wiki.md) and [Pages](pages.md).

Not built yet. This file is the specification. It is also what the **Link a page** button on a
collection — disabled since Slice 3 — has been waiting for.

## Requirement

Three operations, reached from one **Linked Pages** modal:

| | |
|---|---|
| **Move** a Wiki page | to another collection. It leaves the first one. |
| **Duplicate** a Wiki page | into another collection. A new page, its own id, the original untouched. |
| **Link** a Project Page | into a collection. It stays under Project → Pages, and the same underlying page appears in both. |

Everything stays inside the current workspace, on both sides, enforced in the backend and not
only in the picker.

## The architecture, and why

**A linked Project Page is a `wiki_pages` row that points at it.** `source_type` and
`source_page_id` on the existing table; the content is never copied, only referenced.

The requirements' §14 suggests a separate `wiki_collection_page_links` table. The reason not to:
a link row has **no position, no group and no parent**, so a linked page could not be dragged,
filed under a section, or nested under another page — and being arranged alongside the rest is
most of what "appears inside the collection" means. It would also force `WikiReader::sections()`,
`current()`, `tree()`, `WikiCoverCards`, `PageController::pages()`, `CollectionController::payload()`
and the reader's navigation each to merge two sources, and every one of those is a place the
merge could be forgotten.

As a row, a linked page gets all of that for free and every existing list keeps working untouched.
`ProjectPage`'s own docblock already anticipated this: *"§14 wants these pages to join the Wiki
later without their content being migrated or recreated. That is why the content lives in one
column on one row with a parent pointer."*

## Database Fields

### `wiki_pages` — two new columns

| Column | Type | Notes |
|---|---|---|
| `source_type` | string(20) nullable | `null` = an ordinary Wiki page. `project_page` = a pointer |
| `source_page_id` | unsignedBigInteger nullable | the `project_pages` row it stands for |

- `unique(wiki_collection_id, source_type, source_page_id)` — §12's "do not create another
  identical link", enforced by the database rather than by a check somebody could race.
- **No foreign key** on `source_page_id`: the target table is chosen by `source_type`, and today
  there is one value but the column is a string precisely because there will be more.
- `title` and `content` stay **null** on a linked row. Filling them would be the copy this feature
  exists to avoid, and the day somebody renamed the project page the two would disagree.

`WikiPage` gains:

```php
public function isLinked(): bool          // source_type !== null
public function sourcePage(): BelongsTo   // ProjectPage, when source_type is project_page
public function getTitleAttribute()       // the source's title when linked, else the column
public function getContentAttribute()     // the source's content when linked, else the column
public function scopeWithSource()         // eager-loads it — see below
```

**The accessors are why `scopeWithSource()` is not optional.** A collection's page list renders
every title; resolving each through a lazy relation is one query per linked page. Every query that
lists pages adds the scope — `WikiReader::sections()`, `PageController::pages()`,
`CollectionController::payload()` — and a test asserts the query count does not grow with the
number of links.

## Business Rules

### Move — WLP-1 … WLP-5

- **WLP-1 — the subtree moves with it.** A page's sub-pages are part of it; leaving them behind
  would strand them under a parent that is no longer in their collection, and the tree that drew
  them would break. Moving a parent moves everything beneath it, in order.
- **WLP-2 — `wiki_group_id` is cleared.** Groups belong to a collection
  (`wiki_collection_groups.wiki_collection_id`), so the section a page was filed under does not
  exist in the destination. It arrives ungrouped, which the Group view already draws.
- **WLP-3 — `parent_id` is cleared for the page being moved**, and preserved within the subtree.
  The page arrives at the top level of its new collection; its own children stay under it.
- **WLP-4 — appended, never inserted.** It lands at the end of the destination's order rather than
  displacing an arrangement somebody chose — the rule collections, pages and cover cards already
  follow.
- **WLP-5 — one transaction.** A move that half-happens leaves a page in two collections or in
  none. §18 asks for this and it is the one operation where a partial write is unrecoverable.

### Duplicate — WLP-6 … WLP-8

- **WLP-6 — a copy is a new page.** Its own id, its own row, `title = "{Original} – Copy"`,
  content and labels copied, the original untouched. Renaming it is the ordinary page rename.
- **WLP-7 — the copy is a leaf.** Sub-pages are not duplicated. "Duplicate this page" is a
  statement about one page, and silently copying nine more underneath it is not what anybody
  meant by it.
- **WLP-8 — a linked page cannot be duplicated.** It has no content of its own to copy, and
  copying the project page's content is exactly what this feature exists to avoid. The modal
  offers **Link** instead, into the other collection — which is the thing they actually wanted.

### Link — WLP-9 … WLP-12

- **WLP-9 — the Project Page does not move, change or gain a copy.** Its project, ownership,
  hierarchy and id are untouched. Edits on either side are edits to the same row, because there is
  only one row.
- **WLP-10 — one link per collection**, enforced by the unique index. A second attempt answers
  *"This page is already linked to this collection."* The same page may be linked to several
  collections.
- **WLP-11 — a linked page is read-only inside the Wiki, for now.** §7.4 wants edits from the Wiki
  to update the underlying page "subject to permissions", and that is right — but the Wiki editor
  saves through `PageController::update()`, which gates on the *collection*. Routing a save into a
  project page whose own policy has not been consulted is how a Wiki edit permission becomes a
  Project edit permission. Phase 1 opens it read-only with a link to the page in its project;
  editing through the Wiki arrives when `ProjectPagePolicy@update` is checked at that call site.
- **WLP-12 — deleting the link does not delete the page.** Removing it from the collection removes
  the pointer. The same posture "Remove from collection" already takes.

### Everywhere — WLP-13 … WLP-16

- **WLP-13 — the workspace is the boundary, checked server-side.** Both models are
  `BelongsToTenant`, so a cross-tenant id resolves to nothing — but the check is written
  explicitly rather than left to a global scope, because §8 asks for a rejection and "the query
  returned nothing" is not one.
- **WLP-14 — permissions are re-checked at submission**, not only when the picker was drawn (§13).
- **WLP-15 — every operation is one transaction**, and a failure leaves nothing behind.
- **WLP-16 — pickers are permission-filtered and searched server-side.** `Project::scopeVisibleTo`
  and `WikiCollection::scopeVisibleTo` already exist and are exactly these filters. §11 asks for
  type-ahead against the server rather than loading every project and page up front.

## Permissions

| Operation | Source | Destination |
|---|---|---|
| Move | `writableBy` the current collection | `writableBy` the destination |
| Duplicate | `openableBy` the source | `writableBy` the destination |
| Link | `ProjectPagePolicy@view` on the project page | `writableBy` the destination collection |

### The disclosure question, stated plainly

**Linking is a publishing decision, and reading then follows the collection.** Once a project page
is linked into a collection, everyone who can read that collection can read the page — including,
if the collection is published, the open internet, and including any external guests invited to it.

That is what "the page appears inside the collection" has to mean; a row whose content is blank
for half the readers is not a page. But it is a real disclosure vector, so:

- The person linking must be able to **view the project page** and to **write the collection** —
  they can already see both sides, and the act is theirs.
- The modal **names the audience** before the click: *"Everyone who can read {Collection} will be
  able to read this page."* — and says so more loudly when the destination is **published** or has
  **external members**.
- Linking into a collection with external members or a live public URL is worth logging under the
  same audit trail the guests feature already writes.

*Open question 1 below asks whether that is acceptable or whether a linked page should instead be
hidden from readers who cannot see its project.*

## UI Requirements

**Entry point.** The **Link a page** button already on the collection toolbar — disabled since
Slice 3 — becomes **Linked Pages** and opens the modal. Each page row's ⋯ menu gains **Move** and
**Duplicate**, which open the same modal with the source fixed to that page: reaching an operation
about one row from that row is shorter than reaching it from a toolbar and then choosing the row
again.

```text
Linked Pages
──────────────────────────────────────────────
Source     [ Wiki pages ] [ Project pages ]

Action     ( ) Move page      ( ) Duplicate page

Page       [ Escalation process            ▾ ]
Destination[ Engineering Handbook          ▾ ]

           Moving takes this page and its 3 sub-pages
           out of Help Desk Software.

                        [ Cancel ]  [ Move page ]
```

- Source type is a segmented control; the Action row is absent for Project pages, where there is
  only **Link to collection**.
- Every selector is the app's existing searchable `pb-combo`, which already does type-ahead,
  keyboard navigation and empty states — fed from a server-side search endpoint rather than a
  preloaded list.
- The destination combo **excludes the current collection** for Move, and marks collections the
  user cannot write as unselectable rather than hiding them, so "why isn't it there?" does not
  become a support question.
- Move states its consequence before the click, including the sub-page count.
- Duplicate lets the title be edited before it is created, defaulted to `… – Copy`.

## Real-Time Requirements

None.

## Queue Requirements

None. All three operations are a handful of rows inside a transaction.

## Audit Requirements

Move, Duplicate and Link are logged with the source, the destination and the actor (§18).

## Build plan

**Slice 1 — Move and Duplicate.** Not built. No migration needed: both operate on `wiki_pages` as
it stands. The two endpoints, the destination search, the subtree rules (WLP-1 … WLP-8) and the
transactions.

~~**Slice 2 — the link.**~~ **Delivered**, and taken first: the **Link a page** button had been
disabled since Slice 3 of the Wiki, and that is what it promised.

`wiki_pages` gained `source_type` and `source_page_id`, with
`unique(wiki_collection_id, source_type, source_page_id)` — §12's "do not create another identical
link" enforced by the database rather than by a check two simultaneous requests could both pass.

- **`title` and `content` stay NULL on a linked row** and resolve through accessors. There is
  nothing to keep in step because there is only one document: renaming the project page changes
  what the Wiki shows, and a test asserts exactly that.
- **`scopeWithSource()` is applied everywhere pages are listed** — `WikiReader::sections()`,
  `PageController::pages()`, `CollectionController::payload()`. The accessor resolves through a
  relation, so without it a collection's list is one extra query per link.
- **A deleted source renders as *Unavailable page*, with no content.** The relation loads
  `withTrashed()` so the row can still be drawn — but going on to print a soft-deleted page's
  title would keep showing content somebody deleted, which is the one thing deleting it was for.
  Found by a test, fixed in the accessor rather than asserted around.
- **Already-linked pages are absent from the picker** rather than offered and then refused, and
  a project with **Pages switched off** is not listed — it has nothing to offer. (Pages is off on
  a new project; the picker filtering on it is what makes that visible rather than confusing.)
- The page picker answers an **empty list** for a project the viewer cannot open, not a 403:
  whether that project exists is not that endpoint's to disclose.
- Both searches are **server-side** endpoints and accept `q`. The combo box currently filters the
  first 100 client-side; wiring its own input to `q` is a small change and the endpoints are ready
  for it.
- The modal **names the audience** before the click — *"Everyone who can read this collection will
  be able to read this page."*

**Files:** `2026_09_25_000011_add_source_to_wiki_pages.php` · `App\Models\WikiPage` (pointer,
accessors, `withSource`, `sourceIsMissing`) · `App\Http\Controllers\Wiki\LinkedPageController` ·
`routes/wiki.php` · `CollectionController::payload()` · `PageController::pages()` ·
`WikiReader::sections()` · `wiki-collection.js` · `tests/Feature/Wiki/LinkedPagesTest.php`
(14 tests).

**Not yet done from the spec:** a linked page still opens in the Wiki editor rather than read-only
(WLP-11), and unlinking is the ordinary "Remove from collection", which archives the pointer row
rather than deleting it.

**Slice 3 — the edges.** Unlinking from the ⋯ menu, the "already linked" message, the audit
entries, and the louder warning when the destination is published or has external members.

## Decisions

| # | Decision | Why |
|---|---|---|
| L1 | A linked page is a **`wiki_pages` row with a pointer**, not a row in a link table | It inherits position, group and parent, so it can be arranged like any other page — and no existing list has to learn to merge two sources |
| L2 | `title` / `content` stay **null** on a linked row and resolve through accessors | Storing them would be the copy this feature exists to avoid, and they would drift the first time the project page was renamed |
| L3 | **Move takes the subtree**; Duplicate does not | Children are part of a page; a copy is a statement about one page |
| L4 | Move **clears the group** | A section belongs to a collection, so the destination has nowhere to put it |
| L5 | A linked page **cannot be duplicated** | There is nothing of its own to copy; Link is the operation they wanted |
| L6 | A linked page is **read-only in the Wiki** in Phase 1 | The Wiki's save path gates on the collection; routing it into a project page would turn a Wiki permission into a Project one |
| L7 | Reading a linked page **follows the collection** | "It appears in the collection" cannot mean a page that is blank for half its readers. The disclosure is the linker's decision, and the modal names the audience |
| L8 | Pickers search **server-side** | §11, and a workspace with a thousand pages cannot preload them |

## Open questions

1. **The disclosure rule (L7).** Linking a project page into a published collection puts it in
   front of everyone who can read that collection — including external guests and, if published,
   anyone with the link. Is that the intent, or should a linked page be hidden from readers who
   cannot see its project? The second is safer and produces collections that read differently for
   different people.
2. **Should Move be offered at all for a page with sub-pages**, or only for leaves in Phase 1?
   Proposed: offered, with the count stated (WLP-1).
3. **Does `docs/features/pages.md` want the reverse direction** — a Wiki page linked into a
   project? Not in these requirements, and not proposed.
