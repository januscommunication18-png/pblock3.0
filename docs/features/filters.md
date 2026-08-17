# Filters — the shared engine, with Work Items as its first screen

Source: *Project → Work Items filter requirements*. Answers the deferral recorded in
[views.md](views.md) **V3**: *"Saved multi-condition filters are the same infrastructure the Work
Items, Epic, Estimation and Labels screens have each deferred waiting for, so it gets designed
once, deliberately, rather than being grown here."*

This is that design. Not built yet.

## Requirement

`/projects/{project}/work-items` gains a **Filter** button that opens a panel of **categories** —
Status, Members, Due Date, Priority, Label, Update — each opening its own values. Selections
combine as **AND between categories, OR within one**. Active filters appear as removable chips
above the list, and the panel offers **Clear all**.

The engine underneath is not Work Items'. Epics, Estimation, Labels and saved Views each get their
own set of categories and share everything else.

## What already exists, and what this must not duplicate

- `WorkItemScreenPayload::items()` is the **single listing query** for this screen. It already
  applies `limit(config('projects.work_item_page_size'))`, so a filter has to constrain the query
  rather than the result — filtering after a limit returns the first N items *then* discards some,
  which looks like a filter that lost rows.
- It already narrows by policy: a project set to "assigned work items only" filters the list
  itself, because hiding rows in the client while the API still returns them is not a restriction.
  **Filters narrow that further and can never widen it.**
- `ProjectState` supplies the project's configured statuses. `config('projects.work_item_priorities')`
  supplies priorities. `assignees` and `labels` are `BelongsToMany` on `WorkItem`.

## The one category that is not a `whereIn`

**Update** offers four values, backed by *two different mechanisms*:

| Value | Where it comes from |
|---|---|
| On Track / At Risk / Off Track | the **latest** `WorkItemUpdate.status` for that item |
| **Blocked** | an **open blocker count > 0** — a relation, not an update status |

`WorkItemUpdate::STATUSES` is exactly `on_track`, `at_risk`, `off_track`; there is no `blocked`
among them. The requirement presents all four together, which is right for a reader — "why is this
not moving?" is one question — but it means this category's `apply()` builds a single `where`
group spanning a **latest-per-item subquery** and a **blocker existence check**, OR'd together.

Two consequences to accept:
- **Latest-per-group is the expensive shape in SQL.** It wants a correlated subquery
  (`updates.id = (select max(id) …)`), and it is the one filter worth watching as projects grow.
- **"No update" is the absence of rows**, not a status. Offered, per the requirement's "optionally",
  and it is `whereDoesntHave('updates')`.

## The engine

`app/Filters/`, dataset-agnostic:

```php
interface FilterCategory
{
    public function key(): string;                       // 'status'
    public function label(): string;                     // 'Status'
    public function options(Project $project): array;     // [['value','label','avatar'?,'color'?], …]
    public function apply(Builder $query, array $values): void;   // OR within
    public function chip(array $values, Project $project): string; // "Status: In Progress, To Do"
}

final class FilterSet          // parses, validates, applies
{
    public static function fromRequest(Request $r, array $categories): self;
    public function apply(Builder $query): Builder;      // AND between
    public function chips(): array;
    public function isEmpty(): bool;
}

final class FilterRegistry     // 'work_items' => [StatusFilter, MembersFilter, …]
```

Three rules the engine holds so no screen has to:

- **AND between, OR within** — `FilterSet::apply()` calls each category once, and each category
  wraps its own values in a nested `where(fn …)` group. A category that forgets the group leaks
  its `or` across the whole query, which silently widens the result — the failure this shape
  exists to make impossible.
- **Unknown keys and values are dropped, not refused.** A filter is a view of a list, and a stale
  bookmark should show a slightly different list rather than an error page. Dropped values are
  *not* silently applied — they simply are not there, and the chips show what actually ran.
- **A category with no valid values is not applied at all**, so `?status=` is the unfiltered list
  rather than "items whose status is nothing".

