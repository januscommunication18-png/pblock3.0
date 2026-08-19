# Help Desk

Source: *ProjectBlock Help Desk* — the ten phase documents. This file is the build spec: it
turns those requirements into decisions for THIS codebase and records what each slice does and
does not cover. FR references are to the phase document named in the heading above them.

| Phase | Title | Status |
|---|---|---|
| 1 | Enablement, Setup & Membership | **done** (slices 1–4) |
| 2 | Inboxes, Email Channels & Routing Foundation | **done** (slices 1–3) |
| 2a | Inbound Email Addresses & Postmark Inbound (addendum) | **done** |
| 2b | Spaces & Inbox Assignment (addendum) | **done** |
| 2c | Continue to Setup Inbox (addendum) | **done** |
| 3–10 | Conversations onwards | not started |

---

# Phase 1: Enablement, Setup & Membership

## Requirement

Help Desk is a workspace-level application, switched on per workspace, with **its own
membership and role model**. Being a workspace admin does not make you a Help Desk agent, and
being a Help Desk agent grants nothing elsewhere. FR intro, and §5's rule: workspace role,
project role, Help Desk role and inbox access are evaluated **independently, most restrictive
wins**.

## User Roles

| Role | Scope |
|---|---|
| Help Desk Admin | Full configuration and operational control |
| Help Desk Manager | Team operations, workload, assignment, reports |
| Help Desk Agent | Customer-facing conversation handling |
| Collaborator | Internal-only; may never send a customer reply |
| Viewer | Read-only |

Separately, a **Workspace** Owner/Admin may switch the app on and off — that is a workspace
setting, not a Help Desk permission. Enabling it deliberately grants them no Help Desk access.

## Architecture — how this fits what already exists

The workspace already has an app-enablement mechanism and `helpdesk` is already registered in
`config/workspace.php` with `available => false`. Phase 1 does **not** invent a new one:

| Existing piece | Use |
|---|---|
| `config/workspace.php` `apps.helpdesk` | Flip `available` to `true` |
| `WorkspaceApps` (`FLAGS` map) | Add `'helpdesk' => 'help_desk_enabled'` |
| `workspace_settings` table | New `help_desk_enabled` boolean, default false |
| Workspace create form + Settings → General | Both read `WorkspaceApps`, so both light up at once — which is why the app can be chosen **at workspace creation**, not only afterwards |
| `partials/app-sidebar.blade.php` | Nav entry gated on `isEnabled($ws, 'helpdesk')` |

That is the whole of FR-1.1 and FR-1.2, and it is small precisely because the pattern exists.

## Database Fields

**`workspace_settings.help_desk_enabled`** — boolean, default `false` (slice 1).

Built in slice 2, tenant-scoped per CLAUDE.md §7 except where noted:

| Table | Columns |
|---|---|
| `help_desks` | `id`, `tenant_id` (**unique** — one per workspace), `name`, `created_by`, timestamps |
| `help_desk_inboxes` | `id`, `tenant_id`, `help_desk_id`, `name`, `created_by`, timestamps. Unique name per Help Desk. Deliberately minimal; Phase 2 adds email channels and routing |
| `help_desk_members` | `id`, `tenant_id`, `help_desk_id`, `user_id`, `role`, `status`, `created_by`, timestamps, **soft deletes**. Indexed, not unique, on `help_desk_id + user_id` — a soft-deleted row would otherwise block re-adding somebody |
| `help_desk_member_inboxes` | `help_desk_member_id`, `help_desk_inbox_id` — FR-1.7's inbox-level access. **No `tenant_id`**: both sides are already scoped, and a third copy is a third thing that can disagree |

Built in slices 3 and 4:

| Table | Columns |
|---|---|
| `help_desk_invites` | `id`, `tenant_id`, `help_desk_id`, `workspace_invitation_id` (**unique**, cascading), `email`, `role`, `inbox_ids` (JSON — intent, not access), `invited_by`, `redeemed_at`, timestamps. **No token, status or expiry**: those live on the workspace invitation this rides on (H11) |
| `help_desk_activity` | `id`, `tenant_id`, `help_desk_id`, `actor_id`, `event`, `subject`, `meta` (JSON), timestamps. Append-only; labels resolved at write time |
| `help_desks.setup_completed_at` | Nullable timestamp — null means the wizard has never been finished (FR-1.3) |

Roles are **not** a table — see decision H2. `config/help-desk.php` holds the five roles, their
rank, whether they reach every inbox, and their abilities (`view`, `reply`, `note`, `assign`,
`manage_inboxes`, `manage_members`, `manage_settings`).

## Business Rules

- Enabling Help Desk **does not** grant any workspace member access to it (FR-1.1, and the
  first acceptance criterion). Access comes only from Help Desk membership.
- Disabling **hides navigation and preserves all data** (acceptance criterion 4). It is a
  visibility switch, never a delete.
- Only a Workspace Owner/Admin may toggle it — the same `guardManage()` gate every other
  settings section uses.
- Removing a Help Desk member preserves their historical replies, notes and assignments
  (acceptance criterion 5) — hence soft deletes on `help_desk_members`.
- The toggle is a security-sensitive configuration change and is logged (§13) — `WorkspaceApps`
  writes `workspace.app.enabled` / `workspace.app.disabled` with the workspace, the app and the
  actor, and only when the value actually changes.
- Help Desk membership is independent of workspace membership, but not detached from it: the
  person being added must be an **active workspace member**, and losing that loses Help Desk
  access too (§5, most restrictive wins).
- Deactivating a member keeps their role and inbox access and ends their access immediately;
  removing them soft-deletes the membership and re-adding restores the same row (H9).
- Admins and Managers reach every inbox, including ones created later; everybody else reaches
  exactly the inboxes named for them (FR-1.7).

## Acceptance Criteria (slice 1)

- A Workspace Owner/Admin sees Help Desk as a selectable app **on the workspace create form**
  and in Settings → General.
- Enabling it makes the Help Desk entry appear in the left rail; disabling removes it.
- Enabling it grants no member any Help Desk access — there is nothing to access yet, and
  slice 2 must not change that.
