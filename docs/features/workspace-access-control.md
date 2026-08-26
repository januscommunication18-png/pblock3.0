# Workspace visibility and access control

Treated as a **multi-tenant access-control bug**, not a feature request: the rule below was
already how most of the application behaved, and the places that did not follow it were holes.

## Requirement

A signed-in user may see and enter only the workspaces where they hold an **active**
membership. Mike belongs to Workspace 1 and not to Workspace 2: Workspace 2 must not appear in
his switcher, must not be selectable, and must not be reachable by typing its id — nor by
typing the id of anything inside it.

The query is a join, never a filtered listing of everything:

```
authenticated user → active workspace membership → allowed workspaces
```

## User Roles

Everyone. This is the gate that runs before role matters; owner / admin / member / guest
distinctions are decided *inside* a workspace by `WorkspacePolicy` and the project policies,
and none of them are consulted until this check has passed.

## Database Fields

No migration. The rule is expressed with what already exists:

| Requirement state | How it is stored | Grants access? |
|---|---|---|
| Pending | a `workspace_invitations` row — **no membership row exists** | no |
| Active | `workspace_memberships.status = 'active'` | **yes** |
| Suspended | `workspace_memberships.status = 'disabled'` (Back Office "Disable Tenant Access") | no |
| Removed | the membership row is deleted | no |

Pending is deliberately *not* a membership status. An unaccepted invitation grants nothing, and
modelling it as a membership would mean every access check had to remember to exclude it —
one forgotten `where` from being the bug this document exists for. Acceptance is what CREATES
the row, already `active` (`WorkspaceInvitationAccepter`), so **Pending → Active** is a
transition that cannot be skipped.

`users.current_workspace_id` is the pointer to the workspace a person is currently working in.
It is a convenience, **never a permission** — see the bug below.

## Business Rules

1. `App\Services\WorkspaceAccess` is the single definition of "may this user enter this
   workspace". Two gates, both required: an **active membership**, and `ClientAccess` (the Back
   Office's global "disable client" and per-membership "disable tenant access" switches).
2. Anything that *lists* workspaces reads `User::workspaces()` (active memberships only) or
   `WorkspaceAccess::workspacesFor()`. Nothing queries `workspaces` and narrows afterwards.
3. `InitializeWorkspaceTenancy` — the middleware every workspace-level route group runs, ahead
   of route-model binding — refuses to initialize tenancy to a workspace the user may not
   enter. Everything downstream inherits the check: once tenancy is right, `BelongsToTenant`
   confines every tenant-owned model, so `/projects/15` from another workspace is a 404.
4. Losing access releases the pointer. A `WorkspaceMembership` that is deleted, or whose status
   moves away from `active`, clears `current_workspace_id` for that user (model event), so the
   switcher and the landing router agree immediately.
5. One closed workspace is not a closed account. Somebody who belongs to five workspaces and
   loses one lands in another, with the message — not on the sign-in screen.

## Where the rule is applied

| Surface | How |
|---|---|
| Workspace switcher | `WorkspaceSwitcher` → `User::workspaces()` (active only) |
| Dashboard / welcome | `WelcomeController` → `WorkspaceAccess::resolveCurrent()` |
| Switching workspace | `SwitchWorkspaceController` → `can('view', $workspace)` → `WorkspaceAccess` |
| Projects, Wiki, Help Desk, Drafts, Inbox, Search, Your Work, Settings, Quick create | `workspace.tenancy` middleware |
| Child resources (`/projects/15`, work items, pages, spaces …) | tenant scope, after the middleware has established the right tenant |
| API / fetch endpoints | same middleware; a JSON caller gets **403** rather than a redirect |
| Help Center email links (`/help-center/go/spaces/{space}`) | `SpaceEntryController` → `can('view', $workspace)` before making it current |
| Client Hub / Help Center public portals (`acme.projectblock.app`) | out of scope — `IdentifyTenantByHost`; these are the tenant's *public* addresses and are gated by the app being enabled, not by membership |

## The bug, precisely

