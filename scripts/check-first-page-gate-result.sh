#!/usr/bin/env bash
# Decides the required "Fresh app reaches its first page in under 5 minutes" check.
# A pull request skips the install, so the gate passes there. Every other event must have
# a successful install: a skipped or cancelled install is a failure, never a pass.
set -euo pipefail

if [[ "${EVENT_NAME:-}" == 'pull_request' ]]; then
    echo 'The fresh-app install runs in the merge queue and after merges; this PR check only reports.'
elif [[ "${FIRST_PAGE_RESULT:-}" != 'success' ]]; then
    echo "::error::The fresh app did not reach its first page in time (install job result: ${FIRST_PAGE_RESULT:-none})." >&2
    exit 1
fi
