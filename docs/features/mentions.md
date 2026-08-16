# @mentions

Source: *ProjectBlock 3.0 — JoditPro @Mention Integration Requirement* (31 sections). Section
numbers below refer to it.

Typing `@` in a rich-text field offers the people who can see this project; picking one inserts
a chip that keeps their user id; the backend then decides — independently of the browser —
who was really mentioned and tells them.

## Scope of this build

**Slice 1 (this build): the backend.** Everything that decides *who was mentioned* and *who
hears about it* — §4, §5, §9–§16, §23, §24, §25, §27, §28. Complete and covered by tests.

**Slice 2 (next): the editor.** Vendoring `tributejs` + `jodit-tributejs`, the `@` popup, the
chip, keyboard navigation, the display styling and the click popover — §1–§3, §6, §7, §17,
§18, §21, §22, §29.

**Deferred, with a reason.** §19/§20's **Inbox** does not exist: there is no `notifications`
table and the topbar's Inbox button is a dead control. Building one is a feature in its own
right. Until it lands a mention arrives by **email**, carrying the same information and the
same deep link — the owner's call (D-M1). §9's real-time step is deferred with it:
`BROADCAST_CONNECTION=log` and Reverb is not configured.

That split is the spec's own (§31): the editor handles the `@` UX, ProjectBlock owns identity,
persistence, validation and notification. Because the Inbox will read the `mentions` table,
none of slice 1 has to change when it arrives.

## Requirement

- Typing `@` in a work item description or a comment offers people who can access the project.
- Selecting somebody inserts a chip that stores their **user id**, not their name (§8).
- The backend validates every submitted id, records the mention, and notifies the person.
- Editing content notifies only the **newly** named (§11/§12).

## User Roles

Anyone who can open a project's work items may use the autocomplete — a Commenter writing a
reply needs the same list as a Contributor writing a description, and looking somebody up
changes nothing.

Who may be **mentioned** (§16): workspace Owner/Admin (who reach every project), plus everyone
explicitly added to the project. A workspace user with no access to the project is never
offered and never accepted.

## Database Fields

`2026_09_22_000001_create_mentions_table` — **`mentions`**, tenant-scoped (CLAUDE.md §7):

| Column | Why |
|---|---|
| `source_type`, `source_id` | Polymorphic: `work_item` (a description) or `comment`. §2 lists five more surfaces to come; one table is what lets the Inbox later ask "everywhere I was named" with one query. |
| `work_item_id` | Denormalised. §20 has to navigate back to the item, and the Inbox should not join through every possible source table to find out which one. |
| `user_id` | Who was named. |
| `mentioned_by` | Who named them — **not** the source's author: an edit can add a mention, and it is the editor who mentioned you. |
| `UNIQUE(source_type, source_id, user_id)` | §14's deduplication, at the database rather than in the code that writes it. |

`RichTextSanitizer` additionally permits `data-user-id` and `data-mention-type` on `<span>`
(§25) — and nothing else new.

## Business Rules

1. **The editor is not the source of truth (§23/§24).** A `<span data-user-id="999">` is a
   claim. Nothing is recorded until the id names a real user who is mentionable *in this
   project*. A crafted payload naming an administrator produces exactly nothing.
2. **Only structured mentions count (§27).** Typing `@Rohit Philip` by hand is plain text.
   This is what stops pasted or spoofed text raising notifications (§26).
3. **The id is the identity (§8).** Renaming yourself does not break an existing mention.
4. **One person, one mention per body (§14)** — however many times the text names them.
5. **Editing is a diff (§11/§12).** Only people newly named are notified; names removed from
   the text have their records deleted, so the content and the records cannot drift apart.
6. **Mentions are processed after the save (§10).** A work item's description mention needs an
   item id to point at; a comment's needs a comment id.
7. **Self-mentions are recorded but never notified (§15).** The content genuinely names you —
   the Inbox will want to render it — the email would be absurd.
8. **The list that suggests and the check that validates are the same code.** A list offering
   somebody the backend then rejects is a broken feature; the reverse is a permission hole.

## Acceptance Criteria

- **MN-01** The autocomplete offers project members and workspace admins, never a workspace
  user with no project access.
