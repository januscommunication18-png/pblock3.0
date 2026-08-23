# Back Office — Clients

> Phase 1 of the Back Office modules (§25). Builds on `docs/features/backoffice-auth.md`.

## Requirement

The Back Office's Clients module: a searchable, filterable list of every SaaS client, and a
detail page showing everything the platform knows about one — its workspaces, the applications
it has subscribed to, its Help Desk spaces, its users, its usage and its activity — plus the
three administrative actions: reset the primary contact's password, disable the client, delete
the client.

## What a Client IS (BC-D7 — SUPERSEDES BC-D1)

**A Client is a global USER**, keyed on `users.id`, with the email as its human-readable
identifier. One row per person, however many tenants they belong to.

This replaces the first model (BC-D1, below), which grouped WORKSPACES under an invented client
entity. That produced exactly the duplication the owner then reported: somebody who belongs to
three tenants appeared three times. The corrected rule, in the requirement's own words: *"first
identify the unique user/customer account and then load all tenant memberships belonging to that
account."*

Keyed on `user_id`, NOT on email — an email address can change, and a primary relationship that
moves when somebody updates their profile is not a relationship. The email is what the screen
shows; the id is what the database joins on.

`tenants.client_id` is DROPPED. Under this model a tenant has many clients (everybody in it), so
a single foreign key on the tenant could never express the relationship. Client → tenants now
runs through `workspace_memberships`, which is also where the ROLE in each tenant comes from.

### Superseded — the original model (BC-D1)

**A Client groups Workspaces.** `CL-000001 Acme` owns however many workspaces Acme has, and the
applications those workspaces have enabled are the applications Acme has subscribed to.

This is a NEW entity. Before this build there was no grouping above the tenant: `Workspace`
extends stancl's `Tenant` (table `tenants`) and that was the top of the tree.

**Backfill is 1:1** — every existing workspace becomes its own client. Grouping by workspace
OWNER was considered and rejected on the evidence: 15 workspaces across 9 owners, with one
address owning six that are plainly test workspaces rather than one company's. A backfill that
invents groupings produces clients nobody created, and un-merging is harder than merging.

## Scope

Everything §25 lists. Two things are deliberately NOT invented (confirmed with the owner):

- **Subscription (§12)** — no plans, packages or subscriptions exist anywhere. The tab renders an
  honest empty state rather than fabricated billing.
- **Usage limits (§13)** — the COUNTS are real and are shown; the denominators ("18 / 25") are
  not, because no quota exists to read. Bars appear when limits do.

The Plan column (§3) and Plan filter (§5) render "—" and are disabled for the same reason.

## Database fields

**`clients`** (new — CENTRAL)
`id`, `code` (`CL-000128`, unique), `name`, `primary_contact_id` (→ `users`), `phone`, `country`,
`timezone`, `status`, `disabled_at`, `pending_deletion_at`, `deleted_at`, `created_by`
(→ `backoffice_users`), timestamps.

**`tenants`** gains `client_id` (nullable FK). Nullable because a workspace created before its
client exists — or one deliberately unassigned — must still work.

**`client_activities`** (new — CENTRAL): `id`, `client_id`, `backoffice_user_id` (nullable),
`action`, `description`, `old_value`, `new_value`, `meta`, `created_at`.

## Business rules

1. Status is one of `active`, `trial`, `disabled`, `suspended`, `cancelled`, `pending_deletion`.
2. Disabling a client blocks its users from signing in to the customer application (§17). Data is
   retained.
3. Deleting is TWO-STAGE (§19): `pending_deletion` + a 30-day retention window, login blocked,
   data retained, restorable by a Super Admin. Nothing is hard-deleted by the button.
4. Delete requires the client's name typed exactly.
5. Reset Password sends a reset link to the primary contact. The administrator never sees a
   password.
6. Every action writes both a Back Office audit row (§24) and a client activity entry (§15).

## Acceptance criteria

- `/backoffice/clients` lists every client with the §3 columns.
- Search matches client name, contact name, email, workspace name and client code.
- Status, Application and Created-date filters work and combine.
- A client row opens `/backoffice/clients/{id}` with all eight tabs.
- Every tab with no data shows an empty state, never an empty table (§22).
- Reset Password sends a link and audits `CLIENT_PASSWORD_RESET_REQUESTED`.
- Disable blocks customer login and flips the button to Enable.
- Delete requires the typed name, sets `pending_deletion`, blocks login, retains data.
- Destructive actions are not styled like ordinary ones (§6).

