# Back Office — Authentication & Security

> Platform administration, at `/backoffice`, behind
> **Authorized Email → One-Time Email Code → Password**.

---

## Requirement

The Back Office is the platform's own admin area, living inside the ProjectBlock codebase but
behind a boundary the customer application cannot cross. A visitor to `/backoffice` proves the
email is authorized, proves they hold that mailbox, and only then is shown a password screen.
Its session is independent of the customer app's: being signed in as a customer grants nothing.

## Scope of THIS build

Sections 1–11 of the requirement — the authentication and security boundary — plus a dashboard
shell to land on.

**Deliberately NOT built:** the module pages §12 lists (`/customers`, `/workspaces`, `/plans`,
`/subscriptions`, `/invoices`, `/settings`, `/integrations`, `/email-settings`, `/system-log`).
Each is a feature in its own right with no specification here, and stubbing nine empty screens
would be nine promises the Back Office then has to break. `/backoffice/admins` IS built, because
§8's "manage Back Office users" and the last-Super-Admin invariant are security rules, not a
module — the boundary is not finished without them.

## User roles

| Role | May |
|---|---|
| Super Admin | Everything, including managing Back Office users and roles |
| Admin | Everything except managing Back Office users and roles |
| Read-only | View, change nothing |

## Database fields

**`backoffice_users`** (new — CENTRAL, never tenant-scoped)
`id`, `name`, `email` (unique), `password_hash` (nullable), `role`, `is_active`,
`password_set_at`, `last_login_at`, `last_login_ip`, `created_by`, timestamps.

`password_hash` is NULLABLE on purpose: §7 forbids a hard-coded password, so a seeded Super Admin
starts with none and must set one through the reset flow before a first login.

**`backoffice_audit_logs`** (new — CENTRAL)
`id`, `backoffice_user_id` (nullable), `email` (nullable), `action`, `ip`, `user_agent`,
`succeeded` (bool), `meta` (json), `created_at`.

Nullable user: the events worth auditing most — a code requested for an address that is not
authorized, a failed login — have no user to point at. The email is recorded as TYPED.

**Reused, not rebuilt:** `email_verification_codes` (already hashed, single-use, 10-minute TTL,
5-attempt cap, `purpose`-discriminated) gains `PURPOSE_BACKOFFICE`. `password_reset_tokens`
already exists and gets its own broker.

## Business rules

1. `/backoffice` never reveals whether an address exists. Unauthorized, disabled and unknown all
   produce one message and one timing path.
2. A code is 6 digits, hashed at rest, single-use, expires in 10 minutes, allows 5 attempts, and
   is invalidated when a new one is requested.
3. Verifying the code writes a short-lived server-side verification session (20 minutes). Without
   it `/backoffice/login` is unreachable.
4. The login screen's email is fixed to the verified address and is not accepted from the
   request — the browser is not asked to hold it honestly.
5. Back Office auth uses its own guard and its own session key. A customer session grants nothing,
   and vice versa.
6. Idle timeout 30 minutes; absolute timeout 12 hours; session regenerated on login, invalidated
   on logout.
7. A password reset returns the user to `/backoffice`, never straight in.
8. At least one active Super Admin must always exist. The last one cannot be deleted, disabled or
   downgraded — refused server-side, not merely hidden.
9. Every event in §11 is written to the audit log, success or failure.

## Acceptance criteria

- `/backoffice` shows the Security Verification screen.
- An unauthorized, disabled or unknown email gets the same message as an authorized one.
- An authorized email receives a 6-digit code, subject **Your Back Office verification code**.
- A wrong code fails; 5 failures stop the code working; the code dies after 10 minutes; a resend
  kills the previous one.
- `/backoffice/login` visited directly, without verification, redirects to `/backoffice`.
- The login screen shows the verified email read-only.
- Correct password lands on `/backoffice/dashboard`; wrong password is refused and audited.
- Signing into the customer app grants no Back Office access, and vice versa.
- A password reset ends at `/backoffice`, not at the dashboard.
- The final active Super Admin cannot be deleted, disabled or downgraded.
- Every listed event appears in the audit log with IP and user agent.

## Decisions

