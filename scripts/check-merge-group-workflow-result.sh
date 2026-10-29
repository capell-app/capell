#!/usr/bin/env bash
set -euo pipefail

if [[ "${VALIDATOR_RESULT:-}" != 'success' ]]; then
    echo '::error::Merge queue evidence validator tests did not pass.' >&2
    exit 1
fi

if [[ "${EVENT_NAME:-}" == 'pull_request' && "${MATRIX_REQUIRED:-}" == 'false' && "${MATRIX_RESULT:-}" == 'skipped' ]]; then
    echo 'The full matrix runs on the merge-group commit; this PR check verifies the gate itself.'
elif [[ ( "${EVENT_NAME:-}" != 'merge_group' && "${EVENT_NAME:-}" != 'pull_request' ) || "${MATRIX_REQUIRED:-}" != 'true' || "${MATRIX_RESULT:-}" != 'success' ]]; then
    echo '::error::The complete merge-group Test All matrix did not pass.' >&2
    exit 1
fi
