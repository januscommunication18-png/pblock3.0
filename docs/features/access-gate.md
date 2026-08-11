# Access gate — the "secure page" in front of Sign up / Sign in

A code screen that stands in front of the whole site so a Dev or UAT deployment is not open to
the public. Enter one of the codes, press Continue, and the normal Sign up / Sign in screens
appear.

**It is not authentication.** A shared four-digit code keeps out a passer-by and a search
engine crawler; it is not a substitute for a login and nothing sensitive should rely on it.

## How it behaves

| | |
|---|---|
| Screen | `GET /access` — logo, environment badge, code field, Continue |
| Submit | `POST /access`, throttled to 10 attempts a minute |
| Codes | `1000`, `2000`, `3000`, `4000`, `5000` by default |
| After a valid code | Lands on whatever page was originally asked for, or Sign up |
| Where it applies | Every web route, except the gate itself and `/up` |
| Where it runs | `local`, `development`, `dev`, `uat`, `staging` — **never production** |

## The module switch

`ACCESS_GATE_ENABLED` in the env file, read through `App\Services\AccessGate::enabled()`:

- **unset** — on in the environments listed in `ACCESS_GATE_ENVIRONMENTS`, off elsewhere,
- `true` — force on (still only in those environments),
- `false` — force off.

This is the seam the backoffice toggle plugs into. When that screen is built it replaces the
body of `enabled()` with a stored setting and nothing else in the app moves.

**Unset means on, not off.** A fresh Dev or UAT deploy is private without anyone remembering
to set anything — the failure that matters here is a site accidentally left open, not one
accidentally left closed.

## Why the environment list is an allow-list

`allowedHere()` is checked separately from `enabled()`, and production is not on the list. A
stray `ACCESS_GATE_ENABLED=true` copied into a production env file therefore cannot put the
live site behind a code page. The gate's job is to keep *non-production* copies private, so it
is built to be incapable of touching production rather than merely configured not to.

## Why it is global middleware

`RequireAccessCode` is appended to the whole `web` group, not attached to the auth routes. The
requirement is that the *site* is unreachable; hanging the check on a handful of routes means
the next route someone adds is quietly reachable. Opting a path out is then a deliberate line
in `access_gate.except`, which currently holds only the gate's own screen (or the redirect
loops) and `/up` (a deploy probe is not a member of the public — a gate that fails the health
check takes the environment down in order to protect it).

## Decisions worth knowing

**Rotating a code revokes the passes it granted.** The session stores a fingerprint of the
code list rather than a bare `true`, so removing a leaked code locks out whoever was using it.
Storing `true` would have made withdrawing a code change nothing for the one person you
withdrew it from.

**The session id is regenerated on success**, for the same reason logging in does it: a
session fixed beforehand cannot be used to ride in.

**Wrong and empty codes get the same message.** The response should not help someone work out
how close they are.

**JSON callers get a 403 with a message**, not a redirect to an HTML page they cannot use.

**A GET is remembered, a POST is not.** A shared deep link still lands on the right page after
the code; replaying a POST would submit a form the user no longer has in front of them.

### One bug worth recording

`ACCESS_GATE_ENABLED=` (a bare key, no value) reaches config as an empty **string**, not
`null`. The first implementation tested `=== null` to mean "unset", so an empty value was read
as "explicitly off" — and the local site was open while the config file said it was private.
That is precisely the failure this feature exists to prevent, so `enabled()` now treats any
blank value as unset, and `test_a_blank_switch_counts_as_unset_rather_than_off` pins it.

## Tests

`tests/Feature/Auth/AccessGateTest.php` — 12 tests. The ones that matter most are about what
is *not* reachable: the site closed until a code is entered, wrong codes leaving it closed,
rotation revoking passes, and production never being gated.