Adding Epics or Estimation later is a new array in the registry. Views Slice 2 serialises the same
`{category: [values]}` shape into `project_view_filters` and hands it to the same `FilterSet`,
which is what "designed once" has to mean to be worth anything.

## Where the state lives

**The URL query string**: `?status=in_progress,todo&priority=high,urgent&update=at_risk`.

- A filtered list can be **linked, bookmarked, reloaded and gone Back from**. Filter state held
  only in the browser is state somebody cannot send to a colleague.
- It works with **JavaScript off**, because the panel is a form.
- It is the same shape a saved View will persist, so Views Slice 2 stores what the URL already
  says rather than inventing a second encoding.

Comma-separated values, not `filter[status][]=`: shorter, readable in a shared link, and the
parser is one `explode`.

## The categories

| Category | Values | Applied as |
|---|---|---|
| **Status** | the project's configured `ProjectState` rows | `whereIn('state_id', …)` |
| **Members** | project members, searchable, with avatars, **plus Unassigned** | `whereHas('assignees', …)` OR'd with `whereDoesntHave('assignees')` when Unassigned is chosen |
| **Due date** | Overdue · Due today · Due tomorrow · Due this week · Due next week · No due date · Custom range | date predicates OR'd together; **No due date** is `whereNull` |
| **Priority** | from `config('projects.work_item_priorities')`, **plus No priority** | `whereIn('priority', …)`, `whereNull` for none |
| **Label** | the project's labels, searchable when long | `whereHas('labels', …)` |
| **Update** | Blocked · On Track · At Risk · Off Track · No update | see above — the only compound one |

**Members and Unassigned in one category is deliberate.** "Sarah OR unassigned" is a real
question; putting Unassigned in its own category would make it AND, which answers nothing.

**Dates are resolved against the viewer's timezone**, not the server's — "Due today" is a claim
about the reader's day. The application already stores a user timezone preference.

## UI Requirements

```text
[ Filter ▾ ]
┌──────────────────┐        ┌───────────────────────────┐
│ Status         › │        │ ‹ Status        Clear all │
│ Members        › │   →    │ [ Search…               ] │
│ Due Date       › │        │ ☑ Backlog                 │
│ Priority       › │        │ ☑ In Progress             │
│ Label          › │        │ ☐ Done                    │
│ Update         › │        └───────────────────────────┘
└──────────────────┘

Status: In Progress, To Do ×   Priority: High ×   Update: At Risk ×    Clear all
```

- **Categories first, values second** — as asked. Six lists opened at once is a wall.
- Each category's panel keeps a **search box** where the list can be long (Members, Label), and
  every panel is keyboard-navigable.
- **Chips above the list**, one per *category*, naming the values inside it — `Status: In
  Progress, To Do ×` rather than one chip per value. The chip is the category because the
  category is the unit of the AND.
- **Clear all** in the panel and beside the chips.
- Selecting a value applies **immediately** — a filter with an Apply button is a filter you have
  to remember to press.
- The Filter button carries a **count** of active categories when there are any.
- An empty result says *"No work items match these filters"* with **Clear all**, not the empty
  state that invites you to create the first item — the list is not empty, the filter is narrow.

## Business Rules

- **F-1 — filters narrow, never widen.** They are applied on top of `WorkItemPolicy`, and an item
  the viewer may not see cannot be filtered into view.
- **F-2 — AND between categories, OR within one.** Stated once, in `FilterSet`.
- **F-3 — the query is filtered, not the page.** Applied before `limit`, or a filter appears to
  lose rows it never had.
- **F-4 — unknown keys and values are dropped**, and the chips show what actually ran.
- **F-5 — an empty category is not a filter.**
- **F-6 — the URL is the state**, and it is the shape saved Views will persist.
- **F-7 — dates resolve in the viewer's timezone.**
- **F-8 — every category is validated against the project.** A state id, label id or member id
  from another project is dropped: a filter is not a way to ask whether something exists elsewhere.

## Acceptance Criteria

- Filter opens categories; a category opens its values; multiple values select.
- Two categories AND: `status=in_progress,todo` + `priority=high` returns only items satisfying
  both, and the OR inside each is honoured.