| # | Decision | Why |
|---|---|---|
| BO-D1 | `backoffice_users` is a SEPARATE table from `users`, not a flag on it | A flag makes every customer row one bad query away from platform administration, and the two have genuinely different shapes — a Back Office user has no workspace, no tenancy, no onboarding. Separate tables also mean the Back Office guard's provider physically cannot resolve a customer account. |
| BO-D2 | `AuthCodeService` is REUSED, with a new `purpose`, rather than a Back Office copy | It already implements every code rule the requirement asks for — hashed, single-use, 10-minute TTL, 5 attempts, prior codes invalidated on reissue. A second copy would be a second place for those rules to drift, and the drift would be silent. Only the MAIL differs, which is a parameter. |
| BO-D3 | The verification session is server-side session state, not a signed cookie or a token in the URL | §4 asks for a short-lived server-side verification. A URL token would survive being pasted into a chat; a cookie value would be the browser's claim about its own authorization. |
| BO-D4 | The login screen ignores any submitted email and uses the verified one from the session | Accepting it would make the whole verification step decorative: verify as one address, log in as another. |
| BO-D5 | `password_hash` is nullable and the seeded Super Admin has none | §7 forbids a hard-coded password. A null hash cannot be brute-forced and forces the reset flow, which is the only path that proves mailbox control. |
| BO-D6 | The last active Super Admin invariant is enforced in the MODEL, not in the controller | It is the one rule that can lock everybody out of the platform permanently. A controller check is bypassed by the next controller; a model check is not. |
| BO-D7 | Uniform failure everywhere, including timing | "Do not reveal whether the account exists" is defeated by a fast path for unknown addresses. The unauthorized branch does the same hash work as the authorized one. |
| BO-D8 | The nine module routes of §12 are NOT stubbed | An empty screen behind a working nav item reads as a broken feature rather than an absent one. |
| BO-D9 | The customer idle-timeout stack (`EnforceIdleTimeout`, `InjectSessionGuard`) skips `/backoffice*` entirely | Both apps share one browser session. Somebody signed into both who let the CUSTOMER session go idle was signed out by the customer's rules while working in the Back Office — the shared session is invalidated, which takes the `backoffice` guard with it — and landed on `/signin`, which grants nothing here. Back Office timeouts are `BackofficeSessionTimeout`'s alone, and they end at `/backoffice`. |

---

## Files

**New**
- `app/Models/BackofficeUser.php`, `BackofficeAuditLog.php`
- `app/Services/Backoffice/BackofficeVerification.php`, `BackofficeAudit.php`
- `app/Http/Middleware/EnsureBackofficeVerified.php`, `BackofficeSessionTimeout.php`
- `app/Http/Controllers/Backoffice/` — `SecurityVerificationController`, `LoginController`,
  `PasswordResetController`, `DashboardController`, `AdminController`
- `app/Mail/BackofficeCodeMail.php`, `app/Notifications/BackofficePasswordReset.php`
- `routes/backoffice.php`, `resources/views/backoffice/*`, `resources/views/emails/backoffice-code.blade.php`
- `database/seeders/BackofficeSuperAdminSeeder.php`
- Migrations `2026_08_23_100001` (users) and `100002` (audit logs)

**Changed**
- `config/auth.php` — `backoffice` guard, `backoffice_users` provider, `backoffice_users` reset broker
- `app/Models/EmailVerificationCode.php` — `PURPOSE_BACKOFFICE`
- `app/Services/AuthCodeService.php` — sends `BackofficeCodeMail` for that purpose
- `app/Providers/AppServiceProvider.php` — `manage-backoffice-admins` gate
- `bootstrap/app.php` — middleware aliases, and `redirectGuestsTo` (see below)
- `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `.env.example`

## A pre-existing bug this surfaced

An unauthenticated request to any `auth:`-guarded route returned **500 — `Route [login] not
defined`**, not a redirect. Laravel's `Authenticate` middleware sends guests to `route('login')`
by default and this application has never had a route by that name; its sign-in is `signin`.

The Back Office is simply where it showed up — the first `auth:`-guarded group to be exercised by
a signed-out visitor. `bootstrap/app.php` now sets `redirectGuestsTo()`, splitting on the URL
prefix because the middleware runs before the guard has failed: `/backoffice*` goes to the
Security Verification screen, everything else to `signin`. The customer app gets the correct
redirect it should always have had.

## Verified

Driven through the real HTTP kernel with one persistent session, then walked in the browser.

| | Result |
|---|---|
| `/backoffice/login` without verification | 302 → `/backoffice` |
| Unknown address | Same redirect, same screen, **0 codes issued, 0 mail sent** |
| Authorized address | 1 mail, code hashed (`$2y$…`), expires in 10 min, attempts 0 |
| Wrong code | Refused, `CODE_FAILED` audited |
| Correct code | → `/backoffice/login`, code row consumed |
| Login with no password set | Refused, `LOGIN_FAILED` (`reason: no_password`) |
| Reset password | → `/backoffice`, **not** signed in (§6) |
| Verify → login → dashboard | 200, `backoffice` guard signed in, `web` guard NOT |
| Customer signed in, fresh session, `/backoffice/{dashboard,login,admins}` | all 302 → `/backoffice` |
| Last active Super Admin: disable / downgrade | Both refused by the model |
| Same, with a second Super Admin present | Allowed |

The audit log carried all thirteen event types with IP and user agent.

## State left behind on this machine

- One `backoffice_users` row: `rohitcphilip@gmail.com`, Super Admin, active, **no password**.
  A password was set during testing and has been **cleared deliberately** — it appeared in a
  transcript, so it is not a credential anybody should keep. Use *Forgot Password* at
  `/backoffice` to set a real one.
- 18 audit rows from the flows above, including deliberate failures against
  `nobody@example.com`. Left in place: an audit log that gets tidied up is not one.
