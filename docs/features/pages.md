# Project pages

Source: *ProjectBlock 3.0 — Project Pages Requirement*.

Pages are project documentation — requirements, meeting notes, decisions, specifications.
Optional per project, off by default (§3), and enabled from **Project → Settings → Pages**.

## Scope of this build

Phase 1 (§12), complete: the setting and its toggle (§3), the conditional tab (§2/§4), the
listing with its empty state (§7/§9), create / view / edit with the rich-text editor (§8/§10),
archive, project-scoped pages (AC-03…AC-06), permissions (§11), and preserve-on-disable /
restore-on-enable (§5/§6).

Phase 2, deliberately absent: @mentions (§13) and Wiki integration (§14). AC-09 and AC-11 say
neither is required now; AC-10 asks that mentions be *surfaced* as coming soon, which the
editor header does.

## Pages disable differently from Epics, Modules and Cycles

§15 puts all four on one lifecycle, and the toggle, the confirmation and the restore are indeed
the shared ones — see [feature-disable.md](feature-disable.md). One thing differs, and it is
worth knowing before assuming they are identical:

| | Epics / Modules / Cycles off | Pages off |
|---|---|---|
| Records | readable, frozen | **unreachable** |
| Tab | stays, marked Disabled | **removed** |

That is not an inconsistency, it is what the two specs ask for. The Feature Disable rules keep
an epic readable because a work item still *shows* its epic — hiding it would leave a property
pointing at nothing. A page is a document, not a property hanging off anything, so §5 restricts
direct access outright, and AC-01 wants the tab gone. A tab that opened onto a 404 would be
worse than no tab.

The feature declares this itself, in the catalog:

```php
'hides_when_disabled' => true,
```

so `ProjectNavigation` removes the tab without a special case named `pages`, and the next
feature with the same shape only has to say so.

## Decisions

| # | Question | Decision |
|---|---|---|
| P1 | §9's "page status" | **Draft / Published**, in a `status` column, defaulting to draft — documentation is written before it is ready to be read. Kept separate from `archived_at`: draft/published asks "is this ready?", archived asks "is this still current?", and a published page can be archived without becoming a draft again. |
| P2 | `parent_id` in Phase 1 | **Yes**, though nothing nests yet. §9 mentions a parent "when applicable" and §14 asks that pages join the Wiki hierarchy later *without migrating or recreating content* — a self-reference costs nothing now and is the one thing that is expensive to add once pages exist in the wild. |
| P3 | The editor | **`<wi-editor>`**, the component work item descriptions already use. §10's formatting list is exactly its toolbar, and reusing it brings paste handling, image upload and server-side sanitizing rather than a second editor that drifts. |
| P4 | Delete | **Soft**, and children are re-parented to nothing rather than deleted with their parent (`nullOnDelete`). §5's principle is hide, never destroy; the same instinct applies to removing one page. |

## Saving

Three triggers: a pause in typing, leaving the title or the editor, and ⌘/Ctrl+S. All of them
**flush the editor first** — `<wi-editor>` syncs its model on a 120ms debounce, so a save inside
that window reads the previous value. With the "skip when nothing changed" guard, that lag
meant a word typed and immediately saved was dropped entirely. `flush()` exists for exactly
this, and every submit path in work-items.js already calls it.

Leaving the page mid-edit sends a `keepalive` request, which survives the document going away
where a normal fetch does not — otherwise clicking the breadcrumb within the autosave window
loses the last thing typed.


The editor autosaves on a debounce, and ⌘/Ctrl+S forces it — the reflex everyone has in a
document editor, which should not open the browser's save dialog over an app that already
saves. A failed save leaves the indicator reading "Unsaved changes": it must never claim a save
that did not happen.

Only the open page's body travels with the screen. The listing carries every page's metadata
and none of their content — fifty documents in a list is no reason to ship fifty documents.

## Acceptance criteria → tests

§16's AC-01 … AC-12, in `tests/Feature/Project/ProjectPageTest.php`.