- Disabling and re-enabling loses nothing.
- A non-admin workspace member cannot toggle it, by API as well as by UI (§13: server-side
  authorization; hiding the control is not sufficient).
- Another workspace's setting is unaffected (multi-tenant isolation, §12 of the test list).

## Acceptance Criteria (slice 2)

- A Workspace Owner/Admin can add an existing workspace member and give them a Help Desk role
  (acceptance criterion 2).
- Enabling still grants nobody access: an ordinary workspace member gets a 404 at `/help-desk`
  and no rail entry until they are added.
- A Help Desk role grants nothing outside the Help Desk — a Help Desk Admin who is an ordinary
  workspace member is still refused Workspace Settings.
- Each role carries its own abilities, and a Collaborator can never send a customer reply.
- A restricted member reaches only the inboxes named for them; Admins and Managers reach every
  inbox, including later ones.
- Deactivating ends access at once and preserves role and inbox access; removing preserves the
  record and re-adding restores it (acceptance criterion 5).
- Every write re-authorizes server-side, and a membership or inbox from another workspace is
  not addressable (§12, §13).

## Acceptance Criteria (slices 3 and 4)

- Inviting a coworker sends one invitation; accepting it makes them a workspace member **and** a
  Help Desk member with the role and inbox access chosen for them (acceptance criterion 3).
- Accepting twice does not create a second membership, and a revoked invitation admits nobody.
- Inviting somebody who is already in the workspace is refused with a reason that points at
  "add them as a member" instead.
- An administrator who has never set the Help Desk up lands in the wizard; a member never does.
- Every step of the wizard commits as it is taken, and finishing is idempotent.
- Membership, role, status, inbox-access, invitation and inbox changes all appear in the
  activity stream, with the names things had at the time, paginated, newest first.
- The activity screen is refused to Help Desk members who cannot administer it.

## UI Requirements

Slice 1: the app appears in the create form's app picker and in Settings → General's app list
(both already render from `WorkspaceApps`), plus a rail entry.

Slice 2: inside `/help-desk` the shared sidebar's body swaps to the Help Desk's own navigation
(`partials/help-desk-nav.blade.php`) — Overview, the inboxes this person may open, and Settings
→ Members for those who may manage it. The workspace's Projects list is deliberately absent:
this is a different room, not the same room with extra doors, which is the pattern Wiki set.
The member screen is hybrid Vue-in-Blade on the shared settings runtime, so it reuses the same
combobox, modal, confirm and toast the rest of the app uses.

Slice 3: the member screen gains "Invite coworker" beside "Add member", and a Pending
invitations list with revoke.

Slice 4: `/help-desk/setup` is the wizard — Name → Inboxes → Team → Done — and
`/help-desk/settings/activity` is the audit stream, server-rendered with Newer/Older paging
because it is prose with a pager and nothing to interact with.

## Real-Time Requirements

None in Phase 1.

## Queue Requirements

None. The invitation email is sent with `sendNow` by `WorkspaceInviter`, which is the trade the
whole application already makes for invitations: an invitation nobody receives is an invitation
that did not happen, and it must not depend on a worker being up. The acceptance listener runs
synchronously for the same reason — a Help Desk that appears a few seconds after you join reads
as one that failed to.

## Audit Requirements

Enable/disable is logged (§13, §8) by `WorkspaceApps`. The browsable Help Desk activity stream
(FR-1.9) is `help_desk_activity`, following the existing per-domain activity pattern rather than
a generic `audit_events` table (decision H3). It is written by `HelpDeskActivityRecorder` from
the member manager, the inviter, the inbox controller and the wizard — never by the screens, so
a caller cannot forget. Recorded today: member added, role changed, status changed, inbox access
changed, member removed, coworker invited, invitation revoked, invitation accepted, inbox
created, inbox renamed, setup completed.

## Slices

| Slice | Contents | Status |
|---|---|---|
| 1 | FR-1.1 enable/disable, FR-1.2 nav activation, create-form + settings, data-preserving disable | done |
| 2 | FR-1.4 membership, FR-1.6 the five roles, FR-1.7 inbox-level access, FR-1.8 status/deactivation, minimal inboxes | done |
| 3 | FR-1.5 invite existing member + new coworker, acceptance producing workspace **and** Help Desk membership | done |
| 4 | FR-1.3 setup wizard, FR-1.9 audit history screen | done |

## Decisions

