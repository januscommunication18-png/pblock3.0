# Module Management

Source: *ProjectBlock 3.0 — Module Management Requirements* (§ refs are to that document).

A Module groups related work items inside one project towards a common objective. Unlike a
Cycle it is not time-boxed. A work item belongs to **one module at a time** — see "One module
per work item" below, which reverses §9.3.

## Scope of this build

Built: the Modules feature toggle and its own Project Settings page (§4), the modules table
and Modules tab (§4/§7), the modules landing page with the empty-state placeholder and Add
Module button (§7), create/edit with lead and members (§5), status (§6), progress from the
linked work items (§10), adding and removing work items (§8/§9), archive/restore and soft
delete (§12/§13), and the Module property on work items with history events (§9.1/§17).

Deferred: the module intro video is wired to `MODULE_INTRO_VIDEO`, which is unset, so the
empty state renders a placeholder panel rather than a player.

## Module status is stored; cycle status is not

Cycles derive status from their dates (see [cycles.md](cycles.md)) because §9 there *defines*
status as a function of the dates. Modules are the opposite case: §6 lists Backlog, Planned,
In Progress, Paused, Completed and Cancelled, and none of those follow from `start_date` and
`end_date` — Paused and Cancelled in particular are statements about intent that no date can
express. So `modules.status` is a real column, defaulting to `backlog` (§5.2).

## The Modules toggle moved to its own settings page

The toggle started on Settings → Features alongside Cycles. It now lives on Settings → Module,
reached from the settings left menu, because §4 puts module configuration on its own screen.
The catalog in `config/projects.php` gained a `section` key so a feature can declare which
settings page it renders on, rather than each page hard-coding a list of feature keys.

## Tenant-stamped pivots and blank instances

`module_members`, `module_work_items` and `project_subscribers` all carry `tenant_id`, applied
with `withPivotValue()`. That broke as soon as any module had a member:

    InvalidArgumentException: The provided value may not be null.

Eager loading builds a relation on a **blank** model instance, where `$this->tenant_id` is
null and `withPivotValue(..., null)` throws. `App\Models\Concerns\StampsPivotTenant` resolves
the value from the instance first and the tenancy context second:

```php
protected function pivotTenantId(): string
{
    return (string) ($this->tenant_id ?? tenant()?->getTenantKey() ?? '');
}
```

The same flaw sat in four relations, including `Project::subscribers()` which had already
shipped. `ModuleTest::test_every_tenant_stamped_pivot_survives_a_blank_instance` asserts the
shape rather than one symptom, so the next relation added cannot reintroduce it silently.

## The Module property on work items

§9.1 puts a Module selector on the work item. Single-select: choosing a module replaces
whichever one the item was in, and choosing the one it is already in takes it out — the same
control shape as the Cycle chip. Server side:

| Piece | Role |
|---|---|
| `WorkItem::modules()` | `belongsToMany` — many, unlike `cycle()` which is a `belongsTo`. |
| `App\Rules\ModuleAssignable` | Refuses a module that is archived (§12.2/§15) or belongs to a project with Modules switched off (§4.2). |
| `WorkItemUpdater` | Syncs `module_ids` and writes a `modules` history entry with the titles resolved at write time. |

Two deliberate asymmetries in `ModuleAssignable`:

- **Already-linked ids are exempt.** The request echoes the full module list back on every
  save, so saving an unrelated property on an item that happens to sit in an archived module
  must not be refused. Only what is genuinely being *added* is judged.
- **Clearing is always allowed, even with the feature off.** Otherwise turning Modules off
  would trap work items in a module nobody can see or remove.

History stores the module *titles*, not just ids, so renaming a module later cannot rewrite
what the feed says happened.

## Property rows carry their own "+"

The drawer's Modules, Cycle and Labels rows were each a single button wrapping their chips.
With several chips applied there was no obvious place left to click, and an empty row read
"None" — which says nothing about being able to add anything. Each row now renders its chips
plus a dashed `+` button; the chips stay clickable, since that is where the eye goes first.

The Cycle row shows its `+` **only while empty**. §8.3.1 there is one cycle per work item, so
a persistent `+` would promise a second cycle the picker will not give.

## Data model

| Table | Notes |
|---|---|
| `modules` | Tenant- and project-scoped. `title`, `description`, `status`, `start_date`, `end_date`, `lead_user_id`, `archived_at`, `created_by`, soft deletes. |
| `module_members` | Tenant-stamped pivot. Involvement only — §5.2 keeps it independent of work item assignment. |
| `module_work_items` | Tenant-stamped pivot, unique on the pair so adding the same item twice is a no-op (§15). |

## The grid and the counter stay in step

The detail page's grid is `<work-items-screen>`, fed by its own payload — so adding or
removing work items has to write BOTH that payload and the lean `items` behind the header
count, or the two disagree until a reload. See `embedded-work-item-grid.md`.

## One module per work item (§9.3 reversed)

§9.3 originally gave a work item several modules — "a functional module and a release module at
once" — which is why the link is a pivot table rather than a `module_id` column. The product
decision is now **one module at a time**, independent of the cycle rule: an item may hold one
cycle AND one module, because a cycle says *when* work happens and a module says *where* it
belongs.

Three layers, so the rule holds wherever it is approached:

| Layer | What it does |
|---|---|
| `module_work_items.work_item_id` **unique** | the guarantee — no writer can get around it |
| `ModuleController@search` → `whereDoesntHave('modules')` | the picker offers only unassigned work |
| `ModuleController@addWorkItems` | **422** for an item already in another module |

Plus `module_ids` capped at `max:1` in both work-item requests, and the work item's own Module
chip changed from multi-select to single-select.

The pivot **stays**. A unique index expresses "one at a time" without a data migration, and
reversing the decision later costs one index rather than migrating back out of a column. The
payload keeps sending `modules` as a list for the same reason.

Migrating existing data: any work item found in more than one module keeps its FIRST link and
loses the rest — the earliest link is the original decision, the later ones were only possible
because the rule did not exist yet. It was a no-op on the database this shipped against (153
links, none duplicated) and exists because that is not a guarantee about anybody else's.

To move work between modules: remove it from the one it is in, or change the item's own Module
chip, which now moves rather than adds.

## Acceptance criteria → tests

All in `tests/Feature/Project/ModuleTest.php` (19 tests):

- Tab and page appear only when the feature is on; disabling keeps every module and link (§4.2)
- A module needs only a title and starts in Backlog (§5.2); a blank/whitespace title and an
  end date before the start are rejected (§15)
- Work items can be added and removed without being deleted (§8.3); adding twice does not
  duplicate (§15)
- A work item sits in at most one module, and module changes reach history (§17)
- The Add work items picker offers only work with no module; adding one that has since been
  filed elsewhere is refused with 422
- An archived or foreign module cannot be assigned (§12.2/§15)
- With the feature off, no new assignment is accepted but clearing still works (§4.2)
- The screen loads with members attached — the `withPivotValue` regression above
