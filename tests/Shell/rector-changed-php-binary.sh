#!/usr/bin/env bash
# rector-changed.sh must run when PHP_BINARY is unset, as it is in CI, and use the
# php found on PATH. A fake php records the Rector invocation.
# SCRIPT_UNDER_TEST overrides the script path (to prove the test fails without the fix).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
script="${SCRIPT_UNDER_TEST:-$root/scripts/rector-changed.sh}"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
export GIT_AUTHOR_NAME=Test GIT_AUTHOR_EMAIL=test@example.test
export GIT_COMMITTER_NAME=Test GIT_COMMITTER_EMAIL=test@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

origin="$scratch/origin.git"
repo="$scratch/repo"
git init -q --bare --initial-branch=main "$origin"
git clone -q "$origin" "$repo" 2> /dev/null
mkdir -p "$repo/scripts" "$repo/packages/demo/src"
cp "$script" "$repo/scripts/rector-changed.sh"
touch "$repo/README.md"
git -C "$repo" add .
git -C "$repo" -c core.hooksPath= commit -qm base
git -C "$repo" push -q origin HEAD:main
git -C "$repo" fetch -q origin
git -C "$repo" checkout -q -b feature
printf '<?php\n' > "$repo/packages/demo/src/Demo.php"
git -C "$repo" add packages/demo/src/Demo.php
git -C "$repo" -c core.hooksPath= commit -qm change

bin="$scratch/bin"
mkdir -p "$bin"
cat > "$bin/php" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$RECORD"
FAKE
chmod +x "$bin/php"

record="$scratch/record"
if ! (cd "$repo" && env -u PHP_BINARY PATH="$bin:$PATH" RECORD="$record" GITHUB_BASE_REF=main bash scripts/rector-changed.sh > "$scratch/out" 2>&1); then
  echo "FAIL: rector-changed.sh exited non-zero without PHP_BINARY" >&2
  cat "$scratch/out" >&2
  exit 1
fi
if ! grep -q 'vendor/bin/rector' "$record" 2> /dev/null; then
  echo "FAIL: Rector was not run through the php on PATH" >&2
  cat "$scratch/out" >&2
  exit 1
fi
echo "PASS"
