# Phase 1 — Sign Up / Login: install guide

This folder contains **drop-in files** for the ProjectBlock Laravel app. It intentionally lives *outside* the framework so `setup.sh` (which `mv`s a fresh `laravel/laravel` into the project) can't clobber `routes/`, `config/`, etc.

Order matters: **scaffold Laravel first, then copy these in.**

---

## 0. Prerequisites

PHP 8.3+, Composer, Node/npm, and a running MariaDB/MySQL (MAMP). MAMP MySQL is usually on port **8889**, user/pass `root`/`root`.

## 1. Scaffold Laravel 13 (once)

From `~/Sites/pblock3.0`:

```bash
bash setup.sh
```

That creates the Laravel app, sets `DB_*` for MySQL, and installs Boost/tenancy/Reverb. Create the `pblock3` database in MAMP first, then it runs `php artisan migrate`.

> Not using FlyonUI — you can ignore the FlyonUI line in `setup.sh` (or delete `npm install flyonui`). These auth views use plain Tailwind (CDN) + the POC tokens, no build step required.

## 2. Install the extra packages this feature needs

```bash
composer require laravel/socialite
composer require league/flysystem-aws-s3-v3 "^3.0"   # DigitalOcean Spaces (prod)
composer require symfony/postmark-mailer             # Postmark transport (prod email)
```

## 3. Copy the drop-in files into the app

From the project root, copy everything under `_phase1-auth/` into place (paths already mirror the Laravel layout):

```bash
cp -R _phase1-auth/app/*                    app/
cp -R _phase1-auth/database/migrations/*    database/migrations/
cp    _phase1-auth/routes/auth.php          routes/auth.php
cp -R _phase1-auth/resources/views/*        resources/views/
cp -R _phase1-auth/public/assets            public/assets
cp    _phase1-auth/config-snippets/onboarding.php  config/onboarding.php
```

Also copy the feature spec:

```bash
mkdir -p docs/features && cp docs/features/phase-1-auth.md docs/features/   # (already in your repo's docs/features)
```

## 4. Wire the routes

In **`routes/web.php`**, add at the bottom:

```php
require __DIR__.'/auth.php';
```

(The default `Route::get('/', ...)` welcome route is replaced by the sign-up route in `auth.php`; remove the scaffold's `/` route to avoid a name/URL clash.)

## 5. Register the email-capture provider

In **`bootstrap/providers.php`**, add:

```php
App\Providers\EmailLogServiceProvider::class,
```

## 6. Apply the config snippets

Hand-merge these into the existing config files (they're partial — don't overwrite the whole file):

- **`config/filesystems.php`** — add the `avatar_disk` key and the `assets` + `spaces` disks. See `config-snippets/filesystems.php.snippet`.
- **`config/services.php`** — add `postmark`, `google`, `github`, `sso`. See `config-snippets/services.php.snippet`.
- **`config/mail.php`** — add the `capture_to_db` key. See `config-snippets/mail.php.snippet`.

## 7. Environment

Append `_phase1-auth/.env.additions` to your `.env` (and mirror keys into `.env.example`). Defaults are set for **local**: `MAIL_MAILER=log`, `MAIL_CAPTURE_TO_DB=true`, `AVATAR_DISK=assets`. Set `APP_URL` to your MAMP URL.

## 8. Migrate & run

```bash
php artisan migrate
php artisan serve      # or use your MAMP vhost
# In production only, email is queued: run  php artisan queue:work
```

Local avatar disk writes to `public/assets/avatars/` — that folder is served directly, no `storage:link` needed.

---

## What you get / URLs

| URL | Screen |
|---|---|
| `/` or `/signup` | Sign Up (email-first + Google/GitHub/SSO) |
| `/verify` | Enter the 6-digit code |
| `/signin` | Sign In (password **or** emailed code) |
| `/onboarding/profile` | Profile: name (required), avatar, optional password, marketing consent |
| `/onboarding/role` | Role (single-select, skippable) |
| `/onboarding/goals` | Goals (multi-select, skippable) |
| `/emaillog` | **Local only** — view every outbound email + its code |
| `POST /logout` | Log out |

## How local vs production differ (no code changes)

| Concern | Local | Production |
|---|---|---|
| Email transport | `log` mailer, captured to `email_logs`, viewable at `/emaillog` | Postmark (`MAIL_MAILER=postmark`, `POSTMARK_TOKEN`) |
| Avatar storage | `assets` disk → `public/assets/avatars` | `spaces` disk → DigitalOcean Spaces (`AVATAR_DISK=spaces`) |

## Notes & follow-ups

- **6-digit codes** (not magic links) are used for email verification and passwordless login — see `docs/features/phase-1-auth.md` (D-A3). Codes are hashed at rest, expire in 10 min, single-use, max 5 attempts.
- **Auth is central (non-tenant)** — Phase 1 is pre-workspace (D-A1). Tenant scoping starts at Phase 3 workspace creation.
- **Passwords** live in `user_password_credentials` (D-A2); `users.password` is left nullable for framework compatibility.
- **Deferred to next round:** Forgot/Reset password (LOGIN-004), and turning the auth pages into Vue components (kept as Blade + progressive JS for Phase 1 to avoid overengineering).
- **OAuth setup:** create Google + GitHub OAuth apps and set the client id/secret + redirect URIs in `.env`. SSO is a progressive stub — map a domain → IdP URL in `config/services.php` (`sso.domains`).
- `/emaillog` is a Laravel route served by `public/index.php` (not a physical `emaillog.php`), so it reads captured mail via Eloquent. URL is `http://<your-host>/emaillog`.