## Decisions

| # | Decision | Why |
|---|---|---|
| BC-D1 | `clients` is a new table above `tenants`, backfilled 1:1 | Confirmed with the owner: a Client owns many workspaces. 1:1 because grouping by owner would invent companies out of one developer's test workspaces. |
| BC-D2 | `client_id` is nullable on `tenants` | A workspace signed up before anybody assigned it a client must keep working. A NOT NULL column would make the customer signup path depend on the Back Office. |
| BC-D3 | Disable is enforced in the customer AUTH path, not only by hiding UI | §17 says login must be blocked. A status the Back Office shows but nothing enforces is a switch that does nothing. |
| BC-D4 | Deletion is soft, two-stage, with a 30-day window | §19. The button that erases a tenant permanently should not exist in a UI where it can be misclicked. |
| BC-D5 | `client_activities` is separate from `backoffice_audit_logs` | They answer different questions and have different readers: the audit log is "what did an administrator do", scoped to security; the activity feed is "what happened to this client", including things no administrator did (a workspace created, an app enabled). One table would make both queries filter constantly. |
| BC-D6 | Applications are DERIVED from `workspace_settings`, not stored on the client | `wiki_enabled` and `help_desk_enabled` already exist per workspace and are the truth the application actually reads. A second copy on the client would be a second answer to "is Wiki on?". |

---

## Files

