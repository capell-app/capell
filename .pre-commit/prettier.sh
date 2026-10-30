#!/usr/bin/env bash
set -euo pipefail

# The installed snapshot entrypoint supplies staged files in an isolated worktree.
# Formatting never stages files itself.
if [[ $# -eq 0 ]]; then
    exit 0
fi

./node_modules/.bin/prettier --ignore-unknown --write "$@"
