# Feature: Phase 1 — Sign Up / Login & Pre-Workspace Onboarding

> Spec follows `docs/feature-template.md` and CLAUDE.md §4 (define → fields → acceptance → generate → review → migrate → commit).
> Source of truth: uploaded ProjectBlock HTML POC (`html/signup.html`, `onboarding-profile.html`, `onboarding-role.html`, `onboarding-goals.html`) + the Phase 1 requirements document.

## Requirement

Authentication and pre-workspace onboarding for ProjectBlock 3.0. A user can start with **Email** (email-first, no password on the first screen) or a federated identity (**Google**, **GitHub**, **SSO**). After account entry, first-time users complete **Profile → Role → Goals** before workspace creation (Phase 3). Login supports the same identity methods and routes returning users to the correct destination. Authentication and onboarding state is **resumable**.

## User Roles

Auth/onboarding is **pre-tenant** — it runs before a workspace (tenant) exists, so these are **central** (non-tenant-scoped) models, not tenant-scoped. See Decision D-A1.

- Guest / visitor: can sign up and sign in.
- Authenticated user (pre-workspace): can complete Profile, Role, Goals; can log out.
- Developer (local only): can view the `/emaillog` outbound-mail viewer.

## Database Fields

Central tables (no `tenant_id`):

- **users**: `id`, `email` (unique, normalized lowercase), `email_verified_at`, `full_name`, `avatar_url`, `status` (`pending`/`active`/`suspended`, default `active`), `locale`, `timezone`, `marketing_opt_in` (bool, default false), `password` (nullable — see D-A2), `remember_token`, timestamps.
- **user_identities**: `id`, `user_id` → users, `provider` (`email`/`google`/`github`/`sso`), `provider_subject` (nullable, safe provider id), `created_at`, `updated_at`. Unique on (`provider`, `provider_subject`).
- **user_password_credentials**: `id`, `user_id` → users (unique), `password_hash`, `password_set_at`. Row exists only when the user set a password.
- **onboarding_profiles**: `id`, `user_id` → users (unique), `role_selection` (nullable string), `goals` (json, nullable), `current_step` (`profile`/`role`/`goals`/`completed`, default `profile`), `completed_at` (nullable), timestamps.
- **email_verification_codes**: `id`, `email`, `code_hash`, `purpose` (`signup`/`login`), `expires_at`, `consumed_at` (nullable), `attempts` (default 0), timestamps. Short-lived, single-use, never stores the code in plaintext.
- **email_logs** (local/non-prod capture for `/emaillog`): `id`, `to`, `from`, `subject`, `html_body` (longtext), `text_body` (longtext, nullable), `mailer`, `created_at`.

Marketing consent (`marketing_opt_in`) and Terms/Privacy acknowledgement (`terms_accepted_at` — stored on users, added by the users migration) are **independent** (ONB-005 / AUTH-008).

## Business Rules

- **AUTH-005/006/007**: Email-first. `Continue` enabled only on plausible email syntax (client) + authoritative server validation. Email is normalized (trim + lowercase) before identity lookup so matching is case-insensitive. Duplicate verified email must not create a second account (AUTH-002).
- **AUTH-009**: No password on the first Sign Up screen.
- **Verification (D-A3)**: Email account entry issues a **6-digit numeric code** (`email_verification_codes`). Code is hashed at rest, expires in 10 minutes, single-use, max 5 attempts, then invalidated. Verifying a code creates-or-resumes the user + an `email` `user_identities` row and sets `email_verified_at`.
- **ONB-001**: Name required (trimmed, non-empty) before continuing Profile.
- **ONB-002**: Avatar optional; validated image type (`jpg,jpeg,png,webp`) ≤ 2 MB; upload shows progress + failure state; stored on the configured disk (local `assets`, prod DO Spaces).
- **ONB-003/004**: Password optional. If the password section is used, confirmation must match and policy must pass; a row is written to `user_password_credentials`. Accounts stay passwordless otherwise.
- **Role (ONB / §7)**: single-select or Skip; personalization metadata only — never a permission role.
- **Goals (§8)**: multi-select or Skip; personalization metadata only.
- **ONB-006 / resume**: `onboarding_profiles.current_step` advances on each completed step; refresh/login resumes at the next incomplete step.
- **LOGIN-002/003**: Password login for accounts with a credential (verified via `Hash::check` against `user_password_credentials`); passwordless email login uses the same 6-digit code flow.
- **LOGIN-005 routing**: incomplete onboarding → resume; zero workspace → Phase 3 workspace onboarding; one/many workspaces → active workspace / selector. (Workspace routing is a stub returning to Phase 3 until Phase 3 lands.)
- **LOGIN-006**: Logout invalidates the current session (regenerate + invalidate).
- **LOGIN-007**: Auth + verification endpoints are rate-limited (throttle middleware).
- **LOGIN-004 (forgot/reset)**: deferred to a later round per product scope for this build.

## Acceptance Criteria

