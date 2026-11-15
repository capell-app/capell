#!/usr/bin/env bash
# The pre-push PHPStan run is skipped only when every commit in the pushed range was
# analysed cleanly by the pre-commit hook. A --no-verify commit, an amend and a rebase
# leave unrecorded trees and fall back to the full analysis. A fake composer records runs
# and a stub with-lock.php stands in for the pre-commit analysis.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.test
export GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

origin="$scratch/origin.git"
repo="$scratch/repo"
git init -q --bare --initial-branch=main "$origin"
git clone -q "$origin" "$repo" 2> /dev/null
mkdir -p "$repo/scripts/guards" "$repo/packages/demo" "$repo/vendor/phpstan/phpstan"
for script in pre-push-analysis.sh pre-commit-analysis.sh analysed-trees.sh; do
    cp "$root/scripts/guards/$script" "$repo/scripts/guards/$script"
done
touch "$repo/README.md" "$repo/vendor/phpstan/phpstan/phpstan"
printf 'vendor/\n' > "$repo/.gitignore"
git -C "$repo" add .
git -C "$repo" -c core.hooksPath= commit -qm base
git -C "$repo" push -q origin HEAD:main
git -C "$repo" fetch -q origin
git -C "$repo" checkout -q -b feature

bin="$scratch/bin"
mkdir -p "$bin" "$scratch/home" "$scratch/rec"
cat > "$bin/composer" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$RECORD/composer"
FAKE
# The pre-commit analysis runs `php scripts/with-lock.php ...`; the stub's exit stands in for PHPStan.
cat > "$repo/scripts/with-lock.php" <<'FAKE'
<?php
$status = (int) getenv('PHPSTAN_FAKE_EXIT');
if ($status !== 0) {
    throw new RuntimeException("analysis failed");
}
FAKE
chmod +x "$bin/composer"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
push_hook() {
    rm -f "$scratch/rec/composer"
    (cd "$repo" && env -u HEAVY_SLOT_OWNER HOME="$scratch/home" RECORD="$scratch/rec" PATH="$bin:/usr/bin:/bin" \
        bash scripts/guards/pre-push-analysis.sh < /dev/null) > "$scratch/out" 2>&1 \
        || { cat "$scratch/out" >&2; fail 'the push hook exited non-zero'; }
}
commit_php() { # <file> <content> [--no-verify]
    printf '<?php\n// %s\n' "$2" > "$repo/packages/demo/$1"
    git -C "$repo" add "packages/demo/$1"
    if [ "${3:-}" = --no-verify ]; then
        git -C "$repo" -c core.hooksPath= commit -qm "$2"
    else
        # What the Capell pre-commit runner does: the hook runs in an isolated snapshot repository
        # that borrows this repository's objects through an alternate.
        snapshot="$scratch/snapshot"
        rm -rf "$snapshot"
        git init -q "$snapshot"
        printf '%s\n' "$repo/.git/objects" > "$snapshot/.git/objects/info/alternates"
        cp "$repo/.git/index" "$snapshot/.git/index"
        git -C "$snapshot" checkout-index --all --force
        cp -R "$repo/vendor" "$snapshot/vendor"
        cp "$repo/scripts/with-lock.php" "$snapshot/scripts/with-lock.php"
        (cd "$snapshot" && env HOME="$scratch/home" RECORD="$scratch/rec" PATH="$bin:/usr/bin:/bin" CAPELL_NO_STASH_HOOK=1 "${COMMIT_ENV[@]}" \
            bash scripts/guards/pre-commit-analysis.sh "packages/demo/$1") > /dev/null 2>&1 || return 1
        git -C "$repo" -c core.hooksPath= commit -qm "$2"
    fi
}
COMMIT_ENV=(PHPSTAN_FAKE_EXIT=0)
ran() { grep -q 'analyze:diff' "$scratch/rec/composer" 2> /dev/null; }

commit_php One.php one
push_hook
! ran || fail 'a cleanly analysed commit was analysed again at push'
grep -q 'skipping' "$scratch/out" || fail 'no skip message after a clean commit'

commit_php Two.php two --no-verify
push_hook
ran || fail 'a --no-verify commit was not analysed at push'

git -C "$repo" reset -q --hard HEAD~1
commit_php Two.php two
printf '<?php\n// edited\n' >> "$repo/packages/demo/Two.php"
git -C "$repo" add packages/demo/Two.php
git -C "$repo" -c core.hooksPath= commit -q --amend -m amended
push_hook
ran || fail 'an amended commit was not analysed at push'

git -C "$repo" reset -q --hard HEAD~1
commit_php Three.php three
push_hook
! ran || fail 'a clean commit was not skipped after the reset'
# Rebase onto a new base commit: the replayed tree is unrecorded.
git -C "$repo" checkout -q main
printf 'x\n' > "$repo/README.md"
git -C "$repo" add README.md
git -C "$repo" -c core.hooksPath= commit -qm main-moves
git -C "$repo" push -q origin main
git -C "$repo" fetch -q origin
git -C "$repo" checkout -q feature
git -C "$repo" -c core.hooksPath= rebase -q origin/main
push_hook
ran || fail 'a rebased range was not analysed at push'

# A failing pre-commit analysis records nothing.
COMMIT_ENV=(PHPSTAN_FAKE_EXIT=1)
if commit_php Four.php four; then fail 'the failing analysis let the commit through'; fi
git -C "$repo" reset -q --hard HEAD
COMMIT_ENV=(PHPSTAN_FAKE_EXIT=0)

echo 'PROOF: push analysis skips only fully pre-analysed ranges; --no-verify, amend and rebase fall back to the full run'
