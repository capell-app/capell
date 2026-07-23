#!/usr/bin/env bash
#
# Give a git worktree a working vendor/ in seconds instead of minutes.
#
# A full `composer install` in every worktree costs several minutes and ~1.5 GB.
# Symlinking the whole vendor/ directory is fast but SILENTLY WRONG: Composer's
# generated autoloader derives $baseDir from __DIR__ inside vendor/composer/, PHP
# resolves symlinks in __DIR__, so $baseDir becomes the primary checkout. Every
# Capell\* class then loads from the primary tree — your worktree edits are
# invisible and the suite "passes" while testing entirely different code.
#
# This script builds a hybrid instead:
#
#   vendor/composer/     real copy  -> $baseDir resolves to THIS worktree
#   vendor/autoload.php  real copy
#   vendor/bin/          real copy
#   vendor/<pkg>/<name>  symlink    -> shared, read-only, third-party code
#   REAL_PACKAGES        real copy  -> tools whose bin scripts walk __DIR__ up
#                                      to find autoload.php
#
# It then verifies the result and refuses to leave a poisoned vendor/ behind.
#
# Usage:  bash scripts/init-worktree.sh [--force]

set -euo pipefail

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

WORKTREE_ROOT=$(git rev-parse --show-toplevel)
GIT_COMMON_DIR=$(cd "$(git rev-parse --git-common-dir)" && pwd)
PRIMARY_ROOT=$(dirname "$GIT_COMMON_DIR")

if [ "$WORKTREE_ROOT" = "$PRIMARY_ROOT" ]; then
    echo "This is the primary checkout, not a worktree. Run 'composer install' here." >&2
    exit 1
fi

if [ ! -d "$PRIMARY_ROOT/vendor/composer" ]; then
    echo "Primary checkout has no vendor/ at $PRIMARY_ROOT." >&2
    echo "Run 'composer install' there first, then re-run this script." >&2
    exit 1
fi

cd "$WORKTREE_ROOT"

if [ -e vendor ]; then
    if [ "$FORCE" -eq 1 ]; then
        rm -rf vendor
    else
        echo "vendor/ already exists. Re-run with --force to rebuild it." >&2
        exit 1
    fi
fi

# Packages whose bin scripts resolve their own autoloader by walking __DIR__
# upward. If these are symlinks, they find the PRIMARY autoloader and you get
# "Cannot redeclare class ComposerAutoloaderInit..." or a silent wrong-tree run.
REAL_PACKAGES=(
    pestphp/pest
    phpunit/phpunit
    laravel/pint
    phpstan/phpstan
    rector/rector
    brianium/paratest
)

echo "Primary : $PRIMARY_ROOT"
echo "Worktree: $WORKTREE_ROOT"

mkdir vendor
cp -R "$PRIMARY_ROOT/vendor/composer" vendor/composer
cp "$PRIMARY_ROOT/vendor/autoload.php" vendor/autoload.php
cp -R "$PRIMARY_ROOT/vendor/bin" vendor/bin

linked=0
for vendor_dir in "$PRIMARY_ROOT"/vendor/*/; do
    vendor_name=$(basename "$vendor_dir")
    case "$vendor_name" in composer | bin) continue ;; esac

    mkdir -p "vendor/$vendor_name"
    for package_dir in "$vendor_dir"*/; do
        [ -d "$package_dir" ] || continue
        ln -s "$package_dir" "vendor/$vendor_name/$(basename "$package_dir")"
        linked=$((linked + 1))
    done
done

copied=0
for package in "${REAL_PACKAGES[@]}"; do
    if [ -d "$PRIMARY_ROOT/vendor/$package" ]; then
        rm -rf "vendor/$package"
        cp -R "$PRIMARY_ROOT/vendor/$package" "vendor/$package"
        copied=$((copied + 1))
    fi
done

echo "Linked $linked packages, copied $copied tool packages ($(du -sh vendor | cut -f1))."

# ---------------------------------------------------------------------------
# Verify. A wrong answer here means the suite would test the primary checkout,
# so this is a hard failure, not a warning.
# ---------------------------------------------------------------------------
resolved=$(php -r '
    require "vendor/autoload.php";
    echo (new ReflectionClass("Capell\Core\Enums\Concerns\HasEnumOptions"))->getFileName();
')

case "$resolved" in
"$WORKTREE_ROOT"/*)
    echo "OK: Capell classes resolve inside the worktree."
    ;;
*)
    echo >&2
    echo "FAILED: Capell classes resolve to $resolved" >&2
    echo "That is outside this worktree, so tests would exercise the wrong code." >&2
    echo "Removing the broken vendor/ — run 'composer install' here instead." >&2
    rm -rf vendor
    exit 1
    ;;
esac

cat <<'EOF'

Done. Remember that this repo's tooling needs an explicit memory limit:

  composer test:unit
  php -d memory_limit=1G vendor/bin/pest --compact --configuration=phpunit.xml <path>
  php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress <path>

vendor/ is shared with the primary checkout. Never run composer install/require
/update from this worktree — it would rewrite the primary tree's packages.

KNOWN LIMITATION — read before trusting a full-suite run.
Third-party packages are symlinked, so any code that walks upward from inside
vendor/ (dirname(__DIR__, N), a re-registered Composer ClassLoader) lands in the
PRIMARY checkout, not this worktree. Most suites are unaffected, but some — the
core Unit/Support suite is one — end up loading Capell classes from the primary
tree, which silently tests the wrong code.

Use this setup for fast, targeted runs, and confirm the classes you care about
resolve here before believing a result:

  php -r 'require "vendor/autoload.php";
    echo (new ReflectionClass("Your\\Changed\\Class"))->getFileName(), PHP_EOL;'

For an authoritative full-suite run, do a real composer install in this worktree.
EOF
