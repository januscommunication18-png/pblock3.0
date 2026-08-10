# Phase 3 — Create Workspace

> Feature spec + build record (per CLAUDE.md §4 and §15). Source of truth: the uploaded
> ProjectBlock HTML POC + the Phase 3 requirements doc. Secondary: Plane patterns only
> where the HTML/doc are silent.

## Requirement

First-time workspace creation after Profile/Role/Goals onboarding, teammate invitations,
and additional workspace creation from inside the app. A workspace is the tenant boundary
in this single-database multi-tenant app. Agile is the only creatable view this release;
Classic is **Coming Soon** (visible-but-disabled, never selectable or creatable).

Two creation contexts:

1. **First-workspace onboarding** (`onboarding-workspace.html` → `onboarding-invite.html`):
   Name + editable slug + team size. No view selector — created as Agile by default
   (WS-VIEW-004). Then an optional invite step (skippable). Lands on the welcome home.
2. **Additional workspace** (`create-workspace.html`): Name + slug + team size + "Choose
   your view". Agile selectable; Classic disabled/Coming Soon.

## User Roles

Creator becomes **Owner**. Invitable roles: **Admin, Member, Viewer, Guest** (Owner is
never an invite option). Roles are workspace membership roles, resolved from the central
`workspace_memberships` table.

## Database Fields

- **tenants** (Workspace / the tenant): `id` (UUID internal key), `name`, `slug` (unique),
  `company_size`, `timezone`, `logo_url`, `view_type` (agile), `status`, `created_by`,
  timestamps, `data` (stancl overflow JSON, unused).
- **workspace_memberships** (central, cross-tenant): `workspace_id`, `user_id`, `role`,
  `status`, `invited_at`, `joined_at`. Unique on (`workspace_id`, `user_id`).
- **workspace_invitations** (tenant-scoped via `BelongsToTenant`): `tenant_id`, `email`,
  `role`, `inviter_user_id`, `token` (sha-256 hash, unique), `status`, `expires_at`,
  `accepted_at`.
- **users.current_workspace_id** — active/last-active workspace (WS-009).
- **onboarding_profiles.workspace_setup_completed_at** — marks the invite step done/skipped
  so login can resume it (spec §9).

## Business Rules

- Name required (≤80 chars, trimmed). Slug auto-generated from name, user-editable, only
  the slug portion after `app.projectblock.so/`. Slug syntax = lowercase letters, digits,
  hyphens (no leading/trailing/double hyphens); reserved words blocked; globally unique
  (DB unique constraint backs the check, preventing race duplicates — WS-004/005).
- Team size required, from the fixed bucket list (WS-006).
- Creation is atomic: workspace + Owner membership created in one transaction; the new
  workspace becomes the creator's active workspace (WS-008/009). Failure rolls both back.
- Agile is the only creatable view. Server rejects any non-available view even if the
  client is tampered with (WS-VIEW-002/003); Classic sits behind a feature flag
  (`config('workspace.classic_enabled')`) for a future release with no data migration.
- Invitations: multiple rows, "add another", skippable. Per-row handling — invalid email,
  invalid role, duplicate-in-request, already-a-member, and already-invited are each
  flagged without discarding the valid rows (spec §9, INV-004). Invites persist as
  `pending`; **no email is sent this phase** (they surface later under Settings > Members).
- Tenant isolation: `workspace_invitations` is confined to the active workspace by the
  `BelongsToTenant` global scope; central `workspace_memberships` powers the switcher and
  authorization before tenancy is initialized.

## Acceptance Criteria

Covered by `tests/Feature/Workspace/*` (23 tests):

- First-time user creates a workspace with name, unique slug, team size → created as Agile,
  creator is Owner, becomes active workspace, lands on invite step.
- Invite Admin/Member/Viewer/Guest or skip → lands on welcome; Owner is not invitable.
- Additional creation offers Agile and marks Classic Coming Soon; Classic cannot be
  selected in the UI or created via the endpoint or the service (server-side reject).
- Slug uniqueness + reserved words enforced; slug availability endpoint.
- Invitations are tenant-isolated; new invites inherit the active tenant id.
- Create/invite/skip all land on the welcome / get-started experience.

## UI Requirements

Ported from the POC to Blade (Tailwind CDN + POC tokens in `public/assets`):
`onboarding/workspace.blade.php`, `onboarding/invite.blade.php`, `workspace/create.blade.php`
(Classic rendered disabled + "Coming soon" badge), and the full `app/welcome.blade.php`
app shell (topbar, workspace switcher modal wired to real workspaces, rail/sidebar, get
started, account menu) under a new `layouts/app.blade.php`.

## Real-Time / Queue / Audit

None this phase. No notifications, broadcasts, or queued jobs (invites are not emailed
yet). Hooks for later: invitation-sent notification + accept-invite flow.

## Tenancy Decisions (see CLAUDE.md §18 D5/D6)

- Workspace **is** the stancl/tenancy tenant (single database). `config/tenancy.php`
  `bootstrappers` is emptied — no per-tenant database switching; isolation is purely the
  `BelongsToTenant` row scope. `TenancyServiceProvider` strips the database create/migrate/
  delete job pipeline from the stancl stub.
- `workspace_memberships` is deliberately **central** (not tenant-scoped) so the switcher
  and access checks work before tenancy is initialized. `workspace_invitations` is
  tenant-scoped to demonstrate/enforce automatic isolation.

## How to run locally

```bash
composer install         # ensures stancl/tenancy is present
php artisan migrate       # applies the tenants + workspace tables
php artisan test          # 25 passing (23 Phase 3 + Phase 1)
```
