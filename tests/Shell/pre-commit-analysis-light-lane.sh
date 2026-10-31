#!/usr/bin/env bash
# The staged-file PHPStan hook must enter the heavy queue's light lane, so a commit
# never waits behind a full-weight run holding every heavy slot.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
repo="$scratch/repo"
mkdir -p "$repo/scripts/guards"
cp "$root/scripts/guards/pre-commit-analysis.sh" "$repo/scripts/guards/"
mkdir -p "$repo/vendor/phpstan/phpstan" && touch "$repo/vendor/phpstan/phpstan/phpstan"
# with-lock.php is where the hook hands over to the heavy guard; record what it receives.
printf '<?php file_put_contents(getenv("LANE_RECORD"), getenv("HEAVY_LANE") . "|" . getenv("CAPELL_HEAVY_LABEL") . "|" . getenv("HEAVY_LIGHT_FILES"));\n' > "$repo/scripts/with-lock.php"
git init -q "$repo"
record() {
    rm -f "$scratch/lane"
    (cd "$repo" && env -u HEAVY_LANE -u CAPELL_HEAVY_LABEL -u HEAVY_LIGHT_FILES LANE_RECORD="$scratch/lane" \
        bash scripts/guards/pre-commit-analysis.sh "$@")
    cat "$scratch/lane"
}
fail() { echo "FAIL: $1" >&2; exit 1; }
small="$(record src/A.php src/B.php)"
[ "$small" = 'light|capell-static-staged|2' ] || fail "a 2-file commit reached the guard as '$small', not the light lane with its label and file count"
# shellcheck disable=SC2046 # one argument per generated path is the point.
large="$(record $(seq -f 'src/F%g.php' 51))"
[ "$large" = '||' ] || fail "a 51-file commit reached the guard as '$large'; it must queue as heavy"
echo 'PROOF: the staged-file PHPStan hook enters the heavy light lane'
