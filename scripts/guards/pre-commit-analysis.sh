#!/usr/bin/env bash
# pre-commit hides unstaged tracked edits and supplies staged filenames.
set -eu
cd "$(git rev-parse --show-toplevel)"
[ "$#" -gt 0 ] || exit 0

# Match Core's host PHP convention; ./capell is a Docker-only wrapper.
[ ! -d /opt/homebrew/opt/php@8.4/bin ] || export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
if command -v php >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1 && [ -f vendor/phpstan/phpstan/phpstan ]; then
    exec php scripts/with-lock.php capell-static -- python3 -B scripts/guards/pre-commit-analysis.py "$@"
fi
exec ./capell exec php scripts/with-lock.php capell-static -- python3 -B scripts/guards/pre-commit-analysis.py "$@"
