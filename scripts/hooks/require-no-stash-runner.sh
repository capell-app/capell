#!/usr/bin/env bash
# Fail fast unless the checks run through the isolated snapshot runner. The stock pre-commit
# framework hook, and a bare `pre-commit run`, stash every unstaged edit in the
# worktree and can discard a concurrent writer's work when they restore it.
set -eu
if [ "${CAPELL_NO_STASH_HOOK:-}" = 1 ] || [ -n "${CI:-}" ]; then
    exit 0
fi
echo "These checks ran through a hook that stashes the whole worktree, which can discard other writers' edits." >&2
echo "Commit through the no-stash hook (python3 scripts/hooks/install-pre-commit.py in the primary checkout)." >&2
exit 1
