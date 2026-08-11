# Workspace Member Invite Flow — Acceptance & Membership Activation

Source: `ProjectBlock 3.0 — Workspace Team Member Invitation, Signup, Onboarding & Membership
Activation` (§ references below are to that document). This slice covers the spec's
**Phase 2 (Invitation Signup)**, **Phase 3 (Membership Activation)** and **Phase 4 (Existing
User Flow)**. Phase 1 (Members screen, Invite Member form, invitation storage, pending status)
already shipped with Phase 3 — Create Workspace; this slice makes those invitations actually
usable.

## Requirement

An invitation must reach the invited person as an email with a secure link, and that link must
carry them all the way to being an active member of the inviting workspace:

- The invitation email carries a single-use, expiring token (§13, §15–§17).
- `GET /invite/{token}` shows the invitation (workspace, inviter, role, email) or a precise
  reason it cannot be used — expired, revoked, already accepted, workspace unavailable
  (§18–§20, §62–§64, §68–§70).
- A new user accepts, verifies their email, completes the existing onboarding steps, and is
  joined to the inviting workspace — **never** shown Create Workspace (§21, §26–§31, §29).
- An existing user accepts without repeating onboarding or creating a second account
  (§52–§55).
- Membership activation revalidates capacity, applies the invitation's role, marks the
  invitation accepted, sets the invited workspace active, and lands the user in it
  (§33–§40, §49–§51, §91).
- Acceptance is idempotent: repeat submissions never duplicate users, memberships or seats
  (§74, §75).

## User Roles

| Actor | This slice |
|---|---|
| Workspace Owner / Admin | Already able to invite and revoke (`WorkspacePolicy@invite`). Unchanged here. |
| Manager / Member / Viewer / Guest | Assignable invitation roles; the invited user cannot change the role they were given (§40). |
| Invited user (new) | Accepts, verifies email, completes onboarding, joins. |
| Invited user (existing) | Accepts and joins; no second account, no repeated onboarding (§55). |

## Database Fields

| Table | Change | Notes |
|---|---|---|
| `workspace_invitations` | **+ `user_id`** (nullable FK → `users`, `nullOnDelete`) | §11/§32: the invitation is associated with the user as soon as the identity is known — at code verification, *before* onboarding completes and before membership exists. |
| `workspace_invitations` | `token` reused | Already `UNIQUE`, already a SHA-256 hash. What changes is that a **raw** token is now generated and used in the link; only its hash is stored (§13, §79). |
| `workspace_memberships` | unchanged | `status` (`active`/`invited`/`suspended`), `role`, `invited_at`, `joined_at` already cover §37/§38 for this slice. |

Deliberately not added: a `workspace_invitation_events` audit table (§76/§77) and
`resend_count` / `last_sent_at` (§60). Those belong with the admin actions slice — see
*Deferred* below.

## Business Rules

1. **Token.** 64 random characters via `Str::random`, stored only as `hash('sha256', $raw)`.
   The raw token exists in memory long enough to build the email link, never in the database
   and never in a log. The URL exposes no workspace, user or role id (§13).
2. **Expiry.** `config('workspace.invitation_expiry_days')` (14). A pending invitation past
   `expires_at` is treated as expired on read and flipped to `expired` in place, so the
   Members screen stops offering it (§14, §62).
3. **Single use.** Acceptance marks the invitation `accepted` and stamps `accepted_at`.
   Re-opening an accepted link never re-processes it; a still-member user is redirected into
   the workspace instead (§64).
4. **Email must match.** The invitation can only be accepted by the invited email address. A
   signed-in user with a different email is blocked with the §54 message and offered Sign out
   (§53, §54, §91.8).
5. **Role comes from the invitation** at activation time, so an admin changing the role while
   the invitation is pending wins (§40, §41).
6. **Capacity is checked twice** — when the invitation is created and again at activation,
   because the last seat may have gone in between (§9, §34, §67).
