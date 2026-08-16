# Session Timeout & Expiry Handling

## Requirement

Sign a person out after a period of **inactivity**, warn them before it happens, and — when it
does happen — say so in language a person can act on, then put them back where they were.

Two situations, one mechanism:

1. **The tab is open and idle.** A warning appears 5 minutes before expiry offering *Stay Signed
   In* / *Sign Out*. If it lapses, an "expired" dialog replaces it.
2. **The tab was left for an hour and the person clicks something.** The request comes back 401.
   They must never see `401 Unauthorized` — they see "Your session has expired. Sign in again to
   continue," and land back on the same work item afterwards.

## User Roles

- **Everyone signed in** — subject to the timeout; sees the warning and the expiry dialog.
- **Workspace owner / admin** — configures the timeout in Settings → Security.

## Database Fields

`workspace_settings.session_timeout_minutes` — `unsignedSmallInteger`, nullable.
`null` means "use the application default" (`config('settings.security.timeout_default')`, 30),
so an existing workspace needs no backfill and the default can be changed centrally later.

## Business Rules

- **SES-001** Default idle timeout is **30 minutes**.
- **SES-002** The timeout is configurable per workspace, from `config('settings.security.timeout_options')`.
  Values outside that list are rejected server-side, not just hidden in the UI.
- **SES-003** Any authenticated request resets the inactivity timer. The stamp is written
  **before** the response is rendered, not after: the guard markup reports the remaining time
  during rendering, so a stamp written afterwards tells the browser the time left under the
  *previous* stamp while the server has already reset the clock. That gap made tabs declare
  themselves expired against sessions that were alive — see the note below. Client-side activity
  (typing, clicking, scrolling) resets it through a throttled ping — at most one per 60s, since
  the point is to detect *presence*, not to count keystrokes.
- **SES-004** `GET /session/status` is the one authenticated route that does **not** reset the
  timer. If polling for the remaining time also refreshed it, an open tab would never expire —
  which is the whole feature, inverted.
- **SES-005** The warning appears **5 minutes** before expiry, reduced to half the timeout when
  that would otherwise be most of the session (a 5-minute warning on a 6-minute timeout is not
  a warning).
- **SES-006** On expiry the session is invalidated and the CSRF token regenerated — this is a
  sign-out, not a soft lock.
- **SES-007** An expired **HTML** request redirects to sign-in with the explanatory message.
  An expired **JSON/fetch** request returns `401` with `code: "session_expired"`, never a bare
  status line.
- **SES-008** After signing in again the person returns to the URL they were on, not the
  dashboard. Only same-origin relative paths are honoured as a return target.
- **SES-009** Unsaved text (inputs, textareas, rich-text editors) is snapshotted to
  `localStorage` at the moment of expiry and offered back on return. Best-effort by design:
  it must never block signing in again.
- **SES-010** "Sign In Again" goes through **`GET /session/expired`**, which is in neither the
  `guest` nor the `auth` group. A browser can be certain the session is over while the server
  still holds it — a slept laptop, a dropped ping, a skewed clock — and `signin` is a guest
  route, so in exactly that case a still-authenticated visitor is bounced off it. The endpoint
  ends the session first, so the button is correct from either side of the timeout.
- **SES-011** One guard per tab. The markup is not injected into embedded documents
  (`Sec-Fetch-Dest: iframe`): the Views panel opens work items in an iframe, and a guard there
  would run a second countdown whose "Sign In Again" navigates only the panel.

### Why an application-level idle check rather than `session.lifetime`

Laravel's `session.lifetime` is read by `StartSession` before this application knows who is
signed in or which workspace they are in — so a per-workspace, per-user value cannot be applied
there without a circular dependency. Tracking `last_activity_at` inside the session instead
gives exact control over the idle window, the warning point, and the wording on expiry.
`session.lifetime` stays configured as a generous absolute backstop behind it.

### Cross-workspace ambiguity (decision)

A person can belong to several workspaces with different timeouts, but holds **one** session.
Decision: **the active workspace governs.** Switching workspaces re-reads the value on the next
request. The alternative — the strictest of all their workspaces — punishes people for
membership they may not be using.

## Acceptance Criteria

