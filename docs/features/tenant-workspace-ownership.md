# Tenant, Workspace Ownership & Membership

> Status: **built.** Adds the owning entity above a workspace and the My / Invited split in the
> switcher. Access control itself was already membership-based — see
> `workspace-access-control.md`, which this document does not restate.

---

## Requirement

Every client who creates their own workspace gets their own **Tenant**, while the same person
can also be a member of workspaces owned by other tenants. Four things stay separate: tenant
ownership, workspace ownership, workspace membership, and access.

## The name

The requirement's **Tenant is `Account` in this codebase.**

`tenants` is already the workspace table here: a Workspace IS the stancl tenant (CLAUDE.md §18
D5), `config/tenancy.php` sets `tenant_model => Workspace`, and 58 migrations, the Back Office's
"Tenants" tab and `ClientAccess::allowsTenant()` all use the word that way. A second entity
called Tenant would collide with the table, the model and every docblock. `Account` is the same
concept under a name that is free.

| Requirement | Here |
|---|---|
| Tenant | `App\Models\Account` — `accounts` |
| `tenants.owner_user_id` | `accounts.owner_user_id` |
| Workspace | `App\Models\Workspace` — `tenants` (the stancl tenant) |
| `workspaces.tenant_id` | `tenants.account_id` |
| WorkspaceMembership | `App\Models\WorkspaceMembership` — `workspace_memberships` |

`Account` is **not** `Client` (`backoffice-clients.md`), which is also one row per user. A Client
is the Back Office's administrative record of a *person*, and exists for everybody who belongs to
any workspace — invited-only users included. An Account exists only once somebody *owns* a
workspace. Different questions: "who is this person to us?" versus "whose workspaces are these?"

## User Roles

Unchanged. Workspace roles (Owner, Admin, Manager, Member, Viewer, Guest) are evaluated per
workspace and per membership, exactly as §13 requires; owning the account a workspace sits under
adds no role and no privilege anywhere.

## Database Fields

### `accounts` (new, central)

| Field | Notes |
|---|---|
| `id` | |
| `code` | `AC-000001`, human-readable |
| `owner_user_id` | **unique**, nullable → `users.id` `nullOnDelete` |
| `name` | snapshot label, so a deleted owner does not leave the account nameless |
| `status` | `active` / `suspended` / `cancelled` |

`owner_user_id` is unique because §3 gives a user ONE account for the workspaces they own — the
database refuses a second rather than trusting every future writer to look one up first. It is
nullable so deleting a user does not take the ownership record of live workspaces with them;
MySQL and SQLite both allow many NULLs in a unique index, so orphans do not collide.

### `tenants` (workspaces)

| Field | Notes |
|---|---|
| `account_id` | **required**, → `accounts.id` `restrictOnDelete` |

`restrict`, never `cascade`: deleting an account must not silently take live workspaces — and
everything inside them — with it. Removing a workspace stays `WorkspaceDeleter`'s explicit act.

`account_id` is declared in `Workspace::getCustomColumns()`. Leaving it off that list is the
trap this codebase has already fallen into twice: stancl's VirtualColumn folds any undeclared
attribute into the `data` JSON, the real column stays null, and the NOT NULL constraint is the
first thing to notice — at insert time. `TenantOwnershipTest` asserts the column, not the blob.

### `workspace_memberships`

No change. `invited_by` / `accepted_at` from §7 are already expressed as `invited_at` /
`joined_at` plus the `workspace_invitations` row that carries the inviter — see
`member-invite-flow.md`.

## Business Rules

1. **One account per owner** (§3). `AccountProvisioner::forOwner()` is find-or-create, and the
   single place that decides. Getting this wrong is silent: a second account splits somebody's
   workspaces across two owners, and nothing on screen would say so until somebody looked at
   the switcher and found half their workspaces filed under "Invited".
2. **Created lazily** (§2, §20, §21). The account is created when somebody creates their first
   workspace — never at signup, and never by accepting an invitation. Someone who has only ever
   been invited owns nothing and has no account, which is the honest answer rather than an empty
   row waiting for them. The moment they click Create Workspace, they get one.
3. **Every workspace belongs to exactly one account** (§6), stamped by `WorkspaceCreator` inside
   the same transaction as the workspace and its Owner membership — the one funnel every path,
   onboarding and tests included, already goes through.
4. **Ownership is not access** (§4, §10, §17). Owning the account a workspace belongs to grants
   nothing on its own; a workspace is entered through an active membership and nothing else.
   This is what stops Mike, invited to Workspace A, reaching Workspace B under the same account.
   Enforced where it already was — `WorkspaceAccess`, `InitializeWorkspaceTenancy`,
   `WorkspacePolicy` (`workspace-access-control.md`) — and nothing here loosens it.
