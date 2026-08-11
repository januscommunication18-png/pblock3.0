# Feature: Workspace Settings (Phase 3 — Settings)

> Feature spec + build record (per CLAUDE.md §4 and §15). Source of truth: the uploaded
> ProjectBlock HTML POC (`setting-*.html`) + `ProjectBlock_3.0_Phase_2_Settings_Requirements`.
> Secondary: Plane patterns only where the HTML/doc are silent. **Where the earlier text
> requirements conflict with the HTML screens, the HTML wins.**

## Requirement

Workspace-level administration and feature configuration for the active workspace (the
tenant). Eight implemented sections, grouped in a persistent left nav
(Administration · Products · Features · Developers):

| Group | Item | Phase 3 |
|---|---|---|
| Administration | **General**, **Members** | required |
| Administration | Billing and plans, Imports, Exports | nav-only placeholder |
| Products | **Wiki** | required |
| Products | Project Block AI | nav-only placeholder |
| Features | **Projects**, **Teamspaces**, **Initiatives**, **Customers**, **Releases** | required |
| Features | Templates, Integrations | **Coming Soon** (disabled, no functionality) |
| Features | Connections | nav-only placeholder |
| Developers | Webhooks, Access Tokens | nav-only placeholder |

## User Roles

Membership roles resolve from the central `workspace_memberships` table:
**Owner** (workspace creator; never invitable), **Admin**, **Member**, **Viewer**, **Guest**.
Invite roles = Admin, Member, Viewer, Guest.

- **Owner / Admin** — manage all settings (`WorkspacePolicy@update`).
- **Delete workspace** — **Owner only** (`WorkspacePolicy@delete`).
- **Member / Viewer / Guest** — no workspace administration; blocked at the policy layer,
  not just hidden in the UI (doc §3 / §13: "Backend authorization is authoritative").

## Database Fields

All settings data is **tenant-scoped** via `BelongsToTenant` (`tenant_id` → `tenants.id`),
so once tenancy is initialized to the current workspace every query is auto-confined
(CLAUDE.md §7). General-settings fields already live on `tenants` (name, slug,
company_size, timezone, logo_url).

- **workspace_settings** (singleton per tenant): `tenant_id` (unique), feature toggles
  `project_states_enabled` (def true), `releases_enabled` (def true),
  `initiatives_enabled` (def false), `teamspaces_enabled` (def false),
  `customers_enabled` (def true), `wiki_enabled` (def true),
  `teamspaces_locked_at` (one-way latch), `wiki_description`, `wiki_docs_url`.
- **project_states**: `name`, `color`, `description?`, `group`, `is_default`, `position`.
- **project_labels / wiki_labels / release_labels / initiative_labels**:
  `name`, `color`, `position`.
- **release_tags**: `name`, `color?`, `position`.
- **customer_properties**: `title`, `description?`, `type`, `mandatory`, `active`,
  `options?` (json, for Dropdown), `position`.

Immutable/system **default customer properties** (Customer name, Description, Email,
Website, Employees, Industry, Stage, Contract Status, Revenue) are **config-defined**
(`config/settings.php`), display-only, never stored/editable.

## Business Rules

- **General**: edit name (≤80), company size (bucket list), slug (lowercase/digits/hyphens,
  reserved-word + uniqueness checks, warn that URL changes affect links), timezone (IANA),
  logo upload (type/size validated). Cancel = no partial save. **Delete** = Owner only,
  explicit typed/confirmed modal, irreversible; cascades memberships + invitations + tenant.
- **Members**: People + Pending Invites tabs. Invite one-or-more (per-row handling reusing
  `WorkspaceInviter`); Owner never invitable. Revoke pending; edit role (last-owner/last-admin
  guard, Owner protected); remove member (confirm; cannot remove last owner / self-as-owner).
- **Projects**: `project_states_enabled` toggle; states seeded with the 6 approved defaults;
  add/edit/delete states with color + description + lifecycle group; the default state and
  a minimum of one state are protected. Labels (name+color) workspace-scoped, reusable.
- **Wiki**: `wiki_enabled` toggle; editable description + docs URL; workspace wiki labels.
- **Releases**: `releases_enabled` toggle (default on); Release Tags (e.g. `v1.0.0`) and
  Labels (color) tabs, each with empty state.
- **Initiatives**: `initiatives_enabled` toggle (default off); labels are **locked** while
  disabled — the API rejects label writes when the feature is off; three UI states
  (locked / enabled-empty / populated).
