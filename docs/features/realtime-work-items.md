# Real-Time Work Item Updates (Slice 1 — Work Items screen)

> Status: **spec, awaiting approval.** No code written yet.
> Implements CLAUDE.md §8 (notification architecture), §11 (queues), §12 (Reverb), §13 (Echo).
> Scope decision: wire the pipeline end to end, but subscribe **only the Work Items screen**.

---

## Requirement

When someone changes a work item in a project, everyone else looking at that project's Work
Items list sees the row change **without reloading the page** — a new item appears, an edited
item's chips update, an archived or deleted item leaves the list.

Today none of this happens. `BROADCAST_CONNECTION=log`, `app/Events/` is empty,
`routes/channels.php` carries only Laravel's default user channel, there is no
`config/reverb.php`, and Echo is never booted on any page. The packages (`laravel/reverb`,
`laravel-echo`, `pusher-js`) are installed and unused.

A separate fix already landed for the related-but-different problem of **your own** action not
showing (structure writes now return the affected rows — see `work-items.md`). This spec is
about *other people's* changes.

### Explicitly out of scope for this slice

Epics, Cycles, Modules, Pages, comments, the notification bell, presence ("who else is here"),
and live-typing on the description. Each needs its own event and subscription; the point of
doing Work Items alone first is to prove the channel, the authorisation and the client merge
on one screen before repeating them.

---

## User Roles

Broadcast is a **read** concern, so it follows the existing read rules exactly — it introduces
no new permission and must not widen an existing one.

| Role | Behaviour |
|---|---|
| Workspace Owner / Admin | Receives updates for every project in the workspace they can view |
| Project Admin / Member | Receives updates for projects they are a member of |
| Any user, project set to *assigned work items only* | Receives updates **only for items assigned to them** — see the security rule below |
| Non-member | Cannot subscribe at all; channel authorisation refuses |

---

## The security rule that shapes the design

`WorkItemPolicy::view()` is per-item, not per-project: a project with
`restrictsToAssigned()` shows a member only the items assigned to them. A channel, by
contrast, is per-project — everyone subscribed gets the identical message.

**So the broadcast payload carries no work item content.** The event announces only *that*
something changed:

```
{ project_id, work_item_ids: [10, 8], action: 'updated', actor_id: 3 }
```

Each client then fetches the affected rows through an endpoint that runs the normal policy, so
a user who may not see item 10 receives nothing for it and their list is unchanged. Correct by
construction, rather than correct as long as nobody later adds a field to the payload.

The cost is one small request per viewer per change. A burst is coalesced client-side (below),
and the alternative — putting the card in the payload and only *sometimes* trusting it — is a
conditional security rule, which is the kind that rots.

---

## Database Fields

**None.** No migration. This slice adds no tables and no columns; `notifications` already
exists from Laravel's default and is untouched here.

Configuration only:

| Key | Where | Value |
|---|---|---|
| `BROADCAST_CONNECTION` | `.env`, `.env.example` | `reverb` (was `log`) |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | `.env` | generated locally; `.env.example` gets blank placeholders, never real values |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | `.env` | `127.0.0.1`, `8080`, `http` |
| `VITE_REVERB_*` | — | **not used.** The project screens load plain JS from `public/assets`, not Vite, so the client reads these from `<meta>` tags rendered by Blade instead |
| `QUEUE_CONNECTION` | `.env` | stays `sync` for now — see Queue Requirements |

---

## Business Rules

1. **Channel.** `private-project.{projectId}`. One channel per project, authorised in
   `routes/channels.php` by `$user->can('view', $project)` — the same policy the screen itself
   uses. Tenant isolation comes free: `ProjectPolicy::view()` starts from the user's workspace
   membership, so a user cannot subscribe to another tenant's project (CLAUDE.md §12).
2. **The event.** `App\Events\WorkItemsChanged implements ShouldBroadcast`, carrying
   `project_id`, `work_item_ids`, `action` (`created` · `updated` · `removed`), `actor_id`.
