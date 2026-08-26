# CLAUDE.md — pblock3.0 Project Base File

> This is the authoritative base file for AI-assisted development on **pblock3.0**.
> Claude Code and Laravel Boost must read and follow this file before generating any code.
> Keep it current: when a stack decision, convention, or rule changes, update this file first.

---

## 1. Project Overview

**pblock3.0** is a multi-tenant Laravel application with real-time in-app notifications.
It is built on a single-database multi-tenancy model and uses a hybrid Vue-inside-Blade
frontend styled with FlyonUI. Real-time features are powered by Laravel Reverb (WebSockets)
and Laravel Echo on the client.

The project is being built **feature by feature against written specifications** — never
in large unscoped batches. See §4 (AI Coding Workflow) and §15 (Feature Build Format).

---

## 2. Technology Stack

| Layer | Choice |
|---|---|
| Backend framework | **Laravel 13** (PHP 8.3+; targets 8.3–8.5) |
| AI coding assistant | **Laravel Boost** (`laravel/boost`) |
| Multi-tenancy | **tenancyforlaravel.com** (`stancl/tenancy`) |
| Tenancy model | **Single database, multi-tenant** (shared DB, tenant-scoped rows) |
| Database | **MariaDB / MySQL** (local: MAMP) — see §18 decisions log |
| Frontend UI kit | **FlyonUI** (flyonui.com, on top of Tailwind CSS) |
| JS framework | **Vue.js** |
| Vue usage | **Hybrid** — Vue components mounted inside Laravel Blade + FlyonUI markup |
| Real-time engine | **Laravel Reverb** (WebSocket server) |
| Frontend real-time listener | **Laravel Echo** |
| Background processing | **Laravel Queues** |
| Notifications | **Laravel Notifications** (database + broadcast + mail) |
| Custom CSS | Extensions on top of FlyonUI, namespaced with the **`_moretogether`** prefix |
| Code audit | **SonarQube / SonarSource** |

---

## 3. Local Environment

- Local stack: **MAMP** (MySQL/MariaDB + PHP + Apache), project at `~/Sites/pblock3.0`.
- PHP: **8.3 or newer** (Laravel 13 requirement).
- Database engine locally: **MariaDB/MySQL** provided by MAMP.
- Node + npm for the Vite/Vue/FlyonUI frontend build.
- Do **not** hardcode local paths, ports, or credentials in application code — use `.env`.

---

## 4. AI Coding Workflow Rule (MANDATORY)

Claude Code must **not** freely build large sections of the application without a clear
specification. For every feature or module, follow this exact workflow:

1. **Define the requirement** — what the feature does.
2. **Define the database fields** — tables/columns/relationships.
3. **Define the acceptance criteria** — how it will be tested.
4. **Generate the code** — small, convention-based, using Laravel Boost.
5. **Review the generated code** — correctness, security, tenancy isolation.
6. **Run migrations and tests.**
7. **Commit small, focused changes.**

Do not generate unrelated features unless explicitly requested.

**Documentation rule:** For every action, document the planning and reasoning
(what was built, why, and the decisions made) in the feature's spec file under `docs/`
or the commit message. Nothing ships without a recorded rationale.

---

## 5. Laravel Boost Rules

This project uses **Laravel Boost** to improve AI-assisted coding accuracy.
Claude Code should use Laravel Boost for:

- Laravel framework guidance and version-specific documentation
- Artisan command awareness
- Laravel project-structure understanding
- Generating Laravel convention-based code
- Debugging Laravel-specific issues
- Creating models, migrations, controllers, routes, jobs, notifications, policies, and tests

Boost is a **Laravel-specific development assistant, not a replacement for product
specifications.** Always start from the feature spec (§15), then use Boost to implement it.

**Boost commands (reference):**

```bash
composer require laravel/boost --dev
php artisan boost:install     # generates AGENTS.md + agent config; wires MCP server
php artisan boost:update      # refresh Boost resources
php artisan boost:mcp         # MCP server entrypoint
```

> Note: `boost:install` may generate or manage its own AI guideline files (AGENTS.md and,
> depending on the agent, portions of CLAUDE.md). Treat **this file** as the project's
> authoritative product/architecture rules. If Boost regenerates guideline content, keep
> the project-specific sections below intact and reconcile rather than overwrite.

---

## 6. Development Rules for Claude Code

Claude Code must follow these rules:

