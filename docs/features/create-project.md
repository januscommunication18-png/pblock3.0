# Feature: Create Project (Phase 4)

> Feature spec + build record (CLAUDE.md §4/§15). Source of truth: `ProjectBlock_3.0_Create_Project_Requirements`
> + the HTML POC `html/projects.html` (Projects grid + Add Project modal). Secondary: Plane
> "Create a project" patterns where the doc/HTML are silent.

## Requirement

Projects live inside the currently selected workspace (the tenant). A workspace holds
unlimited active + archived projects (no product-level cap). From Workspace → Projects a
user can open an **Add Project** modal and create a project with: cover (optional), Name
(required), Identifier/ID (required, unique within workspace, uppercase A–Z/0–9, auto-derived
from name until edited), Description (optional), Access = Public|Private (required, default
Public), Lead (optional, a workspace member). After create the project is added to the list
and its landing view opens. Projects support archive / restore / permanent-delete, and
Project Settings (General, Members, Features, States, Labels, Estimates, Automations).

## User Roles

- **Create project** — workspace **Owner/Admin only** (product decision for this build).
- **View public project** — any active workspace member except Guest.
- **View private project** — assigned project members + workspace Owner/Admin (admin access).
- **Manage project** (settings/members/lifecycle) — project **admin** OR workspace Owner/Admin.
- **Permanent delete** — manage rights + typed-name confirmation.
- Server-side enforcement everywhere (PRJ-032): unauthorized API → 403/404, no metadata leak.

## Database Fields (all tenant-scoped via BelongsToTenant → tenants.id)

- **projects**: `tenant_id`, `name`, `identifier` (unique per tenant), `description?`,
  `visibility` (public|private), `lead_user_id?`, `cover_url?`, `emoji?`, `timezone`,
  `status` (active|archived), `features` (json toggles), `created_by`, `archived_at?`,
  timestamps. **UNIQUE(tenant_id, identifier)** (PRJ-022 / §5).
- **project_members**: `tenant_id`, `project_id`, `user_id`, `role` (admin|member),
  timestamps. UNIQUE(project_id, user_id).
- **project_item_states** / **project_item_labels**: `tenant_id`, `project_id`, `name`,
  `color`, `position`, (+ `group`, `is_default` for states). Project-scoped work-item
  states/labels (PRJ-043).

## Business Rules

- Identifier auto-generates client-side (`NAME → [A-Z0-9]{≤10}`) until edited; server
  validates format + workspace-uniqueness (case-insensitive) and rejects collisions with a
  field error (same identifier allowed in a *different* workspace).
- Create is atomic: project + creator project-admin membership (+ lead membership if set)
  in one transaction; `created_by` recorded.
- Cover upload: type/size validated; failure keeps the modal open with values intact.
- Visibility enforced in `ProjectPolicy@view`; private projects 404 for non-members.
- Archive: `status=archived`, `archived_at` stamped, leaves active lists, appears in
  Archived, restorable with data intact. Delete: cascades child rows; typed confirmation.
- Feature toggles (cycles, modules, views, pages, intake, time_tracking) stored on
  `projects.features`; disabled features drop from project nav without deleting data.
- Estimates + Automations settings are **Coming Soon** (PRJ-044).
- Tenant isolation: every query/mutation scoped to the active workspace; identifiers,
  membership, and resources never cross workspaces.

## Acceptance Criteria (tests/Feature/Project/*)

- Owner/Admin can create; Member/Viewer/Guest get 403 on create.
- Create persists all fields; creator becomes project admin; lead becomes a member.
- Identifier required + workspace-unique (dup → 422 field error); same id allowed in another
  workspace; list/create endpoints tenant-isolated.
- Name required; description optional; access persists.
- Private project 404s for a non-member standard user; owner/admin can view.
- Archive removes from active list + appears in archived; restore returns it; delete removes
  project + members + project states/labels.
- Feature toggles persist; project settings General/Members mutations enforce manage rights.
- Unlimited projects: creating many is not blocked by any count threshold.

## UI Requirements

Hybrid Vue-in-Blade (no FlyonUI), POC tokens. Projects grid of cards (cover + emoji, name,
identifier, lead, Joined) with Created-date sort, Filters, Archived toggle, and **Add
Project** modal (cover upload + progress, name, auto ID, description, Public/Private combo,
lead searchable combo). Project landing page (header + placeholder body for work items which
arrive in a later phase). Project Settings shell (left nav: General, Members, Features,
States, Labels, Estimates·Soon, Automations·Soon) mirroring the workspace settings shell.

## Real-Time / Queue / Audit

None this phase. Mutations are audit-ready (services + typed requests, `created_by` +
lifecycle timestamps). Hooks for later: project events + notifications.

## Tenancy Decisions

Project + all child tables are tenant-scoped (`BelongsToTenant`). Routes initialize tenancy
to the current workspace via the existing `workspace.tenancy` middleware; project route-model
bindings resolve within the active tenant so a foreign project id 404s.

## Files Changed

See commit: `config/projects.php`; migrations `2026_08_12_*`; models `Project, ProjectMember,
ProjectItemState, ProjectItemLabel`; `ProjectPolicy`; services `ProjectCreator,
ProjectLifecycle`; `app/Http/Requests/Project/*`; `app/Http/Controllers/Project/*`;
`routes/projects.php`; `resources/views/projects/*`; `public/assets/js/projects/*`;
`tests/Feature/Project/*`.
