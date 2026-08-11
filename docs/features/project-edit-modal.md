# Feature: Edit Project Modal (card → Edit)

> Feature spec + build record (CLAUDE.md §4/§15). Extends
> **Project Card Actions** (`docs/features/project-card-actions.md`) and
> **Create Project (Phase 4)** (`docs/phase-4-create-project.md`).
> Screen: `http://127.0.0.1:8000/projects` (Workspace → Projects grid).
> Status: **built and verified** (2026-08-10).

## Requirement

**Edit** in the project card's `⋯` menu previously navigated away to the project's own
settings screen. It must instead open a modal — the same one Add Project uses — with every
field pre-loaded from the card, specifically including:

1. **Project Status**
2. **Priority**
3. **Lead**
4. **Start Date**
5. **End Date**

…alongside name, project ID, description and access (visibility), so a manager can review and
change everything about a project in one dialog and save it in one action.

## User Roles

**Edit is narrower than the rest of the kebab menu** (product decision, 2026-08-10):

- **Open Edit / save** — workspace **Owner** or **Admin** only.
- **Project admin who is only a workspace member** — keeps manage rights, so the kebab still
  offers Archive/Restore and Delete, but **no Edit item**, and `PATCH /projects/{project}`
  returns **403**.
- **Member / Viewer / Guest** — no kebab at all; the endpoint returns **403**.

The card payload therefore carries two flags: `can_manage` (archive / restore / delete, as
before) and the new `can_edit` (workspace Owner/Admin). Hiding the item is presentation only;
authorization is enforced in the controller.

## Database Fields

No schema change. Writes columns already provisioned in Phase 4 / the chips work:

| Column | Modal control |
|---|---|
| `projects.name` | Name input |
| `projects.identifier` | **Read-only** — displayed as the Team handle, never written |
| `projects.description` | Description textarea |
| `projects.visibility` | Access chip (Public / Private) |
| `projects.lead_user_id` | Lead chip |
| `projects.state_id` | **Status chip** |
| `projects.priority_id` | **Priority chip** |
| `projects.start_date` | **Start date chip** (calendar popover) |
| `projects.end_date` | **Due date chip** (calendar popover) |

All tenant-scoped via `BelongsToTenant`; the route runs under `workspace.tenancy`, so a
project id from another workspace 404s at route-model binding.

## Business Rules

- Edit opens the **existing create modal in edit mode** (`editing = true`), pre-filled from
  the card payload the grid already holds — no extra fetch on open.
- The **project ID is immutable**. It is the `@handle` that work items and mentions reference,
  so once a project exists the field is read-only: the input carries `readonly` and a muted
  style, the client omits `identifier` from the payload, the form request has **no**
  `identifier` rule, and the controller never writes the column — a posted identifier is
  silently ignored rather than trusted. (It stays editable in *create* mode.)
- Status, Priority and the two dates are **staged in the form** and written by the single
  save — unlike the card chips, which PATCH immediately. Nothing is persisted until
  **Save changes**.
- **One request**: `PATCH /projects/{project}` writes all nine fields atomically.
- Any cleared picker posts `""` and is stored as **NULL**.
- Status and priority must belong to **this workspace's** configured sets → **422**
  otherwise (same rule the per-chip endpoints enforce).
- `end_date` must be **on or after** `start_date` → **422** otherwise. The calendar popover
  also disables out-of-range days client-side, so the error is a backstop.
- The workspace Owner/Admin check is re-run server-side on every save
  (`isWorkspaceAdmin()`), not just when the menu is rendered.
- If the `update` endpoint is missing from the bootstrap payload, Edit **falls back** to the
  old behaviour (navigate to `settings_url`), so the menu item never dead-ends.

## Acceptance Criteria

Covered by `tests/Feature/Project/ProjectEditModalTest.php` — **9 tests, 43 assertions,
all passing**.

- The `/projects` bootstrap exposes an `update` endpoint as an `__ID__` template.
- The card payload carries `state`, `priority`, `lead`, `start_date` and `end_date`, i.e.
  everything the modal loads.
- A manager can change name, description, visibility, lead, status, priority and both dates
  in one PATCH; the response returns the refreshed card and the DB reflects it.
- Cleared pickers (`""`) persist as NULL.
- A status or priority from outside the workspace → 422.
- A due date before the start date → 422 with an `end_date` validation error.
- A posted `identifier` is ignored (the project keeps its own), and omitting the field
  entirely is valid.
- A workspace **member** gets 403 and the project is unchanged.
- A **project admin who is only a workspace member** sees `can_manage: true` but
  `can_edit: false` on the card and gets 403 from the endpoint; a **workspace admin** sees
  `can_edit: true` and can save.

