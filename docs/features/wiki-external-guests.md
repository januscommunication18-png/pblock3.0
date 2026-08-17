# Wiki — Access: internal and external members

Extends [Wiki & Knowledge Management](wiki.md), Slice 3 (the collection detail and who can open
it) and Slice 7 (the public URL). Read those first.

Not built yet. This file is the specification.

## Requirement

The collection's **Access** box gains two actions:

- **Invite team member** — somebody already in the workspace. Pick the person, choose their
  collection access level. This is today's invite, renamed and given a button of its own.
- **Invite external member** — somebody who is *not* in the workspace. A modal takes their
  **name**, **email address** and **login method** (default: **Magic Link Login**), and emails
  them a secure link. They never set a password.

```text
Collection → Access → Invite External Member → Name + Email → Send Invitation
   → Magic Link Email → External Member Opens Link → Authorized Collection
```

**An external member reaches the collection they were invited to, and nothing else.** Not the
workspace, not the Wiki, not another collection — not even a second collection they were also
invited to, without that collection's own link.

## The two decisions that shape this, and their costs

**The invitation is the authorization — publication is irrelevant.** A guest reads the collection
whether it is Draft, Published or Unpublished. The point is to hand a client or an auditor one
document without putting it on the internet first, so the public URL and its `status` play no part
here. Consequence to accept: `status` no longer decides everything about who can read a
collection, and the collection screen has to say so where the two settings sit.

**The link is reusable until revoked.** It is their door; it keeps working. That makes the URL a
**durable bearer credential** — anyone it is forwarded to has the same access until the guest is
removed. Being deliberate about that, not silent:

- The token is **64 random characters**, and only its **SHA-256 hash is stored** — the same
  handling `WorkspaceInvitation` already gives its tokens, so a database dump is not a set of
  working links.
- **Removing the guest kills the link immediately**, and that is said on the button.
- The Access box states it plainly where the link is created: *"Anyone with this link can read
  this collection until you remove them."* A risk somebody chose is different from one nobody
  mentioned.
- Every open is **logged and timestamped** (`last_seen_at`), so "who has been reading this" is
  answerable.
- Guest pages are `noindex`, and the token never appears in a page the guest can see.

## User Roles

| Role | Can |
|---|---|
| Collection creator / workspace admin | Invite and remove both kinds of member; resend a link |
| Collection member — Edit | Nothing here. Deciding who may read is not an editorial act |
| Internal member | Read, or read and edit, per their permission |
| **External member** | **Read one collection.** No account, no workspace, no editing, no other collection |

Both actions sit behind **`manageableBy`** — the gate that already guards access and publishing.

## Database Fields

### `wiki_collection_guests` — TENANT-SCOPED

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `tenant_id` | fk → workspaces | `BelongsToTenant`, like the rest of the Wiki |
| `wiki_collection_id` | fk, cascadeOnDelete | deleting the collection takes its guests with it |
| `name` | string(120) | what the administrator typed; a list of bare addresses is unreadable |
| `email` | string, lowercased and trimmed | |
| `login_method` | string(20), default `magic_link` | a **string**, not a boolean — the modal calls it a choice, and password / SSO are the obvious next values |
| `token` | string(64), unique | **SHA-256 of the raw token.** The raw value exists only long enough to build the email |
| `invited_by` | fk → users nullOnDelete | |
| `invited_at` | timestamp | when the link was last sent, for "Resent 2h ago" |
| `last_seen_at` | timestamp nullable | never opened is a fact worth showing |
| timestamps | | |

`unique(wiki_collection_id, email)` — re-inviting the same address **resends** rather than making
a second row, the rule re-inviting a member already follows.

**No `users` row, no `workspace_memberships` row.** A guest is an address with a token against one
collection. They appear in no member picker, no mention list, no notification target. Deleting the
row is the entire act of revocation, which is why revocation cannot half-happen.

**No new codes table.** `email_verification_codes` and `AuthCodeService` are for people signing
in; a guest never signs in. Reusing them would put a purpose in that table for something that
never becomes a session on a `User`.

### `wiki_collections` — unchanged

No `guests_only` column. An earlier draft gated the public URL with one; with the invitation as
the authorization, the public URL keeps meaning exactly what Slice 7 says it means and this
feature does not touch it.

## Business Rules

- **WG-1 — an invitation authorizes one collection.** The token resolves to a single
  `wiki_collection_id`. A guest invited to two collections gets two links, and each opens only
  its own. There is no guest landing page listing "your collections".
- **WG-2 — publication is not consulted.** Draft, Published and Unpublished all read the same to
  an invited guest. What a guest may read is decided by their row, and by nothing else.
- **WG-3 — but archiving still wins.** An archived collection is closed to its guests, the same
  way it is closed to its members (WIKI-D5). Archiving means retired, and retired cannot mean
  "retired, except for the client".
- **WG-4 — a guest reads.** No editor, no comments, no page actions, no access list. The reader
  template renders exactly what a published page renders, minus anything naming the workspace's
  other content.
