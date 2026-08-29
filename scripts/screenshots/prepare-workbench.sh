#!/usr/bin/env bash

set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_URL="${CAPELL_FRONTEND_URL:-http://127.0.0.1:8145}"
# The runner's isolated origin is rendered into the page URL badge and slug
# field. Direct marketing-App origins are not accepted screenshot evidence.
DISPLAY_URL="${CAPELL_SCREENSHOT_DISPLAY_ORIGIN:-http://127.0.0.1:8145}"
DATABASE_PATH="${REPOSITORY_ROOT}/workbench/database/screenshots.sqlite"

cd "${REPOSITORY_ROOT}"

mkdir -p "$(dirname "${DATABASE_PATH}")"
# composer's post-autoload-dump deletes the testbench skeleton's migrations
# directory, so a run straight after `composer install` has nowhere to publish
# vendor migrations to and the install aborts at the publish step.
mkdir -p "${REPOSITORY_ROOT}/vendor/orchestra/testbench-core/laravel/database/migrations"
# `session:table` and `notifications:table` generate a migration into whichever
# migration directory is registered, and those generated files persist across
# runs. Clear them from the generated locations only — the previous version of
# this cleanup globbed workbench/database/migrations, which also deleted two
# TRACKED workbench migrations on every run and left the tree dirty.
#
# sessions is not regenerated at all any more: every Laravel 11+ skeleton
# creates it inside 0001_01_01_000000_create_users_table.php, and
# PrepareEnvironmentAction now detects that instead of generating a duplicate
# that failed with "table sessions already exists".
for generated_migration_directory in \
    "${REPOSITORY_ROOT}/vendor/orchestra/testbench-core/laravel/database/migrations" \
    "${REPOSITORY_ROOT}/workbench/database/migrations"; do
    [[ -d "${generated_migration_directory}" ]] || continue

    find "${generated_migration_directory}" -maxdepth 1 -type f \
        \( -name '*_create_notifications_table.php' -o -name '*_create_sessions_table.php' \) \
        -delete
done
# IntegrateViteInputsAction resolves base_path('vite.config.{js,mjs,ts}') and
# throws when none is found. Under Testbench, base_path() is the generated
# Orchestra skeleton, which ships no Vite config at all, so create one that
# already references capellViteInputs and the install step short-circuits
# instead of trying to rewrite it.
cat > "${REPOSITORY_ROOT}/vendor/orchestra/testbench-core/laravel/vite.config.js" <<'VITE_CONFIG'
import { capellViteInputs } from './vendor/capell-app/frontend/resources/js/capell-vite-inputs.js';

export default {
    build: {
        rollupOptions: {
            input: capellViteInputs(import.meta.dirname),
        },
    },
};
VITE_CONFIG

rm -f "${DATABASE_PATH}"
touch "${DATABASE_PATH}"

export PHPRC="${REPOSITORY_ROOT}/workbench/php"
export APP_URL
export CAPELL_SCREENSHOT_DISPLAY_ORIGIN="${DISPLAY_URL}"
export APP_KEY='base64:/MjiNkPfjAngJBfuMDsnFBxDynZGOKk3O6P0u0MhvJE='
export DB_CONNECTION=sqlite
export DB_DATABASE="${DATABASE_PATH}"
export CACHE_STORE=array
export SESSION_DRIVER=array
export QUEUE_CONNECTION=sync
export DEBUGBAR_ENABLED=false
export CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED=false
export CAPELL_MARKETPLACE_URL="${APP_URL}/api/v1"
export CAPELL_MARKETPLACE_WEB_URL="${APP_URL}"

php scripts/configure-testbench-runtime-role.php

php vendor/bin/testbench capell:install \
    --fresh=force \
    --demo \
    --package-mode=core \
    --url="${DISPLAY_URL}" \
    --name=Admin \
    --email=admin@example.com \
    --password=password \
    --clear-cache \
    --install-welcome-route \
    --no-interaction

# capell:install publishes Filament's CSS and JS as a side effect. Ask for them
# explicitly so a change to the install pipeline cannot leave captures unstyled.
php vendor/bin/testbench filament:assets

# The admin panel resolves its theme through Vite
# (viteTheme('resources/css/filament/admin/theme.css', 'build/filament')), and a
# Filament theme REPLACES the published app.css. Compile a real theme bundle so
# admin captures are actually styled — a stub theme.css renders every admin
# page without panel CSS and the runner's stylesheet guard aborts the capture.
node scripts/screenshots/build-filament-theme-css.mjs

# The frontend installer generates a Tailwind input under resources/css, but a
# browser cannot load that source path directly. Compile it to a stable public
# fixture asset before the frontend screenshot seed binds the default theme to
# it. This does not turn generated CSS into route-backed screenshot evidence.
node scripts/screenshots/build-frontend-theme-css.mjs
