#!/usr/bin/env bash
# Runs the commit-time content guards (checkfordebugging, donotcommit) over every
# file changed between two commits, so a commit made with --no-verify, or an
# amend that staged other files, cannot carry a debugging call or a
# do-not-commit marker past them. Excludes and file types come from .pre-commit-config.yaml unchanged.
#
# Usage: scripts/guards/check-commit-range.sh <base-ref> <head-ref>
# The PHP Quality workflow calls it; the pre-push stage runs the same hooks natively.
set -euo pipefail

if [ "$#" -ne 2 ] || [ -z "$1" ] || [ -z "$2" ]; then
    echo "usage: scripts/guards/check-commit-range.sh <base-ref> <head-ref>" >&2
    exit 2
fi

base=$1
head=$2

if ! git rev-parse --verify --quiet "${base}^{commit}" >/dev/null; then
    echo "check-commit-range: base ${base} is not a commit in this checkout; fetch it (CI needs fetch-depth: 0)." >&2
    exit 2
fi

if [ -n "${PRE_COMMIT:-}" ]; then
    read -r -a runner <<<"${PRE_COMMIT}"
elif command -v pre-commit >/dev/null 2>&1; then
    runner=(pre-commit)
else
    echo "check-commit-range: pre-commit is not installed; install it (pipx install pre-commit) or set PRE_COMMIT." >&2
    exit 2
fi

status=0
for hook in checkfordebugging donotcommit; do
    "${runner[@]}" run "$hook" --hook-stage manual --from-ref "$base" --to-ref "$head" || status=1
done

exit "$status"
