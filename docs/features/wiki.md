# Wiki & Knowledge Management

Source: *ProjectBlock — Wiki Product Detail Page*. A workspace-level knowledge system:
collections of pages, nested pages, manual ordering, public/private access, and archiving.

Built incrementally. This file grows one section per delivered slice; anything not listed under
**Delivered** is not built yet.

## Requirement

Give a workspace a home for knowledge that outlives any one project — policies, handbooks,
product and engineering documentation, SOPs, onboarding — organized into **collections** of
**pages**, with pages able to nest under other pages.

Distinct from Project Pages, which already exist: those document a *project*. Wiki documents the
*organization*. A requirement becomes a standard; a technical decision becomes architecture
documentation. Both use the same editor so nobody learns a second writing tool.

## User Roles

| Role | Can |
|---|---|
| Workspace Owner / Admin | Enable Wiki; create and administer any collection |
| Workspace Member | See and open public collections; see private ones they were invited to |
| Collection member — Edit | Create and update pages in that collection |
| Collection member — Read Only | Open and read pages, no changes |
| Collection member — Comment | *Planned.* Discuss without altering content |
| Guest | No Wiki access by default |

## Business Rules

- **WIKI-D1 — Wiki is a workspace capability, off until switched on.** It can be enabled while
  **creating** the workspace or at any time afterwards from **Settings → Wiki**. Nothing about
  the Wiki appears until it is on.
- **WIKI-D2 — one source of truth for "is Wiki on?"**: `workspace_settings.wiki_enabled`. The
  workspace-creation form sets that same flag rather than recording the chosen apps somewhere
  of its own; two records of one fact are two records free to disagree.
- **WIKI-D3 — Projects cannot be switched off.** It is the default app and the create screen
  shows it locked. An app list that can be emptied is a workspace that can do nothing.
- **WIKI-D4 — turning Wiki off hides it, never deletes it.** Same rule the other feature
  toggles in this application already follow: collections, pages and their content survive, and
  switching it back on restores them exactly.

## Database Fields

Nothing new yet — `workspace_settings.wiki_enabled` already exists and is what enabling writes
to. Collections and pages arrive with the slice that builds them.

## Acceptance Criteria

- Wiki can be enabled while creating a workspace, and is off unless chosen.
- Wiki can be enabled and disabled afterwards from Settings → Wiki.
- Projects cannot be deselected at creation.
- An app that is not released cannot be enabled, whatever the form submits.
- A workspace created without Wiki has `wiki_enabled` false; one created with it has it true.

## UI Requirements

- **Create workspace → Enable apps** — Projects locked on; Wiki a real choice; unreleased apps
  shown as Coming Soon and not selectable.
- **Settings → Wiki** — the enable toggle (already built), plus wiki labels.
- Wiki navigation (Home / Collections / Shared / Private / Archived), collection and page
  screens: later slices.

## Real-Time Requirements

None.

## Queue Requirements

None.

## Audit Requirements

To be decided with the slice that builds collections.

---

## Delivered

### Slice 1 — enabling the capability
Wiki is selectable on the create-workspace form and toggleable in Settings → Wiki. The create
form's `apps[]` was previously collected and **thrown away** — no controller read it — so the
step looked like a choice and changed nothing. It now validates against the released apps and
sets `wiki_enabled` on the new workspace.

## Not built yet

Collections; page CRUD and nesting; manual ordering; public/private collections and their
invite permissions; Wiki navigation; Shared; Archived; search.