- **WG-5 — removing a guest is immediate.** The row is checked on every request, so the link and
  any open session die on the next page load. A session that outlives the row is a permission
  nobody can withdraw.
- **WG-6 — the link is a credential and is treated as one.** Hashed at rest, never logged, never
  rendered on a page, and regenerated on **Resend** so an old email stops working. Resending is
  therefore also how you cut off a forwarded link without removing the person.
- **WG-7 — an unknown, revoked or malformed token is a 404**, never a 403 and never a "this link
  has expired for that collection". A 403 confirms the address belongs to something real.
- **WG-8 — the guest's email is never shown to other guests**, and the guest is never shown the
  Access box, the member list, or who else was invited.
- **WG-9 — only workspace people invite.** `manageableBy`. A guest can never invite anybody, and
  there is no request-access flow.
- **WG-10 — inviting is rate-limited** per collection and per inviter. Each invitation sends mail
  on somebody's behalf.
- **WG-11 — everything is logged**: invited, resent, removed, opened, and every refused token —
  with the address and the collection. This is the one surface where workspace content leaves the
  workspace.
- **WG-12 — `login_method` is validated against a known list**, today `['magic_link']`. A method
  the application cannot perform must be refused at the request rather than stored and discovered
  later.

## Acceptance Criteria

**The Access box**
- Shows both actions on every collection, private or public, to whoever can manage it.
- Internal and external members are listed separately and are visually distinguishable.
- **Invite team member** offers only workspace members, and an access level, exactly as today.
- **Invite external member** opens a modal with Name, Email and Login Method defaulting to
  Magic Link Login.
- An invalid email is refused; the address is stored lowercased and trimmed.
- Re-inviting an address already on the collection resends and updates the name — it does not
  create a second row.
- A `login_method` outside the known list is refused (422).
- A member with `edit` sees neither action and is refused by every endpoint (403).

**The link**
- Sending emails the address a link containing a token that appears nowhere in the database in
  plaintext.
- Opening it renders the collection read-only and records `last_seen_at`.
- It keeps working on later visits.
- **Resend** issues a new token and the previous link 404s.
- **Remove** makes the link 404 immediately, mid-session.
- A tampered, unknown or removed token is a 404.

**The boundary**
- A guest of collection A gets a 404 at collection B's guest URL.
- A guest reaches no workspace screen: `/wiki`, `/wiki/collections/{id}`, `/projects` and the rest
  all refuse them, because they are not signed in as a user at all.
- An archived collection is a 404 for its guests.
- Draft and Unpublished collections open normally for a guest — publication is not consulted.
- The guest page is `noindex` and names no other collection.

## UI Requirements

### The Access box

Today it is an avatar row headed **Access**, rendered only on a private collection
(`wiki-collection.js`, `v-if="isPrivate"`). It becomes a card shown on **every** collection —
a public collection can now have external members, so "who can read this" is no longer answered by
visibility alone.

```text
Access
────────────────────────────────────────────────
Team members
  ●● Rohit (Owner)   ●● Sarah Johnson (Can edit)      [+]

External members
  Sarah Lee    sarah@client.com     Opened 2d ago     [Resend] [✕]
  Tom Ridge    tom@auditors.com     Never opened      [Resend] [✕]

[ Invite team member ]  [ Invite external member ]
```

- Team members keep the avatar row and its hover tooltips — that part works.
- External members are a **list with the address visible**, because an address is the whole
  identity here and an initial in a circle would not tell you who `t.r@…` is.
- **Opened / Never opened** answers the question this list actually gets asked.
- **Remove** confirms, and says what it does: *"Their link stops working immediately."*
- On a **public** collection the box explains the difference in one line: everyone in the
  workspace can already read it; the external list is who outside it can.

### The Invite external member modal

```text
Invite external member
────────────────────────────────────────────
Name            [ Sarah Lee                ]
Email address   [ sarah@client.com         ]
Login method    [ Magic Link Login      ▾  ]
                They receive a secure link and never set a password.

Anyone with the link can read this collection until you remove them.

                        [ Cancel ]  [ Send invitation ]
```

Login method is a real select with one option today — the modal calls it a choice, and a field
that will grow a second value should not be a sentence that has to become a field later.

### The guest's view

`wiki/reader.blade.php`, the template Preview and the published page already share, with a banner
naming the workspace and stating this is a shared document. No sidebar, no topbar, no workspace
navigation — a guest has nowhere else to go, and offering links that 404 is worse than offering
none.

## Real-Time Requirements

None.

## Queue Requirements

The invitation email goes through **Mail**, like `WorkspaceInvitationMail`. Queue it with the
others if and when that becomes the pattern; nothing here is special.

## Audit Requirements

Per WG-11 — invited, resent, removed, opened, refused. Address and collection id on each.

## Build plan

~~**Slice 1 — the Access box, and internal invite moved into it.**~~ **Delivered.**

