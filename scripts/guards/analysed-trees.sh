#!/usr/bin/env bash
# Ledger of trees whose staged PHP the pre-commit analysis passed cleanly, so the
# pre-push analysis can skip commits it has already covered.
#   analysed-trees.sh record            record the index tree (after a clean pre-commit analysis)
#   analysed-trees.sh covered <range>   exit 0 only when every commit in <range> either changes no
#                                       analysable PHP file or has a recorded clean tree
# Anything unrecorded (--no-verify, rebase, amend, cherry-pick) is not covered, so the caller
# falls back to the full push analysis.
set -eu
cd "$(git rev-parse --show-toplevel)"
dir="$(git rev-parse --path-format=absolute --git-common-dir)/capell-analysed"
pattern='^(packages|tests)/.+\.php$|^scripts/benchmark-boot.*\.php$'

case "${1:-}" in
    record)
        mkdir -p "$dir"
        : > "$dir/$(git write-tree)"
        ;;
    covered)
        range="${2:?range required}"
        while IFS= read -r commit; do
            if ! git diff --name-only --diff-filter=ACMR "$commit^" "$commit" 2> /dev/null | grep -Eq "$pattern"; then
                # A root commit has no parent to diff against; treat it as unrecorded.
                git rev-parse --verify --quiet "$commit^" > /dev/null || exit 1
                continue
            fi
            [ -e "$dir/$(git rev-parse "$commit^{tree}")" ] || exit 1
        done < <(git rev-list "$range")
        ;;
    *)
        echo "usage: analysed-trees.sh record | covered <range>" >&2
        exit 2
        ;;
esac
