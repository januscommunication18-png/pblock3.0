# Feature: Help Center — Space & Inbox Setup

Source: *Help Desk — Space and Inbox Setup Requirements* (owner-supplied, 2026-08-18). Section
references below (§1–§22) are to that document.

This file is the build spec: it turns those requirements into decisions for THIS codebase and
records what each slice does and does not cover.

| Slice | Title | Status |
|---|---|---|
| 1 | Naming, config & the onboarding gate | **done** |
| 2 | Step 1 — Create Space | **done** |
| 3 | Step 2 — Inbox & email addresses | **done** |
| 4 | Step 3 — Inbound address & forwarding | **done** |
| 5 | Complete setup & Help Center navigation | **done** |

**Phase 2 — six-step Space onboarding** (source: *ProjectBlock Help Desk – Space Onboarding
Requirements*, v1.0, 2026-08-18). Section references `P2 §n` below are to THAT document; bare
`§n` still refers to the Phase 1 source. Phase 2 replaces the three-step wizard with six steps
and, more importantly, changes when anything is written — see HC-D11.

| Slice | Title | Status |
|---|---|---|
| 6 | Draft store & six-step shell | **in progress** |
| 7 | Step 1 — Space, Types & Department Groups | in progress |
| 8 | Step 2 — Invite Your Support Group | pending |
| 9 | Step 3 — Inbox (Phase 1's, moved) | pending |
| 10 | Step 4 — Configure Your Workflow | pending |
| 11 | Step 5 — Conversation Settings & Metadata | pending |
| 12 | Step 6 — Review & Confirm, and the commit | pending |

---

## Requirement

When a workspace switches the Help Center on, the rail entry appears (this already works). The
first person to open it finds **an onboarding wizard, not an empty application** (§1): it walks
them through creating a Space, creating that Space's first Inbox, and configuring inbound email,
then hands them the finished Help Center.

After that first run the wizard never returns. Further Spaces and Inboxes are created from the
navigation, without repeating workspace-level onboarding (§4, §20 rule 12).

### What this phase deliberately does NOT build

The requirements document describes the whole module; §22 asks for a foundation first. These are
named here so their absence is a decision rather than an omission:

- **Conversations.** No model, no ingestion, no counts. The six system views (§16) are built as
  navigation with empty states — they are the shape the conversation work will fill, and building
  the shell now is what makes §13's navigation deliverable at all.
- **Receiving inbound email.** The inbound address is generated, displayed and stored; the
  webhook that accepts mail at it is the next phase. Consequently the **Verify Forwarding** step
  (§11) ships as the status column and its four states, with `Not Configured` / `Waiting for
  Email` reachable and `Verified` reserved for the phase that can honestly set it. A
  **Send Test Email** button that cannot be answered would be a lie in the UI (HC-D7).
- **Inbox members, automation, disable and archive** (§14). Listed as future actions on the
  Inboxes screen; not part of the setup foundation.
- **Navigation counts** (§17). They count conversations.

---

## User Roles

§19 names three roles and calls them *recommended initial permissions*. It does not define a Help
Center membership table, and this phase does not invent one (HC-D4). The three map onto authority
this application already has:

| §19 role | Phase 1 mapping | May |
|---|---|---|
| Help Desk Admin | Workspace **Owner / Admin** | Everything: create, edit and archive Spaces; create Inboxes; manage email addresses; view and regenerate inbound configuration |
| Space Lead | The Space's own `lead_user_id` | Manage **their** Space and its Inboxes and email addresses |
| Agent | Any other active workspace member | View the Help Center and the Spaces they can reach; change no configuration |

Guests are excluded entirely — they are external to the workspace, and §19's least-privileged
role is still an internal one.

A workspace Owner/Admin switching the app on in Settings → General is a **workspace** permission,
not a Help Center one; that gate is `WorkspacePolicy::manageSettings` and is unchanged.

---

## Database Fields

Three tables, all tenant-scoped via `BelongsToTenant` (CLAUDE.md §7), matching §18's hierarchy:
Workspace → Space → Inbox → Email Address.

### `help_center_spaces`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | string, indexed | workspace scope |
| `name` | string(100) | §3. Unique per workspace — `unique(tenant_id, name)` |
| `description` | text, nullable | §3, max 500 chars enforced in the request |
| `types` | json | **many** free-text Space Types, e.g. `["Customer Support","VIP Support"]` (HC-D9) |
| `lead_user_id` | FK users, restricted | §3 Space Lead, **required** |
| `created_by` | FK users, nullable on delete | |
| `position` | integer, default 0 | the order Spaces appear in the nav |
| `archived_at` | timestamp, nullable | §19 "delete/archive Spaces" |
| timestamps | | |

### `help_center_inboxes`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | string, indexed | |
| `help_center_space_id` | FK, cascade | §5 an Inbox belongs to one Space |
| `name` | string(100) | §5. Unique per Space — `unique(help_center_space_id, name)` |
| `inbound_id` | string(32), **globally unique** | §8. The token only, never the whole address (HC-D5) |
| `setup_completed_at` | timestamp, nullable | null means the wizard has never been finished for it (HC-D3) |
| `created_by` | FK users, nullable on delete | |
| `position` | integer, default 0 | |
| `archived_at` | timestamp, nullable | |
| timestamps | | |

### `help_center_email_addresses`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | string, indexed | |
| `help_center_inbox_id` | FK, cascade | §20 rule 3 |
| `email` | string(255) | normalized lowercase + trimmed. `unique(tenant_id, email)` (HC-D6) |
| `name` | string(100), nullable | §6 the display name beside it |
| `status` | string(20), default `pending` | §11 — `pending` · `waiting` · `verified` · `error` |
| `verified_at` | timestamp, nullable | |
| `created_by` | FK users, nullable on delete | |
| timestamps | | |

Roles, space types, the inbound domain and the provider guides are **config, not tables** —
`config/help-center.php`. None of them is workspace-editable data in this phase, and a table
whose rows only ever come from a seeder is a migration pretending to be a feature.

---

## Business Rules

**Enablement and access**

- The rail entry appears only when the workspace has the app on, asked through `WorkspaceApps`
  so the rail, the create form and Settings → General cannot disagree.
- Every Help Center route 404s when the app is off — a workspace that never enabled it has no
  Help Center to reach, the same rule `routes/wiki.php` applies.
- Disabling hides navigation and **preserves all data**. It is a visibility switch, never a
  delete.

**Onboarding (§1, §21)**

- Opening the Help Center with **no Space** starts the wizard.
- The wizard resumes where it was abandoned, because its state is derived from the data rather
  than stored as a step number (HC-D3): no Space → step 1; a Space with no Inbox → step 2; an
  Inbox whose `setup_completed_at` is null → step 3.
- Onboarding is complete for the workspace once **any** Inbox has `setup_completed_at`. Creating
  the second Space therefore cannot restart it (§20 rule 12).
- **Cancel Setup** leaves the wizard without creating anything and returns to Projects.

**Spaces (§3, §4)**

- Name required, ≤100 characters, unique within the workspace, compared case-insensitively —
  "Billing" and "billing" are the same Space to everybody reading the nav.
- Description optional, ≤500 characters.
- At least one Space Type, each ≤40 characters, at most 8 per Space. Free text — there is no
  fixed vocabulary. Values are trimmed, inner whitespace collapsed, blanks dropped, and
  duplicates removed case-insensitively keeping the first spelling; the cap counts what will be
  **stored**, so nine entries that dedupe to five are accepted.
- Space Lead required, and must be an **active, non-guest workspace member**. The same list backs
  the picker and the validation, so a person the picker offers can never be rejected on submit.
- A workspace may hold many Spaces (§20 rule 1).

**Inboxes (§5, §8)**

- Name required, ≤100 characters, unique within its Space (§5).
- Every Inbox is given exactly one inbound identifier at creation (§20 rules 4 and 5). It is
  8 characters from a 32-symbol alphabet with the vowels and look-alikes removed, drawn from
  `random_bytes` — unguessable, globally unique, and never a sequential database id (§8).
- The identifier is stable. Regenerating one is an explicit action, not a side effect of editing
  the Inbox.

**Email addresses (§6, §7)**

- Format-validated, trimmed and lowercased before anything else looks at them.
- **Unique within the workspace**, which is what §7's "already connected to another Inbox"
  message describes; the message is returned as a validation error on the `email` field.
- An Inbox may hold many addresses (§20 rule 3); all of them route into it.
- Addresses start `pending` — "Setup Required" in the table (§6) — because nothing has proved the
  forwarding works yet.
- The customer-facing address is never required to change (§10); it is the *forwarding target*
  that is ours.

**Permissions (§19)**

- Creating and archiving Spaces: workspace Owner/Admin.
- Managing a Space, its Inboxes and their addresses: workspace Owner/Admin, or that Space's Lead.
- Everything is enforced server-side by policy. Hiding a control is presentation, not security.

---

## Acceptance Criteria

**Enablement & routing**

1. A workspace with the Help Center off gets 404 on every `/help-center*` route, and no rail entry.
2. A workspace with it on shows the rail entry, which leads to the Help Center.
3. Enabling grants no configuration rights: an ordinary member reaches the Help Center and cannot
   create a Space.

**Onboarding**

4. The first visit with no Space renders the welcome screen with the four-step progress indicator,
   not an empty Help Center.
5. A workspace whose Space exists but has no Inbox resumes at step 2, not step 1.
6. A workspace with a completed Inbox never sees the wizard again; it lands on the Help Center.
7. Cancel Setup creates nothing.

**Spaces**

8. A Space is created with name, type and lead; it appears in the navigation.
9. A second Space with the same name — in any casing — is rejected with a field error.
10. A name over 100 characters, no types at all, a type over 40 characters, more than 8 types, a
    missing lead, or a lead who is not an active non-guest member are each rejected.
10a. A type outside the suggestion list is **accepted** — the suggestions constrain nothing.
10b. `['  Billing  ', 'billing', 'Sales']` is stored as `['Billing', 'Sales']`.
11. Creating a second Space does not re-trigger onboarding.
12. Another workspace's Spaces are never visible or reachable (tenant isolation).

**Inboxes**

13. An Inbox is created inside its Space and is given an inbound identifier automatically.
14. Two Inboxes never share an inbound identifier.
15. The identifier is not a database id and does not appear in sequence.
16. A duplicate Inbox name within one Space is rejected; the same name in a different Space is fine.

**Email addresses**

17. `Support@Company.com  ` is stored as `support@company.com`.
18. Adding an address already on another Inbox in the workspace is rejected with
    "This email address is already connected to another Inbox."
19. The same address in a *different* workspace is accepted (tenant isolation).
20. A malformed address is rejected.
21. Multiple addresses can be added to one Inbox and all are listed with status "Setup Required".
22. An address can be removed.

**Completion & navigation**

23. Completing step 3 marks the Inbox set up and shows the summary: Space name, Inbox name,
    connected addresses, inbound address, Space Lead.
24. The finished Help Center shows Overview, Conversations, Inboxes and the Spaces section.
25. Each Space expands to the six system views; the expanded/collapsed state survives a reload.
26. A non-admin, non-lead member cannot create a Space or add an email address by API, not only
    by UI.

---

## UI Requirements

Blade + FlyonUI/Tailwind, with Vue mounted where the screen is interactive (CLAUDE.md §14), which
is how every other screen in this application is built. Two Vue roots:

- **`help-center-setup`** — the wizard. One component for all four screens, because they are one
  flow with one piece of state; splitting them would mean re-fetching what the previous step
  already knows.
- **`help-center-app`** — the finished Help Center's screens.

**Wizard**

| Screen | Contents |
|---|---|
| Welcome (§2) | Title, description, video placeholder, getting-started text, the four-step progress indicator, **Get Started** |
| Step 1 (§3) | Space Name, Description, Space Type (**multi-value tag input** — type a value, press Enter, it becomes a removable chip; Backspace on an empty box takes the last one back; suggestion chips beside it are shortcuts, not a list), Space Lead (searchable member picker showing avatar, name, email). **Continue** / **Cancel Setup** |
| Step 2 (§5, §6) | Inbox Name; the **Email Addresses** section with Email Address + Name + **Add**, and the added rows in a Name / Email / Status / Action table |
| Step 3 (§9, §10) | The inbound address with **Copy Address**; the forwarding diagram; expandable provider guides for Google Workspace, Microsoft 365, Zoho, Fastmail and Other; the verification status |
| Complete (§12) | "Your Help Center is Ready" with Space, Inbox, addresses, inbound address and Lead. **Go to Help Center** / **Invite Team Members** |

The progress indicator is on every step, so the four stages of §2 are visible throughout.

**Navigation (§13, §15, §16)** — a Help Center sidebar partial: Overview, Conversations, Inboxes,
then a **Spaces** heading with a `+` that opens Create Space, each Space a `<details>` disclosure
holding Unassigned · Mine · Drafts · Assigned · Closed · Spam. The labels are exactly those —
§16 calls out "Mine" not "Min" and "Drafts" not "Draft". Expanded state is remembered in
`localStorage`, matching the sidebar collapse that already works that way.

The `+` sits beside `<summary>` rather than inside it: an interactive element nested in a
disclosure control is the accessibility bug the Projects sidebar already avoids this way.

Custom CSS, if any is needed beyond FlyonUI utilities, is namespaced `_moretogether` (CLAUDE.md §14).

---

## Real-Time Requirements

**None in this phase.** Nothing here changes under another person's hands: a Space, an Inbox and
an email address are configuration, created by one administrator in one sitting. §17's dynamic
counts are the first real-time requirement in this module, and they count conversations, which
do not exist yet.

When they arrive the channel is `private-tenant.{tenantId}` — Help Center configuration is
workspace-wide, not per-user.

---

## Queue Requirements

**None in this phase.** No import, no export, no mail is sent by the setup flow. The inbound
ingestion of the next phase is the queue's business (CLAUDE.md §11), not this one's.

---

## Audit Requirements

Enabling and disabling the app is already logged by `WorkspaceApps` as
`workspace.app.enabled` / `workspace.app.disabled` with the workspace, app and actor.

Space and Inbox creation are ordinary product actions rather than security-sensitive
configuration changes, and are not separately logged in this phase. The activity feed the old
module had is not reintroduced here; when the module needs one it should be designed against the
conversation work that gives it something to say.

---

# Planning & Reasoning

## Decisions

| # | Topic | Decision |
|---|---|---|
| HC-D1 | Naming | The module is **Help Center** in code and UI: `App\…\HelpCenter\…`, `help_center_*` tables, `help-center.*` routes, `/help-center` URLs. The requirements document says "Help Desk" throughout; that is the previous product name and maps to Help Center everywhere. `config/workspace.php`'s label was updated to match, so the create form, Settings → General and the rail agree. |
| HC-D2 | The workspace flag keeps its old name | `workspace_settings.help_desk_enabled` and the `helpdesk` app key are **unchanged**. They are workspace facts, they already carry live data, and renaming them would be a migration over production rows to change a string nobody sees. `WorkspaceApps` is the one place that maps the key to the column, so the old name stops there. |
| HC-D3 | No `help_centers` table | Onboarding state is **derived**: no Space → step 1, Space without Inbox → step 2, Inbox without `setup_completed_at` → step 3. A table holding one nullable timestamp per workspace would be a row to keep in sync with facts the other tables already state, and it would let the two disagree. Completion is recorded on the Inbox, which is the thing being set up. |
| HC-D4 | No Help Center membership table | §19 gives *recommended* roles and no schema for them. Phase 1 maps them onto workspace role + `lead_user_id` (see User Roles). A membership module invented ahead of the conversations that need it would be exactly the overengineering CLAUDE.md §19 warns against — and it is a decision better made against §14's "Manage members" requirement when that is built. |
| HC-D5 | Store the token, not the address | `inbound_id` holds `a8f4k2m9`; the address is composed with `config('help-center.inbound_domain')`. Storing the whole address would bake today's domain into every row, so moving domains would be a data migration instead of an env change. |
| HC-D6 | Email uniqueness is per workspace | §7 says "prevent duplicate addresses within the Workspace" and "confirm the address has not already been assigned to another Inbox" — one rule, expressed as `unique(tenant_id, email)`. Two workspaces may both route `support@` addresses of their own; that is tenancy working. |
| HC-D7 | Verification ships as states, not as a button | The four states of §11 are modelled and displayed. **Send Test Email** is not built, because nothing yet receives the mail it would send: the control would spin forever and teach people the feature is broken. It arrives with the inbound endpoint. |
| HC-D8 | The six system views are navigation shells | §16's views are built and routed with empty states. They are how §13's navigation is even describable, and the conversation work fills them without moving anything. |
| HC-D9 | Space Type is free text, and there are many per Space | **Not a dropdown.** A Space is "Customer Support" and "VIP Support" at once, and the values are whatever a workspace calls its own support, so `types` is a JSON array of strings a user types. `config('help-center.space_type_suggestions')` is rendered by `pb-tags` as additive chips and **nothing validates against it** — a value removed from that list tomorrow does not invalidate a Space already tagged with it, which is the whole difference between a suggestion and an enum. Stored as JSON rather than a table plus a pivot because nothing joins to a type, orders by one, or lists types independently of their Space; the day it becomes a reporting dimension in its own right is a migration, not a redesign. |
| HC-D10 | Nothing is reused from `legacy/help-desk` | Owner's instruction, 2026-08-18. The archived module is a reference for what the product did, never a source of code — adapting it would reimport the architecture this redesign exists to replace. |

## Files Changed

**Config & naming**
- `config/help-center.php` (new) — space types, inbound domain, provider guides, system views
- `config/workspace.php` — `apps.helpdesk.label` → "Help Center", description reworded
- `.env.example` — `HELP_CENTER_INBOUND_DOMAIN`, replacing the inert `HELP_DESK_*` block

**Migrations**
- `2026_08_18_000001_create_help_center_spaces.php`
- `2026_08_18_000002_create_help_center_inboxes.php`
- `2026_08_18_000003_create_help_center_email_addresses.php`

**Models**
- `App\Models\HelpCenterSpace`, `HelpCenterInbox`, `HelpCenterEmailAddress`

**Services**
- `App\Services\HelpCenter\HelpCenterOnboarding` — the derived wizard state
- `App\Services\HelpCenter\InboundAddressGenerator` — the unguessable identifier
- `App\Services\HelpCenter\HelpCenterSpaceManager` — create a Space
- `App\Services\HelpCenter\HelpCenterInboxManager` — create an Inbox and its addresses
- `App\Services\HelpCenter\HelpCenterNavigation` — the sidebar's Spaces tree
- `App\Services\HelpCenter\EligibleLeads` — who may be a Space Lead, for picker and validator alike

**Policy**
- `App\Policies\HelpCenterSpacePolicy`, registered in `AppServiceProvider`

**Requests**
- `App\Http\Requests\HelpCenter\StoreSpaceRequest`, `StoreInboxRequest`, `StoreEmailAddressRequest`
- `App\Services\HelpCenter\EmailAddressGuard` — §7's uniqueness rule and its wording, in one place

**Controllers**
- `App\Http\Controllers\HelpCenter\HelpCenterController` — Overview, Conversations, the six views
- `App\Http\Controllers\HelpCenter\SetupController` — the wizard
- `App\Http\Controllers\HelpCenter\SpaceController`, `InboxController`, `EmailAddressController`

**Routes**
- `routes/help-center.php` — rewritten; the placeholder route is gone

**Views**
- `resources/views/help-center/setup.blade.php`, `overview.blade.php`, `conversations.blade.php`,
  `inboxes.blade.php`, `space.blade.php`
- `resources/views/partials/help-center-nav.blade.php`
- `resources/views/help-center/placeholder.blade.php` **removed**
- `resources/views/partials/app-sidebar.blade.php` — rail entry now points at `help-center.index`

**JS**
- `public/assets/js/help-center/setup.js` — the wizard (panel capped at 792px)
- `public/assets/js/help-center/inboxes.js` — the Inboxes screen
- `public/assets/js/help-center/space-create.js` — the "Spaces +" dialog
- `public/assets/css/tailwind.css` — rebuilt via `npm run build:css`

**Tests**
- `tests/Feature/HelpCenter/OnboardingTest.php`, `SpaceTest.php`, `InboxTest.php`,
  `EmailAddressTest.php`, `NavigationTest.php`, `PermissionsTest.php`
- `tests/Feature/Workspace/WorkspaceAppsTest.php` — label assertion updated

---

# Phase 2 — Six-Step Space Onboarding

Source: *ProjectBlock Help Desk – Space Onboarding Requirements* v1.0 (2026-08-18).
References `P2 §n` are to that document.

## Requirement

The three-step wizard becomes six (P2 §2):

1. Create Your Space
2. Invite Your Support Group
3. Set Up Your Inbox
4. Configure Your Workflow
5. Conversation Settings & Metadata
6. Review & Confirm

with **Back** and **Continue** throughout, the current step and total shown, data preserved in
both directions, per-step validation before Continue, and per-section **Edit** from the review
screen.

### The change that matters most

> "Do not create the final Inbox/Help Desk configuration until Step 6 is confirmed." — P2 §2

Phase 1 wrote as it went: the Space existed after step 1, the Inbox after step 2, and the wizard
derived which step was outstanding by looking at those rows (HC-D3). **That model is gone**
(HC-D11). Six steps of configuration — a workflow, a support group, a dozen behaviour settings —
cannot be written incrementally and still honour "review before it exists". Abandoning the wizard
at step 4 must leave a workspace with no Space at all, not with a half-configured one already
live in the navigation.

## What this phase deliberately does NOT build

- **The runtime behaviour of Step 5's automation.** Conversation reassignment (P2 §20–§23) and
  auto-follow-on-mention (P2 §24) both act *on conversations*, which still do not exist. The
  settings are captured, validated, stored and shown on Review; the code that reassigns a
  conversation when it "becomes active again" arrives with conversations (HC-D17), for the same
  reason as HC-D7.
- **Status migration on delete** (P2 §14, "if a status is already used by existing
  conversations"). Nothing uses a status yet, and inside the wizard statuses are not even
  persisted until Step 6 — a custom row is removed from a draft, not from a live workflow.
- **Multiple BCC addresses** (P2 §19) — one address, on a column shaped so a second is a
  migration rather than a redesign.

---

## Database Fields

`help_center_spaces` gains one column; four tables are new. All tenant-scoped.

### `help_center_spaces.department_groups`

| Column | Type | Notes |
|---|---|---|
| `department_groups` | json, default `[]` | P2 §6. Free-text multi-value, exactly like `types` (HC-D13). Optional — a Space may have none |

### `help_center_space_members` — the support group (P2 §7, §8)

| Column | Type | Notes |
|---|---|---|
| `id` · `tenant_id` | | |
| `help_center_space_id` | FK, cascade | |
| `user_id` | FK users, cascade, nullable | null while an invitation is outstanding |
| `workspace_invitation_id` | FK, nullable, null on delete | set when the person was invited rather than picked (P2 §8) |
| `email` | string | how an invited-but-not-yet-joined member is identified |
| `department_groups` | json, default `[]` | which of the Space's groups they belong to; a member may be in several (P2 §8) |
| `created_by` · timestamps | | |
| | | `unique(help_center_space_id, email)` — one row per person per Space |

### `help_center_statuses` — the workflow (P2 §11–§16)

| Column | Type | Notes |
|---|---|---|
| `id` · `tenant_id` | | |
| `help_center_space_id` | FK, cascade | |
| `name` | string(60) | unique per Space, case-insensitive (P2 §29) |
| `color` | string(7) | `#RRGGBB` |
| `responsibility` | string(10) | `creator` · `assignee` |
| `is_active` | boolean | Open always true, Closed always false (P2 §16) |
| `system_key` | string(10), nullable | `open` · `closed` for the two protected rows; null for custom (HC-D14) |
| `position` | integer | Open lowest, Closed highest; only custom rows move |
| `default_assignees` | json, default `[]` | user ids, multi-select (P2 §14) |
| timestamps | | |

### `help_center_space_settings` — Step 5 (P2 §17–§24)

One row per Space. Twelve fields of behaviour, kept off the Space itself (HC-D15).

| Column | Type | Notes |
|---|---|---|
| `id` · `tenant_id` | | |
| `help_center_space_id` | FK, cascade, **unique** | |
| `metadata` | json | the seven toggles of P2 §18, keyed by name |
| `auto_bcc_enabled` | boolean, default false | |
| `auto_bcc_email` | string, nullable | required and validated when enabled (P2 §19) |
| `reassign_enabled` | boolean, default false | |
| `reassign_after_minutes` | integer, nullable | **total** minutes; the UI splits it into Hours + Minutes (HC-D16) |
| `reassign_destination` | string(20), default `unassigned` | `unassigned` · `available_agent` (P2 §23) |
| `auto_follow_mentions` | boolean, default true | P2 §24 |
| timestamps | | |

### `help_center_setup_drafts` — the wizard's own state (HC-D11)

| Column | Type | Notes |
|---|---|---|
| `id` · `tenant_id` | | |
| `user_id` | FK users, cascade | one draft per person per workspace — two admins setting up at once are not editing each other's form |
| `step` | integer, default 1 | the furthest step reached, so returning resumes rather than restarts |
| `payload` | json | everything typed so far, one key per step |
| timestamps | | |
| | | `unique(tenant_id, user_id)` |

---

## Business Rules

**The draft (P2 §2)**

- Nothing outside `help_center_setup_drafts` is written before Step 6 is confirmed.
- Continue validates that step and merges it into the draft; Back merges too, so moving backward
  never loses what was typed.
- Exiting and returning resumes at the furthest step reached, with every field repopulated.
- **The inbound identifier is allocated once**, when the draft first reaches Step 3, and stored
  in the draft — P2 §10 requires that navigating back and forth does not regenerate it. It is
  checked for collision again at commit, because a token reserved in a draft yesterday could
  have been issued to somebody else's Space today.

**Step 1 (P2 §4–§6)** — as Phase 1, plus Department Groups: free text, multi-value, optional,
trimmed, no duplicates (case-insensitive). Groups defined here are the options Step 2 assigns
from (P2 §6).

**Step 2 (P2 §8)**

- A coworker is either an existing workspace member picked from search, or an email address.
- An email that is not already a workspace member goes through the **existing workspace
  invitation flow** at commit — this module does not invent a second way to invite somebody.
- Each coworker row carries the **workspace role** they are invited with (HC-D19), chosen from
  `config('workspace.invite_roles')`. It is ignored for somebody already in the workspace: they
  have a role, and Step 2 is not the place to change it.
- A role the actor may not hand out is refused. `WorkspacePolicy::assignRole()` already governs
  this — only an owner may create an owner, and everybody else may assign strictly below their
  own rank — and Step 2 is a new door into inviting people, not a way around that rule.
- A coworker may belong to several Department Groups, and to none.
- Only groups defined in Step 1 may be assigned; renaming a group in Step 1 after assigning it
  drops the stale name from the member rather than silently keeping a group that no longer exists.
- Removable before completion.
- The support group may be empty — a Space Lead setting up alone is a real first run.

**Step 4 (P2 §12–§16)**

- Every Space is created with exactly two protected statuses: **Open** first, **Closed** last.
- Open: name read-only, never deleted, never moved, always Active.
- Closed: name read-only, never deleted, never moved, always Inactive.
- Custom statuses sit strictly between them, are reorderable among themselves, and are the only
  ones that can be deleted.
- Status names are unique within the Space, compared case-insensitively (P2 §29).
- Colour, Responsibility and Default Assignees are configurable on all rows including the two
  system ones (P2 §13, §15).
- Enforced **server-side**: the order is normalized at commit so a reordered payload that tried
  to put Closed second is corrected rather than trusted.

**Step 5 (P2 §18–§24)**

- The seven metadata toggles are stored as a map, so adding an eighth is a config entry.
- **AI Tag renders as "Coming Soon" and cannot be switched on** — rejected server-side too, not
  merely disabled in the UI (P2 §18).
- Auto BCC off ⇒ the address is ignored; on ⇒ it is required and must be a valid, routable
  address, the same rule Inbox addresses use.
- Reassignment off ⇒ the threshold is ignored; on ⇒ total duration must be greater than zero,
  minutes 0–59 (P2 §21).
- Destination is `unassigned` (default) or `available_agent`.

**Step 6 (P2 §28)** — one transaction. Space, Department Groups, support group and its
invitations, Inbox, email addresses, inbound configuration, the workflow, default assignees, the
settings row. Then the draft is deleted and the user is taken to the new Inbox. A failure rolls
the whole thing back and **leaves the draft intact** (P2 §29, "do not silently discard
configuration after a failed submission").

---

## Acceptance Criteria (Phase 2)

Numbered from 27 to continue Phase 1's list.

27. All six steps can be completed, and the sixth creates everything.
28. Nothing exists in `help_center_spaces`, `_inboxes`, `_statuses` or `_space_settings` until
    Step 6 is confirmed — abandoning at step 5 leaves the workspace with no Space.
29. Back preserves what was typed; Continue after Back preserves it too.
30. Exiting the wizard and reopening it resumes at the furthest step, fully repopulated.
31. The inbound identifier shown at Step 3 is unchanged after going Back to Step 1 and forward
    again.
32. Space Type and Department Group each accept multiple removable values and reject duplicates.
33. Department Groups defined in Step 1 are the options offered in Step 2.
34. A coworker can be added by picking a member, and by an email that is not yet a member; the
    latter produces a workspace invitation at commit, with the role Step 2 asked for.
34a. An admin who asks to invite somebody as `admin` gets `member` instead — by API as well as
    UI. `owner` is never assignable here, because it is not an invite role at all.
34b. A refused invitation (no seats, already invited) leaves the member row pending with the
    requested role still recorded, not discarded (P2 §29).
35. Open and Closed are present, name-locked, delete-disabled and position-locked; Open is Active
    and cannot be deactivated, Closed is Inactive and cannot be activated — by API as well as UI.
36. A custom status can be added, coloured, given a responsibility and default assignees,
    toggled, reordered between Open and Closed, and deleted.
37. Duplicate status names are rejected whatever their casing.
38. A payload that puts Closed before a custom status is normalized to `Open → custom → Closed`.
39. `ai_tag` cannot be enabled, by API as well as UI.
40. Auto BCC on with a missing or malformed address is rejected; off with an address stores none.
41. Reassignment on with a zero duration is rejected; minutes above 59 are rejected.
42. Review shows every section, and each section's Edit returns to its step with data intact.
43. A failure during commit writes nothing and leaves the draft recoverable.
44. Another workspace's draft is never visible (tenant isolation).

---

## Decisions (Phase 2)

| # | Topic | Decision |
|---|---|---|
| HC-D11 | Draft until confirmed, and it lives on the server | P2 §2 forbids creating anything before Step 6, which retires HC-D3's derived state. The draft is a ROW (`help_center_setup_drafts`), not client state, because P2 §2 also asks to "preserve draft data if the user exits" — `localStorage` would lose it on another machine, and would put a workspace's configuration somewhere the server cannot validate. Keyed by `(tenant_id, user_id)`: two administrators setting up at the same time are filling in two different forms, and sharing one row would have them overwrite each other. |
| HC-D12 | The inbound identifier is reserved in the draft | P2 §10: "do not regenerate the Inbox ID unnecessarily when navigating back and forth". Generated once on first reaching Step 3 and held in the draft, so the address the user copies at 10:00 is the address that exists at 10:05. Re-checked for collision at commit, because a draft can outlive the moment its token was free. |
| HC-D13 | Department Groups reuse the Space Type pattern | Same control (`pb-tags`), same normalization (`HelpCenterSpace::normalizeTypes()`), same JSON storage, for the same reasons as HC-D9. They are labels a workspace invents; a `department_groups` table would be rows nobody joins to. |
| HC-D14 | Open and Closed are ROWS, marked by `system_key` | Not a flag on the Space and not implied by position. They are real statuses a conversation will point at, so they have to exist as rows; `system_key` is what makes them protected, and it survives reordering, renaming attempts and the addition of custom statuses between them. Position is normalized at commit rather than trusted from the client. |
| HC-D15 | Step 5 gets its own table | Twelve behaviour fields on `help_center_spaces` would make the Space row mostly settings. A one-to-one `help_center_space_settings` keeps "what a Space IS" separate from "how it behaves", and is where the next dozen settings go. |
| HC-D16 | The threshold is stored as total minutes | P2 §21's UI is Hours + Minutes; the DATA is a duration. One integer cannot hold "2 hours 90 minutes", needs no cross-field validation of its own, and compares directly against an elapsed time. The split is presentation, done in the component. |
| HC-D17 | Step 5 captures settings; the behaviour comes with conversations | Reassignment triggers "when the conversation becomes active again" (P2 §22) and auto-follow fires on an @mention in a note (P2 §24). Both need conversations. Storing the settings now is right — they are what the user configured — but no scheduler or listener is built against them, and Review says so rather than implying the automation is live. |
| HC-D19 | Step 2 asks for a workspace role per coworker | P2 §8 lists no role, and defaulting everyone to `member` was the placeholder. The role asked for is the WORKSPACE role, from `config('workspace.invite_roles')` — not a Help Center role, because this module still has no membership vocabulary of its own (HC-D4) and inventing one here would define it in a wizard rather than against the permission work that needs it. Enforced through `WorkspacePolicy::assignRole()` so this new door cannot exceed what Settings → Members allows; anything refused falls back to `member`, because silently downgrading is the safe direction. Recorded on `help_center_space_members.invited_role` as what was ASKED FOR — the `workspace_invitations` row is the authoritative grant, and the two differ exactly when an invitation is refused, which P2 §29 says must not be discarded silently. |
| HC-D18 | AI Tag is refused server-side, not just disabled | P2 §18 makes it non-interactive. A disabled control is presentation; the API rejects `ai_tag => true` as well, because "coming soon" is a statement about the feature, not about the button. |

---

# Phase 6 — Postmark Inbound

Receiving customer email. Closes out HC-D7 and HC-D8's deferrals: `Verified` is now reachable,
and inbound mail has somewhere to land.

## What it does

```
Customer → support@company.com
        → (their mail provider forwards)
        → inbox-<token>@inbound.<domain>
        → Postmark Inbound
        → POST /api/webhooks/postmark/inbound/{token?}
        → IngestInboundEmail (queued)
        → Space / Inbox / Conversation
```

## Decisions

| # | Topic | Decision |
|---|---|---|
| HC-D20 | Inbound needs conversations, so this phase brings them | A webhook that parses a message and has nowhere to put it does nothing. `help_center_conversations` and `help_center_messages` are the SMALLEST correct model — a thread and the messages in it. Assignment, followers, notes, ratings, snoozing and attachments are all real requirements and none is here; every column exists because ingestion writes or reads it. |
| HC-D21 | The webhook authenticates on a shared secret, and unset means CLOSED | Postmark does not sign inbound webhooks, so a secret is all there is. Accepted as the last path segment **or** the basic-auth password, since Postmark's URL field takes either shape; compared with `hash_equals` so it cannot be probed a character at a time. With no secret configured the endpoint refuses everything — "not configured" must never mean "open", or this becomes a way to write rows into any workspace. Rejections answer **404, not 401**: a 401 tells a stranger the URL is right. |
| HC-D22 | The request only enqueues | Postmark expects a fast 2xx and retries anything else. Parsing, routing, opening a conversation and updating address statuses all run in `IngestInboundEmail` behind the queue (CLAUDE.md §11), so the webhook's own work is authenticate → dispatch → 200. **A queue worker must be running or nothing is processed.** |
| HC-D23 | Idempotency keys off the RFC Message-ID | Postmark retries any webhook that did not answer 2xx, so the same message arrives repeatedly. `unique(tenant_id, message_id)` plus a pre-check makes a retry a no-op — without it a retry posts the customer's email into the thread a second time. |
| HC-D24 | Threading matches reply headers, never the subject | `In-Reply-To`/`References` against `thread_key`, then against any message already stored. Subject matching would merge "Re: Invoice" from two different customers into one conversation. Verified: an identical subject from a different sender opens its own conversation. |
| HC-D25 | Routing prefers `OriginalRecipient` | It is the envelope recipient, and the only one that survives forwarding — a customer writes to `support@company.com`, that mailbox forwards, and the To header still says `support@company.com`. To and Cc are checked after it. A Bcc'd inbound address appears in neither header and would otherwise be unroutable. |
| HC-D26 | The token alone is not enough — the domain must match | `inbox-<token>@somebody-else.com` in a Cc must not route. The token is unguessable but not secret: it is printed in forwarding instructions and pasted into mail clients. |
| HC-D27 | Receiving forwarded mail IS the verification (§11) | The address that delivered flips to `Verified` on arrival, so no "Send Test Email" button is needed to establish it. Only addresses actually on the message are touched — an Inbox with three connected addresses verifies the one that delivered, not all three. |
| HC-D28 | A reply reopens a closed Request | A customer answering a closed thread is continuing the old problem, not raising a new one; leaving it closed would hide their reply from every view except Closed. |

## Not built

- **Attachments.** Postmark sends them base64 in the payload; storing them needs a disk decision
  and a size policy, and the message body is useful without them.
- **Outbound replies.** `direction` exists and only `inbound` is ever written.
- **Spam handling beyond the flag.** `X-Spam-Status` is read into `is_spam`; nothing acts on it.
- **The conversation UI.** Superseded by P9 below, which builds the Inbox as the Request queue.

---

# Phase 9 — Requests, Ticket Numbers and a Workflow-Driven Inbox

## Requirement

The Inbox is the Help Desk's only operational view. Every inbound email creates a **Request**
with a unique human-readable **Ticket Number**, and a Request's status comes entirely from the
**Workflow assigned to its Space** — ProjectBlock keeps no fixed Help Desk status list.

## Terminology (the point of the phase)

One object, one word:

```
Inbound Email → creates a → Request → assigned a → Ticket Number → managed through → Inbox
```

"Conversation", "case", "email" and "ticket" were four words for one row. The model, its table,
its FK on messages, the ingestor, the job, the controller, the screen and the navigation all now
say **Request**; the Ticket Number is its human-readable identifier, and nothing else is.

## Database fields

`help_center_conversations` → **`help_center_requests`**, gaining:

| Field | Why |
|---|---|
| `ticket_number` | The counted integer. `#000011` is presentation; storing the padded string would make "the next one" a parsing problem. Unique per **tenant**, so a workspace starts at 1 and no workspace's volume is visible to another. |
| `preview` | The first ~200 characters of the inbound message, for the Inbox's second line. |
| `priority` | `urgent / high / normal / low`, from config. |
| `waiting_since` | When the **current** wait started — reset when responsibility changes hands. |
| `last_activity_at` | The latest relevant activity: a reply, a status change, an assignment. |

`help_center_messages.help_center_conversation_id` → `help_center_request_id`.

`help_center_statuses` gains:

| Field | Why |
|---|---|
| `is_default` | The workflow's **starting status**. A flag, not "the row at position 0": position is a drag-and-drop artefact, and reordering a workflow is not a decision about where new email lands. |
| `waiting_on` | `agent / customer / neither` — whose clock runs in this status. Distinct from `responsibility`, which decides who gets **assigned**. |

## Business rules

- Inbound email opens its Request in **the Space workflow's own default status**, resolved as
  `is_default` → the Open system row → the first status in workflow order. The fallback chain
  exists because a Space with no default must still be able to receive mail: losing a customer's
  email to a configuration gap is far worse than opening it in the wrong column.
- A Request may only be moved to a status **belonging to its own Space's workflow**, checked
  server-side — the client's status list came from a page that may predate a workflow edit.
- Only a **member of that Space** may be assigned. Assigning somebody work they cannot open is a
  silent way to lose a customer's email.
- **The waiting timer is not the age of the ticket.** It restarts only when responsibility
  changes hands: `Agent → Agent` (say, Open → Escalated) is the same person still owing the same
  reply, and zeroing it there would hide a Request that has been ignored all afternoon.
- A **customer reply** hands the wait back to the agent and reopens a closed Request.
- **Spam stops the clock**, so it cannot rise to the top of an Inbox sorted by longest wait.
- Reaching a status whose `waiting_on` is `neither` **stops** the clock rather than resetting it,
  and sets `closed_at` when that status is the workflow's Closed.

## UI

The Inbox is the **work item grid** — same Tabulator table, same `.wi-grid` skin, same group
headers — at 52px rows rather than 44px, because a Request row carries the subject and its
preview on two lines. Columns: Ticket # · Customer (avatar, name, address) · Subject / Preview ·
Waiting · Last activity · Assignee · •••, grouped by workflow status.

Filters are **generated from the Space's workflow**, then the standing ownership views
(Unassigned / Mine / Drafts / Assigned / Closed / Spam). No status name appears in the Blade
template or the screen script, which is what lets one Space run `New / Investigating / Completed`
and another `Open / Tier 1 / Tier 2 / Resolved` with no code between them.

The ••• menu offers Change Status, Assign/Reassign, Change Priority, Copy Request link and Mark
as Spam, through **one** endpoint — those are all "this Request changed", and a route per verb
would be five places that each have to remember what a write does to the waiting clock.

## Decisions

| # | Topic | Decision |
|---|---|---|
| HC-D29 | One queue | The module-wide **Conversations** screen is deleted — nav item, route, controller action and view. A Space's Inbox is the only queue; a second name for the same work, listing nothing, was the thing the requirement asked to remove. |
| HC-D30 | Ticket Number ≠ id | The number is a promise made to a customer in a subject line; the id is an internal handle that may become a UUID. `MAX + 1` per tenant under a row lock, with a unique index so a race fails an insert rather than printing one number on two Requests. |
| HC-D31 | `waiting_on` read through the status, never copied onto the Request | The answer is a property of the workflow step. Copying it would let the two disagree the moment a team edited "Waiting on Customer" to stop the agent clock. |
| HC-D32 | Priority is config, status is per-Space | Priority means the same thing everywhere ("how urgent"); a status is a step in one team's process. Making both configurable would let a Space define "Urgent" as something another Space's reports could not add up. |
| HC-D33 | Inbox rows are sent whole and filtered client-side | Round trips to re-filter a list the page already holds are chances to show a count that disagrees with the rows under it. Revisit when a Space carries thousands. |

## Not built

- **The Request detail screen.** A row click says so rather than doing nothing.
- **A workflow status editor.** Statuses are created by onboarding Step 4; `waiting_on` and
  `is_default` are **displayed** on the Workflow panel but cannot yet be changed there, so a
  custom status arrives as `waiting_on: agent`, not default.
- **Move to another Inbox / Space, and Merge Request.** Both need the detail screen and a
  decision about what happens to the ticket number.
- Attachments, outbound replies, and spam handling beyond the flag (carried over from P6).

---

# Phase 10 (fix) — Add Member: the invitation has to arrive, and land in the Space

## Requirement

Adding somebody to a Space by email address must work for all three kinds of address an admin
can type, and the link that reaches them must put them **inside the Space they were invited to**
rather than at the front door of the application.

| The address belongs to | What happens |
|---|---|
| An active workspace member | Added to the Space immediately. Emailed a **View Space** heads-up. No account to create, no invitation to accept. |
| A ProjectBlock user who is not in this workspace | A workspace invitation carrying this Space's context. **Accept Invitation** signs them in, joins the workspace, completes the Space membership, lands them in the Space. |
| Nobody yet | The same invitation. Accepting creates the account first, then the rest of the line above. |

No duplicate workspace members, and no second pending invitation for one address.

## What was actually broken

Three separate faults, only the first of which was visible as "the email flow doesn't work":

1. **Any emailed link 500'd for a signed-out reader.** Laravel's `auth` middleware redirects
   guests to `route('login')`; this application's sign-in route is named `signin`, so every
   authenticated URL opened without a session raised *Route [login] not defined*. An emailed
   link is precisely the case that hits this — it is opened later, from a mail client, often
   with no session.
2. **A Space URL is only resolvable from inside its own workspace.** Every Help Center route
   runs behind `workspace.tenancy`, which resolves the Space out of whichever workspace the
   reader has *active*. Right for navigation, wrong for a link: a reader who belongs to three
   workspaces got a 404 from the button telling them they had been added to a Space.
3. **The invitation silently did not send.** `WorkspaceInviter::invite()` reports per-recipient
   statuses; `no_seats`, `suspended_member` and `already_invited` all mean *no email left the
   building*. The controller ignored the return value, created the pending member row anyway,
   and answered "Invitation sent to …". The grid then showed **Invited** forever for somebody
   who was never written to.

## Decisions

| # | Topic | Decision |
|---|---|---|
| HC-D34 | One entry URL for emails: `/help-center/go/spaces/{space}` | The only Help Center route **outside** `workspace.tenancy`. It looks the Space up without tenancy, checks membership of the workspace that owns it, makes that workspace current, then hands over to the ordinary tenant-scoped Overview. Non-membership is a **404**, not a 403 — an error must not confirm that a workspace exists. Both emails link here, and so does the post-acceptance screen. |
| HC-D35 | Guests go to `signin?next=…`, set once in `AppServiceProvider` | The mirror of the existing `RedirectIfAuthenticated::redirectUsing`. Fixed centrally rather than per-route: the fault was that *every* authenticated URL 500'd for a guest, not that one link did. `?next=` is the parameter `SignInController` already reads, and `SessionReturnTarget::sanitize` already refuses anything that is not a safe same-origin path. GET only — returning somebody to a POST-only URL lands them on a 405. |
| HC-D36 | Space context travels as **copy**, not as a second kind of invitation | An invitation sent from Add Member is still an ordinary workspace invitation: same seat, same token, same acceptance, same expiry. Only the wording differs, passed as four optional strings to `WorkspaceInvitationMail`. A Help-Center-specific invitation would have been a second set of seat checks and expiry rules to keep in step. |
| HC-D37 | An address that was already invited is **resent**, not refused | The admin asked for that person to be on this Space, and the email already in their mailbox says nothing about a Space. `WorkspaceInviter::resend()` mints a fresh token — the stored value is a SHA-256 hash, so reissuing is the only thing that *can* happen, and it retires the older link as a side effect. |
| HC-D38 | Every other non-sending status **removes the pending row and 422s** | A row saying "Invited" for an address nothing was sent to is a lie the grid keeps telling. The admin gets the reason instead: no seats, suspended, guest. |
| HC-D39 | The Space id is left in the session by the acceptance **listener** | `WorkspaceInvitationAccepter` knows nothing about the Help Center — that is why the linking is a listener — so the destination cannot be decided there. The listener leaves the id under `LinkHelpCenterSpaceMemberships::SESSION_SPACE_KEY`; the "Welcome to {Workspace}" screen pulls it and continues into the Space. If nothing does, the flow lands on the workspace exactly as before. |
| HC-D40 | A guest cannot be added to a Space | A guest has narrow access to specific work; working a customer Inbox is not that. Raising their workspace role is an explicit decision on Settings → Members, not a side effect of Add Member. |

## Acceptance criteria

- A signed-out visitor opening any Help Center URL reaches **sign-in**, not a 500, and lands on
  the requested page after signing in.
- Adding an address with no workspace membership sends an invitation whose subject and body name
  the **Help Center Space**, and whose CTA is **Accept Invitation**.
- Adding an address that already had a pending invitation sends a **new** email on a **new**
  token, and the previous link stops working.
- Adding an address when the workspace has no seats left creates **no** member row and returns
  the reason to the admin.
- Accepting the invitation — as a brand-new account or an existing one — joins the workspace,
  turns the pending Space membership into a real one, and continues to that Space's Overview.
- Adding an active coworker creates **no** invitation, marks them active immediately, and emails
  them a **View Space** heads-up that works from any active workspace and from a signed-out
  browser.

## Files changed

- `app/Providers/AppServiceProvider.php` — `Authenticate::redirectUsing` (HC-D35).
- `app/Http/Controllers/HelpCenter/SpaceEntryController.php` — new; the `/go/` entry URL (HC-D34).
- `routes/help-center.php` — the one route outside the tenancy group.
- `app/Http/Controllers/HelpCenter/SpaceMemberController.php` — outcome handling, resend, Space
  context, mail failure no longer fails the request (HC-D36–D38, D40).
- `app/Services/WorkspaceInviter.php` — optional `$context`; new `resend()`.
- `app/Mail/WorkspaceInvitationMail.php` + `resources/views/emails/workspace-invitation.blade.php`
  — context-aware subject, headline and detail row; unchanged when no context is passed.
- `app/Notifications/AddedToHelpCenterSpace.php` — **View Space**, via the `/go/` URL.
- `app/Listeners/LinkHelpCenterSpaceMemberships.php` — leaves the joined Space id (HC-D39).
- `app/Http/Controllers/Invitation/PendingInvitationController.php` +
  `resources/views/invitations/joined.blade.php` — continue into the Space when there is one.
- `public/assets/js/help-center/space-members.js` — surface the server's reason on a 422.

## Not built

- **A resend control on the Members grid.** `resend()` exists and is reached only by re-adding
  the address. A button belongs with the revoke/resend pair on Settings → Members.
- **Deep-linking to a section other than Overview.** `/go/` always lands on the Space Overview;
  the emails have no reason yet to point anywhere else.

---

# Phase 11 — Space Settings, on the Project Settings pattern

## Requirement

Space Settings becomes a **dedicated screen with its own left-hand navigation**, matching
`/projects/{id}/settings/general` rather than being a settings design of its own. Eleven
sections, each with its own URL, each editable.

## What it replaced

One panel inside `space.blade.php`, rendered when `$panel === 'settings'`. It listed every
setting a Space had as `On` / `Off` / `Coming Soon`, and it had three problems that were all the
same problem — it was a *view* of settings rather than a place to *set* them:

- **Nothing on it could be changed.** The values were written once by the setup wizard's step 5
  and thereafter only displayed. There was no update endpoint at all.
- **No section could be linked to or returned to.** One URL held everything, so a refresh, a
  bookmark or a link to "the Auto BCC setting" all landed at the top of one long page.
- **It was not the settings pattern the rest of the app uses.** Project Settings had a sidebar,
  routed sections and active highlighting; a Space had a list.

## Layout

Three columns, and the first is why this is not simply a copy of Project Settings:

```
[ app rail | Help Center nav ] [ Space Settings nav ] [ the selected page ]
```

Project Settings is a **full-screen detour** out of the project — its own HTML document, a
minimal topbar, and a back arrow, a Close button and Escape to get out. A Space's Settings is a
**section of the Space**, sitting beside Overview and Inbox, so it keeps the
Help Center navigation, the Space toolbar and the Space's section tabs above it. Leaving the
Space in order to configure it — and then needing a way back — would be the wrong shape.

What *is* taken from Project Settings, deliberately and class for class: the `w-60` bordered
sidebar, its "NAME / SPACE SETTINGS" heading, the `h-8` rounded nav rows, the
`bg-sel text-brand font-medium` active treatment, the `Soon` badge on an unclickable item, the
`max-w-[820px] mx-auto px-5 sm:px-8` content column, `pb-section-head`, `pb-toggle`, and the
`#…-root` + `data-bootstrap` + "Loading…" mount contract.

## The sections

| URL segment | Label | What it writes |
|---|---|---|
| `inbox` | Inbox | *(nothing — reads the Inbox, its inbound address and connected addresses)* |
| `members` | Members | *(nothing through this endpoint — the member routes, see P19)* |
| `channel` `snooze` `rating` `tag` | Channel, Snooze, Rating, Tag | one key of `metadata` |
| `ai-tag` | AI Tag **Coming Soon** | nothing — refused |
| `company` | Company | `metadata.company` |
| `customer-email-address` | Customer Email Address | `metadata.customer_email` |
| `auto-bcc` | Auto BCC | `auto_bcc_enabled`, `auto_bcc_email` |
| `reassignment` | Reassignment | `reassign_enabled`, `reassign_after_minutes`, `reassign_destination` |
| `auto-flow-on-mention` | Auto Follow on Mention | `auto_follow_mentions` |

## Decisions

| # | Topic | Decision |
|---|---|---|
| HC-D41 | The nav is `config/help-center.php` → `space_settings_nav` | One list, three consumers: the sidebar renders from it, the router enumerates it (an unknown segment 404s rather than rendering an empty shell), and the controller decides what to load and what to accept from it. The same arrangement as `projects.settings_nav`, so the two cannot drift. |
| HC-D42 | Six sections share one `kind` | Channel, Snooze, Rating, Tag, Company and Customer Email Address are each **one switch over one key of the same JSON column**. They are one panel parameterised by `metadata`, not six near-identical screens — six copies of a save-and-toast would drift the first time one was fixed. |
| HC-D43 | ONE `PATCH`, and the section decides which fields it may write | `UpdateSpaceSettingRequest` reads the section from the route and validates only that section's keys, so the Channel page — a single switch — cannot post a reassignment threshold. Eleven endpoints would be eleven places that each have to agree about who may write to a Space. |
| HC-D44 | A metadata write is **read-modify-write** | `metadata` is one JSON column holding all seven toggles. A panel that owns one of them must not send the whole map: it does not know what the other six are, and sending its own idea of them is how a stale tab reverts somebody else's change. |
| HC-D45 | The settings row is created on demand | A Space built before the wizard's settings step has no row — the old screen said so and stopped. That Space is not "a Space without settings", it is a Space running on the **defaults**, so the defaults are written down the first time one of them is changed. Reads fall back to the default too, rather than showing every switch off. |
| HC-D46 | Single switches save instantly; anything with a field has a Save button | There is nothing to review about one toggle, and a Save button under it is a second click for no decision. A half-typed email address, on the other hand, must not be posted on every keystroke. |
| HC-D47 | `settings` is removed from the `{section}` route's list | Not merely shadowed by registration order. The panel it rendered is deleted, so a future reordering of `routes/help-center.php` would serve a blank page instead of a clean 404. |
| HC-D48 | Coming Soon is refused in **three** places | The nav does not link AI Tag, the request rejects `enabled: true` for any toggle config marks unavailable, and the controller checks again before writing. Reaching the second or third means a hand-rolled request — refused anyway, because "coming soon" is a fact about the feature and not about the button (HC-D18). |
| HC-D49 | The URL says `auto-flow-on-mention`; the label says "Auto Follow on Mention" | The segment is what the requirement specified and a URL is a promise. The label describes what the setting does and matches `auto_follow_mentions` and the wizard's own copy. |
| HC-D50 | A `md:hidden` chip row carries the nav on small screens | Project Settings hides its sidebar under `md` with nothing in its place. Copying that would strand a phone inside whichever section it opened with no way to reach the others, so the same list is also rendered as a horizontally scrolling row. |

## Acceptance criteria

- Clicking **Settings** from a Space opens the dedicated screen; `/settings` redirects to
  `/settings/inbox`.
- The sidebar, active treatment, section header and content width match Project Settings.
- Changing section changes the URL; refreshing keeps the section; the active item is highlighted
  in both the sidebar and the mobile row.
- **AI Tag** shows a `Soon` badge, is not a link, and cannot be enabled by any route.
- An unknown segment 404s.
- Every other section saves and survives a refresh; validation errors land on the field.
- Project Settings is untouched.

## Files changed

- `config/help-center.php` — `space_settings_nav` (HC-D41).
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — new; `index`/`show`/`update`.
- `app/Http/Requests/HelpCenter/UpdateSpaceSettingRequest.php` — new; rules per section (HC-D43).
- `routes/help-center.php` — three routes, **before** `{section}`, which is load-bearing (HC-D47).
- `resources/views/help-center/space-settings.blade.php` — new; the three-column screen.
- `public/assets/js/help-center/space-settings.js` — new; one app, four panel kinds (HC-D42).
- `app/Services/HelpCenter/HelpCenterNavigation.php` — the Settings tab points at the group and
  stays lit from inside it.
- `resources/views/help-center/space.blade.php` — the old read-only Settings panel deleted.

### The Inbox panel manages addresses (2026-08-20)

The Inbox page under Space Settings was read-only: it listed the inbound address and the
customer-facing addresses, and pointed at `/help-center/inboxes` for anything else. That meant
leaving the Space — and the screen you were sent to lists every Inbox in the workspace, so you
then had to find the one you had just been looking at.

It now does what the Inboxes screen does, for this Space's Inboxes: copy the inbound address,
add a customer-facing address, remove one.

The earlier objection — that a second way in would be a second copy of the verification and the
"already connected to another Inbox" check — does not apply, because there is no second copy.
Both screens `POST /help-center/inboxes/{inbox}/addresses` and
`DELETE /help-center/inboxes/{inbox}/addresses/{address}`, so `EmailAddressController` and
`HelpCenterInboxManager` remain the only place those rules live. The panel boots with the same
`HelpCenterInbox::toPayload()` shape the Inboxes screen boots with, for the same reason: two
shapes for one row is how the two screens would start disagreeing about what a status is called.

`manageable` on each Inbox is the panel's `canManage` — managing an address is managing the
Space, which is the permission the whole Settings screen is already gated on. The endpoints
re-check it; hiding the form is presentation.

The Inboxes screen is unchanged and still reachable. It is the cross-Space view; this is the
one-Space view.

### Delete is confirmed, and unverified addresses can be tested (2026-08-20)

**Delete.** The row's `Remove` text link is a trash icon (`_moretogether-iconbtn--danger`), and it
opens `<pb-confirm>` rather than deleting on the click. The confirmation is shown for **every**
address, verified or not — the status changes what the sentence says, not whether there is one.
A verified row is told that mail forwarded from it stops reaching the Space; an unverified row is
told its setup has to be done again. Making the safer-looking row the easier one to lose is the
mistake the single-experience rule exists to prevent.

**Test email.** An address that is not `verified` gets a second action: send a test email.

There is no new verification handshake, because there is nothing to build — §11 already says an
address becomes Verified the moment a message forwarded from it reaches the ingestor, and a
passing inbound test *is* that message. So the button sends the SAME probe the Space Overview's
card sends, aimed at one address:

- `InboundTestRunner::start()` takes an optional `HelpCenterEmailAddress`. Null (the Overview's
  card) still means the Inbox's first address. An Inbox with three connected addresses can have
  two working and one not, and "the Inbox is fine" is no answer to "is THIS address forwarding?".
- `POST|GET /help-center/inboxes/{inbox}/addresses/{address}/test` — start, and poll. Nested
  under the address because the address is what is being probed; the address is checked to belong
  to the Inbox in the URL, exactly as `destroy` checks it.
- The GET returns the **address** alongside the test. A pass verifies the address server-side, and
  returning only the test would leave the badge reading "Setup Required" beside a result saying it
  works, fixable only by reloading.
- `HelpCenterInboundTest::resolveTimeout()` — the "a test that ran out of time is written down on
  read" rule moved from `InboundTestController::show` onto the model, because two screens now
  need it and "when does a test give up?" must not be a question two files answer separately.

The button is hidden once an address is verified: a passing test would prove what the badge
already says. Polling is 5s, matching the Overview card, for the same reason — the answer comes
back through the customer's own mail provider in tens of seconds.

Not built: the same treatment on the cross-Space Inboxes screen, which still has a text `Remove`
and no test action.

---

## P12 — Settings → Channel (2026-08-20)

### Requirement

Which ways customers can reach a Space. Email Support is the channel that exists and is enabled
for every Space; Chat Support and Omnichannel Support are named, badged Coming Soon, and do
nothing. Only the UI and Email's enabled state are in scope — no chat, no omnichannel.

### What it replaced

`channel` was one of the six metadata switches — "Show which channel a conversation arrived
through", a conversation-display preference that happened to share a word with this page's
subject. Its nav entry now points at `kind => 'channels'`.

`metadata.channel` is still in config and `defaultMetadata()` still writes it, so no Space's
stored map loses a value it already holds. But it has **no page**, which is not a state to leave
it in: it needs rehoming with the other display toggles, or removing outright. Flagged rather
than decided, because deleting a stored setting is not something this change was asked to do.

### Read-only, and why there is nothing to save

Email Support is enabled because the Space **has** an Inbox and a generated inbound address —
a fact about the Space, established by setup and configured on the Inbox page, not a preference
somebody set here. So there is no `enabled` column, no PATCH, and the panel sends nothing;
`UpdateSpaceSettingRequest` gives `channels` no rules and the controller's `match` 404s it.

Refreshing preserves the state for the same reason it preserves the inbound address: it is read
from the Space, not from a flag this page wrote.

A switch that saved a boolean nothing reads would be worse than no switch. There is no code path
that honours "email off" — inbound mail would still arrive and still be ingested — so the page
states the position instead of offering a control that lies about what it does. When a second
real channel exists, `config('help-center.channels')` becomes a stored per-Space map and this
becomes a savable panel.

### UI

All three rows are the same shape as every other Settings panel: `border border-line rounded-xl`
with `divide-y`, label, one sentence, `<pb-toggle>` on the right, all three `:disabled="true"`.
The two unbuilt channels carry the same Coming Soon badge the settings nav uses. The active row
carries an `Enabled` badge as well, because a dimmed ON toggle beside two dimmed OFF ones would
leave "is email actually available?" to be read off an opacity.

### Files

- `config/help-center.php` — the `channels` list; the `channel` nav entry's new `kind`.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — the `channels` bootstrap.
- `public/assets/js/help-center/space-settings.js` — the `channels` panel.

---

## P13 — Auto BCC holds many addresses (2026-08-20)

### Requirement

Auto BCC blind-copied ONE address. A Space that wants both a compliance archive and a shared
mailbox had to choose. It now holds a list: add, remove — removal confirmed — up to
`help-center.auto_bcc_max` (10).

### Storage

`auto_bcc_email` (string) is **replaced** by `auto_bcc_emails` (json), not kept beside it. Two
columns describing the same setting is two answers to "where does Auto BCC send?", and the first
reader to pick the wrong one ships a bug that only appears in somebody's mail. The migration
carries every configured address across before dropping the old column; `down()` restores the
first one, because a string cannot hold a list.

JSON rather than a table: the list belongs to one settings row, is read and written whole, is
capped, and nothing joins to it. A table would be a migration, a model and a relationship to
store what is a field.

The cap exists because every entry is a copy of every message the Space sends — the list is a
mail multiplier, and "someone pasted forty addresses" should be refused by the setting rather
than discovered in a bill.

### One write, three actions

The switch, an add and a remove all PATCH the whole setting: `auto_bcc_enabled` plus the entire
`auto_bcc_emails` list. The panel owns all of it, so a partial write would need the server to
guess which of the two lists in front of it is newer. There is no Save button — with add and
remove writing immediately, a Save under the switch would be the only unsaved control on the
page and the easiest thing to walk away from. Each write reverts the control on refusal, the
same optimistic-then-revert every other panel uses.

Addresses are lower-cased and trimmed in `prepareForValidation`, once, so the validator, the
controller and the stored row all see the same string — `ARCHIVE@company.com` and
`archive@company.com` are one mailbox, and storing both would send it two copies. Duplicates are
refused by the request (which names the repeated address) and de-duplicated again on the way
into the column.

### Enabled with an empty list is allowed

The old rule required an address whenever the toggle was on. With a list that is impossible to
satisfy: the switch is what somebody flips FIRST, and the list is what they fill in afterwards.
So an empty list is a legal state and the panel says what it means — "Auto BCC is on, but
nothing is being copied until you add one" — rather than blocking the toggle behind a field that
does not exist yet. A Space in that state sends no blind copies, which is exactly what an empty
list means.

### The setup wizard still asks for one

Step 5 is unchanged: a first run is not the moment to build a list. `SetupCommitter` writes the
one address as a one-element list. More are added here.

### Files

- `database/migrations/2026_08_20_000002_auto_bcc_supports_many_addresses.php`
- `app/Models/HelpCenterSpaceSettings.php` — `auto_bcc_emails` cast, `bccEmails()`, `bccMax()`.
- `app/Http/Requests/HelpCenter/UpdateSpaceSettingRequest.php` — list rules, per-address messages.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — payload and write.
- `app/Services/HelpCenter/SetupCommitter.php` — writes the wizard's single address as a list.
- `public/assets/js/help-center/space-settings.js` — the list, the add form, the confirm.
- `config/help-center.php` — `auto_bcc_max`.

---

## P14 — A Space's tags (2026-08-20)

### Requirement

Settings → Tag was one switch: "Let agents tag conversations". There were no tags to let them
use. The page now also manages the Space's vocabulary — add a tag by name, see every tag in the
Space, remove one.

### A table, not a JSON column

The opposite of the Auto BCC decision one change earlier (P13), for the opposite reasons. Auto
BCC's addresses are a *field*: read and written whole, never referenced by anything. A tag is an
*entity* — Requests will point at it, reports will group by it, and renaming one must not mean
rewriting every row carrying the old spelling. A tag stored as a string in a JSON list is a
foreign key waiting to be discovered.

### Scoped to the Space

Two Spaces may each have a "Billing" tag and they are two different tags: one team's vocabulary
is not another's, and a workspace-wide list would mean every Space's picker growing with words
its agents never use. The unique index is `(help_center_space_id, name_key)`.

`name_key` is the name lower-cased, stored beside the display name. Two columns for one word,
deliberately: "Billing" and "billing" are one tag, and a unique index on `name` alone would hold
that rule on MySQL's default collation and quietly fail on Postgres (D1). `HelpCenterTag::key()`
is the single function that produces it, used by the request when it checks and the controller
when it writes — so a name that passed validation cannot then collide with the index.

### Why the page is still `kind => 'metadata'`

The nav entry gained `'manages' => 'tags'` rather than a new `kind`. The switch is saved through
the one settings PATCH exactly as the other five metadata switches are; the tags are created and
deleted through their own routes, because a tag is a row and not a field. A new `kind` would have
meant a second copy of the toggle's save logic waiting to drift from the first.

The list renders only while the switch is ON. Tags that agents cannot apply are a vocabulary for
a feature that is off.

A TABLE — Tag Name, Created, Action — the same one the Inbox panel's addresses use, not chips.
A Space's tags are a list somebody audits, and a chip has nowhere to say anything about itself;
`created_at` is in the payload because a two-column table is a table, and a one-column one is a
list with borders. Delete is the same trash icon and the same `<pb-confirm>` every other delete
on these pages uses.

### Not built

- **Renaming a tag.** It is a question about every Request already carrying it, and this change
  does not answer it. There is no PATCH route rather than a rename that quietly means something
  undecided.
- **Colours.** Tags are words here. A colour is worth adding when something renders them beside
  each other in a list; nothing does yet.
- **Deleting a tag that is in use.** Nothing carries a tag yet, so there is nothing to protect.
  When Requests do, this needs a decision — refuse, or detach — and the confirmation should say
  which.

Delete was not asked for; it is here because a list that can only grow is a trap, and because
every other delete on these pages is now an icon plus a confirmation.

### Files

- `database/migrations/2026_08_20_000003_create_help_center_tags.php`
- `app/Models/HelpCenterTag.php`, and `HelpCenterSpace::tags()`
- `app/Http/Controllers/HelpCenter/TagController.php`
- `app/Http/Requests/HelpCenter/StoreTagRequest.php`
- `routes/help-center.php` — `spaces.tags.store`, `spaces.tags.destroy`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — `managedPayload()`
- `public/assets/js/help-center/space-settings.js` — the chips, the add form, the confirm
- `config/help-center.php` — `manages` on the Tag nav entry

---

## P15 — Workflow moved into Settings (2026-08-20)

### What changed

`/spaces/{id}/workflow` was one of the Space's five tabs. It is now the second page of Space
Settings, directly after Inbox: `/spaces/{id}/settings/workflow`. The Space's tab bar is four
entries — Overview, Inbox, Members, Settings.

### Why

The tab bar held two different kinds of thing. Overview, Inbox and Members are screens an agent
works in; Workflow was a read-only description of how the Space is configured, sitting beside
them. Every other answer to "how is this Space set up?" is already behind Settings, and the two
questions people open Settings with are "where does mail arrive?" and "what states can a Request
be in?" — so it goes second, after Inbox.

### The old URL still resolves

`/spaces/{id}/workflow` is an explicit redirect to the new page, registered BEFORE the
`{section}` route. `workflow` is no longer one of `space_sections`, so without it the URL would
404 for anybody holding a link — and "it moved" is an answer a redirect can give and a 404
cannot.

### Still read-only

The panel shows exactly what the old one did: the status chain across the top, then the table —
Status, Responsibility, Waiting on, State, Default assignees. Editing a workflow is the setup
wizard's step 4; a second, different editor here would be two places that have to agree about
what a protected status is.

One thing did change: the Blade panel resolved default-assignee names with a `whereIn` **inside
the row loop** — eight statuses meant eight queries for a column most of them leave empty. The
payload resolves them all in one.

### Files

- `config/help-center.php` — `workflow` out of `space_sections`, into `space_settings_nav`.
- `routes/help-center.php` — the redirect.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — the `workflow` payload.
- `public/assets/js/help-center/space-settings.js` — the `workflow` panel.
- `resources/views/help-center/space.blade.php` — the old panel deleted.

---

## P16 — Editing the workflow (2026-08-20)

### Requirement

Settings → Workflow gained an **Edit workflow** button. It opens a full-screen editor — a
1000px column, centred — holding the whole status list: rename, recolour, responsibility,
Waiting on, active, default assignees, which status a Request opens in, order, add and delete.

### Why full screen and not a modal

A workflow row carries six fields and a position. The settings column is 820px, which is narrow
enough that the cards fold into something you cannot compare across — and comparing rows is the
one thing reading a workflow is for. So the editor takes the viewport, at 1000px centred, with a
sticky bar carrying Cancel and Save so a long workflow cannot scroll them away.

It edits a COPY of the list. Cancel has to leave the page showing what is stored, and an editor
bound straight to the rendered statuses would have rewritten them on the way in.

### One card, two screens

The status card moved out of `wizard.js` into `public/assets/js/help-center/status-card.js` and
is registered by both apps. The wizard's step 4 and this editor edit the same rows through the
same server-side rules; a copy in each would be two cards drifting apart while the server went on
treating their output as one thing.

The card's `advanced` prop is the only difference. The wizard asks for name, colour,
responsibility and assignees — a first run deliberately asks less. The editor also sets **Waiting
on** and **which status a Request opens in**, because by then the Space is running and those two
are what people come back to change.

### One set of rules

`WorkflowStatusPayload` now holds what a workflow payload must look like: `rules()`, `check()`,
`normalize()` and `ordered()`. `WorkflowStepRequest` and `UpdateSpaceSettingRequest` both
delegate to it, and `SetupCommitter::createStatuses()` writes through `ordered()`.

The distinction it preserves is the one P2 already made: the system constraints of §16 are
**normalized**, not validated. A payload that renamed Open, put Closed second, or marked Closed
as the starting status is corrected — those are not the user's mistakes to fix. What is validated
is what a person can get wrong: a nameless status, a duplicate name, too many of them, a missing
system row.

`ordered()` also decides `is_default` across the whole list rather than per row, because "where
does a Request open?" is a property of the workflow — two rows claiming it is a Space that cannot
answer the question.

### Why saving is not delete-and-recreate

`SetupCommitter` runs once, on a Space with no statuses, and can create every row.
`WorkflowUpdater` runs on a Space whose statuses are **pointed at** — `help_center_requests`
carries `help_center_status_id` — so it matches rows by id and updates in place. A
delete-and-recreate would silently detach every Request in the Inbox from its status.

Ids from the client are only honoured if they belong to this Space, and the delete pass excludes
system rows: "Open and Closed always exist" is refused by validation and again here, because it
is a fact about the product rather than a rule about one endpoint.

Default assignees are filtered to the Space's members on write. Assigning to somebody who is not
on the Space is assigning to somebody who cannot see the Request.

### Two rows per card (P17)

Each status card in the editor is two rows.

**Row one — the record.** Status · Waiting · Responsibility · Default Assigned · Status
(active). Five fixed-width cells except the name, so the columns line up down the list: a
workflow is read by scanning DOWN a column, and "which statuses wait on the customer?" should be
one column rather than every card read end to end.

**Row two — what you do to it.** Left: the up/down arrows, the colour code, then **Custom Rules**
and **Email Configuration** as Coming Soon. Right, where destructive actions belong: delete.

Splitting them is what lets both work. Row one stays a table you can read down; row two holds
controls of different shapes without pushing those columns out of line.

Details that carry a rule:

- The **colour** is the hex field, not the eight-swatch palette — this row has four other things
  in it. The swatch opens the native picker; it is the same stored value either way. The
  wizard's step 4 still offers the presets.
- The **arrows** are rendered disabled on Open and Closed rather than dropped, and Closed gets
  an empty slot where the start control would be, so row two keeps the same shape on every card.
- **Delete** appears only on custom rows. Open and Closed are protected (P2 §16), and the server
  refuses their removal regardless.
- The two **Coming Soon** items are spans, not buttons. A Coming Soon control that can be pressed
  is a promise the screen then has to break — the same rule the settings nav follows for AI Tag.
  Nothing is behind either of them.
- **Nothing here sets the starting status.** The value round-trips untouched — the editor sends
  back the `is_default` it loaded — so a Space keeps the status the wizard gave it, and no screen
  changes it. `WorkflowStatusPayload::ordered()` still decides it across the list on every save,
  falling back to the first row, so a payload that lost it cannot leave a Space unable to say
  where a Request opens. The read-only Workflow page still labels which one it is.

**The wizard keeps the stacked card.** The layout forks on the same `advanced` prop that decides
which fields appear: the editor has 1000px, step 4 is a narrower page. Two layouts in one file,
not two files — the fields, their bindings and the rules about which rows may be renamed or
moved are written once, and only the arrangement forks.

### Not built

- **Drag to reorder.** The card shows a grip because the wizard's does; both move rows with the
  arrows. One drag implementation, when it comes, should serve both.
- **What happens to Requests in a deleted status.** The confirmation says they will need a new
  one; nothing reassigns them, because nothing carries a status in anger yet. This needs a
  decision — block the delete, or move them to Open — before it does.

### Files

- `app/Services/HelpCenter/WorkflowStatusPayload.php` — the shared rules (new).
- `app/Services/HelpCenter/WorkflowUpdater.php` — the id-preserving save (new).
- `public/assets/js/help-center/status-card.js` — the shared card (new).
- `app/Http/Requests/HelpCenter/Setup/WorkflowStepRequest.php` — now delegates.
- `app/Http/Requests/HelpCenter/UpdateSpaceSettingRequest.php` — the `workflow` section.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — payload, save, `workflowStatuses()`.
- `app/Services/HelpCenter/SetupCommitter.php` — writes through `ordered()`.
- `public/assets/js/help-center/space-settings.js` — the editor.
- `resources/views/help-center/setup.blade.php`, `space-settings.blade.php` — script order.

## Not built

- **An AI Tag page.** It is Coming Soon in the only sense that matters: nothing writes it.
- **Per-section permissions.** The whole screen is gated on `update` of the Space, exactly as the
  Space's other management actions are.

---

## P18 — Company custom fields (2026-08-20)

### Requirement

Settings → Company, under its existing switch: **Custom Fields** — create additional fields to
capture information about a customer's company. Add, edit, disable, delete; a list showing Field
Name, Field Type, Required, Status and the row's actions.

### Why the fields are data and only the types are code

What a workspace wants to record about a customer's company — Industry, Account Tier, Contract
Renewal Time — is its own question, and no enumeration of ours would be right for the next
workspace. So `help_center_company_fields` is a table, scoped to the SPACE like its tags and its
statuses: one team's idea of "Company Size" is not another's, and a workspace-wide list would put
every Space's questions on every Company form.

The eight TYPES are config (`help-center.company_field_types`), and each carries one flag that
matters: `options`. It decides whether the modal shows an Options section, whether the request
demands at least one, and whether the stored list is kept or dropped. Three rules, one answer —
`HelpCenterCompanyField::hasOptions()` — so a Radio cannot need choices in the validator and not
in the modal.

Dropdown, Multiple Select, Checkbox and Radio have options. Input, Time Picker, Small Text Area
and Long Text Area do not, and their section stays hidden.

### The modal

One modal for create and edit, because a field is the same thing either way; `editing` decides
only the title, the button (**Create Field** / **Save Changes**) and where the save goes.

Options can be added, edited, removed and reordered. Two details:

- Choices **survive a change of type between two types that both take them**. Somebody switching
  Dropdown to Radio has not asked to retype three answers. The request drops them for a type
  that takes none, so switching to Input stores nothing — a stored list behind a field that
  cannot show it is a thing the next reader has to decide the meaning of.
- Editing works on a **copy** of the options. Cancel has to leave the stored list alone.

Validation runs in both places and says the same words: *Field name is required.*, *Add at least
one option before creating this field.*, *This option already exists.* The modal checks what is
already on screen — an empty box, a repeated word — because asking the server to report what
this page can already see is a round trip to learn nothing. The server checks again; a client is
not an authority on what it may store. Duplicates are compared case-insensitively: "Enterprise"
and "enterprise" are one choice to everybody reading the form.

### Status is on the modal too

The modal carries **Status — Active / Inactive** beside Required, so a field can be created
inactive (drafted before the form is ready for it) and its state changed while you are already
editing it, instead of only through the row's action.

Two words for two different things, deliberately: the STATE reads Active / Inactive, on the modal
and in the list's Status column; the row's ACTION says Disable / Enable, because that is the
verb. `is_active` is the one value behind both.

### Disable is not delete

A field somebody stopped asking is not a field that never existed — the values already recorded
against it stay meaningful — so the row has an off switch that takes it off the form without
taking it out of the history. `is_active` is what the Status column shows.

Delete removes the QUESTION. Nothing stores answers yet, and when something does, deleting them
is a data-retention decision rather than a side effect of tidying a form. The confirmation says
exactly that rather than implying the two are one act.

### One update endpoint

`PATCH .../company-fields/{field}` takes the WHOLE field. Save Changes sends it and so does the
row's Disable — the client has the row in front of it either way, and a second shape would be a
second answer to what a missing key means. Nested under the Space, and the field is checked to
belong to it, like every other nested route here.

### Not built

- **Rendering these fields on a Company record.** There are no Company records yet. This is the
  configuration; the form that reads it comes with them, and `rows` on the two text-area types
  is already there for it.
- **Unique field names.** Two fields called "Industry" are allowed. The spec does not ask, and
  unlike a tag list — which IS the vocabulary — a form can legitimately repeat a word in two
  sections. Worth revisiting when the form exists.
- **Reordering fields in the LIST.** Options reorder inside a field; the fields themselves are
  appended in creation order. `position` is stored and indexed for it.
- **What happens to answers when an option is renamed.** A stored answer will reference the
  option's text, so a rename is a rename and not a re-key. Needs deciding with the Company form.

### Files

- `database/migrations/2026_08_20_000004_create_help_center_company_fields.php`
- `app/Models/HelpCenterCompanyField.php`, and `HelpCenterSpace::companyFields()`
- `app/Http/Controllers/HelpCenter/CompanyFieldController.php`
- `app/Http/Requests/HelpCenter/CompanyFieldRequest.php`
- `routes/help-center.php` — `spaces.company-fields.{store,update,destroy}`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — `managedPayload()`
- `public/assets/js/help-center/space-settings.js` — the list and the modal
- `config/help-center.php` — `company_field_types`, the option caps, `manages` on the nav entry

---

## P19 — Members moved into Settings (2026-08-20)

### What changed

`/spaces/{id}/members` was one of the Space's four tabs. It is now a page of Space Settings,
third in the list after Inbox and Workflow: `/spaces/{id}/settings/members`. The Space's tab bar
is three entries — Overview, Inbox, Settings.

### Why

The same reason Workflow moved (P15). The tab bar mixed two kinds of thing: Overview and Inbox
are screens an agent works in all day, while Members is **administration** — who may work this
Space, what Department Groups they cover, and an invitation that can reach somebody who has
never opened ProjectBlock. Every other answer to "how is this Space set up?" is already behind
Settings, and the workspace's own member list lives under Settings too, so a Space's member list
sitting in the working tabs was the odd one out.

It goes third, after the two questions people open Settings with ("where does mail arrive?" and
"what states can a Request be in?") and before the display switches.

### The old URL still resolves

`/spaces/{id}/members` is an explicit redirect to the new page, registered BEFORE the
`{section}` route — `members` is no longer one of `space_sections`, so without it the URL would
404 for anybody holding a link. This module **emails** Space links, so a dead one is not
hypothetical. The `POST`/`PATCH`/`DELETE` member endpoints are untouched: only the screen moved.

### The page itself is unchanged

Same Tabulator grid, same toolbar, same Add Member / Department Groups / Remove dialogs, same
payload — `membersPayload()` moved from `SpaceController` to `SpaceSettingsController` verbatim.

It is the one Settings page that is **not** the settings panel, so the template branches on the
nav entry's `kind`: for `members` it renders the members root and loads Tabulator plus
`space-members.js`; for everything else, the settings root and `space-settings.js`. Neither
script is loaded on a page with no root for it to mount into.

Nothing is saved through the settings `PATCH` — the members routes own those writes — so
`update()` has no `members` branch and a `PATCH` to that section 404s rather than quietly
accepting a body no branch would read.

### Files

- `config/help-center.php` — `members` out of `space_sections`, into `space_settings_nav`.
- `routes/help-center.php` — the redirect, as `help-center.spaces.members`.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — `membersPayload()` and the panel
  binding removed.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — `membersPayload()` and the
  `members` kind.
- `resources/views/help-center/space.blade.php` — the old panel deleted.
- `resources/views/help-center/space-settings.blade.php` — the `members` branch.

---

## P20 — Settings sits on the right of a Space's tab bar (2026-08-20)

### What changed

The Space's section tabs are split: Overview and Inbox on the left, **Settings pushed to the
right-hand end** of the same bar — the arrangement `partials/project-tabs.blade.php` already
uses for a project.

### Why

The split is the point. What is on the left are the screens an agent works in all day; what is
on the right is how the Space is configured. Sitting third in a row of three, Settings read as
another working screen. It also makes the Project Workspace and a Space read the same way, which
is the rule the rest of this module follows — same grid, same toolbar, same settings sidebar.

P15 and P19 are what made the split honest: with Workflow and Members moved behind Settings, the
left-hand group is *only* the working screens.

### One bar, one file

The bar was duplicated in `help-center/space.blade.php` and `help-center/space-settings.blade.php`
— two copies that had to be edited identically every time a section changed. It is now
`partials/help-center-space-tabs.blade.php`, included by both. Both groups still render from
`$sections`, so "active" means the same on either side.

`ml-auto` on the Settings item, rather than two containers: the bar wraps on a narrow screen,
and a margin does nothing once the item is on its own line — which is exactly when a left/right
separation stops meaning anything.

### Files

- `resources/views/partials/help-center-space-tabs.blade.php` — new, the one copy.
- `resources/views/help-center/space.blade.php`, `space-settings.blade.php` — both include it.

---

## P21 — The Inbox views moved to the top navigation (2026-08-20)

### What changed

Unassigned, Mine, Draft, Assigned, Closed and Spam were six chips on a Space's Inbox screen.
They are the Help Center's **top-level navigation** now, one URL each, counted across **every
active Space**:

```
Overview
Inbox
Unassigned  2
Mine        1
Draft
Assigned    1
Closed
Spaces
```

The Space's own Inbox keeps its workflow status filters (All / the Space's statuses) and nothing
else. The ownership chips are gone from it.

### Why

Two things were wrong with chips.

They were the wrong altitude. "What is assigned to me?" and "what has nobody picked up?" are the
questions somebody opens the Help Center to answer — and they were two clicks and a Space deep,
inside a component's local state, so they could not be linked to, bookmarked, or survive a
refresh.

And they could only ever answer for one Space. An agent working three Spaces had three Inboxes
to check and no screen that added them up. The queue is cross-Space now, which is what makes a
count in the navigation mean something.

### One definition of what a view is

`App\Services\HelpCenter\RequestViews` — the rows (`apply()`), the counts (`counts()`) and
"does this row still belong here after somebody changed it?" (`tags()`) all read from one set of
predicates.

They already disagreed before this: the model scopes excluded closed Requests from Unassigned
and Mine, while the tags hand-written in `SpaceController::inboxPayload()` did not — so a closed
Request was counted nowhere and tagged "mine". `inbox.js` still re-derives the tags for a row it
just changed, because the update endpoint answers with one Request rather than the queue, and
its `viewsFor()` is deliberately the same rules; if the two ever diverge, the JavaScript half is
the one to delete.

### Counts

One query for the whole bar — conditional sums, not a count per view, because the navigation is
on every Help Center page. `CASE WHEN` with bound booleans, so it stays portable (CLAUDE.md §6).

**A zero is not rendered.** The element is still there and empty, which is what lets the refresh
write a number into it that was not there on page load.

They update without a reload: a queue screen dispatches `helpcenter:requests-changed` after a
successful PATCH and the sidebar re-reads `/help-center/inbox/counts`. A **re-read**, not a
delta applied in JavaScript — the page holds one view's rows and the bar counts three views
across every Space, so an adjustment made in the browser would be a second implementation of
`RequestViews`, and the first to drift.

Inbox carries no count although one could be computed: it would be the total of the three below
it, so the bar would show the same Requests twice and invite adding them up.

### Spam

Not in the navigation — it is where things go to be ignored, and a permanent entry for it
invites reading. It is a button on the queue screen's toolbar, which is where you are standing
when you suspect something was misfiled.

### One screen, two modes

`inbox.js` drives both the Space Inbox and the cross-Space queue.

| | Space Inbox | Queue |
|---|---|---|
| Filtering | client-side, on the Space's statuses | **SQL** — the view is the URL |
| Grouping | by workflow status | by **Space** (two Spaces are two workflows; "Open" would be one name for two things) |
| Row menu | one status list, one member list | the **row's own Space's** lists |
| A changed row | stays, and moves group | **leaves** if it no longer belongs to the view |

### A bug this surfaced

The grid re-reads its data from a watcher on its `rows` prop, and `rowsFor('all')` returned
`this.allRows` itself — the same array reference every time. A watcher does not fire when the
new value is the old value, so an in-place change (splicing a row out, folding the server's
answer back in) mutated the list and the grid went on drawing what it had. Only the case that
emptied the list looked right, because that flips a `v-if` and unmounts the grid rather than
refreshing it. `rowsFor` returns a copy now. The dead `updateRow()` — an alternative in-place
path nothing ever called — is deleted rather than left as a second answer.

### Files

- `config/help-center.php` — `space_views` → `request_views`, with `nav` and `counted` flags.
- `app/Services/HelpCenter/RequestViews.php` — new; the one definition.
- `app/Services/HelpCenter/HelpCenterNavigation.php` — `queue()` and `currentView()`.
- `app/Http/Controllers/HelpCenter/InboxQueueController.php` — new; the screen and `counts`.
- `routes/help-center.php` — `/inbox`, `/inbox/counts`, `/inbox/{view}`.
- `resources/views/help-center/inbox.blade.php` — new.
- `resources/views/partials/help-center-nav.blade.php` — the rows, the counts, the refresh.
- `app/Providers/AppServiceProvider.php` — `helpCenterQueue` on the composer.
- `public/assets/js/help-center/inbox.js` — chips removed, queue mode, grouping, the fix above.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — the duplicated view tagging deleted.

---

## P22 — The same views inside a Space (2026-08-20)

### What changed

P21 put Unassigned / Mine / Draft / Assigned / Closed at the top of the Help Center, across every
Space. They are now **also** a Space's own navigation, narrowed to that Space:

```
Overview                          ▾ Email Testing Phase
Inbox                                 Overview
Unassigned  2                         Inbox
Mine        1                         Unassigned  2
Draft                                 Mine        1
Assigned    1                         Draft
Closed                                Assigned    1
Spaces                                Closed
                                      Settings
```

The Space's tab bar carries the same list, with **Settings still pushed right** (P20): what is on
the left are the screens an agent works in.

`/help-center/spaces/{id}/mine`, `/unassigned`, and so on — the view is a Space SECTION, routed
through the same `{section}` URL Overview and Inbox already use, enumerated from the same
`request_views` config the top-level routes use. A view cannot exist at one scope and 404 at the
other.

### Why two scopes

They answer two different questions and both get asked. "What is unassigned anywhere?" is the
one somebody opens the Help Center with; "what is unassigned **in here**?" is the one somebody
already working a Space has. Neither is a filter on the other's screen — they are the same
definition read at two widths.

### One definition, one payload builder

`RequestViews` already said what each view means. `RequestQueue` now says what a queue screen
boots with, and both controllers call it. The scope changes exactly three things:

| | Across Spaces | Inside a Space |
|---|---|---|
| Query | every active Space | `+ where space` |
| Grouping | by **Space** | by **status** — one workflow to group by |
| Empty status groups | n/a | hidden; "Closed 0" under Mine can only mean nothing |

The Space's own **Inbox** is unchanged: client-side status chips, every row, empty status groups
kept (there the workflow's shape *is* information — "Closed 0" says the Space has a Closed state
and nothing is in it).

### Counts at both scopes

`countsBySpace()` — one `GROUP BY`, not a query per Space, because the sidebar draws a count
under every Space on every Help Center page and that is exactly where a tree of navigation
quietly becomes an N+1. `HelpCenterNavigation` memoises the map for the request, so the tree and
the current Space's tab bar share one read.

The refresh endpoint answers with **both** scopes and the sidebar repaints everything in one
pass: a count element's key is either `mine` (workspace-wide) or `11:mine` (that Space). One
Request changing moves numbers at both scopes, and repainting half of them is how the two start
disagreeing on screen.

### Also in this change

The Space Inbox's `All / Open / Closed` bar had 24px above it and 16px below; it now has 16px on
both sides. The offset is an inline style rather than `-mt-2`, which is **not in the built
stylesheet** — Tailwind emits only what it finds in the sources it scans, and a class that exists
only inside a JavaScript template string silently does nothing. Worth remembering for anything
else added to these screen scripts.

### Files

- `app/Services/HelpCenter/RequestQueue.php` — new; the shared payload.
- `app/Services/HelpCenter/RequestViews.php` — `counts($user, $space)`, `countsBySpace()`.
- `app/Services/HelpCenter/HelpCenterNavigation.php` — the views spliced into a Space's nav.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — view sections render the queue.
- `app/Http/Controllers/HelpCenter/InboxQueueController.php` — thinned onto `RequestQueue`.
- `routes/help-center.php` — the view keys added to the `{section}` enumeration.
- `resources/views/partials/help-center-nav.blade.php` — tree counts, two-scope repaint.
- `resources/views/partials/help-center-space-tabs.blade.php` — tab counts.
- `resources/views/help-center/space.blade.php` — one panel for the Inbox and its views.
- `public/assets/js/help-center/inbox.js` — `emptyGroups`, mode-gated chips, the spacing.

---

## P23 — The setup wizard's width and its progress bar (2026-08-20)

### What changed

The wizard's content column went from **792px to 1100px**, and the six-step progress bar now
sits on **one row**.

### Why

The narrow measure was chosen when every step was a single column of inputs. Steps 2, 3, 4 and 6
have since grown two-column grids, a status editor and a review table, all being squeezed into a
width picked for a paragraph. The fields keep their own readable widths — this only stops the
screen from being narrower than its content.

The bar wrapped onto two lines because the six full labels ("Invite Your Support Group",
"Configure Your Workflow") come to about 120 characters, more than a single row holds at any
width this screen should be. Broken over two lines, the numbers stopped reading as a sequence.

### How it fits

`SetupController::steps()` now carries a `short` for each step — Space / Team / Inbox / Workflow
/ Settings / Review — and the bar draws that. The full label stays as the row's `title` and, more
importantly, as the heading of the step you are standing on, which is the only step whose full
name anyone needs.

`flex-nowrap` means it cannot wrap again; the connector between steps grows, so the six spread
across the bar instead of bunching left. Labels `truncate` as a last resort and are hidden below
`sm`, where the numbers alone carry it. Verified to hold one row with no overflow down to 460px
of content width.

### The Tailwind trap, twice now

`max-w-[1040px]` produced **no rule at all** and the container silently went full width. Tailwind
emits only the classes it finds in the sources it scans, and `public/assets/js/help-center/*.js`
template strings are not scanned — so an arbitrary value used only there does nothing. 1100 is
used because `max-w-[1100px]` is already in the built stylesheet.

This is the second time in a day (P22's `-mt-2`). **Before adding an arbitrary-value utility to a
screen script, grep `public/assets/css/tailwind.css` for it**; if it is not there, pick a value
that is, or use an inline style.

### Breathing room under the actions

150px below the Continue / Cancel row, so the last field is not pinned to the bottom edge of the
window on the steps that fill it. It is a **margin on the action row**, not padding on the page
wrapper: the space belongs to that row, so it travels with it whatever a step renders above.
Inline, for the same reason the width is 1100 — `mb-[150px]` is not in the built stylesheet.

### Continue before Back

The footer reads **Continue · Back**, then Cancel Setup on the right. The forward action is the
one nearly every visit to this screen ends with, so it leads — and it keeps the same position on
all six steps: Back is absent on Step 1, so with Back leading the primary button shifted sideways
between Step 1 and Step 2.

### Step 2's coworker table asks before it removes

The row's "Remove" became a **trash icon** with a confirmation dialog. The column is one control
wide and repeats down the table, so the word was five characters of chrome on every row saying
what the glyph says on its own; `title` and `aria-label` keep it named for a pointer and for a
screen reader.

The dialog is the same `pb-modal` the Space's member grid uses. It says plainly that nothing is
sent or undone — the coworker has not been invited yet, they are only coming off a list — because
a red "Remove" with no context reads like deleting an account.

**Note for later:** *Cancel Setup* still uses a native `window.confirm()`. It works, but it
blocks the renderer (it froze browser automation mid-test) and it does not match the dialog above
or any other confirmation in this module. Worth converting to `pb-modal` — the same is true of
Step 3's address table, whose Remove is still a text button.

### Files

- `app/Http/Controllers/HelpCenter/SetupController.php` — `short` on each step.
- `public/assets/js/help-center/wizard.js` — the container width, the one-row bar, the footer
  spacing, the coworker table's icon and its dialog.

---

## P24 — Step 4's Add Workflow Status went missing (2026-08-21)

### The report

"I click Add Workflow — the new card shows but the Add Workflow button is missing."

### What was actually happening

The button was still on the page. It was above the fold, and the more it was used the further
away it got.

The control sat directly under the **Open** card — above every custom status — while a new card
is always inserted just **above Closed**, because Closed is always last (P2 §16). So the button
and the card it produced were at opposite ends of a list that grows with every click: after
three or four statuses you were at the bottom of the page looking at your newest card, with the
only way to add another scrolled off the top. A control you cannot see is missing, whatever the
DOM says.

The old code knew about the split and tried to paper over it — "where you click and where it
lands are deliberately different, which is why the button says so." A label does not fix a
control that has left the screen.

### The fix

The Add control moved to the **end of the list**, between the last custom status and Closed —
where the next card actually lands. It now follows the list down the page instead of being left
behind by it, and clicking it puts a card immediately above itself, so the thing you just made
and the way to make another are always in the same place.

With no custom statuses it sits between Open and Closed, exactly as before.

The help line under the list lost "New statuses are added just above Closed" — it was describing
the gap that no longer exists.

### Files

- `public/assets/js/help-center/wizard.js` — the Add block moved after the status list.

---

## P25 — The Space subtitle came off the working screens (2026-08-21)

### What changed

A Space's type chips and description used to render above **every** panel — Overview, Inbox and
the queue views. They are gone from all of them, and Description has joined the Overview's
detail table beside Space Type.

### Why

They are facts *about* a Space, and they were being printed over the top of the screens you open
a Space to work in. On the Inbox that meant a type chip and a description standing between the
tab bar and the Requests, answering a question nobody had while the list you came for was pushed
down the page. Space Type was already in the Overview table anyway, so above the Overview it was
the same fact twice.

Description was the one thing the subtitle showed that nothing else did — hence the new row,
rather than deleting the block and quietly losing it from the interface.

### Files

- `resources/views/help-center/space.blade.php` — subtitle block removed, Description row added.

---

## P26 — A new Request assigns itself, and says so (2026-08-21)

## Requirement

A workflow status can carry **Default assignees**. Until now the field was collected by the
wizard's step 4, stored, and displayed on Settings → Workflow — and did nothing. Every inbound
email landed Unassigned, and somebody had to notice it.

When a new Request is opened by inbound email, it is now assigned automatically to a default
assignee of its opening status, and that person is emailed.

## User roles

- Anyone named in a status's **Default assignees** who is still a member of the Space.
- No one else is affected: a Request whose status names nobody stays Unassigned, exactly as
  before.

## Database fields

None added. It reads `help_center_statuses.default_assignees` (already there) and writes
`help_center_requests.assignee_id` (already there).

## Business rules

1. **Only on opening.** A reply landing in an existing thread never reassigns it — the person
   holding it is holding it. `wasRecentlyCreated` is what separates the two.
2. **Assigned in the INSERT**, not as a second write. A Request that exists Unassigned for a
   moment and then changes owner is a row two screens can disagree about.
3. **Never for spam.** A message the filter already doubts should not page anybody, and
   assigning it would drop it into somebody's Mine queue — which is what the Spam view exists to
   avoid.
4. **The assignee must still be a Space member.** The pickers only ever offered members, but a
   list stored months ago outlives somebody leaving, and assigning to a person who cannot open
   the Space is assigning to nobody, silently. If none of the named people qualify, the Request
   stays Unassigned and a warning is logged.
5. **Fewest open Requests wins**, ties broken by the order of the list. A list of five people
   where the first always wins means the same as a list of one; balancing is the least the field
   can do to be worth naming five people in.
6. **The email is sent after the transaction commits.** The notification is queued; dispatched
   from inside the ingest transaction, a worker could read a Request that is not there yet.
7. **A mail failure does not fail the job.** The Request is already stored, and retrying would
   re-run the whole ingest for the sake of a heads-up.

## Acceptance criteria

- Inbound mail into a Space whose opening status names an assignee → the Request opens **already
  assigned** to them, in that status. *(Verified: #000006, status Open, assignee 13.)*
- That person receives "New Request assigned to you — #000006". *(Verified.)*
- A reply into an existing thread changes no assignee and sends nothing.
- A status naming nobody → Unassigned, no mail.
- A status naming only ex-members → Unassigned, one `help-center.auto_assign.no_eligible_assignee`
  warning.
- Spam → Unassigned, no mail.

## Real-time requirements

Mail only for now. CLAUDE.md §9 makes database and broadcast the Phase 1 default, and
`toArray()` is already written in §9's payload shape — but the Help Center has no bell yet, and a
database row nothing renders plus a broadcast nothing listens to are two channels that only look
like they work. Adding them is a one-line change to `via()` the day the bell exists.

## Queue requirements

The notification is `ShouldQueue`, sent from `IngestInboundEmail`, which is itself queued.

**Operational note:** `QUEUE_CONNECTION` is currently `sync`, so both run inline inside
Postmark's webhook request — including an SMTP send. Postmark treats a slow response as a
failure and retries, so a sluggish mail server now turns into repeated webhook attempts. The
ingest is idempotent, so nothing duplicates, but the delivery shows as failing. Moving to a real
queue driver with a running worker is worth doing before this sees traffic.

## Audit requirements

`help-center.inbound.ingested` now carries `assignee_id`, so the log says who a Request went to
as well as that it arrived.

## What the email contains

Ticket number and subject, the customer's name and address, the Space, when it arrived — and
then, under a rule, **the message they actually sent**.

The subject alone rarely settles whether to stop what you are doing: "Invoice question" could be
a typo or a chargeback. Reading it here is what makes the mail worth opening rather than a prompt
to go and open something else.

Details that took a second attempt:

- **One `line()` per paragraph.** A MailMessage renders each line inside a single `<p>`, so
  passing the body as one string collapsed every break in it — greeting, problem, question and
  sign-off arrived as one run-on sentence.
- **No `>` blockquote.** Tried, and dropped: the mail template escapes each line before the
  markdown is parsed, so it arrived as a literal ">" down the left of every paragraph. The `---`
  rule separates our words from the customer's instead.
- **Text part first**, HTML stripped only as a fallback, `preview` as the last resort — the same
  order and the same reasoning as the ingestor's preview.
- **Capped at 2000 characters**, with a line saying it was truncated. This is a heads-up, not an
  archive; a forwarded newsletter should not arrive as a wall of text.

## Files

- `app/Services/HelpCenter/Inbound/AutoAssigner.php` — new; who takes it.
- `app/Services/HelpCenter/Inbound/InboundIngestor.php` — assigns in the create.
- `app/Notifications/HelpCenterRequestAssigned.php` — new; the email.
- `app/Jobs/IngestInboundEmail.php` — sends it after the commit.

---

## P27 — Inbound email ticket confirmation (2026-08-21)

## Requirement

When a customer emails a Space's support address and a Request is opened from it, the system
sends a confirmation back to that customer: their request arrived, a ticket exists, here is its
Ticket Number, here is the subject they wrote, and somebody will follow up.

The point is the silence it removes. Without it, a customer who emails support cannot tell
"received, queued, being read" from "went nowhere".

## User roles

The customer — a person with no account here and no way to sign in. The email is written for
somebody who will never see this product: no ProjectBlock vocabulary, no link into an app they
cannot enter, nothing to do.

## Database fields

None added. It reads the Request that ingestion has already committed.

## Business rules

The rules for *whether* to reply are the substance of this feature — an auto-reply at the wrong
moment is worse than none, because it answers machines, doubles up on threads, and tells spammers
that an address is live. `TicketConfirmer::refusal()` returns a reason string rather than a
boolean, so the log says which rule fired.

| Situation | Sent? | Logged reason |
|---|---|---|
| New Request from a person | **yes** | — |
| Reply into an existing thread | no | `not_a_new_request` |
| Spam (ours or Postmark's verdict) | no | `spam` |
| Out-of-office, mailing list, bounce daemon | no | `auto_submitted` |
| Missing or malformed sender | no | `no_valid_sender` |
| `no-reply@`, `mailer-daemon@`, `postmaster@` | no | `unreplyable_sender` |

Postmark retries any webhook it does not get a 2xx from, and ingestion is idempotent — a retry
finds the message already stored and returns null, so a customer cannot be acknowledged twice for
one email.

## The email itself

- **Subject:** `Re: {their subject} [#000007]`. The `Re:` and their own words make it
  recognisable and thread it under what they sent; the ticket number is what they can quote, and
  what survives in the subject line if their client drops the References header. An existing
  `Re:` is not doubled.
- **From** stays the configured sender; **Reply-To** is the Space's support address. Sending *as*
  `support@theircompany.com` would look nicer and be refused — Postmark sends only from a verified
  signature, and that address belongs to the customer's supplier, not to us. Reply-To gets the
  behaviour that matters: reply → their support mailbox → their forwarding rule → back onto this
  same Request.
- **`In-Reply-To` / `References`** carry their Message-ID, so it threads in their client.
- **`Auto-Submitted: auto-replied`** and `X-Auto-Response-Suppress: All` tell the other side's
  autoresponder not to answer — the same courtesy we extend by refusing to acknowledge anything
  auto-submitted.

## Acceptance criteria

Verified against Space 12 with mail faked and the transaction rolled back:

- New customer email → ticket `#000007`; confirmation sent to `maria@example.com`, subject
  `Re: Invoice charged twice [#000007]`, Reply-To `support4@zorainteractive.com`, all four headers
  present. ✅
- Reply into the same thread → nothing sent. ✅
- `Auto-Submitted: auto-replied` → nothing sent. ✅
- `no-reply@` sender → nothing sent. ✅

## Queue requirements

Sent from `IngestInboundEmail`, after the ingest transaction commits — so the Ticket Number in
the email is a number that exists. A send failure is logged and swallowed for the same reason the
assignment notification's is: the Request is already stored, and a retry would re-run an ingest
with nothing left to do.

## Audit requirements

`help-center.confirmation.sent` with the ticket and recipient; `help-center.confirmation.skipped`
with the reason; `help-center.confirmation.failed` with the error.

## Open question — the Ticket Number format

The requirement's example reads `#HD-1024`; this codebase issues `#000007`, zero-padded and unique
per workspace, and that string is already on the Inbox grid, in the assignment email and in this
confirmation. Treated as illustrative and left alone. Changing it is a one-line change to
`HelpCenterRequest::ticketNumber()` and would apply everywhere at once — worth a decision rather
than an assumption.

## Files

- `app/Mail/HelpCenterTicketConfirmationMail.php` — new.
- `resources/views/emails/ticket-confirmation.blade.php` — new.
- `app/Services/HelpCenter/Inbound/TicketConfirmer.php` — new; the rules.
- `app/Services/HelpCenter/Inbound/PostmarkPayload.php` — `isAutoSubmitted()`.
- `app/Jobs/IngestInboundEmail.php` — sends it after the commit.

---

## P28 — Editable chips on the Inbox row (2026-08-21)

## Requirement

Each row in a queue carries editable controls, so an agent can work a ticket without opening it:
**Status · Assignee · Priority · Tags · •••**. Changes save immediately, the chip updates without
a refresh, and a failure restores the previous value.

## Database fields

`help_center_request_tag` — the pivot P14's own migration predicted ("Requests will point at it,
reports will group by it") and nothing had built. Tenant-scoped like every other table here,
unique on (request, tag) so a chip cannot be drawn twice, indexed on (tenant, tag) for the
reporting direction. Deleting a tag cascades: P14 has no rename, so a cascade is the whole answer
to "what happens to the Requests?".

## Business rules

- **Status** offers the Space's own workflow and nothing else — the server re-checks, because
  the client's list came from a page that may predate a workflow edit.
- **Changing status applies that status's default-assignee rule (P26), but only to an UNOWNED
  Request.** Moving a ticket into "Escalated" must not take it off the person who escalated it;
  a rule that silently reassigns other people's work is one nobody trusts twice.
- **Assignee** offers Space members only, plus an explicit Unassigned. Assigning somebody who
  cannot open the Space is a silent way to lose a customer's email.
- **Tags** are a set, sent whole (`sync`) — "the tags are now these" is one fact, where an add
  and a remove endpoint would be two requests racing to describe one row. Ids not belonging to
  this Space are **intersected out, not rejected**: a tag deleted while the page was open is not
  the sender doing anything wrong, and refusing the edit would lose the four tags they did pick.
- **Close/reopen** goes through `moveTo()` and the Space's own Open/Closed statuses. Writing
  `closed_at` directly would leave a Request that reads Closed on one screen and Open in the
  status column of the next.

## Priority vocabulary

The requirement asks for Urgent / High / **Medium** / Low / **No Priority**.

- `normal` keeps its stored value and is now **labelled** "Medium" — renaming the value would
  have been a data migration over every Request for a word on a chip.
- "No Priority" is the value `none`, not NULL. The column is `string(20) NOT NULL DEFAULT
  'normal'`; making it nullable would be a schema change plus a null branch in every reader, for
  a state that is simply another word in a fixed list.

## Interaction

Optimistic: the row is patched locally, its id goes into `savingIds` so the cluster dims, and the
**server's** version replaces the guess on success — a status change also moves the waiting clock
and may close the Request, and reproducing that in the browser would be a second implementation.
On failure the snapshot goes back and the error is shown. Not a re-fetch: the row we had is the
row that was true a moment ago, and a reload would also undo anything else changed meanwhile.

`savingIds` is a LIST, not a boolean — two chips on two rows can be saving at once, and one flag
would lock the grid for the slower of them.

Clicking a chip does not open the ticket: the delegated listener stops propagation before the
grid's own row-click fires, which is the rule the ••• already relied on.

## Two bugs found while building it

- **`sync($ids, ['tenant_id' => …])` silently drops the attributes.** The second argument of
  `sync()` is `$detaching`, a boolean — so every pivot insert failed on the NOT NULL column.
  Pivot attributes only travel in the keys' values. (The optimistic rollback worked, which is how
  it showed up as a restored chip and an error toast rather than a broken row.)
- **The assignee chip rendered as a bare "?"** for a user whose `name` column is empty.
  `toPayload()` now uses `displayName()`, as the pickers already did.

## Acceptance criteria

Verified on Space 12, then reverted:

- Status changed from the row (Open → Review); row moved group, filter counts and sidebar counts
  followed, and the new status's default assignee was applied because the Request was unowned. ✅
- Assignee chip shows the name; picker is searchable and offers Unassigned. ✅
- Priority chip reads Medium and offers the five values. ✅
- Tags added from the row; chip shows the name, empty state points at Settings › Tag. ✅
- ••• offers Open Ticket, Assign to Me, Mark as Closed / Reopen, Copy Ticket Number, Copy Ticket
  Link, Mark as Spam. ✅
- No refresh needed; failures restore the previous value. ✅

## Not built — ticket activity/history

The requirement asks that changes be recorded in the ticket's activity/history. **There is no
activity table in this module, and no Request detail screen to show one on.** Building the writes
without the record, or the record without anywhere to read it, would be a feature that only looks
finished. `last_activity_at` moves on every change, and the logs carry assignment and
confirmation events; a real history is its own piece of work and should be specified as one.

## Files

- `database/migrations/2026_08_21_000001_create_help_center_request_tag.php` — new.
- `app/Models/HelpCenterRequest.php` — `tags()`, tags in the payload, `displayName()`.
- `app/Http/Controllers/HelpCenter/RequestController.php` — `tag_ids`, `closed`, the
  status-change assignment rule.
- `app/Services/HelpCenter/RequestQueue.php`, `SpaceController.php` — tags in both payloads.
- `config/help-center.php` — the priority vocabulary.
- `public/assets/js/help-center/inbox.js` — the chips, the five pickers, optimistic saves.

---

## P29 — "No user showing up" in the Assignee picker (2026-08-21)

### The report

The Assignee picker on a queue row appeared to offer nobody.

### What was actually happening

It offered everybody it could. `Email Testing Phase` has **four** members and exactly **one** of
them has an account:

| Member | `user_id` | Assignable |
|---|---|---|
| designwithphillip+456@gmail.com | 13 | yes |
| rohitcphilip+20001@gmail.com | — | no — invitation not accepted |
| rohitcphilip+9999@gmai.com | — | no — invitation bounced |
| rohticphilip+2313@gmail.com | — | no — invitation not accepted |

A Space member with no `user_id` was invited and has not accepted. There is no account to assign
work to, so the picker filtered them out — correctly, and *silently*. The Members grid says four
people, the picker offered one, and nothing on screen explained the gap.

(Every Space in this workspace has exactly one assignable member, which is why the list looked so
empty. `werwere` has none at all and shows "This Space has no members yet.")

### The fix

Invited members are now **shown and not offered**: greyed, with an `Invited` badge and a tooltip
saying there is no account to assign to yet. Present, so the list matches the Members grid;
unclickable, so the rule is still the rule. Accepted members sort first — the list's job is
assigning, and the people who can be assigned should not be interleaved with the people who
cannot.

The endpoint refuses a non-member regardless; this is not the enforcement, it is the explanation.

`Assign to Me` also skips pending entries, which it would otherwise have matched on a null id.

### Files

- `app/Services/HelpCenter/RequestQueue.php` — `members()`, one shape for both scopes.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — the same shape on the Space Inbox.
- `public/assets/js/help-center/inbox.js` — the greyed row and its badge.

---

## P30 — The customer column became the avatar (2026-08-21)

### What changed

A queue row printed the customer's name and email address in a fixed 210px column. It now shows
the **avatar only**, 44px, with the name and address on its hover tooltip.

### Why

210px on every row, spent on two facts an agent scanning a queue is not scanning *for* — they are
reading subjects — and taken from the subject and preview, which is what they are reading. The
subject column went from 876px to well over a thousand. The avatar's colour and initial already
tell one customer from another down a list, and both facts are one hover away.

### The tooltip has to go ON the avatar

First attempt put `data-tip` on a wrapper around it and showed only the name. `wiAvatar` writes
its **own** `data-tip` from `person.name`, and the tooltip handler reads the *closest*
`[data-tip]` — so a wrapper's tip is shadowed by the avatar's every time, and the address was
swallowed. The composed string is passed as the avatar's `name` instead.

`email` is passed with it: `PB.avatarColor` prefers an email over a name, so the colour is keyed
to the address rather than to a display string that now holds both — the same customer keeps the
same colour whatever their display name does.

### Files

- `public/assets/js/help-center/inbox.js` — `customerCell`, and the column width.

---

## P31 — Only editable things look editable (2026-08-21)

### What changed

The **waiting clock** and the **last activity** time are no longer drawn as chips. Plain text,
no border, no background, no hover, `cursor-default`.

### Why

They were never editable — both have always been plain spans, and clicking one did nothing. But
they were drawn with a border and a rounded box, which is exactly the treatment the five editable
chips beside them carry (P28). The row offered no way to tell a control from a fact except by
clicking one and watching nothing happen.

They are **measurements**: what the Request has done, not settings anybody picks. The waiting
clock is derived from `waiting_since` and the current status's *Waiting on*; last activity is
`last_activity_at`. Neither has a picker because neither could have one.

So the row now has one rule the eye can learn — **boxed means you can change it** — and it only
works if nothing else is boxed.

Kept: the clock icon, and the amber past four hours. Those carry meaning, not affordance. The
tooltips now say so out loud — "Waiting on Agent for 7 h 9 m — this is a measurement, not a
setting".

### And they no longer open the ticket

Both readings carry `data-static`, and the row's click handler ignores `[data-act],[data-static]`
— so clicking one does nothing at all. It used to open the ticket, which made the two facts
behave like the one part of the row that is supposed to open it: the subject.

The last-activity time also gained 5px of right padding, so there is a visible gap where the
facts end and the controls begin. An inline style, not `pr-[5px]` — Tailwind emits only what it
finds in the sources it scans, and this file is not one of them (P22, P23).

Verified: clicking "35 minutes" does nothing; clicking the subject still opens the ticket.

### Files

- `public/assets/js/help-center/inbox.js` — `metaCell`'s two readings, and `rowClick`.

---

## P32 — The Request detail drawer (2026-08-21)

## Requirement

Clicking a row opens a drawer, the way a work item does, carrying the same elements.

## What the work item drawer actually is

Worth stating, because it shaped this one: a work item's "drawer" is its **detail screen**
(`projects/work-item-frame.blade.php`) rendered in `pageMode` and embedded in a slide-over — so
other screens show the real thing rather than a copy of it.

A Request has **no detail screen** to embed. There was nowhere to click through to at all: the row
click used to say "the detail view is not built yet". So this builds the panel directly, in
`inbox.js`, and the panel is the detail.

## The panel

```
┌─ #000005 ─────────────────────────────────── ⋯  × ┐
│ Unable to Update Billing Information │ STATUS      │
│ ● Design Philip  designwithphillip…  │  [Open    ] │
│                                      │ ASSIGNEE    │
│ ┌ Design Philip        7 hours ago ┐ │  [Unassigned]│
│ │ Hi Support Team,                 │ │ PRIORITY    │
│ │ I'm trying to update the billing…│ │  [Medium  ] │
│ └──────────────────────────────────┘ │ TAGS        │
│ ┌ Replying arrives in a later release│  [+ Tag   ] │
└──────────────────────────────────────┴─────────────┘
                                         Waiting  7h
                                         Last act 7h
                                         Ticket #000005
```

- The **thread**, oldest first. Inbound is the customer; outbound is indented and tinted, the way
  a reply reads in a mail client.
- The **same chips**, opening the **same pickers** with the same save path — a Request cannot
  mean one thing on the row and another in the panel.
- The two **readings** stay readings (P31): plain text, no picker.
- Replying says plainly that it is a later release. A composer that cannot send would be worse
  than the sentence — the module has no outbound path yet.

## Data

`GET /help-center/spaces/{space}/requests/{request}` — fetched when the drawer **opens**, not
shipped with the queue. A queue is a hundred rows and a thread is every email on one of them;
sending all the bodies so that one might be read is the difference between a page that loads and
a page that downloads a mailbox.

The row's own data renders immediately and the thread arrives after, so the panel is never blank.
Message bodies are served as **text** — the text part first, HTML stripped as a fallback — and
rendered with `white-space: pre-wrap`, never `v-html`. It is a stranger's email; their markup does
not belong in our DOM.

The drawer reads its row back **out of the list** rather than from the snapshot it opened with, so
changing a chip inside it updates the panel and the row together.

## Two bugs worth recording

- **`z-[71]` is not in the built stylesheet.** The panel had no z-index at all, the backdrop's
  `z-[70]` painted over it, and every click on a property chip hit the backdrop and closed the
  drawer. The stylesheet holds 60, 70, 80, 81, 85, 90, 95, 100 — the drawer is 60/70, under the
  pickers' 80/81. Third time this trap has bitten (P22's `-mt-2`, P23's `max-w-[1040px]`).
- **`@keydown.esc` on the screen root did nothing.** The drawer and pickers are teleported to
  `<body>`, so a key pressed inside them never reaches that element. Escape is a document
  listener now: it closes the picker if one is open, otherwise the drawer.

## Acceptance criteria

Verified on Space 12:

- Clicking a row opens the drawer with the subject, customer, and the real email thread. ✅
- Property chips open their pickers *inside* the drawer, anchored to the chip. ✅
- Escape closes the picker, then the drawer; × and the backdrop close it. ✅
- Chips still ignore the row click, and the readings still open nothing (P31). ✅

## The shell is the work item drawer's, class for class

Not approximated — copied from `projects/work-items.js`, because a slide-over that is 860px here
and 80% there is two drawers, and the point of following it is that it is one:

| | |
|---|---|
| Container | `fixed inset-0 z-[85]` |
| Backdrop | `absolute inset-0 bg-black/20` |
| Panel | `absolute right-0 top-0 h-full w-full sm:w-[80%] bg-white shadow-2xl` |
| Toolbar | `flex items-center gap-1 px-4 h-14 border-b border-line` |
| Close | `arrow-right-long`, 18px, first in the toolbar |
| Expand | `expand`, 16px, second |

The pickers moved from `z-[80]/[81]` to `z-[90]/[95]`: with the shell at 85, a picker opened from
inside the drawer would otherwise open *underneath* it.

**Expand differs from the work item's, and has to.** There, Expand is a link to the item's own
page. A Request has no page — this drawer is the only detail the module has — so here it widens
the panel to the full window and back, which is the part of "expand" that can be honoured today.
A full-page Request view would make it a link, like the original.

## Files

- `app/Http/Controllers/HelpCenter/RequestController.php` — `show()`, `readableBody()`.
- `routes/help-center.php` — `spaces.requests.show`.
- `app/Services/HelpCenter/RequestQueue.php`, `SpaceController.php` — the `detail` endpoint.
- `public/assets/js/help-center/inbox.js` — the drawer.

---

## P33 — The ticket panel, and the customer behind it (2026-08-21)

## Requirement

The drawer's right-hand panel shows all of a ticket's metadata — four fields editable as chips,
the rest as facts — and beneath it a **Ticket Sender** block: who wrote in, what we know about
them, and their history. Inbound mail matches its sender to an existing customer, or creates one.

## Database

Until now a customer was **three columns on the Request**: `customer_email`, `customer_name`, and
nothing else. Enough to draw a row; not enough to answer what the panel is asked — how many
tickets has this person opened, when did they first write, what company, what number.

`help_center_customers` — tenant, email, name, company, phone, external_id, first_contact_at.
`help_center_requests.help_center_customer_id`, nullable, null-on-delete.

**Workspace-scoped, not per Space.** Somebody who writes to Billing on Monday and Support on
Tuesday is one person with two tickets; a per-Space record would call them two people with one
each — which is exactly the count the panel exists to get right. (Verified: #000004 in one Space
lists #000001 from another under the same sender.)

The Request **keeps its own** `customer_email` and `customer_name`. Those are what *that message*
said; the record is what the workspace knows across all of them, and a Request must still render
if its customer is ever removed.

**Backfilled in the migration.** Without it the panel would say "1 ticket, first contact today"
for somebody with a year of history — worse than showing nothing, because it looks like an answer.

## Matching

`CustomerMatcher`, called from the ingestor before the Request is created, so the link is written
in the same insert.

- Matched on the **email alone**. It is the only thing an inbound message reliably carries about
  its sender, and it is what a reply is addressed to. Matching on a name would merge two John
  Smiths and split one person who signs off differently from their phone.
- An existing record **learns a name and never loses one**: a first email may carry no display
  name and the second may. Filling a gap is useful; overwriting is not — a name somebody typed
  in by hand must not be replaced by whatever a mail header said this morning.

## The panel

**Ticket details** — Status, Assignee, Priority, Tags as editable chips (P28's pickers, opened
from here, saving through the same path, and a status change still applies its default-assignee
rule); then Workflow, Ticket #, Channel, Inbox, Created, Last updated, Waiting as **plain rows**.
The rule from P31 holds throughout: boxed means you can change it.

The Space's name IS the workflow's name — a Space owns exactly one workflow. Dates are formatted
**server-side**: every one is displayed and none is computed with, and formatting in the browser
is how two screens disagree about what "Aug 21" means in another timezone.

**Ticket sender** — name, email, company, phone, customer ID, first contact, total tickets; then
**Copy email**, **View previous tickets** (their last five, each linking into the Space that
ticket actually lives in), and **Update customer**.

`Update customer` edits name, company and phone. **No create, and the email is not editable**: a
customer record is created by the email that opened a ticket, never by hand — inventing one would
mean an address nobody has written from, which no ticket can ever match — and the address is the
identity every Request was matched on, so changing it would silently re-point a history at
somebody else.

## Acceptance criteria

Verified on Space 12, ticket #000004:

- Status / Assignee / Priority / Tags editable from the panel, saving without a refresh. ✅
- Workflow, Ticket #, Channel, Inbox, Created, Last updated, Waiting all shown. ✅
- Sender block shows the customer with **First contact Aug 19** and **Total tickets 2** — both
  from the backfill, both real. ✅
- View previous tickets lists #000001 **from another Space**, proving cross-Space matching. ✅
- Update customer opens seeded with the known name. ✅

## Not built

- **View Customer** — there is no customer screen to open. The panel is the customer view for
  now; a real one is its own piece of work.
- **Add Internal Note** — the module has no notes. A button that opens nothing is worse than no
  button.
- **Ticket history/activity** — still absent, as P28 recorded. `last_activity_at` moves on every
  change and the logs carry assignment and confirmation events, but there is no activity table
  and no place to render one.

## Files

- `database/migrations/2026_08_21_000002_create_help_center_customers.php` — table, link, backfill.
- `app/Models/HelpCenterCustomer.php` — new, with `toPanel()`.
- `app/Services/HelpCenter/Inbound/CustomerMatcher.php` — new.
- `app/Services/HelpCenter/Inbound/InboundIngestor.php` — links the customer on create.
- `app/Http/Controllers/HelpCenter/RequestController.php` — `meta`, `customerPanel()`.
- `app/Http/Controllers/HelpCenter/CustomerController.php` — new; the update.
- `routes/help-center.php` — `help-center.customers.update`.
- `public/assets/js/help-center/inbox.js` — the panel.

---

## P34 — The panel's controls, in the work item drawer's style (2026-08-21)

### What changed

Status, Assignee, Priority and Tags in the ticket panel were **full-width bordered boxes**. They
are now the work item drawer's property controls, taken control for control from
`projects/work-items.js`.

### The pattern

```
Properties                              ← h3, text-[15px] font-semibold
Status            Priority              ← grid-cols-2, gap-x-4 gap-y-4
● Review          ● Medium              ← borderless, hover:bg-hover, -ml-1.5
Assignee          Waiting
D David Webby     8 h 10 m              ← Waiting is a reading: no button (P31)

Details                                 ← text-[13px] font-medium text-sub
Tags     [Billing] [Customer] [+ Tag]   ← chips, then a dashed add — the Labels row's shape
Workflow  e4t3453                       ← divide-y rows
Ticket #  #000004
…
```

A property in a panel is a **value you can click**, and a box around it says "form field"
instead. The label above in `text-[12px] text-sub`, the value borderless with a hover highlight
and a `-ml-1.5` so it optically aligns with the label — that is what makes the work item panel
read as a summary rather than a form, and it is what this now does.

Tags follow the Labels row rather than the grid: a chip each, then a dashed **+ Tag**. Names are
free text of any length, which is why they get a full-width row and everything else gets half of
one, and why each one truncates.

Behaviour is unchanged — the same pickers, opened from the same handler, saving down the same
path.

### Panel width

`w-[320px]`, not the work item's `lg:w-[340px]`: 340 is not in the built stylesheet and 320 is
(P22, P23, P32 — the same trap, checked first this time).

### Files

- `public/assets/js/help-center/inbox.js` — the panel's controls.

---

## P35 — Assign to Me, when it is already yours (2026-08-21)

### What changed

The ••• menu's **Assign to Me** is disabled when the Request is already assigned to the person
reading it, and says why: *"Assign to Me — already yours"*.

### Why

It would have sent the assignee the Request already has. Harmless in the database, not harmless
on screen: `last_activity_at` moves, so the row jumps in a queue sorted by activity, the counts
refresh, and a toast says "updated" — a lot of feedback for a change that changed nothing.

An action that offers to do what has been done is a menu asking to be clicked twice.

### Details

- `disabled` on the button, plus an early return in `assignToMe()` — a disabled attribute is a
  UI state, not a guard, and the method is reachable from a keyboard.
- The hover highlight is bound conditionally rather than through `disabled:hover:bg-transparent`,
  which is not in the built stylesheet. A disabled row that still lights up under the pointer
  reads as clickable. (Checked the stylesheet first this time: `disabled:opacity-50` and
  `disabled:cursor-not-allowed` are both there.)

### Files

- `public/assets/js/help-center/inbox.js` — `assignedToMe`, the menu item, the guard.

---

## P36 — Ticket comments, replies and history (2026-08-21)

## Requirement

The ticket detail carries the work item's tabs — **All / Activity / Reply to Customer / Updates /
Transition / History** — and adds the customer email layer a work item does not have.

## What existed to reuse, and what did not

The work item has four tables: `work_item_activity` (event, field, old, new, meta),
`work_item_comments`, `work_item_updates`, `work_item_transitions`. The Help Center had **none of
them** — P28 and P33 both recorded the gap. So "reuse the components" meant reusing the *shape*,
which is what this does.

| Work item | Help Center | |
|---|---|---|
| `work_item_activity` | `help_center_request_activity` | same columns |
| `work_item_updates` | `help_center_request_updates` | same, minus progress/subtasks |
| `work_item_transitions` | — | **not a table**: see below |
| `work_item_comments` | `help_center_messages` | already existed |

**No transitions table.** A transition is an activity row whose `field` is `status`. Two tables
would be two records of one event, free to disagree the first time one is written and the other
is not — and the Transition tab is a filter, not a different history.

`help_center_request_updates` drops `progress_percent` and the subtask counts: they describe how
far through a piece of work somebody is, and a Request is not worked in subtasks. Carrying them
would be three always-null columns and a form with three fields nobody can fill in.

## One list, six readings

The detail endpoint assembles **one** `timeline` — messages, updates and activity sorted by time,
each carrying a `kind`. Every tab is a filter over it, so no two tabs can disagree about the
order things happened in, which is what six separately-assembled lists eventually do.

Agent and customer replies are both `kind: message`, told apart by `inbound`. The requirement's
rule that a customer reply must never be merged into the agent reply above it holds because they
are **separate rows**, not because the client is careful.

## The three communication types stay separated structurally

| | Emails the customer | Endpoint |
|---|---|---|
| **Reply to Customer** | yes | `POST …/reply` |
| **Customer Reply** | inbound | the ingestor, unchanged |
| **Internal Update** | **no** | `POST …/updates` |

Two endpoints, not one with a flag: the difference is whether an email leaves the building, which
is the most consequential thing this module does — and a boolean deciding it is a boolean
somebody will eventually get wrong. **`storeUpdate()` has no mailer and no recipient**; the
separation is structural.

## Reply details

- **Stored, then sent, then recorded.** The row is written first so a mail server that is down
  loses the delivery and not the reply — an agent who typed three paragraphs and got an error
  must still find them on the ticket. A failed send answers `ok: true, sent: false` and says so.
- **Threaded on the LAST inbound message**, not the first: a client threads a reply under what it
  answers, and answering the top of a long thread puts it in the wrong place.
- **No `Auto-Submitted` header**, unlike the confirmation (P27) — that one is a machine talking;
  this is a person, and a reply the customer's client is told not to answer is a conversation
  with one side muted.
- **Replying hands the wait back to the customer.** A Request the agent has just answered is not
  one the agent is holding up. An internal update does **not** touch the clock — a note to
  colleagues is not an answer, and moving the clock would hide a ticket still owed a reply.

## Activity is recorded in one place

`RequestActivity::record()`. Every change — from the row, from the drawer, from an inbound email,
from a workflow rule — leaves the same row behind. Each caller writing its own is how a history
ends up complete for the paths somebody remembered and silent for the rest, which is worse than
none because it looks complete.

- **Values are stored as displayed**: an old status is its name, an old assignee is their name.
  Ids go in `meta`. A history rendering `help_center_status_id: 31 → 33` is unreadable.
- **A change that changed nothing is not history** — no `Open → Open` rows.
- **A null actor means the system.** An inbound email opening a ticket, an auto-assignment (P26)
  and a rule-driven status change all have one; the timeline says "System" rather than blaming
  whoever was looking.

## Verified

Browser verification was not possible — the session expired mid-run and signing in is not
something I can do. Tested at the PHP level instead, inside a transaction that was rolled back:

- No-op activity suppressed; real changes recorded with old → new. ✅
- Timeline assembled: 1 message, 1 update, 3 activity rows, correctly ordered and typed;
  transition filter finds the status row. ✅
- Reply: subject `Re: Unable to Reset My Account Password [#000004]`, to the customer, Reply-To
  the Space's support address, `In-Reply-To`/`References` present, stored as an outbound message,
  waiting clock moved. ✅
- Internal update stored with its status, **and sent no mail**. ✅

**The UI is unverified.** The tabs, the entry rendering and the two composers are written but
have not been seen in a browser. Worth a look before trusting them.

## Still not built

- **Editing an update.** The work item allows it (`edited_at` is on the table and in the payload);
  the endpoint is not written yet.
- **Custom field and customer changes in history.** `customer_updated` is defined as an event and
  `CustomerController` does not yet record one.

## Files

- `database/migrations/…_create_help_center_request_activity.php`, `…_request_updates.php`
- `app/Models/HelpCenterRequestActivity.php`, `HelpCenterRequestUpdate.php`
- `app/Services/HelpCenter/RequestActivity.php`
- `app/Mail/HelpCenterAgentReplyMail.php`, `resources/views/emails/agent-reply.blade.php`
- `app/Http/Controllers/HelpCenter/RequestController.php` — `reply()`, `storeUpdate()`,
  `timeline()`, and activity recording on every change
- `app/Services/HelpCenter/Inbound/InboundIngestor.php` — `created` and auto-assign rows
- `routes/help-center.php`, `public/assets/js/help-center/inbox.js`

---

## P37 — The ticket's own question, above the tabs (2026-08-21)

### What changed

The message that opened the ticket now sits directly under the subject and the customer, **above
the tab bar** — visible whichever tab is selected.

### Why

It was an entry in the timeline, so it was only on screen while All or Reply to Customer happened
to be open. Reading the history of a ticket meant losing sight of what the ticket was about,
which is the one thing every other tab is context for.

It is the ticket, not a thing that happened to the ticket.

### And it is not shown twice

The opening message is **excluded from the timeline** now that it is the headline — a ticket's own
question repeated two inches below itself is not a second event. So the timeline is everything
*since* the ticket was opened, and the empty states say so: "Nothing has happened since the ticket
was opened", "No replies yet — the customer's first message is above."

A Request with no stored inbound message gets a line saying so rather than an empty frame, which
would read as a failure to load. (One can exist: a Space's Requests created by an import, or by
any path that stores no message.)

### No box, and a Show more (P37b)

The border came off. The work item drawer prints a description straight onto the panel, and a box
around the ticket's own question makes it read as a quotation of something else rather than as
the thing itself.

Long messages are clamped with **Show more / Show less** — the work item's control, verbatim:

- `wi-desc-clamp` from `work-items.css`, which this screen already loads for the grid skin. Five
  lines, with a fade at the bottom edge.
- The overflow test is `scrollHeight - clientHeight > 4`, taken in the **collapsed** state,
  because the two only differ while the clamp applies. Re-run whenever a detail load lands.
- Collapsed again for each ticket opened: "show more" is a decision about the message in front of
  you, not a preference that should follow you down the queue.

Verified: the message clamps at five lines with **Show more**, expands to the full text with
**Show less**, and the sender line and tabs move with it.

### Files

- `app/Http/Controllers/HelpCenter/RequestController.php` — `originalMessage()`, and the
  timeline's exclusion.
- `public/assets/js/help-center/inbox.js` — the headline block, the clamp, two empty states.

---

## P38 — All and Activity, as the work item detail renders them (2026-08-21)

### What changed

The timeline is the work item detail's feed now, row for row:

```
 ◎   David Webby set the priority to High
     48 seconds ago

 💬  ┌─────────────────────────────────────┐
     │ R Rohit Philip  CUSTOMER REPLY  2h  │
     │ Thanks — still not working.         │
     └─────────────────────────────────────┘
```

- **An event-icon circle** leads every row — `h-7 w-7 rounded-full border border-line`, with the
  glyph keyed on the row's `field`. A row's icon says what KIND of change it was before the
  sentence is read, which is what makes a long feed skimmable.
- **Something that HAPPENED** is a one-line sentence with the actor's name leading it in medium
  weight — "David Webby set the priority to High" — and the relative time under it.
- **Something somebody SAID** keeps a card: avatar, name, badge, time, then the words. Exactly
  the treatment the work item gives a comment.
- **Empty states** are the work item's: a bold heading and a line saying what would appear here,
  centred with `py-8`.

**History is the exception** and stays a table of values — `Priority changed / Medium → High /
David Webby · Aug 21, 2026 at 11:34 PM`. A list of changes reads better as values than as prose;
that is the difference between the Activity tab and the History tab, and rendering both as
sentences would make them the same tab twice.

### The phrasing lives on the server

`HelpCenterRequestActivity::phrase()` — "changed the status to Review", "assigned it to David
Webby automatically". Beside `sentence()`, which History uses. Both are on the model because the
phrasing changes with the event, and a client that knows how to phrase eight events is a second
copy of that vocabulary.

The `via` in `meta` earns its place here: an auto-assignment reads "assigned it to David Webby
**automatically**" rather than as though somebody chose it.

### The icons are defined here, not imported

`partials.work-item-assets` loads work-item-ui.js and work-item-list.js but **not**
work-items.js, so `WI_EVENT_ICON` is not on the page. The paths for status, priority, assignee,
tag and created are copied from it, so the two feeds speak one visual language.

### A bug this surfaced

**Editing a chip in the drawer did not refresh the timeline.** The chip patches the ROW; the
timeline lives on the drawer's fetched detail. So the panel showed a ticket whose priority had
visibly just changed above a feed insisting nothing had happened since it was opened. `change()`
now re-reads the detail when the row being changed is the one the drawer has open.

### Verified

Changed #000004's priority from the drawer: the chip updated, and the feed gained
**"David Webby set the priority to High · 48 seconds ago"** with the priority glyph; the tab
counts moved to All 1 / Activity 1 / History 1; History rendered `Medium → High` with the actor
and full timestamp. (That change is real and left two rows of genuine history behind.)

### Files

- `app/Models/HelpCenterRequestActivity.php` — `phrase()`.
- `public/assets/js/help-center/inbox.js` — `HC_EVENT_ICON`, `eventIcon()`, the stream, the
  empty states, and the drawer refresh.

---

## P39 — The Updates tab, as the work item renders it (2026-08-21)

### What changed

Updates were an always-open textarea at the bottom of the tab, with the posted updates appearing
as rows in the shared stream. They are now the work item's Updates tab, control for control:

```
                                        [ + Add update ]
┌──────────────────────────────────────────────────────┐
│ [✓ On Track] [⚠ At Risk] [⊗ Off Track]               │  ← toggles, not a <select>
│ ┌──────────────────────────────────────────────────┐ │
│ │ Add an update…                                   │ │
│ └──────────────────────────────────────────────────┘ │
│                            [ Cancel ] [ Add update ] │
└──────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────┐
│ ⚠ At Risk   1 second ago · David Webby   Edit Delete │
│ Waiting on the billing team before we can answer.    │
└──────────────────────────────────────────────────────┘
```

- **A form that opens**, rather than one always on screen. An always-open textarea invites an
  update nobody meant to write, and it sits between the reader and the updates they came to read.
- **Three toggles, not a dropdown.** The choice is three things and a `<select>` hides two of
  them behind a click. `updateMeta` is the work item's — label, colour *and* icon, because a chip
  that says "at risk" only by being amber says nothing to anyone who cannot see amber.
- **A card each**, with the badge, "time · author", "(edited)", and Edit / Delete — not a feed
  sentence. The same feature with two faces is two features.
- The work item's empty state: "No updates yet / Share the latest status of this ticket."

### Edit and delete, which did not exist

P36 recorded editing as not built. Both endpoints exist now:

- **Edit is the AUTHOR'S only.** An update is somebody's own words about where a ticket stands.
  Anyone who can manage the Space may *delete* one that should not be there, but rewriting what a
  colleague said and leaving their name on it is a different thing entirely. The button only
  appears for the author, and the server refuses anyone else.
- **Delete is soft**, like the work item's — an update somebody wrote and withdrew is still part
  of what happened, and a hard delete would take it out of a timeline that claims to be complete.

### Two things fixed while testing

- **The delete confirmation is inline, not `window.confirm`.** I wrote a native confirm first;
  it blocks the whole renderer — the same trap that froze the wizard's Cancel Setup (P24) — and
  looks nothing like the pb-modal the rest of the module uses. Delete now becomes
  "Delete this update? · Yes, delete · Cancel" on the card itself.
- **A stray "Could not open the Request" toast.** Closing and reopening the same ticket quickly
  left the first detail fetch running, and its result landed against the second open. A row id
  cannot tell those apart; the drawer carries a sequence number now and ignores anything stale.

### Verified

Posted an At Risk update on #000004 — the badge, byline and Edit/Delete rendered as above, and
the tab counts moved to All 2 / Updates 1. Deleted afterwards, so the ticket is back as it was.

### Files

- `app/Http/Controllers/HelpCenter/RequestController.php` — `updateUpdate()`, `destroyUpdate()`,
  the templated endpoints, `raw_id` on the payload.
- `routes/help-center.php` — the two update routes.
- `public/assets/js/help-center/inbox.js` — the tab, `updateMeta`, the form, the inline confirm,
  the fetch sequence guard.

---

## P40 — Reply to Customer, as the work item's comment section (2026-08-21)

### What changed

The Reply tab had its composer at the bottom and rendered messages as feed rows. It is now the
work item's Comments tab: **composer first, then a card per message.**

```
┌──────────────────────────────────────────────────────┐
│ Write a reply to the customer…                       │
└──────────────────────────────────────────────────────┘
This is emailed to the customer.          [ Send reply ]

┌──────────────────────────────────────────────────────┐
│ R  Rohit Philip  CUSTOMER REPLY   2 hours ago        │
│    Thanks — still not working.                       │
└──────────────────────────────────────────────────────┘
```

- **Composer at the top.** A conversation is read from the top and added to at the top; a
  composer under a long thread is a composer nobody scrolls to.
- **A card each** — `rounded-lg border border-line bg-white`, avatar, name, time — the comment
  card's own markup, with the Agent/Customer badge in the header where a comment has none.
- The Send button is right-aligned like the work item's **Comment** button, with the standing
  reminder beside it that this one leaves the building.
- Empty state in the work item's shape: "No replies yet / The customer's first message is above.
  Reply to start the conversation."

### No Edit or Delete on a message

A comment has all three; a reply has none, and that is not an omission. **A reply is an email
that has already been delivered.** Editing one would change our record of what the customer was
sent, which is the opposite of what an edit is for; deleting one would leave a thread the
customer can still read in their inbox and we no longer show. The customer's own messages are
theirs.

### A stray error toast, hardened

"Could not open the Request" appeared once over a drawer that was plainly showing the ticket. The
endpoint is healthy (200 in ~500ms when called directly), and I could not reproduce it on demand
— so rather than guess, the failure path was narrowed: it now reports only when this open is
still the one on screen **and** still waiting. A fetch that fails after something else has
already filled the panel has nobody waiting on it, and an error over a populated drawer is a
message about nothing the reader can see.

Worth watching. If it reappears over an *empty* drawer, that is a real failure and the toast is
doing its job.

### Files

- `public/assets/js/help-center/inbox.js` — the Reply tab, and the `loading` guard.

---

## P41 — The project's rich-text editor in both composers (2026-08-21)

### What changed

Reply to Customer and Updates were plain textareas. They are the application's own editor now —
`<pg-editor>` (Jodit), the same component the work item's description, its comments and its
updates mount.

### It was already on the page

`partials.work-item-assets` includes `partials.rich-editor`, which loads Tribute, Jodit and
`page-editor.js` — the Space Inbox pulls that in for the grid. So `PgEditor` needed registering,
not loading.

**`wi-editor` (the Quill fallback) is not available here**: it is defined *inside* work-items.js,
which this screen does not load. So the composers reach for `pg-editor` directly and fall back to
a plain `<textarea>` when Jodit is absent from a checkout — a component that is not registered
renders as nothing, and a composer that silently disappears is worse than a plain box.

The toolbar is the work item comment composer's, character for character:
`paragraph, fontsize, | bold, italic, underline, strikethrough, | ul, ol, | link, | eraser`. A
reply is a paragraph and a link, not a document, so it gets the short list rather than Jodit's
full set — and it is set for every breakpoint, because Jodit otherwise falls *back* to the full
set as the viewport narrows, growing the toolbar exactly when the room shrinks.

### Rich text means HTML, which means sanitizing

Both composers now send markup, so both endpoints run it through **`RichTextSanitizer`** — the
same class every other rich field in this application uses. Sanitized once on the way IN rather
than escaped on every read; anything the toolbar cannot produce is dropped. An empty body after
stripping tags is refused, so a reply of `<p><br></p>` cannot be sent.

**A reply stores both forms.** `body_html` is what the agent wrote and what the customer is sent;
`body_text` is the same words flattened — the plain-text alternative for mail clients, and what
the timeline reads. A message row holding only markup would make the thread depend on an HTML
parser.

### Only our own markup is rendered as HTML

The detail endpoint serves `body_html` **for outbound messages only**. An agent's reply went
through the sanitizer; an inbound email's `body_html` is a stranger's markup — their stylesheet,
their tracking pixels, their idea of a layout — and it stays flattened to text by
`readableBody()`. The drawer shows what the customer wrote, not how they wrote it.

### Verified

Both composers render the toolbar and the editing area — Reply at 160px, the update form at
120px above its three status toggles. No reply was sent: that would email a real customer.

### Files

- `app/Http/Controllers/HelpCenter/RequestController.php` — sanitising, both body forms,
  `body_html` on outbound messages only.
- `app/Mail/HelpCenterAgentReplyMail.php`, `resources/views/emails/agent-reply.blade.php` — the
  HTML reply, with the plain-text branch for messages stored before the editor existed.
- `app/Services/HelpCenter/RequestQueue.php`, `SpaceController.php` — the editor licence.
- `public/assets/js/help-center/inbox.js` — the component, the toolbar, `wi-rich` rendering.

---

## P42 — Internal Notes (2026-08-21)

## Requirement

A tab for private discussion between the team on a ticket, with @mentions and an email to
whoever is named. Never visible to the customer, never in a customer-facing thread.

Tabs are now: **All / Activity / Reply to Customer / Internal Notes / Updates / Transition /
History**.

## The guarantee is structural

`RequestNoteController` is its own controller rather than four more methods on
`RequestController`, for one reason worth stating plainly: **nothing in that file can send a
customer an email.** No mailer pointed at `customer_email`, no Reply-To, no message row. The only
mail it sends goes to a named colleague, at their own address.

The table says the same thing: `help_center_request_notes` has no recipient column, no message
id, no delivery state. A note could not reach a customer even through a mistake in a controller,
because there is nowhere to put the address.

## Reused, not rebuilt

| Piece | Source |
|---|---|
| Editor + @ autocomplete | `<pg-editor>` with `:mention-url` — the work item's own (P41) |
| Mention parsing | `MentionSync::parse()` — reads `data-user-id` out of the markup |
| Mention email | `MentionedMail`, whole: ticket number → identifier, subject → title, Space → project name, "an internal note" → where |
| Card shape | the work item comment card |
| Sanitising | `RichTextSanitizer`, as every rich field does |

**The mentionable list is the Space's members**, not the workspace's — naming somebody who
cannot open the ticket is naming them at nothing. Members without an account are excluded: no
user to name, no inbox to notify (P29's rule again).

**The markup is presentation; the ids are the record.** `parse()` reads what the editor wrote,
and the controller keeps only the ids that are members of the Space — somebody can send anything.
The panel then renders names from the stored ids rather than from the HTML.

## Mentions are stored on the note, not in `mentions`

The `mentions` table is the right long-term home and is work-item-shaped today:
`mentions.work_item_id` is required, and making it nullable to admit a second kind of source is a
change to a table another feature owns plus a migration over its rows. A JSON list of ids does
what this feature needs — render the names, email the people. The day the notification Inbox
should show a Help Center mention beside a work item one, they become `mentions` rows with a
`source_type` of their own; nothing above the column depends on the difference.

## Rules

- **Editing** is the author's only; **deleting** is anyone who can manage the Space — the same
  split as updates (P39). Deletes are soft.
- **Editing emails only the NEWLY named.** Fixing a typo must not tell everybody again.
- **Never yourself.** The mention is recorded, because the note genuinely says it; the email
  would be absurd (mentions §15).
- **The clock is not touched.** A note to colleagues is not an answer, and moving the waiting
  period would hide a ticket still owed a reply behind one that has merely been discussed.

## Telling internal from customer-facing

Everything private in the drawer is **amber** — an amber ground, a 2px amber left rule, an
`INTERNAL NOTE` badge, and a standing "Internal — never sent to the customer." beside the
composer. Everything the customer will read is not. The same card appears in **All**, so a note
can never render there looking like a reply.

(The left rule is an inline style: `border-l-2` is not in the built stylesheet. Fifth time.)

## Verified

On #000004: the `@` autocomplete offered the Space's member from the live endpoint; the note
posted with the mention chip rendered, an `INTERNAL NOTE` badge, "Notified: David Webby", and the
tab counts moved to All 2 / Internal Notes 1. Deleted afterwards.

**One inaccuracy found and fixed in the process:** the toast said "1 person notified" for a note
that mentioned only its own author — and sent nothing. The count is who was *emailed* now, not
who was named.

## Files

- `database/migrations/2026_08_21_000005_create_help_center_request_notes.php`
- `app/Models/HelpCenterRequestNote.php`
- `app/Http/Controllers/HelpCenter/RequestNoteController.php`
- `app/Models/HelpCenterRequest.php` — `notes()`
- `app/Http/Controllers/HelpCenter/RequestController.php` — notes in the timeline, the endpoints
- `routes/help-center.php`, `public/assets/js/help-center/inbox.js`

---

## P43 — Show more came back (2026-08-21)

### What was wrong

The ticket content's **Show more / Show less** stopped appearing. It was built and verified in
P37; a later edit to the drawer's methods removed `measureOriginal()` while leaving both of its
call sites. `originalOverflows` therefore stayed `false` for ever, and the control is
`v-if="originalOverflows"` — so it simply never rendered.

**It failed silently because of where the calls are.** Both sit inside a promise's `.then`, and a
`TypeError` thrown there rejects the promise rather than reaching the console — so the panel
loaded, the content rendered, and the one thing that did not happen made no noise at all.

### The fix

`measureOriginal()` is restored, with a note on the file saying what it is for. The measurement
is unchanged: `scrollHeight - clientHeight > 4`, taken in the collapsed state because the two
only differ while the clamp applies, re-run after every detail load.

Verified: the message clamps at five lines with **Show more**, expands fully with **Show less**,
and the byline and tabs move with it.

### Files

- `public/assets/js/help-center/inbox.js` — `measureOriginal()`.


---

## P44 — Update customer opens as a dialog (2026-08-21)

### Requirement

> Ticket sender — update contact should open like we have modal window, follow the standard UI.

### What it was

Three bare inputs (`Name`, `Company`, `Phone`) that appeared *inside* the Ticket sender panel,
below the buttons, pushing everything under them down the page. No labels, no title, no sense of
having entered anything — placeholders doing the work of labels, which stop being labels the
moment somebody types.

### What it is now

`pb-modal` — the same dialog Create a Space, Edit Space and Invite a coworker use: a titled
header with its own close, labelled fields, and Cancel / Save changes in the footer.

Two things came with the dialog because it finally had room for them:

- **Email, shown and read-only.** With the ticket now behind a backdrop, "which customer am I
  editing?" is the first question the dialog has to answer. It is deliberately not editable —
  it is the identity every Request was matched on (`CustomerController`), and changing it would
  silently re-point a history at somebody else. The note under the field says so.
- **Customer ID.** The endpoint has always accepted `external_id` and the panel has always
  displayed it; the inline form was the only place it was missing.

Only the four editable fields are sent — `email` is carried in the form for the header alone.

### The stacking problem this exposed

`pb-modal` was hard-coded to `z-[70]`. It is opened here from *inside* the drawer, whose shell is
`z-[85]`, so the dialog rendered **behind the panel that opened it** — present, focusable, and
invisible.

Rather than a second modal shell, `pb-modal` gained a `z` prop defaulting to `z-[70]`, for the
same reason it already had `width`: a dialog opened from a plain page and one opened from inside
a drawer are not on the same layer. The Inbox passes `z-[95]` — above the drawer, below the
toast at `z-[100]`, which has to stay readable over the top of it.

Every other caller is untouched: the default is what the class used to be.

### Verified

Opened from #000004, saved a company, saw it appear in the panel and the toast above the dialog's
layer, reopened with the value seeded, cleared it, saved again. Test value reverted.

### Files

- `public/assets/js/settings/app.js` — `pb-modal` gains the `z` prop.
- `public/assets/js/help-center/inbox.js` — the dialog, `startCustomerEdit()`, `saveCustomer()`.

---

## P45 — Snooze (2026-08-21)

### Requirement

Temporarily remove a ticket from an agent's active queue and bring it back at a chosen time, or
sooner if the customer replies. **Per ticket** — explicitly not a Space-wide or bulk setting.

### Database Fields

Four columns on `help_center_requests`, not a `help_center_request_snoozes` table:

| Column | Meaning |
|---|---|
| `snoozed_until` | when it comes back. NULL means not snoozed, and it is the only thing that means that |
| `snooze_condition` | `if_no_reply` or `regardless` |
| `snoozed_by_id` | who did it (nullable, `nullOnDelete`) |
| `snoozed_at` | when the snooze was *set* — not derivable from `snoozed_until` |

A Request has exactly one snooze: the state it is in now. A table would model a *history* of
snoozes, and this module already has one of those — `help_center_request_activity`, which the
requirement asks these events to appear in anyway. Two records of one event are two records free
to disagree.

### The load-bearing decision: snoozed is a comparison, not a flag

`scopeSnoozed()` is `snoozed_until > now`, never `snoozed_until IS NOT NULL`.

Everything follows from that. A Request whose time has passed is **already back in its queue on
every screen**, before any scheduled job has run. `help-center:unsnooze` (every minute) exists to
write the activity row and clear the columns — not to make the Inbox correct.

A queue that depends on a cron job to be truthful lies every time the cron job misses a beat, and
support software that loses a ticket because a worker was down is support software nobody can
trust twice.

### Where a snooze ends

Four ways, all through `SnoozeManager` so the four columns are cleared in one place:

| Reason | Trigger | Activity row |
|---|---|---|
| `due` | the sweep | "Ticket unsnoozed automatically", actor **System** |
| `manual` | the agent | "Ticket unsnoozed", actor them |
| `customer_reply` | inbound mail, `if_no_reply` only | "Snooze ended — Customer replied", actor **System** |
| `superseded` | closed or marked spam | **none** — "status changed to Closed" already records this act |

`regardless` leaves the snooze alone; the reply is stored exactly as any other reply is, because
nothing stops it. That is the requirement's "still stored and visible when the ticket is
reopened" — satisfied by not implementing anything.

### What Snooze does NOT touch

The **waiting clock**, the **assignee** and the **status**. A snoozed ticket the customer is
still owed a reply on is still owed a reply; parking it is a decision about one agent's queue,
not a statement about who the conversation is waiting on. Resetting the clock would make a
week-old unanswered ticket look freshly handled the moment somebody snoozed it — the one thing an
Inbox sorted by longest wait must never allow (P9).

The requirement's "preserve its existing assignee / preserve its status" are honoured by nothing
in `unsnooze()` writing either column. A snooze was never a change to them, so ending one is not
a change back.

### Natural language, parsed in the browser

`hcParseWhen()` understands `tomorrow`, `tomorrow at 8am`, `3 days`, `in 2 weeks`, `next monday`,
`monday`, `next weekend`, `aug 25`, `25 aug`, `aug 25 at 10:30am`, `8am`, `17:45`, `tonight`,
`90 minutes`. It returns **null** for anything else, and the dialog says so — a parser that
quietly picks a date when it did not understand is how a ticket disappears for a month.

It runs on the **client**, and the resolved instant is what crosses the wire. The browser is the
only party that knows what "8 am" means to the person typing it; sending the words would make the
server guess a timezone, and a ticket that comes back five hours early is worse than one that
never slept. The server validates: readable, future, under a year.

Two bugs found while testing it, both fixed:

- `next weekend` did not parse, though a button of that name sat right above the box. A parser
  that does not understand its own shortcuts' labels looks broken.
- `Aug 21` typed on Aug 21 at 2pm resolved to 8am *next year*. The year-roll compared instants;
  it now compares **dates**. Left in this year it is a past time and the dialog says so — true,
  fixable, and not a ticket that vanishes until next August.

### Timezone: the one place UTC formatting was actively wrong

`config('app.timezone')` is UTC and this app formats dates server-side, so every timestamp on
these screens renders in UTC. For a snooze that stops being a convention and becomes a
contradiction: an agent types **10:30am** and the confirmation says **2:30 PM**.

So the snooze's displayed times are rendered in the browser, from ISO values the payload carries —
the chip's tooltip, the drawer banner, the toast, and the snooze rows in Activity and History
(via `hcLocaliseSnooze`, applied once in the `timeline` computed so no two tabs can disagree).

**This is not a general fix.** Every other timestamp on the screen is still UTC — a real, separate
problem. It was worth correcting here only because this is the one feature where the user supplies
the time themselves and can see it read back wrong.

### UI

- **Row menu**: "Snooze until…" / "Change snooze…" plus "Unsnooze".
- **Drawer toolbar**: a clock button, brand-coloured while snoozed. On the toolbar rather than
  only in the ••• because burying the one action that removes a ticket from your day inside a
  menu makes it something nobody finds.
- **Drawer banner**: "Snoozed until …", the condition, who set it, and Unsnooze. A banner rather
  than a chip because it is not a property in the way status is — it is a statement that this
  ticket is in nobody's queue, which is the first thing a reader needs.
- **Row**: a static "Snoozed" indicator, first on the row. First because it changes what every
  other chip means. Static for the reason the clock is (P31) — it is a state, not a picker.
- **Dialog**: `pb-modal` at `z-[95]` (P44), the free-text box, three quick options each showing
  the date it works out to, and the condition as a radio list rather than a `<select>` — two
  options, each with a sentence that explains it, and a dropdown hides the sentence that matters.

### Queues

Snoozed rows leave Inbox, Unassigned, Mine and Assigned, and appear in a new **Snoozed** view
(nav, counted) at both scopes. Closed and Spam are *not* filtered on snooze — they are archives,
and hiding a row from an archive because of a timer is how a ticket becomes impossible to find.

`RequestViews::COUNT_COLUMNS` was a `const` whose own comment warned that a change to the columns
meant hand-editing both callers' binding lists. This was that change. It is now
`countColumns($userId)`, returning SQL and bindings together — impossible to get wrong rather than
merely documented.

### Acceptance — verified in the browser

Snoozed #000004 for "Aug 25 at 10:30am"; the preview read it back correctly, the row left Mine and
Assigned, the counts moved, Snoozed showed 1 at both scopes, and Activity and History both read
10:30 AM. Unsnoozed it; the row came back and History recorded it. Quick options fill the box and
light up. Set `snoozed_until` to the past and ran the sweep — "Ticket unsnoozed automatically",
actor System. Called `customerReplied()` under both conditions — `if_no_reply` woke it,
`regardless` did not. All test data and test activity rows reverted.

Not exercised end to end: a real inbound email landing on a snoozed ticket (the ingestor hook is
covered only by the direct `customerReplied()` call above).

### Files

- `database/migrations/2026_08_21_100000_add_snooze_to_help_center_requests.php`
- `app/Services/HelpCenter/SnoozeManager.php`, `RequestViews.php`, `RequestQueue.php`
- `app/Http/Controllers/HelpCenter/RequestSnoozeController.php`, `RequestController.php`, `SpaceController.php`
- `app/Console/Commands/UnsnoozeHelpCenterRequests.php`, `routes/console.php`
- `app/Models/HelpCenterRequest.php`, `HelpCenterRequestActivity.php`
- `app/Services/HelpCenter/Inbound/InboundIngestor.php`
- `config/help-center.php`, `routes/help-center.php`
- `public/assets/js/help-center/inbox.js`

---

## P46 — Expand opens the Request's own page (2026-08-21)

### Requirement

> When you click Expand please follow the same UI the way we have for Work Items.

### What Expand used to do

Widen the drawer to the full window. P32 said why: *"The work item's Expand is a LINK to the
item's own page. A Request has no page to open — the drawer is the only detail this module has —
so here it widens the panel instead, which is the part of 'expand' that can be honoured today."*

That was an honest stand-in and it was the wrong control. Expand promises an address: something
you can bookmark, paste to a colleague, middle-click into a new tab. A wider panel is none of
those, and the same icon meaning two different things in two drawers is the thing worth fixing.

### The page

`GET /help-center/spaces/{space}/requests/{request}` renders the **same Vue screen** in page
mode — `pageRequestId` in the bootstrap hides the queue and unwraps the drawer to fill the page.
So the detail there is not a second, thinner copy that would drift; **it is the drawer**, with
every picker, tab, composer, editor and dialog working.

This is exactly how `work-items.show` works, down to the toolbar swap: Close and Expand are
replaced by a breadcrumb back to the queue.

### URLs

`/spaces/{space}/requests/{request}` was the JSON detail endpoint. It is now the page, and the
JSON moved to `.../detail`. The bare URL should belong to the thing a human can hold on to.

**Copy Ticket Link** was producing `…/inbox#request-7` — a fragment nothing read, because there
was nowhere to link to. A link that only works if the recipient is already standing on the right
queue is not a link. It resolves to the real page now.

Not changed: mention emails still deep-link through `spaces.open?request=…&tab=notes`, which
establishes the session and workspace before landing (P10, P26). Pointing those at the page would
skip that.

### Three things that only broke in page mode

- **The teleport.** The drawer lives in `<teleport to="body">` so it can escape the grid's
  stacking context. On the page that lifted the entire detail out to `document.body`, where it
  rendered after the topbar and left the app rail and the Help Center navigation off the screen
  — a page with no way out of it. Fixed with `:disabled="pageMode"` rather than a second copy of
  the markup, so the two framings stay one drawer.
- **The sidebar lit Overview.** `/requests/{id}` carries no `{section}`, so the nav fell back to
  the first one. Same problem Settings had (P11) and the same fix: read the route name. A page
  reached by expanding something in the Inbox should not insist you are somewhere else.
- **Closing.** Escape called `closeDrawer()`, which on a page would leave a blank screen. In page
  mode it navigates back to the queue instead — what the work item page does.

The page carries **no toolbar of its own**, unlike every other screen in the module. The drawer
brings its own `h-14` bar with the breadcrumb in it; a second bar above would be two headings for
one thing. The sidebar-expand control is rendered inside that bar by the Vue template — the
sidebar's listener is delegated on the attribute, which `partials/sidebar-expand` notes is
precisely what lets a Vue-rendered header carry it.

### Verified

Expand from a Space's Inbox → `/spaces/12/requests/6`, full chrome, breadcrumb, Inbox lit. Status
picker, tab switching, the rich-text reply composer and the Snooze dialog all work on the page.
Breadcrumb returns to the Inbox. Expand from the **cross-Space** queue resolved to the row's own
Space (`/spaces/11/requests/4`) and expanded that Space in the sidebar — the templated URL doing
its job.

Not exercised: a very long ticket scrolling the two columns independently.

### Files

- `routes/help-center.php` — the page route; the JSON detail moved to `/detail`.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — `request()`, and `inboxPayload()` now
  takes an optional page Request so the page fetches one row rather than the whole queue.
- `app/Services/HelpCenter/HelpCenterNavigation.php` — Inbox lit on a Request page.
- `app/Services/HelpCenter/RequestQueue.php` — the `page` endpoint template.
- `resources/views/help-center/request.blade.php` — the host.
- `public/assets/js/help-center/inbox.js` — `pageMode`, the toolbar swap, `requestUrl()`,
  `queueUrl`, `copyLink()`, the teleport guard.

---

## P47 — Mark as Spam (2026-08-21)

### What already existed

`is_spam`, the `spam` view and its `/spaces/{space}/spam` URL, exclusion from the active queues,
the clock stopping, and snooze cancellation (P45). This entry is mostly about the gaps — and
three of them were defects, not missing features.

### Added

- **A confirmation.** `pb-confirm` at `z-[95]`, naming the ticket and saying what happens: it
  leaves every active queue, its history is kept, and nothing further happens until it is
  restored. Only *marking* asks. Restoring does not — it is the recovery action, and asking
  somebody to confirm undoing a mistake is a second chance to get the mistake wrong.
- **Spam in the navigation**, immediately after Snoozed, at both scopes. P21 deliberately kept it
  out ("it is where things go to be ignored; a permanent nav entry for it invites reading"). The
  requirement overrides that, which is the product owner's call. Still **not counted** — that
  half of P21's reasoning stands: a badge on Spam is an unread count for mail nobody should read.
- **The Spam button removed** from the Inbox toolbar, now that the sidebar links the same screen.
- **"Not Spam — Restore Ticket"**, replacing the bare "Not spam" toggle. Two menu items rather
  than one control that sometimes opens a dialog and sometimes acts.

`Snooze until…` is absent from a spam ticket's menu — part of "stop other ticket actions".

### Defect 1 — a reply undid the marking

`InboundIngestor` did not check `is_spam` on an existing Request. A customer reply cleared
`closed_at` and reset `waiting_since` to now, which put a ticket somebody had explicitly thrown
away **at the top of an Inbox sorted by longest wait** — the one place it must never be. Spam is
exactly the category of mail that replies to itself, so it came back every time.

The message is still stored (the requirement asks that customer messages be kept for audit);
everything after it is skipped.

Auto-assignment on a status change is now also skipped for spam — the module's only remaining
automation that could hand somebody a discarded ticket.

### Defect 2 — spam and snoozed still showed in the Space's Inbox

The Space Inbox is built by `SpaceController::inboxPayload()`, which fetches **every** row for
the Space and never filtered on `is_spam`. So the screen an agent actually opens kept showing
tickets that every queue view had removed.

The snooze half of this is a **P45 defect**. That requirement said "remove the ticket from the
normal active Inbox view", and it was only honoured for the six views built by
`RequestViews::apply()` — never for this screen. Both are excluded now.

Closed is deliberately still shown: this screen groups by workflow status and closed is where a
Request *ends* in that workflow. Spam and snoozed are not end states, they are removals, and both
have a view of their own.

On a Request's own page neither filter applies — `whereKey` has already narrowed to one row, and
a page that refused to render the ticket its URL names would be a dead link (P46).

### Defect 3 — restoring buried the ticket

`'waiting_since' => $model->waiting_since` looked like it left the clock alone on the way back,
and did — at **null**, because marking had just cleared it. A restored ticket returned reading
"—" (nobody waiting) and sorted to the very bottom of the Inbox. The recovery action quietly
buried the thing it recovered.

The original value is gone, so it is recomputed rather than remembered: the wait belongs to
whoever the current status says owes the next move, running since the last message on the thread.
That is what `moveTo()` does, and it beats `now()` — a ticket wrongly marked yesterday was owed an
answer yesterday, not from the moment somebody noticed.

### One rule for when a row leaves the list

`change()` and `applySnoozed()` each carried their own copy of "does this row still belong on this
screen?", which is how the second came to be missing a case the first had. Both now call
`leaves(row)`.

### Verified

Marked #000005 from a Space Inbox: confirmation named the ticket, the row left the list
immediately, Mine and Assigned dropped, the clock stopped, and it appeared in
`/spaces/12/spam` in the standard layout. Confirmed gone from the Inbox after a reload. Restored
it from the Spam list: it returned to the Inbox with **18 h 20 m** back on its clock, re-sorted
between #000004 and #000006 — the fix repairing the row an earlier round trip had flattened.

Not exercised end to end: a real inbound email arriving on a spam ticket. The guard is verified by
reading the path (message stored, then early return before the reopen block), not by delivering
one.

All test data and test activity rows reverted.

### Files

- `app/Services/HelpCenter/Inbound/InboundIngestor.php` — no further action on spam.
- `app/Http/Controllers/HelpCenter/RequestController.php` — clock restored; no auto-assign on spam.
- `app/Http/Controllers/HelpCenter/SpaceController.php` — Space Inbox excludes spam and snoozed.
- `config/help-center.php` — Spam in the nav after Snoozed.
- `resources/views/help-center/inbox.blade.php` — the redundant Spam button removed.
- `public/assets/js/settings/app.js` — `pb-confirm` passes `z` through.
- `public/assets/js/help-center/inbox.js` — the dialog, `askSpam`/`confirmSpam`/`restoreFromSpam`,
  `leaves()`.

---

## P48 — Email Templates & Agent Signature (2026-08-22)

### Space → Settings → Email Template

One settings page with four tabs: the three template types, then Agent Signature. One nav entry
rather than two, because the requirement nests the signature underneath the template — and a
signature is only ever *seen* through a template, so editing them on separate screens would mean
editing a thing and its content in two places.

### The decision everything else falls out of: a missing row means the default

`help_center_email_templates` holds **overrides**. A Space with no row uses
`config('help-center.email_templates')`. Three things follow for free:

- a brand-new Space sends sensible mail with nothing configured and nothing seeded,
- **Restore Default Template is a DELETE**, not a copy of the default text into the row,
- improving a default improves every Space that never overrode it.

Seeding three rows per Space at creation would have frozen today's wording into every Space ever
made, and left "restore" meaning "restore to whatever we shipped the day this Space was created".

### One table for both kinds of signature

`help_center_signatures.user_id` is null for the **Space default** and set for an **agent's own**.

The requirement describes these in two sections, and two tables is the obvious reading — it would
also have meant two schemas, two forms and two save paths for something the product then asks to
resolve as a single ordered lookup:

```
Agent Signature → Space Default Signature → No Signature
```

As one table that is one query and a sort. As two it is a join that exists only because of how
the requirement was paragraphed.

"Has a signature" means **has one with something in it**. An agent who enables a signature and
leaves every field blank falls through to the Space default rather than stopping the search at a
row that renders nothing — which is what "no signature configured" means in practice.

Name and title are **stored**, not read off the user account: "Dave" in the account and
"David Webby, Customer Success" at the bottom of a support email are both correct, and deriving
one from the other would make the second impossible. Scoped to the Space, so somebody working
Billing and Customer Support can sign as two different things.

### Substitution, and what is escaped

`EmailTemplateRenderer::render()` replaces `{{tag}}`. Values are **escaped** unless config marks
the variable `html` — and every entry on that short list is markup this application sanitized on
the way in: the agent's reply (P41), the signature, and the rendered inner email. Everything else
came from an email header or a column a stranger chose. A customer whose display name is
`<script>` is a customer, not a script. Verified.

An **unknown tag is left exactly as written**, not blanked. Somebody who typos `{{ticket_num}}`
should see it in the preview and fix it; silently emptying it sends a customer an email with a
hole in it and no clue where the hole came from.

### Two saves that are refused

An agent reply without `{{reply_content}}` sends the customer a wrapper and none of the answer.
A layout without `{{email_content}}` **replaces** every email instead of framing it. Both look
perfectly saveable and their damage is invisible until a customer receives one, so the endpoint
refuses them by name.

### Enabled / Disabled

`can_disable` lives in config. The auto-response may be switched off — "a customer can still
create a ticket, but the automatic acknowledgment email will not be sent", checked in
`TicketConfirmer::refusal()` so the reason lands in the same log line as every other reason that
email did not go out. The agent reply "should remain available whenever an agent replies", so
`resolve()` reports it enabled whatever the column says, rather than four send sites each
remembering a special case. Verified both ways.

### Preview and Test

Both render **what is in the editor**, not what is saved — the requirement asks to preview
*before* saving, and previewing the stored row answers a question nobody asked. Both apply the
Space's layout too, so an administrator sees the composed email rather than a fragment.

Sample values are obviously fictional ("Sample Customer"), never the Space's most recent real
ticket: an administrator checking a template should not be shown a customer's actual details.
The **signature is real**, resolved for the person previewing — it is the one value where "what
will this look like?" is genuinely about their own configuration.

Test email goes to **the signed-in user only**, never an address they type. A settings form that
mails anywhere somebody enters is an open relay with a nice interface. Throttled, because a
button that sends mail is a button somebody will hold down.

### Three bugs found while building this

- **`{{ '{{' + v.tag + '}}' }}` will not compile.** Vue ends the interpolation at the first `}}`,
  and the failure is a bare *"missing ) after argument list"* from the template compiler that
  points nowhere near the cause — the whole settings screen rendered blank. Now a `tagToken()`
  method.
- **`v-for` + `v-if` on one `<option>`.** In Vue 3 `v-if` evaluates first, so the loop variable
  does not exist yet. Replaced with a `signatureAgents` computed.
- **`this.endpoints` was never populated.** The screen already had `endpoint` (singular) for the
  one settings PATCH; this panel has six of its own. Preview fired no request at all and failed
  silently. The near-identical names are a hazard worth naming, and the comment in `data()` names
  it.

Also: three methods were used without parentheses in the template. A method read as a property
yields the *function*, so `v-for` over one iterates nothing and `v-if` on one is always true —
silent in both cases. They are computed now.

### Acceptance — verified

Rendered an agent reply end to end: subject, layout header, greeting, `{{reply_content}}`, the
agent's signature, ticket reference, footer. Confirmed the priority chain — an agent with their
own gets it, an agent without gets the Space default, and an agent whose own is *disabled* falls
through to the default. Confirmed escaping, unknown tags, the disable semantics for both types,
and Preview in the browser.

Not exercised end to end: a real inbound email triggering the auto-response, and a real Send Test
Email delivery (`QUEUE_CONNECTION=sync` with live SMTP — the send path is the same one the
verified reply render uses).

A signature for David Webby (Customer Success · ProjectBlock) is left in place — it is the
signed-in user's own, created through the UI, and is a working example of the feature rather than
scratch data. All template overrides and the test Space default signature were removed, so both
Spaces are back on the packaged defaults.

### Files

- `database/migrations/2026_08_22_100000_create_help_center_email_templates.php`
- `database/migrations/2026_08_22_100001_create_help_center_signatures.php`
- `app/Models/HelpCenterEmailTemplate.php`, `HelpCenterSignature.php`
- `app/Services/HelpCenter/EmailTemplateRenderer.php`, `SignatureResolver.php`
- `app/Http/Controllers/HelpCenter/EmailTemplateController.php`, `SpaceSettingsController.php`
- `app/Mail/HelpCenterAgentReplyMail.php`, `HelpCenterTicketConfirmationMail.php`
- `app/Services/HelpCenter/Inbound/TicketConfirmer.php`, `Http/Controllers/HelpCenter/RequestController.php`
- `resources/views/emails/ticket-template.blade.php`, `help-center/space-settings.blade.php`
- `config/help-center.php`, `routes/help-center.php`
- `public/assets/js/help-center/space-settings.js`

---

## P49 — Email Template is a list; editing opens a drawer (2026-08-22)

### Requirement

> This would be list view — when you click on edit we open a drawer for the user to edit it.

### Why the tabs were wrong

P48 shipped this page as four tabs. Tabs show one editor at a time and **none of them at rest**,
so the page could not answer the question an administrator actually arrives with: *which of these
is switched on, and which have we changed?* You had to open all four to find out.

### The list

One row per template, carrying the three facts that were previously invisible:

- the name,
- **Enabled / Disabled** — only where the type can be disabled, because a badge that can never say
  anything else is noise (the Agent Reply has none),
- **Default / Customised** — the absence of a row *is* the default (P48), so this is the only
  place a Space's divergence is visible at a glance.

Signatures follow in their own list: the Space default first, because it is the one that applies
to everyone who has not set their own, then the agents — each showing **Active**, **Off** or
**Not set**. Three states, not two: "never written" and "exists but switched off" are different
situations with different fixes, and collapsing them into "inactive" hides which one you have.

The agent rows come from the already permission-filtered `signatureAgents`, so an agent who
cannot manage the Space sees only themselves. It is never a list of rows that would 403 on
opening.

### The drawer

The module's own slide-over, class for class — `fixed inset-0 z-[85]`, a 20%-black backdrop, a
panel pinned right — so it does not read as a different mechanism from the Request drawer.
Capped narrower (736px) because this is one form, not a ticket with a properties rail beside it.

Two things it fixed as a side effect of having more room:

- **The footer is pinned**, not scrolled with the form. On the old page Save sat below the fold
  every time somebody opened a template.
- **Preview renders inside the drawer**, under the fields it came from, so the input and its
  output are on screen together.

Closing drops the working copies. A draft that survived would reappear next time the drawer
opened, showing edits somebody had already walked away from as though they were saved.

`:key` on the editor is load-bearing: Jodit does not swap its document when the model changes
underneath it, so opening a second template into a live editor would show the first one's body.

### One thing worth recording about the width

`sm:w-[46rem]` does nothing — the built stylesheet contains only the arbitrary values Tailwind
found while scanning its sources, and `public/assets/js/help-center/*.js` is not one of them.
This is the sixth time that has bitten this module (P24, P28, P31, P34, P44, now this). The
drawer uses `sm:w-[80%]` (which exists, because the Request drawer is scanned) plus an inline
`max-width`.

### Verified

Opened each template from the list; saved the auto-response and watched the row change to
**Customised** with **Restore Default** appearing in the footer; restored it and watched both
revert. Preview rendered inside the drawer with the layout wrapped around it. The signature
drawer opened with the saved values and its own preview. Close returns to the list.

No test data left: both Spaces are on the packaged defaults, and the only signature is David
Webby's own.

### Files

- `public/assets/js/help-center/space-settings.js` — the list, the drawer, `closeEmailDrawer()`,
  `signatureRow()`, `signatureStatus()`.

---

## P50 — Space Overview reporting dashboard (2026-08-22)

### Scope

The requirement's own **§24 Phase 1**, complete: KPI summary (total, open, unassigned, closed,
avg first response, avg resolution), ticket volume, status breakdown, agent performance, current
workload, priority breakdown, ticket aging, longest waiting, tag reporting, the five filters, and
drill-down to filtered ticket lists. Plus both empty states (§19/§20) and the interactive
behaviour (§17/§18), which Phase 1 needs to work at all.

**Deferred, per §24's own Phase 2 list:** SLA, CSAT, customer activity reporting, channel
comparison, agent trend comparison, exports, scheduled reports, saved views. Also deferred:
`Avg. Response`, `Avg. Replies to Resolution`, `Reopen Rate`, `Reopened Tickets` and
`Customer Replies` — §7 and §4 describe them, §24 does not list them for Phase 1, and each needs
event-pair analysis the aggregation below is not shaped for yet.

### Period metrics vs current-state metrics

The requirement is careful about this and so is the service. A metric about what **happened**
(created, closed, first response, resolution, volume, status, priority, tags) is bounded by the
date range. A metric about what **is** (open, unassigned, workload, aging, longest waiting)
ignores the range entirely — asking "how many are open?" of last month is a question with no
answer. Both honour the other four filters.

The KPI cards label the two current-state ones "· now" rather than leaving somebody to work out
why they did not move when the range did.

### How it reads the data, and what that costs

Four queries, then arithmetic in PHP: the period's rows (narrow column set), the current open
rows, the first agent reply per Request, and tags plus reply counts.

Deliberately **not** a dozen aggregate queries, and deliberately not `DATE_FORMAT`/`TIMESTAMPDIFF`
grouping in SQL — that is MySQL's spelling, and CLAUDE.md §6 asks this codebase to stay portable.

**The honest limit:** this loads the period's rows into memory. At today's scale that is a few
hundred rows. §23 asks for aggregated tables, cached statistics and background aggregation for
large Spaces — that is the Phase 2 answer, and `SpaceReport` is written so swapping the four reads
for pre-aggregated ones changes nothing above them. This is a stated tradeoff, not an oversight.

### First response excludes automation, structurally

`firstAgentReplies()` reads **outbound messages only**, which in this module means an agent's
reply and nothing else: the auto-response (P27) sends mail without storing a message row, and
internal notes and updates live in their own tables. The requirement's "customer replies,
automated emails and system updates must not count" is satisfied by what the table contains
rather than by a filter somebody has to remember.

### Two approximations, named

- **"Closed" and "assigned" are attributed by assignee.** This module records who a Request is
  assigned to, not who pressed close — so a ticket closed by a colleague covering a shift counts
  to the person it was assigned to. The activity log has the actor and could answer it exactly;
  that is a Phase 2 change, and a number that looks precise and is not would be worse.
- **"Replied" is matched on `from_email`,** because `help_center_messages` has no author column.
  An agent whose account email later changes stops matching their older replies. Recording an
  `author_id` on the message is the permanent fix.

`Reopened` is absent rather than approximated: `closed_at` is cleared on reopen, so a
reopened-then-closed ticket cannot be told from one closed once without reading the activity log.

### Nulls, not zeros

`Avg. First Response` of **0 min** reads as instant service; it actually means nothing in the
window has been answered. Both averages return null when there is nothing to average and the
screen prints an em dash with a count beneath it ("0 answered", "0 closed").

### Drill-down

Every clickable number links to `/spaces/{id}/inbox?assignee[]=…&status[]=…&priority[]=…&tag[]=…`,
and the Space Inbox now reads those. The vocabulary is deliberately identical to `ReportFilters` —
`assignee=0` is unassigned in both — so a dashboard link and a hand-edited address behave the same.

The **date range is deliberately dropped** on the way to the Inbox: it has no reporting window, and
carrying `range=last_30` into it would put a parameter in the URL that nothing reads, which looks
like it did something.

Filter changes refetch through one JSON endpoint and rewrite the address with `replaceState` —
no reload, and reloading the page gives you back the dashboard you were looking at.

### Three bugs, all the same bug

`w-1/2`, `rounded-t-sm`, `min-w-[14px]`, `h-[140px]`, `w-[140px]` and `-rotate-90` are none of them
in the built stylesheet. The chart rendered thirty bars of **zero width** — data right, numbers
right, chart blank — and the donut, unsized, expanded to fill its row and squeezed the legend
beside it to nothing.

That is the **seventh** time this has bitten this module. The geometry is inline style now. The
real fix is adding `public/assets/js/**/*.js` to Tailwind's content globs; it is offered rather
than done, because it changes the build for every screen at once and deserves its own change.

### Verified

Both Spaces with tickets show their own numbers and nothing of each other's. Volume, status donut,
priority, agent table, workload, aging, longest waiting (ordered longest-first) and tags all
cross-check against the three tickets in each. Clicking the Billing tag opened the Inbox at
`?tag[]=7` showing exactly the two Billing tickets. Selecting a status filtered live, updated the
URL and showed Clear Filters; clearing reset it. Range "Today" produced "No reporting data for
this period" with the current-state KPIs still populated. An empty Space shows "Your support
reports will appear here" with the Go to Inbox action.

Not exercised: hour/week/month groupings against real data spanning those ranges, and the custom
date range.

### Files

- `app/Services/HelpCenter/Reporting/ReportFilters.php`, `SpaceReport.php`
- `app/Http/Controllers/HelpCenter/SpaceController.php` — `overviewData()`, `reportOptions()`,
  `applyInboxFilters()`
- `routes/help-center.php`, `resources/views/help-center/space.blade.php`
- `public/assets/js/help-center/overview.js`

---

## P51 — Space Configuration moves into a dialog (2026-08-22)

### Requirement

> Next to Refresh please add a button called Space Configuration — when you click on that we open
> a modal window and show the Space configuration, and remove the Space configuration from the
> overview page.

### What changed

The configuration table that sat below the dashboard is now a `pb-modal` behind a **Space
Configuration** button beside Refresh.

It was reference material — the lead, the type, the workflow, the inbound address — parked
underneath eight panels of numbers, where it was neither findable when you wanted it nor out of
the way when you did not. A dialog is the right shape for something you check occasionally and
close.

### Two things deliberately stayed on the page

The **"No customer-facing email address"** notice and the **inbound test** card. They are not
configuration; they are things to *act* on — one is a fault to fix, the other a test to run.
Moving them behind a button somebody has to think to press would be hiding the two items on this
page that ask for a decision.

### The rows are built server-side

`SpaceController::configurationRows()` returns label/value pairs. The dialog renders whatever it
is handed, so adding a row is one change in one place — and a Vue dialog that had to know about
`groupList()`, `typeLabel()` and the Inbox relation would be a second place that knows how a Space
describes itself.

Unset values render as an em dash rather than an empty cell: a blank next to "Space Lead" reads as
a rendering fault, where "—" reads as "nobody yet", which is the truth. The inbound address
carries a `break` flag, because it is one long unbreakable token that would otherwise push the
dialog wider than the viewport.

The dialog is **read-only**. Every value in it is edited somewhere that owns it — Edit Space,
Settings › Workflow, Settings › Inbox — and duplicating the editing here would be a second place
to change them and a second place for them to disagree.

### Verified

The button sits beside Refresh; the dialog opens with all eight rows and the inbound address
wrapping correctly; Close dismisses it. The page below now ends with Tags followed by the inbound
test, with no configuration table.

### Files

- `app/Http/Controllers/HelpCenter/SpaceController.php` — `configurationRows()`.
- `public/assets/js/help-center/overview.js` — the button and the dialog.
- `resources/views/help-center/space.blade.php` — the table removed.

---

## P52 — Space configuration and Delete Space on Settings › Inbox (2026-08-22)

### Requirement

> Show the Space configuration information in this screen. Plus option to delete the Space +
> modal to confirm on the deletion.

### Configuration

The same rows the Overview's dialog shows (P51), from the same builder — `configurationRows()` is
public and static now, because two screens render it and a Space described two ways is a Space
somebody will eventually act on wrongly.

Settings › Inbox is a reasonable home for it: it is already where somebody comes to answer "how is
this Space set up?" — the inbound address is the first thing they check (P8), and the lead, the
type and the workflow are the rest of the same question.

Read-only here too, for the reason it is read-only on the Overview: every value is edited
somewhere that owns it, and a second place to change them is a second place for them to disagree.

### Delete

Last on the page and inside a danger-bordered block, which is where a destructive action belongs —
findable on purpose, never on the way to something else.

**`canDelete` is read from the policy separately from `canManage`.** `update` is enough to rename a
Space or change its workflow; deleting it takes the conversations, the workflow and the inbound
address with it. The policy already draws that line, and reusing `$canManage` here would have
quietly widened who can do it.

The confirmation is **not** `pb-confirm`. That component is a yes/no, and this needs the Space name
typed — the bar `SpaceController::destroy()` already sets, because a confirm dialog is one careless
click and typing the name is a decision. The name is checked client-side too, so the button is
disabled rather than the request refused: a form whose only feedback is a 422 after you press it is
a form that teaches you the rule by failing at you.

On success it **navigates to the Spaces listing** rather than toasting in place — the Space this
screen is about no longer exists, and staying would leave every control on it pointing at a 404.

This deliberately mirrors the delete already on the Spaces listing (`spaces.js`): same endpoint,
same typed-name bar, same wording. Two entry points, one contract.

### Verified

Both sections render below the Inbox addresses. The dialog names the Space, Delete stays disabled
for a near-miss (`e4t345`) and enables on the exact name.

**Not executed: an actual deletion.** The only Spaces available to test on are the ones this work
has been built and verified against, and the empty ones are still the user's data. The endpoint
itself is pre-existing and already driven by the identical flow on the Spaces listing, so what is
new here is the second caller rather than the deletion path — but I have not watched a Space
deleted from this button.

### Files

- `app/Http/Controllers/HelpCenter/SpaceController.php` — `configurationRows()` made public/static.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — the inbox payload.
- `public/assets/js/help-center/space-settings.js` — the panel, the danger block, the dialog.

---

## P53 — Workflow / System Category tabs (2026-08-22)

### Requirement

> Add two tabs — Workflow and System Category. ProjectBlock should provide the following fixed
> System Categories: Open, Active, Waiting, Resolved, Closed. System Categories are controlled by
> ProjectBlock and cannot be renamed, deleted, or customized.

### The five live in config, not in a table

`config('help-center.system_categories')`. That is the honest expression of "ProjectBlock controls
these": a table would be a thing with an id, a tenant and — eventually — an update endpoint, which
is to say a thing somebody would one day be able to rename. There is nothing to write to.

The panel is read-only for the same reason, and says so twice: a lock beside each name (the same
marker a protected status carries in the table on the other tab, so "you cannot change this" reads
the same way in both places) and a **System** badge on the row.

### What they are, and what they are not

A **vocabulary**, not a workflow. A Space's workflow is its own — `Open → Review → Inprogress →
Completed - In Review → Closed` on this Space, something else entirely on the next — and these five
are the shared meanings a state can carry so reporting and automation have something stable to
reason about across Spaces that name their states differently.

Each carries a one-line description, because a list of five words with no explanation is a list
nobody can act on. "Resolved" and "Closed" in particular are the pair people ask about.

### Not done, and deliberately

**Statuses are not mapped to categories.** Nothing on `help_center_statuses` says which category a
status belongs to. The requirement asked for the tabs and the fixed list; a mapping is a schema
change that would touch the workflow editor, the setup wizard, the reporting service and the
Inbox, and inventing it here would be building past the ask.

What exists today is adjacent but not the same thing: `system_key` (open/closed, which protects
the two ends of a workflow) and `waiting_on` (agent/customer/nobody, which drives the waiting
clock). Neither is a category, and pretending either was would give the mapping wrong answers for
Active and Resolved.

### Verified

Both tabs render on Settings › Workflow; the Workflow tab is unchanged; System Category lists all
five with their colours, locks, descriptions and System badges.

### Files

- `config/help-center.php` — `system_categories`.
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php` — the workflow payload.
- `public/assets/js/help-center/space-settings.js` — the tabs and the panel.

---

## P54 — System Category on every status card (2026-08-22)

### Requirement

> We need to add field called System Category by default in each card.

This is the mapping P53 flagged as deliberately absent. Every workflow status now belongs to one
of the five fixed categories.

### Why it matters

It is what lets two Spaces run entirely different workflows and still be reported on together:
one Space's `Inprogress` and another's `With Engineering` are both `active`, and a query that
wants "everything being worked on" finally has something stable to ask for. Without it,
cross-Space reporting can only group by status *name*, which is to say it cannot group at all.

### NOT NULL with a default

A status without a category would be a row every consumer has to write a fallback for, and five
fallbacks in five files is how the sixth gets forgotten. New statuses start as `active` — the
same value the server falls back to, so a card somebody never touches saves as what it displayed.

### The backfill is derived, not uniform

Making everything `active` would have made the mapping wrong on its first day, which is worse than
not having one: a wrong category is a report that is confidently incorrect. Each existing status
was read for what it already says about itself, strongest signal first:

| Evidence | Category |
|---|---|
| `system_key = closed` | closed |
| `system_key = open` | open |
| waiting on the customer | waiting |
| waiting on nobody | resolved |
| everything else | active |

On this workspace that produced `Open → open`, `Review/Inprogress/Completed - In Review → active`,
`Closed → closed` — which is right.

### Open and Closed are pinned, server-side

`WorkflowStatusPayload::normalize()` forces them to `open` and `closed` whatever the client sends,
the same treatment the two system *names* already get. Filing Closed under "Waiting" would break
every consumer that trusts the vocabulary. An unrecognised value on any other row falls back to
`active` rather than being rejected — the safest wrong answer, since "an agent owes something" is
what an unclassified support state almost always means.

The card shows this rule rather than enforcing a second copy of it: Open and Closed render their
category as locked text with the same lock icon their names carry.

Verified by calling `normalize()` directly with `open`/`closed` mis-filed as `waiting` and a third
row set to `nonsense` — stored as `open`, `closed`, `active`.

### Where it appears

- **The editor card**, beside Waiting — "what does this state mean" and "whose clock runs in it"
  are neighbouring questions, and reading them together is how somebody checks a status they
  invented is filed sensibly.
- **The read-only workflow table**, as a coloured dot and label.
- **The setup wizard** stores it too, so a Space is categorised from creation rather than from its
  first edit. The wizard's cards do not *render* the field (they are the four-field variant), so
  new Spaces take the `active` / pinned defaults.

One vocabulary throughout: the picker's options, the System Category tab and the stored values all
read `config('help-center.system_categories')`.

### Verified

Editor shows the field on every card, locked on Open. Changed `Completed - In Review` to
**Resolved**, saved, and the read-only table and the database both persisted it. Reverted.
Setup wizard loads clean.

### Note

`Completed - In Review` is currently categorised **active** by the backfill, because it waits on an
agent. Its name suggests **resolved** — worth setting deliberately, but it is a product decision
rather than something to infer from a status name, so it was left as the backfill found it.

### Files

- `database/migrations/2026_08_22_200000_add_system_category_to_help_center_statuses.php`
- `app/Models/HelpCenterStatus.php`, `app/Services/HelpCenter/WorkflowStatusPayload.php`,
  `WorkflowUpdater.php`, `SetupCommitter.php`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php`
- `public/assets/js/help-center/status-card.js`, `space-settings.js`

---

## P55 — The Settings sidebar runs full height (2026-08-22)

### Requirement

> The height of menu should be 100% height.

### What was wrong

The Settings sidebar is `self-stretch` inside a `flex items-start` row, so it stretched to the
row's height — and the row's height was whatever the *selected page* happened to contain. On a
short page like Workflow the nav stopped a third of the way down and its right border stopped with
it, leaving the column looking truncated rather than like a sidebar.

### Why not a calc

`min-height: calc(100vh - …)` was the obvious fix and it is the wrong one. The number would have
to be the topbar plus the Space toolbar plus the Space tab bar — and that last one is
`flex-wrap`, with nine tabs on it. It already wraps to two lines at narrower widths, and a magic
number would be silently wrong exactly then.

### The structural fix

The layout's `<main>` becomes `flex flex-col`, and the Settings row asks for the leftover with
`flex-1 min-h-0`. `<main>` already sits inside a bounded `h-screen` column, so "the leftover" is a
real measurement rather than a guess, and it stays correct however many lines the tab bar takes.

`items-start` stays on the row so the page content is still top-aligned; only the aside opts out,
with the `self-stretch` it already had.

### The shared-layout risk, and why it is small

`<main>` is every Help Center screen's container, so this is a change to all of them. It costs
them nothing: a column of blocks stacks identically whether or not its parent is a flex container,
and only a child that explicitly asks for `flex-1` behaves differently — which, apart from this
row, none of them do.

Checked rather than assumed. Space Settings (Workflow and Members, the two different panel kinds),
the Space Inbox grid, the Space Overview dashboard, a Request page (which sets its own `100vh`)
and the Spaces listing all render unchanged.

### Files

- `resources/views/help-center/layout.blade.php`
- `resources/views/help-center/space-settings.blade.php`

---

## P56 — Customer Satisfaction Rating (2026-08-22)

### Scope, stated plainly

The requirement is 31 sections spanning settings, a public customer-facing flow, scheduling,
notifications and reporting. Unlike P50 it names no phases, so this is my scoping and it is worth
being explicit about.

**Built — the complete working loop.** A ticket reaches its trigger → a request is raised → the
Space's own email goes out after the configured delay → the customer opens a tokenised page →
picks a score, optionally writes → it is stored, normalised, recorded in the timeline, and low
scores set off their configured actions.

That covers §1–§18, §20–§23 and §30.

**Deferred, and why:**

| Section | Why not now |
|---|---|
| §19 conditional eligibility | A rules engine (channel/priority/tag/customer conditions) is its own feature. Today: all tickets reaching the trigger, minus the §20 exclusions. |
| §24 notification recipients | The low-rating switches store `notify agent` / `notify Space lead`, and **nothing sends those emails yet**. Followers and selected users need a subscription model this module does not have. |
| §25–§27 reporting | P50 already deferred CSAT to its own Phase 2. The data is now there to build it on. |
| §29 audit history | Settings changes are not versioned anywhere in this module; doing it for one page would be an island. |
| §12 "trigger automation" | There is no automation engine to trigger. |

The two switches that store a setting nothing acts on yet are the one place this is thinner than
it looks, and they are named above rather than left to be discovered.

### The request and the response are one row

`help_center_ratings` holds both. A request nobody answered is that row with a null `score`, which
is exactly what the response-rate metric needs to count. Two tables would mean a LEFT JOIN to
answer "how many did we ask?", and a rating that could exist without a request it answers.

### Every scale stores a score out of five

`thumbs` maps to **1 and 5**, not 1 and 2 — a thumbs-down is as negative as its scale goes, and
scoring it 2 would make a Space that uses thumbs look permanently mediocre beside one that uses
stars. `scale10` maps two points per point. The raw answer is kept alongside, because 7-out-of-10
is not 4-out-of-5 and throwing it away would make the customer's actual answer unrecoverable.

### The public page is the security surface

The only unauthenticated, un-tenanted screen in this module.

- **It never shows the ticket.** Not the subject, not the thread, not the agent. The token arrived
  by email at an address we do not control, and a leaked one must cost the customer nothing but a
  stray star.
- **It never confirms a token existed.** A wrong token and a withdrawn one land on the same page —
  "that link is not one of ours" is an oracle somebody can enumerate against.
- **48 random characters**, unique, length-checked before the query even runs. `withoutGlobalScopes`
  is safe here *only* because the lookup key is unguessable; that is stated in the controller.
- `noindex`, and throttled at 30/min.

### Off by default

Emailing every customer of every existing Space the moment this shipped would be the most damaging
default in the module. Turning it on is a decision.

### Two bugs found while building

- **`ratingPoints` used as a property but written as a method** — `v-for="i in ratingPoints"`
  iterated a function, so the customer preview drew a heading and a button and no rating at all.
  The third time this exact shape has bitten (P48, P50); it is a computed now.
- **An unsaved model has none of the migration's defaults.** `for()` returns `new self(...)` when a
  Space has never configured rating, and every boolean read null → false — so "Allow customer
  comment" arrived switched *off* on a page whose stored default is *on*. Fixed with `$attributes`
  on the model, which restates the migration's defaults. That duplication is a real cost, and a
  settings screen that lies about its own defaults until you press Save is a worse one.

Also caught before it shipped: the panel payload is built as `$common + [...]`, where PHP keeps the
**left** operand's keys — naming the save URL `endpoint` would have been silently dropped and Save
would have written to the shared settings route. It is `ratingEndpoint`. Same near-miss as P48's
`endpoint`/`endpoints`.

### Verified end to end

Settings page renders, saves, and the live preview reacts — picking "Very Dissatisfied" flips the
comment from *(optional)* to **required**, which is the "required for low ratings only" rule
demonstrating itself. Raised a request through `RatingManager` (second ask correctly refused —
§16). Submitted a 2/5 with a comment: score normalised, `★★☆☆☆` rendered, activity recorded as
*"System recorded a customer rating of 2/5 with written feedback"*, and the auto internal note
written with rating, customer, agent, date and the feedback (§13). Opened a real tokenised page as
a customer, submitted 5/5 with a comment, saw the thank-you; revisiting showed "already recorded";
a bogus token showed the neutral page.

**Not exercised:** a real delayed send through the scheduler, reminders, and expiry — all three are
time-dependent and the sweep was verified by reading rather than by waiting. Rating was switched
back **off** afterwards, as it was before this work.

### Files

- `database/migrations/2026_08_22_300000_*`, `2026_08_22_300001_*`
- `app/Models/HelpCenterRatingSettings.php`, `HelpCenterRating.php`
- `app/Services/HelpCenter/RatingManager.php`, `RatingSender.php`
- `app/Http/Controllers/HelpCenter/RatingController.php`, `PublicRatingController.php`
- `app/Mail/HelpCenterRatingRequestMail.php`, `app/Console/Commands/SendHelpCenterRatingRequests.php`
- `resources/views/help-center/rating.blade.php`
- `config/help-center.php`, `routes/help-center.php`, `routes/console.php`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php`, `RequestController.php`
- `app/Services/HelpCenter/Inbound/InboundIngestor.php`, `EmailTemplateRenderer.php`
- `app/Models/HelpCenterRequestActivity.php`, `HelpCenterRequest.php`, `HelpCenterSpace.php`,
  `HelpCenterEmailTemplate.php`
- `public/assets/js/help-center/space-settings.js`

---

## P57 — Rating settings as two cards (2026-08-22)

### Requirement

> The first card should remain dedicated to enabling or disabling the Rating feature. Once
> enabled, the second two-column configuration card becomes visible — settings on the left, a live
> customer preview on the right.

### What changed

P56 put the enable switch at the top of a single left-hand column with the preview beside it from
the start. That made the one control that matters most — *is this on?* — the first item in a
column of twenty others, and showed a preview of an experience that was not running.

Now: **card one is only the switch.** Card two appears when it is on, and holds every setting on
the left with the preview on the right, divided by a border inside one card rather than floating
as a separate panel — the requirement asks for one two-column card, and a preview that reads as a
separate box stops reading as *"this is what the settings on the left do"*.

The preview column sits on `bg-hover`, so the white preview reads as a screen being previewed
rather than as more of the form.

### The switch saves itself

It has to. The Save button lives in the second card, and that card disappears the instant the
switch goes off — a toggle whose only Save vanishes when you use it is a toggle that silently does
nothing.

This is also the screen's own established convention for single switches (`isInstant`), so it is
not a special case. The whole draft goes with it, which is correct rather than incidental: a
half-edited form is still the settings somebody wants, and saving them alongside the flag is what
makes turning Rating on with its defaults a single click.

### Four classes that would have done nothing

`lg:w-[360px]`, `lg:sticky`, `sm:px-5` and `sm:py-5` are none of them in the built stylesheet.
Swapped for `lg:w-[340px]`, unprefixed `sticky` (which costs nothing on a narrow screen where the
column is stacked underneath anyway) and `sm:p-6`.

Eighth time. The standing offer remains: adding `public/assets/js/**/*.js` to Tailwind's content
globs ends this class of bug outright, and is worth doing as its own change.

### Verified

Off: card one alone, full width. Toggling on saved immediately ("Rating settings saved.") and
revealed card two in two columns. Changing Rating Type to **5 Emoji** redrew the preview's five
options instantly, labels intact. State restored — Rating off, type back to 5 Stars.

### Files

- `public/assets/js/help-center/space-settings.js`

---

## P58 — Rating labels, one per row (2026-08-22)

### Requirement

> Do not use a two-column layout for the individual rating labels. Each rating score should appear
> in its own full-width row.

### Why the two columns were wrong

They paired 1 with 2 and 3 with 4, which reads as a mapping *between* the pairs rather than as one
ordered scale — and left 5 alone on the final row, which made the top of the scale look like a
different kind of thing from the rest of it.

A single column is the scale, in the order it is scored, and the wider field leaves room for the
longer labels people actually write.

`grid gap-2 sm:grid-cols-2` → `space-y-2`.

### Verified

1 through 5 each on their own full-width row, in order. Rating was enabled briefly to see the
card and switched back off.

### Files

- `public/assets/js/help-center/space-settings.js`

---

## P59 — One card per section (2026-08-22)

### Requirement

> Do not combine these sections into one large card. Display the cards vertically in this order:
> Enable Rating, Rating Experience, Trigger, Reminder & Expiration, Negative Feedback, Email.

### What changed

P57 put the five configuration sections inside one bordered card, which made them read as a single
long form whose end you had to scroll to find. They are five separate decisions — *how does it
look*, *when does it go*, *how long does it live*, *what happens on a bad one*, *what does it say*
— and each is now its own card with its own header.

The section headings moved from floating above their boxes to sitting inside them as card headers,
which is what makes each read as a card rather than as a labelled fragment of one.

### The preview stayed beside them

The requirement's list names six cards and does not include the Customer Preview, which leaves a
choice. It stays in its own column beside the stack rather than joining it, because it is not a
sixth decision — it is what the other five *look like*, and it has to be on screen while they are
being changed. Dropping it or stacking it at the bottom would undo P57's live preview, which was
its own explicit requirement.

`sticky` matters more now than it did: with five cards to scroll through, a preview pinned in view
is the only way a change to the last one can still be seen taking effect.

### Verified

Six cards stacked in the specified order, visibly separated, each with its own header. The preview
stays pinned through the scroll. Rating was enabled briefly to see it and switched back off.

### Files

- `public/assets/js/help-center/space-settings.js`

---

## P60 — The rating email, edited in a modal (2026-08-22)

### Requirement

> When the user clicks Edit Rating Email, do not navigate away. Open the template in a modal with
> enable/disable, subject, body, variables, preview and save. Any change must also be reflected
> under Space → Settings → Email Template. Both must always edit the same underlying record.

### One record, two doors

The modal reuses the Email Template page's methods **unchanged** — `editTemplate`,
`saveTemplate`, `previewTemplate`, `insertVariable`, `availableVariables`. The rating panel simply
sends the same three payload keys that page sends (`templates`, `variables`, `endpoints`), with
only the one type in it.

That is what makes "same record, never a duplicate" true by construction rather than by two code
paths agreeing to be careful. Verified: after saving from the modal there is exactly **one** row in
`help_center_email_templates`, and the Email Template page shows it as **Enabled · Customised**.

### The template became disable-able

`rating_request` shipped with `can_disable => false`, copied from the agent reply. That was wrong
by analogy: the agent reply must always be available because an agent is mid-sentence when they
need it; nobody is mid-anything when a rating request goes out, and the requirement asks for the
switch.

The sweep **skips** a disabled template rather than cancelling the requests. Disabling is "stop
sending these for now", not "throw away what is already raised" — switching it back on should let
them go, and a cancelled row can never be un-cancelled.

### Closing only on success

`saveTemplate` now returns its promise, resolving to whether the save landed; the modal chains on
it. A failed save leaves the modal open with the edits still in it — losing somebody's email copy
because the server said no would be the worst possible response to the server saying no.

The card behind updates the instant the save returns, because `saveTemplate` already folds the
answer back into `templates` and the card reads the same entry. No second fetch, nothing to keep
in step.

---

## P61 — Dropdowns use the project's combo (2026-08-22)

### Requirement

> All the dropdowns should be changed to the combo style we have in the project.

Every native `<select>` in the Help Center is now `pb-combo`. Thirteen in the settings screen, one
on the Overview dashboard; zero remain.

### Two things that needed bridging

**`pb-combo` types `modelValue` as String|Array.** Six of these settings are integers — trigger
status, delay, expiry, low threshold, reminder max, tag. Binding a number sets the model to a value
the component's own comparison never matches, and the field renders as though nothing were
selected. Each is carried as a string and converted back by one `setRatingNumber(field, value,
nullable)` bridge, rather than a cast at six call sites. `''` and `'never'` both read as null on
the two nullable ones.

**Insert Variable is an action menu, not a value picker.** Bound to a permanently empty value so
the button keeps reading "Insert Variable" rather than adopting whatever was inserted last.
Verified: picking Agent Name inserted `{{agent_name}}` at the caret and the control reset itself.

### Verified

Rating type combo opened, selected "Thumbs Up / Down", and the live preview redrew as 👎/👍 with
the 1-and-5 labels and the comment turning required — the whole chain works through the combo, not
just the control. All test data reverted: template override deleted, Rating off, type back to
5 Stars.

### Files

- `public/assets/js/help-center/space-settings.js`, `overview.js`
- `config/help-center.php`, `app/Console/Commands/SendHelpCenterRatingRequests.php`
- `app/Http/Controllers/HelpCenter/SpaceSettingsController.php`

---

## P62 — Replies are sent from the Space's own address (2026-08-22)

### Requirement

> When an agent replies, the outgoing email must be sent using the configured support email
> address for that specific Space — not the agent's, the workspace owner's, the global sender's,
> or another Space's. Customer replies must return through the same Space and attach to the same
> ticket.

### The constraint this had to meet, not dodge

P27 recorded why the Space's address had only ever been the *Reply-To*: "Postmark only sends from
a verified signature, and that address belongs to the customer's supplier, not to us."

That constraint has not gone away — it has been satisfied. The requirement's own validation list
says the From must be "verified with the email provider", and this module already tracks exactly
that. So `SpaceSender` uses the **verified** address as the From, and refuses the send when there
is not one, with the requirement's own wording.

Deliberately **verified only** — not "verified if there is one, else the first". That fallback was
harmless as a Reply-To (an unverified one just bounces for the customer); as a From it is worse
than harmless, because the provider refuses outright or the mail lands in spam with our domain on
it. An unverified address is not a sender, and saying so beats trying.

Verified: an unverified address refuses; another Space with no verified address refuses rather
than borrowing this one's — which is the isolation rule the requirement is really about.

### Ticket-tagged Reply-To

From is the Space's branded address; **Reply-To is ticket-specific**:

```
From:     Support 4 <support4@zorainteractive.com>
Reply-To: inbox-8pb4kxdj+r6-1fac444890@inbound.myprojectblock.dev
```

The tag names the ticket in the one part of an email a customer's client cannot rewrite. Threading
headers are still sent and still tried — but *after* the tag, because they are the polite method
and the less reliable one: Outlook rewrites References, some mobile clients drop them, and a
forwarded mail loses them entirely.

It is **signed**. A bare `r42` would let anyone post into ticket 42 by writing to a guessed
address; the HMAC makes it unforgeable without the app key. Verified: the tag round-trips to its
id, `r6-0000000000` is rejected, the tagged address still routes to the right Inbox, and plain
un-tagged addresses route exactly as before — the plus form is an addition, not a new contract.

A valid tag naming a Request in a *different* Inbox is also ignored, so a leaked address cannot be
used to post into somebody else's queue.

### The breakage this caused, and how it was handled

Changing the stored `from_email` to the Space's address quietly broke two things that had been
relying on the old meaning:

- **per-agent reply counts** (P50) matched `from_email` against a user's address,
- **the ticket timeline** printed `from_name` to say who replied.

Both would have kept working and started lying — every reply attributed to "Customer Support". So
the two facts now have two columns: `from_*` is honestly the email's From, `author_*` is the
person. Existing rows were backfilled, because a metric that silently loses its history is worse
than one that never had any.

**I found this the way it deserved to be found — by looking.** The first send after the change
showed "Support 4" where the agent's name belonged, in a timeline builder I had missed while
fixing the other two call sites. That is now fixed and re-verified.

Side benefit: `agentReplyCounts` is no longer the approximation P50 flagged. It reads a column
written at send time rather than matching an address that could change.

### Not built

**Attachments on customer replies are not stored** — there is no column and `PostmarkPayload`
never reads `Attachments`. The acceptance list asks for it; it needs a file-storage pipeline this
module does not have, and half-building one would be worse than naming the gap.

Also unchanged: "active Email Channel" is read as "the Space has an Inbox with a verified
address", which is the checkable form of it here.

### Verified

Sent a real reply on #000004: it went out From the Space, the timeline labelled it **Reply to
Customer** and attributed it to **David Webby**, and the stored row carried both identities
correctly. Test reply and its activity rows removed afterwards, waiting clock restored.

### Files

- `app/Services/HelpCenter/SpaceSender.php` (new)
- `database/migrations/2026_08_22_400000_add_author_to_help_center_messages.php`
- `app/Models/HelpCenterRequest.php` — `replyTag()`, `fromReplyTag()`
- `app/Services/HelpCenter/Inbound/InboundRouter.php`, `InboundIngestor.php`
- `app/Http/Controllers/HelpCenter/RequestController.php`, `app/Mail/HelpCenterAgentReplyMail.php`
- `app/Models/HelpCenterMessage.php`, `app/Services/HelpCenter/Reporting/SpaceReport.php`

---

## P63 — Fixing the regression P62 introduced (2026-08-22)

### What the user reported

> Currently I reply to customer on a ticket, there is no email sent to the customer on my reply.

They were right, and it was my fault.

### The mistake

P62 read the requirement's "From address is verified with the email provider" and reached for
`HelpCenterEmailAddress::STATUS_VERIFIED`, refusing to send without it.

**Those are two different facts.** This module's flag is set by
`InboundIngestor::markAddressesVerified()` when mail *arrives* addressed to that address — it means
"the customer's forwarding rule works". Provider verification is a sender signature in the Postmark
account, which this application neither sets nor can see.

Of the six Spaces here, **one** had the inbound flag set. The other five had working addresses in
`pending`, and P62 silently turned their replies into a 422.

### How it was diagnosed rather than guessed

1. `MAIL_MAILER=smtp` via Postmark, `QUEUE_CONNECTION=sync` — no queue swallowing anything.
2. No `help-center.reply.failed` in the log — so nothing was throwing at send time.
3. **Probed the provider directly**: sent a raw mail with `From: support4@zorainteractive.com`.
   Postmark **accepted** it. So the provider was never the blocker.
4. Listed every Space's address statuses — five of six had no `verified` address, which is exactly
   the set that had stopped sending.

That fourth step is the one that turned a plausible theory into the actual cause.

### The fix

The gate is now the only thing this application genuinely knows: **refuse when the Space has no
address configured at all**, which is precisely what the requirement's message describes. A
verified address is still *preferred* when a Space has several — mail demonstrably arrives there,
so it is the one the customer already writes to — but it is not required.

Whether the provider will accept a given From is the provider's verdict, delivered at send time,
and the reply path already catches, logs and surfaces it.

### The lesson worth keeping

A flag named `verified` was assumed to mean the thing the requirement said, and it meant something
else entirely. The class note now states both meanings, so the next reader cannot repeat it.

### Verified

Replied on #000001 in Email Testing Phase — the Space that had been blocked. It sent, stored
`From: Support Box <support1@zorainteractive.com>` with author David Webby, and logged no failure.
#000004 still resolves to its own address. Each Space uses its own; neither borrows the other's.

Test reply, its activity rows and the waiting clock all reverted.

### Files

- `app/Services/HelpCenter/SpaceSender.php`

## P64 — From and Reply-To are both the Space's inbound address; delivery status and Retry (2026-08-22)

**Requirement.** "From Address = Ticket's Space Inbound Email Address … Reply-To Address =
Ticket's Space Inbound Email Address … Show: Agent, Sent date/time, Customer email address, From
address, Reply-To address, Message content, Delivery status. If email delivery fails, show
**Failed to Send** and provide a **Retry** action."

### One address, not two

P62 sent from the Space's *support* address and set Reply-To to the tagged *inbound* address.
That is one address the customer sees and a different one their answer goes to, and it is exactly
the arrangement that made P63 possible: a From the provider had never been asked to authorise.

`SpaceSender` now resolves both from `HelpCenterInbox::inboundAddress()`, with `from_name` set to
the Space's name so the customer still reads a person's organisation rather than a raw mailbox.
It refuses only when there is no Space or no Inbox — never on `STATUS_VERIFIED`, which records
that *inbound forwarding* works and says nothing about what the provider will send as. That
mistake is P63 and it is written into the class docblock so it is not made a third time.

Verified per-Space before shipping: `#000001 → inbox-wknbatec@…`, `#000004 → inbox-8pb4kxdj@…`.

### Delivery status is stored, not inferred

Migration `2026_08_22_500000_add_delivery_to_help_center_messages` adds `delivery_status`,
`delivery_error`, `delivered_at` and `reply_to`, backfilling existing outbound rows to `sent`.
The status is written by the code that did the sending, at the moment it succeeded or threw —
the alternative, deciding after the fact from whether a row exists, cannot tell a message that
failed from one that was never attempted.

`RequestController::reply()` resolves the sender **before** storing, so a Space that cannot send
does not leave a message row claiming it replied to somebody.

### Retry re-sends the stored message

`RequestController::retry()` takes the row that failed and sends *it* — the agent's words
survived, only the delivery did not. It does not create a second message: a retry that appended
a row would show the customer's history two copies of one reply, and the count of replies would
climb every time somebody clicked.

Route: `help-center.spaces.requests.retry`.

### The ticket shows both addresses, always

The timeline prints `From … · Reply-To …` even though P64 makes them the same string. Hiding
the Reply-To when it matches would remove the one line that answers "will their answer come back
here?" exactly when the answer is yes — and a Space still configured the older way, whose two
addresses genuinely differ, would render identically to one configured correctly.

### Verified end to end

A real reply on `#000004` stored `from_email` and `reply_to` both
`inbox-8pb4kxdj@inbound.myprojectblock.dev`, `from_name` the Space name, `author_name` the agent,
`delivery_status = sent`. The row was then set to `failed`, which rendered **Failed to send**, the
error text and the **Retry** button; Retry flipped it back to Sent with a new timestamp and added
no second row. The test message, its activity row and the waiting clock were reverted afterwards.

### Still open

Attachments on customer replies are not stored (no column; `PostmarkPayload` never reads
`Attachments`) — named as a gap in P62 and still open. `QUEUE_CONNECTION=sync` means SMTP runs
inline in the request, so a slow provider is a slow click.

## P65 — A configurable sender name, on every customer-facing email (2026-08-22)

**Requirement.** "Each Help Desk Space must have its own unique Inbound Email Address and a
configurable Inbound Email Display Name … **From:** `Inbound Email Display Name <Space Inbound
Email Address>`, **Reply-To:** the same … The same sender configuration must be used for all
customer-facing emails sent from the Space."

### Where the name lives, and why it is null by default

`help_center_spaces.inbound_display_name`, nullable.

On the **Space**, not the Inbox: the inbound *address* is generated per Inbox and a Space may
hold several, but the requirement states the name as one fact per Space. Storing it per Inbox
would let one Space introduce itself to customers under two names.

**Nullable rather than backfilled with the Space name.** Null means "use the Space name", which
is the requirement's own default, and `HelpCenterSpace::senderName()` resolves it at read time.
Copying the name into the column at creation would freeze it: renaming a Space from "Support" to
"eBay Support" would leave every outgoing email still signed "Support", with nothing on screen
saying which of the two fields to fix.

### The gap this closed

P62–P64 fixed the *agent reply*. They did not touch the other customer-facing mail, and
§4 lists five kinds. The acknowledgement and the rating request were still going out under the
application's **global** sender — so the first email a customer ever received and the answer to
it looked like two different organisations, with the acknowledgement arriving first.

`SpaceSender` gained `forSpace(?Space, ?Inbox)`, which is now the real resolver; `for(Request)`
delegates to it. Several senders have a Space and an Inbox but no Request, so the Space is the
honest parameter. Every customer-facing mailable now takes `from_email`, `from_name` and
`reply_to_name`:

| Sender | Before P65 | After |
|---|---|---|
| `RequestController::reply()` / `retry()` | Space inbound + Space **name** | Space inbound + **display name** |
| `TicketConfirmer` (auto-response) | **global sender** | Space inbound + display name |
| `RatingSender` (CSAT + reminder) | **global sender** | Space inbound + display name |
| `EmailTemplateController::test()` | **global sender** | Space inbound + display name |

The test send matters as much as the rest: the point of a test is to see what the customer will
see, and a test that arrives from a different sender is a test of something else.

### The display name is on the Reply-To too

Both headers are one identity — `eBay Support <inbox-…@inbound…>` twice — so the Reply-To is an
`Address` with the name, not a bare mailbox. Clients that surface the Reply-To on a reply then
show the team rather than a raw generated token.

### Two places it can be set, and one place it must not be cleared

**Create** — the wizard's step 1 (which is the live Create Space form; the `space-create.js`
dialog exists but is not currently mounted by any view, and the sidebar "+" links to the wizard).
Optional, with the help text the requirement specifies and a live preview built from the same
fallback the server applies, so the preview is a promise rather than a guess.

**Edit** — Settings → Inbox, in a "Sender identity" card above the Inbox list and *outside* its
`v-for`: it is one fact about the Space, and rendering it per Inbox would invite somebody to give
one Space two names. The address is shown read-only as the requirement states, as a code block
rather than a disabled input so it does not look like a field somebody failed to enable.

**The trap that was avoided.** The Space edit drawer does not render this field. An unconditional
`prepareForValidation()` merge would therefore have put `null` into `validated()` on every save
from that drawer and silently cleared a configured display name. `UpdateSpaceRequest` now merges
the key only when it was actually sent, and `SpaceController::update()` writes it only when
present. A field absent from a request is a field nobody was editing.

Blank input is stored as NULL, never `''` — an empty string would defeat the fallback and put an
empty quoted display name in the From header.

### Verified

`SpaceSender::forSpace()` returned the fallback (`e4t3453`) with the column null and
`eBay Support` once set. All three mailables' envelopes were rendered and each carried
`eBay Support <inbox-8pb4kxdj@inbound.myprojectblock.dev>` on **both** From and Reply-To. Setting
the name through Settings → Inbox saved, and a live reply on `#000004` stored
`from_name = "eBay Support"` with `delivery_status = sent`. Test data — the message, its activity
row, the waiting clock and the display name itself — was reverted afterwards.

### Not changed

§5 (inbound routing) and §6 (threading headers) already worked and were left alone: the ingestor
resolves the Space from the receiving address and threads on `Message-ID`/`In-Reply-To`/
`References`, which is what P27/P36/P62 built. Attachments on customer replies are still not
stored — open since P62.

## P66 — Inbound attachments are stored, and the ticket carries them (2026-08-22)

**Requirement.** "Inbound Email should be able to accept attachment and ticket will generate
along with attachment."

The gap named as open since P62 and repeated at P64 and P65: Postmark's inbound webhook carries
an `Attachments` array and `PostmarkPayload` never read it. A customer wrote "invoice attached",
the ticket opened with the words and nothing else, and the only copy of the file was in a webhook
body nobody kept.

### The pipeline

`PostmarkPayload::attachments()` decodes the base64 at the boundary, like every other field, so
nothing downstream has to know how Postmark spells things. A file whose content will not decode
is dropped rather than stored empty — a zero-byte row with the right name is worse than no row,
because it looks like a file somebody can open. Postmark's `ContentLength` is ignored in favour
of the decoded length: it is the sender's claim about someone else's data, and the bytes we hold
are the only size worth recording.

`InboundAttachmentStore` writes each one to the **private** disk and creates a row.
`'visibility' => 'private'` is passed explicitly because `MEDIA_DISK` here is `spaces`, whose
config defaults writes to **public** — inheriting that would publish a customer's invoice to an
unauthenticated URL and bypass the check the download route enforces. Verified after the fact:
every stored object reports `private`.

Called from inside the ingest transaction, after the message row exists (an attachment needs the
message id for its path). Inside rather than after, so a ticket is never committed holding half
of what the customer sent: a failed ingest leaves orphaned blobs, which are recoverable, rather
than a message that silently lost an invoice, which is not.

**Spam is included.** The message itself is already stored for audit (P47); keeping its words and
discarding its files would make that audit partial, and a ticket wrongly marked spam must be
restorable whole.

### A deny-list, where work items use an allow-list

`work_item_attachments` validates against an allow-list, and rightly: a colleague told "that type
is not accepted" picks another. **A customer emailing support cannot be told anything — they have
already sent it** — so a file quietly refused has simply vanished, and the agent reads "see
attached" with nothing attached. That is the failure this feature exists to fix.

So everything is kept except the handful of types that are only ever dangerous
(`config('help-center.attachments.blocked_extensions')`), plus caps at 25 MB per file and 25 per
message. **Nothing is refused silently**: `attachments_skipped` and
`attachments_skipped_detail` are written on the message and the ticket says so —
*"2 attachments were not saved · setup.exe (Blocked file type) · database-dump.sql (Larger than
25 MB)"*. Without that line an agent cannot tell whether the customer forgot the file, the parser
broke, or the system refused it, and those need three different replies.

### The filename is the sender's, and is treated as such

It is displayed and used to name the download. It is **never** used to build the storage path —
`Str::random(40)` does that, with only a validated extension carried over. `safeName()` strips
path separators and control characters, so a name containing a newline cannot forge a second
header on the download response.

Probed with hostile names:

| Sent as | Result |
|---|---|
| `../../../../.env` | stored as `-..-..-..-.env`, path random under our own directory |
| `report\r\nX-Injected: yes.pdf` | CRLF stripped from the name |
| `harmless.PDF.exe` | **blocked** — the check reads the final extension, so double-extension disguise fails |
| *(empty)* | named `attachment` |

### Reading a file is authorized through the ticket

`RequestAttachmentController::show()` — its own controller, like `RequestNoteController`, because
what it must never do matters as much as what it does: there is no upload, no delete, and no way
to reach a file except through the Request that owns it.

Gated on **viewing** the Space, not updating it: an attachment is part of what the ticket says.
Two ownership checks, not one — the Request must belong to the Space *and* the attachment to the
Request. Without the second, any attachment id in the workspace would serve through any ticket
the caller can open. Refusals are 404, never 403, so a response never confirms a file exists
somewhere the caller cannot reach.

Always `Content-Disposition: attachment` plus `X-Content-Type-Options: nosniff`, never inline.
This is a file a **stranger** sent: serving their `text/html` or `.svg` inline would run their
markup in this application's origin against a signed-in session.

Verified: the correct URL returns `200 attachment; filename=invoice-4471.pdf` with `nosniff`;
the same file id through another ticket or another Space returns `404`.

### On the ticket

`hcAttachments(root)` builds one strip rendered in two places — under the ticket **headline**
(the opening email, where a screenshot almost always arrives, and which is drawn above the
timeline rather than in it) and under each reply in the timeline. Chips are plain `<a download>`
links to the authorized route, not fetches: a link is the one control that cannot end up
rendering the file in this page.

### The inline-image trade-off, stated plainly

An image the sender embedded in the body arrives as an attachment with a `Content-ID`. Those are
stored and **shown**, with `is_inline` recorded. A signature logo therefore appears as an
attachment, which is mildly noisy — but a pasted screenshot arrives exactly the same way and is
usually the whole point of the ticket, and the two are not reliably distinguishable. Losing the
screenshot is unrecoverable; showing a logo is not. The flag is what a later refinement would
filter on without re-ingesting anything. (Bodies currently render as plain text, so `cid:`
references are not rewritten — there is nothing to rewrite them into.)

### Verified end to end

A crafted inbound payload with four attachments opened ticket #000007: the PDF and the inline PNG
stored with correct sizes and `private` visibility, the `.exe` blocked, the 26 MB file refused,
both refusals shown on the ticket with reasons. Probe tickets and their objects were deleted from
the bucket afterwards.

Then a **real** customer email arrived mid-session carrying an 18 KB `.docx` and was stored and
rendered without intervention — ticket #000009.

### Still open

Attachments on **outbound** replies — an agent cannot attach a file to a reply. Inbound was what
was asked for; the composer has no paperclip and this does not add one.

## P67 — The Inbox updates itself (2026-08-22)

**Requirement.** "When a new customer response, new ticket, or ticket update is received, the
Inbox should update automatically without requiring the agent to refresh… Preserve the agent's
current scroll position, filters, selected ticket, and any unsaved reply being typed… show a
toast… Do not use browser refresh or frequent API polling as the primary solution."

### The bug found on the way in

The realtime layer was **already dead, silently**. `echo.iife.js` exposes a module namespace —
`{Channel, Connector, EventFormatter, default}` — so `window.Echo` is an *object* and
`new window.Echo(...)` throws "not a constructor". `connect()` is wrapped in a try/catch that
turns any failure into "no socket", which is the right instinct and is exactly what hid this: no
error, no broken page, just every real-time feature in the application quietly inert, including
the topbar Inbox badge (§27) that shipped long before the Help Center existed.

`echoConstructor()` now accepts the callable, `.default` and `.Echo` spellings, so a checkout
built the other way is not a dead socket again.

A second ordering bug followed: `realtime.js` is `defer`red and the screens are not, so the
Inbox's `mounted()` ran before `PB.onSpaceTickets` existed — the socket connected, the user
channel subscribed, and the Space channel never did. `realtime.js` now fires
`pb:realtime-ready`; screens try immediately *and* listen for it, and `liveOff` makes the second
path a no-op so neither order double-subscribes.

### The channel is the Space

`private-tenant.{tenantId}.help-center.space.{spaceId}` (CLAUDE.md §12). A new customer reply
concerns whoever has that Inbox open — usually several people, and not necessarily the assignee.
A per-user channel would leave the agent actually reading the queue the last to know.

`routes/channels.php` checks three things: active workspace membership (so a removed member with
a tab open stops hearing), that the Space belongs to that workspace (so a member of A cannot
listen on `tenant.A…space.{a Space in B}`), and the Space policy itself — who may *listen* is
decided by the code that decides who may *read*. `withoutGlobalScopes()` is required rather than
tidy: a subscribe carries no tenancy context, so `BelongsToTenant` would find nothing and refuse
every subscribe.

### One event class, six names

The requirement lists `ticket.created`, `ticket.updated`, `ticket.reply.received`,
`ticket.assigned`, `ticket.status.changed`, `ticket.unread.updated`. They are one fact with six
labels; six classes would be six copies of the same channel, payload and tenancy rule.
`HelpCenterTicketEvent::broadcastAs()` returns the type, so the wire looks exactly as specified
while the rules stay in one place.

`ShouldBroadcastNow` — a queue worker nobody is running is an update that never arrives.
**`ShouldDispatchAfterCommit`** — this fires from inside the ingest transaction, and without it
the socket message beats its own COMMIT: the browser is told "new reply", refetches, and is
served the state from *before* the write. On a fast connection that is the ordinary case, not a
rare race.

### Broadcast from the one place that already records

`TicketBroadcaster` is called from `InboundIngestor` (created / reply received) and from
**`RequestActivity::record()`** — the single place every agent-side change is written. A status
changed from the row, from the drawer, from an inbound rule or from a snooze waking all pass
through there, so none of them can be forgotten. Its existing "a change that changed nothing is
not history" early return now also means saving the same assignee twice sends no socket message.

**Only customer mail toasts.** A status change is something an *agent* did, and the agent who
did it is already looking at the result; a toast per chip on a busy queue is a screen nobody can
work in. The row still moves. Spam updates the screen and never toasts — interrupting somebody
for mail they already threw away is the notification that teaches people to ignore
notifications.

### Refetch, don't splice

The payload carries no ticket — just id, number, subject, customer and URL. The client refetches
`/spaces/{space}/inbox/rows`, which runs **the same query the page was rendered from**
(extracted as `inboxRows()` so the two cannot disagree). Ordering, the spam and snooze
exclusions, the URL's drill-down filters and the queue counts are server rules; a browser
inserting a row where it guessed it belonged would be a second implementation of every one of
them, and the first to drift.

This is not polling — there is no timer. It answers events, which is what the requirement asks
for in preference to a poll.

Refetches are **coalesced**: one in flight, one queued. A burst (a customer replying to five
tickets, an agent bulk-closing) would otherwise race and let the list settle on an older answer
than one already received.

### What is preserved, and why

`refreshDrawer()` replaces `drawer.detail` and touches nothing else, so the open tab, the panel
scroll and **`replyBody`** survive — a reply somebody is halfway through typing must not be the
price of a live update. Tabulator's `replaceData` (not `setData`) keeps the list's scroll
position. The client-side `filter` state is never touched.

### Verified end to end

Reverb started; both channels subscribed and authorized. A customer reply ingested with the page
untouched produced: the toast *"New customer reply received / Sarah Johnson replied to #000001 –
Test for email delivery"*, the row re-sorted with a reset waiting clock, and the navigation
counts updated — no refresh.

Then with #000001 **open** and *"Half-typed reply that must survive the live update."* in the
composer, a second reply appended to the conversation, the tab count went 1 → 2, the draft was
untouched, and **no toast fired** — the agent was already looking at it. Probe messages were
deleted and the waiting clock restored afterwards.

### The cross-Space queue, too

`/help-center/inbox` shows several Spaces at once, so it subscribes to **every Space the
signed-in user may view** — `RequestQueue::liveSpaceIds()`, resolved from the policy.

Deliberately NOT derived from the rows on screen, which is what `spaceOptions` does: a Space with
nothing in the current view would then have no channel, and the *first* ticket to arrive
there — the one most worth knowing about — would be the one nobody was listening for.

Its rows come from `GET /help-center/inbox/rows/{view?}`, running the same
`RequestQueue::rows()` the page was rendered from. The view is optional and **null for the
default queue**: the route's `{view}` is enumerated from `request_views`, which deliberately does
not contain `inbox`, so passing it would build `/inbox/rows/inbox` and 404 on the one view most
people are looking at.

`spaceOptions` travels back with the rows, and that is not decoration — the row menu's status and
assignee pickers are keyed by Space and built *from* the rows, so a ticket arriving from a Space
that was empty in this view a moment ago would otherwise land with a menu that could offer it
neither.

Verified: all three Spaces subscribed on one page; a reply into Space 12 while sitting on the
cross-Space queue toasted, threaded onto #000004 without opening a second ticket, re-sorted the
row with a reset clock, and moved the navigation counts — no refresh. Probe messages deleted and
the clock restored afterwards.

### Still open

`ticket.unread.updated` exists on the wire and nothing dispatches it yet: there is no unread
model in the Help Center to change.

**Reverb must be running** (`php artisan reverb:start`) for any of this. When it is not, every
screen behaves exactly as it did before — the socket only ever adds.

## P68 — Only the customer's new words are stored (2026-08-22)

**Requirement.** "The inbound email parser is currently storing the entire email thread inside
the ticket comment… Only the new customer-written content should be saved."

Real data from #000009, which is what prompted this:

```
One more issues the attachment has raw data which we where trying
toparse into the system
On Sat, 22 Aug 2026 at 13:43, eBay Support System <
inbox-wknbatec@inbound.myprojectblock.dev> wrote:
> Email Testing Phase
> …
```

Every exchange added another copy of the whole conversation, so the one thing an agent needs —
what was just said — became the smallest part of the comment.

### Nothing is destroyed

`raw_text` / `raw_html` hold the body exactly as it arrived; `body_text` / `body_html` become the
cleaned reply, which is what every screen already reads. Nothing renders the raw columns —
the requirement's "raw email content must never be rendered directly inside the ticket
conversation".

**The bodies, not the whole MIME message.** The requirement offers `raw_email` or the original
MIME in object storage; both would mean a second, base64-inflated copy of every attachment —
files this module already stores properly and privately (P66) — so a 20 MB invoice would be kept
twice, once downloadable and once as an unreadable string in a row. The bodies are the part
troubleshooting needs: they are what the parser ran on.

### Plain text — cut at the first line that begins someone else's message

`On … wrote:` (Gmail, Apple Mail, most mobile clients), `-----Original Message-----`,
`----- Forwarded Message -----`, `Begin forwarded message:`, Outlook's underscore rule, a bare
`From:/Sent:/To:/Subject:` block, and runs of `>`.

Three of those rules are deliberately narrower than they look:

- **The underscore rule only cuts when a header block follows.** A row of underscores is also
  something people type above their own signature.
- **The Outlook header block needs two of `From/Sent/To/Cc/Subject/Date`.** A customer writing
  "Sent: yesterday" in a sentence must not truncate their own message.
- **A `>` run only cuts if everything below it is quoted or blank.** Otherwise a customer who
  quotes one line and answers underneath it would lose everything they wrote.

Gmail **wraps** a long attribution, so `On Sat, 22 Aug 2026 at … <` and `…dev> wrote:` are two
lines. Matching single lines would miss exactly the case that prompted this, so a line opening
with `On` is joined with the next few and tested as a whole.

**A bug I introduced and caught in the same pass.** The first version also accepted
`<someone@example.com> wrote:` with 200 characters of anything in front, tested against that
same four-line window — which reached out of the line being tested and into the *next* line's
attribution. On the real Gmail body it matched at line 0, cut the message at its own first word,
and fell back to storing the whole thread: the exact bug this class exists to fix, reintroduced
by the rule meant to fix it. The no-`On` form is now anchored at both ends of a single line.

### HTML — by container, not by prose

The markup states plainly what the text can only be guessed at: `.gmail_quote` /
`.gmail_quote_container`, `blockquote[type=cite]` (Apple Mail), `#divRplyFwdMsg` and
`#appendonsend` (Outlook), `.yahoo_quoted`, `.moz-cite-prefix`, `.protonmail_quote`,
`.OutlookMessageHeader`, Outlook web's reference container, Zimbra's `zmail_extra`.

Everything **after** the marker goes too, not only the node: Outlook's `#divRplyFwdMsg` is a
*header*, and the quoted body is its siblings — removing the marker alone would delete the label
and keep the thread. A "… wrote:" paragraph with the quote as a sibling (Thunderbird, several
webmails) is handled separately, bounded to short paragraphs so one that merely *contains* the
words is not mistaken for the attribution.

Formatting survives: `<b>`, links, lists and paragraphs all pass through untouched.

### MIME noise, defensively

Postmark hands us decoded bodies, so `Content-Type:`, `boundary=`,
`Content-Transfer-Encoding:` and base64 runs should never reach a body at all. They are stripped
anyway, as insurance against a malformed multipart passed through as text — the shape the
requirement names.

### The fallback rule

"If the parser cannot confidently determine where the quoted content starts: do not discard the
entire message." Every cut is checked — one that leaves nothing when there *was* something is
refused, the original kept, and `quote_stripped` set false with a
`help-center.inbound.quote_strip_uncertain` log line. Nothing about the parse reaches the
screen.

### Verified

Eleven plain-text cases and four HTML cases, including the real #000009 body, every client
format named in the requirement, a mid-message quote that must be **kept**, and an
attribution-first message that correctly refuses to blank itself.

The migration backfilled existing rows — #000009 now reads as the two sentences the customer
wrote — writing each original into `raw_*` in the same statement that overwrites it, so there is
no window where the only copy is the cleaned one, and `down()` restores them before dropping the
columns.

End to end: a Gmail-style quoted reply carrying a CSV arrived while #000009 was open. It appeared
**live** (P67) as **Customer Reply** with only *"That fixed the mapping, thank you. Attaching the
corrected export."*, `<b>` preserved in the HTML, `corrected-export.csv` beneath it as a proper
attachment item (P66), the raw 329-character original retained, and no MIME string anywhere in
the comment. Probe message and file deleted afterwards.


## P69 — The timeline avatar had no size (2026-08-22)

**Report.** "fix the avatar image of the user" — Space Inbox.

Every avatar in the ticket timeline rendered as a thin oval instead of a circle, while the ones
in the headline and the side panel beside them were round.

**Cause.** The span was written `h-[22px] w-[22px]`. Tailwind here is **pre-built** and its
content globs do not include `public/assets/js/**`, so an arbitrary value written in a JS
template only works when the identical string happens to appear in a scanned source. `h-[22px]`
and `w-[22px]` do not appear anywhere else, so neither rule was ever generated — the span had no
width and no height at all, collapsed to the width of its single letter, and stretched to the
flex line. `shrink-0` was already present and was never the problem.

`h-6 w-6` (24px, a standard scale value that is generated) replaces it. Audited every
`rounded-full` element in the module afterwards: this was the only one whose size classes were
missing.

### The trap this is the ninth instance of

`public/assets/js/**/*.js` is still not scanned. Across the Help Center's JS there are ~25
further arbitrary `w-[…]`/`h-[…]` values that do not exist in the built stylesheet — mostly modal
and column widths, plus `h-[140px]`/`h-[320px]` on the reporting dashboard, where earlier passes
worked around it with inline styles instead of fixing the cause. Each is a silent no-op: the
class is written, nothing errors, and the element is simply unsized.

The standing fix remains adding `public/assets/js/**/*.js` to the Tailwind content globs and
rebuilding, which would resolve all of them at once. Not done here: it regenerates the whole
stylesheet, which is a change with a blast radius well beyond one avatar and wants to be its own
reviewed step.

## P70 — The waiting clock ran on the sender's clock (2026-08-22)

**Report.** #000009 showed **6 h 51 m** waiting on a ticket that had only been updated two hours
ago.

It was worse than a wrong label: the ticket was **2 h 58 m old** and claimed to have been waiting
**6 h 51 m**. A ticket cannot have been waiting longer than it has existed.

### Cause

`PostmarkPayload::receivedAt()` returned `Carbon::parse($data['Date'])` — the sender's own
`Date:` header, written by the customer's mail client from the customer's own clock. On this
message it was exactly **four hours** behind: sent (claimed) 13:42, actually delivered 17:42.
That value was being written into `received_at`, `last_message_at`, `last_activity_at` and
`waiting_since`.

Two visible faults, one cause:

1. **The waiting period was fiction.** It measured from a number a stranger supplied.
2. **The timeline was out of order.** Rows sort on `received_at`, so the customer's reply
   (claimed 13:44, actually 17:44) rendered *above* the agent's reply of 17:43 that it came
   after. That is visible in the screenshots from P66–P69 and I had looked straight past it.

### Fix — separate the claim from the observation

- **`sentAt()`** — the sender's `Date:` header. Named so nobody forgets what it is. Kept for
  display and debugging, and clamped so it can never be in the future: a clock running fast
  would otherwise sort a message above everything after it and render as "in 3 hours".
- **`receivedAt()`** — the moment of ingest, which for an inbound webhook is within seconds of
  delivery. **Every clock uses this**: the waiting period, the queue order, last activity. They
  measure our own responsiveness, so they must be built from facts this application observed.

New column `sent_at` keeps the claim beside the observation, so nothing is lost and
"why does this say 1:42?" stays answerable.

### The backfill only touches impossible rows

Messages: `sent_at` takes the old `received_at`, and `received_at` takes `created_at` — the
INSERT time, which is the best record we have of when the mail actually reached us.

Requests: only those whose `waiting_since` or `last_message_at` **predates the ticket's own
`created_at`** are corrected. A ticket that has genuinely been waiting three days is left exactly
as it is — this is a correction, not a reset of everybody's queue. Where the wait is rebuilt it
restarts at the last *customer* message, which is the moment an answer became owed; when the last
message is ours, the customer holds the conversation and the clock is left alone.

Verified: every Request in the workspace now reports a waiting period within its own age.
#000009 reads **2 h 58 m** against an age of 2 h 59 m, its headline shows the real arrival
(5:42 PM, not 1:42 PM), and its timeline runs System → agent reply → customer reply, which is the
order the events actually happened in.

### `sent_at` is reference data, and must be labelled as such

Confirmed as an explicit requirement after this shipped:

> `sent_at` may be stored for reference, but it must not be treated as the authoritative system
> timestamp… Do not label it simply as **Sent at**, because that could imply ProjectBlock
> verified the timestamp.

**The rule, for anyone adding a screen later.**

- `sent_at` is **not displayed anywhere today**, which is why nothing needed changing here. If
  it is ever surfaced it must be labelled as the sender's claim — *"Sender-reported sent time"*,
  *"Sender timestamp"*, *"Reported sent at"* — never a bare "Sent at".
- **Sorting, SLA, waiting duration, reporting and audit history all use `received_at`**, the
  system-observed value. That is already true by construction: `received_at` is what the
  timeline sorts on, what `waiting_since` and `last_message_at` are built from, and what
  `SpaceReport` reads.
- Nothing computes with `sent_at`. Searching for it should return this file, the migration, the
  model's fillable list, and the single write in `InboundIngestor` — if it ever appears in an
  ordering, a subtraction or a comparison, that is the bug this note exists to catch.

The value of keeping it is preserving what the sender told us without letting an untrusted number
into a calculation or onto a screen as verified fact.

**A name collision worth knowing about.** Two other models already have a `sent_at`, and both are
the opposite kind of value — ours, not a stranger's:

| Column | Means | Trusted? |
|---|---|---|
| `help_center_messages.sent_at` | when the CUSTOMER'S client says they sent it | **no** |
| `help_center_ratings.sent_at` | when WE sent the rating request | yes |
| `help_center_inbound_tests.sent_at` | when WE sent the test probe | yes |

The last two are read for timeouts, reminders and elapsed time, and rightly. Only the message's
is untrusted, and it is the one that must never be computed with.


## P71 — Spaces are an accordion (2026-08-22)

**Requirement.** "If multiple Spaces are listed, only one Space can be expanded at a time…
Clicking an already expanded Space should collapse it. At no point should two or more Spaces be
expanded simultaneously."

### Not the native attribute

`<details name="...">` expresses exactly this in one word, and it is not used. It is recent
enough that a colleague on an older browser would get the previous free-for-all with no sign
anything was wrong, and a navigation rule that holds on some machines and not others is worse
than one written out.

### Driven by click, not by `toggle`

The first version listened for the `toggle` event and closed the other panels from there. It read
correctly and it was wrong: **`toggle` fires asynchronously**, so three clicks in quick succession
open all three panels before a single handler has run, and the handlers then race to tidy up
after each other. A scripted burst of twelve clicks reached **three panels open at once**.

"At no point" is the requirement, and once per fast double-click is a point. So the click on the
`<summary>` is intercepted, `preventDefault()` takes the decision away from the element's own
default toggle, and `open` is set synchronously — each interaction resolves completely before the
next begins. Keyboard is covered: Enter and Space on a `<summary>` dispatch a click.

Re-run of the same burst, extended to eighteen clicks including repeats on one panel:
`maxSimultaneouslyOpen: 1` throughout.

### Storage

The one open Space's id, under `pb.helpcenter.space`. The previous key held
`{id: true, id: true}` — a shape that cannot represent this rule — and is left behind rather than
migrated, because all it records is which Spaces were open under the old behaviour and there can
no longer be more than one.

**The Space being viewed wins over anything remembered.** Landing inside a Space that looks shut
is the one outcome nobody would choose, which is why the server marks it `data-current`.

### Verified

Expanding B collapses A; clicking the open panel collapses it and leaves none open, clearing the
memory; the choice survives navigation to a non-Space screen; navigating *into* a Space overrides
the remembered one and leaves exactly that Space open. Exactly one panel open at every point in
an eighteen-click burst.

## P74 — The agent's signature moves to My Profile (2026-08-22)

**Requirement.** A Signature field under My Profile, saved against the user, appended automatically
when an agent replies to a customer — visible in the composer so it can be reviewed or edited,
never on internal notes or system entries, and absent entirely when nobody has configured one.
Priority: **Agent → Space Default → None.**

### What already existed

P48 built agent signatures **per Space** (`help_center_signatures.user_id` beside
`help_center_space_id`) and substituted them into the Agent Reply template as
`{{agent_signature}}` at send time. So two things had to change: where the agent's signature
lives, and when it becomes visible.

### `users.signature`, and why plain text

The requirement is explicit — "save the signature against the user profile" — and it is right: a
person's sign-off follows them, and an agent working three Spaces should not write it three
times.

Stored as **plain text**, rendered to HTML on read via `RichTextSanitizer::fromPlainText()`.
The requirement asks for "a rich-text **or** formatted text field" and its own example is three
plain lines; the account dialog is plain JS, so the project's rich editor (`pg-editor`, a Vue
component) cannot be mounted there without turning that dialog into a Vue island for one field.
A textarea also round-trips its own content exactly, which HTML in a textarea does not.

### Priority, with the old rows still honoured

    profile signature → this Space's row for this agent (P48) → Space default → none

Four tiers where the requirement names three, because the middle two are both "the agent's
signature" and one exists only so nothing anybody configured stops working. When the per-Space
agent editor in Space Settings is retired, that tier goes with it and the order is exactly the
three asked for.

### The duplication this would have caused

The Agent Reply template body is `…{{reply_content}}{{agent_signature}}…`. Pre-filling the
composer puts the signature inside `reply_content` — so leaving the tag alone would have sent
the customer **two** copies, one of them the version the agent had just edited and one of them
not.

`EmailTemplateRenderer::variables()` gained a `$signatureInReply` flag. **True** for a real send
and for a retry of one, where the stored body already carries whatever the agent sent. **False**
for the preview and the test email, where there is no composer and the tag is the only thing that
can show an administrator where a signature lands.

Verified: a composed reply carrying the signature renders it **once** in the sent email; the
preview path still resolves the tag; an agent with nothing configured gets an empty string.

### Seeding the composer, without eating a draft

`seedSignature()` fills `replyBody` only when it is **empty**. That guard is the whole of the
logic and it is not hypothetical: this runs on every drawer refresh, and a live update (P67)
refreshes the drawer while somebody is typing — an unconditional append would push a second
signature into a half-written reply every time the customer sent something.

The signature is resolved on the **server** by the same `SignatureResolver` the send uses, so
what the agent sees is what would have gone out; a composer that built its own would be a second
answer to the priority order.

**Internal notes are untouched by construction.** `replyBody` is the only variable this method
names — the note field and the update field are different state, so there is no path by which a
signature reaches them.

### Verified

Profile field renders full width in the account dialog, saves, and reloads into the textarea
unchanged (`Thanks, / Eldon M. Pinson / Customer Support`). Resolver: profile signature wins;
clearing it falls through to the P48 Space row; deleting that falls through to none. Test rows
and the test signature were reverted afterwards.

**Not verified in the browser:** the composer pre-fill on a real ticket. The signed-in session in
this environment belongs to a user with no Help Center Space, and signing in as one would mean
entering somebody's password. The server half — what the drawer payload carries and what the
send renders — is verified above; the one unproven step is the JS assigning it into the editor.

---

## P75 — Company & Customer, and Ticket Metadata Mapping (2026-08-23)

### Requirement

Rename the Space's **Company** setting to **Company & Customer** throughout, turn it into a page
of five feature switches, and add a **Ticket Metadata Mapping** table underneath that maps
fields carried by an inbound request onto Customer and Company records. When the feature is on,
a **Company & Customer** item appears in the Help Desk navigation after **Spam**, leading to a
Customers / Companies management area with per-record profiles. Inbound processing runs the
mappings, matches or creates the Customer and the Company, links both to the Request, and writes
an activity entry for anything it changed.

### User roles

| Role | May |
|---|---|
| Workspace owner / admin, Space lead | Turn the five switches on and off, author custom fields, author mappings, run Reprocess |
| Space member (agent) | Read the Company & Customer area, open a Customer or Company profile, see the ticket panel |
| Customer | Nothing — they are the subject of these records, not a user of the application |

### Database fields

**`help_center_companies`** (new, TENANT-scoped)
`id`, `tenant_id`, `name`, `domain`, `phone`, `external_id`, `tags` (json), `first_seen_at`,
`last_activity_at`, `created_by`, timestamps.
Unique: `(tenant_id, domain)`, `(tenant_id, external_id)`.

**`help_center_customers`** (existing, added columns)
`help_center_company_id` (fk, nullable), `tags` (json), `last_activity_at`.
The existing free-text `company` column stays: it is what a person typed before Companies were
rows, and dropping it would lose that.

**`help_center_customer_fields`** (new, TENANT-scoped) — the mirror of `help_center_company_fields`:
`id`, `tenant_id`, `help_center_space_id`, `name`, `type`, `is_required`, `is_active`,
`options` (json), `position`, `created_by`, timestamps.

**`help_center_custom_field_values`** (new, TENANT-scoped) — one table for both sides:
`id`, `tenant_id`, `field_kind` (`customer`|`company`), `field_id`, `owner_id`, `value` (text),
timestamps. Unique: `(field_kind, field_id, owner_id)`.

**`help_center_metadata_mappings`** (new, TENANT-scoped)
`id`, `tenant_id`, `help_center_space_id`, `source` (source-field key), `record_type`
(`customer`|`company`), `destination` (attribute key, or `custom_field`), `custom_field_id`
(nullable fk), `is_active`, `position`, `created_by`, timestamps.

**`help_center_requests`** (existing, added columns)
`help_center_company_id` (fk, nullable), `inbound_metadata` (json — the parsed source values as
they arrived, kept for audit and for Reprocess).

### Business rules

1. The five switches live in the Space settings row's `metadata` map: `company` (the master,
   relabelled **Company & Customer**), `customer_management`, `company_management`,
   `customer_custom_fields`, `company_custom_fields`, `ticket_metadata_mapping`.
2. A mapping is `(source, record_type, destination)`. `destination = custom_field` requires a
   `custom_field_id` belonging to the same Space and the same record type.
3. Customer matching order: External Customer ID → email → nothing. Never two Customers for one
   email in one workspace.
4. Company matching order: External Company ID → domain → name. Never two Companies for one
   domain in one workspace.
5. A matched record LEARNS empty fields; it never overwrites a value that is already there.
   The one exception is `last_activity_at`, which is a fact about the ticket, not about the
   person.
6. Changing a mapping applies to new inbound data only. Historical records are rewritten only by
   an explicit **Reprocess Existing Records** action, behind a typed confirmation.
7. Every automatic create or update writes a `HelpCenterRequestActivity`-style entry naming the
   old value, the new value and the mapping that caused it.

### Acceptance criteria

- Settings → **Company & Customer** exists at `/help-center/spaces/{space}/settings/company`; no
  screen, nav item or payload still says "Company" alone.
- The five switches persist and survive a reload.
- **Company & Customer** appears in the Help Desk nav after **Spam** only while at least one
  Space has the master switch on.
- A mapping can be added, edited, deleted and individually disabled.
- A Space's Customer and Company custom fields appear as mapping destinations.
- An inbound email from `john@acme.com` with a Company domain of `acme.com` on file links the
  Request to that Company without creating a second one.
- A second email from the same address links to the same Customer.
- `customer_id` and `company_id` are on the Request row, not merely rendered.
- The Request's right-hand panel shows the Customer block, the Company block, and the enabled
  custom fields.

### Real-time / queue / audit

- No new broadcast: the Inbox already refreshes on `TicketBroadcaster` (P67), and the panel is
  read with the ticket.
- Mapping runs INSIDE the existing `IngestInboundEmail` job — it is part of ingest, not a second
  job that could land after the ticket is already on screen.
- Reprocess is queued: it is a bulk update over every Request in a Space.
- Audit entries go to the Request's activity feed and to the record's own history.

### Decisions

| # | Decision | Why |
|---|---|---|
| HC-D51 | Companies are **workspace-scoped**, not Space-scoped, although §8 of the requirement draws `Space → Companies` | `HelpCenterCustomer` is already workspace-scoped, and a workspace-scoped Customer pointing at a Space-scoped Company is a relationship that cannot hold. Space-scoping would also mean one Acme row per Space, which directly contradicts the requirement's own "prevent duplicates wherever a configured unique identifier exists". Custom FIELDS stay Space-scoped, as `help_center_company_fields` already is — the field list is a Space's questions; the record is the workspace's. |
| HC-D52 | The URL segment stays `company` | A URL is a promise (HC-D49). The label changes; the segment does not, so existing links keep resolving. |
| HC-D53 | Customer custom fields mirror `help_center_company_fields` rather than sharing one table with a `kind` column | The company table exists, is Space-scoped, is already read by a controller, a request and a modal, and adding a discriminator to it would mean migrating live rows to gain nothing. The VALUES do share one table, because nothing reads them apart. |
| HC-D54 | One `help_center_custom_field_values` table with `field_kind` | The two sides store identical shapes and are always read per-owner. Two tables would be the same code twice, and the mapping engine would have to branch on which one to write. |
| HC-D55 | The five switches live in the existing `metadata` JSON map | It is already the per-Space feature-flag bag, already has defaults, a validator and a save path, and already carries `company`. Five new boolean columns would be five migrations for flags nothing joins on. |
| HC-D56 | Mapping is stored as rows, not as one JSON blob on the settings row | Each row is edited, reordered and disabled on its own, and a destination points at a custom field by id — a foreign key a JSON blob cannot carry. |
| HC-D57 | The nav item is shown when ANY Space has the feature on | The item sits in the top-level Help Desk nav, which is not per-Space. The requirement's "only when enabled for that Space" is satisfied by the page itself, which lists only the Spaces that have it on. |

### Files

**New**
- `app/Models/HelpCenterCompany.php`, `HelpCenterCustomerField.php`, `HelpCenterCustomFieldValue.php`, `HelpCenterMetadataMapping.php`
- `app/Models/Concerns/IsHelpCenterCustomField.php` — the behaviour `HelpCenterCompanyField` and `HelpCenterCustomerField` share (HC-D53).
- `app/Services/HelpCenter/Metadata/` — `TicketMetadata` (the parsed source map), `MetadataMapper` (the engine), `CompanyMatcher`, `RecordChanges` (the audit sentences), `CompanyCustomerDirectory` (the §11–§13 rows).
- `app/Http/Controllers/HelpCenter/CompanyCustomerController.php`, `CompanyController.php`, `CustomFieldController.php`, `MetadataMappingController.php`
- `app/Http/Requests/HelpCenter/MetadataMappingRequest.php`
- `app/Jobs/ReprocessSpaceMetadata.php`
- `resources/views/help-center/company-customer.blade.php`, `company-customer-profile.blade.php`
- `public/assets/js/help-center/company-customer.js`, `company-customer-profile.js`
- Seven migrations, `2026_08_23_000001` … `000007`.

**Renamed**
- `CompanyFieldController` → `CustomFieldController`; `CompanyFieldRequest` → `CustomFieldRequest`. Both now take a `{kind}`.
- Routes `spaces.company-fields.*` → `spaces.custom-fields.*` with a `{kind}` segment.

**Changed**
- `config/help-center.php` — `metadata` gains five keys and relabels `company`; `space_settings_nav`'s `company` entry becomes `kind => company_customer`; new `mapping_sources`, `mapping_destinations`, `mapping_defaults`, `mapping_max`.
- `HelpCenterCustomer` — `companyRecord()` relation (NOT `company()`; the free-text column already owns that name and Eloquent resolves an attribute first), `tags`, `last_activity_at`, `search()`.
- `HelpCenterRequest` — `company()`, `inbound_metadata`.
- `HelpCenterSpace` — `customerFields()`, `metadataMappings()`, `featureEnabled()`, `COMPANY_SUB_FEATURES`.
- `HelpCenterSpaceSettings::feature()`.
- `CustomerMatcher` — External Customer ID before email; survives losing an insert race.
- `InboundIngestor` — runs the mapper inside the ingest transaction, for replies as well as new Requests.
- `RequestController` — `companyCustomer` on the detail payload (§10).
- `SpaceSettingsController` / `UpdateSpaceSettingRequest` — the `company_customer` kind.
- `HelpCenterNavigation::companyCustomer()`, the composer in `AppServiceProvider`, and `partials/help-center-nav`.
- `public/assets/js/help-center/space-settings.js` — the new panel; the field methods take a kind.
- `public/assets/js/help-center/inbox.js` — the Company & Customer section of the drawer panel.

### Two defects found while testing this, and fixed

- `HelpCenterCompany::normaliseDomain()` used `#` as the delimiter of a pattern whose character
  class contains a literal `#`. It failed silently, returned null, and every domain normalised to
  nothing — so `acme.com` never matched and every ticket created a new company. Delimiters are
  `~` now.
- The unique key on `help_center_metadata_mappings` cannot enforce one-mapping-per-destination for
  ATTRIBUTE destinations: `custom_field_id` is null there and MySQL treats repeated NULLs as
  distinct. Two mappings both writing Customer → Email were accepted and only resolved at ingest,
  by `position`. `MetadataMappingRequest::checkDuplicate()` now refuses it with a message naming
  the destination; the index stays as the backstop for Custom Field destinations, where it works.

---

## P76 — The Settings sidebar stops at the fold; the last card sits on the bottom edge (2026-08-23)

### Requirement

On `/help-center/spaces/{space}/settings/company`, the left Settings menu must run to the full
height of the content area, growing whenever the page does, with no gap beneath it — and the
content area needs 200px of bottom padding so the final card is not pinned to the bottom of the
window. No fixed pixel height that breaks when a section is added.

### What was wrong

`resources/views/help-center/space-settings.blade.php` opened the three-column row as
`flex items-start flex-1 min-h-0`. `min-h-0` lets a flex item shrink below its own content, so
the row was sized to the leftover VIEWPORT height and nothing more. The `<aside>` stretches to
the row (`self-stretch`), so on any page taller than one screen the sidebar and its right-hand
border ended at the fold and left white space under it for the rest of the scroll.

It was correct when it was written (P55): every panel then was one card, and `min-h-0` was what
made the border reach the bottom on a SHORT page. Company & Customer is the first Settings page
with three cards, two field tables and a mapping table, which is what exposed the other half.

### The fix

- The row is `flex items-start flex-1`. Without `min-h-0` its min-height is `auto` — its content
  — and `flex-1` still grows it to fill a short page. The height is `max(leftover, content)`,
  which is both cases at once and has no number in it to go stale.
- The content column is `flex-1 min-w-0 pb-[200px]`. On the CONTAINER rather than inside each
  panel: there are ten panel kinds building their own padding in JS, and ten copies of one value
  is ten places for it to drift. It also grows the row, so the padding and the sidebar's height
  stay one measurement instead of two that have to agree.

### Verified in the browser

| | Long page (Company & Customer) | Short page (Auto Follow on Mention) |
|---|---|---|
| Row height | 1494 | 747 |
| Aside height | 1494 | 747 |
| Gap under aside | **0** | **0** |
| Content `padding-bottom` | **200px** | 200px |
| Page scrolls | yes (1572 > 840) | no — the padding adds no spurious scrollbar |

Members, which is the one section that is a Tabulator grid rather than a settings panel, renders
unchanged.

### Note for the next person: the compiled stylesheet is committed

`tailwind.config.js` scans `./public/assets/js/**/*.js` as well as the Blade views, so an
arbitrary value like `pb-[200px]` written in either place IS emitted — but only by
`npm run build:css`, and `public/assets/css/tailwind.css` is a committed build artefact. A change
that introduces a new arbitrary value must rebuild it in the same change, or the class silently
does nothing. `min-w-[820px]` and `min-w-[640px]` (P75's tables) were in exactly that state until
this rebuild.

---

## P77 — "Could not send the reply" on a reply that was already sent (2026-08-23)

### The report

Replying to a ticket showed **"Could not send the reply."** The reply was stored anyway.

### What was happening

```
RequestController::reply()          ← message stored, email handed to Postmark
  └─ RequestActivity::record()      ← the last line before the success response
       └─ TicketBroadcaster::updated()
            └─ fire() → HelpCenterTicketEvent::dispatch()
                 └─ BroadcastException: cURL error 7, :8080 refused
```

`HelpCenterTicketEvent` is `ShouldBroadcastNow` on purpose — a queue worker nobody is running is
an update that never arrives — so the HTTP call to Reverb happens inline, inside the agent's
request. With Reverb not running, that call threw, and because every write path in the module
funnels through `RequestActivity::record()`, the exception came out of the **controller**. The
request 500'd and the client showed its generic failure copy.

Replying was the worst case rather than a typical one. By the time the activity row is written,
the message is stored AND the email is already away — so the customer had their answer, the
ticket had the message, and the agent was told it failed. The obvious response to that is to
send it again.

`TicketBroadcaster`'s own docblock had promised the opposite since it was written: *"Nothing here
throws. A ticket is not lost because a websocket server is down."* Nothing enforced it.
`InboxNotifier::dispatch()` had the guard the whole time — the Help Center's broadcaster was the
one place in the codebase missing it.

### The fix

`TicketBroadcaster::fire()` wraps the dispatch in `try { … } catch (Throwable)` and logs
`help-center.broadcast.failed` at WARNING. Every method on the class funnels through `fire()`, so
one catch covers created / replyReceived / updated / unreadChanged and every caller of them.

`Throwable`, not `BroadcastException`: the failure modes are a refused connection, a DNS failure,
a TLS error and a serialization error in the payload, and none of them is a reason to lose an
agent's work. WARNING rather than ERROR because a permanently down Reverb IS worth noticing — no
screen updates itself — but it is not a failure of the action the person took.

Verified with Reverb deliberately down: `TicketBroadcaster::updated()` and
`RequestActivity::record()` both return normally and log one warning each.

### Also, in the same pass

The reply toast said **"Reply sent to name@example.com."** and now says **"Reply sent."**

The address added nothing — the agent opened the ticket, the thread is on screen with the
customer's address at the top of it, and the recipient was never a choice made at that moment.
What it did do was put a customer's email address in a floating banner, which is the one part of
the screen that gets screen-shared, screenshotted into a bug report and read over a shoulder.
`retry()` uses the same string, because a Retry is still a send and the two must not describe it
differently. The failure line is unchanged; it never named the address, and its job is to say the
message is on the ticket rather than gone.

---

## P78 — The ticket timeline reads newest first (2026-08-23)

### Requirement

On a ticket, the entries must list latest → oldest.

### The change

`public/assets/js/help-center/inbox.js`, the `timeline` computed — one line, `.slice().reverse()`.

It read newest-LAST: a conversation reading downward, the shape of an email client. On a ticket
that is the wrong way round. An agent opening one wants the last thing that happened, and on a
thread that has run for a week that meant scrolling past everything they had already read to
reach it.

Three things make this one line rather than seven:

- **Every tab is a filter over this one list.** All, Activity, Reply to Customer, Internal Notes,
  Updates, Transition and History all read `timeline`, so one reverse flips them together and
  they cannot disagree about the order.
- **The drawer and the standalone ticket page share the file.**
  `resources/views/help-center/request.blade.php` loads `inbox.js`, so `/spaces/{id}/requests/{id}`
  and the Inbox drawer changed at once.
- **The server was deliberately left alone.** `HelpCenterRequest::messages()` is documented
  oldest-first and `RequestController::reply()` reads its LAST row to build the `In-Reply-To`
  threading header. Reversing the relation would have silently threaded every reply off the wrong
  message, and inbound answers would have started opening new tickets instead of joining the
  thread. The order things happened in is the server's; the order they are READ in is the
  screen's.

`slice()` before `reverse()` because `reverse()` mutates and the array belongs to
`drawer.detail` — reversing in place would flip the stored payload too, so the next recompute
would flip it back and the list would appear to toggle its own order.

The Reply tab's comment changed with it: the composer sits under the list, so the message being
answered is now the one directly above it.

Verified on `/help-center/spaces/12/requests/9`: 18 hours ago → 19 hours ago → 19 hours ago →
1 day ago.

---

## P79 — The Settings menu sticks (2026-08-23)

P76 made the Settings column stretch to the full content height, and it measured correct: aside
1629px = content 1629px, gap 0. It still looked broken, because the thing that stretched was the
BACKGROUND — scrolled to the bottom of Company & Customer, the menu items had scrolled away and
what remained was a tall empty white column.

### The fix

Two elements doing two jobs, in `resources/views/help-center/space-settings.blade.php`:

- The `<aside>` keeps `self-stretch` and stays as tall as the content. It draws the column and its
  right-hand border all the way down — P76's fix, unchanged.
- A new `<div class="sticky top-0 max-h-screen overflow-y-auto">` inside it holds the header and
  the `<nav>`. That is the menu, and it stays in view.

Making the **aside itself** sticky does not work and is worth writing down: it is as tall as the
content, and a sticky element taller than the scrollport has nowhere to stick, so it simply
scrolls. The tall thing has to stay put and the short thing has to stick inside it.

`top-0` is the top of the SCROLL CONTAINER, not of the window — the toolbar and the Space tabs
live inside that container and scroll away above it, which is why the menu ends up directly under
the topbar rather than 134px down.

`max-h-screen overflow-y-auto` never engages today: thirteen 32px rows plus the header is ~480px
against an 840px scrollport. It engages the day somebody adds enough settings sections to outgrow
the window, and without it the last few would be unreachable.

`<nav>` lost its `flex-1`, which only had meaning while it was a direct flex child of the aside.

### Verified

| | Menu | Aside |
|---|---|---|
| Scrolled to top | 134 → 668 | 134 → 1763 |
| Scrolled to bottom (867px) | **56 → 590** (pinned) | -733 → 896 (still full height) |

`getComputedStyle(menu).position === 'sticky'`, and the menu is on screen at the bottom of the
scroll.

---

## P80 — "Open Ticket in New Window" (2026-08-23)

### Requirement

Rename the row menu's **Open Ticket** to **Open Ticket in New Window**, and have it open the
full-screen ticket in a new tab instead of the Inbox drawer, leaving the Inbox untouched.

### What already existed

The full-screen ticket page (P46) and its dedicated URL,
`/help-center/spaces/{space}/requests/{request}`. The drawer already links to it — the `expand`
icon in its header — and `requestUrl(row)` already resolved it per row, resolving the ROW's own
Space so a row in the cross-Space queue opens its own ticket rather than one in whichever Space
the screen happens to be showing.

So this is the menu item, and nothing else.

### The change

`public/assets/js/help-center/inbox.js` — the item was a `<button @click="open(menu.row)">`, which
opened the drawer. It is now:

```
<a :href="requestUrl(menu.row)" target="_blank" rel="noopener" @click="closeMenu">
  Open Ticket in New Window
</a>
```

An anchor rather than a button calling `window.open()`, deliberately:

- middle-click and cmd/ctrl-click keep working, which they cannot on a button;
- popup blockers leave a real link alone;
- the browser shows the destination on hover, so the action is inspectable before it is taken.

`open()` is untouched and still runs on a row click, so the drawer is unchanged. The Inbox
preserves its filters, search, scroll position and selected view for the simplest possible
reason: with `target="_blank"` nothing on that page navigates.

### The URL stays `/requests/`, not `/tickets/`

The requirement offers `/help-center/spaces/{space_id}/tickets/{ticket_id}` "for example". The
existing address already satisfies every behaviour asked of it — refresh, bookmark, share,
back/forward — and renaming it would break every link already copied out of "Copy Ticket Link",
every URL in a broadcast payload, and every link in a sent notification email. A URL is a promise
(HC-D49, P46). The word in the path is the only thing `/tickets/` would change.

### Verified in the browser

Menu item renders as `<a href="…/spaces/12/requests/9" target="_blank" rel="noopener">Open Ticket
in New Window</a>`. Clicking it opened a second tab on that URL while the first stayed on
`/help-center/spaces/12/inbox`.

The new tab carries the full ticket: subject and number, status, assignee, priority, tags, the
metadata block (Workflow, Channel, Inbox, Created, Updated), the customer panel, the Company &
Customer section (P75), all six timeline tabs, the reply composer and the actions menu — 1504px
of a 1792px window.

---

## P81 — The Overview header was being squashed onto its own border (2026-08-23)

### The report

On a Space's Overview, the header's buttons sat against the bottom border. It should match the
Inbox header.

### The cause

Both headers are the SAME markup — `flex items-center gap-2 px-5 sm:px-8 h-12 border-b border-line`
— inside the same parent, the layout's `<main class="flex-1 min-w-0 overflow-y-auto flex flex-col">`.
Measured, they were not the same at all:

| | Bar height | Above the button | Below the button |
|---|---|---|---|
| Inbox | 48px | 8px | 9px |
| Overview | **33px** | **0px** | **1px** |

`h-12` sets `height: 3rem`, but a flex item defaults to `flex-shrink: 1`. The bar is a flex child
of a column whose content — the Overview's charts and tables — is far taller than the container,
so the browser recovered the overflow from every child that would give, and `h-12` became a
suggestion. The 32px buttons then filled a 33px bar.

Inbox looked right only by luck: its list carries its own height and its own scroll
(`height="calc(100vh - 280px)"`), so that column happened to fit and nothing was asked to shrink.
Nobody had written different spacing for the two pages — there was no spacing difference to find,
which is why this needed measuring rather than reading.

### The fix

`shrink-0` on the bar, so it cannot be compressed. Applied to all four copies of the header —
`help-center/space.blade.php`, `inbox.blade.php`, `overview.blade.php`,
`space-settings.blade.php` — plus `partials/help-center-space-tabs.blade.php` and the settings
screen's `md:hidden` chip row, which are flex children of the same column and carry the same
latent bug. Three of them had not been reported yet; they are the same header and the same word.

No padding was changed. The requirement asked for "the same top padding, bottom padding,
left/right spacing, header height" as the Inbox, and the markup was already identical — the bar
simply has to be allowed to be the height it already asks for.

### Verified

Overview now measures 48px with 8px above and 9px below the button — identical to the Inbox,
including the 1px asymmetry, which is the border sitting inside the box.

Responsive behaviour is unchanged by construction rather than by measurement: `shrink-0` only
prevents vertical compression, the `px-5 sm:px-8` horizontal padding is untouched, and the class
list is now character-for-character the Inbox's. The browser-resize tool in this environment did
not change the page's reported viewport, so this was not confirmed at a narrow width.
