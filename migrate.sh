#!/usr/bin/env bash
#
# ProjectBlock 3.0 — DB migration helper
# Run from the project root:  bash migrate.sh
#
# Applies any pending Laravel migrations (Phase 3 Settings tables + Phase 4 Projects
# tables) and clears the config cache. This is the recommended way — it records the
# migrations in the `migrations` table so nothing re-runs.
#
set -euo pipefail
cd "$(dirname "$0")"

# --- locate a PHP 8.3+ binary (system, Homebrew, or MAMP) ---
PHP=""
CANDIDATES=(php /opt/homebrew/bin/php /usr/local/bin/php)
# MAMP ships its own PHP; pick the newest 8.x
for d in /Applications/MAMP/bin/php/php8.*/bin/php; do
  [ -x "$d" ] && CANDIDATES+=("$d")
done
for c in "${CANDIDATES[@]}"; do
  if command -v "$c" >/dev/null 2>&1; then PHP="$c"; break; fi
done

if [ -z "$PHP" ]; then
  echo "Could not find a php binary."
  echo "Edit this script and set PHP=/full/path/to/php  (e.g. your MAMP php), then re-run."
  exit 1
fi

echo "Using PHP: $("$PHP" -v | head -1)"
echo

# Ensure the sqlite database file exists (no-op if you use MySQL).
if grep -q '^DB_CONNECTION=sqlite' .env 2>/dev/null; then
  DB_FILE="$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)"
  [ -n "${DB_FILE:-}" ] && [ ! -f "$DB_FILE" ] && { mkdir -p "$(dirname "$DB_FILE")"; : > "$DB_FILE"; echo "Created $DB_FILE"; }
fi

"$PHP" artisan migrate --force
"$PHP" artisan config:clear

echo
echo "Migration complete. Open /projects in your browser."
