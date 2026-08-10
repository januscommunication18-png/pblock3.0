# Phase 4 — Create Project

> Feature spec + build record (per CLAUDE.md §4 and §15). Source of truth: the uploaded
> `ProjectBlock_3.0_Create_Project_Requirements` doc + the HTML POC (`html/projects.html`).
> Secondary: Plane patterns only where the doc/HTML are silent.

## Scope (this phase = Delivery Phase A — Create Project MVP)

Workspace → Projects list, unlimited projects per workspace, the Create Project modal
(name / ID / description / access / lead / cover), server-side validation + persistence,
workspace-scoped identifier uniqueness, permissions, and routing to the created project.

**Deferred to later phases** (doc §10 Phase B/C): Project Settings (General/Members/
Features/States/Labels/Estimates/Automations), archive / restore / permanent delete,
list sort/filter, and the full project workspace (work items, cycles, modules, pages).
The `projects.status` + `archived_at` columns are provisioned now so archive/restore land
without a schema change.

## Requirement

Projects are always created inside the currently selected workspace (the tenant). A
workspace holds an unlimited number of projects (PRJ-001) — no application-level count cap.
Create Project accepts a cover image (optional), Project Name (required), Project ID
(required, unique within the workspace), Description (optional), Access (Public/Private,
required, default Public) and Lead (optional). The Project ID auto-derives from the name
until the user edits it. After a successful create the project is added to the list and its
default view opens.

## User Roles

Resolved from the central `workspace_memberships` table (spec §6):

- **Owner / Admin** — create projects; administrative access to every project including
  private ones (PRJ-031).
- **Member** — create projects; sees public projects + projects they belong to.
- **Viewer** — no create; sees public projects.
- **Guest** — no create; only projects they are explicitly a member of (a public project
  does not grant a guest access, PRJ-030).

The project creator is seeded as a project **admin**; the chosen lead as a project
**member** (so a private project is immediately usable by both).

## Database Fields

- **projects** (TENANT-SCOPED via `BelongsToTenant`): `id`, `tenant_id` (→ tenants.id, the
  workspace), `name`, `identifier`, `description` (nullable), `visibility`
  (`public|private`), `lead_user_id` (nullable → users), `cover_url` (nullable), `timezone`
  (defaulted from workspace), `status` (`active|archived`), `created_by`, `archived_at`
  (nullable), timestamps. **UNIQUE(tenant_id, identifier)** (PRJ-022); index
  (tenant_id, status) for active-list pagination.
- **project_members** (TENANT-SCOPED): `id`, `tenant_id`, `project_id`, `user_id`,
  `role` (`admin|member`), timestamps. UNIQUE(project_id, user_id). Presence of a row is
  what grants access to a private project.

## Business Rules

- Name required, trimmed, ≤ 80 chars (repeats allowed within a workspace, spec §5).
- Identifier required, uppercase `A–Z/0–9`, ≤ 10 chars; auto-derived from the name until
  manually edited (PRJ-023). Unique **within the workspace only** — the DB unique constraint
  backs the request rule and makes concurrent double-submits race-safe (PRJ-022).
- Description optional (≤ 2000 chars). Access required, `public|private`, default public,
  enforced server-side after creation (PRJ-025/032).
- Lead optional and must be an active member of the current workspace (PRJ-026) — a
  non-member cannot be assigned.
- Cover optional; validated image type + size; a failed upload does not erase the rest of
  the form (spec §7). Stored on the `public` disk under `project-covers/{workspace}`.
- Creation is atomic (spec §7): the project + its seed memberships are one transaction;
  cover storage happens first, outside the transaction, so a filesystem failure doesn't hold
  a DB lock. On success the modal closes and routes to the project; on validation failure a
  422 keeps the modal open, shows field-level errors, and preserves entered values; the
  Create button is disabled while saving to prevent duplicate requests (PRJ-028).
- Tenant isolation (CLAUDE.md §7): all project queries/mutations scope by workspace via the
  `BelongsToTenant` global scope; `{project}` route-binding 404s for a foreign workspace and
  a denied private project 404s rather than leaking metadata (PRJ-032).

## Acceptance Criteria

Covered by `tests/Feature/Project/*`:

- Owner/Admin/Member can open Workspace → Projects and create a project; Viewer/Guest cannot
  create. A newly created project appears in the list without re-login (PRJ-010).
- Project 1, 2, 100+ can be created — no product count cap (PRJ-001).
- Name and ID required; ID auto-generates from the name and is user-editable (PRJ-021/023).
- Duplicate ID in the same workspace is rejected server-side; the **same** ID is allowed in
  a different workspace (workspace-scoped uniqueness, PRJ-022).
- Description optional; Public/Private persists and is enforced (PRJ-024/025).
- Lead is selected from workspace members and persisted; a non-member lead is rejected.
- Optional cover uploads and persists as a URL on the project (PRJ-027).
- Successful create routes to the project; failed create preserves input + shows errors.
- Private project is not openable by direct URL for an unassigned standard member (404);
  workspace admins retain access (PRJ-031). Projects are tenant-isolated between workspaces.

## UI Requirements

Ported from the POC (`html/projects.html`) into the app shell (`layouts/app`), plain
Tailwind (CDN) + POC design tokens, vanilla JS (parity with `app/welcome.blade.php`):

- `resources/views/app/projects.blade.php` — topbar (workspace switcher, user menu), rail,
  sidebar, the Projects header (Add Project; Created-date/Filters shown disabled as they
  belong to Phase B), the project card grid + empty state, and the Create Project modal
  (cover with change/remove, name + auto ID, description, Access dropdown, Lead search) wired
  to the store endpoint via `fetch` with field-level 422 handling.
- `resources/views/app/project.blade.php` — the created project's landing (cover, name,
  identifier, visibility, lead, description) with a placeholder for the work-item phases.
- Rail/sidebar links in `app/welcome.blade.php` now point at `projects.index`.

## Real-Time / Queue / Audit

None this phase. No notifications, broadcasts or queued jobs. `created_by` + timestamps are
recorded for auditability (NFR §8); a future audit-log can hook project create/update/
archive/delete.

## Files

- Migrations: `2026_08_12_000001_create_projects_table`, `..._000002_create_project_members_table`.
- Models: `Project`, `ProjectMember` (both `BelongsToTenant`).
- Policy: `ProjectPolicy` (viewAny / create / view / update) — auto-discovered.
- Request: `Project/StoreProjectRequest`. Service: `ProjectCreator`.
- Controller: `Project/ProjectController` (index / store / show / identifierAvailable).
- Routes: `routes/project.php` (required from `routes/web.php`), under `auth` +
  `workspace.tenancy`.
- Config: `config/projects.php`. Views: `app/projects`, `app/project`; edited `app/welcome`.
- Tests: `tests/Feature/Project/{ProjectTestCase,CreateProjectTest,ProjectAccessTest,ProjectListTest}`.

## How to run locally

```bash
php artisan migrate      # applies the projects + project_members tables
php artisan test         # Phase 4 project tests + existing suite
```
