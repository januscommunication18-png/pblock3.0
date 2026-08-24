# Feature: Help Desk — Ticket SLA & Business SLA

Source: *ProjectBlock Help Desk — Ticket SLA & Business SLA Requirements* (owner-supplied,
2026-08-24), navigated as **Features Space → Features → SLA**. Section references `§n` below are
to THAT document.

This file is the build spec: it turns the requirement into decisions for THIS codebase, records
the schema, and tracks what each slice does and does not cover. It is a sibling of
`help-center.md` rather than another `P` section of it, because the SLA engine is a subsystem
with its own vocabulary, its own tables and its own background worker — appending 40 sections to
a 5,800-line file would have buried it.

| Slice | Title | Status |
|---|---|---|
| S1 | Schema, models & vocabulary (§5–§8, §12, §16, §19, §23, §37) | **done** |
| S2 | The SLA engine — business-hours clock, evaluation, timers (§9–§13, §20–§22, §36) | pending |
| S3 | Settings UI — Policies, Business Hours, Holidays, Escalation Rules (§2–§8, §17, §23–§25) | **done** |
| S4 | Workflow status SLA behavior (§19) + ticket panel & history (§15, §30, §31) | pending |
| S5 | Inbox indicator, filters, sorting (§27–§29) | pending |
| S6 | Escalation *engine* — firing the rules S3 authors (§24, §25) | pending |
| S7 | SLA reporting (§32–§34) | pending |

### What this feature deliberately does NOT build

- **§26 Workflow Builder integration.** This codebase has no workflow builder and no automation
  engine — "Workflow" in Help Desk Settings is the Space's *status list*, nothing more. There is
  no host to add SLA triggers, conditions or actions to. S6 builds the escalation rules the
  requirement describes in §24–§25, which is the same capability with its own UI; §26 becomes a
  wiring task the day a builder exists, and the SLA events it needs are already emitted as
  activity rows. Recorded as SLA-D12.
- **Four of §12's assignment conditions.** Customer Type, Ticket Type, Ticket Channel and
  Support Plan are not things this product stores: a Request has no type and no channel column
  (every one arrives by email), and there is no support-plan concept anywhere. They ship as two
  **custom field** conditions instead — Company & Customer custom fields (P75) are exactly where
  a team records "Enterprise", "Gold Plan" or "Billing". Recorded as SLA-D6.

---

## Requirement

A Space defines service-level targets — business hours, holidays, and per-priority First
Response / Next Response / Resolution durations — and the system applies them to every ticket
automatically, tracks the clocks against business time, pauses them while the ticket waits on
somebody else, escalates as they approach breach, and reports on the result.

## User roles (§35)

| Role | May |
|---|---|
| Space Owner / Admin | Create, edit, duplicate, enable, disable and delete SLA policies; configure Business Hours, Holidays and Escalation Rules; change the SLA on a ticket; view SLA reports |
| Agent | View a ticket's SLA, its remaining time and its breach information. **No** configuration, and no Change SLA |

Enforced by `HelpCenterSpacePolicy` (`update` for configuration, `view` for reading), which is
already what the rest of Space Settings authorizes against.

## Database fields

All tables are **TENANT-SCOPED** (`tenant_id` + `BelongsToTenant`, CLAUDE.md §7) and hang off a
Space, like every other Help Desk table.

### `help_center_business_hours` (§5, §6)

| Column | Type | Notes |
|---|---|---|
| `help_center_space_id` | FK | |
| `name` | string(80) | e.g. `US Support Hours` |
| `timezone` | string(64) | IANA, e.g. `America/New_York` (§6) |
| `schedule` | json | `{"mon":{"open":"08:00","close":"18:00"},"sat":null,…}` — `null` is Closed |
| `is_default` | bool | The calendar a new policy starts with |

### `help_center_sla_holidays` (§7)

| Column | Type | Notes |
|---|---|---|
| `help_center_space_id` | FK | Space-level, not per calendar (SLA-D4) |
| `name` | string(120) | `Christmas Day` |
| `date` | date | |
| `repeats_annually` | bool | Matches month+day in every year when true |

### `help_center_sla_policies` (§3, §4, §12, §14, §17, §23)

| Column | Type | Notes |
|---|---|---|
| `help_center_space_id` | FK | |
| `name`, `description` | string / text | |
| `is_active` | bool | Active / Inactive (§4) |
| `is_default` | bool | Exactly one per Space (§4) |
| `help_center_business_hours_id` | FK nullable | `null` = 24/7 (SLA-D3) |
| `warning_percent` | tinyint | Due Soon threshold, default 80 (§17) |
| `match_type` | string(3) | `all` / `any` — how the conditions combine (§12) |
| `conditions` | json | `[{field,operator,value}]` (SLA-D5), fields from `sla_condition_fields` |
| `reopen_behavior` | string(20) | `resume` / `restart` / `none`, default `resume` (§23) |
| `position` | int | Evaluation order; first match wins (§14) |

