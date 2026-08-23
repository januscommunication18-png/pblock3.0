# Workspace Subdomain (P72)

## Requirement

> During workspace onboarding, introduce subdomain creation when the customer enables Help Desk
> or Client Hub. Each tenant must have a globally unique subdomain across the entire
> ProjectBlock platform.

Taken as specified, including the recommendation to use **one tenant subdomain with the products
separated by route** — `acme.projectblock.app/help`, `acme.projectblock.app/client` — rather than
a host per product. A tenant gets one customer-facing domain to publish, and Help Center and
Client Hub cannot compete over who owns `acme`.

## Why this is not the existing `slug`

`tenants.slug` is already globally unique, already restricted to the same characters, already has
a reserved list and a live availability endpoint. It was tempting to reuse.

It is a **different identifier** and reusing it would have been wrong:

- The slug is this workspace's path on the shared application host —
  `app.projectblock.so/acme-inc` — and **every** workspace has one. The subdomain is a
  customer-facing host, and only a workspace running a customer-facing product needs one.
- The form asks for the subdomain **conditionally** and for the slug **always**. Merging them
  would put two inputs on one column and force every tenant to accept their internal path as
  their public brand.

So `tenants.subdomain` is a second column, nullable, uniquely indexed.

## The rules live in one class

`App\Services\TenantSubdomain`. The question "what may a subdomain be?" is asked in four places —
the live check as somebody types, the validator on submit, the two creation forms, and anything
that later builds a URL. Four copies of a hostname rule is how a name the checker called free
gets refused by the validator.

| Rule | Value |
|---|---|
| Characters | `a-z`, `0-9`, `-`; no leading, trailing or doubled hyphen |
| Length | 3 – **63** — the DNS label limit (RFC 1035), not a preference |
| Case | lowercased automatically |
| Reserved | `www`, `admin`, `api`, `app`, `mail`, `support`, `help`, `billing`, `status`, `backoffice`, and the rest of `config('workspace.subdomain.reserved')` |
| Required when | the chosen apps include `helpdesk` or `clienthub` |

The reserved list is **not** `reserved_slugs`. It protects something different: names that
resolve, or will resolve, somewhere of our own. `autodiscover`/`autoconfig` are what mail clients
probe for; `_domainkey`/`dmarc` are where mail authentication lives, and a tenant owning one
could break signing for the whole zone.

`clienthub` is listed as requiring a subdomain although it is not released yet — the rule is
about what the app *is*, and a list that has to be remembered on release day is a list that will
not be.

## Two bugs found in my own work while testing

**Non-ASCII was silently dropped.** `normalize()` stripped anything outside `a-z0-9-`, so
`ünïcode` became `ncode` — a hostname the customer never chose, on the field that becomes their
public address. It now transliterates (`Str::ascii`) first: `Ökonomie GmbH` → `okonomie-gmbh`.

**The live check and the validator disagreed.** `refusal()` rejects the `xn-` punycode prefix —
a tenant holding one could present a hostname that renders in a browser as somebody else's — and
`rules()` did not. So `xn--evil` was called reserved as you typed and accepted on submit. That is
the exact failure this class exists to prevent, committed on its first outing; `rules()` now
carries a matching `not_regex`.

(The `PATTERN` was *supposed* to catch it by forbidding a double hyphen, and cannot:
`normalize()` runs first and collapses `xn--80ak…` to `xn-80ak…`, which passes. Two rules each
assuming the other did the work.)

## Uniqueness is the index, not the check

The requirement asks for real-time validation **and** validation on submit, and both are
implemented — but neither can promise uniqueness. Two people typing `acme` at the same moment are
both told it is free and both submit.

The unique index on `tenants.subdomain` is the only thing that actually guarantees one tenant per
host. The validator exists to turn the loser's failure into a readable message rather than a 500.
Verified by inserting a duplicate directly: `UniqueConstraintViolationException`.

`NULL` is the "no subdomain" value, never `''`. Both MySQL and Postgres allow repeated NULLs
under a unique index, so workspaces without a customer-facing product do not collide with each
other — an empty string would have made the *second* such workspace fail. Verified.

`Workspace` uses stancl's `VirtualColumn`, so `subdomain` had to be declared in
`getCustomColumns()`. Left off, it would be folded into the `data` JSON blob and the unique index
would be guarding a column nothing ever writes.

## The field

`resources/views/partials/workspace-subdomain.blade.php`, included by **both** creation screens —
onboarding and "create another workspace" — for the reason the apps partial next to it gives:
this markup would otherwise live twice and drift, and a subdomain rule that holds on one screen
is worse than one that holds on neither, because nobody would notice.

Hidden until a customer-facing app is switched on; the value is **kept** when it hides, so
switching Help Center off to read its description and back on does not cost the user their typing.

`public/assets/js/workspace-subdomain.js` does the reveal and the live check. Two details that
are not obvious:

- **Every response carries a sequence number.** Without it, a slow answer for `acm` can arrive
  after a fast one for `acme` and repaint the field with a verdict about a name the user has
  already finished typing.
- **A network failure leaves the state UNKNOWN**, not available. The form still submits and the
  server still checks; claiming a name is free because we could not ask is the one wrong answer.

