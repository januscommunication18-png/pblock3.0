# Your work

Source: the sidebar item "Your work", which shipped as `href="#"` alongside Drafts and
Stickies, plus the tab set and screenshot supplied by the product owner.

One person's work items across every project they can see — the view that answers "what am I
actually on?" without opening six projects to find out.

## Requirement

A workspace-level screen at `/your-work/{tab?}` with five tabs:

| Tab | What it is |
|---|---|
| **Summary** | Coming soon. The landing tab, and it says so rather than showing an empty panel. |
| **Assigned** | Work items you are an assignee of. |
| **Created** | Work items you opened. |
| **Subscribed** | Work items in projects you subscribe to. |
| **Activity** | What you did, newest first. |

The three list tabs are the **same list** the project's Work Items screen renders — same chips,
same drawer, same inline editing — with one extra chip naming the project each row came from.

## User Roles

Anyone who can open the app. There is no role gate on the screen itself, because there is
nothing on it a user could not already see: every tab is bounded by the projects that user may
open, and the rows within them by `WorkItemPolicy@view`. A Viewer sees their own assigned work
and cannot edit it, exactly as on the project's own list.

## Database Fields

**No new tables and no migration.** Every tab is a query over what already exists:

| Tab | Source |
|---|---|
| Assigned | `work_item_assignees` |
| Created | `work_items.created_by` |
| Subscribed | `project_subscribers` (D-Y1) |
| Activity | `work_item_activity.actor_id` |

`WorkItemScreenPayload::card()` gains `project_id` and `project` — redundant on a project's own
list, where every row shares one, and load-bearing here.

## Business Rules

1. **Reach bounds everything.** Every tab starts from `ProjectNavigation::visible()`. A work
   item cannot appear because it was assigned to you if it lives in a project you were never
   added to — **being named on a row is not access to it** (requirements §7). `cards()` then
   applies `WorkItemPolicy@view` per row, which also enforces §10's "assigned work items only"
   projects.
2. Counts on the tab bar are computed through the **same scopes** the lists use, so a number
   can never disagree with the list behind it.
3. Only the tab being viewed loads its rows. Five lists in one response is four lists nobody
   asked for, and they would be stale by the time anyone looked.
4. The project chip is **never editable**, whatever the row's permissions. Moving a work item
   between projects is not a chip's job — its ID, its state and every label on it belong to the
   project it is in.
5. Nothing is created from this screen. "Add a work item" has to be asked inside a project,
   because that is where the state and the ID come from.
6. Activity is bounded by reach too: an entry whose work item now lives in a project you can no
   longer open must not keep showing its old values.

## Acceptance Criteria

- **YW-01** The sidebar link opens the screen, and all five tabs render.
- **YW-02** Summary carries no list and is marked coming soon.
- **YW-03** An unknown tab 404s.
- **YW-04** Assigned lists what you are assigned to; Created lists what you opened; the two are
  different questions and give different answers.
- **YW-05** Subscribed lists items from projects you subscribe to and no others.
- **YW-06** An item assigned to you in a project you are not a member of does **not** appear,
  and the count says 0. Adding you to the project makes the same row appear.
- **YW-07** Every row carries its project, and `project_id` agrees with it.
- **YW-08** The payload indexes each project's vocabulary separately: two projects' state sets
  do not intersect, and each project's `update` endpoint points at its own URL.
- **YW-09** Activity is your own actions, each linked back to the item it happened to.
- **YW-10** Someone who cannot open a project sees none of its activity.
- **YW-11** Tab counts match the length of the list behind them.

## UI Requirements

One screen on the shared app shell, Vue mounted into Blade (CLAUDE.md §14). Tabs are **anchors,
not buttons** — each tab is a URL, so middle-click and "open in new tab" work, and the back
button moves between them.

The three list tabs mount `<work-items-screen>` — the whole component, as the Epic tab already
does, so a row means the same thing here as on the project's own list. Empty states name the
tab's own reason rather than a generic "nothing found".

## Real-Time Requirements

**None.** Every tab is a read of state that other screens already maintain.

