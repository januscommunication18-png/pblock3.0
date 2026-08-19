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
| HC-D28 | A reply reopens a closed conversation | A customer answering a closed thread is continuing the old problem, not raising a new one; leaving it closed would hide their reply from every view except Closed. |

## Not built

- **Attachments.** Postmark sends them base64 in the payload; storing them needs a disk decision
  and a size policy, and the message body is useful without them.
- **Outbound replies.** `direction` exists and only `inbound` is ever written.
- **Spam handling beyond the flag.** `X-Spam-Status` is read into `is_spam`; nothing acts on it.
- **The conversation UI.** The rows exist and the six views are query scopes on the model; the
  Space's Conversations screen still renders its empty state.
