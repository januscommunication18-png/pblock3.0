# Drafts

Source: sidebar item "Drafts" (`partials/app-sidebar`), which shipped as `href="#"` alongside
Home / Your work / Stickies. Work Items §14 deferred drafts out of that phase
([work-items.md](work-items.md) "Deferred beyond this phase"); this is the build that lands them.

A **draft** is a work item captured before it has a home. You have the thought — "chase the
invoice PDF bug" — but not yet the project, the state, the assignee, or the patience to pick
them. The draft holds the thought. Choosing a project is what turns it into a real work item.

## Requirement

- A workspace-level **Drafts** screen, reachable from the sidebar on every authenticated page.
- Create a draft with nothing but a title. Description, priority and dates are optional.
- Drafts are **private to the person who wrote them** — nobody else in the workspace can see,
  edit or publish another user's drafts, regardless of role.
- Drafts have **no project** until they are published. That is the whole point of the feature.
- **Publish** picks a project (and optionally a starting state) and promotes the draft into a
  real work item, at which point it gets its workspace-unique ID and behaves like any other.
- **Discard** deletes the draft outright.

## User Roles

| Role | Drafts |
|---|---|
| Workspace Owner / Admin / Manager / Member | Full — create, edit, publish, discard **their own** drafts |
| Workspace Viewer / Guest | **None.** The sidebar item is hidden and the routes 404. They cannot create work items (Work Items §7), so a draft would be a note they could never publish. |

Publishing is additionally gated per project: the target project must be one the user could
have created a work item in directly (`WorkItemPolicy@create`). No role gets to reach another
user's drafts — an Owner cannot read a Member's scratch pad.

## Database Fields

No new table. Drafts are rows in **`work_items`** (decision D-D1 below), tenant-scoped by
`BelongsToTenant` like every other work item; their images are rows in **`work_item_media`**.

`2026_09_20_000001_add_drafts_to_work_items_table`:

| Column | Change | Why |
|---|---|---|
| `is_draft` | **new** `boolean default false` | The flag the global scope filters on. |
| `project_id` | `NOT NULL` → **nullable** | A draft has no project. |
| `sequence_no` | `NOT NULL` → **nullable** | The workspace ID number is allocated at publish, not at capture — drafts must not burn IDs they may never use. |
| `identifier` | `NOT NULL` → **nullable** | Same. |
| `(tenant_id, created_by, is_draft, updated_at)` | **new index** | Drives the Drafts list: one user's drafts, most recently touched first. |

The uniques are `(tenant_id, sequence_no)` and `(tenant_id, identifier)`; MySQL permits
repeated NULLs in a unique index, so any number of drafts coexist without collision.

**The invariant**, enforced in `WorkItem::isDraft()` and by the publisher:

```
is_draft = 1  ⇔  project_id IS NULL  ∧  sequence_no IS NULL  ∧  identifier IS NULL
```

A draft therefore carries only: `tenant_id`, `title`, `description`, `priority`, `start_date`,
`due_date`, `created_by`, timestamps. Everything else on a work item — state, labels,
assignees, parent, cycle, module, epic, estimate — is **project-scoped** and cannot be
validated without a project, so drafts do not offer them (D-D3).

## Business Rules

1. A draft requires a title. Everything else is optional.
2. A draft never has a project, a state, an identifier or any association. It cannot be
   assigned, labelled, parented, estimated, or put in a cycle/module/epic while it is a draft.
3. Drafts are excluded from **every** work item query in the application — lists, the Views
   grid, search, pickers, cycle/module/epic membership, counts, activity feeds. This is
   enforced by a **global scope** (`ExcludesDrafts`), not by callers remembering to filter,
   for the same reason tenancy is (CLAUDE.md §7).
4. Only `created_by` may read, edit, publish or discard a draft. Any other user gets a 404,
   which never confirms the draft exists.
5. Publishing requires a project the user may create work items in. It allocates the next
   workspace ID number under the same row lock as an ordinary create, clears `is_draft`, and
   writes the creation activity entry and the opening state transition — so a published draft
   is indistinguishable from an item created directly, and its history starts at publish.
6. The draft's `created_by` survives publication. The person who wrote it is the creator.
7. Publishing is atomic. If anything in step 5 fails, the row stays a draft and the ID counter
   rolls back with it.
8. Discarding deletes the row. A draft is a scratch note — there is nothing to preserve, and
   nothing has ever referenced it.

## Acceptance Criteria

- **DR-01** The sidebar Drafts link resolves to `/drafts` and renders the screen.
- **DR-02** A Viewer and a Guest get 404 from `/drafts`, and the sidebar hides the link.
- **DR-03** Creating a draft with a title alone succeeds; the row has `is_draft = 1`,
  `project_id`, `sequence_no` and `identifier` all NULL.
