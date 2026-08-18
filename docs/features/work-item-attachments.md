# Work Item Attachments

Implements the paperclip in the work item detail action row (work-items.md §4.4, §42), which
until now rendered as an inert control tooltipped "Attachments — coming soon".

## Requirement

Attach files to a saved work item, list them on the detail drawer, download them, and remove
them. Distinct from description **media** (`work_item_media`): media is an image embedded in
the description HTML by the editor, an attachment is a file that belongs to the work item as a
record in its own right and is never inlined into the body.

## User Roles

| Role | May |
|---|---|
| Anyone who can view the project's work items | List and download attachments |
| Anyone who can create work items (`canEdit`, §34) | Upload and delete attachments |
| Nobody else | Nothing — 404, never 403, so a refusal cannot confirm a file exists |

Upload and delete share the work item's own edit gate rather than introducing a third rule:
§34 already says whoever may create may edit and delete, and the drawer's other controls are
gated on exactly that.

## Database Fields

`work_item_attachments` — tenant-scoped (CLAUDE.md §7), and project-scoped on top, for the same
reason `work_item_media` is: serving a file has to be authorized, which means resolving it back
to the project that gates it.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `tenant_id` | string | `BelongsToTenant`; FK to `tenants`, cascade delete |
| `project_id` | FK projects | cascade delete — authorization resolves through this |
| `work_item_id` | FK work_items | **required**, cascade delete. Unlike media, an attachment cannot exist before its item: the paperclip only appears on a saved item |
| `uploaded_by` | FK users nullable | `nullOnDelete` — the file outlives the account |
| `disk` | string(32) | recorded, not assumed, so rows survive a disk switch |
| `path` | string | path on that disk |
| `name` | string | the original client filename, shown in the list |
| `mime` | string(128) | |
| `size` | unsigned bigint | bytes |
| `timestamps` | | `created_at` orders the list |

Index on `(work_item_id, created_at)` — the only read pattern.

## Business Rules

- Files are stored **private** on `config('filesystems.media_disk')` and streamed through an
  authorized route. Never the public disk, and never a direct bucket URL: a public link works
  for anyone who receives it regardless of workspace (§7).
- The disk is **recorded per row**. Changing `MEDIA_DISK` affects new uploads only; existing
  rows keep streaming from wherever they were written.
- Accepted types: images plus common documents — `jpg, jpeg, png, webp, gif, pdf, doc, docx,
  xls, xlsx, csv, txt, zip`. Executables are not on the list; arbitrary uploads into shared
  storage is a risk this feature does not need to take.
- Max size `config('projects.attachments.max_kb')` (5120 KB), matching media.
- One bad file fails the batch, as it does for media: a half-inserted set leaves the author
  guessing which one did not make it.
- Deleting a row deletes the file from its disk. A failure to delete the file is logged and the
  row is still removed — an orphaned blob is recoverable, a row pointing at nothing is not.
- Upload failures are logged and returned to the client. They must never present as success.

## Acceptance Criteria

- A member who can edit uploads a file; it appears in the list with name, size and uploader.
- The file lands on the configured disk with **private** visibility, and its bucket URL returns
  403 to an anonymous request.
- The stored row's `disk` equals the disk actually written to.
- A viewer who can see the project can download it; a stranger gets 404.
- A guest is stopped by `auth` middleware.
- A viewer who cannot edit sees no upload control and is refused a delete.
- Deleting removes both row and file.
- Deleting the work item removes its attachments.
- A rejected type or an oversized file is refused with a message, and nothing is stored.
- A failed disk write returns an error, logs it, and creates no row.

## UI Requirements

Two surfaces, deliberately split: the modal **adds**, the detail page **lists**.

- The paperclip in the action row becomes a live `<button>` opening the "Add attachments" modal.
- **Modal** — a "Choose files" drop target, the list of files chosen so far (each removable),
  and a **Cancel / Save** footer. Files are *staged only*: nothing is written until Save, so
  Cancel genuinely cancels. Save is disabled with nothing staged. On failure the modal stays
  open with the staging list intact, so a rejected file can be removed and the rest retried.
- **Detail page** — an "Attachments" section in the same card as Links, one line item per file:
  paperclip icon, name (a download link), size, relative time, and a remove button where
  `canEdit`. Collapsible, open by default, hidden entirely when there are none.
- `hasStructure()` counts attachments, or an item whose only addition is a file would render an
  empty detail with the file nowhere on it.
- Errors are shown in the modal, not swallowed.

## Real-Time Requirements

None. Attachments do not broadcast in this slice; the modal reloads its list after a change.

## Queue Requirements

None. Uploads are small and synchronous, consistent with media.

## Audit Requirements

Upload and delete failures are logged via `Log::error` with disk, bucket, project, work item
and the underlying message. Activity-feed entries are out of scope for this slice.

## Decisions

| # | Question | Decision |
|---|---|---|
| A1 | Any file type? | No — allow-list of images + common documents. Executables excluded. |
| A2 | Who may delete? | Anyone who can edit the work item (§34), not uploader-only. |
| A3 | Versioning? | Out of scope. Re-uploading adds a second row; it does not replace. |
| A4 | Orphan sweep for media | Still deferred (work-items.md §42). Attachments delete their own file on delete, so they do not add to that debt. |
