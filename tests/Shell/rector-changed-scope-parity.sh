#!/usr/bin/env bash
# A changed-file Rector run (the pre-commit hook and pull-request CI) must infer the
# same types as the full run the merge queue makes. Test helpers such as
# tests/Support/DomQuery.php are loaded with require_once rather than Composer, so
# unless rector.php registers them as autoload paths a run that names only the
# changed file cannot see that domElement() returns DOMElement. It then treats
# ->textContent as nullable and adds a (string) cast that the full run removes,
# and the two never agree.
#
# This runs the real Rector with the repository's rector.php on a file outside
# every configured path, naming only that file, as the hook does.
# RECTOR_CONFIG overrides the config path (to prove the test fails without the fix).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
config="${RECTOR_CONFIG:-$root/rector.php}"
php="${PHP_BINARY:-php}"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

fixture="$scratch/HelperReturnTypeFixture.php"
cat > "$fixture" <<PHP
<?php

declare(strict_types=1);

require_once '$root/tests/Support/DomQuery.php';

\$heading = trim(domElement(domXPath('<h1>Title</h1>'), '//h1')->textContent);
PHP
cp "$fixture" "$scratch/expected.php"

if ! (cd "$root" && XDEBUG_MODE=off "$php" vendor/bin/rector process --config="$config" --no-progress-bar \
  --no-diffs --output-format=json "$fixture" > "$scratch/out" 2>&1); then
  echo "FAIL: Rector exited non-zero" >&2
  cat "$scratch/out" >&2
  exit 1
fi

if ! cmp -s "$fixture" "$scratch/expected.php"; then
  echo "FAIL: a changed-file Rector run rewrote code the full run leaves alone:" >&2
  diff -u "$scratch/expected.php" "$fixture" >&2 || true
  exit 1
fi
echo "PASS"