### `help_center_sla_targets` (§8)

One row per policy per priority. `priority` uses the **existing** `help-center.priorities` keys —
`urgent`, `high`, `normal` (labelled Medium), `low`, `none`.

Each of `first_response`, `next_response`, `resolution` is stored as a pair:
`*_value` (int, nullable) + `*_unit` (`minutes` / `hours` / `days`). Nullable means *no target
for that stage* — a policy that only promises a first response is a real policy.

### `help_center_ticket_slas` (§37)

One row per Request. `help_center_sla_policy_id` (nullable — set null if the policy is deleted),
`applied_at`, `applied_by` (null when applied by evaluation, set on a manual change, §31),
`timezone` and `warning_percent` snapshotted at apply time.

### `help_center_sla_timers` (§9–§11, §16)

One row per stage per cycle: `kind` (`first_response` / `next_response` / `resolution`),
`cycle` (1-based; Next Response starts a new cycle per customer reply, §10), `status`
(`not_started` / `running` / `due_soon` / `paused` / `completed` / `breached`, §16),
`target_minutes` (the resolved business-minute budget), `started_at`, `due_at`, `paused_at`,
`paused_minutes`, `completed_at`, `breached_at`, `over_minutes` (§18).

### `help_center_sla_escalations` + `help_center_sla_escalation_runs` (§24, §25)

The rule (trigger, target kind, `actions` json, `is_active`, `position`) and a once-only ledger
keyed `(ticket_sla_id, escalation_id, cycle)` — an escalation that fires twice because a worker
ran twice is a duplicate page at 3am.

### `help_center_statuses.sla_behavior` (§19)

`continue` / `pause` / `complete_resolution` / `stop`, backfilled from the status's existing
`system_category`: `waiting` → pause, `resolved` → complete_resolution, `closed` → stop,
everything else → continue.

## Business rules

1. On ticket creation, active policies are evaluated in `position` order; the first whose
   conditions match is applied. No match → the Default SLA (§13).
2. First Response measures Ticket Created → first **outbound** message. Internal notes are a
   different table and cannot count (§9, §36).
3. Every customer reply opens a new Next Response cycle; the next outbound message closes it
   (§10).
4. Resolution runs from creation until the ticket enters a status whose behavior is
   `complete_resolution` (§11, §19).
5. All three clocks count **business minutes** in the policy's timezone, excluding holidays and
   paused intervals (§36).
6. A status with `pause` stops every running timer; leaving it resumes them with the remaining
   time, not from a fresh deadline (§20, §21).
7. At `warning_percent` of the target, a running timer becomes Due Soon (§17). At the deadline,
   Breached, recording due date, breach date, time over, assignee and status (§18).
8. Reopening a resolved ticket follows the policy's `reopen_behavior` (§23).
9. Every SLA event is written to the ticket's activity (§30) — including manual changes (§31).

## Acceptance criteria

Verbatim from §38, tracked per slice as each lands.

## UI requirements

- **Space → Settings → SLA** — a new settings section with four pages: SLA Policies, Business
  Hours, Holiday Calendar, Escalation Rules (§2).
- **Ticket right panel** — an SLA card with the policy name and one line per stage (§15), plus
  **Change SLA** with a confirmation for admins (§31).
- **Inbox** — an SLA chip on each row (`SLA 42m` / `Due Soon` / `Breached`), reflecting the most
  urgent active timer (§27); filters (§28) and sorts (§29).
- **Overview / Reporting** — the SLA metrics of §32 with the breakdowns of §33–§34.

## Real-time requirements

Timer state changes (Due Soon, Breached, paused, resumed) broadcast on the Space's existing
ticket channel via `TicketBroadcaster`, so an open Inbox re-chips without a refresh — the same
path P67 already uses.

## Queue requirements

- A scheduled `help-desk:sla-tick` command sweeps timers whose `due_at` or warning point has
  passed and dispatches the state change. Nothing about a breach can wait for somebody to open
  the ticket.
- Escalation actions and their notifications run as queued jobs (CLAUDE.md §11).

## Audit requirements

Every event in §30 is a `help_center_request_activity` row with an `sla_*` event key, so it
appears in the ticket's existing History tab with no new reader.

---

## Decisions