- **DR-04** Creating a draft does **not** advance `tenants.work_item_sequence`.
- **DR-05** A draft does not appear in the project Work Items list, and `WorkItem::count()`
  does not include it.
- **DR-06** A second user in the same workspace sees none of the first user's drafts, and
  gets 404 on GET/PATCH/DELETE/publish of one by id.
- **DR-07** A draft cannot be reached through a project work item route (`show`, `update`,
  `archive`, …) — all 404.
- **DR-08** Publishing to a project assigns the next workspace ID number, sets `project_id`,
  clears `is_draft`, and the item then appears in that project's Work Items list.
- **DR-09** A published draft has a `created` activity entry and an opening transition row.
- **DR-10** Publishing to a project the user cannot create work items in is refused, and the
  row stays a draft with the ID counter untouched.
- **DR-11** Publishing consumes the draft: it disappears from the Drafts list.
- **DR-12** Discarding removes the row.
- **DR-13** Editing a draft's title/description/priority/dates persists.
- **DR-15** The description is rich text: formatting survives, script does not, and an editor
  left empty stores `null` rather than its own `<p><br></p>` scaffolding.
- **DR-16** The screen loads the shared controls (`pb-combo`, `wi-calendar`, `pg-editor`,
  the work item glyph vocabulary) rather than reimplementing any of them.
- **DR-17** An image uploaded from a draft is stored project-less on the private disk, is
  readable by its uploader and by nobody else, and the gallery shows one author only their own.
- **DR-18** Publishing makes the images the description references readable by anyone who can
  see the project's work items, through the URL already in the markup. Images the description
  does not reference stay private.
- **DR-19** Publishing a description that quotes another user's draft image does not re-home it.
- **DR-20** A Viewer cannot upload draft images.
- **DR-14** A draft posted with a `project_id`, `state_id` or `assignee_ids` in the payload
  ignores them — the columns stay NULL.

## UI Requirements

One screen, `/drafts`, on the shared app shell (topbar + sidebar), Vue mounted into Blade
per CLAUDE.md §14. FlyonUI/Tailwind utilities only; no new custom CSS was needed, so no
`_moretogether` classes are added.

Nothing on this screen is a new control. Every one of them is the component the equivalent
field already uses elsewhere, which is the point — a draft is a work item, so writing one
should not feel like using a different product:

| Field | Component | Also used by |
|---|---|---|
| Description | `<wi-editor>` (Quill) | The work item detail's description |
| Start / Due date | `<wi-calendar>` | The work item Start/Due chips, Create Cycle |
| Priority, Project, State | `<pb-combo>` | The settings screens' pickers |
| Priority / state glyphs | `WI_PRI`, `wiStateIcon()` | The Work Items and Cycles lists |

- **List** — one row per draft: priority glyph, title, a description excerpt, relative
  "edited" time. Empty state explains what a draft is and offers the first one.
- **New draft** — primary button; opens the editor with an empty draft.
- **Editor panel** — title, the rich-text description, and a row of three comboboxes/date
  triggers of matching height, under a header carrying the save state and a **Save as draft**
  button beside Discard and Publish.
- **Publish** — modal with a searchable project picker (only projects the user may add work
  to) and an optional starting state, which re-seeds when the project changes.
- **Discard** — confirm, then delete.

### Saving

Five triggers, all of which flush the editor first: **a pause in typing** (1.2s), **leaving the
title or the editor**, **⌘/Ctrl+S**, **the Save as draft button**, and **leaving the page**
(a `keepalive` request on `pagehide`, which survives the navigation a normal `fetch` does not).
Picking a date or a priority saves immediately — a menu click has no blur to wait for.

The pause is the important one, and it is why the first build lost work. Blur alone is not
enough here: `<wi-editor>` emits blur when Quill loses *selection*, so clicking a plain area of
the page leaves the caret where it is and commits nothing. A pause in typing does not depend on
where the next click lands.

Only the deliberate saves confirm with a toast; the autosave fires after every pause, so it
announces itself at most every 15s and the header indicator carries the rest
(*Saving… / Unsaved changes / Saved 2m ago*). A save that fails leaves the draft dirty, so the
indicator never claims a save that did not happen.

Two ordering rules the autosave makes load-bearing rather than theoretical, because it fires
mid-sentence by design:

- The response **never overwrites the open editor**. It assigns back only the id — without
  which the next save would post a second draft — so text typed during the request survives,
  and the server's sanitized markup never lands back in Quill mid-sentence, which its
  `modelValue` watcher would treat as a document reload.
- An edit counter is captured before each request and compared after. A response only clears
  the dirty flag for the change it actually carried; anything typed while it was in flight
  stays unsaved and re-queues.

### How the description editor got here

Three attempts, recorded because the middle one is a trap worth not walking into twice:

