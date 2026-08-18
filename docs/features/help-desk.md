# Help Desk — Phase 1: Enablement, Setup & Membership

Source: *ProjectBlock Help Desk — Phase 1 of 10: Help Desk Enablement, Setup & Membership*
(FR refs below are to that document). This file is the build spec: it turns those requirements
into decisions for THIS codebase and records what each slice does and does not cover.

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

Slice 1 adds one column. The membership tables land in slice 2, listed here so the shape is
agreed before it is built.

**`workspace_settings.help_desk_enabled`** — boolean, default `false`.

Planned (slice 2+), all tenant-scoped per CLAUDE.md §7:

| Table | Columns |
|---|---|
| `help_desks` | `id`, `tenant_id`, `name`, `created_by`, timestamps — one per workspace, holds Help Desk-wide config |
| `help_desk_inboxes` | `id`, `tenant_id`, `help_desk_id`, `name`, `created_by`, timestamps. Deliberately minimal; Phase 2 adds email channels and routing |
| `help_desk_members` | `id`, `tenant_id`, `help_desk_id`, `user_id`, `role`, `status`, `created_by`, timestamps, soft deletes |
| `help_desk_member_inboxes` | `help_desk_member_id`, `help_desk_inbox_id` — FR-1.7's inbox-level access |
| `help_desk_invites` | `id`, `tenant_id`, `email`, `role`, `token`, `status`, `invited_by`, `accepted_at` |

## Business Rules

- Enabling Help Desk **does not** grant any workspace member access to it (FR-1.1, and the
  first acceptance criterion). Access comes only from Help Desk membership.
- Disabling **hides navigation and preserves all data** (acceptance criterion 4). It is a
  visibility switch, never a delete.
- Only a Workspace Owner/Admin may toggle it — the same `guardManage()` gate every other
  settings section uses.
- Removing a Help Desk member preserves their historical replies, notes and assignments
  (acceptance criterion 5) — hence soft deletes on `help_desk_members`.
- The toggle is a security-sensitive configuration change and is logged (§13).

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

## UI Requirements

Slice 1: the app appears in the create form's app picker and in Settings → General's app list
(both already render from `WorkspaceApps`), plus a rail entry. A dedicated Settings → Help Desk
page with the wizard arrives in slice 4.

## Real-Time Requirements

None in Phase 1.

## Queue Requirements

None in slice 1. Invite emails in slice 3 go through the queue, per CLAUDE.md §11.

## Audit Requirements

Enable/disable is logged (§13, §8). Slice 1 logs it; the browsable Help Desk activity stream
(FR-1.9) is slice 4.

## Slices

| Slice | Contents | Status |
|---|---|---|
| 1 | FR-1.1 enable/disable, FR-1.2 nav activation, create-form + settings, data-preserving disable | in progress |
| 2 | FR-1.4 membership, FR-1.6 the five roles, FR-1.7 inbox-level access, FR-1.8 status/deactivation, minimal inboxes | not started |
| 3 | FR-1.5 invite existing member + new coworker, acceptance producing workspace **and** Help Desk membership | not started |
| 4 | FR-1.3 setup wizard, FR-1.9 audit history screen | not started |

## Decisions

| # | Question | Decision |
|---|---|---|
| H1 | Enablement mechanism | Reuse `WorkspaceApps` rather than a Help Desk-specific toggle. It is what the create form and Settings → General already read, so one flag lights up every surface — and it is why Help Desk can be chosen at workspace creation. |
| H2 | `help_desk_roles` as a table | **No — fixed roles in config**, like workspace and project roles already are. The document lists a table, but the five roles are fixed, not user-defined; a table would add a join and a seeding step to store five constants. CLAUDE.md §6 says avoid overengineering Phase 1. Revisit if custom roles are ever required. |
| H3 | `audit_events` as a new generic table | **No — follow the existing per-domain activity pattern** (`ProjectActivity`, `EpicActivity`). A single polymorphic audit table across every domain is a bigger architectural change than this phase justifies, and it would sit beside the activity tables rather than replacing them. Slice 1 logs config changes; slice 4 adds `help_desk_activity`. |
| H4 | Inboxes in Phase 1 | Minimal `help_desk_inboxes` in slice 2 — enough for FR-1.7's inbox-level access to be real. Phase 2 adds email channels and routing on top. |
| H5 | Does enabling grant access? | **No.** The first acceptance criterion is explicit, and it is the whole point of the separate membership model. Enabling only makes the app exist for the workspace. |
