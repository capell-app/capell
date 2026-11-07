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
# The manifest now declares a source namespace absent from the primary vendor metadata.
git worktree add -q "$fixture/worktree" -b fixture-worktree
cd "$fixture/worktree"
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
cd "$fixture/primary"
test "$primary_hash" = "$(shasum -a 256 vendor/composer/autoload_psr4.php)"
echo 'PASS primary autoload metadata unchanged'