1. **Plain textarea**, matching the work item *create modal*. Wrong instinct: a draft is where
   the thinking is written down, sometimes over days, which is the *detail* view's job, not the
   modal's.
2. **`<wi-editor>` (Quill)**, the work item detail's editor. It could not survive this screen —
   see the crash below. Two rounds of fixes narrowed it without closing it.
3. **`<pg-editor>` (Jodit)**, the Pages editor, minimum configuration. Its template is
   `<div class="pg-editor"><textarea ref="area"></textarea></div>` — **no reactive bindings at
   all** — so Vue renders it once and never patches the DOM the editor owns. That is a
   structural property, not a fix that has to keep holding.

### The crash, and the two rules that outlived it

Worth writing down because it does not announce itself — the editor keeps *looking* like it
works:

```
TypeError: Cannot read properties of null (reading 'offset')
  normalizedToRange → getRange → update          (inside quill.js)
```

Quill maps the **native** selection to a document position whenever it reconciles. When that
selection sits in a node it does not own, the mapping reads `.offset` off a null blot and
throws inside Quill's own MutationObserver — after which **Quill stops tracking changes
entirely**. Typing still edits the contenteditable, because that is the browser's doing, but
nothing reaches the model: paste appears to do nothing and every save posts the pre-crash text.
`<wi-editor>`'s own `toolbarHost` note records the same failure reached from another direction.

Both rules below were written for Quill and are kept under Jodit — not because Jodit needs
them, but because they are right for any editor embedded this way, and because `<wi-editor>` is
still the fallback:

1. **Never flush the editor unless it has focus.** `save()` here runs from the title's blur,
   from the date and priority menus, from the autosave timer and from `pagehide` — none of
   which leave the caret in the editor, and every one of them was asking the editor to
   reconcile against a selection somewhere else. Nothing is lost by skipping: content typed and
   then left behind was already emitted by the editor's own blur, which fires ahead of its
   debounce for exactly this reason. This screen is the first to flush from anywhere but its
   own submit, which is why it found this.
2. **Feed it a seed, not its own output.** Binding `:model-value` to the value the editor emits
   is a feedback loop — keystroke → new prop → component re-render → Vue patching DOM the
   editor is mid-mutation on. `editorSeed` changes only when the *open draft* changes, with a
   `:key` bump to remount; content comes back one-way through `@update:model-value`. Every
   other prop is a literal, so nothing about the editor changes while it is being written in.

The remount makes Quill hand the seeded document straight back in its own normalised form.
That echo is stored — it is what the next save posts — but deliberately does not mark the draft
dirty, or opening a draft would autosave it. Because that guard makes `dirty` a heuristic, a
save decides whether there is anything to send by comparing against the payload it last
**persisted**, not by trusting the flag: a Save button that reports success for an edit it
never sent is the one failure this screen must not have.

### Images

The existing upload endpoint is project-scoped (`/projects/{project}/work-items/media`) and a
draft has no project, so drafts get a **workspace-level twin**: `POST /drafts/media`,
`GET /drafts/media` (gallery), `GET /drafts/media/{media}` (serve). Same request and response
shapes — `file-N` in, `{"result":[{url,name,size}]}` or `{"errorMessage":"…"}` out — because
those are the editor's contract rather than ours, and both editors already speak it. Files go
on the **private** disk and stream through an authorized route, never the public disk: these
are images out of somebody's private scratch pad.

`work_item_media.project_id` becomes nullable to hold them, for the same reason it did on
`work_items`. A project-less row is readable **only by its uploader** — not by workspace
Owners or Admins — which is the rule the draft itself follows.

**Publishing re-homes the images the description references** onto the target project
(`WorkItemCreator::rehomeMedia`). Without that step every image in a published description
would be a broken box to everyone except its author. Three things about how:

- **Read out of the markup, not from a draft-to-media link.** The markup is the only record of
  what survived editing — an image inserted and then deleted again should not follow the item
  into a project it never appeared in.
- **The URL is never rewritten.** It was baked into the HTML at upload time, so
  `/drafts/media/{id}` has to serve two audiences over its life and answers to whichever
  applies: the uploader always, and — once the row has a project — anyone who can see that
  project's work items.
- **Scoped to the publisher's own uploads**, so a crafted description quoting somebody else's
  draft media id cannot annex it into a project they can read. Covered by a test.

## Real-Time Requirements

**None.** A draft is private to one author on one screen; there is no second party to notify
and nothing to keep in sync. Publishing produces an ordinary work item, so any notification
that already fires on creation — assignment mail, blocked notices — keeps firing on its own
terms. No new channel, no new broadcast event.

## Queue Requirements

**None.** Every action here is a single small write. Publishing is one transaction holding a
row lock, which is exactly what a direct create already does.

## Audit Requirements