Manually verifiable on the page: `⋯` → **Edit** opens the modal with the project's name, ID,
description, access, lead, status, priority and both dates already filled in; the ID field is
greyed out and cannot be typed into; changing anything else and pressing **Save changes**
updates the card in place without a page reload.

## UI Requirements

Hybrid Vue-in-Blade (CLAUDE.md §14), plain Tailwind + POC tokens. All of it lives in the
existing `projects-index` component (`public/assets/js/projects/index.js`) — no new component
file and no new CSS, so the `_moretogether` prefix is not needed here.

- **One modal, two modes.** `editing` switches the primary button between
  *Create project* / *Save changes* (and *Creating…* / *Saving…* while in flight). The four
  extra chips render only in edit mode, because create has no server-side fields for them.
- **Status / Priority / Start / Due chips** sit in the same chip row as Access and Lead, and
  look identical to the card chips.
- **Chip label size**: every chip label on this screen (card *and* modal — lead, access,
  status, priority, both dates) is `text-[12px]`, one step down from the surrounding
  `text-[13px]` body. Toolbar buttons stay at 13px; they are controls, not chips.
- **Pickers are reused, not rebuilt.** The chips open the *same* shared fixed-position
  popovers the cards use (status list, priority list, quick-options + custom-date calendar).
  The form object carries a card-like shape (`id`, `can_manage`, `state`, `priority`,
  `start_date`, `end_date`) with the sentinel id `__form__`, so `menuTarget()` resolves a
  popover to either a card or the form and the handlers branch on `isForm()` — stage locally
  vs. PATCH immediately. No duplicated calendar (≈70 lines of template) and no second code
  path to keep in sync (SonarQube §17).
- **Popover layering**: popovers are `z-[120]` with a `z-[110]` backdrop over a `z-[70]`
  modal, so they open above the dialog and an outside click closes only the popover.
- **Errors**: field errors render under their input; the chip fields have no input of their
  own, so their messages are listed under the chip row via the `chipErrors` computed.
- **Feedback**: `$pb.toast('Project updated.')` on success, `$pb.firstError()` on failure.
  Closing the modal also closes any popover left open.

## Real-Time Requirements

None. Same note as the card-actions feature: once the Phase 1 notification system lands
(CLAUDE.md §8–§13), a project update is a natural event to broadcast to
`private-tenant.{tenantId}` so other members' grids refresh live — noted, not built.

## Queue Requirements

None. A single synchronous row update.

## Audit Requirements

Not logged (no audit table exists yet). When it arrives, `ProjectController@update` is the
hook point for "who changed what" on a project.

---

## Planning & Reasoning (Claude Code)

**1. Reused the modal instead of building a second one.** The create modal already renders
name, ID, description, access, lead and the cover header. Cloning it for edit would have
doubled ~200 lines of template. Instead the component gained an `editing` flag; `openCreate`
and `editProject` both funnel through a shared `resetModal()` and differ only in how they
seed `form`.

**2. Reused the popovers too — via a sentinel-shaped form.** The trickiest part was Status /
Priority / Start / End, since the card versions save on click. Rather than write modal-local
copies of the calendar and the two list menus, the form was given the same shape as a card
payload with `id: '__form__'`. `menuTarget(id)` resolves a popover's target to the form or a
card, and `setStatus` / `setPriority` / `setDates` take an early `isForm()` branch that just
mutates the form. One calendar, one status list, one priority list — used by both surfaces.

**3. One PATCH, not five.** The card edits each chip through its own endpoint. Firing five
requests from a modal Save would be non-atomic (a failure mid-way leaves a half-saved
project) and noisy. `PATCH /projects/{project}` (`projects.update`) takes everything and
returns the refreshed card, which the client swaps into the grid via `applyCard()`.

**4. New form request rather than reusing `UpdateProjectRequest`.** The existing request
(used by Project Settings → General) requires `timezone`, which the modal does not show —
reusing it would blank the timezone on every card edit. It also has a **latent bug**:
`Rule::in(config('projects.visibilities'))` validates against the config's *labels*
(`Public`, `Private`) because that config is an associative map, so `visibility=public`
cannot pass. `UpdateProjectDetailsRequest` uses `array_keys(...)` like `StoreProjectRequest`.
**Not fixed here** — it belongs to the settings screen, is out of scope for this change, and
is logged below.

