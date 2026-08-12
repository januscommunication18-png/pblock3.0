# Icon set — legacy SVG ⇄ Font Awesome Pro

Every icon renders through `pb_icon()`, which draws from one of two sets. Switching the whole
UI over, or back, is one config value — there is no second copy of the markup to keep in step.

## Switching

```dotenv
ICONS_SET=fontawesome   # Font Awesome Pro 7, Classic Regular
ICONS_SET=legacy        # the hand-drawn inline SVGs (default)
```

`php artisan config:clear` after changing it. Nothing else moves: no markup edit, no rebuild,
no revert commit.

## How it fits together

| Piece | Role |
|---|---|
| `config/icons.php` | The switch, the Font Awesome style, and which stylesheets that set needs |
| `app/Support/IconRegistry.php` | Every icon, named once and defined twice — legacy SVG **and** Font Awesome name |
| `pb_icon($name, $size, $class)` | Renders from whichever set is on |
| `pb_icon_styles()` | The stylesheet links — **empty** for legacy, so that build ships no webfont |

```blade
{!! pb_icon('gear') !!}                    {{-- 16px, inherits colour --}}
{!! pb_icon('gear', 18) !!}
{!! pb_icon('bars', 15, 'text-faint') !!}
```

Icons are named for the picture (`gear`, `magnifying-glass`, `bars`) following Font Awesome's
own vocabulary, not for where they happen to be used. A name that describes the drawing
survives being reused; `sidebar-settings-icon` does not.

An unknown name **throws** when `APP_DEBUG` is on and renders nothing in production. A typo
should not ship as an invisible button.

## Why Classic Regular, and only Classic Regular

Vendored from the Pro package into `public/assets/vendor/fontawesome`:

```
css/fontawesome.min.css   152K   the engine
css/regular.min.css         4K   the Classic Regular style
webfonts/fa-regular-400.woff2  352K
```

Not `all.min.css` — that pulls in every family in the pack and each one's webfont. The Pro
source tree is 1.1 GB; the vendored subset is 512 KB.

The source package and its zip are **gitignored**; the vendored subset is committed, because
the app loads it as a plain `<link>` and a deploy must not depend on the pack being present.

## Adding an icon

1. Add it to `IconRegistry::all()` with both a `fa` name and the legacy `svg` body (no
   `width`/`height`/`class` — `pb_icon()` applies those).
2. Call `pb_icon('your-name')`.

`IconSetTest::test_every_icon_used_by_a_view_is_registered` fails if a view calls a name the
registry does not hold.

## Migration status

Converted so far — the shared chrome, 26 icons:

- `partials/app-sidebar.blade.php` (17)
- `partials/app-topbar.blade.php` (9)

Roughly 340 inline SVGs remain, the bulk of them in the Vue string templates under
`public/assets/js/projects/` (88 in `work-items.js` alone). Those need the JS-side twin of
`pb_icon()` before they can be converted — a `wiIcon(name, size, class)` reading the same
registry, shipped as a generated JS map so there is still one source of truth.

**A half-migrated app is fine.** Every layout links the Font Awesome stylesheet when the set
is on, so converted icons render as glyphs while unconverted ones stay as their inline SVGs.
Nothing breaks in either position.

## Tests

`tests/Feature/IconSetTest.php` — both sets render; legacy requests no font; every icon a view
uses is registered; an unknown name is loud in development; both definitions exist for every
entry; the font is self-hosted (same rule as the rest of `docs/features/self-hosted-assets.md`).

`AppSidebarTest::test_the_chrome_follows_the_configured_icon_set` renders a real screen under
each setting — which is what proves the switch actually swaps the UI rather than just the
helper's return value.
