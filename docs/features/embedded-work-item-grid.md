# Embedded Work Item Grid — live add / remove (Cycle · Epic · Module)

> Status: **built.** Fixes the grid-vs-counter split on the Cycle, Epic and Module detail
> screens. No new tables, no new routes, no broadcasting — this is one screen's own action
> showing up on that screen, not somebody else's (see `realtime-work-items.md` for that).

---

## Requirement

Adding work items through **Add work items**, or taking one out, updates the grid and the
counter at the same moment. No page reload, and the page / filter state stays as it was.

## The bug

The Cycle, Epic and Module detail screens hold their work in **two** payloads:

| Payload | Built by | Drives |
|---|---|---|
| `items` | each controller's own `workItems()` — a lean row shape | the header count, the empty state, Transfer |
| `workItems` | `WorkItemScreenPayload::build()` — the full card shape | the grid, which IS `<work-items-screen>` |

`addItems()` wrote back only the first. The count went 3 → 5 because it reads
`items.length`; the grid went on rendering the three rows it was booted with, because
`<work-items-screen>` copies `bootstrap.items` into its own state once, in `data()`, and
nothing ever told it otherwise. Reloading the page rebuilt both, which is why a refresh
"fixed" it.

Removal had the mirror problem. The hosts' own `removeItem()` is no longer wired to anything
— since the grid became `<work-items-screen>`, a work item leaves a cycle by having its Cycle
chip cleared **inside the child component**. The child updated its row; the host's counter
above it never heard about it.

## The fix

**Server** — every write that changes which items a record holds now returns both shapes.
`gridItems` is the record's rows in the work items screen's card shape, built from the same
query and the same filters the screen payload uses, so what a write returns and what a page
load would have rendered cannot disagree:

- `CycleController@addWorkItems`, `@removeWorkItem`, `@transfer`
- `EpicController@addWorkItems`, `@removeWorkItem`
- `ModuleController@addWorkItems`, `@removeWorkItem`

`WorkItemScreenPayload::rows()` is `build()`'s `items` extracted as a public method, so "the
rows on that screen" stays one definition.

**Client, host → child** — `applyItems(resp)` writes `items` for the count and `gridItems`
onto the bootstrap object the child was handed. `<work-items-screen>` watches
`bootstrap.items`, copies them in, and `<wi-list>`'s existing `items` watcher redraws the
grid. Scroll position, collapsed groups and the address's filters all survive, because
nothing navigates.

**Client, child → host** — the child emits `items-changed` when a row leaves the record it is
embedded in (`inHost()` compares the refreshed card against the host's `seed` — its
`cycle_id`, `epic_id` or `module_ids`), when a row is archived or deleted, and when one is
created or copied from the grid. The host adds or drops that row from `items`, so the counter
follows the grid within the same tick.

**Filters** — writes now carry the address's query string (`$pb.withFilters`). Without it the
refreshed rows would answer a different question ("all of them") and put back rows the reader
had filtered out.

## Business rules

- The counter and the grid read the same set, always.
- A work item removed from a cycle / epic / module keeps existing — only the relationship goes.
- Nothing reloads the browser page.

## Acceptance criteria

- A cycle showing 3 items, plus 2 from the picker → the grid shows 5 rows and the count says 5,
  with no reload.
- Clearing a row's Cycle chip in that grid → the row leaves the grid and the count says 4.
- Archiving or deleting a row from the grid → same.
- Creating a work item from inside the embedded grid → the count goes up with the grid.
- With a filter applied on an epic's Work Items tab, adding an item that the filter excludes
  does not put it on screen.
- Transfer on a completed cycle empties the grid as well as the count.
- Same behaviour on Cycle, Epic and Module.

## Real-time requirements

None. This is the acting user's own change; other people's changes are `realtime-work-items.md`.

## Queue requirements

None.

## Audit requirements

Unchanged — the writes already record their own history through `WorkItemUpdater` and the
epic / module activity recorders.