| # | Decision | Why |
|---|---|---|
| SLA-D1 | SLA gets its own doc and its own `help_center_sla_*` tables rather than columns on Requests | The instance/timer split is what makes Next Response *cycles* expressible at all — a ticket has an unbounded number of them, which is rows, not columns. |
| SLA-D2 | Priorities reuse `config('help-center.priorities')` keys, including `normal` for "Medium" | §8's four priorities are the ones the product already has. A second vocabulary would mean a ticket's priority and its SLA target's priority could disagree. `none` gets a target row too, so an unprioritized ticket is not silently exempt. |
| SLA-D3 | A policy with no business-hours calendar runs 24/7 | The requirement assumes a calendar, but "respond within 15 minutes, always" is a real commitment and a null FK expresses it without a fake always-open calendar row somebody could edit. |
| SLA-D4 | Holidays are Space-level, not per calendar | §7 puts the Holiday Calendar directly under the Space's SLA settings and never scopes it to a schedule. Hanging them off a calendar would ask every admin a question the requirement does not ask. |
| SLA-D5 | Assignment conditions are JSON on the policy; there is no separate rules table | §12 gives each policy one "SLA Applies When" block, and §14 orders *policies* ("Acme Enterprise SLA, Enterprise SLA, …, Default SLA"), so the rule and the policy are the same object. Conditions are authored and saved as one group in one drawer, so rows would buy nothing that HC-D56 asks rows for. |
| SLA-D6 | Customer Type, Ticket Type, Ticket Channel and Support Plan are replaced by two custom-field conditions | None of the four is a column, a table or a config vocabulary in this product. A dropdown with nothing in it and a matcher that can only return false is worse than an honest gap — and a custom field on the Customer or Company is where a team already records exactly these facts. |
| SLA-D7 | Durations are `value` + `unit`, not a single minute count | §8 asks for "2 Business Days", and a day under an 08:00–18:00 calendar is 10 business hours, not 24 — flattening to minutes at save time would either invent a constant or silently change meaning when the calendar is edited. The engine resolves days against the calendar at evaluation time. |
| SLA-D8 | `sla_behavior` is a column on `help_center_statuses`, not a mapping from `system_category` | §19 puts the control on the status row and shows Waiting-category statuses that pause and Active ones that continue — but a team may legitimately want an Active status that pauses. The category supplies the *default*, once, in the backfill. |
| SLA-D9 | Timers store `target_minutes` resolved at start, and `due_at` as a wall-clock instant | The Inbox sorts and filters by deadline (§29), and a sort that has to run a business-hours calculation per row is a sort that cannot be done in SQL. `due_at` is recomputed whenever the clock is paused, resumed or retargeted. |
| SLA-D10 | Breach and Due Soon are detected by a scheduled sweep, not lazily on read | A breach that only exists once somebody looks at the ticket cannot trigger an escalation (§24), which is the whole point of having them. |
| SLA-D11 | Escalation firing is recorded in a `runs` ledger with a unique key | Idempotence is the difference between one alert and one alert per worker tick. |
| SLA-D12 | §26 Workflow Builder integration is deferred, not stubbed | There is no workflow builder in this codebase. See *does not build*. |

---

## S1 — Schema, models & vocabulary (2026-08-24)

Nine migrations, the models over them, and the SLA vocabulary in `config/help-center.php`. No
behavior: nothing evaluates a policy, no clock runs, and no screen renders. This slice exists so
S2's engine and S3's screens are written against a settled shape rather than deciding it as they
go.

The models hold STATE and vocabulary only. Advancing a clock needs the policy's calendar and the
Space's holidays, so it belongs to the engine — a model that reaches for both is one that cannot
be reasoned about from its own row.

**New**

- `database/migrations/2026_08_24_000001` … `000009`
- `app/Models/HelpCenterBusinessHours.php`, `HelpCenterSlaHoliday.php`, `HelpCenterSlaPolicy.php`,
  `HelpCenterSlaTarget.php`, `HelpCenterTicketSla.php`, `HelpCenterSlaTimer.php`,
  `HelpCenterSlaEscalation.php`, `HelpCenterSlaEscalationRun.php`

**Changed**

- `config/help-center.php` — `sla_behaviors`, `sla_timer_kinds`, `sla_timer_statuses`,
  `sla_units`, `sla_reopen_behaviors`, `sla_condition_fields`, `sla_condition_operators`,
  `sla_escalation_triggers`, `sla_escalation_actions`, `sla_default_schedule`, `sla_week_days`
- `app/Models/HelpCenterSpace.php` — `businessHours`, `slaHolidays`, `slaPolicies`,
  `defaultSlaPolicy`, `slaEscalations`
