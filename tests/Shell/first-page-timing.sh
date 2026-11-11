#!/usr/bin/env bash
# Argument handling, budget enforcement and summary output of scripts/first-page-timing.sh.
# Real steps (composer, artisan) are replaced with fake ones, so this runs in seconds.
set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
script="${FIRST_PAGE_TIMING_SCRIPT:-$root/scripts/first-page-timing.sh}"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
failures=0

check() { # description, expected, actual
    if [[ "$2" == "$3" ]]; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (expected %s, got %s)\n' "$1" "$2" "$3"
        failures=$((failures + 1))
    fi
}

contains() { # description, file, needle
    if grep -qF -- "$3" "$2"; then
        printf 'ok   %s\n' "$1"
    else
        printf 'FAIL %s (missing "%s" in %s)\n' "$1" "$3" "$2"
        failures=$((failures + 1))
    fi
}

bash "$script" --help >"$scratch/help.out" 2>&1
check 'help exits 0' 0 $?
contains 'help prints usage' "$scratch/help.out" 'Usage:'

bash "$script" --nonsense >"$scratch/unknown.out" 2>&1
check 'unknown option exits 2' 2 $?
contains 'unknown option is named' "$scratch/unknown.out" '--nonsense'

bash "$script" --budget abc >"$scratch/budget.out" 2>&1
check 'non-numeric budget exits 2' 2 $?

bash "$script" --budget >"$scratch/novalue.out" 2>&1
check 'option without a value exits 2' 2 $?

bash "$script" --budget 0 >"$scratch/zero.out" 2>&1
check 'zero budget exits 2' 2 $?

bash "$script" --core-path "$scratch/missing" >"$scratch/core.out" 2>&1
check 'missing core path exits 2' 2 $?

fake_fast() { sleep 0; }
fake_slow() { sleep 2; }
fake_broken() { echo 'boom' >&2; return 7; }

run_fake() { # budget, summary file, steps...
    local budget="$1" summary="$2"
    shift 2
    (
        # shellcheck source=/dev/null
        source "$script"
        parse_args --budget "$budget" --summary "$summary" --core-path "$root" --workdir "$scratch/work"
        STEPS=("$@")
        run_pipeline
    )
}

run_fake 30 "$scratch/ok.md" 'First step|fake_fast' 'Second step|fake_fast' >"$scratch/ok.out" 2>&1
check 'steps within budget exit 0' 0 $?
contains 'summary lists a step' "$scratch/ok.md" '| First step |'
contains 'summary has a total row' "$scratch/ok.md" '| **Total** |'

run_fake 1 "$scratch/slow.md" 'First step|fake_fast' 'Slow step|fake_slow' >"$scratch/slow.out" 2>&1
check 'slow step over budget exits 1' 1 $?
contains 'summary names the budget failure' "$scratch/slow.md" 'exceeded'
contains 'summary still lists the slow step' "$scratch/slow.md" '| Slow step |'

run_fake 30 "$scratch/broken.md" 'Broken step|fake_broken' 'Never reached|fake_fast' >"$scratch/broken.out" 2>&1
check 'failing step exits 1' 1 $?
contains 'failing step is marked' "$scratch/broken.md" 'failed'
contains 'later steps are marked skipped' "$scratch/broken.md" 'skipped'

if ((failures > 0)); then
    printf '%d check(s) failed\n' "$failures"
    exit 1
fi
printf 'all checks passed\n'
