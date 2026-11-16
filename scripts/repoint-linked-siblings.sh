#!/usr/bin/env bash
# Re-point dangling node_modules/@capell-app links in a linked git worktree.
#
# package.json links sibling packages with "file:../<name>", so npm writes a
# RELATIVE link. In a worktree under the shared .capell-wt root that link
# resolves to .capell-wt/<name>, which does not exist, so it dangles and the
# screenshot runner and its .bin shims break. This points each dangling link at
# the absolute location the primary checkout's link resolves to. Siblings live
# beside the primary, never inside it.
#
# Runs from init-worktree.sh and from npm's postinstall, so `npm ci` in a
# worktree repairs its own links. It never touches the primary checkout and
# never fails an install: anything it cannot repair is reported and left alone.
set -u

worktree_root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
git_dir=$(cd "$(git rev-parse --git-dir 2>/dev/null)" 2>/dev/null && pwd -P) || exit 0
common_dir=$(cd "$(git rev-parse --git-common-dir 2>/dev/null)" 2>/dev/null && pwd -P) || exit 0
# Only a linked worktree has its own git dir apart from the shared one. The
# primary checkout, a submodule and a --separate-git-dir repository do not.
[ "$git_dir" != "$common_dir" ] || exit 0
primary_root=$(dirname "$common_dir")
worktree_root=$(cd "$worktree_root" && pwd -P)
[ "$worktree_root" != "$primary_root" ] || exit 0
[ -d "$worktree_root/node_modules/@capell-app" ] || exit 0

repointed=()
for linked in "$worktree_root"/node_modules/@capell-app/*; do
    [ -L "$linked" ] || continue
    [ -e "$linked" ] && continue
    primary_target=$(cd "$primary_root/node_modules/@capell-app/$(basename "$linked")" 2>/dev/null && pwd -P) || primary_target=""
    if [ -n "$primary_target" ] && [ -e "$primary_target" ] && [ "${primary_target#"$primary_root"/}" = "$primary_target" ]; then
        ln -sfn "$primary_target" "$linked"
        repointed+=("@capell-app/$(basename "$linked")")
        echo "Re-pointed node_modules/@capell-app/$(basename "$linked") at $primary_target."
    else
        echo "WARNING: node_modules/@capell-app/$(basename "$linked") dangles and the primary checkout's sibling could not be located; run npm ci in the primary checkout first." >&2
    fi
done

# npm skips a package's bin shims while its link dangles, so link them now. With
# --ignore-scripts, rebuild links bins and runs no lifecycle scripts.
if [ "${#repointed[@]}" -gt 0 ] && command -v npm >/dev/null 2>&1; then
    (cd "$worktree_root" && npm rebuild --ignore-scripts "${repointed[@]}" >/dev/null 2>&1) \
        || echo "WARNING: could not link the bin shims of ${repointed[*]}; run npm rebuild ${repointed[*]} here." >&2
fi
exit 0