| # | Question | Decision |
|---|---|---|
| H1 | Enablement mechanism | Reuse `WorkspaceApps` rather than a Help Desk-specific toggle. It is what the create form and Settings → General already read, so one flag lights up every surface — and it is why Help Desk can be chosen at workspace creation. |
| H2 | `help_desk_roles` as a table | **No — fixed roles in config**, like workspace and project roles already are. The document lists a table, but the five roles are fixed, not user-defined; a table would add a join and a seeding step to store five constants. CLAUDE.md §6 says avoid overengineering Phase 1. Revisit if custom roles are ever required. |
| H3 | `audit_events` as a new generic table | **No — follow the existing per-domain activity pattern** (`ProjectActivity`, `EpicActivity`). A single polymorphic audit table across every domain is a bigger architectural change than this phase justifies, and it would sit beside the activity tables rather than replacing them. Slice 1 logs config changes; slice 4 adds `help_desk_activity`. |
| H4 | Inboxes in Phase 1 | Minimal `help_desk_inboxes` in slice 2 — enough for FR-1.7's inbox-level access to be real. Phase 2 adds email channels and routing on top. |
| H5 | Does enabling grant access? | **No.** The first acceptance criterion is explicit, and it is the whole point of the separate membership model. Enabling only makes the app exist for the workspace. |
| H6 | Who bootstraps the first member? | A **Workspace Owner/Admin administers the Help Desk without holding a Help Desk role**. Somebody has to add the first member, and the alternative — seeding whoever flipped the toggle as a Help Desk Admin — would make enabling grant access, which H5 forbids. It is administrative authority only: `HelpDeskAccess::allows()` reads the Help Desk role, so they may configure the desk and never send a customer reply, and they appear in no inbox. |
| H7 | What does the rail entry test? | Not "is the app on" but **"may this person open it"** (§4: "only for members with Help Desk access"). `HelpDeskAccess::canOpen` — app enabled ∧ active workspace membership ∧ (active Help Desk membership ∨ workspace administrator). |
| H8 | When is the Help Desk row created? | **Lazily, on the first visit by somebody who may administer it**, together with its first inbox (`config('help-desk.default_inbox')`). A workspace that switches the app on to look at it should not have rows written for a support operation it never sets up — and inbox-level access needs an inbox to grant. |
| H9 | What happens when a member is removed and re-added? | The **original row is restored**, not replaced. Their replies, notes and assignments point at that row (acceptance criterion 5), so a second membership would orphan the history and put two rows on one person. This is why `help_desk_id + user_id` is an index rather than a unique constraint. |
| H10 | Who may create a Help Desk Admin? | **Only somebody who administers the workspace** — the same asymmetry that lets only an owner make an owner. A Help Desk Admin promoting another Admin is otherwise a one-way door for whoever set the desk up. Nobody manages their own membership, for the same reason. |
| H11 | A second invite pipeline for Help Desk coworkers? | **No.** A Help Desk invite rides on the existing `workspace_invitations` row — one hashed token, one email, one acceptance screen, one seat check — with a `help_desk_invites` row recording what they get in the Help Desk. That is what makes the third acceptance criterion true by construction: accepting produces workspace membership *and* Help Desk membership. Revoking revokes the workspace invitation, because deleting only the Help Desk row would leave a live link. |
| H12 | What workspace role does an invited coworker get? | **`member`** (`HelpDeskInviter::WORKSPACE_ROLE`). Not `admin` — inviting somebody to answer support email is not a reason to hand them the workspace; not `guest` — a guest is a restricted outsider and this person works here. Their Help Desk role is chosen separately and is the one that decides what they can do in the Help Desk. |
| H13 | How does the Help Desk hear about an acceptance? | A `WorkspaceInvitationAccepted` **event**, with the Help Desk reacting in a listener (CLAUDE.md §6). Calling into the Help Desk from `WorkspaceInvitationAccepter` would make the workspace's core invite flow depend on a feature it should not know exists. The listener never fails the acceptance: they are a workspace member either way, and the recoverable half is the Help Desk membership. |
| H14 | Does the wizard have its own save paths? | **No.** Each step posts to the same endpoints the permanent screens use, so there is one set of rules, and every step commits as it is taken — a wizard abandoned at step two loses nothing. All the wizard owns is the Help Desk's name and the moment setup was declared finished. An administrator with `setup_completed_at = null` is redirected into it; a member never is. |
| H15 | Why does activity store labels rather than ids? | Because a stream that renders current names is a view of the present, not a record of the past. Renaming an inbox would rewrite the entry that recorded its creation. `subject` and `meta` are resolved at write time, which is why the rename entry can still say what the old name was. |

---

# Phase 2: Inboxes, Email Channels & Routing Foundation

Source: *Phase 2 of 10*. All three slices are built; FR references below are to that document.

## Requirement

An inbox stops being a label and becomes a place: it has an address customers write to, an
identity replies come from, somewhere new conversations land, and a stream of conversations
threaded out of arriving email.

## Slices

| Slice | Contents | Status |
|---|---|---|
| 1 | FR-2.1 shared inboxes, FR-2.2 inbound address, FR-2.3 outbound identity, FR-2.5 default assignee, FR-2.6 inbox permissions | done |
| 2 | FR-2.4 ingestion + threading, FR-2.7 conversation numbering | done |
| 3 | FR-2.8 manual routing and move between inboxes, FR-2.9 delivery failure handling | done |

## Database Fields

Added to `help_desk_inboxes`:

| Column | Notes |
|---|---|
| `inbound_address` | **Unique across every tenant** — the one column in the Help Desk not scoped to a workspace, because the outside world is not (H16). Phase 2a made it **generated and read-only** (H28) |
| `outbound_from_name`, `outbound_from_address` | The reply identity (FR-2.3). Null falls back to the inbound address and then to the application's sender, so an inbox is never unable to answer |
| `default_assignee_id` | → `help_desk_members`, `nullOnDelete`. A member, not a user: assignment is a Help Desk fact |

Added to `help_desks`: `conversation_sequence` — the per-Help-Desk case-number counter (FR-2.7).

New:

| Table | Notes |
|---|---|
| `help_desk_conversations` | `number` (unique per Help Desk, immutable), `subject`, `status`, `customer_email`/`customer_name`, `assignee_id`, `help_desk_inbox_id`, `last_message_at`, soft deletes. Indexed for inbox+status, assignee, customer and created-date (§7) |
| `help_desk_messages` | `direction`, RFC 5322 `message_id` / `in_reply_to` / `references`, from/to/cc, subject, sanitized `body_html`, `body_text`, `sent_at`. `message_id` unique per Help Desk — the constraint that makes ingestion idempotent |
| `help_desk_email_deliveries` | Slice 3. One row per recipient per provider event: `status`, `recipient`, the provider's verbatim `reason`, `event_id` (unique per Help Desk — what makes the delivery webhook idempotent), `occurred_at` |

`help_desk_conversations.delivery_failed_at` (slice 3) is a denormalized "currently broken"
flag: set on the first failure, cleared by a later success. Every conversation list wants to
show it, and asking the deliveries table per row is the N+1 §13 forbids.

## Business Rules

- One inbound address belongs to exactly one inbox, anywhere in Project Block. Routing an
  arriving message has to have one answer.
- Ingestion is **idempotent**: the same Message-ID is stored once, however many times it is
  delivered, and however many times the job is retried (§9).
- Threading is by identifier only — In-Reply-To first, then the References chain — never by
  subject. "Re: Invoice" from two customers is two cases.
- A conversation keeps its number for life, including across a move to another inbox, which is
  why numbering is per Help Desk rather than per inbox.
- Inbound HTML is sanitized on the way in, once (§13), with the same sanitizer the rich-text
  fields use.
- Mail to an address nobody owns is logged and dropped, not retried.
- A conversation keeps its number, its history and — where it can — its assignee across a move.
  An assignee who cannot open the destination is cleared, because an assignment somebody can
  neither see nor act on is not an assignment.
