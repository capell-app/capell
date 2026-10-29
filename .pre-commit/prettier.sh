#!/usr/bin/env bash
set -euo pipefail

# Pre-commit supplies an isolated staged snapshot and owns index restoration.
# Formatting may change working files, but a hook must never stage them itself.
if [[ $# -eq 0 ]]; then
    exit 0
fi

./node_modules/.bin/prettier --ignore-unknown --write "$@"
