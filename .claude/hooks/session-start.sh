#!/usr/bin/env bash
# Claude Code cloud SessionStart: provision PHP 8.4 and install dependencies
# from source (zipball downloads are blocked by the sandbox proxy).
set -euo pipefail

[ "${CLAUDE_CODE_REMOTE:-}" = "true" ] || exit 0

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

if ! php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq software-properties-common >/dev/null
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
    apt-get install -y -qq php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml \
        php8.4-zip php8.4-intl php8.4-sqlite3 php8.4-gd php8.4-bcmath >/dev/null
    update-alternatives --set php /usr/bin/php8.4
fi

[ -d vendor ] || scripts/install-from-source.sh