- `app/Models/HelpCenterRequest.php` — `ticketSla`, `slaTimers`
- `app/Models/HelpCenterStatus.php` — `sla_behavior` fillable, the four behavior constants,
  `slaRuns()` and the payload keys the Workflow screen will render in S4

**Notes**

- The escalation-runs table names its foreign keys explicitly. Laravel's generated
  `help_center_sla_escalation_runs_help_center_sla_escalation_id_foreign` is 69 characters, past
  MySQL's 64-character identifier limit — and it fails at `alter table`, *after* the table
  exists, which leaves a half-created schema behind.
- The `sla_behavior` backfill was verified against the local database: `waiting` statuses now
  pause, `resolved` complete the Resolution clock, `closed` stop, everything else continues.

---

## S3 — The SLA settings screen (2026-08-24)

**Space → Settings → SLA**, four tabs on one page: SLA Policies, Business Hours, Holiday
Calendar, Escalation Rules (§2). Configuration only — it writes what the engine will read. The
page says so, in the same words Reassignment uses (HC-D17): a screen that implied response times
were being measured would be promising something nothing yet counts.

Four tabs rather than four nav entries because §2 lists them as one section, and they are one
question asked four ways: what do we promise, when does the clock run, when does it not, and who
hears about it.

**New**

- `app/Http/Controllers/HelpCenter/SlaCalendarController.php` (business hours + holidays — one
  screen, one idea), `SlaPolicyController.php` (policies, targets, conditions, duplicate,
  reorder), `SlaEscalationController.php`
- `app/Http/Requests/HelpCenter/BusinessHoursRequest.php`, `SlaHolidayRequest.php`,
  `SlaPolicyRequest.php`, `SlaEscalationRequest.php`
- `public/assets/js/help-center/sla-settings.js`

**Changed**

- `config/help-center.php` — the `sla` entry in `space_settings_nav`, directly after Workflow
- `routes/help-center.php` — fourteen routes under `/spaces/{space}/sla/…`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — the `sla` kind and
  `slaPayload()`
- `resources/views/help-center/space-settings.blade.php` — the `sla` branch and its own script

**Rules the screen enforces, and where**

| Rule | Enforced by |
|---|---|
| Exactly one default policy and one default calendar per Space | The controllers, inside the write's transaction. MySQL has no partial unique, and a unique on `(space, is_default)` would refuse a Space its second *non*-default row. |
| The first policy (and first calendar) is the default whatever the form said | The controllers. §13 falls back to the default; a Space whose only policy is not the default has an SLA that applies to nothing. |
| The default may not be deleted while others exist | The controllers, 422 with the sentence that says what to do instead. |
| A new policy is added LAST in evaluation order | The controller. §14 makes position the rule, so a new row must not silently outrank the ones already there. |
| A week with every day closed is refused | `BusinessHoursRequest`. Under it no clock could ever reach a deadline — which reads as "SLA is broken", not as "we set no hours". 24/7 is a policy with *no* calendar (SLA-D3). |
| A target value with no unit is refused | `SlaPolicyRequest`. A number with no unit is not a promise. |
| An action that needs a value must have one | `SlaEscalationRequest`, from each action's `needs` in config. A rule that runs and does nothing, silently, on a breach, is the worst kind of half-finished. |

**Note on the config shape.** `sla_escalation_actions` names its requirement `needs`, not
`value`. Every vocabulary reaches the browser as `['value' => $key] + $entry` and PHP's `+` keeps
the LEFT operand's keys — an entry key called `value` is dropped on the way out, and every action
would have rendered a free-text box. The same near-miss P48 and P56 both hit with `endpoint`.

**The mount bug, and why it was silent.** `PB.boot(name, component, options)` takes the element
id as `options.root` — the first argument is only a label for its console message. Called without
it, boot falls back to `#settings-root`, which this page does not have: it logs, returns, and the
screen sits on "Loading SLA…" forever with nothing visibly wrong. `space-settings.js` passes
`{ root: 'help-center-space-settings' }` for exactly this reason; this file now passes
`{ root: 'help-center-sla' }` and says why.

**Verified** in the browser end to end — the page renders, all four tabs switch, the policy
drawer opens with the five priority rows and the condition builder, and a calendar was created,
listed with its Default badge and week summary, and deleted again, with a clean console. Also
verified against the local database by driving the four controllers through their form
requests: a calendar, a holiday, a policy with two priority targets ("15 Minutes" / "2 Business
Days" both read back correctly) and an escalation rule were created and read back; the
closed-week and unitless-target refusals both fired. The settings page renders with the nav
entry, the mount point, the script tag and the bootstrap payload. The test rows were removed
afterwards.
