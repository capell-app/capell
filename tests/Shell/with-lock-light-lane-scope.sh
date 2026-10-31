#!/usr/bin/env bash
# Light-lane hook work must not wait on another checkout's host-wide static lock:
# that lock is held while its holder waits in the heavy queue. Within one checkout,
# and outside the light lane, the lock still serialises.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
scratch="$(mktemp -d)"
trap 'touch "$scratch/release"; wait 2>/dev/null; rm -rf "$scratch"' EXIT
export TMPDIR="$scratch/tmp" HEAVY_SLOT_ENABLED=0
mkdir -p "$TMPDIR"
for checkout in one two; do
    mkdir -p "$scratch/$checkout/scripts"
    cp "$root/scripts/with-lock.php" "$root/scripts/heavy-admission.php" "$scratch/$checkout/scripts/"
done
fail() { echo "FAIL: $1" >&2; exit 1; }
hold() { # hold <checkout> <lane>: take capell-static in <checkout> until released
    # shellcheck disable=SC2016 # $0 and $1 belong to the inner bash.
    (cd "$scratch/$1" && HEAVY_LANE="$2" php scripts/with-lock.php capell-static -- \
        bash -c 'touch "$0"; while [ ! -f "$1" ]; do sleep .05; done' "$scratch/held-$1" "$scratch/release") &
    for _ in $(seq 100); do [ -f "$scratch/held-$1" ] && return; sleep .05; done
    fail "the $1 checkout never took the lock"
}
attempt() { # attempt <checkout> <lane>: prints "ran" when the lock was free within 2s
    (cd "$scratch/$1" && HEAVY_LANE="$2" perl -e 'alarm 2; exec @ARGV' php scripts/with-lock.php capell-static -- echo ran 2>/dev/null) || true
}
hold one light
[ "$(attempt two light)" = ran ] || fail 'a light-lane hook in another checkout waited on the static lock'
[ "$(attempt one light)" != ran ] || fail 'two light-lane hooks in one checkout ran at once over a shared PHPStan cache'
touch "$scratch/release"; wait; rm -f "$scratch/release" "$scratch"/held-*
hold one ''
[ "$(attempt two '')" != ran ] || fail 'outside the light lane the static lock stopped being host-wide'
echo 'PROOF: light-lane static locks are per checkout; other static work stays host-wide'