7. **Idempotent activation.** The invitation row is locked for the duration of the
   transaction; membership is `updateOrCreate`d on `(workspace_id, user_id)`, which is already
   `UNIQUE`. Re-running yields the same membership, the same seat, no duplicate (§75).
8. **No workspace creation for invited users.** A user with no workspaces and a pending
   invitation is routed to the join screen instead of first-workspace onboarding, and the
   "invite your teammates" step is marked complete on join so they land in the workspace
   (§29, §51).
9. **Only a user with no workspaces is auto-routed** to a pending invitation. An existing
   member of other workspaces accepts explicitly from the link, so a pending invitation can
   never hijack their normal navigation.
10. **Passwordless.** The invited signup reuses this app's 6-digit email code — see
    Decisions D-I2.

## Acceptance Criteria

Invitation delivery
- ☑ Creating an invitation sends one email to the invited address with the workspace name,
  inviter name, assigned role, expiry date and an Accept Invitation link.
- ☑ The stored token is a hash; the emailed link contains the raw token; the same raw token is
  never recoverable from the database.

Landing page
- ☑ A valid token renders the workspace name, inviter, role and invited email.
- ☑ Expired / revoked / already-accepted / unknown tokens each render their own message and
  never expose workspace internals.
- ☑ A signed-in user whose email does not match sees the §54 message and cannot accept.

New user
- ☑ Accepting as a guest sends a code to the invited email, which is locked — submitting a
  different email cannot redirect the invitation.
- ☑ After verification the user completes the existing profile/role/goals steps.
- ☑ Create Workspace is never shown; the user is routed to the join screen instead.
- ☑ On joining, membership is active with the invitation's role and the workspace is active.

Existing user
- ☑ A signed-in matching user accepts in one click; no onboarding is repeated.
- ☑ No duplicate user account is created for an email that already exists.
- ☑ A user already in other workspaces keeps normal navigation until they accept.

Activation
- ☑ Capacity is revalidated at acceptance; a workspace that filled its last seat in the
  meantime blocks the join with the §67 message instead of erroring.
- ☑ Accepting twice (double submit, refresh) yields exactly one membership.
- ☑ The invitation becomes `accepted`; the member appears as Active on Settings → Members.

## UI Requirements

| Screen | Content |
|---|---|
| Invitation landing (`/invite/{token}`) | Workspace name + logo, inviter, role, invited email, Accept CTA (§19, §20). |
| Invitation unavailable | One panel, message per state: expired (§62), revoked (§63), already accepted (§64), workspace unavailable (§68–§70), no seat (§67). |
| Wrong account | §54 message, Sign out, Cancel. |
| Join / welcome | "Welcome to {Workspace}" + Go to Workspace (§49). |

All reuse `layouts.auth` and the existing FlyonUI/Tailwind classes — no new CSS.

## Real-Time / Queue / Audit

- **Queue:** the invitation email is queued (CLAUDE.md §11). The mailable carries scalars
  only — no Eloquent models — so it deserializes without a tenancy context.
- **Real-time:** none. §78 (notify the inviter when someone joins) waits for the Phase 1
  notification stack (Reverb/Echo) to exist.
- **Audit:** structured log lines for created / accepted / activation-blocked. The
  audit *table* in §76/§77 is deferred with the admin-actions slice.

## Decisions

