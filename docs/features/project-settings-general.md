# Project Settings → General

Source: *ProjectBlock 3.0 — Project Settings > General Requirements* (§ refs below are to that
document). Most of §26's Phase 1 list already existed — name, description, project ID, network,
timezone, lead, cover, archive/restore/delete. This slice adds the three that did not, and the
access rule behind one of them.

## What was added

| Field | Spec | Notes |
|---|---|---|
| **Work Item View** | §10, §24 | `all` \| `assigned`. Enforced server-side, not hidden in the UI. |
| **Default Assignee** | §12 | Applied when a work item is created with no assignee chosen. |
| **Project Subscribers** | §13 | Multi-select; the notification audience for this project. |

Plus §15's "No changes to save", §16's unsaved-changes warning, and §17's read-only creation
date.

## Data model

| Change | Notes |
|---|---|
| `projects.work_item_view` | Defaults to `all` — a migration must not quietly narrow anyone's existing access. |
| `projects.default_assignee_id` | Nulls on delete: losing the member clears the default rather than orphaning a reference. |
| `project_subscribers` | Separate from `project_members`: subscribing is about who wants to hear about the project, not who may act in it. Unique per pair, tenant-stamped by the relation. |

## Work Item View (§10)

The setting is only real if the API honours it, so it is applied in three places, all from one
predicate (`WorkItemPolicy::canSeeEveryItem`):

1. **The list query** — a restricted member's list is filtered at the database, not in the
   client. Hiding rows while the API still returns them is not a restriction.
2. **The single-item ability** — a hand-typed URL or a direct API call is refused. 404, not
   403, so the response does not confirm the item exists (§10's security requirement asks for
   either; 404 is what the rest of this app does).
3. **The relation/sub-task picker** — otherwise the search endpoint becomes a way to read the
   titles the setting hides.

**Privileged exceptions** (§10): workspace owner, workspace admin, project admin, project
lead. They administer the project, so the setting must not blind them to it.

## Default assignee (§12)

Applied at creation when no assignee was chosen; a manual choice always wins. The create form
always sends `assignee_ids`, so "none chosen" is an empty list rather than a missing key —
worth stating because the obvious `array_key_exists` check would never have fired.

## Open question — §8 Network vs Project Member Management §38

**This spec's §8 contradicts a rule already implemented and tested.**

- **§8 (this spec):** a *public* project is discoverable and accessible by eligible workspace
  members; *private* needs explicit membership.
- **Project Member Management §38 (built):** a workspace member gets **no** access to a project
  until they are explicitly added — public or not. `ProjectPolicy@view` implements this, and
  `ProjectAccessTest::test_public_visibility_does_not_grant_access_without_membership` locks it
  in.

The two cannot both hold: under §38, "public" currently changes nothing about access.

Implementing §8 was tried and reverted, because it is an access-control change that widens who
can see projects and the existing behaviour was a deliberate, documented decision. It also
explains a long-standing test failure:
`ProjectVisibilityLifecycleTest::test_public_project_is_visible_to_a_standard_member` asserts
the §8 behaviour and has been failing since §38 was implemented — one of the two tests is
stale, and which one depends on this decision.

**Needs a product decision.** Whichever way it goes, one test changes and the other stays.

## Identifier: renamed, lower case, and immutable (overrides §7)

§7 calls the field "Project ID", recommends uppercase, and allows it to be changed with a
warning. All three were overridden by the product owner:

**Renamed to "Identifier"** everywhere it is shown — the create modal, the settings form, the
project header, the delete confirmation, and validation messages.

**Lower case is the canonical form.** `websitered`, not `WEBSITERED`. Normalising in the form
requests alone would have left uppercase rows behind, so it is done in three places that
together cover every path:

| Where | Why it is needed |
|---|---|
| `ProjectCreator` | The single funnel every creation path goes through — seeds, tests, any future importer — not just the HTTP form. |
| `StoreProjectRequest` / `UpdateProjectRequest` | Normalise before the `unique` check, so `WEB` and `web` collide instead of both being stored. |
| Migration `2026_08_27_000001` | Existing rows. Collisions are skipped rather than merged — two projects that differ only in casing need a human decision, not a silent winner. |

Comparisons that a user types back — the delete confirmation, the immutability check — are
case-insensitive, so someone typing the old uppercase form is not stonewalled.

**Immutable once created:**

- The field renders read-only, with the reason under it.
- The form no longer sends it at all.
- `UpdateProjectRequest` refuses a *changed* value with "The identifier cannot be changed
  after the project is created." — refused rather than silently ignored, so an attempt gets an
  answer instead of appearing to work.
- Sending the current value is accepted (in any casing), since that is what any client echoing
  the payload will do.

The reason it matters: the identifier is baked into every work item identifier and into every
link already shared outside the app.

## Deferred

Subscriber notification delivery (§14) — the rows are stored and ready, but nothing sends to
them yet; that waits on the notification stack (CLAUDE.md §8/§9). Cover presets (§4).
