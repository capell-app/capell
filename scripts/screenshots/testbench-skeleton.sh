#!/usr/bin/env bash
# Keeps the shared Testbench skeleton pristine around screenshot preparation.
#
#   snapshot  copy the pristine skeleton aside (does nothing while a prepared
#             skeleton is in place, so the first, pristine copy is kept)
#   restore   put the pristine copy back and remove the prepared marker
#
# prepare-workbench.sh rewrites the skeleton under vendor/ and the capture serves
# from it, so it cannot be restored until the capture is over. Restoring is the
# caller's job once it has finished; local-core-screenshots.sh does it on exit.

set -euo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SKELETON="${REPOSITORY_ROOT}/vendor/orchestra/testbench-core/laravel"
SNAPSHOT="${REPOSITORY_ROOT}/storage/framework/testing/testbench-skeleton-pristine"
MARKER="${SKELETON}/.capell-workbench-prepared"

case "${1:-}" in
    snapshot)
        # A marker means the skeleton is already rewritten; the existing copy is the pristine one.
        if [[ -e "${MARKER}" && -d "${SNAPSHOT}" ]]; then
            exit 0
        fi

        mkdir -p "${SKELETON}" "${SNAPSHOT}"
        rsync -a --delete "${SKELETON}/" "${SNAPSHOT}/"
        ;;
    restore)
        if [[ ! -d "${SNAPSHOT}" ]]; then
            echo "No pristine Testbench skeleton copy to restore; run: composer reinstall orchestra/testbench-core --no-scripts" >&2
            exit 1
        fi

        rsync -a --delete "${SNAPSHOT}/" "${SKELETON}/"
        rm -rf "${SNAPSHOT}"
        ;;
    *)
        echo "Usage: testbench-skeleton.sh snapshot|restore" >&2
        exit 2
        ;;
esac
