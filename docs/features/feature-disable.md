# Disabling a project feature

Source: *ProjectBlock 3.0 — Project Feature Disable and Re-enable Requirement*, applied to
**Epics**, **Modules** and **Cycles** alike.

> Disabling a feature is a configuration change, not a delete operation.

That one line is the whole design. Everything below follows from it.

## Three states, not two

`App\Services\ProjectFeatureState` is the single place that answers "what state is this
feature in?", so all three features behave identically:

| State | When | Nav | Page | Records | Work item property |
|---|---|---|---|---|---|
| `enabled` | switched on | normal tab | full | editable | editable |
| `disabled_history` | off, records exist | tab stays, marked **Disabled** | loads, read-only, with the notice | readable, frozen | shown read-only, "Feature disabled" |
| `disabled_unused` | off, never used | **hidden** | — | none | hidden |

The middle row is the one worth defending. A tab that vanished would leave the only route to
that history being a URL somebody remembered — so it stays, and says plainly that the feature
is off. A project that never touched the feature gets the clean UI instead.

`state()` is deliberately **not** memoised: the value changes the instant someone flips the
toggle, and a cached "disabled" would hide a tab the user just turned on. It costs one COUNT,
and only for a feature that is already off.

## Where read and write part company

Each policy's `viewAny`/`view` is **not** gated on the feature; every ability that writes is.
Create/update/archive/manageWorkItems all funnel through `create()`, so a feature goes
read-only from one line rather than five. `delete()` checks separately because the role rule
differs.

## Freezing the work item property

While a feature is off its assignment freezes completely: no new value, no change, **and no
removal**. Removal matters as much as the other two — clearing the field would destroy exactly
the historical relationship the rule exists to protect.

Two of those three are enforced by the per-value rules (`EpicAssignable`, `CycleAssignable`,
`ModuleAssignable`). Removal is not, and cannot be:

- **`epic_id` / `cycle_id`** are `nullable`, and Laravel skips every remaining rule for a null
  value — the rule never runs on a clear.
- **`module_ids`** is a set, and a rule sees one id per call. An id dropped from the list
  simply never arrives.

Both need the whole payload, which is what `UpdateWorkItemRequest::after()` has and a rule does
not. Only that gap lives there, so no refusal produces two messages.

## The confirmation

One dialog, built in `ProjectFeatureState::disableConfirmation()` from the feature's own label.
The requirement gives the wording for Modules and says the same pattern applies to Epics and
Cycles — three hand-written copies of one sentence is three chances to drift. It is a real
gate, not a UI nicety: the server answers **409** with the dialog, and only accepts the change
when the client sends `confirm` back. It is skipped when the feature has no records, because a
confirmation with nothing to preserve has nothing to say.

## Re-enabling

Nothing to migrate and nothing to recreate — the flag is the only thing that ever changed.
Covered by a test per feature.

## What must never happen

No record, work item, sub-task, relationship, comment, activity, history or reporting row is
deleted because a feature was switched off. `EpicTest`, `ModuleTest` and `CycleTest` each
assert this directly, including that a removal attempt is refused rather than silently ignored.
