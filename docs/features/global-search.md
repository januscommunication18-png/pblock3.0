# Global Search

Source: *ProjectBlock 3.0 — Global Search Requirements* v1.0 (§ refs below are to that
document). This file is the build spec: it turns those requirements into decisions for THIS
codebase, and records what each slice does and does not cover.

## Requirement

A workspace-level command palette. One entry point, opened from anywhere, that searches across
workspace objects the current user is allowed to see, groups results by type, and navigates to
the right record with minimal steps (§1, §27).

## User Roles

Everyone with a workspace membership. There is no separate "may search" permission: search
returns exactly what the user could already reach by navigating, and nothing else (§12).

## Architecture

**Laravel Scout**, driver chosen per environment:

| Env | `SCOUT_DRIVER` | Why |
|---|---|---|
| Local | `database` | MySQL 8 `LIKE`/fulltext through Scout's own driver. No server to run — this machine has no Homebrew, Docker or Meilisearch binary. |
| Production | `meilisearch` | Relevance, typo tolerance, and the natural home for the §16/§17 structured and natural-language work. |

The application code is identical for both: everything goes through `Model::search()`. Swapping
the driver is an env change, not a code change.

### Permission model — the part that must not be got wrong

§12 says restricted content must not appear in results, counts, suggestions, snippets or
autocomplete. An external index does not know our policies, so **permission data is indexed as
filterable attributes and applied as a filter at query time**, never as a post-hoc filter of
results. Filtering after the fact corrupts counts and pagination — page 1 of 20 results can
come back with 3 after filtering — and is how "restricted content must not appear in counts"
gets quietly broken.

The rule this codebase actually enforces is **not** "public projects are open to the workspace".
`ProjectPolicy::view()` gives workspace owners and admins sight of every project, and everyone
else needs an **explicit project membership regardless of visibility** — the General-settings
spec says otherwise and that contradiction is logged as open in
`docs/features/project-settings-general.md`. Search follows the policy, not the other spec.

On top of that, `WorkItemPolicy::view()` applies §10's *Work Item View*: in a project set to
"assigned work items only", an ordinary member sees just the items they are assigned to. Leads,
workspace owners/admins and project admins keep sight of everything.

So a user's reach is two sets, both computed per request from memberships:

| Set | Meaning |
|---|---|
| `openProjectIds` | Projects they can view AND see every item in |
| `restrictedProjectIds` | Projects they can view but where only assigned items are visible |

A query filters `tenant_id = <current>` AND `project_id IN (open ∪ restricted)`, then the
assignee constraint is applied to the restricted half during Eloquent hydration. Neither set is
cached in the index, so revoking access takes effect on the next query rather than the next
reindex.

Belt and braces: results are additionally hydrated through Eloquent, so a record deleted since
indexing simply does not come back. Stale index rows cannot resurrect deleted content.

## Database Fields

No new tables in slice 1. `WorkItem` gains no columns — `toSearchableArray()` projects existing
ones. Scout's `database` driver reads the source table directly; `meilisearch` keeps its own
index, rebuilt with `php artisan scout:import`.

Indexed per work item: `id`, `identifier`, `title`, `description` (HTML stripped to text),
`project_id`, `project_name`, `tenant_id`, `visibility`, `state`, `priority`, `updated_at`.

## Business Rules

- Query runs at **2+ characters**, debounced 200 ms (§6.1).
- Case-insensitive. Exact identifier matches (`PB-168`) rank first (§6.1, §6.2).
- Results grouped by object type, **5 per group** in the palette (§7, §18).
- Archived and soft-deleted records are excluded.
- A search must never fail the page: an engine error returns the error state (§13.5), distinct
  from a legitimate zero-result state (§13.4).
- Stale responses are discarded — a slow request for "log" must not overwrite results for
  "login" (§18).

## Acceptance Criteria (slice 1)

- Cmd+K / Ctrl+K opens the palette from any app screen; Escape closes it.
- The topbar search control opens the same palette.
- The field autofocuses; typing 2+ characters returns work items.
- Results are grouped under a "Work Items" heading with the item's identifier, title, project,
  state, priority and relative updated time (§8.1).
- Matched text is highlighted in identifier and title (§6.3).
- Up/Down move the selection, Enter opens it, Escape closes (§10).
- Selecting a work item lands on `/projects/{id}/work-items?item={id}&tab=all` and opens the
  drawer (§11.1).
- A user who cannot see a private project's items gets none of them, in results **or** counts.
- Zero results and engine errors render differently.
- Typing stays responsive; stale responses never overwrite newer ones.

## UI Requirements

Centred command palette, ~700 px, over a dimmed backdrop; full-screen below `sm` (§22). Search
field with icon and placeholder "Search your workspace". Grouped results, keyboard hints in the
footer. Focus is trapped while open and returned to the trigger on close (§21).

## Real-Time Requirements

None. Search is request/response.

## Queue Requirements

`SCOUT_QUEUE=true` in production so indexing does not block the request that saved the record
(CLAUDE.md §11). False locally for immediate feedback.

## Audit Requirements

Indexing failures are logged and recoverable via `scout:import` (§19). Analytics events (§20)
are Phase 2 — deliberately not wired in slice 1, and no search content is sent anywhere.

## Slices

| Slice | Contents | Status |
|---|---|---|
| 1 | Palette shell, Cmd+K, topbar entry, keyboard nav, states, **Work Items** source, permission filtering, work-item navigation | done |
| 2a | **Wiki** source — collections and pages, one group, scoped by `WikiCollection::visibleTo()` | done |
| 2b | Projects and Members sources | not started |
| 3 | Filters (§9), scope selector (§14), recent items (§4.3), quick searches (§4.4) | not started |
| 4 | Highlighting polish, analytics (§20), View-all results page | not started |

## Decisions

| # | Question | Decision |
|---|---|---|
| G1 | Search engine | **Scout**, `database` locally / `meilisearch` in production. Chosen over direct model queries for §16/§17 headroom; chosen over a hand-rolled index table to avoid maintaining sync code. |
| G2 | Permission enforcement | Filter **at query time** on indexed `tenant_id`/`project_id`/`visibility`, with the project id list computed per request. Never a post-filter, which would corrupt counts (§12). |
| G3 | Meilisearch locally | Not installed — no Homebrew/Docker/binary on the dev machine. The `database` driver covers local work; no code differs. |
| G4 | Slice 1 scope | Work Items only. The shell is the risky part; each further type is then one `Searchable` model plus one source class. |
| G5 | DB engine note | MySQL **8.0.44**, not MariaDB as CLAUDE.md D1 assumes. Fulltext is available if the `database` driver needs it. D1 should be updated. |
| G7 | Linked wiki pages | A linked page stores no title or body of its own — both resolve from its source. `toSearchableArray()` reads the accessors, so meilisearch indexes the text the page actually shows. Scout's `database` driver queries the columns instead, where those really are null, so **linked pages are findable in production but not locally**. Accepted rather than denormalised: storing a copy is the duplication the linked-page feature exists to avoid, and it would go stale on the first rename. |
| G6 | "Assigned only" projects | Scout's `database` driver cannot filter on a pivot (`assignees`), so the assignee rule cannot be expressed as an engine filter on both drivers. Instead the engine returns a coarse, over-fetched set and the constraint is applied **in the Eloquent hydration query**, which is authoritative. It can only ever remove rows, never add them, so it cannot leak; the over-fetch keeps the visible group full. Slice 1 therefore shows **no result counts** — a count taken before that constraint would overstate what the user can see (§12). |
