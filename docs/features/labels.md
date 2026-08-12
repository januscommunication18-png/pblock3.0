# Project-level labels

Source: *ProjectBlock 3.0 — Project-Level Labels Requirements*.

Labels existed before this build; what changed is that they are now a **configured feature**
with the same enable/disable model as Epics, Modules, Cycles and Estimation.

## Scope of this build

Built — the Enable Labels switch (§2), disable/re-enable with its confirmation and preserved
data (§3–§5), the management list with description, usage count and status (§6, §12, §24),
create/edit with duplicate-name validation (§7–§9), delete with a usage warning (§10), the
Active → Archived → Restored lifecycle (§11), search (§13), and the label field on Create Work
Item and Work Item Detail following the feature (§15, §18).

Deferred — sorting options (§14) and filtering work items by label (§23), both of which need
work item list filter infrastructure that does not exist yet; and §21's workspace-wide labels,
which the spec itself puts outside this scope.

## Decisions

| # | Question | Decision |
|---|---|---|
| L1 | Where does `enabled` live? | **The project's features map**, with every other optional feature — so §3–§5 reuse the disable model already built rather than a second implementation. |
| L2 | Default state | **ON** (§2), unlike every other feature. Labels are a basic way to organise work rather than a planning layer a team opts into. Because `featureFlags()` merges catalog defaults over stored values, every existing project keeps its labels with no backfill. |
| L3 | Removing a label from a work item while disabled | **Refused**, matching Modules. §3 does not spell this out, but §28.5 requires assignments to survive a disable — and a user who could clear the field while it was off would be destroying exactly that. |
| L4 | Delete vs archive | Both, and the UI steers toward archive. §10's delete removes the label from its work items (never the items); §11's archive keeps the categorization on historical work while taking the label out of the picker. |

## Renaming works because nothing copies the name

§9 requires a rename to update the label everywhere it is assigned. That needs no code: the
chip reads the label through the relation, so one row changes and every display follows.
Copying the name onto the pivot would have been the version needing a migration to fix.

## Where the rules are enforced

- `LabelAssignable` — refuses a label that is archived (§11) or belongs to a project with the
  feature off (§3). Exempts labels the item already has, so saving an unrelated property is
  never refused for echoing the current set back.
- `UpdateWorkItemRequest::after()` — refuses a *removal* while disabled. A per-value rule
  cannot see one: labels are a set, and an id dropped from the list simply never arrives. This
  is the same hook that handles modules, for the same reason.
- `ProjectLabelController::guardWrite()` — Project Admin **and** feature on, so §3's "disable
  creation of additional labels" is a refusal rather than a hidden button.