**New**
- `app/Models/Client.php`, `ClientActivity.php`
- `app/Services/Backoffice/ClientAdmin.php` (the three actions), `ClientAccess.php` (§17's teeth)
- `app/Http/Controllers/Backoffice/ClientController.php`
- `routes/backoffice.php` — seven client routes
- `resources/views/backoffice/clients/` — `index`, `show`, and eight tab partials
- `resources/views/components/backoffice/` — `status-badge` (§21), `empty-state` (§22)
- Migrations `2026_08_23_110001`–`110004` (clients, `tenants.client_id`, activities, backfill)

**Changed**
- `app/Models/Workspace.php` — `workspaceSettings()` relation
- `app/Models/BackofficeAuditLog.php` — the `CLIENT_*` actions of §24
- `app/Http/Controllers/Auth/SignInController.php`, `VerifyCodeController.php` — the §17 check
- `app/Http/Middleware/InitializeWorkspaceTenancy.php` — per-workspace enforcement
- `resources/views/backoffice/layout.blade.php` — the §1/§2 shell and sidebar
- `app/Providers/AppServiceProvider.php` — guest redirect made Back Office aware

## §17 needed two halves, and the first one alone was wrong

The obvious implementation — refuse sign-in when the user's client is disabled — passed nothing.
The test subject belonged to **six** clients, so disabling one left five live ones and the check
waved them through.

Re-reading §17 ("users associated with this client will no longer be able to access **their
workspace or applications**") the rule is about the WORKSPACE, not the account:

- `ClientAccess::allowsWorkspace()` in `InitializeWorkspaceTenancy` — the chokepoint every
  tenant-scoped request already passes through, so no route can miss it. This is what actually
  closes a disabled client.
- `ClientAccess::allows()` at both sign-in paths — refuses somebody with nowhere left to go.

Someone at a disabled company and a live one can still sign in and reach the live one. Anyone
whose only client is disabled cannot sign in at all. Verified both ways.

## Verified

| | Result |
|---|---|
| List, and each of search / status / application / created filters | 200, results narrow |
| No matches | Empty state, not an empty table (§22) |
| All eight tabs | 200, each rendering its own content |
| §16 Reset Password | Link sent to the contact; audit + activity both written |
| §17 Disable | `workspace-entry=false`; single-client user's `sign-in=false` |
| §17 Enable | Both true again |
| §18 Delete, wrong name typed | Refused server-side, status unchanged |
| §18 Delete, correct name | `pending_deletion`, purgeable in 30 days, **workspaces retained** |
| §19 Restore | Back to active |

## Two defects found while building this

- **The backfill's "Client since" was silently wrong.** `created_at` is not in `$fillable`, so
  passing it to `create()` was dropped without error and all 15 clients claimed to have signed up
  on the day the migration ran. Fixed with an explicit `forceFill` after insert — and called out
  in the migration, because it failed quietly.
- **Two guest-redirect rules were fighting.** An uncommitted `Authenticate::redirectUsing()` in
  `AppServiceProvider` (not mine) overrides `redirectGuestsTo()` in `bootstrap/app.php`, because
  provider boot runs later. My Back Office rule was in the losing one, so `/backoffice/clients`
  sent a signed-out visitor to the CUSTOMER sign-in with `?next=/backoffice/clients` — a screen
  that could not have helped, since a customer session grants nothing in the Back Office. The
  rule now lives only in the place that wins.

## State on this machine

15 clients backfilled 1:1 from the existing workspaces, codes `CL-000001`–`CL-000015`, each with
a `CLIENT_CREATED` activity dated to its workspace's creation. `CL-000001` also carries the
disable / enable / delete / restore trail from the tests above; its status is back to **active**
and its data was never touched.

---

# Regrouped by user (BC-D7)

The first build grouped WORKSPACES. That produced exactly the duplication reported: somebody in
three tenants appeared as three clients. A Client is now the global user.

## What changed

**Schema**
- `clients.user_id` — unique, `nullOnDelete`, the primary relationship.
- `clients.primary_contact_id` — **dropped**. The contact IS the client.
- `tenants.client_id` — **dropped**. A tenant has many clients (everyone in it), so a single
  foreign key on the tenant could never express it. Client → tenants runs through
  `workspace_memberships`, which is also where the ROLE in each tenant comes from.
- `WorkspaceMembership::STATUS_DISABLED` — a new value in an existing string column, so no
  migration. It is what "Disable Tenant Access" sets, and existing queries filtering on
  `STATUS_ACTIVE` already exclude it.

15 workspace-clients became **14 user-clients**. `zorainteractive@gmail.com` is one row with six
tenants under it instead of six rows.

**Two switches, deliberately not one** (§ "Client Actions")

| Action | Scope | Effect |
|---|---|---|
| Reset Password | Global | Reset link to the person's account |
| Disable / Delete Client | Global | Blocks sign-in AND every tenant |
| Disable Tenant Access | One tenant | Membership disabled; other tenants untouched |
| Change Tenant Role | One tenant | Role on that membership |
| Remove From Tenant | One tenant | Membership deleted |

The global actions are grouped under a "Global account actions" heading with a note pointing at
the Tenants tab; the tenant actions live on their own page under "Actions for this tenant only",
naming the tenant in every label. They never appear side by side — which is the requirement's
stated reason for separating them.

`ClientAccess::allowsTenant()` checks both gates: the client is not globally disabled, AND the
membership for that tenant is active.

**Tabs** are now Overview · Tenants · Applications · Usage · Users · Activity. Workspaces became
Tenants; Spaces became a column inside a tenant row; Subscription is gone from the top level.
Users means the people this person shares tenants with — a Users tab listing only themselves
would be a one-row table.

**Last Active** is read from `sessions.last_activity`, the only real activity record this
application keeps (`users` has no `last_login_at`). Sessions expire, so it is often "—" rather
than an invented date.

## Verified

| | Result |
|---|---|
| List | 14 rows for 14 clients; `zorainteractive@gmail.com` appears **once** |
| Columns | Client · Email · Tenants · Applications · Status · Last Active · Action |
| Expandable row | Present, CSS-only (`peer-checked`), no JavaScript |
| Tenants tab | All 6 memberships render with role, apps, spaces |
| Change role | owner → admin, audited |
| Disable tenant access | That membership disabled; **5 of 6 still active** |
| `allowsTenant` on that tenant / another | false / **true** |
| Sign-in during a tenant disable | **true** — the global account is fine |
| Global disable | Sign-in false AND every tenant false |

## A third silent mass-assignment failure

`120001` called `Client::create()` with `user_id` before that key was in `$fillable`. Mass
assignment dropped it without error and all 14 clients were written with a null user. Rolling
back to fix it failed halfway — `down()` had already re-added `tenants.client_id` — leaving the
schema half-reverted with the migration still recorded.

`120002` repairs the end state forward instead, checking before every step so it is safe from any
of those intermediate states, and uses `forceFill` throughout. **A migration must not depend on a
model's `$fillable`**: it is a moving target, and when it moves the data is lost silently. This is
the third time in this feature the same class of bug appeared (`created_at` twice, `user_id`
once).
