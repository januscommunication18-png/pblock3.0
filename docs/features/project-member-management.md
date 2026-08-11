# Feature: Project Member Management

> Feature spec + build record (CLAUDE.md §4/§15).
> Source requirement: *ProjectBlock 3.0 — Project Settings → Member Management* (25 pp.),
> referenced below as **§n**.
> Screens: `/projects/{project}/settings/members` and Workspace → Settings → Members.
> Status: **built and verified** (2026-08-10).

## Requirement

A Workspace member does not automatically participate in every project. Each project keeps
its own member list, and an authorized Project Admin assigns existing Workspace coworkers to
the project with a project-level role (§1, §2).

The governing rule is §38:

> Workspace membership determines whether a user belongs to the organisation.
> Project membership determines whether that coworker participates in a specific Project and
> what they can do inside it. **Workspace Member ≠ Automatic Project Member.**

## User Roles

**Project roles** (§3, §25) — new vocabulary, replacing the old `admin` / `member` pair:

| Role | Can | Cannot |
|---|---|---|
| **Admin** | Everything in the project: settings, members, all work | — |
| **Contributor** | Create/update work items, comment, view everything | Project settings, member management |
| **Commenter** | View work and participate through comments | Create or change any work |
| **Guest** | View explicitly permitted project information | Everything else |

**Who may manage members** (§17): Workspace Owner, Workspace Admin, or that project's own
Admin. A Workspace **Manager** qualifies only when they are also Project Admin — which falls
out naturally, because the check is on the *project* role, not the workspace one.
Contributors, Commenters and Guests never qualify.

