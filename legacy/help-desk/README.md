# Legacy Help Desk

This folder contains the previous ProjectBlock Help Desk / Help Center implementation.

The module was moved here before the Help Center architecture and product scope were redesigned.

Do not modify or use this code for new development unless specifically requested.

This code is retained as a reference and backup of the previous implementation.

---

## What is in here

Moved out of the active application on 2026-08-18, complete and unmodified. Nothing was
rewritten, no logic was changed, and no database table, column or row was touched.

| Folder | Was |
|---|---|
| `backend/controllers/` | `app/Http/Controllers/HelpDesk/` (15 files) |
| `backend/requests/` | `app/Http/Requests/HelpDesk/` (6 form requests) |
| `backend/models/` | `app/Models/HelpDesk*.php` (11 models) |
| `backend/services/` | `app/Services/HelpDesk/` (13 services) |
| `backend/policies/` | `app/Policies/HelpDeskPolicy.php`, `HelpDeskConversationPolicy.php` |
| `backend/jobs/` | `app/Jobs/IngestInboundEmail.php`, `RecordEmailDeliveryEvent.php` |
| `backend/listeners/` | `app/Listeners/GrantHelpDeskMembership.php` |
| `backend/notifications/` | `app/Notifications/HelpDeskDeliveryFailed.php` |
| `frontend/views/help-desk/` | `resources/views/help-desk/` (12 Blade screens) |
| `frontend/views/help-desk-nav.blade.php` | `resources/views/partials/help-desk-nav.blade.php` |
| `frontend/js/help-desk/` | `public/assets/js/help-desk/` (10 Vue-in-Blade components) |
| `routes/help-desk.php` | `routes/help-desk.php` |
| `config/help-desk.php` | `config/help-desk.php` |
| `database/migrations/` | the nine migrations that create and alter the `help_desk_*` tables |
| `tests/HelpDesk/` | `tests/Feature/HelpDesk/` (14 feature tests, 199 assertions' worth) |
| `docs/help-desk.md` | `docs/features/help-desk.md` — the build spec, phases 1–2c |

The PHP files keep their original `App\…` namespaces. They are **not autoloaded**: composer
maps `App\` to `app/`, so nothing in here is reachable from the running application. Restoring a
file means moving it back to the path in the table above.

## What deliberately stayed in the active application

Four things, each because removing it would have broken something that is not the Help Desk:

1. **`database/migrations/2026_09_26_000001_add_help_desk_enabled_to_workspace_settings.php`** —
   adds a column to `workspace_settings`, which is a workspace table. `WorkspaceApps` reads it,
   the workspace-creation form offers it, and tests cover both. The column and its data are
   untouched.
2. **`App\Services\WorkspaceApps`'s `helpdesk` flag** and **`config/workspace.php`'s
   `apps.helpdesk`** — the app-enablement plumbing, which belongs to the workspace module.
3. **`App\Events\WorkspaceInvitationAccepted`** — fired by `WorkspaceInvitationAccepter`, which
   is workspace code. Only its Help Desk *listener* moved; the event stays and currently has no
   listeners.
4. **`.env.example`'s `HELP_DESK_*` block** — configuration notes for the inbound email
   endpoints. Inert now that `config/help-desk.php` has moved, and left in place rather than
   deleted so the next implementation can reuse or replace it deliberately.

## The database

Every `help_desk_*` table and every row in them is still there. No `DROP TABLE`, no rollback
migration, no data change of any kind.

The migration FILES moved, which means a database built from scratch today will not have those
tables. Existing databases are unaffected — their `migrations` rows remain, and `php artisan
migrate` has nothing new to run.

Tables as they stand: `help_desks`, `help_desk_spaces`, `help_desk_inboxes`,
`help_desk_members`, `help_desk_member_inboxes`, `help_desk_invites`, `help_desk_activity`,
`help_desk_conversations`, `help_desk_messages`, `help_desk_message_attachments`,
`help_desk_email_addresses`, `help_desk_email_deliveries`.

## What replaced it

`routes/help-center.php` registers one route — `GET /help-desk`, still named
`help-desk.index` — which renders `resources/views/help-center/placeholder.blade.php`: a
"Coming soon" screen. It exists only so the rail entry in a workspace that had the app switched
on still leads somewhere. There is no controller, no policy and no data behind it.
