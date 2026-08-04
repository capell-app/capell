#!/usr/bin/env bash
################################################################################
#
# Prettier pre-commit formatter
#
# Fails closed. A checkout that cannot run Prettier blocks the commit instead of
# reporting "Passed" — a linked git worktree starts without node_modules, and a
# silent pass here ships unformatted code that CI's `prettier --check` then
# rejects several commits later.
#
# Exit 0 if nothing to format, or everything formatted and re-staged
# Exit 1 if Prettier is unavailable, or failed on a staged file
#
################################################################################

set -euo pipefail

PRETTIER="./node_modules/.bin/prettier"

if [ ! -x "$PRETTIER" ]; then
    cat >&2 <<'MSG'
Prettier is not available at ./node_modules/.bin/prettier.

This checkout cannot verify formatting, so the commit is blocked rather than
passing unchecked. A fresh git worktree starts without node_modules — run:

    scripts/worktree-init.sh

(or `npm ci`) in this checkout, then commit again.
MSG
    exit 1
fi

FILES=$(git diff --cached --name-only --diff-filter=ACMR | sed 's| |\\ |g')

if [ -z "$FILES" ]; then
    exit 0
fi

# Prettify all selected files. A non-zero status (unparseable file, plugin
# failure) propagates via set -o pipefail and blocks the commit.
echo "$FILES" | xargs "$PRETTIER" --ignore-unknown --write

# Add back the modified/prettified files to staging
echo "$FILES" | xargs git add

exit 0
