# Workspace Invitation & Project Access — bug fix

> Status: **fixed.** Three bugs with one shared cause, plus the 403/404 question the report
> raised. Access control itself was already membership-based (`workspace-access-control.md`,
> `tenant-workspace-ownership.md`); nothing here loosens it.

---

## The one cause behind the first two bugs

"Which projects can this person see?" was written **four** times:

| Copy | Rule it used |
|---|---|
| `ProjectNavigation::visible()` | membership only (§38 — correct) |
| `ProjectController::visibleProjects()` | membership only (a second copy of the same rule) |
| `WelcomeController::sidebarProjects()` | **membership OR `visibility = public`** — the pre-§38 rule |
| `ProjectPolicy::view()` | membership only — and this is the one that decides |

Project Member Management §38 stopped `visibility` granting access. Three copies were updated;
`WelcomeController`'s was missed. So on `/welcome`, an invited Member was shown every public
project in the workspace — and the policy then refused to open any of them:

```
WELCOME sidebar shows : Project Alpha, Project Beta
  click "Project Alpha" -> opens          (Mike is a project member)
  click "Project Beta"  -> 404 NOT FOUND  (public, but Mike was never added)
```

That is bug §1 exactly. It also produces bug §2 from the other side: the Projects page uses the
membership rule, so the project the sidebar offered was absent there — two lists, two rules, one
of them wrong.

## Requirement → fix

| § | Symptom | Fix |
|---|---|---|
| 1 | Assigned project 404s from the sidebar | `WelcomeController::sidebarProjects()` deleted; it now calls `ProjectNavigation::sidebarProjects()` |
| 2 | Assigned project missing from the Projects page | `ProjectController::visibleProjects()` now calls `ProjectNavigation::visible($user, $status)` |
| 3 | Member sees and can use "+ Add Project" | `ProjectPolicy::create()` — Member is now **no**; `StoreProjectRequest::authorize()` makes the endpoint answer 403 before validation |
| 6 | Navigation inconsistency | One definition — `ProjectNavigation::visible()` — behind every surface |
| 7 | 404 where 403 would be honest | `ProjectAccess::guardView()` — see below |
| 4, 5, 8 | Per-workspace roles, active tenant context | Already correct; now covered by tests |

`ProjectNavigation::visible()` gained a `$status` parameter so the Projects page's Archived tab
narrows the same set instead of writing its own.

### §3 — who may create a project

| Owner | Admin | Manager | Member | Viewer | Guest |
|---|---|---|---|---|---|
| yes | yes | `config('projects.manager_can_create')` (default **true**) | **no** | no | no |

Member was `yes`, which is why the button appeared. Because `ProjectPolicy::create()` is what
both the button and `store()` read, one change fixes the UI and the endpoint together — the
requirement's "do not rely only on hiding the button" is satisfied by there being nothing else
to rely on.

Two related repairs went with it:

- **Authorization now answers before validation.** `StoreProjectRequest::authorize()` was
  `return true`, so a Member posting a malformed body got **422** — their spelling marked before
  being told they may not do this at all. §3 asks for 403, and 403 is the honest first answer
  whatever the payload.
- **The Cycles, Epics and Modules screens** passed `can('create', [WorkItem::class, $project])`
  as `canCreateProject`. That is a work-item ability wired to the "+ Add Project" button, so a
  Member who could add work items was offered a button that creates projects. All three now ask
  the project question.

### §7 — 403 or 404

`App\Services\ProjectAccess` owns the choice, in one place, because a security decision restated
in eight controllers is eight chances to restate it wrongly.

| Situation | Answer |
|---|---|
| Public project, user is in its workspace, not a project member | **403** + "You don't have permission to access this project." |
| Private project, user is in its workspace, not a project member | **404** |
| Any project, user is not in its workspace | **404** |

§7 asks for 403 and allows 404 "where intentionally hiding resource existence is part of the
security model" — which for a private project it is (PRJ-031/032). A public project's *existence*
is already common knowledge inside its own workspace, so hiding it there buys nothing and reads
as a bug. "Public" means public within a workspace, never across one, which is why both halves
of the test are required.

Applied at project **page** entry points (`projects.show`, work items, overview). Sub-resource
and media endpoints keep their flat 404: they are not "opening a project", and an API that
discriminates its refusals is a probe for the ids behind them.

## Acceptance Criteria

Covered by `tests/Feature/Project/InvitedMemberProjectAccessTest.php`, plus updates to
`ProjectAccessTest` and `CreateProjectTest`:

- **WP-01** Every project `/welcome` offers can be opened — asserted by opening each one, not by
  comparing lists (§1, §6).
- **WP-02** A public project the user was never added to is not offered anywhere (§1).
- **WP-03** The Projects page and the sidebar rendered beside it list the same set (§2, §6).
- **WP-04** A Member is not offered "+ Add Project" on `/welcome` or the Projects page; an Owner
  still is (§3).
- **WP-05** Member, Viewer and Guest all get 403 from `POST /projects` — with a valid body and
  with an empty one (§3).
- **WP-06** Manager follows `projects.manager_can_create`, both ways (§3).
- **WP-07** Owning one workspace grants nothing in another, in either direction (§4, §5).
- **WP-08** Public → 403, private → 404, other workspace → 404 (§7).

## UI Requirements

None new. "+ Add Project" disappears for roles that may not use it, because every screen reads
the policy.

## Real-Time / Queue / Audit Requirements

None.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| WP-D1 | Fix the /welcome copy, or delete it? | **Delete it.** Correcting the fourth copy would leave four copies. The rule now lives once, with a comment on `ProjectNavigation::visible()` saying why a second one is not a duplication problem but a 404 generator. |
| WP-D2 | Manager's default for creating projects | **Allowed.** The requirement marks the row "Configurable" and leaves the default open; a manager who cannot start a project has little left to manage. `PROJECTS_MANAGER_CAN_CREATE=false` flips it. |
| WP-D3 | 403 vs 404 (§7) | **Split by visibility** — the owner's decision. 403 where the project's existence is already open to the user, 404 where it is not. Preserves PRJ-031/032 for private projects while giving §7 the friendly answer where it costs nothing. |
| WP-D4 | Authorization before validation on create | **Yes**, moved into `StoreProjectRequest::authorize()`. A 422 for somebody who may not create anything is the wrong first answer. The controller's own `abort_unless` stays: two cheap gates on a rule that decides who may shape a workspace is the right ratio. |
| WP-D5 | Extend the 403/404 split to sub-resources? | **No.** Media, attachment and mention endpoints keep 404 — they are not "opening a project", and discriminated refusals on an API enumerate ids. |

### Verified, not assumed

The bug was reproduced first (a Member shown two projects, one of which 404s), then re-run after
the fix (one project, which opens). Status codes were checked through real HTTP requests, not by
reading the policy.

### Note on the existing suite

`CreateProjectTest` has **pre-existing** failures unrelated to this work: five tests expect
`201` from `POST /projects` and the controller returns `200`, and
`test_owner_can_open_the_projects_screen` asserts on markup that has since changed. Confirmed by
re-running them with this change reverted — they fail either way. Left alone: they are a
different repair, and folding them in here would hide which change fixed what.
