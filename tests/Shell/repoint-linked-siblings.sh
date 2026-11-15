#!/usr/bin/env bash
# `npm ci` in a .capell-wt worktree leaves a relative, dangling link for
# "file:../capell-screenshot-tools"; postinstall must re-point it, and must
# leave the primary checkout and unrelated links alone.
set -euo pipefail
script="${REPOINT_SCRIPT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/repoint-linked-siblings.sh}"
while IFS= read -r variable; do
    unset "$variable"
done < <(git rev-parse --local-env-vars)
fixture=$(cd "$(mktemp -d)" && pwd -P)
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/capell/primary" "$fixture/capell/capell-screenshot-tools/bin" "$fixture/capell/other-tools"
cd "$fixture/capell/primary"
git init -q
git -c user.name=fixture -c user.email=fixture@example.test commit -q --allow-empty -m base
# The primary's npm link, exactly as npm writes it.
mkdir -p node_modules/@capell-app
ln -s ../../../capell-screenshot-tools node_modules/@capell-app/screenshot-tools
git worktree add -q "$fixture/capell/.capell-wt/feature" -b feature
cd "$fixture/capell/.capell-wt/feature"
# What `npm ci` leaves in the worktree: the same relative link, now dangling.
mkdir -p node_modules/@capell-app node_modules/.bin
ln -s ../../../capell-screenshot-tools node_modules/@capell-app/screenshot-tools
touch "$fixture/capell/capell-screenshot-tools/bin/capell-screenshots.js"
# npm writes no bin shim for a dangling link; the stub records that rebuild was asked to add it.
mkdir -p "$fixture/stubs"
cat > "$fixture/stubs/npm" <<'NPM'
#!/bin/sh
echo "$*" >> "$FIXTURE_NPM_LOG"
[ "$1" = rebuild ] && ln -s ../@capell-app/screenshot-tools/bin/capell-screenshots.js node_modules/.bin/capell-screenshots
exit 0
NPM
chmod +x "$fixture/stubs/npm"
export FIXTURE_NPM_LOG="$fixture/npm.log" PATH="$fixture/stubs:$PATH"
: > "$FIXTURE_NPM_LOG"
# A healthy link to a different sibling must not be rewritten.
ln -s "$fixture/capell/other-tools" node_modules/@capell-app/other-tools
# A dangling link with no counterpart in the primary is reported, not guessed.
ln -s ../../../missing-tools node_modules/@capell-app/missing-tools
test ! -e node_modules/@capell-app/screenshot-tools
output=$(bash "$script" 2>&1)
test "$(readlink node_modules/@capell-app/screenshot-tools)" = "$fixture/capell/capell-screenshot-tools"
test -e node_modules/.bin/capell-screenshots
grep -qx 'rebuild --ignore-scripts @capell-app/screenshot-tools' "$FIXTURE_NPM_LOG"
echo 'PASS dangling worktree link re-pointed at the primary sibling, and its .bin shim resolves'
test "$(readlink node_modules/@capell-app/other-tools)" = "$fixture/capell/other-tools"
test "$(readlink node_modules/@capell-app/missing-tools)" = "../../../missing-tools"
grep -q 'missing-tools dangles' <<<"$output"
echo 'PASS healthy and unknown links are left alone'
cd "$fixture/capell/primary"
rm node_modules/@capell-app/screenshot-tools
ln -s ../../../not-there node_modules/@capell-app/screenshot-tools
: > "$FIXTURE_NPM_LOG"
bash "$script"
test "$(readlink node_modules/@capell-app/screenshot-tools)" = "../../../not-there"
test ! -s "$FIXTURE_NPM_LOG"
echo 'PASS primary checkout is never modified'
cd "$fixture"
bash "$script"
echo 'PASS outside a git repository it exits cleanly'
# A repository whose git dir lives elsewhere is not a linked worktree.
git init -q --separate-git-dir "$fixture/separate.git" "$fixture/separate"
mkdir -p "$fixture/separate/node_modules/@capell-app"
ln -s ../../../capell-screenshot-tools "$fixture/separate/node_modules/@capell-app/screenshot-tools"
separate_output=$(cd "$fixture/separate" && bash "$script" 2>&1)
test -z "$separate_output"
test "$(readlink "$fixture/separate/node_modules/@capell-app/screenshot-tools")" = "../../../capell-screenshot-tools"
echo 'PASS a separate-git-dir repository is left alone'
