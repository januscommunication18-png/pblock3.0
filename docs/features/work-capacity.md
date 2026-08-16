# Workspace Working Capacity, Estimation & Team Health

Source: *ProjectBlock 3.0 — Workspace Working Capacity, Estimation & Team Health Requirements*
(CAP-001…CAP-020). Built in the five phases that document defines (§49), one commit per phase.

## Requirement

Connect three things that already exist separately — a workspace's working hours, a project's
estimates, and worklogged time — so that "is this person overloaded?" has an answer.

A workspace states how long a working day is. Project estimates convert into hours. Assigned
work becomes **planned** hours; worklogs become **actual** hours. Both are compared against
what each member actually has, and reported as a utilization percentage with a status.

Capacity and workload language throughout. This measures hours against hours; it does not
diagnose people.

## User Roles

| Role | Can |
|---|---|
| Workspace Owner | Enable tracking, configure hours/thresholds, manage overrides, view all reports |
| Workspace Admin | Configure capacity, manage overrides, view reports |
| Workspace Manager | View team capacity and workload, review estimate vs actual |
| Project Admin | Configure project estimation and its capacity mapping, view project capacity |
| Member / Contributor | View **own** capacity, estimates and worklogs only |
| Guest | No access |

## Database Fields

**Phase 1**
- `estimate_values.capacity_hours` — `decimal(6,2)` nullable. The hour value of one estimate.
- `work_items.capacity_hours` — `decimal(8,2)` nullable. Snapshot, see CAP-D2.
- `workspace_settings` += `capacity_enabled`, `capacity_hours_per_day` `decimal(4,2)`,
  `capacity_working_days` (json), `capacity_near_threshold`, `capacity_over_threshold`,
  `capacity_high_threshold`.
- `member_capacities` — tenant-scoped: `user_id`, `hours_per_day`, `working_days`,
  unique (`tenant_id`, `user_id`).

**Phase 4**
- `capacity_absences` — `user_id` nullable (null = workspace holiday), `starts_on`, `ends_on`,
  `hours` nullable (null = whole day).
- `capacity_week_snapshots` — `user_id`, `week_start`, `capacity_hours`, `planned_hours`,
  `logged_hours`.

## Business Rules

- **CAP-D1 — one conversion mechanism.** Points and sizes both convert through
  `estimate_values.capacity_hours` (§8, §9, §10). A "1 point = 2 hours" multiplier is a UI
  affordance that fills the column, never a stored rule — §8's own example is a table, and a
  multiplier cannot express it once a team decides 8 points is 16h rather than 16. `time`-type
  values derive hours from the existing `duration_minutes` and are never configured.
- **CAP-D2 — `null` capacity hours means UNMAPPED, never zero.** An unmapped or unestimated
  item is excluded from planned hours and *counted separately* (§41). This preserves the rule
  `EstimateValue::rollupValue()` already establishes: a category returns null, not 0, because
  silently treating missing work as no work is how a capacity report lies.
- **CAP-D3 — capacity hours are snapshotted onto the work item** when its estimate is set or
  changed. Reports then need no per-row join, and §47 holds: changing L from 12h to 16h today
  must not rewrite last quarter. Open items are re-snapshotted when the mapping changes;
  completed items keep what they were planned with.
- **CAP-D4 — planned hours spread across the item's own span.** A 16h item running Mon–Fri
  contributes ~3.2h/day, and a week's planned total is the sum of the day-portions inside it.
  Charging the full 16h to every week it touches would inflate every weekly number, and §43's
  daily view would be impossible.
- **CAP-D5 — undated work is excluded and reported.** An item with no `start_date`/`due_date`
  cannot be placed in a week, so it does not contribute; the count is shown beside every total.
  Same treatment, same reason, as CAP-D2.
- **CAP-D6 — one rule settles double counting (§23).** If any child carries an estimate, the
  parent's own estimate is ignored; otherwise the parent's counts. This satisfies both the
  parent-estimate and sub-task-rollup models, and makes `parent + children` structurally
  impossible rather than merely guarded against.
