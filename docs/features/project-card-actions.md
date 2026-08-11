# Feature: Project Card Actions — Edit / Archive / Delete

> Feature spec + build record (CLAUDE.md §4/§15). Extends **Create Project (Phase 4)** —
> see `docs/features/create-project.md` and `docs/phase-4-create-project.md`.
> Screen: `http://127.0.0.1:8000/projects` (Workspace → Projects grid).
> Status: **built and verified** (2026-08-10).

## Requirement

Every project card in the Workspace → Projects grid needs a combo/dropdown action menu
offering **Edit**, **Archive** and **Delete**. The lifecycle endpoints
(`archive` / `restore` / `destroy`) already existed from Phase 4 but were unreachable from
the card — the card was a bare link with no per-project actions.

On the **Archived** list the same menu offers **Restore** in place of Archive, so a project
can be brought back without leaving the screen.

## User Roles

Mirrors the Phase 4 manage-rights rule — no new roles introduced.

- **See the menu / Edit / Archive / Restore / Delete** — project **admin** OR workspace
  **Owner/Admin** (`ProjectPolicy@update` / `@delete`, i.e. "manage rights").
- **Member / Viewer / Guest** — no kebab rendered, and the endpoints reject them server-side
  (403). Hiding the button is presentation only; authorization is enforced in the controller.

## Database Fields

No schema change. Uses the columns provisioned in Phase 4:

- `projects.status` (`active|archived`) — flipped by archive/restore.
- `projects.archived_at` — stamped on archive, nulled on restore.
- `projects.identifier` — the string the user must type back to confirm a delete.
- Delete cascades `project_members`, `project_item_states`, `project_item_labels` via FK.

All tenant-scoped via `BelongsToTenant`; the routes run under `workspace.tenancy`, so a
project id from another workspace 404s at route-model binding.

## Business Rules

- **Edit** navigates to the project's own settings screen
  (`projects.settings` → `general` section). It is only offered when the card payload carries
  a `settings_url`.
- **Archive** → `status=archived`, `archived_at=now()`. The card leaves the active grid
  immediately (the grid is status-filtered) and appears under the Archived toggle.
- **Restore** → `status=active`, `archived_at=null`; card leaves the archived grid.
- **Delete** is permanent and requires the project identifier typed back (PRJ-047). The
  server compares case-insensitively and returns **422** on a mismatch; the client keeps the
  Delete button disabled until the typed text matches, and blocks double-submit while the
  request is in flight.
- Manage rights are re-checked on every mutation: `archive`/`restore` via the controller's
  `canManageProject()`, `destroy` via `ProjectPolicy@delete`.
- The kebab lives inside the card's `<a>`, so its click handler uses `@click.stop.prevent`
  to open the menu instead of navigating to the project.

## Acceptance Criteria

Covered by `tests/Feature/Project/ProjectCardActionsTest.php` — **4 tests, 13 assertions,
all passing**.

- The `/projects` bootstrap payload exposes `archive`, `restore` and `destroy` endpoints as
  `__ID__` templates.
- A manager can archive a project (`status=archived`) and restore it (`status=active`).
- Delete with the wrong text → 422 and the project still exists.
- Delete with the correct identifier (typed lowercase, as the card displays it) → 200 and the
  project is gone.
- A workspace **member** gets 403 on both archive and delete, and the project survives.

Manually verifiable on the page: the `⋯` button appears on each card for a manager; the menu
opens above the card without being clipped; archive/delete remove the card and raise a toast;
clicking the menu does not open the project.

## UI Requirements

Hybrid Vue-in-Blade (CLAUDE.md §14), plain Tailwind + POC tokens, no FlyonUI on this screen.
All of it lives in the existing `projects-index` Vue component
(`public/assets/js/projects/index.js`) — no new component file, no new CSS, so the
`_moretogether` prefix is not needed here.

- **Kebab button** — `⋯` (vertical dots) on the card cover, top-right, in a flex row beside
  the existing visibility badge. `v-if="p.can_manage"`. Carries `aria-haspopup="menu"` and
  `aria-expanded`.
- **Actions menu** — a single shared, `position: fixed` popover (`role="menu"`), reusing the
  pattern already established by the status / priority / lead popovers on this screen so it
  escapes the card's `overflow-hidden`. Items: Edit · Archive **or** Restore · divider ·
  Delete (in `text-danger`). A full-screen transparent backdrop closes it on outside click.
- **Delete confirmation** — the shared `pb-modal` component with a typed-identifier input
  ("Type `WEB` to confirm"), Cancel + a `bg-danger` **Delete project** button that is
  disabled until the text matches and shows "Deleting…" while submitting. Enter submits.
- **Feedback** — success/error via the shared `$pb.toast()`; server validation messages are
  surfaced through `$pb.firstError()`.

## Real-Time Requirements

None. No broadcast events or Echo channels for this feature. When the Phase 1 notification
system lands (CLAUDE.md §8–§13), project archive/delete are natural events to broadcast to
`private-tenant.{tenantId}` so other members' grids update live — noted, not built.

## Queue Requirements

None. Archive, restore and delete are small synchronous row updates; no jobs dispatched.

## Audit Requirements

Not logged yet (no audit table exists). `archived_at` records *when* a project was archived,
but not *who* archived or deleted it. When the audit log arrives, `ProjectLifecycle::archive`
/ `restore` / `delete` are the three hook points — all mutations already funnel through that
service.

---

## Planning & Reasoning (Claude Code)

Followed the CLAUDE.md §4 workflow: read the screen first, defined the rules, then made the
smallest change that delivers them.