**Workspace roles** gained **Manager** (§25) alongside the existing set — see
[Decisions](#decisions-taken) for why it was added rather than swapped in.

## Database Fields

| Table | Change |
|---|---|
| `project_members` | **+`added_by`** (§25) — who added this person, shown as "Added By" (§6). |
| `project_members.role` | Values migrated `member` → `contributor`; column default moved with them. |
| `project_activity` | **New** — `actor_id, event, target_user_id, target_name, old_role, new_role, meta, created_at`, exactly the fields §29 lists. |

`UNIQUE(project_id, user_id)` already existed, satisfying §25's "a user cannot have multiple
active membership records for the same Project".

## Business Rules

- **Membership, not visibility, grants access** (§18, §38). A workspace member reaches a
  project only by being explicitly added to it. Workspace **Owner and Admin** keep
  administrative visibility across every project (§18) — they must, since §17 lets them
  manage any project's members. **This supersedes Phase 4's PRJ-030**, where a *public*
  project was visible to every standard member; `visibility` no longer grants access on its
  own.
- **Permissions resolve in two layers** (§16). Layer 1 — are you in the workspace? Layer 2 —
  what is your role in *this* project? `ProjectMember::roleFor()` is the single entry point
  for layer 2, used by both `ProjectPolicy` and `WorkItemPolicy`, so the model has one
  implementation. Work-item creation now keys off the **project** role per the §34 matrix:
  Admin and Contributor may, Commenter and Guest may not.
- **The creator becomes Project Admin automatically** (§19), which is also what guarantees
  §20 holds from the moment a project exists.
- **The last Project Admin is protected** (§20): they cannot be demoted or removed, with the
  message §20 specifies.
- **Only active coworkers of this workspace can be added** (§9, §26) — enforced in the
  service, so it holds for every caller, not just the HTTP endpoint. Duplicates are rejected
  (§25).
- **Removing from a project never removes from the workspace** (§14). The person keeps their
  workspace membership and simply loses sight of the project.
- **Every membership change is audited** (§29) — actor, action, target, project, previous
  role, new role, timestamp — inside the same transaction as the change, so the two can never
  separate. `ProjectActivity::sentence()` renders §29's phrasing:
  *"Rohit Philip added John Smith to Website Redesign as Contributor."*

## Acceptance Criteria

`tests/Feature/Project/ProjectMemberManagementTest.php` — **16 tests, all passing**, mapped
to §35:

| AC | Covered |
|---|---|
| AC-01 | Members list shows both role layers, plus Added By |
| AC-02 / AC-03 | Candidates are active coworkers, minus those already on the project |
| AC-04 / AC-05 | All four project roles assignable; the member appears immediately |
| AC-06 | Project role governs work-item creation (Commenter/Guest → 403) |
| AC-07 | A Project Admin can change another member's role |
| AC-08 / AC-09 | Removal works and leaves workspace membership intact |
| AC-10 / AC-11 | Contributor and a non-Admin Workspace Manager are both refused (403) |
| AC-12 | A user from another workspace can never be added (422) |
| AC-13 | The last Project Admin cannot be demoted or removed |
| AC-14 | The creator is seeded as Project Admin |

Plus §25 duplicate rejection, §29 audit trail and sentence rendering, §24 screen-level
authorization, and the workspace role vocabulary.

## UI Requirements

**Project Settings → Members** (`public/assets/js/projects/members.js`) is built to **mirror
Workspace → Settings → Members**: same Tabulator v6.3.1 grid and shared `tabulator-skin.css`,
same `pb-section-head` + bordered toolbar, same `.pb-quickaction` "Actions" row menu, same
`pb-modal` dialogs, same avatar colour hashing and HTML escaping. The two member screens read
identically; only the *meaning* of a membership differs (§38).

- **Header** (§5) — "Members", the supporting sentence, and an **Add Member** button.
- **Grid** (§6) — Member (avatar + name, Lead badge), Email, **Workspace Role**,
  **Project Role**, Date Added, Added By, Action. Paginated 10 per page like the workspace
  grid.
- **Search + filter** (§21, §22) — search by name or email and filter by project role, both
  applied through `table.setFilter()` exactly as the workspace screen filters People.
- **Add Member modal** (§7, §8, §10, §32) — `pb-combo` coworker picker listing name, email
  and workspace role; a Project Role selector whose §10 description shows beneath it.
- **Row actions** (§33) — an "Actions" dropdown with Change Role and Remove from Project,
  rendered **only** for users who may manage members.
- **Change role / Remove** — `pb-modal` dialogs, the latter carrying §14's "will remain a
  member of the Workspace" notice.
- **Empty state** (§23) — "Build your project team" + CTA.
- Success messages are §11/§13/§14's exact strings, returned by the server rather than
  hard-coded in the client.

**Workspace → Settings → Members** — unchanged in behaviour; it renders roles from
`config('workspace.roles')`, so **Manager** appears automatically in the member list and the
invite role picker.

## Real-Time Requirements

None built. §30 suggests notifying someone when they are added to a project — a natural first
consumer of the Phase 1 notification stack (CLAUDE.md §8–§13), since `ProjectMemberManager`
already funnels every add through one method.

## Queue Requirements

None. Membership changes are small transactional updates.

## Audit Requirements

Implemented — see Business Rules. `project_activity` is deliberately separate from
`work_item_activity`: the subject is the project, not one work item, and the two feeds are
read in different places.

---

## Decisions taken

Both were confirmed with the product owner before building, because both change behaviour
that is already shipped:

**1. Manager added as a sixth workspace role, not a rename.** §25 lists workspace roles as
owner/admin/manager/guest, but the app runs on owner/admin/member/viewer/guest with live
data. Adding `manager` alongside means no membership is migrated and no policy changes
meaning. Manager carries no workspace privileges of its own — per §17 it only manages project
members when it is also that project's Admin, which the project-role check already handles.

**2. §38 adopted strictly, superseding PRJ-030.** Public projects are no longer visible to
every workspace member; access requires explicit project membership (or Workspace
Owner/Admin). This is the spec's stated core rule, and it changed `ProjectPolicy@view`, the
projects grid, the sidebar list and four existing tests. Those tests now assert the *new*
rule rather than being deleted — e.g. a member 404s on a public project, then opens it fine
once added.

## Bugs found while building

Project Settings → Members had **never worked**. Two independent faults, both fatal:

| Fault | Symptom |
|---|---|
| `config('projects.roles')` was never defined | `array_keys(null)` → **500** on add-member and change-role |
| `ProjectMember::isAdmin()` was called but never defined | `BadMethodCallException` on the last-admin guard |

Both are the same family as the missing `settings_nav`, `ProjectPolicy@manage` and
`ProjectPolicy@delete` found earlier in this phase: Phase 4 shipped controllers referencing
config keys and model methods that were never created. Worth a sweep for others.

## Files Changed

| File | Change |
|---|---|
| `config/workspace.php` | Added the **Manager** role + invite option. |
| `config/projects.php` | Added `roles` (label + §10 description), `default_role`, `contributor_roles`. |
| `database/migrations/2026_08_18_000001..3` | `project_members.added_by`; `member` → `contributor`; `project_activity`. |
| `app/Models/ProjectMember.php` | Four role constants, `added_by`, `addedBy()`, the missing `isAdmin()`, plus `roleFor()` / `contributes()` — the layer-2 entry point. |
| `app/Models/ProjectActivity.php` | **New** — audit entries and §29 sentence rendering. |
| `app/Services/ProjectMemberManager.php` | **New** — add / change role / remove, with §20/§25/§26 rules and §29 audit, all transactional. |
| `app/Http/Controllers/Project/ProjectMembersController.php` | Rewritten on the service; `manageMembers` authorization; shared `memberList()` / `candidates()` payloads. |
| `app/Policies/ProjectPolicy.php` | `view()` now requires project membership (§38); new `manageMembers()` (§17). |
| `app/Policies/WorkItemPolicy.php` | Contributor check moved onto the project role (§34). |
| `app/Services/ProjectCreator.php` | Records `added_by` on the seeded memberships. |
| `app/Services/ProjectNavigation.php`, `ProjectController` | Project lists follow §38. |
| `public/assets/js/projects/members.js` | Rebuilt per §31–§33, on the same Tabulator grid as the workspace members screen. |
| `resources/views/projects/settings/members.blade.php` | Loads Tabulator + the shared skin. |
| `resources/views/projects/settings/layout.blade.php` | Back arrow in the header; sidebar names the project. |
| `tests/Feature/Project/ProjectMemberManagementTest.php` | **New** — 14 tests across §35. |
| `ProjectAccessTest`, `ProjectListTest`, `WorkItemsTest` | Updated to assert the §38 rule and the §34 matrix. |

## Out of scope

Everything in §37 (public project membership, external invitations from this screen, custom
roles, bulk import, access expiry, SCIM/SSO mapping …), plus §12's multi-select add — §12
itself marks it "may be implemented later".

## How to verify locally

```bash
php artisan migrate
php artisan test tests/Feature/Project/ProjectMemberManagementTest.php   # 16 passed

node -e "global.PB={boot:(n,c)=>global.__C=c}; require('./public/assets/js/projects/members.js'); \
  require('@vue/compiler-dom').compile(global.__C.template, {onError:e=>{throw e}}); console.log('OK')"
```

Then open a project → `⋯` → **Settings** → **Members**.

## Pre-existing issues (NOT fixed)

`php artisan test tests/Feature` → **175 tests, 145 passed, 6 failed**. The same 6 that
predate this phase (5 × `store` returning 200 where `CreateProjectTest` expects 201, and an
`assertSee` against a Vue shell), already logged in `docs/features/project-card-actions.md`.