- **CAP-D7 — weekly capacity is derived, never stored** (§15). `hours_per_day × working_days`.
  A stored copy is a third source of truth that drifts from its own two inputs.
- **CAP-D8 — "use workspace default" is the absence of a `member_capacities` row**, not a flag
  on one. An override exists or it does not.
- **CAP-D9 — multiple assignees split equally** (§22). Custom allocation is a later phase.
- **CAP-D10 — over-capacity is a warning, never a blocker** (§34).

### Decisions resolving ambiguity in the source

| # | Ambiguity | Decision |
|---|---|---|
| CAP-A1 | The doc measures capacity weekly but never says which work counts toward a week. | Work item `start_date`–`due_date` overlap, spread across the span (CAP-D4). Cycles are a *view* over the same engine, not a second rule. Confirmed with the product owner. |
| CAP-A2 | §12 puts configuration under Settings → General. | Its own **Settings → Work Capacity** section in the Administration group. General already carries identity, timezone, logo and a delete-workspace danger zone. Confirmed with the product owner. |
| CAP-A3 | §5 implies a point multiplier; §8 shows a per-point table. | Per-value column, multiplier as auto-fill (CAP-D1). |
| CAP-A4 | §23 asks for "two models" of sub-task rollup. | One deterministic rule (CAP-D6) that produces both outcomes. |

## Acceptance Criteria

- Weekly capacity = daily hours × working days, and updates live as either changes.
- All three estimation types convert: `time` needs no mapping, points and sizes use their
  configured hours, an unmapped value contributes nothing and is counted as unestimated.
- §18, §19 and §24's worked examples reproduce exactly (36/40 = 90%, 32/40 = 80%, 36h = 90%).
- §20's mixed-project example totals 34h.
- A parent and its estimated children never both count.
- A 12h item with two assignees contributes 6h to each.
- Thresholds classify correctly at 89/90/99/100/109/110.
- A member override replaces the workspace default for that member only.
- A contributor sees only their own row; a guest sees nothing.
- Estimate changes and reassignment recalculate immediately (§35, §36).

## UI Requirements

- **Settings → Work Capacity** — enable toggle, hours/day, working-day picker, live weekly
  total, three threshold fields.
- **Project → Settings → Estimates → Capacity Mapping** — shown only when workspace capacity
  tracking is on, since with it off an hours column collects a number that feeds nothing.
  Carries the workspace's **working day and working week totals**: "16h" is a heavy item against
  a 40h week and an impossible one against 20h, so the figures being typed need their frame of
  reference on the same screen. One hours field per value, editable for points and sizes and
  read-only for time (which derives its own from `duration_minutes`). Points also get the §5
  multiplier as a one-click fill of the column — never stored, because it cannot express a team
  deciding 8 points is worth 16 hours rather than 16. Unmapped values are counted in a line
  under the totals (§41), where somebody can act on it.
- **Settings → Members → member → Work Capacity** — default or custom.
- **Workspace → Reports → Team Capacity** — summary cards (§31) and table (§32). *New nav area.*
- **Project → Reports → Team Capacity** — same table, project-scoped (Phase 3).
- Assignment capacity indicator and warning (§34, Phase 3).

## Real-Time Requirements

None. Capacity is a reporting surface read on open, not a live feed.

## Queue Requirements

Phase 4's weekly snapshot is a scheduled job (CLAUDE.md §11). Everything else is read-time.

## Audit Requirements

§47: configuration changes are logged — threshold changes, workspace hours, member overrides,
and size/point mapping changes. Historical reports keep the conversion value used at the time
via CAP-D3, so an audit entry explains a change rather than being the only trace of it.

## Phases

1. **Foundation** — data model, settings, mapping, overrides, engine, Team Capacity table.
2. **Estimation intelligence** — variance, accuracy, unestimated/undated warnings, coverage,
   daily view.
3. **Cycle & assignment planning** — cycle capacity, project report, assignment warnings,
   recalculation on estimate/assignee change.
4. **Team health & calendar** — weekly snapshots, sustained workload risk, trends, PTO.
5. **Forecasting** — capacity and demand forecasting, trend reporting.