5. **Being invited never moves your account** (§4). Memberships and accounts do not touch.

## Requirement conformance (§1–§26)

Where each section of the requirement is answered. "Built" means verified by running it, not by
reading the code.

| § | Requirement | Status | Where |
|---|---|---|---|
| 1 | Separate tenant ownership / workspace ownership / membership / access | ✅ | `Account` ← `Workspace` ← `WorkspaceMembership`; access is `WorkspaceAccess` alone |
| 2 | First workspace creates the tenant, owner, workspace, owner membership | ✅ | `WorkspaceCreator::create()`, one transaction |
| 3 | One tenant per owner; later workspaces inherit it | ✅ | `AccountProvisioner::forOwner()` + unique `accounts.owner_user_id` |
| 4 | Being invited never changes your own tenant | ✅ | Memberships and accounts never touch |
| 5 | Own a tenant and be invited elsewhere simultaneously | ✅ | |
| 6 | `tenants` / `users` / `workspaces` structure | ✅ | `accounts`; `tenants.account_id` required; `users` carries no tenant column |
| 7 | `workspace_memberships` fields | ⚠️ **split** | `workspace_id, user_id, role, status, invited_at, joined_at`. `invited_by` and `accepted_at` live on `workspace_invitations` (`inviter_user_id`, `accepted_at`) — see deviation 1 below |
| 8 | Owner membership created with the workspace | ✅ | `WorkspaceCreator`, same transaction |
| 9 | Access = active membership only | ✅ | `WorkspaceAccess::allows()` |
| 10 | Mike sees Mike Workspace + Workspace A, never Workspace B | ✅ | Verified at runtime |
| 11 | Existing user: reuse tenant, or create one if they have none | ✅ | `AccountProvisioner::forOwner()` |
| 12 | Switcher grouped My / Invited | ✅ | `WorkspaceSwitcher::groupsFor()`, `partials/workspace-switcher.blade.php` |
| 13 | Roles Owner…Guest, evaluated per workspace | ⚠️ **partial** | Per-workspace evaluation ✅. `Contributor` and `Commenter` not in `config/workspace.roles` — see TO-D8 |
| 14 | Tenant isolation enforced at the backend | ✅ | `BelongsToTenant` on 83 models |
| 15 | Auth chain → 403 | ⚠️ **by caller** | JSON gets 403; a browser is redirected with the message — deliberate, see `workspace-access-control.md` D-WA2 |
| 16 | URL manipulation refused | ✅ | `InitializeWorkspaceTenancy` runs before route-model binding, so a foreign child id is a 404 |
| 17 | Cross-tenant access is per workspace, never per tenant | ✅ | Ownership grants nothing (TO-D4) |
| 18 | Invitation captures email/role/workspace/inviter/status | ⚠️ **modelled differently** | Pending is a `workspace_invitations` row, **not** a pending membership — see deviation 2 below |
| 19 | Existing user invited: no new user, no new tenant | ✅ | `WorkspaceInviter`; nothing in it touches `Account` |
| 20 | New user invited: no tenant on invitation-only signup | ✅ | `WorkspaceInvitationAccepter` creates only the membership |
| 21 | Invited user later creates a workspace → gets their own tenant | ✅ | Verified at runtime |
| 22 | Subscriptions | ⛔ **not built**, and re-scoped | Per the owner's decision billing is **per workspace**, not per tenant — TO-D9 |
| 23 | Usage tracking | ⛔ **not built** | Follows what is billed — TO-D9 |
| 24 | Relationship model | ✅ | `User` owns `Account` → `Workspace` → `WorkspaceMembership` → `User` |
| 25 | Full worked scenario | ✅ | `TenantOwnershipTest`, and re-verified at runtime |
| 26 | Four final principles | ✅ | |

### Deviation 1 — §7's `invited_by` / `accepted_at`

Both facts are recorded; they sit on `workspace_invitations` rather than on the membership,
because an invitation exists before there is a membership to put them on — and for an email that
has no user account yet, before there is a user either. `workspace_memberships.joined_at` is the
moment access began. Nothing is lost; the columns are one join away.

### Deviation 2 — §18's `status = pending` membership

Pending is deliberately **not** a membership status (`workspace-access-control.md` D-WA3). An
unaccepted invitation grants nothing, and modelling it as a membership row would mean every
access check in the application had to remember to exclude it — one forgotten `where` from being
the access leak that document exists to close. Acceptance is what CREATES the row, already
`active`, so Pending → Active is a transition that cannot be skipped rather than one that has to
be policed.

## Acceptance Criteria

Covered by `tests/Feature/Workspace/TenantOwnershipTest.php`:

- **TO-01** A first workspace creates the account, makes the creator its owner, and writes the
  Owner membership with it (§2, §8).
