#!/usr/bin/env bash
#
# pblock3.0 — bootstrap script
# Scaffolds Laravel 13 and installs the project stack (see CLAUDE.md).
# Run from the project root: ~/Sites/pblock3.0
#
# Prereqs: PHP 8.3+, Composer, Node.js + npm, and a MariaDB/MySQL database (MAMP is fine).
#
# This script is idempotent-ish but assumes a fresh project. Read before running.
# It will NOT drop your database. It DOES move the MAMP placeholder index.php aside.

set -euo pipefail

say() { printf "\n\033[1;34m==>\033[0m %s\n" "$1"; }
warn() { printf "\n\033[1;33m[warn]\033[0m %s\n" "$1"; }

# ---------------------------------------------------------------------------
# 0. Preflight
# ---------------------------------------------------------------------------
say "Checking prerequisites"
command -v php >/dev/null      || { echo "PHP not found"; exit 1; }
command -v composer >/dev/null || { echo "Composer not found"; exit 1; }
command -v npm >/dev/null      || { echo "Node/npm not found"; exit 1; }

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
say "PHP version: $PHP_VER (Laravel 13 requires 8.3+)"

PROJECT_DIR="$(pwd)"
say "Project directory: $PROJECT_DIR"

# ---------------------------------------------------------------------------
# 1. Move the MAMP placeholder aside so we can scaffold into this folder
# ---------------------------------------------------------------------------
if [ -f "index.php" ] && ! [ -f "artisan" ]; then
  say "Moving MAMP placeholder index.php -> _mamp_placeholder.index.php.bak"
  mv index.php _mamp_placeholder.index.php.bak
fi

# ---------------------------------------------------------------------------
# 2. Scaffold Laravel 13 (into a temp dir, then sync into project root)
# ---------------------------------------------------------------------------
if [ -f "artisan" ]; then
  warn "artisan already exists — skipping Laravel scaffold."
else
  say "Creating Laravel 13 app"
  TMP_APP="$(mktemp -d)"
  composer create-project "laravel/laravel:^13.0" "$TMP_APP/app"
  # Move everything (including dotfiles) into the project root
  shopt -s dotglob
  mv "$TMP_APP"/app/* "$PROJECT_DIR"/
  shopt -u dotglob
  rm -rf "$TMP_APP"
fi

# ---------------------------------------------------------------------------
# 3. Configure database for MariaDB/MySQL in .env
#    (Decision D1 in CLAUDE.md — MariaDB engine, portable migrations.)
# ---------------------------------------------------------------------------
say "Configuring .env for MariaDB/MySQL"
if [ -f ".env" ]; then
  # macOS/BSD sed uses -i '' ; GNU sed uses -i
  SED_INPLACE=(-i '')
  if sed --version >/dev/null 2>&1; then SED_INPLACE=(-i); fi
  sed "${SED_INPLACE[@]}" \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' \
    -e 's/^# *DB_HOST=.*/DB_HOST=127.0.0.1/' \
    -e 's/^# *DB_PORT=.*/DB_PORT=3306/' \
    -e 's/^# *DB_DATABASE=.*/DB_DATABASE=pblock3/' \
    -e 's/^# *DB_USERNAME=.*/DB_USERNAME=root/' \
    -e 's/^# *DB_PASSWORD=.*/DB_PASSWORD=root/' \
    .env || warn "Adjust DB_* in .env manually (MAMP defaults: user root / pass root / port 3306 or 8889)."
  warn "MAMP note: the MySQL port is often 8889 and socket differs. Verify DB_PORT/DB_HOST."
fi

# ---------------------------------------------------------------------------
# 4. Laravel Boost (AI coding assistant)
# ---------------------------------------------------------------------------
say "Installing Laravel Boost"
composer require laravel/boost --dev
say "Run 'php artisan boost:install' interactively after this script (it prompts for your agent)."

# ---------------------------------------------------------------------------
# 5. Multi-tenancy (single database) — stancl/tenancy
# ---------------------------------------------------------------------------
say "Installing stancl/tenancy (tenancyforlaravel.com)"
composer require stancl/tenancy
say "Run 'php artisan tenancy:install' and configure SINGLE-DATABASE mode per CLAUDE.md §7."

# ---------------------------------------------------------------------------
# 6. Real-time: Reverb + broadcasting + Echo (installs Echo + pusher-js client)
# ---------------------------------------------------------------------------
say "Installing Laravel Reverb"
composer require laravel/reverb
say "Installing broadcasting + Echo scaffolding"
php artisan install:broadcasting --no-interaction || \
  warn "Run 'php artisan install:broadcasting' manually to wire Reverb + Echo."

# ---------------------------------------------------------------------------
# 7. Frontend: Vue + FlyonUI (on Tailwind)
# ---------------------------------------------------------------------------
say "Installing frontend deps (Vue, FlyonUI, Echo client)"
npm install
npm install vue @vitejs/plugin-vue
npm install flyonui
npm install laravel-echo pusher-js

cat <<'NOTE'

------------------------------------------------------------------
Frontend wiring still to do by hand (see CLAUDE.md §13 & §14):
  * vite.config.js: add @vitejs/plugin-vue to the plugins array.
  * resources/css/app.css: add Tailwind + FlyonUI:
        @import "tailwindcss";
        @plugin "flyonui";
  * resources/js/bootstrap.js (or app.js): configure Echo with the
    Reverb broadcaster using VITE_REVERB_* env vars.
  * Custom CSS extending FlyonUI must use the `_moretogether` prefix.
------------------------------------------------------------------
NOTE

# ---------------------------------------------------------------------------
# 8. Migrate + build
# ---------------------------------------------------------------------------
say "Generating app key"
php artisan key:generate || true

say "Running migrations (ensure the 'pblock3' database exists first)"
php artisan migrate || warn "Create the database and fix DB_* in .env, then run: php artisan migrate"

say "Building frontend assets"
npm run build || warn "Run 'npm run dev' during development."

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
say "Bootstrap complete."
cat <<'DONE'

Next steps:
  1. php artisan boost:install        # choose Claude Code as your agent
  2. php artisan tenancy:install      # then configure single-database mode
  3. Wire Vite/Vue/FlyonUI/Echo (see notes above)
  4. Start services:
        php artisan reverb:start      # WebSocket server
        php artisan queue:work        # queue worker (required for broadcast/mail)
        npm run dev                   # Vite dev server
  5. Build features one at a time using docs/feature-template.md

Confirm the open decisions in CLAUDE.md §18 (database engine, custom-class prefix).
DONE