- Use **Laravel conventions**.
- Use **Laravel Boost** before generating Laravel-specific code.
- Keep changes **small and focused**.
- **Do not** create unrelated modules.
- **Do not** hardcode tenant IDs.
- **Do not** bypass tenant isolation.
- Write migrations with the **Laravel schema builder** (portable; avoid DB-specific raw SQL) — see §18.
- Use **queues** for async work.
- Use **policies or gates** for access control.
- Use **form requests** for validation.
- Use **service classes** for complex business logic.
- Use **events and listeners** where workflow actions need side effects.
- Use **notifications** for user-facing alerts.
- Use **feature tests** for critical workflows.
- Use **clear naming conventions**.
- **Avoid overengineering** Phase 1 features.

---

## 7. Multi-Tenancy Rules (Single Database)

- Tenancy is provided by **stancl/tenancy** in **single-database** mode: all tenants share
  one database; rows are scoped by tenant.
- Every tenant-owned model/table must carry a tenant scope (e.g. `tenant_id`) and be
  isolated automatically — never rely on callers to filter by tenant manually.
- **Never hardcode tenant IDs.** Resolve the current tenant from the tenancy context.
- **Never bypass tenant isolation** (no global unscoped queries on tenant data outside
  explicit, reviewed admin paths).
- Central (non-tenant) data and tenant data must be clearly separated in migrations and models.

---

## 8. Real-Time Notification Architecture

The notification pipeline:

```
Laravel Event / Business Action
      ↓
Laravel Notification
      ↓
Database Notification Channel  ──►  stored in notifications table
      ↓
Broadcast Notification Channel
      ↓
Laravel Queue                  ──►  async delivery
      ↓
Laravel Reverb                 ──►  WebSocket push
      ↓
Laravel Echo                   ──►  client listener
      ↓
Vue Notification Component
      ↓
Real-Time Notification Bell / Panel
```

**Primary notification stack:** Laravel Notifications · Database channel · Broadcast channel ·
Laravel Reverb · Laravel Echo · Vue notification component · Laravel Queue.

---

## 9. Notification Requirements

- The system supports **real-time in-app notifications**.
- Notifications are **stored in the database** and **broadcast in real time**.
- Supported channels: **database**, **broadcast**, **mail**.
- **Phase 1 required channels:** `database` and `broadcast`. Email may be added per workflow where required.