The field reports its state by **dispatching an event** rather than reaching for a submit button:
the two screens gate their buttons differently, and a script that knew both would be a third
implementation waiting to disagree with them.

The availability route is throttled at 60/min, unlike the slug's. It answers a question about
*other* tenants — "does `acme` exist?" — so an unthrottled version is a cheap way to enumerate
who is on the platform.

## Verified

Live states, on screen: `acme` → **✓ acme is available · https://acme.projectblock.app/help**;
a taken name → **✕ takenname is already taken**; `support` → reserved; `ab` → too short, with the
submit button disabled. `Acme Corp!!` shows **✓ acme-corp is available**, so the user sees what
they will actually get rather than having it changed under them after submit.

Server side: a taken name, a reserved name, a short name, an over-63 name and `xn--evil` are all
refused; `Acme Corp!!` validates and stores as `acme-corp`; Help Center on with a blank field is
refused; Projects-only with a blank field is accepted and stores `NULL`.

## Host routing (built after the field)

`acme.projectblock.app/help` now resolves to the workspace whose `subdomain` is `acme`, with
single-database tenancy initialised so every `BelongsToTenant` query below it is confined to that
workspace.

### Ours rather than stancl's

`InitializeTenancyBySubdomain` ships with the package and is already prioritised in
`TenancyServiceProvider`. It resolves through a `domains` table this project does not have — our
identifier is a column on `tenants` — so adopting it would mean creating and synchronising a
second table that says the same thing as a column we already maintain.
`App\Http\Middleware\IdentifyTenantByHost` reads the Host and matches the column directly.

### Route ordering is load-bearing

`routes/tenant.php` is required **FIRST** in `web.php`. A route with no domain constraint matches
*any* host, so every central route already matches `acme.projectblock.app` too, and Laravel takes
the first route whose method, path and host all match. Registered after `auth.php`, a tenant
`/help` would lose to whichever central route claimed the path first — silently.

Only `/help` and `/client` are claimed. Everything else on a tenant host still falls through to
the central application, which is the conservative choice: a customer following an old link gets
the ordinary page rather than a 404 from a route group that grabbed everything.

### Unknown subdomain and missing product are the SAME 404

Never "this workspace exists but has not enabled Client Hub". That sentence confirms a tenant to
somebody who guessed their name, and guessing is free — the point of a public hostname is that
anybody can try one. The two cases are indistinguishable from outside, deliberately.

### Tenancy is ended on the way out

`tenancy()->initialize()` sets a process-global current tenant, cleared in a `finally`. Under a
long-running worker (Octane, or a queue worker serving requests) a request that ended without
clearing it would leave the next one scoped to somebody else's workspace. `php artisan serve`
forks per request and would never show this — which is exactly why it is written down rather
than left to luck.

### Config split: host for matching, port for display

`TENANT_ROOT_DOMAIN` is **host only**. `Route::domain()` matches `$request->getHost()`, which
excludes the port, so a root of `localhost:8000` compiles to a pattern that can never match.
`TENANT_ROOT_PORT` is appended when *building* a URL and never when matching one — needed only
locally, where the dev server runs on a port.

### Testing it locally

No `/etc/hosts` and no dnsmasq: macOS and Chrome resolve `*.localhost` to loopback (RFC 6761).

```
TENANT_ROOT_DOMAIN=localhost
TENANT_ROOT_SCHEME=http
TENANT_ROOT_PORT=8000
```

then `php artisan config:clear && php artisan route:clear`, give a workspace a subdomain, and
visit `http://acme.localhost:8000/help`.

### Verified

| Request | Result |
|---|---|
| `acme.localhost:8000/help` (Help Center on) | **200**, renders the right workspace |
| `acme.localhost:8000/client` (Client Hub off) | **404** |
| `nobody.localhost:8000/help` (no such tenant) | **404** |
| `127.0.0.1:8000/help` (central host) | **404** |

Tenancy proven live inside the request, not just assumed: a probe reported
`tenant_initialized: true`, the right tenant id, and **3 scoped vs 6 unscoped** `HelpCenterSpace`
rows — the row scope is genuinely applied. After the request, `tenancy()->tenant` is `NULL`.

Central routes regression-checked: `/`, `/help-center/inbox`, `/onboarding/workspace` and `/up`
all unchanged. (`/login` 404s on both hosts — this application's login is `/signin`, so that is
pre-existing.)

## Not done

- **The portal pages are placeholders.** `resources/views/tenant/portal.blade.php` proves the
  host resolved to the right tenant and the product is on. It is not a customer portal — nothing
  lists tickets, accepts a request or authenticates a customer, because none of that was
  specified and inventing it would be building a product out of a routing requirement. The real
  one replaces the view; the routing underneath does not change.
- **No DNS or TLS for production.** A wildcard `*.projectblock.app` record and a wildcard
  certificate are infrastructure, not code.
- **Existing workspaces have no subdomain** and are not backfilled. Deriving one from `slug`
  would hand hundreds of tenants a public hostname they never chose, some reserved and some too
  short to be legal. They will need a way to claim one from Settings.
- **No way to change one after creation.** Also a Settings screen, and it needs thought about
  what happens to links already published under the old host.
