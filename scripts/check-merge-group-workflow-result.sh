#!/usr/bin/env bash
set -euo pipefail

if [[ "${VALIDATOR_RESULT:-}" != 'success' ]]; then
    echo '::error::Merge queue evidence validator tests did not pass.' >&2
    exit 1
fi

case "${EVENT_NAME:-}" in
    pull_request)
        echo 'The queue gate runs on the merge-group commit; this PR check verifies the gate itself.'
        ;;
    merge_group)
        if [[ "${EVIDENCE_RESULT:-}" != 'success' ]]; then
            echo '::error::The merge-group evidence selection did not complete.' >&2
            exit 1
        fi
        if [[ "${REUSE:-}" == 'true' ]]; then
            if [[ "${MATRIX_RESULT:-}" != 'skipped' ]]; then
                echo '::error::Reused evidence must not also run the full matrix.' >&2
                exit 1
            fi
        elif [[ "${MATRIX_RESULT:-}" != 'success' ]]; then
            echo '::error::The complete merge-group Test All matrix did not pass.' >&2
            exit 1
        fi
        ;;
    *)
        echo '::error::The merge queue gate only runs for pull requests and merge groups.' >&2
        exit 1
        ;;
esac