- **MN-02** It searches name and email, and returns avatar/initial/email for the popup.
- **MN-03** Someone who cannot open the project gets 404 from the endpoint.
- **MN-04** The mention chip's `class`, `data-user-id` and `data-mention-type` survive
  sanitizing; `onclick` does not.
- **MN-05** A description mention is recorded after the item exists, with the item, the named
  user and the namer — and emails the person.
- **MN-06** Several names create one record each; the same name twice creates one.
- **MN-07** A self-mention is recorded and sends nothing.
- **MN-08** `@Name` typed by hand records nothing.
- **MN-09** A crafted id for someone outside the project records nothing. So does a
  non-existent id.
- **MN-10** Editing notifies only the newly named; removing a name deletes its record.
- **MN-11** A comment mention records the comment as its source and the work item alongside,
  and its email links to `#comment-{id}`.

## UI Requirements

Slice 2. The one decision already taken: a **central** editor configuration (§21/§22) — the
mention setup belongs to `<pg-editor>`, which every rich-text field already mounts, so no form
configures it for itself.

## Real-Time Requirements

Deferred with the Inbox (§9). `MentionNotifier` is the only place delivery happens, so adding a
broadcast channel later is a change in one file.

## Queue Requirements

None. Mail is sent immediately, per the decision recorded in `WorkItemStatusNotifier`.

## Audit Requirements

The `mentions` rows are the record. Mentions are not written to `work_item_activity`: that feed
is the item's own history, and "was mentioned" is about a person, not about the item.

---

## Planning & Reasoning (Claude Code)

### Decisions

| # | Question | Decision |
|---|---|---|
| D-M1 | §19/§20's Inbox does not exist. How does a mention arrive? | **Email now, Inbox later** — owner's call, put before building. The mail carries what the Inbox would: who, where, an excerpt, and a link to the exact comment. Because delivery reads the `mentions` table rather than the editor, the Inbox becomes a reader of the same rows (§31). |
| D-M2 | Sequencing | **Backend first.** The security half (§23/§24) is the half that can be got dangerously wrong, and it is the half a test suite can actually prove. The editor cannot be verified without a browser — which this session has twice needed to catch a client-side bug. |
| D-M3 | One `mentions` table or a column per surface? | **One, polymorphic.** §2 names five more surfaces; the Inbox's only query is "everywhere I was named", which a column-per-surface design answers with a union. |
| D-M4 | Parse with a DOM or a regex? | **Regex on `data-user-id`.** The markup has already been through `RichTextSanitizer`, which is what decides the attribute may exist at all — so parsing only has to read what survived that. The security boundary is the sanitizer plus `MentionableUsers`, not the parser. |
| D-M5 | Where does the self-mention rule live? | **At the notifier, not the sync.** §15 skips the *notification*; the record still belongs, because the content really does name that person and the Inbox will render it. |

### What the sanitizer change does and does not mean

`data-user-id` now survives sanitizing. That is **not** a decision to trust it — it is what
makes the id readable at all so `MentionSync` can then refuse it. The attribute is data; the
authority is `MentionableUsers`. A test asserts both halves: the attribute survives, and a
crafted id for an outsider still produces no mention and no email.

### Not built

The editor integration (slice 2), the Inbox (§19/§20), real-time (§9), the mention click
popover (§18), and mention display styling (§17) — all listed above with their sections.

## Files Changed

**Migration** — `2026_09_22_000001_create_mentions_table.php`

**Model** *(new)* — `app/Models/Mention.php`

**Services** *(new)* — `app/Services/MentionableUsers.php` (who may be named — one definition,
used by both the suggestion list and the validation), `app/Services/MentionSync.php` (parse,
validate, dedupe, diff), `app/Services/MentionNotifier.php` (delivery, and only delivery)

**Controller** *(new)* — `app/Http/Controllers/Project/MentionController.php`

**Wiring** — `WorkItemCreator` (§10), `WorkItemUpdater` (§11),
`WorkItemCollaborationController` (§9/§12)

**Sanitizer** — `app/Services/RichTextSanitizer.php` (§25)

**Mail** *(new)* — `app/Mail/MentionedMail.php`, `resources/views/emails/mentioned.blade.php`

**Routes / config** — `routes/project.php`, `config/projects.php` (`mention_results`)

**Tests** *(new)* — `tests/Feature/Project/MentionTest.php` (15)
