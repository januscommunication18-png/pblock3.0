# Estimation

Source: *ProjectBlock 3.0 — Project Estimation Requirements* (§ refs are to that document).

An estimate says how much effort, complexity or time a work item is expected to take. It is
configured per project, and **one estimation system is active at a time** (§9/§36) — a work
item cannot carry points and a T-shirt size and a duration at once, because nothing downstream
could then aggregate it.

## Scope of this build

**Core loop**, matching how Cycles, Modules and Epics were scoped:

Built — Settings → Estimation with the enable toggle and the empty configuration state (§3,
§4), choosing a type and template (§5–§8), the value editor with add/rename/remove/reorder
(§10, §20), archive-rather-delete for a value already in use (§21), changing the type with its
warning and no automatic conversion (§22, §23), the Estimate chip on create / detail / row
(§11, §12, §16), assign / change / remove (§13–§15), the disable pattern (§24–§29),
permissions (§30), and activity for both the work item and the project configuration (§31).

Deferred — filtering and sorting by estimate (§18, §19), which need work item list filter
infrastructure that does not exist yet; board cards (§17); and the reporting rollups (§32),
whose data model is in place but which have no screens to appear on.

## Decisions

| # | Question | Decision |
|---|---|---|
| ES1 | Where does `enabled` live? §33 puts it on `project_estimation`; §29 says follow Epic/Cycle/Module. | **The features map on the project**, like every other optional feature. §29 asks for exactly that pattern, and it hands Estimation the whole disable model — three-state nav, the confirmation, read-only history — for free. A second `enabled` column would be a second answer to one question. The `project_estimations` row holds the *system*, which is what §25 requires to survive a disable. |
| ES2 | Removing a value that is in use (§21) | **Archive, never delete.** `active = false`: it disappears from every picker, and the work items already carrying it keep reading correctly. §21 recommends this; it is also the only option that does not silently rewrite history. |
| ES3 | Changing the type (§22) | **Nothing is converted and nothing is deleted.** The old system's values are archived, the new ones are created alongside, and work items keep pointing at whatever they were given. §23 is right that `5 points` has no honest translation into `Medium`. |
| ES4 | Ordering | An explicit `sort_order`, not the label. A category system orders XS → S → M → L → XL, which is neither alphabetical nor numeric, and §19 wants that order respected. |

## Data model

| Table | Notes |
|---|---|
| `project_estimations` | One row per project — the active system. `type` (points/category/time), `template` (fibonacci, tshirt, custom …), `created_by`. Survives a disable (§25). |
| `estimate_values` | `label`, `numeric_value`, `duration_minutes`, `sort_order`, `active`. Archived values stay for history (§21). |
| `work_items.estimate_value_id` | Nullable FK, `nullOnDelete`. One value per work item (§33). |

`numeric_value` and `duration_minutes` are both stored because §32's rollups need something to
sum, and only one of them is meaningful per type: points fill the first, time fills the second,
category fills neither and can only be counted. Storing the label alone would make every future
report a string-parsing exercise.

## Disable

Estimation joins `ProjectFeatureState`, so it behaves exactly like Epics, Modules and Cycles —
see [feature-disable.md](feature-disable.md). The configuration and every assigned estimate
survive; the property stops being editable; re-enabling restores the system with nothing to
recreate (§28).

## Acceptance criteria → tests

§34's AC-01 … AC-09, in `tests/Feature/Project/EstimationTest.php`.