## Queue Requirements

**None.**

## Audit Requirements

Nothing new is recorded. This screen *reads* `work_item_activity`; edits made through its rows
are audited by the same code that audits them on a project's list, because it is the same code.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| D-Y1 | What is "Subscribed"? | **Items in projects you subscribe to**, via §13's `project_subscribers`. There is no per-work-item subscription in this application, and building one is a feature in its own right — a table, a Subscribe button on the work item, and auto-subscribe rules on comment and assignment. Put to the owner before building; this was their call. When per-item subscription lands, this tab's scope is the one thing that changes. |
| D-Y2 | What is "Activity"? | **Your own actions**, not what happened to your items. "Your work" is a record of the work; a feed of other people's edits to your items is an inbox, which is a different feature with a different name. Also the owner's call. |
| D-Y3 | Are the chips editable across projects? | **Yes** — the owner chose the larger option over a read-only list. See below for what that took. |
| D-Y4 | Grouping | **Flat**, one "All work items" list, per the supplied screenshot. State grouping is project-scoped: two projects' "In Progress" are different rows, so a grouped cross-project list would repeat the same heading once per project. |

### Making one screen serve several projects

`WorkItemsScreen` is project-scoped by design, and rightly so: its rows share a project, so one
set of states, labels, members, cycles and endpoints serves all of them. Handing it rows from
several projects breaks that in two ways that both corrupt data rather than merely look wrong —
a picker offering **project A's states for project B's item**, and a PATCH going to **the wrong
project's URL**.

The fix is to stop flattening the vocabulary and start **indexing** it. `forUser()` returns
each project's options and endpoints under its own id, and the screen resolves both from the
row it is acting on:

```js
activeItem   → the open chip picker's row, else the open drawer's row
activeProject → projectsById[activeItem.project_id]
vocab        → activeProject || { the component's own flat arrays }
endpoints    → activeProject.endpoints || baseEndpoints
```

Two things made this a small change rather than a rewrite:

- **`endpoints` became a computed property.** All 26 `this.endpoints.X` call sites already act
  on the open row, so every one of them became project-aware without a single one changing.
- The picker option lists funnel through about fourteen `filter` calls, which now read
  `this.vocab.X`. The feature switches (`labelsEnabled`, `cyclesEnabled`, …) became computed
  off `vocab` for the same reason — a project with Cycles off must not offer the chip because
  the project above it in the list has them on — which left the ~20 references to them in the
  drawer template untouched.

**When `projects` is absent — every existing caller — all of this returns the flat arrays
unchanged.** The project's Work Items screen, the Epic tab and the per-item page are byte-for-
byte the same behaviour, which is what the 92 tests over those three screens check.

Two references deliberately did **not** move to `vocab`: the `states` that drives grouping and
the mobile cards, and the create modal's state picker. Grouping must not change when a picker
opens on a row, and nothing is created from this screen anyway.

### Not built

Summary. The view-mode switcher, filter and Display controls in the screenshot's top-right —
those are the project list's own toolbar and belong to a later slice. Per-work-item
subscription (D-Y1).

## Files Changed

**Controller** *(new)* — `app/Http/Controllers/YourWorkController.php`

**Service** — `app/Services/WorkItemScreenPayload.php` (`forUser()`, `card()` carries the
project), `app/Services/ProjectNavigation.php` (`visible()` extracted)

**Routes** — `routes/your-work.php` *(new)*, required from `routes/web.php`

**Config** — `config/projects.php` (`your_work_activity_size`)

**Views** — `resources/views/your-work/index.blade.php` *(new)*,
`resources/views/partials/app-sidebar.blade.php` (the link)

**JS** — `public/assets/js/your-work.js` *(new)*,
`public/assets/js/projects/work-items.js` (multi-project resolution),
`public/assets/js/projects/work-item-ui.js` (the project chip)

**Tests** — `tests/Feature/Project/YourWorkTest.php` *(new)*,
`tests/Feature/EagerLoadColumnsTest.php` (the `workItem` relation)
