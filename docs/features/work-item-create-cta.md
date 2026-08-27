# "Create Work Item" — label and availability

> Status: **built.** A naming standard, a rename across the surfaces that create work, and a
> disabled state for the one case where the action cannot work.

---

## Requirement

One label for creating a work item, used everywhere, and disabled when there is no project to
create one in.

## Naming standard

| Term | Means | Where |
|---|---|---|
| **Create Work Item** | makes a NEW work item | sidebar action, Work Items page CTA, empty state, each state group's `+`, the quick-create modal |
| **Add work items** | assigns work that ALREADY exists to a container | Cycle, Epic and Module detail screens |

The two are different acts and were reading as the same one. "Add work item" on the Work Items
page created something; "Add work items" on a Cycle assigned something that existed. Both were
"Add", and the sidebar called the first one "New work item" — three labels for two ideas.

## Business Rules

1. Every surface that CREATES says **Create Work Item**, capitalised exactly that way.
2. Every surface that ASSIGNS keeps **Add work items**.
3. The sidebar action is **disabled when the user has no project** in the active workspace. A
   work item cannot exist outside a project, so the action has nowhere to go.
4. Disabled means *shown and refused*, never hidden — it is about to come back the moment a
   project exists, and a control that vanishes leaves somebody hunting for it.
5. It re-enables as soon as one project exists. Nothing to refresh: it is derived from the
   sidebar's own project list on every render.

### What "no project" means

The condition is **no project this user can open**, not "no project in the workspace" — read
from the same list the sidebar is already drawing (`ProjectNavigation::visible()`, via
`docs/features/workspace-project-access.md`). Those differ for an invited Member who belongs to
a workspace full of projects but has been added to none: the requirement's literal reading would
offer them a button with nothing behind it. Deriving both from one list also means the sidebar
can never show projects while claiming there are none.

### How "disabled" is built

A `<span>`, not a disabled `<a>` or `<button>`:

- no `href` and no `id`, so it cannot be clicked or focused — and the two scripts that bind the
  global create action by `new-work-item-btn` (`work-item-create.js`, `work-items.js`) find
  nothing to bind, rather than binding to something inert;
- `pointer-events` deliberately left ON, so the tooltip saying *why* still appears on hover —
  `pointer-events: none` would have taken the explanation away with the click;
- `aria-disabled="true"` for anybody not using a pointer.

Tooltip: **"Create a project before creating a work item."**

## Acceptance Criteria

Covered by `tests/Feature/Project/WorkItemCreateCtaTest.php`:

- **CTA-01** With a project, the sidebar renders `Create Work Item` and `id="new-work-item-btn"`,
  and "New work item" appears nowhere.
- **CTA-02** With no project, the action still renders, carries `aria-disabled="true"` and the
  tooltip, and has no `new-work-item-btn` id for the scripts to bind.
- **CTA-03** The creating surfaces say `Create Work Item` — page CTA, empty state, both grids'
  group `+`, and the quick-create modal title.
- **CTA-04** `cycles.js`, `epics.js` and `modules.js` still say `Add work items` and do **not**
  say `Create Work Item`.

CTA-03 and CTA-04 assert against the JS asset files: those labels are rendered by Vue and never
appear in a response body, so there is nothing to assert on server-side. A weaker test than a
rendered one, and still enough to fail the moment somebody sweeps the wrong label through —
which is the mistake worth catching.

## UI Requirements

Covered above. The empty-state copy on the Work Items page changed with the button beside it:
"Add the first one to get started" → "Create the first one to get started".

## Real-Time / Queue / Audit Requirements

None.

---

## Planning & Reasoning (Claude Code)

| # | Question | Decision |
|---|---|---|
| CTA-D1 | Title Case or sentence case? | **Title Case — `Create Work Item`** — as the requirement writes it, four times, including a "Final Label" section. It sits against sentence-case labels elsewhere in the sidebar; the requirement's own "identical capitalization" criterion is what settles it. |
| CTA-D2 | Hide or disable with no project? | **Disable**, per the requirement. A hidden control cannot explain itself, and this one has a specific, fixable reason for being unavailable. |
| CTA-D3 | Which "no project" test? | **No project the user can open** — see above. The literal reading would leave an invited Member with an enabled button and nowhere to go. |
| CTA-D4 | Rename tooltips and the modal title too? | **Yes.** The requirement names two CTAs, but leaving "Add work item" on a group's `+` and "New work item" as the modal title would keep two of the three old labels alive — the standard is the point, not the two buttons. |