1. A user can start with email, Google, GitHub, or SSO and create/resume a single ProjectBlock identity.
2. Email sign-up does not force a password on the first screen.
3. Profile Name is required; avatar and password are optional.
4. Role can be selected or skipped; Goals can be multi-selected or skipped.
5. Onboarding resumes correctly after refresh or later login.
6. Returning users reach the correct workspace/onboarding destination.
7. Auth endpoints are server-validated, rate-limited, and never expose whether an account exists (recovery/verify responses are uniform).
8. Verification/reset tokens are short-lived, single-use, and never logged in plaintext.
9. Locally, every outbound email (including the 6-digit code) is viewable at `/emaillog`.
10. Avatar uploads land in the local `assets` disk in development and in DO Spaces in production, driven by config only.

## UI Requirements

Blade views ported from the HTML POC using the POC's Tailwind tokens (`head/ink/sub/faint/line/stroke/hover/brand/brand-dark/link/danger`, Inter font) and `styles.css` (`.pb-input`, etc.). **FlyonUI is not used.** Real Laravel forms (`@csrf`, POST, validation errors); the POC's small vanilla JS (email gating, avatar progress, password toggle, role/goals selection) is ported as progressive enhancement.

Screens this round: **Sign Up** (`auth/signup`), **Verify code** (`auth/verify`), **Sign In** (`auth/signin`, new — matching style), **Onboarding Profile/Role/Goals**. Plus dev-only **Email log** (`/emaillog`).

## Real-Time Requirements

None for Phase 1 auth. (The real-time notification stack in CLAUDE.md §8–13 is a separate feature.)

## Queue Requirements

Email sending (`LoginCodeMail`) should be queued in production (`ShouldQueue`) so it does not block the request; runs sync-friendly locally. Requires `php artisan queue:work` in production.

## Audit Requirements

Record security events (LOGIN-008) without sensitive tokens: login success/failure, provider used, logout, password set/change, code issued/consumed. This build logs these via Laravel's logger channel `auth` (structured), leaving a full audit table to a later round.

---

## Planning & Reasoning (Decisions)

| # | Topic | Decision | Rationale |
|---|---|---|---|
| D-A1 | Tenancy scope of auth | Users/identities/onboarding are **central**, not tenant-scoped. | Phase 1 is explicitly *pre-workspace*; a tenant does not exist yet. Tenant scoping begins at workspace creation (Phase 3). |
| D-A2 | Password storage | Password lives in **`user_password_credentials`** (per requirements §10); `users.password` kept nullable for framework compatibility but login verifies against the credentials table. | Honors the requirement that a password row exists only when set, while staying compatible with Laravel auth. |
| D-A3 | Verification transport | **6-digit numeric code** for both signup verification and passwordless login. | Requirements leave transport open; a code is simple, secure, and trivially viewable in `/emaillog`. Magic-link can be added later. |
| D-A4 | FlyonUI | **Not used.** Blade + the POC's plain Tailwind setup. | Per product direction in this build. |
| D-A5 | Email viewer | `/emaillog` is a **Laravel route** (front-controller `public/index.php`), local-env only, backed by an `email_logs` capture listener — not a physical `emaillog.php`. | Lets the viewer read captured mail via Eloquent and stays framework-native; URL is `http://<host>/emaillog`. |
| D-A6 | Local vs prod storage/mail | Disk + mailer are **config/env-driven**: local `assets` disk + `log`+capture mailer; prod DO **Spaces** (S3) + **Postmark**. | No code changes between environments. |

## Files Changed

Delivered under `_phase1-auth/` (copy into the scaffolded Laravel app — see `INSTALL.md`):
Models: `User` (extend), `UserIdentity`, `UserPasswordCredential`, `OnboardingProfile`, `EmailVerificationCode`, `EmailLog`.
Migrations: extend users; create user_identities, user_password_credentials, onboarding_profiles, email_verification_codes, email_logs.
Controllers: `Auth\EmailSignupController`, `Auth\VerifyCodeController`, `Auth\SignInController`, `Auth\SocialAuthController`, `Auth\SsoController`, `Auth\LogoutController`, `Onboarding\ProfileController`, `Onboarding\RoleController`, `Onboarding\GoalsController`, `Onboarding\AvatarController`, `Dev\EmailLogController`.
Requests: `EmailSignupRequest`, `VerifyCodeRequest`, `SignInRequest`, `ProfileRequest`, `RoleRequest`, `GoalsRequest`, `AvatarRequest`.
Services: `AuthCodeService`, `OnboardingRouter`.
Mail: `LoginCodeMail` + `emails/login-code.blade.php`.
Capture: `Listeners\CaptureOutgoingEmail`, `Providers\EmailLogServiceProvider`.
Routes: `routes/auth.php`.
Views: `layouts/auth`, `auth/signup`, `auth/verify`, `auth/signin`, `onboarding/{profile,role,goals}`, `dev/emaillog/{index,show}`.
Config: `config-snippets/filesystems.php`, `services.php`, `mail-and-env.md`, `public/assets/*`.