The card shows on **every** collection now, not only a private one. It was private-only because
visibility was the whole answer to *who can read this* — a public collection was open to the
workspace and there was nothing more to say. Once a collection can carry members from outside the
workspace, visibility stops being the whole answer, so the box that gives the rest of it has to be
present to give it. On a public collection it says which part it is answering: *"Everyone in the
workspace can read this. These are the people named on it."*

The faces are now headed **Team members**, and the two ways in are named buttons beneath them.
**The dashed "+" at the end of the row is gone**: with two kinds of invitation it could only have
meant one of them, and a control that silently picks is worse than two that say which — the same
reason the pencil beside the collection name gave way to the ⋯ menu. **Invite external member** is
rendered plainly unavailable with a title until Slice 2, the posture the rest of this feature
already takes toward things that are not built.

One test moved rather than being added: the browser suite's *"does not show an access list on a
public collection"* asserted the old rule, so it now asserts the new one and explains why it
changed. The box is Vue-drawn, so its markup is the browser suite's to assert; the feature tests
check what the **server** owes it — the payload both invitations are gated on.

~~**Slice 2 — external members.**~~ ~~**Slice 3 — the guest door.**~~ **Both delivered, together.**

They shipped in one pass deliberately: an invitation email whose link 404s is worse than a button
that is plainly disabled, so the door had to exist before the invitation could be sent.

`wiki_collection_guests`, tenant-scoped, one row per address per collection. **Only the SHA-256 of
the token is stored** — the raw value lives between minting and the mailable and nowhere else, so
a database dump is not a set of working links. It is absent from `toCard()` too: a credential
belongs in one email, not in a payload the browser can read, and a test asserts it never reaches
the collection screen.

- **Re-inviting an address resends** and mints a fresh token rather than making a second row — so
  it is also how somebody cuts off a forwarded link without removing the person. **Resend** does
  the same thing deliberately, and its tooltip says so.
- **Removing kills the link immediately.** The row is resolved on every request, not once at the
  start of a session, so revocation cannot be outlived.
- **Publication is not consulted** — a Draft opens for its guests, which is the whole point. But
  **archiving still wins** (WIKI-D5).
- Everything wrong is a **404**: unknown token, revoked guest, archived collection, Wiki switched
  off. A 403 would confirm the address belongs to something real.
- The guest sees the same `wiki/reader.blade.php` the published page renders, with a banner naming
  the workspace, and `noindex, nofollow` — a shared document is not a public one and must not turn
  up in a search result because a crawler followed a forwarded link.
- Inviting is behind **`manageableBy`**; an `edit` member is refused.
- The email says plainly that there is nothing to sign up for, and asks them to keep the link to
  themselves.

**Files:** `2026_09_25_000010_create_wiki_collection_guests_table.php` ·
`App\Models\WikiCollectionGuest` · `App\Http\Requests\Wiki\StoreCollectionGuestRequest` ·
`App\Http\Controllers\Wiki\CollectionGuestController` · `App\Http\Controllers\Wiki\WikiGuestController` ·
`App\Mail\WikiGuestInvitationMail` · `emails/wiki-guest-invitation.blade.php` · `routes/wiki.php` ·
`CollectionController::payload()` · `wiki/reader.blade.php` · `wiki-collection.js` ·
`tests/Feature/Wiki/GuestsTest.php` (17 tests).

**Still to do:** the rate limit (WG-10) is specified and not built.

## Decisions

| # | Decision | Why |
|---|---|---|
| G1 | The **invitation is the authorization**; publication is not consulted | Lets a draft be shared with one client without putting it on the internet. Reverses an earlier draft that gated the public URL |
| G2 | **Magic link**, reusable until revoked — not the six-digit code an earlier draft chose | Owner's decision. The cost is a durable bearer credential in a URL; hashing, per-person revocation, regeneration on resend and a plain warning in the UI are what make it a chosen risk rather than a hidden one |
| G3 | A guest is **not a `User`** | No account, no workspace, no membership; deleting the row is the whole of revocation |
| G4 | **One token, one collection** | "Only the collection(s) they were invited to" is enforced by the token resolving to exactly one, rather than by a filter somebody could forget |
| G5 | `login_method` is a **string with a validated list** | The modal presents it as a choice; password and SSO are the obvious next values, and a boolean would have to be migrated away |
| G6 | The Access box shows on **public** collections too | A public collection can now have external members, so visibility alone no longer answers "who can read this" |
| G7 | **Archived beats invited** | Retired cannot mean "retired, except for the client" (WIKI-D5) |
| G8 | Wrong token → **404**, never 403 | A 403 confirms the address belongs to something real |

## Open questions

1. **Does removing a guest tell them?** Proposed: no email. Access being withdrawn is not usually
   news you want to send, and the link simply stops working.
2. **Should the guest's link survive the collection turning private?** Proposed yes — an
   invitation is already an individual decision, and visibility governs the workspace, not this
   list. Same reasoning as WG-2.
3. **A limit on external members per collection?** None proposed. Worth one if this ever becomes
   a way to mail-blast, though WG-10's rate limit is the sharper tool.