**Notification payload should include:**
`title`, `message`, `module`, `entity_type`, `entity_id`, `priority`, `action_url`.
(Backed by Laravel's `notifications` table via the database channel.)

---

## 10. Notification UI Requirements (Vue)

The frontend includes a Vue-based notification component supporting:

- Notification **bell**
- Real-time **unread count**
- Notification **dropdown panel**
- Notification **title**, **message**, **module**, **priority**, **timestamp**
- **Read / unread** state
- **Mark single** notification as read
- **Mark all** notifications as read
- **Clickable action URL**

The component listens on the user's private channel via Echo and updates the UI without a page refresh.

---

## 11. Queue Requirements

Use **Laravel Queues** for async delivery. Queue the following:

- Broadcast notifications
- Email notifications
- Import / export processing
- AI processing
- Large background jobs
- Integration sync jobs
- Audit processing

**Do not run heavy jobs synchronously inside controllers.**

---

## 12. Laravel Reverb Requirements

Use **Reverb** for WebSocket communication:

- Real-time notifications
- Dashboard live updates
- Workflow status changes
- Review / approval updates
- Background job completion alerts

Use **private channels** for user- and tenant-specific data. Channel patterns:

```
private-user.{userId}
private-tenant.{tenantId}
private-tenant.{tenantId}.user.{userId}
```

**Protect all private broadcast channels using Laravel channel authorization**
(`routes/channels.php`), enforcing tenant isolation in the auth callback.

---

## 13. Laravel Echo Requirements

- Use **Laravel Echo** on the frontend to listen for broadcast notifications.
- Configure Echo to work with **Laravel Reverb** (Reverb broadcaster + app key/host from `.env`).
- Vue components subscribe to the appropriate **private channels** and update the UI live.

---

## 14. Frontend Rules (FlyonUI + Vue Hybrid)

- UI is built with **FlyonUI** (on Tailwind CSS) inside **Laravel Blade** views.
- **Vue is used in hybrid mode** — mount Vue components into Blade where interactivity is needed
  (e.g. the notification component), rather than a full SPA.
- **Custom CSS** that extends FlyonUI must be namespaced with the **`_moretogether`** prefix
  (e.g. `._moretogether-card`, `._moretogether-bell`) so custom classes never collide with
  FlyonUI/Tailwind utilities.
- Keep custom CSS additive on top of FlyonUI; prefer FlyonUI/Tailwind utilities first.

---

## 15. Preferred Feature Build Format

Every new feature must arrive as a spec in this format (see `docs/feature-template.md`):

```markdown
# Feature Name

## Requirement
Describe what the feature should do.

## User Roles
List which users can access or manage this feature.

## Database Fields
List required fields.

## Business Rules
List the rules that control behavior.

## Acceptance Criteria
List how the feature will be tested.

## UI Requirements
List required screens, buttons, tables, modals, and components.

## Real-Time Requirements
Mention whether notifications, events, or live updates are needed.

## Queue Requirements
Mention whether background processing is needed.

## Audit Requirements
Mention whether activity should be logged.
```

---

## 16. Example Feature Instruction

```markdown
# Feature: Real-Time Product Review Notification

## Requirement
When an editor submits a product for review, the assigned reviewer receives a
real-time in-app notification.

## User Roles
- Editor: can submit a product for review
- Reviewer: can receive and view the notification
- Administrator: can view all review activity

## Database Fields
Uses Laravel database notifications table. Payload:
title, message, module, entity_type, entity_id, priority, action_url.

## Business Rules
- Notification stored in the database
- Notification broadcast in real time
- Appears in the reviewer's notification bell
- Increases unread count
- Links to the product review page

## Acceptance Criteria
- Reviewer receives the notification without refreshing the page
- Notification is saved in the database
- Unread count increases by 1
- Clicking the notification opens the product review page
- Reviewer can mark the notification as read

## Technical Stack
Laravel Notifications · Database channel · Broadcast channel · Reverb · Echo ·
Vue notification component · Laravel Queue.
```

---

## 17. Code Audit

- The project uses **SonarQube / SonarSource** for code audit.
- Write code to pass static analysis: no dead code, no hardcoded secrets, handled exceptions,
  low duplication, and clear naming. Keep functions small and cohesive.

---

## 18. Decisions & Open Contradictions Log

Record every decision that resolves an ambiguity in the source requirements here.

| # | Topic | Source ambiguity | Decision | Status |
|---|---|---|---|---|
| D1 | Database engine | Stack line says **MariaDB**; the dev rules and Final Stack Summary say **PostgreSQL-compatible / PostgreSQL**. | Use **MariaDB/MySQL** as the engine (matches MAMP + stack), and write **portable migrations via the Laravel schema builder** so a future Postgres move is low-cost. | ⚠️ Confirm with product owner |
| D2 | Laravel version | Spec says "Laravel 12 / Laravel 13". | Target **Laravel 13** (current stable, released Mar 2026; PHP 8.3+). | ✅ |
| D3 | Custom CSS prefix | Source text garbled ("_moretogether"). | Namespace all custom FlyonUI extensions with the **`_moretogether`** prefix. | ⚠️ Confirm exact prefix |
| D4 | Boost & CLAUDE.md | `boost:install` generates its own AI guideline files. | This file holds product/architecture rules; reconcile Boost output rather than overwrite. | ✅ |
| D5 | Tenant = Workspace (single-DB) | §7 mandates single-database tenancy; stancl/tenancy defaults to per-tenant databases. | A **Workspace IS the stancl tenant** (`config/tenancy.php` `tenant_model` = `App\Models\Workspace`). `bootstrappers` is **emptied** — no per-tenant DB switching; isolation is the `BelongsToTenant` row scope only. `TenancyServiceProvider` strips the DB create/migrate/delete job pipeline from the stub. | ✅ (Phase 3) |
| D6 | Membership scoping | Should `workspace_memberships` be tenant-scoped like other tenant data? | **No — central/cross-tenant.** Memberships must be queryable without a tenancy context (workspace switcher + access checks before tenancy init). `workspace_invitations` **is** tenant-scoped (`BelongsToTenant`) to enforce automatic isolation of workspace-owned data. | ✅ (Phase 3) |
| D7 | Tenant above Workspace | The Tenant/Workspace/Membership requirement puts an owning **Tenant** above a workspace; `tenants` is already the WORKSPACE table here (D5), so the name is taken. | The requirement's Tenant is **`App\Models\Account`** (`accounts`), and its `workspaces.tenant_id` is **`tenants.account_id`**. One account per owner, created lazily on first workspace creation. Ownership grants no access — a workspace is still entered only through an active membership. See `docs/features/tenant-workspace-ownership.md`. | ✅ |

> **Action for owner:** confirm D1 (MariaDB vs PostgreSQL) and D3 (exact custom-class prefix)
> so this file can be finalized.

---

## 19. Phase 1 Scope Reminder

Phase 1 delivers the foundation: multi-tenant base, auth/roles, the real-time notification
system (database + broadcast channels, Reverb, Echo, Vue bell/panel), and the queue worker.
Email notifications are added per workflow only where required. Avoid overengineering.