- **Teamspaces**: `teamspaces_enabled` is a **one-way** enable — requires confirmation,
  stamps `teamspaces_locked_at`, and can never be turned off (API rejects disable).
- **Customers**: `customers_enabled` toggle; system default property set shown read-only;
  create custom properties (Title + Type required; Mandatory + Active flags; Dropdown adds
  options); inactive properties retained but hidden from new entry.
- **Everything** persists at workspace scope, is authorized server-side, and is audit-ready
  (mutations flow through services/controllers, never raw unscoped queries).

## Acceptance Criteria

Covered by `tests/Feature/Settings/*`:

- Owner/Admin can open every settings section (200); Member/Viewer/Guest get 403 on
  mutations; non-members 403 on read.
- General update persists name/size/slug/timezone; slug uniqueness + reserved enforced;
  delete-workspace is Owner-only and removes the workspace + memberships.
- Members: invite persists pending rows (Owner not invitable), revoke, role edit with
  last-owner guard, remove member. Sending an invitation now also emails a secure accept
  link — see docs/features/member-invite-flow.md for the acceptance and activation half.
- Projects: toggle persists; default states seeded once; add/edit/delete state (default +
  last-state protected); label CRUD.
- Wiki/Releases/Initiatives/Customers toggles persist; label/tag/property CRUD.
- Initiative label writes rejected while Initiatives disabled.
- Teamspaces enable is one-way: a disable request is rejected after enablement.
- Templates/Integrations render Coming Soon; their routes are not functional.
- All settings rows are tenant-isolated: a second workspace never sees the first's data,
  and new rows inherit the active tenant id.

## UI Requirements

Hybrid **Vue-in-Blade** (CLAUDE.md §14), **no FlyonUI**. Each section is a Blade page
(`resources/views/settings/*.blade.php`) under a shared settings shell
(`settings/layout.blade.php`: topbar + persistent grouped left nav with active state,
Coming-Soon disabled items, placeholder items) that mounts a Vue 3 component for that
section. Initial data passed as JSON props; mutations POST to JSON endpoints (CSRF from
`<meta>`), Vue updates the UI without reload. Styling uses the existing POC design tokens
(`public/assets` — `head/ink/sub/faint/line/stroke/hover/sel/brand`, `.pb-input` etc.).
Shared components: toggle, color picker (10-preset palette + hex), label list + empty
state, modal, confirm dialog. Custom CSS (if any) uses the `_moretogether` prefix.

## Real-Time / Queue / Audit

None this phase (consistent with Phase 3). No notifications/broadcasts/queued jobs.
Mutations are structured for a future audit log (services + typed requests). Hooks for
later: settings-changed + invitation-sent notifications.

## Tenancy Decisions (CLAUDE.md §18 D5/D6)

- All settings tables are tenant-scoped (`BelongsToTenant`). Settings requests initialize
  tenancy to `Auth::user()->currentWorkspace` via `InitializeWorkspaceTenancy` middleware,
  so scoping is automatic. `workspace_memberships` stays central (switcher + authz before
  tenancy).

## Planning & Reasoning

- Frontend delivered as Vue 3 via the CDN global build mounted into Blade (matching the
  app's current CDN-Tailwind delivery — the running app is not served through Vite; the
  build is empty). Components are authored so they map 1:1 to future SFCs if the app moves
  to the Vite pipeline. **No FlyonUI** per product direction.
- Feature toggles + wiki text live on a per-workspace `workspace_settings` singleton;
  list entities get dedicated tenant-scoped tables. Default customer properties are config
  (display-only); default project states are seeded rows (editable).
- Manage-settings authorization reuses `WorkspacePolicy@update` (owner/admin); a new
  `delete` ability restricts workspace deletion to the owner.

## Files Changed

See the commit. Config `config/settings.php`; migrations `2026_08_11_*`; models
`WorkspaceSettings, ProjectState, ProjectLabel, WikiLabel, ReleaseTag, ReleaseLabel,
InitiativeLabel, CustomerProperty`; `WorkspacePolicy@delete`; middleware
`InitializeWorkspaceTenancy`; services `WorkspaceSettingsManager, WorkspaceDeleter`;
`app/Http/Controllers/Settings/*`; `app/Http/Requests/Settings/*`; `routes/settings.php`;
`resources/views/settings/*`; `public/assets/js/settings/*`; `tests/Feature/Settings/*`.