3. **Fired from the service layer, not the controllers.** `WorkItemCreator`, `WorkItemUpdater`,
   `WorkItemRelationManager` and the archive/restore/duplicate/delete paths in
   `WorkItemController` all mutate items; firing from the services means a future caller cannot
   forget. Where a controller mutates directly (archive/restore/destroy), it fires there.
4. **`toOthers()`.** The actor already sees their own change through the existing response
   path; re-applying it from a broadcast would fight the optimistic update. Requires the
   client to send `X-Socket-Id`, which `PB.api` does not do today — see UI Requirements.
5. **Relations broadcast both ends.** Blocking 10 from 8 changes 10's row. The event carries
   both ids, exactly as the fix to `WorkItemStructureController::payload()` already does for
   the actor's own response.
6. **`removed` means "gone from this list", not "deleted".** Archive, soft-delete, and a state
   change that filters the item out all produce the same client behaviour: drop the row.
7. **A missed message is not a broken screen.** If Reverb is down, Echo is absent, or the
   licence-free `pusher-js` fails to connect, the screen behaves exactly as it does today —
   the subscription is wrapped so a failure to connect never throws into the Vue app.
8. **No new write path.** Broadcasting never triggers a write; a client receiving an event only
   ever issues a GET.

---

## API

One new read-only endpoint:

```
GET /projects/{project}/work-items/cards?ids[]=10&ids[]=8
→ { ok: true, cards: [ …rows the CALLER may see… ] }
```

- Authorised by `viewAny` on the project, then each row filtered by
  `WorkItemScreenPayload::cards()`, which already drops items failing `can('view', $i)`.
- Ids capped (50) so the endpoint cannot be used to enumerate a project.
- Returns the identical row shape the list was rendered with, so the client merge path is the
  one that already exists.

---

## UI Requirements

No new screens, no new controls. The Work Items list simply changes under the user.

1. **Vendor Echo.** Copy `node_modules/laravel-echo/dist/echo.iife.js` and `pusher-js`'s UMD
   build into `public/assets/js/vendor/`, the same pattern `vue.global.prod.js` already
   follows, and note their provenance in a short README as the Jodit vendor drop does.
2. **`public/assets/js/realtime.js`** — boots Echo from `<meta name="pb-reverb-*">` tags and
   exposes `PB.listen(channel, event, handler)` returning an unsubscribe function. Absent
   config or absent Echo makes `PB.listen` a no-op that returns a no-op, which is what keeps
   rule 7 true.
3. **`X-Socket-Id` on writes.** `PB.api` gains the header when Echo has a socket id, so
   `toOthers()` works. One line in `settings/app.js`; every screen benefits.
4. **Subscription in `work-items.js`.** On mount, subscribe to the project channel; on
   unmount, unsubscribe. The handler collects ids into a set and fetches **once** after a
   ~300 ms quiet period, so ten rapid edits are one request.
5. **Merging.** The fetched rows go through the existing `mergeCards()`, which replaces rows in
   place and redraws only those rows — scroll position and collapsed groups survive. `removed`
   drops the row; `created` appends it and rebuilds, since a new row belongs in a group.
6. **Never overwrite what the user is editing.** If a row's picker is open, or the drawer is
   open on that item with unsaved edits, the update is held and applied on close — otherwise a
   colleague's change yanks the field mid-edit.
7. **No toast.** A notification per remote change would be noise on a busy project; the row
   changing is the feedback. (Toasts for *your own* actions stay as they are.)

---

## Real-Time Requirements

This slice **is** the real-time requirement. Stack, per CLAUDE.md §8: Laravel event →
`ShouldBroadcast` → queue → Reverb → Echo → the Vue screen.

Running it locally needs a second and third process alongside `php artisan serve`:

```bash
php artisan reverb:start          # the WebSocket server
php artisan queue:work            # only once QUEUE_CONNECTION leaves sync
```

That operational cost is real and worth stating plainly: without `reverb:start` the feature is
silently inert (rule 7 — the screen still works, it just does not update live).

---

## Queue Requirements

