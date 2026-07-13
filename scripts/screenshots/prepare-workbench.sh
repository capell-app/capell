#!/usr/bin/env bash

set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_URL="${CAPELL_FRONTEND_URL:-http://127.0.0.1:8145}"
DATABASE_PATH="${REPOSITORY_ROOT}/workbench/database/screenshots.sqlite"

cd "${REPOSITORY_ROOT}"

mkdir -p "$(dirname "${DATABASE_PATH}")"
find "${REPOSITORY_ROOT}/workbench/database/migrations" -maxdepth 1 -type f \
    \( -name '*_create_notifications_table.php' -o -name '*_create_sessions_table.php' \) \
    -delete
rm -f "${DATABASE_PATH}"
touch "${DATABASE_PATH}"

export PHPRC="${REPOSITORY_ROOT}/workbench/php"
export APP_URL
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

php -d memory_limit=-1 vendor/bin/testbench capell:install \
    --fresh=force \
    --demo \
    --package-mode=core \
    --url="${APP_URL}" \
    --name=Admin \
    --email=admin@example.com \
    --password=password \
    --clear-cache \
    --install-welcome-route \
    --no-interaction