**1. Found where the cards are actually rendered.** `ProjectController@index` returns
`view('projects.index')`, which is a 32-line Vue shell mounting `#settings-root` from a
`data-bootstrap` JSON blob. The cards are built by the `projects-index` component's template
string in `public/assets/js/projects/index.js` — **not** by
`resources/views/app/projects.blade.php`, which is a legacy Blade version of the same screen
that the controller no longer renders. All UI work therefore went into the JS component.

**2. Reused the endpoints that already existed.** Phase 4 shipped `POST /{project}/archive`,
`POST /{project}/restore` and `DELETE /{project}` with typed-identifier confirmation. Nothing
new was needed on the routing side — the endpoints simply were not published to the client, so
three entries were added to the bootstrap `endpoints` map as `__ID__` templates (guarded with
`Route::has`, matching the existing entries) and resolved client-side with `$pb.withId()`.

**3. Found and fixed a live authorization bug.** `ProjectController@destroy` calls
`Auth::user()->can('delete', $project)`, but `ProjectPolicy` had no `delete` method. Laravel
denies an ability whose policy method is missing, so **the delete endpoint returned 403 for
everyone, including workspace owners.** Confirmed with tinker before the fix
(`can update: true`, `can delete: false`) and after (`can delete: true`). Without this, the
new Delete item could not have worked at all.

**4. Scoped delete rights to "manage rights".** `docs/features/create-project.md` states
"**Permanent delete** — manage rights + typed-name confirmation", so `delete` mirrors
`update` (workspace Owner/Admin, or project admin) rather than being Owner-only. The shared
rule was extracted into a private `canManage()` that both policy methods call, keeping
duplication low for SonarQube (§17).

**5. Matched existing UI patterns instead of inventing new ones.** The card has
`overflow-hidden`, so a nested absolute menu would be clipped — the screen already solves this
with shared `position: fixed` popovers driven by `getBoundingClientRect()` (status, priority,
lead, date). The actions menu follows that exact pattern, and the delete dialog reuses the
already-registered `pb-modal`. `pb-confirm` was **not** reused because it has no input slot
and the server requires typed confirmation.

**6. Verified rather than assumed.** Ran the new feature test (4/4 pass), and compiled the
33k-character Vue template with `@vue/compiler-dom` — a malformed template string would blank
the whole page silently, and the syntax check catches that where a PHP test never would.

### Decision candidate for CLAUDE.md §18

| Topic | Ambiguity | Decision |
|---|---|---|
| Project delete rights | `destroy()` called a `delete` ability that no policy defined; unclear whether delete is Owner-only or manage-rights. | **Manage rights** (workspace Owner/Admin **or** project admin), per `create-project.md`. Added `ProjectPolicy@delete` mirroring `@update`. |

Add as **D7** if the product owner confirms delete should not be Owner-only.

## Files Changed

| File | Change |
|---|---|
| `public/assets/js/projects/index.js` | Kebab button on the card cover; shared fixed-position actions menu (Edit / Archive \| Restore / Delete); `pb-modal` delete dialog with typed-identifier confirm. Added `actionsMenu` + `deleteModal` state, `actionsProject` + `deleteConfirmed` computeds, and `openActionsMenu`, `closeActionsMenu`, `editProject`, `setArchived`, `askDelete`, `closeDelete`, `confirmDelete`, `removeCard` methods. |
| `app/Http/Controllers/Project/ProjectController.php` | Published `archive`, `restore`, `destroy` in the index bootstrap `endpoints` map. |
| `app/Policies/ProjectPolicy.php` | Added the missing `delete()` ability; extracted the shared manage-rights check into `canManage()` and pointed `update()` at it. |
| `tests/Feature/Project/ProjectCardActionsTest.php` | **New** — 4 feature tests (endpoints exposed, archive/restore, typed-confirm delete, non-manager 403). |

Unchanged on purpose: routes (`routes/project.php` already had every endpoint),
`ProjectLifecycle`, migrations, `resources/views/projects/index.blade.php`.

## Pre-existing issues found while testing (NOT fixed — out of scope)

`php artisan test tests/Feature/Project tests/Feature/Settings` reports 30 failures that
predate this change. They are logged here so they are not mistaken for regressions:

1. **`ProjectTestCase::makeProject()` called with swapped arguments** — 24 errors across
   `ProjectIndexRenderTest`, `ProjectLeadTest`, `ProjectPriorityDatesTest`,
   `ProjectSettingsTest`, `ProjectStatusTest`, `ProjectVisibilityLifecycleTest`,
   `TopbarPresenceTest`. The helper signature is `(User $creator, Workspace $workspace)` but
   callers pass `($workspace, $owner)`. This is why archive/restore/delete had **no working
   coverage** before `ProjectCardActionsTest` was added.
2. **`ProjectController@store` returns 200, `CreateProjectTest` expects 201** — 5 failures.
   The JSON response needs an explicit `201` status.
3. **`CreateProjectTest::test_owner_can_open_the_projects_screen`** asserts
   `assertSee('Add Project')` against what is now a Vue shell; the string only exists inside
   the JS bundle. The assertion needs to target the bootstrap payload instead.

## How to verify locally

```bash
php artisan test tests/Feature/Project/ProjectCardActionsTest.php   # 4 passed

# Vue template syntax (a broken template blanks the page with no PHP error)
node -e "global.PB={boot:(n,c)=>global.__C=c}; require('./public/assets/js/projects/index.js'); \
  require('@vue/compiler-dom').compile(global.__C.template, {onError:e=>{throw e}}); \
  console.log('template OK')"
```

Then open `http://127.0.0.1:8000/projects` as a workspace Owner/Admin: each card shows `⋯`
top-right → Edit / Archive / Delete. Toggle **Archived** to see Restore in the same menu.
Assets are cache-busted by `pb_asset()` (`?v=<filemtime>`), so no hard refresh is needed.
