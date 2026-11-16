#!/usr/bin/env bash
# A new checkout must use its own manifest even when the primary autoloader is stale.
set -euo pipefail
script="${WORKTREE_INIT_SCRIPT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/init-worktree.sh}"
while IFS= read -r variable; do
    unset "$variable"
done < <(git rev-parse --local-env-vars)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT
mkdir "$fixture/primary"
cd "$fixture/primary"
git init -q
mkdir -p packages/core/src/Enums tests/fixtures/new-source vendor/bin vendor/acme/library/src
printf '<?php return "v1";\n' > vendor/acme/library/src/version.php
cat > packages/core/src/Enums/ImageSourceType.php <<'PHP'
<?php
namespace Capell\Core\Enums;
enum ImageSourceType { case Fixture; }
PHP
cat > composer.json <<'JSON'
{"name":"fixture/worktree","autoload":{"psr-4":{"Capell\\Core\\":"packages/core/src/"}},"config":{"optimize-autoloader":true}}
JSON
composer dump-autoload --no-interaction --no-scripts --no-plugins >/dev/null
# Composer plugins write top-level metadata such as Pest's plugin cache beside the packages.
printf '["Pest\\\\Plugins\\\\Parallel"]\n' > vendor/pest-plugins.json
# Hidden metadata beside the packages belongs to the dependency set as well.
printf 'hidden-metadata\n' > vendor/.hidden-metadata
primary_hash=$(shasum -a 256 vendor/composer/autoload_psr4.php)
cat > tests/fixtures/new-source/NewSource.php <<'PHP'
<?php
namespace Fixture\NewSource;
final class NewSource {}
PHP
python3 - <<'PYTHON'
import json
from pathlib import Path
p=Path('composer.json')
m=json.loads(p.read_text())
m['autoload-dev']={'psr-4': {'Fixture\\NewSource\\': 'tests/fixtures/new-source/'}}
p.write_text(json.dumps(m)+'\n')
PYTHON
printf 'vendor/\nnode_modules/\n' > .gitignore
git add composer.json .gitignore packages tests
git -c user.name=fixture -c user.email=fixture@example.test commit -qm source
# npm leaves a relative link for "file:../sibling" dependencies. Worktrees live in
# a different directory depth, so the cloned link would dangle there.
mkdir -p "$fixture/sibling-tools" node_modules/@capell-app
ln -s ../../../sibling-tools node_modules/@capell-app/sibling-tools
# The script clones node_modules on APFS and otherwise falls back to `npm ci`,
# which needs a lockfile this fixture does not have. Stub npm so the test behaves
# the same on any host: like the real install it recreates the relative link.
mkdir -p "$fixture/stubs"
cat > "$fixture/stubs/npm" <<'NPM'
#!/bin/sh
echo "$*" >> "$FIXTURE_NPM_LOG"
[ "$1" = ci ] || exit 0
mkdir -p node_modules/@capell-app
ln -sfn ../../../sibling-tools node_modules/@capell-app/sibling-tools
NPM
chmod +x "$fixture/stubs/npm"
# A cp that refuses copy-on-write clones forces the fallback path on any host.
mkdir -p "$fixture/noclone"
cat > "$fixture/noclone/cp" <<'CP'
#!/bin/sh
for a in "$@"; do [ "$a" = "-Rc" ] && exit 1; done
exec /bin/cp "$@"
CP
chmod +x "$fixture/noclone/cp"
export FIXTURE_NPM_LOG="$fixture/npm.log"
PATH="$fixture/stubs:$PATH"
# The manifest now declares a source namespace absent from the primary vendor metadata.
mkdir -p "$fixture/nested"
git worktree add -q "$fixture/nested/worktree" -b fixture-worktree
cd "$fixture/nested/worktree"
bash "$script" --host-only
php -r '$loader=require "vendor/autoload.php"; $actual=(new ReflectionClass("Fixture\\NewSource\\NewSource"))->getFileName(); $expected=getcwd()."/tests/fixtures/new-source/NewSource.php"; if (realpath($actual)!==realpath($expected)) { throw new RuntimeException("New source resolved outside worktree"); } echo "PASS new manifest source resolves inside worktree", PHP_EOL;'
cmp vendor/pest-plugins.json "$fixture/primary/vendor/pest-plugins.json"
cmp vendor/.hidden-metadata "$fixture/primary/vendor/.hidden-metadata"
echo 'PASS top-level vendor metadata provisioned'
# Third-party packages must be real trees owned by this worktree: a symlink into
# another checkout goes stale or wrong the moment that checkout's vendor changes.
test -d vendor/acme/library
if [ -L vendor/acme/library ]; then
    echo "FAIL third-party package is a symlink" >&2
    exit 1
fi
if find vendor -type l -exec sh -c 'for l; do case "$(cd "$(dirname "$l")" && cd "$(dirname "$(readlink "$l")")" 2>/dev/null && pwd)" in "$PWD"/*) ;; *) echo "$l"; exit 1;; esac; done' sh {} +; then :; else
    echo "FAIL vendor contains a symlink leaving the worktree" >&2
    exit 1
