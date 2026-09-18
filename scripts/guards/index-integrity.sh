#!/usr/bin/env bash
#
# Refuse a commit that would record an empty or near-empty tree.
#
# `.git/index` on this host has been found emptied several times: 0 tracked
# files, with every file in HEAD staged as a deletion. `git commit` does not
# fail in that state. It faithfully records git's empty tree
# (4b825dc642cb6eb9a060e54bf8d69288fbee4904), every layer downstream reports
# success, and the branch loses every file. That is how capell-packages main
# lost all 15,286 of its files on 2026-09-07.
#
# The index is written by more operations than people expect - `git status`
# rewrites it to refresh its stat cache - so a killed or raced git process can
# truncate it while the working tree stays perfectly intact. The corruption is
# quiet: hooks report "no files to check", and `git status` still shows a file
# as modified immediately after a successful commit of that same file.
#
# Repair is index-only and discards nothing:
#
#     rm .git/index && git read-tree HEAD
#     git ls-files | wc -l      # confirm the real count is back
#
set -euo pipefail

if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    exit 0
fi

tracked=$(git ls-files | wc -l | tr -d ' ')
head_files=$(git ls-tree -r HEAD --name-only | wc -l | tr -d ' ')
staged_deletions=$(git diff --cached --name-only --diff-filter=D | wc -l | tr -d ' ')

fail() {
    echo "" >&2
    echo "REFUSING THIS COMMIT: $1" >&2
    echo "" >&2
    echo "  files tracked in the index : ${tracked}" >&2
    echo "  files in HEAD              : ${head_files}" >&2
    echo "  staged as deleted          : ${staged_deletions}" >&2
    echo "" >&2
    echo "If the index is empty or truncated, repair it - this touches only the" >&2
    echo "index and discards no work:" >&2
    echo "" >&2
    echo "    rm .git/index && git read-tree HEAD" >&2
    echo "    git ls-files | wc -l" >&2
    echo "" >&2
    echo "Then re-stage and commit. If you genuinely intend to delete this much," >&2
    echo "set CAPELL_ALLOW_MASS_DELETE=1 for that one commit." >&2
    echo "" >&2
    exit 1
}

if [ "${CAPELL_ALLOW_MASS_DELETE:-0}" = "1" ]; then
    exit 0
fi

if [ "$head_files" -gt 0 ] && [ "$tracked" -eq 0 ]; then
    fail "the index is empty while HEAD has ${head_files} files."
fi

# A real change never removes most of the repository. Require both a large
# absolute count and a majority share, so small repositories and honest
# directory removals are unaffected.
if [ "$head_files" -gt 0 ] && [ "$staged_deletions" -gt 100 ]; then
    if [ $((staged_deletions * 2)) -gt "$head_files" ]; then
        fail "this commit deletes ${staged_deletions} of HEAD's ${head_files} files."
    fi
fi

exit 0