`users.current_workspace_id` is a stored pointer, and nothing that revoked access cleared it.
`InitializeWorkspaceTenancy` read the pointer, asked only whether the *client* was disabled —
and `ClientAccess::allowsTenant()` answers **true when there is no membership at all**, by
design, because "no membership" is an authorization question it deliberately does not own —
and then initialized tenancy. So a member removed from a workspace kept full access to it on
every subsequent request, indefinitely, until they happened to switch away.

Two smaller holes fed the same failure:

- `User::workspaces()` claimed in its docblock to return workspaces "through active
  memberships" and had no status filter, so a suspended membership still listed — and offered —
  its workspace in the switcher.
- `WelcomeController` read `current_workspace_id` directly and rendered the get-started home,
  sidebar projects and all, for whatever it pointed at.

## Acceptance Criteria

- **WA-01** With memberships in Workspace 1 only, the switcher, `User::workspaces()` and
  `WorkspaceAccess::workspacesFor()` all return exactly Workspace 1.
- **WA-02** `POST /workspaces/2/switch` returns 403 and leaves the active workspace unchanged.
- **WA-03** A member removed from the workspace they were sitting in cannot load any
  workspace-level route with the stale pointer; the pointer is cleared.
- **WA-04** A suspended membership stops the workspace being listed and fails
  `can('view', $workspace)`.
- **WA-05** A user who loses one of two workspaces is moved to the other and shown
  "You do not have access to this Workspace."
- **WA-06** A user who loses their only workspace is sent to first-workspace onboarding (or
  signed out, when the whole client is disabled).
- **WA-07** A JSON/fetch request pointed at a workspace the caller may no longer enter gets
  **403**, not a redirect.
- **WA-08** `GET /projects/{id}` for a project in another workspace answers **404** — the id is
  invisible to the tenant scope, and 404 does not confirm it exists.
- **WA-09** A pending invitation creates no membership and grants no access; accepting it
  creates one that is `active`.

Covered by `tests/Feature/Workspace/WorkspaceAccessControlTest.php`.

## UI Requirements

None new. The switcher, welcome screen and landing router simply stop offering workspaces the
user may not enter. The refusal message is `WorkspaceAccess::DENIED_MESSAGE` —
"You do not have access to this Workspace." — flashed as `status`.

## Real-Time Requirements

None. Revocation takes effect on the revoked user's next request; there is no live eviction of
an open page, and that is a deliberate limit rather than an oversight — see below.

## Queue Requirements

None.

## Audit Requirements

Membership changes made in the Back Office are already trailed by `BackofficeAudit`. Workspace
entry itself is not logged; refusals are not either.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| D-WA1 | Middleware, policy, or both? | **Both, one definition.** The middleware is the only layer that can run *before* route-model binding, which is what makes a foreign child id a 404 instead of a leak; a policy cannot, because by the time it has a model the tenant scope is already set. `WorkspacePolicy::view()` therefore delegates to the same `WorkspaceAccess` service rather than restating the rule. |
| D-WA2 | 403 or redirect? | **Both, by caller.** JSON/fetch gets 403 — a redirect to a sign-in page is not something an API client can act on. A browser gets a redirect with the message, because a 403 page in a workspace that no longer exists for you is a dead end. |
| D-WA3 | New membership statuses (`pending`, `suspended`, `removed`)? | **No.** The four states the requirement names already map onto what is stored (table above), and adding a `pending` membership would make "does a row exist?" stop meaning "is this person in?" — inventing exactly the class of check that can be forgotten. |
| D-WA4 | Clear `current_workspace_id` on revocation, when the middleware already refuses? | **Yes, both.** The middleware is the security boundary; clearing the pointer is what makes the switcher, the landing router and the next request agree without each having to detect staleness. Belt and braces on an access-control bug is the right ratio. |
| D-WA5 | Replay the requested URL after moving somebody to another workspace? | **No** — that URL named a resource in the workspace they just lost, so following it into a different one answers a 404 that reads like a bug. They land on their normal landing page with the message. |

### Known limit

Revocation is enforced per request. Someone with a page already open keeps that rendered page
until they navigate or make a request — every one of which now goes through the check. Evicting
an open session live would need a broadcast on the user's private channel; deferred, and noted
here rather than left to be discovered.