fi
printf '<?php return "v2";\n' > "$fixture/primary/vendor/acme/library/src/version.php"
test "$(php -r 'echo require "vendor/acme/library/src/version.php";')" = "v1"
echo 'PASS third-party packages are real trees isolated from the primary'
test -e node_modules/@capell-app/sibling-tools
test "$(readlink node_modules/@capell-app/sibling-tools)" = "$(cd "$fixture/sibling-tools" && pwd -P)"
echo 'PASS linked sibling packages resolve from the worktree'
cd "$fixture/primary"
test "$(readlink node_modules/@capell-app/sibling-tools)" = "../../../sibling-tools"
test "$primary_hash" = "$(shasum -a 256 vendor/composer/autoload_psr4.php)"
echo 'PASS primary autoload metadata unchanged'
# Force the non-clone fallback regardless of host and prove it provisions the same.
cd "$fixture/primary"
git worktree add -q "$fixture/nested/fallback" -b fixture-fallback
cd "$fixture/nested/fallback"
: > "$FIXTURE_NPM_LOG"
PATH="$fixture/noclone:$PATH" bash "$script" --host-only >/dev/null
grep -qx 'ci' "$FIXTURE_NPM_LOG"
test -e node_modules/@capell-app/sibling-tools
test "$(readlink node_modules/@capell-app/sibling-tools)" = "$(cd "$fixture/sibling-tools" && pwd -P)"
echo 'PASS non-clone fallback re-points linked sibling packages'
# A branch whose composer.lock differs from the cloned vendor/ gets its own install,
# so the first commit is not refused by the pre-commit lock check.
mkdir -p "$fixture/composer-installs" "$fixture/composer-fails"
real_composer=$(command -v composer)
cat > "$fixture/composer-installs/composer" <<COMPOSER
#!/bin/sh
if [ "\$1" = install ]; then
    echo install >> "\$FIXTURE_COMPOSER_LOG"
    php -r '\$lock = json_decode(file_get_contents("composer.lock"), true); file_put_contents("vendor/composer/installed.json", json_encode(["packages" => array_merge(\$lock["packages"], \$lock["packages-dev"])]));'
    exit 0
fi
exec "$real_composer" "\$@"
COMPOSER
cat > "$fixture/composer-fails/composer" <<COMPOSER
#!/bin/sh
[ "\$1" = install ] && { echo 'Your lock file does not contain a compatible set of packages.' >&2; exit 2; }
exec "$real_composer" "\$@"
COMPOSER
chmod +x "$fixture/composer-installs/composer" "$fixture/composer-fails/composer"
export FIXTURE_COMPOSER_LOG="$fixture/composer.log"
cd "$fixture/primary"
git worktree add -q "$fixture/nested/drift" -b fixture-drift
cd "$fixture/nested/drift"
printf '{"packages":[{"name":"acme/library","version":"2.0.0"}],"packages-dev":[]}\n' > composer.lock
drift_output=$(PATH="$fixture/composer-installs:$PATH" bash "$script" --host-only 2>&1)
printf '%s\n' "$drift_output" | grep -q "acme/library: locked 2.0.0, installed none"
printf '%s\n' "$drift_output" | grep -q "OK: vendor/ matches this branch's composer.lock."
grep -qx install "$FIXTURE_COMPOSER_LOG"
php -r '$installed = json_decode(file_get_contents("vendor/composer/installed.json"), true); if (array_column($installed["packages"], "version", "name") !== ["acme/library" => "2.0.0"]) { throw new RuntimeException("vendor/ was not matched to the lock"); }'
echo 'PASS lock drift is reconciled with this worktree install'
# An install that cannot match the lock fails provisioning instead of leaving a vendor/ the hook refuses.
cd "$fixture/primary"
git worktree add -q "$fixture/nested/drift-fails" -b fixture-drift-fails
cd "$fixture/nested/drift-fails"
printf '{"packages":[{"name":"acme/library","version":"2.0.0"}],"packages-dev":[]}\n' > composer.lock
if failed_output=$(PATH="$fixture/composer-fails:$PATH" bash "$script" --host-only 2>&1); then
    echo 'FAIL provisioning succeeded although vendor/ still differs from the lock' >&2
    exit 1
fi
printf '%s\n' "$failed_output" | grep -q "FAILED: 'composer install' could not match vendor/"
echo 'PASS an install that cannot match the lock fails provisioning'
: > "$FIXTURE_COMPOSER_LOG"
cd "$fixture/nested/fallback"
if PATH="$fixture/composer-installs:$PATH" bash "$script" --host-only --force 2>&1 >/dev/null | grep -q 'differs from this branch'; then
    echo 'FAIL lock drift reported without a composer.lock' >&2
    exit 1
fi
if [ -s "$FIXTURE_COMPOSER_LOG" ]; then
    echo 'FAIL composer install ran without a lock difference' >&2
    exit 1
fi
echo 'PASS no install without a lock difference'