Draft edits are **not** audited: the point of a scratch pad is that it has no history to
answer for, and `work_item_activity` is scoped to real work items.

Publication **is**, and is written inside the publishing transaction: a `created` activity
entry plus the opening `work_item_transitions` row. The item's recorded history therefore
begins the moment it became real, not the moment it was typed.

---

## Planning & Reasoning (Claude Code)

Followed CLAUDE.md §4: the three open product questions in the sidebar-link report were put to
the owner before any code, and the answers below are what this build implements.

### Decisions

| # | Question | Decision |
|---|---|---|
| D-D1 | Where does a draft live? | **A row in `work_items` with `is_draft`**, not a separate `work_item_drafts` table. Publishing is then a flag flip and an ID allocation rather than a copy between tables, so there is no second schema to keep in step with `work_items` as it grows, and no window where a draft and its published twin both exist. |
| D-D2 | How are drafts kept out of everything else? | **A global scope (`ExcludesDrafts`) on `WorkItem`**, so existing queries are correct without being touched. The alternative — adding `->where('is_draft', false)` across the controllers, services, the Views grid and the pickers — is a list nobody can finish and everybody can forget. This mirrors §7's rule for tenancy: isolation is automatic, never the caller's job. `WorkItem::drafts()` opts back in, and is the only thing that does. |
| D-D3 | Can a draft carry a state / assignee / label / parent? | **No.** Every one of those is validated against a project (`StoreWorkItemRequest` scopes all six by `project_id`), and a project-less draft has nothing to validate them against. Offering pickers that could only be checked at publish time would mean a draft that fails to publish because of a choice made days earlier. The draft holds the free-text fields; the project's own vocabulary is chosen when the project is. |
| D-D4 | Which description editor? | **`<pg-editor>`** (Jodit) — the Pages editor, in its minimum configuration: `document-view="false"` (no iframe, no page sheet with margins and page breaks, because a description is a field and not a document) and a short toolbar instead of the full document set. Sanitized server-side with `RichTextSanitizer::sanitize`, as every rich-text field here is. `<wi-editor>` remains the fallback for a checkout without the licensed Jodit package, on the same terms Pages falls back. Twice revised — see below. |
| D-D5 | Who allocates the ID at publish? | **`WorkItemCreator::publish()`**, next to `create()`. The workspace counter is read under `lockForUpdate()` in one place, and adding a second caller elsewhere is how gap-free numbering stops being gap-free. |
| D-D6 | Viewers and Guests | **Excluded.** They cannot create work items (Work Items §7), so a draft would be a note they could never publish. The sidebar hides the link and the routes 404 rather than showing a dead end. |

### Why not client-side drafts

Considered and rejected: keeping unsaved create-modal content in `localStorage`. It needs no
migration and no API, but a draft would then vanish on another device or a cleared browser, and
the sidebar's Drafts screen could only ever list the drafts of the machine it was opened on —
which is not a feature, it is a cache. Drafts are real rows.

### Not built

Drafts of anything other than work items (pages, comments). Sharing a draft. Converting a
draft to a sub-item of an existing work item. Bulk publish. All are additions on this
foundation rather than changes to it.

## Files Changed

**Migrations** — `2026_09_20_000001_add_drafts_to_work_items_table.php`,
`2026_09_20_000002_allow_project_less_work_item_media.php`

**Models** — `app/Models/WorkItem.php` (flag, cast, `ExcludesDrafts`, `drafts()`,
`isDraft()`), `app/Models/Scopes/ExcludesDrafts.php` *(new)*

**Policy** — `app/Policies/WorkItemPolicy.php` (`createDraft`, `viewDraft`, `updateDraft`,
`deleteDraft`, `publishDraft`)

**Requests** *(new)* — `app/Http/Requests/Draft/StoreDraftRequest.php`,
`UpdateDraftRequest.php`, `PublishDraftRequest.php`

**Controllers** *(new)* — `app/Http/Controllers/DraftController.php`,
`app/Http/Controllers/DraftMediaController.php`

**Models** — `app/Models/WorkItemMedia.php` (`url()` branches on project, `isDraftMedia()`)

**Service** — `app/Services/WorkItemCreator.php` (`publish()`, `rehomeMedia()`)

**Shared component** — `public/assets/js/projects/page-editor.js` (an optional `buttons` prop
for a trimmed toolbar; Pages keeps Jodit's full set)

**Routes** — `routes/drafts.php` *(new)*, required from `routes/web.php`

**Views** — `resources/views/drafts/index.blade.php` *(new)*,
`resources/views/partials/app-sidebar.blade.php` (the link, and hiding it for Viewers/Guests)

**JS** — `public/assets/js/drafts.js` *(new)*

**Tests** — `tests/Feature/Project/DraftsTest.php` *(new)*
