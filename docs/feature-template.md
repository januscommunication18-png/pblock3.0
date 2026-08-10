# Feature: <Feature Name>

> Copy this file to `docs/features/<feature-name>.md` for each new feature.
> Fill every section before asking Claude Code to generate code (see CLAUDE.md §4 workflow).

## Requirement
Describe what the feature should do.

## User Roles
List which users can access or manage this feature (e.g. Editor, Reviewer, Administrator).

## Database Fields
List required tables, columns, types, and relationships.
For tenant-owned data, note the tenant scope. For notifications, note the payload fields
(title, message, module, entity_type, entity_id, priority, action_url).

## Business Rules
List the rules that control behavior (validation, state transitions, permissions,
side effects, tenant isolation).

## Acceptance Criteria
List how the feature will be tested — concrete, verifiable statements
(the basis for feature tests).

## UI Requirements
List required screens, buttons, tables, modals, and components.
Note FlyonUI usage and any Vue components. Custom CSS uses the `_moretogether` prefix.

## Real-Time Requirements
Whether notifications, broadcast events, or live updates are needed.
If yes, name the channel pattern (private-user.{userId} / private-tenant.{tenantId} / ...).

## Queue Requirements
Whether background processing is needed (broadcast, email, import/export, AI, sync, audit).

## Audit Requirements
Whether activity should be logged, and what should be captured.

---

## Planning & Reasoning (filled by Claude Code)
Record the plan, decisions, and rationale here before/while generating code (CLAUDE.md §4).

## Files Changed
List models, migrations, controllers, routes, jobs, notifications, policies, requests,
services, events/listeners, Vue components, and tests created or modified.
