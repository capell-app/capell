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
mkdir -p packages/core/src/Enums tests/fixtures/new-source vendor/bin
cat > packages/core/src/Enums/ImageSourceType.php <<'PHP'
<?php
namespace Capell\Core\Enums;
enum ImageSourceType { case Fixture; }
PHP
cat > composer.json <<'JSON'
{"name":"fixture/worktree","autoload":{"psr-4":{"Capell\\Core\\":"packages/core/src/"}},"config":{"optimize-autoloader":true}}
JSON
composer dump-autoload --no-interaction --no-scripts --no-plugins >/dev/null
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
cd "$fixture/primary"
test "$primary_hash" = "$(shasum -a 256 vendor/composer/autoload_psr4.php)"
echo 'PASS primary autoload metadata unchanged'