| # | Topic | Ambiguity | Decision |
|---|---|---|---|
| D-I1 | Slice size | The source document spans 5 phases. | Build Phases 2–4 (email → landing → accept → activate). Resend, change-role-while-pending, the audit table and inviter notifications are a follow-up slice. |
| D-I2 | Password | §22–§24 specify password + confirm password; §26/§30/§31 say reuse the existing signup experience. This app is passwordless (6-digit email code). | **Reuse the passwordless code.** Introducing a password path for invited users only would fork authentication for one entry point. The emailed code also satisfies §71's email-ownership requirement. Confirmed with the product owner. |
| D-I3 | Seats | §9/§10/§34–§36/§65–§67 assume a subscription; no subscription or plan model exists. | `WorkspaceSeatGuard` counts active members + pending invitations against `config('workspace.seat_limit')` (null = unlimited) and is called at both invite and activation. When billing lands, the limit source changes inside the guard and no caller moves. Confirmed with the product owner. |
| D-I4 | Where the invited user is remembered | Session vs database. | `workspace_invitations.user_id`, set at verification (§32). A session key would not survive a different browser or a delayed onboarding, and the spec explicitly wants the association recorded. |
| D-I5 | Auto-routing to a pending invitation | §51 says land in the invited workspace; §83/§84 say a user may belong to many workspaces. | Auto-route **only** when the user has no workspaces. Otherwise the invitation is accepted explicitly from the link, so a pending invitation cannot capture an existing user's navigation. |

## Files Changed

| File | Change |
|---|---|
| `database/migrations/2026_08_20_000001_add_user_id_to_workspace_invitations_table.php` | **New** — associates an invitation with the user it resolved to (§32). |
| `app/Models/WorkspaceInvitation.php` | Raw/hashed token helpers, cross-tenant `findByToken()` / `pendingFor()`, `isExpired()` / `isAcceptable()` / `markExpiredIfLapsed()`, `user()` relation. |
| `app/Services/WorkspaceInviter.php` | Generates a raw token per invitation, queues the invitation email, applies the seat guard, retires lapsed invitations, reports `suspended_member` separately from `already_member`. |
| `app/Services/WorkspaceSeatGuard.php` | **New** — the capacity seam (D-I3). |
| `app/Services/WorkspaceInvitationAccepter.php` | **New** — the §91 acceptance sequence: validate, lock, activate, apply role, mark accepted, set active workspace. |
| `app/Mail/WorkspaceInvitationMail.php` + `resources/views/emails/workspace-invitation.blade.php` | **New** — queued invitation email (§15–§17). |
| `app/Http/Controllers/Invitation/InvitationController.php` | **New** — the public link: show / start / accept. |
| `app/Http/Controllers/Invitation/PendingInvitationController.php` | **New** — the signed-in join step and the welcome screen. |
| `resources/views/invitations/*` | **New** — landing, unavailable, mismatch, pending, joined + shared summary/error partials. |
| `routes/invitation.php` | **New** — `/invite/{token}`, `/invite/{token}/start`, `/invite/{token}/accept`, `/invitations/pending`, `/invitations/joined`. |
| `app/Http/Controllers/Auth/VerifyCodeController.php` | Stamps `user_id` on any invitation waiting for the verified address (§32). |
| `app/Services/OnboardingRouter.php` | Routes an invited user to the join step instead of Create Workspace (§29/§51, D-I5). |
| `config/workspace.php` | `seat_limit`. |
| `resources/views/layouts/auth.blade.php` | `@stack('head')`, so the welcome screen can carry its own redirect (§50). |
| `public/assets/js/settings/members.js` | Messages for the two new invite results (`no_seats`, `suspended_member`). |
| `tests/Feature/Workspace/InvitationAcceptTest.php` | **New** — 14 tests across delivery, landing states, both user flows, activation and idempotency. |

## How to verify

```bash
php artisan migrate
php artisan test tests/Feature/Workspace/InvitationAcceptTest.php   # 14 passed
```

Manually: Settings → Members → Invite Member, then open `/emaillog` (local only) to read the
queued invitation and follow its **Accept invitation** link. Opening it signed out runs the
new-user path; opening it signed in as the invited address accepts in one click; opening it
signed in as anyone else shows the wrong-account screen.

## Deferred (next slice)

Resend invitation (§60), change role while pending (§59), invitation audit table (§76/§77),
inviter notification on join (§78), member suspend/remove lifecycle (§38, §95), analytics
events (§86), and a real subscription/seat source (§43, §44).
