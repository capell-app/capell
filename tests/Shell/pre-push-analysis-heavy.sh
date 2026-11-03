#!/usr/bin/env bash
# The pre-push PHPStan run must go through the host heavy queue at the weight its
# configuration's worker cap calls for, run directly with a warning when no queue
# exists, and not be wrapped a second time inside an existing reservation. A fake composer stands in for PHPStan.
# HOOK_UNDER_TEST overrides the hook path (to prove the test fails without the fix).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
hook="${HOOK_UNDER_TEST:-$root/scripts/guards/pre-push-analysis.sh}"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.test
export GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

origin="$scratch/origin.git"
repo="$scratch/repo"
git init -q --bare --initial-branch=main "$origin"
git clone -q "$origin" "$repo" 2> /dev/null
mkdir -p "$repo/scripts/guards" "$repo/packages/demo"
cp "$hook" "$repo/scripts/guards/pre-push-analysis.sh"
touch "$repo/README.md"
git -C "$repo" add .
git -C "$repo" -c core.hooksPath= commit -qm base
git -C "$repo" push -q origin HEAD:main
git -C "$repo" fetch -q origin
git -C "$repo" checkout -q -b feature
printf '<?php\n' > "$repo/packages/demo/Demo.php"
git -C "$repo" add packages/demo/Demo.php
git -C "$repo" -c core.hooksPath= commit -qm change

# A fake HOME hides the installed guard; fake heavy and composer record their arguments.
bin="$scratch/bin"
mkdir -p "$bin" "$scratch/home" "$scratch/rec"
cat > "$bin/heavy" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$RECORD/heavy"
# heavy run -w <n> <label> -- <command...>
shift 5
exec "$@"
FAKE
cat > "$bin/composer" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$RECORD/composer"
FAKE
chmod +x "$bin/heavy" "$bin/composer"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
run_hook() { # <PATH> [VAR=value...]
    local path="$1"
    shift
    rm -f "$scratch/rec/heavy" "$scratch/rec/composer"
    (cd "$repo" && env -u HEAVY_SLOT_OWNER HOME="$scratch/home" RECORD="$scratch/rec" PATH="$path" "$@" \
        bash scripts/guards/pre-push-analysis.sh < /dev/null) > "$scratch/out" 2>&1 \
        || { cat "$scratch/out" >&2; fail 'the hook exited non-zero'; }
}

# The hook's weight must match the worker cap of the configuration `composer analyze:diff`
# uses, so the two cannot drift apart again (a full gate for four workers serialised pushes).
config="$(python3 -c 'import json, re, sys; script = json.load(open(sys.argv[1]))["scripts"]["analyze:diff"]; print(re.search(r"--configuration=(\S+)", script).group(1))' "$root/composer.json")"
weight="$(python3 "$root/tests/Shell/lib/phpstan-worker-weight.py" "$root/$config")"
run_hook "$bin:/usr/bin:/bin"
case "$(cat "$scratch/rec/heavy" 2> /dev/null)" in
    "run -w $weight core-pre-push-analysis -- "*) ;;
    *) fail "heavy received '$(cat "$scratch/rec/heavy" 2> /dev/null)', not weight $weight (the worker cap of $config) with label core-pre-push-analysis" ;;
esac
grep -q -- "--weight $weight core-pre-push-analysis" "$hook" || fail "the heavy-slot.sh fallback does not use weight $weight"
grep -q 'analyze:diff' "$scratch/rec/composer" || fail 'the analysis did not run under heavy'

run_hook "$bin:/usr/bin:/bin" HEAVY_SLOT_OWNER='{"root":"/tmp/x"}'
[ ! -e "$scratch/rec/heavy" ] || fail 'a hook inside a reservation was wrapped again'
grep -q 'analyze:diff' "$scratch/rec/composer" || fail 'the analysis did not run inside a reservation'

mkdir -p "$scratch/nobin"
cp "$bin/composer" "$scratch/nobin/composer"
run_hook "$scratch/nobin:/usr/bin:/bin"
[ ! -e "$scratch/rec/heavy" ] || fail 'heavy ran though it is absent'
grep -q 'analyze:diff' "$scratch/rec/composer" || fail 'the analysis did not run without heavy'
grep -q 'running PHPStan unguarded' "$scratch/out" || fail 'no warning when no heavy queue exists'

echo "PROOF: the pre-push analysis runs through the heavy queue at weight $weight, matching the worker cap of $config"