- **TO-02** A second and third workspace reuse that account; exactly one exists for the owner
  (§3, §11).
- **TO-03** `account_id` lands in its own column, not stancl's `data` blob (§6).
- **TO-04** An invitation alone creates no account for the invited user (§19, §20).
- **TO-05** An invited-only user who later creates a workspace gets their own account, and
  neither side's account moves (§21).
- **TO-06** The switcher returns My Workspaces and Invited Workspaces, in that order (§12).
- **TO-07** A workspace under the same account as an invitation the user holds stays invisible —
  Mike sees three workspaces, and Workspace B is not one of them (§10).
- **TO-08** An Owner membership counts a workspace as the user's own without the account
  changing hands (§12).

Existing coverage carries the rest: `WorkspaceAccessControlTest` for §4/§9/§15/§16/§17,
`InviteTest` / `InvitationAcceptTest` for §18–§20, `TenantIsolationTest` for §14.

## UI Requirements

The switcher modal splits into **My Workspaces** and **Invited Workspaces** (§12). An empty group
is dropped rather than rendered blank, and with only one group the heading is left off entirely —
the modal's own title already says whose workspaces these are.

A workspace is "mine" when the account it sits under is mine **or** my membership role in it is
Owner. The second is not redundant: a workspace can be handed over by making somebody Owner
without the account beneath it changing hands, and to that person it is theirs.

## Real-Time Requirements

None.

## Queue Requirements

None.

## Audit Requirements

None new. Workspace creation and membership changes are already trailed where they happen.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| TO-D1 | What to call the new entity | **`Account`.** Renaming `tenants` → `workspaces` to free the name would touch 58 migrations, stancl's config, the Back Office labels and many docblocks, for no user-visible gain and real risk around stancl internals. The mapping table above is the compensation: stated once, plainly. |
| TO-D2 | Reuse `clients` instead of a new table? | **No.** `clients` is one row per *person* and exists for invited-only users too; an account exists only for owners. Overloading it would conflate the Back Office's administrative record with the ownership grouping, and `ClientAccess` — which gates sign-in — would start answering an ownership question it does not own. |
| TO-D3 | Create the account at signup or at first workspace? | **First workspace** (§21). Creating it at signup would give every invited-only user an empty account and make "does this person own anything?" a question no column answers. |
| TO-D4 | Does account ownership grant workspace access? | **No** (§4/§17). Access is an active membership, full stop. Any shortcut here would recreate exactly the leak `workspace-access-control.md` exists to close. |
| TO-D5 | `account_id` NOT NULL, given existing rows? | **Yes**, in two steps: nullable + FK, backfill, then required. There is no correct default to create it with, so "add it NOT NULL" was never available. The FK is declared *with* the column because SQLite — which the test suite runs on — can attach a reference while adding a column but cannot `ALTER TABLE ADD CONSTRAINT`. |
| TO-D6 | Backfill: who owns an existing workspace? | `created_by`; where that user is gone, the workspace's own Owner membership names them; a workspace with neither gets an ownerless account of its own rather than being folded into somebody else's. Verified against a seeded copy of the §25 scenario before shipping. |
| TO-D7 | §22 subscriptions / §23 usage | **Out of scope**, by the owner's decision. No billing, plan, metering or usage system exists to attach to. |
| TO-D8 | §13 roles — add Contributor and Commenter? | **Out of scope**, by the owner's decision. Per-workspace role evaluation already behaves as §13 requires; the two new roles need permission definitions across every policy before they would grant anything. |
| TO-D9 | Which entity is billed | **The WORKSPACE**, by the owner's decision — overriding §22, which puts the subscription on the Tenant. A workspace is what a team actually buys and what a plan's limits (seats, storage, apps) are already felt in; the account exists to say whose workspaces these are, not to be the billed party. So an owner with three workspaces holds three subscriptions, not one covering all three. The codebase already assumes this: `WorkspaceSeatGuard::limitFor()` takes a **Workspace** and its docblock says that when billing lands it starts reading *the workspace's* plan — so the seam that exists is already the right shape, and no caller of it has to change. |

### Not built

- **§22 Subscription** — see TO-D7 and TO-D9. When built it hangs off the **workspace**:
  `subscriptions.tenant_id` (`tenants` being the workspace table), NOT `accounts`. §22's own
  wording says Tenant; the owner's decision overrides it, and this line exists so the eventual
  build does not follow the requirement document off a cliff.
- **§23 Usage** — follows what is billed, so the counters that feed a bill are per workspace
  too. Anything the account genuinely needs to total across its workspaces sums those rows;
  it does not need a second meter of its own. Worth confirming when the work is scheduled.
- **§13 role list** — see TO-D8.
