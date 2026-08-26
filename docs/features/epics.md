# Epics

Source: *ProjectBlock 3.0 — Epic Requirements* (§ refs are to that document).

An Epic groups related work items under a larger initiative. It answers **"what larger
initiative does this work contribute to?"** — a different question from Module ("what area?")
and Cycle ("when?"), which is why §11/§12 keep all three independent.

## Requirement

A work item may carry an Epic, a Module and a Cycle at once, and none of them constrains the
others. Assigning one never sets or clears another; deleting an Epic never touches a work
item's Module or Cycle (§11, §16).

## Scope of this build

Agreed as the **core loop**:

Built — the feature toggle and its own settings page (§4), the Epics tab (§3), the list with
its empty state (§7, §23), create/edit with title, description, status, priority, lead,
members, start and target dates (§5, §6), the detail page with Overview / Work Items / Activity
(§8), the Epic property on work items (§9), progress from work item completion (§13),
archive/restore/delete (§16), project-role permissions (§15), and the activity log (§22).

Deferred by agreement — filter and Group by Epic in the work item list (§17), labels on an
Epic, colour and icon (§5), success criteria (§5), and bulk assign/remove (§9).

## Decisions

| # | Question | Decision |
|---|---|---|
| E1 | Epic ID (§5 wants `WEB-E01`) | **Plain project-scoped number**, shown as `identifier` — the same word work items, cycles and modules already use. A second ID format would be a new concept for users to learn for no gain, and §6's uniqueness rule still has something to enforce. |
| E2 | Warning before disabling (§4) | **Epic only.** The toggle refuses without an explicit confirmation once Epics exist, naming how many. Cycles and Modules keep disabling silently; nothing is deleted either way, so the warning is informational and only Epic's spec asks for it. |
| E3 | One epic or many per work item | **One** — §9 is explicit for Phase 1. So `work_items.epic_id` is a nullable column, like `cycle_id`, not a pivot like modules. |
| E4 | Status: stored or derived | **Stored**, like Module. §5's six values include Paused and Cancelled, which are statements of intent that no date can express — unlike a Cycle, whose status §9 there *defines* as a function of its dates. |

## Data model

| Table | Notes |
|---|---|
| `epics` | Tenant- and project-scoped. `identifier` (per-project counter), `title`, `description`, `status`, `priority`, `start_date`, `target_date`, `lead_user_id`, `archived_at`, `created_by`, soft deletes. |
| `epic_members` | Tenant-stamped pivot. Involvement only — adding someone here assigns them no work. |
| `epic_activity` | Append-only feed (§8/§22). Separate from `work_item_activity`: the two record different vocabularies, and one shared table would be nullable in every column that tells them apart. |
| `work_items.epic_id` | Nullable FK, `nullOnDelete`. §16: deleting an Epic must preserve every work item, so the column empties rather than cascading. |

The de-linking on delete is done in `EpicController::destroy`, **not** by the FK — see the
disable section below for why the constraint never fires.

## Progress is counted, and it excludes cancelled work

§13: `completed / total × 100`, from work item **count** — not estimates, which do not exist
yet. Cancelled items leave the denominator entirely (§13), so cancelling scope raises progress
rather than permanently capping it below 100%. An Epic with no work items reports 0% with a
"No work items" reading rather than dividing by zero.

Sub-tasks are not counted separately unless they carry their own Epic (§13/§10): they belong to
their parent work item, and counting both would let one piece of work vote twice.

## Status is never automatic

§14: completing every work item does **not** complete the Epic, and completing an Epic does not
finish its unfinished work items. Cancelling an Epic cancels nothing inside it. The spec's
reason is the right one — silent bulk status changes are the kind of thing a user cannot undo
and did not ask for. The UI may *suggest*; it does not act.

## The grid and the counter stay in step

The Work Items tab is `<work-items-screen>`, fed by its own payload — so adding or removing
work items has to write BOTH that payload and the lean `items` behind the count, or the two
disagree until a reload. See `embedded-work-item-grid.md`.

## Acceptance criteria → tests

§24's numbered list, in `tests/Feature/Project/EpicTest.php`.

## Disabling Epics is a configuration change, never a delete

This is the rule the rest of the feature bends around, and it is where Epic deliberately
behaves differently from Cycles and Modules — both of which hide themselves completely when
switched off.

| | Cycles / Modules off | Epics off |
|---|---|---|
| Tab | hidden | hidden |
| Page | 404 | **loads, read-only**, with the notice below |
| Existing records | hidden with the page | listed, readable, openable by URL |
| Work item property | row disappears | **row stays** when the item has an epic, frozen |
| Clearing an existing assignment | allowed | **refused** |

The notice, verbatim from the spec: *"Epics are currently disabled for this project. Enable
Epics from Project Settings to create or manage Epics."*

**Where each half is enforced.** `EpicPolicy` gates every ability that writes; `viewAny` and
`view` are deliberately not gated, which is what keeps the page and its records readable.
Update, archive and manageWorkItems all defer to `create()`, so the whole feature goes
read-only from one line rather than five.

**Two subtleties worth knowing before changing this code:**

1. **`nullable` hides a clear from the validation rule.** Laravel skips every remaining rule
   for an attribute whose value is null, so `EpicAssignable` never runs when someone clears
   `epic_id` — precisely the change the disable rules forbid. That one case is enforced in
   `UpdateWorkItemRequest::after()`. Everything a per-value rule *can* see stays in the rule,
   so there is no duplicate message for the same refusal.

2. **Soft deletes mean `nullOnDelete` never fires.** Epics soft-delete so their activity and
   audit trail survive (§22), which leaves the row in the table and the FK constraint idle.
   `EpicController::destroy` clears `work_items.epic_id` itself, inside the transaction. The
   constraint remains as a backstop for a hard delete or a purge. A soft delete that left the
   column pointing at an invisible epic would show work items filed under an epic nobody can
   open — which is how this was caught.

**Re-enabling** restores everything with no migration and no recreation: the flag is the only
thing that changed.