- Both ends of a move are authorized, not just the destination (FR-2.8).
- A failed delivery is visible **and** notifies somebody, once. The alarm is deduplicated on the
  conversation's transition into failure; the delivery history is not deduplicated at all.
- A deferral is not a failure. Mail servers defer constantly and it usually resolves itself, so
  treating it as one would train people to ignore the flag.

## Acceptance Criteria (slices 1 and 2)

- An administrator can create and configure multiple inboxes; an agent can do neither.
- The same inbound address cannot be claimed by two inboxes, in one workspace or across two.
- Inbound email creates exactly one conversation when no thread exists, numbered in sequence.
- A reply attaches to the right conversation by In-Reply-To, and by References when the client
  drops In-Reply-To.
- The same message delivered twice, or a job retried after failing, changes nothing the second
  time.
- A message lands only in the workspace that owns the address it was sent to.
- The webhook refuses unsigned requests, and refuses everything when no secret is configured.

Slice 3:

- An agent can move a conversation between inboxes they can open; a move into — or out of — an
  inbox they cannot open is refused, and a Viewer cannot move anything.
- A move preserves the number and the history, keeps an assignee who can follow it, clears one
  who cannot, and picks up the destination's default assignee when unassigned.
- A member sees only conversations in the inboxes open to them, and the inbox filter offers only
  those inboxes (§11's "unauthorized members cannot view inbox content").
- A failed outbound delivery records an event, marks the conversation visibly undelivered,
  writes to the activity stream and notifies the assignee — or the Help Desk admins when it is
  unassigned — exactly once.
- A retried delivery webhook changes nothing the second time.

## UI Requirements

Help Desk › Settings › **Inboxes** — the list says what each inbox IS: where it receives, who
replies come from, where new conversations land, how many conversations and how many members
have named access. An inbox with no inbound address says so in red, because it receives nothing
and will go on receiving nothing until somebody notices.

Help Desk › **Conversations** (slice 3) — a list, not a workspace: reading and replying are
Phase 3. It carries the inbox filter, the Move action, and the "Not delivered" badge that is
FR-2.9's visible half. Its three empty states say three different things — no inboxes are open
to you, no conversations yet, or nothing in this inbox — because they need three different
answers.

## Queue Requirements

Both webhooks authenticate and dispatch — `IngestInboundEmail` and `RecordEmailDeliveryEvent`
— returning 202. Three attempts each, backoff 5s then 30s. Retries are safe rather than merely
tolerated: ingestion dedupes on Message-ID, delivery on the provider's event id. The
delivery-failure notification is queued too, so a slow mail server cannot hold up a webhook.

## Audit Requirements

Inbox creation and renaming, conversation moves, and delivery failures are recorded in the Help
Desk activity stream. Individual arriving emails are **not**: a stream that records every message
is a stream nobody reads, and the conversation itself is the record of its own traffic. The
delivery-failure entry is written with no actor — nobody here did it, a mail server did.

## Decisions

| # | Question | Decision |
|---|---|---|
| H16 | `email_addresses` as a table | **Reversed in Phase 2a — see H29.** It was *no* while an inbox had one address and one outbound identity: a table would have modelled a many-to-one that did not exist. The Inbound Email requirements gave an inbox several customer addresses, each with its own status, so the many-to-one exists and the table does now. Routing is unaffected: it still resolves on `help_desk_inboxes.inbound_address`, never through the new table. |
| H17 | `conversation_threads` as a table | **No — a conversation IS the thread.** The identifiers that thread mail together belong to the messages carrying them; a third table holding the same relationship would be a second answer to "which conversation is this reply part of?". |
| H18 | Separate inbound/outbound message tables | **No — one `help_desk_messages` with a `direction`.** Everything that makes a message a message is identical in both directions, and splitting them would make every read of a conversation a union of two tables in date order. |
| H19 | How does mail reach the application? | A **signed webhook** at `POST /help-desk/email/inbound`, taking a normalized payload with an HMAC-SHA256 of the raw body. Provider-agnostic — Postmark, SendGrid, Mailgun and an SMTP relay of your own all map onto it. A body signature rather than a bearer token, because the payload decides who a message is from. No secret configured means the endpoint refuses everything (503): "not configured" must never mean "accepts anything". |
| H20 | Default **team** and **business hours** on an inbox | **Deferred, deliberately.** Teams are Phase 7 and business hours are Phase 5; a column pointing at a table that does not exist is not a foundation. Default *assignee* is built now because members exist. |
| H21 | Deleting an inbox | **Not built.** An inbox holds conversations, and what happens to them when it goes is a product question this phase does not answer. When it is answered it will be an archive, not a delete. |
| H22 | Conversation status in Phase 2 | A single `open`. Phase 3 owns the lifecycle; the column exists now so a row arriving today is not stateless and the indexes §7 asks for can be built before the table is large. |
| H23 | May a workspace admin move a conversation? | **No — moving is operational.** H6 gives workspace owners/admins administrative authority over the Help Desk (configure it, decide who works in it) and deliberately no operational abilities. Refiling customer mail is customer work, so it needs a Help Desk role carrying `move` and access to both inboxes. A workspace admin who needs to do it should make themselves a member, which is a decision with a record rather than a side effect of being an admin. |
| H24 | Which delivery statuses count as a failure? | Config, not a constant: `help-desk.delivery.failure_statuses` (bounced, failed, complained). A **deferral is not a failure** — mail servers defer constantly and it usually resolves itself, and a flag that lights up for deferrals is a flag people learn to ignore. An **unknown** status from a provider is treated as a failure, because whatever it was it was not "delivered" and the wrong side to err on is silence. |
| H25 | Who hears about a failed delivery? | The assignee, because it is their reply that did not arrive; failing that the Help Desk's admins, because an unassigned conversation with a bounced reply belongs to whoever runs the desk. Deliberately not everybody with inbox access — a notification everybody receives is one nobody acts on (§8: "send notifications only to authorized recipients", "must be deduplicated"). |
| H26 | Why a `delivery_failed_at` column as well as the deliveries table? | The table is the history; the column is the current state, and it exists because every conversation list wants to show it. Deriving it per row is the N+1 §13 forbids. Set on the first failure, cleared by a later success — so it means "currently broken", which is the question a list is asking. |
| H27 | Mail-only for the failure notification | The same position `PasswordChanged` records: §9 makes `database` the baseline channel, but the `notifications` table is not provisioned and the in-app Inbox is built around work items rather than conversations. When Phase 3 lands the conversation screen, this is the first notification that should gain an in-app channel pointing at it. |

---

# Phase 2a: Inbound Email Addresses & Postmark Inbound

Source: *Help Desk — Inbox Inbound Email & New Email Address Page Requirements*. Section
references (§) below are to that document. It is an addendum to Phase 2 rather than a phase of
its own: it changes what an inbox's address **is**, adds the screens for connecting customer
addresses to it, and names the Phase 1 provider.

## Requirement

Every inbox is generated a unique inbound address on a domain Project Block owns
(`support-abc123@inbound.projectblock.app`). Customers forward their existing support address to
it, Postmark receives the mail and posts it to a webhook, and it becomes a conversation in the
right inbox. Connecting a customer address is a **page with its own URL**, not a modal.

## Architecture — what changed and why

The one shape change: an inbox's address stops being something an administrator types and
becomes something the system generates. Those are two different facts and they now live in two
places.

| Fact | Where | Who writes it |
|---|---|---|
| The forwarding **destination** — unique everywhere, the routing key | `help_desk_inboxes.inbound_address` | `InboundAddressGenerator`, and nothing else |
| The forwarding **source** — what customers actually write to, with its own status | `help_desk_email_addresses.address` | An administrator, through the new page |

`inbound_address` is deliberately **absent from `HelpDeskInbox::$fillable`** and from
`StoreInboxRequest`. A request cannot mention it, because somebody's mail provider is forwarding
to it and repointing it silently breaks a rule this application cannot see. Changing it is its
own endpoint with its own confirmation (§2's "explicit administrative action").

## Database Fields

New table `help_desk_email_addresses` (tenant-scoped, soft deletes):

| Column | Notes |
|---|---|
| `help_desk_id`, `help_desk_inbox_id` | Which inbox the mail is forwarded into |
| `address` | The customer-facing address. Indexed per Help Desk, **not unique across tenants** — this column routes nothing, so two workspaces may both connect `info@agency.example` |
| `status` | `setup_required` \| `waiting_for_email` \| `connected` \| `error` \| `disabled` (§7) |
| `verification_started_at`, `verified_at`, `last_email_at` | §8's supporting information |
| `created_by` | Who connected it |

New table `help_desk_message_attachments` (tenant-scoped) — §11.6: `disk`, `path` (**nullable**),
`name`, `mime`, `size`, `content_id`, `skipped_reason`. A row without a path is a file that
arrived and was not kept.

The migration that adds the addresses table also **moves each existing inbox's typed address into
it** as `connected`, and generates a new inbound address for the inbox. Those inboxes had been
receiving at that address; demoting a working connection to "setup required" during a migration
would be the migration telling a lie about the state of the system.

## Business Rules

- An inbox has an inbound address from the moment it exists — provisioning and inbox creation
  both generate one. An inbox nothing can be forwarded to is not an inbox.
- The generated address is never derived from the tenant id: an address people publish should
  not carry a workspace identifier around with it.
- `connected` is reached **only** by a message arriving. Nothing an administrator presses can
  assert it, because the forwarding rule lives in somebody else's mail provider.
- **Verify Connection sends nothing.** It starts a clock. The test message has to travel through
  the customer's provider, so only a person writing to their support address can send it.
- A verification that outlives `help-desk.inbound.verification_window_hours` becomes `error`
  **on read**, like `WorkspaceInvitation::markExpiredIfLapsed` — a status that is only correct
  while a worker is running is a status nobody can trust.
- Disable is bookkeeping, not an off switch: mail forwarded to the inbox still arrives, and the
  screen says so. A disabled address still records `last_email_at` and stays disabled.
- Routing prefers the address the message was **delivered to** (`delivered_to`, from Postmark's
  `OriginalRecipient`) over its To header, because forwarding leaves the customer's own address
  in To. Cc counts as well: mail cc'd to support is mail sent to support.
- An arrival credits a connected address only when the message actually names it. Crediting "the
  only address on this inbox" otherwise would report that a forwarding rule works on the
  evidence of a message that never went through it.
- Attachments are capped per file and per message. A file over the limit is **recorded without
  its contents**, never dropped silently.

## Acceptance Criteria

- Every inbox has a unique generated inbound address; two inboxes never share one. ✅
- The generated address cannot be set or changed by any request, including the configure form. ✅
- Regenerating is a separate authorized action, recorded with both the old and new address. ✅
- **New Email Address** is a page at its own URL — no modal — that survives refresh, bookmarking
  and Back/Forward. ✅
- The page shows the generated address read-only, with Copy and Copy-forwarding-instructions. ✅
- Saving returns to the inbox's Email Addresses list with the success notification §9 specifies. ✅
- Status is tracked through the §7 state machine, and verification that never arrives errors. ✅
- Postmark inbound is authenticated, mapped and routed to the correct inbox; a forwarded message
  routes by the address it was delivered to. ✅
- Attachments and both bodies are associated with the resulting conversation. ✅
- An agent can reach none of these screens or endpoints. ✅

## UI Requirements

Help Desk › Settings › Inboxes now shows each inbox's generated address with a Copy button, an
**Email addresses** link, and — inside Configure — a red *Generate a new address* action behind a
confirmation that says what it breaks. The typed inbound-address field is gone.

Two new pages:

- **`/help-desk/settings/inboxes/{inbox}/email-addresses`** — §10's list: address, status badge,
  a sentence saying what the status means and what to do about it, the forwarding target, last
  email, and the actions (copy address, copy setup, verify, disable/enable, delete).
- **`/help-desk/settings/inboxes/{inbox}/email-addresses/new`** — §5's page: the customer address,
  the inbox (preselected from the URL, changeable), the read-only generated address, and §6's
  forwarding block, with Cancel / Save Email Address in the footer.

The requirements' example route is `/help-desk/inboxes/{id}/email-addresses/new`; these sit under
`/settings/` because every other inbox-configuration screen does, and a second convention for one
page would be the surprising thing.

## Queue Requirements

Unchanged in shape: the Postmark webhook authenticates, maps and dispatches `IngestInboundEmail`,
returning 202. Attachment decoding and storage happen inside that job, never in the webhook.

## Audit Requirements

Recorded: an inbound address regenerated (with both addresses), an address connected, removed,
disabled or re-enabled, and the **first** forwarded message arriving from an address — which is
written with no actor, because a mail server did it. Subsequent messages are not recorded: a
stream that records traffic is a stream nobody reads.

## Decisions

| # | Question | Decision |
|---|---|---|
| H28 | Typed or generated inbound address? | **Generated, read-only.** It has to be unique across every workspace for routing to have one answer, and asking administrators to pick a unique string on a domain they do not own is asking them to fail. Read-only because a mail provider elsewhere is forwarding to it. Changing it is `POST …/inbound-address`, with a confirmation naming the consequence. |
| H29 | A table for customer addresses (reverses H16) | **Yes.** §10 lists several addresses per inbox, each with its own status and last-email time — the many-to-one H16 said did not exist now does. Routing still resolves on `help_desk_inboxes.inbound_address`, so the hot path gained no join. |
| H30 | How is a connection proved? | **By mail arriving that names the customer address.** Nothing else can: the forwarding rule is in a system this application cannot see. That is why `connected` is written by ingestion only, why Verify starts a clock rather than sending anything, and why an unmatched arrival changes nothing. |
| H31 | Postmark's endpoint, given it does not sign webhooks | **A secret in the path**, `POST /help-desk/email/inbound/postmark/{token}`, compared with `hash_equals`; unset means 503. Weaker than the body signature the generic endpoint requires, and weaker because the provider decided so. The generic signed endpoint stays, so Postmark is a choice rather than a dependency. |
| H32 | Provider payload mapping | **A mapper class per provider** (`PostmarkInboundPayload`), not a branch inside the ingestor. The ingestor knows about messages, threads and inboxes; it does not know that Postmark capitalizes its keys. §3 names Postmark for Phase 1 only, so the next provider should be one more class, not a second ingestion path. |
| H33 | Which address does an arriving message route by? | **`delivered_to` first, then To, then Cc.** A forwarded message keeps the customer's address in To and carries the generated one only as the delivery recipient — reading To alone would drop exactly the case this feature exists for. |
| H34 | Attachments over the limit | **Recorded without contents**, with `skipped_reason`, never dropped. An agent needs to know the customer sent something; silence reads as "they sent nothing", which is how "I already sent you the screenshot" becomes an argument. |
| H35 | Where do the new screens live? | Under `/help-desk/settings/inboxes/{inbox}/…` rather than the requirements' `/help-desk/inboxes/…`. Every other inbox-configuration screen is under `/settings/`; §4's acceptance criteria are about the page having a URL of its own, which it does. |
| H36 | §11.7, "match the sender to an existing contact/customer" | **Deferred, and named here so it is not mistaken for done.** There is no contact or customer record in the application to match against — `CustomerProperty` is a project custom-property definition, not a person. What ingestion stores instead is `customer_email` / `customer_name` on the conversation, which is the key a Contacts phase would join on when it exists. Building a contacts table to satisfy one line of an ingestion checklist would be the overengineering CLAUDE.md §6 forbids. |

---

# Phase 2b: Spaces & Inbox Assignment

Source: *Help Center — Workspace & Inbox Assignment Requirements*. Section references (§) below
are to that document. An addendum to Phase 2: it puts a layer between the Help Desk and its
inboxes so one organization can run several separate support operations.

## Requirement

An organization may run more than one support operation — a brand, a business unit, a region, a
customer type — under the same ProjectBlock account, without mixing their conversations. Each one
owns one or more inboxes; each inbox belongs to exactly one of them.

    Workspace (the tenant) → Help Desk → SPACE → Inbox → Conversation

## Naming — why "space" and not "workspace"

The requirements call this a *Help Center Workspace*. It is not called that here, and the reason
is not taste: `Workspace` in this codebase **is the stancl tenant** (decision D5 in CLAUDE.md) —
the thing in the topbar switcher, the thing that owns the Help Desk. Two different things called
Workspace, one nested inside the other, both with switchers, on the same screen, is a confusion
that would outlive the wording. So the layer is a **space** (`HelpDeskSpace`, `help_desk_spaces`,
`/help-desk/spaces`), and the area stays **Help Desk** rather than being renamed Help Center.
Everything the requirements say about a "Help Center Workspace" is true of a space.

## Database Fields

New table `help_desk_spaces` (tenant-scoped):

| Column | Notes |
|---|---|
| `help_desk_id`, `name` | Unique name per Help Desk — two spaces called "Partner Support" is an ambiguity nobody can resolve from a switcher |
| `description` | §5's optional purpose |
| `types` | §5's categorization, as a JSON list of **free-text** values (H45). A space is often more than one thing, and the six the form suggests were never going to be everybody's six. Labels, not modes: nothing branches on them |
| `color` | §5's optional "icon / avatar", as a colour plus the name's initial. Not an upload — a file pipeline for decoration |
| `archived_at` | §8's Archive. Not a delete: a space owns inboxes that own conversations |
| `created_by`, timestamps | |

Added to `help_desk_inboxes`: **`help_desk_space_id`** — nullable, `nullOnDelete`. Nullable because
§12 is explicitly about an inbox that exists and has not been assigned yet, so "unassigned" has to
be a state the schema can hold. A column rather than a pivot table because §7's Phase 1 rule is
one inbox to one space; a pivot would model the many-to-many the requirements rule out.

The migration gives every **existing** Help Desk that already has inboxes one space holding them
all. An inbox that has been receiving mail for months showing as "unassigned" on the first screen
after deploy reads as data loss.

## Business Rules

- One inbox belongs to **one** space (§7). Assigning is therefore always a **move**, and there is
  one method that does it — the create page, Assign Inbox, the inbox's own settings and §11's
  create-from-a-space all go through `HelpDeskSpaceManager::assign`.
- A move keeps the inbox's conversations, contacts, routing and email configuration (§7's
  confirmation text). True by construction: a move writes one column on the inbox, and all of
  those hang off the inbox.
- Moving an inbox that already belongs somewhere is **confirmed first**, naming both ends.
- A new Help Desk is provisioned with one space holding its first inbox, the same way it is
  provisioned with the inbox itself (H8).
- A space that still holds inboxes **cannot be archived** — move them first. Orphaning five
  hundred conversations to make a row disappear from a list is not an outcome worth defaulting to.
- Space **context** (§13) is held in the session per workspace, re-checked against permission on
  every read, and it only ever **narrows**: `authorized inboxes ∩ inboxes in this space`. A space
  you are standing in can never show you an inbox you were not given.
- "All spaces" is a real selection, not an absence of one — somebody running two operations needs
  a place to see both.
- Archived spaces never appear in the switcher.

## Acceptance Criteria (§20)

- Help Desk includes a Spaces section. ✅
- Users can create multiple spaces. ✅
- A space can have one or more inboxes. ✅
- An inbox belongs to one space. ✅
- Existing inboxes can be assigned to a space, including ones in none. ✅
- New inboxes created from a space are automatically assigned to it. ✅
- Users can move an inbox between spaces, with the confirmation §7 specifies. ✅
- Switching space changes the Help Desk context. ✅
- Conversation data stays scoped to the selected space. ✅
- Users only see spaces they have permission to access. ✅
- Multiple businesses can run separate spaces under the same account. ✅

## UI Requirements

- **`/help-desk/spaces`** — §8's list: name, type chips, description, and inboxes / members /
  open conversations per row. Row actions are **icons with tooltips** — a pen for Edit and a
  trash for Archive, each carrying `data-tip` for the pointer and an `aria-label` for everything
  else, because an icon with neither is a button only its author can read. Both open the same
  dialogs the words used to. Unassigned inboxes are called out at the top, because an inbox in
  no space still receives mail. §19's empty state when there are none.
- **`/help-desk/spaces/new`** — §5's creation page: name, description, types, colour, and §6's
  inbox selector with search, multi-select, name, address and **current assignment** — the last
  of which is what turns an assign into a visible move. Types use the shared `<pb-tags>` chip
  field: type a value, press Enter or comma, get a removable chip; Backspace on an empty box
  takes the last one back; text left in the box is committed on blur rather than dropped.
- **`/help-desk/spaces/{space}`** — §9's detail page and §10's inbox section, with Assign Inbox
  and New Inbox. Opening it also puts the space in context.
- The Help Desk sidebar gains §13's switcher at the top, and Spaces as a primary section. The
  inbox list below the switcher narrows to the space in context.
- Settings › Inboxes gains a **Space** field (§12) and says so on rows that are in none.

## Queue Requirements

None. Everything here is a synchronous configuration change.

## Audit Requirements

Recorded in the Help Desk activity stream: a space created, renamed, archived or restored, and
every inbox assignment — with both ends stored as names resolved at write time, so renaming a
space later cannot rewrite what a move said at the time.

## Decisions

| # | Question | Decision |
|---|---|---|
| H37 | What is this layer called? | A **space**, not a workspace. `Workspace` is already the tenant (CLAUDE.md D5); two nested things with the same name and two switchers is a confusion that outlives the wording. The area also stays **Help Desk** rather than being renamed Help Center — the rename would touch every existing screen, route name and test to change a label. |
| H38 | Per-space roles, as §15 suggests? | **No — space access is derived from inbox access.** The Help Desk already decides who reaches what at the inbox level (FR-1.7), and a space is a group of inboxes, so "which spaces may I see?" is already answered: the ones holding an inbox I can open. A third access layer would be a third thing to keep in step, and §5's most-restrictive-wins rule would have to be re-derived every time the three disagreed. Admins and Managers reach every inbox, so they reach every space. |
| H39 | Column or pivot for the assignment? | **A nullable column on the inbox.** §7's Phase 1 rule is one inbox to one space; a pivot would model a many-to-many the requirements explicitly rule out, and make "which space owns this inbox?" a join. |
| H40 | Where does the context live? | **The session, keyed per workspace**, re-checked against permission on every read. It is a place you are, not a filter you applied, so it has to survive following a link — and a stored id must never outlive the access it was granted under. |
| H41 | Can context widen what somebody sees? | **Never.** Authorization and context are intersected, and an empty list on either side means nothing rather than everything. A space holding two inboxes does not hand somebody the one they were not given. |
| H42 | Archiving a space that holds inboxes | **Refused, with a message naming the count.** The alternative is orphaning or hiding what is inside it. Same position H21 takes on deleting an inbox: the question "what happens to what is inside?" gets an answer, not a default. |
| H43 | §14's per-space tags, saved views, automation, business hours, SLAs, reports and contacts | **Deferred and named as deferred.** None of those exist in the application yet — they are Phases 5–10. What Phase 2b delivers is the §20 acceptance criteria: the space itself, inbox assignment, switching, and conversation scoping. Isolation of the rest arrives with the features. |
| H45 | Is a space's type a dropdown or a list the reader writes? | **A free-text, multi-value chip field**, stored as JSON on the row. Two changes in one: a space is frequently several things at once (a partner desk that is also a business unit), and no fixed six covers how every organization describes its support operations. The six from §5 survive as one-press SUGGESTIONS, which keeps the common spellings consistent without making them the only answers. JSON rather than a types table plus a pivot: nothing points at these, nothing is scoped by them, and no screen asks "which spaces are tagged X?" — when a type earns behaviour, it earns a table with it. Values are trimmed, collapsed and de-duplicated case-insensitively before validation, with the first spelling kept. |
| H44 | Does an inbox have to be in a space? | **No, and the screens say so.** §12 is written for an inbox that has not been assigned yet, so the state exists — but an unassigned inbox goes on receiving customer mail, so the Spaces screen calls them out at the top and the inbox row says "Not in a space" in amber rather than leaving it to be noticed. |

---

# Phase 2c: Continue to Setup Inbox

Source: *Help Desk — Continue to Setup Inbox Flow*. An addendum to Phase 2b: it gives a space a
way to go from "created" to "receiving customer mail" without anybody having to know which four
screens to visit in which order.

## Requirement

A space that has not finished setting up its inbox offers **Continue to Setup Inbox**, which
opens a four-step, full-page stepper: name the inbox → add the addresses customers write to →
connect the email → invite the team. Progress is saved after each step and resumes where it was
left. Once finished, the row offers **Open Inbox** instead.

## The inbound address format changed

Generated addresses are now **`inbox-{unique-id}@{domain}`** — `inbox-1o5vma@inbound.projectblock.app`
— and carry **nothing** derived from the inbox, the space or the tenant. The flow states the same
requirement three ways (the name must not appear in it; renaming must not change it; moving the
inbox between spaces must not change it), and a name-derived address breaks the second the moment
somebody edits a field.

`help_desk_inboxes.inbound_id` stores the id beside the address rather than parsing it back out:
the address is the routing key, the id is the fact it was built from, and the setup screen shows
them as separate lines.

**Existing addresses were not all rewritten** (H46). An inbox that has already received mail keeps
its old `{name}-{suffix}@…` address, because something is forwarding to it and rewriting it would
break that rule silently — the exact failure the read-only rule exists to prevent. Inboxes that
have never received anything were reformatted.

## Database Fields

| Column | Notes |
|---|---|
| `help_desk_inboxes.inbound_id` | Unique. The id the address is built from |
| `help_desk_spaces.setup_step` | 1–4. Where the flow resumes |
| `help_desk_spaces.setup_completed_at` | Null means unfinished — what puts **Continue to Setup Inbox** on the row |
| `help_desk_spaces.setup_inbox_id` | Which inbox the flow is configuring. Recorded, not inferred: a space can hold several |
| `help_desk_email_addresses.label` | Step 2's Name column. Falls back to the address itself |

## Business Rules

- **Every step commits as it is taken.** The inbox exists after step 1, each address as it is
  added, each invitation when it is sent. The wizard holds no draft — which is what makes
  "save progress after each step" true rather than aspirational, and why abandoning the flow
  half-way leaves a working inbox rather than nothing.
- Progress only moves **forward**. Walking back to step 1 to change a name is an edit, not an undo.
- A step that has not been reached cannot be opened; one that has can be revisited.
- Step 1 refuses blank-only names and duplicates within the Help Desk, and trims what it keeps.
- Going back to step 1 **renames** the inbox rather than creating a second one, and never touches
  the inbound address.
- Changing an address in step 2 **resets its connection status**: what we knew was about a
  different mailbox.
- **Step 3's Continue marks the step read, not proven.** The forwarding rule lives in the
  customer's mail provider and the only proof is a message arriving — possibly days later. A step
  that could only be completed by mail from outside would be a wizard nobody finishes; the status
  goes on telling the truth on the inbox's own screen afterwards.
- Step 4 grants access to **this space's inboxes only**. Somebody already in the workspace is
  added; a stranger is invited through the workspace's own invitation pipeline (H11).
- Inviting is **not required** to finish: a support desk with one person in it is a real one.
- A newly provisioned space starts at **step 2** — its inbox exists and is named, but nothing
  forwards into it yet.

## Acceptance Criteria

- `/help-desk/spaces` shows **Continue to Setup Inbox** for unfinished spaces and **Open Inbox**
  once finished. ✅
- The button opens a dedicated full-page flow, not a modal. ✅
- Step 1 collects the inbox name, validated and trimmed. ✅
- Step 2 adds, edits and removes customer-facing addresses, with icon actions. ✅
- Step 3 shows the generated address, copy actions and forwarding instructions. ✅
- Every generated address is `inbox-{unique-id}@{domain}`, unique, read-only, and never contains
  the inbox name. ✅
- Renaming an inbox and moving it between spaces both leave the address alone. ✅
- Connection status is shown and reaches **Connected** when inbound mail actually arrives. ✅
- Step 4 invites team members scoped to this space. ✅
- Progress is saved per step and resumes at the first incomplete one. ✅
- Finishing lands on the configured inbox with the flow's closing message. ✅

## Queue Requirements

None of its own. The invitation at step 4 rides the workspace's existing mail path.

## Audit Requirements

The flow writes the same activity entries the permanent screens do — inbox created, inbox
renamed, member added, coworker invited — because it goes through the same services. The wizard
itself records nothing extra: "somebody used the setup screen" is not a fact anybody needs later.

## Decisions

| # | Question | Decision |
|---|---|---|
| H46 | Reformat existing inbound addresses to `inbox-{id}@…`? | **Only the ones nothing is forwarding to.** An inbox that has received mail keeps its old address: something out there points at it, and rewriting it breaks that silently in a system this application cannot see. New addresses all take the new shape, and the old ones stay valid for ever. |
| H47 | Store the wizard's step, or derive it? | **Store it.** Steps 1 and 2 could be derived from data, but step 3 cannot: it completes when somebody has read the forwarding instructions, and the evidence that a forward works may never arrive. A wizard that reopened at step 3 for ever is a wizard nobody finishes. |
| H48 | Does the wizard hold a draft? | **No — every step commits.** The alternative is a form that can be lost, which is exactly what "resume where you left off" is supposed to prevent. It also means the flow needs no storage of its own beyond a step counter. |
| H49 | Is step 4 required? | **No.** A desk with one person in it is a real desk. Blocking Finish on inviting somebody would make the flow lie about what it needs. |
| H50 | Do Admin and Manager invited at step 4 get access to other spaces? | **Yes, by role, and the screen says so.** Those two roles reach every inbox (FR-1.7), so a row for them is marked "All spaces" rather than pretending the grant is space-scoped. Agent, Collaborator and Viewer are scoped to this space's inboxes, which is the flow's rule. |
| H51 | A newly provisioned space — finished or not? | **Not finished, and it opens at step 2.** It has an inbox with a generated address, but nothing forwards into it, so it receives nothing. Step 1 has nothing left to ask. |