- A session idle past the configured window is signed out on the next request.
- Activity inside the window keeps it alive indefinitely.
- `GET /session/status` reports the remaining seconds and does not extend the session.
- `POST /session/extend` resets the timer and reports the new deadline.
- The warning dialog appears 5 minutes out; *Stay Signed In* dismisses it and the session
  survives; *Sign Out* signs out immediately.
- An expired fetch shows the expiry dialog rather than an error toast, for any screen in the app.
- Signing in again from the expiry dialog returns to the originating page.
- A non-owner cannot change the timeout; an out-of-range value is rejected with 422.

## UI Requirements

- **Settings → Security** — a new Administration section with the timeout control.
- **Warning modal** — "Your session is about to expire" / "Your session will expire in 5 minutes
  due to inactivity." Buttons: *Stay Signed In* (primary), *Sign Out*. Counts down live.
- **Expired modal** — "Your session has expired" / "For your security, you were signed out
  because your session was inactive. Sign in again to continue." Button: *Sign In Again*.
  Not dismissable: behind it is a page whose contents can no longer be trusted or saved.
- **Recovered-content banner** — shown on return if a snapshot exists for the page, with
  *Restore* and *Discard*.

## Real-Time Requirements

None. Deliberately: the countdown is arithmetic on a deadline the server already stated, and a
websocket that drops would take the warning with it.

## Queue Requirements

None.

## Audit Requirements

None in this phase. Expiry is a client-visible event, not a security incident worth a row.

---

## Implementation Map

| Concern | Where |
|---|---|
| Idle window, options, warning lead | `config/settings.php` → `security` |
| Remaining/expiry arithmetic | `App\Services\SessionTimeout` |
| Enforcement + intended-URL capture | `App\Http\Middleware\EnforceIdleTimeout` |
| Guard markup on every page | `App\Http\Middleware\InjectSessionGuard` |
| `status` / `extend` / `expired` | `App\Http\Controllers\Auth\SessionController` |
| Tearing a session down, shared | `App\Support\EndsSession` |
| Where a signed-in visitor goes from a guest page | `AppServiceProvider` → `RedirectIfAuthenticated::redirectUsing` |
| Return-to-page on sign-in | `SignInController`, `VerifyCodeController`, `SessionReturnTarget` |
| Countdown, dialogs, 401 capture, drafts | `public/assets/js/session-guard.js` |
| Settings screen | `Settings\SecuritySettingsController` + `settings/security.blade.php` |

### Why the guard markup is injected by middleware

The application has ~16 separate full-page Blade templates rather than one shell (`settings/layout`,
`projects/settings/layout`, and each screen that includes `app-topbar`). Adding the partial to
each is a rule someone has to remember on the seventeenth. `InjectSessionGuard` appends it before
`</body>` on authenticated HTML responses, so a page added later is covered by existing code.


---

## Post-release defects (fixed)

Four, all of the same shape — the browser and the server disagreeing about the session.

1. **Activity was stamped after the response was built.** `PB_SESSION.remaining` therefore came
   from the previous stamp while the server had already reset the clock, so the browser's
   deadline was always earlier than the server's — by however long the user had been idle before
   that page load. Idle 29 minutes, click a link, and the new page announced 60 seconds against a
   session with a full 30. The tab then showed the expiry dialog over a live session.
2. **"Sign In Again" led into a redirect loop.** It pointed at `/signin`, a `guest` route, which
   bounced the still-authenticated visitor to Laravel's default target — and with no `dashboard`
   or `home` route here that falls through to `/`, which is `signup`, also a `guest` route. Fixed
   by SES-010, and by naming a real destination via `RedirectIfAuthenticated::redirectUsing`
   (worth doing regardless: *every* guest URL opened while signed in was looping).
3. **The guard was injected into iframes** (SES-011).
4. **`SessionReturnTarget` shared the `url.intended` session key with `RequireAccessCode`.** Inert
   where the access gate is off, but on Dev/UAT the gate overwrote the page somebody was reading
   with the sign-in URL, which `sanitize()` then rejected as an auth route — silently dropping
   the return target. The key is now `session.return_to`.

The regression test for (1) is the one that matters: the original payload test only ever ran
against a fresh session, which is the single case where the stale and fresh stamps agree.
