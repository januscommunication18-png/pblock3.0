# Cycles (Sprints)

Source: *ProjectBlock 3.0 — Cycles (Sprint) Requirements* (§ refs are to that document).

A Cycle is a time-boxed sprint inside one project. Work items join a cycle through a Cycle
property on the item; a work item belongs to at most one cycle at a time.

## Scope of this build

Built: the project Features toggles (§3), the cycles table and Cycles tab (§4), the
Active/Upcoming/Completed landing page (§5), create/edit/delete with overlap validation (§6,
§9), the cycle detail page and its work item list (§7), the Cycle property on work items with
the single-cycle rule and history events (§8), and transfer of incomplete work (§10).

Deferred by agreement: the burn-down chart (§7.1 — needs a daily snapshot table and a
scheduled job), cycle filters on the work item grid (§14), cycle-level notification events
(§15), and estimate progress (§7.2), which has no estimates feature to read from yet.

## The Features screen had to be finished first

§3 puts both toggles under Project Settings → Features. That screen was a stub: `features.js`
and `ProjectSettingsController@toggleFeature` existed, but `config('projects.features')`, the
`projects.features` column and `Project::featureFlags()` did not, so opening it 500'd. Cycles
cannot be enabled per project without it, so it is completed here — which also fixes the
long-standing `ProjectSettingsTest::test_feature_toggle_persists` failure.

Flags are stored as one JSON column rather than a row per project-feature. There is no query
that needs to ask "which projects have cycles on", only "does THIS project have it on", and
the catalog is config-driven — a table would add a join and a migration per new feature for
no gain. `featureFlags()` merges the stored map over the catalog defaults, so a feature added
to the catalog later is immediately readable on every existing project without a backfill.

## Authorization runs before validation

`StoreCycleRequest` and `UpdateCycleRequest` carry a real `authorize()` rather than the
`return true` the older work-item requests use. Laravel runs `authorize()` before the rules,
and without it a commenter attempting to create a cycle was told *"these dates overlap Sprint
08 (Aug 11 – Aug 24)"* before being told they were not allowed to create anything. A refusal
should not first answer the question.

## Cycle status is derived, never stored

§16's recommended model lists a `status` column. It is deliberately **not** built.

Status is a pure function of the dates and today (§9): before start → Upcoming, within the
range inclusive → Active, after end → Completed. A stored column would need a scheduled job to
stay truthful, and between runs the database would disagree with §9's own definition. Any bug
in that job silently corrupts every report built on it.

`completed_at` **is** stored, but it means something different: the moment a user explicitly
closed the cycle out, which is what §10's transfer flow acts on. A cycle that has simply run
past its end date is Completed without being closed.

## Data model

| Table | Notes |
|---|---|
| `projects.features` | JSON map of feature key => bool. Absent keys fall back to the catalog default. |
| `cycles` | Tenant- and project-scoped. `name`, `description`, `start_date`, `end_date`, `created_by`, `completed_at`. No `status` — see above. |
| `work_items.cycle_id` | Nullable FK, `nullOnDelete`. Plus `cycle_assigned_by` / `cycle_assigned_at` (§16). |

**One cycle per work item is a column, not a pivot.** §16 suggests a relationship table with
"a unique constraint or application-level rule" preventing a second row. A nullable FK on the
work item makes a second cycle unrepresentable rather than merely forbidden — there is no
state the application has to defend against, and "move between cycles" becomes one UPDATE
instead of a delete-then-insert that can half-fail.

Deleting a cycle nulls the FK rather than cascading: §8.3.7 says removing the cycle must not
delete the work item, and that has to hold when the removal is the cycle's own deletion.

## Overlap rule (§3.3, §6.2)

With Parallel Cycles off, a new or edited date range may not overlap another cycle in the same
project that is Active or Upcoming. Completed cycles are excluded — a finished sprint is not a
scheduling conflict.

Turning Parallel Cycles off later does **not** rewrite anything (§3.3.4). The rule is checked
at write time only, so existing overlaps survive; they simply cannot be added to.

The check lives in `CycleOverlapGuard`, called from both the store and update requests, so the
rule cannot hold on one path and not the other.

## Entitlement (§13)

The app has no subscription or billing model, so `config('projects.entitlements.parallel_cycles')`
stands in for one. It is enforced in `ProjectSettingsController@toggleFeature`, not only in the
UI, per §13's closing line — with the gate off, a hand-rolled POST is refused with 422 exactly
as the disabled toggle implies. The Features screen renders an Upgrade badge in that state.

When a real plan check arrives, it replaces the body of `Project::entitledTo()` and nothing
else moves.

## Assignment goes through WorkItemUpdater

§8.3 and §8.4 (assign / move / remove, each recorded in history) are implemented by adding
`cycle_id` to the fields `WorkItemUpdater` already diffs, rather than a separate assignment
service. That is what makes the history entry free and correct: the same transaction that
moves the item writes the audit row, and cycle changes read in the feed exactly like state and
parent changes do (`Rohit changed cycle: Sprint 08 → Sprint 09`), with the names resolved at
write time so a renamed cycle does not rewrite the past.

The guard rails live in the form requests, where every other cross-tenant check already is:

- the cycle must belong to **this** project (§8.3.6),
- it may not be a Completed cycle (§8.3.5) — but only when the value is *changing*, so saving
  an unrelated property on an item that is already in a finished cycle is not blocked,
- and the property is rejected outright when Cycles is disabled for the project (§3.2.4).

## Disabling Cycles keeps the data (§3.2.4)

Turning the feature off hides the tab and the property and refuses new assignment. It deletes
nothing: cycles keep their rows, work items keep their `cycle_id`, and re-enabling restores
the lot. The tab is *hidden* rather than shown-and-disabled, because a visible tab that
refuses to open is worse than an absent one.

## Permissions (§12)

| Action | Rule |
|---|---|
| View cycles | Whoever may view the project's work items. |
| Create / edit cycle, add or move work items, transfer | Whoever may create work items (contributor and up). |
| Delete cycle | `manage` — project admin or workspace owner/admin. |
| Enable/disable either toggle | `manage`. |

A cycle URL from another project 404s rather than 403s, matching the rest of the app: the
response must not confirm that a cycle exists somewhere the user cannot see.

## Transfer incomplete work (§10)

Offered on a Completed cycle. It moves every selected work item whose state group is not
`completed` or `cancelled` into a chosen Active or Upcoming cycle. It is a move, so each item
leaves the old cycle in the same update that adds it to the new one, and each gets its own
history row. Nothing else on the work item is touched (§10, "preserve status, assignee,
priority, comments, worklog, attachments, history").

## The grid and the counter stay in step

The detail page's grid is `<work-items-screen>`, fed by its own payload — so adding or
removing work items has to write BOTH that payload and the lean `items` behind the header
count, or the two disagree until a reload. See `embedded-work-item-grid.md`.

## Acceptance criteria (§18)

Covered by `tests/Feature/Project/CycleTest.php`:

- enabling Cycles exposes the tab and the work-item property; disabling hides both and keeps
  the data,
- overlapping ranges are rejected with Parallel Cycles off and accepted with it on,
- pre-existing overlaps survive the toggle being switched off,
- assigning a second cycle moves the item rather than duplicating it, and writes history,
- a cycle from another project cannot be assigned,
- a completed cycle refuses new assignment,
- the landing page groups by derived status,
- transfer moves only incomplete items and preserves the rest of the work item,
- and the permission matrix above.