**5. Made the project ID immutable at every layer, not just visually.** Product decision
(2026-08-10): the ID is the `@handle` everything references, so it cannot change after
creation. A `readonly` input alone would only be a UI suggestion, so the field was removed
from the payload, from the validation rules, and from the controller's assignments — the
endpoint has no code path that writes `identifier`. This also removes the need for a
self-ignoring uniqueness rule on update.

**6. Fixed a cross-request memoization bug the new test exposed.** `ProjectController`
memoizes the workspace role, states and priorities on the instance "for the request". But
Laravel caches **one controller instance on the Route object**, which lives for the whole
process — so the second request in a feature test (and every request under Octane) answered
with the *first* request's workspace role. The symptom: a workspace admin's card came back
`can_manage: false` purely because an earlier request in the same test was made by a member.
Fixed with `syncMemos()`, which flushes all three memos when the acting user or workspace
changes. Pre-existing, but it makes the role rules in this feature simply wrong under a
long-running server, so it was fixed rather than logged.

**7. Verified rather than assumed.** 8 new feature tests (all passing), plus a
`@vue/compiler-dom` compile of the ~37k-character template — a malformed template string
blanks the whole page with no PHP error, which no PHP test would catch.

## Files Changed

| File | Change |
|---|---|
| `app/Http/Requests/Project/UpdateProjectDetailsRequest.php` | **New** — validation for the edit modal: name/description/visibility/lead plus `state_id`, `priority_id`, `start_date`, `end_date`; normalizes `""` → NULL. Deliberately has **no** `identifier` rule. |
| `app/Http/Controllers/Project/ProjectController.php` | New `update()` (PATCH `/projects/{project}`): Owner/Admin check, workspace-membership check for status/priority, `hasColumn` guards for priority/dates, never writes `identifier`, returns the refreshed card. New `isWorkspaceAdmin()` + `can_edit` on the card payload. New `syncMemos()` fixing cross-request memo leakage. Published `update` in the index bootstrap `endpoints`. |
| `routes/project.php` | `PATCH /projects/{project}` → `projects.update`. |
| `public/assets/js/projects/index.js` | Edit opens the modal pre-filled instead of navigating away. Added `editing`/`editId` state, a card-shaped `form` (`pbBlankForm`), `isForm`/`menuTarget`/`canOpenMenu` helpers, form branches in `setStatus`/`setPriority`/`setDates`, `resetModal`/`closeModal`/`applyCard`/`basePayload`/`submit`/`update`, the `chipErrors` computed, the read-only project-ID input in edit mode, and the four chips + dynamic footer button in the modal template. |
| `tests/Feature/Project/ProjectEditModalTest.php` | **New** — 9 feature tests (endpoint exposed, card payload completeness, full save, clearing to NULL, foreign status/priority 422, date ordering 422, immutable identifier, non-manager 403, Owner/Admin-only Edit). |

Unchanged on purpose: migrations, `ProjectLifecycle`, `ProjectSettingsController`,
`resources/views/projects/index.blade.php`.

## Known gaps / pre-existing issues (NOT fixed — out of scope)

1. **Cover image and emoji are still cosmetic.** `projects` has no `emoji` or
   `cover_gradient` column, and the cover *upload* in the modal is a local preview with a
   simulated progress bar (`ProjectCreator` ignores it too). Choosing a gradient or uploading
   an image in the Edit modal therefore does **not** persist — exactly as in the create modal
   today. Persisting them needs a migration plus wiring to
   `projects.settings.cover`; call it out as its own change.
2. **`UpdateProjectRequest::rules()` visibility rule** compares against config *labels*, not
   keys (see reasoning §4). Affects Project Settings → General, not this modal.
3. **`CreateProjectTest` — 6 failures predate this change** (5 × `store` returning 200 where
   the test expects 201, and `assertSee('Add Project')` against what is now a Vue shell).
   Already logged in `docs/features/project-card-actions.md`; re-confirmed unchanged here.

## How to verify locally

```bash
php artisan test tests/Feature/Project/ProjectEditModalTest.php    # 9 passed
php artisan test tests/Feature/Project/ProjectCardActionsTest.php  # 4 passed (no regression)

# Vue template syntax (a broken template blanks the page with no PHP error)
node -e "global.PB={boot:(n,c)=>global.__C=c}; require('./public/assets/js/projects/index.js'); \
  require('@vue/compiler-dom').compile(global.__C.template, {onError:e=>{throw e}}); \
  console.log('template OK')"
```

Then open `http://127.0.0.1:8000/projects` as a workspace Owner/Admin and pick `⋯` → **Edit**
on any card. Assets are cache-busted by `pb_asset()` (`?v=<filemtime>`), so no hard refresh
is needed.