`ShouldBroadcast` queues by definition, and CLAUDE.md §11 requires it. But
`QUEUE_CONNECTION=sync` today, which means the broadcast happens **inline in the request** —
so a slow or unreachable Reverb would slow every work item save.

**Decision:** leave `QUEUE_CONNECTION=sync` in this slice and keep the event `ShouldBroadcast`
(not `ShouldBroadcastNow`), so switching to a real driver is a `.env` change and nothing else.
Flagged rather than fixed because moving the whole app off `sync` affects the existing
assignment and blocked-by emails too, and that belongs in its own change.

---

## Audit Requirements

None. Broadcasting is a read-side projection of changes the existing activity log already
records; logging it again would double-count every edit.

---

## Acceptance Criteria

Automated (feature tests):

1. `Event::fake()` — creating, updating, archiving, deleting and relating a work item each
   dispatch `WorkItemsChanged` with the right `work_item_ids` and `action`. Relating carries
   **both** ids.
2. The event broadcasts on `private-project.{id}` and its payload contains **no** title,
   description, or any other item content — asserted on the array keys, so adding a field to
   the payload fails the test rather than leaking silently.
3. A project member can authorise the channel; a workspace member who is not a project member
   cannot; a user in another workspace cannot (tenant isolation).
4. `GET …/work-items/cards?ids[]=` returns only rows the caller may see: on a project set to
   *assigned work items only*, a member gets their assigned item and **not** the other one,
   even though both ids were asked for.
5. That endpoint refuses ids from another project, and caps the number of ids.
6. The Work Items page renders the Reverb `<meta>` tags and loads `realtime.js`.
7. Existing suite still passes — 415 passing, the same 7 known pre-existing failures.

By hand, two browsers on `/projects/1/work-items` signed in as different members:

8. B changes an item's state → A's row moves group without a reload.
9. B creates an item → it appears in A's list.
10. B archives an item → it leaves A's list.
11. B blocks 10 with 8 → the **Blocked** chip appears on A's row 10.
12. A makes a change → A's own screen does not double-apply it (`toOthers()`).
13. Stop `reverb:start` → both screens keep working normally, just without live updates; no
    console errors beyond a connection warning.
14. A has a row picker open when B edits that row → the picker is not disturbed; the change
    lands when it closes.

---

## Files

| File | Change |
|---|---|
| `config/reverb.php` | new — published |
| `.env` / `.env.example` | `BROADCAST_CONNECTION=reverb`, `REVERB_*` (placeholders in the example) |
| `routes/channels.php` | `private-project.{projectId}` authorisation |
| `app/Events/WorkItemsChanged.php` | new — `ShouldBroadcast` |
| `app/Services/WorkItemCreator.php`, `WorkItemUpdater.php`, `WorkItemRelationManager.php` | dispatch the event |
| `app/Http/Controllers/Project/WorkItemController.php` | dispatch on archive/restore/duplicate/destroy; new `cards()` action |
| `app/Services/WorkItemScreenPayload.php` | Reverb meta into the bootstrap |
| `routes/web.php` | the `cards` route |
| `resources/views/projects/work-items.blade.php` | Reverb `<meta>` tags, `realtime.js` |
| `public/assets/js/vendor/echo.iife.js`, `pusher.min.js`, `README.md` | vendored |
| `public/assets/js/realtime.js` | new — Echo boot + `PB.listen` |
| `public/assets/js/settings/app.js` | `X-Socket-Id` header |
| `public/assets/js/projects/work-items.js` | subscribe, coalesce, merge |
| `tests/Feature/Project/WorkItemRealtimeTest.php` | new — criteria 1–6 |

No migration. No change to any existing policy.

---

## Open questions for the owner

- **Q1 — `QUEUE_CONNECTION`.** Recorded above as *stay on `sync` for now*. Say if you would
  rather move the app to `database` as part of this; it is a small change but it makes the
  queue worker mandatory for assignment emails too.
- **Q2 — Production.** Reverb needs a long-running process and a WebSocket route through the
  web server. Nothing here assumes a deployment target; worth deciding before this reaches one.