- Chips appear per category, remove that category, and Clear all removes everything.
- The filtered list respects the page-size limit *after* filtering, not before.
- A member filter including **Unassigned** returns that member's items **and** unassigned ones.
- **Update = Blocked** returns items with open blockers; **At Risk** returns items whose *latest*
  update says so; an item that was At Risk last week and On Track today is **not** returned.
- **No update** returns items with no updates at all.
- A state, label or member id belonging to another project is dropped.
- A project restricted to "assigned only" still shows only the viewer's items, filtered further.
- The URL round-trips: filtering, copying the address and opening it elsewhere shows the same list.
- Filtering with JavaScript disabled works through the form.

## Real-Time / Queue / Audit

None, none, none. A filter reads.

## Build plan

~~**Slice 1 — the engine.**~~ ~~**Slice 2 — the panel and chips.**~~ **Delivered**, together with
**Status, Priority, Members and Label** from Slice 3.

`app/Filters/` — `FilterCategory`, `FilterSet`, `FilterRegistry`, and four categories under
`WorkItems/`. The Filter button sits before **Add work item** in the toolbar, opens categories,
then values, with a search box in any category offering more than eight. Chips sit above the list,
one per category, each removing its own. The count on the button is of **categories**, because a
category is the unit of the AND.

Two things the build corrected in the spec:

- **`none` is an ordinary priority value, not an absence.** `config('projects.work_item_priorities')`
  already contains `'none' => 'None'`, and `work_items.priority` is NOT NULL defaulting to it. The
  "or it is unset" branch the spec assumed does not exist, and `PriorityFilter` is a plain
  `whereIn`. Members still needs its equivalent, because an unassigned item genuinely has no rows.
- **The statuses are `ProjectItemState`, not `ProjectState`** — `work_items.state_id` is
  constrained to `project_item_states`, and they are provisioned per project on first use of the
  screen rather than at creation.

**The address is the state**, so changing a filter navigates rather than mutating anything
client-side. That is what makes a filtered list linkable and Back-able — and it means the **server**
decides what was applied, so the ticks in the panel cannot claim something the query did not do.

**Slice 3 (remaining) — Due date.** The ranges and the custom range, resolved in the viewer's
timezone.

**Slice 4 — Update.** The latest-per-item subquery, Blocked, No update — last because it is the
only one that is not a `whereIn`, and it should land when the rest is proven.

**Later, as suggested:** `Assignee = Me` and `Created by Me` as one-click quick filters beside the
Filter button. They are the two everybody wants once a project has hundreds of items, and they are
ordinary members/creator filters with a shortcut — no new engine.

## Decisions

| # | Decision | Why |
|---|---|---|
| F-D1 | A **shared engine**, Work Items first | The deferral in views.md asks for exactly this; a second filter implementation is what it was written to prevent |
| F-D2 | State in the **URL** | Linkable, reloadable, Back-able, works without JS, and it is the shape Views will save |
| F-D3 | Comma-separated values | Readable in a shared link; the parser is one `explode` |
| F-D4 | **Unassigned lives inside Members** | "Sarah or unassigned" is a real question; its own category would make it an AND and answer nothing |
| F-D5 | One chip **per category**, listing its values | The category is the unit of the AND, so it is the unit of removal |
| F-D6 | **Blocked sits in Update** despite not being an update status | "Why is this not moving?" is one question; the compound `apply()` is the cost of asking it once |
| F-D7 | Filters apply **before** the page limit | Otherwise the filter appears to lose rows |
| F-D8 | Unknown values are **dropped, not refused** | A stale bookmark should show a list, not an error |

## Open questions

1. **Does the page-size limit want to become pagination** once filters exist? A filtered list that
   is still truncated at N is the same problem one layer down. Not in scope here, but filters make
   it visible.
2. **Should chips show counts** (`Status: 2 ×`) when many values are selected? Proposed: name up
   to two values, then `+N`.
3. **Do Epics / Estimation / Labels want their categories now** or when each screen next moves?
   Proposed: when each moves — the engine is ready either way, and guessing their categories
   without their screens is how a shared abstraction becomes a wrong one.
